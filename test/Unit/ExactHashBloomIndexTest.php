<?php

declare(strict_types=1);

namespace HyperfTest\Unit;

use App\Service\FingerprintContext;
use App\Service\Redis\ExactHashBloomIndex;
use App\Service\Redis\RedisKeyFactory;
use Hyperf\Redis\Redis;
use PHPUnit\Framework\TestCase;

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

    public function __construct()
    {
    }

    public function rawCommand(string $command, mixed ...$args): mixed
    {
        $this->commands[] = [$command, ...$args];
        return true;
    }

    public function pipeline(?callable $callback = null)
    {
        $pipeline = new RecordingNativePipelineRedis();
        $callback?->__invoke($pipeline);
        array_push($this->commands, ...$pipeline->commands);
        return array_fill(0, count($pipeline->commands), 1);
    }
}

final class RecordingNativePipelineRedis extends \Redis
{
    /** @var list<list<mixed>> */
    public array $commands = [];

    public function rawCommand(string $command, mixed ...$args): mixed
    {
        $this->commands[] = [$command, ...$args];
        return $this;
    }
}
