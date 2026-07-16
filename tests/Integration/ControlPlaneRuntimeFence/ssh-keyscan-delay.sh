#!/usr/bin/env bash

set -Eeuo pipefail

delay_file=${FIXTURE_ROOT:-/run}/ssh-keyscan-delay-seconds
if [[ -f $delay_file ]]; then
    sleep "$(<"$delay_file")"
fi
exec /usr/bin/ssh-keyscan "$@"
