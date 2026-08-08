#!/bin/bash
# Ejecutar en el VPS dentro de /opt/cotiz-romulo o /opt/cotiz-reicol
set -euo pipefail
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
git config --global --add safe.directory "$APP_DIR"
cd "$APP_DIR"
git fetch origin main
git reset --hard origin/main
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --build app
docker image prune -f
APP_PORT="$(grep -E '^APP_PORT=' .env.prod | cut -d= -f2- | tr -d ' "' || true)"
APP_PORT="${APP_PORT:-8001}"
curl -sf "http://127.0.0.1:${APP_PORT}/up" > /dev/null
echo "Deploy OK (${APP_DIR} → puerto ${APP_PORT})"
