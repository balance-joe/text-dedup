<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class UInt64
{
    // PHP 整数是有符号类型，因此统一用“高 32 位 + 低 32 位”的八字节大端序保存 uint64。
    private const BASE = 4294967296;
    private const MAX32 = 4294967295;

    /** 把 PHP 有符号 64 位整数转换为八字节补码表示。 */
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

    /** 把八字节补码还原为 PostgreSQL bigint 可保存的 PHP 有符号整数。 */
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

    /** 比较两个八字节大端序 uint64；二进制字典序等同于无符号数值顺序。 */
    public static function compare(string $left, string $right): int
    {
        self::parts($left);
        self::parts($right);
        return $left <=> $right;
    }

    /** 两个 uint64 相加，并只保留模 2^64 后的结果。 */
    public static function add(string $left, string $right): string
    {
        [$leftHigh, $leftLow] = self::parts($left);
        [$rightHigh, $rightLow] = self::parts($right);
        $low = $leftLow + $rightLow;
        $carry = $low > self::MAX32 ? 1 : 0;
        return pack('N2', ($leftHigh + $rightHigh + $carry) & self::MAX32, $low & self::MAX32);
    }

    /** 使用 16 位分段相乘，避免 PHP 有符号整数溢出，并只保留模 2^64 的结果。 */
    public static function multiply(string $left, string $right): string
    {
        [$leftHigh, $leftLow] = self::parts($left);
        [$rightHigh, $rightLow] = self::parts($right);
        $a = [$leftLow & 0xffff, $leftLow >> 16, $leftHigh & 0xffff, $leftHigh >> 16];
        $b = [$rightLow & 0xffff, $rightLow >> 16, $rightHigh & 0xffff, $rightHigh >> 16];
        $result = [0, 0, 0, 0];

        for ($i = 0; $i < 4; ++$i) {
            for ($j = 0; $j + $i < 4; ++$j) {
                $result[$i + $j] += $a[$i] * $b[$j];
            }
        }
        for ($index = 0; $index < 3; ++$index) {
            $result[$index + 1] += intdiv($result[$index], 0x10000);
            $result[$index] &= 0xffff;
        }
        $result[3] &= 0xffff;

        return pack('N2', ($result[3] << 16) | $result[2], ($result[1] << 16) | $result[0]);
    }

    /** 强制把最低位设为 1，供 MinHash 生成奇数乘数。 */
    public static function withLowBitSet(string $value): string
    {
        [$high, $low] = self::parts($value);
        return pack('N2', $high, $low | 1);
    }

    /** 对两个长度合法的 uint64 二进制值执行按位异或。 */
    public static function xor(string $left, string $right): string
    {
        self::parts($left);
        self::parts($right);
        return $left ^ $right;
    }

    /** 对八字节 uint64 执行循环右移。 */
    public static function rotateRight(string $value, int $bits): string
    {
        self::parts($value);
        $bits %= 64;
        if ($bits === 0) {
            return $value;
        }

        $bytes = intdiv($bits, 8);
        $shift = $bits % 8;
        $out = '';
        for ($i = 0; $i < 8; ++$i) {
            $at = ($i - $bytes + 8) % 8;
            $previous = ($at - 1 + 8) % 8;
            $out .= chr($shift === 0
                ? ord($value[$at])
                : (ord($value[$at]) >> $shift) | ((ord($value[$previous]) << (8 - $shift)) & 255));
        }
        return $out;
    }

    /** 转成适合 JSON 和数据库保存的十进制字符串，避免大整数被转为浮点数。 */
    public static function toDecimal(string $value): string
    {
        [$high, $low] = self::parts($value);
        $words = [$high >> 16, $high & 0xffff, $low >> 16, $low & 0xffff];
        $digits = '';
        do {
            $remainder = 0;
            foreach ($words as $index => $word) {
                $current = $remainder * 65536 + $word;
                $words[$index] = intdiv($current, 10);
                $remainder = $current % 10;
            }
            $digits = (string) $remainder . $digits;
        } while (array_filter($words, static fn (int $word): bool => $word !== 0) !== []);
        return $digits;
    }

    private static function parts(string $value): array
    {
        if (strlen($value) !== 8) {
            throw new InvalidArgumentException('uint64 二进制值必须正好包含 8 个字节。');
        }
        $parts = unpack('Nhigh/Nlow', $value);
        return [(int) $parts['high'], (int) $parts['low']];
    }
}
