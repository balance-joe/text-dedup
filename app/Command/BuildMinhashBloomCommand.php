<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MinHash;
use App\Service\SimHashRedisService;
use App\Support\UInt64;
use Hyperf\Command\Command;
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

use function Hyperf\Config\config;

/** 从 PG MinHash 叶分区流式回填 RedisBloom；回填期间查询端始终回退 PG。 */
final class BuildMinhashBloomCommand extends Command
{
    protected ?string $name = 'dedupe:bloom:build';

    public function __construct(private readonly SimHashRedisService $bloom)
    {
        parent::__construct();
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('从 PostgreSQL 全量回填 MinHash RedisBloom，并在成功后标记 ready')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'content 或 title', 'content');
    }

    public function handle(): int
    {
        $scope = (string) $this->input->getOption('scope');
        if (!in_array($scope, ['content', 'title'], true)) {
            $this->error('--scope 只能是 content 或 title。');
            return self::FAILURE;
        }
        if (!$this->bloom->beginMinhashBloomBuild($scope)) {
            $this->error('RedisBloom 初始化失败；确认 DEDUPE_MINHASH_BLOOM_ENABLED=1 且 Redis 已加载 RedisBloom 模块。');
            return self::FAILURE;
        }

        $connection = ApplicationContext::getContainer()
            ->get(ConnectionResolverInterface::class)
            ->connection('default');
        $prefix = $scope === 'title' ? 'title_minhash_band' : 'minhash_band';
        $total = 0;
        try {
            for ($bandIndex = 0; $bandIndex < MinHash::BANDS; ++$bandIndex) {
                $table = $this->qualifiedTable("{$prefix}_p{$bandIndex}");
                $batch = [];
                // cursor() 逐行读取，禁止把约 1.6 亿 band 行一次性装进 PHP 内存。
                foreach ($connection->cursor("SELECT band_value FROM {$table}") as $row) {
                    $signed = (int) (is_array($row) ? $row['band_value'] : $row->band_value);
                    $batch[] = UInt64::toDecimal(UInt64::fromSignedInt64($signed));
                    if (count($batch) === 1000) {
                        $written = $this->bloom->addMinhashBandValues($bandIndex, $batch, $scope);
                        if ($written !== count($batch)) {
                            throw new \RuntimeException("band {$bandIndex} RedisBloom 批量写入失败。");
                        }
                        $total += $written;
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    $written = $this->bloom->addMinhashBandValues($bandIndex, $batch, $scope);
                    if ($written !== count($batch)) {
                        throw new \RuntimeException("band {$bandIndex} RedisBloom 尾批写入失败。");
                    }
                    $total += $written;
                }
                $this->line("band {$bandIndex} 完成，累计 {$total} 行。");
            }
            if (!$this->bloom->markMinhashBloomReady($scope)) {
                throw new \RuntimeException('所有 band 已写入，但 ready 状态写入失败。');
            }
        } catch (Throwable $exception) {
            $this->bloom->disableMinhashBloom($scope);
            $this->error('回填失败，已关闭 ready 状态：' . $exception->getMessage());
            return self::FAILURE;
        }
        $this->info("{$scope} MinHash Bloom 回填完成，共 {$total} 行，状态已设为 ready。");
        return self::SUCCESS;
    }

    private function qualifiedTable(string $table): string
    {
        $schema = (string) config('dedupe.database.schema', 'dedup_content');
        if (preg_match('/\A[a-z_][a-z0-9_]*\z/i', $schema) !== 1
            || preg_match('/\A[a-z_][a-z0-9_]*\z/i', $table) !== 1) {
            throw new InvalidArgumentException('Invalid PostgreSQL schema or table identifier.');
        }
        return sprintf('"%s"."%s"', $schema, $table);
    }
}
