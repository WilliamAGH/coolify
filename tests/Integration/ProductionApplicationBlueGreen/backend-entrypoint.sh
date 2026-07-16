#!/bin/sh

set -eu

php artisan route:clear >/dev/null
php /lab/websocket.php &
exec /usr/local/bin/coolify-entrypoint "$@"
