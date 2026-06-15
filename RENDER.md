# Desplegar Cotiz en Render + Neon + Resend

Imagen Docker en la raíz (`Dockerfile`). Dominio: `https://cotiz.romulo.cl`.

## Arquitectura (recomendada — Render **free**)

```text
[Render Web Service]  ← Dockerfile (nginx + PHP + Laravel)
        │
        ├── [Neon PostgreSQL]  ← datos, sesiones, cache, colas
        │
        └── [Resend API HTTPS]  ← correo transaccional como @romulo.cl
```

Render **free** bloquea SMTP saliente (puertos 465/587). **Resend** envía por HTTPS (443), compatible con plan gratuito.

## 1. Resend + dominio `romulo.cl`

1. Crea cuenta en [resend.com](https://resend.com).
2. **Domains** → **Add domain** → `romulo.cl`.
3. En el DNS de `romulo.cl` (cPanel, Cloudflare, NIC, etc.) agrega los registros que muestra Resend:
   - **DKIM** (registros TXT/CNAME)
   - **SPF** (TXT) — si ya tienes SPF para `mail.romulo.cl`, combínalos según la guía de Resend
4. Espera verificación (minutos a horas).
5. **API Keys** → crea una key → `re_...`

Prueba sin dominio verificado: Resend solo permite enviar desde `onboarding@resend.dev` (no sirve para prod con `@romulo.cl`).

## 2. Crear Web Service en Render

1. Repo de GitHub conectado.
2. **Environment:** Docker.
3. **Dockerfile path:** `./Dockerfile`
4. **Instance type:** Free (OK con Resend).
5. **Health check path:** `/up`
6. **Auto-Deploy:** `Yes` (rama `main`)

## 2.1 Deploy automático (push a `main`)

Render puede redeployar solo con **Auto-Deploy**, pero si el webhook de GitHub falla, el repo incluye un respaldo con **Deploy Hook**:

1. Render → tu Web Service → **Settings** → **Deploy Hook** → copiar la URL.
2. GitHub → repo → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**:
   - **Name:** `RENDER_DEPLOY_HOOK`
   - **Value:** la URL del paso 1
3. Cada push a `main` ejecuta `.github/workflows/render-deploy.yml` y llama al hook.

Verificar: GitHub → **Actions** → workflow *Deploy to Render* (debe quedar en verde). Render → **Events** → debe aparecer un deploy nuevo.

Si el secret no está configurado, el workflow avisa con warning y no falla el CI.

## 3. Variables en Render

Plantilla: **`.env.render.example`**.

### App y base de datos

| Variable | Valor |
|----------|-------|
| `APP_KEY` | `php artisan key:generate --show` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://tienda.romulo.cl` |
| `DB_*` o `DATABASE_URL` | Neon PostgreSQL |
| `CACHE_STORE` | `database` |
| `SESSION_DRIVER` | `database` |
| `SESSION_SECURE_COOKIE` | `true` |
| `QUEUE_CONNECTION` | `database` |
| `RUN_MIGRATIONS` | `true` |
| `RUN_SEED` | `true` una vez, luego `false` |

### Correo (Resend — producción)

| Variable | Valor |
|----------|-------|
| `MAIL_MAILER` | `resend` |
| `RESEND_API_KEY` | `re_xxxxxxxx` |
| `MAIL_FROM_ADDRESS` | `tienda@romulo.cl` |
| `MAIL_FROM_NAME` | `Tienda Rómulo` |

No uses `MAIL_HOST` / `MAIL_PASSWORD` con Resend. El remitente debe estar en un dominio **verificado** en Resend.

### Admin y logs

| Variable | Valor |
|----------|-------|
| `ADMIN_OTP_ENABLED` | `true` |
| `LOG_CHANNEL` | `stderr` |
| `LOG_LEVEL` | `error` |

Tras cambiar variables: **Manual Deploy**.

## 4. Local vs producción

| Entorno | Mailer |
|---------|--------|
| **Local** (Docker) | `MAIL_MAILER=smtp` → `mail.romulo.cl:465` |
| **Render** | `MAIL_MAILER=resend` + `RESEND_API_KEY` |

Mismas notificaciones Laravel (OTP admin, reset cliente, bienvenida); solo cambia el transporte.

## 5. Primer acceso admin

1. `RUN_SEED=true` una vez → existe `admin@carro.local` (correo ficticio).
2. Con OTP activo, crea un admin con **correo real** (`/admin/usuarios`) o usa temporalmente `ADMIN_OTP_ENABLED=false`.
3. No definas `SEED_EXTRA_ADMIN_*` en Render (solo local).

## 6. Alternativa: SMTP en Render de pago

Si prefieres seguir con `mail.romulo.cl` sin Resend, necesitas **instancia de pago** en Render:

```env
MAIL_MAILER=smtp
MAIL_HOST=mail.romulo.cl
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=tienda@romulo.cl
MAIL_PASSWORD=...
```

En free verás: `Unable to connect to ssl://mail.romulo.cl:465 (Operation timed out)`.

## 7. Checklist correo Resend

- [ ] Dominio `romulo.cl` verificado en Resend
- [ ] `MAIL_MAILER=resend` y `RESEND_API_KEY` en Render
- [ ] `MAIL_FROM_ADDRESS=tienda@romulo.cl`
- [ ] Redeploy
- [ ] Probar login admin OTP o recuperar contraseña
- [ ] Log sin timeout SMTP

## URLs

| Recurso | URL |
|---------|-----|
| App | `https://cotiz.romulo.cl/` |
| Admin login | `https://cotiz.romulo.cl/admin/login` |
| Health | `https://cotiz.romulo.cl/up` |
