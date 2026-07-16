# Redis 去重索引设计

本文定义 PHP 去重服务如何在一个独立 Redis 实例中维护精确 Hash、SimHash 和 MinHash 三类在线索引，并说明 `generation`、按天分桶、PostgreSQL 与“数据湖”的职责边界。

## 1. 结论

1. PostgreSQL 是去重数据的唯一事实来源；Redis 是可以重建、可以降级的在线索引。
2. Redis 不作为数据湖，不保存原始文章、长期历史或离线训练数据。
3. `generation` 是整套 Redis 索引的版本号；日期桶是同一版本内部的时间分片，两者是正交维度。
4. 精确 Hash 使用 RedisBloom，SimHash 使用 ZSet 候选桶，MinHash 使用 RedisBloom 过滤 PostgreSQL 叶分区。
5. Exact Bloom 按 generation 全局维护，以保持当前 PostgreSQL 全表精确预过滤和全局唯一约束语义；SimHash/MinHash 按天分桶。
6. Redis 的任何命中都不是最终判定：精确 Hash 由 PostgreSQL 确认，SimHash 计算真实 Hamming distance，MinHash 计算真实 5-gram Jaccard。

## 2. Redis 不是数据湖

Redis 适合低延迟查询，但不适合承担数据湖职责：

- 内存成本高，不适合长期保存原始正文；
- Key 会按保留期淘汰，索引也允许重建；
- Bloom Filter 只表达“可能存在”，不能恢复原始数据；
- SimHash 候选桶只保存文档标识，不能替代完整指纹与正文；
- Redis 故障时服务必须能够回退 PostgreSQL。

本项目中的存储分工为：

| 存储 | 职责 |
|---|---|
| PostgreSQL | 在线事实数据：文档、文本、完整指纹、LSH band 与最终查询依据 |
| Redis | 可重建的低延迟索引：精确 Hash Bloom、SimHash 候选桶、MinHash Bloom |
| 对象存储/数据湖（如未来接入 MinIO/S3） | 长期原始数据、历史快照、离线分析和跨版本重建输入 |

当前 PHP 服务不需要为了使用 Redis 而新增数据湖。只有当 PostgreSQL 的保留期短于业务要求、但又需要从更久历史重建索引时，才需要额外的对象存储。

## 3. Generation 与日期桶

### 3.1 Generation 是索引版本

`generation` 表示一套完整且内部兼容的 Redis 索引，例如：

```text
g000001
g000002
```

它主要解决：

- Key 格式或算法参数变化；
- 全量重建时不覆盖当前可用索引；
- 新索引验收后原子切换；
- 切换失败时继续使用旧版本；
- 延迟删除旧版本，便于短时间回滚。

不要直接把日期当作 generation。`20260716` 表示数据所属日期，`g000002` 表示索引版本；同一个 generation 会包含多个日期桶。

元数据建议使用：

```text
dedupe:meta:active_generation             -> g000002
dedupe:meta:building_generation           -> g000003
dedupe:meta:g000003                       -> Redis Hash
```

generation 元数据至少记录：

```text
status=building|ready|disabled
created_at=2026-07-16T10:30:00+08:00
algorithm_version=v1
min_date=20260707
max_date=20260716
exact_ready=1
simhash_ready=1
minhash_ready=1
```

只有三类索引全部完成并通过抽样校验后，才能把 `active_generation` 切换到新版本。切换使用单条 `SET` 或 Lua 脚本完成。

### 3.2 日期桶是业务时间分片

日期桶格式统一为 `YYYYMMDD`：

```text
d20260716
```

当前数据模型只有 `created_at`，因此第一版使用文章写入 PostgreSQL 的日期作为桶日期，并固定使用 `Asia/Shanghai` 时区。以后若接口增加可信的 `published_at`，可以增加明确的配置选择日期来源，不能静默改变现有语义。

若保留 10 天，2026-07-16 的请求查询：

```text
20260716, 20260715, ..., 20260707
```

日期桶的优点：

- 过期时直接删除整天 Key，不需要逐文档清理；
- Bloom Filter 不支持单元素删除，按天分桶能够自然实现滚动保留；
- SimHash 热桶被限制在单日范围内；
- 可以明确控制“只和最近 N 天文章比较”的业务语义。

每个日期桶使用固定过期时间，而不是每次写入后续期：

```text
expire_at = 当天 23:59:59 + retention_days + grace_days
```

建议 `grace_days=2`，给延迟消息、时钟偏差和重建留出余量。业务查询窗口仍然只使用 `retention_days`，宽限期内的数据不参与正常查询。

