#!/bin/sh

set -eu

php artisan migrate --force --no-interaction
php /lab/job.php
