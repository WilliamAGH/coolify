#!/bin/sh
# shellcheck disable=SC2016 # This test intentionally renders isolated shell fixtures and source-contract literals.

set -eu

SCRIPT_DIRECTORY=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd -P)
REPOSITORY_ROOT=$(CDPATH='' cd -- "$SCRIPT_DIRECTORY/../../.." && pwd -P)
readonly CONTRACT="$REPOSITORY_ROOT/docker/control-plane-blue-green/release-assets.contract"
readonly INSTALLER="$REPOSITORY_ROOT/docker/control-plane-blue-green/install-host-release-bundle.sh"
readonly DISPATCHER_SOURCE="$REPOSITORY_ROOT/docker/control-plane-blue-green/release-dispatch.sh"
readonly BACKUP_COMPONENT_INSTALLER="$REPOSITORY_ROOT/docker/control-plane-blue-green/backup-quiesce/install-host-prerequisites.sh"
readonly RUNTIME_COMPONENT_INSTALLER="$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/install-runtime-attestation-ssh-fence.sh"
readonly COLD_BOOT_ACCEPTANCE="$REPOSITORY_ROOT/docker/control-plane-blue-green/backup-quiesce/cold-boot-acceptance.sh"
readonly SYSTEMD_ENABLEMENT_UNITS='control-plane-backup-quiesce-watchdog.timer coolify-runtime-attestation-ssh-fence.service coolify-runtime-attestation-ssh-fence-watchdog.service'
readonly LEGACY_PORT8000_UNITS='coolify-port8000-nft.service coolify-port8000-haproxy@phase-a.service coolify-port8000-haproxy@phase-b.service coolify-port8000-phase-b-authorizer.service'

fail()
{
    printf 'CONTROL_PLANE_HOST_RELEASE_BUNDLE_FAILURE %s\n' "$1" >&2
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

prepare_source()
(
    source_root=$1
    operator_label=$2
    mkdir -p "$source_root"
    while IFS='|' read -r role source_relative destination_relative mode _dispatchable; do
        source_path="$source_root/$source_relative"
        mkdir -p "${source_path%/*}"
        cp "$REPOSITORY_ROOT/docker/control-plane-blue-green/$source_relative" "$source_path"
    done < "$CONTRACT"
    while IFS='|' read -r _role source_relative _destination_relative mode _dispatchable; do
        [ "$mode" = 644 ] || continue
        printf '\n# fixture-release=%s\n' "$operator_label" >> "$source_root/$source_relative"
    done < "$CONTRACT"
    {
        printf '%s\n' '#!/bin/sh' 'set -eu'
        printf "operator_label='%s'\n" "$operator_label"
        # shellcheck disable=SC2016 # The generated fixture expands release variables at dispatch time.
        printf '%s\n' 'printf '\''operator=%s release=%s manifest_sha256=%s args=%s\n'\'' "$operator_label" "${CONTROL_PLANE_RELEASE_ID:-}" "${CONTROL_PLANE_RELEASE_MANIFEST_SHA256:-}" "$*"'
    } > "$source_root/control-plane-blue-green.sh"
    chmod 0755 "$source_root/control-plane-blue-green.sh"
)

run_bundle()
{
    host_root=$1
    source_root=$2
    release_id=$3
    shift 3
    CONTROL_PLANE_RELEASE_BUNDLE_TEST_MODE=1 \
        CONTROL_PLANE_RELEASE_BUNDLE_TEST_ROOT="$host_root" \
        CONTROL_PLANE_RELEASE_BUNDLE_SOURCE_ROOT="$source_root" \
        "$@" "$INSTALLER" "$release_id"
}

prepare_fake_systemctl()
{
    fake_systemd_bin="$TEST_ROOT/fake-systemd-bin"
    mkdir -p "$fake_systemd_bin"
    {
        printf '%s\n' '#!/bin/sh' 'set -eu'
        # shellcheck disable=SC2016 # The generated fake expands these variables at execution time.
        printf '%s\n' \
            'state_directory=${FAKE_SYSTEMD_STATE_DIRECTORY:?}' \
            'command_name=${1:-}' \
            'shift || true' \
            'printf '\''%s'\'' "$command_name" >> "$state_directory/commands.log"' \
            'for command_argument in "$@"; do printf '\''|%s'\'' "$command_argument" >> "$state_directory/commands.log"; done' \
            'printf '\''\n'\'' >> "$state_directory/commands.log"' \
            'case "$command_name" in' \
            '    daemon-reload)' \
            '        [ "$#" -eq 0 ]' \
            '        ;;' \
            '    is-enabled)' \
            '        [ "$#" -eq 1 ]' \
            '        state_file="$state_directory/state.$1"' \
            '        if [ -f "$state_file" ]; then' \
            '            state=$(sed -n '\''1p'\'' "$state_file")' \
            '        else' \
            '            case "$1" in' \
            '                coolify-port8000-haproxy@phase-a.service|coolify-port8000-haproxy@phase-b.service) definition=coolify-port8000-haproxy@.service ;;' \
            '                *) definition=$1 ;;' \
            '            esac' \
            '            if [ -f "${FAKE_SYSTEMD_UNIT_ROOT:?}/$definition" ]; then state=disabled; else state=not-found; fi' \
            '        fi' \
            '        printf '\''is-enabled-result|%s|%s\n'\'' "$1" "$state" >> "$state_directory/commands.log"' \
            '        printf '\''%s\n'\'' "$state"' \
            '        case "$state" in' \
            '            enabled) exit 0 ;;' \
            '            disabled) exit 1 ;;' \
            '            not-found) exit 4 ;;' \
            '            *) exit 3 ;;' \
            '        esac' \
            '        ;;' \
            '    enable|disable)' \
            '        [ "$#" -eq 1 ]' \
            '        [ "${FAKE_SYSTEMD_FAIL_ACTION:-}" != "$command_name:$1" ] || exit 23' \
            '        [ "${FAKE_SYSTEMD_NOOP_ACTION:-}" != "$command_name:$1" ] || exit 0' \
            '        case "$command_name" in enable) state=enabled ;; disable) state=disabled ;; esac' \
            '        printf '\''%s\n'\'' "$state" > "$state_directory/state.$1"' \
            '        ;;' \
            '    is-active)' \
            '        quiet=0' \
            '        if [ "${1:-}" = --quiet ]; then quiet=1; shift; fi' \
            '        [ "$#" -eq 1 ]' \
            '        [ "${FAKE_SYSTEMD_FAIL_ACTION:-}" != "is-active:$1" ] || exit 23' \
            '        state_file="$state_directory/active.$1"' \
            '        if [ -f "$state_file" ]; then state=$(sed -n '\''1p'\'' "$state_file"); else state=inactive; fi' \
            '        printf '\''is-active-result|%s|%s\n'\'' "$1" "$state" >> "$state_directory/commands.log"' \
            '        [ "$quiet" -eq 1 ] || printf '\''%s\n'\'' "$state"' \
            '        case "$state" in active) exit 0 ;; inactive|failed) exit 3 ;; not-found) exit 4 ;; *) exit 2 ;; esac' \
            '        ;;' \
            '    start|stop)' \
            '        [ "$#" -eq 1 ]' \
            '        [ "${FAKE_SYSTEMD_FAIL_ACTION:-}" != "$command_name:$1" ] || exit 23' \
            '        [ "${FAKE_SYSTEMD_NOOP_ACTION:-}" != "$command_name:$1" ] || exit 0' \
            '        case "$command_name" in start) state=active ;; stop) state=inactive ;; esac' \
            '        printf '\''%s\n'\'' "$state" > "$state_directory/active.$1"' \
            '        ;;' \
            '    *)' \
            '        exit 64' \
            '        ;;' \
            'esac'
    } > "$fake_systemd_bin/systemctl"
    chmod 0755 "$fake_systemd_bin/systemctl"
}

run_bundle_with_systemd()
{
    host_root=$1
    source_root=$2
    release_id=$3
    state_directory=$4
    shift 4
    CONTROL_PLANE_RELEASE_BUNDLE_TEST_SYSTEMD=1 \
        FAKE_SYSTEMD_STATE_DIRECTORY="$state_directory" \
        FAKE_SYSTEMD_UNIT_ROOT="$host_root/etc/systemd/system" \
        PATH="$fake_systemd_bin:$PATH" \
        run_bundle "$host_root" "$source_root" "$release_id" "$@"
}

