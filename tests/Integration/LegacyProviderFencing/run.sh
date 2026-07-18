#!/usr/bin/env bash
set -Eeuo pipefail

LAB_DIRECTORY="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly LAB_DIRECTORY
readonly COMPOSE_FILE="$LAB_DIRECTORY/compose.yaml"
readonly TRAEFIK_IMAGE="traefik:v3.6.17@sha256:802adc80a7bb20a6766c9385c2ad547f0de98564cd20d31d0b6d8f726f906f66"
export TRAEFIK_IMAGE
export COMPOSE_DOCKER_CLI_BUILD=0
export DOCKER_BUILDKIT=0
PROJECT_NAME="coolify-legacy-fencing-$$-$RANDOM"
readonly PROJECT_NAME
EVIDENCE_DIRECTORY="${LEGACY_FENCING_EVIDENCE_DIR:-$(mktemp -d "${TMPDIR:-/tmp}/coolify-legacy-fencing.XXXXXX")}"
readonly EVIDENCE_DIRECTORY
readonly CANDIDATE_FENCE_ACK="1111111111111111111111111111111111111111111111111111111111111111"
readonly STEADY_ACK="2222222222222222222222222222222222222222222222222222222222222222"
readonly LEGACY_BRIDGE_ACK="3333333333333333333333333333333333333333333333333333333333333333"

fail() {
  printf 'LEGACY_PROVIDER_FENCING_FAILURE %s\n' "$*" >&2
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "required command is unavailable: $1"
}

compose() {
  docker compose --ansi never \
    --project-directory "$LAB_DIRECTORY" \
    --project-name "$PROJECT_NAME" \
    --file "$COMPOSE_FILE" "$@"
}

client() {
  compose exec -T candidate node /app/runner.mjs "$@"
}

probe() {
  compose exec -T candidate node /probe/probe.mjs "$@"
}

apply_configuration() {
  compose exec -T switcher /bin/sh /templates/switch.sh "$1"
}

cleanup() {
  local status=$?
  local cleanup_status=0
  set +e
  timeout --kill-after=5s 45s docker compose --ansi never \
    --project-directory "$LAB_DIRECTORY" \
    --project-name "$PROJECT_NAME" \
    --file "$COMPOSE_FILE" down --volumes --remove-orphans >"$EVIDENCE_DIRECTORY/cleanup.log" 2>&1
  cleanup_status=$?
  if [ "$cleanup_status" -ne 0 ]; then
    printf 'LEGACY_PROVIDER_FENCING_CLEANUP_DEBT project=%s status=%s evidence=%s\n' \
      "$PROJECT_NAME" "$cleanup_status" "$EVIDENCE_DIRECTORY" >&2
    if [ "$status" -eq 0 ]; then
      return "$cleanup_status"
    fi
  fi
  return "$status"
}

assert_no_gateway_failures() {
  local access_log="$EVIDENCE_DIRECTORY/traefik-access.log"
  local request_count
  compose logs --no-color traefik | tee "$access_log" >/dev/null
  if grep -E '"(DownstreamStatus|OriginStatus)"[[:space:]]*:[[:space:]]*(404|5[0-9][0-9])' "$access_log" >/dev/null; then
    fail "Traefik access log contains a 404 or 5xx response"
  fi
  request_count="$(grep -c '"RequestPath"' "$access_log" || true)"
  if [ "$request_count" -lt 100 ]; then
    fail "Traefik access log did not contain enough sustained request evidence: $request_count"
  fi
}

for command in docker grep mktemp tee timeout; do
  require_command "$command"
done
docker info >/dev/null 2>&1 || fail "Docker daemon is not reachable"
mkdir -p "$EVIDENCE_DIRECTORY"
trap cleanup EXIT

printf 'LEGACY_PROVIDER_FENCING_START project=%s\n' "$PROJECT_NAME" | tee "$EVIDENCE_DIRECTORY/summary.log"
compose pull switcher traefik | tee "$EVIDENCE_DIRECTORY/pull.log"
compose build --pull legacy candidate | tee "$EVIDENCE_DIRECTORY/build.log"
compose up --detach --no-build --remove-orphans | tee "$EVIDENCE_DIRECTORY/up.log"

probe await-provider active 30000 | tee "$EVIDENCE_DIRECTORY/initial-provider-active.json"
probe await-route legacy none 30000 | tee "$EVIDENCE_DIRECTORY/initial-legacy-route.json"

compose exec -T candidate node /probe/traffic.mjs 45000 \
  >"$EVIDENCE_DIRECTORY/continuous-traffic.json" 2>"$EVIDENCE_DIRECTORY/continuous-traffic.stderr" &
