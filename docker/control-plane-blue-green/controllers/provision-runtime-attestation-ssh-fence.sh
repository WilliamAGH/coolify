#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

SCRIPT_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
readonly SCRIPT_DIRECTORY
readonly CONFIG_DIRECTORY=/etc/coolify-runtime-attestation-ssh-fence
readonly RUNTIME_ENV=$CONFIG_DIRECTORY/runtime.env
readonly CONTROLLER="$SCRIPT_DIRECTORY/runtime-attestation-ssh-fence.sh"
readonly REAPER="$SCRIPT_DIRECTORY/self-ssh-controlmaster-reaper.sh"
readonly PROVIDER_PROBE="$SCRIPT_DIRECTORY/traefik-docker-provider-freshness-probe.sh"
readonly QUEUE_PROBE="$SCRIPT_DIRECTORY/proxy-queue-zero-probe.sh"
readonly TERMINAL_PROBE="$SCRIPT_DIRECTORY/control-plane-terminal-state-probe.sh"
readonly RESTORE_SERVICE=coolify-runtime-attestation-ssh-fence.service
readonly WATCHDOG_SERVICE=coolify-runtime-attestation-ssh-fence-watchdog.service
readonly TRANSACTION_LOCK=/run/lock/coolify-runtime-attestation-ssh-fence-provision.lock
readonly SEMANTIC_KEYS='CONTROL_PLANE_RUNTIME_OPERATION_ID CONTROL_PLANE_RUNTIME_PROXY_CONTAINER CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256 CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_METADATA CONTROL_PLANE_RUNTIME_MANAGEMENT_ENDPOINTS CONTROL_PLANE_RUNTIME_ADDITIONAL_NETWORK_IDS CONTROL_PLANE_RUNTIME_SELF_SSH_TARGET CONTROL_PLANE_RUNTIME_PROBE_MAX_AGE_SECONDS CONTROL_PLANE_RUNTIME_QUEUE_STABLE_SECONDS CONTROL_PLANE_RUNTIME_PROVIDER_CAPTURE_EXPECTATION CONTROL_PLANE_RUNTIME_PROVIDER_API_URL CONTROL_PLANE_RUNTIME_PROVIDER_ROUTER CONTROL_PLANE_RUNTIME_PROVIDER_SERVICE CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT CONTROL_PLANE_RUNTIME_PROVIDER_HEADER_FILE CONTROL_PLANE_RUNTIME_TERMINAL_HTTPS_URL CONTROL_PLANE_RUNTIME_TERMINAL_LOCAL_INGRESS_URL'

fail()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE_PROVISION_FAILURE %s\n' "$1" >&2
    exit 1
}

acquire_transaction_lock()
{
    exec 9>"$TRANSACTION_LOCK"
    flock --nonblock 9 \
        || fail 'another runtime-fence provisioner transaction is active'
}

test_crash()
{
    if [[ ${CONTROL_PLANE_RUNTIME_PROVISION_TEST_MODE:-0} == 1 \
        && ${CONTROL_PLANE_RUNTIME_PROVISION_TEST_CRASH_AT:-} == "$1" ]]; then
        exit 86
    fi
}

sha256_file()
{
    sha256sum "$1" | awk '{print $1}'
}

assert_root_executable()
{
    [[ -f $1 && ! -L $1 && -x $1 && $(stat -c '%u:%g:%a' "$1") == 0:0:700 ]] \
        || fail "installed executable must be root:root mode 0700: $1"
}

release_files_manifest_sha256()
{
    local file=$1 output
    mapfile -t environment_lines < "$file"
    output=$(env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
        "${environment_lines[@]}" "$TERMINAL_PROBE" validate-pool-plan) \
        || fail 'pool plan validation failed'
    [[ $(wc -l <<< "$output") -eq 3 \
        && $(grep -F -x -c version=2 <<< "$output") -eq 1 \
        && $(grep -F -x -c pool_plan=validated <<< "$output") -eq 1 \
        && $(grep -E -c '^release_files_sha256=[a-f0-9]{64}$' <<< "$output") -eq 1 ]] \
        || fail 'pool plan validator returned a malformed identity result'
    sed -n 's/^release_files_sha256=//p' <<< "$output"
}

