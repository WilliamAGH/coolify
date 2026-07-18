#!/usr/bin/env bash

set -Eeuo pipefail

[[ ${LAB_FAIL_CANDIDATE_BOOT:-0} != 1 ]] || exit 70
: "${LAB_DIRECT_PROBE_TOKEN_FILE:?}"
: "${LAB_APPLIED_ACK_FILE:?}"
runtime_image=${LAB_CANDIDATE_RUNTIME_IMAGE_OVERRIDE:-$CONTROL_PLANE_CANDIDATE_IMAGE_DIGEST}
restored_state_mounts=()
candidate_secret_directory=
boot_succeeded=0

# shellcheck disable=SC2317,SC2329 # Invoked by the EXIT and signal traps below.
cleanup()
{
    local exit_status=$?

    trap - EXIT HUP INT TERM
    if [[ ${boot_succeeded:-0} != 1 && -n $candidate_secret_directory ]]; then
        rm -rf -- "$candidate_secret_directory"
    fi
    exit "$exit_status"
}

stage_candidate_secret()
{
    local source_file=$1 staged_file=$2

    cp -- "$source_file" "$staged_file"
    chmod 0444 "$staged_file"
    [[ $(stat -c '%u:%g:%a' "$staged_file") == 0:0:444 \
        && $(sha256sum "$staged_file" | awk '{print $1}') == \
            $(sha256sum "$source_file" | awk '{print $1}') ]]
}

candidate_secret_directory=$(mktemp -d \
    "$LAB_HOST_RUNTIME_DIRECTORY/.lab-candidate-secrets.XXXXXX")
chmod 0700 "$candidate_secret_directory"
trap cleanup EXIT HUP INT TERM
candidate_direct_probe_token_file="$candidate_secret_directory/direct-probe-token"
candidate_applied_ack_file="$candidate_secret_directory/applied-ack"
stage_candidate_secret "$LAB_DIRECT_PROBE_TOKEN_FILE" "$candidate_direct_probe_token_file"
stage_candidate_secret "$LAB_APPLIED_ACK_FILE" "$candidate_applied_ack_file"

if [[ -n ${LAB_RESTORED_STATE_VOLUME:-} ]]; then
    for selected in ssh applications databases services backups; do
        restored_state_mounts+=(--mount \
            "type=volume,src=$LAB_RESTORED_STATE_VOLUME,dst=/restored-state/$selected,volume-subpath=state/$selected,readonly")
    done
else
    restored_state_host_root=${CONTROL_PLANE_RESTORED_STATE_ROOT/#\/lab/$LAB_HOST_RUNTIME_DIRECTORY}
    restored_state_mounts+=(--mount \
        "type=bind,src=$restored_state_host_root,dst=/restored-state,readonly")
fi

if ! docker network inspect --format '{{range .Containers}}{{println .Name}}{{end}}' \
    "$LAB_NETWORK" | grep -Fqx "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER"; then
    docker network connect "$LAB_NETWORK" "$CONTROL_PLANE_RESTORE_REDIS_CONTAINER" >/dev/null
fi

docker create --name "$LAB_CANDIDATE_CONTAINER" --network "$LAB_NETWORK" \
    --label coolify.control-plane.backup-restore.candidate=true \
    --label "coolify.control-plane.backup-restore.operation=$CONTROL_PLANE_OPERATION_ID" \
    --label "coolify.control-plane.backup-restore.created-unix=$(date +%s)" \
    --env "PGHOST=$CONTROL_PLANE_RESTORE_DATABASE_CONTAINER" \
    --env "PGUSER=$CONTROL_PLANE_RESTORE_DATABASE_USER" \
    --env "PGDATABASE=$CONTROL_PLANE_RESTORE_DATABASE_NAME" \
    --env "REDIS_HOST=$CONTROL_PLANE_RESTORE_REDIS_CONTAINER" \
    --env CONTROL_PLANE_STARTUP_MODE=web-only \
    --env "CONTROL_PLANE_RESTORED_STATE_SELECTION=$CONTROL_PLANE_RESTORED_STATE_SELECTION" \
    --mount "type=bind,src=$candidate_direct_probe_token_file,dst=/run/secrets/control-plane-direct-probe-token,readonly" \
    --mount "type=bind,src=$candidate_applied_ack_file,dst=/run/secrets/control-plane-applied-ack,readonly" \
    "${restored_state_mounts[@]}" \
    --entrypoint /usr/local/bin/candidate-entrypoint \
    "$runtime_image" >/dev/null
docker cp "$LAB_CANDIDATE_ENTRYPOINT" \
    "$LAB_CANDIDATE_CONTAINER:/usr/local/bin/candidate-entrypoint"
docker cp "$CONTROL_PLANE_STATE_PROOF_TOOL" \
    "$LAB_CANDIDATE_CONTAINER:/usr/local/bin/control-plane-state-proof"
docker start "$LAB_CANDIDATE_CONTAINER" >/dev/null

for _ in $(seq 1 30); do
    if [[ $(docker inspect --format '{{.State.Running}}' "$LAB_CANDIDATE_CONTAINER") == true ]]; then
        boot_succeeded=1
        printf '%s\n' 'control-plane-candidate-boot=passed'
        exit 0
    fi
    sleep 1
done

exit 70
