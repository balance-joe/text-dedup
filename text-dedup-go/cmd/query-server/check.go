package main

import (
	"context"
	"encoding/binary"
	"errors"
	"fmt"
	"math/bits"
	"sort"
	"strconv"
	"strings"
	"time"
)

type checkRequest struct {
	ID            *string  `json:"id"`
	SourceFrom    string   `json:"source_from"`
	Title         string   `json:"title"`
	Content       string   `json:"content"`
	InsertOnCheck bool     `json:"insert_on_check"`
	Limit         int      `json:"limit"`
	MaxHamming    *int     `json:"max_hamming"`
	MaxBucketSize *int     `json:"max_bucket_size"`
	Levels        []string `json:"levels"`
}

func (a *api) check(ctx context.Context, req checkRequest) (map[string]any, error) {
	started := time.Now()
	if req.Title == "" && req.Content == "" {
		return nil, errors.New("title/content are all empty")
	}
	contentStarted := time.Now()
	content := makeFingerprint(req.Title, req.Content, false)
	contentMS := ms(time.Since(contentStarted))
	if content.Text == "" {
		return nil, errors.New("title/content are all empty")
	}
	var title *fingerprint
	titleMS := 0.0
	if content.NormalizedTitle != "" {
		stage := time.Now()
		value := makeFingerprint(req.Title, req.Content, true)
		title = &value
		titleMS = ms(time.Since(stage))
	}
	documentID := stableID(req)
	titleEnabled := content.NormalizedContent != "" && title != nil && title.Text != ""
	limit := req.Limit
	if limit == 0 {
		limit = 20
	}
	if limit < 1 {
		return nil, errors.New("limit must be positive")
	}
	maxHamming := 25
	if req.MaxHamming != nil {
		maxHamming = *req.MaxHamming
	}
	if maxHamming < 0 {
		return nil, errors.New("max_hamming must not be negative")
	}
	maxBucket := 1000
	if req.MaxBucketSize != nil {
		maxBucket = *req.MaxBucketSize
	}
	if maxBucket < 1 {
		return nil, errors.New("max_bucket_size must be positive")
	}
	if maxBucket > 2000 {
		maxBucket = 2000
	}
	levels := allowedLevels(req.Levels)
	performance := map[string]any{"total": 0.0, "preprocess": round2(contentMS + titleMS), "preprocess_breakdown": map[string]any{"content_context": round2(contentMS), "title_context": round2(titleMS)}, "prefilter": 0.0, "prefilter_details": map[string]any{}, "content_pipeline": pipeline()}
	prefilterStart := time.Now()
	prefilter, prefilterTimings, err := a.findPrefilter(ctx, prefilterRequest{ExternalID: documentID, RawHash: content.RawHash, ContentHash: content.ExactHash, TitleHash: optionalExact(title, titleEnabled)})
	if err != nil {
		return nil, err
	}
	performance["prefilter"] = round2(ms(time.Since(prefilterStart)))
	performance["prefilter_details"] = prefilterTimings
	if prefilter != nil {
		return exactResponse(content, title, prefilter, finish(performance, started)), nil
	}
	if levels["simhash"] {
		stage := time.Now()
		match, details, err := a.matchSimhash(ctx, content, "content", documentID, maxHamming, maxBucket, limit)
		if err != nil {
			return nil, err
		}
		record(performance, "content", "simhash", ms(time.Since(stage)), details)
		if match != nil {
			return similarResponse(content, title, match, "sim_same", "content", "hamming_distance", finish(performance, started)), nil
		}
	}
	if levels["minhash"] {
		stage := time.Now()
		match, details, err := a.matchMinhash(ctx, content, "content", documentID, maxBucket, limit)
		if err != nil {
			return nil, err
		}
		record(performance, "content", "minhash", ms(time.Since(stage)), details)
		if match != nil {
			return similarResponse(content, title, match, "min_same", "content", "score", finish(performance, started)), nil
		}
	}
	if titleEnabled {
		performance["title_pipeline"] = pipeline()
		if levels["simhash"] {
			stage := time.Now()
			match, details, err := a.matchSimhash(ctx, *title, "title", documentID, maxHamming, maxBucket, limit)
			if err != nil {
				return nil, err
			}
			record(performance, "title", "simhash", ms(time.Since(stage)), details)
			if match != nil {
				return similarResponse(content, title, match, "sim_same", "title", "hamming_distance", finish(performance, started)), nil
			}
		}
		if levels["minhash"] {
			stage := time.Now()
			match, details, err := a.matchMinhash(ctx, *title, "title", documentID, maxBucket, limit)
			if err != nil {
				return nil, err
			}
			record(performance, "title", "minhash", ms(time.Since(stage)), details)
			if match != nil {
				return similarResponse(content, title, match, "min_same", "title", "score", finish(performance, started)), nil
			}
		}
	}
	return map[string]any{"dedupe_status": "new", "inserted": false, "id": nil, "raw_hash": content.RawHash, "content_hash": content.ContentHash, "title_hash": titleHash(title), "simhash_hex": fmt.Sprintf("%x", content.Simhash), "match_id": nil, "match_raw_hash": content.RawHash, "text_type": nil, "match_content_hash": nil, "match_title_hash": nil, "best_simhash_distance": nil, "best_simhash_match_id": nil, "best_minhash_score": 0.0, "best_minhash_match_id": nil, "performance_ms": finish(performance, started)}, nil
}

