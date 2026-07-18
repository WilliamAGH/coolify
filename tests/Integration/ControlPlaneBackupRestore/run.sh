#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIRECTORY=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPOSITORY_ROOT=$(cd "$SCRIPT_DIRECTORY/../../.." && pwd)
POSTGRES_IMAGE=docker.io/library/postgres@sha256:3d0f7584ed7d04e27fa050d6683a74746608faf21f202be78460d679cc56461f
WRONG_POSTGRES_IMAGE=docker.io/library/postgres@sha256:09e4f20b14ddb3dfe3a0c825b206032aaf8f28300ba2070c0b60fc1c10c6abc7
REDIS_IMAGE=docker.io/library/redis@sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99
LAB_ID="control-plane-backup-restore-$$"
CANDIDATE_LOOPBACK_PORT=$((18000 + $$ % 1000))
TOOLS_IMAGE="$LAB_ID-tools"
NETWORK="$LAB_ID-network"
RESTORE_NETWORK="$LAB_ID-restore-network"
SOURCE_CONTAINER="$LAB_ID-source"
SOURCE_REDIS_CONTAINER="$LAB_ID-source-redis"
TARGET_CONTAINER="$LAB_ID-target"
CANDIDATE_CONTAINER="$LAB_ID-candidate"
REGISTRY_CONTAINER="$LAB_ID-registry"
SOCKET_CONTAINER="$LAB_ID-ssh-socket"
INVALID_SOCKET_CONTAINER="$LAB_ID-invalid-ssh-socket"
LIVE_CONTAINER="$LAB_ID-live"
REALTIME_CONTAINER="$LAB_ID-realtime"
KILL_CAPTURE_CONTAINER="$LAB_ID-capture-kill"
BACKUP_QUIESCE_BOOT_ID=backup-restore-lab-boot-0123456789
SOURCE_VOLUME="$LAB_ID-source-data"
SOURCE_REDIS_VOLUME="$LAB_ID-source-redis-data"
TARGET_VOLUME="$LAB_ID-target-data"
STATE_VOLUME="$LAB_ID-source-state"
RESTORE_STATE_VOLUME="$LAB_ID-restored-state"
RUNTIME_DIRECTORY=$(mktemp -d "/tmp/${LAB_ID}.XXXXXX")
WRITER_PID=
CAPTURE_PID=
NEGATIVE_COUNT=0
declare -a QUIESCE_DOCKER_OPTIONS=()
declare -a LAB_CONTAINERS=("$SOURCE_CONTAINER" "$SOURCE_REDIS_CONTAINER" \
    "$TARGET_CONTAINER" "$CANDIDATE_CONTAINER" \
    "$REGISTRY_CONTAINER" "$LAB_ID-redis-main")
LAB_CONTAINERS+=("$SOCKET_CONTAINER" "$INVALID_SOCKET_CONTAINER" "$LIVE_CONTAINER" \
    "$REALTIME_CONTAINER" "$KILL_CAPTURE_CONTAINER")
declare -a LAB_VOLUMES=("$SOURCE_VOLUME" "$SOURCE_REDIS_VOLUME" \
    "$TARGET_VOLUME" "$STATE_VOLUME" \
    "$RESTORE_STATE_VOLUME" "$LAB_ID-redis-data-main")

cleanup()
{
    local exit_status=$?

    trap - EXIT HUP INT TERM
    if [[ -n $WRITER_PID ]]; then
        kill "$WRITER_PID" >/dev/null 2>&1 || true
        wait "$WRITER_PID" >/dev/null 2>&1 || true
    fi
    if [[ -n $CAPTURE_PID ]]; then
        kill "$CAPTURE_PID" >/dev/null 2>&1 || true
        wait "$CAPTURE_PID" >/dev/null 2>&1 || true
    fi
    docker rm -f "${LAB_CONTAINERS[@]}" >/dev/null 2>&1 || true
    for resource in $(docker volume ls --quiet \
        --filter label=coolify.control-plane.backup-restore.resource=true \
        --filter label=coolify.control-plane.backup-restore.operation=backup-restore-lab); do
        docker volume rm "$resource" >/dev/null 2>&1 || true
    done
    for resource in $(docker network ls --quiet \
        --filter label=coolify.control-plane.backup-restore.resource=true \
        --filter label=coolify.control-plane.backup-restore.operation=backup-restore-lab); do
        docker network rm "$resource" >/dev/null 2>&1 || true
    done
    docker volume rm "${LAB_VOLUMES[@]}" >/dev/null 2>&1 || true
    docker network rm "$NETWORK" >/dev/null 2>&1 || true
    docker network rm "$RESTORE_NETWORK" >/dev/null 2>&1 || true
    docker image rm "$TOOLS_IMAGE" >/dev/null 2>&1 || true
    [[ -z ${CANDIDATE_IMAGE_TAG:-} ]] || docker image rm "$CANDIDATE_IMAGE_TAG" >/dev/null 2>&1 || true
    [[ -z ${CANDIDATE_IMAGE_ID:-} ]] || docker image rm "$CANDIDATE_IMAGE_ID" >/dev/null 2>&1 || true
    if [[ $exit_status != 0 ]]; then
        for failure_log in capture.log power-loss-capture.log; do
            if [[ -f $RUNTIME_DIRECTORY/$failure_log ]]; then
                printf '%s\n' "--- $failure_log (tail) ---" >&2
                tail -80 "$RUNTIME_DIRECTORY/$failure_log" >&2
            fi
        done
    fi
    rm -rf "$RUNTIME_DIRECTORY"
    exit "$exit_status"
}
trap cleanup EXIT HUP INT TERM

wait_for_postgres()
{
    local container=$1

    for _ in $(seq 1 60); do
        if docker exec "$container" pg_isready --username postgres --dbname postgres \
            >/dev/null 2>&1; then
            return
        fi
        sleep 1
    done
    printf 'PostgreSQL did not become ready: %s\n' "$container" >&2
    exit 1
}

wait_for_source_fixture()
{
    local observed

    for _ in $(seq 1 60); do
        observed=$(docker exec "$SOURCE_CONTAINER" psql --no-psqlrc --tuples-only --no-align \
            --quiet --username postgres --dbname coolify \
            --command "SELECT to_regclass('live_write_probe') IS NOT NULL" 2>/dev/null \
            | tr -d '[:space:]' || true)
        [[ $observed == t ]] && return
        sleep 1
    done
    printf '%s\n' 'source fixture did not finish initialization' >&2
    exit 1
}

wait_for_redis()
{
    local container=$1

    for _ in $(seq 1 60); do
        if docker exec "$container" sh -ec \
            'REDISCLI_AUTH=$REDIS_PASSWORD redis-cli --no-auth-warning ping' \
            >/dev/null 2>&1; then
            return
        fi
        sleep 1
    done
    printf 'Redis did not become ready: %s\n' "$container" >&2
    exit 1
}

create_lab_network()
{
    local name=$1 mode=${2:-routable} offset candidate second third subnet
    local -a options=()

    [[ $mode != internal ]] || options+=(--internal)
    for offset in $(seq 0 4095); do
        candidate=$((($$ + offset) % 4096))
        second=$((240 + candidate / 256))
        third=$((candidate % 256))
        subnet="10.${second}.${third}.0/28"
        if docker network create "${options[@]}" --subnet "$subnet" "$name" \
            >/dev/null 2>&1; then
            return
        fi
    done
    printf 'No collision-free lab subnet was available: %s\n' "$name" >&2
    exit 1
}

manifest_value()
{
    awk -F= -v key="$2" '$1 == key { print substr($0, length($1) + 2) }' "$1"
}

run_tools()
{
    local entrypoint=$1
    shift
    local -a docker_options=()

    while [[ ${1:-} != -- ]]; do
        docker_options+=("$1")
        shift
    done
    shift
    docker run --rm --volume /var/run/docker.sock:/var/run/docker.sock \
        --tmpfs /plaintext:rw,noexec,nosuid,nodev,mode=0700,uid=0,gid=0 \
        --volume "$STATE_VOLUME:/source-state" \
        --volume "$RESTORE_STATE_VOLUME:/restore-state-volume" \
        --volume "$RUNTIME_DIRECTORY:/lab" \
        --volume "$RUNTIME_DIRECTORY:$RUNTIME_DIRECTORY" \
        "${docker_options[@]}" --entrypoint "$entrypoint" "$TOOLS_IMAGE" "$@"
}

expect_failure()
{
    local test_name=$1
    shift

    if "$@" >/dev/null 2>&1; then
        printf 'negative test unexpectedly passed: %s\n' "$test_name" >&2
        exit 1
    fi
    NEGATIVE_COUNT=$((NEGATIVE_COUNT + 1))
    printf 'ControlPlaneBackupRestore negative %s: PASS\n' "$test_name"
}

capture_attempt()
{
    local attempt_operation=$1 candidate_reference=$2 attempt_state_root=$3 attempt_output_root=$4
    local drift_path=${5:-}
    local -a docker_options=("${QUIESCE_DOCKER_OPTIONS[@]}" \
        --hostname source-host-lab --env GNUPGHOME=/lab/source-gnupg)

    if [[ -n $drift_path ]]; then
        docker_options+=(--env CONTROL_PLANE_BACKUP_RESTORE_TEST_MODE=1)
        docker_options+=(--env "CONTROL_PLANE_BACKUP_RESTORE_TEST_STATE_DRIFT_PATH=$drift_path")
    fi
    run_tools /opt/control-plane-backup/capture.sh \
        "${docker_options[@]}" -- \
        --operation-id "$attempt_operation" --candidate-image-digest "$candidate_reference" \
        --expected-source-host-identity source-host-lab \
        --database-container "$SOURCE_CONTAINER" --database-user postgres --database-name coolify \
        --redis-container "$SOURCE_REDIS_CONTAINER" --redis-port 6379 \
        --expected-redis-endpoint "$SOURCE_REDIS_CONTAINER:6379" \
        --expected-redis-image-digest "$REDIS_IMAGE" \
        --state-root "$attempt_state_root" --state-path applications \
        --state-path databases --state-path services --state-path ssh \
        --output-root "$attempt_output_root" \
        --recipient-fingerprint "$RECIPIENT_FINGERPRINT" \
        --plaintext-tmpfs-root /plaintext \
        --quiesce-operator /opt/control-plane-blue-green/control-plane-blue-green.sh \
        --expected-quiesce-operator-sha256 "$QUIESCE_OPERATOR_SHA256" \
        --quiesce-lease-seconds 1200 \
        --restore-tool /opt/control-plane-backup/restore-attest.sh
}

capture_linux_volume_attempt()
{
    local attempt_operation=$1 volume=$2

    run_tools /opt/control-plane-backup/capture.sh \
        "${QUIESCE_DOCKER_OPTIONS[@]}" \
        --hostname source-host-lab --env GNUPGHOME=/lab/source-gnupg \
        --volume "$volume:/linux-state" -- \
        --operation-id "$attempt_operation" \
        --candidate-image-digest "$CANDIDATE_IMAGE_DIGEST" \
        --expected-source-host-identity source-host-lab \
        --database-container "$SOURCE_CONTAINER" --database-user postgres \
        --database-name coolify --redis-container "$SOURCE_REDIS_CONTAINER" --redis-port 6379 \
        --expected-redis-endpoint "$SOURCE_REDIS_CONTAINER:6379" \
        --expected-redis-image-digest "$REDIS_IMAGE" --state-root /linux-state \
        --state-path applications --state-path databases \
        --state-path services --state-path ssh --output-root /lab/negative-captures \
        --recipient-fingerprint "$RECIPIENT_FINGERPRINT" \
        --plaintext-tmpfs-root /plaintext \
        --quiesce-operator /opt/control-plane-blue-green/control-plane-blue-green.sh \
        --expected-quiesce-operator-sha256 "$QUIESCE_OPERATOR_SHA256" \
        --quiesce-lease-seconds 1200 \
        --restore-tool /opt/control-plane-backup/restore-attest.sh
}

verify_attempt()
{
    local dump_path=$1 state_path=$2 manifest_path=$3 attestation_path=$4 attestation_sha=$5

    run_tools /opt/control-plane-backup/restore-attest.sh \
        --hostname restore-host-lab -- \
        verify-attestation --database-dump "$dump_path" \
        --redis-snapshot "/lab/captures/$OPERATION_ID/redis.rdb.gpg" \
        --state-archive "$state_path" \
        --capture-manifest "$manifest_path" "${CANONICAL_BOUND_ARGUMENTS[@]}" \
        --attestation "$attestation_path" --expected-attestation-sha256 "$attestation_sha"
}

