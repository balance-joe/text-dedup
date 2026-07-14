<?php

declare(strict_types=1);

namespace App\Support;

final class ProcessMemory
{
    /**
     * 读取整个进程的 RSS，同时保留 PHP 内存管理器口径，便于区分原生扩展内存。
     *
     * @return array<string, float|string|null>
     */
    public static function snapshot(): array
    {
        $currentRssMb = null;
        $peakRssMb = null;
        $processMetric = '当前操作系统不支持进程 RSS 采集';

        if (is_readable('/proc/self/status')) {
            $status = (string) file_get_contents('/proc/self/status');
            $currentRssMb = self::statusValueMb($status, 'VmRSS');
            $peakRssMb = self::statusValueMb($status, 'VmHWM');
            $processMetric = 'Linux /proc/self/status 的 VmRSS 与 VmHWM';
        }

        return [
            'process_current_rss_mb' => $currentRssMb,
            'process_peak_rss_mb' => $peakRssMb,
            'php_current_allocated_mb' => self::bytesToMb(memory_get_usage(true)),
            'php_peak_allocated_mb' => self::bytesToMb(memory_get_peak_usage(true)),
            'process_metric' => $processMetric,
            'php_metric' => 'PHP 内存管理器实际分配的字节数',
        ];
    }

    private static function statusValueMb(string $status, string $name): ?float
    {
        if (preg_match('/^' . preg_quote($name, '/') . ':\s+(\d+)\s+kB$/m', $status, $matches) !== 1) {
            return null;
        }

        return round(((int) $matches[1]) / 1024, 3);
    }

    private static function bytesToMb(int $bytes): float
    {
        return round($bytes / 1024 / 1024, 3);
    }
}
