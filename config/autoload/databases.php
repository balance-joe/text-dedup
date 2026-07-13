<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
use Hyperf\ModelCache\Handler\RedisHandler;

use function Hyperf\Support\env;

return [
    'dedupe_pg' => [
        'driver' => 'pgsql-swoole',
        'host' => env('DEDUPE_PG_HOST', '127.0.0.1'),
        'port' => (int) env('DEDUPE_PG_PORT', 5432),
        'database' => env('DEDUPE_PG_DATABASE', 'dedupe'),
        'username' => env('DEDUPE_PG_USERNAME', 'dedupe_readonly'),
        'password' => env('DEDUPE_PG_PASSWORD', ''),
        'charset' => 'utf8',
        'prefix' => '',
        'pool' => [
            'min_connections' => (int) env('DEDUPE_PG_POOL_MIN_CONNECTIONS', 1),
            'max_connections' => (int) env('DEDUPE_PG_POOL_MAX_CONNECTIONS', 10),
            'connect_timeout' => (float) env('DEDUPE_PG_CONNECT_TIMEOUT', 5.0),
            'wait_timeout' => (float) env('DEDUPE_PG_WAIT_TIMEOUT', 3.0),
            'max_idle_time' => (float) env('DEDUPE_PG_MAX_IDLE_TIME', 60.0),
        ],
    ],
    'default' => [
        'driver' => env('DB_DRIVER', 'mysql'),
        'host' => env('DB_HOST', 'localhost'),
        'port' => env('DB_PORT', 3306),
        'database' => env('DB_DATABASE', 'hyperf'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
        'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
        'prefix' => env('DB_PREFIX', ''),
        'pool' => [
            'min_connections' => 1,
            'max_connections' => 10,
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => (float) env('DB_MAX_IDLE_TIME', 60),
        ],
        'cache' => [
            'handler' => RedisHandler::class,
            'cache_key' => '{mc:%s:m:%s}:%s:%s',
            'prefix' => 'default',
            'ttl' => 3600 * 24,
            'empty_model_ttl' => 600,
            'load_script' => true,
        ],
        'commands' => [
            'gen:model' => [
                'path' => 'app/Model',
                'force_casts' => true,
                'inheritance' => 'Model',
                'uses' => '',
                'table_mapping' => [],
            ],
        ],
    ],
];
