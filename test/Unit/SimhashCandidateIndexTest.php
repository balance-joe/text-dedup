<?php

declare(strict_types=1);

namespace HyperfTest\Unit;

use App\Service\Redis\RedisDateBucketResolver;
use App\Service\Redis\RedisKeyFactory;
use App\Service\Redis\SimhashCandidateIndex;
use DateTimeImmutable;
use Hyperf\Redis\Redis;
use PHPUnit\Framework\TestCase;

final class SimhashCandidateIndexTest extends TestCase
{
    public function testWritesAllBandsToValueSpecificZsetsInOneCall(): void
    {
        $redis = new RecordingSimhashCandidateRedis();
        $index = new SimhashCandidateIndex(
            new RedisKeyFactory('dedupe:test'),
            new RedisDateBucketResolver(),
        );

        $index->addDocument(
            $redis,
            'g000001',
            'd20260717',
            'content',
            [[0, '00ff'], [1, 'abcd']],
            'doc-1',
            new DateTimeImmutable('2026-07-17 12:00:00+08:00'),
        );

        self::assertSame(2, $redis->lastNumKeys);
        self::assertSame([
            'dedupe:test:g000001:simhash:content:d20260717:b0:v255',
            'dedupe:test:g000001:simhash:content:d20260717:b1:v43981',
        ], array_slice($redis->lastArgs, 0, 2));
        self::assertSame('doc-1', $redis->lastArgs[3]);
    }
}

final class RecordingSimhashCandidateRedis extends Redis
{
    public int $lastNumKeys = 0;

    /** @var list<mixed> */
    public array $lastArgs = [];

    public function __construct()
    {
    }

    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        $this->lastArgs = $args;
        $this->lastNumKeys = $num_keys;
        return array_fill(0, $num_keys, 1);
    }
}
