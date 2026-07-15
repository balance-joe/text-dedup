<?php

declare(strict_types=1);

use function Hyperf\Support\env;

$databaseSchemas = array_values(array_filter(array_map('trim', explode(',', env('DB_SCHEMA', 'dedup_content,public')))));

/*
 * Python dedupe_service/.env 的非敏感运行参数镜像。
 * 数据库连接仍由 config/autoload/databases.php 中的 DB_* 参数负责。
 */
return [
    'database' => [
        // 与 Model 使用同一个 DB_SCHEMA 来源，避免原生叶分区 SQL 和 ORM
        // 因两套环境变量而访问不同 schema。
        'schema' => $databaseSchemas[0] ?? 'dedup_content',
    ],
    'levels' => array_values(array_filter(array_map('trim', explode(',', env('DEDUPE_LEVELS', 'content_hash,simhash,minhash'))))),
    'result_limit' => max(1, (int) env('DEDUPE_RESULT_LIMIT', 20)),
    'simhash' => [
        'bits' => (int) env('DEDUPE_BITS', 128),
        'bands' => (int) env('DEDUPE_BANDS', 8),
        'ngram' => (int) env('DEDUPE_NGRAM', 5),
        'max_hamming' => max(0, (int) env('DEDUPE_MAX_HAMMING', 25)),
        'max_bucket_size' => max(1, (int) env('DEDUPE_MAX_BUCKET_SIZE', 1000)),
        'lsh_max_bucket_size' => max(1, (int) env('DEDUPE_LSH_MAX_BUCKET_SIZE', 2000)),
        'api_max_checks' => max(1, (int) env('DEDUPE_API_MAX_SIMHASH_CHECKS', 200)),
    ],
    'minhash' => [
        'ngram' => (int) env('DEDUPE_MINHASH_NGRAM', 5),
        'num_perm' => (int) env('DEDUPE_MINHASH_NUM_PERM', 128),
        'bands' => (int) env('DEDUPE_MINHASH_BANDS', 32),
        'rows' => (int) env('DEDUPE_MINHASH_ROWS', 1),
        'jaccard_threshold' => (float) env('DEDUPE_MINHASH_JACCARD_THRESHOLD', 0.4),
        'max_candidates' => max(1, (int) env('DEDUPE_MINHASH_MAX_CANDIDATES', 50)),
        'api_max_checks' => max(1, (int) env('DEDUPE_API_MAX_MINHASH_CHECKS', 200)),
    ],
    'bloom' => [
        'enabled' => in_array(strtolower((string) env('DEDUPE_BLOOM_ENABLED', '1')), ['1', 'true', 'yes', 'on'], true),
        'key_prefix' => (string) env('DEDUPE_BLOOM_KEY_PREFIX', 'dedupe:bf:v1'),
        'retention_days' => max(1, (int) env('DEDUPE_BLOOM_RETENTION_DAYS', 10)),
    ],
    'vector' => [
        'enabled' => in_array(strtolower((string) env('DEDUPE_VECTOR_ENABLED', '0')), ['1', 'true', 'yes', 'on'], true),
        'threshold' => (float) env('DEDUPE_VECTOR_SIM_THRESHOLD', 0.8),
        'top_k' => max(1, (int) env('DEDUPE_VECTOR_TOP_K', 1)),
        'upsert_on_ingest' => in_array(strtolower((string) env('DEDUPE_VECTOR_UPSERT_ON_INGEST', '0')), ['1', 'true', 'yes', 'on'], true),
    ],
    'cleanup' => [
        'retention_days' => max(0, (int) env('DEDUPE_CLEANUP_RETENTION_DAYS', 4)),
        'interval_seconds' => max(60, (int) env('DEDUPE_CLEANUP_INTERVAL_SECONDS', 3600)),
        'batch_size' => max(1, (int) env('DEDUPE_CLEANUP_BATCH_SIZE', 1000)),
        'max_batches' => max(1, (int) env('DEDUPE_CLEANUP_MAX_BATCHES', 10)),
        'lock_timeout_seconds' => max(0, (int) env('DEDUPE_CLEANUP_LOCK_TIMEOUT_SECONDS', 0)),
    ],
];
