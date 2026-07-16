<?php

declare(strict_types=1);

namespace App\Service\Redis;

use Hyperf\Redis\Redis;
use RuntimeException;

final class RedisIndexGenerationManager
{
    public function __construct(private readonly RedisKeyFactory $keys)
    {
    }

    public function activeReady(Redis $redis): ?string
    {
        $generation = $redis->get($this->keys->activeGeneration());
        if (!is_string($generation) || $generation === '') {
            return null;
        }
        $this->keys->assertGeneration($generation);
        return $this->status($redis, $generation) === RedisIndexStatus::Ready ? $generation : null;
    }

    /** @return list<string> */
    public function writableGenerations(Redis $redis): array
    {
        // activation 本身由 Lua 原子切换 active 并删除 building；这里也必须原子读取
        // 两个指针，避免在两次 GET 之间切换后只拿到旧 active，造成新 active 漏写。
        $snapshot = $redis->eval(<<<'LUA'
local active = redis.call('GET', KEYS[1])
local building = redis.call('GET', KEYS[2])
return {active or '', building or ''}
LUA, [
            $this->keys->activeGeneration(),
            $this->keys->buildingGeneration(),
        ], 2);
        if (!is_array($snapshot) || count($snapshot) !== 2) {
            throw new RuntimeException('Unable to read writable Redis index generations atomically.');
        }

        $result = [];
        $active = is_string($snapshot[0]) ? $snapshot[0] : '';
        if ($active !== '') {
            $this->keys->assertGeneration($active);
        }
        if ($active !== '' && $this->status($redis, $active) === RedisIndexStatus::Ready) {
            $result[$active] = $active;
        }

        $building = is_string($snapshot[1]) ? $snapshot[1] : '';
        if ($building !== '') {
            $this->keys->assertGeneration($building);
            if (in_array($this->status($redis, $building), [RedisIndexStatus::Building, RedisIndexStatus::Ready], true)) {
                $result[$building] = $building;
            }
        }
        return array_values($result);
    }

    public function beginBuild(Redis $redis, string $generation, array $metadata = []): void
    {
        $this->keys->assertGeneration($generation);
        $current = $redis->get($this->keys->buildingGeneration());
        if (is_string($current) && $current !== '' && $current !== $generation) {
            throw new RuntimeException("Redis index generation {$current} is already building.");
        }
        $meta = array_merge($metadata, [
            'status' => RedisIndexStatus::Building->value,
            'created_at' => (string) ($metadata['created_at'] ?? date(DATE_ATOM)),
        ]);
        if (!$redis->hMSet($this->keys->generationMeta($generation), $meta)
            || !$redis->set($this->keys->buildingGeneration(), $generation)) {
            throw new RuntimeException('Unable to initialize Redis index generation.');
        }
    }

    public function markReady(Redis $redis, string $generation): void
    {
        if ($redis->hSet($this->keys->generationMeta($generation), 'status', RedisIndexStatus::Ready->value) === false) {
            throw new RuntimeException("Unable to mark generation {$generation} ready.");
        }
    }

    public function markDegraded(Redis $redis, string $generation, string $reason): bool
    {
        try {
            return $redis->hMSet($this->keys->generationMeta($generation), [
                'status' => RedisIndexStatus::Degraded->value,
                'degraded_at' => date(DATE_ATOM),
                'degraded_reason' => mb_substr($reason, 0, 500, 'UTF-8'),
            ]);
        } catch (\Throwable) {
            return false;
        }
    }

    public function activate(Redis $redis, string $generation): void
    {
        $this->keys->assertGeneration($generation);
        $script = <<<'LUA'
local building = redis.call('GET', KEYS[1])
local status = redis.call('HGET', KEYS[3], 'status')
if building ~= ARGV[1] then return {err = 'generation is not building'} end
if status ~= 'ready' then return {err = 'generation is not ready'} end
redis.call('SET', KEYS[2], ARGV[1])
redis.call('DEL', KEYS[1])
return 1
LUA;
        $result = $redis->eval($script, [
            $this->keys->buildingGeneration(),
            $this->keys->activeGeneration(),
            $this->keys->generationMeta($generation),
            $generation,
        ], 3);
        if ($result !== 1) {
            throw new RuntimeException("Unable to activate Redis index generation {$generation}.");
        }
    }

    public function status(Redis $redis, string $generation): ?RedisIndexStatus
    {
        $value = $redis->hGet($this->keys->generationMeta($generation), 'status');
        return is_string($value) ? RedisIndexStatus::tryFrom($value) : null;
    }

    /** @return array<string, string> */
    public function metadata(Redis $redis, string $generation): array
    {
        $values = $redis->hGetAll($this->keys->generationMeta($generation));
        return is_array($values) ? array_map('strval', $values) : [];
    }
}
