#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

TEST_DIRECTORY=$(readlink -f -- "$(dirname -- "${BASH_SOURCE[0]}")")
readonly TEST_DIRECTORY
REPOSITORY_ROOT=$(readlink -f -- "$TEST_DIRECTORY/../../..")
readonly REPOSITORY_ROOT
readonly CONTROLLER=$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/runtime-attestation-ssh-fence.sh
readonly SERVICE=$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/coolify-runtime-attestation-ssh-fence.service
readonly WATCHDOG_SERVICE=$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/coolify-runtime-attestation-ssh-fence-watchdog.service
readonly INSTALLER=$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/install-runtime-attestation-ssh-fence.sh
readonly PROVISIONER=$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/provision-runtime-attestation-ssh-fence.sh
readonly PRODUCTION_PROVIDER_PROBE=$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/traefik-docker-provider-freshness-probe.sh
readonly TERMINAL_PROBE_TEST=$TEST_DIRECTORY/control-plane-terminal-state-probe-test.sh
readonly PRODUCTION_REAPER=$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/self-ssh-controlmaster-reaper.sh
readonly CONTROLMASTER_REAPER_TEST=$TEST_DIRECTORY/self-ssh-controlmaster-reaper-test.sh
readonly PREPARE_UBUNTU_TEST=$TEST_DIRECTORY/provisioner-prepare-ubuntu.sh
readonly BOOT_ATOMICITY_UBUNTU_TEST=$TEST_DIRECTORY/provisioner-boot-atomicity-ubuntu.sh
readonly POOL_FIXTURE=$TEST_DIRECTORY/pool-manifest-fixture.bash

fail()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE_TEST_FAILURE %s\n' "$1" >&2
    exit 1
}

runtime_fence_test_error()
{
    local status=$?
    printf 'CONTROL_PLANE_RUNTIME_FENCE_TEST_ERROR line=%s status=%s command=%q\n' \
        "${BASH_LINENO[0]}" "$status" "$BASH_COMMAND" >&2
    return "$status"
}

trap runtime_fence_test_error ERR

file_identity()
{
    if [[ -f $1 && ! -L $1 ]]; then
        sha256sum "$1" | awk '{print $1}'
    else
        printf '%s\n' absent
    fi
}

systemd_fixture_identity()
{
    sha256sum "$fixture/systemd/$(basename -- "$SERVICE")" \
        "$fixture/systemd/$(basename -- "$WATCHDOG_SERVICE")" \
        | sha256sum | awk '{print $1}'
}

assert_runtime_artifacts_unchanged()
{
    local label=$1 state_path=$2 expected_state=$3 expected_nft=$4 expected_systemd=$5
    [[ $(file_identity "$state_path") == "$expected_state" ]] \
        || fail "$label changed durable runtime fence state"
    [[ $(file_identity "$fixture/nft.json") == "$expected_nft" ]] \
        || fail "$label changed the managed nft table"
    [[ $(systemd_fixture_identity) == "$expected_systemd" ]] \
        || fail "$label changed the runtime fence systemd contract"
}

fixture=$(readlink -f -- "$(mktemp -d /tmp/control-plane-runtime-fence.XXXXXX)")
trap 'rm -rf "$fixture"' EXIT HUP INT TERM
mkdir -p "$fixture/bin" "$fixture/libexec" "$fixture/proc" "$fixture/state" \
    "$fixture/systemd/drop-ins"
cp -- "$SERVICE" "$fixture/systemd/$(basename -- "$SERVICE")"
cp -- "$WATCHDOG_SERVICE" "$fixture/systemd/$(basename -- "$WATCHDOG_SERVICE")"
chmod 0644 "$fixture/systemd/$(basename -- "$SERVICE")" \
    "$fixture/systemd/$(basename -- "$WATCHDOG_SERVICE")"
cp -- "$TEST_DIRECTORY/provider-probe.sh" "$fixture/libexec/provider-probe"
cp -- "$TEST_DIRECTORY/reaper.sh" "$fixture/libexec/reaper"
cp -- "$TEST_DIRECTORY/queue-probe.sh" "$fixture/libexec/queue-probe"
cp -- "$TEST_DIRECTORY/terminal-probe.sh" "$fixture/libexec/terminal-probe"
chmod 0700 "$fixture/libexec/provider-probe" "$fixture/libexec/reaper" \
    "$fixture/libexec/queue-probe" "$fixture/libexec/terminal-probe"
printf '%s' 'APP_ENV=production' > "$fixture/candidate-startup.env"
printf '%s' 'APP_ENV=production' > "$fixture/candidate-b-startup.env"
printf '%s' 'APP_ENV=production' > "$fixture/legacy-startup.env"
printf '%s' 'legacy-direct-token' > "$fixture/legacy-direct-token"
printf '%s' 'legacy-applied-ack' > "$fixture/legacy-applied-ack"
printf '%s' 'rogue-secret-source' > "$fixture/rogue-secret-source"
for command in conntrack curl docker flock ip ipcalc nft nsenter ssh ssh-keyscan stat systemctl; do
    ln -s "$TEST_DIRECTORY/fixture-command.sh" "$fixture/bin/$command"
done
printf '1111\n' > "$fixture/dockerd-pid"
printf 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n' > "$fixture/invocation-id"
printf '11111111-2222-3333-4444-555555555555\n' > "$fixture/boot-id"

socket=$fixture/docker.sock
python3 - "$socket" <<'PY' &
import socket
import sys
import time
s = socket.socket(socket.AF_UNIX)
s.bind(sys.argv[1])
time.sleep(120)
PY
socket_pid=$!
trap 'kill "$socket_pid" 2>/dev/null || true; rm -rf "$fixture"' EXIT HUP INT TERM
for _ in {1..20}; do
    [[ -S $socket ]] && break
    sleep 0.1
done
[[ -S $socket ]] || fail 'fixture Docker socket did not start'

