#!/bin/sh

set -eu

fail()
{
    printf '%s\n' "CONTROL_PLANE_BLUE_GREEN_IPV6_INVENTORY_PROBE_FAILURE $1" >&2
    exit 1
}

[ "$#" -eq 4 ] \
    && [ "$1" = --inventory-file ] \
    && [ "$3" = --timeout-seconds ] \
    && [ "$4" = 5 ] \
    || fail 'unexpected arguments'

inventory_file=$2
[ -f "$inventory_file" ] && [ ! -L "$inventory_file" ] \
    || fail 'inventory file is not a regular non-symlink file'

inventory_sha256=$(sha256sum "$inventory_file" | awk '{print $1}')
address_count=$(awk -F= '
    /^(interface_global_ipv6|dns_aaaa|provider_endpoint|external_vantage)=/ \
        && $2 != "none" { address[$2] = 1 }
    END { for (entry in address) count++; print count + 0 }
' "$inventory_file")
denial_count=$(awk -F= '$1 == "external_denial" && $2 != "none" { count++ } END { print count + 0 }' \
    "$inventory_file")

printf 'CONTROL_PLANE_PORT8000_IPV6_INVENTORY result=pass inventory_sha256=%s address_count=%s denial_count=%s\n' \
    "$inventory_sha256" "$address_count" "$denial_count"
