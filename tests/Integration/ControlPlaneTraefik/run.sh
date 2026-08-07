#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIRECTORY="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIRECTORY
readonly COMPOSE_FILE="$SCRIPT_DIRECTORY/compose.yaml"
REPOSITORY_ROOT="$(cd -- "$SCRIPT_DIRECTORY/../../.." && pwd)"
readonly REPOSITORY_ROOT
readonly CONTROL_PLANE_HOST='control-plane.test'
readonly DOCKER_PROVIDER_HOST='docker-provider.test'
readonly APPLICATION_HOST='production-application.test'
readonly APPLICATION_UUID='runtime-app'
readonly APPLICATION_DESTINATION_ID=42
readonly APPLICATION_ROUTING_PREFIX='coolify-bg-2996dc1b6153b95b-'
readonly APPLICATION_MANAGED_FILENAME='coolify-blue-green-2996dc1b6153b95b.yaml'
readonly APPLICATION_DOCKER_ROUTER='runtime-application-docker@docker'
readonly APPLICATION_FILE_ROUTER="${APPLICATION_ROUTING_PREFIX}runtime-application-public@file"
readonly APPLICATION_TRANSACTION_KEY='runtime-forward-retry-0001'
readonly APPLICATION_TRANSACTION_PAYLOAD='{"operation":"runtime-write"}'
readonly PRIVATE_HEALTH_HEADER='X-Coolify-Control-Plane-Health-Proof'
readonly AUTHENTICATION_PROOF_HEADER='X-Coolify-Control-Plane-Authentication-Proof'
readonly MIN_DIRECT_HEALTH_REQUESTS=2
readonly MIN_PROVIDER_HEALTH_REQUESTS=2
readonly MIN_ROUTE_REQUESTS=100
readonly MIN_APPLICATION_REQUESTS=30
readonly MIN_APPLICATION_PHASE_REQUESTS=4
readonly MAX_APPLICATION_REQUEST_LATENCY_MS=2000
readonly MAX_APPLICATION_SAMPLE_GAP_MS=2500
readonly MIN_PROVIDER_CADENCE_MS=500
readonly MAX_PROVIDER_CADENCE_MS=5000
readonly MAX_PROVIDER_START_DELAY_MS=15000
readonly MAX_RELOAD_DELAY_MS=12000
readonly MIN_TRANSITION_OBSERVATION_MS=5000
readonly MAX_TRANSITION_OBSERVATION_MS=35000
readonly TRANSITION_ATTEMPTS=24
readonly TRANSITION_SAMPLE_DELAY_SECONDS=0.05
readonly TRANSPORT_MIN_EVENT_COUNT=5
readonly TRANSPORT_OBSERVER_RELOAD_PHASES=6
readonly TRANSPORT_OBSERVER_TIMEOUT_MARGIN_MS=15000
readonly TRANSPORT_OBSERVER_TIMEOUT_MS=$((
    (2 * MAX_TRANSITION_OBSERVATION_MS)
    + (TRANSPORT_OBSERVER_RELOAD_PHASES * MAX_RELOAD_DELAY_MS)
    + TRANSPORT_OBSERVER_TIMEOUT_MARGIN_MS
))
readonly BACKGROUND_PID_EXIT_TIMEOUT_MS=5000

TEMP_BASE=''
TEMP_DIRECTORY=''
PROJECT_NAME=''
COMPOSE_STARTED=0
TRAEFIK_HTTPS_PORT=''
TRAEFIK_APP_PORT=''
TRAEFIK_API_PORT=''
TRAEFIK_IMAGE=''
COMPOSE_STARTED_AT_MS=0
TRANSITION_OBSERVER_PID=''
TRANSPORT_OBSERVER_PID=''
TRANSPORT_OBSERVER_LOG=''
TRANSPORT_OBSERVER_REPORT=''
TRANSPORT_OBSERVER_READY=''
TRANSPORT_OBSERVER_RELEASE=''
APPLICATION_OBSERVER_PID=''
HELD_TRANSACTION_PID=''
BACKGROUND_PIDS=()

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

now_ms() {
    python3 -c 'import time; print(int(time.time() * 1000))'
}

monotonic_ms() {
    python3 -c 'import time; print(int(time.monotonic() * 1000))'
}

compose() {
    docker compose --project-name "$PROJECT_NAME" --file "$COMPOSE_FILE" "$@"
}

register_background_pid() {
    local pid=$1

    [[ $pid =~ ^[0-9]+$ ]] || fail "Refusing to register an invalid background PID: $pid"
    BACKGROUND_PIDS+=("$pid")
}

unregister_background_pid() {
    local pid=$1
    local registered_pid
    local -a remaining_pids=()

    for registered_pid in "${BACKGROUND_PIDS[@]}"; do
        if [ "$registered_pid" != "$pid" ]; then
            remaining_pids+=("$registered_pid")
        fi
    done
    BACKGROUND_PIDS=("${remaining_pids[@]}")
}

wait_for_registered_background_pid() {
    local pid=$1
    local status=0

    wait "$pid" || status=$?
    unregister_background_pid "$pid"

    return "$status"
}

background_pid_is_running() {
    local expected_pid=$1
    local running_pid

    while IFS= read -r running_pid; do
        if [ "$running_pid" = "$expected_pid" ]; then
            return 0
        fi
    done < <(jobs -pr)

    return 1
}

terminate_registered_background_pids() {
    local pid

    for pid in "${BACKGROUND_PIDS[@]}"; do
        if background_pid_is_running "$pid"; then
            kill "$pid" 2>/dev/null || true
        fi
    done
}

reap_registered_background_pids() {
    local deadline=$(( $(now_ms) + BACKGROUND_PID_EXIT_TIMEOUT_MS ))
    local pid
    local running=0

    while [ "$(now_ms)" -lt "$deadline" ]; do
        running=0
        for pid in "${BACKGROUND_PIDS[@]}"; do
            if background_pid_is_running "$pid"; then
                running=1
                break
            fi
        done
        [ "$running" -eq 0 ] && break
        sleep 0.05
    done

    for pid in "${BACKGROUND_PIDS[@]}"; do
        if background_pid_is_running "$pid"; then
            kill -KILL "$pid" 2>/dev/null || true
        fi
    done
    for pid in "${BACKGROUND_PIDS[@]}"; do
        wait "$pid" 2>/dev/null || true
    done
    BACKGROUND_PIDS=()
}

cleanup() {
    local exit_code=$?
    local cleanup_status=0
    local cleanup_failure
    local temporary_directory_cleanup_status
    local -a cleanup_failures=()

    trap - EXIT
    set +e
    terminate_registered_background_pids
    if [ "$COMPOSE_STARTED" -eq 1 ]; then
        if compose down --volumes --remove-orphans >/dev/null 2>&1; then
            :
        else
            cleanup_status=$?
            cleanup_failures+=("owned Compose project cleanup failed with status $cleanup_status")
        fi
    fi
    reap_registered_background_pids
    if [ -n "$TEMP_DIRECTORY" ]; then
        case "$TEMP_DIRECTORY" in
            "$TEMP_BASE"/coolify-control-plane-traefik.*)
                if rm -rf -- "$TEMP_DIRECTORY"; then
                    :
                else
                    temporary_directory_cleanup_status=$?
                    if [ "$cleanup_status" -eq 0 ]; then
                        cleanup_status=$temporary_directory_cleanup_status
                    fi
                    cleanup_failures+=("owned temporary directory cleanup failed with status $temporary_directory_cleanup_status")
                fi
                ;;
            *)
                printf 'Refusing to remove unexpected integration directory: %s\n' "$TEMP_DIRECTORY" >&2
                ;;
        esac
    fi
    if [ "$cleanup_status" -ne 0 ]; then
        for cleanup_failure in "${cleanup_failures[@]}"; do
            if [ "$exit_code" -eq 0 ]; then
                printf 'FAIL: %s.\n' "$cleanup_failure" >&2
            else
                printf 'WARN: %s; preserving primary status %s.\n' \
                    "$cleanup_failure" "$exit_code" >&2
            fi
        done
        [ "$exit_code" -eq 0 ] && exit "$cleanup_status"
    fi

    exit "$exit_code"
}

trap cleanup EXIT

initialize_temp_directory() {
    TEMP_BASE=${TMPDIR:-/tmp}
    TEMP_BASE=${TEMP_BASE%/}
    TEMP_DIRECTORY=$(mktemp -d "$TEMP_BASE/coolify-control-plane-traefik.XXXXXX")
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "Required command is unavailable: $1"
}

sha256_file() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    else
        shasum -a 256 "$1" | awk '{print $1}'
    fi
}

next_port() {
    python3 - <<'PY'
import socket
with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as socket_handle:
    socket_handle.bind(('127.0.0.1', 0))
    print(socket_handle.getsockname()[1])
PY
}

header_value() {
    local header_name=$1
    local headers_file=$2
    local normalized_header_name

    normalized_header_name=$(printf '%s' "$header_name" | tr '[:upper:]' '[:lower:]')
    awk -v expected="$normalized_header_name" '
        {
            sub(/\r$/, "")
            split($0, fields, ":")
            if (tolower(fields[1]) == expected) {
                sub(/^[^:]*:[[:space:]]*/, "")
                value = $0
            }
        }
        END { print value }
    ' "$headers_file"
}

fetch_route() {
    local route=$1
    local host_header=${2:-$CONTROL_PLANE_HOST}
    local headers_file
    local status
    local url

    headers_file=$(mktemp "$TEMP_DIRECTORY/route-headers.XXXXXX")
    if [ "$route" = https ]; then
        url="https://127.0.0.1:${TRAEFIK_HTTPS_PORT}/runtime-probe"
    elif [ "$route" = app-port ]; then
        url="http://127.0.0.1:${TRAEFIK_APP_PORT}/runtime-probe"
    else
        rm -f -- "$headers_file"
        fail "Unknown route: $route"
    fi

    if ! status=$(curl --silent --show-error --insecure --noproxy '*' --connect-timeout 2 --max-time 4 \
        --header "Host: ${host_header}" --dump-header "$headers_file" --output /dev/null --write-out '%{http_code}' "$url"); then
        status=000
    fi

    printf '%s|%s|%s|%s|%s|%s\n' \
        "$status" \
        "$(header_value 'X-Coolify-Control-Plane-Color' "$headers_file")" \
        "$(header_value 'X-Coolify-Control-Plane-Generation' "$headers_file")" \
        "$(header_value 'X-Coolify-Control-Plane-Config-Ack' "$headers_file")" \
        "$(header_value 'X-Coolify-Control-Plane-Dynamic-Sha' "$headers_file")" \
        "$(header_value 'X-Integration-Backend' "$headers_file")"
    rm -f -- "$headers_file"
}

assert_transport_http() {
    local route=$1
    local expected_backend=$2
    local expected_color=$3
    local expected_generation=$4
    local expected_dynamic_sha=$5
    local headers_file
    local body_file
    local status
    local url

    headers_file=$(mktemp "$TEMP_DIRECTORY/transport-headers.XXXXXX")
    body_file=$(mktemp "$TEMP_DIRECTORY/transport-body.XXXXXX")
    if [ "$route" = https ]; then
        url="https://127.0.0.1:${TRAEFIK_HTTPS_PORT}/transport/http"
    elif [ "$route" = app-port ]; then
        url="http://127.0.0.1:${TRAEFIK_APP_PORT}/transport/http"
    else
        rm -f -- "$headers_file" "$body_file"
        fail "Unknown transport route: $route"
    fi

    if ! status=$(curl --silent --show-error --insecure --noproxy '*' --connect-timeout 2 --max-time 4 \
        --header "Host: ${CONTROL_PLANE_HOST}" --dump-header "$headers_file" --output "$body_file" --write-out '%{http_code}' "$url"); then
        status=000
    fi
    [ "$status" = 200 ] || fail "$route transport HTTP request returned status $status"
    [ "$(header_value 'X-Integration-Backend' "$headers_file")" = "$expected_backend" ] \
        || fail "$route transport HTTP request reached the wrong backend"
    [ "$(header_value 'X-Coolify-Control-Plane-Dynamic-Sha' "$headers_file")" = "$expected_dynamic_sha" ] \
        || fail "$route transport HTTP request returned a stale dynamic SHA"
    jq -e \
        --arg backend "$expected_backend" \
        --arg color "$expected_color" \
        --arg dynamic_sha "$expected_dynamic_sha" \
        --arg generation "$expected_generation" \
        '.transport == "http" and .sequence == 1 and .backend == $backend and .color == $color and .dynamicSha == $dynamic_sha and .generation == $generation' \
        "$body_file" >/dev/null || fail "$route transport HTTP request returned an inconsistent payload"
    rm -f -- "$headers_file" "$body_file"
}

probe_forwarded_identity() {
    local route=$1
    local host_header=$2
    local supplied_proof=$3
    local supplied_forwarded_for=$4
    local url

    if [ "$route" = https ]; then
        url="https://127.0.0.1:${TRAEFIK_HTTPS_PORT}/forwarded-identity-probe"
    elif [ "$route" = app-port ]; then
        url="http://127.0.0.1:${TRAEFIK_APP_PORT}/forwarded-identity-probe"
    else
        fail "Unknown forwarded-identity route: $route"
    fi

    curl --fail --silent --show-error --insecure --noproxy '*' --connect-timeout 2 --max-time 4 \
        --header "Host: ${host_header}" \
        --header "${AUTHENTICATION_PROOF_HEADER}: ${supplied_proof}" \
        --header "X-Forwarded-For: ${supplied_forwarded_for}" \
        --output /dev/null "$url"
}

latest_forwarded_identity_record() {
    touch "$BACKEND_BLUE_STATE_DIR/forwarded-identity.log" "$BACKEND_GREEN_STATE_DIR/forwarded-identity.log"
    sort -n -t '|' -k 1,1 "$BACKEND_BLUE_STATE_DIR/forwarded-identity.log" "$BACKEND_GREEN_STATE_DIR/forwarded-identity.log" | tail -n 1
}

