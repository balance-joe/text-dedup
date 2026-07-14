<?php

declare(strict_types=1);

use App\Service\FingerprintContext;
use App\Support\ProcessMemory;

// Load only the framework-independent fingerprint implementation.  This keeps
// the benchmark representative of the production calculation while allowing it
// to run without booting a Hyperf container in every worker.
require dirname(__DIR__) . '/app/Service/TextNormalizer.php';
require dirname(__DIR__) . '/app/Support/Blake2b.php';
require dirname(__DIR__) . '/app/Support/UInt64.php';
require dirname(__DIR__) . '/app/Support/ProcessMemory.php';
require dirname(__DIR__) . '/app/Service/Ngram.php';
require dirname(__DIR__) . '/app/Service/SimHash.php';
require dirname(__DIR__) . '/app/Service/MinHash.php';
require dirname(__DIR__) . '/app/Service/FingerprintContext.php';

/** @return array{baselinePath: string, workers: int}|null */
function swooleVerifierArguments(array $argv): ?array
{
    array_shift($argv);
    $workers = 5;
    $baselinePath = null;

    while ($argv !== []) {
        $argument = array_shift($argv);
        if ($argument === '--workers' || $argument === '-workers') {
            $value = array_shift($argv);
            if ($value === null || filter_var($value, FILTER_VALIDATE_INT) === false) {
                return null;
            }
            $workers = (int) $value;
            continue;
        }
        if (str_starts_with($argument, '--workers=')) {
            $value = substr($argument, strlen('--workers='));
            if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                return null;
            }
            $workers = (int) $value;
            continue;
        }
        if ($baselinePath !== null) {
            return null;
        }
        $baselinePath = $argument;
    }

    if ($baselinePath === null || $workers < 1) {
        return null;
    }

    return ['baselinePath' => $baselinePath, 'workers' => $workers];
}

/** @return array<string, mixed> */
function swooleVerifierActual(FingerprintContext $context, string $scope): array
{
    return [
        'normalized_title' => $context->normalizedTitle,
        'normalized_content' => $context->normalizedContent,
        'raw_hash_hex' => $context->rawHash,
        'content_hash_hex' => $context->contentHash,
        'title_hash_hex' => $context->titleHash,
        'primary_text' => $scope === 'content' ? $context->text : null,
        'text' => $context->text,
        'text_len' => $context->textLength,
        'low_information' => $context->lowInformation,
        'exact_hash' => $context->exactHash,
        'simhash_hex' => $context->simhashHex,
        'simhash_hi_pg_bigint' => $context->simhashHiPgBigint,
        'simhash_lo_pg_bigint' => $context->simhashLoPgBigint,
        'simhash_bands' => $context->simhashBands,
        'minhash_signature_uint64' => $context->minhashSignature,
        'minhash_bands_uint64' => $context->minhashBands,
    ];
}

/** @return array<string, mixed> */
function swooleVerifierExpected(array $expected, string $scope): array
{
    $expectedScope = $expected["{$scope}_scope"] ?? [];
    return [
        'normalized_title' => $expected['normalized_title'] ?? null,
        'normalized_content' => $expected['normalized_content'] ?? null,
        'raw_hash_hex' => $expected['raw_hash_hex'] ?? null,
        'content_hash_hex' => $expected['content_hash_hex'] ?? null,
        'title_hash_hex' => $expected['title_hash_hex'] ?? null,
        'primary_text' => $scope === 'content' ? ($expected['primary_text'] ?? null) : null,
        'text' => $expectedScope['text'] ?? null,
        'text_len' => $expectedScope['text_len'] ?? null,
        'low_information' => $expectedScope['low_information'] ?? null,
        'exact_hash' => $expectedScope['exact_hash'] ?? null,
        'simhash_hex' => $expectedScope['simhash_hex'] ?? null,
        'simhash_hi_pg_bigint' => $expectedScope['simhash_hi_pg_bigint'] ?? null,
        'simhash_lo_pg_bigint' => $expectedScope['simhash_lo_pg_bigint'] ?? null,
        'simhash_bands' => $expectedScope['simhash_bands'] ?? null,
        'minhash_signature_uint64' => array_map('strval', $expectedScope['minhash_signature_uint64'] ?? []),
        'minhash_bands_uint64' => array_map(static fn (array $band): array => [(int) $band[0], (string) $band[1]], $expectedScope['minhash_bands_uint64'] ?? []),
    ];
}

/**
 * @param list<array<string, mixed>> $records
 * @return array{records: int, scopes: int, failure_count: int, failures: list<array<string, mixed>>}
 */
