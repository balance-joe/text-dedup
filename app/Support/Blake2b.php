<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class Blake2b
{
    public static function digest(string $input, int $length): string
    {
        if (!in_array($length, [8, 16], true)) {
            throw new InvalidArgumentException('Only Python-compatible BLAKE2b digest lengths 8 and 16 are supported.');
        }

        return sodium_crypto_generichash($input, '', $length);
    }
}