assert_forwarded_identity_boundary() {
    local forged_proof
    local forged_forwarded_for
    local record
    local _timestamp observed_host observed_proof observed_forwarded_for

    forged_proof=$(openssl rand -hex 32)
    forged_forwarded_for='203.0.113.199'

    for route in https app-port; do
        probe_forwarded_identity "$route" "$CONTROL_PLANE_HOST" "$forged_proof" "$forged_forwarded_for"
        record=$(latest_forwarded_identity_record)
        IFS='|' read -r _timestamp observed_host observed_proof observed_forwarded_for <<<"$record"
        [ "$observed_host" = "$CONTROL_PLANE_HOST" ] || fail "$route did not preserve the managed control-plane host"
        [ "$observed_proof" = "$CONTROL_PLANE_AUTHENTICATION_PROOF" ] || fail "$route did not overwrite a forged authentication proof"
        [ -n "$observed_forwarded_for" ] || fail "$route omitted the genuine forwarded client identity"
        case "$observed_forwarded_for" in
            *"$forged_forwarded_for"*) fail "$route trusted a caller-supplied forwarded identity" ;;
        esac
    done

    probe_forwarded_identity https "$DOCKER_PROVIDER_HOST" "$forged_proof" "$forged_forwarded_for"
    record=$(latest_forwarded_identity_record)
    IFS='|' read -r _timestamp observed_host observed_proof observed_forwarded_for <<<"$record"
    [ "$observed_host" = "$DOCKER_PROVIDER_HOST" ] || fail 'Docker-provider identity probe reached the wrong host'
    [ "$observed_proof" = "$forged_proof" ] || fail 'File-provider identity middleware leaked onto the Docker-provider route'
}

assert_route_identity() {
    local route=$1
    local expected_color=$2
    local expected_generation=$3
    local expected_acknowledgement=$4
    local expected_dynamic_sha=$5
    local expected_backend=${6:-}
    local record
    local status color generation acknowledgement dynamic_sha backend

    record=$(fetch_route "$route")
    IFS='|' read -r status color generation acknowledgement dynamic_sha backend <<<"$record"
    [ "$status" = 200 ] || fail "$route returned status $status instead of 200"
    [ "$color" = "$expected_color" ] || fail "$route returned color $color instead of $expected_color"
    [ "$generation" = "$expected_generation" ] || fail "$route returned generation $generation instead of $expected_generation"
    [ "$acknowledgement" = "$expected_acknowledgement" ] || fail "$route returned a stale configuration acknowledgement"
    [ "$dynamic_sha" = "$expected_dynamic_sha" ] || fail "$route returned a stale dynamic SHA"
    if [ -n "$expected_backend" ]; then
        [ "$backend" = "$expected_backend" ] || fail "$route reached backend $backend instead of $expected_backend"
    fi
}

route_matches_identity() {
    local route=$1
    local expected_color=$2
    local expected_generation=$3
    local expected_acknowledgement=$4
    local expected_dynamic_sha=$5
    local expected_backend=${6:-}
    local record
    local status color generation acknowledgement dynamic_sha backend

    record=$(fetch_route "$route")
    IFS='|' read -r status color generation acknowledgement dynamic_sha backend <<<"$record"
    [ "$status" = 200 ] \
        && [ "$color" = "$expected_color" ] \
        && [ "$generation" = "$expected_generation" ] \
        && [ "$acknowledgement" = "$expected_acknowledgement" ] \
        && [ "$dynamic_sha" = "$expected_dynamic_sha" ] \
        && { [ -z "$expected_backend" ] || [ "$backend" = "$expected_backend" ]; }
}

assert_routes_identity_twice() {
    local expected_color=$1
    local expected_generation=$2
    local expected_acknowledgement=$3
    local expected_dynamic_sha=$4
    local expected_backend=${5:-}
    local route
    local attempt

    for route in https app-port; do
        for attempt in 1 2; do
            assert_route_identity "$route" "$expected_color" "$expected_generation" "$expected_acknowledgement" "$expected_dynamic_sha" "$expected_backend"
        done
    done
}

assert_route_status() {
    local route=$1
    local expected_status=$2
    local record
    local status

    record=$(fetch_route "$route")
    IFS='|' read -r status _ <<<"$record"
    [ "$status" = "$expected_status" ] || fail "$route returned status $status instead of $expected_status"
}

route_matches_status() {
    local route=$1
    local expected_status=$2
    local record
    local status

    record=$(fetch_route "$route")
    IFS='|' read -r status _ <<<"$record"
    [ "$status" = "$expected_status" ]
}

wait_for_route_identity() {
    local route=$1
    local expected_color=$2
    local expected_generation=$3
    local expected_acknowledgement=$4
    local expected_dynamic_sha=$5
    local expected_backend=${6:-}
    local deadline=$(( $(now_ms) + MAX_RELOAD_DELAY_MS ))

    while [ "$(now_ms)" -lt "$deadline" ]; do
        if route_matches_identity "$route" "$expected_color" "$expected_generation" "$expected_acknowledgement" "$expected_dynamic_sha" "$expected_backend"; then
            return
        fi
        sleep 0.1
    done
    fail "Timed out waiting for $route to apply the expected route identity"
}

wait_for_route_status() {
    local route=$1
    local expected_status=$2
    local deadline=$(( $(now_ms) + MAX_RELOAD_DELAY_MS ))

    while [ "$(now_ms)" -lt "$deadline" ]; do
        if route_matches_status "$route" "$expected_status"; then
            return
        fi
        sleep 0.1
    done
    fail "Timed out waiting for $route to return $expected_status"
}

direct_health() {
    local backend=$1

    compose exec -T "$backend" node - "$PRIVATE_HEALTH_HEADER" "$CONTROL_PLANE_HEALTH_PROOF" <<'NODE'
const http = require('http');
const [headerName, proof] = process.argv.slice(2);
const request = http.request({
  host: '127.0.0.1',
  port: 8080,
  path: '/api/health',
  headers: {[headerName]: proof, 'X-Integration-Direct-Probe': 'true'},
}, (response) => {
  response.resume();
  response.on('end', () => {
    console.log([
      response.statusCode,
      response.headers['x-integration-backend'] || '',
      response.headers['x-coolify-control-plane-dynamic-sha'] || '',
    ].join('|'));
  });
});
request.on('error', (error) => {
  console.error(error.message);
  process.exit(1);
});
request.end();
NODE
}

set_backend_health() {
    local backend=$1
    local healthy=$2

    compose exec -T "$backend" node - "$healthy" <<'NODE'
const fs = require('fs');
const healthy = process.argv[2] === 'true';
const healthPath = '/state/healthy';
if (healthy) {
  fs.writeFileSync(healthPath, 'healthy\n');
} else if (fs.existsSync(healthPath)) {
  fs.unlinkSync(healthPath);
}
NODE
}

set_backend_identity() {
    local backend=$1
    local color=$2
    local generation=$3
    local dynamic_sha=$4

    compose exec -T "$backend" node - "$color" "$generation" "$dynamic_sha" <<'NODE'
const fs = require('fs');
const [color, generation, dynamicSha] = process.argv.slice(2);
const stagedPath = '/state/.identity-' + process.pid + '-' + Date.now();
fs.writeFileSync(stagedPath, [color, generation, dynamicSha].join('|') + '\n');
fs.renameSync(stagedPath, '/state/identity');
NODE
}

assert_directly_healthy_twice() {
    local backend=$1
    local state_directory=$2
    local expected_dynamic_sha=$3
    local attempt
    local record
    local status actual_backend actual_dynamic_sha

    for attempt in 1 2; do
        record=$(direct_health "$backend")
        IFS='|' read -r status actual_backend actual_dynamic_sha <<<"$record"
        [ "$status" = 204 ] || fail "$backend direct health returned $status instead of 204"
        [ "$actual_backend" = "${backend#backend-}" ] || fail "$backend direct health reached $actual_backend"
        [ "$actual_dynamic_sha" = "$expected_dynamic_sha" ] || fail "$backend direct health returned a stale dynamic SHA"
    done

    [ "$(wc -l < "$state_directory/direct-health.log")" -ge "$MIN_DIRECT_HEALTH_REQUESTS" ] || fail "$backend did not receive two direct health checks"
}

write_identity() {
    local state_directory=$1
    local color=$2
    local generation=$3
    local dynamic_sha=$4
    local staged_identity

    staged_identity=$(mktemp "$state_directory/.identity.XXXXXX")
    printf '%s|%s|%s\n' "$color" "$generation" "$dynamic_sha" > "$staged_identity"
    mv -f "$staged_identity" "$state_directory/identity"
}

write_dynamic_snapshot() {
    local destination=$1
    local color=$2
    local generation=$3
    local acknowledgement=$4
    shift 4
    local backend

    {
        printf '%s\n' 'tls:'
        printf '%s\n' '  certificates:'
        printf '%s\n' '    - certFile: /certs/cert.pem'
        printf '%s\n' '      keyFile: /certs/key.pem'
        printf '%s\n' 'http:'
        printf '%s\n' '  routers:'
        printf '%s\n' '    coolify-https:'
        printf '%s\n' "      rule: Host(\`control-plane.test\`)"
        printf '%s\n' '      entryPoints:'
        printf '%s\n' '        - https'
        printf '%s\n' '      service: coolify-control-plane'
        printf '%s\n' '      middlewares:'
        printf '%s\n' '        - coolify-control-plane-identity'
        printf '%s\n' '      tls: {}'
        printf '%s\n' '    coolify-app-port:'
        printf '%s\n' "      rule: PathPrefix(\`/\`)"
        printf '%s\n' '      entryPoints:'
        printf '%s\n' '        - app-port'
        printf '%s\n' '      service: coolify-control-plane'
        printf '%s\n' '      middlewares:'
        printf '%s\n' '        - coolify-control-plane-identity'
        printf '%s\n' '  middlewares:'
        printf '%s\n' '    coolify-control-plane-identity:'
        printf '%s\n' '      headers:'
        printf '%s\n' '        customRequestHeaders:'
        printf '          %s: %s\n' "$AUTHENTICATION_PROOF_HEADER" "$CONTROL_PLANE_AUTHENTICATION_PROOF"
        printf '%s\n' '        customResponseHeaders:'
        printf '          X-Coolify-Control-Plane-Color: %s\n' "$color"
        printf '          X-Coolify-Control-Plane-Generation: %s\n' "$generation"
        printf '          X-Coolify-Control-Plane-Config-Ack: %s\n' "$acknowledgement"
        printf '%s\n' '  services:'
        printf '%s\n' '    coolify-control-plane:'
        printf '%s\n' '      loadBalancer:'
        printf '%s\n' '        servers:'
        for backend in "$@"; do
            printf '          - url: http://%s:8080\n' "$backend"
        done
        printf '%s\n' '        healthCheck:'
        printf '%s\n' '          path: /api/health'
        printf '%s\n' '          scheme: http'
        printf '%s\n' '          hostname: control-plane.test'
        printf '%s\n' '          method: GET'
        printf '%s\n' '          status: 204'
        printf '%s\n' '          headers:'
        printf '            %s: %s\n' "$PRIVATE_HEALTH_HEADER" "$CONTROL_PLANE_HEALTH_PROOF"
        printf '%s\n' '          interval: 1s'
        printf '%s\n' '          unhealthyInterval: 1s'
        printf '%s\n' '          timeout: 3s'
        printf '%s\n' '          followRedirects: false'
    } > "$destination"
}

atomic_replace_snapshot() {
    local source_snapshot=$1
    local staged_filename

    staged_filename=".coolify.yaml.${RANDOM}.$$"
    compose exec -T config-writer mkdir -p /proxy/dynamic
    compose cp "$source_snapshot" "config-writer:/proxy/dynamic/${staged_filename}"
    compose exec -T config-writer sh -c "mv -f /proxy/dynamic/${staged_filename} /proxy/dynamic/coolify.yaml"
}

assert_exact_dynamic_snapshot() {
    local snapshot=$1
    local expected_dynamic_sha
    local actual_dynamic_sha

    expected_dynamic_sha=$(sha256_file "$snapshot")
    actual_dynamic_sha=$(compose exec -T config-writer sh -c 'sha256sum /proxy/dynamic/coolify.yaml' | awk '{print $1}')
    [ "$actual_dynamic_sha" = "$expected_dynamic_sha" ] || fail 'The managed dynamic document does not contain the exact expected bytes'
}

valid_provider_probe_count() {
    local state_directory=$1
    local log_file="$state_directory/provider-health.log"

    if [ ! -f "$log_file" ]; then
        printf '0\n'
        return
    fi
    awk -F'|' '$2 == "1" && $3 == "1" { count += 1 } END { print count + 0 }' "$log_file"
}

wait_for_provider_health() {
    local state_directory=$1
    local backend=$2
    local deadline=$(( $(now_ms) + MAX_PROVIDER_START_DELAY_MS ))

    while [ "$(now_ms)" -lt "$deadline" ]; do
        if [ "$(valid_provider_probe_count "$state_directory")" -ge "$MIN_PROVIDER_HEALTH_REQUESTS" ]; then
            return
        fi
        sleep 0.1
    done
    fail "$backend did not receive two successful Traefik provider health checks"
}

assert_provider_delay_bounds() {
    local state_directory=$1
    local backend=$2
    local first_probe second_probe start_delay cadence

    first_probe=$(awk -F'|' '$2 == "1" && $3 == "1" { print $1; exit }' "$state_directory/provider-health.log")
    second_probe=$(awk -F'|' '$2 == "1" && $3 == "1" { count += 1; if (count == 2) { print $1; exit } }' "$state_directory/provider-health.log")
    start_delay=$((first_probe - COMPOSE_STARTED_AT_MS))
    cadence=$((second_probe - first_probe))

    [ "$start_delay" -ge 0 ] || fail "$backend provider probe timestamp predates Compose startup"
    [ "$start_delay" -le "$MAX_PROVIDER_START_DELAY_MS" ] || fail "$backend provider probe exceeded the startup delay bound"
    [ "$cadence" -ge "$MIN_PROVIDER_CADENCE_MS" ] || fail "$backend provider health cadence did not span a real interval"
    [ "$cadence" -le "$MAX_PROVIDER_CADENCE_MS" ] || fail "$backend provider health cadence exceeded the bound"
}

wait_for_unhealthy_provider_probe() {
    local state_directory=$1
    local backend=$2
    local deadline=$(( $(now_ms) + MAX_PROVIDER_START_DELAY_MS ))

    while [ "$(now_ms)" -lt "$deadline" ]; do
        if [ -f "$state_directory/provider-health.log" ] && awk -F'|' '$2 == "1" && $3 == "0" { found = 1 } END { exit found ? 0 : 1 }' "$state_directory/provider-health.log"; then
            return
        fi
        sleep 0.1
    done
    fail "$backend did not receive a private-header health check after becoming unhealthy"
}

assert_runtime_shared_service() {
    local router_json

    if ! router_json=$(curl --silent --show-error --noproxy '*' --connect-timeout 2 --max-time 4 "http://127.0.0.1:${TRAEFIK_API_PORT}/api/http/routers"); then
        return 1
    fi
    printf '%s' "$router_json" | python3 -c '
import json
import sys

routers = {router.get("name"): router for router in json.load(sys.stdin)}
https = routers.get("coolify-https@file")
app_port = routers.get("coolify-app-port@file")
if https is None or app_port is None:
    raise SystemExit(1)
if not https.get("service") or https.get("service") != app_port.get("service"):
    raise SystemExit(1)
' 2>/dev/null
}

