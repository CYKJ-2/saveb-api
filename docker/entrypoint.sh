#!/bin/sh
set -eu
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache /data/attachments/api
# Only writable mount roots are adjusted; imported attachment trees are not recursively rewritten.
chown www-data:www-data storage storage/framework storage/framework/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache /data/attachments /data/attachments/api
if [ "$1" = php-fpm ] || { [ "$1" = php ] && [ "${2:-}" = artisan ]; }; then
    php artisan config:cache
fi
exec "$@"
