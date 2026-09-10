FROM php:8.3-fpm-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip curl nginx libpq-dev libzip-dev libicu-dev libonig-dev libxml2-dev \
    libpng-dev libjpeg62-turbo-dev libwebp-dev tesseract-ocr tesseract-ocr-eng tesseract-ocr-chi-sim \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j2 pdo_pgsql pgsql bcmath intl zip mbstring dom xml xmlwriter opcache gd pcntl \
    && pecl install redis-6.3.0 && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY docker/business-uploads.ini /usr/local/etc/php/conf.d/business-uploads.ini

# 同一套 PHP 扩展；开发挂载源码和 dev vendor，生产安装锁定的非 dev 依赖。
FROM base AS development
RUN printf 'opcache.validate_timestamps=1\nopcache.revalidate_freq=0\n' > /usr/local/etc/php/conf.d/local.ini
CMD ["php-fpm"]

FROM base AS production
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts \
    && find app routes docker -name '*.php' -print0 | xargs -0 -n1 php -l \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && rm -f /etc/nginx/sites-enabled/default \
    && chmod +x docker/entrypoint.sh
# nginx.conf 来自服务器根目录的只读挂载，不把本地的站点配置固化进镜像。
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["php-fpm", "-F"]