### 3.3 Exact Hash 全局维护

`external_id` 是身份键，要求全局幂等；实际 PostgreSQL 还对 `content_hash` 设置全局唯一约束，现有精确预过滤也对 `raw_hash`、`title_hash` 做全表查询。因此四类 Exact Bloom 都按 generation 全局维护，不按日期过期：

```text
dedupe:g000002:exact:external_id
dedupe:g000002:exact:raw_hash
dedupe:g000002:exact:content_hash
dedupe:g000002:exact:title_hash
```

全局并不等于无限容量。`external_id` Bloom 必须按预计全量和增长周期显式 reserve，例如预计当前 500 万、下一次 generation 重建前最多增长到 800 万，则按至少 800 万并留 30% 余量配置。监控通过 `BF.INFO` 读取插入数量、容量、Filter 数量和内存大小；RedisBloom 不直接提供实时真实 FPP，应用应根据容量使用率和子 Filter 数量估算风险。容量达到 80% 时告警，接近上限时构建更大容量的新 generation；无法及时重建时，该字段降级为直接查询 PostgreSQL，不能继续信任 Bloom False。

这保证只检查接口与真正插入时的约束一致：不能出现 Bloom 因日期窗口返回“不存在”，但 PostgreSQL最终又被 `uk_document_content_hash` 拒绝。若未来产品明确把精确去重也改成有限窗口，必须先调整 PostgreSQL约束和现有全表预过滤语义，不能只修改 Redis Key。

## 4. Key 设计

现有 PostgreSQL 与 Redis 索引的逻辑映射如下：

| PostgreSQL 字段/表 | Redis 索引 | 约束 |
|---|---|---|
| `document_fingerprint.external_id` 唯一约束 | generation 级 External ID Bloom | 全局幂等，`external_id` 不可变 |
| `raw_hash`、`content_hash`、`title_hash` | generation 级全局 Exact Bloom | Bloom True 后仍由 PostgreSQL精确确认 |
| `simhash_band` 8 个分区与 `simhash_hi/lo` | 8 个按天 ZSet 候选桶 | Redis 只召回，Hamming 最终确认 |
| `minhash_band` 32 个分区 | 32 个按天 Bloom | Bloom 只排除空 band，Jaccard 最终确认 |

标题 scope 使用对应的 `title_simhash_band`、`title_minhash_band` 与标题指纹字段。band 数量、指纹算法参数和 Redis Key 的算法版本必须随 generation 一起记录，禁止只改一侧。

统一格式：

```text
dedupe:{generation}:{index}:{scope}:{date_bucket}:{detail}
```

其中：

- `generation`：索引版本；
- `index`：`exact`、`simhash`、`minhash`；
- `scope`：`content` 或 `title`；
- `date_bucket`：`dYYYYMMDD`；
- `detail`：字段名、band index 或 band value。

### 4.1 精确 Hash Bloom

```text
dedupe:g000002:exact:raw_hash
dedupe:g000002:exact:content_hash
dedupe:g000002:exact:title_hash
dedupe:g000002:exact:external_id
```

建议成员值带上字段前缀，避免未来合并 Filter 时发生语义碰撞：

```text
raw:{32位MD5}
content:{32位MD5}
title:{32位MD5}
external:{external_id}
```

查询规则：

1. generation 内相关的四类全局 Filter 都明确返回 False，才能跳过 PostgreSQL 精确查询。
2. 任意 Filter 返回 True，仍然查询 PostgreSQL确认。
3. 任意 Filter 缺失、generation 非 ready 或 Redis 异常，完整回退 PostgreSQL。

### 4.2 SimHash ZSet 候选桶

128 位 SimHash 当前拆成 8 个 16 位 band：

```text
dedupe:g000002:simhash:content:d20260716:b0:7fa2
dedupe:g000002:simhash:content:d20260716:b1:01bc
dedupe:g000002:simhash:title:d20260716:b0:7fa2
```

推荐使用 ZSet：

```text
member = external_id
score  = 写入时间的毫秒时间戳
```

当前 PostgreSQL 使用自增 `doc_pk`，在 Redis 预写阶段还不可用，因此第一版用稳定的 `external_id` 作为 member。候选召回后，通过 PostgreSQL 按 `external_id` 批量读取完整 SimHash 与文档字段。

`external_id` 在 `/dedupe/check` 写入合同中必须是不可变且全局唯一的身份键。同一 `external_id` 携带不同内容再次进入时，应按现有精确冲突语义返回已存在，而不是原地更新。未来若增加更新接口，必须显式删除旧 SimHash band 成员并写入新 band；Bloom 中无法删除的旧项可以保留为安全假阳性。不能把“同一 ID 更新内容”静默混入新增链路。

