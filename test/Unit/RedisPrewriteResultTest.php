<?php

declare(strict_types=1);

namespace HyperfTest\Unit;

use App\Service\Redis\RedisPrewriteResult;
use PHPUnit\Framework\TestCase;

final class RedisPrewriteResultTest extends TestCase
{
    public function testRepresentsSkippedSuccessAndDegradedWrites(): void
    {
        self::assertFalse(RedisPrewriteResult::skipped()->attempted);
        self::assertTrue(RedisPrewriteResult::success(['g000001'])->succeeded);

        $degraded = RedisPrewriteResult::degraded(['g000001'], 'redis unavailable');
        self::assertTrue($degraded->attempted);
        self::assertFalse($degraded->succeeded);
        self::assertSame('redis unavailable', $degraded->error);
    }
}
