#!/usr/bin/env bash
set -Eeuo pipefail

LAB_DIRECTORY="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly LAB_DIRECTORY
readonly COMPOSE_FILE="$LAB_DIRECTORY/compose.yaml"
PROJECT_NAME="coolify-bluegreen-provider-lab-${RANDOM}-${RANDOM}"
readonly PROJECT_NAME
EVIDENCE_DIRECTORY="${BLUEGREEN_EVIDENCE_DIR:-$(mktemp -d "${TMPDIR:-/tmp}/coolify-bluegreen-provider-lab.XXXXXX")}"
readonly EVIDENCE_DIRECTORY
readonly TRAFFIC_STOP_FILE=/state/bluegreen-provider-lab.stop
readonly MINIMUM_EVICTION_DELAY_MILLISECONDS=8000
readonly HELD_TRAFFIC_DURATION_MILLISECONDS=60000
readonly LEGACY_STOP_GRACE_SECONDS=65

fail() {
  printf 'BLUEGREEN_PROVIDER_LAB_FAILURE %s\n' "$*" >&2
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

probe() {
  compose exec -T candidate node /probe/probe.mjs "$@"
}

hold_traffic() {
  compose exec -T candidate node /probe/held-traffic.mjs "$@"
}

apply_configuration() {
  compose exec -T switcher /bin/sh /templates/switch.sh "/templates/$1"
}

remove_configuration() {
  compose exec -T switcher /bin/sh /templates/switch.sh --remove
}

cleanup() {
  local status=$?
  local cleanup_status=0

  trap - EXIT
  set +e
  timeout --kill-after=5s 45s docker compose --ansi never \
    --project-directory "$LAB_DIRECTORY" \
    --project-name "$PROJECT_NAME" \
    --file "$COMPOSE_FILE" down --volumes --remove-orphans >"$EVIDENCE_DIRECTORY/cleanup.log" 2>&1
  cleanup_status=$?
  if [ "$cleanup_status" -ne 0 ]; then
    printf 'BLUEGREEN_PROVIDER_LAB_CLEANUP_DEBT project=%s status=%s evidence=%s\n' \
      "$PROJECT_NAME" "$cleanup_status" "$EVIDENCE_DIRECTORY" >&2
    if [ "$status" -eq 0 ]; then
      status=$cleanup_status
    fi
  fi
  exit "$status"
}

ensure_traffic_is_live() {
  if ! kill -0 "$traffic_pid" 2>/dev/null; then
    fail "continuous multi-protocol traffic stopped before ${1}"
  fi
}

ensure_held_traffic_is_live() {
  local name=$1
  local held_traffic_pid=$2
  if ! kill -0 "$held_traffic_pid" 2>/dev/null; then
    fail "held ${name} traffic stopped before candidate acknowledgement"
  fi
}

wait_for_held_traffic() {
  local name=$1
  local held_traffic_pid=$2
  local proof_file=$3
  local held_traffic_status=0

  if wait "$held_traffic_pid"; then
    :
  else
    held_traffic_status=$?
  fi
  if [ "$held_traffic_status" -ne 0 ]; then
    tee /dev/stderr <"$proof_file" >/dev/null || true
    fail "held ${name} traffic failed during code-mirroring graceful stop"
  fi
  tee -a "$EVIDENCE_DIRECTORY/summary.log" <"$proof_file"
}

for command in awk bash docker grep mktemp node sh shellcheck tail timeout; do
  require_command "$command"
done
docker info >/dev/null 2>&1 || fail "Docker daemon is not reachable"
mkdir -p "$EVIDENCE_DIRECTORY"

shellcheck --shell=bash "$LAB_DIRECTORY/run.sh"
shellcheck --shell=sh "$LAB_DIRECTORY/templates/switch.sh"
bash -n "$LAB_DIRECTORY/run.sh"
sh -n "$LAB_DIRECTORY/templates/switch.sh"
for script in "$LAB_DIRECTORY/access-log-summary.mjs" "$LAB_DIRECTORY/held-traffic.mjs" "$LAB_DIRECTORY/probe.mjs" "$LAB_DIRECTORY/traffic.mjs" "$LAB_DIRECTORY/backend/server.mjs"; do
  node --check "$script"
done
compose config -q

EXPECTED_TRAEFIK_IMAGE="$(compose config --images | grep -E '^traefik:v3\.6\.17@sha256:[[:xdigit:]]{64}$')"
readonly EXPECTED_TRAEFIK_IMAGE
if [ -z "$EXPECTED_TRAEFIK_IMAGE" ]; then
  fail "compose does not pin the expected Traefik v3.6.17 image digest"
fi
EXPECTED_TRAEFIK_DIGEST="${EXPECTED_TRAEFIK_IMAGE#*@}"
readonly EXPECTED_TRAEFIK_DIGEST
EXPECTED_TRAEFIK_VERSION="${EXPECTED_TRAEFIK_IMAGE#traefik:v}"
EXPECTED_TRAEFIK_VERSION="${EXPECTED_TRAEFIK_VERSION%%@*}"
readonly EXPECTED_TRAEFIK_VERSION

trap cleanup EXIT
printf 'BLUEGREEN_PROVIDER_LAB_START project=%s image=%s evidence=%s\n' \
  "$PROJECT_NAME" "$EXPECTED_TRAEFIK_IMAGE" "$EVIDENCE_DIRECTORY" | tee "$EVIDENCE_DIRECTORY/summary.log"
compose pull switcher traefik | tee "$EVIDENCE_DIRECTORY/pull.log"
if ! docker image inspect "$EXPECTED_TRAEFIK_IMAGE" --format '{{range .RepoDigests}}{{println .}}{{end}}' \
  | grep -Fx "traefik@${EXPECTED_TRAEFIK_DIGEST}" >/dev/null; then
  fail "pulled Traefik image does not retain the configured immutable digest"
fi
compose build --pull legacy candidate | tee "$EVIDENCE_DIRECTORY/build.log"
compose up --detach --no-build --remove-orphans | tee "$EVIDENCE_DIRECTORY/up.log"
compose exec -T traefik traefik version | tee "$EVIDENCE_DIRECTORY/traefik-version.log"
if ! awk -v expected_version="$EXPECTED_TRAEFIK_VERSION" '$1 == "Version:" && $2 == expected_version { found = 1 } END { exit !found }' \
  "$EVIDENCE_DIRECTORY/traefik-version.log"; then
  fail "running Traefik does not report ${EXPECTED_TRAEFIK_VERSION}"
fi

# This deliberately short preflight prevents a malformed Docker label from starting a long traffic run.
probe await-provider active 30000 | tee "$EVIDENCE_DIRECTORY/preflight-docker-provider.json"
probe await-route legacy none 15000 | tee "$EVIDENCE_DIRECTORY/preflight-legacy-route.json"
compose logs --no-color traefik | tee "$EVIDENCE_DIRECTORY/preflight-traefik.log" >/dev/null
if grep -F 'field not found, node:' "$EVIDENCE_DIRECTORY/preflight-traefik.log" >/dev/null; then
  fail "Traefik reported a malformed Docker-provider label during preflight"
fi

compose exec -T candidate rm -f "$TRAFFIC_STOP_FILE"
compose exec -T candidate node /probe/traffic.mjs 180000 "$TRAFFIC_STOP_FILE" \
  >"$EVIDENCE_DIRECTORY/continuous-traffic.jsonl" 2>"$EVIDENCE_DIRECTORY/continuous-traffic.stderr" &
traffic_pid=$!
traffic_start_attempts=0
while ! grep -q '"event":"traffic-started"' "$EVIDENCE_DIRECTORY/continuous-traffic.jsonl"; do
  if [ "$traffic_start_attempts" -ge 100 ]; then
    fail "continuous multi-protocol traffic did not start"
  fi
  traffic_start_attempts=$((traffic_start_attempts + 1))
  sleep 0.1
done

legacy_delay_marker="legacy_delay_${RANDOM}_${RANDOM}"
legacy_sse_marker="legacy_sse_${RANDOM}_${RANDOM}"
legacy_websocket_marker="legacy_websocket_${RANDOM}_${RANDOM}"
printf 'BLUEGREEN_PROVIDER_LAB_PHASE live-legacy-connections\n' | tee -a "$EVIDENCE_DIRECTORY/summary.log"
hold_traffic delay "$legacy_delay_marker" legacy "$HELD_TRAFFIC_DURATION_MILLISECONDS" \
  >"$EVIDENCE_DIRECTORY/held-legacy-delay.json" 2>&1 &
legacy_delay_pid=$!
hold_traffic sse "$legacy_sse_marker" legacy "$HELD_TRAFFIC_DURATION_MILLISECONDS" \
  >"$EVIDENCE_DIRECTORY/held-legacy-sse.json" 2>&1 &
legacy_sse_pid=$!
hold_traffic websocket "$legacy_websocket_marker" legacy "$HELD_TRAFFIC_DURATION_MILLISECONDS" \
  >"$EVIDENCE_DIRECTORY/held-legacy-websocket.json" 2>&1 &
legacy_websocket_pid=$!
probe await-marker "$legacy_delay_marker" 15000 | tee "$EVIDENCE_DIRECTORY/held-legacy-delay-started.json"
probe await-marker "$legacy_sse_marker" 15000 | tee "$EVIDENCE_DIRECTORY/held-legacy-sse-started.json"
probe await-marker "$legacy_websocket_marker" 15000 | tee "$EVIDENCE_DIRECTORY/held-legacy-websocket-started.json"
ensure_held_traffic_is_live "HTTP" "$legacy_delay_pid"
ensure_held_traffic_is_live "SSE" "$legacy_sse_pid"
ensure_held_traffic_is_live "WebSocket" "$legacy_websocket_pid"

printf 'BLUEGREEN_PROVIDER_LAB_PHASE adoption-with-injected-switcher-crash\n' | tee -a "$EVIDENCE_DIRECTORY/summary.log"
apply_configuration candidate-adoption.yml | tee "$EVIDENCE_DIRECTORY/adoption-apply.log"
compose kill --signal SIGKILL switcher | tee "$EVIDENCE_DIRECTORY/injected-switcher-crash.log"
probe await-adoption 15000 | tee "$EVIDENCE_DIRECTORY/adoption-proves-file-plus-one-wins-with-live-legacy-connections.json"

printf 'BLUEGREEN_PROVIDER_LAB_PHASE code-mirroring-graceful-legacy-stop\n' | tee -a "$EVIDENCE_DIRECTORY/summary.log"
printf 'BLUEGREEN_PROVIDER_LAB_CONTROL legacy-backend-sigkill=excluded expected=502 pass-path=normal-graceful-stop\n' \
  | tee -a "$EVIDENCE_DIRECTORY/summary.log"
eviction_started_at="$(node --eval 'process.stdout.write(String(Date.now()))')"
compose stop --timeout "$LEGACY_STOP_GRACE_SECONDS" legacy >"$EVIDENCE_DIRECTORY/legacy-graceful-stop.log" 2>&1 &
legacy_stop_pid=$!

printf 'BLUEGREEN_PROVIDER_LAB_PHASE throttled-legacy-eviction\n' | tee -a "$EVIDENCE_DIRECTORY/summary.log"
sleep 2
probe assert-provider present | tee "$EVIDENCE_DIRECTORY/stale-docker-provider-retained-during-graceful-stop.json"
probe await-provider evicted 120000 "$eviction_started_at" "$MINIMUM_EVICTION_DELAY_MILLISECONDS" \
  >"$EVIDENCE_DIRECTORY/throttled-docker-provider-evicted.json" 2>&1 &
provider_eviction_pid=$!

legacy_stop_status=0
if wait "$legacy_stop_pid"; then
  :
else
  legacy_stop_status=$?
fi
if [ "$legacy_stop_status" -ne 0 ]; then
  tee /dev/stderr <"$EVIDENCE_DIRECTORY/legacy-graceful-stop.log" >/dev/null || true
  fail "normal Docker graceful stop failed"
fi
tee -a "$EVIDENCE_DIRECTORY/summary.log" <"$EVIDENCE_DIRECTORY/legacy-graceful-stop.log"
compose logs --no-color legacy >"$EVIDENCE_DIRECTORY/legacy-graceful-stop-events.log"
if ! grep -F '"event":"graceful-shutdown-start"' "$EVIDENCE_DIRECTORY/legacy-graceful-stop-events.log" >/dev/null \
  || ! grep -F '"event":"graceful-shutdown-complete"' "$EVIDENCE_DIRECTORY/legacy-graceful-stop-events.log" >/dev/null \
  || grep -F '"event":"graceful-shutdown-error"' "$EVIDENCE_DIRECTORY/legacy-graceful-stop-events.log" >/dev/null; then
  fail "legacy did not complete its normal graceful shutdown"
fi
wait_for_held_traffic "HTTP" "$legacy_delay_pid" "$EVIDENCE_DIRECTORY/held-legacy-delay.json"
wait_for_held_traffic "SSE" "$legacy_sse_pid" "$EVIDENCE_DIRECTORY/held-legacy-sse.json"
wait_for_held_traffic "WebSocket" "$legacy_websocket_pid" "$EVIDENCE_DIRECTORY/held-legacy-websocket.json"
provider_eviction_status=0
if wait "$provider_eviction_pid"; then
  :
else
  provider_eviction_status=$?
fi
if [ "$provider_eviction_status" -ne 0 ]; then
  tee /dev/stderr <"$EVIDENCE_DIRECTORY/throttled-docker-provider-evicted.json" >/dev/null || true
  fail "Docker provider did not evict the stopped legacy container after the configured throttle"
fi
tee -a "$EVIDENCE_DIRECTORY/summary.log" <"$EVIDENCE_DIRECTORY/throttled-docker-provider-evicted.json"
ensure_traffic_is_live "code-mirroring graceful legacy stop"
compose start switcher | tee "$EVIDENCE_DIRECTORY/switcher-restarted.log"

printf 'BLUEGREEN_PROVIDER_LAB_PHASE steady-candidate\n' | tee -a "$EVIDENCE_DIRECTORY/summary.log"
apply_configuration steady.yml | tee "$EVIDENCE_DIRECTORY/steady-apply.log"
probe await-steady 15000 | tee "$EVIDENCE_DIRECTORY/steady-priority-after-eviction.json"
ensure_traffic_is_live "steady candidate routing"

printf 'BLUEGREEN_PROVIDER_LAB_PHASE rollback-bridge\n' | tee -a "$EVIDENCE_DIRECTORY/summary.log"
apply_configuration candidate-adoption.yml | tee "$EVIDENCE_DIRECTORY/rollback-fence-apply.log"
probe await-route candidate candidate-adoption 15000 | tee "$EVIDENCE_DIRECTORY/rollback-fence-route.json"
compose start legacy | tee "$EVIDENCE_DIRECTORY/legacy-restarted.log"
probe await-health legacy legacy 30000 | tee "$EVIDENCE_DIRECTORY/legacy-direct-health.json"
apply_configuration legacy-bridge.yml | tee "$EVIDENCE_DIRECTORY/legacy-bridge-apply.log"
probe await-bridge 15000 | tee "$EVIDENCE_DIRECTORY/rollback-bridge-route.json"
probe await-provider active 45000 | tee "$EVIDENCE_DIRECTORY/docker-provider-recovered.json"
ensure_traffic_is_live "legacy Docker-provider recovery"
remove_configuration | tee "$EVIDENCE_DIRECTORY/rollback-bridge-removed.log"
probe await-legacy-authority 15000 | tee "$EVIDENCE_DIRECTORY/legacy-docker-provider-authoritative.json"
ensure_traffic_is_live "rollback completion"

compose exec -T candidate touch "$TRAFFIC_STOP_FILE"
traffic_status=0
if wait "$traffic_pid"; then
  :
else
  traffic_status=$?
fi
if ! grep -q '"event":"traffic-complete"' "$EVIDENCE_DIRECTORY/continuous-traffic.jsonl"; then
  fail "continuous traffic did not publish its final status count"
fi
traffic_counts="$(grep '"event":"traffic-complete"' "$EVIDENCE_DIRECTORY/continuous-traffic.jsonl" | tail -n 1)"
printf 'BLUEGREEN_TRAFFIC_COUNTS %s\n' "$traffic_counts" | tee "$EVIDENCE_DIRECTORY/traffic-counts.log"

compose logs --no-color traefik >"$EVIDENCE_DIRECTORY/traefik-access.log"
access_status=0
if access_counts="$(node "$LAB_DIRECTORY/access-log-summary.mjs" "$EVIDENCE_DIRECTORY/traefik-access.log")"; then
  :
else
  access_status=$?
fi
printf 'BLUEGREEN_TRAEFIK_ACCESS_COUNTS %s\n' "$access_counts" | tee "$EVIDENCE_DIRECTORY/traefik-access-counts.log"
probe metrics | tee "$EVIDENCE_DIRECTORY/prometheus.json"

if [ "$traffic_status" -ne 0 ] || [ "$access_status" -ne 0 ]; then
  fail "traffic or Traefik access-log proof failed: traffic=$traffic_status access=$access_status"
fi
printf 'BLUEGREEN_PROVIDER_LAB_PASS evidence=%s\n' "$EVIDENCE_DIRECTORY" | tee -a "$EVIDENCE_DIRECTORY/summary.log"
