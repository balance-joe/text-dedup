# text-dedup

PHP/Hyperf 对 Python「千万文章相似去重服务」的迁移项目。目前已完成并验证的是**文章指纹计算内核**与其批量性能链路；它还不是可直接替换 Python 在线去重服务的 HTTP 应用。

## 当前目的

近期提交依次完成了 Python、Go、PHP 三条相同指纹链路，并以 Python 导出的兼容基准作为唯一正确性标准。目标是让 PHP 对同一篇文章生成与 Python 完全相同的归一化文本、哈希和 LSH 分桶字段，为后续替换在线服务的存储和匹配层打基础。

## 当前已实现

### 指纹内核

- 文本归一化：Unicode NFKC、零宽字符清理、表情和短方括号 token 清理、数字归零、中文/全角字符保留，以及原文本回退规则。
- 内容与标题两个作用域：正文优先，正文为空时内容作用域回退标题；标题作用域只使用标题。
- `raw_hash`、内容/标题 MD5 精确哈希、低信息文本判定。
- UTF-8 字符级 5-gram。
- 128 位 SimHash、8 个 SimHash 分桶。
- 128 维 MinHash、32 个 MinHash 分桶，以及不溢出的 uint64 十进制表示。
- 原生 PHP 扩展 `dedupe_blake2b`：BLAKE2b-8、批量 MinHash、批量 SimHash 和批量 uint64 转换。安装说明见 [ext/dedupe_blake2b/README.md](ext/dedupe_blake2b/README.md)。

### 正确性与批量运行

- [bin/verify-php-compat.php](bin/verify-php-compat.php)：单进程完整兼容性验证。
- [bin/verify-php-compat-swoole.php](bin/verify-php-compat-swoole.php)：Swoole 多进程完整兼容性验证。父进程预读基准后 fork，子进程按记录分片计算。
- `runtime/php-compat-baseline-1000.json`：1000 条 Python 导出基准；通过标准是 `failure_count: 0` 且 `status: passed`。
- Go 与 Python 基准程序用于同一份基准的横向验证和性能比较。

线上已在 1000 条完整基准上验证 PHP Swoole 12 workers 的结果为 `1411.96 records/s`、`failure_count: 0`；同机 Go 12 workers 为 `1421.22 records/s`。这是**离线指纹计算**吞吐，不代表含数据库查询的线上 API 吞吐。

## 可替换范围

| Python 服务能力 | 当前 PHP 状态 | 说明 |
| --- | --- | --- |
| 文本归一化与内容/标题作用域 | 已可替换 | 由 1000 条 Python 基准逐字段验证。 |
| raw/content/title 精确哈希 | 已可替换 | 字段和回退规则已在 `FingerprintContext` 中实现。 |
| 5-gram、SimHash、MinHash 与 LSH 分桶计算 | 已可替换 | 输出与 Python 基准兼容。 |
| 大批量离线指纹生成 | 已可替换 | 可使用 Swoole 多进程验证器作为计算方式参考。 |
| 原生 CPU 加速 | 已可替换 | 使用项目的 `dedupe_blake2b` 扩展。 |
| Python FastAPI `/health`、`/dedupe/check`、`/documents` 接口 | 未实现 | 当前 Hyperf 路由只有示例首页。 |
| MySQL/PostgreSQL 去重表、建表、迁移和数据访问层 | 未实现 | PHP 仅有连接配置，没有 Python `DedupeStore` 等价实现。 |
| raw/content/title 精确去重查询与写入 | 未实现 | 没有文档存储、唯一约束处理或插入流程。 |
| SimHash LSH 候选查询、汉明距离筛选 | 未实现 | 当前只计算 SimHash 和分桶，未查询候选文档。 |
| MinHash LSH 候选查询与 Jaccard 复核 | 未实现 | 当前只计算签名和分桶，未进行候选召回和相似度判定。 |
| 标题链路的 duplicate/similar 状态映射 | 未实现 | 指纹作用域已具备，在线决策和响应格式尚未实现。 |
| RedisBloom raw-hash 预过滤 | 未实现 | 尚未接入 RedisBloom。 |
| Qdrant + embedding 语义去重 | 未实现 | 未接入模型、Qdrant 或向量写入/检索。 |
| 文档删除、过期清理、索引与向量同步 | 未实现 | 没有等价的服务、命令或定时任务。 |
| Python API 的性能指标和响应契约 | 未实现 | 当前只输出离线验证 JSON。 |

