# Python 指纹性能基准

使用与生产 Python 一致的 NumPy SimHash/MinHash 算法，对同一份兼容性基准计算完整指纹字段：

- SimHash 和 band；
- 128 维 MinHash 和 band；
- 文本归一化、MD5 与文本元数据；
- content scope，以及基准中存在的 title scope。

本脚本只统计指纹计算时间，不包含数据库读取和基准 JSON 加载时间。

自定义 Python 项目或基准文件：

```shell
python text-dedup-python/benchmark.py --repeat 1 --input runtime/php-compat-baseline-1000.json
```
