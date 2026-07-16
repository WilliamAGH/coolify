#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

readonly TEST_DIRECTORY=/workspace/tests
readonly CONTROLLER=/workspace/docker/control-plane-blue-green/controllers/haproxy-port8000.sh
readonly BACKEND="$TEST_DIRECTORY/backend.py"
readonly TRAFFIC="$TEST_DIRECTORY/traffic.py"
readonly LAB_ROOT=/root/control-plane-port8000
readonly OPERATION_DIRECTORY="$LAB_ROOT/operation"
readonly CONFIG_DIRECTORY="$LAB_ROOT/config"
readonly RUNTIME_DIRECTORY="$LAB_ROOT/run"
readonly HAPROXY_STATE_DIRECTORY="$LAB_ROOT/state"
readonly WAN_NAMESPACE=cp-port8000-wan
readonly WAN_HOST_INTERFACE=cp8000-host
readonly WAN_PEER_INTERFACE=cp8000-wan
readonly PUBLIC_IPV4=198.18.80.1
readonly CANARY_IPV4=198.18.80.2
readonly NON_CANARY_IPV4=198.18.80.3
readonly PUBLIC_IPV6=2001:db8:8000::1
readonly CANARY_IPV6=2001:db8:8000::2
readonly HOST_HEADER=coolify.test:8000
readonly PUBLIC_URL=http://127.0.0.1:8000
readonly GREEN_ACKNOWLEDGEMENT=port8000-lab-green-ack-20260713
readonly BLUE_ACKNOWLEDGEMENT=port8000-lab-blue-ack-20260713
readonly OPERATION_ID="port8000-${LAB_CELL//[^A-Za-z0-9_.-]/-}"
readonly EXTERNAL_BLOCKED_ENDPOINT=203.0.113.80:8000
readonly EXTERNAL_POLICY_PROBE="$LAB_ROOT/external-policy-probe"
readonly IPV6_INVENTORY="$LAB_ROOT/ipv6-inventory.evidence"
readonly IPV6_INVENTORY_PROBE="$LAB_ROOT/ipv6-inventory-probe"
readonly DOCKER_PID_FILE="$LAB_ROOT/dockerd.pid"
readonly DOCKER_LOG=/evidence/dockerd.log
readonly DOCKER_FAULT_DIRECTORY="$LAB_ROOT/docker-fault-bin"
readonly SYSTEMD_STOP_FIXTURE_DIRECTORY="$LAB_ROOT/systemd-stop-fixture"
readonly SYSTEMD_STOP_FIXTURE_BIN="$SYSTEMD_STOP_FIXTURE_DIRECTORY/bin"
readonly SYSTEMD_STOP_PROCESS_ROOT="$SYSTEMD_STOP_FIXTURE_DIRECTORY/proc"

fail()
{
    printf 'CONTROL_PLANE_PORT8000_LAB_FAILURE cell=%s %s\n' "$LAB_CELL" "$1" >&2
    exit 1
}

note()
{
    printf 'CONTROL_PLANE_PORT8000_LAB cell=%s %s\n' "$LAB_CELL" "$1" >&2
}

source_bundle_checksum()
{
    local file
    for file in "$@"; do
        printf '%s  %s\n' "$(sha256sum "$file" | awk '{print $1}')" "$(basename "$file")"
    done | LC_ALL=C sort | sha256sum | awk '{print $1}'
}

cleanup()
{
    set +e
    if ip netns list | grep -F "$WAN_NAMESPACE" >/dev/null; then
        ip -n "$WAN_NAMESPACE" -details -statistics address show > /evidence/wan-addresses-final.txt 2>&1
        ip -n "$WAN_NAMESPACE" -6 route show table all > /evidence/wan-ipv6-routes-final.txt 2>&1
    fi
    if [[ -S /var/run/docker.sock ]]; then
        docker rm -f legacy-port8000 >/dev/null 2>&1
    fi
    for table in cp_negative_same cp_negative_later coolify_cp_port8000 tailscale_sentinel; do
        nft delete table inet "$table" >/dev/null 2>&1
    done
    for instance in phase-a phase-b; do
        if [[ -f $RUNTIME_DIRECTORY/$instance.pid ]]; then
            kill -TERM "$(cat "$RUNTIME_DIRECTORY/$instance.pid")" >/dev/null 2>&1
        fi
    done
    if [[ -f $SYSTEMD_STOP_FIXTURE_DIRECTORY/main-pid ]]; then
        kill -KILL "$(cat "$SYSTEMD_STOP_FIXTURE_DIRECTORY/main-pid")" >/dev/null 2>&1
    fi
    [[ -z ${BACKEND_GREEN_PID:-} ]] || kill "$BACKEND_GREEN_PID" >/dev/null 2>&1
    [[ -z ${BACKEND_BLUE_PID:-} ]] || kill "$BACKEND_BLUE_PID" >/dev/null 2>&1
    [[ -z ${NEGATIVE_PID:-} ]] || kill "$NEGATIVE_PID" >/dev/null 2>&1
    [[ -z ${DUPLICATE_ACK_PID:-} ]] || kill "$DUPLICATE_ACK_PID" >/dev/null 2>&1
    [[ -z ${CONTINUOUS_PID:-} ]] || kill "$CONTINUOUS_PID" >/dev/null 2>&1
    [[ -z ${PROTOCOL_PID:-} ]] || kill "$PROTOCOL_PID" >/dev/null 2>&1
    ip netns delete "$WAN_NAMESPACE" >/dev/null 2>&1
    ip link delete "$WAN_HOST_INTERFACE" >/dev/null 2>&1
    if [[ -f $DOCKER_PID_FILE ]]; then
        kill -TERM "$(cat "$DOCKER_PID_FILE")" >/dev/null 2>&1
    fi
}
trap cleanup EXIT HUP INT TERM

wait_for_docker()
{
    local attempt=0
    until docker info >/dev/null 2>&1; do
        attempt=$((attempt + 1))
        ((attempt < 120)) || fail 'nested Docker daemon did not become ready'
        sleep 0.25
    done
    [[ $(docker version --format '{{.Server.Version}}') == "$LAB_DOCKER_VERSION" ]] \
        || fail 'nested Docker Server.Version differs from the exact matrix cell'
}

start_docker()
{
    local -a arguments
    arguments=(
        --host=unix:///var/run/docker.sock
        --data-root=/var/lib/docker
        --exec-root=/var/run/docker-exec
        --pidfile="$DOCKER_PID_FILE"
        --userland-proxy="$LAB_USERLAND_PROXY"
        --live-restore=true
        --ipv6=true
        --fixed-cidr-v6=fd00:8000::/80
        --log-level=warn
    )
    if [[ $LAB_FIREWALL_BACKEND == nftables ]]; then
        arguments+=(--firewall-backend=nftables)
    fi
    dockerd "${arguments[@]}" >> "$DOCKER_LOG" 2>&1 &
    wait_for_docker
}

restart_docker()
{
    local old_pid
    old_pid=$(cat "$DOCKER_PID_FILE")
    kill -TERM "$old_pid"
    for _ in {1..120}; do
        kill -0 "$old_pid" 2>/dev/null || break
        sleep 0.25
    done
    kill -0 "$old_pid" 2>/dev/null && fail 'nested dockerd did not stop for restart recovery'
    start_docker
}

controller()
{
    local action=$1 external_policy_probe_sha256
    shift
    external_policy_probe_sha256=$(sha256sum "$EXTERNAL_POLICY_PROBE" | awk '{print $1}')
    env \
        CONTROL_PLANE_INGRESS_OPERATION_ID="$OPERATION_ID" \
        CONTROL_PLANE_INGRESS_OPERATION_DIR="$OPERATION_DIRECTORY" \
        CONTROL_PLANE_PORT8000_HOST_LOCAL_URL="$PUBLIC_URL/api/health" \
        CONTROL_PLANE_INGRESS_PUBLIC_HOST_HEADER="$HOST_HEADER" \
        CONTROL_PLANE_INGRESS_PROBE_ATTEMPTS=20 \
        CONTROL_PLANE_INGRESS_TEST_MODE=1 \
        CONTROL_PLANE_INGRESS_TARGET=lab \
        CONTROL_PLANE_PORT8000_CANARY_TARGET_IPV4="$PUBLIC_IPV4" \
        CONTROL_PLANE_PORT8000_CANARY_TARGET_IPV6="$PUBLIC_IPV6" \
        CONTROL_PLANE_INGRESS_BOOTSTRAP_PORT=18000 \
        CONTROL_PLANE_INGRESS_COLOR=green \
        CONTROL_PLANE_INGRESS_BACKEND=127.0.0.1 \
        CONTROL_PLANE_INGRESS_BACKEND_PORT=18081 \
        CONTROL_PLANE_INGRESS_ACK_FILE="$LAB_ROOT/green.ack" \
        CONTROL_PLANE_INGRESS_DIRECT_PROBE_TOKEN_FILE="$LAB_ROOT/green.probe-token" \
        CONTROL_PLANE_INGRESS_DIRECT_PROBE_PATH=/api/control-plane/probe \
        CONTROL_PLANE_PORT8000_SERVICE_MODE=direct \
        CONTROL_PLANE_PORT8000_CONFIG_DIR="$CONFIG_DIRECTORY" \
        CONTROL_PLANE_PORT8000_RUNTIME_DIR="$RUNTIME_DIRECTORY" \
        CONTROL_PLANE_PORT8000_HAPROXY_STATE_DIR="$HAPROXY_STATE_DIRECTORY" \
        CONTROL_PLANE_PORT8000_NFT_TABLE=coolify_cp_port8000 \
        CONTROL_PLANE_PORT8000_NFT_PRIORITY=-110 \
        CONTROL_PLANE_PORT8000_CANARY_NETNS="$WAN_NAMESPACE" \
        CONTROL_PLANE_PORT8000_CANARY_IPV4="$CANARY_IPV4" \
        CONTROL_PLANE_PORT8000_CANARY_IPV6="$CANARY_IPV6" \
        CONTROL_PLANE_PORT8000_EXTERNAL_POLICY_PROBE="$EXTERNAL_POLICY_PROBE" \
        CONTROL_PLANE_PORT8000_EXTERNAL_POLICY_PROBE_SHA256="$external_policy_probe_sha256" \
        CONTROL_PLANE_PORT8000_EXTERNAL_BLOCKED_ENDPOINT="$EXTERNAL_BLOCKED_ENDPOINT" \
        CONTROL_PLANE_PORT8000_IPV6_INVENTORY_FILE="$IPV6_INVENTORY" \
        CONTROL_PLANE_PORT8000_IPV6_INVENTORY_SHA256="$(sha256sum "$IPV6_INVENTORY" | awk '{print $1}')" \
        CONTROL_PLANE_PORT8000_IPV6_INVENTORY_PROBE="$IPV6_INVENTORY_PROBE" \
        CONTROL_PLANE_PORT8000_IPV6_INVENTORY_PROBE_SHA256="$(sha256sum "$IPV6_INVENTORY_PROBE" | awk '{print $1}')" \
        CONTROL_PLANE_PORT8000_DRAIN_ATTEMPTS="${CONTROL_PLANE_PORT8000_DRAIN_ATTEMPTS:-1}" \
        CONTROL_PLANE_PORT8000_DRAIN_STABLE_SAMPLES=2 \
        CONTROL_PLANE_PORT8000_EXPECTED_DOCKER_VERSION="$LAB_DOCKER_VERSION" \
        CONTROL_PLANE_PORT8000_EXPECTED_FIREWALL_BACKEND="$LAB_FIREWALL_BACKEND" \
        CONTROL_PLANE_PORT8000_EXPECT_USERLAND_PROXY="$LAB_USERLAND_PROXY" \
        "$@" "$CONTROLLER" "$action"
}

