FROM php:8.3-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip curl nginx libpq-dev libzip-dev libicu-dev libonig-dev libxml2-dev \
    libpng-dev libjpeg62-turbo-dev libwebp-dev tesseract-ocr tesseract-ocr-eng tesseract-ocr-chi-sim \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j2 pdo_pgsql pgsql bcmath intl zip mbstring dom xml xmlwriter opcache gd pcntl \
    && pecl install redis-6.3.0 && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts \
    && find app routes deploy -name '*.php' -print0 | xargs -0 -n1 php -l \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && rm -f /etc/nginx/sites-enabled/default \
    && chmod +x deploy/entrypoint.sh
COPY deploy/nginx.conf /etc/nginx/conf.d/saveb.conf
COPY build/php/business-uploads.ini /usr/local/etc/php/conf.d/business-uploads.ini
ENTRYPOINT ["/var/www/html/deploy/entrypoint.sh"]
CMD ["php-fpm", "-F"]
