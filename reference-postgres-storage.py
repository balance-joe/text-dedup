"""PostgreSQL storage adapter for the article dedupe service.

The public methods intentionally mirror ``MySQLDedupeStore`` so matchers and
API handlers stay database-agnostic.  PostgreSQL-specific concerns live here:
signed BIGINT uint64 mapping, direct leaf-partition writes, COPY, and advisory
locks for maintenance.
"""

from __future__ import annotations

import os
import time
from collections import defaultdict
from datetime import datetime, timedelta, timezone
from pathlib import Path

from dedupe_service.config import (
    MINHASH_BANDS,
    MINHASH_ROWS,
    POSTGRES_CONNECT_TIMEOUT,
    POSTGRES_DATABASE,
    POSTGRES_HOST,
    POSTGRES_MAINTENANCE_DATABASE,
    POSTGRES_PASSWORD,
    POSTGRES_POOL_MAX_SIZE,
    POSTGRES_POOL_MIN_SIZE,
    POSTGRES_PORT,
    POSTGRES_USER,
)
from dedupe_service.simhash import simhash_hex


PROJECT_ROOT = Path(__file__).resolve().parents[2]
SCHEMA_PATH = PROJECT_ROOT / "data" / "postgresql_schema.sql"
_UINT64_LIMIT = 1 << 64
_INT64_SIGN = 1 << 63
_POOLS = {}


def uint64_to_int64(value: int) -> int:
    """Store a uint64 hash in PostgreSQL's signed BIGINT without losing bits."""
    value = int(value)
    if not 0 <= value < _UINT64_LIMIT:
        raise ValueError(f"value is not uint64: {value}")
    return value if value < _INT64_SIGN else value - _UINT64_LIMIT


def int64_to_uint64(value: int) -> int:
    value = int(value)
    if not -_INT64_SIGN <= value < _INT64_SIGN:
        raise ValueError(f"value is not int64: {value}")
    return value if value >= 0 else value + _UINT64_LIMIT


def band_value_to_uint64(value) -> int:
    if isinstance(value, str):
        return int(value, 16)
    return int(value)


def _join_simhash(hi: int, lo: int) -> int:
    return (int64_to_uint64(hi) << 64) | int64_to_uint64(lo)


def _pg():
    try:
        import psycopg
        from psycopg.rows import dict_row
        from psycopg_pool import ConnectionPool
    except ImportError as exc:  # pragma: no cover - depends on deployment extras
        raise RuntimeError(
            "PostgreSQL backend requires psycopg[binary,pool]; install requirements.txt first."
        ) from exc
    return psycopg, dict_row, ConnectionPool


def _conninfo(database: str | None = None) -> str:
    psycopg, _, _ = _pg()
    return psycopg.conninfo.make_conninfo(
        host=POSTGRES_HOST,
        port=POSTGRES_PORT,
        dbname=database or POSTGRES_DATABASE,
        user=POSTGRES_USER,
        password=POSTGRES_PASSWORD,
        connect_timeout=POSTGRES_CONNECT_TIMEOUT,
    )


def _pool():
    key = _conninfo()
    pool = _POOLS.get(key)
    if pool is None:
        _, dict_row, ConnectionPool = _pg()
        pool = ConnectionPool(
            conninfo=key,
            min_size=POSTGRES_POOL_MIN_SIZE,
            max_size=max(POSTGRES_POOL_MIN_SIZE, POSTGRES_POOL_MAX_SIZE),
            kwargs={"autocommit": False, "row_factory": dict_row},
        )
        _POOLS[key] = pool
    return pool


def create_postgres_schema() -> None:
    """Create a fresh schema once, or validate the immutable MinHash metadata."""
    if (MINHASH_BANDS, MINHASH_ROWS) != (32, 1):
        raise RuntimeError(
            "PostgreSQL schema requires DEDUPE_MINHASH_BANDS=32 and "
            f"DEDUPE_MINHASH_ROWS=1; got {MINHASH_BANDS}x{MINHASH_ROWS}."
        )
    psycopg, dict_row, _ = _pg()
    try:
        conn = psycopg.connect(_conninfo(), autocommit=False, row_factory=dict_row)
    except psycopg.errors.InvalidCatalogName:
        # PostgreSQL cannot create a database while connected to it. Only use
        # the maintenance DB on first deployment; steady-state startup should
        # work for application roles that cannot access it.
        with psycopg.connect(
            _conninfo(POSTGRES_MAINTENANCE_DATABASE), autocommit=True, row_factory=dict_row
        ) as maintenance_conn:
            with maintenance_conn.cursor() as cur:
                try:
                    cur.execute(
                        psycopg.sql.SQL("CREATE DATABASE {}").format(
                            psycopg.sql.Identifier(POSTGRES_DATABASE)
                        )
                    )
                except psycopg.errors.DuplicateDatabase:
                    # Another worker may have created it concurrently.
                    pass
        conn = psycopg.connect(_conninfo(), autocommit=False, row_factory=dict_row)

    with conn:
        with conn.cursor() as cur:
            cur.execute("SELECT to_regclass('dedup_content.document_fingerprint') AS relation")
            exists = cur.fetchone()["relation"] is not None
            if not exists:
                conn.commit()
                cur.execute(SCHEMA_PATH.read_text(encoding="utf-8"))
            else:
                cur.execute(
                    "SELECT meta_value FROM dedup_content.dedupe_meta WHERE meta_key = 'minhash_bands'"
                )
                row = cur.fetchone()
                if row is None or row["meta_value"] != str(MINHASH_BANDS):
                    raise RuntimeError(
                        "PostgreSQL MinHash metadata does not match 32 bands; explicit rebuild required."
                    )
        conn.commit()


