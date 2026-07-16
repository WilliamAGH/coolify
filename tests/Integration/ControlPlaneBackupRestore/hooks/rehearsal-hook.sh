#!/usr/bin/env bash

set -Eeuo pipefail

[[ ${LAB_FAIL_REHEARSAL:-0} != 1 ]] || exit 70

if ! docker network inspect --format '{{range .Containers}}{{println .Name}}{{end}}' \
    "$LAB_NETWORK" | grep -Fqx "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER"; then
    docker network connect "$LAB_NETWORK" "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER" >/dev/null
fi
redis_password=$(cat "$CONTROL_PLANE_RESTORE_REDIS_PASSWORD_FILE")
redis_queue_proof=$(docker run --rm --network "$LAB_NETWORK" \
    --entrypoint sh --env "REDISCLI_AUTH=$redis_password" \
    "$CONTROL_PLANE_SOURCE_REDIS_IMAGE_DIGEST" -ec \
    'printf "%s:%s:%s\n" \
        "$(redis-cli --no-auth-warning -h "$1" llen queues:deploy)" \
        "$(redis-cli --no-auth-warning -h "$1" zcard queues:deploy:delayed)" \
        "$(redis-cli --no-auth-warning -h "$1" get cache:control-plane)"' \
    sh "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER")
[[ $redis_queue_proof == 2:1:captured-cache ]] || exit 70

docker run --rm --network "$LAB_NETWORK" \
    --entrypoint psql \
    --env "PGHOST=$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER" \
    --env "PGUSER=$CONTROL_PLANE_RESTORE_DATABASE_USER" \
    --env "PGDATABASE=$CONTROL_PLANE_RESTORE_DATABASE_NAME" \
    "$CONTROL_PLANE_CANDIDATE_IMAGE_DIGEST" \
    --no-psqlrc --quiet --set ON_ERROR_STOP=1 \
    --command 'ALTER TABLE control_plane_widget ADD COLUMN rehearsal_verified boolean NOT NULL DEFAULT true' \
    >/dev/null

printf '%s\n' 'control-plane-restore-rehearsal=passed'
