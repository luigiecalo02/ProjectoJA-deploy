#!/bin/sh
set -e

if [ -n "${DB_HOST:-}" ]; then
  echo "Esperando MySQL en ${DB_HOST}:${DB_PORT:-3306}..."
  php -r '
    $host = getenv("DB_HOST");
    $port = getenv("DB_PORT") ?: "3306";
    $db = getenv("DB_DATABASE");
    $user = getenv("DB_USERNAME");
    $pass = getenv("DB_PASSWORD") ?: "";
    for ($i = 0; $i < 60; $i++) {
        try {
            new PDO("mysql:host={$host};port={$port};dbname={$db}", $user, $pass);
            exit(0);
        } catch (Throwable $e) {
            sleep(2);
        }
    }
    fwrite(STDERR, "MySQL no respondió a tiempo." . PHP_EOL);
    exit(1);
  '
fi

if [ ! -L public/storage ]; then
  php artisan storage:link --force || true
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force
fi

if [ "$(id -u)" = "0" ]; then
  chown -R www-data:www-data storage bootstrap/cache || true
  if [ "$1" != "php-fpm" ]; then
    exec runuser -u www-data -- "$@"
  fi
fi

exec "$@"
