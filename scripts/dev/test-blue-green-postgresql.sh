#!/usr/bin/env bash

set -Eeuo pipefail

readonly MODE="${1:-}"
readonly FILTER="${FILTER:-}"
readonly POSTGRES_IMAGE='postgres:16-alpine@sha256:57c72fd2a128e416c7fcc499958864df5301e940bca0a56f58fddf30ffc07777'
readonly REDIS_IMAGE='redis:7-alpine@sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99'
readonly LOCAL_DATABASE='coolify_blue_green_testing'
readonly LOCAL_PASSWORD='coolify-blue-green-tests'
readonly LOCAL_CONTAINER="cpbg-postgresql-tests-$$"
readonly LOCAL_REDIS_CONTAINER="cpbg-redis-tests-$$"
readonly LOCAL_REDIS_USERNAME=''
readonly LOCAL_REDIS_PASSWORD=''
readonly LOCAL_REDIS_DB='0'
readonly LOCAL_REDIS_CACHE_DB='1'
readonly -a DATABASE_ROUTE_OVERRIDES=(
    DATABASE_URL
    DB_READ_HOST
    DB_READ_PORT
    DB_READ_USERNAME
    DB_READ_PASSWORD
    DB_WRITE_HOST
    DB_WRITE_PORT
    DB_WRITE_USERNAME
    DB_WRITE_PASSWORD
)
started_local_container=false
started_local_redis_container=false
test_config_cache_directory=''
test_config_cache_path=''

readonly -a BLUE_GREEN_POSTGRESQL_TESTS=(
    tests/Feature/Api/DeploymentCancellationApiTest.php
    tests/Feature/Api/EmergencyDeploymentRecoveryApiTest.php
    tests/Feature/ApplicationActiveContainerApiTest.php
    tests/Feature/ApplicationDeploymentBlueGreenDestinationFenceTest.php
    tests/Feature/BlueGreenApplicationDeactivationTest.php
    tests/Feature/BlueGreenApplicationManualStopTest.php
    tests/Feature/BlueGreenAutomaticRecoveryAcceptanceTest.php
    tests/Feature/BlueGreenCleanIdleContainerJournalRecoveryTest.php
    tests/Feature/BlueGreenContinuousAvailabilityAcceptanceTest.php
    tests/Feature/BlueGreenCrashBoundaryAcceptanceTest.php
    tests/Feature/BlueGreenDockerComposeTopologyTest.php
    tests/Feature/BlueGreenApplicationDeletionFenceTest.php
    tests/Feature/BlueGreenApplicationDeletionOrchestrationTest.php
    tests/Feature/BlueGreenCancellationCompensationTest.php
    tests/Feature/BlueGreenCandidateContainerSetMigrationTest.php
    tests/Feature/BlueGreenCandidateContainerSetDurabilityTest.php
    tests/Feature/BlueGreenConvergenceTest.php
    tests/Feature/BlueGreenDeactivationDurableBudgetTest.php
    tests/Feature/BlueGreenDeactivationResumeCommandTest.php
    tests/Feature/BlueGreenDeploymentReconciliationTest.php
    tests/Feature/BlueGreenEmergencyRecoveryLockContentionTest.php
    tests/Feature/BlueGreenDeploymentRecoveryFailureTest.php
    tests/Feature/BlueGreenDeploymentRecoveryTest.php
    tests/Feature/BlueGreenFinalizedDrainingRecoveryTest.php
    tests/Feature/BlueGreenFoundationMigrationReplayTest.php
    tests/Feature/BlueGreenFoundationPersistenceTest.php
    tests/Feature/BlueGreenInactiveRetirementTest.php
    tests/Feature/BlueGreenInterventionRecoveryTest.php
    tests/Feature/BlueGreenLifecyclePublicRecoveryTest.php
    tests/Feature/BlueGreenMigrationReplayTest.php
    tests/Feature/BlueGreenMultiPortPromotionAcceptanceTest.php
    tests/Feature/BlueGreenMultiDestinationTopologyTest.php
    tests/Feature/BlueGreenOperationAwareManagedRouteReadTest.php
    tests/Feature/DatabaseMigrationReadinessTest.php
    tests/Feature/LivewireDestinationTopologyPersistenceTest.php
    tests/Feature/BlueGreenOperationFencingTest.php
    tests/Feature/BlueGreenProvenEmptyDeactivationTest.php
    tests/Feature/BlueGreenReleasedV3StateMigrationTest.php
    tests/Feature/BlueGreenReplicaLifecycleTest.php
    tests/Feature/BlueGreenServerDestinationTopologyGuardTest.php
    tests/Feature/BlueGreenStaleContainerJournalRecoveryTest.php
    tests/Feature/BlueGreenSteadyStateRepairFenceTest.php
    tests/Feature/BlueGreenStoppedLegacyContainerCleanupTest.php
    tests/Feature/BlueGreenSupersessionGenerationTest.php
    tests/Feature/BlueGreenTopologyDigestCommandsTest.php
    tests/Feature/BlueGreenTopologyDigestConnectionSettingsTest.php
    tests/Feature/DeleteResourceJobBlueGreenTest.php
    tests/Feature/DockerLogOwnerLabelTest.php
    tests/Feature/FactoryIntegrityTest.php
    tests/Feature/LegacyProxyMutationPayloadAdoptionTest.php
    tests/Feature/ProxyMutationQueueGateTest.php
    tests/Feature/PostgresUserDeletionConcurrencyTest.php
    tests/Feature/PostgresBlueGreenMultiDestinationConcurrencyTest.php
    tests/Feature/Proxy/ControlPlane/PrepareControlPlaneProxyEnrollmentFromHostTest.php
    tests/Feature/QueueApplicationDeploymentCommitTest.php
    tests/Feature/QueueServerDeletionTest.php
    tests/Feature/ScheduleOnOneServerTest.php
    tests/Unit/Actions/Application/BlueGreen/BlueGreenDeploymentLockTest.php
    tests/Unit/Actions/Application/BlueGreen/BlueGreenOperationFenceTest.php
    tests/Unit/Actions/Application/BlueGreenDrainAndReleaseProofTest.php
    tests/Unit/Actions/Application/BlueGreen/BlueGreenNonRootRemoteExecutionTest.php
    tests/Unit/Actions/Application/BlueGreen/ExecuteBlueGreenDestinationMutationTest.php
    tests/Unit/Actions/Application/BlueGreen/ResolveActiveApplicationContainerStateTest.php
    tests/Unit/Actions/Application/BlueGreenPublicRecoveryTest.php
    tests/Unit/Actions/Proxy/BlueGreenCommittedContainerMutationJournalTest.php
    tests/Unit/Actions/Proxy/BlueGreenMultiPortConfigurationTest.php
    tests/Unit/Actions/Proxy/BlueGreenNonRootRemoteExecutionTest.php
    tests/Unit/Actions/Proxy/BlueGreenProxyStateRouteProofTest.php
    tests/Unit/ApplicationDeploymentActivationOrderTest.php
    tests/Unit/ApplicationOpenApiTest.php
    tests/Unit/ProxyMutationQueueTest.php
    tests/Unit/ScheduledJobsRetryConfigTest.php
)

