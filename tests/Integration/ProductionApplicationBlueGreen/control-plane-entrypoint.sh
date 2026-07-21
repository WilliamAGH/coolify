#!/bin/sh

set -eu

ssh_root=/var/www/html/storage/app/ssh
test -d "$ssh_root"
test ! -L "$ssh_root"
test -w "$ssh_root"
test "$(stat -c '%u:%g:%a' "$ssh_root")" = 9999:9999:700
test -s "$ssh_root/testing-host"

php artisan migrate --force --no-interaction
php /lab/deploy.php