validate_environment()
{
    local file=$1 key line value
    local -A seen=()

    [[ -f $file && ! -L $file ]] || fail 'environment source must be a regular non-symlink file'
    while IFS= read -r line || [[ -n $line ]]; do
        [[ -n $line && $line != *$'\r'* && $line == *=* ]] \
            || fail 'environment source contains an empty or malformed line'
        key=${line%%=*}
        value=${line#*=}
        [[ $key =~ ^[A-Z0-9_]+$ && -z ${seen[$key]+present} ]] \
            || fail 'environment source has a malformed or duplicate setting'
        validate_environment_value "$key" "$value"
        seen[$key]=1
    done < "$file"
    for key in $SEMANTIC_KEYS; do
        [[ -n ${seen[$key]+present} ]] || fail "environment source is missing setting: $key"
    done
}

validate_identifier_value()
{
    [[ $1 =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ ]]
}

validate_absolute_path_value()
{
    [[ $1 == /* && $1 != *'//'*
        && $1 != '/..' && $1 != '/../'* && $1 != *'/../'* && $1 != *'/..'
        && $1 =~ ^/[A-Za-z0-9_./-]+$ ]]
}

validate_url_value()
{
    [[ -n $1 && $1 =~ ^[[:graph:]]+$ && $1 != *$'\r'* ]]
}

validate_provider_api_url()
{
    local url=$1 authority_and_resource authority resource port
    local resource_pattern='^/([A-Za-z0-9._~/:+,=@-]|%[A-Fa-f0-9]{2})*(\?([A-Za-z0-9._~/:+,=&@?-]|%[A-Fa-f0-9]{2})+)?$'

    [[ $url == http://* && $url != *'#'* ]] || return 1
    authority_and_resource=${url#http://}
    [[ $authority_and_resource == */* ]] || return 1
    authority=${authority_and_resource%%/*}
    resource=/${authority_and_resource#*/}
    case "$authority" in
        127.0.0.1:*) port=${authority#127.0.0.1:} ;;
        \[::1\]:*) port=${authority#\[::1\]:} ;;
        *) return 1 ;;
    esac
    [[ $port =~ ^[1-9][0-9]{0,4}$ ]] && ((port <= 65535)) \
        && [[ $resource =~ $resource_pattern ]]
}

