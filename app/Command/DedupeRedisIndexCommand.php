<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Redis\RedisIndexBuilder;
use DateTimeImmutable;
use DateTimeZone;
use Hyperf\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use function Hyperf\Config\config;

final class DedupeRedisIndexCommand extends Command
{
    protected ?string $name = 'dedupe:redis-index';

    public function __construct(private readonly RedisIndexBuilder $builder)
    {
        parent::__construct();
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('管理 Redis 去重索引 generation')
            ->addArgument('action', InputArgument::REQUIRED, 'build|activate|status|cleanup')
            ->addOption('generation', 'g', InputOption::VALUE_REQUIRED, '例如 g000001')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, '回填起始日期 YYYY-MM-DD')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, '回填结束日期（包含）YYYY-MM-DD')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, '回填批大小', '10000')
            ->addOption('expected-items', null, InputOption::VALUE_REQUIRED, 'status估算ETA使用的正文MinHash预计总项数', '0')
            ->addOption('sample-seconds', null, InputOption::VALUE_REQUIRED, 'status测速采样秒数', '10')
            ->addOption('force', 'f', InputOption::VALUE_NONE, '确认 activate/cleanup 操作');
    }

    public function handle(): int
    {
        $action = (string) $this->input->getArgument('action');
        $generation = (string) $this->input->getOption('generation');
        if ($generation === '') {
            $this->error('--generation is required.');
            return self::INVALID;
        }
        if (in_array($action, ['activate', 'cleanup'], true) && !(bool) $this->input->getOption('force')) {
            $this->error("Action {$action} changes generation state; rerun with --force.");
            return self::INVALID;
        }
        try {
            return match ($action) {
                'build' => $this->build($generation),
                'activate' => $this->executeAction("Generation {$generation} activated.", fn () => $this->builder->activate($generation)),
                'status' => $this->status($generation),
                'cleanup' => $this->cleanup($generation),
                default => $this->invalidAction($action),
            };
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }

    private function build(string $generation): int
    {
        $timezone = new DateTimeZone((string) config('dedupe.redis_index.timezone', 'Asia/Shanghai'));
        $today = new DateTimeImmutable('today', $timezone);
        $retention = max(1, (int) config('dedupe.redis_index.retention_days', 10));
        $fromValue = $this->input->getOption('from');
        $toValue = $this->input->getOption('to');
        $from = is_string($fromValue) && $fromValue !== '' ? new DateTimeImmutable($fromValue, $timezone) : $today->modify('-' . ($retention - 1) . ' days');
        $toInclusive = is_string($toValue) && $toValue !== '' ? new DateTimeImmutable($toValue, $timezone) : $today;
        $to = $toInclusive->setTime(0, 0)->modify('+1 day');
        $this->builder->build(
            $generation,
            $from->setTime(0, 0),
            $to,
            max(1, (int) $this->input->getOption('batch-size')),
            fn (string $message) => $this->line($message),
        );
        $this->info("Generation {$generation} built and ready; activate it explicitly after validation.");
        return self::SUCCESS;
    }

    private function status(string $generation): int
    {
        $metadata = $this->builder->status($generation);
        if ($metadata === []) {
            $this->warn("Generation {$generation} does not exist.");
            return self::FAILURE;
        }
        foreach ($metadata as $name => $value) {
            $this->line("{$name}={$value}");
        }
        $expected = max(0, (int) $this->input->getOption('expected-items'));
        $sampleSeconds = max(0, min(60, (int) $this->input->getOption('sample-seconds')));
        $first = max(0, (int) ($metadata['content_minhash_rows_processed'] ?? 0));
        $this->line('content_minhash_rows_processed=' . number_format($first));
        if ($expected > 0) {
            $this->line(sprintf('progress=%.2f%%', min(100, $first * 100 / $expected)));
        }
        if (($metadata['status'] ?? '') === 'building' && $sampleSeconds > 0) {
            $startedAt = microtime(true);
            sleep($sampleSeconds);
            $latest = $this->builder->status($generation);
            $second = max(0, (int) ($latest['content_minhash_rows_processed'] ?? 0));
            $elapsed = max(0.001, microtime(true) - $startedAt);
            $rate = max(0.0, ($second - $first) / $elapsed);
            $this->line('content_minhash_rows_after_sample=' . number_format($second));
            $this->line('rows_per_second=' . number_format($rate, 0, '.', ''));
            if ($expected > 0 && $rate > 0) {
                $remaining = max(0, $expected - $second);
                $etaSeconds = (int) ceil($remaining / $rate);
                $this->line('eta=' . $this->duration($etaSeconds));
            }
        }
        return self::SUCCESS;
    }

    private function cleanup(string $generation): int
    {
        $deleted = $this->builder->cleanup($generation);
        $this->info("Generation {$generation} cleanup queued for {$deleted} keys.");
        return self::SUCCESS;
    }

    private function executeAction(string $message, callable $callback): int
    {
        $callback();
        $this->info($message);
        return self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Unsupported action: {$action}");
        return self::INVALID;
    }

    private function duration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds % 60);
    }
}