wait_for_runtime_shared_service() {
    local deadline=$(( $(now_ms) + MAX_RELOAD_DELAY_MS ))

    while [ "$(now_ms)" -lt "$deadline" ]; do
        if assert_runtime_shared_service; then
            return
        fi
        sleep 0.1
    done
    fail 'Traefik did not expose HTTPS and APP_PORT through the same File-provider service'
}

assert_docker_provider_route() {
    local record
    local status _color _generation _acknowledgement _dynamic_sha backend

    record=$(fetch_route https "$DOCKER_PROVIDER_HOST")
    IFS='|' read -r status _color _generation _acknowledgement _dynamic_sha backend <<<"$record"
    [ "$status" = 200 ] && [ "$backend" = blue ]
}

wait_for_docker_provider_route() {
    local timeout_ms=${1:-$MAX_RELOAD_DELAY_MS}
    local deadline=$(( $(now_ms) + timeout_ms ))

    while [ "$(now_ms)" -lt "$deadline" ]; do
        if assert_docker_provider_route; then
            return
        fi
        sleep 0.1
    done
    fail 'Docker-provider route did not become reachable'
}

decode_base64() {
    python3 -c 'import base64, sys; sys.stdout.buffer.write(base64.b64decode(sys.stdin.buffer.read()))'
}

fetch_application_route() {
    local headers_file
    local curl_result
    local duration_ms
    local status
    local total_seconds

    headers_file=$(mktemp "$TEMP_DIRECTORY/application-route-headers.XXXXXX")
    if ! curl_result=$(curl --silent --show-error --insecure --noproxy '*' --connect-timeout 2 --max-time 4 \
        --header "Host: ${APPLICATION_HOST}" \
        --dump-header "$headers_file" \
        --output /dev/null \
        --write-out '%{http_code}|%{time_total}' \
        "https://127.0.0.1:${TRAEFIK_HTTPS_PORT}/runtime-application"); then
        curl_result='000|4.000000'
    fi
    IFS='|' read -r status total_seconds <<<"$curl_result"
    duration_ms=$(awk -v seconds="$total_seconds" 'BEGIN { printf "%d", (seconds * 1000) + 0.5 }')
    printf '%s|%s|%s|%s\n' \
        "$status" \
        "$(header_value 'X-Integration-Backend' "$headers_file")" \
        "$(header_value 'X-Coolify-Probe-Ack' "$headers_file")" \
        "$duration_ms"
    rm -f -- "$headers_file"
}

application_route_matches() {
    local expected_backend=$1
    local expected_acknowledgement=$2
    local record
    local status backend acknowledgement _duration_ms

    record=$(fetch_application_route)
    IFS='|' read -r status backend acknowledgement _duration_ms <<<"$record"
    [ "$status" = 200 ] \
        && [ "$backend" = "$expected_backend" ] \
        && [ "$acknowledgement" = "$expected_acknowledgement" ]
}

wait_for_application_route() {
    local expected_backend=$1
    local expected_acknowledgement=$2
    local deadline=$(( $(now_ms) + MAX_RELOAD_DELAY_MS ))

    while [ "$(now_ms)" -lt "$deadline" ]; do
        if application_route_matches "$expected_backend" "$expected_acknowledgement"; then
            return
        fi
        sleep 0.1
    done
    fail "Timed out waiting for the application route to reach $expected_backend with its exact provider acknowledgement"
}

assert_application_provider_inventory() {
    local expected_file=$1
    local expected_docker=$2
    local expected_public_backend=$3
    local rawdata_file

    rawdata_file=$(mktemp "$TEMP_DIRECTORY/application-rawdata.XXXXXX")
    if ! curl --fail --silent --show-error --noproxy '*' --connect-timeout 2 --max-time 4 \
        --output "$rawdata_file" "http://127.0.0.1:${TRAEFIK_API_PORT}/api/rawdata"; then
        rm -f -- "$rawdata_file"
        return 1
    fi
    if ! python3 - "$rawdata_file" "$expected_file" "$expected_docker" "$expected_public_backend" \
        "$APPLICATION_FILE_ROUTER" "$APPLICATION_DOCKER_ROUTER" "$APPLICATION_HOST" "$APPLICATION_ROUTING_PREFIX" <<'PY'
import json
import sys

rawdata_path, expected_file, expected_docker, expected_public_backend, file_name, docker_name, host, prefix = sys.argv[1:]
with open(rawdata_path, encoding='utf-8') as rawdata_handle:
    rawdata = json.load(rawdata_handle)
routers = rawdata.get('routers') or {}
services = rawdata.get('services') or {}
rule = f'Host(`{host}`)'
file_router = routers.get(file_name)
docker_router = routers.get(docker_name)

if (file_router is not None) != (expected_file == 'present'):
    raise SystemExit(1)
if (docker_router is not None) != (expected_docker == 'present'):
    raise SystemExit(1)
if docker_router is not None:
    if docker_router.get('status') != 'enabled' or docker_router.get('rule') != rule:
        raise SystemExit(1)
    if docker_router.get('service') != f'{prefix}blue':
        raise SystemExit(1)
    blue_service = services.get(f'{prefix}blue@docker')
    if blue_service is None or blue_service.get('status') != 'enabled':
        raise SystemExit(1)
if file_router is not None:
    if file_router.get('status') != 'enabled' or file_router.get('rule') != rule:
        raise SystemExit(1)
    if file_router.get('service') != f'{prefix}active':
        raise SystemExit(1)
    if int(file_router.get('priority', 0)) != len(rule) + 1:
        raise SystemExit(1)
    if docker_router is not None and int(file_router.get('priority', 0)) <= int(docker_router.get('priority', 0)):
        raise SystemExit(1)
    active_service = services.get(f'{prefix}active@file')
    expected_member_name = f'{prefix}{expected_public_backend}@docker'
    expected_children = [{'name': expected_member_name, 'weight': 1}]
    if active_service is None or active_service.get('status') != 'enabled':
        raise SystemExit(1)
    if (active_service.get('weighted') or {}).get('services') != expected_children:
        raise SystemExit(1)
    member_service = services.get(expected_member_name)
    if member_service is None or member_service.get('status') != 'enabled':
        raise SystemExit(1)
elif expected_public_backend != 'blue':
    raise SystemExit(1)
relevant_errors = [
    error for error in (rawdata.get('errors') or [])
    if host in json.dumps(error, sort_keys=True) or prefix in json.dumps(error, sort_keys=True)
]
if relevant_errors:
    raise SystemExit(1)
PY
    then
        rm -f -- "$rawdata_file"
        return 1
    fi
    rm -f -- "$rawdata_file"
}

