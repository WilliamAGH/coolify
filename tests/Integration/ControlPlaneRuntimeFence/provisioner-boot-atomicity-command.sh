#!/usr/bin/env bash

set -Eeuo pipefail

readonly TEST_ROOT=/run/control-plane-runtime-fence-boot-atomicity
readonly CONFIG_DIRECTORY=/etc/coolify-runtime-attestation-ssh-fence
readonly RUNTIME_ENV=$CONFIG_DIRECTORY/runtime.env
readonly CONTROLLER=/usr/local/libexec/coolify-runtime-attestation-ssh-fence
readonly RESTORE_SERVICE=coolify-runtime-attestation-ssh-fence.service
readonly WATCHDOG_SERVICE=coolify-runtime-attestation-ssh-fence-watchdog.service

fail()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE_BOOT_ATOMICITY_FAILURE %s\n' "$1" >&2
    exit 1
}

event()
{
    mkdir -p "$TEST_ROOT"
    printf '%s\n' "$1" >> "$TEST_ROOT/events"
}

phase()
{
    local current
    if [[ -f $TEST_ROOT/phase ]]; then
        IFS= read -r current < "$TEST_ROOT/phase"
        printf '%s\n' "$current"
    else
        printf 'new\n'
    fi
}

write_phase()
{
    printf '%s\n' "$1" > "$TEST_ROOT/.phase.$$"
    mv -f -- "$TEST_ROOT/.phase.$$" "$TEST_ROOT/phase"
}

assert_armed()
{
    [[ ${CONTROL_PLANE_RUNTIME_ARMED:-} == 1 ]] \
        || fail 'fake controller was invoked without the durable armed environment'
}

assert_boot_dependencies()
{
    [[ -L /etc/systemd/system/docker.service.requires/$RESTORE_SERVICE \
        && -L /etc/systemd/system/docker.socket.requires/$RESTORE_SERVICE \
        && -L /etc/systemd/system/docker.service.wants/$WATCHDOG_SERVICE ]] \
        || fail 'fake controller started without all durable Docker activation dependencies'
}

controller()
{
    local action=${1:-}

    mkdir -p "$TEST_ROOT"
    case "$action" in
        stage-capture)
            case "$(phase)" in
                new)
                    [[ ! -e $TEST_ROOT/nft-live ]] \
                        || fail 'staged capture created an nft fence before arming'
                    write_phase preparing
                    ;;
                preparing|active) ;;
                *) fail "stage capture received an invalid phase: $(phase)" ;;
            esac
            event stage-capture
            ;;
        status)
            [[ -f $TEST_ROOT/phase ]] || fail 'status was requested before durable staging'
            printf 'CONTROL_PLANE_RUNTIME_FENCE status=passed operation_id=%s phase=%s\n' \
                "${CONTROL_PLANE_RUNTIME_OPERATION_ID:-missing}" "$(phase)"
            ;;
        restore)
            assert_armed
            assert_boot_dependencies
            case "$(phase)" in
                preparing|active) ;;
                *) fail "restore received an invalid phase: $(phase)" ;;
            esac
            touch "$TEST_ROOT/nft-live"
            event restore
            ;;
        watch)
            assert_armed
            assert_boot_dependencies
            [[ -e $TEST_ROOT/nft-live ]] || fail 'watchdog started before the live nft fence'
            touch "$TEST_ROOT/watchdog-live"
            event watch
            ;;
        capture)
            assert_armed
            [[ $(phase) == preparing ]] || fail 'live capture was not preceded by staged state'
            [[ -e $TEST_ROOT/nft-live ]] || fail 'live capture was not preceded by restore'
            write_phase active
            event capture
            ;;
        verify)
            assert_armed
            assert_boot_dependencies
            [[ $(phase) == active && -e $TEST_ROOT/nft-live ]] \
                || fail 'verify did not observe an active live fence'
            event verify
            ;;
        *) fail "unsupported fake controller action: $action" ;;
    esac
}

load_runtime_environment()
{
    [[ -f $RUNTIME_ENV ]] || fail 'fake systemd could not read runtime.env'
    set -a
    # shellcheck disable=SC1090
    . "$RUNTIME_ENV"
    set +a
}

