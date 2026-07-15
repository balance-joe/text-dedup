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
    'default' => [
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
