# Desplegar Cotiz en Hetzner (Docker + CI/CD) — Rómulo + Reicol

Dos instancias en el **mismo VPS** que `carro`. Misma imagen (`Dockerfile` + `docker-compose.prod.yml`).

## ¿Render sigue funcionando durante la migración?

**Sí.** Hasta que cambies el DNS de `cotiza.romulo.cl` y `cotiza.reicol.cl` al VPS, **Render sigue siendo producción**. Montas Hetzner en paralelo, migras datos, pruebas por IP o `/etc/hosts`, y cortas tráfico cuando estés listo. No hay downtime obligatorio.

```text
Fase 1 (paralelo)     Render (prod)  ← usuarios
                      Hetzner (prep) ← pruebas internas

Fase 2 (corte)        DNS → Hetzner   ← usuarios
                      Render apagado cuando confirmes
```

## Arquitectura en el VPS

```text
[Internet]
    ├─ tienda.romulo.cl   → Nginx → 127.0.0.1:8000  (carro)
    ├─ cotiza.romulo.cl   → Nginx → 127.0.0.1:8001  (cotiz Rómulo)
    └─ cotiza.reicol.cl   → Nginx → 127.0.0.1:8002  (cotiz Reicol)
         └── cada una: [Docker app] + [Docker postgres]
```

| Instancia | Carpeta VPS | `APP_PORT` | `POSTGRES_PORT` |
|-----------|-------------|------------|-----------------|
| Rómulo | `/opt/cotiz-romulo` | `8001` | `5433` |
| Reicol | `/opt/cotiz-reicol` | `8002` | `5434` |

## 1. Bootstrap en el VPS (una vez por sitio)

```bash
ssh root@TU_IP

# Rómulo
APP_DIR=/opt/cotiz-romulo bash scripts/hetzner-bootstrap.sh https://github.com/cling0209/cotiz.git
nano /opt/cotiz-romulo/.env.prod   # pegar variables (ver abajo)

# Reicol
APP_DIR=/opt/cotiz-reicol bash scripts/hetzner-bootstrap.sh https://github.com/cling0209/cotiz.git
nano /opt/cotiz-reicol/.env.prod
```

Plantilla: **`.env.hetzner.example`**. Puedes partir de tus archivos locales `cotiz-romulo.env` / `cotiz-reicol.env` adaptando BD y puertos.

Desde Git Bash en tu PC (genera `.env.prod` listo para subir al VPS):

```bash
bash scripts/patch-env-for-hetzner.sh romulo ../cotiz-romulo.env > cotiz-romulo.env.prod
bash scripts/patch-env-for-hetzner.sh reicol ../cotiz-reicol.env > cotiz-reicol.env.prod
```

Copia cada archivo al VPS como `/opt/cotiz-romulo/.env.prod` y `/opt/cotiz-reicol/.env.prod`.

### Cambios obligatorios al pasar de Render a Hetzner

| Variable Render | En Hetzner |
|-----------------|------------|
| `DB_HOST=dpg-xxx-a` | `DB_HOST=postgres` |
| — | `POSTGRES_*` = mismos user/pass/db que `DB_*` |
| — | `APP_PORT` / `POSTGRES_PORT` (tabla arriba) |
| `RENDER_KEEPALIVE*` | **Eliminar** |
| `REDIS_*` | **Eliminar** (cache/sesión/cola = `database`) |
| `MAIL_HOST=mailpit` | **Resend** o SMTP real |
| `APP_DEBUG=true` | `APP_DEBUG=false` |
| — | `SESSION_SECURE_COOKIE=true` |

**Reicol:** `MERCADOPUBLICO_ANALISIS_ADMIN=false` (no `fslse` ni otro typo).

### Migrar datos desde Render Postgres

1. En `.env.prod`, añade temporalmente (credenciales actuales de Render):

