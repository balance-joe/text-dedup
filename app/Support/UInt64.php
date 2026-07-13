<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class UInt64
{
    private const BASE = 4294967296;
    private const MAX32 = 4294967295;

    public static function fromSignedInt64(int $value): string
    {
        if ($value >= 0) {
            return pack('N2', intdiv($value, self::BASE), $value % self::BASE);
        }
        $magnitude = -$value;
        $remainder = $magnitude % self::BASE;
        $low = $remainder === 0 ? 0 : self::BASE - $remainder;
        $high = self::MAX32 - intdiv($magnitude, self::BASE) + ($remainder === 0 ? 1 : 0);
        return pack('N2', $high, $low);
    }

    public static function toSignedInt64(string $value): int
    {
        [$high, $low] = self::parts($value);
        if ($high < 0x80000000) {
            return $high * self::BASE + $low;
        }
        if ($high === 0x80000000 && $low === 0) {
            return PHP_INT_MIN;
        }
        return -(self::MAX32 - $high) * self::BASE - (self::MAX32 - $low) - 1;
    }

    public static function compare(string $left, string $right): int
    {
        self::parts($left);
        self::parts($right);
        return $left <=> $right;
    }

    private static function parts(string $value): array
    {
        if (strlen($value) !== 8) {
            throw new InvalidArgumentException('A uint64 value must contain exactly eight bytes.');
        }
        $parts = unpack('Nhigh/Nlow', $value);
        return [(int) $parts['high'], (int) $parts['low']];
    }
}