dispatch_with_systemd()
{
    host_root=$1
    state_directory=$2
    shift 2
    dispatch_environment "$host_root" env \
        FAKE_SYSTEMD_STATE_DIRECTORY="$state_directory" \
        FAKE_SYSTEMD_UNIT_ROOT="$host_root/etc/systemd/system" \
        PATH="$fake_systemd_bin:$PATH" "$@"
}

systemd_target_state()
{
    case "$1" in
        control-plane-backup-quiesce-watchdog.timer)
            printf '%s\n' enabled
            ;;
        coolify-runtime-attestation-ssh-fence.service|coolify-runtime-attestation-ssh-fence-watchdog.service)
            printf '%s\n' disabled
            ;;
        *) fail "test has no systemd target state for $1" ;;
    esac
}

set_fake_systemd_state()
{
    state_directory=$1
    unit=$2
    state=$3
    printf '%s\n' "$state" > "$state_directory/state.$unit"
}

set_fake_systemd_active_state()
{
    state_directory=$1
    unit=$2
    state=$3
    printf '%s\n' "$state" > "$state_directory/active.$unit"
}

assert_fake_systemd_state()
{
    state_directory=$1
    unit=$2
    expected_state=$3
    state_file="$state_directory/state.$unit"
    if [ -f "$state_file" ]; then
        observed_state=$(sed -n '1p' "$state_file")
    else
        observed_state=disabled
    fi
    [ "$observed_state" = "$expected_state" ] \
        || fail "fake systemd state differs for $unit: expected $expected_state, observed $observed_state"
}

assert_target_systemd_states()
{
    state_directory=$1
    for unit in $SYSTEMD_ENABLEMENT_UNITS; do
        assert_fake_systemd_state "$state_directory" "$unit" \
            "$(systemd_target_state "$unit")"
    done
}

assert_activation_enablements()
{
    host_root=$1
    enablements="$host_root/etc/coolify-control-plane/release.activation/enablements"
    host_uid=$(file_uid "$host_root")
    host_gid=$(file_gid "$host_root")
    [ -f "$enablements" ] && [ ! -L "$enablements" ] \
        && [ "$(file_uid "$enablements"):$(file_gid "$enablements"):$(file_mode "$enablements")" \
            = "$host_uid:$host_gid:600" ] \
        || fail 'systemd enablement journal identity is unsafe'
    [ "$(wc -l < "$enablements" | tr -d ' ')" = 4 ] \
        || fail 'systemd enablement journal does not contain exactly three units'
    for unit in $SYSTEMD_ENABLEMENT_UNITS; do
        [ "$(awk -F'|' -v unit="$unit" '$1 == "unit" && $2 == unit { count++ } END { print count + 0 }' "$enablements")" = 1 ] \
            || fail "systemd enablement journal unit inventory differs: $unit"
        recorded_target=$(awk -F'|' -v unit="$unit" '$1 == "unit" && $2 == unit { print $4 }' "$enablements")
        [ "$recorded_target" = "$(systemd_target_state "$unit")" ] \
            || fail "systemd enablement journal target differs: $unit"
    done
}

prepare_legacy_port8000_installation()
{
    host_root=$1
    state_directory=$2
    unit_root="$host_root/etc/systemd/system"
    config_root="$host_root/etc/coolify-control-plane-port8000"
    state_root="$host_root/var/lib/coolify-control-plane-port8000"
    runtime_root="$host_root/run/coolify-control-plane-port8000"
    library_root="$host_root/usr/local/lib/coolify-control-plane-port8000/haproxy-2.8.26"
    mkdir -p "$unit_root/multi-user.target.wants" \
        "$unit_root/docker.service.requires" "$unit_root/docker.socket.requires" \
        "$config_root" "$state_root/haproxy/phase-a/0123456789abcdefabcd" \
        "$runtime_root" "$library_root"
    chmod 0700 "$config_root" "$state_root" "$state_root/haproxy" \
        "$state_root/haproxy/phase-a" "$state_root/haproxy/phase-a/0123456789abcdefabcd"
    chmod 0755 "$runtime_root" "$host_root/usr/local/lib/coolify-control-plane-port8000" \
        "$library_root"

    printf '%s' 'W1VuaXRdCkRlc2NyaXB0aW9uPUNvb2xpZnkgY29udHJvbC1wbGFuZSBwb3J0IDgwMDAgbmZ0YWJsZXMgY2FwdHVyZQpEb2N1bWVudGF0aW9uPWh0dHBzOi8vd2lraS5uZnRhYmxlcy5vcmcvd2lraS1uZnRhYmxlcy9pbmRleC5waHAvQ29uZmlndXJpbmdfY2hhaW5zCkRlZmF1bHREZXBlbmRlbmNpZXM9bm8KUmVxdWlyZXM9Y29vbGlmeS1wb3J0ODAwMC1oYXByb3h5QHBoYXNlLWEuc2VydmljZSBjb29saWZ5LXBvcnQ4MDAwLWhhcHJveHlAcGhhc2UtYi5zZXJ2aWNlCkFmdGVyPWxvY2FsLWZzLnRhcmdldCBjb29saWZ5LXBvcnQ4MDAwLWhhcHJveHlAcGhhc2UtYS5zZXJ2aWNlIGNvb2xpZnktcG9ydDgwMDAtaGFwcm94eUBwaGFzZS1iLnNlcnZpY2UKQmVmb3JlPW5ldHdvcmstcHJlLnRhcmdldCBkb2NrZXIuc2VydmljZSBkb2NrZXIuc29ja2V0CgpbU2VydmljZV0KVHlwZT1vbmVzaG90ClVzZXI9cm9vdApHcm91cD1yb290CkV4ZWNTdGFydD0vdXNyL2xvY2FsL2xpYmV4ZWMvY29vbGlmeS1wb3J0ODAwMC1hcHBseS1hY3RpdmUtbmZ0CkV4ZWNSZWxvYWQ9L3Vzci9sb2NhbC9saWJleGVjL2Nvb2xpZnktcG9ydDgwMDAtYXBwbHktYWN0aXZlLW5mdApSZW1haW5BZnRlckV4aXQ9eWVzCk5vTmV3UHJpdmlsZWdlcz10cnVlClByaXZhdGVUbXA9dHJ1ZQpQcm90ZWN0SG9tZT10cnVlClByb3RlY3RTeXN0ZW09c3RyaWN0ClJlYWRPbmx5UGF0aHM9L2V0Yy9jb29saWZ5LWNvbnRyb2wtcGxhbmUtcG9ydDgwMDAKUmVzdHJpY3RBZGRyZXNzRmFtaWxpZXM9QUZfSU5FVCBBRl9JTkVUNiBBRl9ORVRMSU5LIEFGX1VOSVgKQ2FwYWJpbGl0eUJvdW5kaW5nU2V0PUNBUF9ORVRfQURNSU4KQW1iaWVudENhcGFiaWxpdGllcz1DQVBfTkVUX0FETUlOCgpbSW5zdGFsbF0KV2FudGVkQnk9bXVsdGktdXNlci50YXJnZXQKUmVxdWlyZWRCeT1kb2NrZXIuc2VydmljZSBkb2NrZXIuc29ja2V0Cg==' \
        | base64 --decode > "$unit_root/coolify-port8000-nft.service"
    chmod 0644 "$unit_root/coolify-port8000-nft.service"
    [ "$(sha256_file "$unit_root/coolify-port8000-nft.service")" \
        = 680f1e563b2537fe482424d279d2466cda12594d6940f96932668d25336533dd ] \
        || fail 'legacy port8000 nft fixture no longer matches the installer allowlist'

    ln -s /etc/systemd/system/coolify-port8000-haproxy@.service \
        "$unit_root/multi-user.target.wants/coolify-port8000-haproxy@phase-a.service"
    ln -s /etc/systemd/system/coolify-port8000-haproxy@.service \
        "$unit_root/multi-user.target.wants/coolify-port8000-haproxy@phase-b.service"
    ln -s /etc/systemd/system/coolify-port8000-phase-b-authorizer.service \
        "$unit_root/multi-user.target.wants/coolify-port8000-phase-b-authorizer.service"
    ln -s /etc/systemd/system/coolify-port8000-nft.service \
        "$unit_root/multi-user.target.wants/coolify-port8000-nft.service"
    ln -s /etc/systemd/system/coolify-port8000-nft.service \
        "$unit_root/docker.service.requires/coolify-port8000-nft.service"
    ln -s /etc/systemd/system/coolify-port8000-nft.service \
        "$unit_root/docker.socket.requires/coolify-port8000-nft.service"

    printf '%s\n' '# control-plane-instance: phase-a' 'global' \
        > "$config_root/phase-a.cfg"
    chmod 0600 "$config_root/phase-a.cfg"
    printf '%s\n' '# server state fixture' \
        > "$state_root/haproxy/phase-a/0123456789abcdefabcd/server-state"
    chmod 0600 "$state_root/haproxy/phase-a/0123456789abcdefabcd/server-state"
    printf '%s\n' '#!/bin/sh' 'exit 0' > "$library_root/haproxy"
    chmod 0755 "$library_root/haproxy"
    legacy_binary_sha256=$(sha256_file "$library_root/haproxy")
    {
        printf '%s\n' 'version=1' 'haproxy_version=2.8.26'
        printf '%s\n' 'haproxy_source_sha256=88c28dae25ea46672e66f8db0dadd1fb5920e06ee2415ceb9f281c256b537727'
        printf '%s\n' 'haproxy_build_options=TARGET=linux-glibc USE_SYSTEMD=1'
        printf '%s\n' 'haproxy_binary=/usr/local/lib/coolify-control-plane-port8000/haproxy-2.8.26/haproxy'
        printf 'haproxy_binary_sha256=%s\n' "$legacy_binary_sha256"
    } > "$library_root/provenance"
    chmod 0600 "$library_root/provenance"

    for unit in $LEGACY_PORT8000_UNITS; do
        set_fake_systemd_state "$state_directory" "$unit" enabled
        set_fake_systemd_active_state "$state_directory" "$unit" active
    done
}

