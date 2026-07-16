#!/usr/bin/env bash

# This helper is sourced by linux-host-acceptance-inner.sh. It deliberately
# renders only from a real stack contract: it never creates a fake proxy,
# terminal endpoint, queue probe, or acknowledgement response.

LIVE_HOST_FIXTURE_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
readonly LIVE_HOST_FIXTURE_DIRECTORY
# shellcheck disable=SC1091 # Runtime path is fixed by LIVE_HOST_FIXTURE_DIRECTORY above.
source "$LIVE_HOST_FIXTURE_DIRECTORY/pool-manifest-fixture.bash"

live_host_fixture_fail()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE_LIVE_FIXTURE_FAILURE %s\n' "$1" >&2
    return 1
}

live_host_fixture_value()
{
    local name=$1
    if [[ ! $name =~ ^LIVE_HOST_[A-Z0-9_]+$ ]]; then
        live_host_fixture_fail "unsafe live fixture variable requested: $name"
        return 1
    fi
    if [[ -z ${!name:-} ]]; then
        live_host_fixture_fail "live stack contract is missing: $name"
        return 1
    fi
    printf '%s' "${!name}"
}

live_host_fixture_assert_identifier()
{
    [[ $2 =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ ]] \
        || live_host_fixture_fail "$1 is not a safe identifier"
}

live_host_fixture_assert_authority()
{
    [[ $2 =~ ^[A-Za-z0-9._:-]{16,128}$ ]] \
        || live_host_fixture_fail "$1 is not a safe authority token"
}

