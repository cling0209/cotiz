#!/bin/bash
# Ejecutar en el VPS dentro de /opt/cotiz-romulo o /opt/cotiz-reicol
set -euo pipefail
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PROJECT_NAME="$(basename "$APP_DIR")"
APP_CONTAINER="${PROJECT_NAME}-app-1"
COMPOSE=(docker compose --env-file .env.prod -f docker-compose.prod.yml)
LOCK_FILE="/tmp/deploy-${PROJECT_NAME}.lock"

git config --global --add safe.directory "$APP_DIR"
cd "$APP_DIR"

exec 200>"$LOCK_FILE"
if ! flock -w 900 200; then
  echo "Timeout: otro deploy de ${PROJECT_NAME} no terminó en 15 minutos"
  exit 1
fi

git fetch origin main
git reset --hard origin/main

echo "Deteniendo contenedor app (${APP_CONTAINER})..."
"${COMPOSE[@]}" stop app 2>/dev/null || true
"${COMPOSE[@]}" rm -f -s app 2>/dev/null || true
docker rm -f "$APP_CONTAINER" 2>/dev/null || true
# Contenedores huérfanos de recreates fallidos (prefijo hash_)
docker ps -aq --filter "name=${PROJECT_NAME}-app" | xargs -r docker rm -f 2>/dev/null || true

echo "Levantando app..."
"${COMPOSE[@]}" up -d --build --remove-orphans --no-deps app

docker image prune -f
APP_PORT="$(grep -E '^APP_PORT=' .env.prod | cut -d= -f2- | tr -d ' "' || true)"
APP_PORT="${APP_PORT:-8001}"
echo "Esperando health check en puerto ${APP_PORT}..."
for i in $(seq 1 60); do
  if curl -sf "http://127.0.0.1:${APP_PORT}/up" > /dev/null; then
    echo "Deploy OK (${APP_DIR} → puerto ${APP_PORT})"
    exit 0
  fi
  sleep 5
done

echo "App no respondió en /up tras 5 minutos"
"${COMPOSE[@]}" logs app --tail 50
exit 1
