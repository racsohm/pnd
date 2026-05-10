# ═══════════════════════════════════════════════════════════════════
# pnd-backups — PHP 8.3 + nginx + Laravel + mongodb-database-tools
# Una sola imagen con nginx + php-fpm orquestados por supervisord.
# Pensada para Alpine + 1 GB RAM.
# ═══════════════════════════════════════════════════════════════════
FROM php:8.3-fpm-alpine

# ── Paquetes del sistema ─────────────────────────────────────────
RUN apk add --no-cache \
        nginx supervisor bash curl rsync tzdata \
        sqlite sqlite-dev \
        oniguruma-dev libzip-dev icu-dev \
        nodejs npm git \
    && docker-php-ext-install pdo pdo_sqlite mbstring zip intl bcmath

# ── mongodb-database-tools (mongodump/mongorestore) ──────────────
# Alpine tiene paquetes en edge/community. Pin de versión vía edge.
RUN apk add --no-cache --repository=https://dl-cdn.alpinelinux.org/alpine/edge/community \
        mongodb-tools

# ── Composer ─────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Configs ──────────────────────────────────────────────────────
COPY docker/nginx.conf      /etc/nginx/nginx.conf
COPY docker/php.ini         /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/www.conf        /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh   /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# ── App ──────────────────────────────────────────────────────────
WORKDIR /var/www/html
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
    || composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts

COPY package.json package-lock.json* ./
# Sí instalamos devDeps porque vite/tailwind solo se usan para build;
# después del 'npm run build' borramos node_modules.
RUN npm ci || npm install

COPY . .
# Crear dirs ANTES de dump-autoload — package:discover (que corre como
# post-script de composer) escribe en bootstrap/cache.
RUN mkdir -p bootstrap/cache \
        storage/framework/cache storage/framework/sessions storage/framework/views \
        storage/logs database/sqlite \
    && chown -R www-data:www-data storage bootstrap/cache database \
    && composer dump-autoload --optimize \
    && npm run build \
    && rm -rf node_modules

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
