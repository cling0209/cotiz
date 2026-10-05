#!/usr/bin/env bash
# Un solo contenedor de embeddings en el VPS (Reicol + Rómulo lo comparten).
# Ejecutar en el VPS:
#   bash /opt/cotiz-reicol/scripts/vps-start-embeddings.sh
set -euo pipefail

DIR="${COTIZ_EMBEDDINGS_DIR:-/opt/cotiz-reicol}"
PORT="${COTIZ_EMBEDDINGS_PORT:-8091}"

if [[ ! -f "$DIR/docker-compose.embeddings.yml" ]]; then
  echo "No existe $DIR/docker-compose.embeddings.yml" >&2
  exit 1
fi

cd "$DIR"
docker compose -f docker-compose.embeddings.yml up -d --build

echo "Esperando health en 127.0.0.1:${PORT}…"
for i in $(seq 1 60); do
  if curl -sf "http://127.0.0.1:${PORT}/health" | grep -q '"ok":true'; then
    curl -s "http://127.0.0.1:${PORT}/health"
    echo ""
    echo "Embeddings listo. En .env.prod de cada instancia:"
    echo "  COTIZ_EMBEDDING_PROVIDER=local"
    echo "  COTIZ_EMBEDDING_URL=http://host.docker.internal:${PORT}"
    echo "  COTIZ_EMBEDDING_DIMENSION=384"
    echo "  COTIZ_EMBEDDING_MODEL=intfloat/multilingual-e5-small"
    echo "  COTIZ_EMBEDDING_BACKFILL_SLEEP_MS=0"
    exit 0
  fi
  sleep 3
done

echo "Timeout: revisar logs con docker compose -f docker-compose.embeddings.yml logs" >&2
exit 1
