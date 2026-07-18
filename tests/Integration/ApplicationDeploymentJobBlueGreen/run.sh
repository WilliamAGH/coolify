#!/bin/sh

set -eu

LAB_DIRECTORY="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
REPOSITORY_ROOT="$(CDPATH='' cd -- "$LAB_DIRECTORY/../../.." && pwd)"
PROJECT_NAME="application-deployment-job-blue-green-$PPID-$$"
CONTROL_PLANE_IMAGE="${CONTROL_PLANE_IMAGE:-coolify:application-deployment-job-blue-green-current}"
DEFAULT_TESTING_HOST_IMAGE="application-deployment-job-testing-host-fixture:local"
TESTING_HOST_IMAGE="${TESTING_HOST_IMAGE:-$DEFAULT_TESTING_HOST_IMAGE}"
EVIDENCE_DIRECTORY="${APPLICATION_DEPLOYMENT_JOB_BLUE_GREEN_EVIDENCE_DIRECTORY:-/tmp/$PROJECT_NAME}"
export CONTROL_PLANE_IMAGE EVIDENCE_DIRECTORY TESTING_HOST_IMAGE

compose()
{
    docker compose --ansi never --project-name "$PROJECT_NAME" --file "$LAB_DIRECTORY/compose.yaml" "$@"
}

cleanup()
{
    compose logs --no-color >"$EVIDENCE_DIRECTORY/compose.log" 2>&1 || true
    compose down --volumes --remove-orphans >/dev/null 2>&1 || true
}

fail()
{
    printf 'APPLICATION_DEPLOYMENT_JOB_BLUE_GREEN_FAIL %s evidence=%s\n' "$1" "$EVIDENCE_DIRECTORY" >&2
    exit 1
}

TRAEFIK_VERSION=$(jq -er '.traefik["v3.6"]' "$REPOSITORY_ROOT/versions.json")
[ "$TRAEFIK_VERSION" = 3.6.23 ] \
    || fail "canonical Traefik v3.6 release must be exactly 3.6.23, observed $TRAEFIK_VERSION"
export TRAEFIK_VERSION

capture_runtime_evidence()
{
    docker logs "$PROJECT_NAME-control-plane" >"$EVIDENCE_DIRECTORY/control-plane-container.log" 2>&1 || true
    compose run --rm --no-deps -T --entrypoint php control-plane /lab/db-evidence.php \
        >"$EVIDENCE_DIRECTORY/db-state.json" 2>"$EVIDENCE_DIRECTORY/db-state.log" || true
    compose exec -T testing-host docker ps --all --no-trunc \
        --format '{{json .}}' >"$EVIDENCE_DIRECTORY/nested-containers.jsonl" 2>&1 || true
    compose exec -T testing-host docker image ls --no-trunc \
        --format '{{json .}}' >"$EVIDENCE_DIRECTORY/nested-images.jsonl" 2>&1 || true
}

print_failure_evidence()
{
    for evidence_name in control-plane.log control-plane-container.log db-state.log remote-init.log; do
        evidence_path="$EVIDENCE_DIRECTORY/$evidence_name"
        [ -f "$evidence_path" ] || continue
        printf '%s\n' "--- $evidence_name (sanitized tail) ---" >&2
        tail -n 240 "$evidence_path" \
            | sed -E 's/((password|token|secret|private[_-]?key)[[:space:]]*[=:][[:space:]]*)[^[:space:]]+/\1[REDACTED]/Ig' \
            >&2
    done
}

request_observer_final_flush()
{
    [ "$(compose ps --status running --quiet observer | wc -l | tr -d ' ')" = 1 ] \
        || fail 'candidate observer exited before final flush'
    heartbeat_before=
    if [ -s "$EVIDENCE_DIRECTORY/observer-heartbeat" ]; then
        heartbeat_before=$(cat "$EVIDENCE_DIRECTORY/observer-heartbeat")
    fi
    flush_token="$PROJECT_NAME-$(date +%s%N)"
    flush_request_snapshot="$EVIDENCE_DIRECTORY/observer-final-flush.request.$$"
    printf '%s\n' "$flush_token" >"$flush_request_snapshot"
    mv "$flush_request_snapshot" "$EVIDENCE_DIRECTORY/observer-final-flush.request"

    attempt=0
    while :; do
        [ "$(compose ps --status running --quiet observer | wc -l | tr -d ' ')" = 1 ] \
            || fail 'candidate observer exited during final flush'
        flush_ack=
        heartbeat_after=
        if [ -s "$EVIDENCE_DIRECTORY/observer-final-flush.ack" ]; then
            flush_ack=$(cat "$EVIDENCE_DIRECTORY/observer-final-flush.ack")
        fi
        if [ -s "$EVIDENCE_DIRECTORY/observer-heartbeat" ]; then
            heartbeat_after=$(cat "$EVIDENCE_DIRECTORY/observer-heartbeat")
        fi
        if [ "$flush_ack" = "$flush_token" ] \
            && [ -n "$heartbeat_after" ] \
            && [ "$heartbeat_after" != "$heartbeat_before" ]; then
            break
        fi
        attempt=$((attempt + 1))
        [ "$attempt" -lt 100 ] || fail 'candidate observer did not durably acknowledge final flush'
        sleep 0.1
    done
}

