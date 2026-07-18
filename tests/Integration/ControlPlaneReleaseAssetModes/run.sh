#!/bin/sh

set -eu

SCRIPT_DIRECTORY=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd -P)
REPOSITORY_ROOT=$(CDPATH='' cd -- "$SCRIPT_DIRECTORY/../../.." && pwd -P)
readonly CONTRACT="$REPOSITORY_ROOT/docker/control-plane-blue-green/release-assets.contract"
readonly INSTALLER="$REPOSITORY_ROOT/docker/control-plane-blue-green/install-host-release-bundle.sh"
readonly DISPATCHER="$REPOSITORY_ROOT/docker/control-plane-blue-green/release-dispatch.sh"
readonly OPERATOR="$REPOSITORY_ROOT/docker/control-plane-blue-green/control-plane-blue-green.sh"
readonly INGRESS_CONTROLLER="$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/traefik-ingress.sh"
readonly BACKUP_CONTROLLER="$REPOSITORY_ROOT/docker/control-plane-blue-green/backup-quiesce/control-plane-backup-quiesce.sh"

fail()
{
    printf 'CONTROL_PLANE_RELEASE_ASSET_MODES_FAILURE %s\n' "$1" >&2
    exit 1
}

file_mode()
{
    stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"
}

extract_function()
{
    source_file=$1
    function_name=$2
    destination_file=$3
    awk -v function_name="$function_name" '
        $0 == function_name "()" { found = 1; capture = 1 }
        capture { print }
        capture && $0 == "}" { complete = 1; exit }
        END { if (!found || !complete) exit 1 }
    ' "$source_file" > "$destination_file" \
        || fail "could not extract $function_name from $source_file"
}

prepare_bundle_source()
{
    bundle_source_root=$1
    mkdir -p "$bundle_source_root"
    while IFS='|' read -r _role source_relative _destination_relative _mode _dispatchable; do
        bundle_source_path="$bundle_source_root/$source_relative"
        mkdir -p "${bundle_source_path%/*}"
        cp "$REPOSITORY_ROOT/docker/control-plane-blue-green/$source_relative" \
            "$bundle_source_path"
    done < "$CONTRACT"
}

invoke_bundle_installer()
{
    bundle_host_root=$1
    bundle_source_root=$2
    bundle_release_id=$3
    CONTROL_PLANE_RELEASE_BUNDLE_TEST_MODE=1 \
        CONTROL_PLANE_RELEASE_BUNDLE_TEST_ROOT="$bundle_host_root" \
        CONTROL_PLANE_RELEASE_BUNDLE_SOURCE_ROOT="$bundle_source_root" \
        "$INSTALLER" "$bundle_release_id"
}

assert_bundle_release_modes()
{
    bundle_host_root=$1
    bundle_source_root=$2
    bundle_release_id=$3
    bundle_manifest="$bundle_host_root/etc/coolify-control-plane/release.manifest"
    bundle_release_root="$bundle_host_root/usr/local/lib/coolify-control-plane/releases/$bundle_release_id"
    [ "$(file_mode "$bundle_manifest")" = 600 ] \
        || fail 'release bundle did not publish a mode-0600 manifest'
    while IFS='|' read -r role source_relative destination_relative expected_mode _dispatchable; do
        installed_path="$bundle_release_root/$destination_relative"
        [ "$(file_mode "$installed_path")" = "$expected_mode" ] \
            || fail "release bundle installed the wrong mode: $role"
        expected_sha256=$(sha256sum "$bundle_source_root/$source_relative" | awk '{print $1}')
        expected_record="asset|$role|$installed_path|$expected_sha256|$(id -u)|$(id -g)|$expected_mode"
        [ "$(awk -F'|' -v role="$role" '$1 == "asset" && $2 == role { print }' \
            "$bundle_manifest")" = "$expected_record" ] \
            || fail "release bundle manifest does not attest exact installed mode and bytes: $role"
    done < "$CONTRACT"
    [ "$(file_mode "$bundle_host_root/usr/local/sbin/control-plane-blue-green")" = 755 ] \
        && [ "$(file_mode "$bundle_host_root/usr/local/libexec/coolify-control-plane-release-dispatch")" = 700 ] \
        || fail 'release bundle stable launcher or dispatcher mode is invalid'
}

