pool_fixture_sha256()
{
    sha256sum "$1" | awk '{print $1}'
}

pool_fixture_metadata()
{
    local path=$1 mode=${2:-600}
    printf '%s:%s:%s:%s\n' "$POOL_FIXTURE_OWNER_UID" "$POOL_FIXTURE_OWNER_GID" "$mode" \
        "$(wc -c < "$path" | tr -d '[:space:]')"
}

pool_fixture_runtime_metadata()
{
    local path=$1
    printf '%s:%s:400:%s\n' "$POOL_FIXTURE_RUNTIME_UID" "$POOL_FIXTURE_RUNTIME_GID" \
        "$(wc -c < "$path" | tr -d '[:space:]')"
}

pool_fixture_plan_value()
{
    sed -n "s/^$1=//p" "$POOL_FIXTURE_POOL_PLAN_MANIFEST"
}

pool_fixture_ingress_value()
{
    sed -n "s/^$1=//p" "$POOL_FIXTURE_INGRESS_POOL_MANIFEST"
}

pool_fixture_plan_member_keys()
{
    printf '%s\n' role name route_identity image_reference image_id network_ids \
        private_volume expected_loopback_port repin_intent_file \
        direct_probe_token_file direct_probe_token_sha256 direct_probe_token_metadata \
        direct_probe_runtime_file direct_probe_runtime_sha256 direct_probe_runtime_metadata \
        applied_ack_file applied_ack_sha256 applied_ack_metadata \
        applied_ack_runtime_file applied_ack_runtime_sha256 applied_ack_runtime_metadata \
        web_epoch web_marker_path route_drain_epoch route_drain_marker_path writer_epoch \
        writer_marker_path
}

pool_fixture_ingress_member_keys()
{
    printf '%s\n' role name route_identity id address port image_reference image_id \
        runtime_sha256 network_sha256 bindings_sha256 applied_ack_file \
        applied_ack_sha256 applied_ack_metadata
}

pool_fixture_plan_set_bytes()
{
    local member key
    for member in a b; do
        while IFS= read -r key; do
            printf '%s\0' "$(pool_fixture_plan_value "member_${member}_${key}")"
        done < <(pool_fixture_plan_member_keys)
    done
    printf '%s\0' "$(pool_fixture_plan_value writer_member)"
}

pool_fixture_ingress_member_set_bytes()
{
    local member key
    printf '%s\0' "$(pool_fixture_ingress_value generation)" \
        "$(pool_fixture_ingress_value parent_pool_plan_sha256)"
    for member in a b; do
        while IFS= read -r key; do
            printf '%s\0' "$(pool_fixture_ingress_value "member_${member}_${key}")"
        done < <(pool_fixture_ingress_member_keys)
    done
    printf '%s\0' "$(pool_fixture_ingress_value writer_member)" \
        "$(pool_fixture_ingress_value pool_label_key)" \
        "$(pool_fixture_ingress_value pool_label_value)"
}

