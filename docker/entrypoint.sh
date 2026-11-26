#!/bin/sh
set -eu
mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chmod -R 777 bootstrap/cache storage 2>/dev/null || true
php artisan package:discover --ansi --no-interaction || true
# Platform does not execute release_command — migrate here with the migrator role.
# Fail loudly so a missing sessions/users table shows up in app_logs instead of a silent 500.
if [ -n "${MIGRATION_DATABASE_URL:-}" ]; then
  DB_URL="$MIGRATION_DATABASE_URL" php artisan migrate --force --no-interaction
fi
php artisan config:cache || true
php artisan route:cache || true
# Prefer built-in server for the slim footprint; swap to frankenphp/php-fpm if measured better.
# Use docker/router.php (not public/index.php) so existing files under public/ are served as-is.
exec php -S 0.0.0.0:8080 -t public docker/router.php