wait_for_application_provider_inventory() {
    local expected_file=$1
    local expected_docker=$2
    local expected_public_backend=$3
    local deadline=$(( $(now_ms) + MAX_RELOAD_DELAY_MS ))

    while [ "$(now_ms)" -lt "$deadline" ]; do
        if assert_application_provider_inventory "$expected_file" "$expected_docker" "$expected_public_backend"; then
            return
        fi
        sleep 0.1
    done
    curl --silent --show-error --noproxy '*' --connect-timeout 2 --max-time 4 \
        "http://127.0.0.1:${TRAEFIK_API_PORT}/api/rawdata" \
        | jq --arg host "$APPLICATION_HOST" --arg prefix "$APPLICATION_ROUTING_PREFIX" '
            {
                errors,
                routers: (.routers // {} | with_entries(select((.key | contains($prefix)) or (.value.rule // "" | contains($host))))),
                services: (.services // {} | with_entries(select(.key | contains($prefix))))
            }
        ' >&2 || true
    fail "Timed out waiting for application provider inventory file=$expected_file docker=$expected_docker backend=$expected_public_backend"
}

compile_application_lifecycle_plan() {
    local destination=$1
    local expected_boot_id=$2
    local green_container_id=$3

    php -- "$REPOSITORY_ROOT" "$expected_boot_id" "$green_container_id" "$APPLICATION_UUID" "$APPLICATION_DESTINATION_ID" > "$destination" <<'PHP'
<?php

use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;

require $argv[1].'/vendor/autoload.php';

$expectedBootId = $argv[2];
$greenContainerId = $argv[3];
$operationId = 'runtime-interrupted-forward';
$applicationUuid = $argv[4];
$destinationId = (int) $argv[5];
$target = new BlueGreenRoutingTarget(
    destinationId: $destinationId,
    activeColor: BlueGreenDeploymentColor::GREEN,
    blueContainerName: 'backend-blue',
    greenContainerName: 'backend-green',
    port: 8080,
    routingRevision: 1,
    mode: BlueGreenRoutingMode::LegacyAdoption,
    publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($operationId),
    destinationFenceEpoch: 1,
    operationId: $operationId,
    mutationSequence: 1,
    activeDeploymentUuid: 'runtime-green-deployment',
    activeContainerId: $greenContainerId,
    destinationTopologyDigest: hash('sha256', 'runtime-destination-42'),
);
$configuration = (new CompileBlueGreenProxyConfiguration)->compileGeneratedLabels(
    applicationUuid: $applicationUuid,
    generatedLabels: [
        'traefik.enable=true',
        'traefik.http.routers.runtime-application.rule=Host(`production-application.test`)',
        'traefik.http.routers.runtime-application.entryPoints=https',
        'traefik.http.routers.runtime-application.tls=true',
        'traefik.http.routers.runtime-application.service=runtime-application',
        'traefik.http.services.runtime-application.loadbalancer.server.port=8080',
    ],
    target: $target,
);
$rollbackKey = new BlueGreenProxyRollbackKey($operationId, null, $configuration->state);
$writer = new WriteBlueGreenProxyConfiguration;
$interruptedWriter = new class extends WriteBlueGreenProxyConfiguration
{
    protected function afterManagedMutationCommands(): array
    {
        return ['exit 86'];
    }
};
$proxyPath = '/proxy';

echo json_encode([
    'activeServiceName' => BlueGreenRoutingTarget::activeServiceName($applicationUuid, $destinationId).'@file',
    'dockerRouterName' => 'runtime-application-docker@docker',
    'fileRouterName' => BlueGreenRoutingTarget::routingNamePrefix($applicationUuid, $destinationId).'runtime-application-public@file',
    'forwardStateBase64' => base64_encode($configuration->state->serialize()),
    'interruptedCommandBase64' => base64_encode($interruptedWriter->commandFor($proxyPath, $configuration, $rollbackKey, $expectedBootId)),
    'journalPath' => $writer->mutationJournalPath($proxyPath, $configuration->managedFilename),
    'managedFilename' => $configuration->managedFilename,
    'managedPath' => $writer->managedPath($proxyPath, $configuration->managedFilename),
    'managedSha256' => $configuration->sha256,
    'publicAcknowledgement' => $target->publicAcknowledgement(),
    'recoveryCommandBase64' => base64_encode($writer->commandFor($proxyPath, $configuration, $rollbackKey, $expectedBootId)),
    'rollbackArtifactPath' => $writer->rollbackArtifactPath($proxyPath, $rollbackKey),
    'rollbackCommandBase64' => base64_encode($writer->rollbackArtifactRestoreCommandFor($proxyPath, $rollbackKey, $expectedBootId)),
    'rollbackStateBase64' => base64_encode($rollbackKey->rollbackState()->serialize()),
    'statePath' => $writer->statePath($proxyPath, $configuration->managedFilename),
    'yamlBase64' => base64_encode($configuration->yaml),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
PHP

    jq -e \
        --arg active_service "${APPLICATION_ROUTING_PREFIX}active@file" \
        --arg docker_router "$APPLICATION_DOCKER_ROUTER" \
        --arg file_router "$APPLICATION_FILE_ROUTER" \
        --arg filename "$APPLICATION_MANAGED_FILENAME" '
            .activeServiceName == $active_service
            and .dockerRouterName == $docker_router
            and .fileRouterName == $file_router
            and .managedFilename == $filename
            and (.managedSha256 | test("^[a-f0-9]{64}$"))
            and (.publicAcknowledgement | type == "string" and length >= 16)
        ' "$destination" >/dev/null || fail 'The production compiler returned an invalid application lifecycle plan'
}

run_application_writer_command() {
    local plan=$1
    local command_field=$2
    local expected_status=$3
    local command
    local output_file="$TEMP_DIRECTORY/${command_field}.log"
    local status=0

    command=$(jq -er ".${command_field}" "$plan" | decode_base64)
    compose exec -T config-writer sh -c "$command" > "$output_file" 2>&1 || status=$?
    if [ "$status" -ne "$expected_status" ]; then
        cat "$output_file" >&2 || true
        fail "$command_field exited $status instead of $expected_status"
    fi
}

wait_for_host_file() {
    local path=$1
    local description=$2
    local deadline=$(( $(now_ms) + MAX_RELOAD_DELAY_MS ))

    while [ "$(now_ms)" -lt "$deadline" ]; do
        if [ -s "$path" ]; then
            return
        fi
        sleep 0.05
    done
    fail "Timed out waiting for $description"
}

assert_interrupted_application_artifacts() {
    local plan=$1
    local managed_path state_path journal_path artifact_path expected_sha actual_sha

    managed_path=$(jq -er '.managedPath' "$plan")
    state_path=$(jq -er '.statePath' "$plan")
    journal_path=$(jq -er '.journalPath' "$plan")
    artifact_path=$(jq -er '.rollbackArtifactPath' "$plan")
    expected_sha=$(jq -er '.managedSha256' "$plan")
    compose exec -T config-writer sh -c \
        "test -f \"\$1\" && test ! -e \"\$2\" && test ! -L \"\$2\" && test -f \"\$3\" && test -f \"\$4\"" \
        sh "$managed_path" "$state_path" "$journal_path" "$artifact_path" \
        || fail 'Interrupted publication did not retain its exact managed file, journal, and rollback artifact'
    actual_sha=$(compose exec -T config-writer sh -c "sha256sum \"\$1\"" sh "$managed_path" | awk '{print $1}')
    [ "$actual_sha" = "$expected_sha" ] || fail 'Interrupted publication exposed bytes other than the production compiler output'
}

assert_recovered_application_artifacts() {
    local plan=$1
    local managed_path state_path journal_path artifact_path expected_state actual_state

    managed_path=$(jq -er '.managedPath' "$plan")
    state_path=$(jq -er '.statePath' "$plan")
    journal_path=$(jq -er '.journalPath' "$plan")
    artifact_path=$(jq -er '.rollbackArtifactPath' "$plan")
    expected_state=$(jq -er '.forwardStateBase64' "$plan" | decode_base64)
    compose exec -T config-writer sh -c \
        "test -f \"\$1\" && test -f \"\$2\" && test ! -e \"\$3\" && test ! -L \"\$3\" && test -f \"\$4\"" \
        sh "$managed_path" "$state_path" "$journal_path" "$artifact_path" \
        || fail 'Canonical recovery did not finish the interrupted publication journal'
    actual_state=$(compose exec -T config-writer cat "$state_path")
    [ "$actual_state" = "$expected_state" ] || fail 'Canonical recovery did not persist the exact forward destination-fence state'
}

assert_rolled_back_application_artifacts() {
    local plan=$1
    local managed_path state_path journal_path artifact_path expected_state actual_state

    managed_path=$(jq -er '.managedPath' "$plan")
    state_path=$(jq -er '.statePath' "$plan")
    journal_path=$(jq -er '.journalPath' "$plan")
    artifact_path=$(jq -er '.rollbackArtifactPath' "$plan")
    expected_state=$(jq -er '.rollbackStateBase64' "$plan" | decode_base64)
    compose exec -T config-writer sh -c \
        "test ! -e \"\$1\" && test ! -L \"\$1\" && test -f \"\$2\" && test ! -e \"\$3\" && test ! -L \"\$3\" && test -f \"\$4\"" \
        sh "$managed_path" "$state_path" "$journal_path" "$artifact_path" \
        || fail 'Canonical rollback did not restore the exact absent File-provider route state'
    actual_state=$(compose exec -T config-writer cat "$state_path")
    [ "$actual_state" = "$expected_state" ] || fail 'Canonical rollback did not persist the exact monotonic rollback state'
}

publish_application_phase() {
    local phase_file=$1
    local phase=$2
    local staged_phase

    staged_phase=$(mktemp "${phase_file}.XXXXXX")
    jq -n --arg phase "$phase" --argjson published_at "$(now_ms)" \
        '{phase: $phase, publishedAt: $published_at}' > "$staged_phase"
    mv -f -- "$staged_phase" "$phase_file"
}

start_application_observer() {
    local log_file=$1
    local stop_file=$2
    local phase_file=$3
    local completed_at_ms
    local phase_after
    local phase_before
    local record
    local started_at_ms

    : > "$log_file"
    rm -f -- "$stop_file"
    (
        trap - EXIT
        while [ ! -e "$stop_file" ]; do
            phase_before=$(jq -er '.phase' "$phase_file")
            started_at_ms=$(now_ms)
            record=$(fetch_application_route)
            completed_at_ms=$(now_ms)
            phase_after=$(jq -er '.phase' "$phase_file")
            if [ "$phase_before" = "$phase_after" ]; then
                printf '%s|%s|%s|%s\n' \
                    "$started_at_ms" "$completed_at_ms" "$phase_before" "$record" >> "$log_file"
            fi
            sleep 0.05
        done
    ) &
    APPLICATION_OBSERVER_PID=$!
    register_background_pid "$APPLICATION_OBSERVER_PID"
}

wait_for_application_phase_backend() {
    local log_file=$1
    local phase=$2
    local expected_backend=$3
    local expected_acknowledgement=$4
    local deadline=$(( $(now_ms) + MAX_RELOAD_DELAY_MS ))
    local observed_count

    while [ "$(now_ms)" -lt "$deadline" ]; do
        background_pid_is_running "$APPLICATION_OBSERVER_PID" \
            || fail "Application availability observer terminated during phase $phase"
        observed_count=$(awk -F'|' \
            -v acknowledgement="$expected_acknowledgement" \
            -v backend="$expected_backend" \
            -v phase="$phase" '
                $3 == phase && $4 == "200" && $5 == backend && $6 == acknowledgement { count += 1 }
                END { print count + 0 }
            ' "$log_file")
        if [ "$observed_count" -ge "$MIN_APPLICATION_PHASE_REQUESTS" ]; then
            return
        fi
        sleep 0.05
    done
    fail "Application availability phase $phase did not record $MIN_APPLICATION_PHASE_REQUESTS requests from $expected_backend"
}

assert_application_observer() {
    local log_file=$1
    local public_acknowledgement=$2

    if ! python3 - "$log_file" "$public_acknowledgement" \
        "$MIN_APPLICATION_REQUESTS" "$MIN_APPLICATION_PHASE_REQUESTS" \
        "$MAX_APPLICATION_REQUEST_LATENCY_MS" "$MAX_APPLICATION_SAMPLE_GAP_MS" <<'PY'
import sys

(
    log_path,
    public_acknowledgement,
    minimum_requests,
    minimum_phase_requests,
    maximum_latency_ms,
    maximum_gap_ms,
) = sys.argv[1:]
minimum_requests = int(minimum_requests)
minimum_phase_requests = int(minimum_phase_requests)
maximum_latency_ms = int(maximum_latency_ms)
maximum_gap_ms = int(maximum_gap_ms)
phase_pairs = {
    'docker-prior': {('blue', '')},
    'forward-transition': {('blue', ''), ('green', public_acknowledgement)},
    'file-candidate': {('green', public_acknowledgement)},
    'predecessor-draining': {('green', public_acknowledgement)},
    'file-only': {('green', public_acknowledgement)},
    'docker-restored-shadowed': {('green', public_acknowledgement)},
    'rollback-transition': {('green', public_acknowledgement), ('blue', '')},
    'docker-restored': {('blue', '')},
}
phase_order = {phase: index for index, phase in enumerate(phase_pairs)}
phase_counts = {phase: {} for phase in phase_pairs}
records = []

with open(log_path, encoding='utf-8') as log_handle:
    for line_number, line in enumerate(log_handle, start=1):
        fields = line.rstrip('\n').split('|')
        if len(fields) != 7:
            raise ValueError(f'line {line_number} has {len(fields)} fields')
        started_at, completed_at, phase, status, backend, acknowledgement, duration = fields
        started_at = int(started_at)
        completed_at = int(completed_at)
        duration = int(duration)
        if phase not in phase_pairs:
            raise ValueError(f'line {line_number} has unknown phase {phase}')
        pair = (backend, acknowledgement)
        if status != '200' or pair not in phase_pairs[phase]:
            raise ValueError(f'line {line_number} violates phase {phase}: status={status} pair={pair}')
        if started_at > completed_at:
            raise ValueError(f'line {line_number} completes before it starts')
        if duration > maximum_latency_ms or completed_at - started_at > maximum_latency_ms:
            raise ValueError(f'line {line_number} exceeds latency bound: curl={duration} wall={completed_at - started_at}')
        phase_counts[phase][pair] = phase_counts[phase].get(pair, 0) + 1
        records.append((started_at, completed_at, phase))

if len(records) < minimum_requests:
    raise ValueError(f'only {len(records)} application requests were recorded')
if [phase_order[phase] for _, _, phase in records] != sorted(phase_order[phase] for _, _, phase in records):
    raise ValueError('application phases regressed')
for previous, current in zip(records, records[1:]):
    gap_ms = current[0] - previous[1]
    if gap_ms > maximum_gap_ms:
        raise ValueError(f'application availability gap {gap_ms}ms exceeds {maximum_gap_ms}ms')
for phase, expected_pairs in phase_pairs.items():
    for pair in expected_pairs:
        observed = phase_counts[phase].get(pair, 0)
        if observed < minimum_phase_requests:
            raise ValueError(f'phase {phase} pair {pair} has only {observed} requests')
PY
    then
        printf 'Application availability phase evidence:\n' >&2
        cat "$log_file" >&2
        printf 'Recent Traefik routing logs:\n' >&2
        compose logs --no-color --no-log-prefix --tail 200 traefik >&2 || true
        fail 'Application availability violated a phase, backend, latency, or gap contract'
    fi
}

start_held_application_transaction() {
    local headers_file=$1
    local body_file=$2
    local status_file=$3
    local error_file=$4

    curl --silent --show-error --insecure --noproxy '*' --connect-timeout 2 --max-time 65 \
        --request POST \
        --header "Host: ${APPLICATION_HOST}" \
        --header "Idempotency-Key: ${APPLICATION_TRANSACTION_KEY}" \
        --header 'Content-Type: application/json' \
        --data "$APPLICATION_TRANSACTION_PAYLOAD" \
        --dump-header "$headers_file" \
        --output "$body_file" \
        --write-out '%{http_code}' \
        "https://127.0.0.1:${TRAEFIK_HTTPS_PORT}/transactions?hold=forward-publication" \
        > "$status_file" 2> "$error_file" &
    HELD_TRANSACTION_PID=$!
    register_background_pid "$HELD_TRANSACTION_PID"
}

post_application_transaction() {
    local headers_file=$1
    local body_file=$2
    local status_file=$3

    curl --silent --show-error --insecure --noproxy '*' --connect-timeout 2 --max-time 5 \
        --request POST \
        --header "Host: ${APPLICATION_HOST}" \
        --header "Idempotency-Key: ${APPLICATION_TRANSACTION_KEY}" \
        --header 'Content-Type: application/json' \
        --data "$APPLICATION_TRANSACTION_PAYLOAD" \
        --dump-header "$headers_file" \
        --output "$body_file" \
        --write-out '%{http_code}' \
        "https://127.0.0.1:${TRAEFIK_HTTPS_PORT}/transactions" > "$status_file"
}

assert_application_transaction_response() {
    local headers_file=$1
    local body_file=$2
    local status_file=$3
    local expected_status=$4
    local expected_backend=$5
    local expected_created=$6
    local expected_acknowledgement=$7
    local expected_transaction_id=$8

    [ "$(cat "$status_file")" = "$expected_status" ] \
        || fail "Application transaction returned status $(cat "$status_file") instead of $expected_status"
    [ "$(header_value 'X-Integration-Backend' "$headers_file")" = "$expected_backend" ] \
        || fail 'Application transaction reached the wrong backend'
    [ "$(header_value 'X-Coolify-Probe-Ack' "$headers_file")" = "$expected_acknowledgement" ] \
        || fail 'Application transaction returned the wrong provider acknowledgement'
    jq -e \
        --arg backend "$expected_backend" \
        --arg transaction_id "$expected_transaction_id" \
        --argjson created "$expected_created" '
            .backend == $backend
            and .created == $created
            and .transactionId == $transaction_id
            and .writeCount == 1
            and (if $created then .replayedByBackend == null else .replayedByBackend == $backend end)
        ' "$body_file" >/dev/null || fail 'Application transaction response violated the idempotent write contract'
}

assert_durable_transaction_evidence() {
    local transaction_file=$1
    local first_body=$2
    local replay_body=$3
    local idempotency_hash=$4
    local payload_file="$TEMP_DIRECTORY/application-payload"
    local payload_sha
    local transaction_id

    printf '%s' "$APPLICATION_TRANSACTION_PAYLOAD" > "$payload_file"
    payload_sha=$(sha256_file "$payload_file")
    transaction_id=$(jq -er '.transactionId' "$transaction_file")
    jq -e \
        --arg idempotency_hash "$idempotency_hash" \
        --arg payload_sha "$payload_sha" \
        --arg transaction_id "$transaction_id" '
            .createdByBackend == "blue"
            and .idempotencyHash == $idempotency_hash
            and .payloadSha256 == $payload_sha
            and .transactionId == $transaction_id
            and .writeCount == 1
            and (.committedAt | type == "number")
        ' "$transaction_file" >/dev/null || fail 'Durable transaction record does not prove one exact committed write'
    [ "$(jq -er '.transactionId' "$first_body")" = "$transaction_id" ] \
        || fail 'Held predecessor response did not identify the durable transaction'
    [ "$(jq -er '.transactionId' "$replay_body")" = "$transaction_id" ] \
        || fail 'Candidate retry did not adopt the durable transaction'
    [ "$(wc -l < "$APPLICATION_STATE_DIR/durable-writes.log")" -eq 1 ] \
        || fail 'Durable write evidence was emitted more or less than exactly once'
    [ "$(wc -l < "$APPLICATION_STATE_DIR/transaction-attempts.log")" -eq 2 ] \
        || fail 'Application transaction retry evidence did not contain exactly two attempts'
    awk -F'|' -v transaction_id="$transaction_id" '
        NR == 1 && $2 == transaction_id && $3 == "created" && $4 == "blue" { created = 1 }
        NR == 2 && $2 == transaction_id && $3 == "replayed" && $4 == "green" { replayed = 1 }
        END { exit created && replayed ? 0 : 1 }
    ' "$APPLICATION_STATE_DIR/transaction-attempts.log" \
        || fail 'Application transaction attempts were not one blue creation followed by one green replay'
}

assert_application_access_log() {
    local access_log="$TEMP_DIRECTORY/application-access.log"

    compose logs --no-color --no-log-prefix traefik > "$access_log"
    python3 - "$access_log" "$APPLICATION_HOST" "$MIN_APPLICATION_REQUESTS" <<'PY' \
        || fail 'Traefik access logs did not prove enough application requests without 404 or gateway responses'
import json
import sys

log_path, expected_host, minimum_requests = sys.argv[1], sys.argv[2], int(sys.argv[3])
records = []
with open(log_path, encoding='utf-8') as log_handle:
    for line in log_handle:
        start = line.find('{')
        if start < 0:
            continue
        try:
            record = json.loads(line[start:])
        except json.JSONDecodeError:
            continue
        if record.get('RequestHost') == expected_host:
            request_path = (record.get('RequestPath') or '').split('?', 1)[0]
            records.append((request_path, int(record.get('DownstreamStatus', 0))))
if len(records) < minimum_requests:
    print(f'application access-log requests={len(records)} required={minimum_requests}', file=sys.stderr)
    raise SystemExit(1)
invalid = [
    (path, status) for path, status in records
    if status == 404
    or status >= 500
    or (path == '/transport/ws' and status not in (0, 101))
    or (path != '/transport/ws' and not 200 <= status < 300)
]
websocket_statuses = [status for path, status in records if path == '/transport/ws']
sse_statuses = [status for path, status in records if path == '/transport/sse']
transaction_statuses = [status for path, status in records if path == '/transactions']
if invalid or len(websocket_statuses) < 2 or any(status not in (0, 101) for status in websocket_statuses):
    print(f'application access-log invalid={invalid} websocket={websocket_statuses}', file=sys.stderr)
    raise SystemExit(1)
if len(sse_statuses) < 2 or any(status != 200 for status in sse_statuses):
    print(f'application access-log sse={sse_statuses}', file=sys.stderr)
    raise SystemExit(1)
if sorted(transaction_statuses) != [200, 201]:
    print(f'application access-log transactions={transaction_statuses}', file=sys.stderr)
    raise SystemExit(1)
PY
}

start_transport_observer() {
    local transition=$1
    local backend=$2
    local state_directory=$3
    local host=${4:-$CONTROL_PLANE_HOST}
    local log_name="transport-${transition}.log"
    local ready_name="transport-${transition}-ready.json"
    local release_name="transport-${transition}-release.json"
    local report_name="transport-${transition}-report.json"

    TRANSPORT_OBSERVER_LOG="$TEMP_DIRECTORY/$log_name"
    TRANSPORT_OBSERVER_READY="$state_directory/$ready_name"
    TRANSPORT_OBSERVER_RELEASE="$state_directory/$release_name"
    TRANSPORT_OBSERVER_REPORT="$state_directory/$report_name"
    rm -f -- "$TRANSPORT_OBSERVER_LOG" "$TRANSPORT_OBSERVER_READY" "$TRANSPORT_OBSERVER_RELEASE" "$TRANSPORT_OBSERVER_REPORT"

    compose exec -T "$backend" node /transport-observer.js \
        "/state/$report_name" \
        "/state/$ready_name" \
        "/state/$release_name" \
        "$host" \
        "$TRANSPORT_MIN_EVENT_COUNT" \
        "$TRANSPORT_OBSERVER_TIMEOUT_MS" > "$TRANSPORT_OBSERVER_LOG" 2>&1 &
    TRANSPORT_OBSERVER_PID=$!
    register_background_pid "$TRANSPORT_OBSERVER_PID"
}

wait_for_transport_observer_ready() {
    local transition=$1
    local ready_path=$2
    local observer_log=$3
    local deadline=$(( $(now_ms) + MAX_RELOAD_DELAY_MS ))

    while [ "$(now_ms)" -lt "$deadline" ]; do
        if [ -s "$ready_path" ] && jq -e '
            (.sse.startedAt | type == "number")
            and (.sse.firstEventAt | type == "number")
            and (.websocket.startedAt | type == "number")
            and (.websocket.firstEventAt | type == "number")
        ' "$ready_path" >/dev/null; then
            return
        fi
        sleep 0.05
    done
    cat "$observer_log" >&2 || true
    fail "$transition transport observer did not receive initial SSE and WebSocket events"
}

publish_transport_release() {
    local transition=$1
    local applied_at_ms=$2
    local release_path=$3
    local staged_release

    [[ $applied_at_ms =~ ^[0-9]{13,16}$ ]] || fail "Refusing to publish a malformed $transition transport barrier"
    staged_release=$(mktemp "${release_path}.XXXXXX")
    jq -n \
        --arg transition "$transition" \
        --argjson applied_at "$applied_at_ms" \
        '{transition: $transition, appliedAt: $applied_at}' > "$staged_release"
    mv -f -- "$staged_release" "$release_path"
}

wait_for_transport_observer() {
    local transition=$1
    local observer_pid=$2
    local observer_log=$3
    local observer_report=$4

    if ! wait_for_registered_background_pid "$observer_pid"; then
        cat "$observer_log" >&2 || true
        fail "$transition transport observer terminated before crossing its applied-config barrier"
    fi
    [ -s "$observer_report" ] || fail "$transition transport observer did not publish a continuity report"
}

assert_transport_continuity() {
    local report_file=$1
    local transition=$2
    local observer_not_before_ms=$3
    local switch_started_at_ms=$4
    local transition_applied_at_ms=$5
    local expected_backend=$6
    local expected_color=$7
    local expected_generation=$8
    local expected_dynamic_sha=$9

    jq -e \
        --arg backend "$expected_backend" \
        --arg color "$expected_color" \
        --arg dynamic_sha "$expected_dynamic_sha" \
        --arg generation "$expected_generation" \
        --arg transition "$transition" \
        --argjson applied_at "$transition_applied_at_ms" \
        --argjson minimum_count "$TRANSPORT_MIN_EVENT_COUNT" \
        --argjson observer_not_before "$observer_not_before_ms" \
        --argjson switch_started_at "$switch_started_at_ms" '
            .minimumEventCount == $minimum_count
            and .release == {transition: $transition, appliedAt: $applied_at}
            and .sse.release == .release
            and .websocket.release == .release
            and .sse.status == 200
            and .websocket.status == 101
            and .sse.startedAt >= $observer_not_before
            and .websocket.startedAt >= $observer_not_before
            and .sse.startedAt < $switch_started_at
            and .websocket.startedAt < $switch_started_at
            and .sse.events[0].receivedAt < $switch_started_at
            and .websocket.events[0].receivedAt < $switch_started_at
            and .sse.events[-1].receivedAt >= $applied_at
            and .websocket.events[-1].receivedAt >= $applied_at
            and .sse.completedAt >= $applied_at
            and .websocket.completedAt >= $applied_at
            and (.sse.events | length >= $minimum_count)
            and (.websocket.events | length >= $minimum_count)
            and ([.sse.events[].sequence] == [range(1; (.sse.events | length) + 1)])
            and ([.websocket.events[].sequence] == [range(1; (.websocket.events | length) + 1)])
            and ([.sse.events[].receivedAt] == ([.sse.events[].receivedAt] | sort))
            and ([.websocket.events[].receivedAt] == ([.websocket.events[].receivedAt] | sort))
            and (([.sse.events[].backend] | unique) == [$backend])
            and (([.websocket.events[].backend] | unique) == [$backend])
            and (([.sse.events[].color] | unique) == [$color])
            and (([.websocket.events[].color] | unique) == [$color])
            and (([.sse.events[].generation] | unique) == [$generation])
            and (([.websocket.events[].generation] | unique) == [$generation])
            and (([.sse.events[].dynamicSha] | unique) == [$dynamic_sha])
            and (([.websocket.events[].dynamicSha] | unique) == [$dynamic_sha])
            and (([.sse.events[].transport] | unique) == ["sse"])
            and (([.websocket.events[].transport] | unique) == ["websocket"])
            and ([.sse.events[].connectionId] | unique | length == 1)
            and ([.websocket.events[].connectionId] | unique | length == 1)
        ' "$report_file" >/dev/null || fail "$transition SSE or WebSocket connection did not cross the applied-config barrier"
}

assert_full_cycle_transport_continuity() {
    local report_file=$1
    local forward_switch_started_at_ms=$2
    local forward_applied_at_ms=$3
    local rollback_switch_started_at_ms=$4
    local rollback_applied_at_ms=$5
    local expected_backend=$6
    local expected_color=$7
    local expected_generation=$8
    local expected_dynamic_sha=$9

    assert_transport_continuity "$report_file" full-cycle 0 "$forward_switch_started_at_ms" "$rollback_applied_at_ms" \
        "$expected_backend" "$expected_color" "$expected_generation" "$expected_dynamic_sha"
    jq -e \
        --argjson forward_applied_at "$forward_applied_at_ms" \
        --argjson rollback_started_at "$rollback_switch_started_at_ms" '
            any(.sse.events[]; .receivedAt >= $forward_applied_at and .receivedAt < $rollback_started_at)
            and any(.websocket.events[]; .receivedAt >= $forward_applied_at and .receivedAt < $rollback_started_at)
        ' "$report_file" >/dev/null \
        || fail 'Original SSE or WebSocket connection did not remain active throughout the green-applied interval'
}

write_transport_report_fixture() {
    local destination=$1
    local fixture_kind=$2
    local staged_fixture

    jq -n '
        def events($transport; $connection_id):
            [
                {sequence: 1, receivedAt: 1500},
                {sequence: 2, receivedAt: 2500},
                {sequence: 3, receivedAt: 3500},
                {sequence: 4, receivedAt: 4500},
                {sequence: 5, receivedAt: 5500},
                {sequence: 6, receivedAt: 6500}
            ]
            | map(. + {
                backend: "blue",
                color: "blue",
                connectionId: $connection_id,
                dynamicSha: "sha-blue",
                generation: "generation-blue",
                transport: $transport
            });
        {
            minimumEventCount: 5,
            release: {transition: "full-cycle", appliedAt: 6000},
            sse: {
                completedAt: 6500,
                events: events("sse"; "sse-connection"),
                release: {transition: "full-cycle", appliedAt: 6000},
                startedAt: 1000,
                status: 200
            },
            websocket: {
                completedAt: 6500,
                events: events("websocket"; "websocket-connection"),
                release: {transition: "full-cycle", appliedAt: 6000},
                startedAt: 1000,
                status: 101
            }
        }
    ' > "$destination"

    [ "$fixture_kind" = valid ] && return
    staged_fixture=$(mktemp "${destination}.XXXXXX")
    case "$fixture_kind" in
        wrong-backend)
            jq '.sse.events[2].backend = "green"' "$destination" > "$staged_fixture"
            ;;
        wrong-color)
            jq '.websocket.events[2].color = "green"' "$destination" > "$staged_fixture"
            ;;
        wrong-generation)
            jq '.sse.events[2].generation = "generation-green"' "$destination" > "$staged_fixture"
            ;;
        wrong-dynamic-sha)
            jq '.websocket.events[2].dynamicSha = "sha-green"' "$destination" > "$staged_fixture"
            ;;
        changed-connection-id)
            jq '.sse.events[3].connectionId = "sse-successor"' "$destination" > "$staged_fixture"
            ;;
        missing-green-interval)
            jq '
                .sse.events |= map(if .receivedAt >= 3000 and .receivedAt < 5000 then .receivedAt = 2500 else . end)
                | .websocket.events |= map(if .receivedAt >= 3000 and .receivedAt < 5000 then .receivedAt = 2500 else . end)
            ' "$destination" > "$staged_fixture"
            ;;
        missing-post-rollback)
            jq '
                .sse.events |= map(if .receivedAt >= 6000 then .receivedAt = 5500 else . end)
                | .websocket.events |= map(if .receivedAt >= 6000 then .receivedAt = 5500 else . end)
                | .sse.completedAt = 5500
                | .websocket.completedAt = 5500
            ' "$destination" > "$staged_fixture"
            ;;
        *)
            rm -f -- "$staged_fixture"
            fail "Unknown transport report fixture: $fixture_kind"
            ;;
    esac
    mv -f -- "$staged_fixture" "$destination"
}

