<?php

declare(strict_types=1);

namespace App\Service\Redis;

use InvalidArgumentException;

use function Hyperf\Config\config;

final class RedisKeyFactory
{
    public function __construct(private readonly ?string $configuredPrefix = null)
    {
    }

    public function activeGeneration(): string
    {
        return $this->prefix() . ':meta:active_generation';
    }

    public function buildingGeneration(): string
    {
        return $this->prefix() . ':meta:building_generation';
    }

    public function generationMeta(string $generation): string
    {
        return $this->prefix() . ':meta:' . $this->generation($generation);
    }

    public function exactExternalId(string $generation): string
    {
        return sprintf('%s:%s:exact:external_id', $this->prefix(), $this->generation($generation));
    }

    public function exactHash(string $generation, string $field): string
    {
        if (!in_array($field, ['raw_hash', 'content_hash', 'title_hash'], true)) {
            throw new InvalidArgumentException("Unsupported exact hash field: {$field}");
        }
        return sprintf('%s:%s:exact:%s', $this->prefix(), $this->generation($generation), $field);
    }

    public function minhash(string $generation, string $scope, string $bucket, int $bandIndex): string
    {
        if (!in_array($scope, ['content', 'title'], true)) {
            throw new InvalidArgumentException("Unsupported fingerprint scope: {$scope}");
        }
        if ($bandIndex < 0 || $bandIndex >= 32) {
            throw new InvalidArgumentException("Invalid MinHash band index: {$bandIndex}");
        }
        return sprintf('%s:%s:minhash:%s:%s:b%d', $this->prefix(), $this->generation($generation), $scope, $this->bucket($bucket), $bandIndex);
    }

    public function checkpoint(string $generation, string $name): string
    {
        if (preg_match('/\A[a-z0-9:_-]+\z/i', $name) !== 1) {
            throw new InvalidArgumentException("Invalid checkpoint name: {$name}");
        }
        return sprintf('%s:%s:checkpoint:%s', $this->prefix(), $this->generation($generation), $name);
    }

    public function generationPrefix(string $generation): string
    {
        return sprintf('%s:%s:', $this->prefix(), $this->generation($generation));
    }

    public function assertGeneration(string $generation): string
    {
        return $this->generation($generation);
    }

    private function prefix(): string
    {
        $prefix = trim($this->configuredPrefix ?? (string) config('dedupe.redis_index.prefix', 'dedupe'), ': ');
        if ($prefix === '' || preg_match('/\A[a-z0-9:_-]+\z/i', $prefix) !== 1) {
            throw new InvalidArgumentException('Invalid Redis dedupe key prefix.');
        }
        return $prefix;
    }

    private function generation(string $generation): string
    {
        if (preg_match('/\Ag[0-9]{6,20}\z/', $generation) !== 1) {
            throw new InvalidArgumentException("Invalid Redis index generation: {$generation}");
        }
        return $generation;
    }

    private function bucket(string $bucket): string
    {
        if (preg_match('/\Ad[0-9]{8}\z/', $bucket) !== 1) {
            throw new InvalidArgumentException("Invalid Redis date bucket: {$bucket}");
        }
        return $bucket;
    }
}