## 运行当前功能

服务器需加载 `intl`、`mbstring`、`sodium` 和 `dedupe_blake2b` 扩展。

单进程兼容性验证：

```bash
php \
  -d opcache.enable_cli=1 \
  -d opcache.jit_buffer_size=128M \
  -d opcache.jit=tracing \
  bin/verify-php-compat.php \
  runtime/php-compat-baseline-1000.json
```

Swoole 多进程验证：

```bash
php \
  -d opcache.enable_cli=1 \
  -d opcache.jit_buffer_size=128M \
  -d opcache.jit=tracing \
  bin/verify-php-compat-swoole.php \
  runtime/php-compat-baseline-1000.json \
  --workers 12
```

`--workers` 取值应按服务器 CPU 和实际压测结果决定。上述程序会加载整份基准并创建子进程，只用于正确性和 CPU 计算性能验证，不应直接作为线上接口进程模型。






## Redis 数据补全

Redis 数据补全按这个顺序执行。

1. 开启新 band 的日期写入

修改 `.env`：

```dotenv
DEDUPE_BAND_CREATED_AT_WRITE_ENABLED=1
DEDUPE_BAND_DATE_FILTER_ENABLED=0
DEDUPE_REDIS_INDEX_ENABLED=0
```

重启正在运行的 Hyperf Worker，确保新入库 band 已经开始写 `created_at`。

2. 回填 PostgreSQL 历史日期

```bash
php bin/hyperf.php dedupe:band-schema backfill \
  --batch-size=10000 \
  --force
```
然后执行：

```bash
php bin/hyperf.php dedupe:band-schema create-index --force

php bin/hyperf.php dedupe:band-schema validate
```

只有 `validate` 确认 `missing_created_at=0` 后，再执行：

```bash
php bin/hyperf.php dedupe:band-schema enforce --force
```

输出中的：

```text
title_simhash_band rows=-8
title_minhash_band rows=-32
```

不是负数数据，而是 PostgreSQL 部分叶分区尚未 ANALYZE 导致的估算值；`not-scanned` 也不是 Redis 错误。最终以 `validate` 的实际检查结果为准。

3. 开启 Redis 索引与日期查询

修改 `.env`：

```dotenv
DEDUPE_BAND_CREATED_AT_WRITE_ENABLED=1
DEDUPE_BAND_DATE_FILTER_ENABLED=1
DEDUPE_REDIS_INDEX_ENABLED=1
```


4. 真正向 Redis 回填数据

```bash
php bin/hyperf.php dedupe:redis-index build \
  --generation=g2026071601 \
  --from=2026-07-07 \
  --to=2026-07-16 \
  --batch-size=10000
```

这个命令开始后，Redis 才会出现：

```text
dedupe:g2026071601:...
```

包括：

- generation metadata
- Exact Hash Bloom
- external_id Bloom
- 按天、scope、band 分桶的 MinHash Bloom

构建过程中会持续输出类似：

```text
reserved d20260707
exact doc_pk=10000
content minhash band=0 doc_pk=...
```

5. 检查 Redis

构建期间或完成后：

```bash
php bin/hyperf.php dedupe:redis-index status \
  --generation=g2026071601
```

构建过程中可传入预计的正文 MinHash 总项数，命令会只读采样 RedisBloom 并估算剩余时间：

```bash
php bin/hyperf.php dedupe:redis-index status \
  --generation=g2026071601 \
  --expected-items=74245250 \
  --sample-seconds=10
```

也可以直接检查相同 Redis DB：

```bash
redis-cli -h <REDIS_HOST> -p <REDIS_PORT> -n <REDIS_DB> \
  --scan --pattern 'dedupe:*' | head -50
```

构建完成显示 `ready` 后才能激活：

```bash
php bin/hyperf.php dedupe:redis-index activate \
  --generation=g2026071601 \
  --force
```