assert_legacy_port8000_removed()
{
    host_root=$1
    for legacy_path in \
        "$host_root/etc/systemd/system/coolify-port8000-haproxy@.service" \
        "$host_root/etc/systemd/system/coolify-port8000-phase-b-authorizer.service" \
        "$host_root/etc/systemd/system/coolify-port8000-nft.service" \
        "$host_root/usr/local/libexec/coolify-port8000-apply-active-nft" \
        "$host_root/usr/local/libexec/coolify-haproxy-port8000-controller" \
        "$host_root/etc/systemd/system/multi-user.target.wants/coolify-port8000-haproxy@phase-a.service" \
        "$host_root/etc/systemd/system/multi-user.target.wants/coolify-port8000-haproxy@phase-b.service" \
        "$host_root/etc/systemd/system/multi-user.target.wants/coolify-port8000-phase-b-authorizer.service" \
        "$host_root/etc/systemd/system/multi-user.target.wants/coolify-port8000-nft.service" \
        "$host_root/etc/systemd/system/docker.service.requires/coolify-port8000-nft.service" \
        "$host_root/etc/systemd/system/docker.socket.requires/coolify-port8000-nft.service" \
        "$host_root/etc/coolify-control-plane-port8000" \
        "$host_root/var/lib/coolify-control-plane-port8000" \
        "$host_root/run/coolify-control-plane-port8000" \
        "$host_root/usr/local/lib/coolify-control-plane-port8000"; do
        [ ! -e "$legacy_path" ] && [ ! -L "$legacy_path" ] \
            || fail "legacy port8000 artifact survived deprovision: $legacy_path"
    done
}

dispatch_environment()
{
    host_root=$1
    shift
    host_uid=$(file_uid "$host_root")
    host_gid=$(file_gid "$host_root")
    CONTROL_PLANE_RELEASE_DISPATCH_TEST_MODE=1 \
        CONTROL_PLANE_RELEASE_DISPATCH_TEST_ROOT="$host_root" \
        CONTROL_PLANE_RELEASE_MANIFEST_FILE="$host_root/etc/coolify-control-plane/release.manifest" \
        CONTROL_PLANE_RELEASES_ROOT="$host_root/usr/local/lib/coolify-control-plane/releases" \
        CONTROL_PLANE_RELEASE_DISPATCH_IMMUTABLE_UID="$host_uid" \
        CONTROL_PLANE_RELEASE_DISPATCH_IMMUTABLE_GID="$host_gid" \
        "$@"
}

assert_manifest_release()
{
    host_root=$1
    expected_release=$2
    manifest="$host_root/etc/coolify-control-plane/release.manifest"
    [ -f "$manifest" ] && [ ! -L "$manifest" ] && [ "$(file_mode "$manifest")" = 600 ] \
        || fail 'release manifest identity is unsafe'
    [ "$(sed -n '2s/^release|//p' "$manifest")" = "$expected_release" ] \
        || fail "release manifest did not select $expected_release"
    expected_asset_count=$(wc -l < "$CONTRACT" | tr -d ' ')
    observed_asset_count=$(sed -n '/^asset|/p' "$manifest" | wc -l | tr -d ' ')
    [ "$observed_asset_count" = "$expected_asset_count" ] \
        || fail 'release manifest asset cardinality diverges from the canonical contract'
}

assert_bundle_modes()
{
    host_root=$1
    release_id=$2
    release_directory="$host_root/usr/local/lib/coolify-control-plane/releases/$release_id"
    host_uid=$(file_uid "$host_root")
    host_gid=$(file_gid "$host_root")
    while IFS='|' read -r role source_relative destination_relative mode _dispatchable; do
        path="$release_directory/$destination_relative"
        [ -f "$path" ] && [ ! -L "$path" ] \
            && [ "$(file_uid "$path"):$(file_gid "$path"):$(file_mode "$path")" \
                = "$host_uid:$host_gid:$mode" ] \
            || fail "release asset mode or owner is wrong: $role"
    done < "$CONTRACT"
    [ "$(file_mode "$host_root/usr/local/libexec/coolify-control-plane-release-dispatch")" = 700 ] \
        && [ "$(file_mode "$host_root/usr/local/sbin/control-plane-blue-green")" = 755 ] \
        || fail 'stable dispatcher or launcher mode is wrong'
    snapshot_directory="$host_root/run/coolify-control-plane-release-dispatch"
    [ -d "$snapshot_directory" ] && [ ! -L "$snapshot_directory" ] \
        && [ "$(file_mode "$snapshot_directory")" = 700 ] \
        || fail 'bundle installer did not provision the dispatcher snapshot directory for sandboxed units'
    for unit in \
        control-plane-backup-quiesce-watchdog.service \
        control-plane-backup-quiesce-watchdog.timer \
        coolify-runtime-attestation-ssh-fence.service \
        coolify-runtime-attestation-ssh-fence-watchdog.service; do
        [ "$(file_mode "$host_root/etc/systemd/system/$unit")" = 644 ] \
            || fail "stable systemd unit mode is wrong: $unit"
    done
}

assert_launcher_dispatches()
{
    host_root=$1
    expected_label=$2
    shift 2
    output=$(TMPDIR="$host_root/caller-controlled-tmp" dispatch_environment "$host_root" \
        "$host_root/usr/local/sbin/control-plane-blue-green" "$@")
    printf '%s\n' "$output" | grep -F -q "operator=$expected_label " \
        || fail 'stable launcher did not execute the manifest-selected operator'
    manifest_sha256=$(sha256_file "$host_root/etc/coolify-control-plane/release.manifest")
    printf '%s\n' "$output" | grep -F -q "manifest_sha256=$manifest_sha256" \
        || fail 'stable launcher did not bind the active manifest identity'
    snapshot_directory="$host_root/run/coolify-control-plane-release-dispatch"
    [ -d "$snapshot_directory" ] && [ ! -L "$snapshot_directory" ] \
        && [ "$(file_mode "$snapshot_directory")" = 700 ] \
        && [ -z "$(find "$snapshot_directory" -mindepth 1 -print -quit)" ] \
        || fail 'dispatcher did not use and clean its fixed mode-0700 snapshot directory'
}

assert_stable_units_match_source()
{
    host_root=$1
    source_root=$2
    while IFS='|' read -r role source_relative _destination_relative mode _dispatchable; do
        [ "$mode" = 644 ] || continue
        case "$role" in
            backup-quiesce-service-unit) unit=control-plane-backup-quiesce-watchdog.service ;;
            backup-quiesce-timer-unit) unit=control-plane-backup-quiesce-watchdog.timer ;;
            runtime-fence-service-unit) unit=coolify-runtime-attestation-ssh-fence.service ;;
            runtime-fence-watchdog-unit) unit=coolify-runtime-attestation-ssh-fence-watchdog.service ;;
            *) fail "unexpected stable unit role: $role" ;;
        esac
        [ "$(sha256_file "$host_root/etc/systemd/system/$unit")" \
            = "$(sha256_file "$source_root/$source_relative")" ] \
            || fail "stable unit does not match the selected release: $role"
    done < "$CONTRACT"
}