查询步骤：

1. 使用 Pipeline 一次发送查询窗口内所有日期桶的 `ZCARD`，避免按“日期 × 8 个 band”逐条产生网络往返；
2. 跳过超过热桶阈值的 Key，再用第二个 Pipeline 对剩余 Key 执行带 LIMIT 的 `ZREVRANGE`；
3. 合并并按 `external_id` 去重；
4. 应用总候选数和单桶上限；
5. PostgreSQL批量读取候选的 128 位 SimHash；
6. 计算真实 Hamming distance；
7. 达到阈值后才返回匹配。

Pipeline 的目标是把多条命令压缩为一次或少数几次 RTT；Redis 服务端仍然顺序执行命令，不能把它描述为真正的并行执行。若未来验证单连接 Pipeline 仍然成为瓶颈，可以使用 Hyperf 协程配合多个 Redis 连接并发拉取，但必须限制每请求占用的连接数。

每个日期桶只取有限候选，例如每 Key 最多 200 条，再在 PHP 中合并去重并取全局 Top N。按“最新”截断会降低旧候选召回，因此结果中必须记录截断和跳桶信息，阈值属于可观测的算法合同。

热点桶保护是正确性合同的一部分。默认建议 `ZCARD > 5000` 时跳过该 Key，依靠其他 7 个 band 召回，并记录 `scope`、日期、band、band value 和桶大小。跳过热桶会牺牲极端模板文本的部分召回率，不能宣称结果与无限候选算法完全等价；该权衡用于保护 P99 延迟，阈值必须可配置并通过线上指标调整。ZSet 的范围查询不会仅因为 score 相同就必然遍历全部成员，但超大桶仍会带来内存、网络和候选偏置风险，因此硬限制仍有必要。

### 4.3 MinHash Bloom

当前 MinHash 使用 32 个 band：

```text
dedupe:g000002:minhash:content:d20260716:b0
...
dedupe:g000002:minhash:content:d20260716:b31
dedupe:g000002:minhash:title:d20260716:b0
...
dedupe:g000002:minhash:title:d20260716:b31
```

Bloom 成员使用未经 PostgreSQL signed bigint 映射的 uint64 十进制字符串。

查询步骤：

1. 对每个查询 band 检查查询窗口内的日期 Bloom；
2. 某个 band 在所有日期 Bloom 都明确返回 False 时，跳过该 band 的 PostgreSQL叶分区查询；
3. 任意一天返回 True 时，查询对应 PostgreSQL band 叶分区；
4. 合并 `doc_pk`，限制候选数量；
5. PostgreSQL批量读取归一化文本；
6. 计算完整 5-gram Jaccard，达到阈值后才算匹配。

当前 PostgreSQL band 表没有日期列，因此 Redis 日期 Bloom 只能判断“窗口中可能存在”，但 PostgreSQL叶分区查询仍可能返回窗口外候选。如果窗口外相同 `band_value` 的旧文档先占满每桶 LIMIT，窗口内真实候选会被挤出并造成漏判。这是正式启用日期窗口前必须解决的正确性阻断项，不能只在候选回表后过滤日期。

本方案选择给四类 band 父表及其叶分区增加 `created_at`：`simhash_band`、`title_simhash_band`、`minhash_band`、`title_minhash_band`。查询必须在每个子查询的 LIMIT 之前强制日期范围：

```sql
WHERE b.band_value = ?
  AND b.created_at >= ?
  AND b.created_at < ?
ORDER BY b.doc_pk
LIMIT ?
```

每个叶分区增加查询索引：

```sql
CREATE INDEX CONCURRENTLY minhash_band_p0_value_created_doc_idx
    ON dedup_content.minhash_band_p0 (band_value, created_at, doc_pk);
```

现有 `(band_value, doc_pk)` 唯一索引继续保留。日期查询索引不需要、也不应该通过删除现有唯一索引来替换；把 `created_at` 加入唯一键会改变约束语义，并不能为正确性带来收益。

迁移不能直接执行 `ADD COLUMN created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP`，否则历史行会全部被标记为迁移时间并错误进入当前窗口。安全步骤是：

