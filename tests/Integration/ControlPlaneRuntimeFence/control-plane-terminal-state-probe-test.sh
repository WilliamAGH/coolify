#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

TEST_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
readonly TEST_DIRECTORY
REPOSITORY_ROOT=$(cd -- "$TEST_DIRECTORY/../../.." && pwd -P)
readonly REPOSITORY_ROOT
readonly PROBE=$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/control-plane-terminal-state-probe.sh
readonly CONTROLLER_SOURCE=$REPOSITORY_ROOT/docker/control-plane-blue-green/controllers/runtime-attestation-ssh-fence.sh
readonly FIXTURE_COMMAND=$TEST_DIRECTORY/fixture-command.sh
readonly POOL_FIXTURE=$TEST_DIRECTORY/pool-manifest-fixture.bash
readonly QUEUE_PROBE_SOURCE=$TEST_DIRECTORY/queue-probe.sh
readonly WEB_A_ID=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
readonly WEB_B_ID=9999999999999999999999999999999999999999999999999999999999999999

fail()
{
    printf 'CONTROL_PLANE_TERMINAL_PROBE_TEST_FAILURE %s\n' "$1" >&2
    exit 1
}

fixture=$(readlink -f -- "$(mktemp -d /tmp/control-plane-terminal-probe.XXXXXX)")
socket=$fixture/docker.sock
socket_pid=
trap '[[ -z $socket_pid ]] || kill "$socket_pid" 2>/dev/null || true; rm -rf "$fixture"' \
    EXIT HUP INT TERM
fixture_uid=$(id -u)
fixture_gid=$(id -g)
chgrp "$fixture_gid" "$fixture"
mkdir -p "$fixture/bin"
ln -s "$FIXTURE_COMMAND" "$fixture/bin/curl"
ln -s "$FIXTURE_COMMAND" "$fixture/bin/docker"
ln -s "$FIXTURE_COMMAND" "$fixture/bin/stat"
fixture_real_stat=$(command -v gstat || command -v stat)
cp "$CONTROLLER_SOURCE" "$fixture/runtime-controller"
cp "$QUEUE_PROBE_SOURCE" "$fixture/queue-probe"
chmod 0700 "$fixture/runtime-controller" "$fixture/queue-probe"
printf '%s' APP_ENV=production > "$fixture/candidate-startup.env"
printf '%s' APP_ENV=production > "$fixture/candidate-b-startup.env"
printf '%s' candidate-direct-token > "$fixture/candidate-direct-token"
printf '%s' candidate-b-direct-token > "$fixture/candidate-b-direct-token"
printf '%s' candidate-applied-ack > "$fixture/candidate-applied-ack"
printf '%s' candidate-b-applied-ack > "$fixture/candidate-b-applied-ack"
printf '%s' rogue-secret-source > "$fixture/rogue-secret-source"
touch "$fixture/legacy-absent"

python3 - "$socket" <<'PY' &
import socket
import sys
import time
s = socket.socket(socket.AF_UNIX)
s.bind(sys.argv[1])
time.sleep(60)
PY
socket_pid=$!
for _ in {1..20}; do
    [[ -S $socket ]] && break
    sleep 0.1
done
[[ -S $socket ]] || fail 'fixture Docker socket did not start'

# shellcheck source=tests/Integration/ControlPlaneRuntimeFence/pool-manifest-fixture.bash
source "$POOL_FIXTURE"
prepare_control_plane_pool_plan_fixture "$fixture" terminal-probe-test "$fixture_uid" "$fixture_gid"
chgrp -R "$fixture_gid" "$fixture"

container_runtime_sha256()
{
    PATH="$fixture/bin:$PATH" \
    FIXTURE_ROOT="$fixture" \
    FIXTURE_POOL_LABEL_VALUE=terminal-probe-test \
    FIXTURE_STAT_UID="$fixture_uid" \
    FIXTURE_STAT_GID="$fixture_gid" \
    FIXTURE_REAL_STAT="$fixture_real_stat" \
    CONTROL_PLANE_RUNTIME_TEST_MODE=1 \
    CONTROL_PLANE_RUNTIME_DOCKER_SOCKET="$socket" \
        "$fixture/runtime-controller" container-runtime-sha256 "$1"
}

