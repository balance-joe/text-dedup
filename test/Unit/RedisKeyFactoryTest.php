<?php

declare(strict_types=1);

namespace HyperfTest\Unit;

use App\Service\Redis\RedisKeyFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RedisKeyFactoryTest extends TestCase
{
    public function testBuildsStableGenerationAndDateKeys(): void
    {
        $keys = new RedisKeyFactory('dedupe:test');

        self::assertSame('dedupe:test:meta:active_generation', $keys->activeGeneration());
        self::assertSame('dedupe:test:g000001:exact:content_hash', $keys->exactHash('g000001', 'content_hash'));
        self::assertSame('dedupe:test:g000001:minhash:title:d20260716:b31', $keys->minhash('g000001', 'title', 'd20260716', 31));
        self::assertSame('dedupe:test:g000001:minhash:content:*', $keys->minhashPattern('g000001', 'content'));
    }

    public function testRejectsUnsafeGenerationNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RedisKeyFactory('dedupe'))->generationMeta('../bad');
    }
}
