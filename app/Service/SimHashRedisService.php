<?php

declare(strict_types=1);

namespace App\Service;

use Hyperf\Context\ApplicationContext;
use Hyperf\Redis\RedisFactory;
use InvalidArgumentException;
use Redis;
use Throwable;

use function Hyperf\Config\config;

/**
 * Redis 侧 LSH 加速能力。
 *
 * 当前只实现 MinHash 的 RedisBloom 前置过滤器，不接入 /dedupe/check：
 * - BF 确认不存在：未来可跳过该 band 对应的 PostgreSQL 叶分区查询；
 * - BF 可能存在：仍查询 PostgreSQL，Bloom 的误判不会影响召回；
 * - Redis 不可用、重启或回填中：返回 null，调用方必须回退 PostgreSQL。
 *
 * 未来 SimHash Redis 候选桶方案（暂不写入、不参与查询）：
 *   key:   {prefix}:simhash:{scope}:b{0..7}:{4-byte-hex-band-value}
 *   type:  Redis Set / ZSet，member 为 doc_pk；ZSet score 可使用写入时间。
 *   query: 8 个 SMEMBERS/ZRANGEBYSCORE 并集后，再到 PG 批量取 fingerprint 做汉明距离精算。
 *
 * SimHash 桶容量会随文档数线性增长，必须另设每桶上限、热桶保护和过期/重建策略；
 * 因此不能把本类的 Bloom 过滤器误当作 SimHash 候选存储。
 */
final class SimHashRedisService
{
    private bool $warned = false;