1. 使用 `pg_total_relation_size` 统计四类 band 表与现有索引，估算新增时间列和查询索引空间；并发建索引期间必须预留旧索引、新索引与临时文件同时存在的磁盘余量，500 GB 磁盘不能仅按最终索引大小规划；
2. 在 partitioned parent 上增加可空 `created_at TIMESTAMPTZ`，让列定义传播到叶分区；
3. 按 `doc_pk` 范围分批从 `document_fingerprint.created_at` 回填四类 band 表；
4. 对空值数量、每日期行数及随机 `doc_pk` 做一致性校验；
5. 修改 PHP 写入 CTE，使 `inserted` 返回 `doc_pk, created_at`，四类 band INSERT 同时写入相同的 `created_at`；
6. 为所有 8/32 个正文和标题叶分区分别使用 `CREATE INDEX CONCURRENTLY` 创建 `(band_value, created_at, doc_pk)` 查询索引；该命令不能包在普通事务中；
7. 确认线上新写入已持续携带时间后，再设置默认值与 `NOT NULL`；
8. 对带日期条件的真实 band SQL 执行 `EXPLAIN (ANALYZE, BUFFERS)`，确认日期过滤在 LIMIT 之前并使用预期索引。

如果数据库版本不支持在 partitioned parent 上直接完成所需 DDL，应由迁移命令枚举并验证所有叶分区，不能人工只修改部分分区。迁移期间 PHP Redis 日期索引保持关闭。

不改表的替代方案是从 `document_fingerprint` 在同一查询快照中计算窗口 `min(doc_pk)`，再使用 `b.doc_pk >= min_doc_pk`；它依赖 `doc_pk` 与写入时间关系且需要谨慎处理边界，保留为回滚方案，不作为第一版生产实现。简单 JOIN 后把 LIMIT 提高到 500 或 1000 仍可能被大量旧候选占满，不能视为正确性修复。

## 5. 在线查询链路

```text
输入文本
  -> 计算 exact hash
  -> Exact Bloom 检查最近 N 天
     -> 可能存在：PostgreSQL精确确认
     -> 全部不存在：继续
  -> 计算 SimHash
  -> Redis ZSet 召回最近 N 天候选
  -> PostgreSQL读取完整 SimHash
  -> Hamming distance 精算
     -> 命中：返回
     -> 未命中：继续
  -> 计算 MinHash
  -> MinHash Bloom 排除确定为空的 band
  -> PostgreSQL查询剩余 band
  -> PostgreSQL读取候选文本
  -> Jaccard 精算
     -> 命中：返回
     -> 未命中：返回 new
```

正文未命中后，再按相同规则执行标题 scope。Redis 结果中必须保留 `scope`、日期桶和 generation，便于排查候选来自哪一层。

## 6. 在线写入顺序

会返回“确定不存在”或会决定候选集合的 Redis 索引不能落后于 PostgreSQL。写入铁律是：**先预写 Redis 索引，后开启并提交 PostgreSQL 事务**。正常写入顺序为：

```text
1. 计算 external_id、日期桶和全部指纹
2. 写 active generation 的 Exact Bloom
3. 写 active generation 的 SimHash ZSet
4. 写 active generation 的 MinHash Bloom
5. 如果存在 building generation，同时写 building generation
6. BEGIN PostgreSQL 事务
7. 写 document_fingerprint、document_text 和全部 band
8. COMMIT PostgreSQL 事务
```

Redis 先写而 PostgreSQL事务回滚只会留下额外 Bloom 项或无效候选，最终 PostgreSQL验证会过滤它们，属于安全的假阳性。PostgreSQL先提交而 Redis 未写会产生假阴性；进程若恰好在两者之间崩溃，后续请求可能因 Bloom False 或候选缺失绕过 PostgreSQL，把重复文章误报为 `new`，因此不允许。

如果 Redis 写入失败：

1. `RedisDedupIndex` 将预写视为一个有明确状态结果的整体操作，不能吞掉部分命令失败；
2. 任意已启用索引写入失败，先将相应索引或整个 active generation 原子标记为 `degraded`；
3. 确认 degraded 状态已生效后，当前请求才继续写 PostgreSQL；
4. 后续查询对 degraded 索引完整回退 PostgreSQL；
5. 发出告警并安排重建。

三类索引都可能影响召回，不能把 SimHash/MinHash 写入失败仅当作日志问题：SimHash 候选缺失可能漏掉只满足 Hamming 阈值的文档；MinHash Bloom 部分缺失但仍保持 ready 也可能错误跳过 band。最简单且最安全的第一版采用 generation 级 All-or-Nothing：任意一步失败，整个 generation degraded，Exact、SimHash、MinHash 全部回退 PostgreSQL。以后若确有必要独立降级，可以为三类索引分别维护 `ready|degraded` 状态，但查询端必须逐层执行对应的 PostgreSQL后备链路。