systemd_controller()
{
    local action=$1
    shift
    controller "$action" \
        CONTROL_PLANE_PORT8000_SERVICE_MODE=systemd \
        CONTROL_PLANE_PORT8000_TEST_PROCESS_ROOT="$SYSTEMD_STOP_PROCESS_ROOT" \
        CONTROL_PLANE_TEST_PORT8000_SYSTEMD_FIXTURE_DIRECTORY="$SYSTEMD_STOP_FIXTURE_DIRECTORY" \
        "PATH=$SYSTEMD_STOP_FIXTURE_BIN:$PATH" \
        "$@"
}

install_systemd_stop_fixture()
{
    local phase_a_pid
    rm -rf -- "$SYSTEMD_STOP_FIXTURE_DIRECTORY"
    install -d -m 700 \
        "$SYSTEMD_STOP_FIXTURE_BIN" \
        "$SYSTEMD_STOP_PROCESS_ROOT"
    phase_a_pid=$(< "$RUNTIME_DIRECTORY/phase-a.pid")
    [[ $phase_a_pid =~ ^[1-9][0-9]*$ && -r /proc/$phase_a_pid/stat ]] \
        || fail 'phase-A HAProxy process was unavailable for systemd stop attestation fixture'
    install -d -m 700 "$SYSTEMD_STOP_PROCESS_ROOT/$phase_a_pid"
    cp "/proc/$phase_a_pid/stat" "$SYSTEMD_STOP_PROCESS_ROOT/$phase_a_pid/stat"
    chmod 600 "$SYSTEMD_STOP_PROCESS_ROOT/$phase_a_pid/stat"
    cp "/proc/$phase_a_pid/stat" "$SYSTEMD_STOP_FIXTURE_DIRECTORY/original-process-stat"
    chmod 600 "$SYSTEMD_STOP_FIXTURE_DIRECTORY/original-process-stat"
    cp "$CONFIG_DIRECTORY/phase-a.cfg" "$SYSTEMD_STOP_FIXTURE_DIRECTORY/phase-a.cfg"
    chmod 600 "$SYSTEMD_STOP_FIXTURE_DIRECTORY/phase-a.cfg"
    printf '%s\n' "$phase_a_pid" > "$SYSTEMD_STOP_FIXTURE_DIRECTORY/main-pid"
    touch "$SYSTEMD_STOP_FIXTURE_DIRECTORY/active"
    install -m 700 /dev/stdin "$SYSTEMD_STOP_FIXTURE_BIN/systemctl" <<'SYSTEMCTL'
#!/usr/bin/env bash

set -Eeuo pipefail

fixture=${CONTROL_PLANE_TEST_PORT8000_SYSTEMD_FIXTURE_DIRECTORY:?}
readonly fixture
readonly phase_a_unit='coolify-port8000-haproxy@phase-a.service'
readonly phase_b_unit='coolify-port8000-haproxy@phase-b.service'
readonly pid_file="$fixture/main-pid"
readonly process_root="$fixture/proc"

fail()
{
    printf 'CONTROL_PLANE_PORT8000_SYSTEMD_FIXTURE_FAILURE %s\n' "$1" >&2
    exit 1
}

event()
{
    printf '%s\n' "$1" >> "$fixture/events"
}

stop_phase_a_process()
{
    local pid
    pid=$(< "$pid_file")
    [[ -r /proc/$pid/stat ]] || return 0
    kill -TERM "$pid" 2>/dev/null || true
    for _ in {1..50}; do
        [[ -r /proc/$pid/stat ]] || return 0
        sleep 0.1
    done
    kill -KILL "$pid" 2>/dev/null || true
}

write_reused_process_stat()
{
    local pid start_time=987654321
    pid=$(< "$pid_file")
    install -d -m 700 "$process_root/$pid"
    {
        printf '%s (haproxy) S' "$pid"
        for _ in {1..18}; do
            printf ' 0'
        done
        printf ' %s\n' "$start_time"
    } > "$process_root/$pid/stat"
}

case "${1:-}" in
    is-active)
        shift
        [[ ${1:-} == --quiet ]] && shift
        [[ $# -eq 1 ]] || fail 'unexpected is-active invocation'
        case "$1" in
            "$phase_a_unit") [[ -e $fixture/active ]] ;;
            "$phase_b_unit") ;;
            *) fail 'unexpected is-active unit' ;;
        esac
        ;;
    show)
        shift
        property=
        unit=
        for argument in "$@"; do
            case "$argument" in
                --property=*) property=${argument#--property=} ;;
                --value) ;;
                "$phase_a_unit"|"$phase_b_unit") unit=$argument ;;
                *) fail 'unexpected show invocation' ;;
            esac
        done
        [[ -n $unit ]] || fail 'show invocation omitted unit'
        case "$property" in
            MainPID)
                [[ $unit == "$phase_a_unit" ]] || fail 'unexpected MainPID unit'
                event show-main-pid
                if [[ -e $fixture/active ]]; then
                    cat "$pid_file"
                else
                    printf '0\n'
                fi
                ;;
            ActiveState)
                if [[ $unit == "$phase_a_unit" ]]; then
                    activity_mode=${CONTROL_PLANE_TEST_PORT8000_SYSTEMD_ACTIVITY_MODE:-normal}
                    case "$activity_mode" in
                        failure)
                            event show-active-state-failed
                            exit 1
                            ;;
                        unknown)
                            event show-active-state-unknown
                            printf 'deactivating\n'
                            ;;
                        normal)
                            [[ -e $fixture/active ]] && printf 'active\n' || printf 'inactive\n'
                            ;;
                        *) fail 'unexpected activity mode' ;;
                    esac
                else
                    printf 'active\n'
                fi
                ;;
            *) fail 'unexpected show property' ;;
        esac
        ;;
    stop)
        [[ $# -eq 2 && $2 == "$phase_a_unit" ]] || fail 'unexpected stop invocation'
        stop_mode=${CONTROL_PLANE_TEST_PORT8000_SYSTEMD_STOP_MODE:-normal}
        case "$stop_mode" in
            fail)
                event stop-failed
                exit 1
                ;;
            linger)
                rm -f -- "$fixture/active"
                event stop-lingering
                ;;
            no-change)
                event stop-no-change
                ;;
            normal)
                stop_phase_a_process
                rm -rf -- "$fixture/active" "$process_root/$(< "$pid_file")"
                event stop-normal
                ;;
            pid-reuse)
                stop_phase_a_process
                rm -f -- "$fixture/active"
                write_reused_process_stat
                event stop-pid-reuse
                ;;
            *) fail 'unexpected stop mode' ;;
        esac
        ;;
    *) fail 'unexpected systemctl action' ;;
esac
SYSTEMCTL
}

restore_systemd_stop_fixture_process()
{
    local pid process_directory
    pid=$(< "$SYSTEMD_STOP_FIXTURE_DIRECTORY/main-pid")
    [[ $pid =~ ^[1-9][0-9]*$ ]] || fail 'systemd fixture phase-A process ID is malformed'
    process_directory="$SYSTEMD_STOP_PROCESS_ROOT/$pid"
    rm -rf -- "${process_directory:?}"
    install -d -m 700 "$process_directory"
    cp "$SYSTEMD_STOP_FIXTURE_DIRECTORY/original-process-stat" "$process_directory/stat"
    chmod 600 "$process_directory/stat"
    touch "$SYSTEMD_STOP_FIXTURE_DIRECTORY/active"
}

set_systemd_stop_fixture_process_stat()
{
    local mode=$1 pid stat_file
    pid=$(< "$SYSTEMD_STOP_FIXTURE_DIRECTORY/main-pid")
    stat_file="$SYSTEMD_STOP_PROCESS_ROOT/$pid/stat"
    case "$mode" in
        unreadable)
            rm -rf -- "$stat_file"
            install -d -m 700 "$stat_file"
            ;;
        malformed)
            printf 'malformed process stat\n' > "$stat_file"
            chmod 600 "$stat_file"
            ;;
        *) fail 'unexpected process-stat fixture mode' ;;
    esac
}

restore_phase_a_configuration_after_power_loss()
{
    cp "$SYSTEMD_STOP_FIXTURE_DIRECTORY/phase-a.cfg" "$CONFIG_DIRECTORY/phase-a.cfg"
    chmod 600 "$CONFIG_DIRECTORY/phase-a.cfg"
}

expect_systemd_controller_crash()
{
    local seam=$1 action=${2:-assert} stop_mode=${3:-normal} status
    set +e
    systemd_controller "$action" \
        CONTROL_PLANE_PORT8000_DRAIN_ATTEMPTS=30 \
        CONTROL_PLANE_TEST_PORT8000_SYSTEMD_STOP_MODE="$stop_mode" \
        CONTROL_PLANE_TEST_PORT8000_CRASH_AT="$seam" \
        > "/evidence/systemd-crash-${seam}.log" 2>&1
    status=$?
    set -e
    [[ $status -eq 137 ]] || fail "systemd controller crash seam $seam exited $status instead of 137"
}

