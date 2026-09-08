# ============================================================
# SAVEB API - PHP 运行时镜像（生产可发布版）
# ============================================================
#
# 多阶段构建：
#   1) builder  → 安装 composer 依赖（dev deps → 生产用 --no-dev）
#   2) runtime  → 仅包含 PHP 扩展 + 应用代码 + vendor/
#
# 使用 BuildKit 自动启用多阶段（CI 设置 DOCKER_BUILDKIT=1）
#
# 镜像标签由 CI 决定：
#   - git tag vX.Y.Z 推送 → ghcr.io/YOUR_ORG/saveb-api-app:vX.Y.Z + latest
#   - push to main       → ghcr.io/YOUR_ORG/saveb-api-app:dev
# ============================================================

# ─────────────────────────── 1. Builder ───────────────────────────
FROM composer:2 AS vendor

WORKDIR /app

# 先 copy composer 描述文件，利用 Docker 层缓存
COPY composer.json composer.lock ./

# 生产环境不安装 dev 依赖（phpunit、faker、mockery 等）
# --no-scripts 先不跑 scripts（避免生成 .env 相关错误）
# --no-autoloader 先不生成 autoloader，下一步手工跑
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --optimize-autoloader

# 清除 bootstrap 缓存（可能包含已移除 dev 包的引用）
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

# 复制应用源码（仅 .dockerignore 过滤后的）
COPY . .

# 确保没有残留的 dev 包缓存（.dockerignore 在 Windows Docker Desktop 下不完全可靠）
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

# 生成优化过的 autoloader（--no-scripts 跳过 package:discover，避免读取已删除 dev 包的 composer.json 配置）
RUN composer dump-autoload --classmap-authoritative --no-dev --no-scripts

# ─────────────────────────── 2. Runtime ──────────────────────────
FROM php:8.3-fpm AS runtime

ARG APP_ENV=production
ENV APP_ENV=${APP_ENV}

# 安装系统依赖（runtime 必需）
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        curl \
        unzip \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# 安装 PHP 扩展
RUN docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        zip \
        opcache

# 安装 Redis 扩展
RUN pecl install redis \
    && docker-php-ext-enable redis

# 生产 opcache 配置
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.save_comments=1'; \
        echo 'opcache.fast_shutdown=1'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

# 时区
ENV TZ=UTC
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

WORKDIR /var/www/html

# 从 builder 阶段把整个应用（含 vendor/）拷过来
COPY --from=vendor --chown=www-data:www-data /app /var/www/html

# 生产权限：storage 和 bootstrap/cache 可写
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# 运行时 ENTRYPOINT：先修权限（容器重启后 storage 命名卷可能变 root），
# 然后 exec 用户传入的 command（docker-compose 传入 php-fpm / php artisan queue:work 等）
#
# 用 sh -c ... exec "$@" 让 argv[0] 是真实命令，信号传递正常
ENTRYPOINT ["/bin/sh", "-c", "\n    chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true;\n    exec \"$0\" \"$@\"\n"]

# 健康检查默认（生产 compose 里可以覆盖）
HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=30s \
    CMD php -r "echo PHP_VERSION;" || exit 1

# 默认 CMD（在 docker-compose 里会被 command: 覆盖）
CMD ["php-fpm"]