assert_source_mode_normalized()
{
    normalized_role=$1
    alternate_mode=$2
    scenario_root="$TEST_ROOT/normalize-$normalized_role"
    source_root="$scenario_root/source"
    host_root="$scenario_root/host"
    release_id="release-normalize-$normalized_role-000001"
    mkdir -p "$host_root"
    chmod 0700 "$host_root"
    prepare_bundle_source "$source_root"
    source_relative=$(awk -F'|' -v role="$normalized_role" \
        '$1 == role { print $2 }' "$CONTRACT")
    destination_relative=$(awk -F'|' -v role="$normalized_role" \
        '$1 == role { print $3 }' "$CONTRACT")
    expected_mode=$(awk -F'|' -v role="$normalized_role" \
        '$1 == role { print $4 }' "$CONTRACT")
    chmod "0$alternate_mode" "$source_root/$source_relative"
    invoke_bundle_installer "$host_root" "$source_root" "$release_id" >/dev/null
    [ "$(file_mode "$host_root/usr/local/lib/coolify-control-plane/releases/$release_id/$destination_relative")" \
        = "$expected_mode" ] \
        || fail "release bundle did not normalize the installed mode: $normalized_role"
}

assert_release_id_rejected()
{
    rejected_release_id=$1
    scenario_root="$TEST_ROOT/reject-release-id-$(printf '%s' "$rejected_release_id" | tr -c 'A-Za-z0-9' '-')"
    mkdir -p "$scenario_root"
    chmod 0700 "$scenario_root"
    if CONTROL_PLANE_RELEASE_BUNDLE_TEST_MODE=1 \
        CONTROL_PLANE_RELEASE_BUNDLE_TEST_ROOT="$scenario_root" \
        CONTROL_PLANE_RELEASE_BUNDLE_SOURCE_ROOT="$REPOSITORY_ROOT/docker/control-plane-blue-green" \
        "$INSTALLER" "$rejected_release_id" > "$scenario_root/output" 2>&1; then
        fail "unsafe release ID was accepted: $rejected_release_id"
    fi
}

assert_unit_dispatch()
{
    unit_path=$1
    role=$2
    grep -F -q "/usr/local/libexec/coolify-control-plane-release-dispatch $role" "$unit_path" \
        || fail "systemd unit does not dispatch the active release role: $role"
}

assert_dispatcher_sandbox()
{
    unit_path=$1
    state_allowance=${2:-}
    grep -F -x -q 'ProtectSystem=strict' "$unit_path" \
        || fail "dispatcher unit does not use a strict system filesystem: $unit_path"
    grep -F -x -q 'ReadWritePaths=/run/coolify-control-plane-release-dispatch' "$unit_path" \
        || fail "dispatcher unit cannot create a manifest snapshot: $unit_path"
    grep -F -x -q 'ReadWritePaths=/run/lock' "$unit_path" \
        || fail "dispatcher unit cannot create or validate its transaction lock: $unit_path"
    for dispatcher_recovery_path in \
        /etc/coolify-control-plane \
        /etc/systemd/system \
        /usr/local/libexec \
        /usr/local/sbin; do
        grep -F -x -q "ReadWritePaths=$dispatcher_recovery_path" "$unit_path" \
            || fail "dispatcher unit cannot recover an interrupted release activation at $dispatcher_recovery_path: $unit_path"
    done
    grep -F -x -q 'RuntimeDirectory=coolify-control-plane-release-dispatch' "$unit_path" \
        || fail "dispatcher unit does not recreate its snapshot directory after reboot: $unit_path"
    grep -F -x -q 'RuntimeDirectoryMode=0700' "$unit_path" \
        || fail "dispatcher unit does not protect its snapshot directory identity: $unit_path"
    grep -F -x -q 'RuntimeDirectoryPreserve=yes' "$unit_path" \
        || fail "dispatcher unit may remove a shared snapshot directory while another dispatcher is active: $unit_path"
    [ -z "$state_allowance" ] || grep -F -x -q "$state_allowance" "$unit_path" \
        || fail "dispatcher unit lost its existing state-directory allowance: $unit_path"
}

