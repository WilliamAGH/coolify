#!/bin/sh

set -eu
umask 077

readonly REQUIRED_ASSET_ROLES='operator operator-compose rehearsal-compose https-controller port8000-controller port8000-external-policy-probe port8000-ipv6-inventory-probe backup-attestation-verifier backup-quiesce-controller runtime-fence-provisioner runtime-fence-controller runtime-fence-controlmaster-reaper runtime-fence-provider-probe runtime-fence-queue-probe runtime-fence-terminal-probe'
readonly GLOBAL_TRANSACTION_LOCK=/run/lock/coolify-control-plane-blue-green.lock

fail()
{
    printf 'CONTROL_PLANE_RELEASE_INSTALL_FAILURE %s\n' "$1" >&2
    exit 1
}

sha256_file()
{
    sha256sum "$1" | awk '{print $1}'
}

file_mode()
{
    stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"
}

file_uid()
{
    stat -c '%u' "$1" 2>/dev/null || stat -f '%u' "$1"
}

file_gid()
{
    stat -c '%g' "$1" 2>/dev/null || stat -f '%g' "$1"
}

file_link_count()
{
    stat -c '%h' "$1" 2>/dev/null || stat -f '%l' "$1"
}

file_device_inode()
{
    stat -c '%d:%i' "$1" 2>/dev/null || stat -f '%d:%i' "$1"
}

canonical_file_path()
{
    canonical_parent=$(CDPATH='' cd -- "${1%/*}" 2>/dev/null && pwd -P) \
        || fail "release asset parent is unavailable: ${1%/*}"
    printf '%s/%s\n' "${canonical_parent%/}" "${1##*/}"
}

