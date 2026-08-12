<?php

declare(strict_types=1);

namespace App\Service\Redis;

use App\Service\DedupeParameters;
use DateTimeImmutable;
use Hyperf\Redis\Redis;
use RuntimeException;
use Throwable;

/** 按 generation、日期、scope 和 band value 保存 SimHash 候选 external_id。 */
final class SimhashCandidateIndex
{
    public function __construct(
        private readonly RedisKeyFactory $keys,
        private readonly RedisDateBucketResolver $buckets,
    ) {
    }

    /** @param list<array{int, string|int}> $bands */
    public function addDocument(
        Redis $redis,
        string $generation,
        string $bucket,
        string $scope,
        array $bands,
        string $externalId,
        DateTimeImmutable $createdAt,
    ): void {
        $normalized = $this->normalizeBands($bands);
        if ($normalized === []) {
            return;
        }
        $keys = [];
        foreach ($normalized as [$index, $value]) {
            $keys[] = $this->keys->simhash($generation, $scope, $bucket, $index, $value);
        }
        $expireAt = $this->buckets->expireAt($bucket);
        $score = $createdAt->format('U.u');
        $arguments = array_merge($keys, [$score, $externalId, (string) $expireAt]);
        $responses = $redis->eval(<<<'LUA'
local score = ARGV[1]
local member = ARGV[2]
local expire_at = tonumber(ARGV[3])
local results = {}
for index, key in ipairs(KEYS) do
    results[index] = redis.call('ZADD', key, score, member)
    redis.call('EXPIREAT', key, expire_at)
end
return results
LUA, $arguments, count($keys));
        if (!is_array($responses) || count($responses) !== count($keys) || in_array(false, $responses, true)) {
            throw new RuntimeException('SimHash candidate atomic batch write failed.');
        }
    }

    /** @param array<string, float> $members external_id => score */
    public function addMembers(
        Redis $redis,
        string $generation,
        string $bucket,
        string $scope,
        int $bandIndex,
        int $bandValue,
        array $members,
    ): void {
        if ($members === []) {
            return;
        }
        $key = $this->keys->simhash($generation, $scope, $bucket, $bandIndex, $bandValue);
        foreach (array_chunk($members, DedupeParameters::bloomBatchSize(), true) as $chunk) {
            $arguments = [];
            foreach ($chunk as $externalId => $score) {
                $arguments[] = (string) $score;
                $arguments[] = (string) $externalId;
            }
            $response = $redis->rawCommand('ZADD', $key, ...$arguments);
            if ($response === false) {
                throw new RuntimeException("SimHash candidate batch write failed for band {$bandIndex}.");
            }
        }
        $redis->expireAt($key, $this->buckets->expireAt($bucket));
    }

    /** @param array<int, array<string, float>> $groups band_value => (external_id => score) */
    public function addBandGroups(
        Redis $redis,
        string $generation,
        string $bucket,
        string $scope,
        int $bandIndex,
        array $groups,
    ): void {
        foreach (array_chunk($groups, DedupeParameters::bloomBatchSize(), true) as $chunk) {
            $expireAt = $this->buckets->expireAt($bucket);
            $responses = $redis->pipeline(function (\Redis $pipeline) use ($generation, $bucket, $scope, $bandIndex, $chunk, $expireAt): void {
                foreach ($chunk as $bandValue => $members) {
                    $key = $this->keys->simhash($generation, $scope, $bucket, $bandIndex, (int) $bandValue);
                    $arguments = [];
                    foreach ($members as $externalId => $score) {
                        $arguments[] = (string) $score;
                        $arguments[] = (string) $externalId;
                    }
                    if ($arguments !== []) {
                        $pipeline->rawCommand('ZADD', $key, ...$arguments);
                        $pipeline->expireAt($key, $expireAt);
                    }
                }
            });
            if (!is_array($responses) || in_array(false, $responses, true)) {
                throw new RuntimeException("SimHash candidate pipeline write failed for band {$bandIndex}.");
            }
        }
    }

    /**
     * @param list<string> $buckets
     * @param list<array{int, string|int}> $bands
     * @return array<string, list<string>>|null key is "band_index:uint16".
     */
    public function candidates(
        Redis $redis,
        string $generation,
        array $buckets,
        string $scope,
        array $bands,
        int $maxCandidatesPerBand,
    ): ?array {
        try {
            $result = [];
            $checks = [];
            foreach ($this->normalizeBands($bands) as [$index, $value]) {
                $bandKey = "{$index}:{$value}";
                $result[$bandKey] = [];
                foreach ($buckets as $bucket) {
                    $checks[] = [$bandKey, $this->keys->simhash($generation, $scope, $bucket, $index, $value)];
                }
            }
            $responses = $redis->pipeline(static function (\Redis $pipeline) use ($checks, $maxCandidatesPerBand): void {
                foreach ($checks as [, $key]) {
                    $pipeline->zRevRange($key, 0, $maxCandidatesPerBand - 1);
                }
            });
            if (!is_array($responses) || count($responses) !== count($checks)) {
                return null;
            }
            $seen = [];
            foreach ($checks as $offset => [$bandKey]) {
                if (count($result[$bandKey]) >= $maxCandidatesPerBand) {
                    continue;
                }
                $members = $responses[$offset] ?? null;
                if (!is_array($members)) {
                    return null;
                }
                foreach ($members as $externalId) {
                    if (!is_string($externalId) || isset($seen[$bandKey][$externalId])) {
                        continue;
                    }
                    $seen[$bandKey][$externalId] = true;
                    $result[$bandKey][] = $externalId;
                    if (count($result[$bandKey]) >= $maxCandidatesPerBand) {
                        break;
                    }
                }
            }
            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<array{int, string|int}> $bands @return list<array{int, int}> */
    private function normalizeBands(array $bands): array
    {
        $result = [];
        foreach ($bands as $band) {
            if (!is_array($band) || count($band) !== 2 || !is_numeric($band[0])) {
                throw new \InvalidArgumentException('Invalid SimHash band.');
            }
            $index = (int) $band[0];
            $rawValue = $band[1];
            if (is_string($rawValue)) {
                if (preg_match('/\A[0-9a-f]{1,4}\z/i', $rawValue) !== 1) {
                    throw new \InvalidArgumentException('Invalid SimHash band value.');
                }
                $value = hexdec($rawValue);
            } elseif (is_int($rawValue)) {
                $value = $rawValue;
            } else {
                throw new \InvalidArgumentException('Invalid SimHash band value.');
            }
            if ($index < 0 || $index >= DedupeParameters::simhashBands() || $value < 0 || $value > 0xffff) {
                throw new \InvalidArgumentException("Invalid SimHash band index or value: {$index}");
            }
            $result[$index] = [$index, $value];
        }
        return array_values($result);
    }

}
