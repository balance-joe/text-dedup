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
use App\Service\Redis\RedisDedupIndex;
use DateTimeImmutable;
use DateTimeZone;
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Model\Collection;
use InvalidArgumentException;

use function Hyperf\Config\config;

/**
 * 在线去重服务的应用层。
 *
 * 提供精确哈希、SimHash 与 MinHash 判定，并在 insert_on_check=true 时执行
 * Redis 索引预写和 PostgreSQL 原子写入。
 */
final class DedupeService
{
    public function __construct(private readonly RedisDedupIndex $redisIndex)
    {
    }

    public function documentCount(): int
    {
        return DocumentFingerprint::query()->count();
    }

    /**
     * 执行完整去重判定；可选地在 new 结果后写入索引与文档。
     *
     * 当 insert_on_check=true 且判定为 new 时，会把指纹、文本和全部 LSH band
     * 通过一条数据修改 CTE 原子写入 PostgreSQL。
     *
     * @param array<string, mixed> $source
     * @param array{max_hamming?: int, max_bucket_size?: int, limit?: int, levels?: list<string>, insert_on_check?: bool} $options
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
        $insertOnCheck = (bool) ($options['insert_on_check'] ?? false);
        $performance = $this->initialPerformance($contentPreprocessMilliseconds, $titlePreprocessMilliseconds);

        $prefilterStartedAt = microtime(true);
        $prefilterTimings = [];
        // insert_on_check 的并发正确性仍由 PostgreSQL唯一约束与精确预过滤兜底；
        // 只读检查才允许使用 Bloom False 跳过 PG。
        $exactRedisMilliseconds = 0.0;
        $exactBloom = $insertOnCheck
            ? null
            : $this->redisIndex->mightContainExact($documentId, $contentContext, $titleContext, $exactRedisMilliseconds);
        $prefilter = $exactBloom === false
            ? null
            : $this->findPrefilterDuplicate(
                $documentId,
                $contentContext->rawHash,
                $contentContext->exactHash,
                $titleChainEnabled ? $titleContext->exactHash : null,
                $prefilterTimings,
            );
        if ($exactBloom === false) {
            $prefilterTimings = ['redis_bloom_skipped_pg' => true];
        }
        $prefilterTimings['redis_attempted'] = !$insertOnCheck;
        $prefilterTimings['redis_result_available'] = $exactBloom !== null;
        $prefilterTimings['redis_ms'] = round($exactRedisMilliseconds, 3);
        if ($insertOnCheck) {
            $prefilterTimings['redis_skip_reason'] = 'insert_on_check';
        }
        $performance['prefilter'] = round((microtime(true) - $prefilterStartedAt) * 1000, 2);
        $performance['prefilter_details'] = $prefilterTimings;
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

        $result = [
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
            'performance_ms' => [],
        ];
        if ($insertOnCheck) {
            /** @var ConnectionInterface $connection */
            $connection = ApplicationContext::getContainer()
                ->get(ConnectionResolverInterface::class)
                ->connection('default');
            $createdAt = new DateTimeImmutable('now', new DateTimeZone((string) config('dedupe.redis_index.timezone', 'Asia/Shanghai')));
            $redisPrewriteMilliseconds = 0.0;
            $performance['redis_prewrite_attempted'] = true;
            $this->redisIndex->prewrite($documentId, $contentContext, $titleContext, $createdAt, $redisPrewriteMilliseconds);
            $performance['redis_prewrite'] = round($redisPrewriteMilliseconds, 3);
            $writeStartedAt = microtime(true);
            $write = $connection->transaction(
                fn (): array => $this->insertNewDocument($connection, $documentId, $contentContext, $titleContext, $createdAt),
            );
            $performance['insert'] = round((microtime(true) - $writeStartedAt) * 1000, 2);
            if (!$write['inserted']) {
                // 并发请求可能在本次召回结束后先一步完成写入。此时不把它误报为 new。
                $conflict = $this->findPrefilterDuplicate(
                    $documentId,
                    $contentContext->rawHash,
                    $contentContext->exactHash,
                    $titleContext?->exactHash,
                );
                if ($conflict !== null) {
                    return $this->exactResult(
                        $contentContext,
                        $titleContext,
                        $conflict,
                        $this->completePerformance($performance, $totalStartedAt),
                    );
                }
            } else {
                $result['inserted'] = true;
                $result['id'] = $documentId;
            }
        }
        $result['performance_ms'] = $this->completePerformance($performance, $totalStartedAt);
        return $result;
    }

    /**
     * 使用一个数据修改 CTE 完成单篇文章的全部写入。
     *
     * 参考 Python insert_document_fast：先取得 doc_pk，再写文本及 8+32（标题同理）
     * 个 band。但这里把后续四类 band 写入合并在同一 SQL，避免 PHP/PDO 的逐语句
     * 网络往返；INSERT 到父表仍由 PostgreSQL 路由至正确的 LIST 叶分区。
     *
     * @return array{inserted: bool, doc_pk: ?int}
     */
    private function insertNewDocument(ConnectionInterface $connection, string $externalId, FingerprintContext $content, ?FingerprintContext $title, DateTimeImmutable $createdAt): array
    {
        $fingerprintTable = $this->qualifiedTable((new DocumentFingerprint())->getTable());
        $textTable = $this->qualifiedTable((new DocumentText())->getTable());
        $ctes = [
            "inserted AS (
                INSERT INTO {$fingerprintTable}
                    (external_id, source_from, content_hash, title_hash, raw_hash, simhash_hi, simhash_lo,
                     title_simhash_hi, title_simhash_lo, low_information, created_at)
                VALUES (?, ?, decode(?, 'hex'), CASE WHEN ?::text IS NULL THEN NULL ELSE decode(?::text, 'hex') END,
                        decode(?, 'hex'), ?, ?, ?, ?, ?, ?::timestamptz)
                ON CONFLICT DO NOTHING
                RETURNING doc_pk, created_at
            )",
            "text_insert AS (
                INSERT INTO {$textTable} (doc_pk, normalized_title, normalized_content, primary_text)
                SELECT doc_pk, ?, ?, ? FROM inserted
                ON CONFLICT (doc_pk) DO NOTHING
            )",
        ];
        $bindings = [
            $externalId,
            (string) ($content->source['source_from'] ?? ''),
            $content->exactHash,
            $title?->exactHash,
            $title?->exactHash,
            $content->rawHash,
            $content->simhashHiPgBigint,
            $content->simhashLoPgBigint,
            $title?->simhashHiPgBigint,
            $title?->simhashLoPgBigint,
            $content->lowInformation,
            $createdAt->format('Y-m-d H:i:s.uP'),
            $content->normalizedTitle,
            $content->normalizedContent,
            $content->text,
        ];
        $this->appendBandInsertCte($ctes, $bindings, 'content_simhash_insert', new SimhashBand(), $this->normalizeSimhashBands($content->simhashBands), 'integer');
        $this->appendBandInsertCte($ctes, $bindings, 'content_minhash_insert', new MinhashBand(), $this->normalizeMinhashBands($content->minhashBands), 'bigint');
        if ($title !== null) {
            $this->appendBandInsertCte($ctes, $bindings, 'title_simhash_insert', new TitleSimhashBand(), $this->normalizeSimhashBands($title->simhashBands), 'integer');
            $this->appendBandInsertCte($ctes, $bindings, 'title_minhash_insert', new TitleMinhashBand(), $this->normalizeMinhashBands($title->minhashBands), 'bigint');
        }
        $sql = 'WITH ' . implode(",\n", $ctes) . ' SELECT doc_pk FROM inserted';
        $rows = $connection->select($sql, $bindings);
        if ($rows === []) {
            return ['inserted' => false, 'doc_pk' => null];
        }
        return ['inserted' => true, 'doc_pk' => (int) $this->rowValue($rows[0], 'doc_pk')];
    }

    /**
     * @param list<string> $ctes
     * @param list<mixed> $bindings
     * @param list<array{int, int}> $bands
     */
    private function appendBandInsertCte(array &$ctes, array &$bindings, string $name, object $model, array $bands, string $valueType): void
    {
        if ($bands === []) {
            return;
        }
        $indexes = [];
        $values = [];
        foreach ($bands as [$index, $value]) {
            $indexes[] = $index;
            $values[] = $value;
        }
        $table = $this->qualifiedTable($model->getTable());
        // 日期过滤一旦启用，新 band 必须同步写 created_at；即使迁移期写入开关
        // 被误关，也不能生成会被查询窗口永久排除的 NULL 日期行。
        $writeCreatedAt = (bool) config('dedupe.redis_index.band_created_at_write_enabled', false)
            || (bool) config('dedupe.redis_index.date_filter_enabled', false);
        if ($writeCreatedAt) {
            $ctes[] = "{$name} AS (
                INSERT INTO {$table} (band_index, band_value, doc_pk, created_at)
                SELECT value.band_index, value.band_value, inserted.doc_pk, inserted.created_at
                FROM inserted
                CROSS JOIN unnest(?::smallint[], ?::{$valueType}[]) AS value(band_index, band_value)
            )";
        } else {
            $ctes[] = "{$name} AS (
                INSERT INTO {$table} (band_index, band_value, doc_pk)
                SELECT value.band_index, value.band_value, inserted.doc_pk
                FROM inserted
                CROSS JOIN unnest(?::smallint[], ?::{$valueType}[]) AS value(band_index, band_value)
            )";
        }
        $bindings[] = $this->postgresSmallintArray($indexes);
        $bindings[] = $this->postgresBigintArray($values);
    }

    /** PostgreSQL text 格式数组；band 值均为已校验的整数，因此无需字符串转义。 */
    private function postgresSmallintArray(array $values): string
    {
        return '{' . implode(',', array_map(static fn (int $value): string => (string) $value, $values)) . '}';
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
        ?array &$timings = null,
    ): ?array {
        $rawHash = $this->validatedHashHex($rawHashHex, 'raw_hash');
        $contentHash = $this->validatedHashHex($contentHashHex, 'content_hash');
        $titleHash = $titleHashHex === null ? null : $this->validatedHashHex($titleHashHex, 'title_hash');

        $table = $this->qualifiedTable((new DocumentFingerprint())->getTable());
        $columns = 'doc_pk, external_id, raw_hash, content_hash, title_hash';
        $subqueries = [
            "SELECT 1 AS priority, 'external_id' AS conflict_reason, {$columns} FROM {$table} WHERE external_id = ?",
            "SELECT 2 AS priority, 'raw_hash' AS conflict_reason, {$columns} FROM {$table} WHERE raw_hash = decode(?, 'hex')",
            "SELECT 3 AS priority, 'content_hash' AS conflict_reason, {$columns} FROM {$table} WHERE content_hash = decode(?, 'hex')",
        ];
        $bindings = [$externalId, $rawHash, $contentHash];
        if ($titleHash !== null) {
            $subqueries[] = "SELECT 4 AS priority, 'title_hash' AS conflict_reason, {$columns} FROM {$table} WHERE title_hash = decode(?, 'hex')";
            $bindings[] = $titleHash;
        }

        $sql = 'SELECT priority, conflict_reason, ' . $columns
            . ' FROM (' . implode(' UNION ALL ', $subqueries) . ') AS prefilter'
            . ' ORDER BY priority ASC, doc_pk ASC LIMIT 1';
        $select = $this->timedSelect($sql, $bindings);
        $rows = $select['rows'];
        $mappingStartedAt = microtime(true);
        if ($rows === []) {
            $timings = $this->queryTimings($select, (microtime(true) - $mappingStartedAt) * 1000);
            return null;
        }
        $match = $rows[0];

        $result = [
            'priority' => (int) $this->rowValue($match, 'priority'),
            'conflict_reason' => (string) $this->rowValue($match, 'conflict_reason'),
            'doc_pk' => (int) $this->rowValue($match, 'doc_pk'),
            'external_id' => (string) $this->rowValue($match, 'external_id'),
            'raw_hash' => $this->hashHex($this->rowValue($match, 'raw_hash')),
            'content_hash' => $this->hashHex($this->rowValue($match, 'content_hash')),
            'title_hash' => $this->rowValue($match, 'title_hash') === null ? null : $this->hashHex($this->rowValue($match, 'title_hash')),
        ];
        $timings = $this->queryTimings($select, (microtime(true) - $mappingStartedAt) * 1000);
        return $result;
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
    public function findSimhashCandidatesForBands(array $bands, string $scope = 'content', ?int $maxCandidatesPerBand = null, ?array &$timings = null): array
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
            $timings = $this->emptyQueryTimings();
            return $result;
        }

        $bandModel = $scope === 'title' ? new TitleSimhashBand() : new SimhashBand();
        $documentTable = $this->qualifiedTable((new DocumentFingerprint())->getTable());
        $hiColumn = $scope === 'title' ? 'title_simhash_hi' : 'simhash_hi';
        $loColumn = $scope === 'title' ? 'title_simhash_lo' : 'simhash_lo';
        $subqueries = [];
        $bindings = [];
        $dateWindow = $this->bandDateWindow();

        foreach ($normalizedBands as [$bandIndex, $bandValue]) {
            // 与 Python 查询保持一致：直接访问叶分区，每桶独立排序和限流，
            // 但所有数据值仍使用绑定参数，避免每次把请求值拼入 SQL。
            $subqueries[] = sprintf(
                '(SELECT ?::smallint AS band_index, b.band_value, b.doc_pk, d.external_id, d.source_from, d.%s AS simhash_hi, d.%s AS simhash_lo FROM %s AS b JOIN %s AS d ON d.doc_pk = b.doc_pk WHERE b.band_value = ?::integer%s ORDER BY b.doc_pk LIMIT ?::integer)',
                $hiColumn,
                $loColumn,
                $this->bandLeafTable($bandModel->getTable(), $bandIndex, DedupeParameters::simhashBands() - 1),
                $documentTable,
                $dateWindow === null ? '' : ' AND b.created_at >= ?::timestamptz AND b.created_at < ?::timestamptz',
            );
            array_push($bindings, $bandIndex, $bandValue);
            if ($dateWindow !== null) {
                array_push($bindings, ...$dateWindow);
            }
            $bindings[] = $maxCandidatesPerBand;
        }

        $select = $this->timedSelect(implode(' UNION ALL ', $subqueries), $bindings);
        $mappingStartedAt = microtime(true);
        foreach ($select['rows'] as $candidate) {
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

        $timings = $this->queryTimings($select, (microtime(true) - $mappingStartedAt) * 1000);

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
        $bucketTimings = [];
        $candidateMap = $this->findSimhashCandidatesForBands($context->simhashBands, $context->scope, $maxBucketSize + 1, $bucketTimings);
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
        $matchedDocumentTimings = [];
        $matchedDocuments = $this->findMatchDocumentsByDocPks(array_column($matches, 'doc_pk'), $matchedDocumentTimings);
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
            $match['sample_text'] = mb_substr($sampleText, 0, DedupeParameters::sampleTextLength(), 'UTF-8');
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
                'bucket_pool_acquire_ms' => $bucketTimings['pool_acquire_ms'],
                'bucket_sql_ms' => $bucketTimings['sql_ms'],
                'bucket_result_mapping_ms' => $bucketTimings['result_mapping_ms'],
                'candidate_rows' => $candidateRows,
                'candidate_unique_docs' => count($seenDocumentIds),
                'hamming_compare_ms' => round((microtime(true) - $comparisonStartedAt) * 1000, 3),
                'hamming_checks' => $checks,
                'matched_candidates' => $matchedCandidateCount,
                'docs_fetch_ms' => round($fetchMilliseconds, 3),
                'docs_pool_acquire_ms' => $matchedDocumentTimings['pool_acquire_ms'],
                'docs_sql_ms' => $matchedDocumentTimings['sql_ms'],
                'docs_result_mapping_ms' => $matchedDocumentTimings['result_mapping_ms'],
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
    public function findMinhashCandidateIdsForBands(array $bands, string $scope = 'content', ?int $maxCandidatesPerBand = null, ?array &$timings = null): array
    {
        if (!in_array($scope, ['content', 'title'], true)) {
            throw new InvalidArgumentException("Unsupported fingerprint scope: {$scope}");
        }

        $maxCandidatesPerBand ??= (int) config('dedupe.minhash.max_bucket_size', 1000) + 1;
        $normalizedBands = $this->normalizeMinhashBands($bands);
        $result = [];
        foreach ($normalizedBands as [$bandIndex, $bandValue]) {
            $result[$this->bandKey($bandIndex, $bandValue)] = [];
        }
        if ($normalizedBands === []) {
            $timings = $this->emptyQueryTimings();
            return $result;
        }

        $redisMilliseconds = 0.0;
        $bloomResult = $this->redisIndex->mightContainMinhashBands($bands, $scope, null, $redisMilliseconds);
        if ($bloomResult !== null) {
            $normalizedBands = array_values(array_filter(
                $normalizedBands,
                static function (array $band) use ($bloomResult): bool {
                    [$bandIndex, $signedBandValue] = $band;
                    $unsigned = UInt64::toDecimal(UInt64::fromSignedInt64($signedBandValue));
                    // 缺 key 不当作 False，避免错误的 Bloom 数据格式造成漏召回。
                    return ($bloomResult["{$bandIndex}:{$unsigned}"] ?? true) !== false;
                },
            ));
        }
        if ($normalizedBands === []) {
            $timings = $this->emptyQueryTimings();
            $timings['redis_ms'] = round($redisMilliseconds, 3);
            $timings['redis_bloom_skipped_pg'] = true;
            return $result;
        }

        $bandModel = $scope === 'title' ? new TitleMinhashBand() : new MinhashBand();
        $subqueries = [];
        $bindings = [];
        $dateWindow = $this->bandDateWindow();
        foreach ($normalizedBands as [$bandIndex, $bandValue]) {
            $subqueries[] = sprintf(
                '(SELECT ?::smallint AS band_index, b.band_value, b.doc_pk FROM %s AS b WHERE b.band_value = ?::bigint%s ORDER BY b.doc_pk LIMIT ?::integer)',
                $this->bandLeafTable($bandModel->getTable(), $bandIndex, DedupeParameters::minhashBands() - 1),
                $dateWindow === null ? '' : ' AND b.created_at >= ?::timestamptz AND b.created_at < ?::timestamptz',
            );
            array_push($bindings, $bandIndex, $bandValue);
            if ($dateWindow !== null) {
                array_push($bindings, ...$dateWindow);
            }
            $bindings[] = $maxCandidatesPerBand;
        }

        $select = $this->timedSelect(implode(' UNION ALL ', $subqueries), $bindings);
        $mappingStartedAt = microtime(true);
        foreach ($select['rows'] as $candidate) {
            $bandIndex = (int) $this->rowValue($candidate, 'band_index');
            $bandValue = (int) $this->rowValue($candidate, 'band_value');
            $result[$this->bandKey($bandIndex, $bandValue)][] = (int) $this->rowValue($candidate, 'doc_pk');
        }

        $timings = $this->queryTimings($select, (microtime(true) - $mappingStartedAt) * 1000);
        $timings['redis_ms'] = round($redisMilliseconds, 3);
        $timings['redis_bloom_skipped_pg'] = false;

        return $result;
    }

    /**
     * 在 MinHash LSH 候选中按当前配置的 n-gram 执行完整 Jaccard 复核。
     *
     * @return array{matches: list<array{id: string, doc_pk: int, method: string, matched_scope: string, score: float, sample_text: string}>, best_score: float, best_match_id: ?string, skipped_buckets: list<array<string, int|string>>, stats: array<string, int|float>}
     */
    public function matchMinhash(FingerprintContext $context, ?string $documentId = null, ?int $maxBucketSize = null, ?int $limit = null): array
    {
        $maxBucketSize = min(
            $maxBucketSize ?? (int) config('dedupe.minhash.max_bucket_size', 1000),
            (int) config('dedupe.minhash.lsh_max_bucket_size', 2000),
        );
        $maxCandidates = min(
            (int) config('dedupe.minhash.max_candidates', 50),
            (int) config('dedupe.minhash.api_max_checks', 200),
        );
        $limit ??= (int) config('dedupe.result_limit', 20);
        $threshold = (float) config('dedupe.minhash.jaccard_threshold', 0.4);
        if ($maxBucketSize < 1 || $maxCandidates < 1 || $limit < 1 || $threshold < 0 || $threshold > 1) {
            throw new InvalidArgumentException('Invalid MinHash matching configuration.');
        }

        $startedAt = microtime(true);
        $bucketTimings = [];
        $candidateMap = $this->findMinhashCandidateIdsForBands($context->minhashBands, $context->scope, $maxBucketSize + 1, $bucketTimings);
        $queryMilliseconds = (microtime(true) - $startedAt) * 1000;
        $eligibleBandRows = [];
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
            $eligibleBandRows[] = $rows;
        }

        $rankingStartedAt = microtime(true);
        $rankedCandidates = MinhashCandidateRanker::top($eligibleBandRows, $maxCandidates);
        $candidateIds = $rankedCandidates['ids'];
        $rankingMilliseconds = (microtime(true) - $rankingStartedAt) * 1000;
        if ($rankedCandidates['truncated']) {
            $skippedBuckets[] = [
                'level' => 'minhash',
                'reason' => 'candidate limit reached after band-hit ranking',
                'max_candidates' => $maxCandidates,
                'unique_before_limit' => $rankedCandidates['unique_before_limit'],
            ];
        }

        $fetchStartedAt = microtime(true);
        $documentTimings = [];
        $documents = $this->findMatchDocumentsByDocPks($candidateIds, $documentTimings);
        $fetchMilliseconds = (microtime(true) - $fetchStartedAt) * 1000;
        $useNativeJaccard = function_exists('dedupe_jaccard_ngram_many');
        $leftGrams = $useNativeJaccard
            ? []
            : array_fill_keys(Ngram::items($context->text, DedupeParameters::minhashNgram()), true);
        $matches = [];
        $bestScore = 0.0;
        $bestMatchId = null;
        $compareStartedAt = microtime(true);
        $comparisons = [];
        foreach ($candidateIds as $docPk) {
            $document = $documents[$docPk] ?? null;
            if ($document === null || ($documentId !== null && $document['external_id'] === $documentId)) {
                continue;
            }
            $text = $context->scope === 'title' ? $document['normalized_title'] : $document['normalized_content'];
            $comparisons[] = ['document' => $document, 'text' => $text];
        }
        $scores = $useNativeJaccard
            ? dedupe_jaccard_ngram_many($context->text, array_column($comparisons, 'text'), DedupeParameters::minhashNgram())
            : [];
        foreach ($comparisons as $index => $comparison) {
            $document = $comparison['document'];
            $text = $comparison['text'];
            $score = $useNativeJaccard
                ? $scores[$index]
                : $this->jaccard($leftGrams, array_fill_keys(Ngram::items($text, DedupeParameters::minhashNgram()), true));
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
                    'sample_text' => mb_substr($text, 0, DedupeParameters::sampleTextLength(), 'UTF-8'),
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
                'bucket_pool_acquire_ms' => $bucketTimings['pool_acquire_ms'],
                'bucket_sql_ms' => $bucketTimings['sql_ms'],
                'bucket_result_mapping_ms' => $bucketTimings['result_mapping_ms'],
                'redis_ms' => (float) ($bucketTimings['redis_ms'] ?? 0.0),
                'redis_bloom_skipped_pg' => (bool) ($bucketTimings['redis_bloom_skipped_pg'] ?? false),
                'candidate_rows' => $candidateRows,
                'candidate_unique_docs' => count($candidateIds),
                'candidate_unique_docs_before_limit' => $rankedCandidates['unique_before_limit'],
                'candidate_limit' => $maxCandidates,
                'candidate_top_band_hits' => $rankedCandidates['top_band_hits'],
                'candidate_ranking_ms' => round($rankingMilliseconds, 3),
                'docs_fetch_ms' => round($fetchMilliseconds, 3),
                'docs_pool_acquire_ms' => $documentTimings['pool_acquire_ms'],
                'docs_sql_ms' => $documentTimings['sql_ms'],
                'docs_result_mapping_ms' => $documentTimings['result_mapping_ms'],
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
    private function findMatchDocumentsByDocPks(array $docPks, ?array &$timings = null): array
    {
        $ids = [];
        foreach ($docPks as $docPk) {
            $docPk = (int) $docPk;
            if ($docPk > 0) {
                $ids[$docPk] = $docPk;
            }
        }
        if ($ids === []) {
            $timings = $this->emptyQueryTimings();
            return [];
        }

        $fingerprintTable = $this->qualifiedTable((new DocumentFingerprint())->getTable());
        $textTable = $this->qualifiedTable((new DocumentText())->getTable());
        $sql = "SELECT d.doc_pk, d.external_id, d.source_from, d.raw_hash, d.content_hash, d.title_hash,
                       t.normalized_title, t.normalized_content
                FROM {$fingerprintTable} AS d
                LEFT JOIN {$textTable} AS t ON t.doc_pk = d.doc_pk
                WHERE d.doc_pk = ANY(?::bigint[])";
        // 固定 SQL 形状，与 Python psycopg 的 ANY(array) 查询保持一致；候选数
        // 变化时不再生成 1 到 50 个不同的 IN 参数列表，利于 PDO/PG 复用解析结果。
        $select = $this->timedSelect($sql, [$this->postgresBigintArray(array_values($ids))]);
        $rows = $select['rows'];

        $documents = [];
        $mappingStartedAt = microtime(true);
        foreach ($rows as $row) {
            $docPk = (int) $this->rowValue($row, 'doc_pk');
            $titleHash = $this->rowValue($row, 'title_hash');
            $documents[$docPk] = [
                'doc_pk' => $docPk,
                'external_id' => (string) $this->rowValue($row, 'external_id'),
                'source_from' => (string) $this->rowValue($row, 'source_from'),
                'raw_hash' => $this->hashHex($this->rowValue($row, 'raw_hash')),
                'content_hash' => $this->hashHex($this->rowValue($row, 'content_hash')),
                'title_hash' => $titleHash === null ? null : $this->hashHex($titleHash),
                'normalized_title' => (string) ($this->rowValue($row, 'normalized_title') ?? ''),
                'normalized_content' => (string) ($this->rowValue($row, 'normalized_content') ?? ''),
            ];
        }

        $timings = $this->queryTimings($select, (microtime(true) - $mappingStartedAt) * 1000);

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
            if ($bandIndex < 0 || $bandIndex >= DedupeParameters::simhashBands() || $bandValue < 0 || $bandValue > 0xffff) {
                throw new InvalidArgumentException('Invalid SimHash band index or unsigned 16-bit value.');
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
            if ($bandIndex < 0 || $bandIndex >= DedupeParameters::minhashBands()) {
                throw new InvalidArgumentException('Invalid MinHash band index.');
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

    /**
     * 通过 Hyperf 的 ConnectionResolver 取当前协程连接。
     *
     * Resolver 会把连接存入协程 Context，并在协程结束时统一归还；不能直接
     * Pool::get()/release()，否则每条 SQL 都会重复借还连接，绕过 Hyperf 的复用。
     *
     * @param list<mixed> $bindings
     * @return array{rows: list<object>, pool_acquire_ms: float, sql_ms: float}
     */
    private function timedSelect(string $sql, array $bindings): array
    {
        $poolStartedAt = microtime(true);
        $connection = ApplicationContext::getContainer()
            ->get(ConnectionResolverInterface::class)
            ->connection('default');
        $poolAcquireMilliseconds = (microtime(true) - $poolStartedAt) * 1000;
        $sqlStartedAt = microtime(true);
        $rows = $connection->select($sql, $bindings);
        $sqlMilliseconds = (microtime(true) - $sqlStartedAt) * 1000;

        return [
            'rows' => $rows,
            'pool_acquire_ms' => $poolAcquireMilliseconds,
            'sql_ms' => $sqlMilliseconds,
        ];
    }

    /**
     * @param array{rows: list<object>, pool_acquire_ms: float, sql_ms: float} $select
     * @return array{pool_acquire_ms: float, sql_ms: float, result_mapping_ms: float}
     */
    private function queryTimings(array $select, float $resultMappingMilliseconds): array
    {
        return [
            'pool_acquire_ms' => round($select['pool_acquire_ms'], 3),
            'sql_ms' => round($select['sql_ms'], 3),
            'result_mapping_ms' => round($resultMappingMilliseconds, 3),
        ];
    }

    /** @return array{pool_acquire_ms: float, sql_ms: float, result_mapping_ms: float} */
    private function emptyQueryTimings(): array
    {
        return ['pool_acquire_ms' => 0.0, 'sql_ms' => 0.0, 'result_mapping_ms' => 0.0];
    }

    /** @param list<int> $values PostgreSQL bigint[] 的文本输入格式。 */
    private function postgresBigintArray(array $values): string
    {
        return '{' . implode(',', array_map(static fn (int $value): string => (string) $value, $values)) . '}';
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

    /** @return array{string, string}|null */
    private function bandDateWindow(): ?array
    {
        if (!(bool) config('dedupe.redis_index.date_filter_enabled', false)) {
            return null;
        }
        $timezone = new DateTimeZone((string) config('dedupe.redis_index.timezone', 'Asia/Shanghai'));
        $retentionDays = max(1, (int) config('dedupe.redis_index.retention_days', 10));
        $to = new DateTimeImmutable('tomorrow', $timezone);
        $from = $to->modify('-' . $retentionDays . ' days');
        return [$from->format('Y-m-d H:i:sP'), $to->format('Y-m-d H:i:sP')];
    }

    private function hammingDistance(string $left, string $right): int
    {
        $bytes = intdiv(DedupeParameters::simhashBits(), 8);
        if (strlen($left) !== $bytes || strlen($right) !== $bytes) {
            throw new InvalidArgumentException("SimHash values must contain exactly {$bytes} bytes.");
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
            'prefilter_details' => [],
            'redis_prewrite' => 0.0,
            'redis_prewrite_attempted' => false,
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
            return (string) $source['id'];
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