assert_install_rejected_without_manifest_change()
{
    host_root=$1
    source_root=$2
    release_id=$3
    expected_manifest_sha256=$4
    shift 4
    if run_bundle "$host_root" "$source_root" "$release_id" "$@" \
        > "$TEST_ROOT/rejection-output" 2>&1; then
        fail "unsafe release installation unexpectedly succeeded: $release_id"
    fi
    [ "$(sha256_file "$host_root/etc/coolify-control-plane/release.manifest")" \
        = "$expected_manifest_sha256" ] \
        || fail 'rejected release installation changed the active manifest'
}

test_initial_install_and_dispatch()
{
    host_root="$TEST_ROOT/host-main"
    source_one="$TEST_ROOT/source-one"
    mkdir -p "$host_root"
    host_root=$(CDPATH='' cd -- "$host_root" && pwd -P)
    prepare_source "$source_one" one
    source_one=$(CDPATH='' cd -- "$source_one" && pwd -P)
    run_bundle "$host_root" "$source_one" release-bundle-one-000001 >/dev/null
    assert_manifest_release "$host_root" release-bundle-one-000001
    assert_bundle_modes "$host_root" release-bundle-one-000001
    assert_stable_units_match_source "$host_root" "$source_one"
    assert_launcher_dispatches "$host_root" one alpha beta

    watchdog_output=$(dispatch_environment "$host_root" \
        "$host_root/usr/local/sbin/control-plane-blue-green" \
        backup-quiesce watchdog-scan --state-directory /var/lib/coolify/control-plane-backup-quiesce)
    printf '%s\n' "$watchdog_output" | grep -F -q \
        'args=backup-quiesce watchdog-scan --state-directory /var/lib/coolify/control-plane-backup-quiesce' \
        || fail 'backup-quiesce watchdog did not traverse the dispatcher and versioned operator'

    dispatcher="$host_root/usr/local/libexec/coolify-control-plane-release-dispatch"
    if dispatch_environment "$host_root" "$dispatcher" backup-quiesce-service-unit \
        > "$TEST_ROOT/unit-dispatch-output" 2>&1; then
        fail 'dispatcher executed a non-dispatchable systemd unit role'
    fi

    active_operator="$host_root/usr/local/lib/coolify-control-plane/releases/release-bundle-one-000001/control-plane-blue-green.sh"
    printf '\n' >> "$active_operator"
    if dispatch_environment "$host_root" "$host_root/usr/local/sbin/control-plane-blue-green" \
        > "$TEST_ROOT/tamper-output" 2>&1; then
        fail 'dispatcher executed a tampered release asset'
    fi
}

test_crash_and_safety_gates()
{
    host_root="$TEST_ROOT/host-crash"
    source_one="$TEST_ROOT/crash-source-one"
    source_two="$TEST_ROOT/crash-source-two"
    source_three="$TEST_ROOT/crash-source-three"
    mkdir -p "$host_root"
    host_root=$(CDPATH='' cd -- "$host_root" && pwd -P)
    prepare_source "$source_one" old
    prepare_source "$source_two" new
    prepare_source "$source_three" newest
    source_one=$(CDPATH='' cd -- "$source_one" && pwd -P)
    source_two=$(CDPATH='' cd -- "$source_two" && pwd -P)
    source_three=$(CDPATH='' cd -- "$source_three" && pwd -P)
    run_bundle "$host_root" "$source_one" release-crash-old-000001 >/dev/null
    manifest="$host_root/etc/coolify-control-plane/release.manifest"
    old_manifest_sha256=$(sha256_file "$manifest")

    assert_install_rejected_without_manifest_change "$host_root" "$source_two" \
        release-crash-new-000002 "$old_manifest_sha256" \
        env CONTROL_PLANE_RELEASE_BUNDLE_TEST_CRASH_AT=after-first-stable-unit-published
    assert_manifest_release "$host_root" release-crash-old-000001
    assert_launcher_dispatches "$host_root" old before-publication-crash
    assert_stable_units_match_source "$host_root" "$source_one"
    [ ! -e "$host_root/etc/coolify-control-plane/release.activation" ] \
        || fail 'first dispatch did not recover the interrupted stable-unit activation'

    assert_install_rejected_without_manifest_change "$host_root" "$source_two" \
        release-crash-new-000002 "$old_manifest_sha256" \
        env CONTROL_PLANE_RELEASE_BUNDLE_TEST_CRASH_AT=after-release-manifest-candidate-fsync
    [ -n "$(find "$host_root/etc/coolify-control-plane" \
        -name 'release.manifest.new.*' -print -quit)" ] \
        || fail 'manifest-candidate interruption did not leave the recovery fixture'
    assert_launcher_dispatches "$host_root" old stale-manifest-candidate-recovery
    assert_stable_units_match_source "$host_root" "$source_one"
    [ -z "$(find "$host_root/etc/coolify-control-plane" \
        -name 'release.manifest.new.*' -print -quit)" ] \
        && [ ! -e "$host_root/etc/coolify-control-plane/release.activation" ] \
        || fail 'first dispatch did not remove stale activation and manifest candidates'

    run_bundle "$host_root" "$source_two" release-crash-new-000002 >/dev/null
    assert_manifest_release "$host_root" release-crash-new-000002
    assert_stable_units_match_source "$host_root" "$source_two"
    new_manifest_sha256=$(sha256_file "$manifest")

    active_path="$host_root/var/lib/coolify/control-plane-backup-quiesce/active"
    printf 'active-operation\n' > "$active_path"
    chmod 0600 "$active_path"
    assert_install_rejected_without_manifest_change "$host_root" "$source_three" \
        release-crash-newest-000003 "$new_manifest_sha256"
    rm -f "$active_path"

    runtime_environment="$host_root/etc/coolify-runtime-attestation-ssh-fence/runtime.env"
    printf 'CONTROL_PLANE_RUNTIME_ARMED=1\n' > "$runtime_environment"
    chmod 0600 "$runtime_environment"
    assert_install_rejected_without_manifest_change "$host_root" "$source_three" \
        release-crash-newest-000003 "$new_manifest_sha256"
    printf 'CONTROL_PLANE_RUNTIME_ARMED=0\n' > "$runtime_environment"
    chmod 0600 "$runtime_environment"

    set +e
    run_bundle "$host_root" "$source_three" release-crash-newest-000003 \
        env CONTROL_PLANE_RELEASE_BUNDLE_TEST_CRASH_AT=after-release-manifest-replaced \
        > "$TEST_ROOT/post-manifest-crash-output" 2>&1
    crash_status=$?
    set -e
    [ "$crash_status" -eq 137 ] \
        || fail 'post-manifest crash seam did not terminate with the expected status'
    assert_manifest_release "$host_root" release-crash-newest-000003
    assert_launcher_dispatches "$host_root" newest after-publication-crash
    assert_stable_units_match_source "$host_root" "$source_three"
    [ ! -e "$host_root/etc/coolify-control-plane/release.activation" ] \
        || fail 'target-side activation journal remained after first dispatch'
}

test_lock_contention()
{
    host_root="$TEST_ROOT/host-contention"
    source_root="$TEST_ROOT/contention-source"
    mkdir -p "$host_root"
    host_root=$(CDPATH='' cd -- "$host_root" && pwd -P)
    prepare_source "$source_root" contention
    source_root=$(CDPATH='' cd -- "$source_root" && pwd -P)
    marker="$TEST_ROOT/contention.locked"
    CONTROL_PLANE_RELEASE_BUNDLE_TEST_MODE=1 \
        CONTROL_PLANE_RELEASE_BUNDLE_TEST_ROOT="$host_root" \
        CONTROL_PLANE_RELEASE_BUNDLE_SOURCE_ROOT="$source_root" \
        CONTROL_PLANE_RELEASE_BUNDLE_TEST_LOCK_MARKER="$marker" \
        CONTROL_PLANE_RELEASE_BUNDLE_TEST_HOLD_LOCK_SECONDS=2 \
        "$INSTALLER" release-contention-one-000001 \
        > "$TEST_ROOT/contention-first-output" 2>&1 &
    first_pid=$!
    attempt=0
    while [ ! -f "$marker" ]; do
        attempt=$((attempt + 1))
        [ "$attempt" -lt 100 ] || fail 'timed out waiting for release lock holder'
        sleep 0.05
    done
    if run_bundle "$host_root" "$source_root" release-contention-two-000002 \
        > "$TEST_ROOT/contention-second-output" 2>&1; then
        fail 'concurrent release installer acquired the global transaction lock'
    fi
    if ! wait "$first_pid"; then
        sed -n '1,20p' "$TEST_ROOT/contention-first-output" >&2
        fail 'global-lock holder failed to publish its release'
    fi
    assert_manifest_release "$host_root" release-contention-one-000001
}

