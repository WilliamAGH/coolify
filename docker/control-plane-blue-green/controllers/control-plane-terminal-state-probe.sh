#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

declare -a SAFE_SNAPSHOTS=()
declare -A ARTIFACT_SNAPSHOTS=()
declare -A ARTIFACT_IDENTITIES=()

cleanup_safe_snapshots()
{
    local snapshot
    for snapshot in "${SAFE_SNAPSHOTS[@]}"; do
        [[ -n $snapshot ]] && rm -f -- "$snapshot"
    done
}

trap cleanup_safe_snapshots EXIT

fail()
{
    printf 'CONTROL_PLANE_TERMINAL_PROBE_FAILURE %s\n' "$1" >&2
    exit 1
}

sha256_file()
{
    sha256sum "$1" | awk '{print $1}'
}

validate_identifier()
{
    [[ $2 =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ ]] \
        || fail "$1 is not a safe identifier"
}

validate_sha256()
{
    [[ $2 =~ ^[a-f0-9]{64}$ ]] || fail "$1 is not a lowercase SHA-256"
}

validate_authority_token()
{
    [[ $2 =~ ^[A-Za-z0-9._:-]{16,128}$ ]] || fail "$1 is not a safe authority token"
}

assert_distinct_values()
{
    local label=$1 value
    local -A observed=()
    shift
    for value in "$@"; do
        [[ -n $value && -z ${observed[$value]+present} ]] \
            || fail "$label reuses an authority value"
        observed[$value]=1
    done
}

assert_sorted_unique_network_ids()
{
    local label=$1 network_ids=$2 canonical
    canonical=$(tr ',' '\n' <<< "$network_ids" | LC_ALL=C sort -u | paste -sd, -)
    [[ $network_ids == "$canonical" ]] \
        || fail "$label must be sorted and contain no duplicate network IDs"
}

