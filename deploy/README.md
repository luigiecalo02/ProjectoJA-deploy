# Deploy Docker / VPS KVM 2 / Dokploy

ProjectoJA queda en un solo origen: frontend Vue, API Laravel (`/api`), archivos (`/storage`) y Reverb (`/app`).

## 1. Preparar variables

```bash
cp .env.docker.example .env
```

Edite `.env`:

- `DB_PASSWORD` y `MYSQL_ROOT_PASSWORD` (fuertes)
- `REVERB_APP_KEY` y `REVERB_APP_SECRET`
- En el VPS, ponga su dominio:

```env
APP_URL=https://tudominio.com
FRONTEND_URL=https://tudominio.com
SANCTUM_STATEFUL_DOMAINS=tudominio.com
REVERB_HOST=tudominio.com
REVERB_PORT=443
REVERB_SCHEME=https
VITE_API_URL=https://tudominio.com
VITE_REVERB_HOST=tudominio.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

Si cambia un `VITE_*`, hay que reconstruir nginx: `docker compose up -d --build nginx`.

## 2. Arrancar

```bash
docker compose up -d --build
docker compose run --rm --no-deps app php artisan key:generate --force
docker compose exec app php artisan db:seed --class=RolePermissionSeeder --force
```

Local: http://localhost:8080  
Salud API: http://localhost:8080/up

## 3. VPS a mano

1. Instale Docker: `curl -fsSL https://get.docker.com | sh`
2. Clone el repo y copie `.env`
3. Los mismos comandos del paso 2
4. Ponga Caddy o Traefik delante (80/443 → `nginx:80`)
5. No publique el puerto 3306

Backup:

```bash
docker compose exec mysql mysqldump -u projectja -p ProjetJA > backup.sql
```

## 4. Dokploy

1. Instale Dokploy en el KVM 2
2. Cree un proyecto Compose apuntando a este `docker-compose.yml`
3. Pegue las variables de `.env` en el panel (equivalente al archivo `.env`)
4. Dominio HTTPS sobre el servicio **nginx**, puerto **80**
5. No exponga `mysql`, `app`, `reverb` ni `queue`

Dokploy/Traefik termina TLS; Reverb queda en `wss://tudominio.com/app`.

## 5. Otro proyecto, misma base

La red Docker se llama `projectja`. Desde otro compose:

```yaml
networks:
  projectja:
    external: true
```

`DB_HOST=mysql` y un usuario de solo lectura. No corra migraciones desde el otro app.

## 6. Comandos útiles

```bash
docker compose logs -f app nginx reverb queue
docker compose exec app php artisan migrate --force
docker compose down          # no borra datos
docker compose down -v       # borra MySQL y storage
```
