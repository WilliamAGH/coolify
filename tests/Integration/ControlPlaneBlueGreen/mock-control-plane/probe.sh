#!/bin/sh

set -eu

token=$(cat /run/secrets/control-plane-direct-probe-token)
acknowledgement=$(cat /run/secrets/control-plane-applied-ack)
expected_host=${LAB_EXPECTED_HOST:-unset}

if [ "${HTTP_X_CONTROL_PLANE_PROBE:-}" != "$token" ]; then
    printf '%s\n' 'Status: 403 Forbidden'
    printf '%s\n\n' 'Content-Type: text/plain'
    printf '%s\n' 'forbidden'
    exit 0
fi

printf '%s\n' 'Content-Type: text/plain'
printf 'X-Control-Plane-Lab-Host: %s\n' "$expected_host"
printf 'X-Control-Plane-Applied-Config: %s\n\n' "$acknowledgement"
printf '%s\n' 'ok'
