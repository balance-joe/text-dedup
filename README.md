# text-dedup：文章相似去重服务

## 项目目的

本项目使用 PHP 复刻 Python 文章去重服务的核心指纹链路，包括：

- 文本归一化
- UTF-8 字符级 5-gram
- 128 位 SimHash
- 128 维 MinHash
- SimHash/MinHash 分桶字段

Python 导出的兼容性基准是正确性标准。PHP、Go、Python 三套验证程序必须对相同基准文件输出 `failure_count: 0` 和 `status: passed`。

下面的命令都应在项目根目录执行。

## 基准文件

推荐使用 1000 条完整基准：

```text
runtime/php-compat-baseline-1000.json
```

100 条文件适合快速检查，1000 条文件适合性能比较。三种语言比较时必须使用同一个文件、单 Worker，并各自至少运行两次。

## PHP 完整兼容性与性能测试

服务器需要加载 `dedupe_blake2b` 扩展。扩展的编译、安装和验证方法见：

```text
ext/dedupe_blake2b/README.md
```

运行完整 PHP 指纹验证：

```bash
php \
  -d opcache.enable_cli=1 \
  -d opcache.jit_buffer_size=128M \
  -d opcache.jit=tracing \
  bin/verify-php-compat.php \
  runtime/php-compat-baseline-1000.json
```

查看 PHP 各计算阶段耗时：

```bash
php \
  -d opcache.enable_cli=1 \
  -d opcache.jit_buffer_size=128M \
  -d opcache.jit=tracing \
  bin/benchmark-fingerprint-phases.php \
  runtime/php-compat-baseline-1000.json
```

性能报告应确认以下三个后端都是 `native-extension`：

```json
{
  "minhash_backend": "native-extension",
  "simhash_backend": "native-extension",
  "uint64_decimal_backend": "native-extension"
}
```

## Python 完整兼容性与性能测试

使用安装了 NumPy 的 Python/Conda 环境：

```bash
python text-dedup-python/benchmark.py \
  -input runtime/php-compat-baseline-1000.json \
  --repeat 1
```

需要观察多次运行波动时，可改为：

```bash
python text-dedup-python/benchmark.py \
  -input runtime/php-compat-baseline-1000.json \
  --repeat 3
```

横向比较单次完整链路时使用 `--repeat 1`；`--repeat 3` 输出中的 `best_elapsed_seconds` 是三次最好成绩。

## Go 完整兼容性与性能测试

服务器运行 Linux 二进制，单 Worker 命令：

```bash
./text-dedup-go/text-dedup-go-linux-amd64 \
  -input runtime/php-compat-baseline-1000.json \
  -workers 1
```

### 在 Windows 交叉编译 Linux 二进制

进入 Go 目录：

```powershell
cd text-dedup-go
$env:GOOS = "linux"
$env:GOARCH = "amd64"
go build -o text-dedup-go-linux-amd64 .
cd ..
```

将生成的 `text-dedup-go/text-dedup-go-linux-amd64` 上传到服务器，并赋予执行权限：

```bash
chmod +x text-dedup-go/text-dedup-go-linux-amd64
```

如果直接在 Linux 服务器编译：

```bash
cd text-dedup-go
go build -o text-dedup-go-linux-amd64 .
cd ..
```

修改任何 Go 源码后都必须重新构建二进制；只上传 `.go` 文件不会改变旧二进制的输出。

## 内存指标怎么看

三套程序都会输出：

```json
"memory": {
  "process_current_rss_mb": 120.5,
  "process_peak_rss_mb": 180.2,
  "process_metric": "Linux /proc/self/status VmRSS and VmHWM"
}
```

- `process_current_rss_mb`：程序输出结果时的进程实际驻留内存。
- `process_peak_rss_mb`：程序整个生命周期达到过的最高驻留内存，三种语言横向比较优先看这个字段。
- PHP 额外输出 `php_current_allocated_mb` 和 `php_peak_allocated_mb`，它们只统计 PHP 内存管理器，不代表包含 C 扩展在内的整个进程。
- Go 额外输出 Go heap/runtime 指标。
- 基准程序会把 JSON 基准加载进内存，因此这里测量的是“完整验证进程”的内存，不等于生产环境单篇文章的纯计算增量。

## 判断测试是否通过

无论使用哪种语言，都必须同时满足：

```json
{
  "failure_count": 0,
  "status": "passed"
}
```

性能变快但 `failure_count` 不为零没有意义，不得用于生产。

## 公平比较注意事项

- 三种语言必须使用同一个基准文件。
- 单核比较时 Go 必须使用 `-workers 1`，PHP 当前验证脚本也是单进程。
- 不要把 Swoole 协程当成 CPU 并行；CPU 密集任务需要 Swoole 多进程或 Task Worker。
- 首次运行可能受磁盘缓存、CPU 频率和系统负载影响，应至少执行两次并记录结果。
- PHP 修改普通脚本不需要重编扩展；只有 `ext/dedupe_blake2b` 下的 C 源码改变后才需要重新执行 `phpize`、`make` 和 `make install`。
