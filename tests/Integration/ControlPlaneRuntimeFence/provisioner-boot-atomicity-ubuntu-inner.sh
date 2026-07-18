#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

readonly TEST_ROOT=/run/control-plane-runtime-fence-boot-atomicity
readonly CONFIG_DIRECTORY=/etc/coolify-runtime-attestation-ssh-fence
readonly RUNTIME_ENV=$CONFIG_DIRECTORY/runtime.env
readonly PROVISIONER=/usr/local/sbin/coolify-runtime-fence-provision
readonly CONTROLLER=/usr/local/sbin/runtime-attestation-ssh-fence.sh
readonly REAPER=/usr/local/sbin/self-ssh-controlmaster-reaper.sh
readonly PROVIDER_PROBE=/usr/local/sbin/traefik-docker-provider-freshness-probe.sh
readonly QUEUE_PROBE=/usr/local/sbin/proxy-queue-zero-probe.sh
readonly TERMINAL_PROBE=/usr/local/sbin/control-plane-terminal-state-probe.sh
readonly SYSTEMCTL=/usr/local/bin/systemctl
readonly RESTORE_SERVICE=coolify-runtime-attestation-ssh-fence.service
readonly WATCHDOG_SERVICE=coolify-runtime-attestation-ssh-fence-watchdog.service
readonly SOURCE_ENV=/run/runtime-fence-boot-atomicity.env
readonly COMMAND_FIXTURE=/workspace/tests/Integration/ControlPlaneRuntimeFence/provisioner-boot-atomicity-command.sh

fail()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE_BOOT_ATOMICITY_UBUNTU_FAILURE %s\n' "$1" >&2
    exit 1
}

write_environment()
{
    {
        printf 'CONTROL_PLANE_RUNTIME_OPERATION_ID=runtime-fence-boot-atomicity\n'
        printf 'CONTROL_PLANE_RUNTIME_PROXY_CONTAINER=proxy\n'
        printf 'CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST=/run/runtime-fence-pool-plan\n'
        printf 'CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256=%s\n' \
            "$(sha256sum /run/runtime-fence-pool-plan | awk '{print $1}')"
        printf 'CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_METADATA=0:0:600:%s\n' \
            "$(wc -c < /run/runtime-fence-pool-plan | tr -d '[:space:]')"
        printf 'CONTROL_PLANE_RUNTIME_MANAGEMENT_ENDPOINTS=lo=127.0.0.1\n'
        printf 'CONTROL_PLANE_RUNTIME_ADDITIONAL_NETWORK_IDS=\n'
        printf 'CONTROL_PLANE_RUNTIME_SELF_SSH_TARGET=host.docker.internal\n'
        printf 'CONTROL_PLANE_RUNTIME_PROBE_MAX_AGE_SECONDS=30\n'
        printf 'CONTROL_PLANE_RUNTIME_QUEUE_STABLE_SECONDS=2\n'
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_CAPTURE_EXPECTATION=incumbent\n'
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_API_URL=http://127.0.0.1:8080/api/rawdata\n'
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_ROUTER=coolify@docker\n'
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_SERVICE=coolify@docker\n'
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT=8080\n'
        printf 'CONTROL_PLANE_RUNTIME_PROVIDER_HEADER_FILE=\n'
        printf 'CONTROL_PLANE_RUNTIME_TERMINAL_HTTPS_URL=https://coolify.example/api/health\n'
        printf 'CONTROL_PLANE_RUNTIME_TERMINAL_LOCAL_INGRESS_URL=http://127.0.0.1:8000/api/health\n'
    } > "$SOURCE_ENV"
    chmod 0600 "$SOURCE_ENV"
}

assert_phase()
{
    [[ $(< "$TEST_ROOT/phase") == "$1" ]] \
        || fail "unexpected fake-controller phase: expected=$1 actual=$(< "$TEST_ROOT/phase")"
}

write_phase()
{
    printf '%s\n' "$1" > "$TEST_ROOT/phase"
}