run_safe_read_test_hook()
{
    local stage=$1 label=$2 path=$3 hook=${CONTROL_PLANE_TERMINAL_TEST_SAFE_READ_HOOK:-}
    [[ -n $hook ]] || return 0
    [[ $test_mode == 1 ]] || fail 'safe-read test hook is forbidden outside test mode'
    [[ $hook == /* && -f $hook && ! -L $hook && -x $hook ]] \
        || fail 'safe-read test hook must be an absolute executable non-symlink file'
    "$hook" "$stage" "$label" "$path"
}

safe_snapshot_file()
{
    local path=$1 expected_sha256=$2 expected_metadata=$3 expected_uid=$4 expected_gid=$5
    local expected_mode=$6 label=$7 path_identity fd_identity post_identity snapshot checksum
    local source_metadata source_uid source_gid source_mode source_size
    local resolved descriptor_path fd
    validate_absolute_path "$label path" "$path"
    [[ -f $path && ! -L $path ]] || fail "$label is not a regular non-symlink file"
    resolved=$(readlink -f -- "$path")
    [[ $resolved == "$path" ]] || fail "$label path contains a symlink"
    path_identity=$(stat -c '%d:%i:%f:%u:%g:%a:%s' -- "$path") \
        || fail "$label path identity cannot be read"
    source_metadata=$(stat -c '%u:%g:%a:%s' -- "$path") \
        || fail "$label metadata cannot be read"
    IFS=: read -r source_uid source_gid source_mode source_size <<< "$source_metadata"
    [[ $source_uid == "$expected_uid" && $source_gid == "$expected_gid" \
        && $source_mode == "$expected_mode" && $source_size =~ ^[0-9]+$ ]] \
        || fail "$label has unsafe ownership or mode"
    if [[ -n $expected_metadata ]]; then
        [[ $source_metadata == "$expected_metadata" ]] \
            || fail "$label differs from its pinned metadata"
    fi
    if [[ -n $expected_sha256 ]]; then
        validate_sha256 "$label checksum" "$expected_sha256"
    fi
    exec {fd}<"$path" || fail "$label could not be opened"
    descriptor_path=/proc/self/fd/$fd
    [[ -e $descriptor_path ]] || descriptor_path=/dev/fd/$fd
    fd_identity=$(stat -Lc '%d:%i:%f:%u:%g:%a:%s' -- "$descriptor_path") \
        || fail "$label open descriptor identity cannot be read"
    run_safe_read_test_hook after-open "$label" "$path"
    if [[ $test_mode == 1 && $(uname -s) == Darwin ]]; then
        [[ $(awk -F: '{ print $2 ":" $4 ":" $5 ":" $7 }' <<< "$fd_identity") \
            == "$(awk -F: '{ print $2 ":" $4 ":" $5 ":" $7 }' <<< "$path_identity")" ]] || {
            exec {fd}<&-
            fail "$label changed between path attestation and descriptor open"
        }
    elif [[ $fd_identity != "$path_identity" ]]; then
        exec {fd}<&-
        fail "$label changed between path attestation and descriptor open"
    fi
    snapshot=$(mktemp "${TMPDIR:-/tmp}/coolify-terminal-safe-read.XXXXXX")
    chmod 0600 "$snapshot"
    cat <&"$fd" > "$snapshot" || {
        exec {fd}<&-
        fail "$label descriptor could not be snapshotted"
    }
    exec {fd}<&-
    SAFE_SNAPSHOTS+=("$snapshot")
    run_safe_read_test_hook after-copy "$label" "$path"
    [[ -f $path && ! -L $path && $(readlink -f -- "$path") == "$path" ]] \
        || fail "$label path changed after descriptor read"
    post_identity=$(stat -c '%d:%i:%f:%u:%g:%a:%s' -- "$path") \
        || fail "$label post-read identity cannot be read"
    [[ $post_identity == "$path_identity" ]] \
        || fail "$label changed while its descriptor was being read"
    checksum=$(sha256_file "$snapshot")
    if [[ -n $expected_sha256 ]]; then
        [[ $checksum == "$expected_sha256" ]] \
            || fail "$label differs from its pinned checksum"
    fi
    SAFE_SNAPSHOT_PATH=$snapshot
    SAFE_SOURCE_IDENTITY=$(awk -F: '{ print $1 ":" $2 }' <<< "$path_identity")
    SAFE_SOURCE_METADATA=$source_metadata
    SAFE_SOURCE_SHA256=$checksum
}

document_value()
{
    local file=$1 key=$2 count result
    count=$(grep -E -c "^${key}=" "$file" || true)
    [[ $count -eq 1 ]] || fail "document key is absent or duplicated: $key"
    result=$(sed -n "s/^${key}=//p" "$file")
    [[ $result != *$'\n'* ]] || fail "document value contains a newline: $key"
    printf '%s\n' "$result"
}

assert_exact_document_keys()
{
    local file=$1 expected_keys=$2 label=$3 actual_keys
    actual_keys=$(sed 's/=.*//' "$file")
    [[ $actual_keys == "$expected_keys" ]] \
        || fail "$label keys are reordered, duplicated, absent, or unknown"
    [[ $(wc -l < "$file") -eq $(wc -l <<< "$expected_keys") ]] \
        || fail "$label record count is not canonical"
}

plan_member_keys()
{
    local member=$1
    printf '%s\n' \
        "member_${member}_role" \
        "member_${member}_name" \
        "member_${member}_route_identity" \
        "member_${member}_image_reference" \
        "member_${member}_image_id" \
        "member_${member}_network_ids" \
        "member_${member}_private_volume" \
        "member_${member}_expected_loopback_port" \
        "member_${member}_repin_intent_file" \
        "member_${member}_direct_probe_token_file" \
        "member_${member}_direct_probe_token_sha256" \
        "member_${member}_direct_probe_token_metadata" \
        "member_${member}_direct_probe_runtime_file" \
        "member_${member}_direct_probe_runtime_sha256" \
        "member_${member}_direct_probe_runtime_metadata" \
        "member_${member}_applied_ack_file" \
        "member_${member}_applied_ack_sha256" \
        "member_${member}_applied_ack_metadata" \
        "member_${member}_applied_ack_runtime_file" \
        "member_${member}_applied_ack_runtime_sha256" \
        "member_${member}_applied_ack_runtime_metadata" \
        "member_${member}_web_epoch" \
        "member_${member}_web_marker_path" \
        "member_${member}_route_drain_epoch" \
        "member_${member}_route_drain_marker_path" \
        "member_${member}_writer_epoch" \
        "member_${member}_writer_marker_path"
}

pool_plan_keys()
{
    printf '%s\n' version operation_id direction color generation coordination_volume \
        mutation_freeze_epoch mutation_freeze_marker_path mutation_lease_path \
        route_health_path route_health_token_file route_health_token_sha256 \
        route_health_token_metadata route_health_runtime_file route_health_runtime_sha256 \
        route_health_runtime_metadata pool_ack_file pool_ack_sha256 pool_ack_metadata \
        pool_ack_runtime_file pool_ack_runtime_sha256 pool_ack_runtime_metadata \
        backend_port member_count
    plan_member_keys a
    plan_member_keys b
    printf '%s\n' writer_member pool_label_key pool_label_value retired_member_count \
        retired_member_a_name retired_member_a_id
    if [[ $(document_value "$pool_plan_manifest" retired_member_count) == 2 ]]; then
        printf '%s\n' retired_member_b_name retired_member_b_id
    fi
    printf '%s\n' ingress_pool_manifest_path pool_plan_set_sha256
}

ingress_member_keys()
{
    local member=$1
    printf '%s\n' \
        "member_${member}_role" \
        "member_${member}_name" \
        "member_${member}_route_identity" \
        "member_${member}_id" \
        "member_${member}_address" \
        "member_${member}_port" \
        "member_${member}_image_reference" \
        "member_${member}_image_id" \
        "member_${member}_runtime_sha256" \
        "member_${member}_network_sha256" \
        "member_${member}_bindings_sha256" \
        "member_${member}_applied_ack_file" \
        "member_${member}_applied_ack_sha256" \
        "member_${member}_applied_ack_metadata"
}

ingress_pool_keys()
{
    printf '%s\n' version operation_id direction color generation parent_pool_plan_sha256 \
        pool_ack_file pool_ack_sha256 pool_ack_metadata member_count
    ingress_member_keys a
    ingress_member_keys b
    printf '%s\n' writer_member pool_label_key pool_label_value member_set_sha256 \
        route_health_path route_health_token_file route_health_token_sha256 \
        route_health_token_metadata
}

validate_absolute_path()
{
    [[ $2 == /* && $2 != *'//'* && $2 =~ ^/[A-Za-z0-9_./-]+$ \
        && $2 != '/..' && $2 != '/../'* && $2 != *'/../'* && $2 != *'/..' ]] \
        || fail "$1 is not a canonical absolute path"
}

validate_artifact_from_document()
{
    local document=$1 prefix=$2 label=$3 expected_uid=${4:-$immutable_uid}
    local expected_gid=${5:-$immutable_gid} expected_mode=${6:-600}
    local path checksum metadata
    path=$(document_value "$document" "${prefix}_file")
    checksum=$(document_value "$document" "${prefix}_sha256")
    metadata=$(document_value "$document" "${prefix}_metadata")
    validate_absolute_path "$label path" "$path"
    safe_snapshot_file "$path" "$checksum" "$metadata" "$expected_uid" "$expected_gid" \
        "$expected_mode" "$label"
    ARTIFACT_SNAPSHOTS[$path]=$SAFE_SNAPSHOT_PATH
    ARTIFACT_IDENTITIES[$path]=$SAFE_SOURCE_IDENTITY
}

document_artifact_identity()
{
    local document=$1 prefix=$2 path
    path=$(document_value "$document" "${prefix}_file")
    [[ -n ${ARTIFACT_IDENTITIES[$path]+present} ]] \
        || fail "document artifact identity was not safely read: $prefix"
    printf '%s\n' "${ARTIFACT_IDENTITIES[$path]}"
}

release_files_manifest_sha256()
{
    local role path expected_sha256 expected_metadata expected_uid expected_gid expected_mode
    while IFS='|' read -r role path expected_sha256 expected_metadata expected_uid expected_gid \
        expected_mode; do
        if [[ -z $path ]]; then
            [[ $role == provider-header ]] \
                || fail "required release authorization file path is absent: $role"
            printf '%s|absent\n' "$role"
            continue
        fi
        safe_snapshot_file "$path" "$expected_sha256" "$expected_metadata" \
            "$expected_uid" "$expected_gid" "$expected_mode" "$role"
        printf '%s|%s|%s|%s\n' "$role" "$path" "$SAFE_SOURCE_SHA256" \
            "$SAFE_SOURCE_METADATA"
    done <<EOF | sha256sum | awk '{print $1}'
provider-header|${CONTROL_PLANE_RUNTIME_PROVIDER_HEADER_FILE:-}|||$immutable_uid|$immutable_gid|600
pool-plan|$pool_plan_source_path|$pool_plan_manifest_sha256|${CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_METADATA:-}|$immutable_uid|$immutable_gid|600
route-health-token|$(document_value "$pool_plan_manifest" route_health_token_file)|$(document_value "$pool_plan_manifest" route_health_token_sha256)|$(document_value "$pool_plan_manifest" route_health_token_metadata)|$immutable_uid|$immutable_gid|600
route-health-runtime|$(document_value "$pool_plan_manifest" route_health_runtime_file)|$(document_value "$pool_plan_manifest" route_health_runtime_sha256)|$(document_value "$pool_plan_manifest" route_health_runtime_metadata)|$runtime_artifact_uid|$runtime_artifact_gid|400
pool-ack|$(document_value "$pool_plan_manifest" pool_ack_file)|$(document_value "$pool_plan_manifest" pool_ack_sha256)|$(document_value "$pool_plan_manifest" pool_ack_metadata)|$immutable_uid|$immutable_gid|600
pool-ack-runtime|$(document_value "$pool_plan_manifest" pool_ack_runtime_file)|$(document_value "$pool_plan_manifest" pool_ack_runtime_sha256)|$(document_value "$pool_plan_manifest" pool_ack_runtime_metadata)|$runtime_artifact_uid|$runtime_artifact_gid|400
web-a-direct-probe-token|$(document_value "$pool_plan_manifest" member_a_direct_probe_token_file)|$(document_value "$pool_plan_manifest" member_a_direct_probe_token_sha256)|$(document_value "$pool_plan_manifest" member_a_direct_probe_token_metadata)|$immutable_uid|$immutable_gid|600
web-a-direct-probe-runtime|$(document_value "$pool_plan_manifest" member_a_direct_probe_runtime_file)|$(document_value "$pool_plan_manifest" member_a_direct_probe_runtime_sha256)|$(document_value "$pool_plan_manifest" member_a_direct_probe_runtime_metadata)|$runtime_artifact_uid|$runtime_artifact_gid|400
web-a-applied-ack|$(document_value "$pool_plan_manifest" member_a_applied_ack_file)|$(document_value "$pool_plan_manifest" member_a_applied_ack_sha256)|$(document_value "$pool_plan_manifest" member_a_applied_ack_metadata)|$immutable_uid|$immutable_gid|600
web-a-applied-ack-runtime|$(document_value "$pool_plan_manifest" member_a_applied_ack_runtime_file)|$(document_value "$pool_plan_manifest" member_a_applied_ack_runtime_sha256)|$(document_value "$pool_plan_manifest" member_a_applied_ack_runtime_metadata)|$runtime_artifact_uid|$runtime_artifact_gid|400
web-b-direct-probe-token|$(document_value "$pool_plan_manifest" member_b_direct_probe_token_file)|$(document_value "$pool_plan_manifest" member_b_direct_probe_token_sha256)|$(document_value "$pool_plan_manifest" member_b_direct_probe_token_metadata)|$immutable_uid|$immutable_gid|600
web-b-direct-probe-runtime|$(document_value "$pool_plan_manifest" member_b_direct_probe_runtime_file)|$(document_value "$pool_plan_manifest" member_b_direct_probe_runtime_sha256)|$(document_value "$pool_plan_manifest" member_b_direct_probe_runtime_metadata)|$runtime_artifact_uid|$runtime_artifact_gid|400
web-b-applied-ack|$(document_value "$pool_plan_manifest" member_b_applied_ack_file)|$(document_value "$pool_plan_manifest" member_b_applied_ack_sha256)|$(document_value "$pool_plan_manifest" member_b_applied_ack_metadata)|$immutable_uid|$immutable_gid|600
web-b-applied-ack-runtime|$(document_value "$pool_plan_manifest" member_b_applied_ack_runtime_file)|$(document_value "$pool_plan_manifest" member_b_applied_ack_runtime_sha256)|$(document_value "$pool_plan_manifest" member_b_applied_ack_runtime_metadata)|$runtime_artifact_uid|$runtime_artifact_gid|400
EOF
}

pool_plan_set_sha256()
{
    local member key
    for member in a b; do
        while IFS= read -r key; do
            printf '%s\0' "$(document_value "$pool_plan_manifest" "$key")"
        done < <(plan_member_keys "$member")
    done
    printf '%s\0' "$(document_value "$pool_plan_manifest" writer_member)"
}

validate_pool_plan()
{
    local expected_keys direction retired_count member role writer_member key writer_marker volume
    local -a marker_authorities=()
    expected_keys=$(pool_plan_keys)
    assert_exact_document_keys "$pool_plan_manifest" "$expected_keys" pool-plan-manifest
    [[ $(document_value "$pool_plan_manifest" version) == 2 \
        && $(document_value "$pool_plan_manifest" operation_id) == "$operation_id" \
        && $(document_value "$pool_plan_manifest" member_count) == 2 ]] \
        || fail 'pool plan does not belong to this exact two-member operation'
    direction=$(document_value "$pool_plan_manifest" direction)
    [[ $direction =~ ^(bootstrap-forward|forward|reverse)$ ]] \
        || fail 'pool plan direction is invalid'
    [[ $(document_value "$pool_plan_manifest" color) =~ ^(green|blue)$ \
        && $(document_value "$pool_plan_manifest" generation) =~ ^[1-9][0-9]*$ \
        && $(document_value "$pool_plan_manifest" backend_port) =~ ^[1-9][0-9]{0,4}$ ]] \
        || fail 'pool plan generation, color, or backend port is malformed'
    validate_authority_token mutation-freeze-epoch \
        "$(document_value "$pool_plan_manifest" mutation_freeze_epoch)"
    ((10#$(document_value "$pool_plan_manifest" backend_port) <= 65535)) \
        || fail 'pool plan backend port exceeds 65535'
    writer_member=$(document_value "$pool_plan_manifest" writer_member)
    [[ $writer_member == web-a || $writer_member == web-b ]] \
        || fail 'pool plan must declare exactly one writer member'
    validate_identifier pool-label-key "$(document_value "$pool_plan_manifest" pool_label_key)"
    validate_identifier pool-label-value "$(document_value "$pool_plan_manifest" pool_label_value)"
    validate_identifier coordination-volume "$(document_value "$pool_plan_manifest" coordination_volume)"
    for key in mutation_freeze_marker_path mutation_lease_path ingress_pool_manifest_path; do
        validate_absolute_path "$key" "$(document_value "$pool_plan_manifest" "$key")"
    done
    [[ $(document_value "$pool_plan_manifest" route_health_path) \
        == /api/control-plane/route-health ]] \
        || fail 'pool plan route-health path is not the canonical endpoint'
    validate_artifact_from_document "$pool_plan_manifest" route_health_token route-health-token
    validate_artifact_from_document "$pool_plan_manifest" route_health_runtime \
        route-health-runtime "$runtime_artifact_uid" "$runtime_artifact_gid" 400
    validate_artifact_from_document "$pool_plan_manifest" pool_ack pool-ack
    validate_artifact_from_document "$pool_plan_manifest" pool_ack_runtime pool-ack-runtime \
        "$runtime_artifact_uid" "$runtime_artifact_gid" 400
    [[ $(document_value "$pool_plan_manifest" route_health_token_sha256) \
            == "$(document_value "$pool_plan_manifest" route_health_runtime_sha256)" \
        && $(document_value "$pool_plan_manifest" pool_ack_sha256) \
            == "$(document_value "$pool_plan_manifest" pool_ack_runtime_sha256)" ]] \
        || fail 'pool root/runtime artifact copies do not contain identical pinned bytes'
    for member in a b; do
        role=$(document_value "$pool_plan_manifest" "member_${member}_role")
        [[ $role == "web-$member" ]] || fail 'pool plan member roles are not canonical web-a/web-b'
        validate_identifier "member-$member name" \
            "$(document_value "$pool_plan_manifest" "member_${member}_name")"
        validate_authority_token "member-$member route identity" \
            "$(document_value "$pool_plan_manifest" "member_${member}_route_identity")"
        [[ $(document_value "$pool_plan_manifest" "member_${member}_image_reference") \
                =~ ^[A-Za-z0-9][A-Za-z0-9._/:@-]*@sha256:[a-f0-9]{64}$ \
            && $(document_value "$pool_plan_manifest" "member_${member}_image_id") \
                =~ ^sha256:[a-f0-9]{64}$ \
            && $(document_value "$pool_plan_manifest" "member_${member}_network_ids") \
                =~ ^[a-f0-9]{64}(,[a-f0-9]{64})*$ \
            && $(document_value "$pool_plan_manifest" "member_${member}_expected_loopback_port") \
                =~ ^[1-9][0-9]{0,4}$ ]] \
            || fail "pool plan member $member immutable runtime plan is malformed"
        ((10#$(document_value "$pool_plan_manifest" \
            "member_${member}_expected_loopback_port") <= 65535)) \
            || fail "pool plan member $member loopback port exceeds 65535"
        assert_sorted_unique_network_ids "member-$member network plan" \
            "$(document_value "$pool_plan_manifest" "member_${member}_network_ids")"
        validate_authority_token "member-$member web epoch" \
            "$(document_value "$pool_plan_manifest" "member_${member}_web_epoch")"
        validate_authority_token "member-$member route-drain epoch" \
            "$(document_value "$pool_plan_manifest" "member_${member}_route_drain_epoch")"
        validate_identifier "member-$member private volume" \
            "$(document_value "$pool_plan_manifest" "member_${member}_private_volume")"
        for key in repin_intent_file web_marker_path route_drain_marker_path; do
            validate_absolute_path "member-$member $key" \
                "$(document_value "$pool_plan_manifest" "member_${member}_${key}")"
        done
        validate_artifact_from_document "$pool_plan_manifest" \
            "member_${member}_direct_probe_token" "member-$member-direct-probe-token"
        validate_artifact_from_document "$pool_plan_manifest" \
            "member_${member}_direct_probe_runtime" "member-$member-direct-probe-runtime" \
            "$runtime_artifact_uid" "$runtime_artifact_gid" 400
        validate_artifact_from_document "$pool_plan_manifest" \
            "member_${member}_applied_ack" "member-$member-applied-ack"
        validate_artifact_from_document "$pool_plan_manifest" \
            "member_${member}_applied_ack_runtime" "member-$member-applied-ack-runtime" \
            "$runtime_artifact_uid" "$runtime_artifact_gid" 400
        [[ $(document_value "$pool_plan_manifest" \
                "member_${member}_direct_probe_token_sha256") \
                == "$(document_value "$pool_plan_manifest" \
                    "member_${member}_direct_probe_runtime_sha256")" \
            && $(document_value "$pool_plan_manifest" \
                "member_${member}_applied_ack_sha256") \
                == "$(document_value "$pool_plan_manifest" \
                    "member_${member}_applied_ack_runtime_sha256")" ]] \
            || fail "member $member root/runtime artifact copies do not contain identical pinned bytes"
        if [[ $role == "$writer_member" ]]; then
            validate_authority_token "member-$member writer epoch" \
                "$(document_value "$pool_plan_manifest" "member_${member}_writer_epoch")"
            validate_absolute_path "member-$member writer marker" \
                "$(document_value "$pool_plan_manifest" "member_${member}_writer_marker_path")"
        else
            [[ $(document_value "$pool_plan_manifest" "member_${member}_writer_epoch") == absent \
                && $(document_value "$pool_plan_manifest" "member_${member}_writer_marker_path") == absent ]] \
                || fail 'non-writer member has writer marker authority'
        fi
    done
    assert_distinct_values 'pool artifact device/inode identity' \
        "$pool_plan_source_identity" \
        "$(document_artifact_identity "$pool_plan_manifest" route_health_token)" \
        "$(document_artifact_identity "$pool_plan_manifest" route_health_runtime)" \
        "$(document_artifact_identity "$pool_plan_manifest" pool_ack)" \
        "$(document_artifact_identity "$pool_plan_manifest" pool_ack_runtime)" \
        "$(document_artifact_identity "$pool_plan_manifest" member_a_direct_probe_token)" \
        "$(document_artifact_identity "$pool_plan_manifest" member_a_direct_probe_runtime)" \
        "$(document_artifact_identity "$pool_plan_manifest" member_a_applied_ack)" \
        "$(document_artifact_identity "$pool_plan_manifest" member_a_applied_ack_runtime)" \
        "$(document_artifact_identity "$pool_plan_manifest" member_b_direct_probe_token)" \
        "$(document_artifact_identity "$pool_plan_manifest" member_b_direct_probe_runtime)" \
        "$(document_artifact_identity "$pool_plan_manifest" member_b_applied_ack)" \
        "$(document_artifact_identity "$pool_plan_manifest" member_b_applied_ack_runtime)"
    assert_distinct_values 'pool member identity' \
        "$(document_value "$pool_plan_manifest" member_a_name)" \
        "$(document_value "$pool_plan_manifest" member_b_name)"
    assert_distinct_values 'pool route identity' \
        "$(document_value "$pool_plan_manifest" member_a_route_identity)" \
        "$(document_value "$pool_plan_manifest" member_b_route_identity)"
    assert_distinct_values 'pool loopback port' \
        "$(document_value "$pool_plan_manifest" member_a_expected_loopback_port)" \
        "$(document_value "$pool_plan_manifest" member_b_expected_loopback_port)"
    assert_distinct_values 'pool volume authority' \
        "$(document_value "$pool_plan_manifest" coordination_volume)" \
        "$(document_value "$pool_plan_manifest" member_a_private_volume)" \
        "$(document_value "$pool_plan_manifest" member_b_private_volume)"
    assert_distinct_values 'pool direct-probe content' \
        "$(document_value "$pool_plan_manifest" member_a_direct_probe_token_sha256)" \
        "$(document_value "$pool_plan_manifest" member_b_direct_probe_token_sha256)"
    assert_distinct_values 'pool applied-ack content' \
        "$(document_value "$pool_plan_manifest" member_a_applied_ack_sha256)" \
        "$(document_value "$pool_plan_manifest" member_b_applied_ack_sha256)"
    assert_distinct_values 'pool web epoch' \
        "$(document_value "$pool_plan_manifest" member_a_web_epoch)" \
        "$(document_value "$pool_plan_manifest" member_b_web_epoch)"
    assert_distinct_values 'pool route-drain epoch' \
        "$(document_value "$pool_plan_manifest" member_a_route_drain_epoch)" \
        "$(document_value "$pool_plan_manifest" member_b_route_drain_epoch)"
    assert_distinct_values 'pool authority path' \
        "$pool_plan_source_path" \
        "$(document_value "$pool_plan_manifest" mutation_freeze_marker_path)" \
        "$(document_value "$pool_plan_manifest" mutation_lease_path)" \
        "$(document_value "$pool_plan_manifest" ingress_pool_manifest_path)" \
        "$(document_value "$pool_plan_manifest" route_health_token_file)" \
        "$(document_value "$pool_plan_manifest" route_health_runtime_file)" \
        "$(document_value "$pool_plan_manifest" pool_ack_file)" \
        "$(document_value "$pool_plan_manifest" pool_ack_runtime_file)" \
        "$(document_value "$pool_plan_manifest" member_a_repin_intent_file)" \
        "$(document_value "$pool_plan_manifest" member_b_repin_intent_file)" \
        "$(document_value "$pool_plan_manifest" member_a_direct_probe_token_file)" \
        "$(document_value "$pool_plan_manifest" member_b_direct_probe_token_file)" \
        "$(document_value "$pool_plan_manifest" member_a_direct_probe_runtime_file)" \
        "$(document_value "$pool_plan_manifest" member_b_direct_probe_runtime_file)" \
        "$(document_value "$pool_plan_manifest" member_a_applied_ack_file)" \
        "$(document_value "$pool_plan_manifest" member_b_applied_ack_file)" \
        "$(document_value "$pool_plan_manifest" member_a_applied_ack_runtime_file)" \
        "$(document_value "$pool_plan_manifest" member_b_applied_ack_runtime_file)"
    for member in a b; do
        volume=$(document_value "$pool_plan_manifest" "member_${member}_private_volume")
        marker_authorities+=(
            "$volume|$(document_value "$pool_plan_manifest" "member_${member}_web_marker_path")"
            "$volume|$(document_value "$pool_plan_manifest" \
                "member_${member}_route_drain_marker_path")"
        )
        writer_marker=$(document_value "$pool_plan_manifest" \
            "member_${member}_writer_marker_path")
        [[ $writer_marker == absent ]] || marker_authorities+=("$volume|$writer_marker")
    done
    assert_distinct_values 'pool private marker authority tuple' "${marker_authorities[@]}"
    retired_count=$(document_value "$pool_plan_manifest" retired_member_count)
    case "$direction:$retired_count" in
        bootstrap-forward:1) ;;
        forward:2|reverse:2) ;;
        *) fail 'pool plan retired set contradicts its migration direction' ;;
    esac
    validate_identifier retired-member-a-name \
        "$(document_value "$pool_plan_manifest" retired_member_a_name)"
    [[ $(document_value "$pool_plan_manifest" retired_member_a_id) =~ ^[a-f0-9]{64}$ ]] \
        || fail 'retired member A ID is malformed'
    if [[ $retired_count == 2 ]]; then
        validate_identifier retired-member-b-name \
            "$(document_value "$pool_plan_manifest" retired_member_b_name)"
        [[ $(document_value "$pool_plan_manifest" retired_member_b_id) =~ ^[a-f0-9]{64}$ \
            && $(document_value "$pool_plan_manifest" retired_member_a_name) \
                != "$(document_value "$pool_plan_manifest" retired_member_b_name)" \
            && $(document_value "$pool_plan_manifest" retired_member_a_id) \
                != "$(document_value "$pool_plan_manifest" retired_member_b_id)" ]] \
            || fail 'retired two-member set is malformed or duplicated'
    fi
    [[ $(document_value "$pool_plan_manifest" member_a_name) \
            != "$(document_value "$pool_plan_manifest" retired_member_a_name)" \
        && $(document_value "$pool_plan_manifest" member_b_name) \
            != "$(document_value "$pool_plan_manifest" retired_member_a_name)" ]] \
        || fail 'planned member name collides with retired member A'
    if [[ $retired_count == 2 ]]; then
        [[ $(document_value "$pool_plan_manifest" member_a_name) \
                != "$(document_value "$pool_plan_manifest" retired_member_b_name)" \
            && $(document_value "$pool_plan_manifest" member_b_name) \
                != "$(document_value "$pool_plan_manifest" retired_member_b_name)" ]] \
            || fail 'planned member name collides with retired member B'
    fi
    validate_sha256 pool-plan-set "$(document_value "$pool_plan_manifest" pool_plan_set_sha256)"
    [[ $(pool_plan_set_sha256 | sha256sum | awk '{print $1}') \
        == "$(document_value "$pool_plan_manifest" pool_plan_set_sha256)" ]] \
        || fail 'pool plan set hash does not match its canonical NUL-delimited member tuple'
}

ingress_member_set_bytes()
{
    local member key
    printf '%s\0' "$(document_value "$ingress_pool_manifest" generation)" \
        "$(document_value "$ingress_pool_manifest" parent_pool_plan_sha256)"
    for member in a b; do
        while IFS= read -r key; do
            printf '%s\0' "$(document_value "$ingress_pool_manifest" "$key")"
        done < <(ingress_member_keys "$member")
    done
    printf '%s\0' "$(document_value "$ingress_pool_manifest" writer_member)" \
        "$(document_value "$ingress_pool_manifest" pool_label_key)" \
        "$(document_value "$ingress_pool_manifest" pool_label_value)"
}

validate_ingress_pool()
{
    local expected_keys member role key
    expected_keys=$(ingress_pool_keys)
    assert_exact_document_keys "$ingress_pool_manifest" "$expected_keys" ingress-pool-manifest
    [[ $(document_value "$ingress_pool_manifest" version) == 2 \
        && $(document_value "$ingress_pool_manifest" operation_id) == "$operation_id" \
        && $(document_value "$ingress_pool_manifest" direction) \
            == "$(document_value "$pool_plan_manifest" direction)" \
        && $(document_value "$ingress_pool_manifest" color) \
            == "$(document_value "$pool_plan_manifest" color)" \
        && $(document_value "$ingress_pool_manifest" generation) \
            == "$(document_value "$pool_plan_manifest" generation)" \
        && $(document_value "$ingress_pool_manifest" parent_pool_plan_sha256) \
            == "$pool_plan_manifest_sha256" \
        && $(document_value "$ingress_pool_manifest" member_count) == 2 ]] \
        || fail 'ingress pool does not descend from this exact two-member plan generation'
    for key in pool_ack_file pool_ack_sha256 pool_ack_metadata route_health_path \
        route_health_token_file route_health_token_sha256 route_health_token_metadata \
        writer_member pool_label_key pool_label_value; do
        [[ $(document_value "$ingress_pool_manifest" "$key") \
            == "$(document_value "$pool_plan_manifest" "$key")" ]] \
            || fail "ingress pool changed a plan-owned semantic input: $key"
    done
    validate_artifact_from_document "$ingress_pool_manifest" pool_ack ingress-pool-ack
    validate_artifact_from_document "$ingress_pool_manifest" route_health_token ingress-route-health-token
    for member in a b; do
        role=$(document_value "$ingress_pool_manifest" "member_${member}_role")
        [[ $role == "web-$member" \
            && $(document_value "$ingress_pool_manifest" "member_${member}_name") \
                == "$(document_value "$pool_plan_manifest" "member_${member}_name")" \
            && $(document_value "$ingress_pool_manifest" "member_${member}_route_identity") \
                == "$(document_value "$pool_plan_manifest" "member_${member}_route_identity")" \
            && $(document_value "$ingress_pool_manifest" "member_${member}_image_reference") \
                == "$(document_value "$pool_plan_manifest" "member_${member}_image_reference")" \
            && $(document_value "$ingress_pool_manifest" "member_${member}_image_id") \
                == "$(document_value "$pool_plan_manifest" "member_${member}_image_id")" \
            && $(document_value "$ingress_pool_manifest" "member_${member}_applied_ack_file") \
                == "$(document_value "$pool_plan_manifest" "member_${member}_applied_ack_file")" \
            && $(document_value "$ingress_pool_manifest" "member_${member}_applied_ack_sha256") \
                == "$(document_value "$pool_plan_manifest" "member_${member}_applied_ack_sha256")" \
            && $(document_value "$ingress_pool_manifest" "member_${member}_applied_ack_metadata") \
                == "$(document_value "$pool_plan_manifest" "member_${member}_applied_ack_metadata")" \
            && $(document_value "$ingress_pool_manifest" "member_${member}_id") \
                =~ ^[a-f0-9]{64}$ \
            && $(document_value "$ingress_pool_manifest" "member_${member}_address") \
                =~ ^[A-Fa-f0-9:.]+$ \
            && $(document_value "$ingress_pool_manifest" "member_${member}_port") \
                == "$(document_value "$pool_plan_manifest" backend_port)" ]] \
            || fail "ingress pool member $member differs from its immutable plan"
        for key in runtime_sha256 network_sha256 bindings_sha256; do
            validate_sha256 "member-$member $key" \
                "$(document_value "$ingress_pool_manifest" "member_${member}_${key}")"
        done
        validate_artifact_from_document "$ingress_pool_manifest" \
            "member_${member}_applied_ack" "ingress-member-$member-applied-ack"
    done
    [[ $(document_value "$ingress_pool_manifest" member_a_id) \
            != "$(document_value "$ingress_pool_manifest" member_b_id)" \
        && $(document_value "$ingress_pool_manifest" member_a_name) \
            != "$(document_value "$ingress_pool_manifest" member_b_name)" ]] \
        || fail 'ingress pool duplicates a member identity'
    assert_distinct_values 'ingress route and Docker identity' \
        "$(document_value "$ingress_pool_manifest" member_a_route_identity)" \
        "$(document_value "$ingress_pool_manifest" member_b_route_identity)" \
        "$(document_value "$ingress_pool_manifest" member_a_id)" \
        "$(document_value "$ingress_pool_manifest" member_b_id)"
    validate_sha256 member-set "$(document_value "$ingress_pool_manifest" member_set_sha256)"
    [[ $(ingress_member_set_bytes | sha256sum | awk '{print $1}') \
        == "$(document_value "$ingress_pool_manifest" member_set_sha256)" ]] \
        || fail 'ingress member-set hash does not match its canonical NUL-delimited tuple'
}

read_token()
{
    local file=$1 token size
    token=$(<"$file")
    size=$(wc -c < "$file" | tr -d '[:space:]')
    [[ $size == "${#token}" && $token =~ ^[A-Za-z0-9._:-]{16,128}$ ]] \
        || fail 'pinned acknowledgement artifact is malformed or contains a newline'
    printf '%s\n' "$token"
}

probe_ack()
{
    local url=$1 header=$2 token=$3 output count value
    output=$(mktemp /tmp/control-plane-terminal-headers.XXXXXX)
    curl --fail --silent --show-error --max-time 5 --dump-header "$output" --output /dev/null "$url"
    count=$(awk -v header="$header" 'BEGIN { IGNORECASE=1 } index(tolower($0), tolower(header) ":") == 1 { count++ } END { print count+0 }' "$output")
    value=$(awk -v header="$header" 'BEGIN { IGNORECASE=1 } index(tolower($0), tolower(header) ":") == 1 { sub(/^[^:]+:[[:space:]]*/, ""); gsub(/\r/, ""); print }' "$output")
    rm -f -- "$output"
    [[ $count -eq 1 && $value == "$token" ]] \
        || fail "route did not return one exact $header pool acknowledgement"
}

docker_request()
{
    local resource=$1 response_file http_status curl_status
    response_file=$(mktemp /tmp/control-plane-terminal-docker.XXXXXX)
    set +e
    http_status=$(curl --silent --show-error --max-time 5 --unix-socket "$docker_socket" \
        --output "$response_file" --write-out '%{http_code}' "http://localhost/$resource")
    curl_status=$?
    set -e
    if ((curl_status != 0)); then
        rm -f -- "$response_file"
        fail 'Docker Engine API is unavailable'
    fi
    [[ $http_status =~ ^[0-9]{3}$ ]] || {
        rm -f -- "$response_file"
        fail 'Docker Engine API returned a malformed HTTP status'
    }
    docker_http_status=$http_status
    docker_response_file=$response_file
}

inspect_docker_container()
{
    local reference=$1
    docker_request "containers/${reference}/json"
    case "$docker_http_status" in
        200)
            if ! docker_container_json=$(jq -e -c -s '
                select(length == 1 and (.[0] | type == "object"
                    and (.Id | type == "string" and test("^[a-f0-9]{64}$"))
                    and (.Name | type == "string"))) | .[0]
            ' "$docker_response_file"); then
                rm -f -- "$docker_response_file"
                fail 'Docker Engine API returned malformed container inspection data'
            fi
            docker_container_state=present
            ;;
        404)
            jq -e -s --arg reference "$reference" '
                length == 1 and (.[0] | type == "object"
                    and .message == ("No such container: " + $reference))
            ' "$docker_response_file" >/dev/null || {
                rm -f -- "$docker_response_file"
                fail 'Docker Engine API returned an invalid not-found response'
            }
            docker_container_json=
            docker_container_state=absent
            ;;
        *)
            rm -f -- "$docker_response_file"
            fail "Docker Engine API returned HTTP $docker_http_status"
            ;;
    esac
    rm -f -- "$docker_response_file"
}

