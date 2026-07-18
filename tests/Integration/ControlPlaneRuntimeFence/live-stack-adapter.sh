#!/usr/bin/env bash

# A real-stack adapter for linux-host-acceptance-inner.sh. It runs only inside
# the disposable systemd/DinD host and never accepts a host socket or an
# external terminal endpoint.

set -Eeuo pipefail
umask 077

LIVE_STACK_SOURCE_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
readonly LIVE_STACK_SOURCE_DIRECTORY
# shellcheck disable=SC1091 # Sibling files are installed atomically with this entrypoint.
source "$LIVE_STACK_SOURCE_DIRECTORY/live-stack-contract.bash"
# shellcheck disable=SC1091 # Sibling files are installed atomically with this entrypoint.
source "$LIVE_STACK_SOURCE_DIRECTORY/live-stack-bootstrap.bash"
# shellcheck disable=SC1091 # Sibling files are installed atomically with this entrypoint.
source "$LIVE_STACK_SOURCE_DIRECTORY/live-stack-runtime.bash"

live_stack_fail()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE_LIVE_STACK_FAILURE %s\n' "$1" >&2
    exit 1
}

live_stack_blocked()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE_LIVE_STACK_BLOCKED %s\n' "$1" >&2
    exit 78
}

live_stack_assert_identifier()
{
    [[ $2 =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ ]] \
        || live_stack_fail "$1 is not a safe identifier"
}

live_stack_assert_digest()
{
    [[ $2 =~ ^[A-Za-z0-9][A-Za-z0-9._/:@-]*@sha256:[a-f0-9]{64}$ ]] \
        || live_stack_blocked "$1 must be a supplied immutable digest reference"
}

live_stack_assert_image_digest()
{
    [[ $2 =~ ^sha256:[a-f0-9]{64}$ ]] \
        || live_stack_blocked "$1 must be an immutable inner image config digest"
}

live_stack_assert_sha256()
{
    [[ $2 =~ ^[a-f0-9]{64}$ ]] \
        || live_stack_blocked "$1 must be a SHA-256 evidence value"
}

live_stack_platform_from_uname()
{
    local system=$1 machine=$2

    [[ $system == Linux ]] || live_stack_blocked "live stack requires native Linux: system=$system"
    case "$machine" in
        x86_64|amd64) printf '%s' linux/amd64 ;;
        aarch64|arm64) printf '%s' linux/arm64 ;;
        *) live_stack_blocked "live stack host architecture is unsupported: machine=$machine" ;;
    esac
}

live_stack_host_platform()
{
    local expected_platform=${CONTROL_PLANE_RUNTIME_HOST_GATE_PLATFORM:-} native_platform

    case "$expected_platform" in
        linux/amd64|linux/arm64) ;;
        *) live_stack_blocked 'live stack is missing an approved host-gate platform attestation' ;;
    esac
    native_platform=$(live_stack_platform_from_uname "$(uname -s)" "$(uname -m)")
    [[ $native_platform == "$expected_platform" ]] \
        || live_stack_blocked "live stack platform differs from the native inner host: expected=$expected_platform host=$native_platform"
    printf '%s' "$native_platform"
}

live_stack_assert_native_docker_platform()
{
    local actual_platform

    actual_platform=$(docker version --format '{{.Server.Os}}/{{.Server.Arch}}') \
        || live_stack_blocked 'live stack could not attest the nested Docker platform'
    [[ $actual_platform == "$LIVE_STACK_PLATFORM" ]] \
        || live_stack_blocked "live stack Docker platform differs from the native inner host: expected=$LIVE_STACK_PLATFORM actual=$actual_platform"
}

