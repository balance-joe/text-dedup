<?php

declare(strict_types=1);

namespace App\Service\Redis;

use App\Service\DedupeParameters;
use Hyperf\Redis\Redis;
use RuntimeException;
use Throwable;

use function Hyperf\Config\config;

final class MinhashBloomIndex
{
    /** @var array<string, true> */
    private array $knownKeys = [];

    public function __construct(
        private readonly RedisKeyFactory $keys,
        private readonly RedisDateBucketResolver $buckets,
    ) {
    }

    /** @param list<array{int, string}> $bands */
    public function addBands(Redis $redis, string $generation, string $bucket, string $scope, array $bands): void
    {
        $normalized = $this->normalizeBands($bands);
        if ($normalized === []) {
            return;
        }

        $keys = [];
        $values = [];
        foreach ($normalized as [$index, $value]) {
            $key = $this->keys->minhash($generation, $scope, $bucket, $index);
            $keys[] = $key;
            $values[] = $value;
            $this->knownKeys[$key] = true;
        }

        $capacity = max(1, (int) config("dedupe.redis_index.minhash.{$scope}_daily_capacity", 1300000));
        $errorRate = (string) config('dedupe.redis_index.minhash.error_rate', 0.00001);
        $expansion = max(1, (int) config('dedupe.redis_index.expansion', 2));
        $expireAt = $this->buckets->expireAt($bucket);
        $arguments = array_merge($keys, [$errorRate, (string) $capacity, (string) $expansion, (string) $expireAt], $values);

        // 单次 EVAL 在 Redis 内部完成32个band的懒创建、TTL校验和写入，避免
        // 每篇文档产生32次 BF.RESERVE + 32次 EXPIREAT 的网络往返。
        $responses = $redis->eval(<<<'LUA'
local error_rate = ARGV[1]
local capacity = ARGV[2]
local expansion = ARGV[3]
local expire_at = tonumber(ARGV[4])
local results = {}

for index, key in ipairs(KEYS) do
    if redis.call('EXISTS', key) == 0 then
        redis.call('BF.RESERVE', key, error_rate, capacity, 'EXPANSION', expansion)
    end
    if redis.call('TTL', key) < 0 then
        redis.call('EXPIREAT', key, expire_at)
    end
    results[index] = redis.call('BF.ADD', key, ARGV[index + 4])
end

return results
LUA, $arguments, count($keys));
        if (!is_array($responses) || count($responses) !== count($normalized) || in_array(false, $responses, true)) {
            throw new RuntimeException('MinHash Bloom atomic batch write failed.');
        }
    }

    /** @param list<string> $values */
    public function addBandValues(Redis $redis, string $generation, string $bucket, string $scope, int $bandIndex, array $values): void
    {
        if ($bandIndex < 0 || $bandIndex >= DedupeParameters::minhashBands()) {
            throw new \InvalidArgumentException("Invalid MinHash band index: {$bandIndex}");
        }
        $values = array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && preg_match('/\A\d+\z/', $value) === 1));
        if ($values === []) {
            return;
        }
        $key = $this->keys->minhash($generation, $scope, $bucket, $bandIndex);
        $this->ensureFilter($redis, $key, $scope);
        $redis->expireAt($key, $this->buckets->expireAt($bucket));
        foreach (array_chunk($values, DedupeParameters::bloomBatchSize()) as $chunk) {
            $response = $redis->rawCommand('BF.MADD', $key, ...$chunk);
            if (!is_array($response) || count($response) !== count($chunk)) {
                throw new RuntimeException("MinHash Bloom batch write failed for band {$bandIndex}.");
            }
        }
    }

    /**
     * @param list<array{int, string}> $bands
     * @param list<string> $buckets
     * @return array<string, bool>|null key is "band_index:uint64"; true means PG must be queried.
     */
    public function mightContain(Redis $redis, string $generation, array $buckets, string $scope, array $bands): ?array
    {
        try {
            $result = [];
            $checks = [];
            foreach ($this->normalizeBands($bands) as [$index, $value]) {
                $result["{$index}:{$value}"] = false;
                foreach ($buckets as $bucket) {
                    $checks[] = [$index, $value, $this->keys->minhash($generation, $scope, $bucket, $index)];
                }
            }
            $exists = $redis->pipeline(static function (\Redis $pipeline) use ($checks): void {
                foreach ($checks as [, , $key]) {
                    $pipeline->exists($key);
                }
            });
            if (!is_array($exists) || count($exists) !== count($checks) || in_array(0, $exists, true) || in_array(false, $exists, true)) {
                return null;
            }
            $matches = $redis->pipeline(static function (\Redis $pipeline) use ($checks): void {
                foreach ($checks as [, $value, $key]) {
                    $pipeline->rawCommand('BF.EXISTS', $key, $value);
                }
            });
            if (!is_array($matches) || count($matches) !== count($checks)) {
                return null;
            }
            foreach ($checks as $offset => [$index, $value]) {
                if (($matches[$offset] ?? 0) === 1 || ($matches[$offset] ?? false) === true) {
                    $result["{$index}:{$value}"] = true;
                }
            }
            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    public function reserveBucket(Redis $redis, string $generation, string $bucket, string $scope): void
    {
        for ($index = 0; $index < DedupeParameters::minhashBands(); ++$index) {
            $key = $this->keys->minhash($generation, $scope, $bucket, $index);
            $this->ensureFilter($redis, $key, $scope);
            $redis->expireAt($key, $this->buckets->expireAt($bucket));
        }
    }

    /** @param list<array{int, string}> $bands @return list<array{int, string}> */
    private function normalizeBands(array $bands): array
    {
        $result = [];
        foreach ($bands as $band) {
            if (!is_array($band) || count($band) !== 2 || !is_numeric($band[0]) || !is_string($band[1]) || preg_match('/\A\d+\z/', $band[1]) !== 1) {
                throw new \InvalidArgumentException('Invalid MinHash band.');
            }
            $index = (int) $band[0];
            if ($index < 0 || $index >= DedupeParameters::minhashBands()) {
                throw new \InvalidArgumentException("Invalid MinHash band index: {$index}");
            }
            $result[$index] = [$index, $band[1]];
        }
        return array_values($result);
    }

    private function ensureFilter(Redis $redis, string $key, string $scope): void
    {
        if (isset($this->knownKeys[$key])) {
            return;
        }
        $capacity = (int) config("dedupe.redis_index.minhash.{$scope}_daily_capacity", 1300000);
        try {
            $redis->rawCommand(
                'BF.RESERVE',
                $key,
                (string) config('dedupe.redis_index.minhash.error_rate', 0.00001),
                (string) max(1, $capacity),
                'EXPANSION',
                (string) max(1, (int) config('dedupe.redis_index.expansion', 2)),
            );
        } catch (Throwable $exception) {
            if (!str_contains(strtolower($exception->getMessage()), 'exist')) {
                throw $exception;
            }
        }
        $this->knownKeys[$key] = true;
    }
}