pool_fixture_write_plan_member()
{
    local member=$1 role=$2 name=$3 route_identity=$4 image_reference=$5 image_id=$6
    local private_volume=$7 loopback_port=$8 direct_probe_token=$9 direct_probe_runtime=${10}
    local applied_ack=${11} applied_ack_runtime=${12}
    local web_epoch=${13} route_drain_epoch=${14} writer_epoch=${15} writer_marker=${16}

    printf 'member_%s_role=%s\n' "$member" "$role"
    printf 'member_%s_name=%s\n' "$member" "$name"
    printf 'member_%s_route_identity=%s\n' "$member" "$route_identity"
    printf 'member_%s_image_reference=%s\n' "$member" "$image_reference"
    printf 'member_%s_image_id=%s\n' "$member" "$image_id"
    printf 'member_%s_network_ids=%s\n' "$member" "$POOL_FIXTURE_NETWORK_ID"
    printf 'member_%s_private_volume=%s\n' "$member" "$private_volume"
    printf 'member_%s_expected_loopback_port=%s\n' "$member" "$loopback_port"
    printf 'member_%s_repin_intent_file=%s/member-%s-repin.intent\n' \
        "$member" "$POOL_FIXTURE_ROOT" "$member"
    printf 'member_%s_direct_probe_token_file=%s\n' "$member" "$direct_probe_token"
    printf 'member_%s_direct_probe_token_sha256=%s\n' \
        "$member" "$(pool_fixture_sha256 "$direct_probe_token")"
    printf 'member_%s_direct_probe_token_metadata=%s\n' \
        "$member" "$(pool_fixture_metadata "$direct_probe_token")"
    printf 'member_%s_direct_probe_runtime_file=%s\n' "$member" "$direct_probe_runtime"
    printf 'member_%s_direct_probe_runtime_sha256=%s\n' \
        "$member" "$(pool_fixture_sha256 "$direct_probe_runtime")"
    printf 'member_%s_direct_probe_runtime_metadata=%s\n' \
        "$member" "$(pool_fixture_runtime_metadata "$direct_probe_runtime")"
    printf 'member_%s_applied_ack_file=%s\n' "$member" "$applied_ack"
    printf 'member_%s_applied_ack_sha256=%s\n' \
        "$member" "$(pool_fixture_sha256 "$applied_ack")"
    printf 'member_%s_applied_ack_metadata=%s\n' \
        "$member" "$(pool_fixture_metadata "$applied_ack")"
    printf 'member_%s_applied_ack_runtime_file=%s\n' "$member" "$applied_ack_runtime"
    printf 'member_%s_applied_ack_runtime_sha256=%s\n' \
        "$member" "$(pool_fixture_sha256 "$applied_ack_runtime")"
    printf 'member_%s_applied_ack_runtime_metadata=%s\n' \
        "$member" "$(pool_fixture_runtime_metadata "$applied_ack_runtime")"
    printf 'member_%s_web_epoch=%s\n' "$member" "$web_epoch"
    printf 'member_%s_web_marker_path=/var/lib/coolify-control-plane/private/web-epoch\n' \
        "$member"
    printf 'member_%s_route_drain_epoch=%s\n' "$member" "$route_drain_epoch"
    printf 'member_%s_route_drain_marker_path=/var/lib/coolify-control-plane/private/route-drain-epoch\n' \
        "$member"
    printf 'member_%s_writer_epoch=%s\n' "$member" "$writer_epoch"
    printf 'member_%s_writer_marker_path=%s\n' "$member" "$writer_marker"
}

