<?php

declare(strict_types=1);

namespace App\Service\Redis;

enum RedisIndexStatus: string
{
    case Building = 'building';
    case Ready = 'ready';
    case Degraded = 'degraded';
    case Disabled = 'disabled';
}