test_unsafe_sources()
{
    host_root="$TEST_ROOT/host-unsafe"
    source_root="$TEST_ROOT/unsafe-source"
    mkdir -p "$host_root"
    host_root=$(CDPATH='' cd -- "$host_root" && pwd -P)
    prepare_source "$source_root" unsafe
    source_root=$(CDPATH='' cd -- "$source_root" && pwd -P)
    original="$source_root/controllers/traefik-ingress.sh"
    moved="$source_root/controllers/traefik-ingress.real"
    mv "$original" "$moved"
    ln -s "$moved" "$original"
    if run_bundle "$host_root" "$source_root" release-unsafe-link-000001 \
        > "$TEST_ROOT/unsafe-symlink-output" 2>&1; then
        fail 'bundle installer accepted a symlink source asset'
    fi
    rm -f "$original"
    mv "$moved" "$original"
    ln "$original" "$source_root/controllers/traefik-ingress.hardlink"
    if run_bundle "$host_root" "$source_root" release-unsafe-hardlink-000002 \
        > "$TEST_ROOT/unsafe-hardlink-output" 2>&1; then
        fail 'bundle installer accepted a multiply linked source asset'
    fi
}

test_asset_contract_is_an_immutable_trust_root()
{
    copied_root="$TEST_ROOT/copied-installer"
    copied_host="$TEST_ROOT/copied-installer-host"
    copied_source="$TEST_ROOT/copied-installer-source"
    mkdir -p "$copied_root" "$copied_host"
    cp "$INSTALLER" "$copied_root/install-host-release-bundle.sh"
    cp "$DISPATCHER_SOURCE" "$copied_root/release-dispatch.sh"
    cp "$CONTRACT" "$copied_root/release-assets.contract"
    chmod 0755 "$copied_root/install-host-release-bundle.sh" "$copied_root/release-dispatch.sh"
    chmod 0644 "$copied_root/release-assets.contract"
    prepare_source "$copied_source" contract-tamper
    sed 's|compose.yaml|compose.other.yaml|' "$copied_root/release-assets.contract" \
        > "$copied_root/release-assets.contract.new"
    mv "$copied_root/release-assets.contract.new" "$copied_root/release-assets.contract"
    chmod 0644 "$copied_root/release-assets.contract"
    if CONTROL_PLANE_RELEASE_BUNDLE_TEST_MODE=1 \
        CONTROL_PLANE_RELEASE_BUNDLE_TEST_ROOT="$copied_host" \
        CONTROL_PLANE_RELEASE_BUNDLE_SOURCE_ROOT="$copied_source" \
        "$copied_root/install-host-release-bundle.sh" release-contract-tamper-000001 \
        > "$TEST_ROOT/contract-tamper-output" 2>&1; then
        fail 'bundle installer accepted a modified release asset contract'
    fi
    grep -F -q 'release asset contract differs from the canonical inventory' \
        "$TEST_ROOT/contract-tamper-output" \
        || fail 'modified release asset contract failed for an unexpected reason'
}

test_dispatcher_source_is_immutable_owner_trusted()
{
    copied_root="$TEST_ROOT/untrusted-dispatcher-installer"
    copied_host="$TEST_ROOT/untrusted-dispatcher-host"
    copied_source="$TEST_ROOT/untrusted-dispatcher-source"
    mkdir -p "$copied_root" "$copied_host"
    cp "$INSTALLER" "$copied_root/install-host-release-bundle.sh"
    cp "$DISPATCHER_SOURCE" "$copied_root/release-dispatch.sh"
    cp "$CONTRACT" "$copied_root/release-assets.contract"
    chmod 0755 "$copied_root/install-host-release-bundle.sh"
    chmod 0775 "$copied_root/release-dispatch.sh"
    chmod 0644 "$copied_root/release-assets.contract"
    prepare_source "$copied_source" untrusted-dispatcher
    if CONTROL_PLANE_RELEASE_BUNDLE_TEST_MODE=1 \
        CONTROL_PLANE_RELEASE_BUNDLE_TEST_ROOT="$copied_host" \
        CONTROL_PLANE_RELEASE_BUNDLE_SOURCE_ROOT="$copied_source" \
        "$copied_root/install-host-release-bundle.sh" release-untrusted-dispatcher-000001 \
        > "$TEST_ROOT/untrusted-dispatcher-output" 2>&1; then
        fail 'bundle installer accepted a group-writable dispatcher source'
    fi
    grep -F -q 'release source asset is group/world writable: release-dispatcher' \
        "$TEST_ROOT/untrusted-dispatcher-output" \
        || fail 'untrusted dispatcher source failed for an unexpected reason'
}

test_systemd_enablement_transaction()
{
    success_host="$TEST_ROOT/host-systemd-success"
    success_source="$TEST_ROOT/source-systemd-success"
    success_state="$TEST_ROOT/state-systemd-success"
    mkdir -p "$success_host" "$success_state"
    success_host=$(CDPATH='' cd -- "$success_host" && pwd -P)
    prepare_source "$success_source" systemd-success
    success_source=$(CDPATH='' cd -- "$success_source" && pwd -P)
    run_bundle_with_systemd "$success_host" "$success_source" \
        release-systemd-success-000001 "$success_state" >/dev/null
    assert_manifest_release "$success_host" release-systemd-success-000001
    assert_target_systemd_states "$success_state"
    [ ! -e "$success_host/etc/coolify-control-plane/release.activation" ] \
        || fail 'successful systemd activation retained its journal'
    observed_mutations=$(sed -n '/^enable|/p;/^disable|/p' "$success_state/commands.log")
    expected_mutations=
    for unit in $SYSTEMD_ENABLEMENT_UNITS; do
        target_state=$(systemd_target_state "$unit")
        case "$target_state" in
            enabled) mutation=enable ;;
            disabled) mutation=disable ;;
        esac
        if [ -n "$expected_mutations" ]; then
            expected_mutations="$expected_mutations