prepare_control_plane_pool_plan_fixture()
{
    POOL_FIXTURE_ROOT=$1
    POOL_FIXTURE_OPERATION_ID=$2
    POOL_FIXTURE_OWNER_UID=$3
    POOL_FIXTURE_OWNER_GID=$4
    POOL_FIXTURE_RUNTIME_UID=${5:-$POOL_FIXTURE_OWNER_UID}
    POOL_FIXTURE_RUNTIME_GID=${6:-$POOL_FIXTURE_OWNER_GID}
    POOL_FIXTURE_NETWORK_ID=dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd
    POOL_FIXTURE_WEB_A_ID=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
    POOL_FIXTURE_WEB_B_ID=9999999999999999999999999999999999999999999999999999999999999999
    POOL_FIXTURE_RETIRED_ID=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
    POOL_FIXTURE_WEB_A_IMAGE_REFERENCE=registry.example/candidate@sha256:4444444444444444444444444444444444444444444444444444444444444444
    POOL_FIXTURE_WEB_B_IMAGE_REFERENCE=registry.example/candidate-b@sha256:8888888888888888888888888888888888888888888888888888888888888888
    POOL_FIXTURE_WEB_A_IMAGE_ID=sha256:3333333333333333333333333333333333333333333333333333333333333333
    POOL_FIXTURE_WEB_B_IMAGE_ID=sha256:7777777777777777777777777777777777777777777777777777777777777777
    POOL_FIXTURE_POOL_PLAN_MANIFEST=$POOL_FIXTURE_ROOT/pool-plan.manifest
    POOL_FIXTURE_INGRESS_POOL_MANIFEST=$POOL_FIXTURE_ROOT/ingress-pool.manifest
    POOL_FIXTURE_ROUTE_TOKEN=$POOL_FIXTURE_ROOT/route-health.token
    POOL_FIXTURE_POOL_ACK=$POOL_FIXTURE_ROOT/pool.ack
    POOL_FIXTURE_ROUTE_RUNTIME=$POOL_FIXTURE_ROOT/route-health-runtime.token
    POOL_FIXTURE_POOL_ACK_RUNTIME=$POOL_FIXTURE_ROOT/pool-runtime.ack
    POOL_FIXTURE_WEB_A_DIRECT_TOKEN=$POOL_FIXTURE_ROOT/member-a-direct.token
    POOL_FIXTURE_WEB_B_DIRECT_TOKEN=$POOL_FIXTURE_ROOT/member-b-direct.token
    POOL_FIXTURE_WEB_A_APPLIED_ACK=$POOL_FIXTURE_ROOT/member-a-applied.ack
    POOL_FIXTURE_WEB_B_APPLIED_ACK=$POOL_FIXTURE_ROOT/member-b-applied.ack
    POOL_FIXTURE_WEB_A_DIRECT_RUNTIME=$POOL_FIXTURE_ROOT/candidate-direct-token
    POOL_FIXTURE_WEB_B_DIRECT_RUNTIME=$POOL_FIXTURE_ROOT/candidate-b-direct-token
    POOL_FIXTURE_WEB_A_APPLIED_RUNTIME=$POOL_FIXTURE_ROOT/candidate-applied-ack
    POOL_FIXTURE_WEB_B_APPLIED_RUNTIME=$POOL_FIXTURE_ROOT/candidate-b-applied-ack

    printf '%s' route-health-token-01 > "$POOL_FIXTURE_ROUTE_TOKEN"
    printf '%s' pool-ack-token-0001 > "$POOL_FIXTURE_POOL_ACK"
    printf '%s' direct-probe-token-a1 > "$POOL_FIXTURE_WEB_A_DIRECT_TOKEN"
    printf '%s' direct-probe-token-b1 > "$POOL_FIXTURE_WEB_B_DIRECT_TOKEN"
    printf '%s' applied-ack-token-a01 > "$POOL_FIXTURE_WEB_A_APPLIED_ACK"
    printf '%s' applied-ack-token-b01 > "$POOL_FIXTURE_WEB_B_APPLIED_ACK"
    mkdir -p "$POOL_FIXTURE_ROOT/coordination"
    printf '%s' mutation-freeze-0001 > "$POOL_FIXTURE_ROOT/coordination/mutation-freeze-epoch"
    : > "$POOL_FIXTURE_ROOT/coordination/mutation-inflight.lock"
    cp "$POOL_FIXTURE_ROUTE_TOKEN" "$POOL_FIXTURE_ROUTE_RUNTIME"
    cp "$POOL_FIXTURE_POOL_ACK" "$POOL_FIXTURE_POOL_ACK_RUNTIME"
    cp "$POOL_FIXTURE_WEB_A_DIRECT_TOKEN" "$POOL_FIXTURE_WEB_A_DIRECT_RUNTIME"
    cp "$POOL_FIXTURE_WEB_B_DIRECT_TOKEN" "$POOL_FIXTURE_WEB_B_DIRECT_RUNTIME"
    cp "$POOL_FIXTURE_WEB_A_APPLIED_ACK" "$POOL_FIXTURE_WEB_A_APPLIED_RUNTIME"
    cp "$POOL_FIXTURE_WEB_B_APPLIED_ACK" "$POOL_FIXTURE_WEB_B_APPLIED_RUNTIME"
    chmod 0600 "$POOL_FIXTURE_ROUTE_TOKEN" "$POOL_FIXTURE_POOL_ACK" \
        "$POOL_FIXTURE_WEB_A_DIRECT_TOKEN" "$POOL_FIXTURE_WEB_B_DIRECT_TOKEN" \
        "$POOL_FIXTURE_WEB_A_APPLIED_ACK" "$POOL_FIXTURE_WEB_B_APPLIED_ACK"
    chmod 0400 "$POOL_FIXTURE_ROUTE_RUNTIME" "$POOL_FIXTURE_POOL_ACK_RUNTIME" \
        "$POOL_FIXTURE_WEB_A_DIRECT_RUNTIME" "$POOL_FIXTURE_WEB_B_DIRECT_RUNTIME" \
        "$POOL_FIXTURE_WEB_A_APPLIED_RUNTIME" "$POOL_FIXTURE_WEB_B_APPLIED_RUNTIME"
    chmod 0440 "$POOL_FIXTURE_ROOT/coordination/mutation-freeze-epoch"
    chmod 0660 "$POOL_FIXTURE_ROOT/coordination/mutation-inflight.lock"

    {
        printf 'version=2\noperation_id=%s\ndirection=bootstrap-forward\n' \
            "$POOL_FIXTURE_OPERATION_ID"
        printf 'color=green\ngeneration=1\ncoordination_volume=runtime-fence-coordination\n'
        printf 'mutation_freeze_epoch=mutation-freeze-0001\n'
        printf 'mutation_freeze_marker_path=/var/lib/coolify-control-plane/coordination/mutation-freeze-epoch\n'
        printf 'mutation_lease_path=/var/lib/coolify-control-plane/coordination/mutation-inflight.lock\n'
        printf 'route_health_path=/api/control-plane/route-health\n'
        printf 'route_health_token_file=%s\n' "$POOL_FIXTURE_ROUTE_TOKEN"
        printf 'route_health_token_sha256=%s\n' "$(pool_fixture_sha256 "$POOL_FIXTURE_ROUTE_TOKEN")"
        printf 'route_health_token_metadata=%s\n' "$(pool_fixture_metadata "$POOL_FIXTURE_ROUTE_TOKEN")"
        printf 'route_health_runtime_file=%s\n' "$POOL_FIXTURE_ROUTE_RUNTIME"
        printf 'route_health_runtime_sha256=%s\n' "$(pool_fixture_sha256 "$POOL_FIXTURE_ROUTE_RUNTIME")"
        printf 'route_health_runtime_metadata=%s\n' "$(pool_fixture_runtime_metadata "$POOL_FIXTURE_ROUTE_RUNTIME")"
        printf 'pool_ack_file=%s\n' "$POOL_FIXTURE_POOL_ACK"
        printf 'pool_ack_sha256=%s\n' "$(pool_fixture_sha256 "$POOL_FIXTURE_POOL_ACK")"
        printf 'pool_ack_metadata=%s\n' "$(pool_fixture_metadata "$POOL_FIXTURE_POOL_ACK")"
        printf 'pool_ack_runtime_file=%s\n' "$POOL_FIXTURE_POOL_ACK_RUNTIME"
        printf 'pool_ack_runtime_sha256=%s\n' "$(pool_fixture_sha256 "$POOL_FIXTURE_POOL_ACK_RUNTIME")"
        printf 'pool_ack_runtime_metadata=%s\n' "$(pool_fixture_runtime_metadata "$POOL_FIXTURE_POOL_ACK_RUNTIME")"
        printf 'backend_port=8080\nmember_count=2\n'
        pool_fixture_write_plan_member a web-a candidate route-identity-a-01 \
            "$POOL_FIXTURE_WEB_A_IMAGE_REFERENCE" "$POOL_FIXTURE_WEB_A_IMAGE_ID" \
            candidate-control-plane-state 18080 "$POOL_FIXTURE_WEB_A_DIRECT_TOKEN" \
            "$POOL_FIXTURE_WEB_A_DIRECT_RUNTIME" "$POOL_FIXTURE_WEB_A_APPLIED_ACK" \
            "$POOL_FIXTURE_WEB_A_APPLIED_RUNTIME" web-epoch-member-a-01 \
            route-drain-member-a-01 writer-epoch-member-a-01 \
            /var/lib/coolify-control-plane/private/writer-epoch
        pool_fixture_write_plan_member b web-b candidate-b route-identity-b-01 \
            "$POOL_FIXTURE_WEB_B_IMAGE_REFERENCE" "$POOL_FIXTURE_WEB_B_IMAGE_ID" \
            candidate-b-control-plane-state 18082 "$POOL_FIXTURE_WEB_B_DIRECT_TOKEN" \
            "$POOL_FIXTURE_WEB_B_DIRECT_RUNTIME" "$POOL_FIXTURE_WEB_B_APPLIED_ACK" \
            "$POOL_FIXTURE_WEB_B_APPLIED_RUNTIME" web-epoch-member-b-01 \
            route-drain-member-b-01 absent absent
        printf 'writer_member=web-a\n'
        printf 'pool_label_key=coolify.control-plane.pool\n'
        printf 'pool_label_value=%s\n' "$POOL_FIXTURE_OPERATION_ID"
        printf 'retired_member_count=1\nretired_member_a_name=legacy\n'
        printf 'retired_member_a_id=%s\n' "$POOL_FIXTURE_RETIRED_ID"
        printf 'ingress_pool_manifest_path=%s\n' "$POOL_FIXTURE_INGRESS_POOL_MANIFEST"
        printf 'pool_plan_set_sha256=pending\n'
    } > "$POOL_FIXTURE_POOL_PLAN_MANIFEST"
    chmod 0600 "$POOL_FIXTURE_POOL_PLAN_MANIFEST"
    POOL_FIXTURE_PLAN_SET_SHA256=$(pool_fixture_plan_set_bytes | sha256sum | awk '{print $1}')
    sed "s/^pool_plan_set_sha256=pending$/pool_plan_set_sha256=$POOL_FIXTURE_PLAN_SET_SHA256/" \
        "$POOL_FIXTURE_POOL_PLAN_MANIFEST" > "$POOL_FIXTURE_POOL_PLAN_MANIFEST.new"
    chmod 0600 "$POOL_FIXTURE_POOL_PLAN_MANIFEST.new"
    mv -f -- "$POOL_FIXTURE_POOL_PLAN_MANIFEST.new" "$POOL_FIXTURE_POOL_PLAN_MANIFEST"
    POOL_FIXTURE_POOL_PLAN_SHA256=$(pool_fixture_sha256 "$POOL_FIXTURE_POOL_PLAN_MANIFEST")
}

