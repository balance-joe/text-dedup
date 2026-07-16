<?php

declare(strict_types=1);

namespace HyperfTest\Unit;

use App\Service\Redis\RedisIndexGenerationManager;
use App\Service\Redis\RedisKeyFactory;
use Hyperf\Redis\Redis;
use PHPUnit\Framework\TestCase;

final class RedisIndexGenerationManagerTest extends TestCase
{
    public function testWritableGenerationPointersAreReadInOneLuaSnapshot(): void
    {
        $redis = new GenerationSnapshotRedis();
        $manager = new RedisIndexGenerationManager(new RedisKeyFactory('dedupe:test'));

        self::assertSame(['g000001', 'g000002'], $manager->writableGenerations($redis));
        self::assertSame(1, $redis->evalCalls);
        self::assertSame(2, $redis->lastNumKeys);
        self::assertSame(
            ['dedupe:test:meta:active_generation', 'dedupe:test:meta:building_generation'],
            $redis->lastArgs,
        );
    }
}

final class GenerationSnapshotRedis extends Redis
{
    public int $evalCalls = 0;

    public int $lastNumKeys = 0;

    /** @var list<mixed> */
    public array $lastArgs = [];

    public function __construct()
    {
    }

    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        ++$this->evalCalls;
        $this->lastArgs = $args;
        $this->lastNumKeys = $num_keys;
        return ['g000001', 'g000002'];
    }

    public function hGet(string $key, string $member): mixed
    {
        return $key === 'dedupe:test:meta:g000001' ? 'ready' : 'building';
    }
}
