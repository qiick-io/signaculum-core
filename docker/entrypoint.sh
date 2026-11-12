#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

mkdir -p storage/framework/{cache/data,sessions,views} storage/logs storage/app/public bootstrap/cache database
chown -R www-data:www-data storage bootstrap/cache database 2>/dev/null || true
chmod -R ug+rwx storage bootstrap/cache database 2>/dev/null || true

if [[ -z "${APP_KEY:-}" ]]; then
  php artisan key:generate --force --ansi
fi

if [[ ! -f storage/oauth-private.key ]]; then
  php artisan passport:keys --force --ansi
fi

if [[ "${RUN_MIGRATIONS:-false}" == "true" ]]; then
  php artisan migrate --force --no-interaction
fi

php-fpm -D
exec nginx -g 'daemon off;'
