#!/bin/bash
# Configuración idempotente en el VPS: awscli, /etc/cotiz-backup/backup-r2.env y cron 3:00 AM.
#
# Uso manual (root o sudo):
#   export R2_BACKUP_ACCESS_KEY_ID=... R2_BACKUP_SECRET_ACCESS_KEY=... R2_BACKUP_ENDPOINT=...
#   bash /opt/cotiz-romulo/scripts/setup-vps-backup-r2.sh
#
# Variables opcionales:
#   BACKUP_CONFIG     default /etc/cotiz-backup/backup-r2.env
#   BACKUP_SCRIPT     default /opt/cotiz-romulo/scripts/backup-all-dbs-to-r2.sh
#   CRON_HOUR         default 3 (hora del sistema VPS)
#   R2_BACKUP_BUCKET  default romulo-bases
#   FORCE_BACKUP_CONFIG=1  sobrescribe backup-r2.env si ya existe
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BACKUP_CONFIG="${BACKUP_CONFIG:-/etc/cotiz-backup/backup-r2.env}"
BACKUP_SCRIPT="${BACKUP_SCRIPT:-/opt/cotiz-romulo/scripts/backup-all-dbs-to-r2.sh}"
CRON_HOUR="${CRON_HOUR:-3}"
R2_BACKUP_BUCKET="${R2_BACKUP_BUCKET:-romulo-bases}"
LOG_FILE="${BACKUP_LOG_FILE:-/var/log/pg-backup-r2.log}"

install_awscli() {
  if command -v aws >/dev/null 2>&1; then
    echo "[OK] awscli: $(aws --version 2>&1 | head -1)"
    return 0
  fi
  echo "[INSTALL] awscli..."
  if command -v apt-get >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq awscli
  else
    echo "Instala awscli manualmente (apt install awscli)" >&2
    exit 1
  fi
}

detect_r2_endpoint_from_prod() {
  local env_file="/opt/cotiz-romulo/.env.prod"
  if [ -f "$env_file" ]; then
    # shellcheck disable=SC1090
    set -a
    source "$env_file"
    set +a
    if [ -n "${R2_ENDPOINT:-}" ]; then
      echo "$R2_ENDPOINT"
      return 0
    fi
  fi
  return 1
}

write_backup_config() {
  mkdir -p "$(dirname "$BACKUP_CONFIG")"

  if [ -f "$BACKUP_CONFIG" ] && [ "${FORCE_BACKUP_CONFIG:-}" != "1" ]; then
    echo "[OK] Config existente: $BACKUP_CONFIG (usa FORCE_BACKUP_CONFIG=1 para sobrescribir)"
    return 0
  fi

  local endpoint="${R2_BACKUP_ENDPOINT:-${R2_ENDPOINT:-}}"
  if [ -z "$endpoint" ]; then
    endpoint="$(detect_r2_endpoint_from_prod 2>/dev/null || true)"
  fi

  local access_key="${R2_BACKUP_ACCESS_KEY_ID:-}"
  local secret_key="${R2_BACKUP_SECRET_ACCESS_KEY:-}"

  if [ -z "$access_key" ] || [ -z "$secret_key" ] || [ -z "$endpoint" ]; then
    if [ ! -f "$BACKUP_CONFIG" ]; then
      cp "$SCRIPT_DIR/backup-r2.env.example" "$BACKUP_CONFIG"
      chmod 600 "$BACKUP_CONFIG"
      echo "[WARN] Completa credenciales en $BACKUP_CONFIG (R2 token del bucket romulo-bases)"
    fi
    return 0
  fi

  cat > "$BACKUP_CONFIG" <<EOF
# Generado por setup-vps-backup-r2.sh — no commitear
R2_BACKUP_BUCKET=${R2_BACKUP_BUCKET}
R2_ENDPOINT=${endpoint}
R2_ACCESS_KEY_ID=${access_key}
R2_SECRET_ACCESS_KEY=${secret_key}

LOCAL_BACKUP_DIR=/var/backups/postgres
LOCAL_RETENTION_DAYS=3
KEEP_LOCAL_AFTER_UPLOAD=false

BACKUP_TARGETS=(
  "/opt/carro|carro|carro|carro"
  "/opt/cotiz-romulo|romulo|romulo|romulo"
  "/opt/cotiz-reicol|reicol|reicol|reicol"
)
EOF
  chmod 600 "$BACKUP_CONFIG"
  echo "[OK] Escrito $BACKUP_CONFIG"
}

install_cron() {
  if [ ! -f "$BACKUP_SCRIPT" ]; then
    echo "[WARN] No existe $BACKUP_SCRIPT — cron omitido hasta el próximo deploy" >&2
    return 0
  fi

  chmod +x "$BACKUP_SCRIPT" 2>/dev/null || true
  [ -f "$SCRIPT_DIR/restore-db-from-r2.sh" ] && chmod +x "$SCRIPT_DIR/restore-db-from-r2.sh" 2>/dev/null || true

  local cron_line="0 ${CRON_HOUR} * * * BACKUP_CONFIG=${BACKUP_CONFIG} ${BACKUP_SCRIPT} >> ${LOG_FILE} 2>&1"

  local current
  current="$(crontab -l 2>/dev/null || true)"
  if echo "$current" | grep -Fq 'backup-all-dbs-to-r2.sh'; then
    current="$(echo "$current" | grep -Fv 'backup-all-dbs-to-r2.sh' || true)"
  fi
  {
    echo "$current" | sed '/^[[:space:]]*$/d'
    echo "$cron_line"
  } | crontab -

  echo "[OK] Cron instalado: ${CRON_HOUR}:00 diario → ${LOG_FILE}"
  crontab -l | grep backup-all-dbs-to-r2 || true
}

config_ready() {
  if [ ! -f "$BACKUP_CONFIG" ]; then
    return 1
  fi
  # shellcheck disable=SC1090
  set -a
  source "$BACKUP_CONFIG"
  set +a
  [ -n "${R2_ACCESS_KEY_ID:-}" ] && [ -n "${R2_SECRET_ACCESS_KEY:-}" ] && [ -n "${R2_ENDPOINT:-}" ]
}

run_test_backup() {
  if [ "${RUN_BACKUP_NOW:-}" != "1" ]; then
    return 0
  fi
  if ! config_ready; then
    echo "[WARN] RUN_BACKUP_NOW=1 pero faltan credenciales en $BACKUP_CONFIG" >&2
    return 0
  fi
  echo "[RUN] Backup de prueba..."
  BACKUP_CONFIG="$BACKUP_CONFIG" bash "$BACKUP_SCRIPT"
}

echo "=== setup-vps-backup-r2 $(date -Iseconds) ==="
install_awscli
write_backup_config
install_cron
run_test_backup
echo "=== setup completado ==="