remove_service_dependencies()
{
    case "$1" in
        "$RESTORE_SERVICE")
            rm -f -- /etc/systemd/system/docker.service.requires/$RESTORE_SERVICE \
                /etc/systemd/system/docker.socket.requires/$RESTORE_SERVICE
            ;;
        "$WATCHDOG_SERVICE")
            rm -f -- /etc/systemd/system/docker.service.wants/$WATCHDOG_SERVICE
            ;;
        *) fail "unsupported fake systemd dependency service: $1" ;;
    esac
}

enable_dependencies()
{
    install -d -m 0755 \
        /etc/systemd/system/docker.service.requires \
        /etc/systemd/system/docker.socket.requires \
        /etc/systemd/system/docker.service.wants
    ln -sfn /etc/systemd/system/$RESTORE_SERVICE \
        /etc/systemd/system/docker.service.requires/$RESTORE_SERVICE
    ln -sfn /etc/systemd/system/$RESTORE_SERVICE \
        /etc/systemd/system/docker.socket.requires/$RESTORE_SERVICE
    ln -sfn /etc/systemd/system/$WATCHDOG_SERVICE \
        /etc/systemd/system/docker.service.wants/$WATCHDOG_SERVICE
}

assert_enabled()
{
    assert_boot_dependencies
}

is_service_enabled()
{
    case "$1" in
        "$RESTORE_SERVICE")
            [[ -L /etc/systemd/system/docker.service.requires/$RESTORE_SERVICE \
                && -L /etc/systemd/system/docker.socket.requires/$RESTORE_SERVICE ]]
            ;;
        "$WATCHDOG_SERVICE")
            [[ -L /etc/systemd/system/docker.service.wants/$WATCHDOG_SERVICE ]]
            ;;
        *) fail "unsupported fake systemd enabled service: $1" ;;
    esac
}

stop_service()
{
    case "$1" in
        "$RESTORE_SERVICE") rm -f -- "$TEST_ROOT/active-restore" ;;
        "$WATCHDOG_SERVICE") rm -f -- "$TEST_ROOT/active-watchdog" ;;
        *) fail "unsupported fake systemd stop service: $1" ;;
    esac
}

start_service()
{
    local service=$1

    load_runtime_environment
    case "$service" in
        "$RESTORE_SERVICE")
            "$CONTROLLER" restore
            touch "$TEST_ROOT/active-restore"
            ;;
        "$WATCHDOG_SERVICE")
            "$CONTROLLER" watch
            touch "$TEST_ROOT/active-watchdog"
            ;;
        docker.service|docker.socket)
            fail 'provisioner attempted to start Docker while arming the fence'
            ;;
        *) fail "unsupported fake systemd start service: $service" ;;
    esac
}

systemctl_command()
{
    local action=${1:-}
    shift || true
    event "systemctl:$action $*"

    case "$action" in
        daemon-reload) ;;
        enable)
            enable_dependencies
            ;;
        disable)
            for service in "$@"; do
                remove_service_dependencies "$service"
            done
            ;;
        stop)
            for service in "$@"; do
                stop_service "$service"
            done
            ;;
        is-enabled)
            [[ ${1:-} == --quiet ]] && shift
            for service in "$@"; do
                is_service_enabled "$service" || return 1
            done
            ;;
        start)
            [[ $# -eq 1 ]] || fail 'fake systemd start requires exactly one service'
            start_service "$1"
            ;;
        is-active)
            [[ ${1:-} == --quiet ]] && shift
            for service in "$@"; do
                case "$service" in
                    "$RESTORE_SERVICE") [[ -e $TEST_ROOT/active-restore ]] || return 1 ;;
                    "$WATCHDOG_SERVICE") [[ -e $TEST_ROOT/active-watchdog ]] || return 1 ;;
                    *) fail "unsupported fake systemd active service: $service" ;;
                esac
            done
            ;;
        *) fail "unsupported fake systemctl action: $action" ;;
    esac
}

sync_command()
{
    local target
    event "sync:$*"
    for target in "$@"; do
        [[ -e $target ]] || fail "fake sync received an absent path: $target"
    done
}

terminal_probe()
{
    [[ ${1:-} == validate-pool-plan ]] || fail 'fake terminal probe requires plan validation'
    printf 'version=2\npool_plan=validated\nrelease_files_sha256=%064d\n' 0
}

case "${0##*/}" in
    systemctl) systemctl_command "$@" ;;
    sync) sync_command "$@" ;;
    coolify-runtime-attestation-ssh-fence) controller "$@" ;;
    coolify-control-plane-terminal-state-probe) terminal_probe "$@" ;;
    *) fail "unsupported fake command identity: ${0##*/}" ;;
esac