expect_systemd_stop_failure()
{
    local label=$1 stop_mode=$2 activity_mode=$3 expected_message=$4 status
    set +e
    systemd_controller assert \
        CONTROL_PLANE_PORT8000_DRAIN_ATTEMPTS=30 \
        CONTROL_PLANE_TEST_PORT8000_SYSTEMD_STOP_MODE="$stop_mode" \
        CONTROL_PLANE_TEST_PORT8000_SYSTEMD_ACTIVITY_MODE="$activity_mode" \
        > "/evidence/systemd-${label}.log" 2>&1
    status=$?
    set -e
    [[ $status -ne 0 ]] || fail "systemd $label unexpectedly completed finalization"
    grep -F -q "$expected_message" "/evidence/systemd-${label}.log" \
        || fail "systemd $label did not report its expected refusal"
}

assert_systemd_finalization_identity()
{
    local pid start_time boot_id fixture_start_time
    pid=$(sed -n 's/^phase_a_main_pid=//p' "$OPERATION_DIRECTORY/state")
    start_time=$(sed -n 's/^phase_a_main_start_time=//p' "$OPERATION_DIRECTORY/state")
    boot_id=$(sed -n 's/^phase_a_main_boot_id=//p' "$OPERATION_DIRECTORY/state")
    fixture_start_time=$(awk '{print $22}' "$SYSTEMD_STOP_PROCESS_ROOT/$pid/stat")
    [[ $pid == "$(< "$SYSTEMD_STOP_FIXTURE_DIRECTORY/main-pid")" \
        && $start_time == "$fixture_start_time" \
        && $boot_id == "$(< /proc/sys/kernel/random/boot_id)" ]] \
        || fail 'durable phase-A systemd process identity did not match the pre-stop process'
}

assert_systemd_finalization_retained()
{
    [[ $(sed -n 's/^phase=//p' "$OPERATION_DIRECTORY/state") == phase-a-drain-intent \
        && -e $CONFIG_DIRECTORY/phase-a.cfg \
        && -S $RUNTIME_DIRECTORY/phase-a.sock ]] \
        || fail 'systemd finalization failure advanced phase-A cleanup'
    nft list table inet coolify_cp_port8000 >/dev/null 2>&1 \
        || fail 'systemd finalization failure removed the capture nftables table'
}

install_docker_fault_wrapper()
{
    mkdir -p "$DOCKER_FAULT_DIRECTORY"
    {
        printf '%s\n' '#!/usr/bin/env bash'
        printf '%s\n' 'set -euo pipefail'
        printf '%s\n' 'fault=${CONTROL_PLANE_PORT8000_DOCKER_FAULT:-none}'
        printf '%s\n' 'case "$fault:${1:-}" in'
        printf '%s\n' '    list-500:ps)'
        printf '%s\n' '        printf "%s\n" "Error response from daemon: fixture list failure (500)" >&2'
        printf '%s\n' '        exit 1'
        printf '%s\n' '        ;;'
        printf '%s\n' '    daemon-unavailable:ps)'
        printf '%s\n' '        printf "%s\n" "Cannot connect to the Docker daemon" >&2'
        printf '%s\n' '        exit 1'
        printf '%s\n' '        ;;'
        printf '%s\n' '    permission-denied:ps)'
        printf '%s\n' '        printf "%s\n" "permission denied opening Docker socket" >&2'
        printf '%s\n' '        exit 1'
        printf '%s\n' '        ;;'
        printf '%s\n' '    timeout:ps)'
        printf '%s\n' '        printf "%s\n" "Docker container list timed out" >&2'
        printf '%s\n' '        exit 124'
        printf '%s\n' '        ;;'
        printf '%s\n' '    list-malformed:ps)'
        printf '%s\n' '        printf "%s\n" "not-a-full-container-id"'
        printf '%s\n' '        exit 0'
        printf '%s\n' '        ;;'
        printf '%s\n' '    empty-success:ps)'
        printf '%s\n' '        exit 0'
        printf '%s\n' '        ;;'
        printf '%s\n' '    api-500:inspect)'
        printf '%s\n' '        printf "%s\n" "Error response from daemon: fixture inspect failure (500)" >&2'
        printf '%s\n' '        exit 1'
        printf '%s\n' '        ;;'
        printf '%s\n' '    inspect-malformed:inspect)'
        printf '%s\n' '        printf "%s\n" "[]"'
        printf '%s\n' '        exit 0'
        printf '%s\n' '        ;;'
        printf '%s\n' 'esac'
        printf '%s\n' 'exec "${CONTROL_PLANE_PORT8000_REAL_DOCKER:?}" "$@"'
    } > "$DOCKER_FAULT_DIRECTORY/docker"
    chmod 700 "$DOCKER_FAULT_DIRECTORY/docker"
}

assert_docker_inventory_failure_did_not_mutate()
{
    [[ ! -e $OPERATION_DIRECTORY/manifest \
        && ! -e $OPERATION_DIRECTORY/state \
        && ! -e $OPERATION_DIRECTORY/legacy-docker-owner.json \
        && ! -e $OPERATION_DIRECTORY/legacy-docker-owner-adopted.json ]] \
        || fail 'unavailable Docker inventory mutated durable operation state'
    [[ ! -e $CONFIG_DIRECTORY/phase-a.cfg \
        && ! -e $CONFIG_DIRECTORY/phase-b.cfg \
        && ! -e $CONFIG_DIRECTORY/active.nft ]] \
        || fail 'unavailable Docker inventory mutated HAProxy or boot nft configuration'
    ! nft list table inet coolify_cp_port8000 >/dev/null 2>&1 \
        || fail 'unavailable Docker inventory installed the capture nftables table'
}

assert_docker_inventory_tristate()
{
    local docker_command fault status
    docker_command=$(command -v docker)
    install_docker_fault_wrapper

    for fault in list-500 daemon-unavailable permission-denied timeout list-malformed \
        api-500 inspect-malformed; do
        set +e
        controller prepare \
            PATH="$DOCKER_FAULT_DIRECTORY:$PATH" \
            CONTROL_PLANE_PORT8000_REAL_DOCKER="$docker_command" \
            CONTROL_PLANE_PORT8000_DOCKER_FAULT="$fault" \
            > "/evidence/docker-inventory-${fault}.log" 2>&1
        status=$?
        set -e
        [[ $status -ne 0 ]] \
            || fail "controller accepted unavailable or malformed Docker inventory: $fault"
        case "$fault" in
            list-500|daemon-unavailable|permission-denied|timeout)
                grep -F -q 'Docker Engine container list is unavailable' \
                    "/evidence/docker-inventory-${fault}.log"
                ;;
            list-malformed)
                grep -F -q 'Docker Engine container list returned a malformed container ID' \
                    "/evidence/docker-inventory-${fault}.log"
                ;;
            api-500)
                grep -F -q 'Docker Engine container inspection is unavailable' \
                    "/evidence/docker-inventory-${fault}.log"
                ;;
            inspect-malformed)
                grep -F -q 'Docker Engine container inspection returned malformed or mismatched inventory' \
                    "/evidence/docker-inventory-${fault}.log"
                ;;
        esac || fail "controller emitted the wrong Docker inventory failure: $fault"
        assert_docker_inventory_failure_did_not_mutate
    done

    set +e
    controller prepare \
        PATH="$DOCKER_FAULT_DIRECTORY:$PATH" \
        CONTROL_PLANE_PORT8000_REAL_DOCKER="$docker_command" \
        CONTROL_PLANE_PORT8000_DOCKER_FAULT=empty-success \
        > /evidence/docker-inventory-empty-success.log 2>&1
    status=$?
    set -e
    [[ $status -ne 0 ]] || fail 'prepare accepted a genuine empty Docker container list'
    grep -F -q 'preflight requires exactly one Docker container publishing host port 8000' \
        /evidence/docker-inventory-empty-success.log \
        || fail 'successful empty Docker inventory was not classified as absent'
    assert_docker_inventory_failure_did_not_mutate
}

assert_captured_docker_inventory_failure_did_not_mutate()
{
    local docker_command fault nft_before nft_after phase_a_before state_before status
    docker_command=$(command -v docker)
    state_before=$(sha256sum "$OPERATION_DIRECTORY/state" | awk '{print $1}')
    phase_a_before=$(sha256sum "$CONFIG_DIRECTORY/phase-a.cfg" | awk '{print $1}')
    nft_before=$(nft -s -n list table inet coolify_cp_port8000 \
        | sha256sum | awk '{print $1}')

    for fault in list-500 daemon-unavailable permission-denied timeout list-malformed \
        api-500 inspect-malformed; do
        set +e
        controller assert \
            PATH="$DOCKER_FAULT_DIRECTORY:$PATH" \
            CONTROL_PLANE_PORT8000_REAL_DOCKER="$docker_command" \
            CONTROL_PLANE_PORT8000_DOCKER_FAULT="$fault" \
            > "/evidence/captured-docker-inventory-${fault}.log" 2>&1
        status=$?
        set -e
        [[ $status -ne 0 ]] \
            || fail "captured route accepted unavailable or malformed Docker inventory: $fault"
        [[ $(sha256sum "$OPERATION_DIRECTORY/state" | awk '{print $1}') == "$state_before" ]] \
            || fail "captured route mutated durable state on unavailable Docker inventory: $fault"
        [[ $(sha256sum "$CONFIG_DIRECTORY/phase-a.cfg" | awk '{print $1}') == "$phase_a_before" \
            && ! -e $CONFIG_DIRECTORY/phase-b.cfg ]] \
            || fail "captured route mutated HAProxy configuration on unavailable Docker inventory: $fault"
        nft_after=$(nft -s -n list table inet coolify_cp_port8000 \
            | sha256sum | awk '{print $1}')
        [[ $nft_after == "$nft_before" ]] \
            || fail "captured route mutated nftables on unavailable Docker inventory: $fault"
    done
}

