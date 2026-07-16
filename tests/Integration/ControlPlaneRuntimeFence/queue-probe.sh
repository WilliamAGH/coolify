#!/usr/bin/env bash
set -euo pipefail
fixture_root=${FIXTURE_ROOT:-/run}
if [[ -f $fixture_root/queue-delay-seconds ]]; then
    sleep "$(<"$fixture_root/queue-delay-seconds")"
fi
pending=0
[[ ! -e $fixture_root/queue-nonzero ]] || pending=1
if [[ -f $fixture_root/queue-nonzero-after-count ]]; then
    probe_count=0
    [[ ! -f $fixture_root/queue-probe-count ]] \
        || probe_count=$(<"$fixture_root/queue-probe-count")
    probe_count=$((probe_count + 1))
    printf '%s\n' "$probe_count" > "$fixture_root/queue-probe-count"
    [[ $probe_count -lt $(<"$fixture_root/queue-nonzero-after-count") ]] || pending=1
fi
version=2
[[ ! -f $fixture_root/queue-probe-version ]] \
    || version=$(<"$fixture_root/queue-probe-version")
printf 'version=%s\npending=%s\nreserved=0\ndelayed=0\nrunning=0\nobserved_at_epoch=%s\noperation_id=%s\n' \
    "$version" "$pending" "$(date -u +%s)" "$CONTROL_PLANE_RUNTIME_OPERATION_ID"