class PostgresDedupeStore:
    """Synchronous PostgreSQL implementation of the current store contract."""

    def __init__(self):
        self._pool = _pool()
        self.conn = self._pool.getconn()
        self.raw_cache = {}
        self.content_cache = {}
        self.title_cache = {}
        self.doc_id_cache = {}
        self.sim_candidate_cache = {}
        self.mh_candidate_cache = {}
        self.title_sim_candidate_cache = {}
        self.title_mh_candidate_cache = {}
        self.doc_cache = {}
        self.last_flush_timings = {}
        self.last_flush_metrics = {}

    def close(self):
        if self.conn is not None:
            try:
                self.conn.rollback()
            finally:
                self._pool.putconn(self.conn)
                self.conn = None

    def _cursor(self):
        if self.conn is None:
            raise RuntimeError("store is closed")
        return self.conn.cursor()

    @staticmethod
    def _table(scope: str, kind: str) -> str:
        if scope not in {"content", "title"}:
            raise ValueError(f"unknown scope: {scope}")
        if kind not in {"simhash", "minhash"}:
            raise ValueError(f"unknown band kind: {kind}")
        return f"{'title_' if scope == 'title' else ''}{kind}_band"

    @classmethod
    def _leaf_table(cls, scope: str, kind: str, band_index: int) -> str:
        upper = 7 if kind == "simhash" else 31
        if not 0 <= int(band_index) <= upper:
            raise ValueError(f"invalid {kind} band index: {band_index}")
        return f"dedup_content.{cls._table(scope, kind)}_p{int(band_index)}"

    @staticmethod
    def _hash_bytes(hash_hex: str) -> bytes:
        return bytes.fromhex(hash_hex)

    @staticmethod
    def _utc_timestamp(value):
        if value is None:
            return datetime.now(timezone.utc)
        if isinstance(value, datetime):
            return value if value.tzinfo else value.replace(tzinfo=timezone.utc)
        parsed = datetime.fromisoformat(str(value).replace("Z", "+00:00"))
        return parsed if parsed.tzinfo else parsed.replace(tzinfo=timezone.utc)

    def _evict_document_caches(self, external_id=None, content_hash_bin=None, title_hash_bin=None, raw_hash_bin=None, doc_pk=None):
        if external_id is not None:
            self.doc_id_cache.pop(external_id, None)
        for cache, value in (
            (self.content_cache, content_hash_bin),
            (self.title_cache, title_hash_bin),
            (self.raw_cache, raw_hash_bin),
        ):
            if value is not None:
                cache.pop(bytes(value).hex(), None)
        if doc_pk is not None:
            self.doc_cache.pop(doc_pk, None)
        self.sim_candidate_cache.clear()
        self.mh_candidate_cache.clear()
        self.title_sim_candidate_cache.clear()
        self.title_mh_candidate_cache.clear()

    def count_documents(self):
        with self._cursor() as cur:
            cur.execute("SELECT COUNT(*) AS n FROM dedup_content.document_fingerprint")
            return int(cur.fetchone()["n"])

    def _hash_by_external_id(self, column, external_id):
        with self._cursor() as cur:
            cur.execute(
                f"SELECT {column} FROM dedup_content.document_fingerprint WHERE external_id = %s LIMIT 1",
                (external_id,),
            )
            row = cur.fetchone()
        return bytes(row[column]).hex() if row and row[column] is not None else None

    def get_content_hash_by_external_id(self, external_id):
        return self._hash_by_external_id("content_hash", external_id)

    def get_title_hash_by_external_id(self, external_id):
        return self._hash_by_external_id("title_hash", external_id)

    def get_raw_hash_by_external_id(self, external_id):
        return self._hash_by_external_id("raw_hash", external_id)

    def get_exact_hash_by_external_id(self, external_id, scope="content"):
        return self.get_title_hash_by_external_id(external_id) if scope == "title" else self.get_content_hash_by_external_id(external_id)

    def _find_hash_dup(self, column, hash_hex, cache):
        if not hash_hex:
            return None
        cached = cache.get(hash_hex)
        if cached is not None:
            return cached
        with self._cursor() as cur:
            cur.execute(
                f"SELECT doc_pk, external_id FROM dedup_content.document_fingerprint WHERE {column} = %s ORDER BY doc_pk LIMIT 1",
                (self._hash_bytes(hash_hex),),
            )
            row = cur.fetchone()
        if row:
            cache[hash_hex] = row["external_id"]
            self.doc_id_cache[row["external_id"]] = row["doc_pk"]
            return row["external_id"]
        return None

    def find_raw_hash_dup(self, raw_hash_hex):
        return self._find_hash_dup("raw_hash", raw_hash_hex, self.raw_cache)

    def find_content_hash_dup(self, content_hash_hex):
        return self._find_hash_dup("content_hash", content_hash_hex, self.content_cache)

    def find_title_hash_dup(self, title_hash_hex):
        return self._find_hash_dup("title_hash", title_hash_hex, self.title_cache)

    def find_exact_hash_dup(self, exact_hash_hex, scope="content"):
        return self.find_title_hash_dup(exact_hash_hex) if scope == "title" else self.find_content_hash_dup(exact_hash_hex)

    def _find_hash_dups(self, column, hashes, cache):
        result = {}
        missing = []
        for value in dict.fromkeys(hashes):
            if value in cache:
                result[value] = cache[value]
            else:
                missing.append(value)
        if missing:
            with self._cursor() as cur:
                cur.execute(
                    f"SELECT doc_pk, external_id, {column} FROM dedup_content.document_fingerprint WHERE {column} = ANY(%s)",
                    ([self._hash_bytes(value) for value in missing],),
                )
                for row in cur.fetchall():
                    key = bytes(row[column]).hex()
                    cache[key] = row["external_id"]
                    result[key] = row["external_id"]
                    self.doc_id_cache[row["external_id"]] = row["doc_pk"]
        return result

    def find_raw_hash_dups(self, hashes):
        return self._find_hash_dups("raw_hash", hashes, self.raw_cache)

    def find_content_hash_dups(self, hashes):
        return self._find_hash_dups("content_hash", hashes, self.content_cache)

    def has_doc_id(self, external_id):
        if external_id in self.doc_id_cache:
            return True
        with self._cursor() as cur:
            cur.execute("SELECT doc_pk FROM dedup_content.document_fingerprint WHERE external_id = %s", (external_id,))
            row = cur.fetchone()
        if row:
            self.doc_id_cache[external_id] = row["doc_pk"]
            return True
        return False

    def find_prefilter_duplicate(self, external_id, raw_hash_hex, content_hash_hex, title_hash_hex=None):
        """Resolve all exact-duplicate keys in one indexed database round trip."""
        selects = [
            "SELECT 1 AS priority, 'external_id' AS conflict_reason, doc_pk, external_id, raw_hash, content_hash, title_hash "
            "FROM dedup_content.document_fingerprint WHERE external_id = %s",
            "SELECT 2 AS priority, 'raw_hash' AS conflict_reason, doc_pk, external_id, raw_hash, content_hash, title_hash "
            "FROM dedup_content.document_fingerprint WHERE raw_hash = %s",
            "SELECT 3 AS priority, 'content_hash' AS conflict_reason, doc_pk, external_id, raw_hash, content_hash, title_hash "
            "FROM dedup_content.document_fingerprint WHERE content_hash = %s",
        ]
        params = [external_id, self._hash_bytes(raw_hash_hex), self._hash_bytes(content_hash_hex)]
        if title_hash_hex:
            selects.append(
                "SELECT 4 AS priority, 'title_hash' AS conflict_reason, doc_pk, external_id, raw_hash, content_hash, title_hash "
                "FROM dedup_content.document_fingerprint WHERE title_hash = %s"
            )
            params.append(self._hash_bytes(title_hash_hex))
        with self._cursor() as cur:
            cur.execute(
                "SELECT * FROM (" + " UNION ALL ".join(selects) + ") AS matches ORDER BY priority, doc_pk LIMIT 1",
                params,
            )
            row = cur.fetchone()
        if not row:
            return None
        result = dict(row)
        result["raw_hash"] = bytes(result["raw_hash"]).hex() if result.get("raw_hash") else None
        result["content_hash"] = bytes(result["content_hash"]).hex() if result.get("content_hash") else None
        result["title_hash"] = bytes(result["title_hash"]).hex() if result.get("title_hash") else None
        self.doc_id_cache[result["external_id"]] = result["doc_pk"]
        return result

    def existing_doc_ids(self, external_ids):
        result = {value for value in dict.fromkeys(external_ids) if value in self.doc_id_cache}
        missing = [value for value in dict.fromkeys(external_ids) if value not in result]
        if missing:
            with self._cursor() as cur:
                cur.execute(
                    "SELECT doc_pk, external_id FROM dedup_content.document_fingerprint WHERE external_id = ANY(%s)",
                    (missing,),
                )
                for row in cur.fetchall():
                    self.doc_id_cache[row["external_id"]] = row["doc_pk"]
                    result.add(row["external_id"])
        return result

    def _hydrate_document_row(self, row):
        result = dict(row)
        result["content_hash_hex"] = bytes(row["content_hash"]).hex() if row.get("content_hash") else None
        result["title_hash_hex"] = bytes(row["title_hash"]).hex() if row.get("title_hash") else None
        result["simhash_hi"] = int64_to_uint64(row["simhash_hi"])
        result["simhash_lo"] = int64_to_uint64(row["simhash_lo"])
        result["simhash_hex"] = simhash_hex(_join_simhash(row["simhash_hi"], row["simhash_lo"]))
        if row.get("title_simhash_hi") is not None:
            result["title_simhash_hi"] = int64_to_uint64(row["title_simhash_hi"])
            result["title_simhash_lo"] = int64_to_uint64(row["title_simhash_lo"])
            result["title_simhash_hex"] = simhash_hex(_join_simhash(row["title_simhash_hi"], row["title_simhash_lo"]))
        else:
            result["title_simhash_hex"] = None
        return result

    def _documents(self, predicate, params, order_sql="d.doc_pk"):
        sql = f"""
            SELECT d.doc_pk, d.external_id, d.source_from, d.content_hash, d.title_hash,
                   d.simhash_hi, d.simhash_lo, d.title_simhash_hi, d.title_simhash_lo,
                   d.low_information, t.primary_text, t.normalized_title, t.normalized_content
            FROM dedup_content.document_fingerprint AS d
            LEFT JOIN dedup_content.document_text AS t ON t.doc_pk = d.doc_pk
            WHERE {predicate} ORDER BY {order_sql}
        """
        with self._cursor() as cur:
            cur.execute(sql, params)
            return [self._hydrate_document_row(row) for row in cur.fetchall()]

    def find_documents_by_content_hash(self, hash_hex, limit):
        return self._documents("d.content_hash = %s", (self._hash_bytes(hash_hex),), "d.doc_pk LIMIT " + str(int(limit)))

    def find_documents_by_title_hash(self, hash_hex, limit):
        return self._documents("d.title_hash = %s", (self._hash_bytes(hash_hex),), "d.doc_pk LIMIT " + str(int(limit)))

    def find_documents_by_exact_hash(self, hash_hex, limit, scope="content"):
        return self.find_documents_by_title_hash(hash_hex, limit) if scope == "title" else self.find_documents_by_content_hash(hash_hex, limit)

    def get_documents_by_external_ids(self, external_ids):
        ids = list(dict.fromkeys(external_ids))
        if not ids:
            return []
        rows = self._documents("d.external_id = ANY(%s)", (ids,))
        by_id = {row["external_id"]: row for row in rows}
        return [by_id[value] for value in ids if value in by_id]

    def get_documents_by_doc_pks(self, doc_pks):
        ids = list(dict.fromkeys(doc_pks))
        if not ids:
            return []
        rows = self._documents("d.doc_pk = ANY(%s)", (ids,))
        by_id = {row["doc_pk"]: row for row in rows}
        return [by_id[value] for value in ids if value in by_id]

    def get_documents_for_minhash_by_doc_pks(self, doc_pks):
        ids = list(dict.fromkeys(doc_pks))
        if not ids:
            return []
        with self._cursor() as cur:
            cur.execute(
                """SELECT d.doc_pk, d.external_id, t.normalized_title, t.normalized_content
                   FROM dedup_content.document_fingerprint d
                   LEFT JOIN dedup_content.document_text t ON t.doc_pk = d.doc_pk
                   WHERE d.doc_pk = ANY(%s)""",
                (ids,),
            )
            rows = cur.fetchall()
        by_id = {row["doc_pk"]: row for row in rows}
        return [by_id[value] for value in ids if value in by_id]

    def get_primary_texts(self, doc_pks):
        missing = [value for value in doc_pks if value not in self.doc_cache]
        if missing:
            with self._cursor() as cur:
                cur.execute(
                    """SELECT t.doc_pk, t.primary_text, d.external_id
                       FROM dedup_content.document_text t
                       JOIN dedup_content.document_fingerprint d ON d.doc_pk = t.doc_pk
                       WHERE t.doc_pk = ANY(%s)""",
                    (missing,),
                )
                for row in cur.fetchall():
                    self.doc_cache[row["doc_pk"]] = row
        return [self.doc_cache[value] for value in doc_pks if value in self.doc_cache]

    def _simhash_candidates(self, band_index, band_value, scope, limit):
        table = self._leaf_table(scope, "simhash", band_index)
        hi = "title_simhash_hi" if scope == "title" else "simhash_hi"
        lo = "title_simhash_lo" if scope == "title" else "simhash_lo"
        with self._cursor() as cur:
            cur.execute(
                f"""SELECT %s::smallint AS band_index, b.band_value, b.doc_pk, d.external_id,
                           d.{hi} AS simhash_hi, d.{lo} AS simhash_lo
                    FROM {table} b JOIN dedup_content.document_fingerprint d ON d.doc_pk = b.doc_pk
                    WHERE b.band_value = %s LIMIT %s""",
                (band_index, band_value_to_uint64(band_value), limit),
            )
            rows = [dict(row) for row in cur.fetchall()]
        for row in rows:
            row["simhash_hi"] = int64_to_uint64(row["simhash_hi"])
            row["simhash_lo"] = int64_to_uint64(row["simhash_lo"])
        return rows

    def find_simhash_candidates(self, band_index, band_value, max_candidates):
        return self._simhash_candidates(band_index, band_value, "content", max_candidates)

    def find_simhash_candidates_for_bands(self, bands, scope="content", max_candidates_per_band=None):
        cache = self.title_sim_candidate_cache if scope == "title" else self.sim_candidate_cache
        result = {}
        missing = []
        for band_index, band_value in bands:
            key = (int(band_index), band_value_to_uint64(band_value))
            rows = cache.get(key)
            if rows is not None and (max_candidates_per_band is None or len(rows) <= max_candidates_per_band):
                result[key] = rows
            else:
                # Validate indexes before using them to construct trusted table names.
                self._leaf_table(scope, "simhash", key[0])
                missing.append(key)
        if missing:
            limit = max_candidates_per_band or 2147483647
            hi = "title_simhash_hi" if scope == "title" else "simhash_hi"
            lo = "title_simhash_lo" if scope == "title" else "simhash_lo"
            selects, params = [], []
            for band_index, band_value in missing:
                table = self._leaf_table(scope, "simhash", band_index)
                selects.append(
                    f"(SELECT %s::smallint AS band_index, b.band_value, b.doc_pk, d.external_id, "
                    f"d.{hi} AS simhash_hi, d.{lo} AS simhash_lo FROM {table} b "
                    "JOIN dedup_content.document_fingerprint d ON d.doc_pk = b.doc_pk "
                    "WHERE b.band_value = %s ORDER BY b.doc_pk LIMIT %s)"
                )
                params.extend((band_index, band_value, limit))
            grouped = {key: [] for key in missing}
            with self._cursor() as cur:
                cur.execute(" UNION ALL ".join(selects), params)
                for raw_row in cur.fetchall():
                    row = dict(raw_row)
                    row["simhash_hi"] = int64_to_uint64(row["simhash_hi"])
                    row["simhash_lo"] = int64_to_uint64(row["simhash_lo"])
                    grouped[(int(row["band_index"]), int(row["band_value"]))].append(row)
            for key, rows in grouped.items():
                if max_candidates_per_band is None:
                    cache[key] = rows
                result[key] = rows
        return result

    def _minhash_candidates(self, band_index, band_value, scope, limit):
        table = self._leaf_table(scope, "minhash", band_index)
        with self._cursor() as cur:
            cur.execute(
                f"SELECT doc_pk FROM {table} WHERE band_value = %s LIMIT %s",
                (uint64_to_int64(band_value_to_uint64(band_value)), limit),
            )
            return [row["doc_pk"] for row in cur.fetchall()]

    def find_minhash_candidate_ids(self, band_index, band_value, max_candidates):
        return self._minhash_candidates(band_index, band_value, "content", max_candidates)

    def find_minhash_candidate_ids_for_bands(self, bands, scope="content", max_candidates_per_band=None):
        cache = self.title_mh_candidate_cache if scope == "title" else self.mh_candidate_cache
        result = {}
        missing = []
        for band_index, band_value in bands:
            key = (int(band_index), band_value_to_uint64(band_value))
            rows = cache.get(key)
            if rows is not None and (max_candidates_per_band is None or len(rows) <= max_candidates_per_band):
                result[key] = rows
            else:
                self._leaf_table(scope, "minhash", key[0])
                missing.append(key)
        if missing:
            limit = max_candidates_per_band or 2147483647
            selects, params = [], []
            for band_index, band_value in missing:
                table = self._leaf_table(scope, "minhash", band_index)
                selects.append(
                    f"(SELECT %s::smallint AS band_index, band_value, doc_pk FROM {table} "
                    "WHERE band_value = %s ORDER BY doc_pk LIMIT %s)"
                )
                params.extend((band_index, uint64_to_int64(band_value), limit))
            grouped = {key: [] for key in missing}
            with self._cursor() as cur:
                cur.execute(" UNION ALL ".join(selects), params)
                for row in cur.fetchall():
                    key = (int(row["band_index"]), int64_to_uint64(row["band_value"]))
                    grouped[key].append(row["doc_pk"])
            for key, rows in grouped.items():
                if max_candidates_per_band is None:
                    cache[key] = rows
                result[key] = rows
        return result

    def _insert_band_rows(self, cur, scope, kind, rows, copy_threshold=1000):
        grouped = defaultdict(set)
        for band_index, band_value, doc_pk in rows:
            stored = band_value_to_uint64(band_value)
            if kind == "minhash":
                stored = uint64_to_int64(stored)
            grouped[int(band_index)].add((stored, int(doc_pk)))
        for band_index, values in grouped.items():
            table = self._leaf_table(scope, kind, band_index)
            ordered = sorted(values, key=lambda item: item[1])
            if len(ordered) >= copy_threshold:
                with cur.copy(f"COPY {table} (band_index, band_value, doc_pk) FROM STDIN") as copy:
                    for band_value, doc_pk in ordered:
                        copy.write_row((band_index, band_value, doc_pk))
            else:
                sql = (
                    f"INSERT INTO {table} (band_index, band_value, doc_pk) "
                    "VALUES (%s, %s, %s) ON CONFLICT (band_value, doc_pk) DO NOTHING"
                )
                if len(ordered) == 1:
                    band_value, doc_pk = ordered[0]
                    cur.execute(sql, (band_index, band_value, doc_pk))
                else:
                    cur.executemany(
                        sql,
                        [(band_index, band_value, doc_pk) for band_value, doc_pk in ordered],
                    )

    def _insert_new_document_bands(self, cur, scope, kind, bands, doc_pk):
        """Insert one new document's bands with one command per parent table."""
        if not bands:
            return 0
        table = self._table(scope, kind)
        prepared = []
        for band_index, band_value in bands:
            self._leaf_table(scope, kind, band_index)
            stored = band_value_to_uint64(band_value)
            if kind == "minhash":
                stored = uint64_to_int64(stored)
            prepared.append((int(band_index), stored))
        columns = tuple(zip(*prepared))
        cur.execute(
            f"INSERT INTO dedup_content.{table} (band_index, band_value, doc_pk) "
            "SELECT band_index, band_value, %s FROM unnest(%s::smallint[], %s::bigint[]) "
            "AS value(band_index, band_value)",
            (int(doc_pk), list(columns[0]), list(columns[1])),
        )
        return len(prepared)

    def ensure_bands(self, external_id, sim_bands, mh_bands, scope="content"):
        doc_pk = self.doc_id_cache.get(external_id)
        with self._cursor() as cur:
            if doc_pk is None:
                cur.execute("SELECT doc_pk FROM dedup_content.document_fingerprint WHERE external_id = %s", (external_id,))
                row = cur.fetchone()
                if not row:
                    return
                doc_pk = row["doc_pk"]
                self.doc_id_cache[external_id] = doc_pk
            self._insert_band_rows(cur, scope, "simhash", [(i, v, doc_pk) for i, v in sim_bands], copy_threshold=10**9)
            self._insert_band_rows(cur, scope, "minhash", [(i, v, doc_pk) for i, v in mh_bands], copy_threshold=10**9)
        self.conn.commit()

    def insert_document_fast(self, external_id, source_from, content_hash_bin, title_hash_bin, raw_hash_bin,
                             simhash_hi, simhash_lo, title_simhash_hi, title_simhash_lo, low_information,
                             created_at, normalized_title, normalized_content, primary_text, sim_bands,
                             mh_bands, title_sim_bands, title_mh_bands):
        timings = defaultdict(float)
        metrics = {"post_doc_pipeline_commands": 0, "post_doc_pipeline_rows": 0, "band_rows": {}}
        with self._cursor() as cur:
            started = time.perf_counter()
            cur.execute(
                """INSERT INTO dedup_content.document_fingerprint
                   (external_id, source_from, content_hash, title_hash, raw_hash, simhash_hi, simhash_lo,
                    title_simhash_hi, title_simhash_lo, low_information, created_at)
                   VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                   ON CONFLICT DO NOTHING RETURNING doc_pk""",
                (external_id, source_from or "", content_hash_bin, title_hash_bin, raw_hash_bin,
                 uint64_to_int64(simhash_hi), uint64_to_int64(simhash_lo),
                 uint64_to_int64(title_simhash_hi) if title_simhash_hi is not None else None,
                 uint64_to_int64(title_simhash_lo) if title_simhash_lo is not None else None,
                 bool(low_information), self._utc_timestamp(created_at)),
            )
            row = cur.fetchone()
            inserted = row is not None
            if row is None:
                cur.execute("SELECT doc_pk FROM dedup_content.document_fingerprint WHERE external_id = %s", (external_id,))
                row = cur.fetchone()
                if row is None:
                    cur.execute("SELECT doc_pk FROM dedup_content.document_fingerprint WHERE content_hash = %s", (content_hash_bin,))
                    row = cur.fetchone()
            doc_pk = row["doc_pk"]
            timings["doc_insert"] = time.perf_counter() - started
            if inserted:
                # The fingerprint must be read first to obtain doc_pk.  Once
                # it exists, queue text and all direct-leaf band writes in one
                # psycopg pipeline, avoiding a network round trip per leaf.
                stage = time.perf_counter()
                with self.conn.pipeline():
                    cur.execute(
                        """INSERT INTO dedup_content.document_text (doc_pk, normalized_title, normalized_content, primary_text)
                           VALUES (%s, %s, %s, %s) ON CONFLICT (doc_pk) DO NOTHING""",
                        (doc_pk, normalized_title, normalized_content, primary_text),
                    )
                    for scope, kind, bands in (
                        ("content", "simhash", sim_bands),
                        ("content", "minhash", mh_bands),
                        ("title", "simhash", title_sim_bands),
                        ("title", "minhash", title_mh_bands),
                    ):
                        metric_key = f"{scope}_{kind}"
                        metrics["band_rows"][metric_key] = len(bands)
                        metrics["post_doc_pipeline_rows"] += len(bands)
                        if bands:
                            metrics["post_doc_pipeline_commands"] += 1
                            self._insert_new_document_bands(cur, scope, kind, bands, doc_pk)
                timings["text_and_band_pipeline"] = time.perf_counter() - stage
                metrics["post_doc_pipeline_rows"] += 1
                metrics["post_doc_pipeline_commands"] += 1
        stage = time.perf_counter()
        self.conn.commit()
        timings["commit"] = time.perf_counter() - stage
        self.last_flush_timings = dict(timings)
        self.last_flush_metrics = metrics
        self.doc_id_cache[external_id] = doc_pk
        self.content_cache[bytes(content_hash_bin).hex()] = external_id
        if title_hash_bin is not None:
            self.title_cache[bytes(title_hash_bin).hex()] = external_id
        self.raw_cache[bytes(raw_hash_bin).hex()] = external_id
        return inserted, doc_pk

    def flush_documents(self, doc_rows, text_rows, sim_rows, mh_rows, title_sim_rows=None, title_mh_rows=None):
        """Write a batch with set-based id resolution and direct leaf COPY.

        PostgreSQL is particularly good at moving typed arrays into a CTE.  This
        keeps the hot fingerprint path to one server round trip, including the
        resolution of rows skipped by either unique constraint.  Band writes are
        still routed to their leaf partitions, where ``COPY`` is appropriate.
        """
        title_sim_rows, title_mh_rows = title_sim_rows or [], title_mh_rows or []
        if not doc_rows:
            self.last_flush_timings = {}
            self.last_flush_metrics = {}
            return set(), 0
        normalized = [row if len(row) >= 11 else (row[0], row[1], row[2], None, row[3], row[4], row[5], None, None, row[6], row[7]) for row in doc_rows]
        resolved, inserted_ids = {}, set()
        started = time.perf_counter()
        with self._cursor() as cur:
            columns = tuple(zip(*normalized))
            cur.execute(
                """WITH input AS (
                       SELECT *
                       FROM unnest(
                           %s::varchar[], %s::varchar[], %s::bytea[], %s::bytea[], %s::bytea[],
                           %s::bigint[], %s::bigint[], %s::bigint[], %s::bigint[], %s::boolean[], %s::timestamptz[]
                       ) AS value(
                           external_id, source_from, content_hash, title_hash, raw_hash,
                           simhash_hi, simhash_lo, title_simhash_hi, title_simhash_lo, low_information, created_at
                       )
                   ), inserted AS (
                       INSERT INTO dedup_content.document_fingerprint (
                           external_id, source_from, content_hash, title_hash, raw_hash, simhash_hi, simhash_lo,
                           title_simhash_hi, title_simhash_lo, low_information, created_at
                       )
                       SELECT external_id, source_from, content_hash, title_hash, raw_hash, simhash_hi, simhash_lo,
                              title_simhash_hi, title_simhash_lo, low_information, created_at
                       FROM input
                       ON CONFLICT DO NOTHING
                       RETURNING external_id, doc_pk
                   )
                   SELECT input.external_id,
                          COALESCE(inserted.doc_pk, by_external.doc_pk, by_content.doc_pk) AS doc_pk,
                          inserted.doc_pk IS NOT NULL AS inserted
                   FROM input
                   LEFT JOIN inserted USING (external_id)
                   LEFT JOIN dedup_content.document_fingerprint AS by_external
                       ON by_external.external_id = input.external_id
                   LEFT JOIN dedup_content.document_fingerprint AS by_content
                       ON by_content.content_hash = input.content_hash""",
                (
                    list(columns[0]),
                    [value or "" for value in columns[1]],
                    list(columns[2]),
                    list(columns[3]),
                    list(columns[4]),
                    [uint64_to_int64(value) for value in columns[5]],
                    [uint64_to_int64(value) for value in columns[6]],
                    [uint64_to_int64(value) if value is not None else None for value in columns[7]],
                    [uint64_to_int64(value) if value is not None else None for value in columns[8]],
                    [bool(value) for value in columns[9]],
                    [self._utc_timestamp(value) for value in columns[10]],
                ),
            )
            for row in cur.fetchall():
                if row["doc_pk"] is not None:
                    resolved[row["external_id"]] = row["doc_pk"]
                    if row["inserted"]:
                        inserted_ids.add(row["external_id"])
            # A concurrent transaction can make ``ON CONFLICT`` skip a row
            # that is invisible to this statement's snapshot.  Re-read only
            # those rows in a fresh statement; the normal path stays at one
            # round trip while retaining the former per-row correctness.
            unresolved = [row for row in normalized if row[0] not in resolved]
            if unresolved:
                unresolved_ids = [row[0] for row in unresolved]
                unresolved_hashes = [row[2] for row in unresolved]
                cur.execute(
                    """SELECT doc_pk, external_id, content_hash
                       FROM dedup_content.document_fingerprint
                       WHERE external_id = ANY(%s) OR content_hash = ANY(%s)""",
                    (unresolved_ids, unresolved_hashes),
                )
                by_external, by_content = {}, {}
                for row in cur.fetchall():
                    by_external[row["external_id"]] = row["doc_pk"]
                    by_content[bytes(row["content_hash"])] = row["doc_pk"]
                for external_id, _, content_hash, *_ in unresolved:
                    doc_pk = by_external.get(external_id) or by_content.get(bytes(content_hash))
                    if doc_pk is not None:
                        resolved[external_id] = doc_pk
            text_by_id = {row[0]: row for row in text_rows}
            text_values = []
            for external_id, doc_pk in resolved.items():
                if external_id in inserted_ids and external_id in text_by_id:
                    row = text_by_id[external_id]
                    text_values.append((doc_pk, row[1] if len(row) >= 4 else None, row[2] if len(row) >= 4 else None, row[-1]))
            if text_values:
                text_columns = tuple(zip(*text_values))
                cur.execute(
                    """INSERT INTO dedup_content.document_text
                       (doc_pk, normalized_title, normalized_content, primary_text)
                       SELECT * FROM unnest(%s::bigint[], %s::text[], %s::text[], %s::text[])
                       ON CONFLICT (doc_pk) DO NOTHING""",
                    tuple(list(column) for column in text_columns),
                )
            band_row_counts = {}
            for scope, kind, rows in (("content", "simhash", sim_rows), ("content", "minhash", mh_rows), ("title", "simhash", title_sim_rows), ("title", "minhash", title_mh_rows)):
                prepared = [(band, value, resolved[external_id]) for external_id, band, value in rows if external_id in inserted_ids]
                band_row_counts[f"{scope}_{kind}"] = len(prepared)
                self._insert_band_rows(cur, scope, kind, prepared)
        self.conn.commit()
        self.last_flush_timings = {"total": time.perf_counter() - started}
        self.last_flush_metrics = {
            "batch_documents": len(normalized),
            "inserted_documents": len(inserted_ids),
            "band_rows": band_row_counts,
        }
        self._remember_flushed(normalized, resolved)
        return set(resolved), len(normalized) - len(resolved)

    def _remember_flushed(self, doc_rows, resolved):
        for row in doc_rows:
            external_id = row[0]
            if external_id not in resolved:
                continue
            self.doc_id_cache[external_id] = resolved[external_id]
            self.content_cache[bytes(row[2]).hex()] = external_id

    def delete_document(self, external_id):
        with self._cursor() as cur:
            cur.execute("SELECT doc_pk, content_hash, title_hash, raw_hash FROM dedup_content.document_fingerprint WHERE external_id = %s", (external_id,))
            row = cur.fetchone()
            if not row:
                return False
            doc_pk = row["doc_pk"]
            for table in ("simhash_band", "minhash_band", "title_simhash_band", "title_minhash_band"):
                cur.execute(f"DELETE FROM dedup_content.{table} WHERE doc_pk = %s", (doc_pk,))
            cur.execute("DELETE FROM dedup_content.document_fingerprint WHERE doc_pk = %s", (doc_pk,))
        self.conn.commit()
        self._evict_document_caches(external_id, row["content_hash"], row["title_hash"], row["raw_hash"], doc_pk)
        return True

    def delete_expired_documents(self, retention_days, batch_size=1000, max_batches=10, lock_timeout=0):
        if retention_days <= 0:
            return {"deleted": 0, "external_ids": [], "locked": False}
        lock_key = 703211991
        deadline = time.monotonic() + lock_timeout
        locked = False
        with self._cursor() as cur:
            while True:
                cur.execute("SELECT pg_try_advisory_lock(%s) AS locked", (lock_key,))
                if cur.fetchone()["locked"]:
                    locked = True
                    break
                if time.monotonic() >= deadline:
                    return {"deleted": 0, "external_ids": [], "locked": False}
                time.sleep(0.1)
        deleted, external_ids = 0, []
        try:
            cutoff = datetime.now(timezone.utc) - timedelta(days=retention_days)
            for _ in range(max_batches):
                with self._cursor() as cur:
                    cur.execute(
                        """WITH victims AS MATERIALIZED (
                               SELECT doc_pk
                               FROM dedup_content.document_fingerprint
                               WHERE created_at < %s
                               ORDER BY created_at, doc_pk
                               LIMIT %s
                               FOR UPDATE SKIP LOCKED
                           )
                           DELETE FROM dedup_content.document_fingerprint AS document
                           USING victims
                           WHERE document.doc_pk = victims.doc_pk
                           RETURNING document.doc_pk, document.external_id,
                                     document.content_hash, document.title_hash, document.raw_hash""",
                        (cutoff, batch_size),
                    )
                    rows = cur.fetchall()
                    if not rows:
                        break
                    doc_pks = [row["doc_pk"] for row in rows]
                    # document_text is removed by its FK ON DELETE CASCADE.
                    # The LSH tables deliberately have no FK, so keep these
                    # deletes targeted at exactly the returned document ids.
                    for table in ("simhash_band", "minhash_band", "title_simhash_band", "title_minhash_band"):
                        cur.execute(f"DELETE FROM dedup_content.{table} WHERE doc_pk = ANY(%s)", (doc_pks,))
                self.conn.commit()
                for row in rows:
                    self._evict_document_caches(row["external_id"], row["content_hash"], row["title_hash"], row["raw_hash"], row["doc_pk"])
                    external_ids.append(row["external_id"])
                deleted += len(rows)
                if len(rows) < batch_size:
                    break
            return {"deleted": deleted, "external_ids": external_ids, "locked": True}
        except Exception:
            self.conn.rollback()
            raise
        finally:
            if locked:
                with self._cursor() as cur:
                    cur.execute("SELECT pg_advisory_unlock(%s)", (lock_key,))
                self.conn.commit()
