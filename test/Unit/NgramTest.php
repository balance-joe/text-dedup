<?php

declare(strict_types=1);

namespace HyperfTest\Unit;

use App\Service\Ngram;
use PHPUnit\Framework\TestCase;

/** 保护 UTF-8 字符切分、重叠窗口及短文本边界行为。 */
final class NgramTest extends TestCase
{
    public function testBuildsOverlappingUtf8NgramsWithoutRemovingDuplicates(): void
    {
        self::assertSame(['甲乙甲', '乙甲乙', '甲乙甲'], Ngram::items('甲乙甲乙甲', 3));
    }

    public function testReturnsWholeShortTextAndEmptyList(): void
    {
        self::assertSame(['中文'], Ngram::items('中文', 5));
        self::assertSame([], Ngram::items('', 5));
    }
}
