#!/usr/bin/env bash
set -euo pipefail

# Cache framework config/routes/views for production performance.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Apply database migrations (safe to run on every boot).
php artisan migrate --force

exec "$@"
