#!/bin/sh

set -eu

color=${CONTROL_PLANE_COLOR:-legacy}
writer_marker=${CONTROL_PLANE_WRITER_MARKER_PATH:-/var/lib/coolify-control-plane/writer-epoch}
web_marker=${CONTROL_PLANE_WEB_MARKER_PATH:-/var/lib/coolify-control-plane/web-epoch}
mutation_freeze_marker=${CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH:-/var/lib/coolify-control-plane/mutation-freeze-epoch}
mutation_lease=${mutation_freeze_marker%/*}/mutation-inflight.lock
mutation_lease_acquired=0
request_method=${REQUEST_METHOD:-GET}
expected_host=${LAB_EXPECTED_HOST:-}

marker_matches()
{
    marker_path=$1
    expected_epoch=$2

    [ -n "$expected_epoch" ] \
        && [ -f "$marker_path" ] \
        && [ ! -L "$marker_path" ] \
        && printf '%s' "$expected_epoch" | cmp -s - "$marker_path"
}

status='200 OK'
maintenance_state="/lab-state/services-${color}/maintenance"
if [ -f "$maintenance_state" ]; then
    if grep -F -q '"secret":"preexisting-bypass"' "$maintenance_state" \
        && { [ "${HTTP_X_MAINTENANCE_BYPASS:-}" = preexisting-bypass ] \
            || printf '%s' "${HTTP_COOKIE:-}" \
                | grep -F -q 'laravel_maintenance=preexisting-bypass'; }; then
        :
    else
        status='503 Service Unavailable'
    fi
fi
if [ "$status" = '200 OK' ] && [ -n "$expected_host" ] \
    && [ "${HTTP_HOST:-}" != "$expected_host" ]; then
    status='421 Misdirected Request'
fi
if [ "${CONTROL_PLANE_STARTUP_MODE:-full}" = web-only ]; then
    epoch=${CONTROL_PLANE_WEB_EPOCH:-}
    if ! marker_matches "$web_marker" "$epoch"; then
        status='503 Service Unavailable'
    fi
fi
if [ "$status" = '200 OK' ]; then
    exec 7>"$mutation_lease"
    flock -s 7
    mutation_lease_acquired=1
    if [ -e "$mutation_freeze_marker" ] || [ -L "$mutation_freeze_marker" ]; then
        status='423 Locked'
    fi
    if [ "$status" = '200 OK' ] \
        && [ "${HTTP_X_CONTROL_PLANE_MUTATION_GATE:-}" = hold ]; then
        printf '%s\n' "$request_method" >> /lab-state/mutation-inflight.ready
        while [ ! -e /lab-state/mutation-inflight.release ]; do
            sleep 0.1
        done
    fi
fi

if [ "$status" = '200 OK' ]; then
    case "$request_method" in
        GET|HEAD|OPTIONS)
            ;;
        *)
            printf '%s\n' "$color" >> "/lab-state/web-writes-${color}"
            ;;
    esac
fi

if [ "$mutation_lease_acquired" = 1 ]; then
    flock -u 7
fi

printf 'Status: %s\n' "$status"
printf 'Content-Type: text/plain\n'
printf 'X-Control-Plane-Color: %s\n' "$color"
printf 'X-Control-Plane-Lab-Host: %s\n' "${expected_host:-unset}"
printf 'X-Observed-Host: %s\n' "${HTTP_HOST:-missing}"
printf 'X-Observed-Forwarded-For: %s\n' "${HTTP_X_FORWARDED_FOR:-missing}"
printf 'X-Observed-Forwarded-Proto: %s\n\n' "${HTTP_X_FORWARDED_PROTO:-missing}"
printf '%s\n' "$color"

if [ -f "$writer_marker" ]; then
    :
fi