trap cleanup EXIT INT TERM
mkdir -p "$EVIDENCE_DIRECTORY"

sh "$LAB_DIRECTORY/observer-test.sh" >"$EVIDENCE_DIRECTORY/observer-test.log"
sh "$LAB_DIRECTORY/verify-traefik-tombstone-test.sh" \
    >"$EVIDENCE_DIRECTORY/verify-traefik-tombstone-test.log"

sha256sum \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/BlueGreenProxyDeactivationSnapshot.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/BlueGreenDeactivationPreparation.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/BlueGreenDeactivationRemoteOutcome.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/BlueGreenDeactivationRemoteResult.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/BlueGreenDeactivationTransportException.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/DeactivateBlueGreenApplicationDestination.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/DrainAndRemoveBlueGreenApplicationContainers.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/InstallBlueGreenProxyEvictionTombstone.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/ExecuteBlueGreenDeactivationRemoteCommand.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/PrepareBlueGreenDeactivation.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/PrepareBlueGreenProxyDeactivation.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/RemoveBlueGreenApplicationContainers.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/RemoveBlueGreenProxyEvictionTombstone.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/ResumeBlueGreenDeactivations.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/WaitForBlueGreenProxyEviction.php" \
    "$REPOSITORY_ROOT/app/Models/ApplicationBlueGreenDeactivation.php" \
    "$REPOSITORY_ROOT/app/Actions/Proxy/RemoveBlueGreenProxyConfiguration.php" \
    "$REPOSITORY_ROOT/app/Actions/Proxy/WriteBlueGreenProxyConfiguration.php" \
    "$REPOSITORY_ROOT"/database/migrations/2026_07_12_*.php \
    "$REPOSITORY_ROOT/database/migrations/control-plane-migration-inventory.fingerprint" \
    "$REPOSITORY_ROOT/app/Jobs/ApplicationDeploymentJob.php" \
    "$REPOSITORY_ROOT/docker/production/Dockerfile" \
    "$REPOSITORY_ROOT/docker/testing-host/Dockerfile" \
    "$REPOSITORY_ROOT/docker/testing-host/entrypoint.sh" \
    "$REPOSITORY_ROOT/docker/testing-host/keygen.sh" \
    "$REPOSITORY_ROOT/versions.json" \
    "$LAB_DIRECTORY"/* >"$EVIDENCE_DIRECTORY/source-hashes.start"

docker buildx build --load --file "$LAB_DIRECTORY/Dockerfile.fixture" \
    --tag application-deployment-job-fixture:manifest "$LAB_DIRECTORY"
FIXTURE_IMAGE_ID=$(docker image inspect application-deployment-job-fixture:manifest \
    --format '{{.Id}}')
export FIXTURE_IMAGE_ID

docker_architecture=$(docker info --format '{{.Architecture}}')
case "$docker_architecture" in
    aarch64 | arm64)
        testing_host_architecture=arm64
        TRAEFIK_MANIFEST_DIGEST=sha256:5bb4874e6ed29907a6d7a3bff6704c7e31fd5c5d7cf557ef7ff24296ec76f150
        TRAEFIK_IMAGE_ID=sha256:80a1d834ed38003b48708d2154afef424b6593ff597a44e834a573ce39f2b8a4
        ;;
    x86_64 | amd64)
        testing_host_architecture=amd64
        TRAEFIK_MANIFEST_DIGEST=sha256:895fcd96315a34e37270fc3f73034c16125251265a84174ad4fdaf7cd0f2966e
        TRAEFIK_IMAGE_ID=sha256:fe87da91c413a0b9e154bd2e293cb737e9ce52364d95ef34d72a78673a2b99f8
        ;;
    *)
        fail "unsupported Docker daemon architecture: $docker_architecture"
        ;;
