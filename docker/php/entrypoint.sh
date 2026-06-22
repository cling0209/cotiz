#!/bin/sh
set -e
cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist
fi

# Solo migraciones pendientes (nunca migrate:fresh). Activar con RUN_MIGRATIONS=true en .env / docker-compose.
# Nunca migrar en APP_ENV=testing (servicio test usa SQLite en memoria).
if [ "${RUN_MIGRATIONS:-false}" = "true" ] && [ "${APP_ENV:-local}" != "testing" ]; then
  php artisan migrate --force
fi

# Permite: docker compose run --rm test  (sin levantar php-fpm)
if [ "$#" -gt 0 ]; then
  exec "$@"
fi

mkdir -p storage/app/imports/chunks storage/app/imports/merged storage/app/imports/pending storage/app/imports/jobs storage/app/imports/errors storage/app/imports/staging
chmod -R 777 storage/app/imports 2>/dev/null || true
chown -R www-data:www-data storage/app/imports 2>/dev/null || true

if [ "${RUN_SEED:-false}" = "true" ]; then
  php artisan db:seed --force 2>/dev/null || true
fi

php artisan l5-swagger:generate 2>/dev/null || true

exec php-fpm -F
