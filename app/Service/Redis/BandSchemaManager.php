<?php

declare(strict_types=1);

namespace App\Service\Redis;

use App\Service\DedupeParameters;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\ConnectionResolverInterface;
use InvalidArgumentException;

use function Hyperf\Config\config;

final class BandSchemaManager
{
    public function __construct(private readonly ConnectionResolverInterface $connections)
    {
    }

    /** @return array<string, array{rows: int, missing_created_at: int}> */
    public function inspect(): array
    {
        $connection = $this->connection();
        $result = [];
        foreach ($this->tables() as $table => $_) {
            $qualified = $this->qualified($table);
            $present = $connection->selectOne(
                'SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?) AS present',
                [$this->schema(), $table, 'created_at'],
            )->present;
            $hasColumn = in_array($present, [true, 1, '1', 't', 'true'], true);
            $rows = (int) round((float) $connection->selectOne(
                'SELECT COALESCE(sum(child.reltuples), 0) AS count
                 FROM pg_inherits
                 JOIN pg_class parent ON parent.oid = pg_inherits.inhparent
                 JOIN pg_namespace ns ON ns.oid = parent.relnamespace
                 JOIN pg_class child ON child.oid = pg_inherits.inhrelid
                 WHERE ns.nspname = ? AND parent.relname = ?',
                [$this->schema(), $table],
            )->count);
            // inspect 是低成本估算；精确空值扫描仅在 validate 阶段执行。
            $missing = $hasColumn ? -1 : $rows;
            $result[$table] = ['rows' => $rows, 'missing_created_at' => $missing];
        }
        return $result;
    }

    public function addColumn(): void
    {
        $connection = $this->connection();
        foreach (array_keys($this->tables()) as $table) {
            $connection->statement("ALTER TABLE {$this->qualified($table)} ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ");
        }
    }

    /** @param callable(string, int): void|null $progress */
    public function backfill(int $batchSize = 10000, ?callable $progress = null): void
    {
        $connection = $this->connection();
        $document = $this->qualified('document_fingerprint');
        foreach ($this->tables() as $parent => $partitions) {
            for ($index = 0; $index < $partitions; ++$index) {
                $leafName = "{$parent}_p{$index}";
                $leaf = $this->qualified($leafName);
                $total = 0;
                do {
                    $affected = $connection->affectingStatement(
                        "UPDATE {$leaf} AS b
                         SET created_at = d.created_at
                         FROM {$document} AS d
                         WHERE b.ctid IN (
                             SELECT x.ctid
                             FROM {$leaf} AS x
                             JOIN {$document} AS source_document ON source_document.doc_pk = x.doc_pk
                             WHERE x.created_at IS NULL
                             LIMIT ?
                         )
                           AND d.doc_pk = b.doc_pk",
                        [max(1, $batchSize)],
                    );
                    $total += $affected;
                    $progress?->__invoke($leafName, $total);
                } while ($affected > 0);
            }
        }
    }

    /** @param callable(string): void|null $progress */
    public function createIndexes(?callable $progress = null): void
    {
        $connection = $this->connection();
        foreach ($this->tables() as $parent => $partitions) {
            for ($index = 0; $index < $partitions; ++$index) {
                $leaf = "{$parent}_p{$index}";
                $name = "{$leaf}_value_created_doc_idx";
                $existing = $connection->selectOne(
                    'SELECT pg_index.indisvalid AS valid
                     FROM pg_class index_class
                     JOIN pg_namespace ns ON ns.oid = index_class.relnamespace
                     JOIN pg_index ON pg_index.indexrelid = index_class.oid
                     WHERE ns.nspname = ? AND index_class.relname = ?',
                    [$this->schema(), $name],
                );
                if ($existing !== null && !in_array($existing->valid, [true, 1, '1', 't', 'true'], true)) {
                    $connection->statement(sprintf('DROP INDEX CONCURRENTLY "%s"."%s"', $this->schema(), $name));
                    $existing = null;
                }
                if ($existing === null) {
                    $connection->statement(
                        sprintf('CREATE INDEX CONCURRENTLY "%s" ON %s (band_value, created_at, doc_pk)', $name, $this->qualified($leaf)),
                    );
                }
                $progress?->__invoke($leaf);
            }
        }
    }

    public function enforce(): void
    {
        if (!(bool) config('dedupe.redis_index.band_created_at_write_enabled', false)) {
            throw new \RuntimeException('Enable DEDUPE_BAND_CREATED_AT_WRITE_ENABLED before enforcing NOT NULL.');
        }
        $connection = $this->connection();
        foreach (array_keys($this->tables()) as $table) {
            $qualified = $this->qualified($table);
            $connection->statement("ALTER TABLE {$qualified} ALTER COLUMN created_at SET DEFAULT CURRENT_TIMESTAMP");
            $connection->statement("ALTER TABLE {$qualified} ALTER COLUMN created_at SET NOT NULL");
        }
    }

    public function validate(): array
    {
        $connection = $this->connection();
        $result = [];
        foreach ($this->tables() as $table => $_) {
            $qualified = $this->qualified($table);
            $row = $connection->selectOne("SELECT count(*) AS rows, count(*) FILTER (WHERE created_at IS NULL) AS missing FROM {$qualified}");
            $result[$table] = ['rows' => (int) $row->rows, 'missing_created_at' => (int) $row->missing];
            if ($result[$table]['missing_created_at'] !== 0) {
                throw new \RuntimeException("{$table} still has {$result[$table]['missing_created_at']} rows without created_at.");
            }
        }
        return $result;
    }

    private function connection(): ConnectionInterface
    {
        /** @var ConnectionInterface $connection */
        $connection = $this->connections->connection('rebuild');
        $connection->statement("SET application_name = 'dedupe-rebuild'");
        $connection->statement("SET lock_timeout = '3s'");
        $connection->statement("SET idle_in_transaction_session_timeout = '60s'");
        return $connection;
    }

    /** @return array<string, int> */
    private function tables(): array
    {
        return [
            'simhash_band' => DedupeParameters::simhashBands(),
            'title_simhash_band' => DedupeParameters::simhashBands(),
            'minhash_band' => DedupeParameters::minhashBands(),
            'title_minhash_band' => DedupeParameters::minhashBands(),
        ];
    }

    private function schema(): string
    {
        $schema = (string) config('dedupe.database.schema', 'dedup_content');
        if (preg_match('/\A[a-z_][a-z0-9_]*\z/i', $schema) !== 1) {
            throw new InvalidArgumentException('Invalid PostgreSQL schema.');
        }
        return $schema;
    }

    private function qualified(string $table): string
    {
        if (preg_match('/\A[a-z_][a-z0-9_]*\z/i', $table) !== 1) {
            throw new InvalidArgumentException('Invalid PostgreSQL table name.');
        }
        return sprintf('"%s"."%s"', $this->schema(), $table);
    }
}