assert_armed()
{
    grep -F -x -q CONTROL_PLANE_RUNTIME_ARMED=1 "$RUNTIME_ENV" \
        || fail 'runtime.env was not atomically armed'
}

assert_unarmed()
{
    grep -F -x -q CONTROL_PLANE_RUNTIME_ARMED=0 "$RUNTIME_ENV" \
        || fail 'runtime.env unexpectedly armed'
}

assert_dependencies()
{
    [[ -L /etc/systemd/system/docker.service.requires/$RESTORE_SERVICE \
        && -L /etc/systemd/system/docker.socket.requires/$RESTORE_SERVICE \
        && -L /etc/systemd/system/docker.service.wants/$WATCHDOG_SERVICE ]] \
        || fail 'durable systemd Docker activation dependencies are absent'
}

assert_no_dependencies()
{
    [[ ! -e /etc/systemd/system/docker.service.requires/$RESTORE_SERVICE \
        && ! -e /etc/systemd/system/docker.socket.requires/$RESTORE_SERVICE \
        && ! -e /etc/systemd/system/docker.service.wants/$WATCHDOG_SERVICE ]] \
        || fail 'systemd Docker activation dependencies appeared before arming completed'
}

assert_live_fence()
{
    [[ -e $TEST_ROOT/nft-live && -e $TEST_ROOT/active-restore && -e $TEST_ROOT/active-watchdog ]] \
        || fail 'live fence and both runtime services are not active'
}

assert_no_live_fence()
{
    [[ ! -e $TEST_ROOT/nft-live && ! -e $TEST_ROOT/active-restore && ! -e $TEST_ROOT/active-watchdog ]] \
        || fail 'a live fence or service existed before durable arming'
}

expect_crash()
{
    local crash_point=$1 status
    shift
    set +e
    CONTROL_PLANE_RUNTIME_PROVISION_TEST_MODE=1 \
        CONTROL_PLANE_RUNTIME_PROVISION_TEST_CRASH_AT="$crash_point" "$@" \
        > "$TEST_ROOT/$crash_point.out" 2>&1
    status=$?
    set -e
    [[ $status -eq 86 ]] || {
        sed -n '1,160p' "$TEST_ROOT/$crash_point.out" >&2
        fail "expected crash point did not exit 86: $crash_point status=$status"
    }
}

run_provisioner()
{
    local action=$1
    if ! "$PROVISIONER" "$action" > "$TEST_ROOT/$action.out" 2>&1; then
        sed -n '1,160p' "$TEST_ROOT/$action.out" >&2
        fail "provisioner action failed: $action"
    fi
}

simulate_reboot()
{
    rm -f -- "$TEST_ROOT/nft-live" "$TEST_ROOT/watchdog-live" \
        "$TEST_ROOT/active-restore" "$TEST_ROOT/active-watchdog"
    "$SYSTEMCTL" start "$RESTORE_SERVICE" >/dev/null
    "$SYSTEMCTL" start "$WATCHDOG_SERVICE" >/dev/null
    assert_live_fence
}

