#!/bin/sh

set -eu

state_directory="/lab-state/services-${CONTROL_PLANE_COLOR:-legacy}"
mkdir -p "$state_directory"

service_state()
{
    cat "$state_directory/$1" 2>/dev/null || printf '%s\n' down
}

case "${1:-}" in
    initialize)
        startup_mode=${CONTROL_PLANE_STARTUP_MODE:-full}
        if [ "$startup_mode" = full ]; then
            printf '%s\n' up > "$state_directory/horizon"
            printf '%s\n' up > "$state_directory/scheduler-worker"
            printf '%s\n' up > "$state_directory/nightwatch-agent"
        else
            printf '%s\n' down > "$state_directory/horizon"
            printf '%s\n' down > "$state_directory/scheduler-worker"
            printf '%s\n' down > "$state_directory/nightwatch-agent"
        fi
        : > "$state_directory/reserved-count"
        printf '%s\n' 0 > "$state_directory/reserved-count"
        printf '%s\n' 0 > "$state_directory/queued-count"
        printf '%s\n' 0 > "$state_directory/executed-count"
        rm -f "$state_directory/held-mutation-pid" \
            "$state_directory/held-mutation-child-pid" \
            "$state_directory/held-mutation-child-ready" \
            "$state_directory/held-mutation-identity" \
            "$state_directory/held-mutation-completed"
        ;;
    down)
        printf '%s\n' down > "$state_directory/$2"
        ;;
    up)
        printf '%s\n' up > "$state_directory/$2"
        ;;
    pause)
        [ "$2" = horizon ] || exit 64
        printf '%s\n' paused > "$state_directory/horizon"
        ;;
    terminate)
        [ "$2" = horizon ] || exit 64
        printf '%s\n' down > "$state_directory/horizon"
        ;;
    continue)
        [ "$2" = horizon ] || exit 64
        printf '%s\n' up > "$state_directory/horizon"
        ;;
    queues)
        printf '%s\n' high default
        ;;
    reserved-count)
        cat "$state_directory/reserved-count"
        ;;
    schedule-idle)
        [ ! -e "$state_directory/schedule-held" ]
        ;;
    tcp-active)
        port=$(printf '%04X' "${CONTROL_PLANE_BACKEND_PORT:-8080}")
        awk -v port=":${port}" '
            NR > 1 && index($2, port) == length($2) - length(port) + 1 && $4 == "01" { count++ }
            END { print count + 0 }
        ' /proc/net/tcp /proc/net/tcp6 2>/dev/null
        ;;
    resume-all)
        printf '%s\n' up > "$state_directory/horizon"
        printf '%s\n' up > "$state_directory/scheduler-worker"
        printf '%s\n' up > "$state_directory/nightwatch-agent"
        ;;
    resume-promoted)
        [ "${HORIZON_ENABLED:-true}" = false ] || printf '%s\n' up > "$state_directory/horizon"
        [ "${SCHEDULER_ENABLED:-true}" = false ] || printf '%s\n' up > "$state_directory/scheduler-worker"
        [ "${NIGHTWATCH_ENABLED:-false}" = false ] || printf '%s\n' up > "$state_directory/nightwatch-agent"
        ;;
    fence-writer)
        printf '%s\n' sleeping > "$state_directory/horizon"
        printf '%s\n' sleeping > "$state_directory/scheduler-worker"
        printf '%s\n' sleeping > "$state_directory/nightwatch-agent"
        ;;
    writer-fenced)
        [ "$(service_state horizon)" = sleeping ] \
            && [ "$(service_state scheduler-worker)" = sleeping ] \
            && [ "$(service_state nightwatch-agent)" = sleeping ]
        ;;
    service-up)
        [ "$(service_state "$2")" = up ]
        ;;
    service-state)
        service_state "$2"
        ;;
    horizon-master-state)
        case "$(service_state horizon)" in
            up) printf '%s\n' running ;;
            paused) printf '%s\n' paused ;;
            down) printf '%s\n' missing ;;
            *) exit 1 ;;
        esac
        ;;
    set-reserved)
        printf '%s\n' "$2" > "$state_directory/reserved-count"
        ;;
    queue-work)
        printf '%s\n' "$2" > "$state_directory/queued-count"
        ;;
    queue-count)
        cat "$state_directory/queued-count"
        ;;
    executed-count)
        cat "$state_directory/executed-count"
        ;;
    reset-work-counts)
        printf '%s\n' 0 > "$state_directory/reserved-count"
        printf '%s\n' 0 > "$state_directory/queued-count"
        printf '%s\n' 0 > "$state_directory/executed-count"
        ;;
    hold-schedule)
        : > "$state_directory/schedule-held"
        ;;
    release-schedule)
        rm -f "$state_directory/schedule-held"
        ;;
    hold-mutation)
        printf '%s\n' "$$" > "$state_directory/held-mutation-pid"
        printf '%s:%s:%s\n' "$(id -u)" "$(id -g)" "$(id -G)" \
            > "$state_directory/held-mutation-identity"
        python3 -c '