pool_fixture_write_ingress_member()
{
    local member=$1 role=$2 name=$3 route_identity=$4 id=$5 address=$6
    local image_reference=$7 image_id=$8 runtime_sha256=$9 network_sha256=${10}
    local bindings_sha256=${11} applied_ack=${12}

    printf 'member_%s_role=%s\n' "$member" "$role"
    printf 'member_%s_name=%s\n' "$member" "$name"
    printf 'member_%s_route_identity=%s\n' "$member" "$route_identity"
    printf 'member_%s_id=%s\n' "$member" "$id"
    printf 'member_%s_address=%s\n' "$member" "$address"
    printf 'member_%s_port=8080\n' "$member"
    printf 'member_%s_image_reference=%s\n' "$member" "$image_reference"
    printf 'member_%s_image_id=%s\n' "$member" "$image_id"
    printf 'member_%s_runtime_sha256=%s\n' "$member" "$runtime_sha256"
    printf 'member_%s_network_sha256=%s\n' "$member" "$network_sha256"
    printf 'member_%s_bindings_sha256=%s\n' "$member" "$bindings_sha256"
    printf 'member_%s_applied_ack_file=%s\n' "$member" "$applied_ack"
    printf 'member_%s_applied_ack_sha256=%s\n' "$member" "$(pool_fixture_sha256 "$applied_ack")"
    printf 'member_%s_applied_ack_metadata=%s\n' "$member" "$(pool_fixture_metadata "$applied_ack")"
}