$mutation|$unit"
        else
            expected_mutations="$mutation|$unit"
        fi
    done
    [ "$observed_mutations" = "$expected_mutations" ] \
        || fail 'successful systemd activation did not mutate exactly the three canonical units'
    grep -F -q 'is-enabled-result|control-plane-backup-quiesce-watchdog.timer|not-found' \
        "$success_state/commands.log" \
        || fail 'first installation did not exercise the systemd not-found state'
    set_fake_systemd_state "$success_state" \
        control-plane-backup-quiesce-watchdog.timer not-found
    if run_bundle_with_systemd "$success_host" "$success_source" \
        release-systemd-success-000002 "$success_state" \
        > "$TEST_ROOT/systemd-installed-not-found-output" 2>&1; then
        fail 'release upgrade normalized not-found despite an installed unit definition'
    fi
    grep -F -q 'not found despite an installed definition' \
        "$TEST_ROOT/systemd-installed-not-found-output" \
        || fail 'installed unit not-found state failed for an unexpected reason'
    assert_manifest_release "$success_host" release-systemd-success-000001
    rm -f "$success_state/state.control-plane-backup-quiesce-watchdog.timer"

    unsupported_host="$TEST_ROOT/host-systemd-unsupported"
    unsupported_source="$TEST_ROOT/source-systemd-unsupported"
    unsupported_state="$TEST_ROOT/state-systemd-unsupported"
    mkdir -p "$unsupported_host" "$unsupported_state"
    unsupported_host=$(CDPATH='' cd -- "$unsupported_host" && pwd -P)
    prepare_source "$unsupported_source" systemd-unsupported
    unsupported_source=$(CDPATH='' cd -- "$unsupported_source" && pwd -P)
    set_fake_systemd_state "$unsupported_state" \
        coolify-runtime-attestation-ssh-fence.service masked
    if run_bundle_with_systemd "$unsupported_host" "$unsupported_source" \
        release-systemd-unsupported-000001 "$unsupported_state" \
        > "$TEST_ROOT/systemd-unsupported-output" 2>&1; then
        fail 'release installation accepted an unsupported prior systemd state'
    fi
    grep -F -q 'unsupported enablement state' "$TEST_ROOT/systemd-unsupported-output" \
        || fail 'unsupported systemd state failed for an unexpected reason'
    [ ! -e "$unsupported_host/etc/coolify-control-plane/release.manifest" ] \
        && [ ! -e "$unsupported_host/etc/coolify-control-plane/release.activation" ] \
        || fail 'unsupported systemd state published activation state'

    first_crash_host="$TEST_ROOT/host-systemd-first-crash"
    first_crash_source="$TEST_ROOT/source-systemd-first-crash"
    first_crash_state="$TEST_ROOT/state-systemd-first-crash"
    mkdir -p "$first_crash_host" "$first_crash_state"
    first_crash_host=$(CDPATH='' cd -- "$first_crash_host" && pwd -P)
    prepare_source "$first_crash_source" systemd-first-crash
    first_crash_source=$(CDPATH='' cd -- "$first_crash_source" && pwd -P)
    set +e
    run_bundle_with_systemd "$first_crash_host" "$first_crash_source" \
        release-systemd-first-crash-000001 "$first_crash_state" \
        env CONTROL_PLANE_RELEASE_BUNDLE_TEST_CRASH_AT=after-first-systemd-enablement \
        > "$TEST_ROOT/systemd-first-crash-output" 2>&1
    first_crash_status=$?
    set -e
    [ "$first_crash_status" -eq 137 ] \
        || fail 'first-install systemd crash seam did not terminate as expected'
    assert_activation_enablements "$first_crash_host"
    [ ! -e "$first_crash_host/etc/coolify-control-plane/release.manifest" ] \
        || fail 'first-install systemd crash published a manifest'
    assert_fake_systemd_state "$first_crash_state" \
        control-plane-backup-quiesce-watchdog.timer enabled
    active_backup="$first_crash_host/var/lib/coolify/control-plane-backup-quiesce/active"
    printf 'block-after-recovery\n' > "$active_backup"
    chmod 0600 "$active_backup"
    if run_bundle_with_systemd "$first_crash_host" "$first_crash_source" \
        release-systemd-first-crash-000001 "$first_crash_state" \
        > "$TEST_ROOT/systemd-first-recovery-output" 2>&1; then
        fail 'first-install recovery ignored the backup-quiesce gate'
    fi
    for unit in $SYSTEMD_ENABLEMENT_UNITS; do
        assert_fake_systemd_state "$first_crash_state" "$unit" disabled
    done
    [ ! -e "$first_crash_host/etc/coolify-control-plane/release.activation" ] \
        && [ ! -e "$first_crash_host/etc/coolify-control-plane/release.manifest" ] \
        || fail 'installer did not recover first-install systemd state to the old side'
    rm -f "$active_backup"
    run_bundle_with_systemd "$first_crash_host" "$first_crash_source" \
        release-systemd-first-crash-000001 "$first_crash_state" >/dev/null
    assert_target_systemd_states "$first_crash_state"

    upgrade_host="$TEST_ROOT/host-systemd-upgrade"
    upgrade_source_one="$TEST_ROOT/source-systemd-upgrade-one"
    upgrade_source_two="$TEST_ROOT/source-systemd-upgrade-two"
    upgrade_state="$TEST_ROOT/state-systemd-upgrade"
    mkdir -p "$upgrade_host" "$upgrade_state"
    upgrade_host=$(CDPATH='' cd -- "$upgrade_host" && pwd -P)
    prepare_source "$upgrade_source_one" systemd-old
    prepare_source "$upgrade_source_two" systemd-new
    upgrade_source_one=$(CDPATH='' cd -- "$upgrade_source_one" && pwd -P)
    upgrade_source_two=$(CDPATH='' cd -- "$upgrade_source_two" && pwd -P)
    run_bundle_with_systemd "$upgrade_host" "$upgrade_source_one" \
        release-systemd-upgrade-old-000001 "$upgrade_state" >/dev/null
    set_fake_systemd_state "$upgrade_state" \
        control-plane-backup-quiesce-watchdog.timer disabled
    set_fake_systemd_state "$upgrade_state" \
        coolify-runtime-attestation-ssh-fence.service enabled
    if run_bundle_with_systemd "$upgrade_host" "$upgrade_source_two" \
        release-systemd-upgrade-new-000002 "$upgrade_state" \
        env FAKE_SYSTEMD_FAIL_ACTION=disable:coolify-runtime-attestation-ssh-fence.service \
        > "$TEST_ROOT/systemd-upgrade-failure-output" 2>&1; then
        fail 'upgrade swallowed a systemd disable failure'
    fi
    assert_manifest_release "$upgrade_host" release-systemd-upgrade-old-000001
    assert_activation_enablements "$upgrade_host"
    recovery_output=$(dispatch_with_systemd "$upgrade_host" "$upgrade_state" \
        "$upgrade_host/usr/local/sbin/control-plane-blue-green" recover-old-side)
    printf '%s\n' "$recovery_output" | grep -F -q 'operator=systemd-old ' \
        || fail 'dispatcher did not retain the old release after enablement failure'
    assert_fake_systemd_state "$upgrade_state" \
        control-plane-backup-quiesce-watchdog.timer disabled
    assert_fake_systemd_state "$upgrade_state" \
        coolify-runtime-attestation-ssh-fence.service enabled
    assert_fake_systemd_state "$upgrade_state" \
        coolify-runtime-attestation-ssh-fence-watchdog.service \
        "$(systemd_target_state coolify-runtime-attestation-ssh-fence-watchdog.service)"
    [ ! -e "$upgrade_host/etc/coolify-control-plane/release.activation" ] \
        || fail 'dispatcher old-side recovery retained the activation journal'

    if run_bundle_with_systemd "$upgrade_host" "$upgrade_source_two" \
        release-systemd-upgrade-new-000002 "$upgrade_state" \
        env FAKE_SYSTEMD_NOOP_ACTION=enable:control-plane-backup-quiesce-watchdog.timer \
        > "$TEST_ROOT/systemd-upgrade-noop-output" 2>&1; then
        fail 'upgrade accepted a successful systemd command without the exact requested enablement'
    fi
    grep -F -q 'systemd unit enablement differs from the activation journal' \
        "$TEST_ROOT/systemd-upgrade-noop-output" \
        || fail 'systemd post-mutation assertion failed for an unexpected reason'
    assert_manifest_release "$upgrade_host" release-systemd-upgrade-old-000001
    assert_activation_enablements "$upgrade_host"
    recovery_output=$(dispatch_with_systemd "$upgrade_host" "$upgrade_state" \
        "$upgrade_host/usr/local/sbin/control-plane-blue-green" recover-noop-old-side)
    printf '%s\n' "$recovery_output" | grep -F -q 'operator=systemd-old ' \
        || fail 'dispatcher did not retain the old release after an inexact systemd mutation'
    assert_fake_systemd_state "$upgrade_state" \
        control-plane-backup-quiesce-watchdog.timer disabled
    assert_fake_systemd_state "$upgrade_state" \
        coolify-runtime-attestation-ssh-fence.service enabled
    assert_fake_systemd_state "$upgrade_state" \
        coolify-runtime-attestation-ssh-fence-watchdog.service disabled
    [ ! -e "$upgrade_host/etc/coolify-control-plane/release.activation" ] \
        || fail 'dispatcher retained the journal after inexact systemd mutation recovery'

    set +e
    run_bundle_with_systemd "$upgrade_host" "$upgrade_source_two" \
        release-systemd-upgrade-new-000002 "$upgrade_state" \
        env CONTROL_PLANE_RELEASE_BUNDLE_TEST_CRASH_AT=after-release-manifest-replaced \
        > "$TEST_ROOT/systemd-post-manifest-output" 2>&1
    post_manifest_status=$?
    set -e
    [ "$post_manifest_status" -eq 137 ] \
        || fail 'post-manifest systemd crash seam did not terminate as expected'
    assert_manifest_release "$upgrade_host" release-systemd-upgrade-new-000002
    assert_activation_enablements "$upgrade_host"
    assert_target_systemd_states "$upgrade_state"
    target_output=$(dispatch_with_systemd "$upgrade_host" "$upgrade_state" \
        "$upgrade_host/usr/local/sbin/control-plane-blue-green" recover-target-side)
    printf '%s\n' "$target_output" | grep -F -q 'operator=systemd-new ' \
        || fail 'dispatcher did not retain the target release after manifest publication'
    assert_target_systemd_states "$upgrade_state"
    [ ! -e "$upgrade_host/etc/coolify-control-plane/release.activation" ] \
        || fail 'dispatcher target-side recovery retained the activation journal'
}

