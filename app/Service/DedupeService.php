<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\DocumentFingerprint;
use App\Model\DocumentText;
use App\Model\MinhashBand;
use App\Model\SimhashBand;
use App\Model\TitleMinhashBand;
use App\Model\TitleSimhashBand;
use App\Support\UInt64;
use Hyperf\DbConnection\Db;
use Hyperf\Database\Model\Collection;
use InvalidArgumentException;

use function Hyperf\Config\config;

/**
 * 在线去重服务的应用层。
 *
 * 当前阶段开放精确哈希、SimHash 与 MinHash 的只读判定；写入事务尚未接入。
 */
final class DedupeService
{
    public function documentCount(): int
    {
        return DocumentFingerprint::query()->count();
    }

    /**
     * 只读执行现有文档的完整去重判定。
     *
     * 写入链路尚未接入前，new 结果的 inserted 始终为 false；Controller
     * 只用于与 Python 服务按相同数据库进行召回和判定比对。
     *
     * @param array<string, mixed> $source
     * @param array{max_hamming?: int, max_bucket_size?: int, limit?: int, levels?: list<string>} $options
     * @return array<string, mixed>
     */
    public function check(array $source, array $options = []): array
    {
        $totalStartedAt = microtime(true);
        $documentId = $this->stableDocumentId($source);
        $contentPreprocessStartedAt = microtime(true);
        $contentContext = FingerprintContext::fromSource($source);
        $contentPreprocessMilliseconds = (microtime(true) - $contentPreprocessStartedAt) * 1000;
        if ($contentContext->text === '') {
            throw new InvalidArgumentException('title/content are all empty');
        }

        $titlePreprocessStartedAt = microtime(true);
        $titleContext = $contentContext->normalizedTitle === ''
            ? null
            : FingerprintContext::fromSource($source, 'title');
        $titlePreprocessMilliseconds = $titleContext === null
            ? 0.0
            : (microtime(true) - $titlePreprocessStartedAt) * 1000;
        $titleChainEnabled = $contentContext->normalizedContent !== '' && $titleContext !== null && $titleContext->text !== '';
        $levels = $options['levels'] ?? config('dedupe.levels', ['content_hash', 'simhash', 'minhash']);
        $levels = array_values(array_intersect(['content_hash', 'simhash', 'minhash'], $levels));
        $maxHamming = $options['max_hamming'] ?? null;
        $maxBucketSize = $options['max_bucket_size'] ?? null;
        $limit = $options['limit'] ?? null;
        $performance = $this->initialPerformance($contentPreprocessMilliseconds, $titlePreprocessMilliseconds);

        $prefilterStartedAt = microtime(true);
        $prefilter = $this->findPrefilterDuplicate(
            $documentId,
            $contentContext->rawHash,
            $contentContext->exactHash,
            $titleChainEnabled ? $titleContext->exactHash : null,
        );
        $performance['prefilter'] = round((microtime(true) - $prefilterStartedAt) * 1000, 2);
        if ($prefilter !== null) {
            return $this->exactResult(
                $contentContext,
                $titleContext,
                $prefilter,
                $this->completePerformance($performance, $totalStartedAt),
            );
        }

        $contentSimhash = null;
        $contentMinhash = null;
        if (in_array('simhash', $levels, true)) {
            $matcherStartedAt = microtime(true);
            $simhash = $this->matchSimhash($contentContext, $documentId, $maxHamming, $maxBucketSize, $limit);
            $contentSimhash = $simhash;
            $this->recordMatcherPerformance($performance, 'content', 'simhash', (microtime(true) - $matcherStartedAt) * 1000, $simhash);
            if ($simhash['matches'] !== []) {
                return $this->similarResult(
                    $contentContext,
                    $titleContext,
                    $simhash['matches'][0],
                    'sim_same',
                    'content',
                    'hamming_distance',
                    $this->completePerformance($performance, $totalStartedAt),
                );
            }
        }
        if (in_array('minhash', $levels, true)) {
            $matcherStartedAt = microtime(true);
            $minhash = $this->matchMinhash($contentContext, $documentId, $maxBucketSize, $limit);
            $contentMinhash = $minhash;
            $this->recordMatcherPerformance($performance, 'content', 'minhash', (microtime(true) - $matcherStartedAt) * 1000, $minhash);
            if ($minhash['matches'] !== []) {
                return $this->similarResult(
                    $contentContext,
                    $titleContext,
                    $minhash['matches'][0],
                    'min_same',
                    'content',
                    'score',
                    $this->completePerformance($performance, $totalStartedAt),
                );
            }
        }

        if ($titleChainEnabled) {
            $performance['title_pipeline'] = $this->initialTitlePipelinePerformance();
            if (in_array('simhash', $levels, true)) {
                $matcherStartedAt = microtime(true);
                $simhash = $this->matchSimhash($titleContext, $documentId, $maxHamming, $maxBucketSize, $limit);
                $this->recordMatcherPerformance($performance, 'title', 'simhash', (microtime(true) - $matcherStartedAt) * 1000, $simhash);
                if ($simhash['matches'] !== []) {
                    return $this->similarResult(
                        $contentContext,
                        $titleContext,
                        $simhash['matches'][0],
                        'sim_same',
                        'title',
                        'hamming_distance',
                        $this->completePerformance($performance, $totalStartedAt),
                    );
                }
            }
            if (in_array('minhash', $levels, true)) {
                $matcherStartedAt = microtime(true);
                $minhash = $this->matchMinhash($titleContext, $documentId, $maxBucketSize, $limit);
                $this->recordMatcherPerformance($performance, 'title', 'minhash', (microtime(true) - $matcherStartedAt) * 1000, $minhash);
                if ($minhash['matches'] !== []) {
                    return $this->similarResult(
                        $contentContext,
                        $titleContext,
                        $minhash['matches'][0],
                        'min_same',
                        'title',
                        'score',
                        $this->completePerformance($performance, $totalStartedAt),
                    );
                }
            }
        }

        return [
            'dedupe_status' => 'new',
            'inserted' => false,
            'id' => null,
            'raw_hash' => $contentContext->rawHash,
            'content_hash' => $contentContext->contentHash,
            'title_hash' => $titleContext?->exactHash,
            'simhash_hex' => $contentContext->simhashHex,
            'match_id' => null,
            'match_raw_hash' => $contentContext->rawHash,
            'text_type' => null,
            'match_content_hash' => null,
            'match_title_hash' => null,
            'best_simhash_distance' => $contentSimhash['best_hamming_distance'] ?? null,
            'best_simhash_match_id' => $contentSimhash['best_match_id'] ?? null,
            'best_minhash_score' => $contentMinhash['best_score'] ?? 0.0,
            'best_minhash_match_id' => $contentMinhash['best_match_id'] ?? null,
            'performance_ms' => $this->completePerformance($performance, $totalStartedAt),
        ];
    }