usage() {
    echo 'Usage: scripts/dev/test-blue-green-postgresql.sh external|local' >&2
}

cleanup() {
    local test_status=$?
    local cleanup_status=0

    if [[ "$started_local_redis_container" == true ]] && ! docker rm --force --volumes "$LOCAL_REDIS_CONTAINER" >/dev/null 2>&1; then
        echo "Failed to remove $LOCAL_REDIS_CONTAINER; run make docker-ssot-clean after inspecting it." >&2
        cleanup_status=1
    fi
    if [[ "$started_local_container" == true ]] && ! docker rm --force --volumes "$LOCAL_CONTAINER" >/dev/null 2>&1; then
        echo "Failed to remove $LOCAL_CONTAINER; run make docker-ssot-clean after inspecting it." >&2
        cleanup_status=1
    fi
    if [[ -n "$test_config_cache_path" && ( -e "$test_config_cache_path" || -L "$test_config_cache_path" ) ]] \
        && ! rm -f -- "$test_config_cache_path"; then
        echo "Failed to remove disposable Laravel config cache $test_config_cache_path." >&2
        cleanup_status=1
    fi
    if [[ -n "$test_config_cache_directory" ]] && ! rmdir "$test_config_cache_directory"; then
        echo "Failed to remove disposable Laravel config cache directory $test_config_cache_directory." >&2
        cleanup_status=1
    fi

    if (( test_status != 0 )); then
        return "$test_status"
    fi

    return "$cleanup_status"
}

empty_database_route_overrides() {
    export DATABASE_URL=''
    export DB_READ_HOST=''
    export DB_READ_PORT=''
    export DB_READ_USERNAME=''
    export DB_READ_PASSWORD=''
    export DB_WRITE_HOST=''
    export DB_WRITE_PORT=''
    export DB_WRITE_USERNAME=''
    export DB_WRITE_PASSWORD=''
}

configure_test_configuration_cache() {
    local cache_directory

    cache_directory="$(mktemp -d "${TMPDIR:-/tmp}/coolify-blue-green-config-cache.XXXXXX")" || {
        echo 'Could not create an isolated Laravel config cache directory.' >&2
        exit 1
    }
    test_config_cache_directory="$cache_directory"
    test_config_cache_path="$cache_directory/config.php"
    [[ ! -e "$test_config_cache_path" && ! -L "$test_config_cache_path" ]] || {
        echo 'The isolated Laravel config cache path must not exist before tests boot.' >&2
        exit 1
    }

    export APP_CONFIG_CACHE="$test_config_cache_path"
}

