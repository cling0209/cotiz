#!/bin/bash
# Adapta un .env copiado desde Render al formato Hetzner (.env.prod).
# Uso:
#   bash scripts/patch-env-for-hetzner.sh romulo ../cotiz-romulo.env > .env.prod
#   bash scripts/patch-env-for-hetzner.sh reicol ../cotiz-reicol.env > .env.prod
set -euo pipefail

SITE="${1:-}"
INPUT="${2:-}"
if [ "$SITE" != "romulo" ] && [ "$SITE" != "reicol" ]; then
  echo "Uso: bash scripts/patch-env-for-hetzner.sh romulo|reicol <archivo.env>" >&2
  exit 1
fi
if [ -z "$INPUT" ] || [ ! -f "$INPUT" ]; then
  echo "Archivo no encontrado: $INPUT" >&2
  exit 1
fi

if [ "$SITE" = "romulo" ]; then
  APP_PORT=8001
  POSTGRES_PORT=5433
  MP_ADMIN=true
else
  APP_PORT=8002
  POSTGRES_PORT=5434
  MP_ADMIN=false
fi

DB_DATABASE="$(grep -E '^DB_DATABASE=' "$INPUT" | head -1 | cut -d= -f2- | tr -d ' "' || true)"
DB_USERNAME="$(grep -E '^DB_USERNAME=' "$INPUT" | head -1 | cut -d= -f2- | tr -d ' "' || true)"
DB_PASSWORD="$(grep -E '^DB_PASSWORD=' "$INPUT" | head -1 | cut -d= -f2- | tr -d ' "' || true)"
DB_HOST_RENDER="$(grep -E '^DB_HOST=' "$INPUT" | head -1 | cut -d= -f2- | tr -d ' "' || true)"

grep -v -E '^(RENDER_KEEPALIVE|REDIS_|LEGACY_PRODUCT_IMAGES_PATH|L5_SWAGGER_CONST_HOST|SANCTUM_STATEFUL_DOMAINS|APP_PORT|POSTGRES_PORT|POSTGRES_DB|POSTGRES_USER|POSTGRES_PASSWORD|SESSION_SECURE_COOKIE|RENDER_SOURCE_)=' "$INPUT" \
  | sed -E \
    -e 's/^APP_DEBUG=.*/APP_DEBUG=false/' \
    -e 's/^DB_HOST=.*/DB_HOST=postgres/' \
    -e 's/^MERCADOPUBLICO_ANALISIS_ADMIN=.*/MERCADOPUBLICO_ANALISIS_ADMIN='"${MP_ADMIN}"'/'

echo ""
echo "# --- Hetzner (patch-env-for-hetzner.sh) ---"
echo "APP_PORT=${APP_PORT}"
echo "POSTGRES_PORT=${POSTGRES_PORT}"
echo "SESSION_SECURE_COOKIE=true"
echo "POSTGRES_DB=${DB_DATABASE}"
echo "POSTGRES_USER=${DB_USERNAME}"
echo "POSTGRES_PASSWORD=${DB_PASSWORD}"
echo "RENDER_SOURCE_HOST=${DB_HOST_RENDER}"
echo "RENDER_SOURCE_DB=${DB_DATABASE}"
echo "RENDER_SOURCE_USER=${DB_USERNAME}"
echo "RENDER_SOURCE_PASSWORD=${DB_PASSWORD}"