assert_global_ipv4_policy()
{
    source_file=$1
    parameter_order=$2
    source_label=$3
    global_function="$TEST_ROOT/$source_label.global-ipv4-function"
    extract_function "$source_file" validate_globally_routable_ipv4 "$global_function"
    if ! (
        fail()
        {
            return 1
        }
        if [ "$parameter_order" = name-first ]; then
            single_line_function="$TEST_ROOT/$source_label.single-line-function"
            ipv4_function="$TEST_ROOT/$source_label.ipv4-function"
            extract_function "$source_file" validate_single_line "$single_line_function"
            extract_function "$source_file" validate_ipv4 "$ipv4_function"
            # shellcheck disable=SC1090
            . "$single_line_function"
            # shellcheck disable=SC1090
            . "$ipv4_function"
        fi
        # shellcheck disable=SC1090
        . "$global_function"
        for accepted_ipv4 in 1.1.1.1 8.8.8.8 75.8.210.180; do
            if [ "$parameter_order" = name-first ]; then
                validate_globally_routable_ipv4 TEST_IPV4 "$accepted_ipv4"
            else
                validate_globally_routable_ipv4 "$accepted_ipv4" TEST_IPV4
            fi
        done
        for rejected_ipv4 in \
            0.1.2.3 10.0.0.1 100.64.0.1 127.0.0.1 169.254.1.1 \
            172.16.0.1 192.0.2.1 192.168.1.1 198.18.0.1 198.51.100.1 \
            203.0.113.1 224.0.0.1 240.0.0.1; do
            if [ "$parameter_order" = name-first ]; then
                ! validate_globally_routable_ipv4 TEST_IPV4 "$rejected_ipv4" \
                    >/dev/null 2>&1
            else
                ! validate_globally_routable_ipv4 "$rejected_ipv4" TEST_IPV4 \
                    >/dev/null 2>&1
            fi
        done
    ); then
        fail "$source_label globally-routable public IPv4 policy is incomplete"
    fi
}

assert_release_activation_precedes_service_start()
{
    manifest_publish_line=$(grep -n -x 'publish_release_manifest' "$INSTALLER" \
        | cut -d: -f1)
    timer_start_line=$(grep -n -x '    activate_backup_quiesce_timer' "$INSTALLER" \
        | cut -d: -f1)
    [ -n "$manifest_publish_line" ] && [ -n "$timer_start_line" ] \
        || fail 'bundle installer activation ordering calls are unavailable'
    [ "$manifest_publish_line" -lt "$timer_start_line" ] \
        || fail 'bundle installer starts a service before publishing the full release activation'
}