Redis 写入和 generation 切换需要通过 Lua 脚本或短锁协调，确保在线请求要么写入旧 active，要么写入新 active，并在 building 存在时完成双写。

## 7. Generation 重建流程

以 `g000003` 替换 `g000002` 为例：

1. 创建 `g000003` 元数据，状态为 `building`。
2. 设置 `building_generation=g000003`，在线新增开始同时写入 active 和 building。
3. 从 PostgreSQL回填保留窗口内的 Exact、SimHash 和 MinHash 数据。
4. 校验每一天、每个 scope 和每个 band 的行数、容量与抽样查询结果。
5. 在短锁/Lua 脚本中确认 building 状态正常，原子设置 `active_generation=g000003`。
6. 清除 `building_generation`，将 `g000003` 标记为 `ready`。
7. `g000002` 保留一个短回滚窗口，然后按 Key 前缀异步删除。

回填失败时只删除 `g000003`，查询继续使用 `g000002`。generation 解决的是索引版本切换，不改变 PostgreSQL事实数据。

## 8. PHP 类职责

建议目录：

```text
app/Service/Redis/
  RedisDedupIndex.php
  RedisIndexGenerationManager.php
  RedisDateBucketResolver.php
  RedisKeyFactory.php
  ExactHashBloomIndex.php
  SimhashCandidateIndex.php
  MinhashBloomIndex.php
```

职责如下：

| 类 | 职责 |
|---|---|
| `RedisDedupIndex` | 提供给 `DedupeService` 的统一门面，编排三类索引的查询与预写 |
| `RedisIndexGenerationManager` | active/building generation、状态、切换、禁用和重建锁 |
| `RedisDateBucketResolver` | 根据 `Asia/Shanghai` 日期和保留期生成写入桶、查询桶与固定过期时间 |
| `RedisKeyFactory` | 集中生成并校验所有 Redis Key，禁止业务代码拼字符串 |
| `ExactHashBloomIndex` | 四类精确字段的 Bloom reserve、批量写入和窗口查询 |
| `SimhashCandidateIndex` | 8 个 band 的 ZSet 写入、候选读取、热桶保护和过期 |
| `MinhashBloomIndex` | 32 个 band 的 Bloom reserve、批量写入和窗口查询 |

`DedupeService` 不直接读取 Redis 状态、不拼 Key，也不通过 `ApplicationContext` 临时获取具体索引类；它只依赖构造器注入的 `RedisDedupIndex`。

旧 `SimHashRedisService` 已由本节的独立索引类与 `RedisDedupIndex` 门面替代并删除。

### 8.1 在线请求的连接边界

Hyperf 数据库池的 `max_connections` 是每个 worker/进程的上限，不是整个服务的全局上限。当前配置为 8 核默认 8 个 Swoole worker、每个默认数据库池最多 10 条连接，Web 进程理论上可占 80 条；再加异步队列进程、CLI回填、迁移和管理员连接，PostgreSQL若只有 100 条连接会接近耗尽。

连接预算按下式计算并留至少 30% PostgreSQL余量：

```text
application_connections
  = web_worker_num * web_pool_max
  + async_process_num * async_pool_max
  + rebuild_connections
  + migration_and_admin_reserve
```

8 核机器第一版建议从 Web 每 worker 4 条开始，即约 32 条 Web 连接，再根据 `pool_acquire_ms`、吞吐与 PostgreSQL活动连接压测调整。`min_connections`、`max_connections`、`wait_timeout` 必须改成环境变量，不能继续硬编码 1/10/3 秒。

在线 `insert_on_check` 的连接和写入边界为：

```text
1. 从 default 数据库池取得一条连接；取得失败则尚未写 Redis，直接失败/降级
2. 使用 Redis 连接完成 active/building 预写
3. 在已经取得的同一条 PG 连接上 BEGIN
4. 执行 fingerprint、text、band CTE
5. COMMIT
6. 请求/协程结束时归还 PG 与 Redis 连接
```

先取得 PG 连接不改变“Redis 数据写入必须早于 PG 提交”的铁律，却可以避免在数据库池已经耗尽时制造大量无意义 Redis 假阳性。事务开始后不能切换连接；Service 应显式接收或闭包捕获同一 Connection，不要在事务内部多次从容器临时解析连接。

`ConnectionResolverInterface` 在同一协程 Context 中会复用连接，但 Hyperf `Parallel` 创建的子协程可能各自借一条连接。去重链路不能为了并行查询 8/32 个 band 就无上限创建子协程；当前一次 `UNION ALL`/Pipeline 的固定连接方案应保留。监控必须区分池等待时间和 SQL 时间。

