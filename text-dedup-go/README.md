# Go 性能原型

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