func (a *api) matchSimhash(ctx context.Context, input fingerprint, scope, documentID string, maxHamming, maxBucket, limit int) (map[string]any, map[string]any, error) {
	bands := make([]simhashBand, 8)
	for i, value := range input.SimhashBands {
		bands[i] = simhashBand{Index: i, Value: []byte(strconv.Itoa(int(value)))}
	}
	queryStart := time.Now()
	candidates, timings, err := a.findSimhashCandidates(ctx, scope, bands, maxBucket+1)
	if err != nil {
		return nil, nil, err
	}
	queryMS := ms(time.Since(queryStart))
	grouped := map[int][]simhashCandidate{}
	for _, row := range candidates {
		grouped[row.BandIndex] = append(grouped[row.BandIndex], row)
	}
	skipped, matches, seen := []any{}, []map[string]any{}, map[string]bool{}
	candidateRows, largest, checks := 0, 0, 0
	bestDistance := -1
	compareStart := time.Now()
	for index, value := range input.SimhashBands {
		rows := grouped[index]
		candidateRows += len(rows)
		if len(rows) > largest {
			largest = len(rows)
		}
		if len(rows) > maxBucket {
			skipped = append(skipped, map[string]any{"band_index": index, "band_value": fmt.Sprintf("%04x", value), "doc_count": len(rows)})
			continue
		}
		for _, candidate := range rows {
			if checks >= 200 {
				skipped = append(skipped, map[string]any{"level": "simhash", "reason": "check limit reached", "max_checks": 200})
				break
			}
			if candidate.ExternalID == documentID || seen[candidate.ExternalID] {
				continue
			}
			seen[candidate.ExternalID] = true
			distance := hamming(input.Simhash, candidate.SimhashHi, candidate.SimhashLo)
			checks++
			if bestDistance == -1 || distance < bestDistance {
				bestDistance = distance
			}
			if distance <= maxHamming {
				matches = append(matches, map[string]any{"id": candidate.ExternalID, "doc_pk": candidate.DocPK, "source_from": candidate.SourceFrom, "method": "simhash", "hamming_distance": distance, "matched_scope": "content"})
			}
		}
		if checks >= 200 {
			break
		}
	}
	sort.Slice(matches, func(i, j int) bool {
		return matches[i]["hamming_distance"].(int) < matches[j]["hamming_distance"].(int)
	})
	matchedDocsStart := time.Now()
	docs, docTimings, err := a.findDocuments(ctx, docPKs(matches))
	docsMS := ms(time.Since(matchedDocsStart))
	if err != nil {
		return nil, nil, err
	}
	byID := map[int64]document{}
	for _, doc := range docs {
		byID[doc.DocPK] = doc
	}
	hydrated := []map[string]any{}
	for _, match := range matches {
		doc, ok := byID[match["doc_pk"].(int64)]
		if !ok {
			continue
		}
		match["raw_hash"], match["content_hash"], match["title_hash"] = doc.RawHash, doc.ContentHash, doc.TitleHash
		text := doc.NormalizedContent
		if scope == "title" {
			text = doc.NormalizedTitle
		}
		match["sample_text"] = sample(text)
		match["matched_scope"] = scope
		hydrated = append(hydrated, match)
	}
	if len(hydrated) > limit {
		hydrated = hydrated[:limit]
	}
	var match map[string]any
	if len(hydrated) > 0 {
		match = hydrated[0]
	}
	details := map[string]any{"bucket_query_ms": round3(queryMS), "bucket_pool_acquire_ms": timings.PoolAcquireMS, "bucket_sql_ms": timings.SQLMS, "bucket_result_mapping_ms": timings.ResultMappingMS, "candidate_rows": candidateRows, "candidate_unique_docs": len(seen), "hamming_compare_ms": round3(ms(time.Since(compareStart))), "hamming_checks": checks, "matched_candidates": len(matches), "docs_fetch_ms": round3(docsMS), "docs_pool_acquire_ms": docTimings.PoolAcquireMS, "docs_sql_ms": docTimings.SQLMS, "docs_result_mapping_ms": docTimings.ResultMappingMS, "matched_docs_fetched": len(docs), "skipped_bucket_count": len(skipped), "largest_bucket": largest, "bucket_limit": maxBucket + 1}
	return match, details, nil
}