test_post_manifest_timer_recovery()
{
    host_root="$TEST_ROOT/host-timer-recovery"
    source_one="$TEST_ROOT/source-timer-recovery-one"
    source_two="$TEST_ROOT/source-timer-recovery-two"
    state_directory="$TEST_ROOT/state-timer-recovery"
    mkdir -p "$host_root" "$state_directory"
    host_root=$(CDPATH='' cd -- "$host_root" && pwd -P)
    prepare_source "$source_one" timer-old
    prepare_source "$source_two" timer-new
    source_one=$(CDPATH='' cd -- "$source_one" && pwd -P)
    source_two=$(CDPATH='' cd -- "$source_two" && pwd -P)
    run_bundle_with_systemd "$host_root" "$source_one" \
        release-timer-old-000001 "$state_directory" >/dev/null

    set +e
    run_bundle_with_systemd "$host_root" "$source_two" \
        release-timer-start-fail-000002 "$state_directory" \
        env FAKE_SYSTEMD_FAIL_ACTION=start:control-plane-backup-quiesce-watchdog.timer \
        > "$TEST_ROOT/timer-start-failure-output" 2>&1
    timer_start_status=$?
    set -e
    [ "$timer_start_status" -ne 0 ] \
        || fail 'post-manifest timer start failure was swallowed'
    assert_manifest_release "$host_root" release-timer-start-fail-000002
    assert_activation_enablements "$host_root"
    dispatch_output=$(dispatch_with_systemd "$host_root" "$state_directory" \
        "$host_root/usr/local/sbin/control-plane-blue-green" recover-timer-start)
    printf '%s\n' "$dispatch_output" | grep -F -q 'operator=timer-new ' \
        || fail 'dispatcher did not recover a post-manifest timer start failure'
    [ ! -e "$host_root/etc/coolify-control-plane/release.activation" ] \
        || fail 'timer start recovery cleaned no activation journal'

    set_fake_systemd_active_state "$state_directory" \
        control-plane-backup-quiesce-watchdog.timer inactive
    set +e
    run_bundle_with_systemd "$host_root" "$source_two" \
        release-timer-active-fail-000003 "$state_directory" \
        env FAKE_SYSTEMD_NOOP_ACTION=start:control-plane-backup-quiesce-watchdog.timer \
        > "$TEST_ROOT/timer-active-failure-output" 2>&1
    timer_active_status=$?
    set -e
    [ "$timer_active_status" -ne 0 ] \
        || fail 'post-manifest inactive timer verification was swallowed'
    grep -F -q 'backup-quiesce watchdog timer is not active' \
        "$TEST_ROOT/timer-active-failure-output" \
        || fail 'inactive timer verification failed for an unexpected reason'
    assert_manifest_release "$host_root" release-timer-active-fail-000003
    assert_activation_enablements "$host_root"
    dispatch_output=$(dispatch_with_systemd "$host_root" "$state_directory" \
        "$host_root/usr/local/sbin/control-plane-blue-green" recover-timer-active)
    printf '%s\n' "$dispatch_output" | grep -F -q 'operator=timer-new ' \
        || fail 'dispatcher did not recover a post-manifest inactive timer'

    set +e
    run_bundle_with_systemd "$host_root" "$source_two" \
        release-timer-crash-000004 "$state_directory" \
        env CONTROL_PLANE_RELEASE_BUNDLE_TEST_CRASH_AT=after-backup-quiesce-timer-start \
        > "$TEST_ROOT/timer-post-start-crash-output" 2>&1
    timer_crash_status=$?
    set -e
    [ "$timer_crash_status" -eq 137 ] \
        || fail 'post-manifest timer-start crash seam did not terminate as expected'
    assert_manifest_release "$host_root" release-timer-crash-000004
    assert_activation_enablements "$host_root"
    dispatch_output=$(dispatch_with_systemd "$host_root" "$state_directory" \
        "$host_root/usr/local/sbin/control-plane-blue-green" recover-timer-crash)
    printf '%s\n' "$dispatch_output" | grep -F -q 'operator=timer-new ' \
        || fail 'dispatcher did not recover a post-manifest timer-start crash'
    [ ! -e "$host_root/etc/coolify-control-plane/release.activation" ] \
        || fail 'timer crash recovery retained the activation journal'
}

test_legacy_port8000_deprovision()
{
    success_host="$TEST_ROOT/host-legacy-port8000"
    success_source="$TEST_ROOT/source-legacy-port8000"
    success_state="$TEST_ROOT/state-legacy-port8000"
    mkdir -p "$success_host" "$success_state"
    success_host=$(CDPATH='' cd -- "$success_host" && pwd -P)
    prepare_source "$success_source" legacy-port8000
    success_source=$(CDPATH='' cd -- "$success_source" && pwd -P)
    prepare_legacy_port8000_installation "$success_host" "$success_state"
    run_bundle_with_systemd "$success_host" "$success_source" \
        release-legacy-port8000-000001 "$success_state" >/dev/null
    assert_manifest_release "$success_host" release-legacy-port8000-000001
    assert_legacy_port8000_removed "$success_host"
    for unit in $LEGACY_PORT8000_UNITS; do
        grep -F -q "disable|$unit" "$success_state/commands.log" \
            || fail "legacy port8000 unit was not disabled: $unit"
        grep -F -q "stop|$unit" "$success_state/commands.log" \
            || fail "legacy port8000 unit was not stopped: $unit"
    done

    recovery_host="$TEST_ROOT/host-legacy-port8000-recovery"
    recovery_source="$TEST_ROOT/source-legacy-port8000-recovery"
    recovery_state="$TEST_ROOT/state-legacy-port8000-recovery"
    mkdir -p "$recovery_host" "$recovery_state"
    recovery_host=$(CDPATH='' cd -- "$recovery_host" && pwd -P)
    prepare_source "$recovery_source" legacy-port8000-recovery
    recovery_source=$(CDPATH='' cd -- "$recovery_source" && pwd -P)
    prepare_legacy_port8000_installation "$recovery_host" "$recovery_state"
    if run_bundle_with_systemd "$recovery_host" "$recovery_source" \
        release-legacy-port8000-recovery-000001 "$recovery_state" \
        env FAKE_SYSTEMD_FAIL_ACTION=stop:coolify-port8000-haproxy@phase-a.service \
        > "$TEST_ROOT/legacy-port8000-stop-failure-output" 2>&1; then
        fail 'legacy port8000 deprovision swallowed a systemd stop failure'
    fi
    assert_manifest_release "$recovery_host" release-legacy-port8000-recovery-000001
    [ -f "$recovery_host/etc/coolify-control-plane/release.activation/legacy-port8000-deprovision" ] \
        || fail 'failed legacy port8000 deprovision did not retain its activation marker'
    if dispatch_with_systemd "$recovery_host" "$recovery_state" \
        "$recovery_host/usr/local/sbin/control-plane-blue-green" blocked-pending-migration \
        > "$TEST_ROOT/legacy-port8000-dispatch-block-output" 2>&1; then
        fail 'dispatcher executed a target release with unfinished legacy port8000 deprovision'
    fi
    grep -F -q 'requires installer-owned legacy port8000 deprovision recovery' \
        "$TEST_ROOT/legacy-port8000-dispatch-block-output" \
        || fail 'dispatcher blocked unfinished deprovision for an unexpected reason'
    run_bundle_with_systemd "$recovery_host" "$recovery_source" \
        release-legacy-port8000-recovery-000001 "$recovery_state" >/dev/null
    assert_legacy_port8000_removed "$recovery_host"
    [ ! -e "$recovery_host/etc/coolify-control-plane/release.activation" ] \
        || fail 'recovered legacy port8000 deprovision retained its journal'

    unsafe_host="$TEST_ROOT/host-legacy-port8000-unsafe"
    unsafe_source="$TEST_ROOT/source-legacy-port8000-unsafe"
    unsafe_state="$TEST_ROOT/state-legacy-port8000-unsafe"
    mkdir -p "$unsafe_host" "$unsafe_state"
    unsafe_host=$(CDPATH='' cd -- "$unsafe_host" && pwd -P)
    prepare_source "$unsafe_source" legacy-port8000-unsafe
    unsafe_source=$(CDPATH='' cd -- "$unsafe_source" && pwd -P)
    prepare_legacy_port8000_installation "$unsafe_host" "$unsafe_state"
    printf '%s\n' '# local replacement' \
        >> "$unsafe_host/etc/systemd/system/coolify-port8000-nft.service"
    if run_bundle_with_systemd "$unsafe_host" "$unsafe_source" \
        release-legacy-port8000-unsafe-000001 "$unsafe_state" \
        > "$TEST_ROOT/legacy-port8000-unsafe-output" 2>&1; then
        fail 'legacy port8000 deprovision removed an unrecognized unit identity'
    fi
    grep -F -q 'legacy port8000 asset identity is unrecognized: nft-unit' \
        "$TEST_ROOT/legacy-port8000-unsafe-output" \
        || fail 'unrecognized legacy port8000 unit failed for an unexpected reason'
    [ -f "$unsafe_host/etc/systemd/system/coolify-port8000-nft.service" ] \
        && [ ! -e "$unsafe_host/etc/coolify-control-plane/release.manifest" ] \
        || fail 'unrecognized legacy port8000 identity was mutated or activated'
}