expect_transport_report_failure() {
    local report_file=$1
    local fixture_kind=$2

    if (
        trap - EXIT
        assert_full_cycle_transport_continuity "$report_file" 2000 3000 5000 6000 \
            blue blue generation-blue sha-blue
    ) >/dev/null 2>&1; then
        fail "Transport report fixture unexpectedly passed: $fixture_kind"
    fi
}

assert_transport_report_validation() {
    local fixture_kind
    local report_file

    for fixture_kind in valid wrong-backend wrong-color wrong-generation wrong-dynamic-sha changed-connection-id missing-green-interval missing-post-rollback; do
        report_file="$TEMP_DIRECTORY/transport-report-${fixture_kind}.json"
        write_transport_report_fixture "$report_file" "$fixture_kind"
        if [ "$fixture_kind" = valid ]; then
            assert_full_cycle_transport_continuity "$report_file" 2000 3000 5000 6000 \
                blue blue generation-blue sha-blue
        else
            expect_transport_report_failure "$report_file" "$fixture_kind"
        fi
    done
}

assert_cleanup_exit_status_contract() {
    local cleanup_case
    local primary_status
    local compose_cleanup_status
    local temporary_directory_cleanup_status
    local cleanup_exit_status
    local expected_status
    local child_exit_status
    local expected_cleanup_message
    local output_file
    local fixture_base
    local fixture_directory
    local fake_command_directory
    local fake_docker
    local fake_rm
    local -a expected_cleanup_messages=()

    for cleanup_case in compose temporary-directory combined; do
        expected_cleanup_messages=()
        case "$cleanup_case" in
            compose)
                compose_cleanup_status=42
                temporary_directory_cleanup_status=0
                cleanup_exit_status=$compose_cleanup_status
                expected_cleanup_messages+=("owned Compose project cleanup failed with status $compose_cleanup_status")
                ;;
            temporary-directory)
                compose_cleanup_status=0
                temporary_directory_cleanup_status=43
                cleanup_exit_status=$temporary_directory_cleanup_status
                expected_cleanup_messages+=("owned temporary directory cleanup failed with status $temporary_directory_cleanup_status")
                ;;
            combined)
                compose_cleanup_status=42
                temporary_directory_cleanup_status=43
                cleanup_exit_status=$compose_cleanup_status
                expected_cleanup_messages+=("owned Compose project cleanup failed with status $compose_cleanup_status")
                expected_cleanup_messages+=("owned temporary directory cleanup failed with status $temporary_directory_cleanup_status")
                ;;
            *)
                fail "Unsupported cleanup self-test case: $cleanup_case"
                ;;
        esac

        for primary_status in 0 23; do
            if [ "$primary_status" -eq 0 ]; then
                expected_status=$cleanup_exit_status
            else
                expected_status=$primary_status
            fi
            output_file=$(mktemp "$TEMP_DIRECTORY/cleanup-status.${cleanup_case}.${primary_status}.XXXXXX")
            fixture_base=$(mktemp -d "$TEMP_DIRECTORY/cleanup-fixture.${cleanup_case}.${primary_status}.XXXXXX")
            fixture_directory="$fixture_base/coolify-control-plane-traefik.cleanup"
            fake_command_directory="$fixture_base/fake-command-bin"
            fake_docker="$fake_command_directory/docker"
            fake_rm="$fake_command_directory/rm"
            mkdir -p "$fake_command_directory"
            printf '%s\n' '#!/usr/bin/env bash' "exit $compose_cleanup_status" > "$fake_docker"
            chmod 700 "$fake_docker"
            if [ "$temporary_directory_cleanup_status" -ne 0 ]; then
                printf '%s\n' \
                    '#!/usr/bin/env bash' \
                    'set -euo pipefail' \
                    "if [ \"\$#\" -ne 3 ] || [ \"\$1\" != \"-rf\" ] || [ \"\$2\" != \"--\" ] || [ \"\$3\" != \"\${CONTROL_PLANE_TRAEFIK_CLEANUP_SELF_TEST_TEMP_DIRECTORY:?}\" ]; then" \
                    '    printf "Refusing unexpected cleanup self-test rm invocation.\\n" >&2' \
                    '    exit 97' \
                    'fi' \
                    "exit $temporary_directory_cleanup_status" > "$fake_rm"
                chmod 700 "$fake_rm"
            fi

            if PATH="$fake_command_directory:$PATH" \
                CONTROL_PLANE_TRAEFIK_CLEANUP_SELF_TEST_ROOT="$TEMP_DIRECTORY" \
                CONTROL_PLANE_TRAEFIK_CLEANUP_SELF_TEST_TEMP_DIRECTORY="$fixture_directory" \
                "$SCRIPT_DIRECTORY/run.sh" --self-test-cleanup-child \
                "$primary_status" "$fixture_base" "$fixture_directory" "$cleanup_case" > "$output_file" 2>&1; then
                child_exit_status=0
            else
                child_exit_status=$?
            fi

            if [ "$child_exit_status" -ne "$expected_status" ]; then
                rm -rf -- "$fixture_base" "$output_file"
                fail "Cleanup self-test exited $child_exit_status instead of $expected_status for $cleanup_case primary status $primary_status"
            fi
            for expected_cleanup_message in "${expected_cleanup_messages[@]}"; do
                if ! grep -Fq "$expected_cleanup_message" "$output_file"; then
                    rm -rf -- "$fixture_base" "$output_file"
                    fail "Cleanup self-test did not report $expected_cleanup_message for $cleanup_case primary status $primary_status"
                fi
            done
            if [ "$temporary_directory_cleanup_status" -eq 0 ] && [ -e "$fixture_directory" ]; then
                rm -rf -- "$fixture_base" "$output_file"
                fail "Cleanup self-test did not remove its owned temporary directory for $cleanup_case primary status $primary_status"
            fi
            if [ "$temporary_directory_cleanup_status" -ne 0 ] && [ ! -d "$fixture_directory" ]; then
                rm -rf -- "$fixture_base" "$output_file"
                fail "Cleanup self-test did not preserve the injected temporary-directory cleanup failure for $cleanup_case primary status $primary_status"
            fi
            rm -rf -- "$fixture_base" "$output_file"
        done
    done
}

