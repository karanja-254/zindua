#!/usr/bin/env bash
set -euo pipefail

mkdir -p /var/www/html/database
touch /var/www/html/database/database.sqlite
chown -R www-data:www-data /var/www/html/database
chmod 775 /var/www/html/database /var/www/html/database/database.sqlite

has_external_db=0
if [ -n "${DB_URL:-}" ] || [ -n "${DATABASE_URL:-}" ]; then
    has_external_db=1
fi
if [ -n "${DB_HOST:-}" ] && [ "${DB_HOST}" != "127.0.0.1" ] && [ -n "${DB_DATABASE:-}" ] && [ -n "${DB_USERNAME:-}" ]; then
    has_external_db=1
fi

if [ "${has_external_db}" -eq 0 ]; then
    export DB_CONNECTION=sqlite
    export DB_DATABASE=/var/www/html/database/database.sqlite
    unset DB_URL || true
    unset DATABASE_URL || true
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan migrate --force

exec "$@"
