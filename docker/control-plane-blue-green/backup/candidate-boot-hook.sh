#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

fail() { printf 'control-plane-candidate-boot-hook: %s\n' "$1" >&2; exit 1; }
usage()
{
    printf '%s\n' \
        'Required CONTROL_PLANE_* environment: operation/candidate/runtime container; restored state root+selection+proof tool/output; pinned canonical compose; root:root 0600 runtime env/probe token/ack; isolated candidate+live networks; green state/container/epochs/ports/probe path.' >&2
    exit 64
}
required() { [[ -n ${!1:-} ]] || { printf 'absent: %s\n' "$1" >&2; usage; }; }
runtime_env_value()
{
    local key=$1

    awk -F= -v requested_key="$key" '$1 == requested_key { print substr($0, length($1) + 2) }' \
        "$CONTROL_PLANE_RUNTIME_ENV_FILE"
}
ensure_owned_volume()
{
    local volume_name=$1 volume_role=$2 volume_created_unix

    if ! docker volume inspect "$volume_name" >/dev/null 2>&1; then
        docker volume create \
            --label coolify.control-plane.backup-restore.resource=true \
            --label "coolify.control-plane.backup-restore.operation=$CONTROL_PLANE_OPERATION_ID" \
            --label "coolify.control-plane.backup-restore.created-unix=$created_unix" \
            "$volume_name" >/dev/null
    fi

    volume_created_unix=$(docker volume inspect --format \
        '{{index .Labels "coolify.control-plane.backup-restore.created-unix"}}' "$volume_name")
    [[ $(docker volume inspect --format \
        '{{index .Labels "coolify.control-plane.backup-restore.resource"}}' \
        "$volume_name") == true \
        && $(docker volume inspect --format \
            '{{index .Labels "coolify.control-plane.backup-restore.operation"}}' \
            "$volume_name") == "$CONTROL_PLANE_OPERATION_ID" \
        && $volume_created_unix =~ ^[1-9][0-9]*$ ]] \
        || fail "candidate $volume_role volume exists without exact backup ownership labels"
}
initialize_candidate_volumes()
{
    ensure_owned_volume "$CONTROL_PLANE_GREEN_WEB_A_PRIVATE_VOLUME" private
    ensure_owned_volume "$CONTROL_PLANE_COORDINATION_VOLUME" coordination

    docker run --rm --user 0 --network none \
        --mount "type=volume,source=${CONTROL_PLANE_GREEN_WEB_A_PRIVATE_VOLUME},target=/private" \
        --entrypoint /bin/sh "$CONTROL_PLANE_CANDIDATE_IMAGE_DIGEST" -ec '
            test "$(id -u)" = 0
            test ! -L /private
            test -d /private
            for private_entry in /private/* /private/.[!.]* /private/..?*; do
                if [ -e "$private_entry" ] || [ -L "$private_entry" ]; then
                    exit 1
                fi
            done
            chown 0:9999 /private
            chmod 0750 /private
            sync /private
            test "$(stat -c %u:%g:%a /private)" = 0:9999:750
        ' >/dev/null \
        || fail 'candidate private marker volume is not exact and empty'

    docker run --rm --user 0 --network none \
        --mount "type=volume,source=${CONTROL_PLANE_COORDINATION_VOLUME},target=/coordination" \
        --entrypoint /bin/sh "$CONTROL_PLANE_CANDIDATE_IMAGE_DIGEST" -ec '
            test "$(id -u)" = 0
            test ! -L /coordination
            test -d /coordination
            lease=/coordination/mutation-inflight.lock
            if [ ! -e "$lease" ] && [ ! -L "$lease" ]; then
                for coordination_entry in /coordination/* /coordination/.[!.]* \
                    /coordination/..?*; do
                    if [ -e "$coordination_entry" ] || [ -L "$coordination_entry" ]; then
                        exit 1
                    fi
                done
                chown 0:9999 /coordination
                chmod 0750 /coordination
                lease_candidate=$(mktemp /coordination/.mutation-inflight.lock.initialize.XXXXXX)
                trap '\''if [ -n "${lease_candidate:-}" ]; then rm -f "$lease_candidate"; fi'\'' \
                    EXIT HUP INT TERM
                chown 0:9999 "$lease_candidate"
                chmod 0660 "$lease_candidate"
                test "$(stat -c %u:%g:%a:%h:%s "$lease_candidate")" = 0:9999:660:1:0
                sync "$lease_candidate"
                test ! -e "$lease"
                test ! -L "$lease"
                mv -f "$lease_candidate" "$lease"
                lease_candidate=
                sync /coordination
            fi
            test "$(stat -c %u:%g:%a /coordination)" = 0:9999:750
            test ! -L "$lease"
            test -f "$lease"
            test "$(stat -c %u:%g:%a:%h:%s "$lease")" = 0:9999:660:1:0
            lease_identity=$(stat -c %d:%i "$lease")
            exec 8< "$lease"
            test "$(stat -Lc %d:%i /proc/self/fd/8)" = "$lease_identity"
            for coordination_entry in /coordination/* /coordination/.[!.]* \
                /coordination/..?*; do
                if [ "$coordination_entry" != "$lease" ] \
                    && { [ -e "$coordination_entry" ] || [ -L "$coordination_entry" ]; }; then
                    exit 1
                fi
            done
            sync /coordination
            test "$(stat -c %d:%i "$lease")" = "$lease_identity"
        ' >/dev/null \
        || fail 'candidate coordination volume is not an exact root-owned lease volume'
}

cleanup()
{
    local exit_status=$?

    trap - EXIT HUP INT TERM
    if [[ ${boot_succeeded:-0} != 1 && ${candidate_network_owned:-0} == 1 ]]; then
        if [[ -n ${override:-} && -f $override ]]; then
            docker compose --ansi never --project-name \
                "$candidate_project" \
                --file "$CONTROL_PLANE_CANDIDATE_COMPOSE_FILE" --file "$override" \
                down --volumes >/dev/null 2>&1 || true
        fi
        docker rm -f "$CONTROL_PLANE_GREEN_CONTAINER" >/dev/null 2>&1 || true
        docker network disconnect --force "$CONTROL_PLANE_NETWORK" \
            "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER" >/dev/null 2>&1 || true
        docker network disconnect --force "$CONTROL_PLANE_NETWORK" \
            "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER" >/dev/null 2>&1 || true
        docker network rm "$CONTROL_PLANE_NETWORK" >/dev/null 2>&1 || true
        for owned_volume in "${CONTROL_PLANE_GREEN_WEB_A_PRIVATE_VOLUME:-}" \
            "${CONTROL_PLANE_COORDINATION_VOLUME:-}"; do
            [[ -n $owned_volume ]] || continue
            if [[ $(docker volume inspect --format \
                '{{index .Labels "coolify.control-plane.backup-restore.resource"}}' \
                "$owned_volume" 2>/dev/null || true) == true \
                && $(docker volume inspect --format \
                    '{{index .Labels "coolify.control-plane.backup-restore.operation"}}' \
                    "$owned_volume" 2>/dev/null || true) == "$CONTROL_PLANE_OPERATION_ID" ]]; then
                docker volume rm "$owned_volume" >/dev/null 2>&1 || true
            fi
        done
    fi
    [[ -z ${override:-} ]] || rm -f -- "$override"
    [[ -z ${compose_log:-} ]] || rm -f -- "$compose_log"
    exit "$exit_status"
}

for name in CONTROL_PLANE_OPERATION_ID CONTROL_PLANE_CANDIDATE_IMAGE_DIGEST \
    CONTROL_PLANE_RESTORED_STATE_ROOT CONTROL_PLANE_RESTORED_STATE_SELECTION \
    CONTROL_PLANE_STATE_PROOF_TOOL CONTROL_PLANE_CANDIDATE_STATE_PROOF_FILE \
    CONTROL_PLANE_RESTORE_DATABASE_CONTAINER \
    CONTROL_PLANE_SOURCE_REDIS_ENDPOINT CONTROL_PLANE_RESTORE_REDIS_CONTAINER \
    CONTROL_PLANE_RESTORE_REDIS_ENDPOINT CONTROL_PLANE_RESTORE_REDIS_NETWORK \
    CONTROL_PLANE_CANDIDATE_COMPOSE_FILE CONTROL_PLANE_EXPECTED_CANDIDATE_COMPOSE_SHA256 \
    CONTROL_PLANE_RUNTIME_ENV_FILE CONTROL_PLANE_NETWORK CONTROL_PLANE_LIVE_NETWORK \
    CONTROL_PLANE_GREEN_CONTAINER CONTROL_PLANE_CANDIDATE_RUNTIME_CONTAINER \
    CONTROL_PLANE_GREEN_STATE_VOLUME \
    CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE CONTROL_PLANE_GREEN_APPLIED_ACK_FILE \
    CONTROL_PLANE_WRITER_EPOCH CONTROL_PLANE_GREEN_WEB_EPOCH \
    CONTROL_PLANE_DIRECT_PROBE_PATH CONTROL_PLANE_BACKEND_PORT \
    CONTROL_PLANE_GREEN_LOOPBACK_PORT; do
    required "$name"
done
boot_succeeded=0
candidate_network_owned=0
override=
compose_log=
candidate_hash=$(printf '%s' "$CONTROL_PLANE_OPERATION_ID" \
    | sha256sum | awk '{print substr($1, 1, 20)}')
candidate_project="backup-candidate-$candidate_hash"
trap cleanup EXIT HUP INT TERM
[[ $CONTROL_PLANE_NETWORK != "$CONTROL_PLANE_LIVE_NETWORK" ]] \
    || fail 'candidate must use a network isolated from live control-plane credentials'
[[ $CONTROL_PLANE_GREEN_CONTAINER == "$CONTROL_PLANE_CANDIDATE_RUNTIME_CONTAINER" ]] \
    || fail 'canonical green container differs from the attested runtime container'
[[ $CONTROL_PLANE_GREEN_WEB_EPOCH =~ ^[A-Za-z0-9._:-]{16,128}$ ]] \
    || fail 'canonical green web epoch must contain 16 to 128 safe token characters'
[[ $CONTROL_PLANE_GREEN_LOOPBACK_PORT =~ ^[1-9][0-9]{0,4}$ \
    && $CONTROL_PLANE_GREEN_LOOPBACK_PORT -le 65532 ]] \
    || fail 'canonical green loopback port must leave room for four bounded members'
[[ $(runtime_env_value DB_HOST) == "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER" \
    || $(runtime_env_value PGHOST) == "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER" ]] \
    || fail 'candidate runtime environment does not point to the restored PostgreSQL clone'
[[ $(runtime_env_value REDIS_HOST) == "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER" \
    && $CONTROL_PLANE_RESTORE_REDIS_ENDPOINT == \
        "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER:6379" \
    && $CONTROL_PLANE_RESTORE_REDIS_ENDPOINT != "$CONTROL_PLANE_SOURCE_REDIS_ENDPOINT" ]] \
    || fail 'candidate runtime environment does not point to the restored Redis clone'
if docker network inspect "$CONTROL_PLANE_NETWORK" >/dev/null 2>&1; then
    [[ $(docker network inspect --format \
        '{{index .Labels "coolify.control-plane.backup-restore.resource"}}' \
        "$CONTROL_PLANE_NETWORK") == true \
        && $(docker network inspect --format \
            '{{index .Labels "coolify.control-plane.backup-restore.operation"}}' \
            "$CONTROL_PLANE_NETWORK") == "$CONTROL_PLANE_OPERATION_ID" \
        && $(docker network inspect --format \
            '{{index .Labels "coolify.control-plane.backup-restore.created-unix"}}' \
            "$CONTROL_PLANE_NETWORK") =~ ^[1-9][0-9]*$ \
        && $(docker network inspect --format '{{.Internal}}' \
            "$CONTROL_PLANE_NETWORK") == true ]] \
        || fail 'candidate network exists without exact backup ownership labels'
    candidate_network_owned=1
else
    docker network create --internal \
        --label coolify.control-plane.backup-restore.resource=true \
        --label "coolify.control-plane.backup-restore.operation=$CONTROL_PLANE_OPERATION_ID" \
        --label "coolify.control-plane.backup-restore.created-unix=$(date +%s)" \
        "$CONTROL_PLANE_NETWORK" >/dev/null
    candidate_network_owned=1
fi
target_database_id=$(docker inspect --format '{{.Id}}' "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER")
target_redis_id=$(docker inspect --format '{{.Id}}' "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER")
if ! docker network inspect --format '{{range .Containers}}{{println .Name}}{{end}}' \
    "$CONTROL_PLANE_NETWORK" | grep -Fqx "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER"; then
    docker network connect "$CONTROL_PLANE_NETWORK" "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER" \
        >/dev/null || fail 'restored database could not join the isolated candidate network'
fi
if ! docker network inspect --format '{{range .Containers}}{{println .Name}}{{end}}' \
    "$CONTROL_PLANE_NETWORK" | grep -Fqx "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER"; then
    docker network connect "$CONTROL_PLANE_NETWORK" "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER" \
        >/dev/null || fail 'restored Redis could not join the isolated candidate network'
fi
[[ $target_database_id =~ ^[0-9a-f]{64}$ && $target_redis_id =~ ^[0-9a-f]{64}$ ]] \
    || fail 'restored database or Redis container identity is invalid'
[[ $CONTROL_PLANE_CANDIDATE_COMPOSE_FILE == /* \
    && -f $CONTROL_PLANE_CANDIDATE_COMPOSE_FILE \
    && ! -L $CONTROL_PLANE_CANDIDATE_COMPOSE_FILE ]] \
    || fail 'canonical candidate compose file is unsafe or unavailable'
[[ $(sha256sum "$CONTROL_PLANE_CANDIDATE_COMPOSE_FILE" | awk '{print $1}') \
    == "$CONTROL_PLANE_EXPECTED_CANDIDATE_COMPOSE_SHA256" ]] \
    || fail 'canonical candidate compose file differs from its governed digest'
for protected_file in "$CONTROL_PLANE_RUNTIME_ENV_FILE" \
    "$CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE" "$CONTROL_PLANE_GREEN_APPLIED_ACK_FILE"; do
    [[ $protected_file == /* && -f $protected_file && ! -L $protected_file \
        && $(stat -c '%u:%g:%a' "$protected_file") == 0:0:600 ]] \
        || fail 'candidate runtime secret input must be root:root mode 0600'
done
[[ -d $CONTROL_PLANE_RESTORED_STATE_ROOT && ! -L $CONTROL_PLANE_RESTORED_STATE_ROOT \
    && $(stat -c '%u:%g:%a' "$CONTROL_PLANE_RESTORED_STATE_ROOT") == 0:0:700 ]] \
    || fail 'restored state root must remain root:root mode 0700'
for selected in ssh applications databases services; do
    [[ ,$CONTROL_PLANE_RESTORED_STATE_SELECTION, == *",$selected,"* \
        && -d $CONTROL_PLANE_RESTORED_STATE_ROOT/$selected \
        && ! -L $CONTROL_PLANE_RESTORED_STATE_ROOT/$selected ]] \
        || fail "restored state selection is missing canonical path: $selected"
done
[[ -d $CONTROL_PLANE_RESTORED_STATE_ROOT/backups \
    && $(stat -c '%u:%g:%a' "$CONTROL_PLANE_RESTORED_STATE_ROOT/backups") == 9999:9999:700 \
    && -z $(find "$CONTROL_PLANE_RESTORED_STATE_ROOT/backups" -mindepth 1 -print -quit) ]] \
    || fail 'candidate disposable backup directory is not empty service-owned mode 0700'

override=$(mktemp "$(dirname "$CONTROL_PLANE_CANDIDATE_STATE_PROOF_FILE")/candidate.XXXXXX.yaml")
created_unix=$(date +%s)
{
    printf 'services:\n  green-web-a:\n    labels:\n'
    printf '      coolify.control-plane.backup-restore.candidate: "true"\n'
    printf '      coolify.control-plane.backup-restore.operation: "%s"\n' \
        "$CONTROL_PLANE_OPERATION_ID"
    printf '      coolify.control-plane.backup-restore.created-unix: "%s"\n' "$created_unix"
    printf '    environment:\n      CONTROL_PLANE_RESTORED_STATE_SELECTION: "%s"\n' \
        "$CONTROL_PLANE_RESTORED_STATE_SELECTION"
    printf '    volumes:\n'
    if [[ -n ${CONTROL_PLANE_RESTORED_STATE_DOCKER_VOLUME:-} ]]; then
        [[ $(docker volume inspect --format \
            '{{index .Labels "coolify.control-plane.backup-restore.restored-state"}}' \
            "$CONTROL_PLANE_RESTORED_STATE_DOCKER_VOLUME") == true \
            && $(docker volume inspect --format \
                '{{index .Labels "coolify.control-plane.backup-restore.operation"}}' \
                "$CONTROL_PLANE_RESTORED_STATE_DOCKER_VOLUME") == "$CONTROL_PLANE_OPERATION_ID" ]] \
            || fail 'restored-state Docker volume lacks exact operation ownership labels'
        for selected in ssh applications databases services backups; do
            printf '      - type: volume\n        source: restored-state\n        target: /restored-state/%s\n        read_only: true\n        volume:\n          subpath: state/%s\n' \
                "$selected" "$selected"
            printf '      - type: volume\n        source: restored-state\n        target: /var/www/html/storage/app/%s\n        volume:\n          subpath: state/%s\n' \
                "$selected" "$selected"
        done
    else
        for selected in ssh applications databases services backups; do
            printf '      - type: bind\n        source: "%s/%s"\n        target: /restored-state/%s\n        read_only: true\n' \
                "$CONTROL_PLANE_RESTORED_STATE_ROOT" "$selected" "$selected"
        done
    fi
    printf '      - type: bind\n        source: "%s"\n        target: /usr/local/bin/control-plane-state-proof\n        read_only: true\n' \
        "$CONTROL_PLANE_STATE_PROOF_TOOL"
    printf 'volumes:\n  green-web-a-private:\n    labels:\n'
    printf '      coolify.control-plane.backup-restore.resource: "true"\n'
    printf '      coolify.control-plane.backup-restore.operation: "%s"\n' \
        "$CONTROL_PLANE_OPERATION_ID"
    printf '      coolify.control-plane.backup-restore.created-unix: "%s"\n' "$created_unix"
    printf '  control-plane-coordination:\n    labels:\n'
    printf '      coolify.control-plane.backup-restore.resource: "true"\n'
    printf '      coolify.control-plane.backup-restore.operation: "%s"\n' \
        "$CONTROL_PLANE_OPERATION_ID"
    printf '      coolify.control-plane.backup-restore.created-unix: "%s"\n' "$created_unix"
    if [[ -n ${CONTROL_PLANE_RESTORED_STATE_DOCKER_VOLUME:-} ]]; then
        printf '  restored-state:\n    external: true\n    name: "%s"\n' \
            "$CONTROL_PLANE_RESTORED_STATE_DOCKER_VOLUME"
    fi
} > "$override"
chmod 600 "$override"
compose_log=$(mktemp "$(dirname "$CONTROL_PLANE_CANDIDATE_STATE_PROOF_FILE")/candidate.XXXXXX.log")
chmod 600 "$compose_log"

export CONTROL_PLANE_GREEN_IMAGE="$CONTROL_PLANE_CANDIDATE_IMAGE_DIGEST"
export CONTROL_PLANE_BLUE_IMAGE="$CONTROL_PLANE_CANDIDATE_IMAGE_DIGEST"
export CONTROL_PLANE_GREEN_WEB_A_CONTAINER="$CONTROL_PLANE_GREEN_CONTAINER"
export CONTROL_PLANE_GREEN_WEB_B_CONTAINER="backup-unused-green-web-b-$candidate_hash"
export CONTROL_PLANE_BLUE_WEB_A_CONTAINER="backup-unused-blue-web-a-$candidate_hash"
export CONTROL_PLANE_BLUE_WEB_B_CONTAINER="backup-unused-blue-web-b-$candidate_hash"
export CONTROL_PLANE_GREEN_WEB_A_MEMBER_ID="backup-green-web-a-$candidate_hash"
export CONTROL_PLANE_GREEN_WEB_B_MEMBER_ID="backup-green-web-b-$candidate_hash"
export CONTROL_PLANE_BLUE_WEB_A_MEMBER_ID="backup-blue-web-a-$candidate_hash"
export CONTROL_PLANE_BLUE_WEB_B_MEMBER_ID="backup-blue-web-b-$candidate_hash"
export CONTROL_PLANE_GREEN_WEB_A_WEB_EPOCH="$CONTROL_PLANE_GREEN_WEB_EPOCH"
export CONTROL_PLANE_GREEN_WEB_B_WEB_EPOCH="backup-green-web-b-$candidate_hash"
export CONTROL_PLANE_BLUE_WEB_A_WEB_EPOCH="backup-blue-web-a-$candidate_hash"
export CONTROL_PLANE_BLUE_WEB_B_WEB_EPOCH="backup-blue-web-b-$candidate_hash"
export CONTROL_PLANE_GREEN_WEB_A_ROUTE_DRAIN_EPOCH="backup-green-web-a-drain-$candidate_hash"
export CONTROL_PLANE_GREEN_WEB_B_ROUTE_DRAIN_EPOCH="backup-green-web-b-drain-$candidate_hash"
export CONTROL_PLANE_BLUE_WEB_A_ROUTE_DRAIN_EPOCH="backup-blue-web-a-drain-$candidate_hash"
export CONTROL_PLANE_BLUE_WEB_B_ROUTE_DRAIN_EPOCH="backup-blue-web-b-drain-$candidate_hash"
export CONTROL_PLANE_MUTATION_FREEZE_EPOCH="backup-candidate-freeze-$candidate_hash"
export CONTROL_PLANE_GREEN_WEB_A_LOOPBACK_PORT="$CONTROL_PLANE_GREEN_LOOPBACK_PORT"
export CONTROL_PLANE_GREEN_WEB_B_LOOPBACK_PORT="$((CONTROL_PLANE_GREEN_LOOPBACK_PORT + 1))"
export CONTROL_PLANE_BLUE_WEB_A_LOOPBACK_PORT="$((CONTROL_PLANE_GREEN_LOOPBACK_PORT + 2))"
export CONTROL_PLANE_BLUE_WEB_B_LOOPBACK_PORT="$((CONTROL_PLANE_GREEN_LOOPBACK_PORT + 3))"
export CONTROL_PLANE_GREEN_WEB_A_PRIVATE_VOLUME="$CONTROL_PLANE_GREEN_STATE_VOLUME"
export CONTROL_PLANE_GREEN_WEB_B_PRIVATE_VOLUME="backup-unused-green-web-b-state-$candidate_hash"
export CONTROL_PLANE_BLUE_WEB_A_PRIVATE_VOLUME="backup-unused-blue-web-a-state-$candidate_hash"
export CONTROL_PLANE_BLUE_WEB_B_PRIVATE_VOLUME="backup-unused-blue-web-b-state-$candidate_hash"
export CONTROL_PLANE_COORDINATION_VOLUME="backup-candidate-coordination-$candidate_hash"
export CONTROL_PLANE_GREEN_WEB_A_DIRECT_PROBE_RUNTIME_FILE="$CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE"
export CONTROL_PLANE_GREEN_WEB_B_DIRECT_PROBE_RUNTIME_FILE="$CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE"
export CONTROL_PLANE_BLUE_WEB_A_DIRECT_PROBE_RUNTIME_FILE="$CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE"
export CONTROL_PLANE_BLUE_WEB_B_DIRECT_PROBE_RUNTIME_FILE="$CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE"
export CONTROL_PLANE_GREEN_WEB_A_APPLIED_ACK_RUNTIME_FILE="$CONTROL_PLANE_GREEN_APPLIED_ACK_FILE"
export CONTROL_PLANE_GREEN_WEB_B_APPLIED_ACK_RUNTIME_FILE="$CONTROL_PLANE_GREEN_APPLIED_ACK_FILE"
export CONTROL_PLANE_BLUE_WEB_A_APPLIED_ACK_RUNTIME_FILE="$CONTROL_PLANE_GREEN_APPLIED_ACK_FILE"
export CONTROL_PLANE_BLUE_WEB_B_APPLIED_ACK_RUNTIME_FILE="$CONTROL_PLANE_GREEN_APPLIED_ACK_FILE"
export CONTROL_PLANE_GREEN_ROUTE_HEALTH_RUNTIME_FILE="$CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE"
export CONTROL_PLANE_GREEN_POOL_ACK_RUNTIME_FILE="$CONTROL_PLANE_GREEN_APPLIED_ACK_FILE"
export CONTROL_PLANE_BLUE_ROUTE_HEALTH_RUNTIME_FILE="$CONTROL_PLANE_GREEN_DIRECT_PROBE_TOKEN_FILE"
export CONTROL_PLANE_BLUE_POOL_ACK_RUNTIME_FILE="$CONTROL_PLANE_GREEN_APPLIED_ACK_FILE"
# The isolated candidate has no Traefik route. Keep its label token public and
# deliberately unmatched so the direct-probe credential never enters metadata.
export CONTROL_PLANE_GREEN_ROUTE_HEALTH_TOKEN="backup-route-health-$candidate_hash"
export CONTROL_PLANE_BLUE_ROUTE_HEALTH_TOKEN="$CONTROL_PLANE_GREEN_ROUTE_HEALTH_TOKEN"
export CONTROL_PLANE_POOL_LABEL_KEY=coolify.control-plane.pool
export CONTROL_PLANE_GREEN_POOL_LABEL_VALUE="backup-green-$CONTROL_PLANE_OPERATION_ID"
export CONTROL_PLANE_BLUE_POOL_LABEL_VALUE="backup-blue-$CONTROL_PLANE_OPERATION_ID"
export CONTROL_PLANE_HOST=restore-candidate.invalid
export CONTROL_PLANE_TRUSTED_PROXY_ADDRESSES=127.0.0.1
export CONTROL_PLANE_SSH_DIRECTORY="$CONTROL_PLANE_RESTORED_STATE_ROOT/ssh"
export CONTROL_PLANE_APPLICATIONS_DIRECTORY="$CONTROL_PLANE_RESTORED_STATE_ROOT/applications"
export CONTROL_PLANE_DATABASES_DIRECTORY="$CONTROL_PLANE_RESTORED_STATE_ROOT/databases"
export CONTROL_PLANE_SERVICES_DIRECTORY="$CONTROL_PLANE_RESTORED_STATE_ROOT/services"
export CONTROL_PLANE_BACKUPS_DIRECTORY="$CONTROL_PLANE_RESTORED_STATE_ROOT/backups"
unset CONTROL_PLANE_GREEN_WEB_A_WRITER_EPOCH CONTROL_PLANE_GREEN_WEB_A_WRITER_MARKER_PATH
unset CONTROL_PLANE_GREEN_WEB_B_WRITER_EPOCH CONTROL_PLANE_GREEN_WEB_B_WRITER_MARKER_PATH
unset CONTROL_PLANE_BLUE_WEB_A_WRITER_EPOCH CONTROL_PLANE_BLUE_WEB_A_WRITER_MARKER_PATH
unset CONTROL_PLANE_BLUE_WEB_B_WRITER_EPOCH CONTROL_PLANE_BLUE_WEB_B_WRITER_MARKER_PATH
initialize_candidate_volumes
if ! docker compose --ansi never --project-name "$candidate_project" \
    --file "$CONTROL_PLANE_CANDIDATE_COMPOSE_FILE" --file "$override" \
    up --detach --no-deps --pull never green-web-a >"$compose_log" 2>&1; then
    sed -n '1,20p' "$compose_log" >&2
    docker compose --ansi never --project-name "$candidate_project" \
        --file "$CONTROL_PLANE_CANDIDATE_COMPOSE_FILE" --file "$override" \
        down --volumes >/dev/null 2>&1 || true
    docker network disconnect --force "$CONTROL_PLANE_NETWORK" \
        "$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER" >/dev/null 2>&1 || true
    docker network disconnect --force "$CONTROL_PLANE_NETWORK" \
        "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER" >/dev/null 2>&1 || true
    docker network rm "$CONTROL_PLANE_NETWORK" >/dev/null 2>&1 || true
    fail 'canonical candidate failed to boot'
fi
[[ $(docker inspect --format '{{.State.Running}}' "$CONTROL_PLANE_GREEN_CONTAINER") == true ]] \
    || fail 'canonical candidate is not running'
boot_succeeded=1
printf '%s\n' 'control-plane-candidate-boot=passed'
