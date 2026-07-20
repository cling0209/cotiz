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
| `APP_URL` | `https://cotiza.romulo.cl` |
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

### Cotiz — negocio (Softland / alertas precio)

Equivalente a `config.php` del legacy **allproducts**. Ver `config/cotiz.php`.

| Variable | Uso | Valor típico |
|----------|-----|--------------|
| `COTIZ_PROD_VALOR_FECHA_MESES` | Meses sin actualizar precio → fecha en **rojo** en cotización y recepción Agile | `3` |
| `COTIZ_CODIGO_BODEGA` | Columna 1 CSV **Guía ingreso** Softland | `01` |
| `COTIZ_CONCEPTO_BODEGA` | Columna 4 CSV guía ingreso | `26` |
| `COTIZ_CODIGO_PROVEEDOR` | Columna 6 CSV guía ingreso (RUT/código proveedor) | `76185139` |
| `COTIZ_FACTOR_PRECIO_VENTA` | Factor costo → venta en cotizaciones | `1.22` |
| `COTIZ_SISTEMA` | Nombre de esta instancia (Romulo / Reicol) | `Romulo` |
| `COTIZ_API_USUARIO_URL` | URL del par para replicar usuarios al crear | Romulo: `https://cotiza.reicol.cl/api/v1/usuario` — Reicol: `https://cotiza.romulo.cl/api/v1/usuario` |
| `COTIZ_API_OPORTUNIDAD_ENCONTRADA_URL` | URL del par para replicar oportunidades encontradas (opcional) | Si vacío, se deriva de `COTIZ_API_USUARIO_URL` (`.../oportunidad-encontrada`) |
| `MERCADOPUBLICO_ANALISIS_ADMIN` | Habilita búsqueda de oportunidades + palabras clave (solo sitio buscador) | `true` en Romulo; `false` en Reicol |

Las **palabras clave no se sincronizan** entre sitios. Lo que se replica al par son las **oportunidades encontradas** (y el resultado de vinculación). Si el par está dormido, quedan pendientes y se reintentan: al **boot**, cada **30 min** (`oportunidad:sync-encontradas-par`) y al **terminar** búsqueda/vinculación (wake `/up` + espera `COTIZ_OPORTUNIDAD_SYNC_WAKE_ESPERA_SEG` + cola pendiente).

En el sitio con `MERCADOPUBLICO_ANALISIS_ADMIN=true` (Rómulo), la pantalla **Oportunidades** muestra dos paneles: **Sync cotizaciones al par** y **Sync vinculaciones al par**, con pendientes, último error y botón para reintentar cada cola por separado.

Al **tomar** un código CA (`*-COT*`), se reserva de forma atómica en este sitio y en el par (`accion=tomada`) **antes** de grabar la nota. Si el par no responde o ya está tomado, se bloquea (no se permite duplicar entre ejecutivos/sitios).
| `COTIZ_API_NOTA_USER` / `COTIZ_API_NOTA_PASSWORD` | Basic Auth compartida (cotizaciones y usuarios) | **Obligatorias** — mismas credenciales en Romulo y Reicol |
| `COTIZ_API_CONSULTA_NRO_COTIZACION` | URL consulta duplicados (opcional) | Si vacío y `APP_URL` es `cotiza.reicol.cl` o `cotiza.romulo.cl`, se usa el par automáticamente |

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

## 6. Consulta MP programada (Resultados Compra Ágil)

El contenedor ejecuta `php artisan schedule:run` cada minuto (`RUN_SCHEDULER=true` por defecto).

Además hay **catch-up** en dos momentos:

1. Al **boot** del contenedor (cold start / redeploy).
2. Al **login** del admin: si el último horario ya pasó y no hubo corrida masiva desde ese slot, encola la consulta.

El último resultado (encolado u omitido, con hora y origen) se guarda en cache y se muestra en **Resultados Compra Ágil**, debajo de «Última consulta», solo para quien tiene acceso a esa pantalla.

Así, si Render dormía a las 09:00 y un usuario inicia sesión a las 10:05, la corrida se encola al loguearse.

Por defecto **no reconsulta** en la corrida masiva una cotización ya consultada el mismo día (`MERCADOPUBLICO_RESULTADOS_SKIP_MISMO_DIA=true`). El botón individual «Consultar MP» sigue pudiendo forzar.

| Variable | Default | Uso |
|----------|---------|-----|
| `MERCADOPUBLICO_RESULTADOS_SCHEDULE` | `true` | Activa la corrida automática + catch-up al boot |
| `MERCADOPUBLICO_RESULTADOS_SCHEDULE_HOURS` | `10,19` | Horas locales (`APP_TIMEZONE`, Chile) |
| `MERCADOPUBLICO_RESULTADOS_SKIP_MISMO_DIA` | `true` | Omite en masiva las ya consultadas hoy |
| `RUN_SCHEDULER` | `true` | Loop del scheduler en el entrypoint |

Equivalente a «Consultar ahora»: `php artisan compra-agil:consultar-resultados`.

Catch-up manual: `php artisan compra-agil:consultar-resultados --catch-up`.

La búsqueda de **Oportunidades** usa los mismos horarios y parámetros HTTP de Mercado Público:

- Schedule: `php artisan oportunidad:buscar` en las horas de `MERCADOPUBLICO_RESULTADOS_SCHEDULE_HOURS`.
- Catch-up/retoma al boot (`php artisan oportunidad:buscar --catch-up`) y al login admin.
- Sync al par: al boot, cada 30 min y al terminar búsqueda/vinculación (`oportunidad:sync-encontradas-par` / wake + pendientes).
- Solo se ejecuta donde `MERCADOPUBLICO_ANALISIS_ADMIN=true`.
- La corrida queda persistida y continúa desde el siguiente paso si Render reinicia.
- Si el día **en curso** ya tiene corrida completa, el siguiente horario (o Buscar) hace una corrida **incremental** desde la última `fecha_publicacion` conocida (`cambio_desde`), para no perder cotizaciones publicadas más tarde.
- Regiones con **Falló (definitivo)** en la corrida previa del mismo día se reconsultan **completas** (sin `cambio_desde`) y **antes** que el resto incremental, por si el fallo de MP fue temporal.
- Días pasados siguen con una sola corrida completa (no se reconsultan).
- Si el worker se cae o Mercado Público deja la corrida sin avance (`updated_at` viejo), el poll de estado / login / catch-up **reencola automáticamente** desde el checkpoint (`plan_json`). Endpoint manual: `POST .../oportunidades/para-cotizar/reanudar`.
- Un error temporal de MP afecta solo al paso `palabra × región`.
- Al terminar una región, se **reintenta una vez** lo fallido de esa región; si sigue fallando, se pasa a la siguiente región del orden configurado.

## OCR de PDFs escaneados

El importador PDF/Word puede leer listados escaneados (p. ej. EETT) con **pdftoppm** + **tesseract** (paquetes en el `Dockerfile`: `poppler-utils`, `tesseract-ocr`, `tesseract-ocr-data-spa/eng`).

Si el PDF ya trae texto nativo, no usa OCR. En local Windows hace falta Tesseract y Poppler en PATH (o las rutas habituales de instalación).

## URLs

| Recurso | URL |
|---------|-----|
| App | `https://cotiz.romulo.cl/` |
| Admin login | `https://cotiz.romulo.cl/admin/login` |
| Health | `https://cotiz.romulo.cl/up` |
