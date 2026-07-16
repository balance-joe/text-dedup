<?php

declare(strict_types=1);

namespace App\Service\Redis;

final class RedisPrewriteResult
{
    /** @param list<string> $generations */
    public function __construct(
        public bool $attempted,
        public bool $succeeded,
        public array $generations = [],
        public ?string $error = null,
    ) {
    }

    public static function skipped(): self
    {
        return new self(false, true);
    }

    /** @param list<string> $generations */
    public static function success(array $generations): self
    {
        return new self(true, true, $generations);
    }

    /** @param list<string> $generations */
    public static function degraded(array $generations, string $error): self
    {
        return new self(true, false, $generations, $error);
    }
}