assert_target_mode_binding_rejected()
{
    local status
    set +e
    controller preflight \
        CONTROL_PLANE_INGRESS_TEST_MODE=0 \
        CONTROL_PLANE_INGRESS_TARGET=lab \
        > /evidence/production-mode-lab-target-rejected.log 2>&1
    status=$?
    set -e
    [[ $status -ne 0 ]] || fail 'production mode accepted the lab controller target'
    grep -F -q 'must be exactly 0:production or 1:lab' \
        /evidence/production-mode-lab-target-rejected.log \
        || fail 'production-mode/lab-target rejection did not reach the target binding gate'
    [[ ! -e $OPERATION_DIRECTORY ]] \
        || fail 'rejected production-mode/lab-target controller invocation mutated operation state'
    ! nft list table inet coolify_cp_port8000 >/dev/null 2>&1 \
        || fail 'rejected production-mode/lab-target controller invocation installed nftables state'
    [[ ! -e $CONFIG_DIRECTORY/phase-a.cfg && ! -e $CONFIG_DIRECTORY/phase-b.cfg ]] \
        || fail 'rejected production-mode/lab-target controller invocation installed HAProxy state'
}

install_external_policy_probe()
{
    {
        printf '%s\n' '#!/bin/sh'
        printf '%s\n' 'set -eu'
        printf '%s\n' 'test "$#" -eq 4'
        printf '%s\n' 'test "$1" = --endpoint'
        printf 'case "$2" in %s|"[%s]:8000") ;; *) exit 1 ;; esac\n' \
            "$EXTERNAL_BLOCKED_ENDPOINT" "$PUBLIC_IPV6"
        printf '%s\n' 'test "$3" = --timeout-seconds'
        printf '%s\n' 'test "$4" = 5'
        printf '%s\n' 'printf "CONTROL_PLANE_PORT8000_EXTERNAL_POLICY result=blocked endpoint=%s\\n" "$2"'
    } > "$EXTERNAL_POLICY_PROBE"
    chmod 700 "$EXTERNAL_POLICY_PROBE"
}

install_ipv6_inventory_probe()
{
    {
        printf '%s\n' '#!/bin/sh'
        printf '%s\n' 'set -eu'
        printf '%s\n' 'test "$#" -eq 4'
        printf '%s\n' 'test "$1" = --inventory-file'
        printf '%s\n' 'inventory=$2'
        printf '%s\n' 'test "$3" = --timeout-seconds'
        printf '%s\n' 'test "$4" = 5'
        printf '%s\n' 'inventory_sha256=$(sha256sum "$inventory" | awk '\''{print $1}'\'')'
        printf '%s\n' 'address_count=$(sed -n -E '\''s/^(interface_global_ipv6|dns_aaaa|provider_endpoint|external_vantage)=//p'\'' "$inventory" | grep -v -x none | sort -u | wc -l | tr -d " ")'
        printf '%s\n' 'denial_count=$(sed -n '\''s/^external_denial=//p'\'' "$inventory" | grep -v -x none | wc -l | tr -d " ")'
        printf '%s\n' 'printf "CONTROL_PLANE_PORT8000_IPV6_INVENTORY result=pass inventory_sha256=%s address_count=%s denial_count=%s\n" "$inventory_sha256" "$address_count" "$denial_count"'
    } > "$IPV6_INVENTORY_PROBE"
    chmod 700 "$IPV6_INVENTORY_PROBE"
}

assert_input_and_family_negatives()
{
    local status unsafe_parent="$LAB_ROOT/unsafe-parent" real_parent="$LAB_ROOT/real-parent"
    set +e
    controller preflight CONTROL_PLANE_PORT8000_HOST_LOCAL_URL=http://localhost:8000/api/health \
        > /evidence/host-local-dns-rejected.log 2>&1
    status=$?
    set -e
    [[ $status -ne 0 && ! -e $OPERATION_DIRECTORY ]] \
        || fail 'host-local DNS authority was accepted or mutated operation state'

    mkdir -m 777 "$unsafe_parent"
    printf '%s' "$GREEN_ACKNOWLEDGEMENT" > "$unsafe_parent/green.ack"
    chmod 600 "$unsafe_parent/green.ack"
    set +e
    controller switch CONTROL_PLANE_INGRESS_ACK_FILE="$unsafe_parent/green.ack" \
        > /evidence/unsafe-artifact-parent-rejected.log 2>&1
    status=$?
    set -e
    [[ $status -ne 0 && ! -e $OPERATION_DIRECTORY ]] \
        || fail 'artifact under a writable parent was accepted or mutated operation state'

    mkdir -m 700 "$real_parent"
    ln -s "$real_parent" "$LAB_ROOT/symlink-parent"
    set +e
    controller restore CONTROL_PLANE_INGRESS_OPERATION_DIR="$LAB_ROOT/symlink-parent/operation" \
        > /evidence/symlink-operation-parent-rejected.log 2>&1
    status=$?
    set -e
    [[ $status -ne 0 && ! -e $real_parent/operation ]] \
        || fail 'symlink operation parent was accepted or mutated its target'

    set +e
    controller external-blocked-status \
        CONTROL_PLANE_PORT8000_IPV6_INVENTORY_SHA256="$(printf '0%.0s' {1..64})" \
        > /evidence/ipv6-inventory-hash-rejected.log 2>&1
    status=$?
    set -e
    [[ $status -ne 0 ]] || fail 'unreviewed IPv6 inventory checksum was accepted'
}

assert_external_policy_checksum_refused()
{
    local status
    set +e
    controller external-blocked-status \
        CONTROL_PLANE_PORT8000_EXTERNAL_POLICY_PROBE_SHA256="$(printf '0%.0s' {1..64})" \
        > /evidence/external-policy-tampered-checksum.log 2>&1
    status=$?
    set -e
    [[ $status -ne 0 ]] || fail 'external blocked status accepted an unreviewed probe checksum'
}

write_boot_bundle()
{
    local directory=$1 mode=$2 table=$3 payload
    payload="$directory/.payload"
    {
        printf '# coolify-port8000-mode=%s\n' "$mode"
        printf '%s\n' '# coolify-port8000-operation=boot-helper-test'
        printf '# coolify-port8000-table=%s\n' "$table"
        if [[ $mode != absent ]]; then
            printf 'table inet %s {\n' "$table"
            printf '    comment "coolify-port8000 operation=boot-helper-test mode=%s"\n' "$mode"
            printf '%s\n' '}'
        fi
    } > "$payload"
    {
        printf '%s\n' '# coolify-port8000-active-bundle-format=1'
        printf '# payload-sha256=%s\n' "$(sha256sum "$payload" | awk '{print $1}')"
        cat "$payload"
    } > "$directory/active.nft"
    chmod 600 "$directory/active.nft"
    rm -f -- "$payload"
}

assert_atomic_boot_bundle_helper()
{
    local directory="$LAB_ROOT/boot-helper" table=cp_port8000_boot_helper status
    mkdir -m 700 "$directory"
    write_boot_bundle "$directory" capture "$table"
    printf '%s\n' '# interrupted candidate' > "$directory/.active.nft.interrupted"
    env CONTROL_PLANE_PORT8000_CONFIG_DIR="$directory" \
        /workspace/docker/control-plane-blue-green/port8000/apply-active-nft.sh \
        > /evidence/boot-helper-valid.log 2>&1
    nft -s list table inet "$table" | grep -F 'operation=boot-helper-test mode=capture' >/dev/null \
        || fail 'boot helper did not apply the committed single-file bundle'

    printf '%s\n' '# torn committed object' > "$directory/active.nft"
    chmod 600 "$directory/active.nft"
    set +e
    env CONTROL_PLANE_PORT8000_CONFIG_DIR="$directory" \
        /workspace/docker/control-plane-blue-green/port8000/apply-active-nft.sh \
        > /evidence/boot-helper-torn-rejected.log 2>&1
    status=$?
    set -e
    [[ $status -ne 0 ]] || fail 'boot helper accepted a torn active bundle'
    nft list table inet "$table" >/dev/null 2>&1 \
        || fail 'rejected torn publication altered the last valid live table'

    write_boot_bundle "$directory" absent "$table"
    env CONTROL_PLANE_PORT8000_CONFIG_DIR="$directory" \
        /workspace/docker/control-plane-blue-green/port8000/apply-active-nft.sh \
        > /evidence/boot-helper-absent.log 2>&1
    ! nft list table inet "$table" >/dev/null 2>&1 \
        || fail 'absent bundle did not remove only its owned table'
}

expect_controller_crash()
{
    local seam=$1 action=${2:-switch} status
    set +e
    controller "$action" CONTROL_PLANE_TEST_PORT8000_CRASH_AT="$seam" \
        > "/evidence/crash-${seam}.log" 2>&1
    status=$?
    set -e
    [[ $status -eq 137 ]] || fail "controller crash seam $seam exited $status instead of 137"
}

route_owner()
{
    curl --fail --silent --show-error --max-time 5 -D - -o /dev/null \
        -H "Host: $HOST_HEADER" "$PUBLIC_URL/api/health" \
        | sed -n 's/^X-Control-Plane-Port-Owner:[[:space:]]*//Ip' \
        | tr -d '\r'
}

assert_owner()
{
    local expected=$1 actual
    actual=$(route_owner)
    [[ $actual == "$expected" ]] || fail "expected route owner $expected, observed '$actual'"
}

backend_color()
{
    curl --fail --silent --show-error --max-time 5 -D - -o /dev/null \
        -H "Host: $HOST_HEADER" "$PUBLIC_URL/api/health" \
        | sed -n 's/^X-Backend-Color:[[:space:]]*//Ip' | tr -d '\r'
}

assert_legacy()
{
    [[ $(curl --fail --silent --show-error --max-time 5 "$PUBLIC_URL/api/health") == legacy* ]] \
        || fail 'request did not reach legacy Docker endpoint'
}

assert_header_overwrite()
{
    local headers=/evidence/header-overwrite.headers
    curl --fail --silent --show-error --max-time 5 -D "$headers" -o /dev/null \
        -H "Host: $HOST_HEADER" \
        -H 'Forwarded: for=forged;proto=https;host=forged.invalid' \
        -H 'X-Forwarded-For: forged-client' \
        -H 'X-Forwarded-Proto: forged-proto' \
        -H 'X-Forwarded-Host: forged.invalid' \
        -H 'X-Real-IP: forged-client' \
        "$PUBLIC_URL/api/health"
    grep -F -i -q "X-Observed-Host: $HOST_HEADER" "$headers" || fail 'Host including :8000 was not preserved'
    grep -F -i -q 'X-Observed-Forwarded-Proto: http' "$headers" || fail 'X-Forwarded-Proto was not overwritten'
    grep -F -i -q "X-Observed-Forwarded-Host: $HOST_HEADER" "$headers" || fail 'X-Forwarded-Host was not overwritten'
    grep -F -i -q 'X-Observed-Forwarded: absent' "$headers" || fail 'Forwarded was not removed'
    ! grep -E -i -q 'forged-client|forged-proto|forged.invalid' "$headers" \
        || fail 'a spoofable forwarding header reached the backend'
}

