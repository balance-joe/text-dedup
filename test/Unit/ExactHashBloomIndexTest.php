<?php

declare(strict_types=1);

namespace HyperfTest\Unit;

use App\Service\FingerprintContext;
use App\Service\Redis\ExactHashBloomIndex;
use App\Service\Redis\RedisKeyFactory;
use PHPUnit\Framework\TestCase;
use Redis;

final class ExactHashBloomIndexTest extends TestCase
{
    public function testTitleFallbackIsWrittenToContentHashBloom(): void
    {
        $source = ['title' => '标题', 'content' => ''];
        $content = FingerprintContext::fromSource($source);
        $title = FingerprintContext::fromSource($source, 'title');
        self::assertNull($content->contentHash);
        self::assertSame(md5('标题'), $content->exactHash);

        $redis = new RecordingBloomRedis();
        $index = new ExactHashBloomIndex(new RedisKeyFactory('dedupe:test'));
        $index->addDocument($redis, 'g000001', 'doc-1', $content, $title);

        self::assertContains(
            ['BF.ADD', 'dedupe:test:g000001:exact:content_hash', 'content:' . md5('标题')],
            $redis->commands,
        );
    }
}

final class RecordingBloomRedis extends Redis
{
    /** @var list<list<mixed>> */
    public array $commands = [];

    private int $pending = 0;

    public function multi(int $value = Redis::MULTI): Redis|bool
    {
        $this->pending = 0;
        return $this;
    }

    public function rawCommand(string $command, mixed ...$args): mixed
    {
        $this->commands[] = [$command, ...$args];
        if (strtoupper($command) === 'BF.ADD') {
            ++$this->pending;
        }
        return true;
    }

    public function exec(): Redis|array|false
    {
        return array_fill(0, $this->pending, 1);
    }
}
