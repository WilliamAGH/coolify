#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

SCRIPT_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
readonly SCRIPT_DIRECTORY
readonly CONFIG_DIRECTORY=/etc/coolify-runtime-attestation-ssh-fence
readonly STATE_DIRECTORY=/var/lib/coolify-runtime-attestation-ssh-fence
readonly CONTROLLER_DESTINATION=/usr/local/libexec/coolify-runtime-attestation-ssh-fence
readonly REAPER_DESTINATION=/usr/local/libexec/coolify-self-ssh-controlmaster-reaper
readonly PROVIDER_PROBE_DESTINATION=/usr/local/libexec/coolify-traefik-provider-freshness-probe
readonly QUEUE_PROBE_DESTINATION=/usr/local/libexec/coolify-proxy-queue-zero-probe
readonly TERMINAL_PROBE_DESTINATION=/usr/local/libexec/coolify-control-plane-terminal-state-probe
readonly PROVISION_DESTINATION=/usr/local/sbin/coolify-runtime-fence-provision
readonly SERVICE_NAME=coolify-runtime-attestation-ssh-fence.service
readonly WATCHDOG_SERVICE_NAME=coolify-runtime-attestation-ssh-fence-watchdog.service
readonly GLOBAL_TRANSACTION_LOCK=/run/lock/coolify-control-plane-blue-green.lock

fail()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE_INSTALL_FAILURE %s\n' "$1" >&2
    exit 1
}

file_link_count()
{
    stat -c '%h' "$1"
}

acquire_global_transaction_lock()
{
    local lock_parent=${global_transaction_lock%/*}
    [[ -d $lock_parent && ! -L $lock_parent ]] \
        || fail "global transaction lock parent is unsafe: $lock_parent"
    [[ ! -e $global_transaction_lock \
        || (-f $global_transaction_lock && ! -L $global_transaction_lock) ]] \
        || fail "global transaction lock path is unsafe: $global_transaction_lock"
    exec 9> "$global_transaction_lock"
    chmod 0600 "$global_transaction_lock"
    if [[ $runtime_install_test_mode == 0 ]]; then
        [[ $(stat -c '%u:%g:%a' "$global_transaction_lock") == 0:0:600 ]] \
            || fail 'production global transaction lock ownership or mode is unsafe'
    fi
    flock -n 9 \
        || fail "another control-plane transaction holds the lock: $global_transaction_lock"
}

assert_installed_file()
{
    local role=$1 source=$2 destination=$3 mode=$4 executable=$5
    [[ -f $destination && ! -L $destination \
        && $(readlink -f -- "$destination") == "$destination" \
        && $(stat -c '%u:%g:%a' "$destination") == "0:0:$mode" \
        && $(file_link_count "$destination") == 1 \
        && $(sha256sum "$source" | awk '{print $1}') \
            == "$(sha256sum "$destination" | awk '{print $1}')" ]] \
        || fail "installed runtime fence asset identity changed: $role"
    [[ $executable == 0 || -x $destination ]] \
        || fail "installed runtime fence executable is not executable: $role"
}

install_file_atomically()
{
    local role=$1 source=$2 destination=$3 mode=$4 executable=$5
    local destination_directory candidate
    destination_directory=$(dirname -- "$destination")
    candidate=$destination_directory/.${destination##*/}.new.$$
    [[ -d $destination_directory && ! -L $destination_directory \
        && ! -e $candidate && ! -L $candidate ]] \
        || fail "runtime fence install destination is unsafe: $role"
    install -m "$mode" -o root -g root "$source" "$candidate"
    assert_installed_file "$role" "$source" "$candidate" "$mode" "$executable"
    sync "$candidate"
    mv -f -- "$candidate" "$destination"
    sync "$destination_directory"
    assert_installed_file "$role" "$source" "$destination" "$mode" "$executable"
}

runtime_install_test_mode=${CONTROL_PLANE_RUNTIME_INSTALL_TEST_MODE:-0}
[[ $runtime_install_test_mode == 0 || $runtime_install_test_mode == 1 ]] \
    || fail 'runtime installer test mode must be exactly 0 or 1'
if [[ $runtime_install_test_mode == 0 ]]; then
    [[ -z ${CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE:-} ]] \
        || fail 'production runtime installer rejects a lab global lock override'
    global_transaction_lock=$GLOBAL_TRANSACTION_LOCK
