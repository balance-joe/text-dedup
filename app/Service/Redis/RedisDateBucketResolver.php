<?php

declare(strict_types=1);

namespace App\Service\Redis;

use DateTimeImmutable;
use DateTimeZone;

use function Hyperf\Config\config;

final class RedisDateBucketResolver
{
    private readonly DateTimeZone $timezone;

    public function __construct(
        ?string $timezone = null,
        private readonly ?int $retentionDays = null,
        private readonly ?int $graceDays = null,
    ) {
        $this->timezone = new DateTimeZone($timezone ?? (string) config('dedupe.redis_index.timezone', 'Asia/Shanghai'));
    }

    public function writeBucket(DateTimeImmutable $time): string
    {
        return 'd' . $time->setTimezone($this->timezone)->format('Ymd');
    }

    /** @return list<string> */
    public function queryBuckets(?DateTimeImmutable $time = null, ?int $days = null): array
    {
        $time = ($time ?? new DateTimeImmutable('now', $this->timezone))->setTimezone($this->timezone)->setTime(0, 0);
        $days ??= $this->retentionDays ?? (int) config('dedupe.redis_index.retention_days', 10);
        $days = max(1, $days);
        $result = [];
        for ($offset = 0; $offset < $days; ++$offset) {
            $result[] = $this->writeBucket($time->modify("-{$offset} days"));
        }
        return $result;
    }

    public function expireAt(string $bucket): int
    {
        if (preg_match('/\Ad(\d{8})\z/', $bucket, $matches) !== 1) {
            throw new \InvalidArgumentException("Invalid Redis date bucket: {$bucket}");
        }
        $date = DateTimeImmutable::createFromFormat('!Ymd', $matches[1], $this->timezone);
        if (!$date instanceof DateTimeImmutable) {
            throw new \InvalidArgumentException("Invalid Redis date bucket: {$bucket}");
        }
        $days = $this->retentionDays ?? (int) config('dedupe.redis_index.retention_days', 10);
        $grace = $this->graceDays ?? (int) config('dedupe.redis_index.grace_days', 2);
        return $date->modify('tomorrow')->modify('+' . max(1, $days) . ' days')->modify('+' . max(0, $grace) . ' days')->getTimestamp();
    }
}