func (a *api) matchMinhash(ctx context.Context, input fingerprint, scope, documentID string, maxBucket, limit int) (map[string]any, map[string]any, error) {
	queryStart := time.Now()
	grouped, timings, err := a.findMinhashCandidateGroups(ctx, scope, input.MinhashBands, maxBucket+1)
	if err != nil {
		return nil, nil, err
	}
	queryMS := ms(time.Since(queryStart))
	ids := []int64{}
	seen := map[int64]bool{}
	skipped := []any{}
	candidateRows, largest := 0, 0
	for index, value := range input.MinhashBands {
		rows := grouped[index]
		candidateRows += len(rows)
		if len(rows) > largest {
			largest = len(rows)
		}
		if len(rows) > maxBucket {
			skipped = append(skipped, map[string]any{"band_index": index, "band_value": strconv.FormatUint(value, 10), "doc_count": len(rows)})
			continue
		}
		for _, id := range rows {
			if !seen[id] {
				seen[id] = true
				ids = append(ids, id)
			}
			if len(ids) >= 50 {
				break
			}
		}
		if len(ids) >= 50 {
			break
		}
	}
	if len(ids) >= 50 {
		skipped = append(skipped, map[string]any{"level": "minhash", "reason": "candidate limit reached", "max_candidates": 50})
	}
	fetchStart := time.Now()
	docs, docTimings, err := a.findDocuments(ctx, ids)
	fetchMS := ms(time.Since(fetchStart))
	if err != nil {
		return nil, nil, err
	}
	byID := map[int64]document{}
	for _, doc := range docs {
		byID[doc.DocPK] = doc
	}
	compareStart := time.Now()
	matches := []map[string]any{}
	bestScore := 0.0
	for _, id := range ids {
		doc, ok := byID[id]
		if !ok || doc.ExternalID == documentID {
			continue
		}
		text := doc.NormalizedContent
		if scope == "title" {
			text = doc.NormalizedTitle
		}
		score := fpJaccard(input.Text, text)
		if score > bestScore {
			bestScore = score
		}
		if score >= .4 {
			matches = append(matches, map[string]any{"id": doc.ExternalID, "doc_pk": doc.DocPK, "method": "minhash", "matched_scope": scope, "score": score, "sample_text": sample(text), "raw_hash": doc.RawHash, "content_hash": doc.ContentHash, "title_hash": doc.TitleHash})
		}
	}
	sort.Slice(matches, func(i, j int) bool { return matches[i]["score"].(float64) > matches[j]["score"].(float64) })
	if len(matches) > limit {
		matches = matches[:limit]
	}
	var match map[string]any
	if len(matches) > 0 {
		match = matches[0]
	}
	details := map[string]any{"bucket_query_ms": round3(queryMS), "bucket_pool_acquire_ms": timings.PoolAcquireMS, "bucket_sql_ms": timings.SQLMS, "bucket_result_mapping_ms": timings.ResultMappingMS, "candidate_rows": candidateRows, "candidate_unique_docs": len(ids), "docs_fetch_ms": round3(fetchMS), "docs_pool_acquire_ms": docTimings.PoolAcquireMS, "docs_sql_ms": docTimings.SQLMS, "docs_result_mapping_ms": docTimings.ResultMappingMS, "docs_fetched": len(docs), "jaccard_compare_ms": round3(ms(time.Since(compareStart))), "matched_docs_fetch_ms": 0.0, "matched_docs_fetched": len(matches), "matched_candidates": len(matches), "skipped_bucket_count": len(skipped), "largest_bucket": largest, "bucket_limit": maxBucket + 1}
	return match, details, nil
}

