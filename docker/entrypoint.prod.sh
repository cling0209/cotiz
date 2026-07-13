#!/bin/sh
set -e

PORT="${PORT:-8000}"
cd /var/www/html

sed "s/listen 8000/listen ${PORT}/" /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf

if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "" ]; then
  echo "ERROR: APP_KEY no definida. Configúrala en Koyeb." >&2
  exit 1
fi

run_as_www() {
  if id www-data >/dev/null 2>&1; then
    su -s /bin/sh www-data -c "$1"
  else
    sh -c "$1"
  fi
}

fix_storage_permissions() {
  mkdir -p storage/app/imports/chunks storage/app/imports/merged storage/app/imports/pending storage/app/imports/jobs storage/app/imports/errors storage/app/imports/staging storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
  touch storage/logs/laravel.log storage/logs/mail.log 2>/dev/null || true
  chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
  chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true
  chmod -R 777 storage/logs storage/framework 2>/dev/null || true
  chown www-data:www-data storage/logs/laravel.log storage/logs/mail.log 2>/dev/null || true
  chmod 666 storage/logs/laravel.log storage/logs/mail.log 2>/dev/null || true
}

fix_storage_permissions

run_as_www 'php artisan package:discover --ansi 2>/dev/null || true'
run_as_www 'php artisan view:clear 2>/dev/null || true'
php -r 'echo "PHP max_input_vars=".ini_get("max_input_vars").PHP_EOL;'
run_as_www 'php artisan optimize:clear 2>/dev/null || true'
run_as_www 'php artisan config:cache'
run_as_www 'php artisan route:cache'
run_as_www 'php artisan view:cache'

if [ "$RUN_MIGRATIONS" = "true" ]; then
  run_as_www 'php artisan migrate --force'
fi

if [ "$RUN_SEED" = "true" ]; then
  run_as_www 'php artisan db:seed --force'
fi

run_as_www 'php artisan l5-swagger:generate 2>/dev/null || true'

fix_storage_permissions

if [ "$RUN_QUEUE_WORKER" = "true" ]; then
  echo "Iniciando queue worker (database) con auto-restart..." >&2
  (
    while true; do
      run_as_www 'php artisan queue:work database --sleep=3 --tries=1 --timeout=43200 --max-time=43200 2>&1'
      echo "[$(date)] Queue worker terminó (exit $?). Reiniciando en 5s..." >&2
      sleep 5
    done
  ) &
  echo "Queue worker loop PID: $!" >&2
fi

# Si Render dormía en el horario programado, encolar catch-up al boot.
# También hay catch-up en el primer request web (middleware EnsureMpResultadosScheduleCatchUp).
if [ "${MERCADOPUBLICO_RESULTADOS_SCHEDULE:-true}" = "true" ]; then
  echo "Catch-up consulta MP programada (si el slot se perdió por sleep)..." >&2
  run_as_www 'php artisan compra-agil:consultar-resultados --catch-up --no-interaction 2>&1' || true
fi

# Scheduler Laravel (consulta MP a las 10 y 19, u horas en MERCADOPUBLICO_RESULTADOS_SCHEDULE_HOURS).
if [ "${RUN_SCHEDULER:-true}" = "true" ]; then
  echo "Iniciando Laravel scheduler (cada 60s)..." >&2
  (
    while true; do
      run_as_www 'php artisan schedule:run --verbose --no-interaction 2>&1' || true
      sleep 60
    done
  ) &
  echo "Scheduler loop PID: $!" >&2
fi

php-fpm -D
exec nginx -g 'daemon off;'