Redis Pipeline 必须独占借到的单条 Redis 连接直到 `exec` 完成；不能让多个协程同时操作同一个连接对象。若未来使用多连接并发读取日期桶，应设每请求并发上限，并把最坏连接数计入 Redis 池预算。

### 8.2 回填与迁移使用独立连接

generation 回填和 band `created_at` 迁移不使用在线 `default` 连接配置。新增 `rebuild` 数据库连接：

- 与 default 指向同一个 PostgreSQL和 schema，但使用独立应用名/角色；
- pool `min_connections=0`、`max_connections=1`；
- 显式设置 `application_name=dedupe-rebuild`；
- 设置较短 `lock_timeout`，避免等待 DDL 锁拖住线上事务；
- 回填 SELECT 可使用单独的较长 `statement_timeout`，在线连接保持较短超时；
- 设置 `idle_in_transaction_session_timeout`，禁止失败命令留下长期空闲事务；
- 每批提交并记录 checkpoint，命令重启后从上个 `doc_pk` 继续。

旧 `BuildMinhashBloomCommand` 的 default连接和长 `cursor()` 已删除；`dedupe:redis-index build` 改用 rebuild 单连接和基于 `(band_value, created_at, doc_pk)`/`doc_pk` 的 Keyset Pagination 分批查询。每批读取后释放结果并写 Redis，避免单条超长快照、连接断开后从头重来以及客户端缓冲全部结果。

`CREATE INDEX CONCURRENTLY` 使用专用迁移连接执行，不能放入普通事务；执行前检查 `pg_stat_activity`、锁等待与磁盘余量。在线服务、回填命令和迁移命令即使配置为不同 Hyperf connection name，最终仍共享 PostgreSQL服务器的 `max_connections`、IO和CPU，因此必须纳入同一个容量预算。

CLI 只保留两个薄入口，内部复用前述 Service，不按每个动作拆一个 Command：

```text
dedupe:band-schema <inspect|add-column|backfill|create-index|validate|enforce>
dedupe:redis-index <build|activate|status|cleanup>
```

`dedupe:band-schema` 负责 PostgreSQL band 日期迁移的阶段控制；`dedupe:redis-index` 负责 generation 的完整生命周期。数据库修改阶段以及 generation activate/cleanup 必须额外传 `--force`，但不为 build、activate、status、cleanup 分别创建四个 PHP Command 类。这样 `config/autoload/commands.php` 最终只注册两个命令，命令层只解析参数和输出进度，实际逻辑留在可测试的 Service 中。

## 9. Bloom 假阳性与容量预算

Bloom 每元素位数按下式估算：

```text
bits_per_element = -ln(false_positive_rate) / (ln 2)^2
optimal_hash_functions = bits_per_element * ln 2
```

本项目建议：

| 索引 | 目标假阳性率 | 理论位数/元素 | 规划取值 | 最优哈希函数数 |
|---|---:|---:|---:|---:|
| Exact Hash | `1e-4` | 19.17 bit | 20 bit | 约 14 |
| MinHash band | `1e-5` | 23.96 bit | 24 bit | 约 17 |

Exact 假阳性只会增加一次较便宜的 PostgreSQL 精确查询。MinHash 每次请求最多检查 `32 × retention_days` 个 Bloom；若保留 10 天，单 Filter 为 `1e-5` 时，至少一个空 band 被误报的概率约为：

```text
1 - (1 - 0.00001)^(32 * 10) ≈ 0.32%
```

如果 MinHash 也使用 `1e-4`，放大后约为 3.15%，会明显增加空 PostgreSQL band 查询，因此 MinHash 使用更低 FPP。

容量使用单日峰值而不是平均值：

```text
daily_capacity = 单日历史峰值 * 1.3
```

例如每天峰值 100 万篇、保留 10 天：

| 索引 | 原始位图估算 |
|---|---:|
| 四个全局 Exact Hash，1000万文档、20 bit/元素 | 约 95.4 MiB |
| 正文 MinHash，32 band，24 bit/元素 | 约 915 MiB |
| 标题 MinHash（若每篇都有标题） | 约 915 MiB |
| 单 generation 合计 | 约 1.9 GiB |

加入 RedisBloom 元数据、Key、allocator、扩展 Filter 和碎片，按原始位图的 1.4 倍规划，约 2.7 GiB/generation。active 与 building 同时存在时约 5.4 GiB，适合 16 GiB 独立 Redis；如果实际是每天 600 万篇，上述数字需要乘 6，两代索引将超出该服务器容量。

