#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
readonly SCRIPT_DIRECTORY
readonly OPERATOR_SOURCE="$SCRIPT_DIRECTORY/../control-plane-blue-green.sh"
readonly CONTROLLER_SOURCE="$SCRIPT_DIRECTORY/control-plane-backup-quiesce.sh"
readonly SERVICE_SOURCE="$SCRIPT_DIRECTORY/control-plane-backup-quiesce-watchdog.service"
readonly TIMER_SOURCE="$SCRIPT_DIRECTORY/control-plane-backup-quiesce-watchdog.timer"
readonly STATE_DIRECTORY=/var/lib/coolify/control-plane-backup-quiesce

fail()
{
    printf 'CONTROL_PLANE_BACKUP_QUIESCE_COLD_BOOT_FAILURE %s\n' "$1" >&2
    exit 1
}

[[ $(id -u) == 0 ]] || fail 'cold-boot acceptance must run as root'
declare -A installed=(
    ["$OPERATOR_SOURCE"]=/usr/local/sbin/control-plane-blue-green
    ["$CONTROLLER_SOURCE"]=/usr/local/libexec/coolify/control-plane-backup-quiesce
    ["$SERVICE_SOURCE"]=/etc/systemd/system/control-plane-backup-quiesce-watchdog.service
    ["$TIMER_SOURCE"]=/etc/systemd/system/control-plane-backup-quiesce-watchdog.timer
)
for source_path in "${!installed[@]}"; do
    installed_path=${installed[$source_path]}
    [[ -f $installed_path && ! -L $installed_path ]] \
        || fail "installed backup quiesce asset is absent: $installed_path"
    [[ $(stat -c '%u:%g' "$installed_path") == 0:0 ]] \
        || fail "installed backup quiesce asset is not owned by root:root: $installed_path"
    [[ $(sha256sum "$installed_path" | awk '{print $1}') \
        == "$(sha256sum "$source_path" | awk '{print $1}')" ]] \
        || fail "installed backup quiesce asset differs from reviewed source: $installed_path"
done
[[ $(stat -c '%a' /usr/local/sbin/control-plane-blue-green) == 755 \
    && $(stat -c '%a' /usr/local/libexec/coolify/control-plane-backup-quiesce) == 755 \
    && $(stat -c '%a' /etc/systemd/system/control-plane-backup-quiesce-watchdog.service) == 644 \
    && $(stat -c '%a' /etc/systemd/system/control-plane-backup-quiesce-watchdog.timer) == 644 \
    && -d $STATE_DIRECTORY && ! -L $STATE_DIRECTORY \
    && $(stat -c '%u:%g:%a' "$STATE_DIRECTORY") == 0:0:700 ]] \
    || fail 'installed backup quiesce asset or state-directory modes are not exact'
systemctl is-enabled --quiet control-plane-backup-quiesce-watchdog.timer \
    || fail 'watchdog timer is not enabled after cold boot'
systemctl is-active --quiet control-plane-backup-quiesce-watchdog.timer \
    || fail 'watchdog timer is not active after cold boot'
systemctl start control-plane-backup-quiesce-watchdog.service

printf 'backup-quiesce-cold-boot=passed;operator_sha256=%s;controller_sha256=%s;service_sha256=%s;timer_sha256=%s\n' \
    "$(sha256sum "$OPERATOR_SOURCE" | awk '{print $1}')" \
    "$(sha256sum "$CONTROLLER_SOURCE" | awk '{print $1}')" \
    "$(sha256sum "$SERVICE_SOURCE" | awk '{print $1}')" \
    "$(sha256sum "$TIMER_SOURCE" | awk '{print $1}')"