    /**
     * 建立（或确认存在）某个 scope 的 32 个 MinHash Bloom Filter，并标为 building。
     *
     * 调用顺序：beginMinhashBloomBuild() -> 从 PG 全量 addMinhashBands() -> markMinhashBloomReady()。
     * build 期间 mightContainMinhashBands() 始终返回 null，保证没有漏召回。
     */
    public function beginMinhashBloomBuild(string $scope): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        try {
            $redis = $this->redis();
            for ($bandIndex = 0; $bandIndex < MinHash::BANDS; ++$bandIndex) {
                $this->reserve($redis, $this->minhashBloomKey($scope, $bandIndex), $scope);
            }
            $redis->set($this->minhashStateKey($scope), 'building');
            return true;
        } catch (Throwable $exception) {
            $this->warnOnce('MinHash Bloom 初始化失败', $exception);
            return false;
        }
    }

    /**
     * 仅应在全量回填成功、且此后写入链路会同步 addMinhashBands() 时调用。
     */
    public function markMinhashBloomReady(string $scope): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        try {
            $redis = $this->redis();
            for ($bandIndex = 0; $bandIndex < MinHash::BANDS; ++$bandIndex) {
                if (!$redis->exists($this->minhashBloomKey($scope, $bandIndex))) {
                    return false;
                }
            }
            return (bool) $redis->set($this->minhashStateKey($scope), 'ready');
        } catch (Throwable $exception) {
            $this->warnOnce('MinHash Bloom 就绪状态写入失败', $exception);
            return false;
        }
    }

    /**
     * Redis 重启、回填失败或发现数据不同步时调用；后续查询将全部回退 PG。
     */
    public function disableMinhashBloom(string $scope): void
    {
        try {
            $this->redis()->del($this->minhashStateKey($scope));
        } catch (Throwable $exception) {
            $this->warnOnce('MinHash Bloom 状态清理失败', $exception);
        }
    }

    /**
     * @param list<array{int, string}> $bands MinHash::bandItems() 的返回值，值必须是 uint64 十进制字符串。
     */
    public function addMinhashBands(array $bands, string $scope = 'content'): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        try {
            foreach ($this->normalizeBands($bands) as [$bandIndex, $bandValue]) {
                if ($this->addMinhashBandValues($bandIndex, [$bandValue], $scope) !== 1) {
                    return false;
                }
            }
            return true;
        } catch (Throwable $exception) {
            $this->warnOnce('MinHash Bloom 写入失败', $exception);
            return false;
        }
    }

    /**
     * 回填命令使用的批量写入接口；values 必须是未映射为 PostgreSQL signed bigint 的 uint64 十进制字符串。
     *
     * @param list<string> $values
     * @return int 成功提交给 RedisBloom 的元素数量；0 代表未写入或不可安全确认。
     */
    public function addMinhashBandValues(int $bandIndex, array $values, string $scope = 'content'): int
    {
        if (!$this->enabled()) {
            return 0;
        }
        $this->assertScope($scope);
        if ($bandIndex < 0 || $bandIndex >= MinHash::BANDS) {
            throw new InvalidArgumentException('MinHash band index out of range.');
        }
        $values = array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && preg_match('/^\d+$/', $value) === 1));
        if ($values === []) {
            return 0;
        }

        try {
            $redis = $this->redis();
            $key = $this->minhashBloomKey($scope, $bandIndex);
            // 未 reserve 不自动建 BF：这能避免错误容量或误上线导致静默漏建。
            if (!$redis->exists($key)) {
                return 0;
            }
            foreach (array_chunk($values, 1000) as $chunk) {
                $redis->rawCommand('BF.MADD', $key, ...$chunk);
            }
            return count($values);
        } catch (Throwable $exception) {
            $this->warnOnce('MinHash Bloom 批量写入失败', $exception);
            return 0;
        }
    }

    /**
     * @param list<array{int, string}> $bands
     * @return array<string, bool>|null key 为 "band_index:unsigned_band_value"；null 表示必须整组回退 PG。
     */
    public function mightContainMinhashBands(array $bands, string $scope = 'content'): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        try {
            $redis = $this->redis();
            if ($redis->get($this->minhashStateKey($scope)) !== 'ready') {
                return null;
            }

            $result = [];
            foreach ($this->normalizeBands($bands) as [$bandIndex, $bandValue]) {
                $key = $this->minhashBloomKey($scope, $bandIndex);
                if (!$redis->exists($key)) {
                    return null;
                }
                $result[$this->bandResultKey($bandIndex, $bandValue)] = (bool) $redis->rawCommand('BF.EXISTS', $key, $bandValue);
            }
            return $result;
        } catch (Throwable $exception) {
            $this->warnOnce('MinHash Bloom 查询失败', $exception);
            return null;
        }
    }

    public function isMinhashBloomReady(string $scope = 'content'): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        try {
            return $this->redis()->get($this->minhashStateKey($scope)) === 'ready';
        } catch (Throwable $exception) {
            $this->warnOnce('MinHash Bloom 状态读取失败', $exception);
            return false;
        }
    }

    private function enabled(): bool
    {
        return (bool) config('dedupe.bloom.minhash.enabled', false);
    }

    private function redis(): Redis
    {
        /** @var RedisFactory $factory */
        $factory = ApplicationContext::getContainer()->get(RedisFactory::class);
        /** @var Redis $redis */
        $redis = $factory->get('default');
        return $redis;
    }

    private function reserve(Redis $redis, string $key, string $scope): void
    {
        try {
            $redis->rawCommand(
                'BF.RESERVE',
                $key,
                (string) config('dedupe.bloom.minhash.error_rate', 0.0001),
                (string) $this->capacityForScope($scope),
                'EXPANSION',
                (string) config('dedupe.bloom.minhash.expansion', 2),
            );
        } catch (Throwable $exception) {
            // RedisBloom 对已存在 key 返回 ERR item exists；该情况是幂等成功。
            if (!str_contains(strtolower($exception->getMessage()), 'exist')) {
                throw $exception;
            }
        }
    }

    private function capacityForScope(string $scope): int
    {
        $this->assertScope($scope);
        return (int) config("dedupe.bloom.minhash.{$scope}_capacity", 1);
    }

    private function minhashBloomKey(string $scope, int $bandIndex): string
    {
        $this->assertScope($scope);
        if ($bandIndex < 0 || $bandIndex >= MinHash::BANDS) {
            throw new InvalidArgumentException('MinHash band index out of range.');
        }
        return sprintf('%s:minhash:%s:b%d', config('dedupe.bloom.minhash.key_prefix', 'dedupe:lsh:bf:v1'), $scope, $bandIndex);
    }

    private function minhashStateKey(string $scope): string
    {
        $this->assertScope($scope);
        return sprintf('%s:minhash:%s:state', config('dedupe.bloom.minhash.key_prefix', 'dedupe:lsh:bf:v1'), $scope);
    }

    /** @param list<array{int, string}> $bands @return list<array{int, string}> */
    private function normalizeBands(array $bands): array
    {
        $result = [];
        foreach ($bands as $band) {
            if (!is_array($band) || count($band) !== 2 || !is_int($band[0]) || !is_string($band[1]) || !preg_match('/^\d+$/', $band[1])) {
                throw new InvalidArgumentException('Each MinHash band must contain an index and a uint64 decimal string.');
            }
            $bandIndex = $band[0];
            if ($bandIndex < 0 || $bandIndex >= MinHash::BANDS) {
                throw new InvalidArgumentException('MinHash band index out of range.');
            }
            $result[$bandIndex] = [$bandIndex, $band[1]];
        }
        return array_values($result);
    }

    private function bandResultKey(int $bandIndex, string $bandValue): string
    {
        return $bandIndex . ':' . $bandValue;
    }

    private function assertScope(string $scope): void
    {
        if (!in_array($scope, ['content', 'title'], true)) {
            throw new InvalidArgumentException("Unsupported fingerprint scope: {$scope}");
        }
    }

    private function warnOnce(string $message, Throwable $exception): void
    {
        if ($this->warned) {
            return;
        }
        $this->warned = true;
        error_log(sprintf('[dedupe] %s: %s', $message, $exception->getMessage()));
    }
}