run_cleanup_self_test_child() {
    local primary_status=$1
    local fixture_base=$2
    local fixture_directory=$3
    local cleanup_case=$4
    local fake_command_directory="$fixture_base/fake-command-bin"
    local fake_docker="$fake_command_directory/docker"
    local fake_rm="$fake_command_directory/rm"
    local self_test_root=${CONTROL_PLANE_TRAEFIK_CLEANUP_SELF_TEST_ROOT:-}

    case "$primary_status" in
        0 | 23)
            ;;
        *)
            fail "Unsupported cleanup self-test primary status: $primary_status"
            ;;
    esac
    [ -x "$fake_docker" ] || fail "Cleanup self-test fake Docker executable is unavailable: $fake_docker"
    [ "$(command -v docker)" = "$fake_docker" ] \
        || fail 'Cleanup self-test refuses to run without its fake Docker executable first in PATH'
    case "$self_test_root" in
        ?*)
            ;;
        *)
            fail 'Cleanup self-test root is unavailable'
            ;;
    esac
    case "$fixture_base" in
        "$self_test_root"/cleanup-fixture.*)
            ;;
        *)
            fail "Unexpected cleanup self-test fixture base: $fixture_base"
            ;;
    esac
    case "$fixture_directory" in
        "$fixture_base"/coolify-control-plane-traefik.cleanup)
            ;;
        *)
            fail "Unexpected cleanup self-test temporary directory: $fixture_directory"
            ;;
    esac
    case "$cleanup_case" in
        compose)
            [ ! -e "$fake_rm" ] || fail "Unexpected fake rm executable for cleanup self-test case: $cleanup_case"
            ;;
        temporary-directory | combined)
            [ -x "$fake_rm" ] || fail "Cleanup self-test fake rm executable is unavailable: $fake_rm"
            [ "$(command -v rm)" = "$fake_rm" ] \
                || fail 'Cleanup self-test refuses to run without its fake rm executable first in PATH'
            ;;
        *)
            fail "Unsupported cleanup self-test case: $cleanup_case"
            ;;
    esac

    COMPOSE_STARTED=1
    PROJECT_NAME='cpbg-cleanup-self-test'
    TEMP_BASE=$fixture_base
    TEMP_DIRECTORY=$fixture_directory
    BACKGROUND_PIDS=()
    mkdir -p "$TEMP_DIRECTORY"
    exit "$primary_status"
}

start_transition_observer() {
    local log_file=$1
    local applied_barrier_file=$2
    local attempt=1
    local first_https_observed_at_ms=0
    local first_app_port_observed_at_ms=0
    local https_observed_at_ms=0
    local app_port_observed_at_ms=0
    local observer_started_at_ms
    local record
    local transition_applied_at_ms=0

    : > "$log_file"
    (
        trap - EXIT
        observer_started_at_ms=$(monotonic_ms)
        while :; do
            record=$(fetch_route https)
            https_observed_at_ms=$(now_ms)
            printf '%s|https|%s\n' "$https_observed_at_ms" "$record" >> "$log_file"

            record=$(fetch_route app-port)
            app_port_observed_at_ms=$(now_ms)
            printf '%s|app-port|%s\n' "$app_port_observed_at_ms" "$record" >> "$log_file"

            if [ "$attempt" -eq 1 ]; then
                first_https_observed_at_ms=$https_observed_at_ms
                first_app_port_observed_at_ms=$app_port_observed_at_ms
            fi
            if [ -s "$applied_barrier_file" ]; then
                transition_applied_at_ms=$(jq -er '.appliedAt | select(type == "number")' "$applied_barrier_file")
            fi
            if [ "$attempt" -ge "$TRANSITION_ATTEMPTS" ] \
                && [ "$((https_observed_at_ms - first_https_observed_at_ms))" -ge "$MIN_TRANSITION_OBSERVATION_MS" ] \
                && [ "$((app_port_observed_at_ms - first_app_port_observed_at_ms))" -ge "$MIN_TRANSITION_OBSERVATION_MS" ] \
                && [ "$transition_applied_at_ms" -gt 0 ] \
                && [ "$https_observed_at_ms" -ge "$transition_applied_at_ms" ] \
                && [ "$app_port_observed_at_ms" -ge "$transition_applied_at_ms" ]; then
                break
            fi
            if [ "$(( $(monotonic_ms) - observer_started_at_ms ))" -ge "$MAX_TRANSITION_OBSERVATION_MS" ]; then
                printf '%s\n' 'Transition observer did not cross the applied-config barrier within the bounded observation window' >&2
                exit 1
            fi
            sleep "$TRANSITION_SAMPLE_DELAY_SECONDS"
            attempt=$((attempt + 1))
        done
    ) &
    TRANSITION_OBSERVER_PID=$!
    register_background_pid "$TRANSITION_OBSERVER_PID"
}

wait_for_observer_samples() {
    local log_file=$1
    local deadline=$(( $(now_ms) + MAX_RELOAD_DELAY_MS ))

    while [ "$(now_ms)" -lt "$deadline" ]; do
        if [ "$(wc -l < "$log_file")" -ge 8 ]; then
            return
        fi
        sleep 0.05
    done
    fail 'Transition observer did not collect baseline traffic'
}

assert_transition_log() {
    local log_file=$1
    local old_color=$2
    local old_generation=$3
    local old_acknowledgement=$4
    local old_dynamic_sha=$5
    local new_color=$6
    local new_generation=$7
    local new_acknowledgement=$8
    local new_dynamic_sha=$9
    local new_backend=${10}
    local old_backend=${11}
    local transition_applied_at_ms=${12}
    local observed_at_ms route status color generation acknowledgement dynamic_sha backend
    local https_samples=0
    local app_port_samples=0
    local old_https=0
    local old_app_port=0
    local new_https=0
    local new_app_port=0
    local new_https_after_applied=0
    local new_app_port_after_applied=0
    local first_https_observed_at_ms=0
    local first_app_port_observed_at_ms=0
    local last_observed_at_ms=0
    local last_https_observed_at_ms=0
    local last_app_port_observed_at_ms=0
    local https_observation_ms
    local app_port_observation_ms

    [[ $transition_applied_at_ms =~ ^[0-9]{13,16}$ ]] || fail 'Transition validator received a malformed applied-config timestamp'
    awk -F'|' 'NF != 8 { exit 1 }' "$log_file" || fail 'Transition observer recorded a malformed sample'
    while IFS='|' read -r observed_at_ms route status color generation acknowledgement dynamic_sha backend; do
        [[ $observed_at_ms =~ ^[0-9]{13,16}$ ]] || fail 'Transition observer recorded a malformed epoch-millisecond timestamp'
        if [ "$last_observed_at_ms" -gt 0 ] && [ "$observed_at_ms" -lt "$last_observed_at_ms" ]; then
            fail 'Transition observer recorded nonmonotonic sample timestamps'
        fi
        last_observed_at_ms=$observed_at_ms
        [ "$status" = 200 ] || fail "Transition observer saw $route status $status"
        if [ "$color" = "$old_color" ] && [ "$generation" = "$old_generation" ] && [ "$acknowledgement" = "$old_acknowledgement" ] && [ "$dynamic_sha" = "$old_dynamic_sha" ] && [ "$backend" = "$old_backend" ]; then
            if [ "$route" = https ]; then
                old_https=$((old_https + 1))
            else
                old_app_port=$((old_app_port + 1))
            fi
        elif [ "$color" = "$new_color" ] && [ "$generation" = "$new_generation" ] && [ "$acknowledgement" = "$new_acknowledgement" ] && [ "$dynamic_sha" = "$new_dynamic_sha" ] && [ "$backend" = "$new_backend" ]; then
            if [ "$route" = https ]; then
                new_https=$((new_https + 1))
                if [ "$observed_at_ms" -ge "$transition_applied_at_ms" ]; then
                    new_https_after_applied=$((new_https_after_applied + 1))
                fi
            else
                new_app_port=$((new_app_port + 1))
                if [ "$observed_at_ms" -ge "$transition_applied_at_ms" ]; then
                    new_app_port_after_applied=$((new_app_port_after_applied + 1))
                fi
            fi
        else
            fail "Transition observer saw a mixed or partial route identity on $route"
        fi

        if [ "$route" = https ]; then
            if [ "$last_https_observed_at_ms" -gt 0 ] && [ "$observed_at_ms" -lt "$last_https_observed_at_ms" ]; then
                fail 'Transition observer recorded nonmonotonic HTTPS timestamps'
            fi
            if [ "$first_https_observed_at_ms" -eq 0 ]; then
                first_https_observed_at_ms=$observed_at_ms
            fi
            last_https_observed_at_ms=$observed_at_ms
            https_samples=$((https_samples + 1))
        elif [ "$route" = app-port ]; then
            if [ "$last_app_port_observed_at_ms" -gt 0 ] && [ "$observed_at_ms" -lt "$last_app_port_observed_at_ms" ]; then
                fail 'Transition observer recorded nonmonotonic APP_PORT timestamps'
            fi
            if [ "$first_app_port_observed_at_ms" -eq 0 ]; then
                first_app_port_observed_at_ms=$observed_at_ms
            fi
            last_app_port_observed_at_ms=$observed_at_ms
            app_port_samples=$((app_port_samples + 1))
        else
            fail "Transition observer recorded an unknown route: $route"
        fi
    done < "$log_file"

    [ "$https_samples" -ge "$TRANSITION_ATTEMPTS" ] || fail 'Transition observer did not sample HTTPS enough times'
    [ "$app_port_samples" -ge "$TRANSITION_ATTEMPTS" ] || fail 'Transition observer did not sample APP_PORT enough times'
    [ "$old_https" -gt 0 ] && [ "$old_app_port" -gt 0 ] || fail 'Transition observer never saw the predecessor identity on both routes'
    [ "$new_https" -gt 0 ] && [ "$new_app_port" -gt 0 ] || fail 'Transition observer never saw the replacement identity on both routes'
    [ "$new_https_after_applied" -gt 0 ] && [ "$new_app_port_after_applied" -gt 0 ] \
        || fail 'Transition observer did not sample both replacement routes after their applied-config barrier'
    https_observation_ms=$((last_https_observed_at_ms - first_https_observed_at_ms))
    app_port_observation_ms=$((last_app_port_observed_at_ms - first_app_port_observed_at_ms))
    [ "$https_observation_ms" -ge "$MIN_TRANSITION_OBSERVATION_MS" ] \
        || fail "Transition observer sampled HTTPS for only ${https_observation_ms}ms"
    [ "$app_port_observation_ms" -ge "$MIN_TRANSITION_OBSERVATION_MS" ] \
        || fail "Transition observer sampled APP_PORT for only ${app_port_observation_ms}ms"
}

write_transition_log_fixture() {
    local log_file=$1
    local fixture_kind=$2
    local attempt
    local observed_at_ms
    local interval_ms=220
    local color='blue'
    local generation='generation-blue'
    local acknowledgement='ack-blue'
    local dynamic_sha='sha-blue'
    local backend='blue'
    local malformed_suffix

    if [ "$fixture_kind" = too-short ]; then
        interval_ms=100
    fi
    : > "$log_file"
    for ((attempt = 1; attempt <= TRANSITION_ATTEMPTS; attempt++)); do
        observed_at_ms=$((1700000000000 + (attempt - 1) * interval_ms))
        malformed_suffix=''
        if [ "$attempt" -gt 12 ]; then
            color='green'
            generation='generation-green'
            acknowledgement='ack-green'
            dynamic_sha='sha-green'
            backend='green'
        fi
        if [ "$fixture_kind" = wrong-predecessor-backend ] && [ "$attempt" -le 12 ]; then
            backend='green'
        fi
        if [ "$fixture_kind" = nonmonotonic ] && [ "$attempt" -eq 13 ]; then
            observed_at_ms=1699999999999
        fi
        if [ "$fixture_kind" = malformed ] && [ "$attempt" -eq 7 ]; then
            malformed_suffix='|unexpected'
        fi
        printf '%s|https|200|%s|%s|%s|%s|%s%s\n' \
            "$observed_at_ms" "$color" "$generation" "$acknowledgement" "$dynamic_sha" "$backend" "$malformed_suffix" >> "$log_file"
        printf '%s|app-port|200|%s|%s|%s|%s|%s\n' \
            "$observed_at_ms" "$color" "$generation" "$acknowledgement" "$dynamic_sha" "$backend" >> "$log_file"
    done
}

expect_transition_log_failure() {
    local log_file=$1
    local expected_failure=$2
    local transition_applied_at_ms=$3

    if (assert_transition_log "$log_file" blue generation-blue ack-blue sha-blue green generation-green ack-green sha-green green blue "$transition_applied_at_ms") >/dev/null 2>&1; then
        fail "Transition-log validator accepted a ${expected_failure} fixture"
    fi
}

