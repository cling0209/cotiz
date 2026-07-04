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
php -r 'echo "PHP max_input_vars=".ini_get("max_input_vars").PHP_EOL;'
php artisan optimize:clear 2>/dev/null || true
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

if [ "$RUN_QUEUE_WORKER" = "true" ]; then
  echo "Iniciando queue worker (database) con auto-restart..." >&2
  (
    while true; do
      php artisan queue:work database --sleep=3 --tries=1 --timeout=14400 --max-time=14400 >> storage/logs/queue-worker.log 2>&1
      echo "[$(date)] Queue worker terminó (exit $?). Reiniciando en 5s..." >&2
      sleep 5
    done
  ) &
  echo "Queue worker loop PID: $!" >&2
fi

php-fpm -D
exec nginx -g 'daemon off;'