install_negative_priority_control()
{
    local priority=$1 table=$2
    nft -f - <<RULES
table inet $table {
    chain prerouting {
        type nat hook prerouting priority $priority; policy accept;
        meta nfproto ipv4 fib daddr type local tcp dport 8000 redirect to :18000
        meta nfproto ipv6 fib daddr type local tcp dport 8000 redirect to :18000
    }
    chain output {
        type nat hook output priority $priority; policy accept;
        meta nfproto ipv4 fib daddr type local tcp dport 8000 redirect to :18000
        meta nfproto ipv6 fib daddr type local tcp dport 8000 redirect to :18000
    }
}
RULES
}

assert_negative_priority_control()
{
    local priority=$1 table=$2 response
    response=$(ip netns exec "$WAN_NAMESPACE" curl --fail --silent --show-error --max-time 5 \
        "http://${PUBLIC_IPV4}:8000/api/health")
    [[ $response == legacy* ]] || fail "priority $priority unexpectedly outran Docker destination NAT"
    nft delete table inet "$table"
}

assert_controller_priority_rejected()
{
    local priority=$1 status
    set +e
    controller preflight CONTROL_PLANE_PORT8000_NFT_PRIORITY="$priority" \
        > "/evidence/priority-${priority}-rejected.log" 2>&1
    status=$?
    set -e
    [[ $status -ne 0 ]] || fail "controller accepted unsafe nftables priority $priority"
}

assert_direct_bootstrap_denied()
{
    ! curl --fail --silent --show-error --max-time 2 http://127.0.0.1:18000/api/health >/dev/null 2>&1 \
        || fail 'host OUTPUT reached bootstrap port directly'
    ! ip netns exec "$WAN_NAMESPACE" curl --fail --silent --show-error --max-time 2 \
        "http://${PUBLIC_IPV4}:18000/api/health" >/dev/null 2>&1 \
        || fail 'WAN PREROUTING reached bootstrap port directly'
}

assert_docker_bridge_client()
{
    docker run --rm --add-host host.docker.internal:host-gateway --entrypoint sh "$LAB_DIND_IMAGE" \
        -ec 'wget -q -O- http://host.docker.internal:8000/api/health' \
        | grep -F 'ok:green' >/dev/null || fail 'Docker bridge client did not traverse phase A'
}

install_lab_dependencies()
{
    local attempt=0
    until apk add --no-cache bash conntrack-tools curl haproxy ipcalc iproute2 iptables ip6tables \
        jq nftables python3 socat util-linux > /evidence/apk.log 2>&1; do
        attempt=$((attempt + 1))
        ((attempt < 4)) || fail 'Alpine dependency installation failed after bounded retries'
        sleep 2
    done
}

assert_legacy_restore_available()
{
    local label=$1 before after
    before=$(operation_fingerprint)
    controller legacy-restore-status > "/evidence/legacy-restore-available-${label}.log"
    after=$(operation_fingerprint)
    [[ $before == "$after" ]] \
        || fail "legacy restore status mutated durable operation state: $label"
}

operation_fingerprint()
{
    (
        cd "$OPERATION_DIRECTORY"
        while IFS= read -r path; do
            stat -c '%n|%F|%a|%u|%g|%s|%Y|%Z' "$path"
            [[ ! -f $path ]] || sha256sum "$path"
        done < <(find . -mindepth 1 ! -path './controller.lock' | LC_ALL=C sort)
    ) | sha256sum | awk '{print $1}'
}

assert_legacy_restore_refused()
{
    local label=$1 status
    set +e
    controller legacy-restore-status > "/evidence/legacy-restore-refused-${label}.log" 2>&1
    status=$?
    set -e
    [[ $status -ne 0 ]] || fail "legacy restore status remained available after boundary: $label"
}

assert_tampered_restore_state_refused()
{
    local tampered_directory="$LAB_ROOT/tampered-operation" status
    cp -a "$OPERATION_DIRECTORY" "$tampered_directory"
    printf 'phase=captured\n' >> "$tampered_directory/state"
    set +e
    controller legacy-restore-status \
        CONTROL_PLANE_INGRESS_OPERATION_DIR="$tampered_directory" \
        > /evidence/legacy-restore-tampered.log 2>&1
    status=$?
    set -e
    [[ $status -ne 0 ]] || fail 'tampered durable state was accepted by legacy restore status'
}

assert_backend_identity_negatives()
{
    local before after status
    before=$(route_state_fingerprint)
    set +e
    controller switch CONTROL_PLANE_INGRESS_BACKEND_PORT=18082 CONTROL_PLANE_INGRESS_PROBE_ATTEMPTS=1 \
        > /evidence/healthy-wrong-backend-rejected.log 2>&1
    status=$?
    set -e
    [[ $status -ne 0 ]] || fail 'healthy wrong backend was accepted without its secret identity'
    after=$(route_state_fingerprint)
    [[ $before == "$after" ]] || fail 'wrong-backend rejection mutated durable route state'

    set +e
    controller switch CONTROL_PLANE_INGRESS_BACKEND_PORT=18083 CONTROL_PLANE_INGRESS_PROBE_ATTEMPTS=1 \
        > /evidence/duplicate-backend-ack-rejected.log 2>&1
    status=$?
    set -e
    [[ $status -ne 0 ]] || fail 'backend with duplicate applied-config headers was accepted'
    after=$(route_state_fingerprint)
    [[ $before == "$after" ]] || fail 'duplicate-ACK rejection mutated durable route state'
}

assert_duplicate_phase_owner_rejected()
{
    local count status
    set +e
    controller switch CONTROL_PLANE_INGRESS_PROBE_ATTEMPTS=1 \
        CONTROL_PLANE_TEST_PORT8000_DUPLICATE_PHASE_OWNER=1 \
        > /evidence/duplicate-phase-owner-rejected.log 2>&1
    status=$?
    set -e
    [[ $status -ne 0 ]] || fail 'duplicate HAProxy phase-owner headers were accepted'
    count=$(curl --fail --silent --show-error --max-time 5 -D - -o /dev/null \
        -H "Host: $HOST_HEADER" "$PUBLIC_URL/api/health" \
        | grep -F -i -c 'X-Control-Plane-Port-Owner: bootstrap-a')
    [[ $count -eq 2 ]] || fail 'duplicate-owner negative did not produce two phase-owner headers'
    [[ $(sed -n 's/^phase=//p' "$OPERATION_DIRECTORY/state") == capture-installed ]] \
        || fail 'duplicate-owner rejection did not stop before public acknowledgement'
}

assert_retained_capture_rejected()
{
    local status
    set +e
    controller switch CONTROL_PLANE_INGRESS_PROBE_ATTEMPTS=1 \
        CONTROL_PLANE_TEST_PORT8000_RETAIN_CAPTURE_AFTER_REDIRECT=1 \
        > /evidence/retained-capture-after-redirect-rejected.log 2>&1
    status=$?
    set -e
    [[ $status -ne 0 ]] || fail 'phase-B acknowledgement accepted a retained capture redirect'
    [[ $(sed -n 's/^phase=//p' "$OPERATION_DIRECTORY/state") == captured ]] \
        || fail 'retained-capture rejection did not roll back to captured phase A'
    nft -s list table inet coolify_cp_port8000 | grep -F 'mode=capture' >/dev/null \
        || fail 'retained-capture rejection did not leave the proven phase-A rollback installed'
    assert_owner bootstrap-a
}

route_state_fingerprint()
{
    {
        sha256sum "$OPERATION_DIRECTORY/state"
        for config in "$CONFIG_DIRECTORY/phase-a.cfg" "$CONFIG_DIRECTORY/phase-b.cfg"; do
            [[ ! -f $config ]] || sha256sum "$config"
        done
        nft -s -n list table inet coolify_cp_port8000 2>/dev/null || true
    } | sha256sum | awk '{print $1}'
}

blue_controller()
{
    controller "$1" \
        CONTROL_PLANE_INGRESS_COLOR=blue \
        CONTROL_PLANE_INGRESS_BACKEND_PORT=18082 \
        CONTROL_PLANE_INGRESS_ACK_FILE="$LAB_ROOT/blue.ack" \
        CONTROL_PLANE_INGRESS_DIRECT_PROBE_TOKEN_FILE="$LAB_ROOT/blue.probe-token"
}

assert_permanent_green_to_blue_update()
{
    local green_identity blue_identity
    green_identity=$(sed -n 's/^# control-plane-backend-identity: //p' "$CONFIG_DIRECTORY/phase-b.cfg")
    [[ $green_identity =~ ^[a-f0-9]{20}$ ]] || fail 'green permanent backend identity is malformed'
    blue_controller switch > /evidence/permanent-green-to-blue-switch.log
    blue_controller ack > /evidence/permanent-green-to-blue-ack.log
    blue_identity=$(sed -n 's/^# control-plane-backend-identity: //p' "$CONFIG_DIRECTORY/phase-b.cfg")
    [[ $blue_identity =~ ^[a-f0-9]{20}$ && $blue_identity != "$green_identity" ]] \
        || fail 'green and blue reused one HAProxy server-state identity'
    [[ -f $HAPROXY_STATE_DIRECTORY/phase-b/$green_identity/server-state ]] \
        || fail 'green server state was not saved under its backend identity'
    grep -F -q "server-state-base $HAPROXY_STATE_DIRECTORY/phase-b/$blue_identity" \
        "$CONFIG_DIRECTORY/phase-b.cfg" || fail 'blue config did not isolate its server-state path'
    [[ $(route_owner) == permanent-b && $(backend_color) == blue ]] \
        || fail 'permanent green-to-blue update did not route through phase B to blue'
    [[ $(sed -n 's/^color=//p' "$OPERATION_DIRECTORY/state") == blue ]] \
        || fail 'durable route state did not commit the blue update'
    restart_docker
    blue_controller assert > /evidence/permanent-blue-post-dockerd-restart.log
    [[ $(route_owner) == permanent-b && $(backend_color) == blue ]] \
        || fail 'blue route did not survive Docker restart'
}