main()
{
    [ -f "$CONTRACT" ] && [ -x "$INSTALLER" ] \
        && [ -x "$DISPATCHER" ] && [ -x "$OPERATOR" ] && [ -x "$INGRESS_CONTROLLER" ] \
        || fail 'release contract or enforcement sources are unavailable'
    awk -F'|' 'NF != 5 || seen[$1]++ { invalid = 1 } END { exit invalid || NR == 0 }' \
        "$CONTRACT" || fail 'canonical release asset contract is malformed'

    grep -F -x -q "assert_source_asset release-dispatcher \"\$DISPATCHER_SOURCE\"" \
        "$INSTALLER" \
        || fail 'bundle installer does not apply release-source ownership and root-trust policy to its dispatcher'
    grep -F -x -q "install_directory \"\$dispatch_snapshot_directory\" 700" "$INSTALLER" \
        || fail 'bundle installer does not provision the dispatcher snapshot directory before sandboxed services run'
    assert_release_activation_precedes_service_start
    assert_global_ipv4_policy "$OPERATOR" value-first operator
    assert_global_ipv4_policy "$INGRESS_CONTROLLER" name-first ingress-controller
    # shellcheck disable=SC2016 # This is an exact production-policy source assertion.
    grep -F -x -q \
        '        validate_globally_routable_ipv4 "$expected_ipv4" CONTROL_PLANE_EXPECTED_PUBLIC_IPV4' \
        "$OPERATOR" \
        || fail 'production operator does not require a globally routable public IPv4'
    # shellcheck disable=SC2016 # This is an exact production-policy source assertion.
    grep -F -x -q \
        '    validate_globally_routable_ipv4 CONTROL_PLANE_INGRESS_EXPECTED_IPV4 "$expected_ipv4"' \
        "$INGRESS_CONTROLLER" \
        || fail 'production ingress proof does not require a globally routable public IPv4'

    pass_root="$TEST_ROOT/exact-pass"
    pass_source="$pass_root/source"
    pass_host="$pass_root/host"
    pass_release_id=release-exact-modes-000001
    mkdir -p "$pass_host"
    chmod 0700 "$pass_host"
    prepare_bundle_source "$pass_source"
    invoke_bundle_installer "$pass_host" "$pass_source" "$pass_release_id" >/dev/null
    assert_bundle_release_modes "$pass_host" "$pass_source" "$pass_release_id"

    assert_source_mode_normalized operator-compose 640
    assert_source_mode_normalized backup-quiesce-service-unit 640
    assert_source_mode_normalized runtime-fence-controller 500
    assert_source_mode_normalized backup-attestation-verifier 555
    assert_source_mode_normalized backup-quiesce-controller 555

    assert_release_id_rejected .hidden-release-000001
    assert_release_id_rejected .new-release-candidate-000001

    # shellcheck disable=SC2016 # This is a literal operator source contract.
    grep -F -q 'backup_attestation_verifier_expected_mode=$(release_asset_expected_mode backup-attestation-verifier)' \
        "$OPERATOR" || fail 'backup verifier consumer bypasses the canonical mode projection'
    # shellcheck disable=SC2016 # This is a literal controller source contract.
    if ! grep -F -q 'CONTROL_PLANE_BACKUP_QUIESCE_CONTROLLER_PATH' "$BACKUP_CONTROLLER" \
        || ! grep -F -q '[ "$(file_mode "$controller_path")" = 755 ]' "$BACKUP_CONTROLLER"; then
        fail 'backup-quiesce consumer does not pin its versioned controller and exact mode'
    fi
    grep -F -q \
        'ExecStart=/usr/local/sbin/control-plane-blue-green backup-quiesce watchdog-scan' \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/backup-quiesce/control-plane-backup-quiesce-watchdog.service" \
        || fail 'backup-quiesce watchdog bypasses the stable dispatcher and versioned operator'
    assert_dispatcher_sandbox \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/backup-quiesce/control-plane-backup-quiesce-watchdog.service" \
        'ReadWritePaths=/var/lib/coolify/control-plane-backup-quiesce'
    assert_unit_dispatch \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/coolify-runtime-attestation-ssh-fence.service" \
        runtime-fence-controller
    assert_dispatcher_sandbox \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/coolify-runtime-attestation-ssh-fence.service" \
        'ReadWritePaths=/var/lib/coolify-runtime-attestation-ssh-fence'
    assert_unit_dispatch \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/coolify-runtime-attestation-ssh-fence-watchdog.service" \
        runtime-fence-controller
    assert_dispatcher_sandbox \
        "$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/coolify-runtime-attestation-ssh-fence-watchdog.service" \
        'ReadWritePaths=/var/lib/coolify-runtime-attestation-ssh-fence'
    printf '%s\n' 'CONTROL_PLANE_RELEASE_ASSET_MODES_PASS'
}

TEST_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/coolify-release-asset-modes.XXXXXX")
TEST_ROOT=$(CDPATH='' cd -- "$TEST_ROOT" && pwd -P)
trap 'rm -rf "$TEST_ROOT"' 0 HUP INT TERM
main "$@"
