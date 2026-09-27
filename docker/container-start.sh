#!/bin/sh
set -eu

umask 0027

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is required. Generate it before handing off the .env file." >&2
    exit 1
fi

if [ -z "${DB_URL:-}" ] && {
    [ -z "${DB_HOST:-}" ] ||
    [ -z "${DB_DATABASE:-}" ] ||
    [ -z "${DB_USERNAME:-}" ];
}; then
    echo "Neon is not configured. Set DB_URL or all discrete DB_* credentials in .env." >&2
    exit 1
fi

mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

if [ ! -e public/storage ] && [ ! -L public/storage ]; then
    php artisan storage:link
fi

echo "Applying pending database migrations..."
if [ -n "${DB_MIGRATION_URL:-}" ]; then
    DB_URL="${DB_MIGRATION_URL}" php artisan migrate --force
else
    php artisan migrate --force
fi

php artisan optimize

# Artisan runs as root during container startup. `optimize` recreates the
# configuration, route, event, and view caches, so fix their ownership only
# after every cache file has been generated. Apache serves PHP requests as
# www-data and must also be able to write Laravel's runtime storage.
chown -R www-data:www-data storage bootstrap/cache
chmod -R u+rwX storage bootstrap/cache

exec apache2-foreground
