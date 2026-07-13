<?php

declare(strict_types=1);

namespace HyperfTest\Unit;

use App\Service\TextNormalizer;
use PHPUnit\Framework\TestCase;

final class TextNormalizerTest extends TestCase
{
    public function testNormalizesChineseDigitsEmojiAndBracketTokens(): void
    {
        $normalizer = new TextNormalizer();
        self::assertSame('测试0新闻', $normalizer->normalize('测试 123 新闻😀[微笑]'));
    }

    public function testFallsBackToWhitespaceStrippedOriginalWhenNoChineseRemains(): void
    {
        self::assertSame('hello', (new TextNormalizer())->normalize(' H e l l o '));
    }

    public function testBuildsPythonCompatibleRawHashText(): void
    {
        self::assertSame("title\x1f标题\x1econtent\x1f正文", (new TextNormalizer())->rawTextForHash(['title' => '标题', 'content' => '正文']));
    }
}
