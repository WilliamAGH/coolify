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
    printf 'CONTROL_PLANE_BACKUP_QUIESCE_INSTALL_FAILURE %s\n' "$1" >&2
    exit 1
}

[[ $(id -u) == 0 ]] || fail 'installer must run as root'
for command_name in awk flock install sha256sum stat systemctl; do
    command -v "$command_name" >/dev/null 2>&1 \
        || fail "required command is unavailable: $command_name"
done
for source_path in "$OPERATOR_SOURCE" "$CONTROLLER_SOURCE" "$SERVICE_SOURCE" "$TIMER_SOURCE"; do
    [[ -f $source_path && ! -L $source_path ]] \
        || fail "reviewed source is not a regular non-symlink file: $source_path"
done
for install_path in \
    /usr/local/sbin/control-plane-blue-green \
    /usr/local/libexec/coolify/control-plane-backup-quiesce \
    /etc/systemd/system/control-plane-backup-quiesce-watchdog.service \
    /etc/systemd/system/control-plane-backup-quiesce-watchdog.timer \
    "$STATE_DIRECTORY"; do
    [[ ! -L $install_path ]] \
        || fail "backup quiesce install path must not be a symlink: $install_path"
done
[[ -d /etc/systemd/system && ! -L /etc/systemd/system \
    && $(stat -c '%u:%g:%a' /etc/systemd/system) == 0:0:755 ]] \
    || fail 'systemd unit directory must be root:root mode 0755'
install -d -o root -g root -m 0755 /usr/local/sbin /usr/local/libexec/coolify
install -d -o root -g root -m 0700 "$STATE_DIRECTORY"
[[ -d $STATE_DIRECTORY && ! -L $STATE_DIRECTORY \
    && $(stat -c '%u:%g:%a' "$STATE_DIRECTORY") == 0:0:700 ]] \
    || fail 'backup quiesce state directory must be root:root mode 0700'
readonly LOCK_FILE="$STATE_DIRECTORY/.lock"
[[ ! -L $LOCK_FILE ]] || fail 'backup quiesce install lock must not be a symlink'
exec 8> "$LOCK_FILE"
chmod 0600 "$LOCK_FILE"
[[ -f $LOCK_FILE && ! -L $LOCK_FILE \
    && $(stat -c '%u:%g:%a' "$LOCK_FILE") == 0:0:600 ]] \
    || fail 'backup quiesce install lock must be root:root mode 0600'
flock 8
if [[ -e $STATE_DIRECTORY/active || -L $STATE_DIRECTORY/active ]]; then
    fail 'an active backup quiesce lease forbids operator/controller replacement'
fi

install -o root -g root -m 0755 "$OPERATOR_SOURCE" /usr/local/sbin/control-plane-blue-green
install -o root -g root -m 0755 "$CONTROLLER_SOURCE" \
    /usr/local/libexec/coolify/control-plane-backup-quiesce
install -o root -g root -m 0644 "$SERVICE_SOURCE" \
    /etc/systemd/system/control-plane-backup-quiesce-watchdog.service
install -o root -g root -m 0644 "$TIMER_SOURCE" \
    /etc/systemd/system/control-plane-backup-quiesce-watchdog.timer

systemctl daemon-reload
systemctl enable --now control-plane-backup-quiesce-watchdog.timer

declare -A installed=(
    ["$OPERATOR_SOURCE"]=/usr/local/sbin/control-plane-blue-green
    ["$CONTROLLER_SOURCE"]=/usr/local/libexec/coolify/control-plane-backup-quiesce
    ["$SERVICE_SOURCE"]=/etc/systemd/system/control-plane-backup-quiesce-watchdog.service
    ["$TIMER_SOURCE"]=/etc/systemd/system/control-plane-backup-quiesce-watchdog.timer
)
for source_path in "${!installed[@]}"; do
    installed_path=${installed[$source_path]}
    [[ -f $installed_path && ! -L $installed_path ]] \
        || fail "installed backup quiesce asset is invalid: $installed_path"
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
    && $(stat -c '%u:%g:%a' "$STATE_DIRECTORY") == 0:0:700 ]] \
    || fail 'installed backup quiesce asset or state-directory modes are not exact'
systemctl is-enabled --quiet control-plane-backup-quiesce-watchdog.timer \
    || fail 'watchdog timer is not enabled'
systemctl is-active --quiet control-plane-backup-quiesce-watchdog.timer \
    || fail 'watchdog timer is not active'

printf 'backup-quiesce-install=passed;operator_sha256=%s;controller_sha256=%s;service_sha256=%s;timer_sha256=%s\n' \
    "$(sha256sum "$OPERATOR_SOURCE" | awk '{print $1}')" \
    "$(sha256sum "$CONTROLLER_SOURCE" | awk '{print $1}')" \
    "$(sha256sum "$SERVICE_SOURCE" | awk '{print $1}')" \
    "$(sha256sum "$TIMER_SOURCE" | awk '{print $1}')"
