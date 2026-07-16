#!/usr/bin/env bash
set -euo pipefail

delay_file=${FIXTURE_ROOT:-/run}/provider-delay-seconds
if [[ -f $delay_file ]]; then
    delay_seconds=$(cat "$delay_file")
    [[ $delay_seconds =~ ^[1-9][0-9]*$ ]]
    sleep "$delay_seconds"
fi

expectation=${CONTROL_PLANE_RUNTIME_PROVIDER_EXPECTATION:?}
retired_member_count=${CONTROL_PLANE_RUNTIME_RETIRED_MEMBER_COUNT:?}
retired_a_name=${CONTROL_PLANE_RUNTIME_RETIRED_A_NAME:?}
retired_a_id=${CONTROL_PLANE_RUNTIME_RETIRED_A_ID:?}
retired_b_name=${CONTROL_PLANE_RUNTIME_RETIRED_B_NAME:?}
retired_b_id=${CONTROL_PLANE_RUNTIME_RETIRED_B_ID:?}
member_set_sha256=$(
    {
        printf '%s\0' "$expectation" "$retired_member_count"
        printf '%s\0' retired_a "$retired_a_name" "$retired_a_id"
        printf '%s\0' retired_b "$retired_b_name" "$retired_b_id"
    } | sha256sum | awk '{print $1}'
)
if [[ $expectation == absent ]]; then
    retired_a_backend_url=absent
    retired_b_backend_url=absent
else
    retired_a_backend_url=http://172.18.0.3:8080
    if [[ $retired_member_count == 2 ]]; then
        retired_b_backend_url=http://172.18.0.5:8080
    else
        retired_b_backend_url=absent
    fi
fi
backend_set_sha256=$(
    {
        printf '%s\0' retired_a "$retired_a_backend_url"
        printf '%s\0' retired_b "$retired_b_backend_url"
    } | sha256sum | awk '{print $1}'
)

printf 'version=2\nstatus=fresh\nobserved_at_epoch=%s\noperation_id=%s\nproxy_id=%s\nproxy_pid=1111\n' \
    "$(date -u +%s)" "$CONTROL_PLANE_RUNTIME_OPERATION_ID" "$CONTROL_PLANE_RUNTIME_PROXY_ID"
printf 'expectation=%s\nretired_member_count=%s\nretired_a_name=%s\nretired_a_id=%s\n' \
    "$expectation" "$retired_member_count" "$retired_a_name" "$retired_a_id"
printf 'retired_b_name=%s\nretired_b_id=%s\nretired_a_backend_url=%s\nretired_b_backend_url=%s\n' \
    "$retired_b_name" "$retired_b_id" "$retired_a_backend_url" "$retired_b_backend_url"
printf 'member_set_sha256=%s\nbackend_set_sha256=%s\nsnapshot_sha256=%064d\n' \
    "$member_set_sha256" "$backend_set_sha256" 0
