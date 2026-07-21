#!/bin/sh

set -eu

lab_directory="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
repository_root="$(CDPATH='' cd -- "$lab_directory/../../.." && pwd)"
production_image="${PRODUCTION_IMAGE:?PRODUCTION_IMAGE is required}"
testing_host_image="${TESTING_HOST_IMAGE:?TESTING_HOST_IMAGE is required}"
evidence_parent="${PRODUCTION_APPLICATION_BLUE_GREEN_EVIDENCE_DIRECTORY:-${TMPDIR:-/tmp}}"

plain_fail()
{
    printf 'PRODUCTION_APPLICATION_BLUE_GREEN_FAIL %s\n' "$1" >&2
    exit 1
}

for command in cat docker jq ssh-keygen mktemp od python3 tr; do
    command -v "$command" >/dev/null 2>&1 || plain_fail "required command is absent: $command"
done

global_timeout_seconds="${PRODUCTION_APPLICATION_BLUE_GREEN_TIMEOUT_SECONDS:-900}"
case "$global_timeout_seconds" in
    ''|*[!0-9]*) plain_fail 'global timeout must be a positive integer' ;;
esac
test "$global_timeout_seconds" -ge 66 && test "$global_timeout_seconds" -le 900 \
    || plain_fail 'global timeout must be between 66 and 900 seconds'

monotonic_milliseconds()
{
    python3 -c 'import time; print(int(time.monotonic() * 1000))'
}

global_deadline_milliseconds=$(($(monotonic_milliseconds) + global_timeout_seconds * 1000 - 5000))
work_deadline_milliseconds=$((global_deadline_milliseconds - 60000))
active_child_pid=
test -d "$evidence_parent" && test -w "$evidence_parent" \
    || plain_fail 'evidence parent must already exist and be writable'
evidence_root=$(mktemp -d "${evidence_parent%/}/production-application-blue-green.XXXXXX")
chmod 0700 "$evidence_root"
evidence_directory="$evidence_root/shared"
mkdir "$evidence_directory"
chmod 0777 "$evidence_directory"
capture_output_file="$evidence_root/command-output"
touch "$capture_output_file"
chmod 0600 "$capture_output_file"

owned_child_is_running()
{
    expected_pid=$1
    for running_pid in $(jobs -p); do
        if test "$running_pid" = "$expected_pid"; then
            return 0
        fi
    done

    return 1
}

terminate_owned_child()
{
    child_pid=$1
    hard_deadline_milliseconds=$2
    if owned_child_is_running "$child_pid"; then
        kill -TERM "$child_pid" 2>/dev/null || true
    fi
    term_deadline_milliseconds=$(($(monotonic_milliseconds) + 5000))
    if test "$term_deadline_milliseconds" -gt "$hard_deadline_milliseconds"; then
        term_deadline_milliseconds=$hard_deadline_milliseconds
    fi
    while owned_child_is_running "$child_pid" \
        && test "$(monotonic_milliseconds)" -lt "$term_deadline_milliseconds"; do
        sleep 1
    done
    if owned_child_is_running "$child_pid"; then
        kill -KILL "$child_pid" 2>/dev/null || true
    fi
    kill_deadline_milliseconds=$(($(monotonic_milliseconds) + 5000))
    if test "$kill_deadline_milliseconds" -gt "$hard_deadline_milliseconds"; then
        kill_deadline_milliseconds=$hard_deadline_milliseconds
    fi
    while owned_child_is_running "$child_pid" \
        && test "$(monotonic_milliseconds)" -lt "$kill_deadline_milliseconds"; do
        sleep 1
    done
    owned_child_is_running "$child_pid" && return 124
    wait "$child_pid" 2>/dev/null || true
}

