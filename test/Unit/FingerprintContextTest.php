<?php

declare(strict_types=1);

namespace HyperfTest\Unit;

use App\Service\FingerprintContext;
use PHPUnit\Framework\TestCase;

/** 验证标题/正文作用域及低信息判定与 Python 生产规则一致。 */
final class FingerprintContextTest extends TestCase
{
    public function testBuildsContentScopeWithContentPreferredOverTitle(): void
    {
        $context = FingerprintContext::fromSource(['title' => '标题 123', 'content' => '正文 456']);

        self::assertSame('标题', $context->normalizedTitle);
        self::assertSame('正文', $context->normalizedContent);
        self::assertSame('正文', $context->text);
        self::assertSame(md5('正文'), $context->contentHash);
        self::assertSame(md5('标题'), $context->titleHash);
        self::assertSame($context->contentHash, $context->exactHash);
    }

    public function testContentScopeFallsBackToTitleAndTitleScopeNeverDoes(): void
    {
        $source = ['title' => '标题', 'content' => ''];

        self::assertSame('标题', FingerprintContext::fromSource($source)->text);
        self::assertSame('标题', FingerprintContext::fromSource($source, 'title')->text);
        self::assertNull(FingerprintContext::fromSource(['title' => '', 'content' => '正文'], 'title')->exactHash);
    }

    public function testLowInformationMatchesPythonRules(): void
    {
        self::assertTrue(FingerprintContext::isLowInformation('短文本'));
        self::assertFalse(FingerprintContext::isLowInformation('这是一段长度足够并且包含很多汉字用于验证低信息文本判定规则是否一致的测试内容'));
    }
}