live_stack_assert_loaded_image()
{
    local role=$1 image=$2

    docker image inspect "$image" >/dev/null \
        || live_stack_blocked "inner Docker is missing the loaded immutable $role image"
    [[ $(docker image inspect --format '{{.Id}}' "$image") == "$image" \
        && $(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$image") == "$LIVE_STACK_PLATFORM" ]] \
        || live_stack_blocked "inner Docker loaded the wrong immutable $role image content or platform"
}

live_stack_check()
{
    local component

    for component in live-stack-adapter.sh live-stack-contract.bash live-stack-bootstrap.bash live-stack-runtime.bash; do
        [[ -f $LIVE_STACK_SOURCE_DIRECTORY/$component && ! -L $LIVE_STACK_SOURCE_DIRECTORY/$component ]] \
            || live_stack_fail "reviewed live-stack source is absent: $component"
        bash -n "$LIVE_STACK_SOURCE_DIRECTORY/$component"
    done
    grep -F -q 'CONTROL_PLANE_STARTUP_MODE: web-only' \
        "$LIVE_STACK_SOURCE_DIRECTORY/../../../docker/control-plane-blue-green/compose.yaml" \
        || live_stack_fail 'canonical Coolify web-only compose contract is absent'
    grep -F -q 'ENTRYPOINT ["/usr/local/bin/coolify-entrypoint"]' \
        "$LIVE_STACK_SOURCE_DIRECTORY/../../../docker/production/Dockerfile" \
        || live_stack_fail 'canonical Coolify production image contract is absent'
    grep -F -q 'target: control-plane-direct-probe-token' \
        "$LIVE_STACK_SOURCE_DIRECTORY/../../../docker/control-plane-blue-green/compose.yaml" \
        || live_stack_fail 'canonical Coolify direct-probe secret mount contract is absent'
    [[ $(live_stack_platform_from_uname Linux x86_64) == linux/amd64 \
        && $(live_stack_platform_from_uname Linux aarch64) == linux/arm64 ]] \
        || live_stack_fail 'live-stack native platform mapping is incomplete'
    if (live_stack_platform_from_uname Darwin arm64) >/dev/null 2>&1 \
        || (live_stack_platform_from_uname Linux riscv64) >/dev/null 2>&1; then
        live_stack_fail 'live-stack native platform mapping accepted Darwin or an unsupported architecture'
    fi
    printf 'CONTROL_PLANE_RUNTIME_FENCE_LIVE_STACK check=passed\n'
}

if [[ ${1:-} == --check ]]; then
    [[ $# -eq 1 ]] || {
        printf 'usage: live-stack-adapter.sh --check\n' >&2
        exit 64
    }
    live_stack_check
    exit 0
fi

[[ $# -eq 3 ]] || {
    printf 'usage: live-stack-adapter.sh ACTION OPERATION CONTRACT\n' >&2
    exit 64
}

action=$1
readonly LIVE_STACK_OPERATION=$2
readonly LIVE_STACK_CONTRACT=$3
readonly LIVE_STACK_ROOT=${CONTROL_PLANE_RUNTIME_HOST_GATE_ROOT:-}
readonly LIVE_STACK_WEB_A_SOURCE_IMAGE=${CONTROL_PLANE_RUNTIME_WEB_A_IMAGE:-}
readonly LIVE_STACK_WEB_B_SOURCE_IMAGE=${CONTROL_PLANE_RUNTIME_WEB_B_IMAGE:-}
readonly LIVE_STACK_PROXY_SOURCE_IMAGE=${CONTROL_PLANE_RUNTIME_PROXY_IMAGE:-}
readonly LIVE_STACK_WEB_IMAGE=${CONTROL_PLANE_RUNTIME_WEB_INNER_IMAGE_DIGEST:-}
readonly LIVE_STACK_PROXY_IMAGE=${CONTROL_PLANE_RUNTIME_PROXY_INNER_IMAGE_DIGEST:-}
readonly LIVE_STACK_IMAGE_TRANSPORT_OPERATION=${CONTROL_PLANE_RUNTIME_IMAGE_TRANSPORT_OPERATION:-}
readonly LIVE_STACK_IMAGE_TRANSPORT_MANIFEST_SHA256=${CONTROL_PLANE_RUNTIME_IMAGE_TRANSPORT_MANIFEST_SHA256:-}
readonly LIVE_STACK_WEB_ARCHIVE_SHA256=${CONTROL_PLANE_RUNTIME_WEB_ARCHIVE_SHA256:-}
readonly LIVE_STACK_PROXY_ARCHIVE_SHA256=${CONTROL_PLANE_RUNTIME_PROXY_ARCHIVE_SHA256:-}
readonly LIVE_STACK_WEB_CONTRACT_SHA256=${CONTROL_PLANE_RUNTIME_WEB_CONTRACT_SHA256:-}
readonly LIVE_STACK_PROXY_CONTRACT_SHA256=${CONTROL_PLANE_RUNTIME_PROXY_CONTRACT_SHA256:-}
LIVE_STACK_PLATFORM=$(live_stack_host_platform)
readonly LIVE_STACK_PLATFORM
live_stack_assert_native_docker_platform

live_stack_assert_identifier operation "$LIVE_STACK_OPERATION"
[[ $LIVE_STACK_ROOT == "/var/lib/coolify-runtime-fence-host-gate/${LIVE_STACK_OPERATION}" \
    && ! -L $LIVE_STACK_ROOT ]] \
    || live_stack_fail 'live-stack root is outside the disposable host-gate operation directory'
[[ $LIVE_STACK_CONTRACT == "$LIVE_STACK_ROOT/live-stack.env" ]] \
    || live_stack_fail 'live-stack contract path is outside the operation directory'
live_stack_assert_digest web-a-source-image "$LIVE_STACK_WEB_A_SOURCE_IMAGE"
live_stack_assert_digest web-b-source-image "$LIVE_STACK_WEB_B_SOURCE_IMAGE"
live_stack_assert_digest proxy-source-image "$LIVE_STACK_PROXY_SOURCE_IMAGE"
[[ $LIVE_STACK_WEB_A_SOURCE_IMAGE == "$LIVE_STACK_WEB_B_SOURCE_IMAGE" ]] \
    || live_stack_blocked 'the live stack requires one supplied immutable Coolify/Laravel digest for all four web members'
[[ $LIVE_STACK_IMAGE_TRANSPORT_OPERATION == "$LIVE_STACK_OPERATION" ]] \
    || live_stack_blocked 'the inner image transport evidence belongs to a different operation'
live_stack_assert_sha256 image-transport-manifest "$LIVE_STACK_IMAGE_TRANSPORT_MANIFEST_SHA256"
live_stack_assert_sha256 web-archive "$LIVE_STACK_WEB_ARCHIVE_SHA256"
live_stack_assert_sha256 proxy-archive "$LIVE_STACK_PROXY_ARCHIVE_SHA256"
live_stack_assert_sha256 web-content-contract "$LIVE_STACK_WEB_CONTRACT_SHA256"
live_stack_assert_sha256 proxy-content-contract "$LIVE_STACK_PROXY_CONTRACT_SHA256"
live_stack_assert_image_digest web-inner-image "$LIVE_STACK_WEB_IMAGE"
live_stack_assert_image_digest proxy-inner-image "$LIVE_STACK_PROXY_IMAGE"
live_stack_assert_loaded_image web "$LIVE_STACK_WEB_IMAGE"
live_stack_assert_loaded_image proxy "$LIVE_STACK_PROXY_IMAGE"

readonly LIVE_STACK_NETWORK=${LIVE_STACK_OPERATION}-control
readonly LIVE_STACK_ESCAPE_NETWORK=${LIVE_STACK_OPERATION}-escape
readonly LIVE_STACK_PROXY_CONTAINER=${LIVE_STACK_OPERATION}-proxy
readonly LIVE_STACK_POSTGRES_CONTAINER=${LIVE_STACK_OPERATION}-postgres
readonly LIVE_STACK_REDIS_CONTAINER=${LIVE_STACK_OPERATION}-redis
readonly LIVE_STACK_BLUE_WEB_A_CONTAINER=${LIVE_STACK_OPERATION}-blue-web-a
readonly LIVE_STACK_BLUE_WEB_B_CONTAINER=${LIVE_STACK_OPERATION}-blue-web-b
readonly LIVE_STACK_GREEN_WEB_A_CONTAINER=${LIVE_STACK_OPERATION}-green-web-a
readonly LIVE_STACK_GREEN_WEB_B_CONTAINER=${LIVE_STACK_OPERATION}-green-web-b
readonly LIVE_STACK_COORDINATION_VOLUME=${LIVE_STACK_OPERATION}-coordination
readonly LIVE_STACK_BLUE_WEB_A_PRIVATE_VOLUME=${LIVE_STACK_OPERATION}-blue-web-a-private
readonly LIVE_STACK_BLUE_WEB_B_PRIVATE_VOLUME=${LIVE_STACK_OPERATION}-blue-web-b-private
readonly LIVE_STACK_GREEN_WEB_A_PRIVATE_VOLUME=${LIVE_STACK_OPERATION}-green-web-a-private
readonly LIVE_STACK_GREEN_WEB_B_PRIVATE_VOLUME=${LIVE_STACK_OPERATION}-green-web-b-private
readonly LIVE_STACK_POOL_LABEL_KEY=coolify.control-plane.pool
readonly LIVE_STACK_PROXY_IP=172.30.0.2
readonly LIVE_STACK_POSTGRES_IP=172.30.0.3
readonly LIVE_STACK_REDIS_IP=172.30.0.4
readonly LIVE_STACK_BLUE_WEB_A_IP=172.30.0.10
readonly LIVE_STACK_BLUE_WEB_B_IP=172.30.0.11
readonly LIVE_STACK_GREEN_WEB_A_IP=172.30.0.20
readonly LIVE_STACK_GREEN_WEB_B_IP=172.30.0.21
readonly LIVE_STACK_ARTIFACT_DIRECTORY=$LIVE_STACK_ROOT/artifacts
readonly LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY=$LIVE_STACK_ARTIFACT_DIRECTORY/runtime
readonly LIVE_STACK_CONFIGURATION_DIRECTORY=$LIVE_STACK_ROOT/configuration
readonly LIVE_STACK_APPLICATION_ENVIRONMENT=$LIVE_STACK_CONFIGURATION_DIRECTORY/application.env
readonly LIVE_STACK_TLS_DIRECTORY=$LIVE_STACK_ROOT/tls
readonly LIVE_STACK_TRAEFIK_DIRECTORY=$LIVE_STACK_ROOT/traefik
readonly LIVE_STACK_DYNAMIC_DIRECTORY=$LIVE_STACK_TRAEFIK_DIRECTORY/dynamic
readonly LIVE_STACK_POSTGRES_DIRECTORY=$LIVE_STACK_ROOT/postgres
export LIVE_STACK_OPERATION LIVE_STACK_CONTRACT LIVE_STACK_ROOT
export LIVE_STACK_WEB_A_SOURCE_IMAGE LIVE_STACK_WEB_B_SOURCE_IMAGE LIVE_STACK_PROXY_SOURCE_IMAGE
export LIVE_STACK_WEB_IMAGE LIVE_STACK_PROXY_IMAGE
export LIVE_STACK_IMAGE_TRANSPORT_OPERATION LIVE_STACK_IMAGE_TRANSPORT_MANIFEST_SHA256
export LIVE_STACK_WEB_ARCHIVE_SHA256 LIVE_STACK_PROXY_ARCHIVE_SHA256
export LIVE_STACK_WEB_CONTRACT_SHA256 LIVE_STACK_PROXY_CONTRACT_SHA256
export LIVE_STACK_NETWORK LIVE_STACK_ESCAPE_NETWORK LIVE_STACK_PROXY_CONTAINER LIVE_STACK_POSTGRES_CONTAINER
export LIVE_STACK_REDIS_CONTAINER LIVE_STACK_BLUE_WEB_A_CONTAINER LIVE_STACK_BLUE_WEB_B_CONTAINER
export LIVE_STACK_GREEN_WEB_A_CONTAINER LIVE_STACK_GREEN_WEB_B_CONTAINER LIVE_STACK_COORDINATION_VOLUME
export LIVE_STACK_BLUE_WEB_A_PRIVATE_VOLUME LIVE_STACK_BLUE_WEB_B_PRIVATE_VOLUME
export LIVE_STACK_GREEN_WEB_A_PRIVATE_VOLUME LIVE_STACK_GREEN_WEB_B_PRIVATE_VOLUME LIVE_STACK_POOL_LABEL_KEY
export LIVE_STACK_PROXY_IP LIVE_STACK_POSTGRES_IP LIVE_STACK_REDIS_IP LIVE_STACK_BLUE_WEB_A_IP
export LIVE_STACK_BLUE_WEB_B_IP LIVE_STACK_GREEN_WEB_A_IP LIVE_STACK_GREEN_WEB_B_IP
export LIVE_STACK_ARTIFACT_DIRECTORY LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY LIVE_STACK_CONFIGURATION_DIRECTORY
export LIVE_STACK_APPLICATION_ENVIRONMENT LIVE_STACK_TLS_DIRECTORY LIVE_STACK_TRAEFIK_DIRECTORY
export LIVE_STACK_DYNAMIC_DIRECTORY LIVE_STACK_POSTGRES_DIRECTORY

case "$action" in
    bootstrap)
        live_stack_assert_absent
        live_stack_assert_real_images
        live_stack_create_resources
        live_stack_prepare_artifacts
        live_stack_initialize_state
        live_stack_start_dependencies
        live_stack_write_route_configuration "$LIVE_STACK_BLUE_WEB_A_CONTAINER" "$LIVE_STACK_BLUE_WEB_B_CONTAINER"
        live_stack_start_proxy
        live_stack_start_blue
        live_stack_wait_for_terminal
        live_stack_write_contract "$(docker network inspect --format '{{.Id}}' "$LIVE_STACK_NETWORK")"
        ;;
    start-green)
        live_stack_start_green
        ;;
    start-green-wrong-coordination-mount)
        live_stack_start_wrong_mount coordination
        ;;
    start-green-wrong-private-volume)
        live_stack_start_wrong_mount private
        ;;
    remove-retired)
        live_stack_remove_retired
        ;;
    hold-mutation-lease)
        live_stack_hold_lease
        ;;
    release-mutation-lease)
        live_stack_release_lease
        ;;
    corrupt-mutation-marker)
        live_stack_mutate_freeze_marker corrupt
        ;;
    restore-mutation-marker)
        live_stack_mutate_freeze_marker restore
        ;;
    cleanup)
        live_stack_cleanup
        ;;
    *)
        live_stack_fail 'unsupported live-stack adapter action'
        ;;
esac
