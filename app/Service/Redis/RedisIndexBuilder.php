<?php

declare(strict_types=1);

namespace App\Service\Redis;

use App\Support\UInt64;
use DateTimeImmutable;
use DateTimeZone;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Redis\Redis;
use Hyperf\Redis\RedisFactory;
use InvalidArgumentException;

use function Hyperf\Config\config;

final class RedisIndexBuilder
{
    public function __construct(
        private readonly RedisFactory $redisFactory,
        private readonly ConnectionResolverInterface $connections,
        private readonly RedisIndexGenerationManager $generations,
        private readonly RedisKeyFactory $keys,
        private readonly RedisDateBucketResolver $buckets,
        private readonly ExactHashBloomIndex $exact,
        private readonly MinhashBloomIndex $minhash,
    ) {
    }

    /** @param callable(string): void|null $progress */
    public function build(string $generation, DateTimeImmutable $from, DateTimeImmutable $to, int $batchSize = 10000, ?callable $progress = null): void
    {
        if (!(bool) config('dedupe.redis_index.enabled', false)) {
            throw new \RuntimeException('Enable DEDUPE_REDIS_INDEX_ENABLED before building so online writes can populate the building generation.');
        }
        if (!(bool) config('dedupe.redis_index.band_created_at_write_enabled', false)) {
            throw new \RuntimeException('Enable DEDUPE_BAND_CREATED_AT_WRITE_ENABLED before building Redis date buckets.');
        }
        if ($from >= $to) {
            throw new InvalidArgumentException('--from must be earlier than --to.');
        }
        $redis = $this->redis();
        $this->generations->beginBuild($redis, $generation, [
            'min_date' => $from->format('Ymd'),
            'max_date' => $to->modify('-1 second')->format('Ymd'),
            'algorithm_version' => 'v1',
        ]);
        try {
            $this->exact->reserveGeneration($redis, $generation);
            for ($day = $from->setTime(0, 0); $day < $to; $day = $day->modify('+1 day')) {
                $bucket = $this->buckets->writeBucket($day);
                $this->minhash->reserveBucket($redis, $generation, $bucket, 'content');
                $this->minhash->reserveBucket($redis, $generation, $bucket, 'title');
                $progress?->__invoke("reserved {$bucket}");
            }
            $this->buildExact($redis, $generation, $batchSize, $progress);
            $this->buildMinhash($redis, $generation, $from, $to, $batchSize, $progress);
            $this->generations->markReady($redis, $generation);
        } catch (\Throwable $exception) {
            $this->generations->markDegraded($redis, $generation, $exception->getMessage());
            throw $exception;
        }
    }

    public function activate(string $generation): void
    {
        if (!(bool) config('dedupe.redis_index.date_filter_enabled', false)) {
            throw new \RuntimeException('Enable DEDUPE_BAND_DATE_FILTER_ENABLED before activating a date-bucketed generation.');
        }
        $this->generations->activate($this->redis(), $generation);
    }

    /** @return array<string, string> */
    public function status(string $generation): array
    {
        return $this->generations->metadata($this->redis(), $generation);
    }

    public function cleanup(string $generation): int
    {
        $redis = $this->redis();
        if ($redis->get($this->keys->activeGeneration()) === $generation
            || $redis->get($this->keys->buildingGeneration()) === $generation) {
            throw new \RuntimeException('Active or building generation cannot be cleaned up.');
        }
        $prefix = $this->keys->generationPrefix($generation);
        $iterator = null;
        $deleted = 0;
        do {
            $keys = $redis->scan($iterator, $prefix . '*', 500);
            if (is_array($keys) && $keys !== []) {
                $deleted += (int) $redis->unlink(...$keys);
            }
        } while ($iterator !== 0);
        $redis->del($this->keys->generationMeta($generation));
        return $deleted;
    }