assert_transition_log_validation() {
    local fixture_kind
    local log_file
    local transition_applied_at_ms

    for fixture_kind in valid malformed nonmonotonic too-short wrong-predecessor-backend missing-post-barrier; do
        log_file="$TEMP_DIRECTORY/transition-${fixture_kind}.log"
        write_transition_log_fixture "$log_file" "$fixture_kind"
        transition_applied_at_ms=1700000003000
        if [ "$fixture_kind" = missing-post-barrier ]; then
            transition_applied_at_ms=1700000006000
        fi
        if [ "$fixture_kind" = valid ]; then
            assert_transition_log "$log_file" blue generation-blue ack-blue sha-blue green generation-green ack-green sha-green green blue "$transition_applied_at_ms"
        else
            expect_transition_log_failure "$log_file" "$fixture_kind" "$transition_applied_at_ms"
        fi
    done
}

traefik_container_id() {
    compose ps -q traefik
}

traefik_started_at() {
    docker inspect --format '{{.State.StartedAt}}' "$1"
}

assert_traefik_unchanged() {
    local expected_id=$1
    local expected_started_at=$2
    local actual_id
    local actual_started_at

    actual_id=$(traefik_container_id)
    actual_started_at=$(traefik_started_at "$actual_id")
    [ "$actual_id" = "$expected_id" ] || fail 'Traefik container was replaced during a file-provider switch'
    [ "$actual_started_at" = "$expected_started_at" ] || fail 'Traefik restarted during a file-provider switch'
}

route_request_count() {
    local count=0
    local state_directory

    for state_directory in "$BACKEND_BLUE_STATE_DIR" "$BACKEND_GREEN_STATE_DIR"; do
        if [ -f "$state_directory/route.log" ]; then
            count=$((count + $(wc -l < "$state_directory/route.log")))
        fi
    done
    printf '%s\n' "$count"
}

assert_production_application_lifecycle() {
    local blue_color=$1
    local blue_generation=$2
    local blue_dynamic_sha=$3
    local green_color=$4
    local green_generation=$5
    local green_dynamic_sha=$6
    local expected_traefik_id=$7
    local expected_traefik_started_at=$8
    local plan="$TEMP_DIRECTORY/application-lifecycle-plan.json"
    local boot_id
    local green_container_id
    local public_acknowledgement
    local availability_log="$TEMP_DIRECTORY/application-availability.log"
    local availability_phase="$TEMP_DIRECTORY/application-availability-phase.json"
    local availability_stop="$TEMP_DIRECTORY/application-availability.stop"
    local availability_pid
    local config_writer_after_recreate
    local config_writer_before_recreate
    local application_forward_transport_log application_forward_transport_pid application_forward_transport_ready application_forward_transport_release application_forward_transport_report
    local application_recovery_transport_log application_recovery_transport_pid application_recovery_transport_ready application_recovery_transport_release application_recovery_transport_report
    local held_headers="$TEMP_DIRECTORY/held-transaction-headers"
    local held_body="$TEMP_DIRECTORY/held-transaction-body.json"
    local held_status="$TEMP_DIRECTORY/held-transaction-status"
    local held_error="$TEMP_DIRECTORY/held-transaction-error.log"
    local replay_headers="$TEMP_DIRECTORY/replay-transaction-headers"
    local replay_body="$TEMP_DIRECTORY/replay-transaction-body.json"
    local replay_status="$TEMP_DIRECTORY/replay-transaction-status"
    local transaction_key_file="$TEMP_DIRECTORY/application-transaction-key"
    local idempotency_hash
    local transaction_file
    local transaction_id
    local application_forward_switch_started_at_ms
    local application_forward_applied_at_ms
    local stop_pid
    local stop_log="$TEMP_DIRECTORY/backend-blue-stop.log"
    local application_rollback_switch_started_at_ms
    local application_rollback_applied_at_ms

    wait_for_application_route blue ''
    wait_for_application_provider_inventory absent present blue
    boot_id=$(compose exec -T config-writer cat /proc/sys/kernel/random/boot_id | tr -d '\r\n')
    green_container_id=$(docker inspect --format '{{.Id}}' "$(compose ps -q backend-green)")
    compile_application_lifecycle_plan "$plan" "$boot_id" "$green_container_id"
    public_acknowledgement=$(jq -er '.publicAcknowledgement' "$plan")

    publish_application_phase "$availability_phase" docker-prior
    start_application_observer "$availability_log" "$availability_stop" "$availability_phase"
    availability_pid=$APPLICATION_OBSERVER_PID
    wait_for_application_phase_backend "$availability_log" docker-prior blue ''

    start_transport_observer application-forward backend-green "$BACKEND_GREEN_STATE_DIR" "$APPLICATION_HOST"
    application_forward_transport_log=$TRANSPORT_OBSERVER_LOG
    application_forward_transport_pid=$TRANSPORT_OBSERVER_PID
    application_forward_transport_ready=$TRANSPORT_OBSERVER_READY
    application_forward_transport_release=$TRANSPORT_OBSERVER_RELEASE
    application_forward_transport_report=$TRANSPORT_OBSERVER_REPORT
    wait_for_transport_observer_ready application-forward "$application_forward_transport_ready" "$application_forward_transport_log"

    start_held_application_transaction "$held_headers" "$held_body" "$held_status" "$held_error"
    printf '%s' "$APPLICATION_TRANSACTION_KEY" > "$transaction_key_file"
    idempotency_hash=$(sha256_file "$transaction_key_file")
    transaction_file="$APPLICATION_STATE_DIR/transactions/${idempotency_hash}.json"
    wait_for_host_file "$transaction_file" 'the held predecessor transaction to become durable'
    transaction_id=$(jq -er '.transactionId' "$transaction_file")
    background_pid_is_running "$HELD_TRANSACTION_PID" \
        || fail 'The predecessor transaction response was not held before route publication'

    publish_application_phase "$availability_phase" forward-transition
    wait_for_application_phase_backend "$availability_log" forward-transition blue ''
    application_forward_switch_started_at_ms=$(now_ms)
    run_application_writer_command "$plan" interruptedCommandBase64 86
    wait_for_application_route green "$public_acknowledgement"
    wait_for_application_provider_inventory present present green
    application_forward_applied_at_ms=$(now_ms)
    wait_for_application_phase_backend "$availability_log" forward-transition green "$public_acknowledgement"
    assert_interrupted_application_artifacts "$plan"
    background_pid_is_running "$HELD_TRANSACTION_PID" \
        || fail 'The held predecessor response ended during interrupted candidate publication'

    post_application_transaction "$replay_headers" "$replay_body" "$replay_status"
    assert_application_transaction_response \
        "$replay_headers" "$replay_body" "$replay_status" 200 green false "$public_acknowledgement" "$transaction_id"
    publish_application_phase "$availability_phase" file-candidate
    wait_for_application_phase_backend "$availability_log" file-candidate green "$public_acknowledgement"
    config_writer_before_recreate=$(compose ps -q config-writer)
    compose up -d --force-recreate config-writer
    config_writer_after_recreate=$(compose ps -q config-writer)
    [ -n "$config_writer_before_recreate" ] && [ -n "$config_writer_after_recreate" ] \
        && [ "$config_writer_before_recreate" != "$config_writer_after_recreate" ] \
        || fail 'Config writer was not recreated between interrupted publication and recovery'
    assert_interrupted_application_artifacts "$plan"
    wait_for_application_route green "$public_acknowledgement"
    wait_for_application_provider_inventory present present green
    assert_traefik_unchanged "$expected_traefik_id" "$expected_traefik_started_at"
    run_application_writer_command "$plan" recoveryCommandBase64 0
    assert_recovered_application_artifacts "$plan"

    publish_application_phase "$availability_phase" predecessor-draining
    wait_for_application_phase_backend "$availability_log" predecessor-draining green "$public_acknowledgement"
    compose stop --timeout 40 backend-blue > "$stop_log" 2>&1 &
    stop_pid=$!
    register_background_pid "$stop_pid"
    wait_for_host_file "$BACKEND_BLUE_STATE_DIR/shutdown.log" 'the predecessor graceful-shutdown marker'
    background_pid_is_running "$stop_pid" || fail 'The predecessor exited before held connections drained'
    background_pid_is_running "$HELD_TRANSACTION_PID" || fail 'The held predecessor response did not survive SIGTERM'
    wait_for_application_provider_inventory present present green
    wait_for_application_route green "$public_acknowledgement"

    touch "$APPLICATION_STATE_DIR/releases/forward-publication"
    if ! wait_for_registered_background_pid "$HELD_TRANSACTION_PID"; then
        cat "$held_error" >&2 || true
        fail 'The held predecessor transaction did not complete after its drain barrier'
    fi
    assert_application_transaction_response \
        "$held_headers" "$held_body" "$held_status" 201 blue true '' "$transaction_id"
    publish_transport_release application-forward "$application_forward_applied_at_ms" "$application_forward_transport_release"
    wait_for_transport_observer application-forward \
        "$application_forward_transport_pid" "$application_forward_transport_log" "$application_forward_transport_report"
    assert_transport_continuity "$application_forward_transport_report" application-forward 0 \
        "$application_forward_switch_started_at_ms" "$application_forward_applied_at_ms" \
        blue "$blue_color" "$blue_generation" "$blue_dynamic_sha"
    if ! wait_for_registered_background_pid "$stop_pid"; then
        cat "$stop_log" >&2 || true
        fail 'The predecessor did not stop cleanly after held transports drained'
    fi
    wait_for_application_provider_inventory present absent green
    wait_for_application_route green "$public_acknowledgement"
    publish_application_phase "$availability_phase" file-only
    wait_for_application_phase_backend "$availability_log" file-only green "$public_acknowledgement"

    compose up -d backend-blue
    wait_for_docker_provider_route
    wait_for_application_provider_inventory present present green
    wait_for_application_route green "$public_acknowledgement"
    publish_application_phase "$availability_phase" docker-restored-shadowed
    wait_for_application_phase_backend "$availability_log" docker-restored-shadowed green "$public_acknowledgement"

    start_transport_observer application-recovery backend-blue "$BACKEND_BLUE_STATE_DIR" "$APPLICATION_HOST"
    application_recovery_transport_log=$TRANSPORT_OBSERVER_LOG
    application_recovery_transport_pid=$TRANSPORT_OBSERVER_PID
    application_recovery_transport_ready=$TRANSPORT_OBSERVER_READY
    application_recovery_transport_release=$TRANSPORT_OBSERVER_RELEASE
    application_recovery_transport_report=$TRANSPORT_OBSERVER_REPORT
    wait_for_transport_observer_ready application-recovery "$application_recovery_transport_ready" "$application_recovery_transport_log"
    publish_application_phase "$availability_phase" rollback-transition
    wait_for_application_phase_backend "$availability_log" rollback-transition green "$public_acknowledgement"
    application_rollback_switch_started_at_ms=$(now_ms)
    run_application_writer_command "$plan" rollbackCommandBase64 0
    wait_for_application_route blue ''
    wait_for_application_provider_inventory absent present blue
    application_rollback_applied_at_ms=$(now_ms)
    wait_for_application_phase_backend "$availability_log" rollback-transition blue ''
    publish_application_phase "$availability_phase" docker-restored
    wait_for_application_phase_backend "$availability_log" docker-restored blue ''
    publish_transport_release application-recovery "$application_rollback_applied_at_ms" "$application_recovery_transport_release"
    wait_for_transport_observer application-recovery \
        "$application_recovery_transport_pid" "$application_recovery_transport_log" "$application_recovery_transport_report"
    assert_transport_continuity "$application_recovery_transport_report" application-recovery "$application_forward_applied_at_ms" \
        "$application_rollback_switch_started_at_ms" "$application_rollback_applied_at_ms" \
        green "$green_color" "$green_generation" "$green_dynamic_sha"
    run_application_writer_command "$plan" rollbackCommandBase64 0
    assert_rolled_back_application_artifacts "$plan"
    assert_traefik_unchanged "$expected_traefik_id" "$expected_traefik_started_at"

    sleep 0.25
    touch "$availability_stop"
    wait_for_registered_background_pid "$availability_pid" \
        || fail 'Application availability observer terminated unexpectedly'
    assert_application_observer "$availability_log" "$public_acknowledgement"
    assert_durable_transaction_evidence "$transaction_file" "$held_body" "$replay_body" "$idempotency_hash"
    assert_application_access_log
}

