# Hyperf PHP 8.4 + Swoole + OPcache JIT 运行镜像
#
# @link     https://www.hyperf.io
# @document https://hyperf.wiki
# @contact  group@hyperf.io
# @license  https://github.com/hyperf/hyperf/blob/master/LICENSE

FROM hyperf/hyperf:8.4-alpine-v3.22-swoole

LABEL maintainer="Hyperf Developers <group@hyperf.io>" \
      version="1.0" \
      license="MIT" \
      app.name="text-dedup"

##
# ---------- 运行环境 ----------
##

ARG timezone=Asia/Shanghai

ENV TIMEZONE=${timezone} \
    APP_ENV=prod \
    SCAN_CACHEABLE=true \
    COMPOSER_ALLOW_SUPERUSER=1

##
# ---------- PHP / OPcache / JIT / Swoole 配置 ----------
##

RUN set -eux; \
    \
    PHP_CONF_DIR="/etc/php84/conf.d"; \
    test -d "${PHP_CONF_DIR}"; \
    \
    { \
        echo "[PHP]"; \
        echo "date.timezone=${TIMEZONE}"; \
        echo "memory_limit=1G"; \
        echo "upload_max_filesize=128M"; \
        echo "post_max_size=128M"; \
        echo "max_execution_time=0"; \
        echo "max_input_time=-1"; \
        echo "max_input_vars=10000"; \
        echo "default_socket_timeout=60"; \
        echo "expose_php=Off"; \
        echo ""; \
        echo "[Zend OPcache]"; \
        echo "opcache.enable=1"; \
        echo "opcache.enable_cli=1"; \
        echo "opcache.memory_consumption=256"; \
        echo "opcache.interned_strings_buffer=32"; \
        echo "opcache.max_accelerated_files=30000"; \
        echo "opcache.max_wasted_percentage=10"; \
        echo "opcache.validate_timestamps=0"; \
        echo "opcache.revalidate_freq=0"; \
        echo "opcache.save_comments=1"; \
        echo "opcache.enable_file_override=1"; \
        echo "opcache.huge_code_pages=1"; \
        echo ""; \
        echo "; PHP JIT 配置"; \
        echo "opcache.jit=tracing"; \
        echo "opcache.jit_buffer_size=128M"; \
        echo ""; \
        echo "[Realpath Cache]"; \
        echo "realpath_cache_size=4096K"; \
        echo "realpath_cache_ttl=600"; \
        echo ""; \
        echo "[Swoole]"; \
        echo "swoole.use_shortname=Off"; \
    } > "${PHP_CONF_DIR}/99_text_dedup.ini"; \
    \
    ln -snf "/usr/share/zoneinfo/${TIMEZONE}" /etc/localtime; \
    echo "${TIMEZONE}" > /etc/timezone; \
    \
    php -v; \
    php -m; \
    php --ri swoole; \
    php --ri "Zend OPcache"; \
    \
    php -r ' \
        $required = [ \
            "json", \
            "pcntl", \
            "posix", \
            "openssl", \
            "pdo", \
            "pdo_mysql", \
            "pdo_pgsql", \
            "pdo_sqlite", \
            "redis", \
            "swoole", \
            "opcache", \
            "mbstring", \
            "curl", \
            "sockets", \
            "fileinfo", \
            "bcmath", \
            "sysvshm", \
            "sysvmsg", \
            "sysvsem" \
        ]; \
        foreach ($required as $extension) { \
            if (!extension_loaded($extension)) { \
                fwrite(STDERR, "缺少 PHP 扩展：{$extension}\n"); \
                exit(1); \
            } \
            echo "PHP 扩展可用：{$extension}\n"; \
        } \
    '; \
    \
    php -r ' \
        $shortName = strtolower((string) ini_get("swoole.use_shortname")); \
        if (in_array($shortName, ["1", "on", "true", "yes"], true)) { \
            fwrite(STDERR, "swoole.use_shortname 必须设置为 Off\n"); \
            exit(1); \
        } \
        echo "Swoole 短类名：Off\n"; \
    '; \
    \
    php -r ' \
        if (!filter_var(ini_get("opcache.enable_cli"), FILTER_VALIDATE_BOOLEAN)) { \
            fwrite(STDERR, "OPcache CLI 尚未启用\n"); \
            exit(1); \
        } \
        if ((int) ini_get("opcache.jit_buffer_size") <= 0) { \
            fwrite(STDERR, "PHP JIT 尚未启用\n"); \
            exit(1); \
        } \
        $jit = opcache_get_status(false)["jit"] ?? []; \
        echo "OPcache CLI：已启用\n"; \
        echo "JIT 模式：", ini_get("opcache.jit"), "\n"; \
        echo "JIT 缓冲区：", ini_get("opcache.jit_buffer_size"), "\n"; \
        echo "JIT 已启用：", !empty($jit["enabled"]) ? "是" : "否", "\n"; \
        echo "JIT 正在运行：", !empty($jit["on"]) ? "是" : "否", "\n"; \
    '; \
    \
    rm -rf /var/cache/apk/* /tmp/* /usr/share/man; \
    echo -e "\033[42;37m 镜像构建检查全部通过。\033[0m\n"

##
# ---------- 工作目录 ----------
##

WORKDIR /data

##
# ---------- 可选：把项目代码打进镜像 ----------
#
# 如果后续要把项目打进镜像，取消下面注释：
#
# COPY composer.json composer.lock /data/
#
# RUN composer install \
#     --no-dev \
#     --prefer-dist \
#     --no-interaction \
#     --no-progress \
#     --no-scripts \
#     --optimize-autoloader
#
# COPY . /data
#
# RUN composer dump-autoload \
#     --no-dev \
#     --classmap-authoritative \
#     --no-interaction
#
# CMD ["php", "/data/bin/hyperf.php", "start"]
##

EXPOSE 9501

STOPSIGNAL SIGTERM

CMD ["php", "-a"]
