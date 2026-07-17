#!/bin/sh

set -eu

token=$(cat /run/secrets/control-plane-route-health-token)
applied_ack=$(cat /run/secrets/control-plane-applied-ack)
pool_ack=$(cat /run/secrets/control-plane-pool-ack)
member_id=${CONTROL_PLANE_MEMBER_ID:-}
expected_host=${LAB_EXPECTED_HOST:-}
web_marker=${CONTROL_PLANE_WEB_MARKER_PATH:-/var/lib/coolify-control-plane/web-epoch}
web_epoch=${CONTROL_PLANE_WEB_EPOCH:-}
drain_marker=${CONTROL_PLANE_ROUTE_DRAIN_MARKER_PATH:-/var/lib/coolify-control-plane/route-drain-epoch}
drain_epoch=${CONTROL_PLANE_ROUTE_DRAIN_EPOCH:-}
database_host=${DB_HOST:-}
database_port=${DB_PORT:-5432}
database_name=${DB_DATABASE:-}
database_user=${DB_USERNAME:-}
redis_host=${REDIS_HOST:-}
redis_port=${REDIS_PORT:-6379}

marker_matches()
{
    marker_path=$1
    expected_epoch=$2

    [ -n "$expected_epoch" ] \
        && [ -f "$marker_path" ] \
        && [ ! -L "$marker_path" ] \
        && printf '%s' "$expected_epoch" | cmp -s - "$marker_path"
}

if [ "${CONTROL_PLANE_MODE:-}" != active ] \
    || [ "${CONTROL_PLANE_STARTUP_MODE:-}" != web-only ] \
    || [ "${REQUEST_METHOD:-}" != GET ] \
    || [ -n "${QUERY_STRING:-}" ] \
    || [ "${HTTP_X_CONTROL_PLANE_ROUTE_HEALTH:-}" != "$token" ]; then
    printf '%s\n\n' 'Status: 404 Not Found'
    exit 0
fi

status='204 No Content'
host_matches=1
if [ -n "$expected_host" ] && [ "${HTTP_HOST:-}" != "$expected_host" ]; then
    host_matches=0
fi
if [ -z "$member_id" ] \
    || [ "$host_matches" = 0 ] \
    || ! marker_matches "$web_marker" "$web_epoch"; then
    status='503 Service Unavailable'
fi

route_state=
if [ -e "$drain_marker" ] || [ -L "$drain_marker" ]; then
    status='503 Service Unavailable'
    if marker_matches "$drain_marker" "$drain_epoch"; then
        route_state=draining
    fi
fi

if [ "$status" = '204 No Content' ]; then
    database_probe=
    if [ -n "$database_host" ] \
        && [ -n "$database_name" ] \
        && [ -n "$database_user" ]; then
        database_probe=$(PGCONNECT_TIMEOUT=1 psql --no-psqlrc --quiet --tuples-only --no-align \
            --host "$database_host" --port "$database_port" --dbname "$database_name" \
            --username "$database_user" --command 'select 1' 2>/dev/null || true)
    fi
    redis_probe=
    if [ -n "$redis_host" ]; then
        redis_probe=$(printf '*1\r\n\0444\r\nPING\r\n' \
            | nc -w 1 "$redis_host" "$redis_port" 2>/dev/null \
            | tr -d '\r\n' || true)
    fi
    if [ "$database_probe" != 1 ] || [ "$redis_probe" != +PONG ]; then
        status='503 Service Unavailable'
    fi
fi

printf 'Status: %s\n' "$status"
printf 'X-Control-Plane-Applied-Config: %s\n' "$applied_ack"
printf 'X-Control-Plane-Pool-Ack: %s\n' "$pool_ack"
printf 'X-Control-Plane-Member: %s\n' "$member_id"
if [ "$route_state" = draining ]; then
    printf '%s\n' 'X-Control-Plane-Route-State: draining'
    printf 'X-Control-Plane-Route-Drain-Epoch: %s\n' "$drain_epoch"
fi
printf '\n'