test_release_id_safety()
{
    host_root="$TEST_ROOT/host-release-id"
    source_root="$TEST_ROOT/release-id-source"
    mkdir -p "$host_root"
    host_root=$(CDPATH='' cd -- "$host_root" && pwd -P)
    prepare_source "$source_root" release-id
    source_root=$(CDPATH='' cd -- "$source_root" && pwd -P)
    for rejected_release_id in .hidden-release-000001 .new-release-candidate-000001; do
        if run_bundle "$host_root" "$source_root" "$rejected_release_id" \
            > "$TEST_ROOT/release-id-output" 2>&1; then
            fail "bundle installer accepted an unsafe release ID: $rejected_release_id"
        fi
    done
    [ ! -e "$host_root/etc/coolify-control-plane/release.manifest" ] \
        || fail 'rejected release ID published a manifest'
}

test_cold_boot_uses_manifest_selected_assets()
{
    host_root="$TEST_ROOT/host-cold-boot"
    source_root="$TEST_ROOT/source-cold-boot"
    fake_bin="$TEST_ROOT/cold-boot-bin"
    release_id=release-cold-boot-000001
    mkdir -p "$host_root" "$fake_bin"
    host_root=$(CDPATH='' cd -- "$host_root" && pwd -P)
    prepare_source "$source_root" cold-boot
    source_root=$(CDPATH='' cd -- "$source_root" && pwd -P)
    run_bundle "$host_root" "$source_root" "$release_id" >/dev/null
    {
        printf '%s\n' '#!/bin/sh' 'set -eu'
        printf '%s\n' \
            'case "$*" in' \
            '    "is-enabled --quiet control-plane-backup-quiesce-watchdog.timer") exit 0 ;;' \
            '    "is-active --quiet control-plane-backup-quiesce-watchdog.timer") exit 0 ;;' \
            '    "start control-plane-backup-quiesce-watchdog.service") exit 0 ;;' \
            '    *) exit 64 ;;' \
            'esac'
    } > "$fake_bin/systemctl"
    chmod 0755 "$fake_bin/systemctl"

    printf '\nsource-tree-drift\n' >> "$source_root/control-plane-blue-green.sh"
    printf '\n# source-tree-drift\n' \
        >> "$source_root/backup-quiesce/control-plane-backup-quiesce.sh"
    printf '\n# source-tree-drift\n' \
        >> "$source_root/backup-quiesce/control-plane-backup-quiesce-watchdog.service"
    printf '\n# source-tree-drift\n' \
        >> "$source_root/backup-quiesce/control-plane-backup-quiesce-watchdog.timer"
    cold_boot_output=$(CONTROL_PLANE_BACKUP_QUIESCE_COLD_BOOT_TEST_MODE=1 \
        CONTROL_PLANE_BACKUP_QUIESCE_COLD_BOOT_TEST_ROOT="$host_root" \
        PATH="$fake_bin:$PATH" "$COLD_BOOT_ACCEPTANCE")
    printf '%s\n' "$cold_boot_output" | grep -F -q 'backup-quiesce-cold-boot=passed;' \
        || fail 'cold-boot acceptance did not use the active manifest-selected release'

    active_controller="$host_root/usr/local/lib/coolify-control-plane/releases/$release_id/backup-quiesce/control-plane-backup-quiesce.sh"
    printf '\n' >> "$active_controller"
    if CONTROL_PLANE_BACKUP_QUIESCE_COLD_BOOT_TEST_MODE=1 \
        CONTROL_PLANE_BACKUP_QUIESCE_COLD_BOOT_TEST_ROOT="$host_root" \
        PATH="$fake_bin:$PATH" "$COLD_BOOT_ACCEPTANCE" \
        > "$TEST_ROOT/cold-boot-tamper-output" 2>&1; then
        fail 'cold-boot acceptance accepted a modified manifest-selected controller'
    fi
    grep -F -q 'active release asset identity is unsafe: backup-quiesce-controller' \
        "$TEST_ROOT/cold-boot-tamper-output" \
        || fail 'manifest-selected cold-boot asset tamper failed for an unexpected reason'
}

test_component_installers_delegate_to_bundle()
{
    source_root="$TEST_ROOT/component-source"
    prepare_source "$source_root" component
    source_root=$(CDPATH='' cd -- "$source_root" && pwd -P)
    component_index=0
    for component_installer in \
        "$BACKUP_COMPONENT_INSTALLER" \
        "$RUNTIME_COMPONENT_INSTALLER"; do
        component_index=$((component_index + 1))
        host_root="$TEST_ROOT/host-component-$component_index"
        release_id="release-component-$component_index-000001"
        mkdir -p "$host_root"
        host_root=$(CDPATH='' cd -- "$host_root" && pwd -P)
        if CONTROL_PLANE_RELEASE_BUNDLE_TEST_MODE=1 \
            CONTROL_PLANE_RELEASE_BUNDLE_TEST_ROOT="$host_root" \
            CONTROL_PLANE_RELEASE_BUNDLE_SOURCE_ROOT="$source_root" \
            "$component_installer" > "$TEST_ROOT/component-no-id-output" 2>&1; then
            fail 'retired component installer reported success without a complete release ID'
        fi
        CONTROL_PLANE_RELEASE_BUNDLE_TEST_MODE=1 \
            CONTROL_PLANE_RELEASE_BUNDLE_TEST_ROOT="$host_root" \
            CONTROL_PLANE_RELEASE_BUNDLE_SOURCE_ROOT="$source_root" \
            "$component_installer" "$release_id" >/dev/null
        assert_manifest_release "$host_root" "$release_id"
        assert_bundle_modes "$host_root" "$release_id"
        assert_stable_units_match_source "$host_root" "$source_root"
        assert_launcher_dispatches "$host_root" component delegated-component-install
    done
}

main()
{
    [ -f "$CONTRACT" ] && [ -x "$INSTALLER" ] && [ -x "$DISPATCHER_SOURCE" ] \
        && [ -x "$BACKUP_COMPONENT_INSTALLER" ] \
        && [ -x "$RUNTIME_COMPONENT_INSTALLER" ] && [ -x "$COLD_BOOT_ACCEPTANCE" ] \
        || fail 'bundle implementation or canonical contract is unavailable'
    prepare_fake_systemctl
    test_initial_install_and_dispatch
    test_crash_and_safety_gates
    test_lock_contention
    test_unsafe_sources
    test_asset_contract_is_an_immutable_trust_root
    test_dispatcher_source_is_immutable_owner_trusted
    test_systemd_enablement_transaction
    test_post_manifest_timer_recovery
    test_legacy_port8000_deprovision
    test_release_id_safety
    test_cold_boot_uses_manifest_selected_assets
    test_component_installers_delegate_to_bundle
    printf '%s\n' 'CONTROL_PLANE_HOST_RELEASE_BUNDLE_PASS'
}

TEST_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/coolify-host-release-bundle.XXXXXX")
TEST_ROOT=$(CDPATH='' cd -- "$TEST_ROOT" && pwd -P)
trap 'rm -rf "$TEST_ROOT"' 0 HUP INT TERM
main "$@"
