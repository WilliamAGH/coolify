#!/usr/bin/env bash

set -Eeuo pipefail

readonly REAL_SYSTEMCTL=/usr/bin/systemctl

derive_dockerd_pid()
{
    local pid

    pid=$(pgrep -xo dockerd)
    [[ $pid =~ ^[1-9][0-9]*$ && -r /proc/$pid/stat ]] || exit 1
    printf '%s\n' "$pid"
}

derive_dockerd_invocation_id()
{
    local pid process_stat start_ticks

    pid=$(derive_dockerd_pid)
    process_stat=$(sed 's/^.*) //' "/proc/$pid/stat")
    # shellcheck disable=SC2086 # Linux proc stat fields must be split after removing comm.
    set -- $process_stat
    [[ $# -ge 20 ]]
    shift 19
    start_ticks=$1
    [[ $start_ticks =~ ^[1-9][0-9]*$ ]]
    printf '%s:%s:%s\n' "$(</proc/sys/kernel/random/boot_id)" "$pid" "$start_ticks" \
        | sha256sum | awk '{print substr($1, 1, 32)}'
}

if [[ $# -eq 5 && $1 == show && $2 == docker.service \
    && $3 == --property && $5 == --value ]]; then
    observed=$($REAL_SYSTEMCTL "$@")
    case $4 in
        MainPID)
            if [[ $observed =~ ^[1-9][0-9]*$ ]]; then
                printf '%s\n' "$observed"
            else
                derive_dockerd_pid
            fi
            exit
            ;;
        InvocationID)
            if [[ $observed =~ ^[a-f0-9]{32}$ ]]; then
                printf '%s\n' "$observed"
            else
                derive_dockerd_invocation_id
            fi
            exit
            ;;
    esac
fi

exec "$REAL_SYSTEMCTL" "$@"