setup_network()
{
    ip netns add "$WAN_NAMESPACE"
    ip link add "$WAN_HOST_INTERFACE" type veth peer name "$WAN_PEER_INTERFACE"
    ip link set "$WAN_PEER_INTERFACE" netns "$WAN_NAMESPACE"
    ip address add "$PUBLIC_IPV4/24" dev "$WAN_HOST_INTERFACE"
    ip -6 address add "$PUBLIC_IPV6/64" dev "$WAN_HOST_INTERFACE" nodad
    ip link set "$WAN_HOST_INTERFACE" up
    ip -n "$WAN_NAMESPACE" link set lo up
    ip -n "$WAN_NAMESPACE" address add "$CANARY_IPV4/24" dev "$WAN_PEER_INTERFACE"
    ip -n "$WAN_NAMESPACE" address add "$NON_CANARY_IPV4/24" dev "$WAN_PEER_INTERFACE"
    ip -n "$WAN_NAMESPACE" -6 address add "$CANARY_IPV6/64" dev "$WAN_PEER_INTERFACE" nodad
    ip -n "$WAN_NAMESPACE" link set "$WAN_PEER_INTERFACE" up
}

start_backends()
{
    python3 "$BACKEND" --port 18081 --color green \
        --probe-token-file "$LAB_ROOT/green.probe-token" --applied-config-file "$LAB_ROOT/green.ack" \
        >/evidence/backend-green.log 2>&1 &
    BACKEND_GREEN_PID=$!
    python3 "$BACKEND" --port 18082 --color blue \
        --probe-token-file "$LAB_ROOT/blue.probe-token" --applied-config-file "$LAB_ROOT/blue.ack" \
        >/evidence/backend-blue.log 2>&1 &
    BACKEND_BLUE_PID=$!
    python3 "$BACKEND" --port 18083 --color duplicate \
        --probe-token-file "$LAB_ROOT/green.probe-token" --applied-config-file "$LAB_ROOT/green.ack" \
        --duplicate-ack >/evidence/backend-duplicate-ack.log 2>&1 &
    DUPLICATE_ACK_PID=$!
    python3 "$BACKEND" --port 18000 --color negative \
        --probe-token-file "$LAB_ROOT/negative.probe-token" --applied-config-file "$LAB_ROOT/negative.ack" \
        >/evidence/backend-negative.log 2>&1 &
    NEGATIVE_PID=$!
    for port in 18000 18081 18082 18083; do
        for _ in {1..50}; do
            ss -H -ltn "sport = :$port" | grep . >/dev/null && break
            sleep 0.1
        done
        ss -H -ltn "sport = :$port" | grep . >/dev/null || fail "backend port $port did not listen"
    done
}

start_legacy()
{
    local mode=${1:-initial} response
    [[ $mode == initial || $mode == replacement ]] || fail 'legacy startup mode is invalid'
    docker pull "$LAB_DIND_IMAGE" >/evidence/inner-image-pull.log
    docker run -d --name legacy-port8000 --restart always -p 8000:8080 \
        --mount type=volume,source=control-plane-port8000-legacy-runtime,target=/var/lib/docker \
        --entrypoint sh "$LAB_DIND_IMAGE" -ec '
            while :; do
                printf "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 7\r\nConnection: close\r\n\r\nlegacy\n" \
                    | nc -l -p 8080
            done
        ' >/evidence/legacy-container-id
    if [[ $mode == replacement ]]; then
        for _ in {1..80}; do
            [[ $(docker inspect --format '{{.State.Running}}' legacy-port8000 2>/dev/null) == true ]] && return
            sleep 0.25
        done
        fail 'replacement legacy published endpoint did not become running'
    fi
    for _ in {1..80}; do
        response=$(curl --silent --show-error --max-time 2 "$PUBLIC_URL/api/health" 2>/dev/null || true)
        [[ $response == legacy* ]] && return
        sleep 0.25
    done
    fail 'legacy published endpoint did not become ready'
}

expect_rollback_owner_adoption_crash()
{
    local seam=$1 owner_id=$2 status
    set +e
    controller adopt-rollback-owner \
        CONTROL_PLANE_PORT8000_ROLLBACK_OWNER_ID="$owner_id" \
        CONTROL_PLANE_TEST_PORT8000_CRASH_AT="$seam" \
        > "/evidence/crash-${seam}.log" 2>&1
    status=$?
    set -e
    [[ $status -eq 137 ]] || fail "rollback-owner adoption crash seam $seam exited $status instead of 137"
}

assert_rollback_owner_adoption()
{
    local original_id original_snapshot_sha256 original_mounts_sha256 replacement_id replacement_mounts_sha256 adoption_snapshot_sha256 before after status
    original_id=$(docker inspect --format '{{.Id}}' legacy-port8000)
    docker inspect legacy-port8000 > /evidence/rollback-owner-original-inspect.json
    original_mounts_sha256=$(jq -S '.[0].Mounts' /evidence/rollback-owner-original-inspect.json | sha256sum | awk '{print $1}')
    original_snapshot_sha256=$(sha256sum "$OPERATION_DIRECTORY/legacy-docker-owner.json" | awk '{print $1}')
    docker rm -f legacy-port8000 > /evidence/rollback-owner-original-remove.log
    for _ in {1..80}; do
        [[ $(docker ps -aq --filter publish=8000 | wc -l | tr -d '[:space:]') == 0 ]] && break
        sleep 0.25
    done
    [[ $(docker ps -aq --filter publish=8000 | wc -l | tr -d '[:space:]') == 0 ]] \
        || fail 'original rollback owner did not release TCP/8000'
    start_legacy replacement
    replacement_id=$(docker inspect --format '{{.Id}}' legacy-port8000)
    docker inspect legacy-port8000 > /evidence/rollback-owner-replacement-inspect.json
    replacement_mounts_sha256=$(jq -S '.[0].Mounts' /evidence/rollback-owner-replacement-inspect.json | sha256sum | awk '{print $1}')
    [[ $replacement_id != "$original_id" ]] || fail 'rollback incumbent recreation did not receive a new Docker ID'
    [[ $replacement_mounts_sha256 == "$original_mounts_sha256" ]] \
        || fail 'rollback incumbent recreation changed its exact mount inventory'
    assert_dynamic_endpoint_attestation
    assert_legacy_restore_refused replacement-unadopted

    before=$(operation_fingerprint)
    set +e
    controller adopt-rollback-owner CONTROL_PLANE_PORT8000_ROLLBACK_OWNER_ID="$original_id" \
        > /evidence/rollback-owner-original-id-rejected.log 2>&1
    status=$?
    set -e
    [[ $status -ne 0 ]] || fail 'rollback owner adoption accepted the removed original Docker ID'
    after=$(operation_fingerprint)
    [[ $before == "$after" ]] || fail 'rejected rollback owner adoption mutated durable state'

    expect_rollback_owner_adoption_crash after-rollback-owner-adoption-intent "$replacement_id"
    [[ $(sed -n 's/^phase=//p' "$OPERATION_DIRECTORY/state") == rollback-owner-adoption-intent ]] \
        || fail 'rollback owner intent was not durable before snapshot adoption'
    [[ ! -e $OPERATION_DIRECTORY/legacy-docker-owner-adopted.json ]] \
        || fail 'rollback owner snapshot existed before its durable adoption write'

    expect_rollback_owner_adoption_crash after-rollback-owner-adoption-snapshot "$replacement_id"
    [[ $(sed -n 's/^phase=//p' "$OPERATION_DIRECTORY/state") == rollback-owner-adoption-intent ]] \
        || fail 'snapshot crash unexpectedly advanced rollback owner state'
    [[ -f $OPERATION_DIRECTORY/legacy-docker-owner-adopted.json ]] \
        || fail 'snapshot crash did not leave the immutable adoption candidate'

    controller adopt-rollback-owner CONTROL_PLANE_PORT8000_ROLLBACK_OWNER_ID="$replacement_id" \
        > /evidence/rollback-owner-adoption.log
    controller adopt-rollback-owner CONTROL_PLANE_PORT8000_ROLLBACK_OWNER_ID="$replacement_id" \
        > /evidence/rollback-owner-adoption-idempotent.log
    adoption_snapshot_sha256=$(sha256sum "$OPERATION_DIRECTORY/legacy-docker-owner-adopted.json" | awk '{print $1}')
    [[ $(sha256sum "$OPERATION_DIRECTORY/legacy-docker-owner.json" | awk '{print $1}') == "$original_snapshot_sha256" ]] \
        || fail 'rollback owner adoption overwrote the original immutable Docker snapshot'
    [[ $(sed -n 's/^legacy_owner_original_snapshot_sha256=//p' "$OPERATION_DIRECTORY/state") == "$original_snapshot_sha256" \
        && $(sed -n 's/^legacy_owner_adoption_snapshot_sha256=//p' "$OPERATION_DIRECTORY/state") == "$adoption_snapshot_sha256" \
        && $(sed -n 's/^legacy_owner_active_snapshot_sha256=//p' "$OPERATION_DIRECTORY/state") == "$adoption_snapshot_sha256" ]] \
        || fail 'rollback owner state did not retain append-only immutable lineage'
    [[ $(jq -r '.[0].id' "$OPERATION_DIRECTORY/legacy-docker-owner-adopted.json") == "$replacement_id" ]] \
        || fail 'rollback owner adoption snapshot did not pin the replacement Docker ID'
    assert_legacy_restore_available replacement-adopted
    controller assert > /evidence/rollback-owner-adoption-assert.log
    restart_docker
    controller assert > /evidence/rollback-owner-adoption-post-dockerd-restart.log
    assert_owner bootstrap-a
}

assert_dynamic_endpoint_attestation()
{
    docker inspect legacy-port8000 \
        | jq -S '.[0] | {bindings: .HostConfig.PortBindings, endpoints: .NetworkSettings.Networks}' \
        > /evidence/legacy-dynamic-endpoints.json
    jq -e '(.bindings["8080/tcp"] | length) >= 1' /evidence/legacy-dynamic-endpoints.json >/dev/null \
        || fail 'legacy published binding was not dynamically attested'
    jq -e '[.endpoints[] | select(.IPAddress != "")] | length >= 1' /evidence/legacy-dynamic-endpoints.json >/dev/null \
        || fail 'legacy IPv4 endpoint was not dynamically attested'
    jq -e '[.endpoints[] | select(.GlobalIPv6Address != "")] | length >= 1' /evidence/legacy-dynamic-endpoints.json >/dev/null \
        || fail 'legacy IPv6 endpoint was not dynamically attested'
}

