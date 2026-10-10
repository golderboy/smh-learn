#!/bin/sh
set -eu
umask 0027
mkdir -p storage/app/private/learning storage/app/private/reports storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
# Each container builds its own caches from runtime secrets, never from the build host.
if [ "$1" = php-fpm ] || [ "${3:-}" = queue:work ] || [ "${3:-}" = schedule:work ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi
exec docker-php-entrypoint "$@"
