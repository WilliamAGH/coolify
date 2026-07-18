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

docker_architecture=$(docker info --format '{{.Architecture}}')
case "$docker_architecture" in
    aarch64 | arm64)
        testing_host_architecture=arm64
        TRAEFIK_PRODUCTION_MANIFEST_DIGEST=sha256:fa2d74d82a13db11d067c25b5b761262003774de8cd96e5ed8306bdd8f6a480d
        TRAEFIK_PRODUCTION_IMAGE_ID=sha256:f6812724be8993ef6b8935543d68c5305fba26ddd800c141752d152d8005538c
        TRAEFIK_CANDIDATE_MANIFEST_DIGEST=sha256:acacb46feeef8c402e666d36d4ba63013446cbaffc78d6b2e6297c53e991f45d
        TRAEFIK_CANDIDATE_IMAGE_ID=sha256:6a74c416e0c4aa1898229dad35767314379a35a824cfbaf6b56870a7d4e6df92
        ;;
    x86_64 | amd64)
        testing_host_architecture=amd64
        TRAEFIK_PRODUCTION_MANIFEST_DIGEST=sha256:6f4b3a3b43d82dd33ed740cc3d6b85ccae4c311b77f114e6c55c98d5dbf9b1b1
        TRAEFIK_PRODUCTION_IMAGE_ID=sha256:e861a9b21b1200af43526c8954fc7031c9d842cb5b40bd8bef773750cdce45f4
        TRAEFIK_CANDIDATE_MANIFEST_DIGEST=sha256:18d36de0b283a62956cd290fef284a474aa1242f18c005a856a8ef5d8f5fc93b
        TRAEFIK_CANDIDATE_IMAGE_ID=sha256:67838d6e3bef0d6a7c0670b582e440804f33de34d79b99484e6428b2d2a85d1e
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

TRAEFIK_MATRIX_PLATFORM="linux/$testing_host_architecture"
TRAEFIK_PRODUCTION_SOURCE="traefik@$TRAEFIK_PRODUCTION_MANIFEST_DIGEST"
TRAEFIK_CANDIDATE_SOURCE="traefik@$TRAEFIK_CANDIDATE_MANIFEST_DIGEST"

verify_traefik_source()
{
    source=$1
    expected_id=$2
    expected_version=$3
    target=$4

    docker pull --platform "$TRAEFIK_MATRIX_PLATFORM" "$source" >/dev/null
    [ "$(docker image inspect "$source" --format '{{.Id}}')" = "$expected_id" ] \
        || fail "exact Traefik $expected_version image identity drifted"
    [ "$(docker image inspect "$source" --format '{{.Os}}/{{.Architecture}}')" = \
        "$TRAEFIK_MATRIX_PLATFORM" ] \
        || fail "Traefik $expected_version image platform drifted"
    [ "$(docker image inspect "$source" --format '{{index .Config.Labels "org.opencontainers.image.version"}}')" = \
        "v$expected_version" ] \
        || fail "Traefik $expected_version version label drifted"
    docker tag "$source" "$target"
    [ "$(docker image inspect "$target" --format '{{.Id}}')" = "$expected_id" ] \
        || fail "Traefik $expected_version local tag identity drifted"
}

verify_traefik_source "$TRAEFIK_PRODUCTION_SOURCE" "$TRAEFIK_PRODUCTION_IMAGE_ID" 3.6.13 \
    traefik:production-3.6.13
verify_traefik_source "$TRAEFIK_CANDIDATE_SOURCE" "$TRAEFIK_CANDIDATE_IMAGE_ID" 3.6.17 \
    traefik:v3.6.17
export TRAEFIK_CANDIDATE_IMAGE_ID TRAEFIK_CANDIDATE_MANIFEST_DIGEST TRAEFIK_MATRIX_PLATFORM \
    TRAEFIK_PRODUCTION_IMAGE_ID TRAEFIK_PRODUCTION_MANIFEST_DIGEST

docker image inspect \
    "$CONTROL_PLANE_IMAGE" \
    "$TESTING_HOST_IMAGE" \
    'docker:28.4.0-dind@sha256:2ceb471176ad51e37145d43ce7cbf0fa5d644a2b185bd537f0ef695fb3a37497' \
    'postgres:15.18-alpine3.24@sha256:3d0f7584ed7d04e27fa050d6683a74746608faf21f202be78460d679cc56461f' \
    'redis:7-alpine@sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99' \
    "$TRAEFIK_CANDIDATE_SOURCE" \
    "$TRAEFIK_PRODUCTION_SOURCE" \
    'traefik:v3.6.17' \
    'traefik:production-3.6.13' \
    'ghcr.io/coollabsio/coolify-helper:1.0.14@sha256:55acc11740d42a5646e74108276ab252cc040abce0679fc6b36c81b464849d52' \
    'registry:2.8.3@sha256:a3d8aaa63ed8681a604f1dea0aa03f100d5895b6a58ace528858a7b332415373' \
    application-deployment-job-fixture:manifest >"$EVIDENCE_DIRECTORY/images.start.json"

docker save --output "$EVIDENCE_DIRECTORY/nested-images.tar" \
    application-deployment-job-fixture:manifest \
    "$TESTING_HOST_IMAGE" \
    traefik:v3.6.17 \
    traefik:production-3.6.13 \
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
    --arg platform "$TRAEFIK_MATRIX_PLATFORM" \
    --arg productionImageId "$TRAEFIK_PRODUCTION_IMAGE_ID" \
    --arg candidateImageId "$TRAEFIK_CANDIDATE_IMAGE_ID" \
    'length == 2
    and (map(.status) | all(. == 418))
    and (map(.version) | sort) == ["3.6.13", "3.6.17"]
    and (map(.platform) | all(. == $platform))
    and (map(select(.version == "3.6.13" and .imageId == $productionImageId)) | length) == 1
    and (map(select(.version == "3.6.17" and .imageId == $candidateImageId)) | length) == 1' \
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
    "$TRAEFIK_CANDIDATE_SOURCE" \
    "$TRAEFIK_PRODUCTION_SOURCE" \
    'traefik:v3.6.17' \
    'traefik:production-3.6.13' \
    'ghcr.io/coollabsio/coolify-helper:1.0.14@sha256:55acc11740d42a5646e74108276ab252cc040abce0679fc6b36c81b464849d52' \
    'registry:2.8.3@sha256:a3d8aaa63ed8681a604f1dea0aa03f100d5895b6a58ace528858a7b332415373' \
    application-deployment-job-fixture:manifest >"$EVIDENCE_DIRECTORY/images.end.json"
cmp "$EVIDENCE_DIRECTORY/images.start.json" "$EVIDENCE_DIRECTORY/images.end.json" \
    || fail 'outer image inputs changed during the acceptance run'

printf 'APPLICATION_DEPLOYMENT_JOB_BLUE_GREEN_PASS image=%s evidence=%s\n' \
    "$CONTROL_PLANE_IMAGE" "$EVIDENCE_DIRECTORY"