reject_inherited_database_route_overrides() {
    local variable

    for variable in "${DATABASE_ROUTE_OVERRIDES[@]}"; do
        [[ -z "${!variable:-}" ]] || {
            echo "$variable must be unset to prevent external database routes." >&2
            exit 1
        }
    done
}

require_local_docker_context() {
    [[ -z "${DOCKER_HOST:-}" ]] || {
        echo 'DOCKER_HOST must be unset for the disposable local PostgreSQL lane.' >&2
        exit 1
    }
    [[ -z "${DOCKER_CONTEXT:-}" ]] || {
        echo 'DOCKER_CONTEXT must be unset for the disposable local PostgreSQL lane.' >&2
        exit 1
    }

    local docker_context docker_endpoint
    docker_context="$(docker context show 2>/dev/null)" || {
        echo 'Could not determine the active Docker context for the disposable local PostgreSQL lane.' >&2
        exit 1
    }
    docker_endpoint="$(docker context inspect "$docker_context" --format '{{.Endpoints.docker.Host}}' 2>/dev/null)" || {
        echo 'Could not determine the Docker endpoint for the disposable local PostgreSQL lane.' >&2
        exit 1
    }
    case "$docker_endpoint" in
        unix://* | npipe://*) ;;
        *)
            echo "Docker context $docker_context must use a local Unix or npipe socket." >&2
            exit 1
            ;;
    esac
}

configure_local_postgresql() {
    command -v docker >/dev/null 2>&1 || {
        echo 'Docker is required for the disposable local PostgreSQL lane.' >&2
        exit 1
    }
    require_local_docker_context

    docker create \
        --name "$LOCAL_CONTAINER" \
        --label coolify.integration.ephemeral=true \
        --env "POSTGRES_DB=$LOCAL_DATABASE" \
        --env "POSTGRES_PASSWORD=$LOCAL_PASSWORD" \
        --publish 127.0.0.1::5432 \
        "$POSTGRES_IMAGE" >/dev/null
    started_local_container=true
    docker start "$LOCAL_CONTAINER" >/dev/null

    local attempt
    for attempt in {1..30}; do
        if docker exec "$LOCAL_CONTAINER" pg_isready -U postgres -d "$LOCAL_DATABASE" >/dev/null 2>&1; then
            break
        fi
        if [[ "$attempt" == 30 ]]; then
            echo 'Disposable PostgreSQL did not become ready.' >&2
            exit 1
        fi
        sleep 1
    done

    local published_port
    published_port="$(docker port "$LOCAL_CONTAINER" 5432/tcp)"
    published_port="${published_port##*:}"
    [[ "$published_port" =~ ^[0-9]+$ ]] || {
        echo 'Could not resolve the disposable PostgreSQL port.' >&2
        exit 1
    }

    export COOLIFY_EXTERNAL_TEST_SERVICES=true
    export DB_CONNECTION=pgsql
    export DB_HOST=127.0.0.1
    export DB_PORT="$published_port"
    export DB_DATABASE="$LOCAL_DATABASE"
    export DB_USERNAME=postgres
    export DB_PASSWORD="$LOCAL_PASSWORD"
}

configure_local_redis() {
    docker create \
        --name "$LOCAL_REDIS_CONTAINER" \
        --label coolify.integration.ephemeral=true \
        --publish 127.0.0.1::6379 \
        "$REDIS_IMAGE" >/dev/null
    started_local_redis_container=true
    docker start "$LOCAL_REDIS_CONTAINER" >/dev/null

    local attempt
    for attempt in {1..30}; do
        if docker exec "$LOCAL_REDIS_CONTAINER" \
            redis-cli ping 2>/dev/null | grep -Fxq PONG; then
            break
        fi
        if [[ "$attempt" == 30 ]]; then
            echo 'Disposable Redis did not become ready.' >&2
            exit 1
        fi
        sleep 1
    done

    local published_port
    published_port="$(docker port "$LOCAL_REDIS_CONTAINER" 6379/tcp)"
    published_port="${published_port##*:}"
    [[ "$published_port" =~ ^[0-9]+$ ]] || {
        echo 'Could not resolve the disposable Redis port.' >&2
        exit 1
    }

    export REDIS_URL=''
    export REDIS_HOST=127.0.0.1
    export REDIS_PORT="$published_port"
    export REDIS_DB="$LOCAL_REDIS_DB"
    export REDIS_CACHE_DB="$LOCAL_REDIS_CACHE_DB"
    export REDIS_USERNAME="$LOCAL_REDIS_USERNAME"
    export REDIS_PASSWORD="$LOCAL_REDIS_PASSWORD"
}

