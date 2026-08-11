#!/bin/bash
# Respaldo pg_dump de todas las bases del VPS → Cloudflare R2 (bucket romulo-bases).
# Requiere: docker, awscli, BACKUP_CONFIG con credenciales R2 y BACKUP_TARGETS.
#
# Uso en VPS:
#   cp scripts/backup-r2.env.example /etc/cotiz-backup/backup-r2.env
#   nano /etc/cotiz-backup/backup-r2.env
#   chmod 600 /etc/cotiz-backup/backup-r2.env
#   BACKUP_CONFIG=/etc/cotiz-backup/backup-r2.env bash scripts/backup-all-dbs-to-r2.sh
#
# Cron (3:00 AM):
#   0 3 * * * BACKUP_CONFIG=/etc/cotiz-backup/backup-r2.env /opt/cotiz-romulo/scripts/backup-all-dbs-to-r2.sh >> /var/log/pg-backup-r2.log 2>&1
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BACKUP_CONFIG="${BACKUP_CONFIG:-}"

if [ -z "$BACKUP_CONFIG" ]; then
  if [ -f "$SCRIPT_DIR/backup-r2.env" ]; then
    BACKUP_CONFIG="$SCRIPT_DIR/backup-r2.env"
  elif [ -f /etc/cotiz-backup/backup-r2.env ]; then
    BACKUP_CONFIG=/etc/cotiz-backup/backup-r2.env
  elif [ -f /root/.backup-r2.env ]; then
    BACKUP_CONFIG=/root/.backup-r2.env
  else
    echo "Define BACKUP_CONFIG o copia scripts/backup-r2.env.example a /etc/cotiz-backup/backup-r2.env" >&2
    exit 1
  fi
fi

if [ ! -f "$BACKUP_CONFIG" ]; then
  echo "No existe BACKUP_CONFIG: $BACKUP_CONFIG" >&2
  exit 1
fi

# shellcheck disable=SC1090
set -a
source "$BACKUP_CONFIG"
set +a

: "${R2_BACKUP_BUCKET:?Define R2_BACKUP_BUCKET en $BACKUP_CONFIG}"
: "${R2_ENDPOINT:?Define R2_ENDPOINT en $BACKUP_CONFIG}"
: "${R2_ACCESS_KEY_ID:?Define R2_ACCESS_KEY_ID en $BACKUP_CONFIG}"
: "${R2_SECRET_ACCESS_KEY:?Define R2_SECRET_ACCESS_KEY en $BACKUP_CONFIG}"

LOCAL_BACKUP_DIR="${LOCAL_BACKUP_DIR:-/var/backups/postgres}"
LOCAL_RETENTION_DAYS="${LOCAL_RETENTION_DAYS:-3}"
KEEP_LOCAL_AFTER_UPLOAD="${KEEP_LOCAL_AFTER_UPLOAD:-false}"

if ! command -v aws >/dev/null 2>&1; then
  echo "aws CLI no encontrado. En Debian/Ubuntu: apt install awscli" >&2
  exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "docker no encontrado." >&2
  exit 1
fi

if [ "${#BACKUP_TARGETS[@]}" -eq 0 ]; then
  echo "BACKUP_TARGETS vacío en $BACKUP_CONFIG" >&2
  exit 1
fi

export AWS_ACCESS_KEY_ID="$R2_ACCESS_KEY_ID"
export AWS_SECRET_ACCESS_KEY="$R2_SECRET_ACCESS_KEY"
export AWS_DEFAULT_REGION="${R2_REGION:-auto}"

mkdir -p "$LOCAL_BACKUP_DIR"
TIMESTAMP="$(date +%Y-%m-%d_%H%M)"
FAILED=0

backup_one() {
  local spec="$1"
  local app_dir backup_name pg_user pg_db compose_file env_file container local_file r2_key

  IFS='|' read -r app_dir backup_name pg_user pg_db compose_file <<< "$spec"
  compose_file="${compose_file:-docker-compose.prod.yml}"
  env_file=".env.prod"

  if [ ! -d "$app_dir" ]; then
    echo "[SKIP] $backup_name: no existe $app_dir" >&2
    return 1
  fi

  if [ ! -f "$app_dir/$env_file" ]; then
    echo "[SKIP] $backup_name: falta $app_dir/$env_file" >&2
    return 1
  fi

  # shellcheck disable=SC1090
  set -a
  # POSTGRES_PASSWORD puede venir del .env.prod de cada instancia
  source "$app_dir/$env_file"
  set +a

  local pg_password="${POSTGRES_PASSWORD:-${DB_PASSWORD:-}}"
  if [ -z "$pg_password" ]; then
    echo "[FAIL] $backup_name: POSTGRES_PASSWORD/DB_PASSWORD vacío en $app_dir/$env_file" >&2
    return 1
  fi

  container="$(docker compose --env-file "$app_dir/$env_file" -f "$app_dir/$compose_file" ps -q postgres 2>/dev/null || true)"
  if [ -z "$container" ]; then
    echo "[FAIL] $backup_name: contenedor postgres no corre en $app_dir" >&2
    return 1
  fi

  local_file="$LOCAL_BACKUP_DIR/${backup_name}_${TIMESTAMP}.sql.gz"
  r2_key="${backup_name}/${backup_name}_${TIMESTAMP}.sql.gz"

  echo "[DUMP] $backup_name ($pg_db) → $local_file"
  docker exec -e PGPASSWORD="$pg_password" "$container" \
    pg_dump -U "$pg_user" -d "$pg_db" --no-owner --no-acl \
    | gzip > "$local_file"

  echo "[UPLOAD] s3://${R2_BACKUP_BUCKET}/${r2_key}"
  aws s3 cp "$local_file" "s3://${R2_BACKUP_BUCKET}/${r2_key}" \
    --endpoint-url "$R2_ENDPOINT"

  if [ "$KEEP_LOCAL_AFTER_UPLOAD" != "true" ]; then
    rm -f "$local_file"
  fi

  echo "[OK] $backup_name"
}

echo "=== Backup R2 $(date -Iseconds) bucket=${R2_BACKUP_BUCKET} ==="

for spec in "${BACKUP_TARGETS[@]}"; do
  if ! backup_one "$spec"; then
    FAILED=1
  fi
done

if [ "$KEEP_LOCAL_AFTER_UPLOAD" = "true" ] && [ -d "$LOCAL_BACKUP_DIR" ]; then
  find "$LOCAL_BACKUP_DIR" -name '*.sql.gz' -mtime +"$LOCAL_RETENTION_DAYS" -delete 2>/dev/null || true
fi

if [ "$FAILED" -ne 0 ]; then
  echo "=== Backup terminado con errores ===" >&2
  exit 1
fi

echo "=== Backup completado OK ==="
