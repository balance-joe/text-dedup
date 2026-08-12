<?php

declare(strict_types=1);

namespace App\Service\Redis;

use App\Service\FingerprintContext;
use DateTimeImmutable;
use Hyperf\Redis\Redis;
use Hyperf\Redis\RedisFactory;
use Throwable;

use function Hyperf\Config\config;

final class RedisDedupIndex
{
    /** @var array<string, bool> */
    private array $simhashReady = [];

    public function __construct(
        private readonly RedisFactory $redisFactory,
        private readonly RedisIndexGenerationManager $generations,
        private readonly RedisDateBucketResolver $buckets,
        private readonly ExactHashBloomIndex $exact,
        private readonly SimhashCandidateIndex $simhash,
        private readonly MinhashBloomIndex $minhash,
    ) {
    }

    public function prewrite(string $externalId, FingerprintContext $content, ?FingerprintContext $title, DateTimeImmutable $createdAt, ?float &$milliseconds = null): RedisPrewriteResult
    {
        $startedAt = microtime(true);
        try {
            return $this->performPrewrite($externalId, $content, $title, $createdAt);
        } finally {
            $milliseconds = (microtime(true) - $startedAt) * 1000;
        }
    }

    private function performPrewrite(string $externalId, FingerprintContext $content, ?FingerprintContext $title, DateTimeImmutable $createdAt): RedisPrewriteResult
    {
        if (!$this->enabled()) {
            return RedisPrewriteResult::skipped();
        }
        $redis = $this->redis();
        $writable = [];
        try {
            $writable = $this->generations->writableGenerations($redis);
            if ($writable === []) {
                return RedisPrewriteResult::skipped();
            }
            $bucket = $this->buckets->writeBucket($createdAt);
            foreach ($writable as $generation) {
                if ((bool) config('dedupe.redis_index.exact.enabled', true)) {
                    $this->exact->addDocument($redis, $generation, $externalId, $content, $title);
                }
                if ((bool) config('dedupe.redis_index.simhash.enabled', true)
                    && $this->supportsSimhash($redis, $generation)) {
                    $this->simhash->addDocument($redis, $generation, $bucket, 'content', $content->simhashBands, $externalId, $createdAt);
                    if ($title !== null && $title->text !== '') {
                        $this->simhash->addDocument($redis, $generation, $bucket, 'title', $title->simhashBands, $externalId, $createdAt);
                    }
                }
                if ((bool) config('dedupe.redis_index.minhash.enabled', true)) {
                    $this->minhash->addBands($redis, $generation, $bucket, 'content', $content->minhashBands);
                    if ($title !== null && $title->text !== '') {
                        $this->minhash->addBands($redis, $generation, $bucket, 'title', $title->minhashBands);
                    }
                }
            }
            return RedisPrewriteResult::success($writable);
        } catch (Throwable $exception) {
            $degradedConfirmed = $writable !== [];
            foreach ($writable as $generation) {
                $degradedConfirmed = $this->generations->markDegraded($redis, $generation, $exception->getMessage()) && $degradedConfirmed;
            }
            if (!$degradedConfirmed) {
                throw new \RuntimeException(
                    'Redis prewrite failed and generation degradation could not be confirmed; PostgreSQL insert was aborted.',
                    0,
                    $exception,
                );
            }
            error_log('[dedupe] Redis index prewrite failed; PostgreSQL fallback enabled: ' . $exception->getMessage());
            return RedisPrewriteResult::degraded($writable, $exception->getMessage());
        }
    }

    public function mightContainExact(string $externalId, FingerprintContext $content, ?FingerprintContext $title, ?float &$milliseconds = null): ?bool
    {
        $startedAt = microtime(true);
        try {
            return $this->performMightContainExact($externalId, $content, $title);
        } finally {
            $milliseconds = (microtime(true) - $startedAt) * 1000;
        }
    }

    private function performMightContainExact(string $externalId, FingerprintContext $content, ?FingerprintContext $title): ?bool
    {
        if (!$this->enabled() || !(bool) config('dedupe.redis_index.exact.enabled', true)) {
            return null;
        }
        try {
            $redis = $this->redis();
            $generation = $this->generations->activeReady($redis);
            if ($generation === null) {
                return null;
            }
            return $this->exact->mightContain($redis, $generation, $externalId, $content, $title);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<array{int, string|int}> $bands
     * @return array<string, list<string>>|null
     */
    public function findSimhashCandidateExternalIds(
        array $bands,
        string $scope,
        int $maxCandidatesPerBand,
        ?DateTimeImmutable $now = null,
        ?float &$milliseconds = null,
    ): ?array
    {
        $startedAt = microtime(true);
        try {
            return $this->performFindSimhashCandidateExternalIds($bands, $scope, $maxCandidatesPerBand, $now);
        } finally {
            $milliseconds = (microtime(true) - $startedAt) * 1000;
        }
    }

    /** @param list<array{int, string|int}> $bands @return array<string, list<string>>|null */
    private function performFindSimhashCandidateExternalIds(array $bands, string $scope, int $maxCandidatesPerBand, ?DateTimeImmutable $now): ?array
    {
        if (!$this->enabled() || !(bool) config('dedupe.redis_index.simhash.enabled', true)) {
            return null;
        }
        try {
            $redis = $this->redis();
            $generation = $this->generations->activeReady($redis);
            if ($generation === null) {
                return null;
            }
            if (!$this->supportsSimhash($redis, $generation)) {
                return null;
            }
            return $this->simhash->candidates(
                $redis,
                $generation,
                $this->buckets->queryBuckets($now),
                $scope,
                $bands,
                $maxCandidatesPerBand,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<array{int, string}> $bands
     * @return array<string, bool>|null
     */
    public function mightContainMinhashBands(array $bands, string $scope, ?DateTimeImmutable $now = null, ?float &$milliseconds = null): ?array
    {
        $startedAt = microtime(true);
        try {
            return $this->performMightContainMinhashBands($bands, $scope, $now);
        } finally {
            $milliseconds = (microtime(true) - $startedAt) * 1000;
        }
    }

    private function performMightContainMinhashBands(array $bands, string $scope, ?DateTimeImmutable $now): ?array
    {
        if (!$this->enabled() || !(bool) config('dedupe.redis_index.minhash.enabled', true)) {
            return null;
        }
        try {
            $redis = $this->redis();
            $generation = $this->generations->activeReady($redis);
            if ($generation === null) {
                return null;
            }
            return $this->minhash->mightContain($redis, $generation, $this->buckets->queryBuckets($now), $scope, $bands);
        } catch (Throwable) {
            return null;
        }
    }

    private function enabled(): bool
    {
        return (bool) config('dedupe.redis_index.enabled', false);
    }

    private function supportsSimhash(Redis $redis, string $generation): bool
    {
        if (!isset($this->simhashReady[$generation])) {
            $metadata = $this->generations->metadata($redis, $generation);
            $this->simhashReady[$generation] = ($metadata['simhash_redis_index'] ?? '') === 'zset_v1';
        }
        return $this->simhashReady[$generation];
    }

    private function redis(): Redis
    {
        return $this->redisFactory->get('default');
    }
}
