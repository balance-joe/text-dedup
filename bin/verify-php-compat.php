<?php

declare(strict_types=1);

use App\Service\FingerprintContext;
use App\Support\ProcessMemory;

// Keep this verifier usable on a development machine without Hyperf/Swoole
// (or a Composer platform compatible with the production dependency set).
require dirname(__DIR__) . '/app/Service/TextNormalizer.php';
require dirname(__DIR__) . '/app/Support/Blake2b.php';
require dirname(__DIR__) . '/app/Support/UInt64.php';
require dirname(__DIR__) . '/app/Support/ProcessMemory.php';
require dirname(__DIR__) . '/app/Service/Ngram.php';
require dirname(__DIR__) . '/app/Service/SimHash.php';
require dirname(__DIR__) . '/app/Service/MinHash.php';
require dirname(__DIR__) . '/app/Service/FingerprintContext.php';

$argv = $_SERVER['argv'] ?? $GLOBALS['argv'] ?? [];
$argc = count($argv);

if (!class_exists(Normalizer::class) || !function_exists('mb_strlen') || !function_exists('sodium_crypto_generichash')) {
    fwrite(STDERR, "This verifier requires PHP extensions intl (Normalizer), mbstring, and sodium (BLAKE2b).\n");
    exit(2);
}

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php bin/verify-php-compat.php <php-compat-baseline.json>\n");
    exit(2);
}

$baselinePath = $argv[1];
if (!is_file($baselinePath)) {
    fwrite(STDERR, "Baseline file not found: {$baselinePath}\n");
    exit(2);
}

try {
    $baseline = json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
} catch (JsonException $exception) {
    fwrite(STDERR, "Invalid baseline JSON: {$exception->getMessage()}\n");
    exit(2);
}

if (($baseline['format'] ?? null) !== 'dedupe-php-compat-baseline/v1') {
    fwrite(STDERR, "Unsupported compatibility baseline format.\n");
    exit(2);
}

$failures = [];
$startedAt = microtime(true);
$records = $baseline['records'] ?? [];
$totalRecords = count($records);
$processed = 0;
$scopeCount = 0;
try {
foreach ($records as $record) {
    $source = $record['canonical_input'] ?? [];
    $expected = $record['expected_from_canonical_input'] ?? [];
    $contexts = ['content' => FingerprintContext::fromSource($source)];
    if (($expected['title_scope'] ?? null) !== null) {
        $contexts['title'] = FingerprintContext::fromSource($source, 'title');
    }

    foreach ($contexts as $scope => $context) {
        ++$scopeCount;
        $expectedScope = $expected["{$scope}_scope"] ?? [];
        $actual = [
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
        $expectedValues = [
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

        foreach ($expectedValues as $field => $expectedValue) {
            if ($actual[$field] !== $expectedValue) {
                $expectedDisplay = $expectedValue;
                $actualDisplay = $actual[$field];
                if (in_array($field, ['minhash_signature_uint64', 'minhash_bands_uint64'], true)) {
                    foreach ($expectedValue as $index => $value) {
                        if (($actual[$field][$index] ?? null) !== $value) {
                            $expectedDisplay = ['index' => $index, 'value' => $value];
                            $actualDisplay = ['index' => $index, 'value' => $actual[$field][$index] ?? null];
                            break;
                        }
                    }
                }
                $failures[] = [
                    'doc_pk' => $record['doc_pk'] ?? null,
                    'scope' => $scope,
                    'field' => $field,
                    'expected' => $expectedDisplay,
                    'actual' => $actualDisplay,
                ];
                break;
            }
        }
    }
    ++$processed;
    if ($processed % 100 === 0 || $processed === $totalRecords) fwrite(STDERR, sprintf("Processed %d/%d (%.1fs)\n", $processed, $totalRecords, microtime(true) - $startedAt));
}
} catch (Throwable $exception) {
    fwrite(STDERR, PHP_EOL);
    fwrite(STDERR, "Compatibility verification could not complete: {$exception->getMessage()}\n");
    exit(2);
}

$elapsedSeconds = microtime(true) - $startedAt;
$summary = [
    'language' => 'php',
    'contract' => 'full-fingerprint',
    'phase' => 'fingerprint-simhash-minhash',
    'records' => count($baseline['records'] ?? []),
    'scopes' => $scopeCount,
    'elapsed_seconds' => round($elapsedSeconds, 6),
    'records_per_second' => round(count($baseline['records'] ?? []) / max($elapsedSeconds, 1e-9), 2),
    'memory' => ProcessMemory::snapshot(),
    'failure_count' => count($failures),
    'failures' => array_slice($failures, 0, 5),
    'failures_omitted' => max(0, count($failures) - 5),
    'status' => $failures === [] ? 'passed' : 'failed',
];
fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
fwrite(STDERR, PHP_EOL);
exit($failures === [] ? 0 : 1);
