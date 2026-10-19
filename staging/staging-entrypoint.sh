#!/bin/sh
# Prepare the storage volume (same tree as production) before php-fpm / artisan.
set -e
cd /var/www/backend
mkdir -p storage/app/public storage/app/private/clubs storage/app/exports \
         storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/framework/testing storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
exec "$@"
