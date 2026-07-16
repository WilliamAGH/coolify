#!/bin/sh

set -eu

writer_marker_path=${CONTROL_PLANE_WRITER_MARKER_PATH:-/var/lib/coolify-control-plane/writer-epoch}
web_marker_path=${CONTROL_PLANE_WEB_MARKER_PATH:-/var/lib/coolify-control-plane/web-epoch}

marker_matches()
{
    marker_path=$1
    expected_epoch=$2

    [ -f "$marker_path" ] \
        && [ "$(wc -c < "$marker_path" | tr -d '[:space:]')" = "${#expected_epoch}" ] \
        && [ "$(cat "$marker_path")" = "$expected_epoch" ]
}

write_marker()
{
    marker_path=$1
    marker_epoch=$2
    candidate="${marker_path}.$$"

    if marker_matches "$marker_path" "$marker_epoch"; then
        return
    fi
    [ ! -e "$marker_path" ] || exit 75
    umask 077
    printf '%s' "$marker_epoch" > "$candidate"
    mv "$candidate" "$marker_path"
    sync
}

activate_web()
{
    [ "${CONTROL_PLANE_MODE:-}" = active ] || exit 64
    [ "${CONTROL_PLANE_STARTUP_MODE:-}" = web-only ] || exit 64
    [ "${CONTROL_PLANE_WEB_ACTIVATION_CONFIRM:-}" = route-switch-pending ] || exit 64
    epoch=${CONTROL_PLANE_WEB_EPOCH:-}
    [ "${#epoch}" -ge 16 ] || exit 64
    write_marker "$web_marker_path" "$epoch"
    printf '%s\n' 'Control plane web activation completed.'
}

promote_writer()
{
    [ "${CONTROL_PLANE_MODE:-}" = active ] || exit 64
    [ "${CONTROL_PLANE_STARTUP_MODE:-}" = web-only ] || exit 64
    [ "${CONTROL_PLANE_PROMOTION_CONFIRM:-}" = blue-stopped ] || exit 64
    [ "${CONTROL_PLANE_LAB_FAIL_PROMOTION_UNPROVEN:-0}" != 1 ] || exit 70
    [ "${CONTROL_PLANE_LAB_FAIL_PROMOTION:-0}" != 1 ] || exit 1
    epoch=${CONTROL_PLANE_WRITER_EPOCH:-}
    [ "${#epoch}" -ge 16 ] || exit 64
    write_marker "$writer_marker_path" "$epoch"
    [ "${CONTROL_PLANE_LAB_STOP_AFTER_WRITER_MARKER:-0}" != 1 ] || exit 70
    /usr/local/bin/control-plane-lab-service resume-promoted
    printf '%s\n' 'Control plane writer promotion completed.'
}

fence_writer()
{
    [ "${CONTROL_PLANE_MODE:-}" = active ] || exit 64
    [ "${CONTROL_PLANE_STARTUP_MODE:-}" = web-only ] || exit 64
    [ ! -e "$writer_marker_path" ] || exit 75
    [ "${CONTROL_PLANE_LAB_FAIL_FENCE:-0}" != 1 ] || exit 70
    /usr/local/bin/control-plane-lab-service fence-writer
    /usr/local/bin/control-plane-lab-service writer-fenced
    printf '%s\n' 'Control plane writer fencing completed.'
}

service_enabled()
{
    setting_name=$1
    default_value=$2
    case "$setting_name" in
        HORIZON_ENABLED)
            setting_value=${HORIZON_ENABLED:-$default_value}
            ;;
        SCHEDULER_ENABLED)
            setting_value=${SCHEDULER_ENABLED:-$default_value}
            ;;
        NIGHTWATCH_ENABLED)
            setting_value=${NIGHTWATCH_ENABLED:-$default_value}
            ;;
        *)
            return 1
            ;;
    esac
    [ "$setting_value" = true ] || return 1
    [ "${CONTROL_PLANE_MODE:-active}" = active ] || return 1
    if [ "${CONTROL_PLANE_STARTUP_MODE:-full}" = web-only ]; then
        marker_matches "$writer_marker_path" "${CONTROL_PLANE_WRITER_EPOCH:-}" || return 1
    fi
}

horizon_behavior()
{
    state_directory="/lab-state/services-${CONTROL_PLANE_COLOR:-legacy}"
    while sleep 0.1; do
        [ "$(cat "$state_directory/horizon" 2>/dev/null || printf down)" = up ] || continue
        [ ! -f "$state_directory/maintenance" ] || continue
        queued_count=$(cat "$state_directory/queued-count" 2>/dev/null || printf 0)
        [ "$queued_count" -gt 0 ] || continue
        printf '%s\n' 1 > "$state_directory/reserved-count"
        printf '%s\n' "$((queued_count - 1))" > "$state_directory/queued-count"
        executed_count=$(cat "$state_directory/executed-count" 2>/dev/null || printf 0)
        printf '%s\n' "$((executed_count + 1))" > "$state_directory/executed-count"
        printf '%s\n' 0 > "$state_directory/reserved-count"
    done
}

case "${1:-}" in
    mode)
        printf '%s\n' "${CONTROL_PLANE_MODE:-active}"
        ;;
    startup-mode)
        printf '%s\n' "${CONTROL_PLANE_STARTUP_MODE:-full}"
        ;;
    activate-web)
        activate_web
        ;;
    web-activated)
        marker_matches "$web_marker_path" "${CONTROL_PLANE_WEB_EPOCH:-}"
        ;;
    promote-writer)
        promote_writer
        ;;
    fence-writer)
        fence_writer
        ;;
    writer-fenced)
        [ ! -e "$writer_marker_path" ] \
            && /usr/local/bin/control-plane-lab-service writer-fenced
        ;;
    service-enabled)
        service_enabled "$2" "$3"
        ;;
    '')
        /usr/local/bin/control-plane-lab-service initialize
        horizon_behavior &
        exec httpd -f -p "${CONTROL_PLANE_BACKEND_PORT:-8080}" -h /srv/www
        ;;
    *)
        exec "$@"
        ;;
esac
