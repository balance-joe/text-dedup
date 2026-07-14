<?php

declare(strict_types=1);

namespace HyperfTest\Unit;

use App\Service\SimHash;
use PHPUnit\Framework\TestCase;

/** 使用 Python 已知结果锁定 SimHash 位序和分桶顺序。 */
final class SimHashTest extends TestCase
{
    public function testMatchesKnownPythonFixtureValueAndBandOrder(): void
    {
        $value = SimHash::value('好上午九点去高铁站的找拼车吗');

        self::assertSame('f1f7559c3fbb75ef7dd4f5d632836c3f', SimHash::hex($value));
        self::assertSame([
            [0, '6c3f'], [1, '3283'], [2, 'f5d6'], [3, '7dd4'],
            [4, '75ef'], [5, '3fbb'], [6, '559c'], [7, 'f1f7'],
        ], SimHash::bandItems($value));
    }

    public function testEmptyTextProducesZeroValue(): void
    {
        self::assertSame(str_repeat('0', 32), SimHash::hex(SimHash::value('')));
    }
}