16 GiB 服务器建议给 Redis 设置约 11 GiB 上限并保留操作系统、连接和重建余量：

```conf
maxmemory 11gb
maxmemory-policy noeviction
```

不能使用会自动淘汰索引 Key 的策略，否则缺失成员会形成业务假阴性。达到内存上限时应让写入显式失败，PHP 随即禁用相应索引并回退 PostgreSQL。500 GB 硬盘只用于 RDB/AOF 和系统文件，不能替代 Bloom 所需内存；Redis 索引可从 PostgreSQL重建，因此不把持久化文件当作事实数据。

RedisBloom 的 `BF.RESERVE` 实际只需传 `error_rate` 和 `capacity`，由模块计算位数和哈希函数数；20/24 bit 是容量规划与监控口径。保留 `EXPANSION=2` 作为突发增长保护，但容量达到 80% 时应告警并在下一 generation 扩大 reserve，而不是长期依赖多个扩展 Filter。

### 9.1 SimHash ZSet 内存必须单独计算

上面的 2.7 GiB/generation 只包含 Bloom，不包含 SimHash。每天 100 万篇、保留 10 天时，正文 SimHash 有：

```text
10,000,000 documents * 8 bands = 80,000,000 ZSet memberships
```

若按 `external_id + score + ZSet 编码/指针` 每成员至少 50~60 bytes 粗估，仅正文成员就是 4.0~4.8 GB 十进制空间；标题全量启用时可能再增加同量。这个数字仍未包括数百万个日期/band-value Key、Redis object、SDS、哈希表、碎片和连接缓冲。小 ZSet 可能使用 listpack 节省成员开销，但大量小 Key 的固定成本会升高，最终必须用生产形态样本执行 `MEMORY USAGE` 与 `INFO MEMORY` 压测，不能只相信单成员理论值。

active 与 building 两代同时存在时，正文 SimHash 的成员下限约为 8~9.6 GB，再加约 5.4 GiB 两代 Bloom 和 Key 开销，会超过 16 GiB 服务器的安全范围；若标题 SimHash 也全量启用则更不可能容纳。因此本项目的默认决策是：

1. 16 GiB Redis 第一阶段不启用 SimHash Redis，继续使用当前约 1.6 ms 的 PostgreSQL SimHash 查询；
2. Exact Bloom 与 MinHash Bloom 先上线，验证它们对 PostgreSQL压力的实际收益；
3. 只有确认 SimHash 成为瓶颈后才进入第三阶段；
4. 若第三阶段仍使用 16 GiB Redis，将 SimHash 独立保留期设为 3 天，并分别压测正文与标题；
5. 若要求正文和标题都保留 10 天及双 generation，应使用至少 32 GiB，实际规格以 `MEMORY USAGE` 压测和 30% 空闲余量为准。

SimHash 的保留期与 Bloom 保留期允许不同，但必须在接口结果和监控中明确实际召回窗口。缩短窗口是业务召回范围变化，不只是性能配置变更。

## 10. 配置建议

```dotenv
DEDUPE_REDIS_INDEX_ENABLED=0
DEDUPE_REDIS_INDEX_PREFIX=dedupe
DEDUPE_REDIS_INDEX_RETENTION_DAYS=10
DEDUPE_REDIS_INDEX_GRACE_DAYS=2
DEDUPE_REDIS_INDEX_TIMEZONE=Asia/Shanghai
DEDUPE_BAND_CREATED_AT_WRITE_ENABLED=0
DEDUPE_BAND_DATE_FILTER_ENABLED=0

DB_POOL_MIN_CONNECTIONS=1
DB_POOL_MAX_CONNECTIONS=4
DB_POOL_WAIT_TIMEOUT=3
DB_REBUILD_POOL_MAX_CONNECTIONS=1
REDIS_POOL_MIN_CONNECTIONS=1
REDIS_POOL_MAX_CONNECTIONS=4
REDIS_POOL_WAIT_TIMEOUT=3

DEDUPE_EXACT_BLOOM_ERROR_RATE=0.0001
DEDUPE_EXACT_BLOOM_CAPACITY=10000000
DEDUPE_EXACT_BLOOM_EXTERNAL_ID_CAPACITY=10000000

DEDUPE_SIMHASH_REDIS_ENABLED=0
DEDUPE_SIMHASH_RETENTION_DAYS=3
DEDUPE_SIMHASH_BUCKET_LIMIT=1000
DEDUPE_SIMHASH_TOTAL_CANDIDATES=200
DEDUPE_SIMHASH_HOT_BUCKET_SIZE=5000

DEDUPE_MINHASH_BLOOM_ENABLED=0
DEDUPE_MINHASH_BLOOM_ERROR_RATE=0.00001
DEDUPE_MINHASH_BLOOM_DAILY_CAPACITY=1300000
```

