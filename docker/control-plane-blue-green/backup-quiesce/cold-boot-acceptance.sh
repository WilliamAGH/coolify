#!/usr/bin/env bash

set -euo pipefail

readonly PRODUCTION_STATE_DIRECTORY=/var/lib/coolify/control-plane-backup-quiesce
readonly PRODUCTION_RELEASE_MANIFEST=/etc/coolify-control-plane/release.manifest
readonly PRODUCTION_RELEASES_ROOT=/usr/local/lib/coolify-control-plane/releases
readonly PRODUCTION_RELEASE_DISPATCHER=/usr/local/libexec/coolify-control-plane-release-dispatch
readonly PRODUCTION_RELEASE_LAUNCHER=/usr/local/sbin/control-plane-blue-green
readonly PRODUCTION_SERVICE_UNIT=/etc/systemd/system/control-plane-backup-quiesce-watchdog.service
readonly PRODUCTION_TIMER_UNIT=/etc/systemd/system/control-plane-backup-quiesce-watchdog.timer

fail()
{
    printf 'CONTROL_PLANE_BACKUP_QUIESCE_COLD_BOOT_FAILURE %s\n' "$1" >&2
    exit 1
}

sha256_file()
{
    sha256sum "$1" | awk '{print $1}'
}

file_uid()
{
    stat -c '%u' "$1" 2>/dev/null || stat -f '%u' "$1"
}

file_gid()
{
    stat -c '%g' "$1" 2>/dev/null || stat -f '%g' "$1"
}

file_mode()
{
    stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"
}

file_link_count()
{
    stat -c '%h' "$1" 2>/dev/null || stat -f '%l' "$1"
}

host_path()
{
    if [[ $cold_boot_test_mode == 1 ]]; then
        printf '%s%s\n' "$cold_boot_test_root" "$1"
    else
        printf '%s\n' "$1"
    fi
}

canonical_file_path()
{
    local path=$1 parent

    parent=$(cd -- "${path%/*}" 2>/dev/null && pwd -P) \
        || fail "file parent is unavailable: ${path%/*}"
    printf '%s/%s\n' "${parent%/}" "${path##*/}"
}

assert_regular_single_link()
{
    local path=$1 expected_mode=$2 label=$3

    [[ -f $path && ! -L $path \
        && $(canonical_file_path "$path") == "$path" \
        && $(file_uid "$path"):$(file_gid "$path"):$(file_mode "$path"):$(file_link_count "$path") \
            == "$immutable_uid:$immutable_gid:$expected_mode:1" ]] \
        || fail "$label identity is unsafe"
}

manifest_asset_line()
{
    local role=$1

    awk -F'|' -v role="$role" \
        '$1 == "asset" && $2 == role { print; count++ } END { exit count == 1 ? 0 : 1 }' \
        "$RELEASE_MANIFEST" \
        || fail "active release manifest does not select exactly one asset: $role"
}

assert_manifest_asset()
{
    local role=$1 expected_relative=$2 expected_mode=$3
    local line record_type manifest_role path expected_sha256 expected_uid expected_gid manifest_mode

    line=$(manifest_asset_line "$role")
    IFS='|' read -r record_type manifest_role path expected_sha256 expected_uid expected_gid manifest_mode \
        <<< "$line"
    [[ $record_type == asset && $manifest_role == "$role" \
        && $path == "$active_release_directory/$expected_relative" \
        && $expected_uid:$expected_gid == "$immutable_uid:$immutable_gid" \
        && $manifest_mode == "$expected_mode" ]] \
        || fail "active release manifest metadata is unauthorized: $role"
    assert_regular_single_link "$path" "$expected_mode" "active release asset $role"
    [[ $(sha256_file "$path") == "$expected_sha256" ]] \
        || fail "active release asset identity is unsafe: $role"
    printf '%s\n' "$path"
}

cold_boot_test_mode=${CONTROL_PLANE_BACKUP_QUIESCE_COLD_BOOT_TEST_MODE:-0}
[[ $cold_boot_test_mode == 0 || $cold_boot_test_mode == 1 ]] \
    || fail 'cold-boot acceptance test mode must be exactly 0 or 1'
if [[ $cold_boot_test_mode == 0 ]]; then
    [[ $(id -u) == 0 ]] || fail 'cold-boot acceptance must run as root'
    [[ -z ${CONTROL_PLANE_BACKUP_QUIESCE_COLD_BOOT_TEST_ROOT:-} ]] \
        || fail 'production cold-boot acceptance rejects a lab host root'
    cold_boot_test_root=
    immutable_uid=0
    immutable_gid=0