acquire_global_transaction_lock()
{
    lock_parent=${global_transaction_lock%/*}
    [ -d "$lock_parent" ] && [ ! -L "$lock_parent" ] \
        || fail "global transaction lock parent is unsafe: $lock_parent"
    [ ! -e "$global_transaction_lock" ] \
        || { [ -f "$global_transaction_lock" ] && [ ! -L "$global_transaction_lock" ]; } \
        || fail "global transaction lock path is unsafe: $global_transaction_lock"
    exec 9> "$global_transaction_lock"
    chmod 0600 "$global_transaction_lock"
    if [ "$release_test_mode" = 0 ]; then
        [ "$(file_uid "$global_transaction_lock"):$(file_gid "$global_transaction_lock"):$(file_mode "$global_transaction_lock")" \
            = 0:0:600 ] \
            || fail 'production global transaction lock ownership or mode is unsafe'
    fi
    flock -n 9 \
        || fail "another control-plane transaction holds the lock: $global_transaction_lock"
}

assert_safe_parent_chain()
{
    checked_path=$1
    checked_parent=${checked_path%/*}
    [ -n "$checked_parent" ] || checked_parent=/
    [ "${checked_path#/}" != "$checked_path" ] \
        || fail "release asset path must be absolute: $checked_path"
    resolved_parent=$(CDPATH='' cd -- "$checked_parent" 2>/dev/null && pwd -P) \
        || fail "release asset parent is unavailable: $checked_parent"
    [ "$resolved_parent" = "$checked_parent" ] \
        || fail "release asset parent path contains a symlink: $checked_parent"
    current_parent=$checked_parent
    while [ "$current_parent" != / ]; do
        [ -d "$current_parent" ] && [ ! -L "$current_parent" ] \
            || fail "release asset parent is unsafe: $current_parent"
        if [ "$release_test_mode" = 0 ]; then
            [ "$(file_uid "$current_parent"):$(file_gid "$current_parent")" = 0:0 ] \
                || fail "production release asset parent is not root-owned: $current_parent"
            parent_mode=$(file_mode "$current_parent")
            [ $((0$parent_mode & 0022)) -eq 0 ] \
                || fail "production release asset parent is group/world writable: $current_parent"
        fi
        current_parent=${current_parent%/*}
        [ -n "$current_parent" ] || current_parent=/
    done
}

assert_asset()
{
    asset_role=$1
    asset_path=$2
    [ -f "$asset_path" ] && [ ! -L "$asset_path" ] \
        || fail "release asset must be a regular non-symlink file: $asset_role"
    asset_canonical_path=$(canonical_file_path "$asset_path")
    [ "$asset_canonical_path" = "$asset_path" ] \
        || fail "release asset path contains a symlink: $asset_role"
    [ "$(file_link_count "$asset_path")" = 1 ] \
        || fail "release asset link count must be exactly one: $asset_role"
    assert_safe_parent_chain "$asset_path"
    asset_uid=$(file_uid "$asset_path")
    asset_gid=$(file_gid "$asset_path")
    asset_mode=$(file_mode "$asset_path")
    [ "$asset_uid:$asset_gid" = "$immutable_uid:$immutable_gid" ] \
        || fail "release asset owner must be $immutable_uid:$immutable_gid: $asset_role"
    case "$asset_role" in
        operator-compose|rehearsal-compose)
            [ $((0$asset_mode & 0133)) -eq 0 ] \
                || fail "release data asset is writable or executable outside its owner: $asset_role"
            [ "$release_test_mode" = 1 ] || [ "$asset_mode" = 600 ] \
                || fail "production release data asset must have mode 0600: $asset_role"
            ;;
        *)
            [ -x "$asset_path" ] && [ $((0$asset_mode & 0022)) -eq 0 ] \
                || fail "release executable is absent, non-executable, or writable outside its owner: $asset_role"
            [ "$release_test_mode" = 1 ] || [ "$asset_mode" = 700 ] \
                || fail "production release executable must have mode 0700: $asset_role"
            ;;
    esac
}

assert_existing_manifest()
{
    [ -f "$manifest_path" ] && [ ! -L "$manifest_path" ] \
        || fail 'existing release manifest is not a regular non-symlink file'
    [ "$(canonical_file_path "$manifest_path")" = "$manifest_path" ] \
        || fail 'existing release manifest path contains a symlink'
    [ "$(file_link_count "$manifest_path")" = 1 ] \
        || fail 'existing release manifest link count is not exactly one'
    [ "$(file_uid "$manifest_path"):$(file_gid "$manifest_path"):$(file_mode "$manifest_path")" \
        = "$immutable_uid:$immutable_gid:600" ] \
        || fail 'existing release manifest ownership or mode is unsafe'
}

recover_stale_manifest_candidates()
{
    for stale_candidate in "${manifest_path}".new.[0-9]*; do
        [ -e "$stale_candidate" ] || [ -L "$stale_candidate" ] || continue
        [ -f "$stale_candidate" ] && [ ! -L "$stale_candidate" ] \
            || fail 'stale release manifest candidate is not a regular non-symlink file'
        [ "$(canonical_file_path "$stale_candidate")" = "$stale_candidate" ] \
            || fail 'stale release manifest candidate path contains a symlink'
        [ "$(file_link_count "$stale_candidate")" = 1 ] \
            || fail 'stale release manifest candidate link count is not exactly one'
        [ "$(file_uid "$stale_candidate"):$(file_gid "$stale_candidate"):$(file_mode "$stale_candidate")" \
            = "$immutable_uid:$immutable_gid:600" ] \
            || fail 'stale release manifest candidate ownership or mode is unsafe'
        [ -s "$stale_candidate" ] \
            || fail 'stale release manifest candidate is empty'
        rm -f -- "$stale_candidate" \
            || fail 'stale release manifest candidate cleanup failed'
        [ ! -e "$stale_candidate" ] && [ ! -L "$stale_candidate" ] \
            || fail 'stale release manifest candidate remained after cleanup'
    done
}

release_test_crash()
{
    [ "$release_test_mode" = 1 ] \
        && [ "$release_test_crash_point" = "$1" ] \
        || return 0

    trap - 0 HUP INT TERM
    exit 137
}

release_test_mode=${CONTROL_PLANE_RELEASE_TEST_MODE:-0}
[ "$release_test_mode" = 0 ] || [ "$release_test_mode" = 1 ] \
    || fail 'CONTROL_PLANE_RELEASE_TEST_MODE must be exactly 0 or 1'
release_test_crash_point=
if [ "$release_test_mode" = 0 ]; then
    [ -z "${CONTROL_PLANE_RELEASE_TEST_CRASH_AT:-}" ] \
        || fail 'production release installation rejects lab crash injection'
else
    release_test_crash_point=${CONTROL_PLANE_RELEASE_TEST_CRASH_AT:-}
    case "$release_test_crash_point" in
        ''|after-release-manifest-candidate-fsync|after-release-manifest-replaced|after-release-manifest-parent-fsync) ;;
        *) fail 'lab release crash injection point is unsupported' ;;
    esac
fi
if [ "$release_test_mode" = 0 ]; then
    [ "$(id -u)" = 0 ] || fail 'production release installation requires root'
    immutable_uid=0
    immutable_gid=0
    manifest_path=/etc/coolify-control-plane/release.manifest
    [ -z "${CONTROL_PLANE_RELEASE_MANIFEST_PATH:-}" ] \
        || [ "$CONTROL_PLANE_RELEASE_MANIFEST_PATH" = "$manifest_path" ] \
        || fail 'production release manifest path is fixed'
else
    immutable_uid=$(id -u)
    immutable_gid=$(id -g)
    manifest_path=${CONTROL_PLANE_RELEASE_MANIFEST_PATH:-}
    [ -n "$manifest_path" ] || fail 'lab release installation requires an explicit manifest path'
fi
if [ "$release_test_mode" = 0 ]; then
    [ -z "${CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE:-}" ] \
        || fail 'production release installation rejects a lab global lock override'
    global_transaction_lock=$GLOBAL_TRANSACTION_LOCK
else
    global_transaction_lock=${CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE:-}
    [ -n "$global_transaction_lock" ] \
        || fail 'lab release installation requires an explicit global transaction lock path'
fi
case "$global_transaction_lock" in
    /*) ;;
    *) fail 'global transaction lock path must be absolute' ;;
esac
acquire_global_transaction_lock

if [ "$release_test_mode" = 1 ] \
    && [ -n "${CONTROL_PLANE_RELEASE_TEST_LOCK_MARKER:-}" ]; then
    case "$CONTROL_PLANE_RELEASE_TEST_LOCK_MARKER" in
        /*) ;;
        *) fail 'lab release lock marker path must be absolute' ;;
    esac
    printf 'locked\n' > "$CONTROL_PLANE_RELEASE_TEST_LOCK_MARKER"
fi
if [ "$release_test_mode" = 1 ] \
    && [ "${CONTROL_PLANE_RELEASE_TEST_HOLD_LOCK_SECONDS:-0}" != 0 ]; then
    printf '%s' "$CONTROL_PLANE_RELEASE_TEST_HOLD_LOCK_SECONDS" | grep -Eq '^[1-9][0-9]*$' \
        || fail 'lab release lock hold must be a positive integer'
    sleep "$CONTROL_PLANE_RELEASE_TEST_HOLD_LOCK_SECONDS"
fi

release_id=${1:-}
shift || true
printf '%s' "$release_id" | grep -Eq '^[A-Za-z0-9._:-]{16,128}$' \
    || fail 'release ID must contain 16 to 128 safe token characters'
[ "$#" -eq 15 ] || fail 'exactly fifteen role=absolute-path release assets are required'

asset_inventory=$(mktemp "${TMPDIR:-/tmp}/coolify-release-assets.XXXXXX")
manifest_candidate="${manifest_path}.new.$$"
trap 'rm -f "$asset_inventory"; [ -z "${manifest_candidate:-}" ] || rm -f "$manifest_candidate"' \
    0 HUP INT TERM
: > "$asset_inventory"
for asset_specification in "$@"; do
    asset_role=${asset_specification%%=*}
    asset_path=${asset_specification#*=}
    [ "$asset_role" != "$asset_specification" ] && [ -n "$asset_path" ] \
        || fail 'release asset must use role=absolute-path syntax'
    case " $REQUIRED_ASSET_ROLES " in
        *" $asset_role "*) ;;
        *) fail "release asset role is not authorized: $asset_role" ;;
    esac
    printf '%s' "$asset_path" | grep -Eq '^/[A-Za-z0-9_./-]+$' \
        || fail "release asset path is unsafe: $asset_role"
    ! grep -F -q "${asset_role}|" "$asset_inventory" \
        || fail "release asset role is duplicated: $asset_role"
    asset_canonical_path=$(canonical_file_path "$asset_path")
    asset_device_inode=$(file_device_inode "$asset_path")
    ! awk -F'|' -v path="$asset_canonical_path" '$7 == path { found = 1 } END { exit !found }' \
        "$asset_inventory" \
        || fail "release asset canonical path is assigned to multiple roles: $asset_canonical_path"
    ! awk -F'|' -v identity="$asset_device_inode" '$8 == identity { found = 1 } END { exit !found }' \
        "$asset_inventory" \
        || fail "release asset device/inode is assigned to multiple roles: $asset_device_inode"
    assert_asset "$asset_role" "$asset_path"
    printf '%s|%s|%s|%s|%s|%s|%s|%s\n' "$asset_role" "$asset_path" \
        "$(sha256_file "$asset_path")" "$(file_uid "$asset_path")" \
        "$(file_gid "$asset_path")" "$(file_mode "$asset_path")" \
        "$asset_canonical_path" "$asset_device_inode" >> "$asset_inventory"
done

for required_role in $REQUIRED_ASSET_ROLES; do
    grep -F -q "${required_role}|" "$asset_inventory" \
        || fail "required release asset is absent: $required_role"
done

manifest_parent=${manifest_path%/*}
[ -n "$manifest_parent" ] || manifest_parent=/
if [ ! -e "$manifest_parent" ]; then
    [ "$release_test_mode" = 0 ] \
        || fail 'lab release manifest parent must already exist'
    manifest_grandparent=${manifest_parent%/*}
    [ -n "$manifest_grandparent" ] || manifest_grandparent=/
    assert_safe_parent_chain "$manifest_grandparent/placeholder"
    install -d -m 0700 -o root -g root "$manifest_parent"
fi
assert_safe_parent_chain "$manifest_parent/placeholder"
[ -d "$manifest_parent" ] && [ ! -L "$manifest_parent" ] \
    || fail 'release manifest parent is unsafe'
recover_stale_manifest_candidates
if [ -e "$manifest_path" ] || [ -L "$manifest_path" ]; then
    assert_existing_manifest
fi
[ ! -e "$manifest_candidate" ] && [ ! -L "$manifest_candidate" ] \
    || fail 'release manifest candidate already exists'
{
    printf 'version|1\nrelease|%s\n' "$release_id"
    LC_ALL=C sort "$asset_inventory" \
        | awk -F'|' 'BEGIN { OFS="|" } { print "asset", $1, $2, $3, $4, $5, $6 }'
} > "$manifest_candidate"
chown "$immutable_uid:$immutable_gid" "$manifest_candidate"
chmod 0600 "$manifest_candidate"
sync "$manifest_candidate"
[ "$(file_link_count "$manifest_candidate")" = 1 ] \
    || fail 'release manifest candidate link count is not exactly one'
release_test_crash after-release-manifest-candidate-fsync
mv -f -- "$manifest_candidate" "$manifest_path" \
    || fail 'release manifest atomic replacement failed'
manifest_candidate=
release_test_crash after-release-manifest-replaced
sync "$manifest_parent"
release_test_crash after-release-manifest-parent-fsync
[ -f "$manifest_path" ] && [ ! -L "$manifest_path" ] \
    && [ "$(file_link_count "$manifest_path")" = 1 ] \
    || fail 'published release manifest identity is unsafe'
assert_existing_manifest
trap - 0 HUP INT TERM
rm -f "$asset_inventory"

printf 'CONTROL_PLANE_RELEASE_INSTALL complete=true release_id=%s manifest=%s sha256=%s owner=%s:%s mode=0600\n' \
    "$release_id" "$manifest_path" "$(sha256_file "$manifest_path")" \
    "$immutable_uid" "$immutable_gid"