validate_local_ingress_url()
{
    local url=$1 port

    validate_url_value "$url" \
        && [[ $url =~ ^http://127\.0\.0\.1:([1-9][0-9]{0,4})(/[A-Za-z0-9_./?\&=%~-]*)?$ ]] \
        || return 1
    port=${BASH_REMATCH[1]}
    ((port <= 65535))
}

validate_management_endpoints_value()
{
    local value=$1 endpoint interface address
    local -a endpoints

    [[ -n $value && $value != ,* && $value != *, && $value != *,,* ]] || return 1
    IFS=, read -r -a endpoints <<< "$value"
    for endpoint in "${endpoints[@]}"; do
        [[ $endpoint == *=* ]] || return 1
        interface=${endpoint%%=*}
        address=${endpoint#*=}
        [[ $address != *=* && $interface =~ ^[A-Za-z0-9_.-]+$ \
            && $address =~ ^[A-Fa-f0-9:.]+$ ]] || return 1
    done
}

validate_environment_value()
{
    local key=$1 value=$2

    case "$key" in
        CONTROL_PLANE_RUNTIME_OPERATION_ID|CONTROL_PLANE_RUNTIME_PROXY_CONTAINER)
            validate_identifier_value "$value" ;;
        CONTROL_PLANE_RUNTIME_MANAGEMENT_ENDPOINTS)
            validate_management_endpoints_value "$value" ;;
        CONTROL_PLANE_RUNTIME_ADDITIONAL_NETWORK_IDS)
            [[ -z $value || $value =~ ^[a-f0-9]{64}(,[a-f0-9]{64})*$ ]] ;;
        CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST)
            validate_absolute_path_value "$value" ;;
        CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256)
            [[ $value =~ ^[a-f0-9]{64}$ ]] ;;
        CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_METADATA)
            [[ $value =~ ^0:0:600:[1-9][0-9]*$ ]] ;;
        CONTROL_PLANE_RUNTIME_SELF_SSH_TARGET)
            [[ $value =~ ^[A-Za-z0-9_.:-]+$ ]] ;;
        CONTROL_PLANE_RUNTIME_PROBE_MAX_AGE_SECONDS|CONTROL_PLANE_RUNTIME_QUEUE_STABLE_SECONDS)
            [[ $value =~ ^[1-9][0-9]*$ ]] ;;
        CONTROL_PLANE_RUNTIME_PROVIDER_CAPTURE_EXPECTATION)
            [[ $value == incumbent || $value == absent ]] ;;
        CONTROL_PLANE_RUNTIME_PROVIDER_API_URL)
            validate_provider_api_url "$value" ;;
        CONTROL_PLANE_RUNTIME_PROVIDER_ROUTER|CONTROL_PLANE_RUNTIME_PROVIDER_SERVICE)
            [[ $value =~ ^[A-Za-z0-9_.-]+@docker$ ]] ;;
        CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT)
            [[ $value =~ ^[1-9][0-9]{0,4}$ ]] && ((value <= 65535)) ;;
        CONTROL_PLANE_RUNTIME_PROVIDER_HEADER_FILE)
            [[ -z $value ]] || validate_absolute_path_value "$value" ;;
        CONTROL_PLANE_RUNTIME_TERMINAL_HTTPS_URL)
            validate_url_value "$value" && [[ $value == https://* ]] ;;
        CONTROL_PLANE_RUNTIME_TERMINAL_LOCAL_INGRESS_URL)
            validate_local_ingress_url "$value" ;;
        *)
            fail "environment source has an unknown setting: $key" ;;
    esac || fail "environment source has an unsafe value: $key"
}

environment_command()
{
    local file=$1
    shift
    mapfile -t environment_lines < "$file"
    env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
        "${environment_lines[@]}" "$CONTROLLER" "$@"
}

environment_value()
{
    local file=$1 key=$2 count value
    count=$(grep -E -c "^${key}=" "$file" || true)
    [[ $count -eq 1 ]] || fail "runtime environment key is absent or duplicated: $key"
    value=$(sed -n "s/^${key}=//p" "$file")
    [[ $value != *$'\n'* ]] || fail "runtime environment value contains a newline: $key"
    printf '%s\n' "$value"
}

install_environment_atomically()
{
    local candidate=$1
    chown root:root "$candidate"
    chmod 0600 "$candidate"
    sync "$candidate"
    mv -f -- "$candidate" "$RUNTIME_ENV"
    sync "$CONFIG_DIRECTORY"
}

controller_phase()
{
    local file=$1 output phase count
    output=$(environment_command "$file" status)
    count=$(printf '%s\n' "$output" | tr ' ' '\n' | grep -E -c '^phase=' || true)
    [[ $count -eq 1 ]] || fail 'controller status did not report exactly one runtime fence phase'
    phase=$(printf '%s\n' "$output" | tr ' ' '\n' | sed -n 's/^phase=//p')
    [[ $phase =~ ^(preparing|active)$ ]] \
        || fail "runtime fence cannot be armed from controller phase: $phase"
    printf '%s\n' "$phase"
}

