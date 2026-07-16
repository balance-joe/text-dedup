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

$base = [
        'driver' => env('DB_DRIVER', 'pgsql'),
        'host' => env('DB_HOST', 'localhost'),
        'port' => env('DB_PORT', 5432),
        'database' => env('DB_DATABASE', 'dedup_content'),
        'username' => env('DB_USERNAME', 'postgres'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => env('DB_CHARSET', 'utf8'),
        'collation' => env('DB_COLLATION', ''),
        'schema' => array_values(array_filter(array_map('trim', explode(',', env('DB_SCHEMA', 'dedup_content,public'))))),
        'prefix' => env('DB_PREFIX', ''),
];

return [
    'default' => array_merge($base, [
        'pool' => [
            'min_connections' => max(0, (int) env('DB_POOL_MIN_CONNECTIONS', 1)),
            'max_connections' => max(1, (int) env('DB_POOL_MAX_CONNECTIONS', 4)),
            'connect_timeout' => max(0.1, (float) env('DB_POOL_CONNECT_TIMEOUT', 10)),
            'wait_timeout' => max(0.1, (float) env('DB_POOL_WAIT_TIMEOUT', 3)),
            'heartbeat' => (float) env('DB_POOL_HEARTBEAT', -1),
            'max_idle_time' => max(1, (float) env('DB_MAX_IDLE_TIME', 60)),
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
    ]),
    'rebuild' => array_merge($base, [
        'username' => env('DB_REBUILD_USERNAME', env('DB_USERNAME', 'postgres')),
        'password' => env('DB_REBUILD_PASSWORD', env('DB_PASSWORD', '')),
        'pool' => [
            'min_connections' => 0,
            'max_connections' => max(1, (int) env('DB_REBUILD_POOL_MAX_CONNECTIONS', 1)),
            'connect_timeout' => max(0.1, (float) env('DB_REBUILD_CONNECT_TIMEOUT', 10)),
            'wait_timeout' => max(0.1, (float) env('DB_REBUILD_WAIT_TIMEOUT', 10)),
            'heartbeat' => (float) env('DB_REBUILD_HEARTBEAT', -1),
            'max_idle_time' => max(1, (float) env('DB_REBUILD_MAX_IDLE_TIME', 30)),
        ],
    ]),
];
