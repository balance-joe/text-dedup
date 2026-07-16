// query-server only reproduces the PostgreSQL read path of /dedupe/check.
// Fingerprint calculation and similarity scoring intentionally stay out of this binary.
package main

import (
	"context"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"net/http"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
)

const defaultSchema = "dedup_content"

type api struct {
	pool   *pgxpool.Pool
	schema string
}

type timing struct {
	PoolAcquireMS   float64 `json:"pool_acquire_ms"`
	SQLMS           float64 `json:"sql_ms"`
	ResultMappingMS float64 `json:"result_mapping_ms"`
}

type prefilterRequest struct {
	ExternalID  string  `json:"id"`
	RawHash     string  `json:"raw_hash"`
	ContentHash string  `json:"content_hash"`
	TitleHash   *string `json:"title_hash,omitempty"`
}

type prefilterMatch struct {
	Priority       int     `json:"priority"`
	ConflictReason string  `json:"conflict_reason"`
	DocPK          int64   `json:"doc_pk"`
	ExternalID     string  `json:"external_id"`
	RawHash        string  `json:"raw_hash"`
	ContentHash    string  `json:"content_hash"`
	TitleHash      *string `json:"title_hash"`
}

type simhashBand struct {
	Index int             `json:"index"`
	Value json.RawMessage `json:"value"`
}

type minhashBand struct {
	Index int    `json:"index"`
	Value string `json:"value"`
}

type candidateRequest struct {
	Scope                string          `json:"scope"`
	Bands                json.RawMessage `json:"bands"`
	MaxCandidatesPerBand int             `json:"max_candidates_per_band"`
}

type simhashCandidate struct {
	BandIndex  int    `json:"band_index"`
	BandValue  int    `json:"band_value"`
	DocPK      int64  `json:"doc_pk"`
	ExternalID string `json:"external_id"`
	SourceFrom string `json:"source_from"`
	SimhashHi  int64  `json:"simhash_hi"`
	SimhashLo  int64  `json:"simhash_lo"`
}

type documentsRequest struct {
	DocPKs []int64 `json:"doc_pks"`
}

type document struct {
	DocPK             int64   `json:"doc_pk"`
	ExternalID        string  `json:"external_id"`
	SourceFrom        string  `json:"source_from"`
	RawHash           string  `json:"raw_hash"`
	ContentHash       string  `json:"content_hash"`
	TitleHash         *string `json:"title_hash"`
	NormalizedTitle   string  `json:"normalized_title"`
	NormalizedContent string  `json:"normalized_content"`
}

func main() {
	ctx := context.Background()
	pool, err := pgxpool.New(ctx, databaseURL())
	if err != nil {
		log.Fatal(err)
	}
	defer pool.Close()
	if err := pool.Ping(ctx); err != nil {
		log.Fatalf("postgres ping: %v", err)
	}

	a := &api{pool: pool, schema: env("DEDUPE_DB_SCHEMA", env("DB_SCHEMA", defaultSchema))}
	if comma := strings.IndexByte(a.schema, ','); comma >= 0 {
		a.schema = strings.TrimSpace(a.schema[:comma])
	}
	if !identifier(a.schema) {
		log.Fatalf("invalid schema %q", a.schema)
	}

	mux := http.NewServeMux()
	mux.HandleFunc("GET /health", a.health)
	mux.HandleFunc("POST /dedupe/check", a.checkHandler)
	mux.HandleFunc("POST /dedupe/query/prefilter", a.prefilter)
	mux.HandleFunc("POST /dedupe/query/simhash-candidates", a.simhashCandidates)
	mux.HandleFunc("POST /dedupe/query/minhash-candidates", a.minhashCandidates)
	mux.HandleFunc("POST /dedupe/query/documents", a.documents)
	addr := env("LISTEN_ADDR", ":8009")
	log.Printf("dedupe PostgreSQL query server listening on %s", addr)
	log.Fatal(http.ListenAndServe(addr, mux))
}

