#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

readonly CONFIG_DIRECTORY=${CONTROL_PLANE_PORT8000_CONFIG_DIR:-/etc/coolify-control-plane-port8000}
readonly ACTIVE_BUNDLE="$CONFIG_DIRECTORY/active.nft"

fail()
{
    printf 'CONTROL_PLANE_PORT8000_BOOT_NFT_FAILURE %s\n' "$1" >&2
    exit 1
}

assert_safe_directory()
{
    local directory=$1 resolved
    [[ $directory == /* && -d $directory && ! -L $directory ]] \
        || fail 'configuration directory is not an absolute regular directory'
    resolved=$(readlink -f -- "$directory")
    [[ $resolved == "$directory" ]] || fail 'configuration directory has a symlink component'
    [[ $(stat -c '%a:%u:%g' "$directory") == 700:0:0 ]] \
        || fail 'configuration directory must be root-owned mode 0700'
}

[[ $(id -u) -eq 0 ]] || fail 'nftables boot apply requires root'
assert_safe_directory "$CONFIG_DIRECTORY"
[[ -f $ACTIVE_BUNDLE && ! -L $ACTIVE_BUNDLE ]] || fail 'active bundle is not a regular non-symlink file'
[[ $(stat -c '%a:%u:%g' "$ACTIVE_BUNDLE") == 600:0:0 ]] || fail 'active bundle metadata changed'

format=$(sed -n '1s/^# coolify-port8000-active-bundle-format=//p' "$ACTIVE_BUNDLE")
declared_payload_sha256=$(sed -n '2s/^# payload-sha256=//p' "$ACTIVE_BUNDLE")
[[ $format == 1 ]] || fail 'active bundle format is unsupported'
[[ $declared_payload_sha256 =~ ^[a-f0-9]{64}$ ]] || fail 'active bundle payload checksum is malformed'
actual_payload_sha256=$(tail -n +3 "$ACTIVE_BUNDLE" | sha256sum | awk '{print $1}')
[[ $actual_payload_sha256 == "$declared_payload_sha256" ]] || fail 'active bundle payload checksum changed'

mode=$(sed -n '3s/^# coolify-port8000-mode=//p' "$ACTIVE_BUNDLE")
operation_id=$(sed -n '4s/^# coolify-port8000-operation=//p' "$ACTIVE_BUNDLE")
table_name=$(sed -n '5s/^# coolify-port8000-table=//p' "$ACTIVE_BUNDLE")
[[ $mode == capture || $mode == canary || $mode == post-switch || $mode == absent ]] \
    || fail 'active bundle mode is unsafe'
[[ $operation_id =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ ]] || fail 'active bundle operation is unsafe'
[[ $table_name =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ ]] || fail 'active bundle table is unsafe'

case "$mode" in
    capture|canary) selected_instance=phase-a ;;
    post-switch|absent) selected_instance=phase-b ;;
esac
selected_unit="coolify-port8000-haproxy@${selected_instance}.service"
systemctl is-active --quiet "$selected_unit" \
    || fail "selected HAProxy instance is not active: $selected_instance"

transaction=$(mktemp /run/coolify-port8000-nft.XXXXXX)
trap 'rm -f -- "$transaction"' EXIT
if nft list table inet "$table_name" >/dev/null 2>&1; then
    nft -s list table inet "$table_name" | grep -F "operation=$operation_id" >/dev/null \
        || fail 'refusing to replace an nftables table owned by another operation'
    printf 'delete table inet %s\n' "$table_name" > "$transaction"
else
    : > "$transaction"
fi

if [[ $mode != absent ]]; then
    [[ $(grep -E -c '^table inet [A-Za-z0-9][A-Za-z0-9_.-]{0,127} \{$' "$ACTIVE_BUNDLE") -eq 1 ]] \
        || fail 'active bundle must own exactly one safe inet table'
    grep -F -x -q "table inet $table_name {" "$ACTIVE_BUNDLE" \
        || fail 'active bundle payload table differs from its header'
    grep -F -q "operation=$operation_id mode=$mode" "$ACTIVE_BUNDLE" \
        || fail 'active bundle payload ownership differs from its header'
    tail -n +6 "$ACTIVE_BUNDLE" >> "$transaction"
else
    [[ $(wc -l < "$ACTIVE_BUNDLE" | tr -d '[:space:]') == 5 ]] \
        || fail 'absent bundle must not contain nftables commands'
fi

nft -c -f "$transaction" || fail 'active nftables transaction failed validation'
nft -f "$transaction" || fail 'active nftables transaction failed atomically'
if [[ $mode == absent ]]; then
    ! nft list table inet "$table_name" >/dev/null 2>&1 \
        || fail 'owned nftables table survived absent-mode restore'
else
    nft -s list table inet "$table_name" | grep -F "operation=$operation_id mode=$mode" >/dev/null \
        || fail 'active nftables ownership was not installed'
fi

systemctl is-active --quiet "$selected_unit" \
    || fail "selected HAProxy instance stopped during nftables activation: $selected_instance"
accepted=0
for ((attempt = 0; attempt < 50; attempt += 1)); do
    if { exec 3<>/dev/tcp/127.0.0.1/8000; } 2>/dev/null; then
        exec 3>&- 3<&-
        accepted=1
        break
    fi
    sleep 0.1
done
[[ $accepted -eq 1 ]] \
    || fail "selected HAProxy path did not accept TCP/8000: $selected_instance"
