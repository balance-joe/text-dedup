<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

final class Blake2b
{
    /**
     * 生成与 Python hashlib.blake2b 完全一致的二进制摘要。
     *
     * 8 字节摘要不能由 16 字节摘要截断得到，因此必须调用项目原生扩展；
     * 16 字节摘要可直接使用 Sodium，主要供 128 位 SimHash 使用。
     */
    public static function digest(string $input, int $length): string
    {
        if (!in_array($length, [8, 16], true)) {
            throw new InvalidArgumentException('仅支持与 Python 兼容的 8 字节或 16 字节 BLAKE2b 摘要。');
        }

        if ($length === 8) {
            if (!function_exists('dedupe_blake2b')) {
                throw new RuntimeException('计算 BLAKE2b-8 必须安装并加载 dedupe_blake2b PHP 扩展。');
            }
            return dedupe_blake2b($input, $length);
        }

        return sodium_crypto_generichash($input, '', $length);
    }
}
