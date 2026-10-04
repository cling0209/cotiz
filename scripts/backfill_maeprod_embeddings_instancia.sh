#!/usr/bin/env bash
# Genera embeddings pgvector en la instancia actual (Reicol o Romulo).
# Uso en VPS:
#   cd /opt/cotiz-reicol && bash scripts/backfill_maeprod_embeddings_instancia.sh
#   cd /opt/cotiz-romulo && bash scripts/backfill_maeprod_embeddings_instancia.sh
#
# Ambas instancias: scripts/vps-backfill-maeprod-embeddings.sh
set -euo pipefail

COMPOSE=(docker compose --env-file .env.prod -f "${COMPOSE_FILE:-docker-compose.prod.yml}")
LIMIT="${LIMIT:-0}"
SLEEP_MS="${SLEEP_MS:-150}"
MODE="${MODE:-queue}"

echo "==> Migración (extensión vector + columnas maeprod)"
"${COMPOSE[@]}" exec -T app php artisan migrate --force
"${COMPOSE[@]}" exec -T app php artisan config:clear

if [[ "$MODE" == "sync" ]]; then
  echo "==> Backfill en consola (lotes; puede tardar horas)"
  "${COMPOSE[@]}" exec -T app php artisan cotiz:maeprod-embeddings --missing --limit="${LIMIT}" --sleep-ms="${SLEEP_MS}"
else
  echo "==> Backfill masivo en cola (worker en app)"
  "${COMPOSE[@]}" exec -T app php artisan cotiz:maeprod-embeddings --queue
  echo "==> Consola manual:"
  echo "    MODE=sync LIMIT=$LIMIT SLEEP_MS=$SLEEP_MS bash scripts/backfill_maeprod_embeddings_instancia.sh"
fi

echo "==> Listo."
