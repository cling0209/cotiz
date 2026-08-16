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

### Proxy en el host (Caddy)

En este VPS **carro ya usa Caddy** (`/etc/caddy/Caddyfile`), no Nginx.

```bash
cd /opt/cotiz-romulo
cp deploy/caddy/Caddyfile /etc/caddy/Caddyfile
caddy validate --config /etc/caddy/Caddyfile
systemctl reload caddy
```

Ver **`deploy/caddy/README.md`**. Alternativa Nginx: **`deploy/nginx/`** (solo si no hay Caddy).

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
3. `docker compose ... build` de app, LibreOffice y PaddleOCR; `up` de sidecars + app
4. Health check en `/up` (puerto de cada `.env.prod`)

### Secrets en GitHub (repo `cotiz`)

Los secrets **no se comparten entre repos**. Aunque `carro` ya despliegue bien, hay que crear los mismos en **github.com/cling0209/cotiz → Settings → Secrets and variables → Actions → New repository secret**.

| Secret | Valor para pegar |
|--------|------------------|
| `VPS_HOST` | Misma IP que en `carro` (ej. `46.224.20.162`) |
| `VPS_USER` | Mismo usuario que en `carro` (ej. `root`) |
| `VPS_SSH_KEY` | Clave **privada** completa (ver abajo) |
| `VPS_PORT` | `22` (opcional; solo si usas otro puerto) |
| `R2_BACKUP_ACCESS_KEY_ID` | Token R2 solo para bucket `romulo-bases` (respaldos) |
| `R2_BACKUP_SECRET_ACCESS_KEY` | Secret del token R2 backups |
| `R2_BACKUP_ENDPOINT` | Endpoint R2 (`https://….r2.cloudflarestorage.com`) |
| `R2_BACKUP_BUCKET` | `romulo-bases` (opcional) |

#### Copiar `VPS_SSH_KEY` para pegar en GitHub

**Opción A — Reutilizar la clave que ya funciona en `carro`** (recomendado):

La `.pub` correspondiente ya está en `~/.ssh/authorized_keys` del VPS. Solo copia la **privada** que usaste para `carro`.

En PowerShell (Windows), desde la carpeta donde está la clave:

```powershell
Get-Content -Raw hetzner_deploy | Set-Clipboard
```

Luego en GitHub → repo **cotiz** → Settings → Secrets → **New repository secret** → Name: `VPS_SSH_KEY` → pegar (Ctrl+V) → Save.

**Opción B — Generar clave nueva** (solo si no tienes la de carro):

```bash
ssh-keygen -t ed25519 -C "github-actions-hetzner" -f hetzner_deploy -N ""
```

1. En el VPS, añade `hetzner_deploy.pub` a `~/.ssh/authorized_keys` del usuario `VPS_USER`.
2. Copia la **privada** (`hetzner_deploy`, sin `.pub`) a GitHub secret `VPS_SSH_KEY`.

Formato correcto de la privada (debe verse así al pegar):

```text
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAAB...
(muchas líneas)
...AAAAECw==
-----END OPENSSH PRIVATE KEY-----
```

**No** pegues la `.pub` (empieza con `ssh-ed25519 AAAA...`).

Verificar en tu PC antes de guardar en GitHub:

```bash
ssh-keygen -y -f hetzner_deploy
```

Si imprime una línea `ssh-ed25519 AAAA...`, la privada es válida.

Probar SSH manual:

```bash
ssh -i hetzner_deploy VPS_USER@VPS_HOST
```

#### Checklist rápido (repo `cotiz`)

1. `VPS_HOST` → IP del VPS
2. `VPS_USER` → usuario SSH (ej. `root`)
3. `VPS_SSH_KEY` → privada con `Get-Content -Raw hetzner_deploy | Set-Clipboard`
4. (opcional) `VPS_PORT` → `22`
5. Actions → **Deploy to Hetzner** → **Run workflow**

Errores habituales: ver **carro/HETZNER.md** (`ssh: no key found`, clave en una sola línea, passphrase, `.pub` en lugar de privada).

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

## 7. Respaldo de bases → Cloudflare R2

