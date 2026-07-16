#!/usr/bin/env bash

set -Eeuo pipefail

report_candidate_failure()
{
    if docker inspect "$LAB_CANDIDATE_CONTAINER" >/dev/null 2>&1; then
        docker inspect --format \
            'candidate-state={{.State.Status}} exit={{.State.ExitCode}} error={{.State.Error}}' \
            "$LAB_CANDIDATE_CONTAINER" >&2 || true
        docker logs --tail 20 "$LAB_CANDIDATE_CONTAINER" >&2 || true
    fi
    exit 70
}

[[ ${LAB_FAIL_CANDIDATE_PROBE:-0} != 1 ]] || report_candidate_failure
: "${LAB_DIRECT_PROBE_TOKEN_FILE:?}"
: "${LAB_APPLIED_ACK_FILE:?}"
probe_token=$(<"$LAB_DIRECT_PROBE_TOKEN_FILE")
expected_ack=$(<"$LAB_APPLIED_ACK_FILE")
if [[ ${LAB_TAMPER_RESTORED_STATE:-0} == 1 ]]; then
    printf '%s\n' 'candidate-tamper=true' \
        >> "$CONTROL_PLANE_RESTORED_STATE_ROOT/applications/operation-state"
fi

for _ in $(seq 1 30); do
    observed=$(docker exec "$LAB_CANDIDATE_CONTAINER" curl --silent --show-error \
        --dump-header - --output /dev/null \
        --header "X-Control-Plane-Probe: $probe_token" \
        http://127.0.0.1:8080/api/control-plane/probe 2>/dev/null || true)
    observed_status=$(printf '%s\n' "$observed" \
        | awk 'NR == 1 { sub(/\r$/, ""); print $2 }')
    observed_ack=$(printf '%s\n' "$observed" \
        | awk -F': ' 'tolower($1) == "x-control-plane-applied-config" {
            sub(/\r$/, "", $2); print $2
        }')
    if [[ $observed_status == 204 && $observed_ack == "$expected_ack" ]]; then
        state_proof=$(docker exec "$LAB_CANDIDATE_CONTAINER" \
            /usr/local/bin/control-plane-state-proof /restored-state \
            "$CONTROL_PLANE_RESTORED_STATE_SELECTION")
        [[ $state_proof == \
            "control_plane_state_proof_sha256=$CONTROL_PLANE_RESTORED_STATE_PROOF_SHA256" ]] \
            || report_candidate_failure
        printf '%s\n' "$state_proof" > "$CONTROL_PLANE_CANDIDATE_STATE_PROOF_FILE"
        chmod 400 "$CONTROL_PLANE_CANDIDATE_STATE_PROOF_FILE"
        printf '%s\n' 'control-plane-candidate-api-probe=passed'
        exit 0
    fi
    sleep 1
done

report_candidate_failure
