#!/bin/sh

set -eu

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
    while true; do
        nc -l -p "$port" </dev/null >/dev/null 2>&1 || true
    done &
    listener_pids="$listener_pids $!"
done
wait