    /**
     * 以 Python 当前线上链路完全相同的优先级查找精确冲突。
     *
     * @return array{priority: int, conflict_reason: string, doc_pk: int, external_id: string, raw_hash: string, content_hash: string, title_hash: ?string}|null
     */
    public function findPrefilterDuplicate(
        string $externalId,
        string $rawHashHex,
        string $contentHashHex,
        ?string $titleHashHex = null,
    ): ?array {
        $rawHash = $this->validatedHashHex($rawHashHex, 'raw_hash');
        $contentHash = $this->validatedHashHex($contentHashHex, 'content_hash');
        $titleHash = $titleHashHex === null ? null : $this->validatedHashHex($titleHashHex, 'title_hash');

        $query = DocumentFingerprint::query()
            ->selectRaw("1 AS priority, 'external_id' AS conflict_reason, doc_pk, external_id, raw_hash, content_hash, title_hash")
            ->where('external_id', $externalId)
            ->unionAll(
                DocumentFingerprint::query()
                    ->selectRaw("2 AS priority, 'raw_hash' AS conflict_reason, doc_pk, external_id, raw_hash, content_hash, title_hash")
                    ->whereRaw("raw_hash = decode(?, 'hex')", [$rawHash])
            )
            ->unionAll(
                DocumentFingerprint::query()
                    ->selectRaw("3 AS priority, 'content_hash' AS conflict_reason, doc_pk, external_id, raw_hash, content_hash, title_hash")
                    ->whereRaw("content_hash = decode(?, 'hex')", [$contentHash])
            );

        if ($titleHash !== null) {
            $query->unionAll(
                DocumentFingerprint::query()
                    ->selectRaw("4 AS priority, 'title_hash' AS conflict_reason, doc_pk, external_id, raw_hash, content_hash, title_hash")
                    ->whereRaw("title_hash = decode(?, 'hex')", [$titleHash])
            );
        }

        /** @var DocumentFingerprint|null $match */
        $match = $query->orderBy('priority')->orderBy('doc_pk')->first();
        if ($match === null) {
            return null;
        }

        return [
            'priority' => (int) $match->getAttribute('priority'),
            'conflict_reason' => (string) $match->getAttribute('conflict_reason'),
            'doc_pk' => (int) $match->getAttribute('doc_pk'),
            'external_id' => (string) $match->getAttribute('external_id'),
            'raw_hash' => $this->hashHex($match->getAttribute('raw_hash')),
            'content_hash' => $this->hashHex($match->getAttribute('content_hash')),
            'title_hash' => $match->getAttribute('title_hash') === null ? null : $this->hashHex($match->getAttribute('title_hash')),
        ];
    }