assert_boot_restore_dependencies()
{
    [[ -L /etc/systemd/system/docker.service.requires/$RESTORE_SERVICE \
        && -L /etc/systemd/system/docker.socket.requires/$RESTORE_SERVICE \
        && -L /etc/systemd/system/docker.service.wants/$WATCHDOG_SERVICE \
        && $(readlink -f /etc/systemd/system/docker.service.requires/$RESTORE_SERVICE) \
            == /etc/systemd/system/$RESTORE_SERVICE \
        && $(readlink -f /etc/systemd/system/docker.socket.requires/$RESTORE_SERVICE) \
            == /etc/systemd/system/$RESTORE_SERVICE \
        && $(readlink -f /etc/systemd/system/docker.service.wants/$WATCHDOG_SERVICE) \
            == /etc/systemd/system/$WATCHDOG_SERVICE ]] \
        || fail 'armed restore/watchdog services are not durable through Docker activation paths'
    systemctl is-enabled --quiet "$RESTORE_SERVICE" "$WATCHDOG_SERVICE" \
        || fail 'armed restore/watchdog services are not enabled'
}

sync_boot_restore_dependencies()
{
    local path
    for path in /etc/systemd/system \
        /etc/systemd/system/docker.service.requires \
        /etc/systemd/system/docker.socket.requires \
        /etc/systemd/system/docker.service.wants; do
        [[ -e $path ]] && sync "$path"
    done
}

prepare()
{
    local source=${1:-} candidate=$CONFIG_DIRECTORY/.runtime.env.$$
    local release_files_sha256 semantic_config_sha256
    [[ $(id -u) -eq 0 ]] || fail 'provisioning requires root'
    [[ -f $source && ! -L $source && $(stat -c '%u:%g:%a' "$source") == 0:0:600 ]] \
        || fail 'source environment must be root:root mode 0600'
    validate_environment "$source"
    semantic_config_sha256=$(LC_ALL=C sort "$source" | sha256sum | awk '{print $1}')
    for executable in "$CONTROLLER" "$REAPER" "$PROVIDER_PROBE" "$QUEUE_PROBE" "$TERMINAL_PROBE"; do
        assert_root_executable "$executable"
    done
    release_files_sha256=$(release_files_manifest_sha256 "$source")
    if [[ -e $RUNTIME_ENV ]]; then
        [[ -f $RUNTIME_ENV && ! -L $RUNTIME_ENV && $(stat -c '%u:%g:%a' "$RUNTIME_ENV") == 0:0:600 ]] \
            || fail 'existing runtime.env is unsafe'
        if grep -F -x -q CONTROL_PLANE_RUNTIME_ARMED=1 "$RUNTIME_ENV"; then
            [[ $(environment_value "$RUNTIME_ENV" CONTROL_PLANE_RUNTIME_SEMANTIC_CONFIG_SHA256) \
                == "$semantic_config_sha256" \
                && $(environment_value "$RUNTIME_ENV" CONTROL_PLANE_RUNTIME_RELEASE_FILES_SHA256) \
                    == "$release_files_sha256" ]] \
                || fail 'refusing to replace an armed fence with different semantic configuration'
            printf 'CONTROL_PLANE_RUNTIME_FENCE_PROVISION prepared=true armed=true configuration_unchanged=true\n'
            return
        fi
    fi
    {
        printf 'CONTROL_PLANE_RUNTIME_ARMED=0\n'
        printf 'CONTROL_PLANE_RUNTIME_STATE_DIR=/var/lib/coolify-runtime-attestation-ssh-fence\n'
        cat "$source"
        printf 'CONTROL_PLANE_RUNTIME_SEMANTIC_CONFIG_SHA256=%s\n' "$semantic_config_sha256"
        printf 'CONTROL_PLANE_RUNTIME_RELEASE_FILES_SHA256=%s\n' "$release_files_sha256"
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_PROBE=%s\n' "$PROVIDER_PROBE"
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_PROBE_SHA256=%s\n' "$(sha256_file "$PROVIDER_PROBE")"
        printf 'CONTROL_PLANE_RUNTIME_CONTROLMASTER_REAPER=%s\n' "$REAPER"
        printf 'CONTROL_PLANE_RUNTIME_CONTROLMASTER_REAPER_SHA256=%s\n' "$(sha256_file "$REAPER")"
        printf 'CONTROL_PLANE_RUNTIME_QUEUE_PROBE=%s\n' "$QUEUE_PROBE"
        printf 'CONTROL_PLANE_RUNTIME_QUEUE_PROBE_SHA256=%s\n' "$(sha256_file "$QUEUE_PROBE")"
        printf 'CONTROL_PLANE_RUNTIME_TERMINAL_PROBE=%s\n' "$TERMINAL_PROBE"
        printf 'CONTROL_PLANE_RUNTIME_TERMINAL_PROBE_SHA256=%s\n' "$(sha256_file "$TERMINAL_PROBE")"
    } > "$candidate"
    install_environment_atomically "$candidate"
    systemctl disable "$RESTORE_SERVICE" "$WATCHDOG_SERVICE" >/dev/null 2>&1 || true
    printf 'CONTROL_PLANE_RUNTIME_FENCE_PROVISION prepared=true armed=false\n'
}