network_sha256()
{
    jq -S -c '
        [.NetworkSettings.Networks
            | to_entries[]
            | {
                name: .key,
                network_id: .value.NetworkID,
                endpoint_id: (.value.EndpointID // ""),
                ipv4_address: (.value.IPAddress // ""),
                ipv4_gateway: (.value.Gateway // ""),
                ipv6_address: (.value.GlobalIPv6Address // ""),
                ipv6_gateway: (.value.IPv6Gateway // ""),
                mac_address: (.value.MacAddress // "")
            }]
        | sort_by(.network_id, .name)
    ' <<< "$1" | sha256sum | awk '{print $1}'
}

bindings_sha256()
{
    jq -S -c '{
        configured_bindings: (.HostConfig.PortBindings // {}),
        published_bindings: (.NetworkSettings.Ports // {})
    }' <<< "$1" | sha256sum | awk '{print $1}'
}

prepare_runtime_attestors()
{
    local controller_path queue_path
    controller_path=${CONTROL_PLANE_RUNTIME_CONTROLLER_PATH:-}
    queue_path=${CONTROL_PLANE_RUNTIME_QUEUE_PROBE:-}
    safe_snapshot_file "$controller_path" "${CONTROL_PLANE_RUNTIME_CONTROLLER_SHA256:-}" '' \
        "$immutable_uid" "$immutable_gid" 700 runtime-digest-controller
    runtime_digest_controller=$SAFE_SNAPSHOT_PATH
    chmod 0700 "$runtime_digest_controller"
    safe_snapshot_file "$queue_path" "${CONTROL_PLANE_RUNTIME_QUEUE_PROBE_SHA256:-}" '' \
        "$immutable_uid" "$immutable_gid" 700 queue-probe
    queue_probe_snapshot=$SAFE_SNAPSHOT_PATH
    queue_probe_sha256=${CONTROL_PLANE_RUNTIME_QUEUE_PROBE_SHA256:-}
    chmod 0700 "$queue_probe_snapshot"
}

live_runtime_sha256()
{
    local container=$1 digest
    digest=$(CONTROL_PLANE_RUNTIME_TEST_MODE="$test_mode" \
        CONTROL_PLANE_RUNTIME_DOCKER_SOCKET="$docker_socket" \
        "$runtime_digest_controller" container-runtime-sha256 "$container") \
        || fail "live runtime-v4 digest failed for $container"
    validate_sha256 "live runtime-v4 digest for $container" "$digest"
    printf '%s\n' "$digest"
}

collect_queue_zero_evidence()
{
    local output now
    output=$(mktemp /tmp/control-plane-terminal-queue.XXXXXX)
    CONTROL_PLANE_RUNTIME_OPERATION_ID="$operation_id" \
    CONTROL_PLANE_RUNTIME_QUEUE_CONTAINER="$(document_value "$pool_plan_manifest" \
        "member_${writer_member#web-}_name")" \
        "$queue_probe_snapshot" > "$output" \
        || fail 'terminal queue-zero probe failed'
    [[ $(wc -l < "$output") -eq 7 \
        && $(grep -E -c '^(version=2|pending=0|reserved=0|delayed=0|running=0|observed_at_epoch=[1-9][0-9]*|operation_id=[A-Za-z0-9_.-]+)$' \
            "$output") -eq 7 \
        && $(sed -n 's/^version=//p' "$output") == 2 \
        && $(sed -n 's/^operation_id=//p' "$output") == "$operation_id" ]] \
        || fail 'terminal queue evidence was not exactly zero and operation-bound'
    queue_observed_at_epoch=$(sed -n 's/^observed_at_epoch=//p' "$output")
    now=$(date -u +%s)
    ((queue_observed_at_epoch <= now + 5 \
        && now - queue_observed_at_epoch <= probe_max_age_seconds)) \
        || fail 'terminal queue evidence is stale or from the future'
    queue_pending=$(sed -n 's/^pending=//p' "$output")
    queue_reserved=$(sed -n 's/^reserved=//p' "$output")
    queue_delayed=$(sed -n 's/^delayed=//p' "$output")
    queue_running=$(sed -n 's/^running=//p' "$output")
    queue_evidence_sha256=$(sha256_file "$output")
    rm -f -- "$output"
}

assert_active_member()
{
    local member=$1 expected_name expected_route_identity expected_id expected_address expected_port
    local container_json observed_route_identity observed_runtime_sha256
    expected_name=$(document_value "$ingress_pool_manifest" "member_${member}_name")
    expected_route_identity=$(document_value "$ingress_pool_manifest" \
        "member_${member}_route_identity")
    expected_id=$(document_value "$ingress_pool_manifest" "member_${member}_id")
    expected_address=$(document_value "$ingress_pool_manifest" "member_${member}_address")
    expected_port=$(document_value "$ingress_pool_manifest" "member_${member}_port")
    inspect_docker_container "$expected_name"
    [[ $docker_container_state == present ]] || fail "active web-$member member is absent"
    container_json=$docker_container_json
    observed_route_identity=$(jq -e -r '
        [.Config.Env[]? | select(startswith("CONTROL_PLANE_MEMBER_ID="))] as $entry
        | select(($entry | length) == 1)
        | $entry[0] | sub("^CONTROL_PLANE_MEMBER_ID="; "")
    ' <<< "$container_json") || fail "active web-$member has no single configured route identity"
    observed_runtime_sha256=$(live_runtime_sha256 "$expected_name")
    [[ $(jq -r '.Id' <<< "$container_json") == "$expected_id" \
        && $(jq -r '.Name' <<< "$container_json") == "/$expected_name" \
        && $observed_route_identity == "$expected_route_identity" \
        && $(jq -r '.Image' <<< "$container_json") \
            == "$(document_value "$ingress_pool_manifest" "member_${member}_image_id")" \
        && $(jq -r '.Config.Image' <<< "$container_json") \
            == "$(document_value "$ingress_pool_manifest" "member_${member}_image_reference")" \
        && $(jq -r '.State.Running == true
            and ((.State.Health? // null) == null or .State.Health.Status == "healthy")' \
            <<< "$container_json") == true \
        && $(jq -r --arg address "$expected_address" '
            [.NetworkSettings.Networks[]
                | select(.IPAddress == $address or .GlobalIPv6Address == $address)] | length' \
            <<< "$container_json") -eq 1 \
        && $(jq -r --arg port "${expected_port}/tcp" '.Config.ExposedPorts | has($port)' \
            <<< "$container_json") == true \
        && $(network_sha256 "$container_json") \
            == "$(document_value "$ingress_pool_manifest" "member_${member}_network_sha256")" \
        && $(bindings_sha256 "$container_json") \
            == "$(document_value "$ingress_pool_manifest" "member_${member}_bindings_sha256")" \
        && $observed_runtime_sha256 \
            == "$(document_value "$ingress_pool_manifest" "member_${member}_runtime_sha256")" ]] \
        || fail "active web-$member member differs from its exact ingress identity"
    printf '%s\n' "$expected_id"
}

assert_exact_pool_members()
{
    local label_key label_value expected actual
    label_key=$(document_value "$ingress_pool_manifest" pool_label_key)
    label_value=$(document_value "$ingress_pool_manifest" pool_label_value)
    docker_request 'containers/json?all=1&size=0'
    [[ $docker_http_status == 200 ]] || {
        rm -f -- "$docker_response_file"
        fail "Docker Engine API returned HTTP $docker_http_status for container list"
    }
    if ! actual=$(jq -r --arg key "$label_key" --arg value "$label_value" '
        if type != "array" then error("container list is not an array") else .[] end
        | select((.Labels // {})[$key] == $value)
        | [.Id, ((.Names // []) | sort | join(","))] | @tsv
    ' "$docker_response_file" | LC_ALL=C sort); then
        rm -f -- "$docker_response_file"
        fail 'Docker Engine API returned malformed container list data'
    fi
    rm -f -- "$docker_response_file"
    expected=$(printf '%s\t/%s\n%s\t/%s\n' \
        "$(document_value "$ingress_pool_manifest" member_a_id)" \
        "$(document_value "$ingress_pool_manifest" member_a_name)" \
        "$(document_value "$ingress_pool_manifest" member_b_id)" \
        "$(document_value "$ingress_pool_manifest" member_b_name)" | LC_ALL=C sort)
    [[ $actual == "$expected" ]] \
        || fail 'pool selector resolved missing, duplicate, or unexpected candidate members'
}

assert_retired_set_absent()
{
    local member count name id
    count=$(document_value "$pool_plan_manifest" retired_member_count)
    for member in a b; do
        [[ $member == a || $count == 2 ]] || continue
        name=$(document_value "$pool_plan_manifest" "retired_member_${member}_name")
        id=$(document_value "$pool_plan_manifest" "retired_member_${member}_id")
        inspect_docker_container "$name"
        [[ $docker_container_state == absent ]] \
            || fail "retired member $member name still exists"
        inspect_docker_container "$id"
        [[ $docker_container_state == absent ]] \
            || fail "retired member $member exact ID still exists"
    done
}

provider_freshness_sha256()
{
    local output observed now snapshot retired_member_count retired_a_name retired_a_id
    local retired_b_name retired_b_id member_set_sha256 backend_set_sha256 expected_keys
    local retired_a_backend_url=absent retired_b_backend_url=absent
    retired_member_count=$(document_value "$pool_plan_manifest" retired_member_count)
    retired_a_name=$(document_value "$pool_plan_manifest" retired_member_a_name)
    retired_a_id=$(document_value "$pool_plan_manifest" retired_member_a_id)
    if [[ $retired_member_count == 1 ]]; then
        retired_b_name=absent
        retired_b_id=absent
    else
        retired_b_name=$(document_value "$pool_plan_manifest" retired_member_b_name)
        retired_b_id=$(document_value "$pool_plan_manifest" retired_member_b_id)
    fi
    member_set_sha256=$(
        {
            printf '%s\0' absent "$retired_member_count"
            printf '%s\0' retired_a "$retired_a_name" "$retired_a_id"
            printf '%s\0' retired_b "$retired_b_name" "$retired_b_id"
        } | sha256sum | awk '{print $1}'
    )
    backend_set_sha256=$(
        {
            printf '%s\0' retired_a "$retired_a_backend_url"
            printf '%s\0' retired_b "$retired_b_backend_url"
        } | sha256sum | awk '{print $1}'
    )
    output=$(CONTROL_PLANE_RUNTIME_OPERATION_ID="$operation_id" \
        CONTROL_PLANE_RUNTIME_PROVIDER_EXPECTATION=absent \
        CONTROL_PLANE_RUNTIME_RETIRED_MEMBER_COUNT="$retired_member_count" \
        CONTROL_PLANE_RUNTIME_RETIRED_A_NAME="$retired_a_name" \
        CONTROL_PLANE_RUNTIME_RETIRED_A_ID="$retired_a_id" \
        CONTROL_PLANE_RUNTIME_RETIRED_B_NAME="$retired_b_name" \
        CONTROL_PLANE_RUNTIME_RETIRED_B_ID="$retired_b_id" \
        "${CONTROL_PLANE_RUNTIME_PROVIDER_PROBE:?}") \
        || fail 'terminal provider freshness probe failed'
    expected_keys=$(printf '%s\n' \
        version status observed_at_epoch operation_id proxy_id proxy_pid expectation \
        retired_member_count retired_a_name retired_a_id retired_b_name retired_b_id \
        retired_a_backend_url retired_b_backend_url member_set_sha256 \
        backend_set_sha256 snapshot_sha256)
    [[ $(cut -d= -f1 <<< "$output") == "$expected_keys" ]] \
        || fail 'terminal provider evidence contains reordered, duplicated, absent, or unknown records'
    [[ $(wc -l <<< "$output") -eq 17 \
        && $(grep -F -x -c version=2 <<< "$output") -eq 1 \
        && $(grep -F -x -c status=fresh <<< "$output") -eq 1 \
        && $(grep -F -x -c "operation_id=$operation_id" <<< "$output") -eq 1 \
        && $(grep -F -x -c expectation=absent <<< "$output") -eq 1 \
        && $(grep -F -x -c "retired_member_count=$retired_member_count" <<< "$output") -eq 1 \
        && $(grep -F -x -c "retired_a_name=$retired_a_name" <<< "$output") -eq 1 \
        && $(grep -F -x -c "retired_a_id=$retired_a_id" <<< "$output") -eq 1 \
        && $(grep -F -x -c "retired_b_name=$retired_b_name" <<< "$output") -eq 1 \
        && $(grep -F -x -c "retired_b_id=$retired_b_id" <<< "$output") -eq 1 \
        && $(grep -F -x -c "retired_a_backend_url=$retired_a_backend_url" \
            <<< "$output") -eq 1 \
        && $(grep -F -x -c "retired_b_backend_url=$retired_b_backend_url" \
            <<< "$output") -eq 1 \
        && $(grep -F -x -c "member_set_sha256=$member_set_sha256" <<< "$output") -eq 1 \
        && $(grep -F -x -c "backend_set_sha256=$backend_set_sha256" <<< "$output") -eq 1 ]] \
        || fail 'terminal provider evidence was not fresh for an absent retired set'
    observed=$(sed -n 's/^observed_at_epoch=//p' <<< "$output")
    snapshot=$(sed -n 's/^snapshot_sha256=//p' <<< "$output")
    [[ $observed =~ ^[1-9][0-9]*$ ]] || fail 'provider timestamp is malformed'
    validate_sha256 provider-snapshot "$snapshot"
    now=$(date -u +%s)
    ((observed <= now + 5 && now - observed <= probe_max_age_seconds)) \
        || fail 'provider evidence is stale or from the future'
    printf '%s\n' "$snapshot"
}

validate_local_ingress_url()
{
    local url=$1 port

    [[ $url =~ ^http://127\.0\.0\.1:([1-9][0-9]{0,4})(/[A-Za-z0-9_./?\&=%~-]*)?$ ]] \
        || fail 'terminal local ingress URL must use the native Traefik loopback endpoint'
    port=${BASH_REMATCH[1]}
    ((port <= 65535)) \
        || fail 'terminal local ingress URL port must be at most 65535'
}

terminal_action=${1:-probe}
[[ $# -le 1 && $terminal_action =~ ^(probe|validate-pool-plan)$ ]] \
    || fail 'usage: control-plane-terminal-state-probe.sh [validate-pool-plan]'
test_mode=${CONTROL_PLANE_TERMINAL_TEST_MODE:-0}
[[ $test_mode == 0 || $test_mode == 1 ]] || fail 'terminal test mode must be 0 or 1'
if [[ $test_mode == 1 ]]; then
    immutable_uid=$(id -u)
    immutable_gid=$(id -g)
    runtime_artifact_uid=$immutable_uid
    runtime_artifact_gid=$immutable_gid
else
    [[ $(id -u) -eq 0 ]] || fail 'terminal state probe requires root'
    immutable_uid=0
    immutable_gid=0
    runtime_artifact_uid=9999
    runtime_artifact_gid=9999
fi
operation_id=${CONTROL_PLANE_RUNTIME_OPERATION_ID:-}
validate_identifier operation-id "$operation_id"
pool_plan_source_path=${CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST:-}
pool_plan_manifest_sha256=${CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256:-}
safe_snapshot_file "$pool_plan_source_path" "$pool_plan_manifest_sha256" \
    "${CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_METADATA:-}" \
    "$immutable_uid" "$immutable_gid" 600 pool-plan-manifest
pool_plan_manifest=$SAFE_SNAPSHOT_PATH
pool_plan_source_identity=$SAFE_SOURCE_IDENTITY
validate_pool_plan
if [[ $terminal_action == validate-pool-plan ]]; then
    printf 'version=2\npool_plan=validated\nrelease_files_sha256=%s\n' \
        "$(release_files_manifest_sha256)"
    exit 0
fi
ingress_pool_source_path=$(document_value "$pool_plan_manifest" ingress_pool_manifest_path)
[[ $ingress_pool_source_path == "${CONTROL_PLANE_INGRESS_POOL_MANIFEST:-}" ]] \
    || fail 'terminal ingress manifest path differs from its immutable pool plan'
ingress_pool_manifest_sha256=${CONTROL_PLANE_INGRESS_POOL_MANIFEST_SHA256:-}
safe_snapshot_file "$ingress_pool_source_path" "$ingress_pool_manifest_sha256" \
    "${CONTROL_PLANE_INGRESS_POOL_MANIFEST_METADATA:-}" \
    "$immutable_uid" "$immutable_gid" 600 ingress-pool-manifest
ingress_pool_manifest=$SAFE_SNAPSHOT_PATH
validate_ingress_pool
docker_socket=${CONTROL_PLANE_RUNTIME_DOCKER_SOCKET:-/var/run/docker.sock}
[[ -S $docker_socket ]] || fail 'Docker socket is unavailable'
probe_max_age_seconds=${CONTROL_PLANE_RUNTIME_PROBE_MAX_AGE_SECONDS:-30}
[[ $probe_max_age_seconds =~ ^[1-9][0-9]*$ ]] || fail 'probe max age must be positive'
semantic_config_sha256=${CONTROL_PLANE_RUNTIME_SEMANTIC_CONFIG_SHA256:-}
validate_sha256 semantic-config "$semantic_config_sha256"
writer_member=$(document_value "$ingress_pool_manifest" writer_member)
prepare_runtime_attestors

assert_retired_set_absent
active_web_a_id=$(assert_active_member a)
active_web_b_id=$(assert_active_member b)
assert_exact_pool_members
pool_ack_source=$(document_value "$ingress_pool_manifest" pool_ack_file)
[[ -n ${ARTIFACT_SNAPSHOTS[$pool_ack_source]+present} ]] \
    || fail 'pool acknowledgement artifact has no safe snapshot'
pool_ack=$(read_token "${ARTIFACT_SNAPSHOTS[$pool_ack_source]}")
probe_ack "${CONTROL_PLANE_RUNTIME_TERMINAL_HTTPS_URL:-}" \
    X-Control-Plane-Pool-Ack "$pool_ack"
local_ingress_url=${CONTROL_PLANE_RUNTIME_TERMINAL_LOCAL_INGRESS_URL:-}
validate_local_ingress_url "$local_ingress_url"
probe_ack "$local_ingress_url" \
    X-Control-Plane-Pool-Ack "$pool_ack"
provider_snapshot_sha256=$(provider_freshness_sha256)
collect_queue_zero_evidence

printf 'version=3\nterminal_state=passed\nobserved_at_epoch=%s\noperation_id=%s\n' \
    "$(date -u +%s)" "$operation_id"
printf 'semantic_config_sha256=%s\n' "$semantic_config_sha256"
printf 'active_web_a_id=%s\nactive_web_b_id=%s\n' "$active_web_a_id" "$active_web_b_id"
printf 'pool_manifest_sha256=%s\npool_plan_manifest_sha256=%s\npool_generation=%s\n' \
    "$ingress_pool_manifest_sha256" "$pool_plan_manifest_sha256" \
    "$(document_value "$ingress_pool_manifest" generation)"
printf 'pool_member_set_sha256=%s\nhttps_pool_ack_sha256=%s\nlocal_ingress_pool_ack_sha256=%s\n' \
    "$(document_value "$ingress_pool_manifest" member_set_sha256)" \
    "$(document_value "$ingress_pool_manifest" pool_ack_sha256)" \
    "$(document_value "$ingress_pool_manifest" pool_ack_sha256)"
printf 'retired_incumbent_set=absent\nprovider_fresh=passed\nprovider_snapshot_sha256=%s\n' \
    "$provider_snapshot_sha256"
printf 'queue_zero=passed\nqueue_observed_at_epoch=%s\nqueue_probe_sha256=%s\n' \
    "$queue_observed_at_epoch" "$queue_probe_sha256"
printf 'queue_evidence_sha256=%s\nqueue_pending=%s\nqueue_reserved=%s\n' \
    "$queue_evidence_sha256" "$queue_pending" "$queue_reserved"
printf 'queue_delayed=%s\nqueue_running=%s\nmutation_freeze_epoch=%s\n' \
    "$queue_delayed" "$queue_running" \
    "$(document_value "$pool_plan_manifest" mutation_freeze_epoch)"
