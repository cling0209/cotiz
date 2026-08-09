#!/bin/bash
# Restaura cotiz-romulo y cotiz-reicol desde Render Postgres al VPS local.
set -euo pipefail

migrate_one() {
  local dir="$1"
  local name="$2"
  echo "========== Migrating $name from Render =========="
  cd "$dir"
  chmod +x scripts/render-dump.sh
  docker compose --env-file .env.prod -f docker-compose.prod.yml stop app paddleocr 2>/dev/null || true
  docker compose --env-file .env.prod -f docker-compose.prod.yml up -d postgres
  bash scripts/render-dump.sh
  docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --build app paddleocr
  echo "========== $name restore complete =========="
}

migrate_one /opt/cotiz-romulo "cotiz-romulo"
migrate_one /opt/cotiz-reicol "cotiz-reicol"

echo "Done. Both databases restored from Render."
