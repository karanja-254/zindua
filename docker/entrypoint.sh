#!/usr/bin/env bash
set -euo pipefail

mkdir -p /var/www/html/database /var/www/html/storage/app/evidence /var/www/html/storage/logs
touch /var/www/html/database/database.sqlite
chown -R www-data:www-data /var/www/html/database /var/www/html/storage
chmod 775 /var/www/html/database /var/www/html/database/database.sqlite
chmod -R 775 /var/www/html/storage

# Render provides DATABASE_URL; Laravel's config reads DB_URL.
if [ -z "${DB_URL:-}" ] && [ -n "${DATABASE_URL:-}" ]; then
    export DB_URL="${DATABASE_URL}"
fi

is_loopback_host() {
    local host="${1:-}"
    [ -z "${host}" ] && return 0
    [ "${host}" = "127.0.0.1" ] && return 0
    [ "${host}" = "localhost" ] && return 0
    [ "${host}" = "::1" ] && return 0
    return 1
}

url_looks_loopback() {
    local url="${1:-}"
    case "${url}" in
        *://127.0.0.1[:/]*|*://localhost[:/]*|*@127.0.0.1[:/]*|*@localhost[:/]*)
            return 0
            ;;
    esac
    return 1
}

use_remote_db=0
db_url="${DB_URL:-}"

if [ -n "${db_url}" ] && ! url_looks_loopback "${db_url}"; then
    case "${db_url}" in
        postgres://*|postgresql://*|pgsql://*)
            export DB_CONNECTION=pgsql
            use_remote_db=1
            ;;
        mysql://*|mysql2://*|mariadb://*)
            export DB_CONNECTION=mysql
            use_remote_db=1
            ;;
        sqlite:*|sqlite://*)
            export DB_CONNECTION=sqlite
            use_remote_db=0
            ;;
        *)
            use_remote_db=1
            ;;
    esac
elif ! is_loopback_host "${DB_HOST:-}" && [ -n "${DB_DATABASE:-}" ] && [ -n "${DB_USERNAME:-}" ]; then
    use_remote_db=1
    case "${DB_CONNECTION:-}" in
        pgsql|postgres|postgresql|mysql|mariadb) ;;
        *) export DB_CONNECTION=pgsql ;;
    esac
fi

if [ "${use_remote_db}" -eq 0 ]; then
    export DB_CONNECTION=sqlite
    export DB_DATABASE=/var/www/html/database/database.sqlite
    unset DB_URL || true
    unset DATABASE_URL || true
    unset DB_HOST || true
    unset DB_PORT || true
    unset DB_USERNAME || true
    unset DB_PASSWORD || true
else
    # Discrete Render env vars (especially a truncated DB_DATABASE) must not
    # override the parsed DATABASE_URL / DB_URL.
    if [ -n "${DB_URL:-}" ]; then
        eval "$(php -r '
            $u = getenv("DB_URL") ?: "";
            if ($u === "") { exit(0); }
            $p = parse_url($u);
            if (! is_array($p) || empty($p["host"])) { exit(0); }
            $db = explode("?", ltrim((string) ($p["path"] ?? ""), "/"), 2)[0];
            $q = [];
            parse_str((string) ($p["query"] ?? ""), $q);
            $esc = static fn (string $k, string $v): string => "export ".$k."=".escapeshellarg($v).PHP_EOL;
            fwrite(STDOUT, $esc("DB_HOST", (string) $p["host"]));
            if (! empty($p["port"])) {
                fwrite(STDOUT, $esc("DB_PORT", (string) $p["port"]));
            }
            if ($db !== "") {
                fwrite(STDOUT, $esc("DB_DATABASE", $db));
            }
            if (! empty($p["user"])) {
                fwrite(STDOUT, $esc("DB_USERNAME", (string) $p["user"]));
            }
            if (array_key_exists("pass", $p)) {
                fwrite(STDOUT, $esc("DB_PASSWORD", (string) $p["pass"]));
            }
            if (! empty($q["sslmode"])) {
                fwrite(STDOUT, $esc("DB_SSLMODE", (string) $q["sslmode"]));
            }
        ')"
    fi
    case "${DB_DATABASE:-}" in
        *.sqlite|*.sqlite3|*database/database.sqlite)
            unset DB_DATABASE || true
            ;;
        *_)
            export DB_DATABASE="${DB_DATABASE%_}"
            ;;
    esac
    if [ "${DB_CONNECTION}" = "pgsql" ] && [ -z "${DB_SSLMODE:-}" ]; then
        export DB_SSLMODE=require
    fi
fi

php artisan config:clear >/dev/null 2>&1 || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan migrate --force

if [ -n "${TELEGRAM_BOT_TOKEN:-}" ]; then
    php artisan telegram:set-webhook || true
fi

exec "$@"
