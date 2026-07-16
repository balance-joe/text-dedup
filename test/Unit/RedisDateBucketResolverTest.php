<?php

declare(strict_types=1);

namespace HyperfTest\Unit;

use App\Service\Redis\RedisDateBucketResolver;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class RedisDateBucketResolverTest extends TestCase
{
    public function testBuildsWriteAndQueryBucketsInConfiguredTimezone(): void
    {
        $resolver = new RedisDateBucketResolver('Asia/Shanghai', 3, 2);
        $time = new DateTimeImmutable('2026-07-16 00:30:00+08:00');

        self::assertSame('d20260716', $resolver->writeBucket($time));
        self::assertSame(['d20260716', 'd20260715', 'd20260714'], $resolver->queryBuckets($time));
    }

    public function testUsesFixedExpiryInsteadOfSlidingTtl(): void
    {
        $resolver = new RedisDateBucketResolver('Asia/Shanghai', 3, 2);
        $expected = new DateTimeImmutable('2026-07-22 00:00:00', new DateTimeZone('Asia/Shanghai'));

        self::assertSame($expected->getTimestamp(), $resolver->expireAt('d20260716'));
    }
}