chmod +x "$TEST_DIRECTORY"/*.sh "$CONTROLLER"
if [[ ${CONTROL_PLANE_RUNTIME_SKIP_PREFLIGHTS:-0} != 1 ]]; then
    "$TEST_DIRECTORY/linux-host-acceptance.sh" --check
    "$TEST_DIRECTORY/proxy-queue-zero-probe-test.sh"
    "$TERMINAL_PROBE_TEST"
    "$CONTROLMASTER_REAPER_TEST"
    "$PREPARE_UBUNTU_TEST"
    "$BOOT_ATOMICITY_UBUNTU_TEST"
fi
export FIXTURE_ROOT=$fixture
export FIXTURE_REAL_STAT
FIXTURE_REAL_STAT=$(command -v gstat || command -v stat)
export PATH=$fixture/bin:$PATH
export FIXTURE_STAT_UID
FIXTURE_STAT_UID=$(id -u)
export FIXTURE_STAT_GID
FIXTURE_STAT_GID=$(id -g)
export FIXTURE_POOL_LABEL_VALUE=runtime-fence-test
export FIXTURE_POOL_ACK=pool-ack-token-0001
export CONTROL_PLANE_RUNTIME_TEST_MODE=1
export CONTROL_PLANE_RUNTIME_TEST_SYSTEMD_DIRECTORY=$fixture/systemd
export CONTROL_PLANE_RUNTIME_OPERATION_ID=runtime-fence-test
export CONTROL_PLANE_RUNTIME_STATE_DIR=$fixture/state
export CONTROL_PLANE_RUNTIME_PROXY_CONTAINER=proxy
# shellcheck source=tests/Integration/ControlPlaneRuntimeFence/pool-manifest-fixture.bash
source "$POOL_FIXTURE"
prepare_control_plane_pool_plan_fixture \
    "$fixture" runtime-fence-test "$FIXTURE_STAT_UID" "$FIXTURE_STAT_GID"
chgrp -R "$FIXTURE_STAT_GID" "$fixture"
export CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST=$POOL_FIXTURE_POOL_PLAN_MANIFEST
export CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256=$POOL_FIXTURE_POOL_PLAN_SHA256
export CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_METADATA
CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_METADATA=$(pool_fixture_metadata \
    "$POOL_FIXTURE_POOL_PLAN_MANIFEST")
export CONTROL_PLANE_RUNTIME_LEGACY_CONTAINER=legacy
export CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER=candidate
export CONTROL_PLANE_RUNTIME_CANDIDATE_IMAGE_REFERENCE=registry.example/candidate@sha256:4444444444444444444444444444444444444444444444444444444444444444
export CONTROL_PLANE_RUNTIME_CANDIDATE_IMAGE_ID=sha256:3333333333333333333333333333333333333333333333333333333333333333
export CONTROL_PLANE_RUNTIME_CANDIDATE_NETWORK_IDS=dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd
export CONTROL_PLANE_RUNTIME_CANDIDATE_REPIN_INTENT_FILE=$fixture/candidate-repin-intent
export CONTROL_PLANE_RUNTIME_PROVIDER_PROBE=$fixture/libexec/provider-probe
export CONTROL_PLANE_RUNTIME_PROVIDER_PROBE_SHA256
CONTROL_PLANE_RUNTIME_PROVIDER_PROBE_SHA256=$(sha256sum "$CONTROL_PLANE_RUNTIME_PROVIDER_PROBE" | awk '{print $1}')
export CONTROL_PLANE_RUNTIME_CONTROLMASTER_REAPER=$fixture/libexec/reaper
export CONTROL_PLANE_RUNTIME_CONTROLMASTER_REAPER_SHA256
CONTROL_PLANE_RUNTIME_CONTROLMASTER_REAPER_SHA256=$(sha256sum "$CONTROL_PLANE_RUNTIME_CONTROLMASTER_REAPER" | awk '{print $1}')
export CONTROL_PLANE_RUNTIME_QUEUE_PROBE=$fixture/libexec/queue-probe
export CONTROL_PLANE_RUNTIME_QUEUE_PROBE_SHA256
CONTROL_PLANE_RUNTIME_QUEUE_PROBE_SHA256=$(sha256sum "$CONTROL_PLANE_RUNTIME_QUEUE_PROBE" | awk '{print $1}')
export CONTROL_PLANE_RUNTIME_TERMINAL_PROBE=$fixture/libexec/terminal-probe
export CONTROL_PLANE_RUNTIME_TERMINAL_PROBE_SHA256
CONTROL_PLANE_RUNTIME_TERMINAL_PROBE_SHA256=$(sha256sum "$CONTROL_PLANE_RUNTIME_TERMINAL_PROBE" | awk '{print $1}')
export CONTROL_PLANE_RUNTIME_MANAGEMENT_ENDPOINTS='tailscale0=100.64.0.1'
export CONTROL_PLANE_RUNTIME_ADDITIONAL_NETWORK_IDS=
export CONTROL_PLANE_RUNTIME_SELF_SSH_TARGET=host.docker.internal
export CONTROL_PLANE_RUNTIME_PROBE_MAX_AGE_SECONDS=30
export CONTROL_PLANE_RUNTIME_QUEUE_STABLE_SECONDS=1
export CONTROL_PLANE_RUNTIME_PROVIDER_CAPTURE_EXPECTATION=incumbent
export CONTROL_PLANE_RUNTIME_PROVIDER_API_URL=http://127.0.0.1:8080/api/rawdata
export CONTROL_PLANE_RUNTIME_PROVIDER_ROUTER=coolify@docker
export CONTROL_PLANE_RUNTIME_PROVIDER_SERVICE=coolify@docker
export CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT=8080
export CONTROL_PLANE_RUNTIME_PROVIDER_HEADER_FILE=
export CONTROL_PLANE_RUNTIME_QUEUE_CONTAINER=candidate
export CONTROL_PLANE_RUNTIME_ACTIVE_CONTAINER=candidate
export CONTROL_PLANE_RUNTIME_TERMINAL_HTTPS_URL=https://coolify.example/health
export CONTROL_PLANE_RUNTIME_TERMINAL_PORT8000_URL=http://127.0.0.1:8000/health
export CONTROL_PLANE_RUNTIME_TERMINAL_HTTPS_ACK_FILE=$fixture/https-ack
export CONTROL_PLANE_RUNTIME_TERMINAL_PORT8000_ACK_FILE=$fixture/port8000-ack
printf 'abcdefghijklmnop' > "$CONTROL_PLANE_RUNTIME_TERMINAL_HTTPS_ACK_FILE"
printf 'abcdefghijklmnop' > "$CONTROL_PLANE_RUNTIME_TERMINAL_PORT8000_ACK_FILE"
CONTROL_PLANE_RUNTIME_RELEASE_FILES_SHA256=$(
    {
        printf 'provider-header|absent\n'
        printf 'pool-plan|%s|%s|%s\n' "$POOL_FIXTURE_POOL_PLAN_MANIFEST" \
            "$POOL_FIXTURE_POOL_PLAN_SHA256" \
            "$(pool_fixture_metadata "$POOL_FIXTURE_POOL_PLAN_MANIFEST")"
        printf 'route-health-token|%s|%s|%s\n' "$POOL_FIXTURE_ROUTE_TOKEN" \
            "$(pool_fixture_sha256 "$POOL_FIXTURE_ROUTE_TOKEN")" \
            "$(pool_fixture_metadata "$POOL_FIXTURE_ROUTE_TOKEN")"
        printf 'route-health-runtime|%s|%s|%s\n' "$POOL_FIXTURE_ROUTE_RUNTIME" \
            "$(pool_fixture_sha256 "$POOL_FIXTURE_ROUTE_RUNTIME")" \
            "$(pool_fixture_metadata "$POOL_FIXTURE_ROUTE_RUNTIME" 400)"
        printf 'pool-ack|%s|%s|%s\n' "$POOL_FIXTURE_POOL_ACK" \
            "$(pool_fixture_sha256 "$POOL_FIXTURE_POOL_ACK")" \
            "$(pool_fixture_metadata "$POOL_FIXTURE_POOL_ACK")"
        printf 'pool-ack-runtime|%s|%s|%s\n' "$POOL_FIXTURE_POOL_ACK_RUNTIME" \
            "$(pool_fixture_sha256 "$POOL_FIXTURE_POOL_ACK_RUNTIME")" \
            "$(pool_fixture_metadata "$POOL_FIXTURE_POOL_ACK_RUNTIME" 400)"
        printf 'web-a-direct-probe-token|%s|%s|%s\n' "$POOL_FIXTURE_WEB_A_DIRECT_TOKEN" \
            "$(pool_fixture_sha256 "$POOL_FIXTURE_WEB_A_DIRECT_TOKEN")" \
            "$(pool_fixture_metadata "$POOL_FIXTURE_WEB_A_DIRECT_TOKEN")"
        printf 'web-a-direct-probe-runtime|%s|%s|%s\n' "$POOL_FIXTURE_WEB_A_DIRECT_RUNTIME" \
            "$(pool_fixture_sha256 "$POOL_FIXTURE_WEB_A_DIRECT_RUNTIME")" \
            "$(pool_fixture_metadata "$POOL_FIXTURE_WEB_A_DIRECT_RUNTIME" 400)"
        printf 'web-a-applied-ack|%s|%s|%s\n' "$POOL_FIXTURE_WEB_A_APPLIED_ACK" \
            "$(pool_fixture_sha256 "$POOL_FIXTURE_WEB_A_APPLIED_ACK")" \
            "$(pool_fixture_metadata "$POOL_FIXTURE_WEB_A_APPLIED_ACK")"
        printf 'web-a-applied-ack-runtime|%s|%s|%s\n' "$POOL_FIXTURE_WEB_A_APPLIED_RUNTIME" \
            "$(pool_fixture_sha256 "$POOL_FIXTURE_WEB_A_APPLIED_RUNTIME")" \
            "$(pool_fixture_metadata "$POOL_FIXTURE_WEB_A_APPLIED_RUNTIME" 400)"
        printf 'web-b-direct-probe-token|%s|%s|%s\n' "$POOL_FIXTURE_WEB_B_DIRECT_TOKEN" \
            "$(pool_fixture_sha256 "$POOL_FIXTURE_WEB_B_DIRECT_TOKEN")" \
            "$(pool_fixture_metadata "$POOL_FIXTURE_WEB_B_DIRECT_TOKEN")"
        printf 'web-b-direct-probe-runtime|%s|%s|%s\n' "$POOL_FIXTURE_WEB_B_DIRECT_RUNTIME" \
            "$(pool_fixture_sha256 "$POOL_FIXTURE_WEB_B_DIRECT_RUNTIME")" \
            "$(pool_fixture_metadata "$POOL_FIXTURE_WEB_B_DIRECT_RUNTIME" 400)"
        printf 'web-b-applied-ack|%s|%s|%s\n' "$POOL_FIXTURE_WEB_B_APPLIED_ACK" \
            "$(pool_fixture_sha256 "$POOL_FIXTURE_WEB_B_APPLIED_ACK")" \
            "$(pool_fixture_metadata "$POOL_FIXTURE_WEB_B_APPLIED_ACK")"
        printf 'web-b-applied-ack-runtime|%s|%s|%s\n' "$POOL_FIXTURE_WEB_B_APPLIED_RUNTIME" \
            "$(pool_fixture_sha256 "$POOL_FIXTURE_WEB_B_APPLIED_RUNTIME")" \
            "$(pool_fixture_metadata "$POOL_FIXTURE_WEB_B_APPLIED_RUNTIME" 400)"
    } | sha256sum | awk '{print $1}'
)
export CONTROL_PLANE_RUNTIME_RELEASE_FILES_SHA256
semantic_config=$fixture/semantic-config
for key in CONTROL_PLANE_RUNTIME_OPERATION_ID CONTROL_PLANE_RUNTIME_PROXY_CONTAINER \
    CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256 \
    CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_METADATA \
    CONTROL_PLANE_RUNTIME_MANAGEMENT_ENDPOINTS \
    CONTROL_PLANE_RUNTIME_ADDITIONAL_NETWORK_IDS CONTROL_PLANE_RUNTIME_SELF_SSH_TARGET \
    CONTROL_PLANE_RUNTIME_PROBE_MAX_AGE_SECONDS CONTROL_PLANE_RUNTIME_QUEUE_STABLE_SECONDS \
    CONTROL_PLANE_RUNTIME_PROVIDER_CAPTURE_EXPECTATION \
    CONTROL_PLANE_RUNTIME_PROVIDER_API_URL \
    CONTROL_PLANE_RUNTIME_PROVIDER_ROUTER CONTROL_PLANE_RUNTIME_PROVIDER_SERVICE \
    CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT \
    CONTROL_PLANE_RUNTIME_PROVIDER_HEADER_FILE \
    CONTROL_PLANE_RUNTIME_TERMINAL_HTTPS_URL \
    CONTROL_PLANE_RUNTIME_TERMINAL_PORT8000_URL; do
    printf '%s=%s\n' "$key" "${!key}" >> "$semantic_config"
done
CONTROL_PLANE_RUNTIME_SEMANTIC_CONFIG_SHA256=$(LC_ALL=C sort "$semantic_config" \
    | sha256sum | awk '{print $1}')
export CONTROL_PLANE_RUNTIME_SEMANTIC_CONFIG_SHA256
export CONTROL_PLANE_RUNTIME_BOOT_ID_FILE=$fixture/boot-id
export CONTROL_PLANE_RUNTIME_DOCKER_SOCKET=$socket
CONTROL_PLANE_RUNTIME_TEST_EXPECTED_NFT_IDENTITY=$(printf '%s\n' \
    '{"nftables":[{"metainfo":{"json_schema_version":1,"release_name":"fixture"}},{"table":{"family":"inet","name":"coolify_control_plane_ssh_fence","handle":1}},{"chain":{"family":"inet","table":"coolify_control_plane_ssh_fence","name":"container_to_host_ssh","handle":11}}]}' \
    | jq -S -c 'del(.. | .handle?) | .nftables |= map(select(has("metainfo") | not))' \
    | sha256sum | awk '{print $1}')
export CONTROL_PLANE_RUNTIME_TEST_EXPECTED_NFT_IDENTITY

CONTROL_PLANE_RUNTIME_ARMED=0 "$CONTROLLER" restore \
    | grep -F -x 'CONTROL_PLANE_RUNTIME_FENCE restore=skipped armed=false' >/dev/null

touch "$fixture/candidate-absent"
pre_capture_state=$fixture/state/runtime-fence-test/state
pre_capture_state_identity=$(file_identity "$pre_capture_state")
pre_capture_nft_identity=$(file_identity "$fixture/nft.json")
pre_capture_systemd_identity=$(systemd_fixture_identity)
for docker_api_scenario in absence-api-500 absence-daemon-unavailable \
    absence-permission-denied absence-timeout absence-malformed-success \
    absence-malformed-not-found; do
    printf '%s\n' "$docker_api_scenario" > "$fixture/docker-api-scenario"
    if "$CONTROLLER" stage-capture \
        > "$fixture/pre-capture-${docker_api_scenario}.out" 2>&1; then
        fail "capture staging accepted unavailable candidate-absence evidence: $docker_api_scenario"
    fi
    assert_runtime_artifacts_unchanged "pre-capture $docker_api_scenario" \
        "$pre_capture_state" "$pre_capture_state_identity" "$pre_capture_nft_identity" \
        "$pre_capture_systemd_identity"
done
for docker_api_scenario in info-failure malformed-info network-failure malformed-network; do
    printf '%s\n' "$docker_api_scenario" > "$fixture/docker-api-scenario"
    if "$CONTROLLER" stage-capture \
        > "$fixture/pre-capture-${docker_api_scenario}.out" 2>&1; then
        fail "capture staging accepted unavailable Docker inventory evidence: $docker_api_scenario"
    fi
    assert_runtime_artifacts_unchanged "pre-capture $docker_api_scenario" \
        "$pre_capture_state" "$pre_capture_state_identity" "$pre_capture_nft_identity" \
        "$pre_capture_systemd_identity"
done
rm -f -- "$fixture/docker-api-scenario"
touch "$fixture/unhealthy"
if "$CONTROLLER" stage-capture > "$fixture/unhealthy.out" 2>&1; then
    fail 'capture staging accepted an unhealthy exact container'
fi
grep -F -q 'container runtime identity is malformed, stopped, or unhealthy' "$fixture/unhealthy.out" || {
    sed 's/^/unhealthy-stage: /' "$fixture/unhealthy.out" >&2
    fail 'unhealthy capture emitted an unexpected failure'
}
[[ ! -e $fixture/nft.json ]] || fail 'unhealthy capture installed an nft fence before validation'
rm "$fixture/unhealthy"
touch "$fixture/management-bridge"
if "$CONTROLLER" stage-capture > "$fixture/management-bridge.out" 2>&1; then
    fail 'capture staging accepted a Linux bridge as the management SSH interface'
fi
grep -F -q 'management SSH interface is a Linux bridge or is attached through one: tailscale0 (bridge=tailscale0)' \
    "$fixture/management-bridge.out" || {
        cat "$fixture/management-bridge.out" >&2
        fail 'direct management bridge rejection emitted an unexpected failure'
    }
[[ ! -e $fixture/nft.json ]] || fail 'bridged management interface installed an nft fence'
[[ ! -e $fixture/state/runtime-fence-test/state ]] \
    || fail 'bridged management interface wrote capture state before rejection'
rm "$fixture/management-bridge"
touch "$fixture/management-bridge-master"
if "$CONTROLLER" stage-capture > "$fixture/management-bridge-master.out" 2>&1; then
    fail 'capture staging accepted a management interface attached through a Linux bridge'
fi
grep -F -q 'management SSH interface is a Linux bridge or is attached through one: tailscale0 (bridge=br-management)' \
    "$fixture/management-bridge-master.out"
[[ ! -e $fixture/nft.json ]] || fail 'bridged management master installed an nft fence'
[[ ! -e $fixture/state/runtime-fence-test/state ]] \
    || fail 'bridged management master wrote capture state before rejection'
rm "$fixture/management-bridge-master"
if CONTROL_PLANE_RUNTIME_TEST_CRASH_AT=after-preparing-state \
    "$CONTROLLER" stage-capture > "$fixture/preparing-crash.out" 2>&1; then
    fail 'preparing-state crash fixture unexpectedly completed'
fi
grep -F -x -q phase=preparing "$fixture/state/runtime-fence-test/state" || {
    sed 's/^/preparing-crash: /' "$fixture/preparing-crash.out" >&2
    fail 'preparing-state crash did not persist staged state'
}
[[ ! -e $fixture/nft.json ]] || fail 'preparing-state crash installed the nft fence'
if CONTROL_PLANE_RUNTIME_ARMED=0 "$CONTROLLER" capture > "$fixture/preparing-unarmed.out" 2>&1; then
    fail 'unarmed capture applied the staged nft fence'
fi
grep -F -q 'runtime fence capture requires boot restoration to be armed before nft mutation' \
    "$fixture/preparing-unarmed.out"
[[ ! -e $fixture/nft.json ]] || fail 'unarmed capture installed the staged nft fence'
touch "$fixture/management-bridge"
if CONTROL_PLANE_RUNTIME_ARMED=1 "$CONTROLLER" capture > "$fixture/preparing-management-bridge.out" 2>&1; then
    fail 'preparing capture resumed through a bridged management interface'
fi
grep -F -q 'management SSH interface is a Linux bridge or is attached through one: tailscale0 (bridge=tailscale0)' \
    "$fixture/preparing-management-bridge.out" || {
        sed 's/^/preparing-management-bridge: /' \
            "$fixture/preparing-management-bridge.out" >&2
        fail 'preparing management bridge emitted an unexpected failure'
    }
[[ ! -e $fixture/nft.json ]] \
    || fail 'preparing capture applied an nft fence after management topology drift'
rm "$fixture/management-bridge"

rm "$fixture/candidate-absent"
if CONTROL_PLANE_RUNTIME_ARMED=1 "$CONTROLLER" capture > "$fixture/candidate-before-fence.out" 2>&1; then
    fail 'capture activated a fence after the planned candidate already existed'
fi
grep -F -q 'planned candidate exists before the runtime fence is active' \
    "$fixture/candidate-before-fence.out"
[[ ! -e $fixture/nft.json ]] || fail 'pre-fence candidate rejection installed the nft fence'
grep -F -x -q candidate_runtime_sha256=none "$fixture/state/runtime-fence-test/state"
if "$CONTROLLER" abort > "$fixture/unpinned-candidate-abort.out" 2>&1; then
    fail 'pre-candidate abort ignored the planned candidate name without a runtime digest'
fi
grep -F -q 'planned candidate exists during pre-candidate fence cancellation' \
    "$fixture/unpinned-candidate-abort.out"
grep -F -x -q phase=preparing "$fixture/state/runtime-fence-test/state"
touch "$fixture/candidate-absent"

CONTROL_PLANE_RUNTIME_ARMED=1 "$CONTROLLER" restore \
    | grep -F -x 'CONTROL_PLANE_RUNTIME_FENCE restore=passed phase=preparing' >/dev/null
[[ -e $fixture/nft.json ]] || fail 'armed restore did not reconstruct the staged nft fence'
"$CONTROLLER" capture | grep -F -x 'CONTROL_PLANE_RUNTIME_FENCE capture=passed operation_id=runtime-fence-test phase=active' >/dev/null
ruleset=$fixture/state/runtime-fence-test/ssh-fence.nft
inventory=$fixture/state/runtime-fence-test/coolify-bridge-networks.tsv
state=$fixture/state/runtime-fence-test/state
grep -F -x -q version=4 "$state"
grep -F -x -q runtime_digest_version=4 "$state"
grep -F -x -q candidate_runtime_sha256=none "$state"
if grep -E -q '^candidate_denial_' "$state"; then
    fail 'candidate-absent capture persisted denial evidence'
fi
if grep -E -q 'candidate-direct-token|candidate-applied-ack|APP_ENV=production' "$state"; then
    fail 'durable runtime fence state persisted a secret-bearing runtime value'
fi
grep -F -x -q 'add table inet coolify_control_plane_ssh_fence' "$ruleset"
grep -F -x -q 'add rule inet coolify_control_plane_ssh_fence container_to_host_ssh meta iifkind "bridge" tcp dport 22 drop' "$ruleset"
grep -F -x -q 'add rule inet coolify_control_plane_ssh_fence container_to_host_ssh iifname "br-*" tcp dport 22 drop' "$ruleset"
grep -F -x -q 'add rule inet coolify_control_plane_ssh_fence container_to_host_ssh iifname "docker0" tcp dport 22 drop' "$ruleset"
grep -F -x -q 'add rule inet coolify_control_plane_ssh_fence container_to_host_ssh iifname "docker_gwbridge" tcp dport 22 drop' "$ruleset"
grep -F -x -q 'add rule inet coolify_control_plane_ssh_fence container_to_host_ssh iifname "br-dddddddddddd" tcp dport 22 drop' "$ruleset"
grep -F -x -q 'add rule inet coolify_control_plane_ssh_fence container_to_host_ssh iifname "br-dddddddddddd" ip saddr @bridge_ipv4 tcp dport 22 drop' "$ruleset"
grep -F -x -q 'add rule inet coolify_control_plane_ssh_fence container_to_host_ssh iifname "br-dddddddddddd" ip6 saddr @bridge_ipv6 tcp dport 22 drop' "$ruleset"
grep -F -q $'172.18.0.0/16' "$inventory"
grep -F -q $'fd00:18::/64' "$inventory"
"$CONTROLLER" verify | grep -F -x 'CONTROL_PLANE_RUNTIME_FENCE verify=passed operation_id=runtime-fence-test phase=active' >/dev/null
printf '\n# unit drift\n' >> "$fixture/systemd/$(basename -- "$SERVICE")"
for systemd_drift_action in verify status restore watch capture release; do
    if CONTROL_PLANE_RUNTIME_WATCH_ITERATIONS=1 \
        "$CONTROLLER" "$systemd_drift_action" > "$fixture/systemd-unit-${systemd_drift_action}.out" 2>&1; then
        fail "runtime fence action accepted changed installed unit bytes: $systemd_drift_action"
    fi
    grep -F -q 'systemd runtime fence unit contract changed' \
        "$fixture/systemd-unit-${systemd_drift_action}.out" || {
            sed "s/^/systemd-$systemd_drift_action: /" \
                "$fixture/systemd-unit-${systemd_drift_action}.out" >&2
            fail "systemd unit drift emitted an unexpected failure: $systemd_drift_action"
        }
done
cp -- "$SERVICE" "$fixture/systemd/$(basename -- "$SERVICE")"
"$CONTROLLER" verify >/dev/null
printf '[Service]\nRestart=no\n' > "$fixture/systemd/drop-ins/runtime-drift.conf"
touch "$fixture/systemd-drop-in"
if "$CONTROLLER" status > "$fixture/systemd-drop-in-drift.out" 2>&1; then
    fail 'runtime fence status accepted a changed systemd drop-in inventory'
fi
grep -F -q 'systemd runtime fence unit contract changed' "$fixture/systemd-drop-in-drift.out"
rm "$fixture/systemd-drop-in" "$fixture/systemd/drop-ins/runtime-drift.conf"
"$CONTROLLER" status >/dev/null
printf 'tampered-pool-ack' > "$POOL_FIXTURE_POOL_ACK"
if "$CONTROLLER" verify > "$fixture/release-file-tamper.out" 2>&1; then
    fail 'verify accepted changed release authorization file bytes'
fi
grep -E -q '(live release authorization files differ from their prepared identity|pool-ack differs from its pinned (metadata|checksum))' \
    "$fixture/release-file-tamper.out" || {
        sed 's/^/release-file-tamper: /' "$fixture/release-file-tamper.out" >&2
        fail 'release file tamper emitted an unexpected failure'
    }
printf '%s' pool-ack-token-0001 > "$POOL_FIXTURE_POOL_ACK"
"$CONTROLLER" verify >/dev/null
rm "$fixture/candidate-absent"
touch "$fixture/candidate-wrong-image"
if "$CONTROLLER" prove-pool-denied > "$fixture/candidate-image-drift.out" 2>&1; then
    fail 'candidate denial accepted an image outside the immutable pre-start plan'
fi
grep -F -q 'unproven two-member pool differs from its immutable image/restart plan' \
    "$fixture/candidate-image-drift.out" || {
        sed 's/^/candidate-image-drift: /' "$fixture/candidate-image-drift.out" >&2
        fail 'candidate image drift emitted an unexpected failure'
    }
if grep -E -q '^candidate_denial_id=' "$fixture/state/runtime-fence-test/state"; then
    fail 'candidate image mismatch persisted denial evidence'
fi
rm "$fixture/candidate-wrong-image"
if "$CONTROLLER" prove-pool-denied unexpected-candidate > "$fixture/candidate-name-drift.out" 2>&1; then
    fail 'pool denial accepted a singular candidate argument'
fi
grep -F -q 'two-member pool denial does not accept a singular container argument' \
    "$fixture/candidate-name-drift.out"
for mount_drift_marker in \
    candidate-private-volume-wrong \
    candidate-private-volume-swapped \
    candidate-private-volume-missing \
    candidate-coordination-volume-wrong \
    candidate-mounts-swapped \
    candidate-coordination-volume-missing \
    candidate-private-volume-extra-destination \
    candidate-coordination-volume-extra-destination \
    candidate-other-private-volume-extra-destination \
    candidate-b-private-volume-wrong \
    candidate-b-private-volume-swapped \
    candidate-b-private-volume-missing \
    candidate-b-coordination-volume-wrong \
    candidate-b-mounts-swapped \
    candidate-b-coordination-volume-missing \
    candidate-b-private-volume-extra-destination \
    candidate-b-coordination-volume-extra-destination \
    candidate-b-other-private-volume-extra-destination; do
    touch "$fixture/$mount_drift_marker"
    if "$CONTROLLER" prove-pool-denied > "$fixture/$mount_drift_marker.out" 2>&1; then
        fail "pool denial accepted a planned volume mount drift: $mount_drift_marker"
    fi
    grep -F -q 'pool member live private or coordination volume mount differs from the immutable plan' \
        "$fixture/$mount_drift_marker.out" || {
            sed "s/^/$mount_drift_marker: /" "$fixture/$mount_drift_marker.out" >&2
            fail "planned volume mount drift emitted an unexpected failure: $mount_drift_marker"
        }
    grep -F -x -q candidate_runtime_sha256=none "$state"
    rm -f -- "$fixture/$mount_drift_marker"
done
touch "$fixture/candidate-second-network"
if "$CONTROLLER" prove-pool-denied > "$fixture/candidate-pre-pin-network-drift.out" 2>&1; then
    fail 'candidate denial pinned a runtime outside the immutable pre-start network plan'
fi
grep -F -q 'candidate complete network inventory differs from the immutable plan' \
    "$fixture/candidate-pre-pin-network-drift.out"
grep -F -x -q candidate_runtime_sha256=none "$state"
if grep -E -q '^candidate_denial_' "$state"; then
    fail 'pre-pin network mismatch persisted candidate denial evidence'
fi
rm "$fixture/candidate-second-network"
expected_candidate_runtime_sha256=$("$CONTROLLER" container-runtime-sha256 candidate)
if CONTROL_PLANE_RUNTIME_TEST_CRASH_AT=after-candidate-denial-before-runtime-pin \
    "$CONTROLLER" prove-pool-denied > "$fixture/candidate-pin-crash.out" 2>&1; then
    candidate_pin_crash_status=0
else
    candidate_pin_crash_status=$?
fi
if [[ $candidate_pin_crash_status -ne 86 ]]; then
    sed 's/^/candidate-pin-crash: /' "$fixture/candidate-pin-crash.out" >&2
    fail 'candidate denial pre-pin crash fixture did not stop at the atomic pin boundary'
fi
grep -F -x -q phase=active "$state"
grep -F -x -q candidate_runtime_sha256=none "$state"
if grep -E -q '^candidate_denial_' "$state"; then
    fail 'pre-pin crash persisted partial candidate denial evidence'
fi
[[ -e $fixture/nft.json ]] || fail 'pre-pin crash removed the active runtime fence'
"$CONTROLLER" verify \
    | grep -F -x 'CONTROL_PLANE_RUNTIME_FENCE verify=passed operation_id=runtime-fence-test phase=active' >/dev/null
"$CONTROLLER" prove-pool-denied \
    | grep -F 'CONTROL_PLANE_RUNTIME_FENCE candidate_denial=passed' >/dev/null
grep -F -x -q "candidate_runtime_sha256=$expected_candidate_runtime_sha256" "$state"
if grep -E -q '^candidate_denial_runtime_sha256=' "$state"; then
    fail 'candidate denial evidence duplicated the canonical pinned runtime digest'
fi
touch "$fixture/candidate-wrong-control-plane-env"
if "$CONTROLLER" prove-pool-denied > "$fixture/candidate-control-plane-env-drift.out" 2>&1; then
    fail 'candidate denial accepted changed CONTROL_PLANE runtime environment'
fi
grep -F -q 'candidate runtime identity differs from the first denied runtime' \
    "$fixture/candidate-control-plane-env-drift.out"
rm "$fixture/candidate-wrong-control-plane-env"
touch "$fixture/candidate-wrong-security"
if "$CONTROLLER" prove-pool-denied > "$fixture/candidate-security-drift.out" 2>&1; then
    fail 'candidate denial accepted changed security configuration'
fi
grep -F -q 'candidate HostConfig security baseline differs from the canonical static contract' \
    "$fixture/candidate-security-drift.out"
rm "$fixture/candidate-wrong-security"
touch "$fixture/candidate-wrong-runtime"
if "$CONTROLLER" prove-pool-denied > "$fixture/candidate-runtime-drift.out" 2>&1; then
    fail 'candidate denial accepted runtime configuration outside the immutable pre-start plan'
fi
grep -F -q 'candidate runtime identity differs from the first denied runtime' \
    "$fixture/candidate-runtime-drift.out"
rm "$fixture/candidate-wrong-runtime"
touch "$fixture/candidate-wrong-bindings"
if "$CONTROLLER" prove-pool-denied > "$fixture/candidate-binding-drift.out" 2>&1; then
    fail 'candidate denial accepted port bindings outside the immutable pre-start plan'
fi
grep -F -q 'candidate runtime identity differs from the first denied runtime' \
    "$fixture/candidate-binding-drift.out"
rm "$fixture/candidate-wrong-bindings"
printf '%s' 'APP_ENV=drifted' > "$fixture/candidate-startup.env"
if "$CONTROLLER" prove-pool-denied > "$fixture/candidate-startup-file-drift.out" 2>&1; then
    fail 'candidate denial accepted changed startup-file source bytes'
fi
grep -F -q 'candidate runtime identity differs from the first denied runtime' \
    "$fixture/candidate-startup-file-drift.out"
printf '%s' 'APP_ENV=production' > "$fixture/candidate-startup.env"
touch "$fixture/candidate-secret-source-drift"
if "$CONTROLLER" prove-pool-denied > "$fixture/candidate-secret-source-drift.out" 2>&1; then
    fail 'candidate denial accepted a different mounted secret source'
fi
grep -F -q 'candidate runtime identity differs from the first denied runtime' \
    "$fixture/candidate-secret-source-drift.out"
rm "$fixture/candidate-secret-source-drift"
touch "$fixture/candidate-secret-writable"
if "$CONTROLLER" prove-pool-denied > "$fixture/candidate-secret-writable.out" 2>&1; then
    fail 'candidate denial accepted a writable mounted secret source'
fi
grep -F -q 'applied-ack mount must be a read-only regular non-symlink bind source' \
    "$fixture/candidate-secret-writable.out"
rm "$fixture/candidate-secret-writable"
"$CONTROLLER" prove-pool-denied \
    | grep -F 'CONTROL_PLANE_RUNTIME_FENCE candidate_denial=passed' >/dev/null
touch "$fixture/candidate-second-network"
if "$CONTROLLER" prove-pool-denied > "$fixture/network-drift.out" 2>&1; then
    fail 'candidate denial accepted an added network'
fi
grep -F -q 'candidate complete network inventory differs from the immutable plan' \
    "$fixture/network-drift.out"
rm "$fixture/candidate-second-network"
"$CONTROLLER" prove-pool-denied \
    | grep -F 'CONTROL_PLANE_RUNTIME_FENCE pool_denial=passed' >/dev/null
write_control_plane_ingress_pool_fixture \
    "$(sed -n 's/^candidate_runtime_sha256=//p' "$state")" \
    "$(sed -n 's/^web_a_network_sha256=//p' "$state")" \
    "$(sed -n 's/^web_a_bindings_sha256=//p' "$state")" \
    "$(sed -n 's/^web_b_runtime_sha256=//p' "$state")" \
    "$(sed -n 's/^web_b_network_sha256=//p' "$state")" \
    "$(sed -n 's/^web_b_bindings_sha256=//p' "$state")"
chgrp "$FIXTURE_STAT_GID" "$POOL_FIXTURE_INGRESS_POOL_MANIFEST"
"$CONTROLLER" pin-ingress-pool-manifest "$POOL_FIXTURE_INGRESS_POOL_SHA256" \
    | grep -F 'CONTROL_PLANE_RUNTIME_FENCE ingress_pool_pin=passed' >/dev/null
grep -F -x -q "ingress_pool_manifest_sha256=$POOL_FIXTURE_INGRESS_POOL_SHA256" "$state"
{
    printf 'version=2\noperation_id=runtime-fence-test\nmember_role=web-a\n'
    printf 'candidate_name=candidate\ncandidate_id=%s\n' "$POOL_FIXTURE_WEB_A_ID"
    printf 'candidate_image_id=%s\ncandidate_image_reference=%s\n' \
        "$POOL_FIXTURE_WEB_A_IMAGE_ID" "$POOL_FIXTURE_WEB_A_IMAGE_REFERENCE"
    printf 'restart_policy=always\n'
} > "$fixture/member-a-repin.intent"
{
    printf 'version=2\noperation_id=runtime-fence-test\nmember_role=web-b\n'
    printf 'candidate_name=candidate-b\ncandidate_id=%s\n' "$POOL_FIXTURE_WEB_B_ID"
    printf 'candidate_image_id=%s\ncandidate_image_reference=%s\n' \
        "$POOL_FIXTURE_WEB_B_IMAGE_ID" "$POOL_FIXTURE_WEB_B_IMAGE_REFERENCE"
    printf 'restart_policy=always\n'
} > "$fixture/member-b-repin.intent"
chmod 0600 "$fixture/member-a-repin.intent" "$fixture/member-b-repin.intent"
chgrp "$FIXTURE_STAT_GID" "$fixture/member-a-repin.intent" "$fixture/member-b-repin.intent"
touch "$fixture/candidate-restart-always" "$fixture/candidate-b-restart-always"
"$CONTROLLER" repin-pool "$POOL_FIXTURE_WEB_A_ID" "$POOL_FIXTURE_WEB_B_ID" \
    | grep -F 'CONTROL_PLANE_RUNTIME_FENCE pool_repin=passed' >/dev/null
CONTROL_PLANE_RUNTIME_RETIRED_ROLE=retired_a \
CONTROL_PLANE_RUNTIME_RETIRED_CONTAINER=legacy \
CONTROL_PLANE_RUNTIME_RETIRED_ID=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
CONTROL_PLANE_RUNTIME_NETWORK_INVENTORY="$inventory" \
CONTROL_PLANE_RUNTIME_PROC_ROOT=$fixture/proc \
CONTROL_PLANE_RUNTIME_SELF_SSH_TARGET=host.docker.internal \
    "$PRODUCTION_REAPER" \
    | grep -F -x 'version=2' >/dev/null
grep -F -q -- '--orig-src 172.18.0.0/16 --orig-dst 172.18.0.1 --dport 22' "$fixture/conntrack-delete.log"
grep -F -q -- '--orig-src fd00:18::/64 --orig-dst fd00:18::1 --dport 22' "$fixture/conntrack-delete.log"
rm "$fixture/nft.json"
"$CONTROLLER" restore \
    | grep -F -x 'CONTROL_PLANE_RUNTIME_FENCE restore=passed phase=active' >/dev/null
"$CONTROLLER" verify >/dev/null
[[ $(cat "$fixture/nft-handle") -ge 2 ]] || fail 'nft fixture did not recreate with volatile handles'
rm "$fixture/nft.json"
CONTROL_PLANE_RUNTIME_WATCH_ITERATIONS=1 "$CONTROLLER" watch
[[ -e $fixture/nft.json ]] || fail 'watchdog did not recreate a missing active fence'

printf 'different-invocation\n' > "$fixture/invocation-id"
if "$CONTROLLER" verify > "$fixture/mismatch.out" 2>&1; then
    fail 'same-boot dockerd InvocationID mismatch was accepted'
fi
grep -F -q 'same-boot runtime identity changed: dockerd_invocation_id' "$fixture/mismatch.out"
printf 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n' > "$fixture/invocation-id"

cp "$fixture/nft.json" "$fixture/nft.expected"
printf '%s\n' '{"nftables":[{"table":{"family":"inet","name":"coolify_control_plane_ssh_fence"}},{"chain":{"name":"tampered"}}]}' > "$fixture/nft.json"
if "$CONTROLLER" verify > "$fixture/nft-mismatch.out" 2>&1; then
    fail 'nft identity mismatch was accepted'
fi
grep -F -q 'managed nft table identity changed' "$fixture/nft-mismatch.out"
cp "$fixture/nft.expected" "$fixture/nft.json"
touch "$fixture/unrelated-nft-table"

if "$CONTROLLER" verify-post-revoke > "$fixture/legacy-present-post-revoke.out" 2>&1; then
    fail 'post-revoke verification accepted a present legacy container'
fi
grep -F -q 'retired container name or exact ID still exists after revocation' \
    "$fixture/legacy-present-post-revoke.out"
if "$CONTROLLER" release > "$fixture/legacy-present.out" 2>&1; then
    fail 'release accepted a present legacy container'
fi
grep -F -q 'retired container name or exact ID still exists' "$fixture/legacy-present.out"
touch "$fixture/legacy-absent"
touch "$fixture/candidate-coordination-mount-drift"
if "$CONTROLLER" release > "$fixture/coordination-mount-drift.out" 2>&1; then
    fail 'release accepted a writer without the exact planned coordination-volume mount'
fi
grep -F -q 'pool member live private or coordination volume mount differs from the immutable plan' \
    "$fixture/coordination-mount-drift.out" \
    || fail 'coordination-volume mount drift emitted an unexpected failure'
[[ -e $fixture/nft.json ]] || fail 'coordination-volume mount drift removed the fence'
rm -f -- "$fixture/candidate-coordination-mount-drift"
chmod 0640 "$fixture/coordination/mutation-freeze-epoch"
printf '%s' 'wrong-freeze-epoch' > "$fixture/coordination/mutation-freeze-epoch"
chmod 0440 "$fixture/coordination/mutation-freeze-epoch"
if "$CONTROLLER" release > "$fixture/mutation-freeze-epoch-drift.out" 2>&1; then
    fail 'release accepted a coordination-volume freeze marker from another operation'
fi
grep -F -q 'durable mutation-freeze marker differs from its operation epoch' \
    "$fixture/mutation-freeze-epoch-drift.out" \
    || fail 'coordination-volume freeze marker drift emitted an unexpected failure'
[[ -e $fixture/nft.json ]] || fail 'coordination-volume freeze marker drift removed the fence'
chmod 0640 "$fixture/coordination/mutation-freeze-epoch"
sed -n 's/^mutation_freeze_epoch=//p' "$POOL_FIXTURE_POOL_PLAN_MANIFEST" \
    | tr -d '\n' > "$fixture/coordination/mutation-freeze-epoch"
chmod 0440 "$fixture/coordination/mutation-freeze-epoch"
printf '1\n' > "$fixture/queue-probe-version"
if "$CONTROLLER" release > "$fixture/queue-version-drift.out" 2>&1; then
    fail 'release accepted a stale proxy queue probe protocol version'
fi
grep -F -q 'queue-probe output contains an unknown or malformed record' \
    "$fixture/queue-version-drift.out"
rm "$fixture/queue-probe-version"
touch "$fixture/queue-nonzero"
post_revoke_state=$fixture/state/runtime-fence-test/state
post_revoke_state_identity=$(file_identity "$post_revoke_state")
post_revoke_nft_identity=$(file_identity "$fixture/nft.json")
post_revoke_systemd_identity=$(systemd_fixture_identity)
for docker_api_scenario in absence-api-500 absence-daemon-unavailable \
    absence-permission-denied absence-timeout absence-malformed-success \
    absence-malformed-not-found list-failure malformed-list; do
    printf '%s\n' "$docker_api_scenario" > "$fixture/docker-api-scenario"
    if "$CONTROLLER" release > "$fixture/post-revoke-${docker_api_scenario}.out" 2>&1; then
        fail "release accepted unavailable legacy-absence evidence: $docker_api_scenario"
    fi
    assert_runtime_artifacts_unchanged "post-revoke $docker_api_scenario" \
        "$post_revoke_state" "$post_revoke_state_identity" "$post_revoke_nft_identity" \
        "$post_revoke_systemd_identity"
done
rm -f -- "$fixture/docker-api-scenario"
"$CONTROLLER" verify-post-revoke \
    | grep -F -x 'CONTROL_PLANE_RUNTIME_FENCE verify_post_revoke=passed operation_id=runtime-fence-test phase=active' >/dev/null
if "$CONTROLLER" release > "$fixture/nonzero-queue.out" 2>&1; then
    fail 'release accepted nonzero queued proxy work'
fi
grep -F -q 'queue-probe output contains an unknown or malformed record' "$fixture/nonzero-queue.out"
rm "$fixture/queue-nonzero"

if CONTROL_PLANE_RUNTIME_TEST_CRASH_AT=after-release-state "$CONTROLLER" release >/dev/null 2>&1; then
    fail 'release-state crash fixture unexpectedly completed'
fi
grep -F -x -q phase=release-in-progress "$fixture/state/runtime-fence-test/state"
[[ -e $fixture/nft.json ]] || fail 'pre-delete release crash removed the fence'
CONTROL_PLANE_RUNTIME_WATCH_ITERATIONS=1 "$CONTROLLER" watch
grep -F -x -q phase=active "$fixture/state/runtime-fence-test/state"

if CONTROL_PLANE_RUNTIME_TEST_CRASH_AT=after-nft-delete \
    "$CONTROLLER" release > "$fixture/release-post-delete-crash.out" 2>&1; then
    fail 'post-delete release crash fixture unexpectedly completed'
fi
if ! grep -F -x -q phase=release-in-progress "$fixture/state/runtime-fence-test/state"; then
    cat "$fixture/release-post-delete-crash.out" >&2
    fail 'post-delete release crash did not reach release-in-progress state'
fi
if [[ -e $fixture/nft.json ]]; then
    cat "$fixture/release-post-delete-crash.out" >&2
    fail 'post-delete crash did not exercise the absent-fence window'
fi
CONTROL_PLANE_RUNTIME_WATCH_ITERATIONS=1 "$CONTROLLER" watch
grep -F -x -q phase=active "$fixture/state/runtime-fence-test/state"
[[ -e $fixture/nft.json ]] || fail 'watchdog did not restore the fence after interrupted removal'

printf '4\n' > "$fixture/queue-nonzero-after-count"
rm -f -- "$fixture/queue-probe-count"
if "$CONTROLLER" release > "$fixture/final-queue-race.out" 2>&1; then
    fail 'release deleted the fence after work appeared at the final queue recheck'
fi
grep -F -q 'queue-probe output contains an unknown or malformed record' \
    "$fixture/final-queue-race.out" \
    || fail 'final queue recheck race emitted an unexpected failure'
grep -F -x -q phase=release-in-progress "$fixture/state/runtime-fence-test/state"
[[ -e $fixture/nft.json ]] || fail 'failed final queue recheck removed the fence'
CONTROL_PLANE_RUNTIME_WATCH_ITERATIONS=1 "$CONTROLLER" watch
grep -F -x -q phase=active "$fixture/state/runtime-fence-test/state"
rm -f -- "$fixture/queue-nonzero-after-count" "$fixture/queue-probe-count"

"$CONTROLLER" release \
    | grep -F -x 'CONTROL_PLANE_RUNTIME_FENCE release=passed operation_id=runtime-fence-test phase=released' >/dev/null
"$CONTROLLER" release \
    | grep -F -x 'CONTROL_PLANE_RUNTIME_FENCE release=passed operation_id=runtime-fence-test phase=released already_terminal=true' >/dev/null
"$CONTROLLER" status \
    | grep -F 'CONTROL_PLANE_RUNTIME_FENCE status=passed operation_id=runtime-fence-test phase=released' >/dev/null
[[ ! -e $fixture/nft.json ]] || fail 'managed nft table remained after authorized release'
grep -E -x -q 'release_queue_sha256=[a-f0-9]{64}' \
    "$fixture/state/runtime-fence-test/state" \
    || fail 'released state omitted initial queue evidence'
grep -E -x -q 'release_final_queue_sha256=[a-f0-9]{64}' \
    "$fixture/state/runtime-fence-test/state" \
    || fail 'released state omitted final pre-delete queue evidence'
[[ -e $fixture/unrelated-nft-table ]] || fail 'release changed unrelated nft state'
"$CONTROLLER" restore \
    | grep -F -x 'CONTROL_PLANE_RUNTIME_FENCE restore=passed phase=released fence=absent' >/dev/null

rm "$fixture/legacy-absent"
rm -rf "$fixture/state/runtime-fence-test"
rm -f -- "$fixture/candidate-restart-always" "$fixture/candidate-b-restart-always"
touch "$fixture/candidate-absent"
"$CONTROLLER" stage-capture >/dev/null
if CONTROL_PLANE_RUNTIME_TEST_CRASH_AT=after-nft-apply \
    "$CONTROLLER" capture >/dev/null 2>&1; then
    fail 'post-nft-apply crash fixture unexpectedly completed'
fi
grep -F -x -q phase=preparing "$fixture/state/runtime-fence-test/state"
[[ -e $fixture/nft.json ]] || fail 'post-nft-apply crash did not install the exact fence'
"$CONTROLLER" capture >/dev/null
grep -F -x -q candidate_runtime_sha256=none "$fixture/state/runtime-fence-test/state"
rm "$fixture/candidate-absent"
"$CONTROLLER" prove-pool-denied >/dev/null
if grep -F -x -q candidate_runtime_sha256=none "$fixture/state/runtime-fence-test/state"; then
    fail 'reverse abort generation did not pin the first denied candidate runtime'
fi
touch "$fixture/candidate-absent"
if CONTROL_PLANE_RUNTIME_TEST_CRASH_AT=after-abort-state "$CONTROLLER" abort >/dev/null 2>&1; then
    fail 'abort-state crash fixture unexpectedly completed'
fi
grep -F -x -q phase=abort-in-progress "$fixture/state/runtime-fence-test/state"
CONTROL_PLANE_RUNTIME_WATCH_ITERATIONS=1 "$CONTROLLER" watch
grep -F -x -q phase=active "$fixture/state/runtime-fence-test/state"
if CONTROL_PLANE_RUNTIME_TEST_CRASH_AT=after-abort-nft-delete \
    "$CONTROLLER" abort > "$fixture/abort-post-delete-crash.out" 2>&1; then
    fail 'post-delete abort crash fixture unexpectedly completed'
fi
if ! grep -F -x -q phase=abort-in-progress "$fixture/state/runtime-fence-test/state"; then
    cat "$fixture/abort-post-delete-crash.out" >&2
    fail 'post-delete abort crash did not reach abort-in-progress state'
fi
if [[ -e $fixture/nft.json ]]; then
    cat "$fixture/abort-post-delete-crash.out" >&2
    fail 'post-delete abort crash did not remove the fence'
fi
CONTROL_PLANE_RUNTIME_WATCH_ITERATIONS=1 "$CONTROLLER" watch
[[ -e $fixture/nft.json ]] || fail 'watchdog did not restore the interrupted abort fence'
"$CONTROLLER" abort \
    | grep -F -x 'CONTROL_PLANE_RUNTIME_FENCE abort=passed operation_id=runtime-fence-test phase=aborted' >/dev/null
"$CONTROLLER" abort \
    | grep -F -x 'CONTROL_PLANE_RUNTIME_FENCE abort=passed operation_id=runtime-fence-test phase=aborted already_terminal=true' >/dev/null
"$CONTROLLER" status \
    | grep -F 'CONTROL_PLANE_RUNTIME_FENCE status=passed operation_id=runtime-fence-test phase=aborted' >/dev/null
"$CONTROLLER" restore \
    | grep -F -x 'CONTROL_PLANE_RUNTIME_FENCE restore=passed phase=aborted fence=absent' >/dev/null
rm -rf "$fixture/state/runtime-fence-test"
rm -f "$fixture/legacy-absent" "$fixture/legacy-replacement"
touch "$fixture/candidate-absent"
"$CONTROLLER" stage-capture >/dev/null
"$CONTROLLER" capture >/dev/null
grep -F -x -q candidate_runtime_sha256=none "$fixture/state/runtime-fence-test/state"
touch "$fixture/legacy-replacement"
"$CONTROLLER" adopt-rollback-incumbent legacy \
    | grep -F 'CONTROL_PLANE_RUNTIME_FENCE rollback_incumbent_adoption=passed operation_id=runtime-fence-test incumbent_id=ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff' >/dev/null
grep -F -x -q 'legacy_id=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' \
    "$fixture/state/runtime-fence-test/state"
grep -F -x -q 'rollback_incumbent_id=ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff' \
    "$fixture/state/runtime-fence-test/state"
"$CONTROLLER" adopt-rollback-incumbent legacy \
    | grep -F -x 'CONTROL_PLANE_RUNTIME_FENCE rollback_incumbent_adoption=passed operation_id=runtime-fence-test already_adopted=true' >/dev/null
"$CONTROLLER" abort \
    | grep -F -x 'CONTROL_PLANE_RUNTIME_FENCE abort=passed operation_id=runtime-fence-test phase=aborted' >/dev/null
[[ ! -e $fixture/nft.json ]] || fail 'adopted rollback incumbent abort left the nft fence installed'
rm -rf "$fixture/state/runtime-fence-test"
rm -f "$fixture/legacy-replacement"
"$CONTROLLER" stage-capture >/dev/null
"$CONTROLLER" capture >/dev/null
grep -F -x -q candidate_runtime_sha256=none "$fixture/state/runtime-fence-test/state"
printf '2222\n' > "$fixture/dockerd-pid"
printf 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\n' > "$fixture/invocation-id"
if "$CONTROLLER" verify > "$fixture/recovery-required.out" 2>&1; then
    fail 'ordinary verification accepted a changed dockerd lifecycle before recovery-abort'
fi
grep -F -q 'same-boot runtime identity changed: dockerd_pid' "$fixture/recovery-required.out"
if CONTROL_PLANE_RUNTIME_TEST_CRASH_AT=after-recovery-abort-state \
    "$CONTROLLER" recover-abort >/dev/null 2>&1; then
    fail 'recovery-abort state crash fixture unexpectedly completed'
fi
grep -F -x -q phase=recovery-abort-in-progress "$fixture/state/runtime-fence-test/state"
grep -F -x -q dockerd_pid=1111 "$fixture/state/runtime-fence-test/state"
grep -F -x -q recovery_dockerd_pid=2222 "$fixture/state/runtime-fence-test/state"
CONTROL_PLANE_RUNTIME_WATCH_ITERATIONS=1 "$CONTROLLER" watch
grep -F -x -q phase=recovery-abort-active "$fixture/state/runtime-fence-test/state"
if CONTROL_PLANE_RUNTIME_TEST_CRASH_AT=after-recovery-abort-nft-delete \
    "$CONTROLLER" recover-abort >/dev/null 2>&1; then
    fail 'recovery-abort nft deletion crash fixture unexpectedly completed'
fi
[[ ! -e $fixture/nft.json ]] || fail 'recovery-abort deletion crash left the fence present'
CONTROL_PLANE_RUNTIME_WATCH_ITERATIONS=1 "$CONTROLLER" watch
[[ -e $fixture/nft.json ]] || fail 'watchdog did not restore the interrupted recovery-abort fence'
grep -F -x -q phase=recovery-abort-active "$fixture/state/runtime-fence-test/state"
"$CONTROLLER" recover-abort \
    | grep -F -x 'CONTROL_PLANE_RUNTIME_FENCE recover_abort=passed operation_id=runtime-fence-test phase=recovery-aborted' >/dev/null
"$CONTROLLER" recover-abort \
    | grep -F -x 'CONTROL_PLANE_RUNTIME_FENCE recover_abort=passed operation_id=runtime-fence-test phase=recovery-aborted already_terminal=true' >/dev/null
[[ ! -e $fixture/nft.json ]] || fail 'terminal recovery-abort left the nft fence installed'
printf '1111\n' > "$fixture/dockerd-pid"
printf 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n' > "$fixture/invocation-id"
rm -rf "$fixture/state/runtime-fence-test"
if CONTROL_PLANE_RUNTIME_TEST_CRASH_AT=after-preparing-state \
    "$CONTROLLER" stage-capture >/dev/null 2>&1; then
    fail 'pre-candidate abort fixture unexpectedly completed capture'
fi
"$CONTROLLER" abort \
    | grep -F -x 'CONTROL_PLANE_RUNTIME_FENCE abort=passed operation_id=runtime-fence-test phase=aborted pre_candidate=true' >/dev/null
[[ ! -e $fixture/nft.json ]] || fail 'pre-candidate abort left the nft fence installed'
touch "$fixture/legacy-absent"

grep -F -x -q 'Before=network-pre.target docker.service docker.socket' "$SERVICE"
grep -F -x -q 'RequiredBy=docker.service docker.socket' "$SERVICE"
grep -F -x -q 'PartOf=docker.service docker.socket' "$SERVICE"
grep -F -x -q 'BindsTo=docker.service' "$WATCHDOG_SERVICE"
grep -F -x -q 'WantedBy=docker.service' "$WATCHDOG_SERVICE"
grep -F -x -q 'Restart=always' "$WATCHDOG_SERVICE"
grep -F -q 'install_file_atomically runtime-fence-controlmaster-reaper' "$INSTALLER"
# The assertion intentionally matches literal installer source.
# shellcheck disable=SC2016
grep -F -q '"$SCRIPT_DIRECTORY/self-ssh-controlmaster-reaper.sh" "$REAPER_DESTINATION" 0700 1' \
    "$INSTALLER"
grep -F -q 'coolify-traefik-provider-freshness-probe' "$INSTALLER"
grep -F -q 'coolify-proxy-queue-zero-probe' "$INSTALLER"
grep -F -q 'coolify-control-plane-terminal-state-probe' "$INSTALLER"
# The assertion intentionally matches literal installer source.
# shellcheck disable=SC2016
if grep -F -q 'systemctl enable "$SERVICE_NAME"' "$INSTALLER"; then
    fail 'unarmed installer enables the Docker-blocking restore service'
fi
# These assertions intentionally match literal provisioner source.
# shellcheck disable=SC2016
grep -F -q 'run_prepared stage-capture >/dev/null' "$PROVISIONER"
# shellcheck disable=SC2016
grep -F -q 'install_environment_atomically "$candidate"' "$PROVISIONER"
grep -F -q 'assert_boot_restore_dependencies' "$PROVISIONER"
# shellcheck disable=SC2016
grep -F -q 'systemctl start "$RESTORE_SERVICE"' "$PROVISIONER"
# shellcheck disable=SC2016
grep -F -q 'systemctl start "$WATCHDOG_SERVICE"' "$PROVISIONER"
grep -F -q 'CONTROL_PLANE_RUNTIME_ARMED=0' "$PROVISIONER"
grep -F -q 'CONTROL_PLANE_RUNTIME_ARMED=1' "$PROVISIONER"
if grep -E -q 'systemctl (restart|start) (docker\.service|docker\.socket)' "$PROVISIONER"; then
    fail 'provisioner restarts or starts Docker while arming the runtime fence'
fi
if grep -E -q 'flush ruleset|flush table|delete table (ip|ip6|inet) [^c]' "$CONTROLLER"; then
    fail 'controller contains a broad nft flush or delete'
fi

rm -f "$fixture/legacy-absent" "$fixture/candidate-absent"
CONTROL_PLANE_RUNTIME_PROVIDER_API_URL=http://127.0.0.1:8080/api/rawdata \
CONTROL_PLANE_RUNTIME_PROVIDER_ROUTER=coolify@docker \
CONTROL_PLANE_RUNTIME_PROVIDER_SERVICE=coolify@docker \
CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT=8080 \
CONTROL_PLANE_RUNTIME_OPERATION_ID=runtime-fence-test \
CONTROL_PLANE_RUNTIME_PROXY_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
CONTROL_PLANE_RUNTIME_PROVIDER_EXPECTATION=incumbent \
CONTROL_PLANE_RUNTIME_RETIRED_MEMBER_COUNT=2 \
CONTROL_PLANE_RUNTIME_RETIRED_A_NAME=legacy \
CONTROL_PLANE_RUNTIME_RETIRED_A_ID=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
CONTROL_PLANE_RUNTIME_RETIRED_B_NAME=candidate-b \
CONTROL_PLANE_RUNTIME_RETIRED_B_ID=9999999999999999999999999999999999999999999999999999999999999999 \
    "$PRODUCTION_PROVIDER_PROBE" > "$fixture/provider-present.out"
grep -F -x -q status=fresh "$fixture/provider-present.out"
grep -F -x -q version=2 "$fixture/provider-present.out"
grep -F -x -q retired_member_count=2 "$fixture/provider-present.out"
grep -F -x -q retired_b_name=candidate-b "$fixture/provider-present.out"
CONTROL_PLANE_RUNTIME_PROVIDER_API_URL='http://[::1]:8080/api/rawdata?token=a=b' \
CONTROL_PLANE_RUNTIME_PROVIDER_ROUTER=coolify@docker \
CONTROL_PLANE_RUNTIME_PROVIDER_SERVICE=coolify@docker \
CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT=8080 \
CONTROL_PLANE_RUNTIME_OPERATION_ID=runtime-fence-test \
CONTROL_PLANE_RUNTIME_PROXY_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
CONTROL_PLANE_RUNTIME_PROVIDER_EXPECTATION=incumbent \
CONTROL_PLANE_RUNTIME_RETIRED_MEMBER_COUNT=2 \
CONTROL_PLANE_RUNTIME_RETIRED_A_NAME=legacy \
CONTROL_PLANE_RUNTIME_RETIRED_A_ID=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
CONTROL_PLANE_RUNTIME_RETIRED_B_NAME=candidate-b \
CONTROL_PLANE_RUNTIME_RETIRED_B_ID=9999999999999999999999999999999999999999999999999999999999999999 \
    "$PRODUCTION_PROVIDER_PROBE" > "$fixture/provider-ipv6-query.out"
grep -F -x -q status=fresh "$fixture/provider-ipv6-query.out"
touch "$fixture/provider-a-dual-stack-missing-b"
if CONTROL_PLANE_RUNTIME_PROVIDER_API_URL=http://127.0.0.1:8080/api/rawdata \
    CONTROL_PLANE_RUNTIME_PROVIDER_ROUTER=coolify@docker \
    CONTROL_PLANE_RUNTIME_PROVIDER_SERVICE=coolify@docker \
    CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT=8080 \
    CONTROL_PLANE_RUNTIME_OPERATION_ID=runtime-fence-test \
    CONTROL_PLANE_RUNTIME_PROXY_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
    CONTROL_PLANE_RUNTIME_PROVIDER_EXPECTATION=incumbent \
    CONTROL_PLANE_RUNTIME_RETIRED_MEMBER_COUNT=2 \
    CONTROL_PLANE_RUNTIME_RETIRED_A_NAME=legacy \
    CONTROL_PLANE_RUNTIME_RETIRED_A_ID=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
    CONTROL_PLANE_RUNTIME_RETIRED_B_NAME=candidate-b \
    CONTROL_PLANE_RUNTIME_RETIRED_B_ID=9999999999999999999999999999999999999999999999999999999999999999 \
        "$PRODUCTION_PROVIDER_PROBE" > "$fixture/provider-a-dual-stack-missing-b.out" 2>&1; then
    fail 'provider probe let retired A dual-stack backends mask a missing retired B assignment'
fi
grep -F -q 'does not bind the exact retired pool to the captured Docker backend set' \
    "$fixture/provider-a-dual-stack-missing-b.out"
rm -f "$fixture/provider-a-dual-stack-missing-b"
touch "$fixture/provider-dual-stack-chosen-members"
CONTROL_PLANE_RUNTIME_PROVIDER_API_URL=http://127.0.0.1:8080/api/rawdata \
CONTROL_PLANE_RUNTIME_PROVIDER_ROUTER=coolify@docker \
CONTROL_PLANE_RUNTIME_PROVIDER_SERVICE=coolify@docker \
CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT=8080 \
CONTROL_PLANE_RUNTIME_OPERATION_ID=runtime-fence-test \
CONTROL_PLANE_RUNTIME_PROXY_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
CONTROL_PLANE_RUNTIME_PROVIDER_EXPECTATION=incumbent \
CONTROL_PLANE_RUNTIME_RETIRED_MEMBER_COUNT=2 \
CONTROL_PLANE_RUNTIME_RETIRED_A_NAME=legacy \
CONTROL_PLANE_RUNTIME_RETIRED_A_ID=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
CONTROL_PLANE_RUNTIME_RETIRED_B_NAME=candidate-b \
CONTROL_PLANE_RUNTIME_RETIRED_B_ID=9999999999999999999999999999999999999999999999999999999999999999 \
    "$PRODUCTION_PROVIDER_PROBE" > "$fixture/provider-dual-stack-chosen-members.out"
grep -F -x -q 'retired_a_backend_url=http://[fd00:18::3]:8080' \
    "$fixture/provider-dual-stack-chosen-members.out"
grep -F -x -q 'retired_b_backend_url=http://172.18.0.5:8080' \
    "$fixture/provider-dual-stack-chosen-members.out"
rm -f "$fixture/provider-dual-stack-chosen-members"
touch "$fixture/provider-wrong-backend"
if CONTROL_PLANE_RUNTIME_PROVIDER_API_URL=http://127.0.0.1:8080/api/rawdata \
    CONTROL_PLANE_RUNTIME_PROVIDER_ROUTER=coolify@docker \
    CONTROL_PLANE_RUNTIME_PROVIDER_SERVICE=coolify@docker \
    CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT=8080 \
    CONTROL_PLANE_RUNTIME_OPERATION_ID=runtime-fence-test \
    CONTROL_PLANE_RUNTIME_PROXY_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
    CONTROL_PLANE_RUNTIME_PROVIDER_EXPECTATION=incumbent \
    CONTROL_PLANE_RUNTIME_RETIRED_MEMBER_COUNT=2 \
    CONTROL_PLANE_RUNTIME_RETIRED_A_NAME=legacy \
    CONTROL_PLANE_RUNTIME_RETIRED_A_ID=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
    CONTROL_PLANE_RUNTIME_RETIRED_B_NAME=candidate-b \
    CONTROL_PLANE_RUNTIME_RETIRED_B_ID=9999999999999999999999999999999999999999999999999999999999999999 \
        "$PRODUCTION_PROVIDER_PROBE" > "$fixture/provider-wrong-backend.out" 2>&1; then
    fail 'provider probe accepted a backend outside the exact captured legacy container'
fi
grep -F -q 'does not bind the exact retired pool to the captured Docker backend set' \
    "$fixture/provider-wrong-backend.out"
rm "$fixture/provider-wrong-backend"
for unsafe_provider_url in \
    'http://127.0.0.1:8080@attacker.example/api/rawdata' \
    'http://[::1]:8080@attacker.example/api/rawdata' \
    'http://127.0.0.1.attacker.example:8080/api/rawdata' \
    'http://127.0.0.1:65536/api/rawdata' \
    'http://127.0.0.1:8080/api/%zz' \
    'http://127.0.0.1:8080/api/rawdata#@attacker.example'; do
    if CONTROL_PLANE_RUNTIME_PROVIDER_API_URL="$unsafe_provider_url" \
        CONTROL_PLANE_RUNTIME_PROVIDER_ROUTER=coolify@docker \
        CONTROL_PLANE_RUNTIME_PROVIDER_SERVICE=coolify@docker \
        CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT=8080 \
        CONTROL_PLANE_RUNTIME_OPERATION_ID=runtime-fence-test \
        CONTROL_PLANE_RUNTIME_PROXY_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
        CONTROL_PLANE_RUNTIME_PROVIDER_EXPECTATION=incumbent \
        CONTROL_PLANE_RUNTIME_RETIRED_MEMBER_COUNT=2 \
        CONTROL_PLANE_RUNTIME_RETIRED_A_NAME=legacy \
        CONTROL_PLANE_RUNTIME_RETIRED_A_ID=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
        CONTROL_PLANE_RUNTIME_RETIRED_B_NAME=candidate-b \
        CONTROL_PLANE_RUNTIME_RETIRED_B_ID=9999999999999999999999999999999999999999999999999999999999999999 \
            "$PRODUCTION_PROVIDER_PROBE" > "$fixture/provider-unsafe-url.out" 2>&1; then
        fail "provider probe accepted an unsafe provider URL: $unsafe_provider_url"
    fi
    grep -F -q 'provider API URL must use exact loopback HTTP authority and a valid port/path' \
        "$fixture/provider-unsafe-url.out"
done
touch "$fixture/legacy-absent"
CONTROL_PLANE_RUNTIME_PROVIDER_API_URL=http://127.0.0.1:8080/api/rawdata \
CONTROL_PLANE_RUNTIME_PROVIDER_ROUTER=coolify@docker \
CONTROL_PLANE_RUNTIME_PROVIDER_SERVICE=coolify@docker \
CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT=8080 \
CONTROL_PLANE_RUNTIME_OPERATION_ID=runtime-fence-test \
CONTROL_PLANE_RUNTIME_PROXY_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
CONTROL_PLANE_RUNTIME_PROVIDER_EXPECTATION=absent \
CONTROL_PLANE_RUNTIME_RETIRED_MEMBER_COUNT=1 \
CONTROL_PLANE_RUNTIME_RETIRED_A_NAME=legacy \
CONTROL_PLANE_RUNTIME_RETIRED_A_ID=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
CONTROL_PLANE_RUNTIME_RETIRED_B_NAME=absent \
CONTROL_PLANE_RUNTIME_RETIRED_B_ID=absent \
    "$PRODUCTION_PROVIDER_PROBE" > "$fixture/provider-absent.out"
grep -F -x -q status=fresh "$fixture/provider-absent.out"
kill "$socket_pid" 2>/dev/null || true
wait "$socket_pid" 2>/dev/null || true
printf 'CONTROL_PLANE_RUNTIME_FENCE_TEST PASS\n'
