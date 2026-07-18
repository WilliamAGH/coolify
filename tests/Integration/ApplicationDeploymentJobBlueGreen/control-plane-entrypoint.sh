#!/bin/sh

set -eu

ssh_root=/var/www/html/storage/app/ssh
[ -d "$ssh_root" ] && [ ! -L "$ssh_root" ] && [ -w "$ssh_root" ]
[ "$(stat -c '%u:%g:%a' "$ssh_root")" = 9999:9999:700 ]
[ -s "$ssh_root/testing-host" ]

php artisan migrate --force --no-interaction
php /lab/job.php