    /** @return Collection<int, DocumentFingerprint> */
    public function findDocumentsByExactHash(string $hashHex, string $scope = 'content', int $limit = 20): Collection
    {
        $column = match ($scope) {
            'content' => 'content_hash',
            'title' => 'title_hash',
            default => throw new InvalidArgumentException("Unsupported fingerprint scope: {$scope}"),
        };

        return DocumentFingerprint::query()
            ->with('text')
            ->whereRaw("{$column} = decode(?, 'hex')", [$this->validatedHashHex($hashHex, $column)])
            ->orderBy('doc_pk')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * 一次请求所有 SimHash band 的候选，并按 band 返回结果。
     *
     * 与 Python 一样直接读取叶分区；每个子查询保留稳定排序和单桶上限，
     * 避免父表重复分区规划，也避免热点分桶拖垮在线请求。
     *
     * @param list<array{int, string|int}> $bands
     * @return array<string, list<array{band_index: int, band_value: int, doc_pk: int, external_id: string, source_from: string, simhash_hi: int, simhash_lo: int}>>
     */
    public function findSimhashCandidatesForBands(array $bands, string $scope = 'content', ?int $maxCandidatesPerBand = null): array
    {
        if (!in_array($scope, ['content', 'title'], true)) {
            throw new InvalidArgumentException("Unsupported fingerprint scope: {$scope}");
        }

        $maxCandidatesPerBand ??= (int) config('dedupe.simhash.max_bucket_size', 1000) + 1;
        if ($maxCandidatesPerBand < 1) {
            throw new InvalidArgumentException('maxCandidatesPerBand must be positive.');
        }

        $normalizedBands = $this->normalizeSimhashBands($bands);
        $result = [];
        foreach ($normalizedBands as [$bandIndex, $bandValue]) {
            $result[$this->bandKey($bandIndex, $bandValue)] = [];
        }
        if ($normalizedBands === []) {
            return $result;
        }

        $bandModel = $scope === 'title' ? new TitleSimhashBand() : new SimhashBand();
        $documentTable = $this->qualifiedTable((new DocumentFingerprint())->getTable());
        $hiColumn = $scope === 'title' ? 'title_simhash_hi' : 'simhash_hi';
        $loColumn = $scope === 'title' ? 'title_simhash_lo' : 'simhash_lo';
        $subqueries = [];
        $bindings = [];

        foreach ($normalizedBands as [$bandIndex, $bandValue]) {
            // 与 Python 查询保持一致：直接访问叶分区，每桶独立排序和限流，
            // 但所有数据值仍使用绑定参数，避免每次把请求值拼入 SQL。
            $subqueries[] = sprintf(
                '(SELECT ?::smallint AS band_index, b.band_value, b.doc_pk, d.external_id, d.source_from, d.%s AS simhash_hi, d.%s AS simhash_lo FROM %s AS b JOIN %s AS d ON d.doc_pk = b.doc_pk WHERE b.band_value = ?::integer ORDER BY b.doc_pk LIMIT ?::integer)',
                $hiColumn,
                $loColumn,
                $this->bandLeafTable($bandModel->getTable(), $bandIndex, 7),
                $documentTable,
            );
            array_push($bindings, $bandIndex, $bandValue, $maxCandidatesPerBand);
        }

        foreach (Db::select(implode(' UNION ALL ', $subqueries), $bindings) as $candidate) {
            $bandIndex = (int) $this->rowValue($candidate, 'band_index');
            $bandValue = (int) $this->rowValue($candidate, 'band_value');
            $result[$this->bandKey($bandIndex, $bandValue)][] = [
                'band_index' => $bandIndex,
                'band_value' => $bandValue,
                'doc_pk' => (int) $this->rowValue($candidate, 'doc_pk'),
                'external_id' => (string) $this->rowValue($candidate, 'external_id'),
                'source_from' => (string) $this->rowValue($candidate, 'source_from'),
                'simhash_hi' => (int) $this->rowValue($candidate, 'simhash_hi'),
                'simhash_lo' => (int) $this->rowValue($candidate, 'simhash_lo'),
            ];
        }

        return $result;
    }

    /**
     * 在 SimHash LSH 候选中执行 128 位汉明距离筛选。
     *
     * @return array{
     *   matches: list<array{id: string, doc_pk: int, source_from: string, method: string, hamming_distance: int, matched_scope: string}>,
     *   best_hamming_distance: ?int,
     *   best_match_id: ?string,
     *   skipped_buckets: list<array<string, int|string>>,
     *   stats: array<string, int|float>
     * }
     */
    public function matchSimhash(
        FingerprintContext $context,
        ?string $documentId = null,
        ?int $maxHamming = null,
        ?int $maxBucketSize = null,
        ?int $limit = null,
    ): array {
        $maxHamming ??= (int) config('dedupe.simhash.max_hamming', 25);
        $maxBucketSize = min(
            $maxBucketSize ?? (int) config('dedupe.simhash.max_bucket_size', 1000),
            (int) config('dedupe.simhash.lsh_max_bucket_size', 2000),
        );
        $limit ??= (int) config('dedupe.result_limit', 20);
        $maxChecks = (int) config('dedupe.simhash.api_max_checks', 200);
        if ($maxHamming < 0 || $maxBucketSize < 1 || $limit < 1) {
            throw new InvalidArgumentException('SimHash matching limits must be positive (except maxHamming, which may be zero).');
        }

        $startedAt = microtime(true);
        $candidateMap = $this->findSimhashCandidatesForBands($context->simhashBands, $context->scope, $maxBucketSize + 1);
        $queryMilliseconds = (microtime(true) - $startedAt) * 1000;
        $skippedBuckets = [];
        $matches = [];
        $seenDocumentIds = [];
        $bestDistance = null;
        $bestMatchId = null;
        $candidateRows = 0;
        $largestBucket = 0;
        $checks = 0;
        $comparisonStartedAt = microtime(true);
        $checkLimitReached = false;

        foreach ($this->normalizeSimhashBands($context->simhashBands) as [$bandIndex, $bandValue]) {
            $rows = $candidateMap[$this->bandKey($bandIndex, $bandValue)] ?? [];
            $rowCount = count($rows);
            $candidateRows += $rowCount;
            $largestBucket = max($largestBucket, $rowCount);
            if ($rowCount > $maxBucketSize) {
                $skippedBuckets[] = [
                    'band_index' => $bandIndex,
                    'band_value' => sprintf('%04x', $bandValue),
                    'doc_count' => $rowCount,
                ];
                continue;
            }

            foreach ($rows as $candidate) {
                if ($checks >= $maxChecks) {
                    $checkLimitReached = true;
                    break 2;
                }
                if ($documentId !== null && $candidate['external_id'] === $documentId) {
                    continue;
                }
                if (isset($seenDocumentIds[$candidate['external_id']])) {
                    continue;
                }
                $seenDocumentIds[$candidate['external_id']] = true;
                $distance = $this->hammingDistance(
                    $context->simhashValue,
                    UInt64::fromSignedInt64($candidate['simhash_hi']) . UInt64::fromSignedInt64($candidate['simhash_lo']),
                );
                ++$checks;
                if ($bestDistance === null || $distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestMatchId = $candidate['external_id'];
                }
                if ($distance <= $maxHamming) {
                    $matches[] = [
                        'id' => $candidate['external_id'],
                        'doc_pk' => $candidate['doc_pk'],
                        'source_from' => $candidate['source_from'],
                        'method' => 'simhash',
                        'hamming_distance' => $distance,
                        'matched_scope' => $context->scope,
                    ];
                }
            }
        }

        if ($checkLimitReached) {
            $skippedBuckets[] = [
                'level' => 'simhash',
                'reason' => 'check limit reached',
                'max_checks' => $maxChecks,
            ];
        }

        usort($matches, static fn (array $left, array $right): int => $left['hamming_distance'] <=> $right['hamming_distance']);
        $matchedCandidateCount = count($matches);
        $fetchStartedAt = microtime(true);
        $matchedDocuments = $this->findMatchDocumentsByDocPks(array_column($matches, 'doc_pk'));
        $fetchMilliseconds = $matches === [] ? 0.0 : (microtime(true) - $fetchStartedAt) * 1000;
        $hydratedMatches = [];
        foreach ($matches as $match) {
            $document = $matchedDocuments[$match['doc_pk']] ?? null;
            if ($document === null) {
                continue;
            }
            $match['raw_hash'] = $document['raw_hash'];
            $match['content_hash'] = $document['content_hash'];
            $match['title_hash'] = $document['title_hash'];
            $sampleText = $context->scope === 'title' ? $document['normalized_title'] : $document['normalized_content'];
            $match['sample_text'] = mb_substr($sampleText, 0, 160, 'UTF-8');
            $hydratedMatches[] = $match;
        }
        $matches = array_slice($hydratedMatches, 0, $limit);

        return [
            'matches' => $matches,
            'best_hamming_distance' => $bestDistance,
            'best_match_id' => $bestMatchId,
            'skipped_buckets' => $skippedBuckets,
            'stats' => [
                'bucket_query_ms' => round($queryMilliseconds, 3),
                'candidate_rows' => $candidateRows,
                'candidate_unique_docs' => count($seenDocumentIds),
                'hamming_compare_ms' => round((microtime(true) - $comparisonStartedAt) * 1000, 3),
                'hamming_checks' => $checks,
                'matched_candidates' => $matchedCandidateCount,
                'docs_fetch_ms' => round($fetchMilliseconds, 3),
                'matched_docs_fetched' => count($matchedDocuments),
                'skipped_bucket_count' => count($skippedBuckets),
                'largest_bucket' => $largestBucket,
                'bucket_limit' => $maxBucketSize + 1,
            ],
        ];
    }

    /**
     * @param list<array{int, string}> $bands
     * @return array<string, list<int>>
     */
    public function findMinhashCandidateIdsForBands(array $bands, string $scope = 'content', ?int $maxCandidatesPerBand = null): array
    {
        if (!in_array($scope, ['content', 'title'], true)) {
            throw new InvalidArgumentException("Unsupported fingerprint scope: {$scope}");
        }

        $maxCandidatesPerBand ??= (int) config('dedupe.simhash.max_bucket_size', 1000) + 1;
        $normalizedBands = $this->normalizeMinhashBands($bands);
        $result = [];
        foreach ($normalizedBands as [$bandIndex, $bandValue]) {
            $result[$this->bandKey($bandIndex, $bandValue)] = [];
        }
        if ($normalizedBands === []) {
            return $result;
        }

        $bandModel = $scope === 'title' ? new TitleMinhashBand() : new MinhashBand();
        $subqueries = [];
        $bindings = [];
        foreach ($normalizedBands as [$bandIndex, $bandValue]) {
            $subqueries[] = sprintf(
                '(SELECT ?::smallint AS band_index, b.band_value, b.doc_pk FROM %s AS b WHERE b.band_value = ?::bigint ORDER BY b.doc_pk LIMIT ?::integer)',
                $this->bandLeafTable($bandModel->getTable(), $bandIndex, 31),
            );
            array_push($bindings, $bandIndex, $bandValue, $maxCandidatesPerBand);
        }

        foreach (Db::select(implode(' UNION ALL ', $subqueries), $bindings) as $candidate) {
            $bandIndex = (int) $this->rowValue($candidate, 'band_index');
            $bandValue = (int) $this->rowValue($candidate, 'band_value');
            $result[$this->bandKey($bandIndex, $bandValue)][] = (int) $this->rowValue($candidate, 'doc_pk');
        }

        return $result;
    }

    /**
     * 在 MinHash LSH 候选中进行完整 5-gram Jaccard 复核。
     *
     * @return array{matches: list<array{id: string, doc_pk: int, method: string, matched_scope: string, score: float, sample_text: string}>, best_score: float, best_match_id: ?string, skipped_buckets: list<array<string, int|string>>, stats: array<string, int|float>}
     */
    public function matchMinhash(FingerprintContext $context, ?string $documentId = null, ?int $maxBucketSize = null, ?int $limit = null): array
    {
        $maxBucketSize = min(
            $maxBucketSize ?? (int) config('dedupe.simhash.max_bucket_size', 1000),
            (int) config('dedupe.simhash.lsh_max_bucket_size', 2000),
        );
        $maxCandidates = (int) config('dedupe.minhash.max_candidates', 50);
        $limit ??= (int) config('dedupe.result_limit', 20);
        $threshold = (float) config('dedupe.minhash.jaccard_threshold', 0.4);
        if ($maxBucketSize < 1 || $maxCandidates < 1 || $limit < 1 || $threshold < 0 || $threshold > 1) {
            throw new InvalidArgumentException('Invalid MinHash matching configuration.');
        }

        $startedAt = microtime(true);
        $candidateMap = $this->findMinhashCandidateIdsForBands($context->minhashBands, $context->scope, $maxBucketSize + 1);
        $queryMilliseconds = (microtime(true) - $startedAt) * 1000;
        $candidateIds = [];
        $seen = [];
        $skippedBuckets = [];
        $candidateRows = 0;
        $largestBucket = 0;
        foreach ($this->normalizeMinhashBands($context->minhashBands) as [$bandIndex, $bandValue]) {
            $rows = $candidateMap[$this->bandKey($bandIndex, $bandValue)] ?? [];
            $rowCount = count($rows);
            $candidateRows += $rowCount;
            $largestBucket = max($largestBucket, $rowCount);
            if ($rowCount > $maxBucketSize) {
                $skippedBuckets[] = ['band_index' => $bandIndex, 'band_value' => UInt64::toDecimal(UInt64::fromSignedInt64($bandValue)), 'doc_count' => $rowCount];
                continue;
            }
            foreach ($rows as $docPk) {
                if (!isset($seen[$docPk])) {
                    $seen[$docPk] = true;
                    $candidateIds[] = $docPk;
                }
                if (count($candidateIds) >= $maxCandidates) {
                    break 2;
                }
            }
        }
        if (count($candidateIds) >= $maxCandidates) {
            $skippedBuckets[] = ['level' => 'minhash', 'reason' => 'candidate limit reached', 'max_candidates' => $maxCandidates];
        }

        $fetchStartedAt = microtime(true);
        $documents = $this->findMatchDocumentsByDocPks($candidateIds);
        $fetchMilliseconds = (microtime(true) - $fetchStartedAt) * 1000;
        $leftGrams = array_fill_keys(Ngram::items($context->text, MinHash::NGRAM), true);
        $matches = [];
        $bestScore = 0.0;
        $bestMatchId = null;
        $compareStartedAt = microtime(true);
        foreach ($candidateIds as $docPk) {
            $document = $documents[$docPk] ?? null;
            if ($document === null || ($documentId !== null && $document['external_id'] === $documentId)) {
                continue;
            }
            $text = $context->scope === 'title' ? $document['normalized_title'] : $document['normalized_content'];
            $score = $this->jaccard($leftGrams, array_fill_keys(Ngram::items($text, MinHash::NGRAM), true));
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatchId = $document['external_id'];
            }
            if ($score >= $threshold) {
                $matches[] = [
                    'id' => $document['external_id'],
                    'doc_pk' => $document['doc_pk'],
                    'method' => 'minhash',
                    'matched_scope' => $context->scope,
                    'score' => $score,
                    'sample_text' => mb_substr($text, 0, 160, 'UTF-8'),
                    'raw_hash' => $document['raw_hash'],
                    'content_hash' => $document['content_hash'],
                    'title_hash' => $document['title_hash'],
                ];
            }
        }
        usort($matches, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
        $matchedCandidateCount = count($matches);

        return [
            'matches' => array_slice($matches, 0, $limit),
            'best_score' => $bestScore > 0 ? round($bestScore, 4) : 0.0,
            'best_match_id' => $bestMatchId,
            'skipped_buckets' => $skippedBuckets,
            'stats' => [
                'bucket_query_ms' => round($queryMilliseconds, 3),
                'candidate_rows' => $candidateRows,
                'candidate_unique_docs' => count($candidateIds),
                'docs_fetch_ms' => round($fetchMilliseconds, 3),
                'docs_fetched' => count($documents),
                'jaccard_compare_ms' => round((microtime(true) - $compareStartedAt) * 1000, 3),
                'matched_docs_fetch_ms' => 0.0,
                'matched_docs_fetched' => $matchedCandidateCount,
                'matched_candidates' => $matchedCandidateCount,
                'skipped_bucket_count' => count($skippedBuckets),
                'largest_bucket' => $largestBucket,
                'bucket_limit' => $maxBucketSize + 1,
            ],
        ];
    }

    /** Validate an MD5 value before binding it as PostgreSQL decode(..., 'hex'). */
    private function validatedHashHex(string $hashHex, string $field): string
    {
        if (preg_match('/\\A[0-9a-f]{32}\\z/i', $hashHex) !== 1) {
            throw new InvalidArgumentException("{$field} must be a 32-character MD5 hexadecimal string.");
        }

        return strtolower($hashHex);
    }

    private function hashHex(mixed $value): string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }
        if (is_string($value) && preg_match('/\A\\\\x([0-9a-f]{32})\z/i', $value, $matches) === 1) {
            return strtolower($matches[1]);
        }
        if (!is_string($value) || strlen($value) !== 16) {
            throw new \UnexpectedValueException('PostgreSQL hash column must contain exactly 16 bytes.');
        }