fixture_container_json()
{
    local container=$1 output=$2 status
    status=$(PATH="$fixture/bin:$PATH" FIXTURE_ROOT="$fixture" \
        FIXTURE_POOL_LABEL_VALUE=terminal-probe-test \
        curl --silent --show-error --max-time 5 --unix-socket "$socket" \
            --output "$output" --write-out '%{http_code}' \
            "http://localhost/containers/$container/json")
    [[ $status == 200 ]] || fail "fixture container is unavailable: $container"
}

fixture_container_json candidate "$fixture/candidate.json"
fixture_container_json candidate-b "$fixture/candidate-b.json"
web_a_network_sha256=$(jq -S -c '[.NetworkSettings.Networks | to_entries[] | {
    name: .key, network_id: .value.NetworkID,
    endpoint_id: (.value.EndpointID // ""), ipv4_address: (.value.IPAddress // ""),
    ipv4_gateway: (.value.Gateway // ""),
    ipv6_address: (.value.GlobalIPv6Address // ""),
    ipv6_gateway: (.value.IPv6Gateway // ""), mac_address: (.value.MacAddress // "")
}] | sort_by(.network_id, .name)' "$fixture/candidate.json" \
    | sha256sum | awk '{print $1}')
web_b_network_sha256=$(jq -S -c '[.NetworkSettings.Networks | to_entries[] | {
    name: .key, network_id: .value.NetworkID,
    endpoint_id: (.value.EndpointID // ""), ipv4_address: (.value.IPAddress // ""),
    ipv4_gateway: (.value.Gateway // ""),
    ipv6_address: (.value.GlobalIPv6Address // ""),
    ipv6_gateway: (.value.IPv6Gateway // ""), mac_address: (.value.MacAddress // "")
}] | sort_by(.network_id, .name)' "$fixture/candidate-b.json" \
    | sha256sum | awk '{print $1}')
web_a_bindings_sha256=$(jq -S -c '{configured_bindings: (.HostConfig.PortBindings // {}),
    published_bindings: .NetworkSettings.Ports}' "$fixture/candidate.json" \
    | sha256sum | awk '{print $1}')
web_b_bindings_sha256=$(jq -S -c '{configured_bindings: (.HostConfig.PortBindings // {}),
    published_bindings: .NetworkSettings.Ports}' "$fixture/candidate-b.json" \
    | sha256sum | awk '{print $1}')
web_a_runtime_sha256=$(container_runtime_sha256 candidate)
web_b_runtime_sha256=$(container_runtime_sha256 candidate-b)
write_control_plane_ingress_pool_fixture \
    "$web_a_runtime_sha256" \
    "$web_a_network_sha256" "$web_a_bindings_sha256" \
    "$web_b_runtime_sha256" \
    "$web_b_network_sha256" "$web_b_bindings_sha256"
chgrp -R "$fixture_gid" "$fixture"

# shellcheck disable=SC2016
{
    printf '%s\n' '#!/usr/bin/env bash'
    printf '%s\n' 'set -euo pipefail'
    printf '%s\n' 'member_set_sha256=474801d4d2b1a83dbca09fc04e21eb201f84bb557cdd5753f3217b30c81450fe'
    printf '%s\n' 'retired_a_backend_url=absent'
    printf '%s\n' 'retired_b_backend_url=absent'
    printf '%s\n' 'backend_set_sha256=$({ printf "%s\0" retired_a "$retired_a_backend_url"; printf "%s\0" retired_b "$retired_b_backend_url"; } | sha256sum | awk '\''{print $1}'\'')'
    printf '%s\n' 'printf "version=2\nstatus=fresh\nobserved_at_epoch=%s\noperation_id=%s\nproxy_id=%064d\nproxy_pid=1111\n" "$(date -u +%s)" "$CONTROL_PLANE_RUNTIME_OPERATION_ID" 0'
    printf '%s\n' 'printf "expectation=%s\nretired_member_count=%s\nretired_a_name=%s\nretired_a_id=%s\n" "$CONTROL_PLANE_RUNTIME_PROVIDER_EXPECTATION" "$CONTROL_PLANE_RUNTIME_RETIRED_MEMBER_COUNT" "$CONTROL_PLANE_RUNTIME_RETIRED_A_NAME" "$CONTROL_PLANE_RUNTIME_RETIRED_A_ID"'
    printf '%s\n' 'printf "retired_b_name=%s\nretired_b_id=%s\nretired_a_backend_url=%s\nretired_b_backend_url=%s\n" "$CONTROL_PLANE_RUNTIME_RETIRED_B_NAME" "$CONTROL_PLANE_RUNTIME_RETIRED_B_ID" "$retired_a_backend_url" "$retired_b_backend_url"'
    printf '%s\n' 'printf "member_set_sha256=%s\nbackend_set_sha256=%s\nsnapshot_sha256=%064d\n" "$member_set_sha256" "$backend_set_sha256" 0'
} > "$fixture/provider-probe"
chmod 0700 "$fixture/provider-probe"

run_probe()
{
    local scenario=$1 output=$2
    local port8000_url=${TERMINAL_TEST_PORT8000_URL:-http://127.0.0.1:8000/health}
    if [[ $scenario == healthy ]]; then
        rm -f -- "$fixture/docker-api-scenario"
    else
        printf '%s\n' "$scenario" > "$fixture/docker-api-scenario"
    fi
    PATH="$fixture/bin:$PATH" \
    FIXTURE_ROOT="$fixture" \
    FIXTURE_POOL_LABEL_VALUE=terminal-probe-test \
    FIXTURE_POOL_ACK=pool-ack-token-0001 \
    FIXTURE_STAT_UID="$fixture_uid" \
    FIXTURE_STAT_GID="$fixture_gid" \
    FIXTURE_REAL_STAT="$fixture_real_stat" \
    CONTROL_PLANE_TERMINAL_TEST_MODE=1 \
    CONTROL_PLANE_RUNTIME_OPERATION_ID=terminal-probe-test \
    CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST="$POOL_FIXTURE_POOL_PLAN_MANIFEST" \
    CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256="$POOL_FIXTURE_POOL_PLAN_SHA256" \
    CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_METADATA="$(pool_fixture_metadata "$POOL_FIXTURE_POOL_PLAN_MANIFEST")" \
    CONTROL_PLANE_RUNTIME_CONTROLLER_PATH="$fixture/runtime-controller" \
    CONTROL_PLANE_RUNTIME_CONTROLLER_SHA256="$(pool_fixture_sha256 "$fixture/runtime-controller")" \
    CONTROL_PLANE_RUNTIME_QUEUE_PROBE="$fixture/queue-probe" \
    CONTROL_PLANE_RUNTIME_QUEUE_PROBE_SHA256="$(pool_fixture_sha256 "$fixture/queue-probe")" \
    CONTROL_PLANE_RUNTIME_SEMANTIC_CONFIG_SHA256=eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee \
    CONTROL_PLANE_RUNTIME_PROBE_MAX_AGE_SECONDS=30 \
    CONTROL_PLANE_INGRESS_POOL_MANIFEST="$POOL_FIXTURE_INGRESS_POOL_MANIFEST" \
    CONTROL_PLANE_INGRESS_POOL_MANIFEST_SHA256="$POOL_FIXTURE_INGRESS_POOL_SHA256" \
    CONTROL_PLANE_INGRESS_POOL_MANIFEST_METADATA="$(pool_fixture_metadata "$POOL_FIXTURE_INGRESS_POOL_MANIFEST")" \
    CONTROL_PLANE_RUNTIME_DOCKER_SOCKET="$socket" \
    CONTROL_PLANE_RUNTIME_TERMINAL_HTTPS_URL=https://coolify.example/health \
    CONTROL_PLANE_RUNTIME_TERMINAL_PORT8000_URL="$port8000_url" \
    CONTROL_PLANE_RUNTIME_PROVIDER_PROBE="$fixture/provider-probe" \
        "$PROBE" > "$output" 2>&1
}

if ! run_probe healthy "$fixture/healthy.out"; then
    sed 's/^/terminal-probe: /' "$fixture/healthy.out" >&2
    fail 'validated two-member terminal state failed'
fi
grep -F -x -q terminal_state=passed "$fixture/healthy.out" \
    || fail 'validated two-member terminal state did not pass'
grep -F -x -q "active_web_a_id=$WEB_A_ID" "$fixture/healthy.out" \
    || fail 'terminal probe did not preserve web-a identity'
grep -F -x -q "active_web_b_id=$WEB_B_ID" "$fixture/healthy.out" \
    || fail 'terminal probe did not preserve web-b identity'
grep -F -x -q "pool_manifest_sha256=$POOL_FIXTURE_INGRESS_POOL_SHA256" \
    "$fixture/healthy.out" || fail 'terminal probe did not preserve ingress manifest identity'
[[ $(wc -l < "$fixture/healthy.out") -eq 15 ]] \
    && fail 'terminal probe retained the obsolete v2 record count'
[[ $(wc -l < "$fixture/healthy.out") -eq 25 ]] \
    || fail 'terminal probe emitted a non-canonical v3 record count'
grep -F -x -q version=3 "$fixture/healthy.out" \
    || fail 'terminal probe did not emit protocol v3'
grep -F -x -q queue_zero=passed "$fixture/healthy.out" \
    || fail 'terminal probe omitted self-contained queue-zero evidence'
grep -F -x -q queue_pending=0 "$fixture/healthy.out" \
    || fail 'terminal probe omitted exact queue counts'

expect_runtime_drift()
{
    local label=$1 marker=$2
    printf 'CONTROL_PLANE_TERMINAL_PROBE_TEST phase=runtime-drift label=%s\n' "$label"
    touch "$fixture/$marker"
    if run_probe healthy "$fixture/runtime-$label.out"; then
        fail "terminal probe accepted live runtime-v4 drift: $label"
    fi
    grep -F -q 'active web-a member differs from its exact ingress identity' \
        "$fixture/runtime-$label.out" \
        || fail "terminal runtime-v4 drift emitted an unexpected failure: $label"
    rm -f -- "$fixture/$marker"
}

expect_runtime_drift environment candidate-wrong-control-plane-env
expect_runtime_drift mount-source candidate-secret-source-drift
expect_runtime_drift volume candidate-volume-drift

printf '%s\n' 'CONTROL_PLANE_TERMINAL_PROBE_TEST phase=runtime-secret-attestation'
printf '%s' 'APP_ENV=drifted' > "$fixture/candidate-startup.env"
if run_probe healthy "$fixture/runtime-secret-bytes.out"; then
    fail 'terminal probe accepted mounted startup secret byte drift'
fi
grep -F -q 'active web-a member differs from its exact ingress identity' \
    "$fixture/runtime-secret-bytes.out" || fail 'startup secret byte drift was not runtime-bound'
printf '%s' 'APP_ENV=production' > "$fixture/candidate-startup.env"

chmod 0640 "$fixture/candidate-startup.env"
if run_probe healthy "$fixture/runtime-secret-mode.out"; then
    fail 'terminal probe accepted mounted startup secret mode drift'
fi
grep -F -q 'active web-a member differs from its exact ingress identity' \
    "$fixture/runtime-secret-mode.out" || fail 'startup secret mode drift was not runtime-bound'
chmod 0600 "$fixture/candidate-startup.env"

mv "$fixture/candidate-startup.env" "$fixture/candidate-startup.original"
cp "$fixture/candidate-startup.original" "$fixture/candidate-startup.env"
chmod 0600 "$fixture/candidate-startup.env"
chgrp "$fixture_gid" "$fixture/candidate-startup.env"
if run_probe healthy "$fixture/runtime-secret-inode.out"; then
    fail 'terminal probe accepted mounted startup secret inode drift'
fi
grep -F -q 'active web-a member differs from its exact ingress identity' \
    "$fixture/runtime-secret-inode.out" || fail 'startup secret inode drift was not runtime-bound'
rm -f -- "$fixture/candidate-startup.env"
mv "$fixture/candidate-startup.original" "$fixture/candidate-startup.env"

printf '%s\n' 'CONTROL_PLANE_TERMINAL_PROBE_TEST phase=queue-attestation'
touch "$fixture/queue-nonzero"
if run_probe healthy "$fixture/queue-nonzero.out"; then
    fail 'terminal probe accepted nonzero operation-bound queue evidence'
fi
grep -F -q 'terminal queue evidence was not exactly zero and operation-bound' \
    "$fixture/queue-nonzero.out" || fail 'nonzero terminal queue emitted an unexpected failure'
rm -f -- "$fixture/queue-nonzero"

if TERMINAL_TEST_PORT8000_URL=http://127.0.0.1:8001/health \
    run_probe healthy "$fixture/port8000-wrong-port.out"; then
    fail 'terminal probe accepted a loopback URL on a non-8000 port'
fi
grep -F -q 'terminal port-8000 URL must use an exact loopback host and port 8000' \
    "$fixture/port8000-wrong-port.out" || fail 'wrong port-8000 URL emitted an unexpected failure'

printf '%s\n' 'CONTROL_PLANE_TERMINAL_PROBE_TEST phase=manifest-attestation'
cp "$POOL_FIXTURE_INGRESS_POOL_MANIFEST" "$fixture/ingress-pool.expected"
printf 'unknown_key=unexpected\n' >> "$POOL_FIXTURE_INGRESS_POOL_MANIFEST"
if run_probe healthy "$fixture/ingress-drift.out"; then
    fail 'terminal probe accepted ingress manifest byte drift'
fi
grep -E -q 'ingress-pool-manifest differs from its pinned (metadata|checksum)' \
    "$fixture/ingress-drift.out" || {
        sed 's/^/terminal-probe-ingress-drift: /' "$fixture/ingress-drift.out" >&2
        fail 'ingress drift emitted an unexpected failure'
    }
mv "$fixture/ingress-pool.expected" "$POOL_FIXTURE_INGRESS_POOL_MANIFEST"
chmod 0600 "$POOL_FIXTURE_INGRESS_POOL_MANIFEST"
chgrp "$fixture_gid" "$POOL_FIXTURE_INGRESS_POOL_MANIFEST"

replace_document_value()
{
    local document=$1 key=$2 replacement=$3 temporary
    temporary=$document.new
    awk -F= -v key="$key" -v replacement="$replacement" \
        '$1 == key { print key "=" replacement; next } { print }' \
        "$document" > "$temporary"
    chmod 0600 "$temporary"
    chgrp "$fixture_gid" "$temporary"
    mv -f -- "$temporary" "$document"
}

cp "$POOL_FIXTURE_POOL_PLAN_MANIFEST" "$fixture/pool-plan.route-identity.expected"
cp "$POOL_FIXTURE_INGRESS_POOL_MANIFEST" "$fixture/ingress-pool.route-identity.expected"
replace_document_value "$POOL_FIXTURE_POOL_PLAN_MANIFEST" member_a_route_identity "$WEB_A_ID"
replace_document_value "$POOL_FIXTURE_POOL_PLAN_MANIFEST" pool_plan_set_sha256 \
    "$(pool_fixture_plan_set_bytes | sha256sum | awk '{print $1}')"
POOL_FIXTURE_POOL_PLAN_SHA256=$(pool_fixture_sha256 "$POOL_FIXTURE_POOL_PLAN_MANIFEST")
replace_document_value "$POOL_FIXTURE_INGRESS_POOL_MANIFEST" parent_pool_plan_sha256 \
    "$POOL_FIXTURE_POOL_PLAN_SHA256"
replace_document_value "$POOL_FIXTURE_INGRESS_POOL_MANIFEST" member_a_route_identity "$WEB_A_ID"
replace_document_value "$POOL_FIXTURE_INGRESS_POOL_MANIFEST" member_set_sha256 \
    "$(pool_fixture_ingress_member_set_bytes | sha256sum | awk '{print $1}')"
POOL_FIXTURE_INGRESS_POOL_SHA256=$(pool_fixture_sha256 "$POOL_FIXTURE_INGRESS_POOL_MANIFEST")
if run_probe healthy "$fixture/route-identity-docker-id.out"; then
    fail 'terminal probe accepted a route identity equal to a member Docker ID'
fi
grep -F -q 'ingress route and Docker identity reuses an authority value' \
    "$fixture/route-identity-docker-id.out" \
    || fail 'route/Docker identity collision emitted an unexpected failure'
mv "$fixture/pool-plan.route-identity.expected" "$POOL_FIXTURE_POOL_PLAN_MANIFEST"
mv "$fixture/ingress-pool.route-identity.expected" "$POOL_FIXTURE_INGRESS_POOL_MANIFEST"
chmod 0600 "$POOL_FIXTURE_POOL_PLAN_MANIFEST" "$POOL_FIXTURE_INGRESS_POOL_MANIFEST"
chgrp "$fixture_gid" "$POOL_FIXTURE_POOL_PLAN_MANIFEST" \
    "$POOL_FIXTURE_INGRESS_POOL_MANIFEST"
POOL_FIXTURE_POOL_PLAN_SHA256=$(pool_fixture_sha256 "$POOL_FIXTURE_POOL_PLAN_MANIFEST")
POOL_FIXTURE_INGRESS_POOL_SHA256=$(pool_fixture_sha256 "$POOL_FIXTURE_INGRESS_POOL_MANIFEST")

printf '%s\n' 'CONTROL_PLANE_TERMINAL_PROBE_TEST phase=retired-member-attestation'
rm -f -- "$fixture/legacy-absent"
if run_probe healthy "$fixture/legacy-present.out"; then
    fail 'terminal probe accepted a present retired member name'
fi
grep -F -q 'retired member a name still exists' "$fixture/legacy-present.out" \
    || fail 'present retired name did not reach the explicit present state'
touch "$fixture/legacy-absent"

if run_probe legacy-id-present "$fixture/legacy-id-present.out"; then
    fail 'terminal probe accepted the exact captured retired member ID'
fi
grep -F -q 'retired member a exact ID still exists' "$fixture/legacy-id-present.out" \
    || fail 'present retired ID did not reach the exact-ID present state'

printf '%s\n' 'CONTROL_PLANE_TERMINAL_PROBE_TEST phase=docker-api-tri-state'
for scenario in api-500 daemon-unavailable permission-denied timeout \
    malformed-success malformed-not-found active-malformed list-failure malformed-list; do
    printf 'CONTROL_PLANE_TERMINAL_PROBE_TEST phase=docker-api-tri-state scenario=%s\n' \
        "$scenario"
    if run_probe "$scenario" "$fixture/${scenario}.out"; then
        fail "terminal probe accepted unavailable or malformed Docker evidence: $scenario"
    fi
    case "$scenario" in
        api-500)
            grep -F -q 'Docker Engine API returned HTTP 500' "$fixture/${scenario}.out"
            ;;
        daemon-unavailable|permission-denied|timeout)
            grep -F -q 'Docker Engine API is unavailable' "$fixture/${scenario}.out"
            ;;
        malformed-success|active-malformed)
            grep -F -q 'Docker Engine API returned malformed container inspection data' \
                "$fixture/${scenario}.out"
            ;;
        malformed-not-found)
            grep -F -q 'Docker Engine API returned an invalid not-found response' \
                "$fixture/${scenario}.out"
            ;;
        list-failure)
            grep -F -q 'Docker Engine API returned HTTP 500 for container list' \
                "$fixture/${scenario}.out"
            ;;
        malformed-list)
            grep -F -q 'Docker Engine API returned malformed container list data' \
                "$fixture/${scenario}.out"
            ;;
    esac || {
        sed "s/^/terminal-probe-$scenario: /" "$fixture/${scenario}.out" >&2
        fail "terminal probe emitted the wrong tri-state failure: $scenario"
    }
done

printf '%s\n' 'CONTROL_PLANE_TERMINAL_PROBE_TEST PASS'
