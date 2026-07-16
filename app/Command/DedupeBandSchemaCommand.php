<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Redis\BandSchemaManager;
use Hyperf\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class DedupeBandSchemaCommand extends Command
{
    protected ?string $name = 'dedupe:band-schema';

    public function __construct(private readonly BandSchemaManager $manager)
    {
        parent::__construct();
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('分阶段迁移四类 PostgreSQL band 表的 created_at 与查询索引')
            ->addArgument('action', InputArgument::REQUIRED, 'inspect|add-column|backfill|create-index|validate|enforce')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, '回填批大小', '10000')
            ->addOption('force', 'f', InputOption::VALUE_NONE, '确认执行会修改数据库的阶段');
    }

    public function handle(): int
    {
        $action = (string) $this->input->getArgument('action');
        if (in_array($action, ['add-column', 'backfill', 'create-index', 'enforce'], true)
            && !(bool) $this->input->getOption('force')) {
            $this->error("Action {$action} changes PostgreSQL; rerun with --force after inspection.");
            return self::INVALID;
        }
        try {
            return match ($action) {
                'inspect' => $this->show($this->manager->inspect()),
                'add-column' => $this->executeAction('created_at 可空列已添加。', fn () => $this->manager->addColumn()),
                'backfill' => $this->backfill(),
                'create-index' => $this->createIndexes(),
                'validate' => $this->show($this->manager->validate()),
                'enforce' => $this->executeAction('created_at 默认值与 NOT NULL 已启用。', fn () => $this->manager->enforce()),
                default => $this->invalidAction($action),
            };
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }

    private function backfill(): int
    {
        $batchSize = max(1, (int) $this->input->getOption('batch-size'));
        $this->manager->backfill($batchSize, fn (string $table, int $total) => $this->line("{$table}: {$total}"));
        $this->info('band created_at 回填完成。');
        return self::SUCCESS;
    }

    private function createIndexes(): int
    {
        $this->manager->createIndexes(fn (string $table) => $this->line("{$table}: index ready"));
        $this->info('band 日期查询索引创建完成。');
        return self::SUCCESS;
    }

    private function show(array $rows): int
    {
        foreach ($rows as $table => $stats) {
            $missing = $stats['missing_created_at'] < 0 ? 'not-scanned' : (string) $stats['missing_created_at'];
            $this->line(sprintf('%s rows=%d missing_created_at=%s', $table, $stats['rows'], $missing));
        }
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
}