function swooleVerifierRunPartition(array $records, int $workerId, int $workerCount): array
{
    $processed = 0;
    $scopes = 0;
    $failureCount = 0;
    $failures = [];

    foreach ($records as $index => $record) {
        if ($index % $workerCount !== $workerId) {
            continue;
        }
        $source = $record['canonical_input'] ?? [];
        $expected = $record['expected_from_canonical_input'] ?? [];
        $contexts = ['content' => FingerprintContext::fromSource($source)];
        if (($expected['title_scope'] ?? null) !== null) {
            $contexts['title'] = FingerprintContext::fromSource($source, 'title');
        }

        foreach ($contexts as $scope => $context) {
            ++$scopes;
            $actual = swooleVerifierActual($context, $scope);
            foreach (swooleVerifierExpected($expected, $scope) as $field => $expectedValue) {
                if ($actual[$field] === $expectedValue) {
                    continue;
                }
                ++$failureCount;
                // Keep IPC messages bounded. The single-process verifier remains
                // available when full expected/actual values are needed.
                if (count($failures) < 5) {
                    $failures[] = ['doc_pk' => $record['doc_pk'] ?? null, 'scope' => $scope, 'field' => $field];
                }
                break;
            }
        }
        ++$processed;
    }

    return ['records' => $processed, 'scopes' => $scopes, 'failure_count' => $failureCount, 'failures' => $failures];
}

$arguments = swooleVerifierArguments($_SERVER['argv'] ?? $GLOBALS['argv'] ?? []);
if ($arguments === null) {
    fwrite(STDERR, "Usage: php bin/verify-php-compat-swoole.php <php-compat-baseline.json> [--workers N]\n");
    exit(2);
}
if (!class_exists('Swoole\\Process')) {
    fwrite(STDERR, "This verifier requires the Swoole extension (Swoole\\Process).\n");
    exit(2);
}
if (!class_exists(Normalizer::class) || !function_exists('mb_strlen') || !function_exists('sodium_crypto_generichash')) {
    fwrite(STDERR, "This verifier requires PHP extensions intl (Normalizer), mbstring, and sodium (BLAKE2b).\n");
    exit(2);
}
if (!is_file($arguments['baselinePath'])) {
    fwrite(STDERR, "Baseline file not found: {$arguments['baselinePath']}\n");
    exit(2);
}

try {
    $baseline = json_decode((string) file_get_contents($arguments['baselinePath']), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
} catch (JsonException $exception) {
    fwrite(STDERR, "Invalid baseline JSON: {$exception->getMessage()}\n");
    exit(2);
}
if (($baseline['format'] ?? null) !== 'dedupe-php-compat-baseline/v1') {
    fwrite(STDERR, "Unsupported compatibility baseline format.\n");
    exit(2);
}

$records = $baseline['records'] ?? [];
$workers = min($arguments['workers'], max(1, count($records)));
$startedAt = microtime(true);
$processes = [];

for ($workerId = 0; $workerId < $workers; ++$workerId) {
    $processes[] = new Swoole\Process(static function (Swoole\Process $process) use ($records, $workerId, $workers): void {
        try {
            $result = swooleVerifierRunPartition($records, $workerId, $workers);
        } catch (Throwable $exception) {
            $result = ['records' => 0, 'scopes' => 0, 'failure_count' => 1, 'failures' => [['worker' => $workerId, 'error' => $exception->getMessage()]]];
        }
        $process->write(json_encode($result, JSON_THROW_ON_ERROR));
    });
}

foreach ($processes as $process) {
    $process->start();
}

$processed = 0;
$scopeCount = 0;
$failureCount = 0;
$failures = [];
foreach ($processes as $workerId => $process) {
    $message = $process->read();
    if ($message === false || $message === '') {
        ++$failureCount;
        $failures[] = ['worker' => $workerId, 'error' => 'worker exited without a result'];
        continue;
    }
    try {
        $result = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        ++$failureCount;
        $failures[] = ['worker' => $workerId, 'error' => 'invalid worker result: ' . $exception->getMessage()];
        continue;
    }
    $processed += $result['records'];
    $scopeCount += $result['scopes'];
    $failureCount += $result['failure_count'];
    foreach ($result['failures'] as $failure) {
        if (count($failures) < 5) {
            $failures[] = $failure;
        }
    }
}
foreach ($processes as $_) {
    Swoole\Process::wait(true);
}

$elapsedSeconds = microtime(true) - $startedAt;
$summary = [
    'language' => 'php-swoole-process',
    'contract' => 'full-fingerprint',
    'phase' => 'fingerprint-simhash-minhash',
    'records' => $processed,
    'scopes' => $scopeCount,
    'workers' => $workers,
    'elapsed_seconds' => round($elapsedSeconds, 6),
    'records_per_second' => round($processed / max($elapsedSeconds, 1e-9), 2),
    'memory' => ProcessMemory::snapshot(),
    'failure_count' => $failureCount,
    'failures' => $failures,
    'failures_omitted' => max(0, $failureCount - count($failures)),
    'status' => $failureCount === 0 ? 'passed' : 'failed',
];
fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
exit($failureCount === 0 ? 0 : 1);
