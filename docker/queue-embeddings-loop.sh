#!/bin/sh
# Worker dedicado: cola embeddings (MaeprodEmbeddingsBackfillJob). Sin nginx/php-fpm.
set -e

cd /var/www/html

QUEUE_NAME="${COTIZ_EMBEDDING_BACKFILL_QUEUE:-embeddings}"

run_as_www() {
  if id www-data >/dev/null 2>&1; then
    su -s /bin/sh www-data -c "$1"
  else
    sh -c "$1"
  fi
}

if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "" ]; then
  echo "ERROR: APP_KEY no definida." >&2
  exit 1
fi

run_as_www 'php artisan config:cache 2>/dev/null || true'

echo "Queue embeddings dedicado (cola=${QUEUE_NAME})..." >&2

set +e
while true; do
  echo "[$(date)] queue:work database --queue=${QUEUE_NAME} arrancando..." >&2
  run_as_www "php artisan queue:work database --queue=${QUEUE_NAME} --sleep=1 --tries=1 --timeout=3600 --max-jobs=1 -v"
  code=$?
  echo "[$(date)] Queue embeddings terminó (exit ${code}). Reiniciando en 2s..." >&2
  sleep 2
done
