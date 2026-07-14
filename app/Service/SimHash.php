<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Blake2b;
use InvalidArgumentException;

/** 与 Python 基准兼容、且不依赖框架的 128 位 SimHash 实现。 */
final class SimHash
{
    public const BITS = 128;
    public const BANDS = 8;
    public const NGRAM = 5;

    /** 返回 16 字节大端序二进制字符串表示的无符号 128 位 SimHash。 */
    public static function value(string $text, int $ngram = self::NGRAM, ?array $grams = null): string
    {
        if ($ngram < 1) {
            throw new InvalidArgumentException('N-gram 长度必须大于零。');
        }

        $grams ??= Ngram::items($text, $ngram);
        if ($grams === []) {
            return str_repeat("\0", self::BITS / 8);
        }
        if ($ngram === self::NGRAM && function_exists('dedupe_simhash')) {
            // 生产环境优先把大量逐位累计计算交给原生扩展。
            return dedupe_simhash($grams);
        }

        $weights = array_fill(0, self::BITS, 0);
        foreach ($grams as $gram) {
            $digest = Blake2b::digest($gram, self::BITS / 8);
            for ($bit = 0; $bit < self::BITS; ++$bit) {
                // Python 将摘要解释成大端序整数，因此最低位是最后一个字节的第 0 位。
                $byteIndex = (self::BITS / 8 - 1) - intdiv($bit, 8);
                $weights[$bit] += (ord($digest[$byteIndex]) & (1 << ($bit % 8))) !== 0 ? 1 : -1;
            }
        }

        $result = str_repeat("\0", self::BITS / 8);
        foreach ($weights as $bit => $weight) {
            // 与 Python 保持一致：权重相等时该位取 1。
            if ($weight >= 0) {
                $byteIndex = (self::BITS / 8 - 1) - intdiv($bit, 8);
                $result[$byteIndex] = chr(ord($result[$byteIndex]) | (1 << ($bit % 8)));
            }
        }

        return $result;
    }

    public static function hex(string $value): string
    {
        self::assertValue($value);
        return bin2hex($value);
    }

    /** @return list<array{int, string}> */
    public static function bandItems(string $value): array
    {
        self::assertValue($value);
        $items = [];
        for ($band = 0; $band < self::BANDS; ++$band) {
            // 与 Python 保持一致：第 0 个分桶读取最低 16 位。
            $items[] = [$band, bin2hex(substr($value, 14 - $band * 2, 2))];
        }
        return $items;
    }

    private static function assertValue(string $value): void
    {
        if (strlen($value) !== self::BITS / 8) {
            throw new InvalidArgumentException('128 位 SimHash 必须正好包含 16 个字节。');
        }
    }
}