reject_inherited_redis_url() {
    [[ -z "${REDIS_URL:-}" ]] || {
        echo 'REDIS_URL must be unset; configure Redis with explicit REDIS_* values.' >&2
        exit 1
    }
}

require_explicit_external_postgresql() {
    [[ "${COOLIFY_EXTERNAL_TEST_SERVICES:-}" == true ]] || {
        echo 'COOLIFY_EXTERNAL_TEST_SERVICES=true is required.' >&2
        exit 1
    }
    [[ "${DB_CONNECTION:-}" == pgsql ]] || {
        echo 'DB_CONNECTION=pgsql is required.' >&2
        exit 1
    }
    [[ "${DB_HOST:-}" == 127.0.0.1 || "${DB_HOST:-}" == localhost || "${DB_HOST:-}" == ::1 ]] || {
        echo 'DB_HOST must be loopback.' >&2
        exit 1
    }
    [[ "${DB_PORT:-}" =~ ^[0-9]+$ ]] || {
        echo 'DB_PORT must be explicitly configured.' >&2
        exit 1
    }
    [[ "${DB_DATABASE:-}" == *_testing ]] || {
        echo 'DB_DATABASE must end in _testing.' >&2
        exit 1
    }
    [[ -n "${DB_USERNAME:-}" && -n "${DB_PASSWORD:-}" ]] || {
        echo 'DB_USERNAME and DB_PASSWORD must be explicitly configured.' >&2
        exit 1
    }
}

require_explicit_external_redis() {
    [[ "${REDIS_HOST:-}" == 127.0.0.1 || "${REDIS_HOST:-}" == localhost || "${REDIS_HOST:-}" == ::1 ]] || {
        echo 'REDIS_HOST must be loopback.' >&2
        exit 1
    }
    [[ -n "${REDIS_PORT+x}" && "${REDIS_PORT:-}" =~ ^[1-9][0-9]*$ ]] || {
        echo 'REDIS_PORT must be explicitly configured.' >&2
        exit 1
    }
    [[ -n "${REDIS_DB+x}" && "${REDIS_DB:-}" =~ ^[0-9]+$ ]] || {
        echo 'REDIS_DB must be explicitly configured.' >&2
        exit 1
    }
    [[ -n "${REDIS_CACHE_DB+x}" && "${REDIS_CACHE_DB:-}" =~ ^[0-9]+$ ]] || {
        echo 'REDIS_CACHE_DB must be explicitly configured.' >&2
        exit 1
    }
    [[ "$REDIS_DB" != "$REDIS_CACHE_DB" ]] || {
        echo 'REDIS_DB and REDIS_CACHE_DB must be distinct.' >&2
        exit 1
    }
    [[ -n "${REDIS_USERNAME+x}" && -n "${REDIS_PASSWORD+x}" ]] || {
        echo 'REDIS_USERNAME and REDIS_PASSWORD must be explicitly configured, even when empty.' >&2
        exit 1
    }
    [[ ( -z "$REDIS_USERNAME" && -z "$REDIS_PASSWORD" ) || ( -n "$REDIS_USERNAME" && -n "$REDIS_PASSWORD" ) ]] || {
        echo 'REDIS_USERNAME and REDIS_PASSWORD must both be empty or both be non-empty.' >&2
        exit 1
    }

    export REDIS_URL=''
}

case "$MODE" in
    external | local) ;;
    *)
        usage
        exit 64
        ;;
esac

php -r 'exit(function_exists("pcntl_fork") ? 0 : 1);' || {
    echo 'pcntl_fork is required for blue-green PostgreSQL concurrency tests.' >&2
    exit 1
}

if [[ "$MODE" == local ]]; then
    empty_database_route_overrides
else
    reject_inherited_database_route_overrides
fi

reject_inherited_redis_url

trap cleanup EXIT
configure_test_configuration_cache

if [[ "$MODE" == local ]]; then
    configure_local_postgresql
    configure_local_redis
fi

require_explicit_external_postgresql
require_explicit_external_redis

export APP_ENV=testing
export APP_MAINTENANCE_DRIVER=file
export CACHE_STORE=array
export QUEUE_CONNECTION=sync
export SESSION_DRIVER=array

pest_arguments=(--compact --do-not-cache-result)
if [[ -n "$FILTER" ]]; then
    pest_arguments+=(--filter "$FILTER")
fi
php -d memory_limit=1G vendor/bin/pest "${pest_arguments[@]}" "${BLUE_GREEN_POSTGRESQL_TESTS[@]}"

if [[ -z "$FILTER" ]]; then
    php artisan test --compact tests/Unit/DeploymentConfiguration/ApplicationConfigurationSnapshotTest.php \
        --filter='fences deployment command'
fi