run_with_deadline_at()
{
    deadline_milliseconds=$1
    shift
    "$@" &
    active_child_pid=$!
    while owned_child_is_running "$active_child_pid"; do
        if test "$(monotonic_milliseconds)" -ge "$deadline_milliseconds"; then
            terminate_owned_child "$active_child_pid" "$global_deadline_milliseconds" || true
            active_child_pid=
            printf 'PRODUCTION_APPLICATION_BLUE_GREEN_FAIL command exceeded owned deadline: %s\n' "$1" >&2
            return 124
        fi
        sleep 1
    done
    if wait "$active_child_pid"; then
        command_status=0
    else
        command_status=$?
    fi
    active_child_pid=

    return "$command_status"
}

capture_with_deadline_at()
{
    capture_deadline_milliseconds=$1
    capture_mode=$2
    shift 2
    if test "$capture_mode" = combined; then
        if run_with_deadline_at "$capture_deadline_milliseconds" "$@" >"$capture_output_file" 2>&1; then
            capture_status=0
        else
            capture_status=$?
        fi
    else
        if run_with_deadline_at "$capture_deadline_milliseconds" "$@" >"$capture_output_file"; then
            capture_status=0
        else
            capture_status=$?
        fi
    fi
    CAPTURED_OUTPUT=$(cat "$capture_output_file")

    return "$capture_status"
}

persist_sanitized_capture_output()
{
    test -n "${evidence_directory:-}" || return 0
    test -f "$capture_output_file" || return 0
    command_output_artifact="$evidence_directory/command-output.log"
    temporary_command_output_artifact=$(mktemp "$evidence_directory/.command-output.XXXXXX") || return 1
    chmod 0600 "$temporary_command_output_artifact"
    if python3 "$lab_directory/sanitize-evidence.py" \
        "$capture_output_file" "$temporary_command_output_artifact"
    then
        mv -f "$temporary_command_output_artifact" "$command_output_artifact"
        chmod 0600 "$command_output_artifact"
        return 0
    fi
    rm -f "$temporary_command_output_artifact"

    return 1
}

if test -n "${DOCKER_HOST:-}"; then
    outer_docker_host=$DOCKER_HOST
else
    selected_context="${DOCKER_CONTEXT:-}"
    if test -z "$selected_context"; then
        capture_with_deadline_at "$work_deadline_milliseconds" stdout env -u DOCKER_HOST -u DOCKER_CONTEXT docker context show
        selected_context=$CAPTURED_OUTPUT
    fi
    capture_with_deadline_at "$work_deadline_milliseconds" stdout env -u DOCKER_HOST -u DOCKER_CONTEXT \
        docker context inspect "$selected_context" --format '{{.Endpoints.docker.Host}}'
    outer_docker_host=$CAPTURED_OUTPUT
fi
case "$outer_docker_host" in
    unix:///*) outer_docker_socket=${outer_docker_host#unix://} ;;
    *) plain_fail 'outer Docker must resolve to an absolute local unix socket; TCP, SSH, and opaque contexts are rejected' ;;
