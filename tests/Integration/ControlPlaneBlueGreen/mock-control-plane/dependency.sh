#!/bin/sh

set -eu

if [ "${CONTROL_PLANE_LAB_DEPENDENCY_RESPONDER:-}" = 1 ] && [ "$#" -eq 0 ]; then
    printf '+PONG\r\n'
    exit 0
fi

# Do not let the entrypoint mode be selected by its caller's environment.
unset CONTROL_PLANE_LAB_DEPENDENCY_RESPONDER
export CONTROL_PLANE_LAB_DEPENDENCY_RESPONDER=1

listener_pids=

stop_listeners()
{
    trap - TERM INT
    for listener_pid in $listener_pids; do
        kill "$listener_pid" >/dev/null 2>&1 || true
    done
    wait >/dev/null 2>&1 || true
    exit 0
}

trap stop_listeners TERM INT

for port in "$@"; do
    nc -lk -p "$port" -e "$0" >/dev/null 2>&1 &
    listener_pids="$listener_pids $!"
done
wait
