#!/usr/bin/env bash
# VPS Hetzner: llenar maeprod.prod_embedding (pgvector) en Reicol y/o Rómulo.
# Por defecto: contenedor local (multilingual-e5-small). Alternativa: COTIZ_EMBEDDING_PROVIDER=gemini.
# Antes: bash scripts/vps-start-embeddings.sh
#
# Ejecutar EN EL VPS (como root o con docker):
#   bash /opt/cotiz-romulo/scripts/vps-backfill-maeprod-embeddings.sh
#   bash /opt/cotiz-romulo/scripts/vps-backfill-maeprod-embeddings.sh reicol
#   bash /opt/cotiz-romulo/scripts/vps-backfill-maeprod-embeddings.sh status
#
# Modo consola (sin cola; útil si RUN_QUEUE_WORKER=false). En segundo plano:
#   MODE=sync nohup bash /opt/cotiz-romulo/scripts/vps-backfill-maeprod-embeddings.sh romulo \
#     >> /var/log/cotiz-embeddings-romulo.log 2>&1 &
#
# Variables:
#   MODE=queue|sync|status   (default queue)
#   BATCH=150                lotes en modo sync
#   SLEEP_MS=200             pausa entre SKUs (sync)
#   LOG=/var/log/cotiz-embeddings.log
set -euo pipefail

MODE="${MODE:-queue}"
BATCH="${BATCH:-150}"
SLEEP_MS="${SLEEP_MS:-0}"
LOG="${LOG:-/var/log/cotiz-embeddings.log}"

declare -A SITE_DIR=(
  [romulo]="/opt/cotiz-romulo"
  [reicol]="/opt/cotiz-reicol"
)
declare -A SITE_DB=(
  [romulo]="romulo"
  [reicol]="reicol"
)

usage() {
  echo "Uso: $0 [romulo|reicol|both|status]" >&2
  echo "  MODE=queue (default) encola MaeprodEmbeddingsBackfillJob (requiere worker en app)." >&2
  echo "  MODE=sync procesa en consola por lotes hasta agotar pendientes." >&2
  exit 1
}

sites_to_run() {
  local arg="${1:-both}"
  case "$arg" in
    romulo|reicol) echo "$arg" ;;
    both|"") printf '%s\n' reicol romulo ;;
    status) echo "__status__" ;;
    -h|--help) usage ;;
    *) echo "Sitio desconocido: $arg" >&2; usage ;;
  esac
}

compose_cmd() {
  local dir="$1"
  shift
  (cd "$dir" && docker compose --env-file .env.prod -f docker-compose.prod.yml "$@")
}

embedding_count() {
  local site="$1"
  local dir="${SITE_DIR[$site]}"
  local db="${SITE_DB[$site]}"
  compose_cmd "$dir" exec -T postgres psql -U "$db" -d "$db" -t -A -c \
    "SELECT count(*) FROM maeprod WHERE prod_embedding IS NOT NULL;"
}

pending_estimate() {
  local site="$1"
  local dir="${SITE_DIR[$site]}"
  local db="${SITE_DB[$site]}"
  compose_cmd "$dir" exec -T postgres psql -U "$db" -d "$db" -t -A -c \
    "SELECT count(*) FROM maeprod WHERE prod_embedding IS NULL AND prod_nombre IS NOT NULL AND prod_nombre <> '';" \
    2>/dev/null | tr -d '\r\n ' || echo "?"
}

print_status() {
  for site in reicol romulo; do
    local dir="${SITE_DIR[$site]}"
    if [[ ! -d "$dir" ]]; then
      echo "[$site] no existe $dir"
      continue
    fi
    local emb
    emb="$(embedding_count "$site" | tr -d ' \r\n')"
    local pend
    pend="$(pending_estimate "$site")"
    local jobs
    jobs="$(compose_cmd "$dir" exec -T postgres psql -U "${SITE_DB[$site]}" -d "${SITE_DB[$site]}" -t -A -c \
      "SELECT count(*) FROM jobs WHERE payload LIKE '%MaeprodEmbeddingsBackfillJob%';" 2>/dev/null | tr -d '\r\n ' || echo "?")"
    echo "[$site] con embedding: ${emb:-?}  sin embedding (aprox): ${pend}  jobs backfill en cola: ${jobs}"
  done
}

run_site_queue() {
  local site="$1"
  local dir="${SITE_DIR[$site]}"
  echo "======== $site ($dir) ========"
  if [[ ! -f "$dir/.env.prod" ]]; then
    echo "Falta $dir/.env.prod" >&2
    return 1
  fi
  compose_cmd "$dir" exec -T app php artisan migrate --force
  compose_cmd "$dir" exec -T app php artisan config:clear
  compose_cmd "$dir" exec -T app php artisan cotiz:maeprod-embeddings --queue
  echo "Encolado. Asegúrate RUN_QUEUE_WORKER=true en .env.prod y contenedor app activo."
  embedding_count "$site" | awk -v s="$site" '{print "[" s "] embeddings ahora:", $0}'
}

run_site_sync() {
  local site="$1"
  local dir="${SITE_DIR[$site]}"
  echo "======== $site SYNC ($dir) ========" | tee -a "$LOG"
  compose_cmd "$dir" exec -T app php artisan migrate --force
  compose_cmd "$dir" exec -T app php artisan config:clear
  local round=0
  while true; do
    round=$((round + 1))
    local before after
    before="$(embedding_count "$site" | tr -d ' \r\n')"
    echo "[$(date -Is)] [$site] ronda $round embeddings=$before" | tee -a "$LOG"
    local out
    out="$(compose_cmd "$dir" exec -T app php artisan cotiz:maeprod-embeddings --missing \
      --limit="$BATCH" --sleep-ms="$SLEEP_MS" 2>&1)" || true
    echo "$out" | tee -a "$LOG"
    after="$(embedding_count "$site" | tr -d ' \r\n')"
    if echo "$out" | grep -q 'No hay productos pendientes'; then
      echo "[$site] Sin pendientes." | tee -a "$LOG"
      break
    fi
    if [[ "$after" == "$before" ]] && echo "$out" | grep -qE '0 OK'; then
      echo "[$site] Sin avance (¿servicio embeddings caído?). Esperando 120s…" | tee -a "$LOG"
      sleep 120
    else
      sleep 3
    fi
  done
}

main() {
  local arg="${1:-both}"
  if [[ "$MODE" == "status" ]] || [[ "$arg" == "status" ]]; then
    print_status
    exit 0
  fi

  while IFS= read -r site; do
    [[ "$site" == "__status__" ]] && continue
    case "$MODE" in
      queue) run_site_queue "$site" ;;
      sync) run_site_sync "$site" ;;
      *) echo "MODE inválido: $MODE" >&2; exit 1 ;;
    esac
  done < <(sites_to_run "$arg")

  echo "Listo. Estado:"
  print_status
}

main "${1:-both}"