esac
case "$outer_docker_socket" in
    /*) ;;
    *) plain_fail 'outer Docker unix socket path is not absolute' ;;
esac
test -S "$outer_docker_socket" || plain_fail "outer Docker unix socket is absent: $outer_docker_socket"
unset BUILDX_BUILDER DOCKER_CERT_PATH DOCKER_CONTEXT DOCKER_TLS_VERIFY
export DOCKER_HOST="$outer_docker_host"

outer_docker()
{
    run_with_deadline_at "$work_deadline_milliseconds" env DOCKER_HOST="$outer_docker_host" docker "$@"
}

capture_outer_docker()
{
    capture_with_deadline_at "$work_deadline_milliseconds" stdout env DOCKER_HOST="$outer_docker_host" docker "$@"
}

capture_outer_docker_combined()
{
    capture_with_deadline_at "$work_deadline_milliseconds" combined env DOCKER_HOST="$outer_docker_host" docker "$@"
}

cleanup_outer_docker()
{
    run_with_deadline_at "$global_deadline_milliseconds" env DOCKER_HOST="$outer_docker_host" docker "$@"
}

capture_cleanup_outer_docker()
{
    capture_with_deadline_at "$global_deadline_milliseconds" stdout env DOCKER_HOST="$outer_docker_host" docker "$@"
}

outer_docker version >/dev/null || plain_fail 'validated outer Docker unix socket is not usable'
run_token=$(basename "$evidence_root" | tr '[:upper:].' '[:lower:]-')
project_name="$run_token-$$"
control_plane_container="$project_name-control-plane"
control_network_name="$project_name-control"
fixture_image="production-application-fixture:$project_name"
traefik_fixture_image="production-application-traefik:$project_name"
helper_fixture_image="production-application-helper:$project_name"
registry_fixture_image="production-application-registry:$project_name"
network_created=0
fixture_tag_created=0
dependency_aliases_created=0
resources_cleaned=0
export CONTROL_NETWORK_NAME="$control_network_name" EVIDENCE_DIRECTORY="$evidence_directory"
export PRODUCTION_IMAGE="$production_image" TESTING_HOST_IMAGE="$testing_host_image" FIXTURE_IMAGE="$fixture_image"
export TRAEFIK_RUNTIME_IMAGE="$traefik_fixture_image" HELPER_RUNTIME_IMAGE="$helper_fixture_image"
export REGISTRY_RUNTIME_IMAGE="$registry_fixture_image"

compose()
{
    outer_docker compose --ansi never --project-name "$project_name" --file "$lab_directory/compose.yaml" "$@"
}

fail()
{
    persist_sanitized_capture_output || printf 'PRODUCTION_APPLICATION_BLUE_GREEN_WARN could not persist sanitized command output\n' >&2
    printf 'PRODUCTION_APPLICATION_BLUE_GREEN_FAIL %s evidence=%s\n' "$1" "$evidence_directory" >&2
    exit 1
}

ensure_image()
{
    image=$1
    if capture_outer_docker_combined image inspect "$image"; then
        return
    else
        inspect_status=$?
        inspect_error=$CAPTURED_OUTPUT
    fi
    test "$inspect_status" -ne 124 || return "$inspect_status"
    case "$inspect_error" in
        *'No such image'*) outer_docker pull "$image" >/dev/null ;;
        *) fail "could not inspect dependency image $image: $inspect_error" ;;
    esac
}

record_cleanup_failure()
{
    test -n "$cleanup_failure" || cleanup_failure=$1
}

cleanup_resources()
{
    cleanup_failure=
    if test -n "$active_child_pid"; then
        terminate_owned_child "$active_child_pid" "$global_deadline_milliseconds" \
            || record_cleanup_failure 'active Docker client did not terminate before the global deadline'
        active_child_pid=
    fi
    cleanup_outer_docker compose --ansi never --project-name "$project_name" --file "$lab_directory/compose.yaml" \
        down --volumes --remove-orphans >/dev/null 2>&1 \
        || record_cleanup_failure 'owned Compose project removal failed'
    if test "$network_created" -eq 1; then
        cleanup_outer_docker network rm "$control_network_name" >/dev/null 2>&1 \
            || record_cleanup_failure 'owned control network removal failed'
    fi
    if test "$fixture_tag_created" -eq 1; then
        cleanup_outer_docker image rm "$fixture_image" >/dev/null 2>&1 \
            || record_cleanup_failure 'owned fixture image alias removal failed'
    fi
    if test "$dependency_aliases_created" -eq 1; then
        cleanup_outer_docker image rm "$traefik_fixture_image" "$helper_fixture_image" "$registry_fixture_image" \
            >/dev/null 2>&1 || record_cleanup_failure 'owned dependency image alias removal failed'
    fi

    if capture_cleanup_outer_docker ps --all --quiet --filter "label=com.docker.compose.project=$project_name"; then
        test -z "$CAPTURED_OUTPUT" || record_cleanup_failure 'owned Compose containers remain after cleanup'
    else
        record_cleanup_failure 'owned Compose container census failed'
    fi
    if capture_cleanup_outer_docker volume ls --quiet --filter "label=com.docker.compose.project=$project_name"; then
        test -z "$CAPTURED_OUTPUT" || record_cleanup_failure 'owned Compose volumes remain after cleanup'
    else
        record_cleanup_failure 'owned Compose volume census failed'
    fi
    if capture_cleanup_outer_docker network ls --quiet --filter "label=coolify.integration.owner=$project_name"; then
        test -z "$CAPTURED_OUTPUT" || record_cleanup_failure 'owned control network remains after cleanup'
    else
        record_cleanup_failure 'owned control network census failed'
    fi
    if capture_cleanup_outer_docker image ls --format '{{.Repository}}:{{.Tag}}'; then
        for owned_image in "$fixture_image" "$traefik_fixture_image" "$helper_fixture_image" "$registry_fixture_image"; do
            printf '%s\n' "$CAPTURED_OUTPUT" | grep -Fx "$owned_image" >/dev/null \
                && record_cleanup_failure "owned image alias remains after cleanup: $owned_image"
        done
    else
        record_cleanup_failure 'owned image alias census failed'
    fi

    if test -n "$cleanup_failure"; then
        printf 'PRODUCTION_APPLICATION_BLUE_GREEN_CLEANUP_FAIL %s evidence=%s\n' "$cleanup_failure" "$evidence_directory" >&2
        return 1
    fi

    return 0
}

cleanup_on_exit()
{
    original_status=$?
    trap - EXIT INT TERM HUP
    persist_sanitized_capture_output || printf 'PRODUCTION_APPLICATION_BLUE_GREEN_WARN could not persist sanitized command output\n' >&2
    if test "$resources_cleaned" -eq 0; then
        cleanup_resources || true
    fi
    exit "$original_status"
}

interrupt()
{
    exit 130
}

trap cleanup_on_exit EXIT
trap interrupt INT TERM HUP

attempt=0
while test "$network_created" -eq 0; do
    attempt=$((attempt + 1))
    test "$attempt" -le 32 || fail 'could not allocate a collision-free owned control subnet'
    random_value=$(od -An -N2 -tu2 /dev/urandom | tr -d ' ')
    second_octet=$((20 + (random_value % 10)))
    third_octet=$(((random_value / 10) % 256))
    control_subnet="172.$second_octet.$third_octet.0/24"
    if capture_outer_docker_combined network create --internal --subnet "$control_subnet" \
        --label "coolify.integration.owner=$project_name" "$control_network_name"; then
        network_created=1
    else
        network_error=$CAPTURED_OUTPUT
        case "$network_error" in
            *overlap*|*Overlap*) continue ;;
            *) fail "owned control network creation failed: $network_error" ;;
        esac
    fi
done

capture_outer_docker image inspect "$production_image" --format '{{.Id}}'
production_image_id=$CAPTURED_OUTPUT
capture_outer_docker image inspect "$testing_host_image" --format '{{.Id}}'
testing_host_image_id=$CAPTURED_OUTPUT
printf '%s\n' "$production_image_id" | grep -Eq '^sha256:[a-f0-9]{64}$' || fail 'production image ID is not full length'
printf '%s\n' "$testing_host_image_id" | grep -Eq '^sha256:[a-f0-9]{64}$' || fail 'testing-host image ID is not full length'

fixture_tag_created=1
DOCKER_BUILDKIT=1 outer_docker build --file "$lab_directory/Dockerfile.fixture" \
    --tag "$fixture_image" "$lab_directory"
capture_outer_docker image inspect "$fixture_image" --format '{{.Id}}'
FIXTURE_IMAGE_ID=$CAPTURED_OUTPUT
TRAEFIK_VERSION=$(jq -er '.traefik["v3.6"] | select(test("^[0-9]+\\.[0-9]+\\.[0-9]+$"))' "$repository_root/versions.json")
test "$TRAEFIK_VERSION" = 3.6.23 || fail 'reviewed Traefik digest must be updated with versions.json'
TRAEFIK_SOURCE_IMAGE="traefik:3.6.23@sha256:f5dba1e65167778cd5f8d1b463fc5d200f49d40c6458fc9f4b391a68ebfb9534"
HELPER_SOURCE_IMAGE="ghcr.io/coollabsio/coolify-helper:1.0.14@sha256:5dd461dcc5dbb96733e3b68294440e6465ce104de6230586151e7976783b7d60"
REGISTRY_SOURCE_IMAGE="registry:2.8.3@sha256:a3d8aaa63ed8681a604f1dea0aa03f100d5895b6a58ace528858a7b332415373"
ensure_image "$TRAEFIK_SOURCE_IMAGE"
ensure_image "$HELPER_SOURCE_IMAGE"
ensure_image "$REGISTRY_SOURCE_IMAGE"
ensure_image 'docker:28.4.0-dind@sha256:2ceb471176ad51e37145d43ce7cbf0fa5d644a2b185bd537f0ef695fb3a37497'
ensure_image 'postgres:15.18-alpine3.24@sha256:3d0f7584ed7d04e27fa050d6683a74746608faf21f202be78460d679cc56461f'
ensure_image 'redis:7-alpine@sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99'

dependency_aliases_created=1
outer_docker image tag "$TRAEFIK_SOURCE_IMAGE" "$traefik_fixture_image"
outer_docker image tag "$HELPER_SOURCE_IMAGE" "$helper_fixture_image"
outer_docker image tag "$REGISTRY_SOURCE_IMAGE" "$registry_fixture_image"
capture_outer_docker image inspect "$traefik_fixture_image" --format '{{.Id}}'
TRAEFIK_IMAGE_ID=$CAPTURED_OUTPUT
capture_outer_docker image inspect "$helper_fixture_image" --format '{{.Id}}'
HELPER_IMAGE_ID=$CAPTURED_OUTPUT
capture_outer_docker image inspect "$registry_fixture_image" --format '{{.Id}}'
REGISTRY_IMAGE_ID=$CAPTURED_OUTPUT
export FIXTURE_IMAGE_ID TRAEFIK_VERSION TRAEFIK_IMAGE_ID HELPER_IMAGE_ID REGISTRY_IMAGE_ID
export TRAEFIK_SOURCE_IMAGE HELPER_SOURCE_IMAGE REGISTRY_SOURCE_IMAGE

outer_docker save --output "$evidence_directory/images.tar" \
    "$fixture_image" "$traefik_fixture_image" "$helper_fixture_image" "$registry_fixture_image"

compose config >"$evidence_directory/compose-config.yaml"
compose up --detach postgres redis dind keygen testing-host
capture_outer_docker compose --ansi never --project-name "$project_name" --file "$lab_directory/compose.yaml" \
    ps --all --quiet keygen
keygen_container_id=$CAPTURED_OUTPUT
capture_outer_docker compose --ansi never --project-name "$project_name" --file "$lab_directory/compose.yaml" \
    ps --all --quiet testing-host
testing_host_container_id=$CAPTURED_OUTPUT
printf '%s\n' "$keygen_container_id" | grep -Eq '^[a-f0-9]{64}$' || fail 'keygen container ID is truncated'
printf '%s\n' "$testing_host_container_id" | grep -Eq '^[a-f0-9]{64}$' || fail 'testing-host container ID is truncated'
capture_outer_docker inspect "$keygen_container_id" --format '{{.Image}}'
actual_keygen_image_id=$CAPTURED_OUTPUT
capture_outer_docker inspect "$testing_host_container_id" --format '{{.Image}}'
actual_testing_host_image_id=$CAPTURED_OUTPUT
test "$actual_keygen_image_id" = "$testing_host_image_id" || fail 'keygen did not run the supplied exact testing-host image'
test "$actual_testing_host_image_id" = "$testing_host_image_id" || fail 'testing host did not run the supplied exact testing-host image'
remote_init_timeout_seconds=$(((work_deadline_milliseconds - $(monotonic_milliseconds)) / 1000))
test "$remote_init_timeout_seconds" -gt 0 || fail 'global work deadline elapsed before remote initialization'
compose exec -T \
    --env "PRODUCTION_APPLICATION_BLUE_GREEN_REMOTE_TIMEOUT_SECONDS=$remote_init_timeout_seconds" \
    testing-host timeout -s TERM -k 5 "$remote_init_timeout_seconds" \
    /bin/sh /lab/remote-init.sh >"$evidence_directory/remote-init.log"

compose run --no-deps --detach --name "$control_plane_container" control-plane >/dev/null
capture_outer_docker inspect "$control_plane_container" --format '{{.Id}}'
control_plane_id=$CAPTURED_OUTPUT
printf '%s\n' "$control_plane_id" | grep -Eq '^[a-f0-9]{64}$' || fail 'control-plane container ID is truncated'
capture_outer_docker inspect "$control_plane_container" --format '{{.State.Running}}'
control_plane_running=$CAPTURED_OUTPUT
while test "$control_plane_running" = true; do
    if test "$(monotonic_milliseconds)" -ge "$work_deadline_milliseconds"; then
        outer_docker logs "$control_plane_container" >"$evidence_directory/control-plane.log" 2>&1 || true
        fail 'production application blue-green contract exceeded its global runtime'
    fi
    sleep 1
    capture_outer_docker inspect "$control_plane_container" --format '{{.State.Running}}'
    control_plane_running=$CAPTURED_OUTPUT
done
outer_docker logs "$control_plane_container" >"$evidence_directory/control-plane.log" 2>&1 || true
capture_outer_docker inspect "$control_plane_container" --format '{{.State.ExitCode}}'
control_plane_exit=$CAPTURED_OUTPUT
if test "$control_plane_exit" -ne 0; then
    tail -n 240 "$evidence_directory/control-plane.log" >&2 || true
    fail 'real preparation and activation execution failed'
fi

test -s "$evidence_directory/report.json" || fail 'deployment report is absent'
capture_outer_docker inspect "$control_plane_container" --format '{{.Image}}'
actual_control_plane_image_id=$CAPTURED_OUTPUT
test "$actual_control_plane_image_id" = "$production_image_id" || fail 'control plane did not run the supplied exact production image'
report_tmp=$(mktemp "$evidence_directory/report.final.XXXXXX")
jq --arg controlPlaneContainerId "$control_plane_id" \
    --arg productionImage "$production_image" \
    --arg productionImageId "$production_image_id" \
    --arg testingHostImageId "$testing_host_image_id" \
    --arg keygenContainerId "$keygen_container_id" \
    --arg testingHostContainerId "$testing_host_container_id" \
    --arg traefikImageId "$TRAEFIK_IMAGE_ID" \
    --arg helperImageId "$HELPER_IMAGE_ID" \
    --arg registryImageId "$REGISTRY_IMAGE_ID" \
    --arg traefikSourceImage "$TRAEFIK_SOURCE_IMAGE" \
    --arg helperSourceImage "$HELPER_SOURCE_IMAGE" \
    --arg registrySourceImage "$REGISTRY_SOURCE_IMAGE" \
    --arg fixtureImageId "$FIXTURE_IMAGE_ID" \
    '. + {
        controlPlaneContainerId: $controlPlaneContainerId,
        productionImage: $productionImage,
        productionImageId: $productionImageId,
        fixtureImageId: $fixtureImageId,
        testingHostImageId: $testingHostImageId,
        testingHostRuntime: {
            keygenContainerId: $keygenContainerId,
            testingHostContainerId: $testingHostContainerId
        },
        pinnedDependencyImages: {
            traefik: { source: $traefikSourceImage, imageId: $traefikImageId },
            helper: { source: $helperSourceImage, imageId: $helperImageId },
            registry: { source: $registrySourceImage, imageId: $registryImageId }
        }
    }' "$evidence_directory/report.json" >"$report_tmp"
mv "$report_tmp" "$evidence_directory/report.json"

jq -e '
    .first.status == "finished"
    and .second.status == "finished"
    and (.first.prepareAttempt != .first.activationAttempt)
    and (.second.prepareAttempt != .second.activationAttempt)
    and ([.first, .second] | all(
        .queue.before == {pending: 1, reserved: 0, delayed: 0}
        and .queue.during == {pending: 0, reserved: 1, delayed: 0}
        and .queue.after == {pending: 0, reserved: 0, delayed: 0}
        and (.queue.transportUuid | test("^[A-Za-z0-9-]{36}$"))
        and .queue.attempts == 1
    ))
    and (.first.candidateContainerId | test("^[a-f0-9]{64}$"))
    and (.second.candidateContainerId | test("^[a-f0-9]{64}$"))
    and (.first.routingRevision | type == "number" and . > 0)
    and ((.first.routingRevision) as $firstRoutingRevision
        | (.second.routingRevision | type == "number" and . > $firstRoutingRevision))
    and (.managedContainers | length == 2)
    and (.managedContainers.blue == .first.candidateContainerId)
    and (.managedContainers.green == .second.candidateContainerId)
    and (.controlPlaneContainerId | test("^[a-f0-9]{64}$"))
    and (.productionImageId | test("^sha256:[a-f0-9]{64}$"))
    and (.fixtureImageId | test("^sha256:[a-f0-9]{64}$"))
    and (.testingHostImageId | test("^sha256:[a-f0-9]{64}$"))
    and (.testingHostRuntime.keygenContainerId | test("^[a-f0-9]{64}$"))
    and (.testingHostRuntime.testingHostContainerId | test("^[a-f0-9]{64}$"))
    and ([.pinnedDependencyImages[] | (.source | test("@sha256:[a-f0-9]{64}$"))] | all)
    and ([.pinnedDependencyImages[] | (.imageId | test("^sha256:[a-f0-9]{64}$"))] | all)
    and (.daemonId | type == "string" and length > 0)
    and .activationElapsedMilliseconds >= 2000
    and .continuity.requestCount >= 30
    and .continuity.firstCount >= 5
    and .continuity.secondCount >= 3
    and .continuity.postReplayCount >= 5
    and (.continuity.replayCompletedAt | type == "number")
    and .continuity.finalAcknowledgement == .expectedAcknowledgements.second
    and (.continuity as $continuity
        | ([$continuity.samples[] | select(.afterTerminalReplay)] | length) == $continuity.postReplayCount)
    and ((.expectedAcknowledgements.second) as $secondAcknowledgement
        | ([.continuity.samples[] | select(.afterTerminalReplay) | .acknowledgement == $secondAcknowledgement] | all))
    and .continuity.errors == []
    and (.continuity as $continuity | ($continuity.samples | length) == $continuity.requestCount)
    and .continuity.maxGapMilliseconds < 1500
    and .activeRoute.activeColor == "green"
    and .activeRoute.activeDeploymentUuid == .second.deploymentUuid
    and .activeRoute.activeContainerId == .managedContainers.green
    and .activeRoute.routingRevision == .second.routingRevision
    and .greenInspection.dockerId == .managedContainers.green
    and .greenInspection.status == "running"
    and .greenInspection.health == "healthy"
    and (.trafficContainerId | test("^[a-f0-9]{64}$"))
' "$evidence_directory/report.json" >/dev/null || fail 'deployment report contract failed'

if cleanup_resources; then
    cleanup_status=0
else
    cleanup_status=$?
fi
resources_cleaned=1
trap - EXIT INT TERM HUP
test "$cleanup_status" -eq 0 || exit "$cleanup_status"

printf 'PRODUCTION_APPLICATION_BLUE_GREEN_PASS production_image=%s production_image_id=%s evidence=%s\n' \
    "$production_image" "$production_image_id" "$evidence_directory"
