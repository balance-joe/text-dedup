<?php

declare(strict_types=1);

namespace HyperfTest\Unit;

use App\Support\UInt64;
use PHPUnit\Framework\TestCase;

final class UInt64Test extends TestCase
{
    public function testRoundTripsSignedPostgresBigints(): void
    {
        foreach ([0, 1, -1, PHP_INT_MAX, PHP_INT_MIN] as $value) {
            self::assertSame($value, UInt64::toSignedInt64(UInt64::fromSignedInt64($value)));
        }
    }

    public function testComparesBigEndianUnsignedValues(): void
    {
        self::assertGreaterThan(0, UInt64::compare(UInt64::fromSignedInt64(-1), UInt64::fromSignedInt64(1)));
    }
}
