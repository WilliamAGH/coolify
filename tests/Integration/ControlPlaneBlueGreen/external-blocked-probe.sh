#!/bin/sh

set -eu

[ "$#" -eq 4 ] \
    && [ "$1" = --endpoint ] \
    && [ "$3" = --timeout-seconds ] \
    && [ "$4" = 5 ] \
    || exit 64

endpoint=$2
address=${endpoint%:*}
port=${endpoint##*:}
[ "$address" != "$endpoint" ] || exit 64
printf '%s\n' "$address" | awk -F . '
    NF != 4 { exit 1 }
    {
        for (octet = 1; octet <= 4; octet++) {
            if ($octet !~ /^[0-9]+$/ || $octet > 255) {
                exit 1
            }
        }
    }
' || exit 64
case "$port" in
    ''|*[!0-9]*) exit 64 ;;
esac
[ "$port" -ge 1 ] && [ "$port" -le 65535 ] || exit 64

probe_output=$(mktemp "${TMPDIR:-/tmp}/control-plane-port8000-external-probe.XXXXXX")
trap 'rm -f "$probe_output"' EXIT
probe_status=0
nc -v -z -w "$4" "$address" "$port" > "$probe_output" 2>&1 || probe_status=$?

[ "$probe_status" -ne 0 ] || exit 1
if grep -F -i -q 'Connection refused' "$probe_output" \
    || grep -E -i -q '(operation |connection )?timed out' "$probe_output"; then
    :
else
    exit 1
fi

printf 'CONTROL_PLANE_PORT8000_EXTERNAL_POLICY result=blocked endpoint=%s\n' "$2"
