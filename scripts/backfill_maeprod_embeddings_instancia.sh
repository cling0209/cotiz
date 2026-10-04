#!/usr/bin/env bash
# Genera embeddings pgvector en la instancia actual (Reicol o Romulo).
# Uso en VPS:
#   cd /opt/cotiz-reicol && bash scripts/backfill_maeprod_embeddings_instancia.sh
#   cd /opt/cotiz-romulo && bash scripts/backfill_maeprod_embeddings_instancia.sh
set -euo pipefail

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
LIMIT="${LIMIT:-0}"
SLEEP_MS="${SLEEP_MS:-150}"

echo "==> Migración (extensión vector + columnas maeprod)"
docker compose -f "$COMPOSE_FILE" exec -T app php artisan migrate --force

echo "==> Backfill masivo (pendientes; en cola si hay worker)"
docker compose -f "$COMPOSE_FILE" exec -T app php artisan cotiz:maeprod-embeddings --queue

echo "==> (Opcional) mismo proceso en consola sin cola:"
echo "    docker compose -f $COMPOSE_FILE exec -T app php artisan cotiz:maeprod-embeddings --missing --limit=$LIMIT --sleep-ms=$SLEEP_MS"

echo "==> Listo."
