#!/bin/sh

set -eu

# Debug mode

if [ "$(/usr/local/bin/coolify-entrypoint mode)" = passive ]; then
    echo "Passive control plane mode: debug setup skipped."
    exit 0
fi

if [ "${APP_DEBUG:-false}" = "true" ]; then
    echo "Debug mode is enabled"
    echo "Installing development dependencies..."
    composer install --dev --no-scripts
    echo "Clearing optimized classes..."
    php artisan optimize:clear
fi
