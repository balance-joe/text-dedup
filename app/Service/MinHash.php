<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Blake2b;
use App\Support\UInt64;

/** 与 Python/NumPy 基准兼容的 64 位 MinHash 实现。 */
final class MinHash
{
    /** 兼容旧调用方的默认值；PHP 主链路实际读取 DedupeParameters。 */
    public const NGRAM = 5;
    public const NUM_PERM = 128;
    public const BANDS = 32;
    public const ROWS = 1;

    /** @return list<string> 以八字节大端序二进制字符串表示的 uint64 列表 */
    public static function signature(string $text, ?array $gramItems = null): array
    {
        $gramItems ??= Ngram::items($text, DedupeParameters::minhashNgram());
        $numPerm = DedupeParameters::minhashNumPerm();
        if (function_exists('dedupe_minhash_signature')) {
            // 原生扩展一次完成全部置换，避免 PHP 执行大量无符号 64 位乘加。
            return array_slice(dedupe_minhash_signature($gramItems), 0, $numPerm);
        }

        $grams = array_fill_keys($gramItems, true);
        if ($grams === []) {
            return array_fill(0, $numPerm, str_repeat("\xff", 8));
        }

        [$a0, $a1, $a2, $a3, $bHigh, $bLow] = self::permutationParams();
        $signatureHigh = array_fill(0, $numPerm, 0xffffffff);
        $signatureLow = array_fill(0, $numPerm, 0xffffffff);
        foreach ($grams as $gram => $_) {
            [$hashHigh, $hashLow] = self::parts(self::stableHash64($gram));
            $hash0 = $hashLow & 0xffff;
            $hash1 = $hashLow >> 16;
            $hash2 = $hashHigh & 0xffff;
            $hash3 = $hashHigh >> 16;

            for ($index = 0; $index < $numPerm; ++$index) {
                $multiplier0 = $a0[$index];
                $multiplier1 = $a1[$index];
                $multiplier2 = $a2[$index];
                $multiplier3 = $a3[$index];

                // 使用四个 16 位分段完成乘法，只保留模 2^64 后的低四段，避免 PHP 有符号整数溢出。
                $limb0 = $hash0 * $multiplier0;
                $limb1 = $hash0 * $multiplier1 + $hash1 * $multiplier0 + ($limb0 >> 16);
                $limb2 = $hash0 * $multiplier2 + $hash1 * $multiplier1 + $hash2 * $multiplier0 + ($limb1 >> 16);
                $limb3 = $hash0 * $multiplier3 + $hash1 * $multiplier2 + $hash2 * $multiplier1 + $hash3 * $multiplier0 + ($limb2 >> 16);
                $productLow = (($limb1 & 0xffff) << 16) | ($limb0 & 0xffff);
                $productHigh = (($limb3 & 0xffff) << 16) | ($limb2 & 0xffff);

                $sumLow = $productLow + $bLow[$index];
                $valueLow = $sumLow & 0xffffffff;
                $valueHigh = ($productHigh + $bHigh[$index] + ($sumLow > 0xffffffff ? 1 : 0)) & 0xffffffff;

                if ($valueHigh < $signatureHigh[$index]
                    || ($valueHigh === $signatureHigh[$index] && $valueLow < $signatureLow[$index])) {
                    $signatureHigh[$index] = $valueHigh;
                    $signatureLow[$index] = $valueLow;
                }
            }
        }

        $signature = [];
        for ($index = 0; $index < $numPerm; ++$index) {
            $signature[] = pack('N2', $signatureHigh[$index], $signatureLow[$index]);
        }
        return $signature;
    }

    /** @param list<string> $signature @return list<array{int, string}> */
    public static function bandItems(array $signature): array
    {
        $bandsCount = DedupeParameters::minhashBands();
        $expected = $bandsCount * DedupeParameters::minhashRows();
        if (count($signature) < $expected) {
            throw new \InvalidArgumentException("MinHash 签名长度不能少于 {$expected}。");
        }
        $values = array_slice($signature, 0, $bandsCount);
        // 十进制结果必须保持字符串类型，否则大于 PHP_INT_MAX 的 uint64 会溢出。
        $decimals = function_exists('dedupe_uint64_decimals')
            ? dedupe_uint64_decimals($values)
            : array_map(static fn (string $value): string => UInt64::toDecimal($value), $values);

        $bands = [];
        for ($index = 0; $index < $bandsCount; ++$index) {
            $bands[] = [$index, $decimals[$index]];
        }
        return $bands;
    }

    /** @param list<string> $signature @return list<string> */
    public static function decimalSignature(array $signature): array
    {
        if (function_exists('dedupe_uint64_decimals')) {
            // 批量跨越一次 PHP/C 边界，避免逐个值调用扩展或执行 PHP 长除法。
            return dedupe_uint64_decimals($signature);
        }

        return array_map(static fn (string $value): string => UInt64::toDecimal($value), $signature);
    }

    private static function stableHash64(string $value): string
    {
        return Blake2b::digest($value, 8);
    }

    /** @return array{list<int>, list<int>, list<int>, list<int>, list<int>, list<int>} */
    private static function permutationParams(): array
    {
        static $params = [];
        $numPerm = DedupeParameters::minhashNumPerm();
        if (isset($params[$numPerm])) {
            return $params[$numPerm];
        }
        $a0 = $a1 = $a2 = $a3 = $bHigh = $bLow = [];
        for ($index = 0; $index < $numPerm; ++$index) {
            [$high, $low] = self::parts(self::stableHash64("minhash-perm-{$index}"));
            $low |= 1;
            $a0[] = $low & 0xffff;
            $a1[] = $low >> 16;
            $a2[] = $high & 0xffff;
            $a3[] = $high >> 16;
            [$high, $low] = self::parts(self::stableHash64("minhash-offset-{$index}"));
            $bHigh[] = $high;
            $bLow[] = $low;
        }
        return $params[$numPerm] = [$a0, $a1, $a2, $a3, $bHigh, $bLow];
    }

    /** @return array{int, int} */
    private static function parts(string $value): array
    {
        $parts = unpack('Nhigh/Nlow', $value);
        return [(int) $parts['high'], (int) $parts['low']];
    }

}
