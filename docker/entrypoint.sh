#!/bin/sh
set -e

php artisan package:discover --ansi >/dev/null

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    php artisan migrate --force
    php artisan db:seed --force
fi

exec "$@"