else
    global_transaction_lock=${CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE:-}
    [[ $global_transaction_lock == /* ]] \
        || fail 'lab runtime installer requires an explicit absolute global lock path'
fi
acquire_global_transaction_lock
if [[ $runtime_install_test_mode == 1 ]]; then
    [[ ${CONTROL_PLANE_RUNTIME_INSTALL_TEST_LOCK_ONLY:-0} == 1 ]] \
        || fail 'lab runtime installer only supports the lock-only contract probe'
    runtime_install_hold=${CONTROL_PLANE_RUNTIME_INSTALL_TEST_HOLD_LOCK_SECONDS:-0}
    [[ $runtime_install_hold =~ ^[0-9]+$ ]] \
        || fail 'lab runtime installer lock hold must be a nonnegative integer'
    ((runtime_install_hold == 0)) || sleep "$runtime_install_hold"
    printf 'CONTROL_PLANE_RUNTIME_FENCE_INSTALL lock_probe=true\n'
    exit 0
fi

[[ $(id -u) -eq 0 ]] || fail 'installation requires root'
# The installer deliberately pins the authoritative host OS release file.
# shellcheck disable=SC1091
[[ $(. /etc/os-release; printf '%s:%s' "$ID" "$VERSION_ID") == ubuntu:24.04 ]] \
    || fail 'installer is pinned to Ubuntu 24.04'
[[ $(dpkg-query -W -f='${Version}' nftables 2>/dev/null || true) == 1.0.9-1ubuntu0.1 ]] \
    || fail 'exact nftables 1.0.9-1ubuntu0.1 must already be installed'
[[ $(dpkg-query -W -f='${Version}' conntrack 2>/dev/null || true) == 1:1.4.8-1ubuntu1 ]] \
    || fail 'exact conntrack 1:1.4.8-1ubuntu1 must already be installed'
for command in conntrack curl docker flock ip ipcalc jq nft nsenter sha256sum ssh ssh-keyscan systemctl unshare; do
    command -v "$command" >/dev/null 2>&1 || fail "required command is unavailable: $command"
done

if [[ -e $CONFIG_DIRECTORY/runtime.env ]]; then
    [[ -f $CONFIG_DIRECTORY/runtime.env && ! -L $CONFIG_DIRECTORY/runtime.env \
        && $(stat -c '%u:%g:%a' "$CONFIG_DIRECTORY/runtime.env") == 0:0:600 ]] \
        || fail 'existing runtime.env is unsafe'
    ! grep -F -x -q CONTROL_PLANE_RUNTIME_ARMED=1 "$CONFIG_DIRECTORY/runtime.env" \
        || fail 'refusing to replace hash-attested executables while the fence is armed'
fi

install -d -m 0700 -o root -g root "$CONFIG_DIRECTORY" "$STATE_DIRECTORY"
install -d -m 0755 -o root -g root /usr/local/libexec
install_file_atomically runtime-fence-controller \
    "$SCRIPT_DIRECTORY/runtime-attestation-ssh-fence.sh" "$CONTROLLER_DESTINATION" 0700 1
install_file_atomically runtime-fence-controlmaster-reaper \
    "$SCRIPT_DIRECTORY/self-ssh-controlmaster-reaper.sh" "$REAPER_DESTINATION" 0700 1
install_file_atomically runtime-fence-provider-probe \
    "$SCRIPT_DIRECTORY/traefik-docker-provider-freshness-probe.sh" \
    "$PROVIDER_PROBE_DESTINATION" 0700 1
install_file_atomically runtime-fence-queue-probe \
    "$SCRIPT_DIRECTORY/proxy-queue-zero-probe.sh" "$QUEUE_PROBE_DESTINATION" 0700 1
install_file_atomically runtime-fence-terminal-probe \
    "$SCRIPT_DIRECTORY/control-plane-terminal-state-probe.sh" \
    "$TERMINAL_PROBE_DESTINATION" 0700 1
install_file_atomically runtime-fence-provisioner \
    "$SCRIPT_DIRECTORY/provision-runtime-attestation-ssh-fence.sh" \
    "$PROVISION_DESTINATION" 0700 1
install_file_atomically runtime-fence-service-unit \
    "$SCRIPT_DIRECTORY/$SERVICE_NAME" "/etc/systemd/system/$SERVICE_NAME" 0644 0
install_file_atomically runtime-fence-watchdog-unit \
    "$SCRIPT_DIRECTORY/$WATCHDOG_SERVICE_NAME" \
    "/etc/systemd/system/$WATCHDOG_SERVICE_NAME" 0644 0

if [[ ! -e $CONFIG_DIRECTORY/runtime.env ]]; then
    printf 'CONTROL_PLANE_RUNTIME_ARMED=0\nCONTROL_PLANE_RUNTIME_STATE_DIR=%s\n' \
        "$STATE_DIRECTORY" > "$CONFIG_DIRECTORY/.runtime.env.$$"
    chown root:root "$CONFIG_DIRECTORY/.runtime.env.$$"
    chmod 0600 "$CONFIG_DIRECTORY/.runtime.env.$$"
    sync "$CONFIG_DIRECTORY/.runtime.env.$$"
    mv -f -- "$CONFIG_DIRECTORY/.runtime.env.$$" "$CONFIG_DIRECTORY/runtime.env"
    sync "$CONFIG_DIRECTORY"
fi
[[ -f $CONFIG_DIRECTORY/runtime.env && ! -L $CONFIG_DIRECTORY/runtime.env \
    && $(stat -c '%u:%g:%a' "$CONFIG_DIRECTORY/runtime.env") == 0:0:600 ]] \
    || fail 'runtime.env must be a root:root mode 0600 regular non-symlink file'

systemctl daemon-reload
assert_installed_file runtime-fence-controller \
    "$SCRIPT_DIRECTORY/runtime-attestation-ssh-fence.sh" "$CONTROLLER_DESTINATION" 0700 1
"$CONTROLLER_DESTINATION" systemd-contract-sha256 >/dev/null
systemctl disable "$SERVICE_NAME" "$WATCHDOG_SERVICE_NAME" >/dev/null 2>&1 || true
[[ ! -e /etc/systemd/system/docker.service.requires/$SERVICE_NAME \
    && ! -e /etc/systemd/system/docker.socket.requires/$SERVICE_NAME \
    && ! -e /etc/systemd/system/docker.service.wants/$WATCHDOG_SERVICE_NAME ]] \
    || fail 'unarmed installation unexpectedly blocks a Docker activation path'

printf 'CONTROL_PLANE_RUNTIME_FENCE_INSTALL complete=true armed=false services_enabled=false\n'