esac

if [ "$TESTING_HOST_IMAGE" = "$DEFAULT_TESTING_HOST_IMAGE" ]; then
    docker buildx build --load --platform "linux/$testing_host_architecture" \
        --file "$LAB_DIRECTORY/Dockerfile.testing-host-fixture" \
        --tag "$TESTING_HOST_IMAGE" "$REPOSITORY_ROOT"
fi

testing_host_image_os=$(docker image inspect "$TESTING_HOST_IMAGE" --format '{{.Os}}')
testing_host_image_architecture=$(docker image inspect "$TESTING_HOST_IMAGE" --format '{{.Architecture}}')
TESTING_HOST_IMAGE_ID=$(docker image inspect "$TESTING_HOST_IMAGE" --format '{{.Id}}')
[ "$testing_host_image_os" = linux ] \
    || fail 'testing-host fixture is not a Linux image'
[ "$testing_host_image_architecture" = "$testing_host_architecture" ] \
    || fail "testing-host fixture architecture $testing_host_image_architecture does not match Docker daemon $testing_host_architecture"
export TESTING_HOST_IMAGE_ARCHITECTURE="$testing_host_image_architecture" TESTING_HOST_IMAGE_ID

TRAEFIK_PLATFORM="linux/$testing_host_architecture"
TRAEFIK_SOURCE="traefik@$TRAEFIK_MANIFEST_DIGEST"

verify_traefik_source()
{
    source=$1
    expected_id=$2
    expected_version=$3
    target=$4

    docker pull --platform "$TRAEFIK_PLATFORM" "$source" >/dev/null
    [ "$(docker image inspect "$source" --format '{{.Id}}')" = "$expected_id" ] \
        || fail "exact Traefik $expected_version image identity drifted"
    [ "$(docker image inspect "$source" --format '{{.Os}}/{{.Architecture}}')" = \
        "$TRAEFIK_PLATFORM" ] \
        || fail "Traefik $expected_version image platform drifted"
    [ "$(docker image inspect "$source" --format '{{index .Config.Labels "org.opencontainers.image.version"}}')" = \
        "v$expected_version" ] \
        || fail "Traefik $expected_version version label drifted"
    docker tag "$source" "$target"
    [ "$(docker image inspect "$target" --format '{{.Id}}')" = "$expected_id" ] \
        || fail "Traefik $expected_version local tag identity drifted"
}

verify_traefik_source "$TRAEFIK_SOURCE" "$TRAEFIK_IMAGE_ID" "$TRAEFIK_VERSION" \
    "traefik:v$TRAEFIK_VERSION"
export TRAEFIK_IMAGE_ID TRAEFIK_MANIFEST_DIGEST TRAEFIK_PLATFORM

docker pull 'docker:28.4.0-dind@sha256:2ceb471176ad51e37145d43ce7cbf0fa5d644a2b185bd537f0ef695fb3a37497' >/dev/null
docker pull 'postgres:15.18-alpine3.24@sha256:3d0f7584ed7d04e27fa050d6683a74746608faf21f202be78460d679cc56461f' >/dev/null
docker pull 'redis:7-alpine@sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99' >/dev/null
docker pull 'ghcr.io/coollabsio/coolify-helper:1.0.14@sha256:55acc11740d42a5646e74108276ab252cc040abce0679fc6b36c81b464849d52' >/dev/null
docker pull 'registry:2.8.3@sha256:a3d8aaa63ed8681a604f1dea0aa03f100d5895b6a58ace528858a7b332415373' >/dev/null
docker tag 'ghcr.io/coollabsio/coolify-helper:1.0.14@sha256:55acc11740d42a5646e74108276ab252cc040abce0679fc6b36c81b464849d52' \
    ghcr.io/coollabsio/coolify-helper:1.0.14
docker tag 'registry:2.8.3@sha256:a3d8aaa63ed8681a604f1dea0aa03f100d5895b6a58ace528858a7b332415373' \
    registry:2.8.3
HELPER_IMAGE_ID=$(docker image inspect ghcr.io/coollabsio/coolify-helper:1.0.14 \
    --format '{{.Id}}')
