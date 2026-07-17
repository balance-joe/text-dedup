<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;
use Throwable;

use function Hyperf\Config\config;

/** PHP 去重链路的集中参数入口；无框架CLI环境自动使用兼容默认值。 */
final class DedupeParameters
{
    public static function simhashBits(): int
    {
        $value = self::integer('dedupe.simhash.bits', 128);
        if ($value !== 128) {
            throw new InvalidArgumentException('PHP/PG 当前只支持 DEDUPE_BITS=128；修改位数需要迁移指纹字段与历史索引。');
        }
        return $value;
    }

    public static function simhashBands(): int
    {
        $value = self::integer('dedupe.simhash.bands', 8);
        if ($value !== 8) {
            throw new InvalidArgumentException('现有 simhash_band 物理分区只支持 DEDUPE_BANDS=8；修改后必须迁移表结构并重建索引。');
        }
        return $value;
    }

    public static function simhashNgram(): int
    {
        return max(1, self::integer('dedupe.simhash.ngram', 5));
    }

    public static function minhashNgram(): int
    {
        return max(1, self::integer('dedupe.minhash.ngram', 5));
    }

    public static function minhashBands(): int
    {
        $value = self::integer('dedupe.minhash.bands', 32);
        if ($value !== 32) {
            throw new InvalidArgumentException('现有 minhash_band 物理分区和Redis generation只支持 DEDUPE_MINHASH_BANDS=32。');
        }
        return $value;
    }

    public static function minhashRows(): int
    {
        $value = self::integer('dedupe.minhash.rows', 1);
        if ($value !== 1) {
            throw new InvalidArgumentException('当前band编码只支持 DEDUPE_MINHASH_ROWS=1；修改需要新算法版本和全量重建。');
        }
        return $value;
    }

    public static function minhashNumPerm(): int
    {
        $minimum = self::minhashBands() * self::minhashRows();
        $value = self::integer('dedupe.minhash.num_perm', 128);
        if ($value < $minimum || $value > 128) {
            throw new InvalidArgumentException("DEDUPE_MINHASH_NUM_PERM 必须在 {$minimum} 到 128 之间。");
        }
        return $value;
    }

    public static function sampleTextLength(): int
    {
        return max(0, self::integer('dedupe.sample_text_length', 160));
    }

    public static function algorithmVersion(): string
    {
        $value = trim((string) self::value('dedupe.algorithm_version', 'v1'));
        if ($value === '' || preg_match('/\A[a-z0-9._-]{1,40}\z/i', $value) !== 1) {
            throw new InvalidArgumentException('DEDUPE_ALGORITHM_VERSION 格式不正确。');
        }
        return $value;
    }

    public static function bracketTokenMaxLength(): int
    {
        return max(1, self::integer('dedupe.low_information.bracket_token_max_length', 20));
    }

    public static function lowInformationMinLength(): int
    {
        return max(0, self::integer('dedupe.low_information.min_length', 30));
    }

    public static function lowInformationMinAlphanumeric(): int
    {
        return max(0, self::integer('dedupe.low_information.min_alphanumeric', 10));
    }

    public static function lowInformationTokenRatio(): float
    {
        return min(1.0, max(0.0, self::floating('dedupe.low_information.token_ratio', 0.65)));
    }

    public static function bloomBatchSize(): int
    {
        return max(1, self::integer('dedupe.redis_index.write_batch_size', 1000));
    }

    private static function integer(string $key, int $default): int
    {
        return (int) self::value($key, $default);
    }

    private static function floating(string $key, float $default): float
    {
        return (float) self::value($key, $default);
    }

    private static function value(string $key, mixed $default): mixed
    {
        try {
            return function_exists('Hyperf\\Config\\config') ? config($key, $default) : $default;
        } catch (Throwable) {
            return $default;
        }
    }
}