install_firewall_sentinel()
{
    nft -f - <<'RULES'
table inet tailscale_sentinel {
    comment "external-owner=tailscale-lab-sentinel"
    chain input {
        type filter hook input priority 50; policy accept;
        ip saddr 192.0.2.254 counter accept comment "sentinel-rule"
    }
}
RULES
    nft -s -n list table inet tailscale_sentinel | sha256sum | awk '{print $1}' \
        > /evidence/tailscale-sentinel.sha256
    iptables-save > /evidence/docker-iptables-v4.txt
    ip6tables-save > /evidence/docker-iptables-v6.txt
    nft -a list ruleset > /evidence/firewall-ruleset-before.txt
}

assert_firewall_owners_survive()
{
    local expected actual
    expected=$(cat /evidence/tailscale-sentinel.sha256)
    actual=$(nft -s -n list table inet tailscale_sentinel | sha256sum | awk '{print $1}')
    [[ $actual == "$expected" ]] || fail 'operator mutation changed the Tailscale-owned sentinel table'
    if [[ $LAB_FIREWALL_BACKEND == nftables ]]; then
        nft list table ip docker-bridges >/dev/null 2>&1 \
            || fail 'Docker native nftables table disappeared'
        nft list table ip6 docker-bridges >/dev/null 2>&1 \
            || fail 'Docker native IPv6 nftables table disappeared'
    else
        iptables-save > /evidence/docker-iptables-v4-current.txt
        ip6tables-save > /evidence/docker-iptables-v6-current.txt
        grep -F -q 'DOCKER' /evidence/docker-iptables-v4-current.txt \
            || fail 'Docker IPv4 iptables-nft rules disappeared'
        grep -F -q 'DOCKER' /evidence/docker-iptables-v6-current.txt \
            || fail 'Docker IPv6 iptables-nft rules disappeared'
    fi
}

assert_phase_b_bind_behavior()
{
    local config=/tmp/phase-b-bind-negative.cfg status
    {
        printf '%s\n' 'global'
        printf '%s\n' 'defaults'
        printf '%s\n' '    mode http'
        printf '%s\n' '    timeout connect 1s'
        printf '%s\n' '    timeout client 1s'
        printf '%s\n' '    timeout server 1s'
        printf '%s\n' 'frontend test'
        printf '%s\n' '    bind :::8000 v4v6'
        printf '%s\n' '    http-request return status 200 string forbidden'
    } > "$config"
    set +e
    timeout 2 haproxy -db -f "$config" >/evidence/phase-b-pre-removal.log 2>&1
    status=$?
    set -e
    if [[ $LAB_USERLAND_PROXY == true ]]; then
        [[ $status -ne 124 && $status -ne 0 ]] || fail 'phase B bound while docker-proxy owned :8000'
    fi
    controller switch >/evidence/pre-removal-repeat-switch.log
    [[ ! -e $CONFIG_DIRECTORY/phase-b.cfg ]] \
        || fail 'controller attempted phase B while Docker still declared :8000'
}

run_phase_a_crash_matrix()
{
    expect_controller_crash after-capture-intent
    assert_legacy_restore_available after-capture-intent
    expect_controller_crash after-phase-a-start
    assert_legacy_restore_available after-phase-a-start
    expect_controller_crash after-nft-capture
    assert_legacy_restore_available after-nft-capture
    expect_controller_crash after-public-ack
    assert_legacy_restore_available after-public-ack
    controller switch >/evidence/phase-a-switch.log
    controller ack >/evidence/phase-a-ack.log
    assert_owner bootstrap-a
    assert_direct_bootstrap_denied
    assert_header_overwrite
    assert_docker_bridge_client
    nft -a list table inet coolify_cp_port8000 > /evidence/phase-a-nft.txt
}

run_phase_b_crash_matrix()
{
    local canary_header="$LAB_ROOT/.canary-probe-header"
    local identity_state_sha256 phase_a_pid reused_start_time captured_start_time systemd_main_pid_show_count
    expect_controller_crash after-permanent-intent
    assert_owner bootstrap-a
    expect_controller_crash after-phase-b-start
    assert_owner bootstrap-a
    expect_controller_crash after-canary-rule
    assert_owner bootstrap-a
    expect_controller_crash after-canary-ack
    assert_owner bootstrap-a
    expect_controller_crash after-external-policy-ack
    assert_owner bootstrap-a
    printf 'X-Control-Plane-Probe: %s\n' "$(<"$LAB_ROOT/green.probe-token")" > "$canary_header"
    chmod 600 "$canary_header"
    if ! ip netns exec "$WAN_NAMESPACE" curl --fail --silent --show-error --max-time 5 -D - -o /dev/null \
        -H "@$canary_header" -H "Host: $HOST_HEADER" \
        "http://${PUBLIC_IPV4}:8000/api/control-plane/probe" \
        | grep -F -i "X-Control-Plane-Applied-Config: $GREEN_ACKNOWLEDGEMENT" >/dev/null; then
        rm -f -- "$canary_header"
        fail 'canary did not reach phase B while normal traffic remained on phase A'
    fi
    rm -f -- "$canary_header"
    nft -a list table inet coolify_cp_port8000 > /evidence/canary-nft.txt

    printf 'X-Control-Plane-Probe: %s\n' "$(<"$LAB_ROOT/green.probe-token")" > "$canary_header"
    chmod 600 "$canary_header"
    if ! ip netns exec "$WAN_NAMESPACE" curl --interface "$NON_CANARY_IPV4" \
        --fail --silent --show-error --max-time 5 -D - -o /dev/null \
        -H "@$canary_header" -H "Host: $HOST_HEADER" \
        "http://${PUBLIC_IPV4}:8000/api/control-plane/probe" \
        | grep -F -i 'X-Control-Plane-Port-Owner: bootstrap-a' >/dev/null; then
        rm -f -- "$canary_header"
        fail 'non-canary source escaped phase A during the canary window'
    fi
    rm -f -- "$canary_header"

    assert_retained_capture_rejected

    expect_controller_crash after-redirect-remove
    assert_owner permanent-b
    expect_controller_crash after-permanent-intent
    assert_owner bootstrap-a

    python3 "$TRAFFIC" protocols --url "$PUBLIC_URL" \
        --output-directory /evidence/held-protocols &
    PROTOCOL_PID=$!
    sleep 0.5
    expect_controller_crash after-public-permanent-ack
    assert_owner permanent-b
    controller switch >/evidence/permanent-drain-first.log
    [[ $(sed -n 's/^phase=//p' "$OPERATION_DIRECTORY/state") == permanent-acknowledged ]] \
        || fail 'phase A was removed before held sessions and conntrack drained'
    wait "$PROTOCOL_PID" || fail 'held keepalive/WebSocket/SSE/long request failed across atomic switch'
    PROTOCOL_PID=
    install_systemd_stop_fixture
    expect_systemd_controller_crash after-phase-a-drain-intent assert
    assert_systemd_finalization_identity
    assert_systemd_finalization_retained
    identity_state_sha256=$(sha256sum "$OPERATION_DIRECTORY/state" | awk '{print $1}')
    expect_systemd_stop_failure activity-query-failure no-change failure 'systemd could not safely determine HAProxy phase-a activity state'
    assert_systemd_finalization_retained
    expect_systemd_stop_failure activity-state-unknown no-change unknown 'systemd could not safely determine HAProxy phase-a activity state'
    assert_systemd_finalization_retained
    expect_systemd_stop_failure stop-failure fail normal 'systemd failed to stop HAProxy phase-a'
    assert_systemd_finalization_retained
    [[ $(sha256sum "$OPERATION_DIRECTORY/state" | awk '{print $1}') == "$identity_state_sha256" ]] \
        || fail 'systemd stop failure changed the durable phase-A process identity'
    assert_owner permanent-b
    set_systemd_stop_fixture_process_stat unreadable
    expect_systemd_stop_failure unreadable-process-stat linger normal 'process stat could not be safely attested after systemd stop'
    assert_systemd_finalization_retained
    restore_systemd_stop_fixture_process
    set_systemd_stop_fixture_process_stat malformed
    expect_systemd_stop_failure malformed-process-stat linger normal 'process stat could not be safely attested after systemd stop'
    assert_systemd_finalization_retained
    restore_systemd_stop_fixture_process
    expect_systemd_stop_failure lingering-captured-pid linger normal 'captured phase-A HAProxy MainPID'
    assert_systemd_finalization_retained
    [[ $(sha256sum "$OPERATION_DIRECTORY/state" | awk '{print $1}') == "$identity_state_sha256" ]] \
        || fail 'lingering captured phase-A process changed the durable stop identity'
    expect_systemd_controller_crash after-phase-a-systemd-stop assert normal
    assert_systemd_finalization_retained
    expect_systemd_controller_crash after-phase-a-config-removed assert pid-reuse
    [[ $(sed -n 's/^phase=//p' "$OPERATION_DIRECTORY/state") == phase-a-drain-intent \
        && ! -e $CONFIG_DIRECTORY/phase-a.cfg \
        && -S $RUNTIME_DIRECTORY/phase-a.sock ]] \
        || fail 'config-deletion crash did not preserve a retryable phase-A drain intent'
    assert_owner permanent-b
    phase_a_pid=$(sed -n 's/^phase_a_main_pid=//p' "$OPERATION_DIRECTORY/state")
    reused_start_time=$(awk '{print $22}' "$SYSTEMD_STOP_PROCESS_ROOT/$phase_a_pid/stat")
    captured_start_time=$(sed -n 's/^phase_a_main_start_time=//p' "$OPERATION_DIRECTORY/state")
    [[ $reused_start_time != "$captured_start_time" ]] \
        || fail 'PID reuse fixture did not change the captured process start time'
    restore_phase_a_configuration_after_power_loss
    [[ -e $CONFIG_DIRECTORY/phase-a.cfg ]] \
        || fail 'power-loss fixture did not restore the deleted phase-A configuration'
    expect_systemd_controller_crash after-phase-a-stop assert normal
    [[ $(sed -n 's/^phase=//p' "$OPERATION_DIRECTORY/state") == phase-a-stopped \
        && ! -e $CONFIG_DIRECTORY/phase-a.cfg \
        && ! -e $RUNTIME_DIRECTORY/phase-a.sock ]] \
        || fail 'phase-A retry did not durably reconcile reappeared configuration and socket cleanup'
    expect_systemd_controller_crash after-nft-removal-intent assert
    [[ $(sed -n 's/^phase=//p' "$OPERATION_DIRECTORY/state") == nft-removal-intent ]] \
        || fail 'nftables removal intent was not durable before boot-bundle publication'
    expect_systemd_controller_crash after-nft-absent-published assert
    grep -F -x -q '# coolify-port8000-mode=absent' "$CONFIG_DIRECTORY/active.nft" \
        || fail 'absent boot bundle was not atomically published before nftables removal'
    nft list table inet coolify_cp_port8000 >/dev/null 2>&1 \
        || fail 'nftables table disappeared before the injected post-publication crash seam'
    expect_systemd_controller_crash after-nft-table-removed assert
    ! nft list table inet coolify_cp_port8000 >/dev/null 2>&1 \
        || fail 'post-delete crash seam retained the capture nftables table'
    expect_systemd_controller_crash after-nft-removed assert
    [[ $(sed -n 's/^phase=//p' "$OPERATION_DIRECTORY/state") == nft-removed ]] \
        || fail 'nftables removal was not durably recorded after deletion'
    expect_systemd_controller_crash after-phase-a-drain assert
    [[ $(sed -n 's/^phase=//p' "$OPERATION_DIRECTORY/state") == permanent ]] \
        || fail 'permanent phase was not durable before its crash seam'
    systemd_controller assert CONTROL_PLANE_PORT8000_DRAIN_ATTEMPTS=30 >/evidence/permanent-assert.log
    [[ $(sed -n 's/^phase=//p' "$OPERATION_DIRECTORY/state") == permanent ]] \
        || fail 'phase A was not removed after stable session and conntrack drain'
    [[ ! -e $CONFIG_DIRECTORY/phase-a.cfg ]] || fail 'phase-A configuration survived permanent drain'
    ! nft list table inet coolify_cp_port8000 >/dev/null 2>&1 \
        || fail 'capture nftables table survived permanent drain'
    grep -F -x -q '# coolify-port8000-mode=absent' "$CONFIG_DIRECTORY/active.nft" \
        || fail 'permanent phase did not retain the absent boot bundle'
    systemd_main_pid_show_count=$(grep -F -c show-main-pid "$SYSTEMD_STOP_FIXTURE_DIRECTORY/events" || true)
    [[ $systemd_main_pid_show_count -eq 1 ]] \
        || fail 'systemd retry recaptured rather than reused the durable phase-A process identity'
}