        return bin2hex($value);
    }

    /**
     * 一次 JOIN 取回 MinHash 复核需要的正文和最终响应需要的指纹字段。
     *
     * Python 先取最小文本行，命中后再回表补齐完整指纹；候选最多 50 条时，
     * PHP 在一次查询中多取三个 16 字节哈希，可以省掉一次网络往返，也避免
     * ORM eager-loading 将 fingerprint/text 拆成两条 SQL。
     *
     * @param list<int> $docPks
     * @return array<int, array{doc_pk: int, external_id: string, source_from: string, raw_hash: string, content_hash: string, title_hash: ?string, normalized_title: string, normalized_content: string}>
     */
    private function findMatchDocumentsByDocPks(array $docPks): array
    {
        $ids = [];
        foreach ($docPks as $docPk) {
            $docPk = (int) $docPk;
            if ($docPk > 0) {
                $ids[$docPk] = $docPk;
            }
        }
        if ($ids === []) {
            return [];
        }

        $fingerprintTable = (new DocumentFingerprint())->getTable();
        $textTable = (new DocumentText())->getTable();
        $rows = DocumentFingerprint::query()
            ->from("{$fingerprintTable} AS d")
            ->leftJoin("{$textTable} AS t", 't.doc_pk', '=', 'd.doc_pk')
            ->whereIn('d.doc_pk', array_values($ids))
            ->get([
                'd.doc_pk',
                'd.external_id',
                'd.source_from',
                'd.raw_hash',
                'd.content_hash',
                'd.title_hash',
                't.normalized_title',
                't.normalized_content',
            ]);

        $documents = [];
        foreach ($rows as $row) {
            $docPk = (int) $row->getAttribute('doc_pk');
            $titleHash = $row->getAttribute('title_hash');
            $documents[$docPk] = [
                'doc_pk' => $docPk,
                'external_id' => (string) $row->getAttribute('external_id'),
                'source_from' => (string) $row->getAttribute('source_from'),
                'raw_hash' => $this->hashHex($row->getAttribute('raw_hash')),
                'content_hash' => $this->hashHex($row->getAttribute('content_hash')),
                'title_hash' => $titleHash === null ? null : $this->hashHex($titleHash),
                'normalized_title' => (string) ($row->getAttribute('normalized_title') ?? ''),
                'normalized_content' => (string) ($row->getAttribute('normalized_content') ?? ''),
            ];
        }

        return $documents;
    }

    /** @param list<array{int, string|int}> $bands @return list<array{int, int}> */
    private function normalizeSimhashBands(array $bands): array
    {
        $result = [];
        foreach ($bands as $band) {
            if (!is_array($band) || count($band) !== 2) {
                throw new InvalidArgumentException('Each SimHash band must contain an index and value.');
            }
            $bandIndex = (int) $band[0];
            if (is_string($band[1]) && preg_match('/\\A[0-9a-f]{1,4}\\z/i', $band[1]) !== 1) {
                throw new InvalidArgumentException('SimHash band hexadecimal values must contain 1 to 4 hexadecimal characters.');
            }
            $bandValue = is_string($band[1]) ? hexdec($band[1]) : (int) $band[1];
            if ($bandIndex < 0 || $bandIndex > 7 || $bandValue < 0 || $bandValue > 0xffff) {
                throw new InvalidArgumentException('SimHash bands must use indexes 0-7 and unsigned 16-bit values.');
            }
            $key = $this->bandKey($bandIndex, $bandValue);
            $result[$key] = [$bandIndex, $bandValue];
        }

        return array_values($result);
    }

    /** @param list<array{int, string}> $bands @return list<array{int, int}> */
    private function normalizeMinhashBands(array $bands): array
    {
        $result = [];
        foreach ($bands as $band) {
            if (!is_array($band) || count($band) !== 2 || !is_string($band[1])) {
                throw new InvalidArgumentException('Each MinHash band must contain an index and uint64 decimal string.');
            }
            $bandIndex = (int) $band[0];
            if ($bandIndex < 0 || $bandIndex > 31) {
                throw new InvalidArgumentException('MinHash band indexes must be in the range 0-31.');
            }
            $signedValue = UInt64::toSignedInt64(UInt64::fromDecimal($band[1]));
            $key = $this->bandKey($bandIndex, $signedValue);
            $result[$key] = [$bandIndex, $signedValue];
        }

        return array_values($result);
    }

    private function bandKey(int $bandIndex, int $bandValue): string
    {
        return "{$bandIndex}:{$bandValue}";
    }

    private function rowValue(array|object $row, string $key): mixed
    {
        return is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);
    }

    private function bandLeafTable(string $parentTable, int $bandIndex, int $maximumBandIndex): string
    {
        if ($bandIndex < 0 || $bandIndex > $maximumBandIndex) {
            throw new InvalidArgumentException("Invalid band index: {$bandIndex}");
        }

        // $parentTable only comes from the four fixed Model definitions and
        // $bandIndex is range-validated, so this identifier cannot contain
        // request-controlled SQL.
        return $this->qualifiedTable("{$parentTable}_p{$bandIndex}");
    }

    private function qualifiedTable(string $table): string
    {
        $schema = (string) config('dedupe.database.schema', 'dedup_content');
        if (preg_match('/\A[a-z_][a-z0-9_]*\z/i', $schema) !== 1
            || preg_match('/\A[a-z_][a-z0-9_]*\z/i', $table) !== 1) {
            throw new InvalidArgumentException('Invalid PostgreSQL schema or table identifier.');
        }

        return sprintf('"%s"."%s"', $schema, $table);
    }

    private function hammingDistance(string $left, string $right): int
    {
        if (strlen($left) !== SimHash::BITS / 8 || strlen($right) !== SimHash::BITS / 8) {
            throw new InvalidArgumentException('SimHash values must contain exactly 16 bytes.');
        }

        static $bitCount = null;
        if ($bitCount === null) {
            $bitCount = [];
            for ($value = 0; $value < 256; ++$value) {
                $bitCount[$value] = substr_count(decbin($value), '1');
            }
        }

        $distance = 0;
        $xor = $left ^ $right;
        for ($index = 0; $index < strlen($xor); ++$index) {
            $distance += $bitCount[ord($xor[$index])];
        }

        return $distance;
    }

    /** @param array<string, true> $left @param array<string, true> $right */
    private function jaccard(array $left, array $right): float
    {
        if ($left === [] && $right === []) {
            return 1.0;
        }
        if ($left === [] || $right === []) {
            return 0.0;
        }

        $smaller = count($left) <= count($right) ? $left : $right;
        $larger = $smaller === $left ? $right : $left;
        $intersection = 0;
        foreach ($smaller as $gram => $_) {
            if (isset($larger[$gram])) {
                ++$intersection;
            }
        }

        return $intersection / (count($left) + count($right) - $intersection);
    }

    /** @return array<string, mixed> */
    private function initialPerformance(float $contentPreprocessMilliseconds, float $titlePreprocessMilliseconds): array
    {
        return [
            'total' => 0.0,
            'preprocess' => round($contentPreprocessMilliseconds + $titlePreprocessMilliseconds, 2),
            'preprocess_breakdown' => [
                'content_context' => round($contentPreprocessMilliseconds, 2),
                'title_context' => round($titlePreprocessMilliseconds, 2),
            ],
            'prefilter' => 0.0,
            'content_pipeline' => [
                'simhash' => 0.0,
                'minhash' => 0.0,
                'vector' => 0.0,
                // 精确哈希已由上面的组合预过滤一次完成，不重复访问数据库。
                'matcher_breakdown' => ['content_hash' => 0.0],
                'simhash_details' => [],
                'minhash_details' => [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function initialTitlePipelinePerformance(): array
    {
        return [
            'matcher_breakdown' => ['content_hash' => 0.0],
            'simhash_details' => [],
            'minhash_details' => [],
        ];
    }

    /** @param array<string, mixed> $performance @param array<string, mixed> $matcherResult */
    private function recordMatcherPerformance(
        array &$performance,
        string $scope,
        string $matcher,
        float $elapsedMilliseconds,
        array $matcherResult,
    ): void {
        $pipeline = $scope === 'title' ? 'title_pipeline' : 'content_pipeline';
        $performance[$pipeline]['matcher_breakdown'][$matcher] = round($elapsedMilliseconds, 3);
        $performance[$pipeline]["{$matcher}_details"] = $matcherResult['stats'] ?? [];
        if ($scope === 'content') {
            $performance[$pipeline][$matcher] = round($elapsedMilliseconds, 2);
        }
    }

    /** @param array<string, mixed> $performance @return array<string, mixed> */
    private function completePerformance(array $performance, float $totalStartedAt): array
    {
        $performance['total'] = round((microtime(true) - $totalStartedAt) * 1000, 2);
        return $performance;
    }

    /** @param array<string, mixed> $source */
    private function stableDocumentId(array $source): string
    {
        if (array_key_exists('id', $source) && $source['id'] !== null) {
            $sourceFrom = trim((string) ($source['source_from'] ?? ''));
            return $sourceFrom === '' ? (string) $source['id'] : "{$sourceFrom}:{$source['id']}";
        }

        return 'doc_' . md5(
            (string) ($source['source_from'] ?? '') . '|'
            . (string) ($source['title'] ?? '') . '|'
            . (string) ($source['content'] ?? '')
        );
    }

    /** @param array{priority: int, conflict_reason: string, doc_pk: int, external_id: string, raw_hash: string, content_hash: string, title_hash: ?string} $match */
    private function exactResult(
        FingerprintContext $contentContext,
        ?FingerprintContext $titleContext,
        array $match,
        array $performance,
    ): array
    {
        $reason = $match['conflict_reason'];
        $textType = match ($reason) {
            'title_hash' => 'title',
            'content_hash' => 'content',
            default => 'all',
        };

        return [
            'dedupe_status' => 'text_same',
            'inserted' => false,
            'id' => null,
            'raw_hash' => $contentContext->rawHash,
            'content_hash' => $contentContext->contentHash,
            'title_hash' => $titleContext?->exactHash,
            'match_id' => $match['external_id'],
            'match_raw_hash' => $match['raw_hash'],
            'text_type' => $textType,
            'match_content_hash' => $match['content_hash'],
            'match_title_hash' => $match['title_hash'],
            'performance_ms' => $performance,
        ];
    }

    /** @param array<string, mixed> $match @param array<string, mixed> $performance */
    private function similarResult(
        FingerprintContext $contentContext,
        ?FingerprintContext $titleContext,
        array $match,
        string $status,
        string $textType,
        string $metric,
        array $performance,
    ): array
    {
        return [
            'dedupe_status' => $status,
            'inserted' => false,
            'id' => null,
            'raw_hash' => $contentContext->rawHash,
            'content_hash' => $contentContext->contentHash,
            'title_hash' => $titleContext?->exactHash,
            'match_id' => $match['id'],
            'match_raw_hash' => $match['raw_hash'] ?? null,
            'text_type' => $textType,
            'match_content_hash' => $match['content_hash'] ?? null,
            'match_title_hash' => $match['title_hash'] ?? null,
            $metric => $match[$metric],
            'performance_ms' => $performance,
        ];
    }
}