live_host_fixture_assert_absolute_path()
{
    [[ $2 == /* && $2 != *'//'*
        && $2 != '/..' && $2 != '/../'* && $2 != *'/../'* && $2 != *'/..' \
        && $2 =~ ^/[A-Za-z0-9_./-]+$ ]] \
        || live_host_fixture_fail "$1 is not a safe absolute path"
}

live_host_fixture_assert_digest_reference()
{
    [[ $2 =~ ^[A-Za-z0-9][A-Za-z0-9._/:@-]*@sha256:[a-f0-9]{64}$ ]] \
        || live_host_fixture_fail "$1 is not an immutable image reference"
}

live_host_fixture_assert_port()
{
    local label=$1 port=$2

    if [[ ! $port =~ ^[1-9][0-9]{0,4}$ ]] || ((port > 65535)); then
        live_host_fixture_fail "$label is malformed"
    fi
}

live_host_fixture_assert_network_ids()
{
    local label=$1 values=$2 canonical network_id
    [[ $values =~ ^[a-f0-9]{64}(,[a-f0-9]{64})*$ ]] \
        || live_host_fixture_fail "$label is malformed"
    canonical=$(tr ',' '\n' <<< "$values" | LC_ALL=C sort -u | paste -sd, -)
    [[ $values == "$canonical" ]] \
        || live_host_fixture_fail "$label must be sorted and unique"
    while IFS= read -r network_id; do
        docker network inspect "$network_id" >/dev/null \
            || live_host_fixture_fail "$label refers to an unavailable Docker network"
    done < <(tr ',' '\n' <<< "$values")
}

live_host_fixture_sha256()
{
    sha256sum "$1" | awk '{print $1}'
}

live_host_fixture_metadata()
{
    printf '%s\n' "$(stat -c '%u:%g:%a:%s' "$1")"
}

live_host_fixture_assert_file()
{
    local label=$1 path=$2 expected_metadata=$3
    [[ -f $path && ! -L $path && $(readlink -f -- "$path") == "$path" \
        && $(live_host_fixture_metadata "$path") == "$expected_metadata" ]] \
        || live_host_fixture_fail "$label has an unsafe ownership, mode, or path identity"
}

live_host_fixture_assert_root_artifact()
{
    local label=$1 path=$2
    live_host_fixture_assert_absolute_path "$label path" "$path"
    live_host_fixture_assert_file "$label" "$path" "0:0:600:$(wc -c < "$path" | tr -d '[:space:]')"
}

live_host_fixture_assert_runtime_artifact()
{
    local label=$1 path=$2
    local expected_uid expected_gid
    expected_uid=$(live_host_fixture_value LIVE_HOST_RUNTIME_UID)
    expected_gid=$(live_host_fixture_value LIVE_HOST_RUNTIME_GID)
    [[ $expected_uid =~ ^[0-9]+$ && $expected_gid =~ ^[0-9]+$ ]] \
        || live_host_fixture_fail 'runtime artifact ownership is malformed'
    live_host_fixture_assert_absolute_path "$label path" "$path"
    live_host_fixture_assert_file "$label" "$path" \
        "$expected_uid:$expected_gid:400:$(wc -c < "$path" | tr -d '[:space:]')"
}

live_host_fixture_artifact_bytes_match()
{
    local root_path=$1 runtime_path=$2 label=$3
    [[ $(live_host_fixture_sha256 "$root_path") == "$(live_host_fixture_sha256 "$runtime_path")" ]] \
        || live_host_fixture_fail "$label root and runtime artifact bytes differ"
}

live_host_fixture_container_id()
{
    local container=$1 identifier
    identifier=$(docker inspect --format '{{.Id}}' "$container") \
        || live_host_fixture_fail "container is unavailable: $container"
    [[ $identifier =~ ^[a-f0-9]{64}$ ]] \
        || live_host_fixture_fail "container ID is malformed: $container"
    printf '%s' "$identifier"
}

live_host_fixture_image_id()
{
    local reference=$1 identifier
    identifier=$(docker image inspect --format '{{.Id}}' "$reference") \
        || live_host_fixture_fail "image is unavailable: $reference"
    [[ $identifier =~ ^sha256:[a-f0-9]{64}$ ]] \
        || live_host_fixture_fail "image ID is malformed: $reference"
    printf '%s' "$identifier"
}

live_host_fixture_assert_member_address()
{
    local container=$1 address=$2
    [[ $address =~ ^[A-Fa-f0-9:.]+$ ]] \
        || live_host_fixture_fail 'ingress member address is malformed'
    [[ $(docker inspect "$container" | jq -r --arg address "$address" \
        '[.[0].NetworkSettings.Networks[] | select(.IPAddress == $address or .GlobalIPv6Address == $address)] | length') \
        == 1 ]] \
        || live_host_fixture_fail "ingress address is not owned by its member: $container"
}

live_host_fixture_member_prefix()
{
    case "$1" in
        a) printf '%s' WEB_A ;;
        b) printf '%s' WEB_B ;;
        *) live_host_fixture_fail 'member is outside the canonical two-member pool' ;;
    esac
}

live_host_fixture_member_value()
{
    local member=$1 suffix=$2 prefix name
    prefix=$(live_host_fixture_member_prefix "$member")
    name=LIVE_HOST_${prefix}_${suffix}
    live_host_fixture_value "$name"
}

live_host_fixture_validate_contract()
{
    local member prefix root artifact backend_port loopback_port
    local -a required=(
        LIVE_HOST_ROOT LIVE_HOST_OPERATION_ID LIVE_HOST_GENERATION LIVE_HOST_COORDINATION_VOLUME
        LIVE_HOST_MUTATION_FREEZE_EPOCH LIVE_HOST_MUTATION_FREEZE_MARKER_PATH
        LIVE_HOST_MUTATION_LEASE_PATH LIVE_HOST_BACKEND_PORT LIVE_HOST_POOL_LABEL_KEY
        LIVE_HOST_POOL_LABEL_VALUE LIVE_HOST_WRITER_MEMBER LIVE_HOST_RUNTIME_UID LIVE_HOST_RUNTIME_GID
        LIVE_HOST_GREEN_WEB_A_CONTAINER LIVE_HOST_GREEN_WEB_B_CONTAINER
        LIVE_HOST_BLUE_WEB_A_CONTAINER LIVE_HOST_BLUE_WEB_B_CONTAINER
        LIVE_HOST_GREEN_WEB_A_PRIVATE_VOLUME LIVE_HOST_GREEN_WEB_B_PRIVATE_VOLUME
        LIVE_HOST_BLUE_WEB_A_PRIVATE_VOLUME LIVE_HOST_BLUE_WEB_B_PRIVATE_VOLUME
        LIVE_HOST_WEB_A_IMAGE_REFERENCE LIVE_HOST_WEB_B_IMAGE_REFERENCE
        LIVE_HOST_WEB_A_NETWORK_IDS LIVE_HOST_WEB_B_NETWORK_IDS
        LIVE_HOST_WEB_A_ROUTE_IDENTITY LIVE_HOST_WEB_B_ROUTE_IDENTITY
        LIVE_HOST_WEB_A_LOOPBACK_PORT LIVE_HOST_WEB_B_LOOPBACK_PORT
        LIVE_HOST_WEB_A_WEB_EPOCH LIVE_HOST_WEB_B_WEB_EPOCH
        LIVE_HOST_WEB_A_ROUTE_DRAIN_EPOCH LIVE_HOST_WEB_B_ROUTE_DRAIN_EPOCH
        LIVE_HOST_WEB_A_WRITER_EPOCH LIVE_HOST_WEB_A_WRITER_MARKER_PATH
        LIVE_HOST_ROUTE_HEALTH_TOKEN_FILE LIVE_HOST_ROUTE_HEALTH_RUNTIME_FILE
        LIVE_HOST_POOL_ACK_FILE LIVE_HOST_POOL_ACK_RUNTIME_FILE
        LIVE_HOST_WEB_A_DIRECT_PROBE_TOKEN_FILE LIVE_HOST_WEB_A_DIRECT_PROBE_RUNTIME_FILE
        LIVE_HOST_WEB_B_DIRECT_PROBE_TOKEN_FILE LIVE_HOST_WEB_B_DIRECT_PROBE_RUNTIME_FILE
        LIVE_HOST_WEB_A_APPLIED_ACK_FILE LIVE_HOST_WEB_A_APPLIED_ACK_RUNTIME_FILE
        LIVE_HOST_WEB_B_APPLIED_ACK_FILE LIVE_HOST_WEB_B_APPLIED_ACK_RUNTIME_FILE
        LIVE_HOST_WEB_A_INGRESS_ADDRESS LIVE_HOST_WEB_B_INGRESS_ADDRESS
    )

    for artifact in "${required[@]}"; do
        live_host_fixture_value "$artifact" >/dev/null
    done
    root=$(live_host_fixture_value LIVE_HOST_ROOT)
    live_host_fixture_assert_absolute_path 'fixture root' "$root"
    [[ -d $root && ! -L $root && $(readlink -f -- "$root") == "$root" \
        && $(stat -c '%u:%g:%a' "$root") == 0:0:700 ]] \
        || live_host_fixture_fail 'fixture root must be root-owned mode 0700'
    live_host_fixture_assert_identifier operation-id "$(live_host_fixture_value LIVE_HOST_OPERATION_ID)"
    [[ $(live_host_fixture_value LIVE_HOST_GENERATION) =~ ^[1-9][0-9]*$ ]] \
        || live_host_fixture_fail 'generation is malformed'
    backend_port=$(live_host_fixture_value LIVE_HOST_BACKEND_PORT)
    live_host_fixture_assert_port 'backend port' "$backend_port"
    [[ $(live_host_fixture_value LIVE_HOST_WRITER_MEMBER) == web-a ]] \
        || live_host_fixture_fail 'live host fixture must preserve web-a as the sole writer'
    for artifact in \
        LIVE_HOST_COORDINATION_VOLUME LIVE_HOST_GREEN_WEB_A_CONTAINER LIVE_HOST_GREEN_WEB_B_CONTAINER \
        LIVE_HOST_BLUE_WEB_A_CONTAINER LIVE_HOST_BLUE_WEB_B_CONTAINER \
        LIVE_HOST_GREEN_WEB_A_PRIVATE_VOLUME LIVE_HOST_GREEN_WEB_B_PRIVATE_VOLUME \
        LIVE_HOST_BLUE_WEB_A_PRIVATE_VOLUME LIVE_HOST_BLUE_WEB_B_PRIVATE_VOLUME \
        LIVE_HOST_POOL_LABEL_KEY LIVE_HOST_POOL_LABEL_VALUE; do
        live_host_fixture_assert_identifier "$artifact" "$(live_host_fixture_value "$artifact")"
    done
    local -a volumes=(
        "$(live_host_fixture_value LIVE_HOST_COORDINATION_VOLUME)"
        "$(live_host_fixture_value LIVE_HOST_BLUE_WEB_A_PRIVATE_VOLUME)"
        "$(live_host_fixture_value LIVE_HOST_BLUE_WEB_B_PRIVATE_VOLUME)"
        "$(live_host_fixture_value LIVE_HOST_GREEN_WEB_A_PRIVATE_VOLUME)"
        "$(live_host_fixture_value LIVE_HOST_GREEN_WEB_B_PRIVATE_VOLUME)"
    )
    [[ $(printf '%s\n' "${volumes[@]}" | LC_ALL=C sort -u | wc -l | tr -d '[:space:]') == 5 ]] \
        || live_host_fixture_fail 'all four private volumes and the coordination volume must be distinct'
    live_host_fixture_assert_authority mutation-freeze-epoch \
        "$(live_host_fixture_value LIVE_HOST_MUTATION_FREEZE_EPOCH)"
    live_host_fixture_assert_absolute_path mutation-freeze-marker-path \
        "$(live_host_fixture_value LIVE_HOST_MUTATION_FREEZE_MARKER_PATH)"
    live_host_fixture_assert_absolute_path mutation-lease-path \
        "$(live_host_fixture_value LIVE_HOST_MUTATION_LEASE_PATH)"

    for member in a b; do
        prefix=$(live_host_fixture_member_prefix "$member")
        live_host_fixture_assert_digest_reference "member-$member image" \
            "$(live_host_fixture_value "LIVE_HOST_${prefix}_IMAGE_REFERENCE")"
        live_host_fixture_image_id "$(live_host_fixture_value "LIVE_HOST_${prefix}_IMAGE_REFERENCE")" >/dev/null
        live_host_fixture_assert_network_ids "member-$member network IDs" \
            "$(live_host_fixture_value "LIVE_HOST_${prefix}_NETWORK_IDS")"
        live_host_fixture_assert_authority "member-$member route identity" \
            "$(live_host_fixture_value "LIVE_HOST_${prefix}_ROUTE_IDENTITY")"
        loopback_port=$(live_host_fixture_value "LIVE_HOST_${prefix}_LOOPBACK_PORT")
        live_host_fixture_assert_port "member-$member loopback port" "$loopback_port"
        live_host_fixture_assert_authority "member-$member web epoch" \
            "$(live_host_fixture_value "LIVE_HOST_${prefix}_WEB_EPOCH")"
        live_host_fixture_assert_authority "member-$member route drain epoch" \
            "$(live_host_fixture_value "LIVE_HOST_${prefix}_ROUTE_DRAIN_EPOCH")"
    done
    live_host_fixture_assert_authority web-a-writer-epoch \
        "$(live_host_fixture_value LIVE_HOST_WEB_A_WRITER_EPOCH)"
    live_host_fixture_assert_absolute_path web-a-writer-marker \
        "$(live_host_fixture_value LIVE_HOST_WEB_A_WRITER_MARKER_PATH)"

    for artifact in \
        ROUTE_HEALTH_TOKEN POOL_ACK WEB_A_DIRECT_PROBE_TOKEN WEB_B_DIRECT_PROBE_TOKEN \
        WEB_A_APPLIED_ACK WEB_B_APPLIED_ACK; do
        live_host_fixture_assert_root_artifact "$artifact" \
            "$(live_host_fixture_value LIVE_HOST_${artifact}_FILE)"
        live_host_fixture_assert_runtime_artifact "$artifact runtime" \
            "$(live_host_fixture_value LIVE_HOST_${artifact}_RUNTIME_FILE)"
        live_host_fixture_artifact_bytes_match \
            "$(live_host_fixture_value LIVE_HOST_${artifact}_FILE)" \
            "$(live_host_fixture_value LIVE_HOST_${artifact}_RUNTIME_FILE)" "$artifact"
    done
}

live_host_fixture_write_member()
{
    local member=$1 role=$2 prefix private_volume image_reference image_id network_ids
    local route_identity loopback_port direct_token direct_runtime applied_ack applied_runtime
    local web_epoch route_drain_epoch writer_epoch writer_marker

    prefix=$(live_host_fixture_member_prefix "$member")
    private_volume=$(live_host_fixture_value "LIVE_HOST_GREEN_${prefix}_PRIVATE_VOLUME")
    image_reference=$(live_host_fixture_value "LIVE_HOST_${prefix}_IMAGE_REFERENCE")
    image_id=$(live_host_fixture_image_id "$image_reference")
    network_ids=$(live_host_fixture_value "LIVE_HOST_${prefix}_NETWORK_IDS")
    route_identity=$(live_host_fixture_value "LIVE_HOST_${prefix}_ROUTE_IDENTITY")
    loopback_port=$(live_host_fixture_value "LIVE_HOST_${prefix}_LOOPBACK_PORT")
    direct_token=$(live_host_fixture_value "LIVE_HOST_${prefix}_DIRECT_PROBE_TOKEN_FILE")
    direct_runtime=$(live_host_fixture_value "LIVE_HOST_${prefix}_DIRECT_PROBE_RUNTIME_FILE")
    applied_ack=$(live_host_fixture_value "LIVE_HOST_${prefix}_APPLIED_ACK_FILE")
    applied_runtime=$(live_host_fixture_value "LIVE_HOST_${prefix}_APPLIED_ACK_RUNTIME_FILE")
    web_epoch=$(live_host_fixture_value "LIVE_HOST_${prefix}_WEB_EPOCH")
    route_drain_epoch=$(live_host_fixture_value "LIVE_HOST_${prefix}_ROUTE_DRAIN_EPOCH")
    if [[ $member == a ]]; then
        writer_epoch=$(live_host_fixture_value LIVE_HOST_WEB_A_WRITER_EPOCH)
        writer_marker=$(live_host_fixture_value LIVE_HOST_WEB_A_WRITER_MARKER_PATH)
    else
        writer_epoch=absent
        writer_marker=absent
    fi

    printf 'member_%s_role=%s\n' "$member" "$role"
    printf 'member_%s_name=%s\n' "$member" \
        "$(live_host_fixture_value "LIVE_HOST_GREEN_${prefix}_CONTAINER")"
    printf 'member_%s_route_identity=%s\n' "$member" "$route_identity"
    printf 'member_%s_image_reference=%s\n' "$member" "$image_reference"
    printf 'member_%s_image_id=%s\n' "$member" "$image_id"
    printf 'member_%s_network_ids=%s\n' "$member" "$network_ids"
    printf 'member_%s_private_volume=%s\n' "$member" "$private_volume"
    printf 'member_%s_expected_loopback_port=%s\n' "$member" "$loopback_port"
    printf 'member_%s_repin_intent_file=%s/member-%s-repin.intent\n' \
        "$member" "$LIVE_HOST_ROOT" "$member"
    printf 'member_%s_direct_probe_token_file=%s\n' "$member" "$direct_token"
    printf 'member_%s_direct_probe_token_sha256=%s\n' "$member" \
        "$(live_host_fixture_sha256 "$direct_token")"
    printf 'member_%s_direct_probe_token_metadata=%s\n' "$member" \
        "$(live_host_fixture_metadata "$direct_token")"
    printf 'member_%s_direct_probe_runtime_file=%s\n' "$member" "$direct_runtime"
    printf 'member_%s_direct_probe_runtime_sha256=%s\n' "$member" \
        "$(live_host_fixture_sha256 "$direct_runtime")"
    printf 'member_%s_direct_probe_runtime_metadata=%s\n' "$member" \
        "$(live_host_fixture_metadata "$direct_runtime")"
    printf 'member_%s_applied_ack_file=%s\n' "$member" "$applied_ack"
    printf 'member_%s_applied_ack_sha256=%s\n' "$member" \
        "$(live_host_fixture_sha256 "$applied_ack")"
    printf 'member_%s_applied_ack_metadata=%s\n' "$member" \
        "$(live_host_fixture_metadata "$applied_ack")"
    printf 'member_%s_applied_ack_runtime_file=%s\n' "$member" "$applied_runtime"
    printf 'member_%s_applied_ack_runtime_sha256=%s\n' "$member" \
        "$(live_host_fixture_sha256 "$applied_runtime")"
    printf 'member_%s_applied_ack_runtime_metadata=%s\n' "$member" \
        "$(live_host_fixture_metadata "$applied_runtime")"
    printf 'member_%s_web_epoch=%s\n' "$member" "$web_epoch"
    printf 'member_%s_web_marker_path=/var/lib/coolify-control-plane/private/web-epoch\n' "$member"
    printf 'member_%s_route_drain_epoch=%s\n' "$member" "$route_drain_epoch"
    printf 'member_%s_route_drain_marker_path=/var/lib/coolify-control-plane/private/route-drain-epoch\n' \
        "$member"
    printf 'member_%s_writer_epoch=%s\n' "$member" "$writer_epoch"
    printf 'member_%s_writer_marker_path=%s\n' "$member" "$writer_marker"
}

prepare_live_control_plane_pool_plan_fixture()
{
    local retired_a retired_b manifest_candidate

    live_host_fixture_validate_contract
    LIVE_HOST_POOL_PLAN_MANIFEST=$LIVE_HOST_ROOT/pool-plan.manifest
    LIVE_HOST_INGRESS_POOL_MANIFEST=$LIVE_HOST_ROOT/ingress-pool.manifest
    POOL_FIXTURE_POOL_PLAN_MANIFEST=$LIVE_HOST_POOL_PLAN_MANIFEST
    POOL_FIXTURE_INGRESS_POOL_MANIFEST=$LIVE_HOST_INGRESS_POOL_MANIFEST
    export POOL_FIXTURE_POOL_PLAN_MANIFEST POOL_FIXTURE_INGRESS_POOL_MANIFEST

    retired_a=$(live_host_fixture_container_id "$(live_host_fixture_value LIVE_HOST_BLUE_WEB_A_CONTAINER)")
    retired_b=$(live_host_fixture_container_id "$(live_host_fixture_value LIVE_HOST_BLUE_WEB_B_CONTAINER)")
    [[ $retired_a != "$retired_b" ]] || live_host_fixture_fail 'retired members share a Docker ID'

    manifest_candidate=$LIVE_HOST_ROOT/.pool-plan.manifest.$$
    {
        printf 'version=2\noperation_id=%s\ndirection=forward\ncolor=green\ngeneration=%s\n' \
            "$LIVE_HOST_OPERATION_ID" "$LIVE_HOST_GENERATION"
        printf 'coordination_volume=%s\n' "$LIVE_HOST_COORDINATION_VOLUME"
        printf 'mutation_freeze_epoch=%s\n' "$LIVE_HOST_MUTATION_FREEZE_EPOCH"
        printf 'mutation_freeze_marker_path=%s\n' "$LIVE_HOST_MUTATION_FREEZE_MARKER_PATH"
        printf 'mutation_lease_path=%s\n' "$LIVE_HOST_MUTATION_LEASE_PATH"
        printf 'route_health_path=/api/control-plane/route-health\n'
        printf 'route_health_token_file=%s\n' "$LIVE_HOST_ROUTE_HEALTH_TOKEN_FILE"
        printf 'route_health_token_sha256=%s\n' \
            "$(live_host_fixture_sha256 "$LIVE_HOST_ROUTE_HEALTH_TOKEN_FILE")"
        printf 'route_health_token_metadata=%s\n' \
            "$(live_host_fixture_metadata "$LIVE_HOST_ROUTE_HEALTH_TOKEN_FILE")"
        printf 'route_health_runtime_file=%s\n' "$LIVE_HOST_ROUTE_HEALTH_RUNTIME_FILE"
        printf 'route_health_runtime_sha256=%s\n' \
            "$(live_host_fixture_sha256 "$LIVE_HOST_ROUTE_HEALTH_RUNTIME_FILE")"
        printf 'route_health_runtime_metadata=%s\n' \
            "$(live_host_fixture_metadata "$LIVE_HOST_ROUTE_HEALTH_RUNTIME_FILE")"
        printf 'pool_ack_file=%s\n' "$LIVE_HOST_POOL_ACK_FILE"
        printf 'pool_ack_sha256=%s\n' "$(live_host_fixture_sha256 "$LIVE_HOST_POOL_ACK_FILE")"
        printf 'pool_ack_metadata=%s\n' "$(live_host_fixture_metadata "$LIVE_HOST_POOL_ACK_FILE")"
        printf 'pool_ack_runtime_file=%s\n' "$LIVE_HOST_POOL_ACK_RUNTIME_FILE"
        printf 'pool_ack_runtime_sha256=%s\n' \
            "$(live_host_fixture_sha256 "$LIVE_HOST_POOL_ACK_RUNTIME_FILE")"
        printf 'pool_ack_runtime_metadata=%s\n' \
            "$(live_host_fixture_metadata "$LIVE_HOST_POOL_ACK_RUNTIME_FILE")"
        printf 'backend_port=%s\nmember_count=2\n' "$LIVE_HOST_BACKEND_PORT"
        live_host_fixture_write_member a web-a
        live_host_fixture_write_member b web-b
        printf 'writer_member=web-a\n'
        printf 'pool_label_key=%s\npool_label_value=%s\n' \
            "$LIVE_HOST_POOL_LABEL_KEY" "$LIVE_HOST_POOL_LABEL_VALUE"
        printf 'retired_member_count=2\nretired_member_a_name=%s\nretired_member_a_id=%s\n' \
            "$LIVE_HOST_BLUE_WEB_A_CONTAINER" "$retired_a"
        printf 'retired_member_b_name=%s\nretired_member_b_id=%s\n' \
            "$LIVE_HOST_BLUE_WEB_B_CONTAINER" "$retired_b"
        printf 'ingress_pool_manifest_path=%s\npool_plan_set_sha256=pending\n' \
            "$LIVE_HOST_INGRESS_POOL_MANIFEST"
    } > "$manifest_candidate"
    chown root:root "$manifest_candidate"
    chmod 0600 "$manifest_candidate"
    mv -f -- "$manifest_candidate" "$LIVE_HOST_POOL_PLAN_MANIFEST"

    POOL_FIXTURE_PLAN_SET_SHA256=$(pool_fixture_plan_set_bytes | sha256sum | awk '{print $1}')
    sed "s/^pool_plan_set_sha256=pending$/pool_plan_set_sha256=$POOL_FIXTURE_PLAN_SET_SHA256/" \
        "$LIVE_HOST_POOL_PLAN_MANIFEST" > "$manifest_candidate"
    chown root:root "$manifest_candidate"
    chmod 0600 "$manifest_candidate"
    mv -f -- "$manifest_candidate" "$LIVE_HOST_POOL_PLAN_MANIFEST"

    LIVE_HOST_POOL_PLAN_SHA256=$(live_host_fixture_sha256 "$LIVE_HOST_POOL_PLAN_MANIFEST")
    LIVE_HOST_POOL_PLAN_METADATA=$(live_host_fixture_metadata "$LIVE_HOST_POOL_PLAN_MANIFEST")
    export LIVE_HOST_POOL_PLAN_MANIFEST LIVE_HOST_POOL_PLAN_SHA256 LIVE_HOST_POOL_PLAN_METADATA
}

live_host_fixture_write_ingress_member()
{
    local member=$1 role=$2 runtime_sha256=$3 network_sha256=$4 bindings_sha256=$5
    local prefix container container_id address image_reference image_id applied_ack

    prefix=$(live_host_fixture_member_prefix "$member")
    container=$(live_host_fixture_value "LIVE_HOST_GREEN_${prefix}_CONTAINER")
    container_id=$(live_host_fixture_container_id "$container")
    address=$(live_host_fixture_value "LIVE_HOST_${prefix}_INGRESS_ADDRESS")
    image_reference=$(live_host_fixture_value "LIVE_HOST_${prefix}_IMAGE_REFERENCE")
    image_id=$(live_host_fixture_image_id "$image_reference")
    applied_ack=$(live_host_fixture_value "LIVE_HOST_${prefix}_APPLIED_ACK_FILE")
    live_host_fixture_assert_member_address "$container" "$address"
    [[ $(docker inspect --format '{{.Config.Image}}' "$container") == "$image_reference" \
        && $(docker inspect --format '{{.Image}}' "$container") == "$image_id" ]] \
        || live_host_fixture_fail "live member image identity differs from its immutable plan: $container"
    [[ $runtime_sha256 =~ ^[a-f0-9]{64}$ && $network_sha256 =~ ^[a-f0-9]{64}$ \
        && $bindings_sha256 =~ ^[a-f0-9]{64}$ ]] \
        || live_host_fixture_fail "live member runtime identity is malformed: $container"

    printf 'member_%s_role=%s\n' "$member" "$role"
    printf 'member_%s_name=%s\n' "$member" "$container"
    printf 'member_%s_route_identity=%s\n' "$member" \
        "$(live_host_fixture_value "LIVE_HOST_${prefix}_ROUTE_IDENTITY")"
    printf 'member_%s_id=%s\n' "$member" "$container_id"
    printf 'member_%s_address=%s\n' "$member" "$address"
    printf 'member_%s_port=%s\n' "$member" "$LIVE_HOST_BACKEND_PORT"
    printf 'member_%s_image_reference=%s\n' "$member" "$image_reference"
    printf 'member_%s_image_id=%s\n' "$member" "$image_id"
    printf 'member_%s_runtime_sha256=%s\n' "$member" "$runtime_sha256"
    printf 'member_%s_network_sha256=%s\n' "$member" "$network_sha256"
    printf 'member_%s_bindings_sha256=%s\n' "$member" "$bindings_sha256"
    printf 'member_%s_applied_ack_file=%s\n' "$member" "$applied_ack"
    printf 'member_%s_applied_ack_sha256=%s\n' "$member" \
        "$(live_host_fixture_sha256 "$applied_ack")"
    printf 'member_%s_applied_ack_metadata=%s\n' "$member" \
        "$(live_host_fixture_metadata "$applied_ack")"
}

write_live_control_plane_ingress_pool_fixture()
{
    local web_a_runtime_sha256=$1 web_a_network_sha256=$2 web_a_bindings_sha256=$3
    local web_b_runtime_sha256=$4 web_b_network_sha256=$5 web_b_bindings_sha256=$6
    local manifest_candidate

    [[ -n ${LIVE_HOST_POOL_PLAN_SHA256:-} && -f ${LIVE_HOST_POOL_PLAN_MANIFEST:-} ]] \
        || live_host_fixture_fail 'pool plan must be prepared before ingress rendering'
    manifest_candidate=$LIVE_HOST_ROOT/.ingress-pool.manifest.$$
    {
        printf 'version=2\noperation_id=%s\ndirection=forward\ncolor=green\ngeneration=%s\n' \
            "$LIVE_HOST_OPERATION_ID" "$LIVE_HOST_GENERATION"
        printf 'parent_pool_plan_sha256=%s\n' "$LIVE_HOST_POOL_PLAN_SHA256"
        printf 'pool_ack_file=%s\npool_ack_sha256=%s\npool_ack_metadata=%s\nmember_count=2\n' \
            "$LIVE_HOST_POOL_ACK_FILE" \
            "$(live_host_fixture_sha256 "$LIVE_HOST_POOL_ACK_FILE")" \
            "$(live_host_fixture_metadata "$LIVE_HOST_POOL_ACK_FILE")"
        live_host_fixture_write_ingress_member a web-a \
            "$web_a_runtime_sha256" "$web_a_network_sha256" "$web_a_bindings_sha256"
        live_host_fixture_write_ingress_member b web-b \
            "$web_b_runtime_sha256" "$web_b_network_sha256" "$web_b_bindings_sha256"
        printf 'writer_member=web-a\npool_label_key=%s\npool_label_value=%s\nmember_set_sha256=pending\n' \
            "$LIVE_HOST_POOL_LABEL_KEY" "$LIVE_HOST_POOL_LABEL_VALUE"
        printf 'route_health_path=/api/control-plane/route-health\nroute_health_token_file=%s\n' \
            "$LIVE_HOST_ROUTE_HEALTH_TOKEN_FILE"
        printf 'route_health_token_sha256=%s\nroute_health_token_metadata=%s\n' \
            "$(live_host_fixture_sha256 "$LIVE_HOST_ROUTE_HEALTH_TOKEN_FILE")" \
            "$(live_host_fixture_metadata "$LIVE_HOST_ROUTE_HEALTH_TOKEN_FILE")"
    } > "$manifest_candidate"
    chown root:root "$manifest_candidate"
    chmod 0600 "$manifest_candidate"
    mv -f -- "$manifest_candidate" "$LIVE_HOST_INGRESS_POOL_MANIFEST"

    POOL_FIXTURE_MEMBER_SET_SHA256=$(pool_fixture_ingress_member_set_bytes \
        | sha256sum | awk '{print $1}')
    sed "s/^member_set_sha256=pending$/member_set_sha256=$POOL_FIXTURE_MEMBER_SET_SHA256/" \
        "$LIVE_HOST_INGRESS_POOL_MANIFEST" > "$manifest_candidate"
    chown root:root "$manifest_candidate"
    chmod 0600 "$manifest_candidate"
    mv -f -- "$manifest_candidate" "$LIVE_HOST_INGRESS_POOL_MANIFEST"

    LIVE_HOST_INGRESS_POOL_SHA256=$(live_host_fixture_sha256 "$LIVE_HOST_INGRESS_POOL_MANIFEST")
    LIVE_HOST_INGRESS_POOL_METADATA=$(live_host_fixture_metadata "$LIVE_HOST_INGRESS_POOL_MANIFEST")
    export LIVE_HOST_INGRESS_POOL_SHA256 LIVE_HOST_INGRESS_POOL_METADATA
}

write_live_host_repin_intents()
{
    local web_a_id=$1 web_b_id=$2 member prefix identifier image_reference image_id intent

    [[ $web_a_id =~ ^[a-f0-9]{64}$ && $web_b_id =~ ^[a-f0-9]{64}$ && $web_a_id != "$web_b_id" ]] \
        || live_host_fixture_fail 'repin intents require both exact distinct member IDs'
    for member in a b; do
        prefix=$(live_host_fixture_member_prefix "$member")
        if [[ $member == a ]]; then
            identifier=$web_a_id
        else
            identifier=$web_b_id
        fi
        image_reference=$(live_host_fixture_value "LIVE_HOST_${prefix}_IMAGE_REFERENCE")
        image_id=$(live_host_fixture_image_id "$image_reference")
        intent=$LIVE_HOST_ROOT/member-$member-repin.intent
        {
            printf 'version=2\noperation_id=%s\nmember_role=web-%s\n' "$LIVE_HOST_OPERATION_ID" "$member"
            printf 'candidate_name=%s\ncandidate_id=%s\n' \
                "$(live_host_fixture_value "LIVE_HOST_GREEN_${prefix}_CONTAINER")" "$identifier"
            printf 'candidate_image_id=%s\ncandidate_image_reference=%s\nrestart_policy=always\n' \
                "$image_id" "$image_reference"
        } > "$intent"
        chown root:root "$intent"
        chmod 0600 "$intent"
    done
}