assert_event_order()
{
    local staged systemd_sync restore_dependency_sync socket_dependency_sync watchdog_dependency_sync
    local restored watched captured
    staged=$(grep -n -m 1 '^stage-capture$' "$TEST_ROOT/events" | cut -d: -f1)
    systemd_sync=$(grep -n -m 1 '^sync:/etc/systemd/system$' "$TEST_ROOT/events" | cut -d: -f1)
    restore_dependency_sync=$(grep -n -m 1 \
        '^sync:/etc/systemd/system/docker.service.requires$' \
        "$TEST_ROOT/events" | cut -d: -f1)
    socket_dependency_sync=$(grep -n -m 1 \
        '^sync:/etc/systemd/system/docker.socket.requires$' \
        "$TEST_ROOT/events" | cut -d: -f1)
    watchdog_dependency_sync=$(grep -n -m 1 \
        '^sync:/etc/systemd/system/docker.service.wants$' \
        "$TEST_ROOT/events" | cut -d: -f1)
    restored=$(grep -n -m 1 '^restore$' "$TEST_ROOT/events" | cut -d: -f1)
    watched=$(grep -n -m 1 '^watch$' "$TEST_ROOT/events" | cut -d: -f1)
    captured=$(grep -n -m 1 '^capture$' "$TEST_ROOT/events" | cut -d: -f1)
    [[ $staged =~ ^[1-9][0-9]*$ && $systemd_sync =~ ^[1-9][0-9]*$ \
        && $restore_dependency_sync =~ ^[1-9][0-9]*$ \
        && $socket_dependency_sync =~ ^[1-9][0-9]*$ \
        && $watchdog_dependency_sync =~ ^[1-9][0-9]*$ \
        && $restored =~ ^[1-9][0-9]*$ \
        && $watched =~ ^[1-9][0-9]*$ && $captured =~ ^[1-9][0-9]*$ \
        && $staged -lt $systemd_sync && $staged -lt $restore_dependency_sync \
        && $staged -lt $socket_dependency_sync && $staged -lt $watchdog_dependency_sync \
        && $systemd_sync -lt $restored && $restore_dependency_sync -lt $restored \
        && $socket_dependency_sync -lt $restored && $watchdog_dependency_sync -lt $restored \
        && $restored -lt $watched && $watched -lt $captured ]] \
        || fail 'fence event ordering did not stage, sync every durable dependency, then restore, watchdog, and live capture'
    if grep -E -q '^systemctl:(start|restart) (docker\.service|docker\.socket)' "$TEST_ROOT/events"; then
        fail 'arming restarted or directly started Docker'
    fi
}

complete_arm()
{
    run_provisioner arm
    assert_phase active
    assert_armed
    assert_dependencies
    assert_live_fence
    assert_event_order
}

prepare_terminal_release()
{
    reset_fixture
    run_provisioner capture
    complete_arm
    write_phase released
}

assert_terminal_finalized()
{
    assert_unarmed
    assert_no_dependencies
    [[ ! -e $TEST_ROOT/active-restore && ! -e $TEST_ROOT/active-watchdog ]] \
        || fail 'terminal finalization left a restore or watchdog process active'
}

assert_terminal_crash_checkpoint()
{
    local crash_point=$1

    assert_unarmed
    case "$crash_point" in
        after-runtime-env-disarmed)
            assert_dependencies
            [[ -e $TEST_ROOT/active-restore && -e $TEST_ROOT/active-watchdog ]] \
                || fail 'disarm-first crash changed systemd process state before its seam'
            ;;
        after-watchdog-service-stop)
            assert_dependencies
            [[ -e $TEST_ROOT/active-restore && ! -e $TEST_ROOT/active-watchdog ]] \
                || fail 'watchdog-stop crash did not preserve the exact partial convergence state'
            ;;
        after-restore-service-stop)
            assert_dependencies
            [[ ! -e $TEST_ROOT/active-restore && ! -e $TEST_ROOT/active-watchdog ]] \
                || fail 'restore-stop crash did not preserve both services stopped'
            ;;
        after-watchdog-service-disable)
            [[ -L /etc/systemd/system/docker.service.requires/$RESTORE_SERVICE \
                && -L /etc/systemd/system/docker.socket.requires/$RESTORE_SERVICE \
                && ! -e /etc/systemd/system/docker.service.wants/$WATCHDOG_SERVICE ]] \
                || fail 'watchdog-disable crash did not preserve its exact dependency convergence'
            ;;
        after-restore-service-disable|after-terminal-systemd-reload)
            assert_no_dependencies
            ;;
        *) fail "unknown terminal finalization crash point: $crash_point" ;;
    esac
}

reset_fixture()
{
    rm -rf -- "$TEST_ROOT" "$CONFIG_DIRECTORY" \
        /etc/systemd/system/docker.service.requires \
        /etc/systemd/system/docker.socket.requires \
        /etc/systemd/system/docker.service.wants
    install -d -m 0700 "$CONFIG_DIRECTORY" "$TEST_ROOT"
    write_environment
    if ! "$PROVISIONER" prepare "$SOURCE_ENV" > "$TEST_ROOT/prepare.out" 2>&1; then
        sed -n '1,160p' "$TEST_ROOT/prepare.out" >&2
        fail 'provisioner prepare failed'
    fi
    assert_unarmed
    assert_no_dependencies
}

