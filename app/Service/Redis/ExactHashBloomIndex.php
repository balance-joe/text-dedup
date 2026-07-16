<?php

declare(strict_types=1);

namespace App\Service\Redis;

use App\Service\FingerprintContext;
use Redis;
use RuntimeException;
use Throwable;

use function Hyperf\Config\config;

final class ExactHashBloomIndex
{
    /** @var array<string, true> */
    private array $knownKeys = [];

    public function __construct(
        private readonly RedisKeyFactory $keys,
    ) {
    }

    public function addDocument(Redis $redis, string $generation, string $externalId, FingerprintContext $content, ?FingerprintContext $title): void
    {
        $this->addHashes(
            $redis,
            $generation,
            $externalId,
            $content->rawHash,
            // PostgreSQL content_hash 保存的是正文为空时可回退到标题的 exactHash。
            $content->exactHash,
            $title?->exactHash ?? $content->titleHash,
        );
    }

    public function addHashes(Redis $redis, string $generation, string $externalId, string $rawHash, ?string $contentHash, ?string $titleHash): void
    {
        $values = [
            $this->keys->exactExternalId($generation) => 'external:' . $externalId,
            $this->keys->exactHash($generation, 'raw_hash') => 'raw:' . $rawHash,
        ];
        if ($contentHash !== null) {
            $values[$this->keys->exactHash($generation, 'content_hash')] = 'content:' . $contentHash;
        }
        if ($titleHash !== null) {
            $values[$this->keys->exactHash($generation, 'title_hash')] = 'title:' . $titleHash;
        }
        foreach ($values as $key => $_) {
            $this->ensureFilter($redis, $key, $key === $this->keys->exactExternalId($generation));
        }
        $pipeline = $redis->multi(Redis::PIPELINE);
        foreach ($values as $key => $value) {
            $pipeline->rawCommand('BF.ADD', $key, $value);
        }
        $responses = $pipeline->exec();
        if (!is_array($responses) || count($responses) !== count($values) || in_array(false, $responses, true)) {
            throw new RuntimeException('Exact Hash Bloom pipeline failed.');
        }
    }

    public function mightContain(Redis $redis, string $generation, string $externalId, FingerprintContext $content, ?FingerprintContext $title): ?bool
    {
        try {
            $checks = [[$this->keys->exactExternalId($generation), 'external:' . $externalId]];
            $checks[] = [$this->keys->exactHash($generation, 'raw_hash'), 'raw:' . $content->rawHash];
            if ($content->exactHash !== null) {
                $checks[] = [$this->keys->exactHash($generation, 'content_hash'), 'content:' . $content->exactHash];
            }
            $titleHash = $title?->exactHash ?? $content->titleHash;
            if ($titleHash !== null) {
                $checks[] = [$this->keys->exactHash($generation, 'title_hash'), 'title:' . $titleHash];
            }
            $pipeline = $redis->multi(Redis::PIPELINE);
            foreach ($checks as [$key]) {
                $pipeline->exists($key);
            }
            $exists = $pipeline->exec();
            if (!is_array($exists) || count($exists) !== count($checks) || in_array(0, $exists, true) || in_array(false, $exists, true)) {
                return null;
            }
            $pipeline = $redis->multi(Redis::PIPELINE);
            foreach ($checks as [$key, $value]) {
                $pipeline->rawCommand('BF.EXISTS', $key, $value);
            }
            $matches = $pipeline->exec();
            if (!is_array($matches) || count($matches) !== count($checks)) {
                return null;
            }
            return in_array(1, $matches, true) || in_array(true, $matches, true);
        } catch (Throwable) {
            return null;
        }
    }

    public function reserveGeneration(Redis $redis, string $generation): void
    {
        $this->ensureFilter($redis, $this->keys->exactExternalId($generation), true);
        foreach (['raw_hash', 'content_hash', 'title_hash'] as $field) {
            $key = $this->keys->exactHash($generation, $field);
            $this->ensureFilter($redis, $key, false);
        }
    }

    /** @param list<string> $externalIds */
    public function addExternalIds(Redis $redis, string $generation, array $externalIds): void
    {
        $key = $this->keys->exactExternalId($generation);
        $this->ensureFilter($redis, $key, true);
        $this->madd($redis, $key, array_map(static fn (string $id): string => 'external:' . $id, $externalIds));
    }

    /** @param list<string> $hashes */
    public function addHashValues(Redis $redis, string $generation, string $field, array $hashes): void
    {
        $key = $this->keys->exactHash($generation, $field);
        $this->ensureFilter($redis, $key, false);
        $prefix = match ($field) {
            'raw_hash' => 'raw:',
            'content_hash' => 'content:',
            'title_hash' => 'title:',
            default => throw new \InvalidArgumentException("Unsupported exact hash field: {$field}"),
        };
        $this->madd($redis, $key, array_map(static fn (string $hash): string => $prefix . $hash, $hashes));
    }

    /** @param list<string> $values */
    private function madd(Redis $redis, string $key, array $values): void
    {
        $values = array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== '')));
        foreach (array_chunk($values, 1000) as $chunk) {
            $response = $redis->rawCommand('BF.MADD', $key, ...$chunk);
            if (!is_array($response) || count($response) !== count($chunk)) {
                throw new RuntimeException("Bloom batch write failed for {$key}.");
            }
        }
    }

    private function ensureFilter(Redis $redis, string $key, bool $external): void
    {
        if (isset($this->knownKeys[$key])) {
            return;
        }
        $capacity = (int) config($external
            ? 'dedupe.redis_index.exact.external_id_capacity'
            : 'dedupe.redis_index.exact.capacity', 10000000);
        try {
            $redis->rawCommand(
                'BF.RESERVE',
                $key,
                (string) config('dedupe.redis_index.exact.error_rate', 0.0001),
                (string) max(1, $capacity),
                'EXPANSION',
                (string) max(1, (int) config('dedupe.redis_index.expansion', 2)),
            );
        } catch (Throwable $exception) {
            if (!str_contains(strtolower($exception->getMessage()), 'exist')) {
                throw $exception;
            }
        }
        $this->knownKeys[$key] = true;
    }
}