Respaldos diarios de **carro**, **romulo** y **reicol** al bucket privado **`romulo-bases`**, con nombre `{base}_{fecha}.sql.gz` en la carpeta `{base}/`.

### 7.1 Cloudflare

1. R2 → crear bucket **`romulo-bases`** (privado, sin dominio público).
2. Crear API token R2 con lectura/escritura **solo** en ese bucket (no reutilizar el de imágenes).
3. Anotar `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY` y `R2_ENDPOINT`.

### 7.2 Configuración en el VPS (automática o manual)

**Automática (recomendada):** en GitHub → repo **cotiz** → Settings → Secrets → Actions, agrega:

| Secret | Valor |
|--------|--------|
| `R2_BACKUP_ACCESS_KEY_ID` | Access key del token R2 (bucket `romulo-bases`) |
| `R2_BACKUP_SECRET_ACCESS_KEY` | Secret key |
| `R2_BACKUP_ENDPOINT` | `https://ACCOUNT_ID.r2.cloudflarestorage.com` |
| `R2_BACKUP_BUCKET` | `romulo-bases` (opcional; default en script) |

Opcional en **Variables** → `BACKUP_CRON_HOUR` = `3` (hora del VPS, default 3 AM).

Cada deploy (`hetzner-deploy.yml`) ejecuta `scripts/setup-vps-backup-r2.sh`: instala `awscli`, escribe `/etc/cotiz-backup/backup-r2.env` y registra el cron.

**Manual (una vez):**

```bash
apt install -y awscli

export R2_BACKUP_ACCESS_KEY_ID=...
export R2_BACKUP_SECRET_ACCESS_KEY=...
export R2_BACKUP_ENDPOINT=https://ACCOUNT_ID.r2.cloudflarestorage.com
bash /opt/cotiz-romulo/scripts/setup-vps-backup-r2.sh

# Probar backup inmediato:
RUN_BACKUP_NOW=1 bash /opt/cotiz-romulo/scripts/setup-vps-backup-r2.sh
```

Si no hay secrets en GitHub, el setup deja plantilla en `/etc/cotiz-backup/backup-r2.env` para completar con `nano`.

Plantilla: **`scripts/backup-r2.env.example`**. Ajusta `BACKUP_TARGETS` si **carro** usa otro usuario/BD o ruta distinta a `/opt/carro`.

### 7.3 Ejecutar manualmente

```bash
BACKUP_CONFIG=/etc/cotiz-backup/backup-r2.env \
  bash /opt/cotiz-romulo/scripts/backup-all-dbs-to-r2.sh
```

Archivos en R2:

```text
romulo-bases/carro/carro_2026-08-10_0300.sql.gz
romulo-bases/romulo/romulo_2026-08-10_0300.sql.gz
romulo-bases/reicol/reicol_2026-08-10_0300.sql.gz
```

### 7.4 Cron diario (3:00 AM)

```bash
crontab -e
```

```cron
0 3 * * * BACKUP_CONFIG=/etc/cotiz-backup/backup-r2.env /opt/cotiz-romulo/scripts/backup-all-dbs-to-r2.sh >> /var/log/pg-backup-r2.log 2>&1
```

Opcional en Cloudflare R2: regla **Lifecycle** para borrar objetos con más de 30 días.

### 7.5 Restaurar desde R2

```bash
BACKUP_CONFIG=/etc/cotiz-backup/backup-r2.env \
  bash /opt/cotiz-romulo/scripts/restore-db-from-r2.sh \
  romulo /opt/cotiz-romulo romulo/romulo_2026-08-10_0300.sql.gz
```

Listar backups en el bucket:

```bash
source /etc/cotiz-backup/backup-r2.env
export AWS_ACCESS_KEY_ID="$R2_ACCESS_KEY_ID" AWS_SECRET_ACCESS_KEY="$R2_SECRET_ACCESS_KEY"
aws s3 ls "s3://${R2_BACKUP_BUCKET}/" --recursive --endpoint-url "$R2_ENDPOINT"
```

**Importante:** nunca uses `docker compose down -v` en producción; borra el volumen `postgres_data`.
