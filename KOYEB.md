# Desplegar Carro en Koyeb + Neon

## Arquitectura

```text
[Koyeb Web Service]  ← Dockerfile (nginx + PHP + Laravel Blade)
        │
        └── [Neon PostgreSQL]  ← datos, carrito, sesiones, cache, colas
```

No se requiere Redis ni Upstash: cache, sesiones y colas usan tablas en PostgreSQL (`cache`, `sessions`, `jobs`).

## 1. Generar APP_KEY

```bash
php artisan key:generate --show
```

## 2. Neon PostgreSQL

1. Crea proyecto en [Neon](https://neon.tech)
2. Copia host, usuario, contraseña y nombre de BD
3. Usa `sslmode=require` en la conexión

Variables en Koyeb (`.env.koyeb.example`):

| Variable | Ejemplo |
|----------|---------|
| `DB_HOST` | `ep-xxx.neon.tech` |
| `DB_DATABASE` | `neondb` |
| `DB_USERNAME` | `neondb_owner` |
| `DB_PASSWORD` | `***` |

## 3. Crear Web Service en Koyeb

1. Sube el repo a GitHub
2. **Create Web Service** → Dockerfile
3. **Port:** `8000`
4. **Health check:** `/up`

## 4. Variables obligatorias

| Variable | Notas |
|----------|-------|
| `APP_KEY` | `base64:...` |
| `APP_URL` | `https://tu-app.koyeb.app` |
| `TRANSBANK_RETURN_URL` | `https://tu-app.koyeb.app/checkout/webpay/return` |
| `RUN_MIGRATIONS` | `true` (solo migraciones pendientes; no borra datos) |
| `RUN_SEED` | `true` **solo el primer deploy** en BD vacía; luego `false` |
| `CACHE_STORE` | `database` |
| `SESSION_DRIVER` | `database` |
| `QUEUE_CONNECTION` | `database` (o `sync` si no usas jobs async) |

## 5. URLs

| Recurso | URL |
|---------|-----|
| Tienda | `https://tu-app.koyeb.app/` |
| API | `https://tu-app.koyeb.app/api/v1/...` |
| Swagger | `https://tu-app.koyeb.app/api/documentation` |

## Webpay producción

1. Contrato Transbank
2. `TRANSBANK_ENV=production`
3. `TRANSBANK_RETURN_URL` HTTPS y ruta `/checkout/webpay/return`

## Redis opcional (local o alto tráfico)

Docker local sigue usando Redis en `docker-compose.yml`. En producción, si más adelante quieres Redis externo (Upstash, Redis Cloud, etc.):

```
REDIS_URL=rediss://...
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

El carrito de compras **siempre** persiste en PostgreSQL (`carts`), no en Redis.
