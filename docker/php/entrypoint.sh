#!/bin/sh
set -e
cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist
fi

php artisan migrate --force 2>/dev/null || true

mkdir -p storage/app/imports/chunks storage/app/imports/merged storage/app/imports/jobs storage/app/imports/errors
chown -R www-data:www-data storage/app/imports 2>/dev/null || true

if [ "${RUN_SEED:-false}" = "true" ]; then
  php artisan db:seed --force 2>/dev/null || true
fi

php artisan l5-swagger:generate 2>/dev/null || true

exec php-fpm -F