main()
{
    mkdir -p /evidence "$LAB_ROOT" "$CONFIG_DIRECTORY" "$RUNTIME_DIRECTORY" "$HAPROXY_STATE_DIRECTORY"
    chmod 700 "$LAB_ROOT" "$CONFIG_DIRECTORY" "$HAPROXY_STATE_DIRECTORY"
    chmod 755 "$RUNTIME_DIRECTORY"
    printf '%s' "$GREEN_ACKNOWLEDGEMENT" > "$LAB_ROOT/green.ack"
    printf '%s' "$BLUE_ACKNOWLEDGEMENT" > "$LAB_ROOT/blue.ack"
    printf '%s' 'port8000-lab-green-probe-token-20260713' > "$LAB_ROOT/green.probe-token"
    printf '%s' 'port8000-lab-blue-probe-token-20260713' > "$LAB_ROOT/blue.probe-token"
    printf '%s' 'port8000-lab-negative-probe-token-20260713' > "$LAB_ROOT/negative.probe-token"
    printf '%s' 'port8000-lab-negative-ack-20260713' > "$LAB_ROOT/negative.ack"
    {
        printf '%s\n' 'version=1'
        printf '%s\n' 'host=coolify.test'
        printf 'observed_at_epoch=%s\n' "$(date -u +%s)"
        printf '%s\n' 'default_route_sha256=none'
        printf 'interface_global_ipv6=%s\n' "$PUBLIC_IPV6"
        printf '%s\n' 'dns_aaaa=none'
        printf '%s\n' 'provider_endpoint=none'
        printf '%s\n' 'external_vantage=none'
        printf 'external_denial=[%s]:8000\n' "$PUBLIC_IPV6"
    } > "$IPV6_INVENTORY"
    chmod 600 "$LAB_ROOT"/*.ack "$LAB_ROOT"/*.probe-token "$IPV6_INVENTORY"
    install_external_policy_probe
    install_ipv6_inventory_probe
    install_lab_dependencies
    assert_atomic_boot_bundle_helper
    assert_target_mode_binding_rejected
    assert_input_and_family_negatives
    sysctl -w net.ipv4.ip_forward=1 >/dev/null
    sysctl -w net.ipv6.conf.all.forwarding=1 >/dev/null
    # Keep the exact drain invariant while bounding the nine-cell lab. Production retains its host
    # conntrack timeouts and therefore keeps phase A until a later assert if old tuples remain.
    sysctl -w net.netfilter.nf_conntrack_tcp_timeout_time_wait=3 >/dev/null
    sysctl -w net.netfilter.nf_conntrack_tcp_timeout_close_wait=3 >/dev/null
    setup_network
    start_backends
    start_docker
    docker info --format '{{json .}}' > /evidence/docker-info.json
    start_legacy
    assert_dynamic_endpoint_attestation
    assert_docker_inventory_tristate
    install_firewall_sentinel
    assert_firewall_owners_survive

    # Equal priority is registration-order dependent across nftables and iptables-nft; the controller
    # must reject it rather than claim deterministic ownership. Later priority is proven to lose.
    assert_controller_priority_rejected -100
    assert_controller_priority_rejected -90
    install_negative_priority_control -90 cp_negative_later
    assert_negative_priority_control -90 cp_negative_later
    kill "$NEGATIVE_PID"
    wait "$NEGATIVE_PID" 2>/dev/null || true
    NEGATIVE_PID=
    ! ss -H -ltn 'sport = :18000' | grep . >/dev/null \
        || fail 'negative-control listener did not release bootstrap port'
    controller external-blocked-status > /evidence/external-policy-status.log
    assert_external_policy_checksum_refused
    controller policy-status > /evidence/policy-status-legacy-unprepared.log
    controller preflight > /evidence/preflight.log
    controller prepare > /evidence/prepare.log
    assert_legacy_restore_available prepared
    assert_tampered_restore_state_refused
    assert_duplicate_phase_owner_rejected

    run_phase_a_crash_matrix
    assert_captured_docker_inventory_failure_did_not_mutate
    assert_backend_identity_negatives
    controller policy-status > /evidence/policy-status-captured.log
    assert_firewall_owners_survive
    assert_phase_b_bind_behavior

    python3 "$TRAFFIC" continuous --url "$PUBLIC_URL" --count 1200 \
        --output /evidence/continuous-failures.txt &
    CONTINUOUS_PID=$!
    restart_docker
    assert_owner bootstrap-a
    controller assert > /evidence/post-dockerd-restart-phase-a.log
    assert_dynamic_endpoint_attestation
    assert_firewall_owners_survive
    assert_rollback_owner_adoption
    assert_firewall_owners_survive

    before_write=$(curl --fail --silent --show-error --max-time 5 \
        -X POST -H 'Idempotency-Key: port8000-idempotent' --data 'payload' "$PUBLIC_URL/write")
    docker rm -f legacy-port8000 > /evidence/legacy-remove.log
    for _ in {1..80}; do
        [[ $(docker ps -aq --filter publish=8000 | wc -l | tr -d '[:space:]') == 0 ]] && break
        sleep 0.25
    done
    assert_legacy_restore_refused original-blue-removed
    run_phase_b_crash_matrix
    controller policy-status > /evidence/policy-status-permanent.log
    assert_legacy_restore_refused permanent
    after_write=$(curl --fail --silent --show-error --max-time 5 \
        -X POST -H 'Idempotency-Key: port8000-idempotent' --data 'payload' "$PUBLIC_URL/write")
    [[ $before_write == "$after_write" ]] || fail 'idempotent write response changed across :8000 ownership switch'

    restart_docker
    assert_owner permanent-b

    controller assert > /evidence/post-dockerd-restart-permanent.log
    assert_firewall_owners_survive
    wait "$CONTINUOUS_PID" || fail 'fresh request stream observed a 4xx/5xx/transport gap'
    CONTINUOUS_PID=
    [[ ! -s /evidence/continuous-failures.txt ]] || fail 'continuous request evidence contains failures'

    set +e
    controller restore > /evidence/forbidden-legacy-restore.log 2>&1
    restore_status=$?
    set -e
    [[ $restore_status -ne 0 ]] || fail 'legacy restore was allowed after permanent ownership'
    assert_owner permanent-b
    assert_permanent_green_to_blue_update

    {
        printf 'result_version=2\n'
        printf 'cell=%s\n' "$LAB_CELL"
        printf 'docker_version=%s\n' "$(docker version --format '{{.Server.Version}}')"
        printf 'image_ref=%s\n' "$LAB_DIND_IMAGE"
        printf 'firewall_backend=%s\n' "$LAB_FIREWALL_BACKEND"
        printf 'userland_proxy=%s\n' "$LAB_USERLAND_PROXY"
        printf 'controller_sha256=%s\n' "$(sha256sum "$CONTROLLER" | awk '{print $1}')"
        printf 'test_bundle_sha256=%s\n' "$(source_bundle_checksum \
            "$TEST_DIRECTORY/run.sh" "$TEST_DIRECTORY/cell.sh" "$BACKEND" "$TRAFFIC")"
        printf 'haproxy_unit_sha256=%s\n' "$(sha256sum /workspace/docker/control-plane-blue-green/port8000/coolify-port8000-haproxy@.service | awk '{print $1}')"
        printf 'nft_unit_sha256=%s\n' "$(sha256sum /workspace/docker/control-plane-blue-green/port8000/coolify-port8000-nft.service | awk '{print $1}')"
        printf 'nft_helper_sha256=%s\n' "$(sha256sum /workspace/docker/control-plane-blue-green/port8000/apply-active-nft.sh | awk '{print $1}')"
        printf 'result=pass\n'
    } > /evidence/result
    note 'result=pass'
}

main "$@"