arm()
{
    local candidate=$CONFIG_DIRECTORY/.runtime.env.arm.$$ armed phase
    [[ $(id -u) -eq 0 ]] || fail 'arming requires root'
    [[ -f $RUNTIME_ENV && ! -L $RUNTIME_ENV && $(stat -c '%u:%g:%a' "$RUNTIME_ENV") == 0:0:600 ]] \
        || fail 'prepared runtime.env is absent or unsafe'
    armed=$(environment_value "$RUNTIME_ENV" CONTROL_PLANE_RUNTIME_ARMED)
    [[ $armed == 0 || $armed == 1 ]] || fail 'runtime.env armed state is invalid'
    phase=$(controller_phase "$RUNTIME_ENV")
    if [[ $armed == 0 ]]; then
        sed 's/^CONTROL_PLANE_RUNTIME_ARMED=0$/CONTROL_PLANE_RUNTIME_ARMED=1/' \
            "$RUNTIME_ENV" > "$candidate"
        controller_phase "$candidate" >/dev/null
        install_environment_atomically "$candidate"
        test_crash after-runtime-env-armed
    fi

    # The complete staged controller state is already durable.  Publish ARMED=1
    # before enabling any boot dependency so an enabled restore unit can never
    # start with a configuration that asks it to skip the fence.
    systemctl daemon-reload
    systemctl enable "$RESTORE_SERVICE" "$WATCHDOG_SERVICE" >/dev/null
    assert_boot_restore_dependencies
    sync_boot_restore_dependencies
    systemctl daemon-reload
    test_crash after-systemd-enable
    systemctl start "$RESTORE_SERVICE"
    test_crash after-restore-service-start
    systemctl start "$WATCHDOG_SERVICE"
    test_crash after-watchdog-service-start
    systemctl is-active --quiet "$RESTORE_SERVICE" "$WATCHDOG_SERVICE" \
        || fail 'armed restore/watchdog services are not active'

    # Starting the restore service is the first live nft mutation.  It occurs
    # only after the root-owned env and both persisted systemd relationships are
    # durable, so a reboot resumes the exact staged ruleset before Docker.
    if [[ $phase == preparing ]]; then
        environment_command "$RUNTIME_ENV" capture >/dev/null
        test_crash after-controller-capture
    fi
    environment_command "$RUNTIME_ENV" verify >/dev/null
    printf 'CONTROL_PLANE_RUNTIME_FENCE_PROVISION prepared=true armed=true services_active=true\n'
}

capture()
{
    run_prepared stage-capture >/dev/null
    test_crash after-capture-stage
    printf 'CONTROL_PLANE_RUNTIME_FENCE_PROVISION captured=true armed=false staged=true\n'
}

