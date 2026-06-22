# Cotiz — Sistema de cotizaciones (Romulo)

Migración Laravel de **allproducts** (cotizaciones). Stack: Laravel 13, PHP 8.4, PostgreSQL, Blade + Bootstrap 5.

Basado en la estructura de [carro](../carro).

## Requisitos

- Docker Desktop

## Inicio rápido

```powershell
cd C:\Users\csoto\Documents\workspace84\cotiz
copy .env.example .env
# Generar APP_KEY (con PHP local o dentro del contenedor):
docker compose run --rm app php artisan key:generate
docker compose build
docker compose up -d
docker compose exec app php artisan migrate --force
# Primera vez con datos demo (opcional): docker compose exec app php artisan db:seed --force
```

**Preservar datos (local, Neon QA y producción)**

- `php artisan migrate` solo aplica migraciones **pendientes**; no vacía tablas.
- **Nunca** uses `migrate:fresh`, `migrate:refresh` ni `db:wipe` en ambientes con datos reales.
- En Docker local, **no** uses `docker compose down -v` (el flag `-v` borra el volumen `postgres_data`).
- `RUN_SEED=false` en `.env` y en el panel de producción (Koyeb/Render) después del primer despliegue.
- `RUN_MIGRATIONS=true` aplica solo cambios de schema nuevos; los productos y cotizaciones existentes se mantienen.

**Tests (crítico si `.env` apunta a Neon QA)**

Los tests usan `RefreshDatabase` (= `migrate:fresh`). Si se ejecutan contra PostgreSQL/Neon, **borran toda la base**.

- **Usar siempre:** `docker compose run --rm test php artisan test`
- **Filtro:** `docker compose run --rm test php artisan test --filter=CompraAgil`
- **Prohibido:** `docker compose exec app php artisan test` (el contenedor `app` tiene credenciales Neon del `.env`)
- Fuera de Docker: `php artisan test` (usa `.env.testing` + SQLite en memoria; `phpunit.xml` fuerza la conexión)
- Protecciones en código: `phpunit.xml` (`force="true"`), `tests/TestCase.php` (aborta si no es SQLite `:memory:`)

| Servicio | URL |
|----------|-----|
| App | http://localhost:8082 |
| Health | http://localhost:8082/up |
| Mailpit | http://localhost:8027 |

## Credenciales demo

| Usuario | Contraseña | Perfil |
|---------|------------|--------|
| `admin` | `Admin123!` | Superadmin (3) |
| `ejecutivo` | `Ejec123!` | Ejecutivo (4) |

## Módulos MVP

- **Listado cotizaciones** — `/admin/cotizaciones`
- **Nueva cotización** — crear cabecera + detalle de productos
- **Retomar última** — última cotización del usuario
- Búsqueda de productos en `maeprod` (demo seed)

## Pendiente (fases siguientes)

- Enviar API, aceptar, asignar
- PDF / Excel
- `apinota`, Agile, maestro productos admin
- Migración de datos desde MySQL legacy

## Estructura

```
app/Services/NotaService.php       → lógica sp_notas
app/Services/NotaDetalleService.php
app/Services/NotaListadoService.php → lógica sp_notaslis
app/Http/Controllers/Web/Admin/Cotizacion*
database/migrations/               → Postgres desde sql/tablas.sql
config/cotiz.php                   → factor precio, empresa
```

## Puertos (no chocan con carro)

| Servicio | carro | cotiz |
|----------|-------|-------|
| Web | 8081 | **8082** |
| Postgres | 5433 | **5434** |
| Mailpit | 8026 | **8027** |
