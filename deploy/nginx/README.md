# Nginx en el VPS (host)

Proxy TLS/HTTP del host hacia contenedores Docker en `127.0.0.1`.

| Dominio | App | Puerto Docker |
|---------|-----|---------------|
| `tienda.romulo.cl` | carro | `8000` |
| `cotiza.romulo.cl` | cotiz Rómulo | `8001` |
| `cotiza.reicol.cl` | cotiz Reicol | `8002` |

## Instalación (en el VPS)

```bash
cd /opt/cotiz-romulo   # cualquier clone del repo con deploy/nginx/
bash deploy/nginx/install-on-vps.sh
```

Si `tienda.romulo.cl` ya está configurado, el script lo omite.

## Certbot (HTTPS)

DNS debe apuntar a `46.224.20.162` (o usar `--dry-run` para probar):

```bash
apt-get install -y certbot python3-certbot-nginx
certbot --nginx -d cotiza.romulo.cl -d cotiza.reicol.cl
# carro si aún no tiene cert:
certbot --nginx -d tienda.romulo.cl
```

Renovación automática: `certbot renew` (timer systemd).

## Verificar

```bash
curl -sf http://127.0.0.1:8000/up   # carro
curl -sf http://127.0.0.1:8001/up   # cotiz Rómulo
curl -sf http://127.0.0.1:8002/up   # cotiz Reicol
curl -I -H "Host: cotiza.romulo.cl" http://127.0.0.1/up
```
