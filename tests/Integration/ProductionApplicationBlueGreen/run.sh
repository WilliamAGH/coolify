#!/bin/sh

set -eu

LAB_DIRECTORY="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
REPOSITORY_ROOT="$(CDPATH='' cd -- "$LAB_DIRECTORY/../../.." && pwd)"
PROJECT_NAME="production-application-blue-green-$PPID-$$"
PRODUCTION_APPLICATION_BLUE_GREEN_IMAGE="${PRODUCTION_APPLICATION_BLUE_GREEN_IMAGE:-coolify:production-application-blue-green}"
EVIDENCE_DIRECTORY="${PRODUCTION_APPLICATION_BLUE_GREEN_EVIDENCE_DIRECTORY:-/tmp/$PROJECT_NAME}"
COMPOSE_FILE="$LAB_DIRECTORY/compose.yaml"
export PRODUCTION_APPLICATION_BLUE_GREEN_IMAGE

compose()
{
    docker compose --ansi never --project-name "$PROJECT_NAME" --file "$COMPOSE_FILE" "$@"
}

cleanup()
{
    compose logs --no-color >"$EVIDENCE_DIRECTORY/compose.log" 2>&1 || true
    compose down --volumes --remove-orphans >/dev/null 2>&1 || true
}

interrupt()
{
    trap - EXIT INT TERM
    cleanup
    exit 130
}

fail()
{
    printf 'PRODUCTION_APPLICATION_BLUE_GREEN_FAIL %s evidence=%s\n' "$1" "$EVIDENCE_DIRECTORY" >&2
    exit 1
}

run_compiler()
{
    compose run --rm --no-deps -T --entrypoint php compiler /lab/compiler.php "$@"
}

run_expected_crash()
{
    expected_status="$1"
    evidence_file="$2"
    stderr_file="${evidence_file%.json}.compose.log"
    shift 2
    status=0
    if run_compiler "$@" >"$evidence_file" 2>"$stderr_file"; then
        fail "expected compiler crash status $expected_status for $*"
    else
        status=$?
    fi
    [ "$status" -eq "$expected_status" ] || fail "compiler crash returned $status, expected $expected_status"
}

probe_route()
{
    route_expected_revision="$1"
    route_expected_acknowledgement="$2"
    route_probe_header="${3:-}"
    route_probe_token="${4:-}"
    route_attempt=0
    while [ "$route_attempt" -lt 60 ]; do
        if [ -n "$route_probe_header" ]; then
            route_response="$(compose run --rm --no-deps -T --entrypoint curl compiler --silent --show-error --include --header "$route_probe_header: $route_probe_token" http://traefik/api/revision 2>/dev/null || true)"
        else
            route_response="$(compose run --rm --no-deps -T --entrypoint curl compiler --silent --show-error --include http://traefik/api/revision 2>/dev/null || true)"
        fi
        if printf '%s\n' "$route_response" | grep -qF 'HTTP/1.1 200' \
            && printf '%s\n' "$route_response" | grep -qF "\"revision\":\"$route_expected_revision\"" \
            && printf '%s\n' "$route_response" | grep -qiF "X-Coolify-Probe-Ack: $route_expected_acknowledgement"; then
            printf '%s\n' "$route_response"
            return 0
        fi
        route_attempt=$((route_attempt + 1))
        sleep 1
    done
    fail "route did not converge to $route_expected_revision with the compiled acknowledgement"
}