verify_redis_attempt()
{
    local redis_path=$1

    run_tools /opt/control-plane-backup/restore-attest.sh \
        --hostname restore-host-lab -- \
        verify-attestation \
        --database-dump "/lab/captures/$OPERATION_ID/database.pgdump.gpg" \
        --redis-snapshot "$redis_path" \
        --state-archive "/lab/captures/$OPERATION_ID/control-plane-state.tar.gpg" \
        --capture-manifest "/lab/captures/$OPERATION_ID/capture.manifest" \
        "${CANONICAL_BOUND_ARGUMENTS[@]}" \
        --attestation /lab/attestations/restore.attestation \
        --expected-attestation-sha256 "$ATTESTATION_SHA256"
}

verify_wrong_redis_identity()
{
    local index
    local -a wrong_arguments=()

    for ((index = 0; index < ${#CANONICAL_BOUND_ARGUMENTS[@]}; index++)); do
        if [[ ${CANONICAL_BOUND_ARGUMENTS[index]} == --expected-source-redis-container-id ]]; then
            wrong_arguments+=(--expected-source-redis-container-id \
                ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff)
            index=$((index + 1))
        else
            wrong_arguments+=("${CANONICAL_BOUND_ARGUMENTS[index]}")
        fi
    done
    run_tools /opt/control-plane-backup/restore-attest.sh --hostname restore-host-lab -- \
        verify-attestation --database-dump "/lab/captures/$OPERATION_ID/database.pgdump.gpg" \
        --redis-snapshot "/lab/captures/$OPERATION_ID/redis.rdb.gpg" \
        --state-archive "/lab/captures/$OPERATION_ID/control-plane-state.tar.gpg" \
        --capture-manifest "/lab/captures/$OPERATION_ID/capture.manifest" \
        "${wrong_arguments[@]}" --attestation /lab/attestations/restore.attestation \
        --expected-attestation-sha256 "$ATTESTATION_SHA256"
}

start_fresh_target()
{
    local suffix=$1 image=$2

    STARTED_TARGET="$LAB_ID-target-$suffix"
    STARTED_VOLUME="$LAB_ID-target-data-$suffix"
    LAB_CONTAINERS+=("$STARTED_TARGET")
    LAB_VOLUMES+=("$STARTED_VOLUME")
    docker volume create "$STARTED_VOLUME" >/dev/null
    docker run --detach --name "$STARTED_TARGET" --network "$RESTORE_NETWORK" \
        --label coolify.control-plane.backup-restore.disposable=true \
        --label "coolify.control-plane.backup-restore.operation=$OPERATION_ID" \
        --env POSTGRES_HOST_AUTH_METHOD=trust --env POSTGRES_DB=postgres \
        --volume "$STARTED_VOLUME:/var/lib/postgresql/data" "$image" >/dev/null
    wait_for_postgres "$STARTED_TARGET"
}

restore_attempt()
{
    local suffix=$1 target_container=$2 candidate_container=$3 fault_name=${4:-}
    local fault_value=${5:-1}
    local plaintext_root=${6:-/plaintext}
    local restored_state_volume=${7:-}
    local restored_state_root="/lab/restored-state-$suffix"
    local -a docker_options=(
        --hostname restore-host-lab --env GNUPGHOME=/lab/offhost-gnupg
        --env "LAB_NETWORK=$RESTORE_NETWORK" \
        --env "LAB_CANDIDATE_CONTAINER=$candidate_container"
        --env LAB_CANDIDATE_ENTRYPOINT=/opt/lab-hooks/candidate-entrypoint.sh
        --env "LAB_HOST_RUNTIME_DIRECTORY=$RUNTIME_DIRECTORY"
        --env "LAB_DIRECT_PROBE_TOKEN_FILE=$RUNTIME_DIRECTORY/probe-token"
        --env "LAB_APPLIED_ACK_FILE=$RUNTIME_DIRECTORY/probe-ack"
    )

    if [[ -n $fault_name ]]; then
        docker_options+=(--env "$fault_name=$fault_value")
    fi
    if [[ -n $restored_state_volume ]]; then
        restored_state_root=/restored-state/state
        docker_options+=(--volume "$restored_state_volume:/restored-state")
        docker_options+=(--env "LAB_RESTORED_STATE_VOLUME=$restored_state_volume")
        run_tools sh --volume "$restored_state_volume:/restored-state" -- -ec \
            '[ -d /restored-state/state ] || mkdir -m 700 /restored-state/state'
    fi
    LAB_CONTAINERS+=("$candidate_container" "$LAB_ID-redis-$suffix")
    LAB_VOLUMES+=("$LAB_ID-redis-data-$suffix")
    if [[ -z $restored_state_volume && ! -d $RUNTIME_DIRECTORY/restored-state-$suffix ]]; then
        run_tools mkdir -- --mode=700 "/lab/restored-state-$suffix"
    fi
    run_tools /opt/control-plane-backup/restore-attest.sh \
        "${docker_options[@]}" -- restore "${COMMON_ARGUMENTS[@]}" \
        --restore-target-container "$target_container" --restore-database-user postgres \
        --restore-redis-container "$LAB_ID-redis-$suffix" \
        --restore-redis-volume "$LAB_ID-redis-data-$suffix" \
        --restore-redis-network "$LAB_ID-redis-network-$suffix" \
        --restore-redis-password-file /lab/redis-password \
        --operator-rehearse-migrations-hook /opt/lab-hooks/rehearsal-hook.sh \
        --candidate-boot-hook /opt/lab-hooks/candidate-boot-hook.sh \
        --candidate-api-probe-hook /opt/lab-hooks/candidate-api-probe-hook.sh \
        --candidate-runtime-container "$candidate_container" \
        --restore-state-root "$restored_state_root" \
        --plaintext-tmpfs-root "$plaintext_root" --stale-work-max-age-seconds 3600 \
        --state-proof-tool /opt/control-plane-backup/state-proof.sh \
        --attestation-output "/lab/attestations/failure-$suffix.attestation"
}

canonical_restore_attempt()
{
    local suffix=$1 target_container=$2 candidate_container=$3 expected_batch=$4
    local runtime_redis_host=${5:-$LAB_ID-redis-$suffix}
    local runtime_database_host=${6:-$target_container}
    local state_volume="$LAB_ID-restored-state-$suffix"

    LAB_VOLUMES+=("$state_volume")
    docker volume create \
        --label coolify.control-plane.backup-restore.restored-state=true \
        --label "coolify.control-plane.backup-restore.operation=$OPERATION_ID" \
        "$state_volume" >/dev/null
    # shellcheck disable=SC2016
    run_tools sh --env "TARGET_CONTAINER=$runtime_database_host" \
        --env "REDIS_CONTAINER=$runtime_redis_host" \
        --env "RUNTIME_ENV=/lab/candidate-$suffix.env" -- -ec \
        'printf "DB_HOST=%s\nPGHOST=%s\nPGUSER=postgres\nPGDATABASE=coolify\nREDIS_HOST=%s\nREDIS_PASSWORD=redis-restore-password\n" \
            "$TARGET_CONTAINER" "$TARGET_CONTAINER" "$REDIS_CONTAINER" \
            > "$RUNTIME_ENV"; chmod 600 "$RUNTIME_ENV"'
    run_tools mkdir --volume "$state_volume:/canonical-state" -- --mode=700 /canonical-state/state
    LAB_CONTAINERS+=("$candidate_container" "$LAB_ID-redis-$suffix")
    LAB_VOLUMES+=("$LAB_ID-redis-data-$suffix")
    run_tools /opt/control-plane-backup/restore-attest.sh \
        --hostname restore-host-lab --env GNUPGHOME=/lab/offhost-gnupg \
        --env "LAB_NETWORK=$RESTORE_NETWORK" \
        --volume "$state_volume:/canonical-state" \
        --env "CONTROL_PLANE_CANDIDATE_COMPOSE_FILE=/opt/control-plane-blue-green/compose.yaml" \
        --env "CONTROL_PLANE_EXPECTED_CANDIDATE_COMPOSE_SHA256=$CANONICAL_COMPOSE_SHA256" \
        --env "CONTROL_PLANE_RUNTIME_ENV_FILE=$RUNTIME_DIRECTORY/candidate-$suffix.env" \
        --env "CONTROL_PLANE_NETWORK=$LAB_ID-candidate-network-$suffix" \
        --env "CONTROL_PLANE_LIVE_NETWORK=$NETWORK" \
        --env "CONTROL_PLANE_GREEN_CONTAINER=$candidate_container" \
        --env "CONTROL_PLANE_GREEN_STATE_VOLUME=$LAB_ID-candidate-state-$suffix" \
        --env "CONTROL_PLANE_RESTORED_STATE_DOCKER_VOLUME=$state_volume" \
        --env "CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE=$RUNTIME_DIRECTORY/probe-token" \
        --env "CONTROL_PLANE_GREEN_APPLIED_ACK_FILE=$RUNTIME_DIRECTORY/probe-ack" \
        --env CONTROL_PLANE_WRITER_EPOCH=writer-epoch-0001 \
        --env CONTROL_PLANE_GREEN_WEB_EPOCH=green-web-epoch-01 \
        --env CONTROL_PLANE_DIRECT_PROBE_PATH=/api/control-plane/probe \
        --env CONTROL_PLANE_BACKEND_PORT=8080 \
        --env "CONTROL_PLANE_GREEN_LOOPBACK_PORT=$((CANDIDATE_LOOPBACK_PORT + 1))" \
        --env "CONTROL_PLANE_EXPECTED_MIGRATION_BATCH=$expected_batch" -- \
        restore "${CANONICAL_COMMON_ARGUMENTS[@]}" \
        --restore-target-container "$target_container" --restore-database-user postgres \
        --restore-redis-container "$LAB_ID-redis-$suffix" \
        --restore-redis-volume "$LAB_ID-redis-data-$suffix" \
        --restore-redis-network "$LAB_ID-redis-network-$suffix" \
        --restore-redis-password-file /lab/redis-password \
        --operator-rehearse-migrations-hook /opt/lab-hooks/rehearsal-hook.sh \
        --candidate-boot-hook /opt/control-plane-backup/candidate-boot-hook.sh \
        --candidate-api-probe-hook /opt/control-plane-backup/candidate-api-probe-hook.sh \
        --candidate-runtime-container "$candidate_container" \
        --restore-state-root /canonical-state/state --plaintext-tmpfs-root /plaintext \
        --stale-work-max-age-seconds 3600 \
        --state-proof-tool "$RUNTIME_DIRECTORY/state-proof.sh" \
        --attestation-output "/lab/attestations/failure-$suffix.attestation"
}

assert_failed_restore_cleanup()
{
    local suffix=$1 target_container=$2 candidate_container=$3 database_count

    if docker inspect "$candidate_container" >/dev/null 2>&1; then
        printf '%s\n' "failed restore leaked candidate: $candidate_container" >&2
        exit 1
    fi
    database_count=$(docker exec "$target_container" psql --no-psqlrc --tuples-only --no-align \
        --quiet --username postgres --dbname postgres --command \
        "SELECT count(*) FROM pg_database WHERE datname = 'coolify'" | tr -d '[:space:]')
    [[ $database_count == 0 \
        && -z $(find "$RUNTIME_DIRECTORY/restored-state-$suffix" -mindepth 1 -print -quit) ]] \
        || { printf '%s\n' "failed restore leaked database or state: $suffix" >&2; exit 1; }
}

chmod 700 "$RUNTIME_DIRECTORY"
docker pull "$POSTGRES_IMAGE" >/dev/null
docker pull "$WRONG_POSTGRES_IMAGE" >/dev/null
docker pull "$REDIS_IMAGE" >/dev/null
POSTGRES_PLATFORM=$(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$POSTGRES_IMAGE")
QUIESCE_OPERATOR_SHA256=$(sha256sum \
    "$REPOSITORY_ROOT/docker/control-plane-blue-green/control-plane-blue-green.sh" | awk '{print $1}')
docker build --quiet --tag "$TOOLS_IMAGE" --file "$SCRIPT_DIRECTORY/Dockerfile.tools" \
    "$REPOSITORY_ROOT" >/dev/null
docker run --detach --name "$REGISTRY_CONTAINER" --publish 127.0.0.1::5000 \
    registry@sha256:a3d8aaa63ed8681a604f1dea0aa03f100d5895b6a58ace528858a7b332415373 \
    >/dev/null
REGISTRY_PORT=$(docker port "$REGISTRY_CONTAINER" 5000/tcp | awk -F: '{print $NF}')
CANDIDATE_IMAGE_TAG="localhost:$REGISTRY_PORT/control-plane-candidate:lab"
docker build --quiet --tag "$CANDIDATE_IMAGE_TAG" \
    --file "$SCRIPT_DIRECTORY/Dockerfile.candidate" "$REPOSITORY_ROOT" >/dev/null
CANDIDATE_IMAGE_ID=$(docker image inspect --format '{{.Id}}' "$CANDIDATE_IMAGE_TAG")
docker push "$CANDIDATE_IMAGE_TAG" >/dev/null
CANDIDATE_IMAGE_DIGEST=$(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' \
    "$CANDIDATE_IMAGE_TAG" | awk -v repository="${CANDIDATE_IMAGE_TAG%:lab}@" \
    'index($0, repository) == 1 { print; exit }')
[[ $CANDIDATE_IMAGE_DIGEST =~ @sha256:[0-9a-f]{64}$ ]] \
    || { printf '%s\n' 'candidate image digest was not resolved from local registry' >&2; exit 1; }
create_lab_network "$NETWORK"
create_lab_network "$RESTORE_NETWORK" internal
docker volume create "$SOURCE_VOLUME" >/dev/null
docker volume create "$SOURCE_REDIS_VOLUME" >/dev/null
docker volume create "$TARGET_VOLUME" >/dev/null
docker volume create "$STATE_VOLUME" >/dev/null
docker volume create --label coolify.control-plane.backup-restore.restored-state=true \
    --label coolify.control-plane.backup-restore.operation=backup-restore-lab \
    "$RESTORE_STATE_VOLUME" >/dev/null

# shellcheck disable=SC2016
run_tools bash --env "TARGET_CONTAINER=$TARGET_CONTAINER" \
    --env "RESTORE_REDIS_HOST=$LAB_ID-redis-main" -- -c '
    set -Eeuo pipefail
    mkdir -m 700 /lab/captures /lab/attestations /lab/source-gnupg /lab/offhost-gnupg
    cp -a /opt/lab-fixtures/control-plane-state/. /source-state/
    find /source-state -mindepth 1 -type d -exec chown 9999:9999 {} + -exec chmod 700 {} +
    find /source-state -type f -exec chown 9999:9999 {} + -exec chmod 600 {} +
    for selected in ssh applications databases services; do chown 9999:0 "/source-state/$selected"; done
    mkdir -m 700 /source-state/services-legacy
    chown 9999:9999 /source-state/services-legacy
    chmod 644 /source-state/ssh/config
    gpg --homedir /lab/offhost-gnupg --batch --pinentry-mode loopback --passphrase "" \
        --quick-generate-key "Control Plane Backup Restore Lab" rsa2048 encr 0 >/dev/null 2>&1
    fingerprint=$(gpg --homedir /lab/offhost-gnupg --batch --with-colons --fingerprint 2>/dev/null \
        | awk -F: '\''$1 == "fpr" { print toupper($10); exit }'\'')
    gpg --homedir /lab/offhost-gnupg --batch --export "$fingerprint" > /lab/recipient-public.gpg
    gpg --homedir /lab/source-gnupg --batch --import /lab/recipient-public.gpg >/dev/null 2>&1
    printf "%s\n" "$fingerprint" > /lab/recipient-fingerprint
    chmod 700 /lab/captures /lab/attestations /lab/source-gnupg /lab/offhost-gnupg
    chmod 600 /lab/recipient-public.gpg /lab/recipient-fingerprint
    cp /opt/control-plane-backup/state-proof.sh /lab/state-proof.sh
    printf "DB_HOST=%s\nPGHOST=%s\nPGUSER=postgres\nPGDATABASE=coolify\nREDIS_HOST=%s\nREDIS_PASSWORD=%s\n" \
        "$TARGET_CONTAINER" "$TARGET_CONTAINER" "$RESTORE_REDIS_HOST" \
        "redis-restore-password" > /lab/candidate.env
    printf "%s\n" "redis-restore-password" > /lab/redis-password
    printf "%s" 0123456789abcdef0123456789abcdef > /lab/probe-token
    printf "%s" fedcba9876543210fedcba9876543210 > /lab/probe-ack
    chmod 755 /lab/state-proof.sh
    chmod 600 /lab/candidate.env /lab/redis-password /lab/probe-token /lab/probe-ack

    release_id=backup-restore-lab-release-0001
    release_manifest=/lab/release.manifest
    printf "version|1\nrelease|%s\n" "$release_id" > "$release_manifest"
    while IFS="|" read -r asset_role asset_path; do
        asset_sha256=$(sha256sum "$asset_path")
        asset_sha256=${asset_sha256%% *}
        printf "asset|%s|%s|%s|%s|%s|%s\n" \
            "$asset_role" "$asset_path" "$asset_sha256" \
            "$(stat -c %u "$asset_path")" "$(stat -c %g "$asset_path")" \
            "$(stat -c %a "$asset_path")" >> "$release_manifest"
    done <<EOF
operator|/opt/control-plane-blue-green/control-plane-blue-green.sh
operator-compose|/opt/control-plane-blue-green/compose.yaml
rehearsal-compose|/opt/control-plane-blue-green/compose.rehearsal.yaml
ingress-controller|/opt/control-plane-blue-green/controllers/traefik-ingress.sh
backup-attestation-verifier|/opt/control-plane-blue-green/backup/restore-attest.sh
backup-quiesce-controller|/opt/control-plane-blue-green/backup-quiesce/control-plane-backup-quiesce.sh
backup-quiesce-service-unit|/opt/control-plane-blue-green/backup-quiesce/control-plane-backup-quiesce-watchdog.service
backup-quiesce-timer-unit|/opt/control-plane-blue-green/backup-quiesce/control-plane-backup-quiesce-watchdog.timer
runtime-fence-provisioner|/opt/control-plane-blue-green/controllers/provision-runtime-attestation-ssh-fence.sh
runtime-fence-controller|/opt/control-plane-blue-green/controllers/runtime-attestation-ssh-fence.sh
runtime-fence-controlmaster-reaper|/opt/control-plane-blue-green/controllers/self-ssh-controlmaster-reaper.sh
runtime-fence-provider-probe|/opt/control-plane-blue-green/controllers/traefik-docker-provider-freshness-probe.sh
runtime-fence-queue-probe|/opt/control-plane-blue-green/controllers/proxy-queue-zero-probe.sh
runtime-fence-terminal-probe|/opt/control-plane-blue-green/controllers/control-plane-terminal-state-probe.sh
runtime-fence-service-unit|/opt/control-plane-blue-green/controllers/coolify-runtime-attestation-ssh-fence.service
runtime-fence-watchdog-unit|/opt/control-plane-blue-green/controllers/coolify-runtime-attestation-ssh-fence-watchdog.service
EOF
    chmod 600 "$release_manifest"
    release_manifest_sha256=$(sha256sum "$release_manifest")
    printf "%s\n" "${release_manifest_sha256%% *}" > /lab/release-manifest.sha256
'
run_tools mkdir -- --mode=700 /source-state/ssh/mux
run_tools chown -- 9999:9999 /source-state/ssh/mux
docker run --detach --name "$SOCKET_CONTAINER" --user 9999:9999 \
    --volume "$STATE_VOLUME:/source-state" --entrypoint socat "$TOOLS_IMAGE" \
    UNIX-LISTEN:/source-state/ssh/mux/mux_abcdefghijklmnopqrstuvwx,fork,mode=600 \
    EXEC:/bin/true >/dev/null

docker run --detach --name "$SOURCE_CONTAINER" --network "$NETWORK" \
    --env POSTGRES_HOST_AUTH_METHOD=trust --env POSTGRES_DB=coolify \
    --volume "$SOURCE_VOLUME:/var/lib/postgresql/data" \
    --volume "$SCRIPT_DIRECTORY/source-init.sql:/docker-entrypoint-initdb.d/source-init.sql:ro" \
    "$POSTGRES_IMAGE" >/dev/null
wait_for_postgres "$SOURCE_CONTAINER"
wait_for_source_fixture
docker run --detach --name "$SOURCE_REDIS_CONTAINER" --network "$NETWORK" \
    --env REDIS_PASSWORD=redis-source-password --volume "$SOURCE_REDIS_VOLUME:/data" \
    "$REDIS_IMAGE" redis-server --save 20 1 --requirepass redis-source-password >/dev/null
wait_for_redis "$SOURCE_REDIS_CONTAINER"
docker exec "$SOURCE_REDIS_CONTAINER" sh -ec '
    export REDISCLI_AUTH=$REDIS_PASSWORD
    redis-cli --no-auth-warning set cache:control-plane captured-cache >/dev/null
    redis-cli --no-auth-warning rpush queues:deploy "captured-job-1" "captured-job-2" >/dev/null
    redis-cli --no-auth-warning zadd queues:deploy:delayed 1700000000 captured-delayed-job >/dev/null
'

docker run --detach --name "$LIVE_CONTAINER" --user 9999:9999 \
    --label com.docker.compose.project=backuprestorelab \
    --label com.docker.compose.service=controlplaneblue \
    --volume "$STATE_VOLUME:/lab-state" --entrypoint sh "$TOOLS_IMAGE" -ec \
    '/usr/local/bin/control-plane-lab-service initialize; exec sleep infinity' >/dev/null
docker run --detach --name "$REALTIME_CONTAINER" --entrypoint sleep "$TOOLS_IMAGE" infinity \
    >/dev/null

RELEASE_MANIFEST_SHA256=$(tr -d '[:space:]' < "$RUNTIME_DIRECTORY/release-manifest.sha256")

QUIESCE_DOCKER_OPTIONS=(
    --env CONTROL_PLANE_TEST_MODE=1
    --env CONTROL_PLANE_OPERATOR_TARGET=lab
    --env CONTROL_PLANE_TEST_GLOBAL_TRANSACTION_LOCK_FILE=/lab/control-plane-blue-green.lock
    --env CONTROL_PLANE_RELEASE_MANIFEST_FILE=/lab/release.manifest
    --env "CONTROL_PLANE_RELEASE_MANIFEST_SHA256=$RELEASE_MANIFEST_SHA256"
    --env CONTROL_PLANE_RELEASE_ID=backup-restore-lab-release-0001
    --env "CONTROL_PLANE_TEST_BACKUP_QUIESCE_BOOT_ID=$BACKUP_QUIESCE_BOOT_ID"
    --env "CONTROL_PLANE_BACKUP_LIVE_CONTAINER=$LIVE_CONTAINER"
    --env "CONTROL_PLANE_BACKUP_REALTIME_CONTAINER=$REALTIME_CONTAINER"
    --env "CONTROL_PLANE_DATABASE_CONTAINER=$SOURCE_CONTAINER"
    --env CONTROL_PLANE_DATABASE_NAME=coolify
    --env CONTROL_PLANE_DATABASE_ADMIN_USER=postgres
    --env CONTROL_PLANE_BACKUP_APPLICATION_DATABASE_ROLE=coolify_app
    --env CONTROL_PLANE_BACKUP_QUIESCE_STATE_DIR=/lab/backup-quiesce-state
    --env CONTROL_PLANE_BACKUP_QUIESCE_DRAIN_TIMEOUT_SECONDS=3
    --env CONTROL_PLANE_BACKUP_QUIESCE_PROBE_TIMEOUT_SECONDS=3
    --env CONTROL_PLANE_S6_WAIT_MILLISECONDS=1000
    --env CONTROL_PLANE_BACKUP_QUIESCE_REALTIME_STOP_SECONDS=1
    --env CONTROL_PLANE_BACKUP_QUIESCE_MINIMUM_CAPTURE_SECONDS=2
    --env CONTROL_PLANE_TEST_BACKUP_QUIESCE_WATCHDOG_PID_FILE=/lab/watchdog.pid
)

RECIPIENT_FINGERPRINT=$(tr -d '[:space:]' < "$RUNTIME_DIRECTORY/recipient-fingerprint")
OPERATION_ID=backup-restore-lab
CANDIDATE_RESOURCE_HASH=$(printf '%s' "$OPERATION_ID" \
    | sha256sum | awk '{print substr($1, 1, 20)}')

(
    write_count=0
    while [[ ! -f $RUNTIME_DIRECTORY/backup-quiesce-writer.stop ]]; do
        if [[ $(docker exec "$LIVE_CONTAINER" \
            /usr/local/bin/control-plane-lab-service maintenance-state) == absent:none ]]; then
            mutation_id=$(docker exec "$SOURCE_CONTAINER" psql --no-psqlrc --tuples-only \
                --no-align --quiet --set ON_ERROR_STOP=1 --username coolify_app \
                --dbname coolify --command \
                "INSERT INTO scheduled_task_executions (status) VALUES ('running') RETURNING id" \
                2>/dev/null | tr -d '[:space:]') || mutation_id=
            if [[ $mutation_id =~ ^[1-9][0-9]*$ ]] \
                && docker exec "$SOURCE_CONTAINER" psql --no-psqlrc --quiet \
                    --set ON_ERROR_STOP=1 --username coolify_app --dbname coolify \
                    --command \
                    "INSERT INTO live_write_probe (marker) VALUES ('live-write-${write_count}')" \
                    >/dev/null 2>&1; then
                if [[ $write_count == 1 ]]; then
                    : > "$RUNTIME_DIRECTORY/writer-interstep-reached"
                    while [[ ! -f $RUNTIME_DIRECTORY/writer-interstep-release ]]; do
                        sleep 0.05
                    done
                fi
                docker exec "$LIVE_CONTAINER" sh -c \
                    'printf "%s\n" "$1" >> /lab-state/applications/backup-quiesce-writer-state' \
                    sh "live-write-${write_count}"
                docker exec "$SOURCE_CONTAINER" psql --no-psqlrc --quiet \
                    --set ON_ERROR_STOP=1 --username coolify_app --dbname coolify \
                    --command \
                    "UPDATE scheduled_task_executions SET status = 'completed' WHERE id = ${mutation_id}" \
                    >/dev/null
                write_count=$((write_count + 1))
            fi
        fi
        sleep 0.05
    done
    printf '%s\n' "$write_count" > "$RUNTIME_DIRECTORY/concurrent-write-count"
) &
WRITER_PID=$!
for _ in $(seq 1 60); do
    docker exec "$LIVE_CONTAINER" test -s \
        /lab-state/applications/backup-quiesce-writer-state 2>/dev/null && break
    sleep 0.25
done
docker exec "$LIVE_CONTAINER" test -s /lab-state/applications/backup-quiesce-writer-state \
    || { printf '%s\n' 'cross-domain source writer did not initialize' >&2; exit 1; }
for _ in $(seq 1 60); do
    [[ -f $RUNTIME_DIRECTORY/writer-interstep-reached ]] && break
    sleep 0.25
done
[[ -f $RUNTIME_DIRECTORY/writer-interstep-reached ]] \
    || { printf '%s\n' 'cross-domain writer did not reach the injected inter-step race' >&2; exit 1; }

run_tools /opt/control-plane-backup/capture.sh \
    "${QUIESCE_DOCKER_OPTIONS[@]}" \
    --hostname source-host-lab --env GNUPGHOME=/lab/source-gnupg -- \
    --operation-id "$OPERATION_ID" \
    --candidate-image-digest "$CANDIDATE_IMAGE_DIGEST" \
    --expected-source-host-identity source-host-lab \
    --database-container "$SOURCE_CONTAINER" --database-user postgres --database-name coolify \
    --redis-container "$SOURCE_REDIS_CONTAINER" --redis-port 6379 \
    --expected-redis-endpoint "$SOURCE_REDIS_CONTAINER:6379" \
    --expected-redis-image-digest "$REDIS_IMAGE" \
    --state-root /source-state --state-path applications --state-path databases \
    --state-path services --state-path ssh \
    --output-root /lab/captures --recipient-fingerprint "$RECIPIENT_FINGERPRINT" \
    --plaintext-tmpfs-root /plaintext \
    --quiesce-operator /opt/control-plane-blue-green/control-plane-blue-green.sh \
    --expected-quiesce-operator-sha256 "$QUIESCE_OPERATOR_SHA256" \
    --quiesce-lease-seconds 1200 \
    --restore-tool /opt/control-plane-backup/restore-attest.sh \
    > "$RUNTIME_DIRECTORY/capture.log" 2>&1 &
CAPTURE_PID=$!
QUIESCE_STATE="$RUNTIME_DIRECTORY/backup-quiesce-state/$OPERATION_ID/state"
for _ in $(seq 1 120); do
    [[ -f $QUIESCE_STATE ]] && grep -Fqx phase=acquiring "$QUIESCE_STATE" && break
    sleep 0.25
done
if [[ ! -f $QUIESCE_STATE ]] || ! grep -Fqx phase=acquiring "$QUIESCE_STATE"; then
    printf '%s\n' 'backup fence crossed an admitted database/filesystem mutation' >&2
    exit 1
fi
[[ $(docker exec "$SOURCE_CONTAINER" psql --no-psqlrc --tuples-only --no-align \
    --quiet --set ON_ERROR_STOP=1 --username postgres --dbname coolify \
    --command "SELECT count(*) FROM scheduled_task_executions WHERE status = 'running'" \
    | tr -d '[:space:]') == 1 ]] \
    || { printf '%s\n' 'injected inter-step mutation was not registered at the fence' >&2; exit 1; }
: > "$RUNTIME_DIRECTORY/writer-interstep-release"
for _ in $(seq 1 120); do
    [[ -f $QUIESCE_STATE ]] && grep -Fqx phase=acquired "$QUIESCE_STATE" && break
    sleep 0.25
done
if [[ ! -f $QUIESCE_STATE ]] || ! grep -Fqx phase=acquired "$QUIESCE_STATE"; then
    printf '%s\n' 'canonical backup fence did not reach acquired phase' >&2
    exit 1
fi
FENCED_DATABASE_COUNT=$(docker exec "$SOURCE_CONTAINER" psql --no-psqlrc --tuples-only \
    --no-align --quiet --username postgres --dbname coolify \
    --command 'SELECT count(*) FROM live_write_probe' | tr -d '[:space:]')
FENCED_STATE_LINES=$(docker exec "$LIVE_CONTAINER" sh -c 'wc -l < "$1"' sh \
    /lab-state/applications/backup-quiesce-writer-state | tr -d '[:space:]')
[[ $FENCED_DATABASE_COUNT =~ ^[1-9][0-9]*$ \
    && $FENCED_DATABASE_COUNT == "$FENCED_STATE_LINES" ]] \
    || { printf 'source database/filesystem generations crossed before capture (database=%s filesystem=%s)\n' \
        "$FENCED_DATABASE_COUNT" "$FENCED_STATE_LINES" >&2; exit 1; }
FENCED_STATE_SHA256=$(docker exec "$LIVE_CONTAINER" sha256sum \
    /lab-state/applications/backup-quiesce-writer-state | awk '{print $1}')
sleep 1
[[ $(docker exec "$SOURCE_CONTAINER" psql --no-psqlrc --tuples-only --no-align --quiet \
    --username postgres --dbname coolify --command 'SELECT count(*) FROM live_write_probe' \
    | tr -d '[:space:]') == "$FENCED_DATABASE_COUNT" \
    && $(docker exec "$LIVE_CONTAINER" sha256sum \
        /lab-state/applications/backup-quiesce-writer-state | awk '{print $1}') \
        == "$FENCED_STATE_SHA256" \
    && $(grep -F phase= "$QUIESCE_STATE") == phase=acquired ]] \
    || { printf '%s\n' 'database/filesystem writer crossed the canonical backup fence' >&2; exit 1; }
wait "$CAPTURE_PID"
CAPTURE_PID=
: > "$RUNTIME_DIRECTORY/backup-quiesce-writer.stop"
wait "$WRITER_PID"
WRITER_PID=
CONCURRENT_WRITE_COUNT=$(tr -d '[:space:]' < "$RUNTIME_DIRECTORY/concurrent-write-count")
[[ $CONCURRENT_WRITE_COUNT =~ ^[1-9][0-9]*$ ]] \
    || { printf '%s\n' 'concurrent source writer did not overlap capture' >&2; exit 1; }

KILL_OPERATION=backup-restore-power-loss
POWER_LOSS_LEASE_SECONDS=80
docker run --detach --name "$KILL_CAPTURE_CONTAINER" --hostname source-host-lab \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --tmpfs /plaintext:rw,noexec,nosuid,nodev,mode=0700,uid=0,gid=0 \
    --volume "$STATE_VOLUME:/source-state" \
    --volume "$RESTORE_STATE_VOLUME:/restore-state-volume" \
    --volume "$RUNTIME_DIRECTORY:/lab" \
    --volume "$RUNTIME_DIRECTORY:$RUNTIME_DIRECTORY" \
    "${QUIESCE_DOCKER_OPTIONS[@]}" --env GNUPGHOME=/lab/source-gnupg \
    --entrypoint sleep "$TOOLS_IMAGE" infinity >/dev/null
docker exec "$KILL_CAPTURE_CONTAINER" /opt/control-plane-backup/capture.sh \
    --operation-id "$KILL_OPERATION" \
    --candidate-image-digest "$CANDIDATE_IMAGE_DIGEST" \
    --expected-source-host-identity source-host-lab \
    --database-container "$SOURCE_CONTAINER" --database-user postgres --database-name coolify \
    --redis-container "$SOURCE_REDIS_CONTAINER" --redis-port 6379 \
    --expected-redis-endpoint "$SOURCE_REDIS_CONTAINER:6379" \
    --expected-redis-image-digest "$REDIS_IMAGE" \
    --state-root /source-state --state-path applications --state-path databases \
    --state-path services --state-path ssh \
    --output-root /lab/captures --recipient-fingerprint "$RECIPIENT_FINGERPRINT" \
    --plaintext-tmpfs-root /plaintext \
    --quiesce-operator /opt/control-plane-blue-green/control-plane-blue-green.sh \
    --expected-quiesce-operator-sha256 "$QUIESCE_OPERATOR_SHA256" \
    --quiesce-lease-seconds "$POWER_LOSS_LEASE_SECONDS" \
    --restore-tool /opt/control-plane-backup/restore-attest.sh \
    > "$RUNTIME_DIRECTORY/power-loss-capture.log" 2>&1 &
CAPTURE_PID=$!
POWER_PLAINTEXT_OBSERVED=0
for _ in $(seq 1 120); do
    if docker exec "$KILL_CAPTURE_CONTAINER" find /plaintext -type f \
        -name database.pgdump -size +0c -print -quit 2>/dev/null | grep -q .; then
        POWER_PLAINTEXT_OBSERVED=1
        break
    fi
    sleep 0.25
done
[[ $POWER_PLAINTEXT_OBSERVED == 1 ]] \
    || { printf '%s\n' 'power-loss capture never materialized plaintext on tmpfs' >&2; exit 1; }
docker kill --signal KILL "$KILL_CAPTURE_CONTAINER" >/dev/null
wait "$CAPTURE_PID" >/dev/null 2>&1 || true
CAPTURE_PID=
docker rm "$KILL_CAPTURE_CONTAINER" >/dev/null
sleep "$((POWER_LOSS_LEASE_SECONDS + 1))"
run_tools /opt/control-plane-blue-green/backup-quiesce/control-plane-backup-quiesce.sh \
    --env CONTROL_PLANE_TEST_MODE=1 \
    --env "CONTROL_PLANE_TEST_BACKUP_QUIESCE_BOOT_ID=$BACKUP_QUIESCE_BOOT_ID" -- \
    watchdog-scan --state-directory /lab/backup-quiesce-state
POWER_QUIESCE_STATE="$RUNTIME_DIRECTORY/backup-quiesce-state/$KILL_OPERATION/state"
if [[ ! -f $POWER_QUIESCE_STATE ]] || ! grep -Fqx phase=released "$POWER_QUIESCE_STATE"; then
    printf '%s\n' 'fresh watchdog did not release the power-loss capture fence' >&2
    exit 1
fi
for service_name in scheduler-worker horizon nightwatch-agent; do
    [[ $(docker exec "$LIVE_CONTAINER" /usr/local/bin/control-plane-lab-service \
        service-state "$service_name") == up ]] \
        || { printf '%s\n' 'power-loss watchdog did not restore live service prestate' >&2; exit 1; }
done
[[ $(docker inspect --format '{{.State.Running}}' "$REALTIME_CONTAINER") == true ]] \
    || { printf '%s\n' 'power-loss watchdog did not restore realtime prestate' >&2; exit 1; }
POWER_ROLE_SETTING=$(docker exec "$SOURCE_CONTAINER" psql --no-psqlrc --tuples-only \
    --no-align --quiet --username postgres --dbname coolify --command \
    "SELECT COALESCE((SELECT split_part(setting, '=', 2) FROM unnest(rolconfig) AS setting
      WHERE split_part(setting, '=', 1) = 'default_transaction_read_only'), 'unset')
     FROM pg_roles WHERE rolname = 'postgres'" | tr -d '[:space:]')
[[ $POWER_ROLE_SETTING == unset ]] \
    || { printf '%s\n' 'power-loss watchdog did not restore database role prestate' >&2; exit 1; }
POWER_CAPTURE_DIRECTORY="$RUNTIME_DIRECTORY/captures/$KILL_OPERATION"
while IFS= read -r power_artifact; do
    case ${power_artifact##*/} in
        database.pgdump.gpg|control-plane-state.tar.gpg|capture.manifest) ;;
        *) printf '%s\n' "power-loss capture persisted an ungoverned artifact: $power_artifact" >&2
            exit 1 ;;
    esac
done < <(find "$POWER_CAPTURE_DIRECTORY" -mindepth 1 -type f -print)
[[ -z $(find "$POWER_CAPTURE_DIRECTORY" -mindepth 1 ! -type f -print -quit) \
    && -z $(rg --text --files-with-matches --fixed-strings 'PGDMP' \
        "$POWER_CAPTURE_DIRECTORY" 2>/dev/null || true) \
    && -z $(rg --text --files-with-matches --fixed-strings 'revision=7' \
        "$POWER_CAPTURE_DIRECTORY" 2>/dev/null || true) ]] \
    || { printf '%s\n' 'power-loss capture persisted plaintext outside tmpfs' >&2; exit 1; }

docker exec "$SOURCE_CONTAINER" psql --no-psqlrc --quiet --set ON_ERROR_STOP=1 \
    --username postgres --dbname coolify --command \
    "INSERT INTO control_plane_widget (id, name, enabled) VALUES (99, 'post-capture', true)" \
    >/dev/null
docker exec "$SOURCE_REDIS_CONTAINER" sh -ec '
    export REDISCLI_AUTH=$REDIS_PASSWORD
    redis-cli --no-auth-warning set cache:post-capture must-not-restore >/dev/null
'

CAPTURE_DIRECTORY="$RUNTIME_DIRECTORY/captures/$OPERATION_ID"
CAPTURE_MANIFEST="$CAPTURE_DIRECTORY/capture.manifest"
CAPTURE_MANIFEST_SHA256=$(sha256sum "$CAPTURE_MANIFEST" | awk '{print $1}')
SOURCE_SYSTEM_IDENTIFIER=$(manifest_value "$CAPTURE_MANIFEST" source_pg_system_identifier)
SOURCE_DATABASE_OID=$(manifest_value "$CAPTURE_MANIFEST" source_pg_database_oid)
SOURCE_REDIS_CONTAINER_ID=$(manifest_value "$CAPTURE_MANIFEST" source_redis_container_id)
CAPTURE_LEASE_REQUESTED=$(manifest_value "$CAPTURE_MANIFEST" \
    quiesce_lease_requested_seconds)
CAPTURE_LEASE_ACQUIRED=$(manifest_value "$CAPTURE_MANIFEST" quiesce_lease_acquired_unix)
CAPTURE_LEASE_EXPIRES=$(manifest_value "$CAPTURE_MANIFEST" quiesce_lease_expires_unix)
[[ $CAPTURE_LEASE_REQUESTED == 1200 \
    && $((CAPTURE_LEASE_EXPIRES - CAPTURE_LEASE_ACQUIRED)) == 1200 ]] \
    || { printf '%s\n' 'capture did not bind the explicit conservative lease' >&2; exit 1; }
REHEARSAL_HOOK_SHA256=$(sha256sum "$SCRIPT_DIRECTORY/hooks/rehearsal-hook.sh" | awk '{print $1}')
CANDIDATE_BOOT_HOOK_SHA256=$(sha256sum "$SCRIPT_DIRECTORY/hooks/candidate-boot-hook.sh" | awk '{print $1}')
CANDIDATE_PROBE_HOOK_SHA256=$(sha256sum \
    "$SCRIPT_DIRECTORY/hooks/candidate-api-probe-hook.sh" | awk '{print $1}')
CANONICAL_BOOT_HOOK_SHA256=$(sha256sum \
    "$REPOSITORY_ROOT/docker/control-plane-blue-green/backup/candidate-boot-hook.sh" | awk '{print $1}')
CANONICAL_PROBE_HOOK_SHA256=$(sha256sum \
    "$REPOSITORY_ROOT/docker/control-plane-blue-green/backup/candidate-api-probe-hook.sh" | awk '{print $1}')
STATE_PROOF_TOOL_SHA256=$(sha256sum \
    "$REPOSITORY_ROOT/docker/control-plane-blue-green/backup/state-proof.sh" | awk '{print $1}')
CANONICAL_COMPOSE_SHA256=$(sha256sum \
    "$REPOSITORY_ROOT/docker/control-plane-blue-green/compose.yaml" | awk '{print $1}')

# shellcheck disable=SC2016
run_tools bash -- -c '
    set -Eeuo pipefail
    mkdir -m 700 /lab/negative-captures
    mkdir -m 755 /lab/insecure-output
'

expect_failure mutable-candidate-tag capture_attempt mutable-tag \
    docker.io/library/postgres:15.18-alpine /source-state /lab/negative-captures
expect_failure insecure-output-mode capture_attempt insecure-output \
    "$CANDIDATE_IMAGE_DIGEST" /source-state /lab/insecure-output
expect_failure relative-output-path capture_attempt relative-output \
    "$CANDIDATE_IMAGE_DIGEST" /source-state relative-output

for state_fault in owner mode symlink special socket; do
    state_volume="$LAB_ID-state-$state_fault"
    LAB_VOLUMES+=("$state_volume")
    docker volume create "$state_volume" >/dev/null
    # shellcheck disable=SC2016
    run_tools bash --volume "$state_volume:/linux-state" -- -c '
        cp -a /opt/lab-fixtures/control-plane-state/. /linux-state/
        find /linux-state -mindepth 1 -type d -exec chown 9999:9999 {} + -exec chmod 700 {} +
        find /linux-state -type f -exec chown 9999:9999 {} + -exec chmod 600 {} +
        for selected in ssh applications databases services; do chown 9999:0 "/linux-state/$selected"; done
    '
done
run_tools chown --volume "$LAB_ID-state-owner:/linux-state" -- \
    1234:1234 /linux-state/applications/operation-state
run_tools chmod --volume "$LAB_ID-state-mode:/linux-state" -- \
    0666 /linux-state/applications/operation-state
run_tools ln --volume "$LAB_ID-state-symlink:/linux-state" -- \
    -s /tmp /linux-state/applications/nested-link
run_tools mkfifo --volume "$LAB_ID-state-special:/linux-state" -- \
    /linux-state/applications/nested-fifo
docker run --detach --name "$INVALID_SOCKET_CONTAINER" --user 9999:9999 \
    --volume "$LAB_ID-state-socket:/linux-state" --entrypoint socat "$TOOLS_IMAGE" \
    UNIX-LISTEN:/linux-state/ssh/ungoverned_socket,fork,mode=600 EXEC:/bin/true >/dev/null
sleep 1
expect_failure nested-state-owner capture_linux_volume_attempt nested-owner \
    "$LAB_ID-state-owner"
expect_failure nested-state-mode capture_linux_volume_attempt nested-mode \
    "$LAB_ID-state-mode"
expect_failure nested-state-symlink capture_linux_volume_attempt nested-symlink \
    "$LAB_ID-state-symlink"
expect_failure nested-state-special-file capture_linux_volume_attempt nested-special \
    "$LAB_ID-state-special"
expect_failure ungoverned-state-socket capture_linux_volume_attempt nested-socket \
    "$LAB_ID-state-socket"

docker run --detach --name "$TARGET_CONTAINER" --network "$RESTORE_NETWORK" \
    --label coolify.control-plane.backup-restore.disposable=true \
    --label "coolify.control-plane.backup-restore.operation=$OPERATION_ID" \
    --env POSTGRES_HOST_AUTH_METHOD=trust --env POSTGRES_DB=postgres \
    --volume "$TARGET_VOLUME:/var/lib/postgresql/data" "$POSTGRES_IMAGE" >/dev/null
wait_for_postgres "$TARGET_CONTAINER"

IDENTITY_ARGUMENTS=(
    --operation-id "$OPERATION_ID"
    --expected-capture-manifest-sha256 "$CAPTURE_MANIFEST_SHA256"
    --expected-source-pg-system-identifier "$SOURCE_SYSTEM_IDENTIFIER"
    --expected-source-pg-database-oid "$SOURCE_DATABASE_OID"
    --expected-source-pg-version 15.18
    --expected-source-database-name coolify
    --expected-source-host-identity source-host-lab
    --expected-source-redis-container-id "$SOURCE_REDIS_CONTAINER_ID"
    --expected-source-redis-image-digest "$REDIS_IMAGE"
    --expected-source-redis-endpoint "$SOURCE_REDIS_CONTAINER:6379"
    --expected-candidate-image-digest "$CANDIDATE_IMAGE_DIGEST"
    --expected-recipient-fingerprint "$RECIPIENT_FINGERPRINT"
    --expected-quiesce-operator-sha256 "$QUIESCE_OPERATOR_SHA256"
    --expected-restore-target-identity restore-host-lab
    --expected-state-proof-tool-sha256 "$STATE_PROOF_TOOL_SHA256"
    --max-age-seconds 3600
)
BOUND_ARGUMENTS=(
    "${IDENTITY_ARGUMENTS[@]}"
    --expected-operator-rehearsal-hook-sha256 "$REHEARSAL_HOOK_SHA256"
    --expected-candidate-boot-hook-sha256 "$CANDIDATE_BOOT_HOOK_SHA256"
    --expected-candidate-api-probe-hook-sha256 "$CANDIDATE_PROBE_HOOK_SHA256"
)
CANONICAL_BOUND_ARGUMENTS=(
    "${IDENTITY_ARGUMENTS[@]}"
    --expected-operator-rehearsal-hook-sha256 "$REHEARSAL_HOOK_SHA256"
    --expected-candidate-boot-hook-sha256 "$CANONICAL_BOOT_HOOK_SHA256"
    --expected-candidate-api-probe-hook-sha256 "$CANONICAL_PROBE_HOOK_SHA256"
)
COMMON_ARGUMENTS=(
    --database-dump "/lab/captures/$OPERATION_ID/database.pgdump.gpg"
    --redis-snapshot "/lab/captures/$OPERATION_ID/redis.rdb.gpg"
    --state-archive "/lab/captures/$OPERATION_ID/control-plane-state.tar.gpg"
    --capture-manifest "/lab/captures/$OPERATION_ID/capture.manifest"
    "${BOUND_ARGUMENTS[@]}"
)
CANONICAL_COMMON_ARGUMENTS=(
    --database-dump "/lab/captures/$OPERATION_ID/database.pgdump.gpg"
    --redis-snapshot "/lab/captures/$OPERATION_ID/redis.rdb.gpg"
    --state-archive "/lab/captures/$OPERATION_ID/control-plane-state.tar.gpg"
    --capture-manifest "/lab/captures/$OPERATION_ID/capture.manifest"
    "${CANONICAL_BOUND_ARGUMENTS[@]}"
)

run_tools mkdir -- --mode=700 /restore-state-volume/state
run_tools /opt/control-plane-backup/restore-attest.sh \
    --hostname restore-host-lab --env GNUPGHOME=/lab/offhost-gnupg \
    --env "LAB_NETWORK=$RESTORE_NETWORK" \
    --env "CONTROL_PLANE_CANDIDATE_COMPOSE_FILE=/opt/control-plane-blue-green/compose.yaml" \
    --env "CONTROL_PLANE_EXPECTED_CANDIDATE_COMPOSE_SHA256=$CANONICAL_COMPOSE_SHA256" \
    --env "CONTROL_PLANE_RUNTIME_ENV_FILE=$RUNTIME_DIRECTORY/candidate.env" \
    --env "CONTROL_PLANE_NETWORK=$LAB_ID-candidate-network" \
    --env "CONTROL_PLANE_LIVE_NETWORK=$NETWORK" \
    --env "CONTROL_PLANE_GREEN_CONTAINER=$CANDIDATE_CONTAINER" \
    --env "CONTROL_PLANE_GREEN_STATE_VOLUME=$LAB_ID-candidate-state" \
    --env "CONTROL_PLANE_RESTORED_STATE_DOCKER_VOLUME=$RESTORE_STATE_VOLUME" \
    --env "CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE=$RUNTIME_DIRECTORY/probe-token" \
    --env "CONTROL_PLANE_GREEN_APPLIED_ACK_FILE=$RUNTIME_DIRECTORY/probe-ack" \
    --env CONTROL_PLANE_WRITER_EPOCH=writer-epoch-0001 \
    --env CONTROL_PLANE_GREEN_WEB_EPOCH=green-web-epoch-01 \
    --env CONTROL_PLANE_DIRECT_PROBE_PATH=/api/control-plane/probe \
    --env CONTROL_PLANE_BACKEND_PORT=8080 \
    --env "CONTROL_PLANE_GREEN_LOOPBACK_PORT=$CANDIDATE_LOOPBACK_PORT" \
    --env CONTROL_PLANE_EXPECTED_MIGRATION_BATCH=1 -- \
    restore "${CANONICAL_COMMON_ARGUMENTS[@]}" \
    --restore-target-container "$TARGET_CONTAINER" --restore-database-user postgres \
    --restore-redis-container "$LAB_ID-redis-main" \
    --restore-redis-volume "$LAB_ID-redis-data-main" \
    --restore-redis-network "$LAB_ID-redis-network-main" \
    --restore-redis-password-file /lab/redis-password \
    --operator-rehearse-migrations-hook /opt/lab-hooks/rehearsal-hook.sh \
    --candidate-boot-hook /opt/control-plane-backup/candidate-boot-hook.sh \
    --candidate-api-probe-hook /opt/control-plane-backup/candidate-api-probe-hook.sh \
    --candidate-runtime-container "$CANDIDATE_CONTAINER" \
    --restore-state-root /restore-state-volume/state \
    --plaintext-tmpfs-root /plaintext \
    --stale-work-max-age-seconds 3600 \
    --state-proof-tool "$RUNTIME_DIRECTORY/state-proof.sh" \
    --attestation-output /lab/attestations/restore.attestation >/dev/null

ATTESTATION="$RUNTIME_DIRECTORY/attestations/restore.attestation"
ATTESTATION_SHA256=$(sha256sum "$ATTESTATION" | awk '{print $1}')
run_tools /opt/control-plane-backup/restore-attest.sh \
    --hostname restore-host-lab -- \
    verify-attestation "${CANONICAL_COMMON_ARGUMENTS[@]}" \
    --attestation /lab/attestations/restore.attestation \
    --expected-attestation-sha256 "$ATTESTATION_SHA256" >/dev/null

[[ $(manifest_value "$ATTESTATION" control_plane_state_restore) == passed \
    && $(manifest_value "$ATTESTATION" candidate_clone_endpoints) == passed \
    && $(manifest_value "$ATTESTATION" candidate_api_probe) == passed \
    && $(manifest_value "$ATTESTATION" restored_state_proof_sha256) == \
        "$(manifest_value "$ATTESTATION" candidate_observed_state_proof_sha256)" \
    && $(docker exec "$CANDIDATE_CONTAINER" \
        sed -n '1p' /restored-state/applications/operation-state) == revision=7 ]] \
    || { printf '%s\n' 'functional restored-state candidate proof is absent' >&2; exit 1; }
[[ $(docker exec "$CANDIDATE_CONTAINER" stat -c '%u:%g:%a' \
        /run/secrets/control-plane-direct-probe-token) == 0:0:444 \
    && $(docker exec "$CANDIDATE_CONTAINER" stat -c '%u:%g:%a' \
        /run/secrets/control-plane-applied-ack) == 0:0:444 \
    && $(stat -c '%u:%g:%a' "$RUNTIME_DIRECTORY/probe-token") == 0:0:600 \
    && $(stat -c '%u:%g:%a' "$RUNTIME_DIRECTORY/probe-ack") == 0:0:600 ]] \
    || { printf '%s\n' 'candidate secrets do not preserve host and runtime ownership' >&2; exit 1; }
CANDIDATE_DIRECT_PROBE_SOURCE=$(docker inspect --format \
    '{{range .Mounts}}{{if eq .Destination "/run/secrets/control-plane-direct-probe-token"}}{{.Source}}{{end}}{{end}}' \
    "$CANDIDATE_CONTAINER")
CANDIDATE_APPLIED_ACK_SOURCE=$(docker inspect --format \
    '{{range .Mounts}}{{if eq .Destination "/run/secrets/control-plane-applied-ack"}}{{.Source}}{{end}}{{end}}' \
    "$CANDIDATE_CONTAINER")
[[ -f $CANDIDATE_DIRECT_PROBE_SOURCE && ! -L $CANDIDATE_DIRECT_PROBE_SOURCE \
    && $(stat -c '%u:%g:%a' "$CANDIDATE_DIRECT_PROBE_SOURCE") == 0:0:444 \
    && -f $CANDIDATE_APPLIED_ACK_SOURCE && ! -L $CANDIDATE_APPLIED_ACK_SOURCE \
    && $(stat -c '%u:%g:%a' "$CANDIDATE_APPLIED_ACK_SOURCE") == 0:0:444 ]] \
    || { printf '%s\n' 'candidate restartable secret sources did not survive boot' >&2; exit 1; }
docker restart "$CANDIDATE_CONTAINER" >/dev/null
CANDIDATE_RESTART_READY=0
for _ in $(seq 1 30); do
    if [[ $(docker inspect --format '{{.State.Running}}' "$CANDIDATE_CONTAINER") == true \
        && $(docker exec "$CANDIDATE_CONTAINER" \
            cat /run/secrets/control-plane-direct-probe-token) \
            == "$(cat "$RUNTIME_DIRECTORY/probe-token")" \
        && $(docker exec "$CANDIDATE_CONTAINER" \
            cat /run/secrets/control-plane-applied-ack) \
            == "$(cat "$RUNTIME_DIRECTORY/probe-ack")" ]] \
        && docker exec "$CANDIDATE_CONTAINER" sh -ec '
            probe_token=$(cat /run/secrets/control-plane-direct-probe-token)
            expected_ack=$(cat /run/secrets/control-plane-applied-ack)
            response=$(mktemp)
            trap '\''rm -f "$response"'\'' EXIT
            status=$(curl --silent --show-error --output /dev/null --dump-header "$response" \
                --write-out "%{http_code}" --header "X-Control-Plane-Probe: $probe_token" \
                http://127.0.0.1:8080/api/control-plane/probe)
            [ "$status" = 204 ]
            [ "$(sed -n '\''s/^X-Control-Plane-Applied-Config: \(.*\)\r$/\1/p'\'' "$response")" \
                = "$expected_ack" ]
        ' 2>/dev/null; then
        CANDIDATE_RESTART_READY=1
        break
    fi
    sleep 1
done
[[ $CANDIDATE_RESTART_READY == 1 ]] \
    || { printf '%s\n' 'candidate API and secrets were unavailable after restart' >&2; exit 1; }
CANONICAL_CANDIDATE_NETWORK="$LAB_ID-candidate-network"
EXPECTED_CANDIDATE_MEMBERS=$(printf '%s\n' "$CANDIDATE_CONTAINER" "$TARGET_CONTAINER" \
    "$LAB_ID-redis-main" | LC_ALL=C sort)
OBSERVED_CANDIDATE_MEMBERS=$(docker network inspect --format \
    '{{range .Containers}}{{println .Name}}{{end}}' "$CANONICAL_CANDIDATE_NETWORK" \
    | awk 'NF { print }' | LC_ALL=C sort)
CANDIDATE_NETWORKS=$(docker inspect --format \
    '{{range $network, $_ := .NetworkSettings.Networks}}{{println $network}}{{end}}' \
    "$CANDIDATE_CONTAINER" | awk 'NF { print }' | LC_ALL=C sort)
[[ $(docker network inspect --format '{{.Internal}}' "$CANONICAL_CANDIDATE_NETWORK") == true \
    && $OBSERVED_CANDIDATE_MEMBERS == "$EXPECTED_CANDIDATE_MEMBERS" \
    && $CANDIDATE_NETWORKS == "$CANONICAL_CANDIDATE_NETWORK" \
    && $OBSERVED_CANDIDATE_MEMBERS != *"$SOURCE_CONTAINER"* \
    && $OBSERVED_CANDIDATE_MEMBERS != *"$SOURCE_REDIS_CONTAINER"* ]] \
    || { printf '%s\n' 'candidate is not confined to the clone-only internal network' >&2; exit 1; }

RESTORED_SENTINEL_COUNT=$(docker exec "$TARGET_CONTAINER" psql --no-psqlrc --tuples-only \
    --no-align --quiet --set ON_ERROR_STOP=1 --username postgres --dbname coolify \
    --command 'SELECT count(*) FROM control_plane_widget WHERE id = 99' | tr -d '[:space:]')
SOURCE_SENTINEL_COUNT=$(docker exec "$SOURCE_CONTAINER" psql --no-psqlrc --tuples-only \
    --no-align --quiet --set ON_ERROR_STOP=1 --username postgres --dbname coolify \
    --command 'SELECT count(*) FROM control_plane_widget WHERE id = 99' | tr -d '[:space:]')
[[ $RESTORED_SENTINEL_COUNT == 0 && $SOURCE_SENTINEL_COUNT == 1 ]] \
    || { printf '%s\n' 'restored database is not the captured snapshot' >&2; exit 1; }
RESTORED_WRITER_COUNT=$(docker exec "$TARGET_CONTAINER" psql --no-psqlrc --tuples-only \
    --no-align --quiet --set ON_ERROR_STOP=1 --username postgres --dbname coolify \
    --command 'SELECT count(*) FROM live_write_probe' | tr -d '[:space:]')
RESTORED_WRITER_LINES=$(docker exec "$CANDIDATE_CONTAINER" sh -c 'wc -l < "$1"' sh \
    /restored-state/applications/backup-quiesce-writer-state | tr -d '[:space:]')
[[ $RESTORED_WRITER_COUNT =~ ^[1-9][0-9]*$ \
    && $RESTORED_WRITER_COUNT == "$RESTORED_WRITER_LINES" ]] \
    || { printf 'restored database/filesystem generations crossed the backup fence (database=%s filesystem=%s)\n' \
        "$RESTORED_WRITER_COUNT" "$RESTORED_WRITER_LINES" >&2; exit 1; }
RESTORED_REDIS_PROOF=$(docker exec "$LAB_ID-redis-main" sh -ec '
    export REDISCLI_AUTH=$REDIS_PASSWORD
    printf "%s:%s:%s\n" "$(redis-cli --no-auth-warning llen queues:deploy)" \
        "$(redis-cli --no-auth-warning zcard queues:deploy:delayed)" \
        "$(redis-cli --no-auth-warning get cache:control-plane)"
')
RESTORED_POST_CAPTURE_REDIS=$(docker exec "$LAB_ID-redis-main" sh -ec '
    export REDISCLI_AUTH=$REDIS_PASSWORD
    redis-cli --no-auth-warning exists cache:post-capture
')
[[ $RESTORED_REDIS_PROOF == 2:1:captured-cache && $RESTORED_POST_CAPTURE_REDIS == 0 ]] \
    || { printf '%s\n' 'restored Redis queue/cache snapshot differs from capture' >&2; exit 1; }
docker rm -f "$CANDIDATE_CONTAINER" >/dev/null
docker network disconnect --force "$LAB_ID-candidate-network" "$TARGET_CONTAINER" \
    >/dev/null 2>&1 || true
docker network disconnect --force "$LAB_ID-candidate-network" "$LAB_ID-redis-main" \
    >/dev/null 2>&1 || true
docker volume rm "$LAB_ID-candidate-state" >/dev/null
docker volume rm "backup-candidate-coordination-$CANDIDATE_RESOURCE_HASH" >/dev/null
[[ -z $(docker volume ls --quiet \
    --filter "name=^backup-candidate-coordination-${CANDIDATE_RESOURCE_HASH}$") ]] \
    || { printf '%s\n' 'candidate coordination volume survived successful restore proof' >&2; exit 1; }
docker network rm "$LAB_ID-candidate-network" >/dev/null
docker rm -f "$LAB_ID-redis-main" >/dev/null
docker volume rm "$LAB_ID-redis-data-main" >/dev/null
docker network rm "$LAB_ID-redis-network-main" >/dev/null

# shellcheck disable=SC2016
run_tools bash -- -c '
    set -Eeuo pipefail
    negative=/lab/negatives
    mkdir -m 700 "$negative"

    cp /lab/captures/backup-restore-lab/database.pgdump.gpg "$negative/dump-tampered.gpg"
    printf X >> "$negative/dump-tampered.gpg"
    chmod 600 "$negative/dump-tampered.gpg"

    cp /lab/captures/backup-restore-lab/redis.rdb.gpg "$negative/redis-tampered.gpg"
    printf X >> "$negative/redis-tampered.gpg"
    chmod 600 "$negative/redis-tampered.gpg"

    cp /lab/captures/backup-restore-lab/control-plane-state.tar.gpg "$negative/archive-tampered.gpg"
    printf X >> "$negative/archive-tampered.gpg"
    chmod 600 "$negative/archive-tampered.gpg"

    cp /lab/captures/backup-restore-lab/database.pgdump.gpg "$negative/dump-mode.gpg"
    chmod 644 "$negative/dump-mode.gpg"
    cp /lab/captures/backup-restore-lab/database.pgdump.gpg "$negative/dump-owner.gpg"
    chown 1234:1234 "$negative/dump-owner.gpg"
    chmod 600 "$negative/dump-owner.gpg"
    stat -c %u "$negative/dump-owner.gpg" > "$negative/owner-uid"
    ln -s /lab/captures/backup-restore-lab/database.pgdump.gpg "$negative/dump-link.gpg"

    cp /lab/attestations/restore.attestation "$negative/attestation-tampered"
    chmod 600 "$negative/attestation-tampered"
    sed -i "s/^candidate_boot=passed$/candidate_boot=failed/" "$negative/attestation-tampered"
    chmod 400 "$negative/attestation-tampered"
    tampered_hash=$(sha256sum "$negative/attestation-tampered")
    printf "%s\\n" "${tampered_hash%% *}" > "$negative/attestation-tampered.sha256"

    rewrite_attestation()
    {
        local file=$1 expression=$2
        chmod 600 "$file"
        sed -i "$expression" "$file"
        line_count=$(wc -l < "$file" | tr -d "[:space:]")
        sed -i "${line_count}d" "$file"
        payload_output=$(sha256sum "$file")
        payload=${payload_output%% *}
        printf "attestation_payload_sha256=%s\\n" "$payload" >> "$file"
        chmod 400 "$file"
        file_hash_output=$(sha256sum "$file")
        printf "%s\\n" "${file_hash_output%% *}" > "$file.sha256"
    }

    cp /lab/attestations/restore.attestation "$negative/attestation-stale"
    rewrite_attestation "$negative/attestation-stale" "s/^generated_unix=.*/generated_unix=1/"
    cp /lab/attestations/restore.attestation "$negative/attestation-hook-tampered"
    rewrite_attestation "$negative/attestation-hook-tampered" \
        "s/^operator_rehearsal_hook_sha256=.*/operator_rehearsal_hook_sha256=$(printf 0%.0s {1..64})/"

    cp /lab/attestations/restore.attestation "$negative/attestation-mode"
    chmod 600 "$negative/attestation-mode"
    cp /lab/attestations/restore.attestation "$negative/attestation-owner"
    chown 1234:1234 "$negative/attestation-owner"
    chmod 400 "$negative/attestation-owner"
    stat -c %u "$negative/attestation-owner" > "$negative/attestation-owner-uid"
    ln -s /lab/attestations/restore.attestation "$negative/attestation-link"
'

TAMPERED_ATTESTATION_SHA256=$(tr -d '[:space:]' \
    < "$RUNTIME_DIRECTORY/negatives/attestation-tampered.sha256")
STALE_ATTESTATION_SHA256=$(tr -d '[:space:]' \
    < "$RUNTIME_DIRECTORY/negatives/attestation-stale.sha256")
HOOK_TAMPERED_ATTESTATION_SHA256=$(tr -d '[:space:]' \
    < "$RUNTIME_DIRECTORY/negatives/attestation-hook-tampered.sha256")

expect_failure tampered-dump verify_attempt /lab/negatives/dump-tampered.gpg \
    "/lab/captures/$OPERATION_ID/control-plane-state.tar.gpg" \
    "/lab/captures/$OPERATION_ID/capture.manifest" /lab/attestations/restore.attestation \
    "$ATTESTATION_SHA256"
expect_failure tampered-redis-snapshot verify_redis_attempt /lab/negatives/redis-tampered.gpg
expect_failure wrong-source-redis-identity verify_wrong_redis_identity
expect_failure tampered-state-archive verify_attempt \
    "/lab/captures/$OPERATION_ID/database.pgdump.gpg" /lab/negatives/archive-tampered.gpg \
    "/lab/captures/$OPERATION_ID/capture.manifest" /lab/attestations/restore.attestation \
    "$ATTESTATION_SHA256"
expect_failure tampered-attestation verify_attempt \
    "/lab/captures/$OPERATION_ID/database.pgdump.gpg" \
    "/lab/captures/$OPERATION_ID/control-plane-state.tar.gpg" \
    "/lab/captures/$OPERATION_ID/capture.manifest" /lab/negatives/attestation-tampered \
    "$TAMPERED_ATTESTATION_SHA256"
expect_failure stale-attestation verify_attempt \
    "/lab/captures/$OPERATION_ID/database.pgdump.gpg" \
    "/lab/captures/$OPERATION_ID/control-plane-state.tar.gpg" \
    "/lab/captures/$OPERATION_ID/capture.manifest" /lab/negatives/attestation-stale \
    "$STALE_ATTESTATION_SHA256"
expect_failure tampered-hook-binding verify_attempt \
    "/lab/captures/$OPERATION_ID/database.pgdump.gpg" \
    "/lab/captures/$OPERATION_ID/control-plane-state.tar.gpg" \
    "/lab/captures/$OPERATION_ID/capture.manifest" \
    /lab/negatives/attestation-hook-tampered "$HOOK_TAMPERED_ATTESTATION_SHA256"
expect_failure artifact-mode verify_attempt /lab/negatives/dump-mode.gpg \
    "/lab/captures/$OPERATION_ID/control-plane-state.tar.gpg" \
    "/lab/captures/$OPERATION_ID/capture.manifest" /lab/attestations/restore.attestation \
    "$ATTESTATION_SHA256"
if [[ $(tr -d '[:space:]' < "$RUNTIME_DIRECTORY/negatives/owner-uid") == 1234 ]]; then
    expect_failure artifact-owner verify_attempt /lab/negatives/dump-owner.gpg \
        "/lab/captures/$OPERATION_ID/control-plane-state.tar.gpg" \
        "/lab/captures/$OPERATION_ID/capture.manifest" /lab/attestations/restore.attestation \
        "$ATTESTATION_SHA256"
fi
expect_failure artifact-symlink verify_attempt /lab/negatives/dump-link.gpg \
    "/lab/captures/$OPERATION_ID/control-plane-state.tar.gpg" \
    "/lab/captures/$OPERATION_ID/capture.manifest" /lab/attestations/restore.attestation \
    "$ATTESTATION_SHA256"
expect_failure attestation-mode verify_attempt \
    "/lab/captures/$OPERATION_ID/database.pgdump.gpg" \
    "/lab/captures/$OPERATION_ID/control-plane-state.tar.gpg" \
    "/lab/captures/$OPERATION_ID/capture.manifest" /lab/negatives/attestation-mode \
    "$ATTESTATION_SHA256"
if [[ $(tr -d '[:space:]' < "$RUNTIME_DIRECTORY/negatives/attestation-owner-uid") == 1234 ]]; then
    expect_failure attestation-owner verify_attempt \
        "/lab/captures/$OPERATION_ID/database.pgdump.gpg" \
        "/lab/captures/$OPERATION_ID/control-plane-state.tar.gpg" \
        "/lab/captures/$OPERATION_ID/capture.manifest" /lab/negatives/attestation-owner \
        "$ATTESTATION_SHA256"
else
    printf '%s\n' 'ControlPlaneBackupRestore owner-negative: SKIP (bind ownership normalized)'
fi
expect_failure attestation-symlink verify_attempt \
    "/lab/captures/$OPERATION_ID/database.pgdump.gpg" \
    "/lab/captures/$OPERATION_ID/control-plane-state.tar.gpg" \
    "/lab/captures/$OPERATION_ID/capture.manifest" /lab/negatives/attestation-link \
    "$ATTESTATION_SHA256"
expect_failure relative-artifact-path verify_attempt relative-dump.gpg \
    "/lab/captures/$OPERATION_ID/control-plane-state.tar.gpg" \
    "/lab/captures/$OPERATION_ID/capture.manifest" /lab/attestations/restore.attestation \
    "$ATTESTATION_SHA256"

start_fresh_target wrong-version "$WRONG_POSTGRES_IMAGE"
WRONG_VERSION_TARGET=$STARTED_TARGET
WRONG_VERSION_VOLUME=$STARTED_VOLUME
expect_failure wrong-postgres-version restore_attempt wrong-version "$WRONG_VERSION_TARGET" \
    "$LAB_ID-candidate-wrong-version"
docker rm -f "$WRONG_VERSION_TARGET" >/dev/null 2>&1 || true
docker volume rm "$WRONG_VERSION_VOLUME" >/dev/null 2>&1 || true

start_fresh_target persistent-plaintext "$POSTGRES_IMAGE"
PERSISTENT_PLAINTEXT_TARGET=$STARTED_TARGET
PERSISTENT_PLAINTEXT_VOLUME=$STARTED_VOLUME
expect_failure persistent-plaintext-work restore_attempt persistent-plaintext \
    "$PERSISTENT_PLAINTEXT_TARGET" "$LAB_ID-candidate-persistent-plaintext" '' 1 /lab
assert_failed_restore_cleanup persistent-plaintext "$PERSISTENT_PLAINTEXT_TARGET" \
    "$LAB_ID-candidate-persistent-plaintext"
docker rm -f "$PERSISTENT_PLAINTEXT_TARGET" >/dev/null 2>&1 || true
docker volume rm "$PERSISTENT_PLAINTEXT_VOLUME" >/dev/null 2>&1 || true

start_fresh_target rehearsal-failure "$POSTGRES_IMAGE"
REHEARSAL_FAILURE_TARGET=$STARTED_TARGET
REHEARSAL_FAILURE_VOLUME=$STARTED_VOLUME
expect_failure failed-rehearsal restore_attempt rehearsal-failure "$REHEARSAL_FAILURE_TARGET" \
    "$LAB_ID-candidate-rehearsal-failure" LAB_FAIL_REHEARSAL
assert_failed_restore_cleanup rehearsal-failure "$REHEARSAL_FAILURE_TARGET" \
    "$LAB_ID-candidate-rehearsal-failure"
docker rm -f "$REHEARSAL_FAILURE_TARGET" >/dev/null 2>&1 || true
docker volume rm "$REHEARSAL_FAILURE_VOLUME" >/dev/null 2>&1 || true

start_fresh_target boot-failure "$POSTGRES_IMAGE"
BOOT_FAILURE_TARGET=$STARTED_TARGET
BOOT_FAILURE_VOLUME=$STARTED_VOLUME
expect_failure failed-candidate-boot restore_attempt boot-failure "$BOOT_FAILURE_TARGET" \
    "$LAB_ID-candidate-boot-failure" LAB_FAIL_CANDIDATE_BOOT
assert_failed_restore_cleanup boot-failure "$BOOT_FAILURE_TARGET" \
    "$LAB_ID-candidate-boot-failure"
docker rm -f "$LAB_ID-candidate-boot-failure" "$BOOT_FAILURE_TARGET" >/dev/null 2>&1 || true
docker volume rm "$BOOT_FAILURE_VOLUME" >/dev/null 2>&1 || true

start_fresh_target probe-failure "$POSTGRES_IMAGE"
PROBE_FAILURE_TARGET=$STARTED_TARGET
PROBE_FAILURE_VOLUME=$STARTED_VOLUME
expect_failure failed-candidate-api-probe restore_attempt probe-failure "$PROBE_FAILURE_TARGET" \
    "$LAB_ID-candidate-probe-failure" LAB_FAIL_CANDIDATE_PROBE
assert_failed_restore_cleanup probe-failure "$PROBE_FAILURE_TARGET" \
    "$LAB_ID-candidate-probe-failure"
docker rm -f "$LAB_ID-candidate-probe-failure" "$PROBE_FAILURE_TARGET" >/dev/null 2>&1 || true
docker volume rm "$PROBE_FAILURE_VOLUME" >/dev/null 2>&1 || true

start_fresh_target canonical-probe-failure "$POSTGRES_IMAGE"
CANONICAL_PROBE_FAILURE_TARGET=$STARTED_TARGET
CANONICAL_PROBE_FAILURE_VOLUME=$STARTED_VOLUME
expect_failure canonical-post-boot-api-failure canonical_restore_attempt \
    canonical-probe-failure "$CANONICAL_PROBE_FAILURE_TARGET" \
    "$LAB_ID-candidate-canonical-probe-failure" 2
if docker inspect "$LAB_ID-candidate-canonical-probe-failure" >/dev/null 2>&1; then
    printf '%s\n' 'canonical post-boot failure leaked candidate container' >&2
    exit 1
fi
CANONICAL_FAILURE_DATABASE_COUNT=$(docker exec "$CANONICAL_PROBE_FAILURE_TARGET" psql \
    --no-psqlrc --tuples-only --no-align --quiet --username postgres --dbname postgres \
    --command "SELECT count(*) FROM pg_database WHERE datname = 'coolify'" | tr -d '[:space:]')
[[ $CANONICAL_FAILURE_DATABASE_COUNT == 0 ]] \
    || { printf '%s\n' 'canonical post-boot failure leaked restored database' >&2; exit 1; }
# shellcheck disable=SC2016
run_tools sh --volume "$LAB_ID-restored-state-canonical-probe-failure:/canonical-state" -- \
    -ec '[ -z "$(find /canonical-state/state -mindepth 1 -print -quit)" ]'
[[ -z $(docker volume ls --quiet \
        --filter label=coolify.control-plane.backup-restore.resource=true \
        --filter label="coolify.control-plane.backup-restore.operation=$OPERATION_ID") \
    && -z $(docker network ls --quiet \
        --filter label=coolify.control-plane.backup-restore.resource=true \
        --filter label="coolify.control-plane.backup-restore.operation=$OPERATION_ID") ]] \
    || { printf '%s\n' 'canonical post-boot failure leaked labeled resources' >&2; exit 1; }
docker rm -f "$CANONICAL_PROBE_FAILURE_TARGET" >/dev/null 2>&1 || true
docker volume rm "$CANONICAL_PROBE_FAILURE_VOLUME" >/dev/null 2>&1 || true

start_fresh_target canonical-live-redis-env "$POSTGRES_IMAGE"
CANONICAL_LIVE_REDIS_TARGET=$STARTED_TARGET
CANONICAL_LIVE_REDIS_VOLUME=$STARTED_VOLUME
expect_failure candidate-live-redis-endpoint canonical_restore_attempt \
    canonical-live-redis-env "$CANONICAL_LIVE_REDIS_TARGET" \
    "$LAB_ID-candidate-canonical-live-redis-env" 1 "$SOURCE_REDIS_CONTAINER"
if docker inspect "$LAB_ID-candidate-canonical-live-redis-env" >/dev/null 2>&1; then
    printf '%s\n' 'live-Redis endpoint negative leaked candidate' >&2
    exit 1
fi
CANONICAL_LIVE_REDIS_DATABASE_COUNT=$(docker exec "$CANONICAL_LIVE_REDIS_TARGET" psql \
    --no-psqlrc --tuples-only --no-align --quiet --username postgres --dbname postgres \
    --command "SELECT count(*) FROM pg_database WHERE datname = 'coolify'" | tr -d '[:space:]')
[[ $CANONICAL_LIVE_REDIS_DATABASE_COUNT == 0 ]] \
    || { printf '%s\n' 'live-Redis endpoint negative leaked restored database' >&2; exit 1; }
docker rm -f "$CANONICAL_LIVE_REDIS_TARGET" >/dev/null 2>&1 || true
docker volume rm "$CANONICAL_LIVE_REDIS_VOLUME" \
    "$LAB_ID-restored-state-canonical-live-redis-env" >/dev/null 2>&1 || true

start_fresh_target canonical-live-postgres-env "$POSTGRES_IMAGE"
CANONICAL_LIVE_POSTGRES_TARGET=$STARTED_TARGET
CANONICAL_LIVE_POSTGRES_VOLUME=$STARTED_VOLUME
expect_failure candidate-live-postgres-endpoint canonical_restore_attempt \
    canonical-live-postgres-env "$CANONICAL_LIVE_POSTGRES_TARGET" \
    "$LAB_ID-candidate-canonical-live-postgres-env" 1 \
    "$LAB_ID-redis-canonical-live-postgres-env" "$SOURCE_CONTAINER"
if docker inspect "$LAB_ID-candidate-canonical-live-postgres-env" >/dev/null 2>&1; then
    printf '%s\n' 'live-PostgreSQL endpoint negative leaked candidate' >&2
    exit 1
fi
CANONICAL_LIVE_POSTGRES_DATABASE_COUNT=$(docker exec "$CANONICAL_LIVE_POSTGRES_TARGET" psql \
    --no-psqlrc --tuples-only --no-align --quiet --username postgres --dbname postgres \
    --command "SELECT count(*) FROM pg_database WHERE datname = 'coolify'" | tr -d '[:space:]')
[[ $CANONICAL_LIVE_POSTGRES_DATABASE_COUNT == 0 ]] \
    || { printf '%s\n' 'live-PostgreSQL endpoint negative leaked database' >&2; exit 1; }
docker rm -f "$CANONICAL_LIVE_POSTGRES_TARGET" >/dev/null 2>&1 || true
docker volume rm "$CANONICAL_LIVE_POSTGRES_VOLUME" \
    "$LAB_ID-restored-state-canonical-live-postgres-env" >/dev/null 2>&1 || true

start_fresh_target state-proof-mismatch "$POSTGRES_IMAGE"
STATE_PROOF_MISMATCH_TARGET=$STARTED_TARGET
STATE_PROOF_MISMATCH_VOLUME=$STARTED_VOLUME
expect_failure candidate-state-proof-mismatch restore_attempt state-proof-mismatch \
    "$STATE_PROOF_MISMATCH_TARGET" "$LAB_ID-candidate-state-proof-mismatch" \
    LAB_TAMPER_RESTORED_STATE
assert_failed_restore_cleanup state-proof-mismatch "$STATE_PROOF_MISMATCH_TARGET" \
    "$LAB_ID-candidate-state-proof-mismatch"
docker rm -f "$STATE_PROOF_MISMATCH_TARGET" >/dev/null 2>&1 || true
docker volume rm "$STATE_PROOF_MISMATCH_VOLUME" >/dev/null 2>&1 || true

start_fresh_target runtime-identity "$POSTGRES_IMAGE"
RUNTIME_IDENTITY_TARGET=$STARTED_TARGET
RUNTIME_IDENTITY_VOLUME=$STARTED_VOLUME
expect_failure wrong-candidate-runtime-identity restore_attempt runtime-identity \
    "$RUNTIME_IDENTITY_TARGET" "$LAB_ID-candidate-runtime-identity" \
    LAB_CANDIDATE_RUNTIME_IMAGE_OVERRIDE "$WRONG_POSTGRES_IMAGE"
assert_failed_restore_cleanup runtime-identity "$RUNTIME_IDENTITY_TARGET" \
    "$LAB_ID-candidate-runtime-identity"
docker rm -f "$LAB_ID-candidate-runtime-identity" "$RUNTIME_IDENTITY_TARGET" >/dev/null 2>&1 || true
docker volume rm "$RUNTIME_IDENTITY_VOLUME" >/dev/null 2>&1 || true

start_fresh_target stale-reap "$POSTGRES_IMAGE"
STALE_REAP_TARGET=$STARTED_TARGET
STALE_REAP_VOLUME=$STARTED_VOLUME
STALE_REAP_CANDIDATE="$LAB_ID-candidate-stale-reap"
STALE_RESOURCE_VOLUME="$LAB_ID-stale-candidate-state"
STALE_RESOURCE_NETWORK="$LAB_ID-stale-candidate-network"
STALE_RESTORE_STATE_VOLUME="$LAB_ID-stale-restored-state"
LAB_VOLUMES+=("$STALE_RESOURCE_VOLUME" "$STALE_RESTORE_STATE_VOLUME")
docker volume create \
    --label coolify.control-plane.backup-restore.resource=true \
    --label "coolify.control-plane.backup-restore.operation=$OPERATION_ID" \
    --label coolify.control-plane.backup-restore.created-unix=1 \
    "$STALE_RESOURCE_VOLUME" >/dev/null
docker volume create "$STALE_RESTORE_STATE_VOLUME" >/dev/null
docker network create \
    --label coolify.control-plane.backup-restore.resource=true \
    --label "coolify.control-plane.backup-restore.operation=$OPERATION_ID" \
    --label coolify.control-plane.backup-restore.created-unix=1 \
    "$STALE_RESOURCE_NETWORK" >/dev/null
# shellcheck disable=SC2016
run_tools bash --volume "$STALE_RESTORE_STATE_VOLUME:/stale-state" -- -c '
    mkdir -m 700 /stale-state/state
    printf "%s\n" "backup-restore-lab:1" > /stale-state/state/.backup-restore-operation
    printf "%s\n" stale > /stale-state/state/stale-plaintext
    chmod 400 /stale-state/state/.backup-restore-operation
'
docker exec "$STALE_REAP_TARGET" createdb --username postgres coolify
docker create --name "$STALE_REAP_CANDIDATE" \
    --label coolify.control-plane.backup-restore.candidate=true \
    --label "coolify.control-plane.backup-restore.operation=$OPERATION_ID" \
    --label coolify.control-plane.backup-restore.created-unix=1 \
    "$POSTGRES_IMAGE" >/dev/null
restore_attempt stale-reap "$STALE_REAP_TARGET" "$STALE_REAP_CANDIDATE" \
    '' 1 /plaintext "$STALE_RESTORE_STATE_VOLUME" >/dev/null
[[ -f $RUNTIME_DIRECTORY/attestations/failure-stale-reap.attestation \
    && $(docker inspect --format \
        '{{index .Config.Labels "coolify.control-plane.backup-restore.created-unix"}}' \
        "$STALE_REAP_CANDIDATE") != 1 \
    && $(docker exec "$STALE_REAP_CANDIDATE" stat -c '%u:%g:%a' \
        /run/secrets/control-plane-direct-probe-token) == 0:0:444 \
    && $(docker exec "$STALE_REAP_CANDIDATE" stat -c '%u:%g:%a' \
        /run/secrets/control-plane-applied-ack) == 0:0:444 \
    && -z $(docker volume ls --quiet --filter "name=^${STALE_RESOURCE_VOLUME}$") \
    && -z $(docker network ls --quiet --filter "name=^${STALE_RESOURCE_NETWORK}$") ]] \
    || { printf '%s\n' 'stale state/database/candidate resources were not reaped' >&2; exit 1; }
docker rm -f "$STALE_REAP_CANDIDATE" "$STALE_REAP_TARGET" >/dev/null 2>&1 || true
docker volume rm "$STALE_REAP_VOLUME" >/dev/null 2>&1 || true

docker stop "$SOURCE_CONTAINER" >/dev/null
SAME_IDENTITY_VOLUME="$LAB_ID-target-data-same-identity"
SAME_IDENTITY_TARGET="$LAB_ID-target-same-identity"
SAME_IDENTITY_CANDIDATE="$LAB_ID-candidate-same-identity"
LAB_VOLUMES+=("$SAME_IDENTITY_VOLUME")
LAB_CONTAINERS+=("$SAME_IDENTITY_TARGET" "$SAME_IDENTITY_CANDIDATE")
docker volume create "$SAME_IDENTITY_VOLUME" >/dev/null
docker run --rm --entrypoint sh \
    --volume "$SOURCE_VOLUME:/source:ro" --volume "$SAME_IDENTITY_VOLUME:/target" \
    "$POSTGRES_IMAGE" -ec 'cp -a /source/. /target/'
docker run --detach --name "$SAME_IDENTITY_TARGET" --network "$NETWORK" \
    --label coolify.control-plane.backup-restore.disposable=true \
    --label "coolify.control-plane.backup-restore.operation=$OPERATION_ID" \
    --env POSTGRES_HOST_AUTH_METHOD=trust \
    --volume "$SAME_IDENTITY_VOLUME:/var/lib/postgresql/data" "$POSTGRES_IMAGE" >/dev/null
wait_for_postgres "$SAME_IDENTITY_TARGET"
expect_failure same-postgres-system-identity restore_attempt same-identity \
    "$SAME_IDENTITY_TARGET" "$SAME_IDENTITY_CANDIDATE"

printf '%s\n' 'ControlPlaneBackupRestore happy-path: PASS'
printf '%s\n' 'ControlPlaneBackupRestore stale-power-loss-reap: PASS'
printf 'ControlPlaneBackupRestore concurrent-source-writes: %s\n' "$CONCURRENT_WRITE_COUNT"
printf 'ControlPlaneBackupRestore postgres-platform: %s\n' "$POSTGRES_PLATFORM"
printf 'ControlPlaneBackupRestore negative-count: %s\n' "$NEGATIVE_COUNT"