import os
import pathlib
import signal
import sys
import time

os.setpgid(0, 0)
signal.signal(signal.SIGTERM, signal.SIG_IGN)
pathlib.Path(sys.argv[2]).touch()
time.sleep(int(sys.argv[1]))
pathlib.Path(sys.argv[3]).touch()
' "$2" "$state_directory/held-mutation-child-ready" \
            "$state_directory/held-mutation-completed" &
        held_child_pid=$!
        printf '%s\n' "$held_child_pid" > "$state_directory/held-mutation-child-pid"
        wait "$held_child_pid"
        ;;
    held-mutation-state)
        held_pid=$(cat "$state_directory/held-mutation-pid" 2>/dev/null || true)
        case "$held_pid" in
            ''|*[!0-9]*) printf '%s\n' absent ;;
            *)
                held_state=$(sed 's/^.*) //' "/proc/$held_pid/stat" 2>/dev/null \
                    | awk '{print $1}')
                case "$held_state" in
                    ''|Z*) printf '%s\n' gone ;;
                    *) printf '%s\n' running ;;
                esac
                ;;
        esac
        ;;
    held-mutation-child-state)
        held_pid=$(cat "$state_directory/held-mutation-child-pid" 2>/dev/null || true)
        case "$held_pid" in
            ''|*[!0-9]*) printf '%s\n' absent ;;
            *)
                held_state=$(sed 's/^.*) //' "/proc/$held_pid/stat" 2>/dev/null \
                    | awk '{print $1}')
                case "$held_state" in
                    ''|Z*) printf '%s\n' gone ;;
                    *) printf '%s\n' running ;;
                esac
                ;;
        esac
        ;;
    held-mutation-child-ready)
        [ -e "$state_directory/held-mutation-child-ready" ]
        ;;
    held-mutation-identity)
        cat "$state_directory/held-mutation-identity"
        ;;
    held-mutation-process-tree)
        held_pid=$(cat "$state_directory/held-mutation-pid")
        held_child_pid=$(cat "$state_directory/held-mutation-child-pid")
        held_stat=$(sed 's/^.*) //' "/proc/$held_pid/stat")
        # shellcheck disable=SC2086 # Proc fields require intentional word splitting.
        set -- $held_stat
        held_process_group=$3
        held_session=$4
        child_stat=$(sed 's/^.*) //' "/proc/$held_child_pid/stat")
        # shellcheck disable=SC2086 # Proc fields require intentional word splitting.
        set -- $child_stat
        child_process_group=$3
        child_session=$4
        printf '%s:%s:%s:%s:%s:%s\n' "$held_pid" "$held_process_group" \
            "$held_session" "$held_child_pid" "$child_process_group" "$child_session"
        ;;
    held-mutation-completed)
        [ -e "$state_directory/held-mutation-completed" ]
        ;;
    maintenance-state)
        if [ -f "$state_directory/maintenance" ]; then
            maintenance_sha256=$(sha256sum "$state_directory/maintenance" | awk '{print $1}')
            printf 'present:%s\n' "$maintenance_sha256"
        else
            printf '%s\n' absent:none
        fi
        ;;
    maintenance-metadata)
        stat -c '%u:%g:%a' "$state_directory/maintenance"
        ;;
    maintenance-export)
        cat "$state_directory/maintenance"
        ;;
    maintenance-acquire)
        maintenance_candidate="$state_directory/.maintenance.$$"
        printf '%s\n' '{"retry":60}' > "$maintenance_candidate"
        mv "$maintenance_candidate" "$state_directory/maintenance"
        sync
        ;;
    maintenance-set-prestate)
        maintenance_candidate="$state_directory/.maintenance.$$"
        printf '%s\n' '{"retry":120,"secret":"preexisting-bypass"}' \
            > "$maintenance_candidate"
        chmod 640 "$maintenance_candidate"
        mv "$maintenance_candidate" "$state_directory/maintenance"
        sync
        ;;
    maintenance-restore)
        maintenance_candidate="$state_directory/.maintenance.$$"
        cat > "$maintenance_candidate"
        chown "$2:$3" "$maintenance_candidate"
        chmod "$4" "$maintenance_candidate"
        mv "$maintenance_candidate" "$state_directory/maintenance"
        sync
        ;;
    maintenance-release)
        rm -f "$state_directory/maintenance"
        sync
        ;;
    *)
        exit 64
        ;;
esac