traffic_pid=$!
for attempt in $(seq 1 100); do
  if grep -q '"event":"traffic-started"' "$EVIDENCE_DIRECTORY/continuous-traffic.json"; then
    break
  fi
  if [ "$attempt" -eq 100 ]; then
    fail "continuous traffic did not start"
  fi
  sleep 0.1
done

held_marker="held-before-adoption-$RANDOM"
client delay --url "http://traefik/delay?ms=5000&request=${held_marker}" --expected-revision legacy --timeout-ms 10000 \
  >"$EVIDENCE_DIRECTORY/held-before-adoption.json" 2>"$EVIDENCE_DIRECTORY/held-before-adoption.stderr" &
held_pid=$!
client await-marker --marker "$held_marker" --timeout-ms 5000 | tee "$EVIDENCE_DIRECTORY/held-before-adoption-started.json"

sse_marker="sse-before-adoption-$RANDOM"
client sse --url "http://traefik/sse?count=20&interval=500&stream=${sse_marker}" --expected-revision legacy \
  --expected-events 20 --timeout-ms 15000 \
  >"$EVIDENCE_DIRECTORY/sse-before-adoption.json" 2>"$EVIDENCE_DIRECTORY/sse-before-adoption.stderr" &
sse_pid=$!
client await-marker --marker "$sse_marker" --timeout-ms 5000 | tee "$EVIDENCE_DIRECTORY/sse-before-adoption-started.json"

apply_configuration /templates/candidate-fence.yml
probe await-route candidate "$CANDIDATE_FENCE_ACK" 15000 | tee "$EVIDENCE_DIRECTORY/candidate-fence-active.json"
compose kill --signal SIGKILL switcher | tee "$EVIDENCE_DIRECTORY/injected-control-plane-crash.log"
probe await-route candidate "$CANDIDATE_FENCE_ACK" 5000 | tee "$EVIDENCE_DIRECTORY/crash-retained-candidate-fence.json"
sleep 1
compose start switcher | tee "$EVIDENCE_DIRECTORY/control-plane-restarted.log"

compose stop --timeout 30 legacy | tee "$EVIDENCE_DIRECTORY/legacy-stop.log"
probe assert-provider active 1000 | tee "$EVIDENCE_DIRECTORY/stale-provider-retained.json"
probe await-provider evicted 30000 | tee "$EVIDENCE_DIRECTORY/provider-evicted.json"
apply_configuration /templates/steady.yml
probe await-route candidate "$STEADY_ACK" 15000 | tee "$EVIDENCE_DIRECTORY/steady-candidate-route.json"

apply_configuration /templates/candidate-fence.yml
probe await-route candidate "$CANDIDATE_FENCE_ACK" 15000 | tee "$EVIDENCE_DIRECTORY/rollback-safety-fence.json"
compose start legacy | tee "$EVIDENCE_DIRECTORY/legacy-start.log"
client await --url http://legacy:3000/health --expected-revision legacy --timeout-ms 15000 \
  | tee "$EVIDENCE_DIRECTORY/legacy-direct-health.json"
apply_configuration /templates/legacy-bridge.yml
probe await-route legacy "$LEGACY_BRIDGE_ACK" 15000 | tee "$EVIDENCE_DIRECTORY/legacy-bridge-active.json"
probe await-provider active 30000 | tee "$EVIDENCE_DIRECTORY/provider-restored.json"
apply_configuration --remove
probe await-route legacy none 15000 | tee "$EVIDENCE_DIRECTORY/docker-provider-authoritative.json"

held_status=0
if wait "$held_pid"; then
  :
else
  held_status=$?
fi
sse_status=0
if wait "$sse_pid"; then
  :
else
  sse_status=$?
fi
traffic_status=0
if wait "$traffic_pid"; then
  :
else
  traffic_status=$?
fi

client metrics --url http://traefik:8082/metrics | tee "$EVIDENCE_DIRECTORY/prometheus.json"
assert_no_gateway_failures
if [ "$held_status" -ne 0 ] || [ "$sse_status" -ne 0 ] || [ "$traffic_status" -ne 0 ]; then
  fail "one or more held, SSE, or continuous multi-protocol probes failed: held=$held_status sse=$sse_status traffic=$traffic_status"
fi
printf 'LEGACY_PROVIDER_FENCING_PASS evidence=%s\n' "$EVIDENCE_DIRECTORY" | tee -a "$EVIDENCE_DIRECTORY/summary.log"