trap cleanup EXIT
trap interrupt INT TERM
mkdir -p "$EVIDENCE_DIRECTORY"
rm -f "$EVIDENCE_DIRECTORY"/*.json "$EVIDENCE_DIRECTORY"/*.log

command -v docker >/dev/null 2>&1 || fail 'docker is required'
command -v jq >/dev/null 2>&1 || fail 'jq is required'
docker info >/dev/null 2>&1 || fail 'a running Docker daemon is required'

if [ "${PRODUCTION_APPLICATION_BLUE_GREEN_SKIP_BUILD:-false}" != true ]; then
    docker buildx build \
        --load \
        --file "$REPOSITORY_ROOT/docker/production/Dockerfile" \
        --tag "$PRODUCTION_APPLICATION_BLUE_GREEN_IMAGE" \
        "$REPOSITORY_ROOT" | tee "$EVIDENCE_DIRECTORY/image-build.log"
fi
docker image inspect "$PRODUCTION_APPLICATION_BLUE_GREEN_IMAGE" \
    --format '{{json .RepoTags}} {{.Id}}' >"$EVIDENCE_DIRECTORY/image.json"

compose config >"$EVIDENCE_DIRECTORY/compose-config.yaml"
grep -qF 'traefik:v3.6.17@sha256:802adc80a7bb20a6766c9385c2ad547f0de98564cd20d31d0b6d8f726f906f66' \
    "$EVIDENCE_DIRECTORY/compose-config.yaml" || fail 'Traefik v3.6.17 is not pinned to the required digest'

compose up --detach volume-init production-blue production-green traefik
compose ps >"$EVIDENCE_DIRECTORY/initial-services.log"

initial_operation=initial-production-saga
run_compiler apply blue 1 "$initial_operation" steady >"$EVIDENCE_DIRECTORY/initial-blue.json"
run_compiler commit blue 1 "$initial_operation" steady >"$EVIDENCE_DIRECTORY/initial-blue-commit.json"
managed_filename="$(jq -er '.managedFilename' "$EVIDENCE_DIRECTORY/initial-blue.json")"
initial_sha256="$(jq -er '.yamlSha256' "$EVIDENCE_DIRECTORY/initial-blue.json")"
initial_acknowledgement="$(jq -er '.publicAcknowledgement' "$EVIDENCE_DIRECTORY/initial-blue.json")"
jq -e '
    .blueGreenLifecycle.completeMethodIsPublic == true
    and .blueGreenLifecycle.promoteMethodIsPublic == true
    and .blueGreenLifecycle.rollbackMethodIsPublic == true
' "$EVIDENCE_DIRECTORY/initial-blue.json" >/dev/null \
    || fail 'the compiler did not exercise the public blue-green lifecycle contract'
probe_route blue "$initial_acknowledgement" >"$EVIDENCE_DIRECTORY/initial-blue-route.log"

compose up --detach traffic
sleep 2

promotion_operation=promote-production-saga
run_expected_crash 86 "$EVIDENCE_DIRECTORY/crash-before-write.json" \
    crash-before-write blue 2 "$promotion_operation" probe
after_compile_sha256="$(compose run --rm --no-deps -T --entrypoint sha256sum compiler "/proxy/dynamic/$managed_filename" | cut -d ' ' -f 1)"
[ "$after_compile_sha256" = "$initial_sha256" ] || fail 'crash-before-write changed the active route bytes'
probe_route blue "$initial_acknowledgement" >"$EVIDENCE_DIRECTORY/after-crash-before-write-route.log"
sleep 2

run_expected_crash 87 "$EVIDENCE_DIRECTORY/crash-after-write.json" \
    crash-after-write blue 2 "$promotion_operation" probe
probe_acknowledgement="$(jq -er '.probeAcknowledgement' "$EVIDENCE_DIRECTORY/crash-after-write.json")"
probe_header="$(jq -er '.probeHeader' "$EVIDENCE_DIRECTORY/crash-after-write.json")"
probe_token="$(jq -er '.probeToken' "$EVIDENCE_DIRECTORY/crash-after-write.json")"
candidate_public_acknowledgement="$(jq -er '.publicAcknowledgement' "$EVIDENCE_DIRECTORY/crash-after-write.json")"
probe_route blue "$candidate_public_acknowledgement" >"$EVIDENCE_DIRECTORY/after-atomic-write-public-route.log"
probe_route green "$probe_acknowledgement" "$probe_header" "$probe_token" >"$EVIDENCE_DIRECTORY/after-atomic-write-probe-route.log"
sleep 2

run_expected_crash 88 "$EVIDENCE_DIRECTORY/crash-after-promotion.json" \
    crash-after-promotion green 2 "$promotion_operation" steady
green_acknowledgement="$(jq -er '.publicAcknowledgement' "$EVIDENCE_DIRECTORY/crash-after-promotion.json")"
probe_route green "$green_acknowledgement" >"$EVIDENCE_DIRECTORY/after-promotion-route.log"
compose exec -T traffic php -r 'file_put_contents("/saga/switch-observed", (string) microtime(true), LOCK_EX);'
sleep 2

run_compiler commit green 2 "$promotion_operation" steady >"$EVIDENCE_DIRECTORY/promotion-commit.json"
probe_route green "$green_acknowledgement" >"$EVIDENCE_DIRECTORY/committed-green-route.log"
sleep 2

compose exec -T traffic touch /saga/stop
traffic_container="$(compose ps --quiet traffic)"
[ -n "$traffic_container" ] || fail 'traffic container was not created'
traffic_status="$(docker wait "$traffic_container")"
compose cp traffic:/saga/report.json "$EVIDENCE_DIRECTORY/traffic-report.json" >/dev/null
compose cp traffic:/saga/events.jsonl "$EVIDENCE_DIRECTORY/traffic-events.jsonl" >/dev/null
compose cp traffic:/saga/switch-observed "$EVIDENCE_DIRECTORY/switch-observed.txt" >/dev/null
compose logs --no-color traefik >"$EVIDENCE_DIRECTORY/traefik-access.log" 2>&1
[ "$traffic_status" -eq 0 ] || fail "continuous traffic exited with status $traffic_status"
jq -e '
    (.error | length) == 0
    and (.protocol | has("http") and has("sse") and has("websocket") and has("write"))
    and (.revision | has("blue") and has("green"))
    and (.status | has("101"))
    and ([.status | keys[] | tonumber | select(. >= 300)] | length) == 0
    and ((.heldAcrossPromotion.sse | length) > 0)
    and ((.heldAcrossPromotion.websocket | length) > 0)
    and (.durableWritesExactlyOnce == true)
    and (.durableWriteCount == .operation.writeCreated)
    and (.operation.writeCreated == .operation.writeReplayed)
' "$EVIDENCE_DIRECTORY/traffic-report.json" >/dev/null || fail 'traffic report contains protocol gaps, errors, 404, or 5xx'

if compose run --rm --no-deps -T --entrypoint sh compiler -c 'find /proxy/dynamic -name "*.coolify-rollback" -print -quit | grep -q .' ; then
    fail 'committed promotion left a rollback artifact behind'
fi

printf 'PRODUCTION_APPLICATION_BLUE_GREEN_PASS image=%s evidence=%s\n' \
    "$PRODUCTION_APPLICATION_BLUE_GREEN_IMAGE" "$EVIDENCE_DIRECTORY"
