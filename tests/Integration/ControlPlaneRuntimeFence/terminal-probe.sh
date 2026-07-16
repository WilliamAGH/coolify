#!/usr/bin/env bash
set -euo pipefail
fixture_root=${FIXTURE_ROOT:-/run}
if [[ -f $fixture_root/terminal-delay-seconds ]]; then
    sleep "$(<"$fixture_root/terminal-delay-seconds")"
fi
manifest_value()
{
    sed -n "s/^$1=//p" "$CONTROL_PLANE_INGRESS_POOL_MANIFEST"
}

active_web_a_id=$(docker inspect --format '{{.Id}}' candidate)
active_web_b_id=$(docker inspect --format '{{.Id}}' candidate-b)
pool_ack_sha256=$(manifest_value pool_ack_sha256)
queue_output=$(mktemp)
trap 'rm -f -- "$queue_output"' EXIT HUP INT TERM
CONTROL_PLANE_RUNTIME_OPERATION_ID="$CONTROL_PLANE_RUNTIME_OPERATION_ID" \
CONTROL_PLANE_RUNTIME_QUEUE_CONTAINER=candidate \
    "$CONTROL_PLANE_RUNTIME_QUEUE_PROBE" > "$queue_output"
queue_value()
{
    sed -n "s/^$1=//p" "$queue_output"
}
queue_evidence_sha256=$(sha256sum "$queue_output" | awk '{print $1}')
printf 'version=3\nterminal_state=passed\nobserved_at_epoch=%s\noperation_id=%s\n' \
    "$(date -u +%s)" "$CONTROL_PLANE_RUNTIME_OPERATION_ID"
printf 'semantic_config_sha256=%s\n' "$CONTROL_PLANE_RUNTIME_SEMANTIC_CONFIG_SHA256"
printf 'active_web_a_id=%s\nactive_web_b_id=%s\n' "$active_web_a_id" "$active_web_b_id"
printf 'pool_manifest_sha256=%s\npool_plan_manifest_sha256=%s\npool_generation=%s\n' \
    "$CONTROL_PLANE_INGRESS_POOL_MANIFEST_SHA256" \
    "$CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256" "$(manifest_value generation)"
printf 'pool_member_set_sha256=%s\nhttps_pool_ack_sha256=%s\nport8000_pool_ack_sha256=%s\n' \
    "$(manifest_value member_set_sha256)" "$pool_ack_sha256" "$pool_ack_sha256"
printf 'retired_incumbent_set=absent\nprovider_fresh=passed\nprovider_snapshot_sha256=%064d\n' 0
printf 'queue_zero=passed\nqueue_observed_at_epoch=%s\nqueue_probe_sha256=%s\n' \
    "$(queue_value observed_at_epoch)" "$CONTROL_PLANE_RUNTIME_QUEUE_PROBE_SHA256"
printf 'queue_evidence_sha256=%s\nqueue_pending=%s\nqueue_reserved=%s\n' \
    "$queue_evidence_sha256" "$(queue_value pending)" "$(queue_value reserved)"
printf 'queue_delayed=%s\nqueue_running=%s\nmutation_freeze_epoch=%s\n' \
    "$(queue_value delayed)" "$(queue_value running)" \
    "$(sed -n 's/^mutation_freeze_epoch=//p' "$CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST")"
