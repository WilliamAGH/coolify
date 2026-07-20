#!/usr/bin/env bash

set -Eeuo pipefail

readonly PRODUCTION_BASE_IMAGE='serversideup/php:8.5-fpm-nginx-alpine@sha256:1854d81da23fc5c174a26bf039bc7724aeccec5743524717bbc6c10c1c927ac2'
SCRIPT_DIRECTORY="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIRECTORY
REPOSITORY_ROOT="$(cd -- "$SCRIPT_DIRECTORY/../../.." && pwd)"
readonly REPOSITORY_ROOT
readonly MIGRATION_SERVICE="$REPOSITORY_ROOT/docker/production/etc/s6-overlay/s6-rc.d/db-migration/up"

TEMP_DIRECTORY=''

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

cleanup() {
    local exit_code=$?

    trap - EXIT
    if [ -n "$TEMP_DIRECTORY" ]; then
        case "$TEMP_DIRECTORY" in
            "${TMPDIR:-/tmp}"/coolify-database-migration-s6.*)
                rm -rf -- "$TEMP_DIRECTORY"
                ;;
            *)
                printf 'Refusing to remove unexpected integration directory: %s\n' "$TEMP_DIRECTORY" >&2
                ;;
        esac
    fi
    exit "$exit_code"
}

trap cleanup EXIT

command -v docker >/dev/null 2>&1 || fail 'docker is required'

TEMP_DIRECTORY="$(mktemp -d "${TMPDIR:-/tmp}/coolify-database-migration-s6.XXXXXX")"
readonly TEMP_DIRECTORY

cat > "$TEMP_DIRECTORY/php" <<'SH'
#!/bin/sh
set -eu

[ "$#" -eq 2 ] || exit 64
[ "$1" = 'artisan' ] || exit 65
[ "$2" = 'start:migration' ] || exit 66

case "${FAKE_ARTISAN_STATUS:-}" in
    ''|*[!0-9]*) exit 67 ;;
esac

exit "$FAKE_ARTISAN_STATUS"
SH
chmod 0755 "$TEMP_DIRECTORY/php"

run_status_case() {
    local expected_status=$1
    local actual_status

    set +e
    docker run --rm \
        --entrypoint /command/execlineb \
        --env "FAKE_ARTISAN_STATUS=$expected_status" \
        --env 'PATH=/command:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin' \
        --mount "type=bind,src=$MIGRATION_SERVICE,dst=/fixture/db-migration-up,readonly" \
        --mount "type=bind,src=$TEMP_DIRECTORY/php,dst=/usr/local/bin/php,readonly" \
        "$PRODUCTION_BASE_IMAGE" \
        -P /fixture/db-migration-up
    actual_status=$?
    set -e

    if [ "$actual_status" -ne "$expected_status" ]; then
        fail "db-migration S6 service returned $actual_status; expected $expected_status"
    fi
}

run_status_case 0
run_status_case 42

printf 'Database migration S6 exit propagation passed.\n'