else
    cold_boot_test_root=${CONTROL_PLANE_BACKUP_QUIESCE_COLD_BOOT_TEST_ROOT:-}
    [[ $cold_boot_test_root == /* && -d $cold_boot_test_root && ! -L $cold_boot_test_root ]] \
        || fail 'lab cold-boot acceptance requires an absolute non-symlink host root'
    cold_boot_test_root=$(cd -- "$cold_boot_test_root" && pwd -P)
    immutable_uid=$(file_uid "$cold_boot_test_root")
    immutable_gid=$(file_gid "$cold_boot_test_root")
fi

STATE_DIRECTORY=$(host_path "$PRODUCTION_STATE_DIRECTORY")
RELEASE_MANIFEST=$(host_path "$PRODUCTION_RELEASE_MANIFEST")
RELEASES_ROOT=$(host_path "$PRODUCTION_RELEASES_ROOT")
RELEASE_DISPATCHER=$(host_path "$PRODUCTION_RELEASE_DISPATCHER")
RELEASE_LAUNCHER=$(host_path "$PRODUCTION_RELEASE_LAUNCHER")
SERVICE_UNIT=$(host_path "$PRODUCTION_SERVICE_UNIT")
TIMER_UNIT=$(host_path "$PRODUCTION_TIMER_UNIT")
readonly STATE_DIRECTORY RELEASE_MANIFEST RELEASES_ROOT RELEASE_DISPATCHER RELEASE_LAUNCHER
readonly SERVICE_UNIT TIMER_UNIT

assert_regular_single_link "$RELEASE_MANIFEST" 600 'active release manifest'
[[ $(sed -n '1p' "$RELEASE_MANIFEST") == 'version|1' ]] \
    || fail 'active release manifest version is unsupported'
release_id=$(sed -n '2s/^release|//p' "$RELEASE_MANIFEST")
[[ $release_id =~ ^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$ ]] \
    || fail 'active release ID is unsafe'
active_release_directory="$RELEASES_ROOT/$release_id"
[[ -d $active_release_directory && ! -L $active_release_directory \
    && $(cd -- "$active_release_directory" && pwd -P) == "$active_release_directory" \
    && $(file_uid "$active_release_directory"):$(file_gid "$active_release_directory"):$(file_mode "$active_release_directory") \
        == "$immutable_uid:$immutable_gid:755" ]] \
    || fail 'active release directory identity is unsafe'

assert_regular_single_link "$RELEASE_DISPATCHER" 700 'installed release dispatcher'
assert_regular_single_link "$RELEASE_LAUNCHER" 755 'installed release launcher'
[[ $(sha256_file "$RELEASE_DISPATCHER") == "$(sha256_file "$RELEASE_LAUNCHER")" ]] \
    || fail 'stable dispatcher and launcher bytes or modes disagree'

operator_path=$(assert_manifest_asset operator control-plane-blue-green.sh 755)
controller_path=$(assert_manifest_asset \
    backup-quiesce-controller backup-quiesce/control-plane-backup-quiesce.sh 755)
release_service_unit=$(assert_manifest_asset \
    backup-quiesce-service-unit \
    backup-quiesce/control-plane-backup-quiesce-watchdog.service 644)
release_timer_unit=$(assert_manifest_asset \
    backup-quiesce-timer-unit \
    backup-quiesce/control-plane-backup-quiesce-watchdog.timer 644)

assert_regular_single_link "$SERVICE_UNIT" 644 'stable backup-quiesce service unit'
assert_regular_single_link "$TIMER_UNIT" 644 'stable backup-quiesce timer unit'
[[ $(sha256_file "$SERVICE_UNIT") == "$(sha256_file "$release_service_unit")" \
    && $(sha256_file "$TIMER_UNIT") == "$(sha256_file "$release_timer_unit")" \
    && -d $STATE_DIRECTORY && ! -L $STATE_DIRECTORY \
    && $(cd -- "$STATE_DIRECTORY" && pwd -P) == "$STATE_DIRECTORY" \
    && $(file_uid "$STATE_DIRECTORY"):$(file_gid "$STATE_DIRECTORY"):$(file_mode "$STATE_DIRECTORY") \
        == "$immutable_uid:$immutable_gid:700" ]] \
    || fail 'stable backup-quiesce units or state directory differ from the active release'

systemctl is-enabled --quiet control-plane-backup-quiesce-watchdog.timer \
    || fail 'watchdog timer is not enabled after cold boot'
systemctl is-active --quiet control-plane-backup-quiesce-watchdog.timer \
    || fail 'watchdog timer is not active after cold boot'
systemctl start control-plane-backup-quiesce-watchdog.service

printf 'backup-quiesce-cold-boot=passed;dispatcher_sha256=%s;operator_sha256=%s;controller_sha256=%s;service_sha256=%s;timer_sha256=%s\n' \
    "$(sha256_file "$RELEASE_DISPATCHER")" \
    "$(sha256_file "$operator_path")" \
    "$(sha256_file "$controller_path")" \
    "$(sha256_file "$SERVICE_UNIT")" \
    "$(sha256_file "$TIMER_UNIT")"