REGISTRY_IMAGE_ID=$(docker image inspect registry:2.8.3 --format '{{.Id}}')
export HELPER_IMAGE_ID REGISTRY_IMAGE_ID

docker image inspect \
    "$CONTROL_PLANE_IMAGE" \
    "$TESTING_HOST_IMAGE" \
    'docker:28.4.0-dind@sha256:2ceb471176ad51e37145d43ce7cbf0fa5d644a2b185bd537f0ef695fb3a37497' \
    'postgres:15.18-alpine3.24@sha256:3d0f7584ed7d04e27fa050d6683a74746608faf21f202be78460d679cc56461f' \
    'redis:7-alpine@sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99' \
    "$TRAEFIK_SOURCE" \
    "traefik:v$TRAEFIK_VERSION" \
    'ghcr.io/coollabsio/coolify-helper:1.0.14@sha256:55acc11740d42a5646e74108276ab252cc040abce0679fc6b36c81b464849d52' \
    'registry:2.8.3@sha256:a3d8aaa63ed8681a604f1dea0aa03f100d5895b6a58ace528858a7b332415373' \
    application-deployment-job-fixture:manifest >"$EVIDENCE_DIRECTORY/images.start.json"

docker save --output "$EVIDENCE_DIRECTORY/nested-images.tar" \
    application-deployment-job-fixture:manifest \
    "$TESTING_HOST_IMAGE" \
    "traefik:v$TRAEFIK_VERSION" \
    ghcr.io/coollabsio/coolify-helper:1.0.14 \
    registry:2.8.3
sha256sum "$EVIDENCE_DIRECTORY/nested-images.tar" >"$EVIDENCE_DIRECTORY/nested-images.sha256"

compose config >"$EVIDENCE_DIRECTORY/compose-config.yaml"
compose up --detach postgres redis dind keygen testing-host
compose exec -T testing-host /bin/sh /lab/remote-init.sh >"$EVIDENCE_DIRECTORY/remote-init.log"
compose up --detach observer
[ "$(compose ps --status running --quiet observer | wc -l | tr -d ' ')" = 1 ] \
    || fail 'candidate observer did not start'

if ! compose run --no-deps -T --name "$PROJECT_NAME-control-plane" control-plane >"$EVIDENCE_DIRECTORY/control-plane.log" 2>&1; then
    capture_runtime_evidence
    print_failure_evidence
    fail 'real ApplicationDeploymentJob handle execution failed'
fi
capture_runtime_evidence
docker cp "$PROJECT_NAME-control-plane:/tmp/application-deployment-job-blue-green-report.json" "$EVIDENCE_DIRECTORY/report.json" >/dev/null \
    || fail 'real handle report could not be copied from the control plane'
[ -s "$EVIDENCE_DIRECTORY/report.json" ] || fail 'real handle report was not persisted'
request_observer_final_flush

first_not_found_at="$(jq -r '.deactivationTraffic.firstNotFoundAt' "$EVIDENCE_DIRECTORY/report.json")"
first_tombstone_at="$(jq -r '.deactivationTraffic.firstTombstoneAt' "$EVIDENCE_DIRECTORY/report.json")"
last_stream_ended_at="$(jq -r '[.traffic.heldHttp.endedAt, .traffic.sse.endedAt, .traffic.websocket.endedAt] | max' "$EVIDENCE_DIRECTORY/report.json")"
for container_id in $(jq -r '.drainedContainerId[]' "$EVIDENCE_DIRECTORY/report.json"); do
    jq -s -e --arg containerId "$container_id" \
        --argjson firstTombstoneAt "$first_tombstone_at" \
        --argjson firstNotFoundAt "$first_not_found_at" \
        --argjson lastStreamEndedAt "$last_stream_ended_at" \
        'map(select(.id == $containerId and .action == "stop"))[0].timeNano / 1000000 as $stop
            | $stop > $firstTombstoneAt and $stop > $lastStreamEndedAt and $stop < $firstNotFoundAt' \
        "$EVIDENCE_DIRECTORY/docker-events.jsonl" >/dev/null \
        || fail 'managed target stop did not follow tombstone ACK and stream drain before final route absence'
done
jq -s -e \
    --arg platform "$TRAEFIK_PLATFORM" \
    --arg imageId "$TRAEFIK_IMAGE_ID" \
    --arg version "$TRAEFIK_VERSION" \
    'length == 1
    and .[0].status == 418
    and .[0].version == $version
    and .[0].platform == $platform
    and .[0].imageId == $imageId' \
    "$EVIDENCE_DIRECTORY/traefik-tombstone-matrix.jsonl" >/dev/null \
    || fail 'exact production/candidate Traefik tombstone contract matrix failed'
