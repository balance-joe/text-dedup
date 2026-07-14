# dedupe_blake2b：PHP 原生 BLAKE2b 扩展

## 这是什么，为什么存在

本扩展为文章去重服务提供一个 PHP 原生函数：

```php
dedupe_blake2b(string $input, int $length = 8): string
```

同时提供原生 MinHash 批量置换函数：

```php
dedupe_minhash_signature(array $grams): array
```

它接收 PHP 已生成的 5-gram 字符串数组，返回 128 个八字节大端序 uint64。项目会自动优先使用该函数，避免在 PHP 中执行约两千万次无符号 64 位乘加。

它返回**二进制** BLAKE2b 摘要，摘要长度可为 1 到 64 字节。

本项目的 MinHash 必须使用 Python 代码中的：

```python
hashlib.blake2b(text.encode("utf-8"), digest_size=8).digest()
```

也就是 **BLAKE2b-8**。PHP 的 `sodium_crypto_generichash()` 最短只支持 16 字节输出；把 BLAKE2b-16 截断为 8 字节和 Python 的 BLAKE2b-8 **不是同一个结果**，会让 MinHash 签名全部不一致。

因此不要删除、替换或把这个扩展降级为 `md5`、`sha256`、Sodium 截断值或外部命令。

## 依赖与边界

- 只依赖 PHP 扩展编译工具和 C 编译器。
- 内置 BLAKE2b C 实现，**不依赖 OpenSSL、FFI、Swoole、Python 或数据库**。
- 可在普通 PHP CLI、PHP-FPM、Hyperf/Swoole 中加载。
- 只负责 BLAKE2b 与 MinHash 的 128 维签名运算；不负责 SimHash、数据库或协程调度。
- MinHash 函数只负责 128 维签名运算；文本归一化与 5-gram 生成仍由 PHP 完成。

## 服务器编译与安装

进入本目录：

```bash
cd ext/dedupe_blake2b
```

确认编译工具与运行 PHP 是同一个版本：

```bash
phpize --version
php-config --version
php -v
gcc --version
```

构建：

```bash
make clean 2>/dev/null || true
phpize
./configure --enable-dedupe_blake2b --with-php-config="$(command -v php-config)"
make -j"$(nproc)"
sudo make install
```

`make install` 成功后会显示 `dedupe_blake2b.so` 的安装目录。

## 启用扩展

先确认当前 CLI 使用哪份配置：

```bash
php --ini
```

推荐创建独立配置文件，不要直接修改主 `php.ini`：

```bash
sudo vim /usr/local/php/etc/conf.d/dedupe_blake2b.ini
```

文件内容只有一行：

```ini
extension=dedupe_blake2b.so
```

再用下面命令确认该目录确实被当前 PHP 扫描：

```bash
php --ini
```

如果去重服务通过 PHP-FPM 启动，必须在 **PHP-FPM 使用的 ini** 中也加入同一行，然后重启 PHP-FPM。

## 安装后必须验证

确认扩展已加载：

```bash
php -r 'var_dump(extension_loaded("dedupe_blake2b"));'
```

应输出：

```text
bool(true)
```

确认 BLAKE2b-8 与 Python 一致：

```bash
php -r 'echo bin2hex(dedupe_blake2b("abc", 8)), PHP_EOL;'
```

应输出：

```text
d8bb14d833d59559
```

确认原生 MinHash 函数存在：

```bash
php -r 'var_dump(function_exists("dedupe_minhash_signature"));'
```

最后运行项目兼容性验证：

```bash
php bin/verify-php-compat.php runtime/php-compat-baseline-100.json
```

只有最终 `status` 为 `passed`，才表示 PHP 指纹与生产 Python 基准一致。

## PHP 调用规则

函数返回原始二进制字符串，不是十六进制文本：

```php
$binary = dedupe_blake2b('abc', 8);   // strlen($binary) === 8
$hex = bin2hex($binary);              // 用于日志或比对时再转 hex
```

项目中的 `App\Support\Blake2b::digest($input, 8)` 会自动优先调用此扩展；业务代码不要直接自行更换哈希算法。

## 常见故障

| 现象 | 原因与处理 |
| --- | --- |
| `phpize: command not found` | 安装当前 PHP 版本对应的开发包，不能拿别的 PHP 版本的 phpize 编译。 |
| `Unable to load dynamic library` | `dedupe_blake2b.so` 路径、PHP API 版本或 ini 文件不匹配。重新用当前服务器的 `phpize` 和 `php-config` 编译。 |
| CLI 可用、服务不可用 | CLI 与 PHP-FPM/Hyperf 使用了不同 ini；分别用 `php --ini` 和服务配置确认。 |
| `undefined function dedupe_blake2b()` | 扩展没有加载。检查 `php -m | grep dedupe_blake2b`。 |
| 基准 MinHash 仍不一致 | 先执行上面的 `abc` 验证，再确认实际运行的 PHP 进程加载了扩展。不要截断 Sodium 的 16 字节输出。 |

## 修改注意事项

- `blake2b_ref.c` 是算法核心；不要为了“简化”改成 OpenSSL、Sodium 截断或 PHP 循环实现。
- 修改 C 源码后必须重新执行 `phpize`、`configure`、`make`、`make install`，并重启对应服务。
- 每次修改后都必须运行 100 条 Python 兼容性基准，不能只用单条 `abc` 结果判断。