三类索引分别提供开关，但 generation 只有在启用的索引全部 ready 时才能整体切换。第一阶段可以先启用 Exact 和 MinHash，SimHash 继续查询 PostgreSQL。

## 11. 监控指标

至少记录：

- active/building generation；
- Redis 查询失败和 PostgreSQL降级次数；
- Web/异步/rebuild 各连接池的当前连接、借用连接、等待数、超时数和 acquire 延迟；
- PostgreSQL总连接、active/idle/idle in transaction、长事务、锁等待和按 `application_name` 的连接数；
- 各类 Bloom 的正向率、`BF.INFO` 插入数量/容量/Filter 数量/内存大小，以及估算 FPP；
- `external_id` Bloom 容量使用率，达到 80% 告警；
- SimHash 每桶候选数、热桶数和截断数；
- SimHash Pipeline 命令数、RTT、服务端耗时与合并耗时；
- SimHash 的成员总数、Key 数、`MEMORY USAGE` 抽样值、正文/标题及 active/building 分代内存；
- 每个日期桶的 Key 数、内存和过期时间；
- Redis 候选经 PostgreSQL验证后的有效率；
- Exact、SimHash、MinHash 各阶段耗时；
- generation 回填进度、校验结果与切换时间。

任何 Redis 索引缺失、状态异常或数据格式不匹配都应视为“不能安全加速”，而不是“数据不存在”。

## 12. 实施顺序

1. 先按第 4.3 节安全迁移四类 PostgreSQL band 表的 `created_at` 和叶分区查询索引，确保日期过滤发生在每桶 LIMIT 之前；这是后续按天窗口的正确性前置条件。
2. 建立 generation manager、日期桶解析器和统一 Key factory。
3. 实现 Exact Hash Bloom，并保持 PostgreSQL精确确认。
4. 将现有 MinHash Bloom 拆入独立类，加入 generation 和日期桶。
5. 实现统一 Redis 预写、generation 级 All-or-Nothing 降级，再调整 PostgreSQL事务顺序。
6. 实现两个薄 CLI 入口：`dedupe:band-schema` 管理数据库迁移阶段，`dedupe:redis-index` 管理 generation 的回填、校验、切换和清理。
7. 先启用 Exact 与 MinHash，验证结果与纯 PostgreSQL链路一致后扩大流量。
8. 最后单独评审 SimHash Redis 的必要性、保留期和内存压测结果，再决定是否实现 ZSet 候选桶。

## 13. 验收标准

1. 关闭 Redis 或删除任意索引 Key 时，请求自动回退 PostgreSQL且判定结果不变。
2. Bloom False 只减少 PostgreSQL查询，不直接产生重复结论。
3. SimHash Redis 只提供候选，不直接产生相似结论。
4. Redis 预写成功、PostgreSQL失败时不会产生错误重复，只允许额外查询。
5. generation 回填期间持续写入的数据同时进入 active 与 building。
6. generation 切换前后，同一批基准数据的状态、匹配 ID、匹配类型和分数一致。
7. 日期边界（23:59:59/00:00:00）、保留期首尾和宽限期均有自动化测试。
8. 过期日期桶不再参与查询，并在宽限期结束后自动删除。
9. 最近 N 天的 SimHash 查询通过 Pipeline 在固定的少数几次 RTT 内完成，不随日期桶数量线性增加网络往返。
10. Exact 和 MinHash Bloom 的容量、估算 FPP、80% 告警及 Redis `noeviction` 降级路径经过压测验证。
11. PostgreSQL日期条件在每个 band 子查询的 LIMIT 之前生效，窗口外候选无法挤占窗口内候选名额。
12. 任意 Redis 预写步骤部分失败时，generation 在 PostgreSQL提交前进入 degraded，后续请求验证为完整 PostgreSQL回退。
13. 启用 SimHash Redis 前提交正文/标题、active/building 两代的实际内存压测报告；16 GiB 环境不得按 10 天全量估算直接上线。
14. 8 个 Web worker、异步进程、rebuild 和运维连接的总预算低于 PostgreSQL上限并保留至少 30% 余量；连接池耗尽场景不会在 PG 提交前留下不可降级状态。
15. generation 回填使用独立的单连接 rebuild 配置和可恢复的 Keyset Pagination，不依赖一条跨完整叶分区的长 `cursor()`。