install_fixture()
{
    # shellcheck disable=SC1091
    [[ $(. /etc/os-release; printf '%s:%s' "$ID" "$VERSION_ID") == ubuntu:24.04 ]] \
        || fail 'boot-atomicity test did not run in the pinned Ubuntu 24.04 image'
    install -d -m 0700 "$CONFIG_DIRECTORY" /usr/local/sbin "$TEST_ROOT"
    install -d -m 0755 /usr/local/bin
    install -m 0700 "$COMMAND_FIXTURE" "$CONTROLLER"
    install -m 0700 "$COMMAND_FIXTURE" "$REAPER"
    install -m 0700 "$COMMAND_FIXTURE" "$PROVIDER_PROBE"
    install -m 0700 "$COMMAND_FIXTURE" "$QUEUE_PROBE"
    install -m 0700 "$COMMAND_FIXTURE" "$TERMINAL_PROBE"
    install -m 0700 /workspace/docker/control-plane-blue-green/controllers/provision-runtime-attestation-ssh-fence.sh \
        "$PROVISIONER"
    install -m 0700 "$COMMAND_FIXTURE" "$SYSTEMCTL"
    install -m 0700 "$COMMAND_FIXTURE" /usr/local/bin/sync
    printf '%s' fixture-pool-plan > /run/runtime-fence-pool-plan
    chmod 0600 /run/runtime-fence-pool-plan
}

export PATH=/usr/local/bin:/usr/local/sbin:/usr/sbin:/usr/bin:/sbin:/bin
install_fixture

reset_fixture
expect_crash after-capture-stage "$PROVISIONER" capture
assert_phase preparing
assert_unarmed
assert_no_dependencies
assert_no_live_fence
run_provisioner capture
complete_arm

reset_fixture
run_provisioner capture
expect_crash after-runtime-env-armed "$PROVISIONER" arm
assert_phase preparing
assert_armed
assert_no_dependencies
assert_no_live_fence
complete_arm

reset_fixture
run_provisioner capture
expect_crash after-systemd-enable "$PROVISIONER" arm
assert_phase preparing
assert_armed
assert_dependencies
assert_no_live_fence
simulate_reboot
complete_arm

reset_fixture
run_provisioner capture
expect_crash after-restore-service-start "$PROVISIONER" arm
assert_phase preparing
assert_armed
assert_dependencies
[[ -e $TEST_ROOT/nft-live && -e $TEST_ROOT/active-restore && ! -e $TEST_ROOT/active-watchdog ]] \
    || fail 'restore-start crash did not stop at the expected durable boundary'
simulate_reboot
complete_arm

reset_fixture
run_provisioner capture
expect_crash after-watchdog-service-start "$PROVISIONER" arm
assert_phase preparing
assert_armed
assert_dependencies
assert_live_fence
simulate_reboot
complete_arm

reset_fixture
run_provisioner capture
expect_crash after-controller-capture "$PROVISIONER" arm
assert_phase active
assert_armed
assert_dependencies
assert_live_fence
simulate_reboot
complete_arm
run_provisioner arm
assert_event_order

for terminal_crash_point in \
    after-runtime-env-disarmed \
    after-watchdog-service-stop \
    after-restore-service-stop \
    after-watchdog-service-disable \
    after-restore-service-disable \
    after-terminal-systemd-reload; do
    prepare_terminal_release
    expect_crash "$terminal_crash_point" "$PROVISIONER" finalize-release
    assert_terminal_crash_checkpoint "$terminal_crash_point"
    run_provisioner finalize-release
    assert_terminal_finalized
    run_provisioner finalize-release
    assert_terminal_finalized
done

printf 'CONTROL_PLANE_RUNTIME_FENCE_BOOT_ATOMICITY_UBUNTU PASS crash_points=12 reboot_boundaries=4 docker_restarts=0\n'