jq -s -e 'last.present == false and last.finalFlush == true' "$EVIDENCE_DIRECTORY/proxy-route.jsonl" >/dev/null \
    || fail 'managed Traefik route survived deactivation'
jq -s -e 'length == 2 and (map(.Names) | sort) == ["coolify-proxy", "fixture-registry"]' \
    "$EVIDENCE_DIRECTORY/nested-containers.jsonl" >/dev/null \
    || fail 'nested Docker retained orphan containers after deactivation'

sha256sum \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/BlueGreenProxyDeactivationSnapshot.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/BlueGreenDeactivationPreparation.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/BlueGreenDeactivationRemoteOutcome.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/BlueGreenDeactivationRemoteResult.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/BlueGreenDeactivationTransportException.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/DeactivateBlueGreenApplicationDestination.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/DrainAndRemoveBlueGreenApplicationContainers.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/InstallBlueGreenProxyEvictionTombstone.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/ExecuteBlueGreenDeactivationRemoteCommand.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/PrepareBlueGreenDeactivation.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/PrepareBlueGreenProxyDeactivation.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/RemoveBlueGreenApplicationContainers.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/RemoveBlueGreenProxyEvictionTombstone.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/ResumeBlueGreenDeactivations.php" \
    "$REPOSITORY_ROOT/app/Actions/Application/BlueGreen/WaitForBlueGreenProxyEviction.php" \
    "$REPOSITORY_ROOT/app/Models/ApplicationBlueGreenDeactivation.php" \
    "$REPOSITORY_ROOT/app/Actions/Proxy/RemoveBlueGreenProxyConfiguration.php" \
    "$REPOSITORY_ROOT/app/Actions/Proxy/WriteBlueGreenProxyConfiguration.php" \
    "$REPOSITORY_ROOT"/database/migrations/2026_07_12_*.php \
    "$REPOSITORY_ROOT/database/migrations/control-plane-migration-inventory.fingerprint" \
    "$REPOSITORY_ROOT/app/Jobs/ApplicationDeploymentJob.php" \
    "$REPOSITORY_ROOT/docker/production/Dockerfile" \
    "$REPOSITORY_ROOT/docker/testing-host/Dockerfile" \
    "$REPOSITORY_ROOT/docker/testing-host/entrypoint.sh" \
    "$REPOSITORY_ROOT/docker/testing-host/keygen.sh" \
    "$REPOSITORY_ROOT/versions.json" \
    "$LAB_DIRECTORY"/* >"$EVIDENCE_DIRECTORY/source-hashes.end"
cmp "$EVIDENCE_DIRECTORY/source-hashes.start" "$EVIDENCE_DIRECTORY/source-hashes.end" \
    || fail 'source inputs changed during the acceptance run'
docker image inspect \
    "$CONTROL_PLANE_IMAGE" \
    "$TESTING_HOST_IMAGE" \
    'docker:28.4.0-dind@sha256:2ceb471176ad51e37145d43ce7cbf0fa5d644a2b185bd537f0ef695fb3a37497' \
    'postgres:15.18-alpine3.24@sha256:3d0f7584ed7d04e27fa050d6683a74746608faf21f202be78460d679cc56461f' \
    'redis:7-alpine@sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99' \
    "$TRAEFIK_SOURCE" \
    "traefik:v$TRAEFIK_VERSION" \
    'ghcr.io/coollabsio/coolify-helper:1.0.14@sha256:55acc11740d42a5646e74108276ab252cc040abce0679fc6b36c81b464849d52' \
    'registry:2.8.3@sha256:a3d8aaa63ed8681a604f1dea0aa03f100d5895b6a58ace528858a7b332415373' \
    application-deployment-job-fixture:manifest >"$EVIDENCE_DIRECTORY/images.end.json"
cmp "$EVIDENCE_DIRECTORY/images.start.json" "$EVIDENCE_DIRECTORY/images.end.json" \
    || fail 'outer image inputs changed during the acceptance run'

printf 'APPLICATION_DEPLOYMENT_JOB_BLUE_GREEN_PASS image=%s evidence=%s\n' \
    "$CONTROL_PLANE_IMAGE" "$EVIDENCE_DIRECTORY"
