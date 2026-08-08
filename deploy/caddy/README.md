# Caddy (recomendado si carro ya usa Caddy en el VPS)

En `46.224.20.162` el proxy del host es **Caddy**, no Nginx (puerto 80/443).

```bash
cp deploy/caddy/Caddyfile /etc/caddy/Caddyfile
caddy validate --config /etc/caddy/Caddyfile
systemctl reload caddy
```

Caddy obtiene certificados Let's Encrypt automáticamente cuando el DNS apunta al VPS.

## Verificar

```bash
curl -I https://cotiza.romulo.cl/up
curl -I https://cotiza.reicol.cl/up
```

Si el DNS aún apunta a Render, prueba con `curl -H "Host: cotiza.romulo.cl" http://127.0.0.1/up` en el VPS o edita `hosts` en tu PC.

## Alternativa: Nginx

Si el VPS **no** tiene Caddy, usa `deploy/nginx/` (ver `deploy/nginx/README.md`).