main() {
    local docker_context
    local docker_endpoint
    local initial_snapshot
    local blue_final_snapshot
    local green_snapshot
    local initial_dynamic_sha
    local blue_final_dynamic_sha
    local green_dynamic_sha
    local blue_initial_color='blue'
    local blue_initial_generation='generation-blue-1'
    local blue_initial_acknowledgement='ack-blue-11111111111111111111111111111111'
    local blue_final_color='blue'
    local blue_final_generation='generation-blue-2'
    local blue_final_acknowledgement='ack-blue-22222222222222222222222222222222'
    local green_color='green'
    local green_generation='generation-green-3'
    local green_acknowledgement='ack-green-33333333333333333333333333333333'
    local traefik_id
    local traefik_before_switch_started_at
    local switch_observer_log
    local switch_observer_pid
    local rollback_observer_log
    local rollback_observer_pid
    local forward_transport_log
    local forward_transport_pid
    local forward_transport_ready
    local forward_transport_release
    local forward_transport_report
    local forward_transition_barrier
    local rollback_transport_log
    local rollback_transport_pid
    local rollback_transport_ready
    local rollback_transport_release
    local rollback_transport_report
    local forward_switch_started_at_ms
    local forward_applied_at_ms
    local rollback_switch_started_at_ms
    local rollback_applied_at_ms
    local restart_started_at
    local total_route_requests

    if [ "${1:-}" = --self-test-cleanup-child ]; then
        [ "$#" -eq 5 ] || fail 'Usage: run.sh --self-test-cleanup-child <primary-status> <fixture-base> <fixture-directory> <cleanup-case>'
        run_cleanup_self_test_child "$2" "$3" "$4" "$5"
    fi
    if [ "$#" -gt 1 ] || { [ "$#" -eq 1 ] && [ "$1" != --self-test ]; }; then
        fail 'Usage: run.sh [--self-test]'
    fi
    if [ "${1:-}" = --self-test ]; then
        for command in python3 mktemp awk jq mkdir rm chmod grep; do
            require_command "$command"
        done
        initialize_temp_directory
        assert_transition_log_validation
        assert_transport_report_validation
        assert_cleanup_exit_status_contract
        printf 'PASS: transition-log, transport-report, and cleanup validation self-tests completed.\n'
        return
    fi

    for command in docker curl openssl php python3 mktemp awk sed tr cp mv wc jq; do
        require_command "$command"
    done
    [ -f "$REPOSITORY_ROOT/vendor/autoload.php" ] \
        || fail 'Composer dependencies are required for the production application lifecycle proof'
    "$SCRIPT_DIRECTORY/attestor-test.sh"
    if ! docker version --format '{{.Server.Version}}' >/dev/null 2>&1; then
        printf 'SKIP: Docker daemon is unavailable.\n' >&2
        exit 77
    fi

    docker_context=$(docker context show)
    docker_endpoint=${DOCKER_HOST:-$(docker context inspect "$docker_context" --format '{{ .Endpoints.docker.Host }}')}
    case "$docker_endpoint" in
        unix://*)
            TRAEFIK_DOCKER_SOCKET=${docker_endpoint#unix://}
            ;;
        *)
            printf 'SKIP: refusing to run the disposable integration against non-local Docker endpoint %s.\n' "$docker_endpoint" >&2
            exit 77
            ;;
    esac
    [ -S "$TRAEFIK_DOCKER_SOCKET" ] || fail "Docker socket is not a local socket: $TRAEFIK_DOCKER_SOCKET"

    initialize_temp_directory
    assert_transition_log_validation
    assert_transport_report_validation
    PROJECT_NAME="cpbg-control-plane-traefik-$$"
    TRAEFIK_CERT_DIR="$TEMP_DIRECTORY/certs"
    BACKEND_BLUE_STATE_DIR="$TEMP_DIRECTORY/backend-blue"
    BACKEND_GREEN_STATE_DIR="$TEMP_DIRECTORY/backend-green"
    APPLICATION_STATE_DIR="$TEMP_DIRECTORY/application-state"
    CONTROL_PLANE_HEALTH_PROOF="proof-$(openssl rand -hex 32)"
    CONTROL_PLANE_AUTHENTICATION_PROOF=$(openssl rand -hex 32)
    TRAEFIK_HTTPS_PORT=$(next_port)
    TRAEFIK_APP_PORT=$(next_port)
    TRAEFIK_API_PORT=$(next_port)
    CONTROL_PLANE_TRAEFIK_SCRIPT_DIRECTORY="$SCRIPT_DIRECTORY"
    TRAEFIK_IMAGE="traefik:$(jq -er '.traefik["v3.6"] | select(test("^[0-9]+\\.[0-9]+\\.[0-9]+$"))' "$REPOSITORY_ROOT/versions.json")" \
        || fail 'versions.json does not own an exact Traefik 3.6 release'
    export PROJECT_NAME TRAEFIK_CERT_DIR TRAEFIK_DOCKER_SOCKET BACKEND_BLUE_STATE_DIR BACKEND_GREEN_STATE_DIR APPLICATION_STATE_DIR CONTROL_PLANE_TRAEFIK_SCRIPT_DIRECTORY
    export CONTROL_PLANE_HEALTH_PROOF CONTROL_PLANE_AUTHENTICATION_PROOF TRAEFIK_HTTPS_PORT TRAEFIK_APP_PORT TRAEFIK_API_PORT TRAEFIK_IMAGE
    export COMPOSE_PROJECT_NAME="$PROJECT_NAME"

    mkdir -p "$TRAEFIK_CERT_DIR" "$BACKEND_BLUE_STATE_DIR" "$BACKEND_GREEN_STATE_DIR" "$APPLICATION_STATE_DIR"
    chmod 777 "$BACKEND_BLUE_STATE_DIR" "$BACKEND_GREEN_STATE_DIR" "$APPLICATION_STATE_DIR"
    touch "$BACKEND_BLUE_STATE_DIR/forwarded-identity.log" "$BACKEND_GREEN_STATE_DIR/forwarded-identity.log"
    chmod 666 "$BACKEND_BLUE_STATE_DIR/forwarded-identity.log" "$BACKEND_GREEN_STATE_DIR/forwarded-identity.log"
    openssl req -x509 -nodes -newkey rsa:2048 -days 1 -subj "/CN=${CONTROL_PLANE_HOST}" \
        -keyout "$TRAEFIK_CERT_DIR/key.pem" -out "$TRAEFIK_CERT_DIR/cert.pem" >/dev/null 2>&1

    initial_snapshot="$TEMP_DIRECTORY/initial.yaml"
    write_dynamic_snapshot "$initial_snapshot" "$blue_initial_color" "$blue_initial_generation" "$blue_initial_acknowledgement" backend-blue backend-green
    initial_dynamic_sha=$(sha256_file "$initial_snapshot")
    write_identity "$BACKEND_BLUE_STATE_DIR" "$blue_initial_color" "$blue_initial_generation" "$initial_dynamic_sha"
    write_identity "$BACKEND_GREEN_STATE_DIR" "$blue_initial_color" "$blue_initial_generation" "$initial_dynamic_sha"
    touch "$BACKEND_BLUE_STATE_DIR/healthy" "$BACKEND_GREEN_STATE_DIR/healthy"
    compose config -q
    compose pull
    COMPOSE_STARTED=1
    COMPOSE_STARTED_AT_MS=$(now_ms)
    compose up -d --remove-orphans --pull never
    [ "$(docker inspect --format '{{.Config.Image}}' "$(traefik_container_id)")" = "$TRAEFIK_IMAGE" ] \
        || fail 'Traefik did not start from the exact versions.json image'
    atomic_replace_snapshot "$initial_snapshot"

    wait_for_runtime_shared_service
    wait_for_docker_provider_route "$MAX_PROVIDER_START_DELAY_MS"
    wait_for_provider_health "$BACKEND_BLUE_STATE_DIR" backend-blue
    wait_for_provider_health "$BACKEND_GREEN_STATE_DIR" backend-green
    assert_forwarded_identity_boundary
    assert_provider_delay_bounds "$BACKEND_BLUE_STATE_DIR" backend-blue
    assert_provider_delay_bounds "$BACKEND_GREEN_STATE_DIR" backend-green
    assert_directly_healthy_twice backend-blue "$BACKEND_BLUE_STATE_DIR" "$initial_dynamic_sha"
    assert_directly_healthy_twice backend-green "$BACKEND_GREEN_STATE_DIR" "$initial_dynamic_sha"
    assert_routes_identity_twice "$blue_initial_color" "$blue_initial_generation" "$blue_initial_acknowledgement" "$initial_dynamic_sha"

    set_backend_health backend-blue false
    wait_for_unhealthy_provider_probe "$BACKEND_BLUE_STATE_DIR" backend-blue
    wait_for_route_identity https "$blue_initial_color" "$blue_initial_generation" "$blue_initial_acknowledgement" "$initial_dynamic_sha" green
    assert_routes_identity_twice "$blue_initial_color" "$blue_initial_generation" "$blue_initial_acknowledgement" "$initial_dynamic_sha" green

    set_backend_health backend-green false
    wait_for_route_status https 503
    wait_for_route_status app-port 503
    assert_route_status https 503
    assert_route_status app-port 503

    set_backend_health backend-blue true
    set_backend_health backend-green true
    wait_for_provider_health "$BACKEND_BLUE_STATE_DIR" backend-blue
    wait_for_provider_health "$BACKEND_GREEN_STATE_DIR" backend-green

    blue_final_snapshot="$TEMP_DIRECTORY/blue-final.yaml"
    write_dynamic_snapshot "$blue_final_snapshot" "$blue_final_color" "$blue_final_generation" "$blue_final_acknowledgement" backend-blue
    blue_final_dynamic_sha=$(sha256_file "$blue_final_snapshot")
    atomic_replace_snapshot "$blue_final_snapshot"
    set_backend_identity backend-blue "$blue_final_color" "$blue_final_generation" "$blue_final_dynamic_sha"
    wait_for_route_identity https "$blue_final_color" "$blue_final_generation" "$blue_final_acknowledgement" "$blue_final_dynamic_sha" blue
    wait_for_route_identity app-port "$blue_final_color" "$blue_final_generation" "$blue_final_acknowledgement" "$blue_final_dynamic_sha" blue
    assert_transport_http https blue "$blue_final_color" "$blue_final_generation" "$blue_final_dynamic_sha"
    assert_transport_http app-port blue "$blue_final_color" "$blue_final_generation" "$blue_final_dynamic_sha"

    green_snapshot="$TEMP_DIRECTORY/green.yaml"
    write_dynamic_snapshot "$green_snapshot" "$green_color" "$green_generation" "$green_acknowledgement" backend-green
    green_dynamic_sha=$(sha256_file "$green_snapshot")
    set_backend_identity backend-green "$green_color" "$green_generation" "$green_dynamic_sha"
    traefik_id=$(traefik_container_id)
    traefik_before_switch_started_at=$(traefik_started_at "$traefik_id")

    start_transport_observer forward backend-blue "$BACKEND_BLUE_STATE_DIR"
    forward_transport_log=$TRANSPORT_OBSERVER_LOG
    forward_transport_pid=$TRANSPORT_OBSERVER_PID
    forward_transport_ready=$TRANSPORT_OBSERVER_READY
    forward_transport_release=$TRANSPORT_OBSERVER_RELEASE
    forward_transport_report=$TRANSPORT_OBSERVER_REPORT
    forward_transition_barrier="$TEMP_DIRECTORY/forward-transition-barrier.json"
    rm -f -- "$forward_transition_barrier"
    wait_for_transport_observer_ready forward "$forward_transport_ready" "$forward_transport_log"
    switch_observer_log="$TEMP_DIRECTORY/switch-traffic.log"
    start_transition_observer "$switch_observer_log" "$forward_transition_barrier"
    switch_observer_pid=$TRANSITION_OBSERVER_PID
    wait_for_observer_samples "$switch_observer_log"
    forward_switch_started_at_ms=$(now_ms)
    atomic_replace_snapshot "$green_snapshot"
    wait_for_route_identity https "$green_color" "$green_generation" "$green_acknowledgement" "$green_dynamic_sha" green
    wait_for_route_identity app-port "$green_color" "$green_generation" "$green_acknowledgement" "$green_dynamic_sha" green
    assert_transport_http https green "$green_color" "$green_generation" "$green_dynamic_sha"
    assert_transport_http app-port green "$green_color" "$green_generation" "$green_dynamic_sha"
    forward_applied_at_ms=$(now_ms)
    publish_transport_release forward "$forward_applied_at_ms" "$forward_transition_barrier"
    if ! wait_for_registered_background_pid "$switch_observer_pid"; then
        fail 'Forward transition observer terminated before crossing the applied-config barrier'
    fi
    assert_transition_log "$switch_observer_log" \
        "$blue_final_color" "$blue_final_generation" "$blue_final_acknowledgement" "$blue_final_dynamic_sha" \
        "$green_color" "$green_generation" "$green_acknowledgement" "$green_dynamic_sha" green blue "$forward_applied_at_ms"
    assert_traefik_unchanged "$traefik_id" "$traefik_before_switch_started_at"

    start_transport_observer rollback backend-green "$BACKEND_GREEN_STATE_DIR"
    rollback_transport_log=$TRANSPORT_OBSERVER_LOG
    rollback_transport_pid=$TRANSPORT_OBSERVER_PID
    rollback_transport_ready=$TRANSPORT_OBSERVER_READY
    rollback_transport_release=$TRANSPORT_OBSERVER_RELEASE
    rollback_transport_report=$TRANSPORT_OBSERVER_REPORT
    wait_for_transport_observer_ready rollback "$rollback_transport_ready" "$rollback_transport_log"
    rollback_observer_log="$TEMP_DIRECTORY/rollback-traffic.log"
    start_transition_observer "$rollback_observer_log" "$rollback_transport_release"
    rollback_observer_pid=$TRANSITION_OBSERVER_PID
    wait_for_observer_samples "$rollback_observer_log"
    rollback_switch_started_at_ms=$(now_ms)
    atomic_replace_snapshot "$blue_final_snapshot"
    wait_for_route_identity https "$blue_final_color" "$blue_final_generation" "$blue_final_acknowledgement" "$blue_final_dynamic_sha" blue
    wait_for_route_identity app-port "$blue_final_color" "$blue_final_generation" "$blue_final_acknowledgement" "$blue_final_dynamic_sha" blue
    assert_transport_http https blue "$blue_final_color" "$blue_final_generation" "$blue_final_dynamic_sha"
    assert_transport_http app-port blue "$blue_final_color" "$blue_final_generation" "$blue_final_dynamic_sha"
    rollback_applied_at_ms=$(now_ms)
    publish_transport_release rollback "$rollback_applied_at_ms" "$rollback_transport_release"
    wait_for_transport_observer rollback "$rollback_transport_pid" "$rollback_transport_log" "$rollback_transport_report"
    assert_transport_continuity "$rollback_transport_report" rollback "$forward_applied_at_ms" "$rollback_switch_started_at_ms" "$rollback_applied_at_ms" \
        green "$green_color" "$green_generation" "$green_dynamic_sha"
    if ! wait_for_registered_background_pid "$rollback_observer_pid"; then
        fail 'Rollback transition observer terminated before crossing the applied-config barrier'
    fi
    assert_transition_log "$rollback_observer_log" \
        "$green_color" "$green_generation" "$green_acknowledgement" "$green_dynamic_sha" \
        "$blue_final_color" "$blue_final_generation" "$blue_final_acknowledgement" "$blue_final_dynamic_sha" blue green "$rollback_applied_at_ms"
    assert_traefik_unchanged "$traefik_id" "$traefik_before_switch_started_at"
    assert_exact_dynamic_snapshot "$blue_final_snapshot"
    publish_transport_release full-cycle "$rollback_applied_at_ms" "$forward_transport_release"
    wait_for_transport_observer full-cycle "$forward_transport_pid" "$forward_transport_log" "$forward_transport_report"
    assert_full_cycle_transport_continuity "$forward_transport_report" \
        "$forward_switch_started_at_ms" "$forward_applied_at_ms" "$rollback_switch_started_at_ms" "$rollback_applied_at_ms" \
        blue "$blue_final_color" "$blue_final_generation" "$blue_final_dynamic_sha"

    assert_production_application_lifecycle \
        "$blue_final_color" "$blue_final_generation" "$blue_final_dynamic_sha" \
        "$green_color" "$green_generation" "$green_dynamic_sha" \
        "$traefik_id" "$traefik_before_switch_started_at"

    restart_started_at=$(traefik_started_at "$traefik_id")
    compose restart traefik
    if [ "$(traefik_started_at "$traefik_id")" = "$restart_started_at" ]; then
        fail 'Traefik restart did not produce a new runtime start timestamp'
    fi
    wait_for_runtime_shared_service
    assert_routes_identity_twice "$blue_final_color" "$blue_final_generation" "$blue_final_acknowledgement" "$blue_final_dynamic_sha" blue
    assert_exact_dynamic_snapshot "$blue_final_snapshot"

    total_route_requests=$(route_request_count)
    [ "$total_route_requests" -ge "$MIN_ROUTE_REQUESTS" ] || fail "Only $total_route_requests backend route requests were observed"
    printf 'PASS: native Traefik runtime proof completed with %s backend route requests.\n' "$total_route_requests"
}

main "$@"
