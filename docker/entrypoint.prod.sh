#!/bin/sh
set -e

PORT="${PORT:-8000}"
cd /var/www/html

sed "s/listen 8000/listen ${PORT}/" /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf

if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "" ]; then
  echo "ERROR: APP_KEY no definida. Configúrala en Koyeb." >&2
  exit 1
fi

mkdir -p storage/app/imports/chunks storage/app/imports/merged storage/app/imports/pending storage/app/imports/jobs storage/app/imports/errors storage/app/imports/staging
chown -R www-data:www-data storage/app/imports 2>/dev/null || true

php artisan package:discover --ansi 2>/dev/null || true
php artisan view:clear 2>/dev/null || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ "$RUN_MIGRATIONS" = "true" ]; then
  php artisan migrate --force
fi

if [ "$RUN_SEED" = "true" ]; then
  php artisan db:seed --force
fi

php artisan l5-swagger:generate 2>/dev/null || true

php-fpm -D
exec nginx -g 'daemon off;'
