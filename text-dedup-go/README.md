# Go 性能原型

## PostgreSQL 查询压测服务

`cmd/query-server` 复刻 `/dedupe/check`：文本归一化、MD5、SimHash、MinHash、精确预过滤、SimHash/MinHash 叶分区候选召回、候选回表、汉明距离及 5-gram Jaccard 判定。它访问与 PHP 相同的 PostgreSQL schema 和表。

```powershell
cd text-dedup-go
go mod tidy
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '5432'
$env:DB_DATABASE = 'dedup_content'
$env:DB_USERNAME = 'postgres'
$env:DB_PASSWORD = 'your-password'
go run ./cmd/query-server
```

服务默认监听 `:8009`；可用 `LISTEN_ADDR` 覆盖。完整兼容接口为 `POST /dedupe/check`，输入 `id`、`source_from`、`title`、`content`、`limit` 等字段，与 PHP 版本一致。`insert_on_check` 当前与 PHP 一样只读，始终不会写入数据库。

```powershell
$body = @{ id='9851222221'; source_from='test'; title=''; content='待去重文本'; insert_on_check=$false; limit=1 } | ConvertTo-Json -Compress
Invoke-RestMethod http://127.0.0.1:8009/dedupe/check -Method Post -ContentType application/json -Body $body
```

保留的拆分查询接口为：

- `POST /dedupe/query/prefilter`：`id`、`raw_hash`、`content_hash` 和可选 `title_hash`（均为 MD5 十六进制）
- `POST /dedupe/query/simhash-candidates`：`scope`、`bands:[{"index":0,"value":"abcd"}]`、`max_candidates_per_band`
- `POST /dedupe/query/minhash-candidates`：`scope`、`bands:[{"index":0,"value":"18446744073709551615"}]`、`max_candidates_per_band`
- `POST /dedupe/query/documents`：`doc_pks:[1,2]`

每个响应都会给出 `pool_acquire_ms`、`sql_ms` 和 `result_mapping_ms`，便于将同样的请求打到 PHP 接口进行比较。打包 Linux 二进制：

```powershell
$env:GOOS = 'linux'; $env:GOARCH = 'amd64'
go build -o text-dedup-check-linux-amd64 ./cmd/query-server
```

使用同一份 Python 兼容性基准，计算并校验完整指纹字段：文本归一化、MD5、文本元数据、SimHash/高低位/bands、128 维 MinHash/bands，以及存在时的 title scope。

```bash
go mod tidy
go run . -input ../runtime/php-compat-baseline-100.json -workers 1
go run . -input ../runtime/php-compat-baseline-100.json -workers 8


# 上传服务器
cd text-dedup-go
$env:GOOS = "linux"
$env:GOARCH = "amd64"
go build -o text-dedup-go-linux-amd64 .

# 在服务器修改权限
chmod +x text-dedup-go/text-dedup-go-linux-amd64

./text-dedup-go/text-dedup-go-linux-amd64   -input runtime/php-compat-baseline-1000.json   -workers 5
```
### 输出结果

```json
{
  "contract": "full-fingerprint",
  "elapsed_seconds": 1.36085421,
  "failure_count": 0,
  "failures": [],
  "language": "go",
  "memory": {
    "go_heap_allocated_mb": 55.593,
    "go_runtime_metric": "Go runtime.MemStats Alloc and Sys",
    "go_runtime_sys_mb": 121.576,
    "process_current_rss_mb": 77.465,
    "process_metric": "Linux /proc/self/status VmRSS and VmHWM",
    "process_peak_rss_mb": 113.586
  },
  "records": 1000,
  "records_per_second": 734.8325725501485,
  "scopes": 1000,
  "status": "passed",
  "workers": 5
}


```
