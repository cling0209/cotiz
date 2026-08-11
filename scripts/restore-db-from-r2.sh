#!/bin/bash
# Restaurar un dump desde R2 al Postgres Docker de una instancia cotiz/carro.
#
# Uso:
#   BACKUP_CONFIG=/etc/cotiz-backup/backup-r2.env \
#   bash scripts/restore-db-from-r2.sh romulo /opt/cotiz-romulo romulo/romulo_2026-08-10_0300.sql.gz
#
# El tercer argumento es la clave dentro del bucket (carpeta/archivo.sql.gz).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BACKUP_NAME="${1:-}"
APP_DIR="${2:-}"
R2_OBJECT_KEY="${3:-}"
BACKUP_CONFIG="${BACKUP_CONFIG:-}"

if [ -z "$BACKUP_NAME" ] || [ -z "$APP_DIR" ] || [ -z "$R2_OBJECT_KEY" ]; then
  echo "Uso: BACKUP_CONFIG=... $0 <nombre> <app_dir> <clave_r2>" >&2
  echo "Ej:  $0 romulo /opt/cotiz-romulo romulo/romulo_2026-08-10_0300.sql.gz" >&2
  exit 1
fi

if [ -z "$BACKUP_CONFIG" ]; then
  if [ -f "$SCRIPT_DIR/backup-r2.env" ]; then
    BACKUP_CONFIG="$SCRIPT_DIR/backup-r2.env"
  elif [ -f /etc/cotiz-backup/backup-r2.env ]; then
    BACKUP_CONFIG=/etc/cotiz-backup/backup-r2.env
  else
    echo "Define BACKUP_CONFIG" >&2
    exit 1
  fi
fi

# shellcheck disable=SC1090
set -a
source "$BACKUP_CONFIG"
set +a

: "${R2_BACKUP_BUCKET:?}"
: "${R2_ENDPOINT:?}"
: "${R2_ACCESS_KEY_ID:?}"
: "${R2_SECRET_ACCESS_KEY:?}"

ENV_FILE="$APP_DIR/.env.prod"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
LOCAL_FILE="/tmp/${BACKUP_NAME}_restore.sql.gz"

export AWS_ACCESS_KEY_ID="$R2_ACCESS_KEY_ID"
export AWS_SECRET_ACCESS_KEY="$R2_SECRET_ACCESS_KEY"
export AWS_DEFAULT_REGION="${R2_REGION:-auto}"

if [ ! -f "$ENV_FILE" ]; then
  echo "No existe $ENV_FILE" >&2
  exit 1
fi

# shellcheck disable=SC1090
set -a
source "$ENV_FILE"
set +a

PG_USER="${POSTGRES_USER:-${DB_USERNAME:-$BACKUP_NAME}}"
PG_DB="${POSTGRES_DB:-${DB_DATABASE:-$BACKUP_NAME}}"
PG_PASSWORD="${POSTGRES_PASSWORD:-${DB_PASSWORD:-}}"

container="$(docker compose --env-file "$ENV_FILE" -f "$APP_DIR/$COMPOSE_FILE" ps -q postgres)"
if [ -z "$container" ]; then
  echo "Postgres no corre en $APP_DIR" >&2
  exit 1
fi

echo "Descargando s3://${R2_BACKUP_BUCKET}/${R2_OBJECT_KEY} ..."
aws s3 cp "s3://${R2_BACKUP_BUCKET}/${R2_OBJECT_KEY}" "$LOCAL_FILE" \
  --endpoint-url "$R2_ENDPOINT"

echo "Restaurando en $PG_DB ($APP_DIR) ..."
gunzip -c "$LOCAL_FILE" \
  | docker exec -i -e PGPASSWORD="$PG_PASSWORD" "$container" \
    psql -U "$PG_USER" -d "$PG_DB" -v ON_ERROR_STOP=1

rm -f "$LOCAL_FILE"
echo "Restore OK: $BACKUP_NAME"