```env
RENDER_SOURCE_HOST=dpg-xxxxx-a.oregon-postgres.render.com
RENDER_SOURCE_DB=romulo
RENDER_SOURCE_USER=romulo
RENDER_SOURCE_PASSWORD=...
```

2. Levanta solo Postgres local y restaura:

```bash
cd /opt/cotiz-romulo
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d postgres
bash scripts/render-dump.sh
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --build
```

3. Repite en `/opt/cotiz-reicol` con sus credenciales Render.
4. Borra `RENDER_SOURCE_*` de `.env.prod` tras el dump.

Render **sigue sirviendo** mientras haces esto; el dump es lectura.

### Nginx en el host

Configs listas en **`deploy/nginx/`** (las 3 apps):

```bash
cd /opt/cotiz-romulo
bash deploy/nginx/install-on-vps.sh
certbot --nginx -d cotiza.romulo.cl -d cotiza.reicol.cl
# carro si falta TLS: certbot --nginx -d tienda.romulo.cl
```

Ver **`deploy/nginx/README.md`**.

### Probar antes del corte DNS

En tu PC (Windows, como admin):

```text
46.224.20.162  cotiza.romulo.cl
46.224.20.162  cotiza.reicol.cl
```

Archivo: `C:\Windows\System32\drivers\etc\hosts`. Render sigue activo para el resto del mundo.

## 2. CI/CD — GitHub Actions

Cada **push a `main`** ejecuta `.github/workflows/hetzner-deploy.yml`:

1. SSH al VPS
2. `git pull` en `/opt/cotiz-romulo` y `/opt/cotiz-reicol`
3. `docker compose ... up -d --build app` en cada una
4. Health check en `/up` (puerto de cada `.env.prod`)

### Secrets en GitHub (repo `cotiz`)

Mismos que `carro` si es el mismo VPS:

| Secret | Valor |
|--------|-------|
| `VPS_HOST` | IP del VPS |
| `VPS_USER` | Usuario SSH |
| `VPS_SSH_KEY` | Clave privada deploy |
| `VPS_PORT` | `22` (opcional) |

Ver **carro/HETZNER.md** sección clave SSH si hay errores `no key found`.

Deploy manual:

```bash
/opt/cotiz-romulo/scripts/deploy-prod.sh
/opt/cotiz-reicol/scripts/deploy-prod.sh
```

## 3. Sync Romulo ↔ Reicol

Las URLs cruzadas (`COTIZ_API_USUARIO_URL`, etc.) deben seguir apuntando a los dominios públicos. Durante la migración:

- Si **solo uno** está en Hetzner, el otro en Render: deja las URLs como están (dominio → donde apunte DNS).
- Tras **ambos** en Hetzner: mismas URLs, sin cambio si conservas `cotiza.romulo.cl` / `cotiza.reicol.cl`.

## 4. Corte a producción (cuando Hetzner esté OK)

1. `curl -sf http://127.0.0.1:8001/up` y `:8002/up` en el VPS
2. Probar login admin en ambos sitios (hosts file o DNS de prueba)
3. Cambiar DNS A/AAAA de ambos dominios → IP del VPS
4. Esperar propagación; verificar cotizaciones y sync al par
5. Opcional: pausar servicios Render para no duplicar schedulers (MP, colas)

**Importante:** si Romulo y Reicol corren **a la vez** en Render y Hetzner con el mismo ticket MP y mismos schedulers, podrías duplicar consultas. Durante la prueba paralela usa hosts file; al cortar DNS, apaga Render.

## 5. Comandos útiles

```bash
cd /opt/cotiz-romulo
docker compose --env-file .env.prod -f docker-compose.prod.yml ps
docker compose --env-file .env.prod -f docker-compose.prod.yml logs -f app
docker stats
```

## 6. RAM del VPS

Con `carro` + 2× `cotiz` ≈ **6 contenedores** (3 app + 3 postgres). En **CX23 (4 GB)** puede ir justo; vigila `docker stats`. Considera **CX33 (8 GB)** si hay imports pesados o OCR concurrente.
