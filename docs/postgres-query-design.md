# PostgreSQL 在线查询设计

本文只描述当前只读 `/dedupe/check` 查询链路。写入、删除与清理尚未接入 PHP 服务。

## Python 原查询的优点

1. 精确预过滤把 `external_id`、`raw_hash`、`content_hash`、`title_hash` 四种索引查询合并成一次 `UNION ALL`，并用固定优先级只返回一条结果。
2. SimHash 的 8 个 band、MinHash 的 32 个 band 都直接查询 LIST 叶分区，例如 `minhash_band_p0`，避免重复规划父分区裁剪。
3. 所有 band 在一次数据库往返中用 `UNION ALL` 查询；每个分支独立 `ORDER BY doc_pk LIMIT bucket_limit + 1`，既保证候选稳定，又能识别并跳过热桶。
4. SimHash 候选查询直接 JOIN 指纹热表，查询结果已经包含汉明距离比较所需的 128 位指纹，不需要逐候选回表。
5. MinHash 先只取 `doc_pk`，去重并限制到 50 个候选后才读取正文，避免从热桶拉取大量 TOAST 文本。
6. 查询、回表、比较分别计时，能够区分数据库耗时与 Python/NumPy 计算耗时。

## Python 原查询的缺点

1. 32 路 MinHash `UNION ALL` 仍有固定解析和规划成本；低并发时很快，高并发时会放大 PostgreSQL CPU 压力。
2. 每个请求的 band 值不同，使用 unnamed statement 时不能稳定获得服务端 prepared statement 的复用收益。
3. MinHash 命中后会再次按 `external_id` 回表，形成“候选文本查询 + 命中文档完整查询”两次数据库往返。
4. 进程内候选缓存会在写入时整体清空；多 worker 之间不共享，命中率不稳定，不能作为正确性依赖。
5. `LIMIT`、最大候选数和最大比较数保护了延迟，但热点桶或大量碰撞时会降低召回率；这些上限属于算法合同，不能只按性能随意缩小。
6. 叶分区名依赖固定 8/32 band 的物理结构，修改分桶配置必须同步迁移表结构。

## PHP 查询实现

### 精确预过滤

保持 Python 的一次 `UNION ALL` 和优先级语义。MD5 以 32 位十六进制字符串进入 Service，SQL 使用 `decode(?, 'hex')` 转为 `bytea`，避免把任意二进制误当 UTF-8 参数。

### SimHash 桶查询

- 直接访问 `dedup_content.simhash_band_p0` 至 `p7`，标题使用对应 title 表。
- 8 个叶分区子查询一次 `UNION ALL` 发给 PostgreSQL。
- 每个分支使用绑定参数、`ORDER BY b.doc_pk` 和独立 `LIMIT`。
- 叶表名称只由固定 Model 表名与校验后的 band index 生成；schema 与 Model 一样取 `DB_SCHEMA` 第一项。
- 汉明距离命中后只进行一次 JOIN 回表，补齐响应需要的哈希和样本文本。

### MinHash 桶查询

- 直接访问 32 个 MinHash 叶分区，一次 `UNION ALL`，每桶稳定排序并独立限流。
- PHP 先在内存中去重 `doc_pk`，最多保留 `DEDUPE_MINHASH_MAX_CANDIDATES` 个候选。
- 候选回表使用 `document_fingerprint LEFT JOIN document_text` 一条 SQL。候选最多 50 条，因此同时读取三个 16 字节哈希的成本很小，却可以省掉 Python 命中后的第二次回表。
- Jaccard 仍基于完整归一化文本的 5-gram 集合计算，阈值与 Python 保持一致。

## 验收标准

1. 同一请求的 `dedupe_status`、`match_id`、匹配类型和分数与 Python 一致。
2. 哈希字段必须完整，不允许相似匹配时只返回 `match_id` 而丢失 `match_*_hash`。
3. 连续请求至少测试三次，区分首次连接/缓存预热与稳定延迟。
4. 分别观察 `bucket_query_ms`、`docs_fetch_ms`、比较耗时，不用总耗时替代定位。
5. 若桶查询仍慢，在线执行对应 SQL 的 `EXPLAIN (ANALYZE, BUFFERS)`，确认使用 `(band_value, doc_pk)` 叶分区索引，并检查统计信息和连接池等待。