    private function buildExact(Redis $redis, string $generation, int $batchSize, ?callable $progress): void
    {
        $connection = $this->connection();
        $table = $this->qualified('document_fingerprint');
        $checkpointKey = $this->keys->checkpoint($generation, 'exact:doc_pk');
        $checkpoint = $redis->get($checkpointKey);
        $lastDocPk = is_string($checkpoint) && ctype_digit($checkpoint) ? (int) $checkpoint : 0;
        do {
            $rows = $connection->select(
                "SELECT doc_pk, external_id, encode(raw_hash, 'hex') AS raw_hash,
                        encode(content_hash, 'hex') AS content_hash,
                        CASE WHEN title_hash IS NULL THEN NULL ELSE encode(title_hash, 'hex') END AS title_hash
                 FROM {$table}
                 WHERE doc_pk > ?
                 ORDER BY doc_pk
                 LIMIT ?",
                [$lastDocPk, max(1, $batchSize)],
            );
            if ($rows === []) {
                break;
            }
            $externalIds = [];
            $hashes = ['raw_hash' => [], 'content_hash' => [], 'title_hash' => []];
            foreach ($rows as $row) {
                $lastDocPk = (int) $row->doc_pk;
                $externalIds[] = (string) $row->external_id;
                $hashes['raw_hash'][] = (string) $row->raw_hash;
                if ($row->content_hash !== null) {
                    $hashes['content_hash'][] = (string) $row->content_hash;
                }
                if ($row->title_hash !== null) {
                    $hashes['title_hash'][] = (string) $row->title_hash;
                }
            }
            $this->exact->addExternalIds($redis, $generation, $externalIds);
            foreach ($hashes as $field => $values) {
                $this->exact->addHashValues($redis, $generation, $field, $values);
            }
            $redis->set($checkpointKey, (string) $lastDocPk);
            $progress?->__invoke("exact doc_pk={$lastDocPk}");
        } while (count($rows) === $batchSize);
        $redis->del($checkpointKey);
    }

    private function buildMinhash(Redis $redis, string $generation, DateTimeImmutable $from, DateTimeImmutable $to, int $batchSize, ?callable $progress): void
    {
        $connection = $this->connection();
        foreach (['content' => 'minhash_band', 'title' => 'title_minhash_band'] as $scope => $parent) {
            for ($bandIndex = 0; $bandIndex < 32; ++$bandIndex) {
                $table = $this->qualified("{$parent}_p{$bandIndex}");
                $checkpointKey = $this->keys->checkpoint($generation, "minhash:{$scope}:b{$bandIndex}");
                $checkpoint = $redis->get($checkpointKey);
                $decoded = is_string($checkpoint) ? json_decode($checkpoint, true) : null;
                $last = is_array($decoded)
                    && isset($decoded['band_value'], $decoded['created_at'], $decoded['doc_pk'])
                    ? $decoded
                    : null;
                do {
                    $bindings = [$from->format('Y-m-d H:i:sP'), $to->format('Y-m-d H:i:sP')];
                    $after = '';
                    if ($last !== null) {
                        $after = ' AND (band_value, created_at, doc_pk) > (?::bigint, ?::timestamptz, ?::bigint)';
                        array_push($bindings, $last['band_value'], $last['created_at'], $last['doc_pk']);
                    }
                    $bindings[] = max(1, $batchSize);
                    $rows = $connection->select(
                        "SELECT band_value, created_at, doc_pk
                         FROM {$table}
                         WHERE created_at >= ?::timestamptz AND created_at < ?::timestamptz{$after}
                         ORDER BY band_value, created_at, doc_pk
                         LIMIT ?",
                        $bindings,
                    );
                    if ($rows === []) {
                        break;
                    }
                    $grouped = [];
                    foreach ($rows as $row) {
                        $createdAt = new DateTimeImmutable((string) $row->created_at);
                        $bucket = $this->buckets->writeBucket($createdAt);
                        $signed = (int) $row->band_value;
                        $grouped[$bucket][] = UInt64::toDecimal(UInt64::fromSignedInt64($signed));
                        $last = [
                            'band_value' => $signed,
                            'created_at' => $createdAt->format('Y-m-d H:i:s.uP'),
                            'doc_pk' => (int) $row->doc_pk,
                        ];
                    }
                    foreach ($grouped as $bucket => $values) {
                        $this->minhash->addBandValues($redis, $generation, $bucket, $scope, $bandIndex, $values);
                    }
                    $redis->set($checkpointKey, json_encode($last, JSON_THROW_ON_ERROR));
                    $redis->hIncrBy(
                        $this->keys->generationMeta($generation),
                        "{$scope}_minhash_rows_processed",
                        count($rows),
                    );
                    $progress?->__invoke("{$scope} minhash band={$bandIndex} doc_pk={$last['doc_pk']}");
                } while (count($rows) === $batchSize);
                $redis->del($checkpointKey);
            }
        }
    }

    private function connection(): ConnectionInterface
    {
        /** @var ConnectionInterface $connection */
        $connection = $this->connections->connection('rebuild');
        $connection->statement("SET application_name = 'dedupe-redis-build'");
        $connection->statement("SET idle_in_transaction_session_timeout = '60s'");
        return $connection;
    }

    private function redis(): Redis
    {
        return $this->redisFactory->get('default');
    }

    private function qualified(string $table): string
    {
        $schema = (string) config('dedupe.database.schema', 'dedup_content');
        if (preg_match('/\A[a-z_][a-z0-9_]*\z/i', $schema) !== 1 || preg_match('/\A[a-z_][a-z0-9_]*\z/i', $table) !== 1) {
            throw new InvalidArgumentException('Invalid PostgreSQL identifier.');
        }
        return sprintf('"%s"."%s"', $schema, $table);
    }
}
