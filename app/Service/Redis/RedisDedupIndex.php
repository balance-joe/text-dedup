<?php

declare(strict_types=1);

namespace App\Service\Redis;

use App\Service\FingerprintContext;
use DateTimeImmutable;
use Hyperf\Redis\RedisFactory;
use Redis;
use Throwable;

use function Hyperf\Config\config;

final class RedisDedupIndex
{
    public function __construct(
        private readonly RedisFactory $redisFactory,
        private readonly RedisIndexGenerationManager $generations,
        private readonly RedisDateBucketResolver $buckets,
        private readonly ExactHashBloomIndex $exact,
        private readonly MinhashBloomIndex $minhash,
    ) {
    }

    public function prewrite(string $externalId, FingerprintContext $content, ?FingerprintContext $title, DateTimeImmutable $createdAt): RedisPrewriteResult
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

    public function mightContainExact(string $externalId, FingerprintContext $content, ?FingerprintContext $title, ?DateTimeImmutable $now = null): ?bool
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
     * @param list<array{int, string}> $bands
     * @return array<string, bool>|null
     */
    public function mightContainMinhashBands(array $bands, string $scope, ?DateTimeImmutable $now = null): ?array
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

    private function redis(): Redis
    {
        /** @var Redis $redis */
        $redis = $this->redisFactory->get('default');
        return $redis;
    }
}