func (a *api) checkHandler(w http.ResponseWriter, r *http.Request) {
	var req checkRequest
	if err := decode(r, &req); err != nil {
		badRequest(w, err)
		return
	}
	data, err := a.check(r.Context(), req)
	if err != nil {
		badRequest(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": 1, "msg": "", "data": data})
}

func (a *api) health(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{"status": 1, "data": map[string]string{"db": "postgres"}})
}

func (a *api) prefilter(w http.ResponseWriter, r *http.Request) {
	var req prefilterRequest
	if err := decode(r, &req); err != nil {
		badRequest(w, err)
		return
	}
	match, t, err := a.findPrefilter(r.Context(), req)
	if err != nil {
		badRequest(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": 1, "data": map[string]any{"match": match, "timings": t}})
}

func (a *api) simhashCandidates(w http.ResponseWriter, r *http.Request) {
	var req candidateRequest
	if err := decode(r, &req); err != nil {
		badRequest(w, err)
		return
	}
	var bands []simhashBand
	if err := json.Unmarshal(req.Bands, &bands); err != nil {
		badRequest(w, errors.New("bands must be an array of {index,value}"))
		return
	}
	rows, t, err := a.findSimhashCandidates(r.Context(), req.Scope, bands, req.MaxCandidatesPerBand)
	if err != nil {
		badRequest(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": 1, "data": map[string]any{"candidates": rows, "timings": t}})
}

func (a *api) minhashCandidates(w http.ResponseWriter, r *http.Request) {
	var req candidateRequest
	if err := decode(r, &req); err != nil {
		badRequest(w, err)
		return
	}
	var bands []minhashBand
	if err := json.Unmarshal(req.Bands, &bands); err != nil {
		badRequest(w, errors.New("bands must be an array of {index,value}"))
		return
	}
	rows, t, err := a.findMinhashCandidates(r.Context(), req.Scope, bands, req.MaxCandidatesPerBand)
	if err != nil {
		badRequest(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": 1, "data": map[string]any{"candidate_doc_pks": rows, "timings": t}})
}

func (a *api) documents(w http.ResponseWriter, r *http.Request) {
	var req documentsRequest
	if err := decode(r, &req); err != nil {
		badRequest(w, err)
		return
	}
	rows, t, err := a.findDocuments(r.Context(), req.DocPKs)
	if err != nil {
		badRequest(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": 1, "data": map[string]any{"documents": rows, "timings": t}})
}

func (a *api) findPrefilter(ctx context.Context, req prefilterRequest) (*prefilterMatch, timing, error) {
	raw, err := hashBytes(req.RawHash)
	if err != nil {
		return nil, timing{}, fmt.Errorf("raw_hash: %w", err)
	}
	content, err := hashBytes(req.ContentHash)
	if err != nil {
		return nil, timing{}, fmt.Errorf("content_hash: %w", err)
	}
	args := []any{req.ExternalID, raw, content}
	parts := []string{
		"SELECT 1 AS priority, 'external_id' AS conflict_reason, doc_pk, external_id, raw_hash, content_hash, title_hash FROM " + a.table("document_fingerprint") + " WHERE external_id = $1",
		"SELECT 2 AS priority, 'raw_hash' AS conflict_reason, doc_pk, external_id, raw_hash, content_hash, title_hash FROM " + a.table("document_fingerprint") + " WHERE raw_hash = $2",
		"SELECT 3 AS priority, 'content_hash' AS conflict_reason, doc_pk, external_id, raw_hash, content_hash, title_hash FROM " + a.table("document_fingerprint") + " WHERE content_hash = $3",
	}
	if req.TitleHash != nil {
		title, err := hashBytes(*req.TitleHash)
		if err != nil {
			return nil, timing{}, fmt.Errorf("title_hash: %w", err)
		}
		args = append(args, title)
		parts = append(parts, "SELECT 4 AS priority, 'title_hash' AS conflict_reason, doc_pk, external_id, raw_hash, content_hash, title_hash FROM "+a.table("document_fingerprint")+" WHERE title_hash = $4")
	}
	sql := "SELECT priority, conflict_reason, doc_pk, external_id, raw_hash, content_hash, title_hash FROM (" + strings.Join(parts, " UNION ALL ") + ") AS prefilter ORDER BY priority ASC, doc_pk ASC LIMIT 1"
	conn, acquired, err := a.acquire(ctx)
	if err != nil {
		return nil, timing{}, err
	}
	defer conn.Release()
	started := time.Now()
	row := conn.QueryRow(ctx, sql, args...)
	var out prefilterMatch
	var rawHash, contentHash []byte
	var titleHash []byte
	err = row.Scan(&out.Priority, &out.ConflictReason, &out.DocPK, &out.ExternalID, &rawHash, &contentHash, &titleHash)
	t := timing{PoolAcquireMS: ms(acquired), SQLMS: ms(time.Since(started))}
	mapped := time.Now()
	if errors.Is(err, pgx.ErrNoRows) {
		t.ResultMappingMS = ms(time.Since(mapped))
		return nil, t, nil
	}
	if err != nil {
		return nil, t, err
	}
	out.RawHash, out.ContentHash = hex.EncodeToString(rawHash), hex.EncodeToString(contentHash)
	if titleHash != nil {
		value := hex.EncodeToString(titleHash)
		out.TitleHash = &value
	}
	t.ResultMappingMS = ms(time.Since(mapped))
	return &out, t, nil
}

func (a *api) findSimhashCandidates(ctx context.Context, scope string, bands []simhashBand, limit int) ([]simhashCandidate, timing, error) {
	if scope == "" {
		scope = "content"
	}
	if scope != "content" && scope != "title" {
		return nil, timing{}, errors.New("scope must be content or title")
	}
	if limit < 1 {
		return nil, timing{}, errors.New("max_candidates_per_band must be positive")
	}
	if len(bands) == 0 {
		return []simhashCandidate{}, timing{}, nil
	}
	hi, lo, prefix := "simhash_hi", "simhash_lo", "simhash_band"
	if scope == "title" {
		hi, lo, prefix = "title_simhash_hi", "title_simhash_lo", "title_simhash_band"
	}
	parts, args := make([]string, 0, len(bands)), make([]any, 0, len(bands)*3)
	seen := map[int]bool{}
	for _, band := range bands {
		value, err := parseSimhashValue(band.Value)
		if err != nil {
			return nil, timing{}, err
		}
		if band.Index < 0 || band.Index > 7 {
			return nil, timing{}, errors.New("simhash band index must be 0-7")
		}
		if seen[band.Index] {
			continue
		}
		seen[band.Index] = true
		p := len(args)
		args = append(args, band.Index, value, limit)
		parts = append(parts, fmt.Sprintf("(SELECT $%d::smallint AS band_index, b.band_value, b.doc_pk, d.external_id, d.source_from, d.%s AS simhash_hi, d.%s AS simhash_lo FROM %s AS b JOIN %s AS d ON d.doc_pk = b.doc_pk WHERE b.band_value = $%d::integer ORDER BY b.doc_pk LIMIT $%d::integer)", p+1, hi, lo, a.table(prefix+"_p"+strconv.Itoa(band.Index)), a.table("document_fingerprint"), p+2, p+3))
	}
	return a.querySimhash(ctx, strings.Join(parts, " UNION ALL "), args)
}

func (a *api) findMinhashCandidates(ctx context.Context, scope string, bands []minhashBand, limit int) ([]int64, timing, error) {
	if scope == "" {
		scope = "content"
	}
	if scope != "content" && scope != "title" {
		return nil, timing{}, errors.New("scope must be content or title")
	}
	if limit < 1 {
		return nil, timing{}, errors.New("max_candidates_per_band must be positive")
	}
	if len(bands) == 0 {
		return []int64{}, timing{}, nil
	}
	prefix := "minhash_band"
	if scope == "title" {
		prefix = "title_minhash_band"
	}
	parts, args := make([]string, 0, len(bands)), make([]any, 0, len(bands)*3)
	seen := map[int]bool{}
	for _, band := range bands {
		value, err := strconv.ParseUint(band.Value, 10, 64)
		if err != nil {
			return nil, timing{}, errors.New("minhash values must be uint64 decimal strings")
		}
		if band.Index < 0 || band.Index > 31 {
			return nil, timing{}, errors.New("minhash band index must be 0-31")
		}
		if seen[band.Index] {
			continue
		}
		seen[band.Index] = true
		p := len(args)
		args = append(args, band.Index, int64(value), limit)
		parts = append(parts, fmt.Sprintf("(SELECT $%d::smallint AS band_index, b.band_value, b.doc_pk FROM %s AS b WHERE b.band_value = $%d::bigint ORDER BY b.doc_pk LIMIT $%d::integer)", p+1, a.table(prefix+"_p"+strconv.Itoa(band.Index)), p+2, p+3))
	}
	conn, acquired, err := a.acquire(ctx)
	if err != nil {
		return nil, timing{}, err
	}
	defer conn.Release()
	started := time.Now()
	rows, err := conn.Query(ctx, strings.Join(parts, " UNION ALL "), args...)
	if err != nil {
		return nil, timing{PoolAcquireMS: ms(acquired), SQLMS: ms(time.Since(started))}, err
	}
	defer rows.Close()
	out := []int64{}
	mapped := time.Now()
	for rows.Next() {
		var index int16
		var value int64
		var id int64
		if err := rows.Scan(&index, &value, &id); err != nil {
			return nil, timing{}, err
		}
		out = append(out, id)
	}
	if err := rows.Err(); err != nil {
		return nil, timing{}, err
	}
	return out, timing{PoolAcquireMS: ms(acquired), SQLMS: ms(time.Since(started)), ResultMappingMS: ms(time.Since(mapped))}, nil
}

func (a *api) querySimhash(ctx context.Context, sql string, args []any) ([]simhashCandidate, timing, error) {
	conn, acquired, err := a.acquire(ctx)
	if err != nil {
		return nil, timing{}, err
	}
	defer conn.Release()
	started := time.Now()
	rows, err := conn.Query(ctx, sql, args...)
	if err != nil {
		return nil, timing{PoolAcquireMS: ms(acquired), SQLMS: ms(time.Since(started))}, err
	}
	defer rows.Close()
	out := []simhashCandidate{}
	mapped := time.Now()
	for rows.Next() {
		var row simhashCandidate
		if err := rows.Scan(&row.BandIndex, &row.BandValue, &row.DocPK, &row.ExternalID, &row.SourceFrom, &row.SimhashHi, &row.SimhashLo); err != nil {
			return nil, timing{}, err
		}
		out = append(out, row)
	}
	if err := rows.Err(); err != nil {
		return nil, timing{}, err
	}
	return out, timing{PoolAcquireMS: ms(acquired), SQLMS: ms(time.Since(started)), ResultMappingMS: ms(time.Since(mapped))}, nil
}

func (a *api) findDocuments(ctx context.Context, ids []int64) ([]document, timing, error) {
	unique := make([]int64, 0, len(ids))
	seen := map[int64]bool{}
	for _, id := range ids {
		if id > 0 && !seen[id] {
			seen[id] = true
			unique = append(unique, id)
		}
	}
	if len(unique) == 0 {
		return []document{}, timing{}, nil
	}
	sql := "SELECT d.doc_pk, d.external_id, d.source_from, d.raw_hash, d.content_hash, d.title_hash, COALESCE(t.normalized_title, ''), COALESCE(t.normalized_content, '') FROM " + a.table("document_fingerprint") + " AS d LEFT JOIN " + a.table("document_text") + " AS t ON t.doc_pk = d.doc_pk WHERE d.doc_pk = ANY($1::bigint[])"
	conn, acquired, err := a.acquire(ctx)
	if err != nil {
		return nil, timing{}, err
	}
	defer conn.Release()
	started := time.Now()
	rows, err := conn.Query(ctx, sql, unique)
	if err != nil {
		return nil, timing{PoolAcquireMS: ms(acquired), SQLMS: ms(time.Since(started))}, err
	}
	defer rows.Close()
	out := []document{}
	mapped := time.Now()
	for rows.Next() {
		var row document
		var raw, content, title []byte
		if err := rows.Scan(&row.DocPK, &row.ExternalID, &row.SourceFrom, &raw, &content, &title, &row.NormalizedTitle, &row.NormalizedContent); err != nil {
			return nil, timing{}, err
		}
		row.RawHash, row.ContentHash = hex.EncodeToString(raw), hex.EncodeToString(content)
		if title != nil {
			value := hex.EncodeToString(title)
			row.TitleHash = &value
		}
		out = append(out, row)
	}
	if err := rows.Err(); err != nil {
		return nil, timing{}, err
	}
	return out, timing{PoolAcquireMS: ms(acquired), SQLMS: ms(time.Since(started)), ResultMappingMS: ms(time.Since(mapped))}, nil
}

func (a *api) acquire(ctx context.Context) (*pgxpool.Conn, time.Duration, error) {
	started := time.Now()
	conn, err := a.pool.Acquire(ctx)
	return conn, time.Since(started), err
}
func (a *api) table(name string) string { return `"` + a.schema + `"."` + name + `"` }
func hashBytes(value string) ([]byte, error) {
	if len(value) != 32 {
		return nil, errors.New("must be a 32-character MD5 hex string")
	}
	b, err := hex.DecodeString(value)
	return b, err
}
func parseSimhashValue(raw json.RawMessage) (int, error) {
	var text string
	if err := json.Unmarshal(raw, &text); err == nil {
		value, err := strconv.ParseUint(text, 16, 16)
		if err != nil {
			return 0, errors.New("simhash values must be hexadecimal uint16 strings")
		}
		return int(value), nil
	}
	var value int
	if err := json.Unmarshal(raw, &value); err != nil || value < 0 || value > 65535 {
		return 0, errors.New("simhash values must be unsigned 16-bit integers")
	}
	return value, nil
}
func decode(r *http.Request, target any) error {
	defer r.Body.Close()
	decoder := json.NewDecoder(http.MaxBytesReader(nil, r.Body, 1<<20))
	decoder.DisallowUnknownFields()
	return decoder.Decode(target)
}
func writeJSON(w http.ResponseWriter, status int, value any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(value)
}
func badRequest(w http.ResponseWriter, err error) {
	writeJSON(w, http.StatusBadRequest, map[string]any{"status": 0, "msg": err.Error(), "data": map[string]any{}})
}
func env(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return fallback
}
func databaseURL() string {
	if value := os.Getenv("DATABASE_URL"); value != "" {
		return value
	}
	host, port := env("DB_HOST", "localhost"), env("DB_PORT", "5432")
	return fmt.Sprintf("postgres://%s:%s@%s:%s/%s", env("DB_USERNAME", "postgres"), env("DB_PASSWORD", ""), host, port, env("DB_DATABASE", "dedup_content"))
}
func identifier(value string) bool {
	if value == "" {
		return false
	}
	for i, r := range value {
		if !(r == '_' || r >= 'a' && r <= 'z' || r >= 'A' && r <= 'Z' || i > 0 && r >= '0' && r <= '9') {
			return false
		}
	}
	return true
}
func ms(value time.Duration) float64 { return float64(value.Microseconds()) / 1000 }