finalize_terminal_action()
{
    local expected_phase=$1 action=$2 candidate=$CONFIG_DIRECTORY/.runtime.env.terminal.$$
    local armed service status_output
    [[ $expected_phase == released || $expected_phase == aborted \
        || $expected_phase == recovery-aborted ]] \
        || fail 'terminal phase is invalid'
    [[ $(id -u) -eq 0 ]] || fail 'terminal action requires root'
    [[ -f $RUNTIME_ENV && ! -L $RUNTIME_ENV && $(stat -c '%u:%g:%a' "$RUNTIME_ENV") == 0:0:600 ]] \
        || fail 'runtime.env is absent or unsafe'
    status_output=$(environment_command "$RUNTIME_ENV" status)
    [[ $status_output == *"phase=$expected_phase"* ]] \
        || fail "controller is not in the required terminal phase: $expected_phase"
    armed=$(environment_value "$RUNTIME_ENV" CONTROL_PLANE_RUNTIME_ARMED)
    [[ $armed == 0 || $armed == 1 ]] || fail 'runtime.env armed state is invalid'
    if [[ $armed == 1 ]]; then
        sed 's/^CONTROL_PLANE_RUNTIME_ARMED=1$/CONTROL_PLANE_RUNTIME_ARMED=0/' \
            "$RUNTIME_ENV" > "$candidate"
        install_environment_atomically "$candidate"
        test_crash after-runtime-env-disarmed
    fi
    # A terminal fence must first make restore/watch idempotently inert on disk.
    # Every later seam only converges systemd state and can safely resume after a
    # process crash without ever publishing ARMED=1 again.
    systemctl stop "$WATCHDOG_SERVICE"
    ! systemctl is-active --quiet "$WATCHDOG_SERVICE" \
        || fail 'terminal watchdog service remained active after stop'
    test_crash after-watchdog-service-stop
    systemctl stop "$RESTORE_SERVICE"
    ! systemctl is-active --quiet "$RESTORE_SERVICE" \
        || fail 'terminal restore service remained active after stop'
    test_crash after-restore-service-stop
    systemctl disable "$WATCHDOG_SERVICE" >/dev/null
    sync_boot_restore_dependencies
    ! systemctl is-enabled --quiet "$WATCHDOG_SERVICE" \
        || fail 'terminal watchdog service remained enabled after disable'
    test_crash after-watchdog-service-disable
    systemctl disable "$RESTORE_SERVICE" >/dev/null
    sync_boot_restore_dependencies
    ! systemctl is-enabled --quiet "$RESTORE_SERVICE" \
        || fail 'terminal restore service remained enabled after disable'
    test_crash after-restore-service-disable
    systemctl daemon-reload
    test_crash after-terminal-systemd-reload
    [[ ! -e /etc/systemd/system/docker.service.requires/$RESTORE_SERVICE \
        && ! -e /etc/systemd/system/docker.socket.requires/$RESTORE_SERVICE \
        && ! -e /etc/systemd/system/docker.service.wants/$WATCHDOG_SERVICE ]] \
        || fail 'terminal restore service remained required by a Docker activation path'
    for service in "$WATCHDOG_SERVICE" "$RESTORE_SERVICE"; do
        ! systemctl is-active --quiet "$service" \
            || fail "terminal service remained active: $service"
        ! systemctl is-enabled --quiet "$service" \
            || fail "terminal service remained enabled: $service"
    done
    [[ $(environment_value "$RUNTIME_ENV" CONTROL_PLANE_RUNTIME_ARMED) == 0 ]] \
        || fail 'terminal finalization republished an armed runtime environment'
    printf 'CONTROL_PLANE_RUNTIME_FENCE_PROVISION terminal=%s armed=false services_active=false\n' "$action"
}

abort_and_finalize()
{
    run_prepared abort >/dev/null
    finalize_terminal_action aborted abort
}

recover_abort_and_finalize()
{
    run_prepared recover-abort >/dev/null
    finalize_terminal_action recovery-aborted recover-abort
}

