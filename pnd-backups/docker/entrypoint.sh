#!/bin/bash
# Inicializa la app en cada arranque (idempotente).
set -e

cd /var/www/html

mkdir -p storage/framework/{cache,sessions,views} storage/logs database/sqlite /var/backups
chown -R www-data:www-data storage bootstrap/cache database /var/backups

# nginx corre como www-data (ver nginx.conf) pero en Alpine /var/lib/nginx
# pertenece al usuario 'nginx', así que el buffer de uploads
# (/var/lib/nginx/tmp/client_body) da EACCES en cada POST con body grande.
chown -R www-data:www-data /var/lib/nginx

# ── Acceso al docker.sock del host (para rebuild desde la UI) ────
# El socket viene con el GID del grupo 'docker' del host. Creamos un
# grupo interno con ese mismo GID y le agregamos www-data para que
# `docker compose` corra sin sudo desde PHP-FPM.
if [ -S /var/run/docker.sock ]; then
  DOCKER_GID="$(stat -c %g /var/run/docker.sock 2>/dev/null || echo '')"
  if [ -n "$DOCKER_GID" ] && [ "$DOCKER_GID" != "0" ]; then
    if ! getent group "$DOCKER_GID" >/dev/null 2>&1; then
      addgroup -g "$DOCKER_GID" docker 2>/dev/null || addgroup docker 2>/dev/null || true
    fi
    GRP_NAME="$(getent group "$DOCKER_GID" | cut -d: -f1)"
    [ -n "$GRP_NAME" ] && addgroup www-data "$GRP_NAME" 2>/dev/null || true
  else
    # Si el socket está en gid 0 (root), www-data igual no entra. Como
    # último recurso, abrimos lectura/escritura al socket. Solo aplica en
    # hosts donde dockerd corre con --group root, raro pero posible.
    chmod 0660 /var/run/docker.sock 2>/dev/null || true
    adduser www-data root 2>/dev/null || true
  fi
fi

# SQLite vacío si no existe
DB_FILE="${DB_DATABASE:-/var/www/html/database/sqlite/database.sqlite}"
if [ ! -f "$DB_FILE" ]; then
  install -o www-data -g www-data -m 644 /dev/null "$DB_FILE"
fi

# ── APP_KEY ─────────────────────────────────────────────────────
# La APP_KEY llega por env_file → variable de proceso. Puede venir
# vacía, entre comillas o con espacios; normalizamos antes de decidir.
# Si tras todo sigue vacía, fallamos rápido — preferible no arrancar
# que servir 500 a cada POST por MissingAppKeyException.
#
# Fallback persistente: storage/.app_key. storage está bind-mounted
# al host, así que la key sobrevive a 'docker compose up --force-recreate'.
normalize_key() {
  local v="${1:-}"
  # trim espacios
  v="${v#"${v%%[![:space:]]*}"}"
  v="${v%"${v##*[![:space:]]}"}"
  # quita un par de comillas circundantes (dobles o simples)
  if [ "${v#\"}" != "$v" ] && [ "${v%\"}" != "$v" ]; then v="${v#\"}"; v="${v%\"}"; fi
  if [ "${v#\'}" != "$v" ] && [ "${v%\'}" != "$v" ]; then v="${v#\'}"; v="${v%\'}"; fi
  printf '%s' "$v"
}

APP_KEY="$(normalize_key "${APP_KEY:-}")"
KEY_CACHE="storage/.app_key"

if [ -z "$APP_KEY" ] && [ -s "$KEY_CACHE" ]; then
  APP_KEY="$(normalize_key "$(cat "$KEY_CACHE")")"
  echo "[entrypoint] APP_KEY recuperada de $KEY_CACHE"
fi

if [ -z "$APP_KEY" ]; then
  APP_KEY="base64:$(openssl rand -base64 32)"
  printf '%s' "$APP_KEY" > "$KEY_CACHE"
  chown www-data:www-data "$KEY_CACHE"
  chmod 600 "$KEY_CACHE"
  echo "[entrypoint] APP_KEY generada y cacheada en $KEY_CACHE"
  echo "[entrypoint] Para sobrevivir a 'docker compose down -v', persistila también en el .env del host."
fi

export APP_KEY

if [ -z "$APP_KEY" ]; then
  echo "[entrypoint] ERROR: APP_KEY sigue vacía tras todos los fallbacks. Abortando." >&2
  exit 1
fi

# Invalidar cache de config de arranques previos (puede haberse
# congelado con APP_KEY vacía). config:cache se regenera abajo.
php artisan config:clear || true

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
