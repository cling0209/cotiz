#!/bin/bash
# Restaurar dump desde Render Postgres al contenedor local (una vez por instancia).
# Requiere en .env.prod (temporal): RENDER_SOURCE_HOST, RENDER_SOURCE_DB,
# RENDER_SOURCE_USER, RENDER_SOURCE_PASSWORD + Postgres local levantado.
set -euo pipefail
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$APP_DIR"

set -a
# shellcheck disable=SC1091
source .env.prod
set +a

: "${RENDER_SOURCE_HOST:?Define RENDER_SOURCE_HOST en .env.prod}"
: "${RENDER_SOURCE_DB:?Define RENDER_SOURCE_DB en .env.prod}"
: "${RENDER_SOURCE_USER:?Define RENDER_SOURCE_USER en .env.prod}"
: "${RENDER_SOURCE_PASSWORD:?Define RENDER_SOURCE_PASSWORD en .env.prod}"
: "${POSTGRES_USER:?Define POSTGRES_USER en .env.prod}"
: "${POSTGRES_DB:?Define POSTGRES_DB en .env.prod}"

RENDER_HOST="$RENDER_SOURCE_HOST"
if [[ "$RENDER_HOST" != *.* ]]; then
  RENDER_HOST="${RENDER_HOST}.oregon-postgres.render.com"
fi

POSTGRES_CONTAINER="$(docker compose --env-file .env.prod -f docker-compose.prod.yml ps -q postgres)"
if [ -z "$POSTGRES_CONTAINER" ]; then
  echo "Postgres local no está corriendo. Ejecuta primero:" >&2
  echo "  docker compose --env-file .env.prod -f docker-compose.prod.yml up -d postgres" >&2
  exit 1
fi

echo "Dump Render ${RENDER_SOURCE_DB}@${RENDER_HOST} → Postgres local ${POSTGRES_DB}..."
docker run --rm -e PGPASSWORD="$RENDER_SOURCE_PASSWORD" postgres:18-alpine \
  pg_dump -h "$RENDER_HOST" -U "$RENDER_SOURCE_USER" -d "$RENDER_SOURCE_DB" \
  --no-owner --no-acl --clean --if-exists \
  | docker exec -i "$POSTGRES_CONTAINER" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1

echo "Restore complete. Opcional: borra RENDER_SOURCE_* de .env.prod."