func (a *api) findMinhashCandidateGroups(ctx context.Context, scope string, bands [32]uint64, limit int) (map[int][]int64, timing, error) {
	prefix := "minhash_band"
	if scope == "title" {
		prefix = "title_minhash_band"
	}
	parts, args := []string{}, []any{}
	for index, value := range bands {
		p := len(args)
		args = append(args, index, int64(value), limit)
		parts = append(parts, fmt.Sprintf("(SELECT $%d::smallint AS band_index, b.band_value, b.doc_pk FROM %s AS b WHERE b.band_value = $%d::bigint ORDER BY b.doc_pk LIMIT $%d::integer)", p+1, a.table(prefix+"_p"+strconv.Itoa(index)), p+2, p+3))
	}
	conn, acquired, err := a.acquire(ctx)
	if err != nil {
		return nil, timing{}, err
	}
	defer conn.Release()
	started := time.Now()
	rows, err := conn.Query(ctx, strings.Join(parts, " UNION ALL "), args...)
	if err != nil {
		return nil, timing{}, err
	}
	defer rows.Close()
	grouped := map[int][]int64{}
	mapped := time.Now()
	for rows.Next() {
		var index int16
		var value, id int64
		if err := rows.Scan(&index, &value, &id); err != nil {
			return nil, timing{}, err
		}
		grouped[int(index)] = append(grouped[int(index)], id)
	}
	return grouped, timing{PoolAcquireMS: ms(acquired), SQLMS: ms(time.Since(started)), ResultMappingMS: ms(time.Since(mapped))}, rows.Err()
}
func stableID(req checkRequest) string {
	if req.ID != nil {
		return *req.ID
	}
	return "doc_" + fpMD5(req.SourceFrom+"|"+req.Title+"|"+req.Content)
}
func allowedLevels(values []string) map[string]bool {
	result := map[string]bool{}
	if len(values) == 0 {
		result["simhash"], result["minhash"] = true, true
		return result
	}
	for _, value := range values {
		if value == "simhash" || value == "minhash" {
			result[value] = true
		}
	}
	return result
}
func optionalExact(value *fingerprint, enabled bool) *string {
	if value == nil || !enabled {
		return nil
	}
	result := value.ExactHash
	return &result
}
func titleHash(value *fingerprint) any {
	if value == nil {
		return nil
	}
	return value.ExactHash
}
func pipeline() map[string]any {
	return map[string]any{"simhash": 0.0, "minhash": 0.0, "vector": 0.0, "matcher_breakdown": map[string]any{"content_hash": 0.0}, "simhash_details": map[string]any{}, "minhash_details": map[string]any{}}
}
func record(performance map[string]any, scope, matcher string, elapsed float64, details map[string]any) {
	name := "content_pipeline"
	if scope == "title" {
		name = "title_pipeline"
	}
	p := performance[name].(map[string]any)
	p["matcher_breakdown"].(map[string]any)[matcher] = round3(elapsed)
	p[matcher+"_details"] = details
	if scope == "content" {
		p[matcher] = round2(elapsed)
	}
}
func finish(performance map[string]any, started time.Time) map[string]any {
	performance["total"] = round2(ms(time.Since(started)))
	return performance
}
func exactResponse(content fingerprint, title *fingerprint, match *prefilterMatch, performance map[string]any) map[string]any {
	textType := "all"
	if match.ConflictReason == "title_hash" {
		textType = "title"
	}
	if match.ConflictReason == "content_hash" {
		textType = "content"
	}
	return map[string]any{"dedupe_status": "text_same", "inserted": false, "id": nil, "raw_hash": content.RawHash, "content_hash": content.ContentHash, "title_hash": titleHash(title), "match_id": match.ExternalID, "match_raw_hash": match.RawHash, "text_type": textType, "match_content_hash": match.ContentHash, "match_title_hash": match.TitleHash, "performance_ms": performance}
}
func similarResponse(content fingerprint, title *fingerprint, match map[string]any, status, textType, metric string, performance map[string]any) map[string]any {
	return map[string]any{"dedupe_status": status, "inserted": false, "id": nil, "raw_hash": content.RawHash, "content_hash": content.ContentHash, "title_hash": titleHash(title), "match_id": match["id"], "match_raw_hash": match["raw_hash"], "text_type": textType, "match_content_hash": match["content_hash"], "match_title_hash": match["title_hash"], metric: match[metric], "performance_ms": performance}
}
func hamming(value [16]byte, hi, lo int64) int {
	return bits.OnesCount64(binary.BigEndian.Uint64(value[:8])^uint64(hi)) + bits.OnesCount64(binary.BigEndian.Uint64(value[8:])^uint64(lo))
}
func docPKs(matches []map[string]any) []int64 {
	result := make([]int64, 0, len(matches))
	for _, match := range matches {
		result = append(result, match["doc_pk"].(int64))
	}
	return result
}
func sample(value string) string {
	runes := []rune(value)
	if len(runes) > 160 {
		return string(runes[:160])
	}
	return value
}
func round2(value float64) float64 { return float64(int(value*100+.5)) / 100 }
func round3(value float64) float64 { return float64(int(value*1000+.5)) / 1000 }
