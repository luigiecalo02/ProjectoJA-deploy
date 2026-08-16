# ProjectJABack

API Laravel (Modular Monolith) — Fase 1: autenticación y gestión de usuarios.

## Requisitos

- **PHP 8.4** (instalado en paralelo, no reemplaza tu PHP 8.2 de XAMPP)
- Composer
- MySQL 8 — base de datos `ProjetJA`
- Redis (opcional en local; por defecto usa cache en `database`)

## PHP 8.4 solo en esta terminal (Opción B)

No cambies el PATH global de Windows. En **cada** terminal donde trabajes el backend:

```powershell
$env:Path = "C:\php\php84;" + $env:Path
php -v   # debe mostrar PHP 8.4.x
```

Tu proyecto con PHP 8.2 sigue intacto al cerrar esta terminal.

## Arranque local

```powershell
$env:Path = "C:\php\php84;" + $env:Path
cd ProjectJABack
copy .env.example .env   # si aún no existe
php artisan key:generate
# Ajusta DB_* en .env (DB_DATABASE=ProjetJA)
php artisan migrate --seed
php artisan serve
```

API: `http://127.0.0.1:8000`

Usuario admin sembrado:

- Email: `admin@projectja.local`
- Password: `Admin123!`

## Endpoints principales

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/v1/auth/login` | Login |
| POST | `/api/v1/auth/logout` | Logout (Bearer) |
| GET | `/api/v1/auth/me` | Usuario actual |
| GET | `/api/v1/auth/oauth/{google\|facebook}/redirect` | OAuth |
| GET/POST/PUT/PATCH/DELETE | `/api/v1/users` | CRUD usuarios |

Respuestas en envelope: `success`, `message`, `data`, `errors`, `pagination`, `meta`.

## Tests

```powershell
$env:Path = "C:\php\php84;" + $env:Path
php artisan test
```

## Repo

https://github.com/luigiecalo02/ProjectJABack.git
