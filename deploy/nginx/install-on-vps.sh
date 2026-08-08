#!/bin/bash
# Instalar Nginx host en VPS Hetzner (46.224.20.162) — carro + cotiz × 2
# Ejecutar en el VPS como root, desde /opt/cotiz-romulo o /opt/cotiz-reicol (repo clonado):
#   bash deploy/nginx/install-on-vps.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SNIPPET_DST="/etc/nginx/snippets/laravel-proxy.conf"
SITES_AVAILABLE="/etc/nginx/sites-available"
SITES_ENABLED="/etc/nginx/sites-enabled"

if [ "$(id -u)" -ne 0 ]; then
  echo "Ejecutar como root (sudo bash $0)" >&2
  exit 1
fi

if ! command -v nginx >/dev/null 2>&1; then
  apt-get update
  apt-get install -y nginx
fi

mkdir -p /etc/nginx/snippets
cp "$SCRIPT_DIR/snippets/laravel-proxy.conf" "$SNIPPET_DST"

install_site() {
  local name="$1"
  local src="$SCRIPT_DIR/${name}.conf"
  if [ ! -f "$src" ]; then
    echo "No encontrado: $src" >&2
    return 1
  fi
  if [ -f "$SITES_AVAILABLE/${name}.conf" ] && [ "$name" = "tienda.romulo.cl" ]; then
    echo "Omitiendo $name — ya existe en sites-available (revisar manualmente)"
    return 0
  fi
  cp "$src" "$SITES_AVAILABLE/${name}.conf"
  ln -sf "$SITES_AVAILABLE/${name}.conf" "$SITES_ENABLED/${name}.conf"
  echo "Instalado: $name"
}

install_site tienda.romulo.cl
install_site cotiza.romulo.cl
install_site cotiza.reicol.cl

nginx -t
systemctl enable nginx
systemctl reload nginx

echo ""
echo "Nginx OK (HTTP :80). TLS con Certbot:"
echo "  certbot --nginx -d tienda.romulo.cl"
echo "  certbot --nginx -d cotiza.romulo.cl"
echo "  certbot --nginx -d cotiza.reicol.cl"
echo ""
echo "O todos de una vez si los DNS ya apuntan al VPS:"
echo "  certbot --nginx -d tienda.romulo.cl -d cotiza.romulo.cl -d cotiza.reicol.cl"
