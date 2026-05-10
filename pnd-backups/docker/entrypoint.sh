#!/bin/bash
# Inicializa la app en cada arranque (idempotente).
set -e

cd /var/www/html

mkdir -p storage/framework/{cache,sessions,views} storage/logs database/sqlite /var/backups
chown -R www-data:www-data storage bootstrap/cache database /var/backups

# SQLite vacío si no existe
DB_FILE="${DB_DATABASE:-/var/www/html/database/sqlite/database.sqlite}"
if [ ! -f "$DB_FILE" ]; then
  install -o www-data -g www-data -m 644 /dev/null "$DB_FILE"
fi

# Generar APP_KEY si está vacío
if [ -z "${APP_KEY:-}" ] || [ "${APP_KEY:-}" = "" ]; then
  if grep -qE '^APP_KEY=$' .env 2>/dev/null; then
    php artisan key:generate --force --no-interaction || true
  fi
fi

# Migraciones + seed admin
php artisan migrate --force --no-interaction || true
php artisan db:seed --class=AdminSeeder --force --no-interaction || true

# Cache de config en producción
if [ "${APP_ENV:-production}" = "production" ]; then
  php artisan config:cache || true
  php artisan route:cache  || true
  php artisan view:cache   || true
fi

exec "$@"