write_control_plane_ingress_pool_fixture()
{
    local web_a_runtime_sha256=$1 web_a_network_sha256=$2 web_a_bindings_sha256=$3
    local web_b_runtime_sha256=$4 web_b_network_sha256=$5 web_b_bindings_sha256=$6

    {
        printf 'version=2\noperation_id=%s\ndirection=bootstrap-forward\n' \
            "$POOL_FIXTURE_OPERATION_ID"
        printf 'color=green\ngeneration=1\nparent_pool_plan_sha256=%s\n' \
            "$POOL_FIXTURE_POOL_PLAN_SHA256"
        printf 'pool_ack_file=%s\n' "$POOL_FIXTURE_POOL_ACK"
        printf 'pool_ack_sha256=%s\n' "$(pool_fixture_sha256 "$POOL_FIXTURE_POOL_ACK")"
        printf 'pool_ack_metadata=%s\nmember_count=2\n' \
            "$(pool_fixture_metadata "$POOL_FIXTURE_POOL_ACK")"
        pool_fixture_write_ingress_member a web-a candidate route-identity-a-01 \
            "$POOL_FIXTURE_WEB_A_ID" 172.18.0.4 "$POOL_FIXTURE_WEB_A_IMAGE_REFERENCE" \
            "$POOL_FIXTURE_WEB_A_IMAGE_ID" "$web_a_runtime_sha256" \
            "$web_a_network_sha256" "$web_a_bindings_sha256" \
            "$POOL_FIXTURE_WEB_A_APPLIED_ACK"
        pool_fixture_write_ingress_member b web-b candidate-b route-identity-b-01 \
            "$POOL_FIXTURE_WEB_B_ID" 172.18.0.5 "$POOL_FIXTURE_WEB_B_IMAGE_REFERENCE" \
            "$POOL_FIXTURE_WEB_B_IMAGE_ID" "$web_b_runtime_sha256" \
            "$web_b_network_sha256" "$web_b_bindings_sha256" \
            "$POOL_FIXTURE_WEB_B_APPLIED_ACK"
        printf 'writer_member=web-a\n'
        printf 'pool_label_key=coolify.control-plane.pool\n'
        printf 'pool_label_value=%s\n' "$POOL_FIXTURE_OPERATION_ID"
        printf 'member_set_sha256=pending\n'
        printf 'route_health_path=/api/control-plane/route-health\n'
        printf 'route_health_token_file=%s\n' "$POOL_FIXTURE_ROUTE_TOKEN"
        printf 'route_health_token_sha256=%s\n' "$(pool_fixture_sha256 "$POOL_FIXTURE_ROUTE_TOKEN")"
        printf 'route_health_token_metadata=%s\n' "$(pool_fixture_metadata "$POOL_FIXTURE_ROUTE_TOKEN")"
    } > "$POOL_FIXTURE_INGRESS_POOL_MANIFEST"
    chmod 0600 "$POOL_FIXTURE_INGRESS_POOL_MANIFEST"
    POOL_FIXTURE_MEMBER_SET_SHA256=$(pool_fixture_ingress_member_set_bytes \
        | sha256sum | awk '{print $1}')
    sed "s/^member_set_sha256=pending$/member_set_sha256=$POOL_FIXTURE_MEMBER_SET_SHA256/" \
        "$POOL_FIXTURE_INGRESS_POOL_MANIFEST" > "$POOL_FIXTURE_INGRESS_POOL_MANIFEST.new"
    chmod 0600 "$POOL_FIXTURE_INGRESS_POOL_MANIFEST.new"
    mv -f -- "$POOL_FIXTURE_INGRESS_POOL_MANIFEST.new" "$POOL_FIXTURE_INGRESS_POOL_MANIFEST"
    # shellcheck disable=SC2034
    POOL_FIXTURE_INGRESS_POOL_SHA256=$(pool_fixture_sha256 "$POOL_FIXTURE_INGRESS_POOL_MANIFEST")
}