run_prepared()
{
    [[ $(id -u) -eq 0 ]] || fail 'runtime fence execution requires root'
    [[ -f $RUNTIME_ENV && ! -L $RUNTIME_ENV && $(stat -c '%u:%g:%a' "$RUNTIME_ENV") == 0:0:600 ]] \
        || fail 'prepared runtime.env is absent or unsafe'
    environment_command "$RUNTIME_ENV" "$@"
}

container_runtime_sha256()
{
    [[ $(id -u) -eq 0 ]] || fail 'runtime identity inspection requires root'
    assert_root_executable "$CONTROLLER"
    "$CONTROLLER" container-runtime-sha256 "${1:-}"
}

action=${1:-}
[[ $action == status || $action == container-runtime-sha256 ]] || acquire_transaction_lock
case "$action" in
    container-runtime-sha256) container_runtime_sha256 "${2:-}" ;;
    prepare) prepare "${2:-}" ;;
    capture) capture ;;
    arm) arm ;;
    assert-pool-baseline)
        [[ $# -eq 1 ]] || fail 'pool baseline does not accept member arguments'
        run_prepared assert-pool-baseline
        ;;
    verify) run_prepared verify ;;
    verify-post-revoke) run_prepared verify-post-revoke ;;
    prove-pool-denied)
        [[ $# -eq 1 ]] || fail 'two-member pool denial does not accept member arguments'
        run_prepared prove-pool-denied
        ;;
    pin-ingress-pool-manifest)
        [[ $# -eq 2 ]] || fail 'ingress pool pin requires exactly one manifest SHA-256'
        run_prepared pin-ingress-pool-manifest "$2"
        ;;
    repin-pool)
        [[ $# -eq 3 ]] || fail 'pool repin requires exact web-a and web-b IDs'
        run_prepared repin-pool "$2" "$3"
        ;;
    adopt-rollback-incumbent) run_prepared adopt-rollback-incumbent "${2:-}" ;;
    recover-daemon) run_prepared recover-daemon "${2:-}" "${3:-}" "${4:-}" "${5:-}" ;;
    finalize-daemon-recovery) run_prepared finalize-daemon-recovery "${2:-}" "${3:-}" \
        "${4:-}" "${5:-}" "${6:-}" ;;
    classify-routed-runtime) run_prepared classify-routed-runtime "${2:-}" "${3:-}" \
        "${4:-}" "${5:-}" ;;
    recover-routed-runtime) run_prepared recover-routed-runtime "${2:-}" "${3:-}" "${4:-}" \
        "${5:-}" ;;
    finalize-routed-runtime-recovery) run_prepared finalize-routed-runtime-recovery "${2:-}" \
        "${3:-}" "${4:-}" "${5:-}" "${6:-}" ;;
    recover-abort) recover_abort_and_finalize ;;
    release) run_prepared release ;;
    verify-released) run_prepared verify-released ;;
    finalize-release) finalize_terminal_action released release ;;
    abort) abort_and_finalize ;;
    status) run_prepared status ;;
    *) fail 'usage: provision-runtime-attestation-ssh-fence.sh {container-runtime-sha256 CONTAINER|prepare ROOT_0600_ENV|capture|arm|assert-pool-baseline|verify|verify-post-revoke|prove-pool-denied|pin-ingress-pool-manifest INGRESS_SHA256|repin-pool WEB_A_ID WEB_B_ID|adopt-rollback-incumbent CONTAINER|recover-daemon DIRECTION COLOR PARENT_PHASE PROVIDER_EXPECTATION|finalize-daemon-recovery DIRECTION COLOR PARENT_PHASE PROVIDER_EXPECTATION ROUTE_PROOF_SHA256|classify-routed-runtime DIRECTION COLOR PARENT_PHASE PROVIDER_EXPECTATION|recover-routed-runtime DIRECTION COLOR PARENT_PHASE PROVIDER_EXPECTATION|finalize-routed-runtime-recovery DIRECTION COLOR PARENT_PHASE PROVIDER_EXPECTATION ROUTE_PROOF_SHA256|recover-abort|release|verify-released|finalize-release|abort|status}' ;;
esac
