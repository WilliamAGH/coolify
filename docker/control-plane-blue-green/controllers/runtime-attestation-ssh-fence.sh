#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

readonly TABLE_FAMILY=inet
readonly TABLE_NAME=coolify_control_plane_ssh_fence
readonly CHAIN_NAME=container_to_host_ssh
readonly STATE_VERSION=4
readonly RUNTIME_DIGEST_VERSION=4
readonly RUNTIME_RECOVERY_VERSION=2
readonly RUNTIME_RECOVERY_ROLES='proxy retired_a retired_b web_a web_b'
readonly POOL_PLAN_VERSION=2
readonly RESTORE_SERVICE=coolify-runtime-attestation-ssh-fence.service
readonly WATCHDOG_SERVICE=coolify-runtime-attestation-ssh-fence-watchdog.service
readonly SYSTEMD_DIRECTORY=/etc/systemd/system
readonly SYSTEMD_PROPERTIES='LoadState FragmentPath DropInPaths ExecStart EnvironmentFiles Before After Wants Requires BindsTo PartOf User Group SupplementaryGroups CapabilityBoundingSet AmbientCapabilities NoNewPrivileges PrivateTmp PrivateDevices PrivateNetwork PrivateUsers ProtectHome ProtectSystem ProtectControlGroups ProtectKernelModules ProtectKernelTunables ProtectClock ProtectHostname ReadWritePaths ReadOnlyPaths InaccessiblePaths RestrictAddressFamilies RestrictNamespaces LockPersonality MemoryDenyWriteExecute RestrictRealtime SystemCallFilter SystemCallArchitectures UMask DynamicUser DevicePolicy DeviceAllow IPAddressAllow IPAddressDeny Restart RestartUSec Type RemainAfterExit DefaultDependencies'
readonly SEMANTIC_KEYS='CONTROL_PLANE_RUNTIME_OPERATION_ID CONTROL_PLANE_RUNTIME_PROXY_CONTAINER CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256 CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_METADATA CONTROL_PLANE_RUNTIME_MANAGEMENT_ENDPOINTS CONTROL_PLANE_RUNTIME_ADDITIONAL_NETWORK_IDS CONTROL_PLANE_RUNTIME_SELF_SSH_TARGET CONTROL_PLANE_RUNTIME_PROBE_MAX_AGE_SECONDS CONTROL_PLANE_RUNTIME_QUEUE_STABLE_SECONDS CONTROL_PLANE_RUNTIME_PROVIDER_CAPTURE_EXPECTATION CONTROL_PLANE_RUNTIME_PROVIDER_API_URL CONTROL_PLANE_RUNTIME_PROVIDER_ROUTER CONTROL_PLANE_RUNTIME_PROVIDER_SERVICE CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT CONTROL_PLANE_RUNTIME_PROVIDER_HEADER_FILE CONTROL_PLANE_RUNTIME_TERMINAL_HTTPS_URL CONTROL_PLANE_RUNTIME_TERMINAL_LOCAL_INGRESS_URL'

declare -a SAFE_SNAPSHOTS=()
declare -A PLAN_ARTIFACT_IDENTITIES=()
declare -A RECOVERY_NEW_SNAPSHOT=()
declare -A RECOVERY_NEW_STATIC_SHA256=()
declare -A RECOVERY_NEW_WEB_NETWORK=()

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
    printf 'CONTROL_PLANE_RUNTIME_FENCE_FAILURE %s\n' "$1" >&2
    exit 1
}

note()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE %s\n' "$1"
}

test_crash()
{
    if [[ $test_mode == 1 && ${CONTROL_PLANE_RUNTIME_TEST_CRASH_AT:-} == "$1" ]]; then
        exit 86
    fi
}

require_command()
{
    command -v "$1" >/dev/null 2>&1 || fail "required command is unavailable: $1"
}

sha256_file()
{
    sha256sum "$1" | awk '{print $1}'
}

sha256_text()
{
    printf '%s' "$1" | sha256sum | awk '{print $1}'
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

assert_root_path()
{
    local path=$1 mode=$2 label=$3 resolved
    [[ -f $path && ! -L $path ]] || fail "$label is not a regular non-symlink file"
    resolved=$(readlink -f -- "$path")
    [[ $resolved == "$path" ]] || fail "$label path contains a symlink"
    [[ $(stat -c '%u:%g:%a' "$path") == "$immutable_uid:$immutable_gid:$mode" ]] \
        || fail "$label must be owned by $immutable_uid:$immutable_gid mode 0$mode"
}

run_safe_read_test_hook()
{
    local stage=$1 label=$2 path=$3 hook=${CONTROL_PLANE_RUNTIME_TEST_SAFE_READ_HOOK:-}
    [[ -n $hook ]] || return 0
    [[ $test_mode == 1 ]] \
        || fail 'safe-read test hook is forbidden outside test mode'
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
    snapshot=$(mktemp "${TMPDIR:-/tmp}/coolify-runtime-safe-read.XXXXXX")
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

manifest_value()
{
    local key=$1 count result
    count=$(grep -E -c "^${key}=" "$pool_plan_document" || true)
    [[ $count -eq 1 ]] || fail "pool plan key is absent or duplicated: $key"
    result=$(sed -n "s/^${key}=//p" "$pool_plan_document")
    [[ $result != *$'\n'* ]] || fail "pool plan value contains a newline: $key"
    printf '%s\n' "$result"
}

validate_absolute_path()
{
    [[ $2 == /* && $2 != *'//'* && $2 =~ ^/[A-Za-z0-9_./-]+$ \
        && $2 != '/..' && $2 != '/../'* && $2 != *'/../'* && $2 != *'/..' ]] \
        || fail "$1 is not a canonical absolute path"
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
    if [[ $(manifest_value retired_member_count) == 2 ]]; then
        printf '%s\n' retired_member_b_name retired_member_b_id
    fi
    printf '%s\n' ingress_pool_manifest_path pool_plan_set_sha256
}

pool_plan_set_sha256()
{
    local member key
    for member in a b; do
        while IFS= read -r key; do
            printf '%s\0' "$(manifest_value "$key")"
        done < <(plan_member_keys "$member")
    done
    printf '%s\0' "$(manifest_value writer_member)"
}

validate_plan_artifact()
{
    local prefix=$1 label=$2 expected_uid=${3:-$immutable_uid}
    local expected_gid=${4:-$immutable_gid} expected_mode=${5:-600}
    local path checksum metadata
    path=$(manifest_value "${prefix}_file")
    checksum=$(manifest_value "${prefix}_sha256")
    metadata=$(manifest_value "${prefix}_metadata")
    validate_absolute_path "$label path" "$path"
    safe_snapshot_file "$path" "$checksum" "$metadata" "$expected_uid" "$expected_gid" \
        "$expected_mode" "$label"
    PLAN_ARTIFACT_IDENTITIES[$path]=$SAFE_SOURCE_IDENTITY
}

plan_artifact_identity()
{
    local prefix=$1 path
    path=$(manifest_value "${prefix}_file")
    [[ -n ${PLAN_ARTIFACT_IDENTITIES[$path]+present} ]] \
        || fail "plan artifact identity was not safely read: $prefix"
    printf '%s\n' "${PLAN_ARTIFACT_IDENTITIES[$path]}"
}

load_pool_plan()
{
    local expected_keys actual_keys direction retired_count member role key writer_marker volume
    local -a marker_authorities=()
    safe_snapshot_file "$pool_plan_manifest" "$pool_plan_manifest_sha256" \
        "$pool_plan_manifest_metadata" "$immutable_uid" "$immutable_gid" 600 pool-plan-manifest
    pool_plan_document=$SAFE_SNAPSHOT_PATH
    pool_plan_source_identity=$SAFE_SOURCE_IDENTITY
    pool_plan_manifest_metadata=$SAFE_SOURCE_METADATA
    expected_keys=$(pool_plan_keys)
    actual_keys=$(sed 's/=.*//' "$pool_plan_document")
    [[ $actual_keys == "$expected_keys" \
        && $(wc -l < "$pool_plan_document") -eq $(wc -l <<< "$expected_keys") ]] \
        || fail 'pool plan keys are reordered, duplicated, absent, or unknown'
    [[ $(manifest_value version) == "$POOL_PLAN_VERSION" \
        && $(manifest_value operation_id) == "$operation_id" \
        && $(manifest_value member_count) == 2 ]] \
        || fail 'pool plan does not belong to this exact two-member operation'
    direction=$(manifest_value direction)
    [[ $direction =~ ^(bootstrap-forward|forward|reverse)$ \
        && $(manifest_value color) =~ ^(green|blue)$ \
        && $(manifest_value generation) =~ ^[1-9][0-9]*$ \
        && $(manifest_value backend_port) =~ ^[1-9][0-9]{0,4}$ ]] \
        || fail 'pool plan direction, color, generation, or backend port is malformed'
    validate_authority_token mutation-freeze-epoch "$(manifest_value mutation_freeze_epoch)"
    ((10#$(manifest_value backend_port) <= 65535)) \
        || fail 'pool plan backend port exceeds 65535'
    validate_identifier coordination-volume "$(manifest_value coordination_volume)"
    validate_identifier pool-label-key "$(manifest_value pool_label_key)"
    validate_identifier pool-label-value "$(manifest_value pool_label_value)"
    [[ $(manifest_value route_health_path) == /api/control-plane/route-health ]] \
        || fail 'pool plan route-health path is not the canonical endpoint'
    for key in mutation_freeze_marker_path mutation_lease_path ingress_pool_manifest_path; do
        validate_absolute_path "$key" "$(manifest_value "$key")"
    done
    validate_plan_artifact route_health_token route-health-token
    validate_plan_artifact route_health_runtime route-health-runtime \
        "$runtime_artifact_uid" "$runtime_artifact_gid" 400
    validate_plan_artifact pool_ack pool-ack
    validate_plan_artifact pool_ack_runtime pool-ack-runtime \
        "$runtime_artifact_uid" "$runtime_artifact_gid" 400
    [[ $(manifest_value route_health_token_sha256) \
            == "$(manifest_value route_health_runtime_sha256)" \
        && $(manifest_value pool_ack_sha256) == "$(manifest_value pool_ack_runtime_sha256)" ]] \
        || fail 'pool root/runtime artifact copies do not contain identical pinned bytes'
    pool_writer_member=$(manifest_value writer_member)
    [[ $pool_writer_member == web-a || $pool_writer_member == web-b ]] \
        || fail 'pool plan must declare exactly one writer member'
    for member in a b; do
        role=$(manifest_value "member_${member}_role")
        [[ $role == "web-$member" ]] || fail 'pool plan member roles are not canonical web-a/web-b'
        validate_identifier "member-$member name" "$(manifest_value "member_${member}_name")"
        validate_authority_token "member-$member route identity" \
            "$(manifest_value "member_${member}_route_identity")"
        validate_identifier "member-$member private volume" \
            "$(manifest_value "member_${member}_private_volume")"
        [[ $(manifest_value "member_${member}_image_reference") \
                =~ ^[A-Za-z0-9][A-Za-z0-9._/:@-]*@sha256:[a-f0-9]{64}$ \
            && $(manifest_value "member_${member}_image_id") =~ ^sha256:[a-f0-9]{64}$ \
            && $(manifest_value "member_${member}_network_ids") \
                =~ ^[a-f0-9]{64}(,[a-f0-9]{64})*$ \
            && $(manifest_value "member_${member}_expected_loopback_port") \
                =~ ^[1-9][0-9]{0,4}$ \
            ]] \
            || fail "pool plan member $member immutable runtime plan is malformed"
        assert_sorted_unique_network_ids "member-$member network plan" \
            "$(manifest_value "member_${member}_network_ids")"
        validate_authority_token "member-$member web epoch" \
            "$(manifest_value "member_${member}_web_epoch")"
        validate_authority_token "member-$member route-drain epoch" \
            "$(manifest_value "member_${member}_route_drain_epoch")"
        ((10#$(manifest_value "member_${member}_expected_loopback_port") <= 65535)) \
            || fail "pool plan member $member loopback port exceeds 65535"
        for key in repin_intent_file web_marker_path route_drain_marker_path; do
            validate_absolute_path "member-$member $key" \
                "$(manifest_value "member_${member}_${key}")"
        done
        validate_plan_artifact "member_${member}_direct_probe_token" \
            "member-$member-direct-probe-token"
        validate_plan_artifact "member_${member}_direct_probe_runtime" \
            "member-$member-direct-probe-runtime" \
            "$runtime_artifact_uid" "$runtime_artifact_gid" 400
        validate_plan_artifact "member_${member}_applied_ack" "member-$member-applied-ack"
        validate_plan_artifact "member_${member}_applied_ack_runtime" \
            "member-$member-applied-ack-runtime" \
            "$runtime_artifact_uid" "$runtime_artifact_gid" 400
        [[ $(manifest_value "member_${member}_direct_probe_token_sha256") \
                == "$(manifest_value "member_${member}_direct_probe_runtime_sha256")" \
            && $(manifest_value "member_${member}_applied_ack_sha256") \
                == "$(manifest_value "member_${member}_applied_ack_runtime_sha256")" ]] \
            || fail "member $member root/runtime artifact copies do not contain identical pinned bytes"
        if [[ $role == "$pool_writer_member" ]]; then
            validate_authority_token "member-$member writer epoch" \
                "$(manifest_value "member_${member}_writer_epoch")"
            validate_absolute_path "member-$member writer marker" \
                "$(manifest_value "member_${member}_writer_marker_path")"
        else
            [[ $(manifest_value "member_${member}_writer_epoch") == absent \
                && $(manifest_value "member_${member}_writer_marker_path") == absent ]] \
                || fail 'non-writer member has writer marker authority'
        fi
    done
    assert_distinct_values 'pool artifact device/inode identity' \
        "$pool_plan_source_identity" \
        "$(plan_artifact_identity route_health_token)" \
        "$(plan_artifact_identity route_health_runtime)" \
        "$(plan_artifact_identity pool_ack)" \
        "$(plan_artifact_identity pool_ack_runtime)" \
        "$(plan_artifact_identity member_a_direct_probe_token)" \
        "$(plan_artifact_identity member_a_direct_probe_runtime)" \
        "$(plan_artifact_identity member_a_applied_ack)" \
        "$(plan_artifact_identity member_a_applied_ack_runtime)" \
        "$(plan_artifact_identity member_b_direct_probe_token)" \
        "$(plan_artifact_identity member_b_direct_probe_runtime)" \
        "$(plan_artifact_identity member_b_applied_ack)" \
        "$(plan_artifact_identity member_b_applied_ack_runtime)"
    assert_distinct_values 'pool member identity' \
        "$(manifest_value member_a_name)" "$(manifest_value member_b_name)"
    assert_distinct_values 'pool route identity' \
        "$(manifest_value member_a_route_identity)" "$(manifest_value member_b_route_identity)"
    assert_distinct_values 'pool loopback port' \
        "$(manifest_value member_a_expected_loopback_port)" \
        "$(manifest_value member_b_expected_loopback_port)"
    assert_distinct_values 'pool volume authority' \
        "$(manifest_value coordination_volume)" "$(manifest_value member_a_private_volume)" \
        "$(manifest_value member_b_private_volume)"
    assert_distinct_values 'pool direct-probe content' \
        "$(manifest_value member_a_direct_probe_token_sha256)" \
        "$(manifest_value member_b_direct_probe_token_sha256)"
    assert_distinct_values 'pool applied-ack content' \
        "$(manifest_value member_a_applied_ack_sha256)" \
        "$(manifest_value member_b_applied_ack_sha256)"
    assert_distinct_values 'pool web epoch' \
        "$(manifest_value member_a_web_epoch)" "$(manifest_value member_b_web_epoch)"
    assert_distinct_values 'pool route-drain epoch' \
        "$(manifest_value member_a_route_drain_epoch)" \
        "$(manifest_value member_b_route_drain_epoch)"
    assert_distinct_values 'pool authority path' \
        "$pool_plan_manifest" \
        "$(manifest_value mutation_freeze_marker_path)" \
        "$(manifest_value mutation_lease_path)" \
        "$(manifest_value ingress_pool_manifest_path)" \
        "$(manifest_value route_health_token_file)" \
        "$(manifest_value route_health_runtime_file)" \
        "$(manifest_value pool_ack_file)" \
        "$(manifest_value pool_ack_runtime_file)" \
        "$(manifest_value member_a_repin_intent_file)" \
        "$(manifest_value member_b_repin_intent_file)" \
        "$(manifest_value member_a_direct_probe_token_file)" \
        "$(manifest_value member_b_direct_probe_token_file)" \
        "$(manifest_value member_a_direct_probe_runtime_file)" \
        "$(manifest_value member_b_direct_probe_runtime_file)" \
        "$(manifest_value member_a_applied_ack_file)" \
        "$(manifest_value member_b_applied_ack_file)" \
        "$(manifest_value member_a_applied_ack_runtime_file)" \
        "$(manifest_value member_b_applied_ack_runtime_file)"
    for member in a b; do
        volume=$(manifest_value "member_${member}_private_volume")
        marker_authorities+=(
            "$volume|$(manifest_value "member_${member}_web_marker_path")"
            "$volume|$(manifest_value "member_${member}_route_drain_marker_path")"
        )
        writer_marker=$(manifest_value "member_${member}_writer_marker_path")
        [[ $writer_marker == absent ]] || marker_authorities+=("$volume|$writer_marker")
    done
    assert_distinct_values 'pool private marker authority tuple' "${marker_authorities[@]}"
    retired_count=$(manifest_value retired_member_count)
    case "$direction:$retired_count" in
        bootstrap-forward:1) ;;
        forward:2|reverse:2) ;;
        *) fail 'pool plan retired set contradicts its migration direction' ;;
    esac
    validate_identifier retired-member-a-name "$(manifest_value retired_member_a_name)"
    [[ $(manifest_value retired_member_a_id) =~ ^[a-f0-9]{64}$ ]] \
        || fail 'retired member A ID is malformed'
    if [[ $retired_count == 2 ]]; then
        validate_identifier retired-member-b-name "$(manifest_value retired_member_b_name)"
        [[ $(manifest_value retired_member_b_id) =~ ^[a-f0-9]{64}$ \
            && $(manifest_value retired_member_a_name) != "$(manifest_value retired_member_b_name)" \
            && $(manifest_value retired_member_a_id) != "$(manifest_value retired_member_b_id)" ]] \
            || fail 'retired two-member set is malformed or duplicated'
    fi
    [[ $(manifest_value member_a_name) != "$(manifest_value retired_member_a_name)" \
        && $(manifest_value member_b_name) != "$(manifest_value retired_member_a_name)" ]] \
        || fail 'planned member name collides with retired member A'
    if [[ $retired_count == 2 ]]; then
        [[ $(manifest_value member_a_name) != "$(manifest_value retired_member_b_name)" \
            && $(manifest_value member_b_name) != "$(manifest_value retired_member_b_name)" ]] \
            || fail 'planned member name collides with retired member B'
    fi
    validate_sha256 pool-plan-set "$(manifest_value pool_plan_set_sha256)"
    [[ $(pool_plan_set_sha256 | sha256sum | awk '{print $1}') \
        == "$(manifest_value pool_plan_set_sha256)" ]] \
        || fail 'pool plan set hash does not match its canonical NUL-delimited member tuple'

    pool_direction=$direction
    pool_color=$(manifest_value color)
    pool_generation=$(manifest_value generation)
    pool_plan_set_sha256_value=$(manifest_value pool_plan_set_sha256)
    pool_label_key=$(manifest_value pool_label_key)
    pool_label_value=$(manifest_value pool_label_value)
    pool_retired_member_count=$retired_count
    legacy_container=$(manifest_value retired_member_a_name)
    pool_retired_member_a_id=$(manifest_value retired_member_a_id)
    pool_retired_member_b_name=none
    pool_retired_member_b_id=none
    if [[ $retired_count == 2 ]]; then
        pool_retired_member_b_name=$(manifest_value retired_member_b_name)
        pool_retired_member_b_id=$(manifest_value retired_member_b_id)
    fi
    candidate_container=$(manifest_value member_a_name)
    candidate_route_identity=$(manifest_value member_a_route_identity)
    candidate_image_reference=$(manifest_value member_a_image_reference)
    candidate_image_id=$(manifest_value member_a_image_id)
    candidate_network_ids=$(manifest_value member_a_network_ids)
    candidate_repin_intent_file=$(manifest_value member_a_repin_intent_file)
    pool_web_b_container=$(manifest_value member_b_name)
    pool_web_b_route_identity=$(manifest_value member_b_route_identity)
    pool_web_b_image_reference=$(manifest_value member_b_image_reference)
    pool_web_b_image_id=$(manifest_value member_b_image_id)
    pool_web_b_network_ids=$(manifest_value member_b_network_ids)
    pool_web_b_repin_intent_file=$(manifest_value member_b_repin_intent_file)
    ingress_pool_manifest=$(manifest_value ingress_pool_manifest_path)
    if [[ $pool_writer_member == web-a ]]; then
        pool_writer_container=$candidate_container
    else
        pool_writer_container=$pool_web_b_container
    fi
}

ingress_value()
{
    local key=$1 count result
    count=$(grep -E -c "^${key}=" "$ingress_pool_document" || true)
    [[ $count -eq 1 ]] || fail "ingress pool key is absent or duplicated: $key"
    result=$(sed -n "s/^${key}=//p" "$ingress_pool_document")
    [[ $result != *$'\n'* ]] || fail "ingress pool value contains a newline: $key"
    printf '%s\n' "$result"
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

ingress_member_set_bytes()
{
    local member key
    printf '%s\0' "$(ingress_value generation)" "$(ingress_value parent_pool_plan_sha256)"
    for member in a b; do
        while IFS= read -r key; do
            printf '%s\0' "$(ingress_value "$key")"
        done < <(ingress_member_keys "$member")
    done
    printf '%s\0' "$(ingress_value writer_member)" "$(ingress_value pool_label_key)" \
        "$(ingress_value pool_label_value)"
}

validate_ingress_pool_manifest()
{
    local expected_sha256=$1 expected_keys actual_keys member key container inspect_json address port
    local expected_metadata='' state_id_key state_runtime_key
    validate_sha256 ingress-pool-manifest "$expected_sha256"
    if [[ -f ${state_file:-/nonexistent} \
        && $(state_value ingress_pool_manifest_metadata) != pending ]]; then
        expected_metadata=$(state_value ingress_pool_manifest_metadata)
    fi
    safe_snapshot_file "$ingress_pool_manifest" "$expected_sha256" "$expected_metadata" \
        "$immutable_uid" "$immutable_gid" 600 ingress-pool-manifest
    ingress_pool_document=$SAFE_SNAPSHOT_PATH
    expected_keys=$(ingress_pool_keys)
    actual_keys=$(sed 's/=.*//' "$ingress_pool_document")
    [[ $actual_keys == "$expected_keys" \
        && $(wc -l < "$ingress_pool_document") -eq $(wc -l <<< "$expected_keys") ]] \
        || fail 'ingress pool keys are reordered, duplicated, absent, or unknown'
    [[ $(ingress_value version) == 2 \
        && $(ingress_value operation_id) == "$operation_id" \
        && $(ingress_value direction) == "$pool_direction" \
        && $(ingress_value color) == "$pool_color" \
        && $(ingress_value generation) == "$pool_generation" \
        && $(ingress_value parent_pool_plan_sha256) == "$pool_plan_manifest_sha256" \
        && $(ingress_value member_count) == 2 \
        && $(ingress_value writer_member) == "$pool_writer_member" \
        && $(ingress_value pool_label_key) == "$pool_label_key" \
        && $(ingress_value pool_label_value) == "$pool_label_value" ]] \
        || fail 'ingress pool does not descend from the exact planned generation'
    for key in pool_ack_file pool_ack_sha256 pool_ack_metadata route_health_path \
        route_health_token_file route_health_token_sha256 route_health_token_metadata; do
        [[ $(ingress_value "$key") == "$(manifest_value "$key")" ]] \
            || fail "ingress pool changed a plan-owned semantic input: $key"
    done
    for member in a b; do
        if [[ $member == a ]]; then
            container=$candidate_container
            state_id_key=candidate_denial_id
            state_runtime_key=candidate_runtime_sha256
        else
            container=$pool_web_b_container
            state_id_key=web_b_denial_id
            state_runtime_key=web_b_runtime_sha256
        fi
        [[ $(ingress_value "member_${member}_role") == "web-$member" \
            && $(ingress_value "member_${member}_name") == "$container" \
            && $(ingress_value "member_${member}_route_identity") \
                == "$(manifest_value "member_${member}_route_identity")" \
            && $(ingress_value "member_${member}_id") == "$(state_value "$state_id_key")" \
            && $(ingress_value "member_${member}_image_reference") \
                == "$(manifest_value "member_${member}_image_reference")" \
            && $(ingress_value "member_${member}_image_id") \
                == "$(manifest_value "member_${member}_image_id")" \
            && $(ingress_value "member_${member}_runtime_sha256") \
                == "$(state_value "$state_runtime_key")" \
            && $(ingress_value "member_${member}_network_sha256") \
                == "$(state_value "web_${member}_network_sha256")" \
            && $(ingress_value "member_${member}_bindings_sha256") \
                == "$(state_value "web_${member}_bindings_sha256")" \
            && $(ingress_value "member_${member}_applied_ack_file") \
                == "$(manifest_value "member_${member}_applied_ack_file")" \
            && $(ingress_value "member_${member}_applied_ack_sha256") \
                == "$(manifest_value "member_${member}_applied_ack_sha256")" \
            && $(ingress_value "member_${member}_applied_ack_metadata") \
                == "$(manifest_value "member_${member}_applied_ack_metadata")" ]] \
            || fail "ingress pool member $member differs from its fence-pinned runtime"
        address=$(ingress_value "member_${member}_address")
        port=$(ingress_value "member_${member}_port")
        [[ $address =~ ^[A-Fa-f0-9:.]+$ && $port == "$(manifest_value backend_port)" ]] \
            || fail "ingress pool member $member address or port is malformed"
        inspect_json=$(require_docker_container_json "$container")
        [[ $(jq -r --arg address "$address" '[.[0].NetworkSettings.Networks[]
                | select(.IPAddress == $address or .GlobalIPv6Address == $address)] | length' \
                <<< "$inspect_json") -eq 1 \
            && $(jq -r --arg port "${port}/tcp" '.[0].Config.ExposedPorts | has($port)' \
                <<< "$inspect_json") == true ]] \
            || fail "ingress pool member $member address/port is not owned by its exact container"
        assert_container_route_identity_json "$inspect_json" \
            "$(manifest_value "member_${member}_route_identity")" "web-$member"
    done
    [[ $(ingress_value member_a_id) != "$(ingress_value member_b_id)" \
        && $(ingress_value member_a_name) != "$(ingress_value member_b_name)" ]] \
        || fail 'ingress pool duplicates a member identity'
    assert_distinct_values 'ingress route and Docker identity' \
        "$(ingress_value member_a_route_identity)" \
        "$(ingress_value member_b_route_identity)" \
        "$(ingress_value member_a_id)" "$(ingress_value member_b_id)"
    validate_sha256 ingress-member-set "$(ingress_value member_set_sha256)"
    [[ $(ingress_member_set_bytes | sha256sum | awk '{print $1}') \
        == "$(ingress_value member_set_sha256)" ]] \
        || fail 'ingress member-set hash does not match its canonical NUL-delimited tuple'
    ingress_pool_manifest_metadata=$SAFE_SOURCE_METADATA
}

pin_ingress_pool_manifest()
{
    local expected_sha256=${1:-} candidate
    [[ $# -eq 1 ]] || fail 'ingress pool pin requires exactly one manifest SHA-256'
    [[ -f $state_file && $(state_value phase) == active ]] \
        || fail 'ingress pool pin requires an active runtime fence'
    assert_common_identity
    assert_pool_denial
    validate_ingress_pool_manifest "$expected_sha256"
    if [[ $(state_value ingress_pool_manifest_sha256) != pending ]]; then
        [[ $(state_value ingress_pool_manifest_sha256) == "$expected_sha256" \
            && $(state_value ingress_pool_manifest_metadata) \
                == "$ingress_pool_manifest_metadata" ]] \
            || fail 'ingress pool manifest was already pinned to another identity'
        note "ingress_pool_pin=passed operation_id=$operation_id already_pinned=true"
        return
    fi
    candidate=$operation_directory/.state.ingress-pool.$$
    awk -F= -v sha256="$expected_sha256" -v metadata="$ingress_pool_manifest_metadata" '
        $1 == "ingress_pool_manifest_sha256" {
            print "ingress_pool_manifest_sha256=" sha256; next
        }
        $1 == "ingress_pool_manifest_metadata" {
            print "ingress_pool_manifest_metadata=" metadata; next
        }
        { print }
    ' "$state_file" > "$candidate"
    atomic_state "$candidate"
    test_crash after-ingress-pool-manifest-pin
    note "ingress_pool_pin=passed operation_id=$operation_id manifest_sha256=$expected_sha256"
}

assert_safe_parent_chain()
{
    local path=$1 current=/ component mode
    [[ $path == /* ]] || fail 'path must be absolute'
    IFS=/ read -r -a components <<< "${path#/}"
    for component in "${components[@]}"; do
        [[ -n $component ]] || continue
        current=${current%/}/$component
        [[ -e $current ]] || break
        [[ -d $current && ! -L $current ]] || fail "unsafe non-directory or symlink parent: $current"
        if [[ $test_mode == 0 ]]; then
            [[ $(stat -c '%u:%g' "$current") == 0:0 ]] || fail "state parent is not root-owned: $current"
            mode=$(stat -c '%a' "$current")
            (((8#$mode & 8#022) == 0)) || fail "state parent is group/world writable: $current"
        fi
    done
}

ensure_state_directory()
{
    local parent
    [[ $state_directory == /* ]] || fail 'state directory must be absolute'
    parent=$(dirname -- "$state_directory")
    assert_safe_parent_chain "$parent"
    [[ -d $parent && ! -L $parent ]] || fail 'state parent must be a non-symlink directory'
    if [[ ! -e $state_directory ]]; then
        install -d -m 0700 "$state_directory"
    fi
    [[ -d $state_directory && ! -L $state_directory ]] || fail 'state path is not a non-symlink directory'
    [[ $(readlink -f -- "$state_directory") == "$state_directory" ]] \
        || fail 'state directory path contains a symlink'
    [[ $(stat -c '%u:%g:%a' "$state_directory") == "$immutable_uid:$immutable_gid:700" ]] \
        || fail "state directory must be owned by $immutable_uid:$immutable_gid mode 0700"
}

state_value()
{
    local key=$1 count result
    count=$(grep -E -c "^${key}=" "$state_file" || true)
    [[ $count -eq 1 ]] || fail "state key is absent or duplicated: $key"
    result=$(sed -n "s/^${key}=//p" "$state_file")
    [[ $result != *$'\n'* ]] || fail "state key contains a newline: $key"
    printf '%s\n' "$result"
}

assert_candidate_runtime_state()
{
    local runtime_sha256 evidence_count
    runtime_sha256=$(state_value candidate_runtime_sha256)
    evidence_count=$(grep -E -c \
        '^(candidate_denial_|candidate_restart_policy=|candidate_repin_intent_sha256=|candidate_repinned_at_epoch=)' \
        "$state_file" || true)
    if [[ $runtime_sha256 == none ]]; then
        [[ $evidence_count -eq 0 ]] \
            || fail 'unpinned candidate runtime has partial denial evidence'
        return
    fi
    validate_sha256 candidate-runtime "$runtime_sha256"
    [[ $evidence_count -eq 12 \
        && $(state_value candidate_denial_name) == "$(state_value candidate_container)" \
        && $(state_value candidate_denial_id) =~ ^[a-f0-9]{64}$ \
        && $(state_value candidate_denial_image_id) == "$(state_value candidate_image_id)" \
        && $(state_value candidate_denial_image_reference) == "$(state_value candidate_image_reference)" \
        && $(state_value candidate_denial_started_at) =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[^[:space:]]+Z$ \
        && $(state_value candidate_denial_restart_count) =~ ^[0-9]+$ \
        && $(state_value candidate_restart_policy) =~ ^(unless-stopped|always)$ \
        && $(state_value candidate_denial_networks_b64) =~ ^[A-Za-z0-9+/=]+$ \
        && $(state_value candidate_denial_proven_at_epoch) =~ ^[1-9][0-9]*$ \
        && $(state_value candidate_repin_intent_sha256) =~ ^(none|[a-f0-9]{64})$ \
        && $(state_value candidate_repinned_at_epoch) =~ ^(none|[1-9][0-9]*)$ ]] \
        || fail 'pinned candidate runtime has incomplete or inconsistent denial evidence'
    if [[ $(state_value candidate_restart_policy) == unless-stopped ]]; then
        [[ $(state_value candidate_repin_intent_sha256) == none \
            && $(state_value candidate_repinned_at_epoch) == none ]] \
            || fail 'unrepinned candidate has restart-policy authorization evidence'
    else
        validate_sha256 candidate-repin-intent "$(state_value candidate_repin_intent_sha256)"
        [[ $(state_value candidate_repinned_at_epoch) =~ ^[1-9][0-9]*$ ]] \
            || fail 'repinned candidate timestamp is malformed'
    fi
}

assert_pool_runtime_state()
{
    local web_b_runtime_sha256 evidence_count
    web_b_runtime_sha256=$(state_value web_b_runtime_sha256)
    evidence_count=$(grep -E -c '^(web_b_denial_|web_b_restart_policy=|web_b_repin_intent_sha256=|web_b_repinned_at_epoch=)' \
        "$state_file" || true)
    if [[ $web_b_runtime_sha256 == none ]]; then
        [[ $evidence_count -eq 0 \
            && $(state_value pool_runtime_member_set_sha256) == pending ]] \
            || fail 'unproven web-b member has partial pool runtime evidence'
        return
    fi
    validate_sha256 web-b-runtime "$web_b_runtime_sha256"
    validate_sha256 pool-runtime-member-set "$(state_value pool_runtime_member_set_sha256)"
    [[ $evidence_count -eq 12 \
        && $(state_value web_b_denial_name) == "$pool_web_b_container" \
        && $(state_value web_b_denial_id) =~ ^[a-f0-9]{64}$ \
        && $(state_value web_b_denial_image_id) == "$pool_web_b_image_id" \
        && $(state_value web_b_denial_image_reference) == "$pool_web_b_image_reference" \
        && $(state_value web_b_denial_started_at) \
            =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[^[:space:]]+Z$ \
        && $(state_value web_b_denial_restart_count) =~ ^[0-9]+$ \
        && $(state_value web_b_restart_policy) =~ ^(unless-stopped|always)$ \
        && $(state_value web_b_denial_networks_b64) =~ ^[A-Za-z0-9+/=]+$ \
        && $(state_value web_b_denial_proven_at_epoch) =~ ^[1-9][0-9]*$ \
        && $(state_value web_b_repin_intent_sha256) =~ ^(none|[a-f0-9]{64})$ \
        && $(state_value web_b_repinned_at_epoch) =~ ^(none|[1-9][0-9]*)$ ]] \
        || fail 'web-b member has incomplete or inconsistent denial evidence'
    for key in web_a_network_sha256 web_a_bindings_sha256 web_b_network_sha256 \
        web_b_bindings_sha256; do
        validate_sha256 "$key" "$(state_value "$key")"
    done
    if [[ $(state_value web_b_restart_policy) == unless-stopped ]]; then
        [[ $(state_value web_b_repin_intent_sha256) == none \
            && $(state_value web_b_repinned_at_epoch) == none ]] \
            || fail 'unrepinned web-b member has restart-policy authorization evidence'
    else
        validate_sha256 web-b-repin-intent "$(state_value web_b_repin_intent_sha256)"
    fi
}

atomic_state()
{
    local source=$1
    chmod 0600 "$source"
    sync "$source"
    mv -f -- "$source" "$state_file"
    sync "$state_directory"
}

encode()
{
    base64 -w 0
}

semantic_configuration_sha256()
{
    local key
    for key in $SEMANTIC_KEYS; do
        printf '%s=%s\n' "$key" "${!key:-}"
    done | LC_ALL=C sort | sha256sum | awk '{print $1}'
}

release_files_manifest_sha256()
{
    local role path expected_uid expected_gid expected_mode
    while IFS='|' read -r role path expected_uid expected_gid expected_mode; do
        if [[ -z $path ]]; then
            [[ $role == provider-header ]] \
                || fail "required release authorization file path is absent: $role"
            printf '%s|absent\n' "$role"
            continue
        fi
        safe_snapshot_file "$path" '' '' "$expected_uid" "$expected_gid" \
            "$expected_mode" "$role"
        printf '%s|%s|%s|%s\n' "$role" "$path" "$SAFE_SOURCE_SHA256" \
            "$SAFE_SOURCE_METADATA"
    done <<EOF | sha256sum | awk '{print $1}'
provider-header|${CONTROL_PLANE_RUNTIME_PROVIDER_HEADER_FILE:-}|$immutable_uid|$immutable_gid|600
pool-plan|$pool_plan_manifest|$immutable_uid|$immutable_gid|600
route-health-token|$(manifest_value route_health_token_file)|$immutable_uid|$immutable_gid|600
route-health-runtime|$(manifest_value route_health_runtime_file)|$runtime_artifact_uid|$runtime_artifact_gid|400
pool-ack|$(manifest_value pool_ack_file)|$immutable_uid|$immutable_gid|600
pool-ack-runtime|$(manifest_value pool_ack_runtime_file)|$runtime_artifact_uid|$runtime_artifact_gid|400
web-a-direct-probe-token|$(manifest_value member_a_direct_probe_token_file)|$immutable_uid|$immutable_gid|600
web-a-direct-probe-runtime|$(manifest_value member_a_direct_probe_runtime_file)|$runtime_artifact_uid|$runtime_artifact_gid|400
web-a-applied-ack|$(manifest_value member_a_applied_ack_file)|$immutable_uid|$immutable_gid|600
web-a-applied-ack-runtime|$(manifest_value member_a_applied_ack_runtime_file)|$runtime_artifact_uid|$runtime_artifact_gid|400
web-b-direct-probe-token|$(manifest_value member_b_direct_probe_token_file)|$immutable_uid|$immutable_gid|600
web-b-direct-probe-runtime|$(manifest_value member_b_direct_probe_runtime_file)|$runtime_artifact_uid|$runtime_artifact_gid|400
web-b-applied-ack|$(manifest_value member_b_applied_ack_file)|$immutable_uid|$immutable_gid|600
web-b-applied-ack-runtime|$(manifest_value member_b_applied_ack_runtime_file)|$runtime_artifact_uid|$runtime_artifact_gid|400
EOF
}

process_start_time()
{
    local pid=$1 process_stat remainder
    local -a fields

    [[ $pid =~ ^[1-9][0-9]*$ && -r /proc/$pid/stat ]] \
        || fail 'watchdog process identity is unavailable'
    process_stat=$(<"/proc/$pid/stat")
    remainder=${process_stat##*) }
    read -r -a fields <<< "$remainder"
    [[ ${fields[19]:-} =~ ^[1-9][0-9]*$ ]] \
        || fail 'watchdog process start time is malformed'
    printf '%s\n' "${fields[19]}"
}

tool_version()
{
    case "$1" in
        docker) docker version --format '{{.Client.Version}}/{{.Server.Version}}' ;;
        nft) nft --version ;;
        conntrack) conntrack -V ;;
        ssh) ssh -V 2>&1 ;;
        systemd) systemctl --version | sed -n '1p' ;;
        *) fail "unknown tool version request: $1" ;;
    esac
}

docker_engine_request()
{
    local resource=$1 reference=$2 endpoint=$3 response_file http_status curl_status
    local parsed_response parse_status
    response_file=$(mktemp "${TMPDIR:-/tmp}/coolify-runtime-docker-api.XXXXXX")
    set +e
    http_status=$(curl --silent --show-error --max-time 5 \
        --unix-socket "$docker_socket" --output "$response_file" \
        --write-out '%{http_code}' "http://localhost/$endpoint")
    curl_status=$?
    set -e
    if ((curl_status != 0)); then
        rm -f -- "$response_file"
        fail "Docker Engine API is unavailable: resource=$resource"
    fi
    if [[ ! $http_status =~ ^[0-9]{3}$ ]]; then
        rm -f -- "$response_file"
        fail "Docker Engine API returned a malformed HTTP status: resource=$resource"
    fi

    case "$http_status" in
        200)
            set +e
            case "$resource" in
                container)
                    parsed_response=$(jq -e -S -c '
                        select(type == "object"
                            and (.Id | type == "string"
                                and test("^[a-f0-9]{64}$"))
                            and (.Name | type == "string" and startswith("/"))
                            and (.Image | type == "string"
                                and test("^sha256:[a-f0-9]{64}$"))
                            and (.Config | type == "object")
                            and (.State | type == "object")
                            and (.RestartCount | type == "number")
                            and (.HostConfig | type == "object")
                            and (.Mounts | type == "array")
                            and (.NetworkSettings | type == "object")
                            and (.NetworkSettings.Networks | type == "object"))
                        | [.]' "$response_file")
                    ;;
                container-list)
                    parsed_response=$(jq -e -S -c '
                        select(type == "array"
                            and all(.[];
                                type == "object"
                                and (.Id | type == "string"
                                    and test("^[a-f0-9]{64}$"))
                                and (.Names | type == "array")
                                and all(.Names[]; type == "string"
                                    and startswith("/"))))' "$response_file")
                    ;;
                network)
                    parsed_response=$(jq -e -S -c '
                        select(type == "object"
                            and (.Id | type == "string"
                                and test("^[a-f0-9]{64}$"))
                            and (.Name | type == "string"
                                and test("^[A-Za-z0-9_.-]+$"))
                            and (.Driver | type == "string")
                            and (.Options == null or (.Options | type == "object"))
                            and (.IPAM | type == "object")
                            and (.IPAM.Config | type == "array"))
                        | [.]' "$response_file")
                    ;;
                info)
                    parsed_response=$(jq -e -S -c '
                        select(type == "object"
                            and (.ID | type == "string"
                                and test("^[A-Za-z0-9:_-]+$")))' "$response_file")
                    ;;
                *)
                    rm -f -- "$response_file"
                    fail "unknown Docker Engine API resource: $resource"
                    ;;
            esac
            parse_status=$?
            set -e
            if ((parse_status != 0)); then
                rm -f -- "$response_file"
                fail "Docker Engine API returned malformed $resource data"
            fi
            docker_api_state=present
            docker_api_json=$parsed_response
            ;;
        404)
            set +e
            case "$resource" in
                container)
                    jq -e --arg reference "$reference" '
                        type == "object"
                        and .message == ("No such container: " + $reference)
                    ' "$response_file" >/dev/null
                    ;;
                network)
                    jq -e --arg reference "$reference" '
                        type == "object"
                        and (.message == ("network " + $reference + " not found")
                            or .message == ("No such network: " + $reference))
                    ' "$response_file" >/dev/null
                    ;;
                *)
                    false
                    ;;
            esac
            parse_status=$?
            set -e
            if ((parse_status != 0)); then
                rm -f -- "$response_file"
                fail "Docker Engine API returned an invalid $resource not-found response"
            fi
            docker_api_state=absent
            docker_api_json=
            ;;
        *)
            rm -f -- "$response_file"
            fail "Docker Engine API returned HTTP $http_status: resource=$resource"
            ;;
    esac
    rm -f -- "$response_file"
}

inspect_docker_container()
{
    validate_identifier docker-container "$1"
    docker_engine_request container "$1" "containers/$1/json"
}

require_docker_container_json()
{
    inspect_docker_container "$1"
    [[ $docker_api_state == present ]] \
        || fail "required Docker container is absent: $1"
    printf '%s\n' "$docker_api_json"
}

inspect_docker_network()
{
    [[ $1 =~ ^[a-f0-9]{64}$ ]] || fail 'Docker network ID is malformed'
    docker_engine_request network "$1" "networks/$1"
}

docker_container_list()
{
    docker_engine_request container-list all 'containers/json?all=1&size=0'
    [[ $docker_api_state == present ]] \
        || fail 'Docker container list unexpectedly reported absence'
}

container_id_is_listed()
{
    local id=$1 match_count
    [[ $id =~ ^[a-f0-9]{64}$ ]] || fail 'Docker container ID is malformed'
    docker_container_list
    match_count=$(jq -r --arg id "$id" '[.[] | select(.Id == $id)] | length' \
        <<< "$docker_api_json")
    [[ $match_count =~ ^[0-9]+$ ]] \
        || fail 'Docker container list match count is malformed'
    ((match_count > 0))
}

pool_selector_rows()
{
    docker_container_list
    jq -r --arg key "$pool_label_key" --arg value "$pool_label_value" '
        .[]
        | select((.Labels // {})[$key] == $value)
        | [.Id, ((.Names // []) | sort | join(","))]
        | @tsv
    ' <<< "$docker_api_json" | LC_ALL=C sort
}

assert_pool_candidates_absent()
{
    inspect_docker_container "$candidate_container"
    [[ $docker_api_state == absent ]] || fail 'planned web-a member exists before the fence is active'
    inspect_docker_container "$pool_web_b_container"
    [[ $docker_api_state == absent ]] || fail 'planned web-b member exists before the fence is active'
    [[ -z $(pool_selector_rows) ]] \
        || fail 'pool selector resolved an unexpected candidate before the fence was active'
}

assert_exact_pool_selector_members()
{
    local actual expected
    actual=$(pool_selector_rows)
    expected=$(printf '%s\t/%s\n%s\t/%s\n' \
        "$(state_value candidate_denial_id)" "$(state_value candidate_denial_name)" \
        "$(state_value web_b_denial_id)" "$(state_value web_b_denial_name)" \
        | LC_ALL=C sort)
    [[ $actual == "$expected" ]] \
        || fail 'pool selector resolved missing, duplicate, or unexpected candidate members'
}

docker_daemon_id()
{
    docker_engine_request info daemon info
    [[ $docker_api_state == present ]] || fail 'Docker daemon identity is absent'
    jq -r .ID <<< "$docker_api_json"
}

container_json()
{
    local inspect_json
    inspect_json=$(require_docker_container_json "$1")
    jq -S -c '.[0] | {
        id: .Id,
        image_id: .Image,
        image_reference: .Config.Image,
        started_at: .State.StartedAt,
        status: .State.Status,
        running: .State.Running,
        health: (.State.Health.Status // "not-configured"),
        restart_count: .RestartCount,
        restart_policy: .HostConfig.RestartPolicy.Name,
        configured_bindings: (.HostConfig.PortBindings // {}),
        published_bindings: (.NetworkSettings.Ports // {})
    }' <<< "$inspect_json"
}

assert_container_route_identity_json()
{
    local inspect_json=$1 expected=$2 label=$3 observed
    observed=$(jq -e -r '
        [.[0].Config.Env[]? | select(startswith("CONTROL_PLANE_MEMBER_ID="))] as $entry
        | select(($entry | length) == 1)
        | $entry[0] | sub("^CONTROL_PLANE_MEMBER_ID="; "")
    ' <<< "$inspect_json") || fail "$label has no single configured route identity"
    [[ $observed == "$expected" ]] \
        || fail "$label configured route identity differs from the immutable plan"
}

assert_container_route_identity()
{
    local container=$1 expected=$2 label=$3 inspect_json
    inspect_json=$(require_docker_container_json "$container")
    assert_container_route_identity_json "$inspect_json" "$expected" "$label"
}

protected_mount_role()
{
    case "$1" in
        /var/www/html/.env) printf '%s\n' startup-environment ;;
        /run/secrets/control-plane-direct-probe-token) printf '%s\n' direct-probe-token ;;
        /run/secrets/control-plane-applied-ack) printf '%s\n' applied-ack ;;
        /run/secrets)
            if [[ ${test_mode:-0} == 1 ]]; then
                printf '%s\n' lab-secret-directory
            else
                printf '%s\n' ordinary
            fi
            ;;
        *) printf '%s\n' ordinary ;;
    esac
}

lab_secret_directory_sha256()
{
    local secret_directory=$1 entry_count secret_file secret_path metadata

    [[ -d $secret_directory && ! -L $secret_directory ]] \
        || fail 'lab secret directory is not a non-symlink directory'
    [[ $(readlink -f -- "$secret_directory") == "$secret_directory" ]] \
        || fail 'lab secret directory path contains a symlink'
    require_command find
    require_command sort
    entry_count=$(find "$secret_directory" -mindepth 1 -maxdepth 1 -print \
        | wc -l | tr -d '[:space:]')
    [[ $entry_count == 2 ]] \
        || fail 'lab secret directory must contain exactly two entries'
    {
        for secret_file in control-plane-direct-probe-token control-plane-applied-ack; do
            secret_path=$secret_directory/$secret_file
            [[ -f $secret_path && ! -L $secret_path ]] \
                || fail "lab secret directory child is not a regular non-symlink file: $secret_file"
            metadata=$(stat -c '%d:%i:%u:%g:%a:%s' "$secret_path")
            printf '%s|%s|%s\n' "$secret_file" "$metadata" "$(sha256_file "$secret_path")"
        done
    } | LC_ALL=C sort | sha256sum | awk '{print $1}'
}

mounted_source_identities()
{
    local inspect_json=$1 encoded_mount mount type source destination read_write role
    local resolved metadata source_type source_sha256

    while IFS= read -r encoded_mount; do
        [[ -n $encoded_mount ]] || continue
        mount=$(printf '%s' "$encoded_mount" | base64 --decode)
        type=$(jq -r '.Type' <<< "$mount")
        source=$(jq -r '.Source' <<< "$mount")
        destination=$(jq -r '.Destination' <<< "$mount")
        read_write=$(jq -r '.RW | tostring' <<< "$mount")
        role=$(protected_mount_role "$destination")
        [[ $source == /* && $source != *$'\n'* && $destination == /* ]] \
            || fail 'container mount has an unsafe source or destination'
        if [[ $role == lab-secret-directory ]]; then
            [[ $type == bind && $read_write == false && -d $source && ! -L $source ]] \
                || fail "$role mount must be a read-only non-symlink directory bind source"
        elif [[ $role != ordinary ]]; then
            [[ $type == bind && $read_write == false && -f $source && ! -L $source ]] \
                || fail "$role mount must be a read-only regular non-symlink bind source"
        fi
        if [[ $type == bind ]]; then
            [[ -e $source && ! -L $source ]] \
                || fail "bind mount source is absent or a symlink: $destination"
            resolved=$(readlink -f -- "$source")
            [[ $resolved == "$source" ]] \
                || fail "bind mount source path contains a symlink: $destination"
            if [[ -f $source ]]; then
                source_type='file'
                metadata=$(stat -c '%d:%i:%u:%g:%a:%s' "$source") \
                    || fail "bind mount file metadata capture failed: $destination"
                source_sha256=$(sha256_file "$source") \
                    || fail "bind mount file content capture failed: $destination"
            elif [[ -d $source ]]; then
                source_type='directory'
                # Writable bind directories legitimately gain and lose children while
                # their mount identity remains unchanged. Directory size reflects
                # entry allocation, not the identity of the mounted inode.
                metadata=$(stat -c '%d:%i:%u:%g:%a' "$source") \
                    || fail "bind mount directory metadata capture failed: $destination"
                if [[ $role == lab-secret-directory ]]; then
                    source_sha256=$(lab_secret_directory_sha256 "$source") \
                        || fail 'lab secret directory identity capture failed'
                else
                    source_sha256='unavailable'
                fi
            else
                fail "bind mount source has an unsupported file type: $destination"
            fi
            jq -S -c -n --arg role "$role" --arg source "$source" \
                --arg destination "$destination" --arg source_type "$source_type" \
                --arg metadata "$metadata" --arg source_sha256 "$source_sha256" \
                '{role: $role, source: $source, destination: $destination,
                    source_type: $source_type, metadata: $metadata,
                    source_sha256: $source_sha256}'
        fi
    done < <(jq -r '.[0].Mounts
        | if type == "array" then .[] | @base64 else empty end' <<< "$inspect_json") \
        | jq -S -c -s 'sort_by(.destination, .source)'
}

container_static_contract_json()
{
    local container=$1 inspect_json mounted_sources
    validate_identifier container "$container"
    inspect_json=$(require_docker_container_json "$container") \
        || fail 'container inspection failed while building the static runtime contract'
    [[ $(jq -r 'type == "array" and length == 1' <<< "$inspect_json") == true ]] \
        || fail 'container inspection did not return exactly one runtime'
    mounted_sources=$(mounted_source_identities "$inspect_json") \
        || fail 'mounted source identity capture failed while building the static runtime contract'
    printf '%s' "$inspect_json" | jq -S -c --argjson mounted_sources "$mounted_sources" \
        --argjson contract_version "$RUNTIME_DIGEST_VERSION" '
        def sorted_array:
            if type == "array" then sort_by(tojson) else . end;
        def sort_array_field(name):
            if has(name) then .[name] |= sorted_array else . end;
        def canonical_port_bindings:
            if type == "object" then
                with_entries(.value |=
                    if type == "array" then sort_by(.HostIp // "", .HostPort // "") else . end)
            else . end;
        def normalize_host_config:
            del(.RestartPolicy)
            | sort_array_field("Binds")
            | sort_array_field("Mounts")
            | sort_array_field("VolumesFrom")
            | sort_array_field("CapAdd")
            | sort_array_field("CapDrop")
            | sort_array_field("GroupAdd")
            | sort_array_field("Links")
            | sort_array_field("SecurityOpt")
            | sort_array_field("Devices")
            | sort_array_field("DeviceCgroupRules")
            | sort_array_field("DeviceRequests")
            | sort_array_field("Ulimits")
            | sort_array_field("BlkioWeightDevice")
            | sort_array_field("BlkioDeviceReadBps")
            | sort_array_field("BlkioDeviceWriteBps")
            | sort_array_field("BlkioDeviceReadIOps")
            | sort_array_field("BlkioDeviceWriteIOps")
            | sort_array_field("MaskedPaths")
            | sort_array_field("ReadonlyPaths")
            | if has("PortBindings") then
                .PortBindings |= canonical_port_bindings
              else . end;
        .[0] | {
            contract_version: $contract_version,
            config: .Config,
            host_config: (.HostConfig | normalize_host_config),
            mounts: (.Mounts |
                if type == "array" then map({
                    type: .Type,
                    name: .Name,
                    source: .Source,
                    destination: .Destination,
                    driver: .Driver,
                    mode: .Mode,
                    read_write: .RW,
                    propagation: .Propagation
                }) | sort_by(.destination, .source) else . end),
            mounted_source_identities: $mounted_sources,
            declared_network_plan: {
                ports: (.NetworkSettings.Ports | canonical_port_bindings),
                networks: (.NetworkSettings.Networks |
                    if type == "object" then to_entries | map({
                        name: .key,
                        network_id: .value.NetworkID,
                        ipam_config: .value.IPAMConfig,
                        links: (.value.Links | sorted_array),
                        driver_opts: .value.DriverOpts,
                        gw_priority: .value.GwPriority
                    }) | sort_by(.name, .network_id) else . end)
            }
        }'
}

assert_candidate_static_baseline_contract()
{
    local contract=$1
    jq --exit-status '
        .contract_version == 4
        and .config.NetworkDisabled == false
        and .host_config.AutoRemove == false
        and (.host_config.CapAdd // []) == []
        and (.host_config.CapDrop // []) == []
        and (.host_config.Devices // []) == []
        and (.host_config.DeviceCgroupRules // []) == []
        and (.host_config.DeviceRequests // []) == []
        and .host_config.IpcMode == "private"
        and .host_config.PidMode == ""
        and .host_config.Privileged == false
        and .host_config.PublishAllPorts == false
        and .host_config.ReadonlyRootfs == false
        and (.host_config.SecurityOpt // []) == []
        and .host_config.UsernsMode == ""
        and .host_config.UTSMode == ""
        and .host_config.Runtime == "runc"
        and (.host_config.NetworkMode | type == "string" and length > 0)
        and (.host_config.Binds | type == "array")
        and (.mounts | type == "array")
        and (.declared_network_plan.networks | type == "array" and length > 0)
    ' <<< "$contract" >/dev/null \
        || fail 'candidate HostConfig security baseline differs from the canonical static contract'
}

assert_pool_member_mount_contract()
{
    local container=$1 member=$2 private_volume coordination_volume inspect_json
    local member_a_private_volume member_b_private_volume
    validate_identifier container "$container"
    case "$member" in
        a|b) ;;
        *) fail 'pool member mount contract requested outside web-a or web-b' ;;
    esac
    private_volume=$(manifest_value "member_${member}_private_volume")
    coordination_volume=$(manifest_value coordination_volume)
    member_a_private_volume=$(manifest_value member_a_private_volume)
    member_b_private_volume=$(manifest_value member_b_private_volume)
    [[ $private_volume != "$coordination_volume" \
        && $member_a_private_volume != "$member_b_private_volume" ]] \
        || fail 'pool plan does not assign distinct private and coordination volumes'
    inspect_json=$(require_docker_container_json "$container") \
        || fail 'pool member inspection failed while checking planned volume mounts'
    jq --exit-status --arg private_volume "$private_volume" \
        --arg coordination_volume "$coordination_volume" \
        --arg member_a_private_volume "$member_a_private_volume" \
        --arg member_b_private_volume "$member_b_private_volume" \
        --arg member "$member" '
        .[0].Mounts
        | if type == "array" then . else error("container mounts are not an array") end
        | [ .[] | select(.Destination == "/var/lib/coolify-control-plane/private") ] as $private
        | [ .[] | select(.Destination == "/var/lib/coolify-control-plane/coordination") ] as $coordination
        | [ .[] | select(.Name == $private_volume) ] as $private_volume_mounts
        | [ .[] | select(.Name == $coordination_volume) ] as $coordination_volume_mounts
        | [ .[] | select(
            .Name == (if $member == "a" then $member_b_private_volume
                else $member_a_private_volume end)) ] as $other_private_volume_mounts
        | ($private | length) == 1
            and ($private[0].Type == "volume")
            and ($private[0].Name == $private_volume)
            and ($private[0].RW == true)
            and ($coordination | length) == 1
            and ($coordination[0].Type == "volume")
            and ($coordination[0].Name == $coordination_volume)
            and ($coordination[0].RW == true)
            and ($private_volume_mounts | length) == 1
            and ($private_volume_mounts[0].Destination
                == "/var/lib/coolify-control-plane/private")
            and ($coordination_volume_mounts | length) == 1
            and ($coordination_volume_mounts[0].Destination
                == "/var/lib/coolify-control-plane/coordination")
            and ($other_private_volume_mounts | length) == 0
    ' <<< "$inspect_json" >/dev/null \
        || fail 'pool member live private or coordination volume mount differs from the immutable plan'
}

assert_candidate_static_baseline()
{
    local container=$1 contract expected_route_identity role member
    contract=$(container_static_contract_json "$container") \
        || fail 'candidate static runtime contract capture failed'
    assert_candidate_static_baseline_contract "$contract"
    case "$container" in
        "$candidate_container") expected_route_identity=$candidate_route_identity; role=web-a; member=a ;;
        "$pool_web_b_container") expected_route_identity=$pool_web_b_route_identity; role=web-b; member=b ;;
        *) fail 'candidate static baseline requested outside the immutable pool plan' ;;
    esac
    assert_pool_member_mount_contract "$container" "$member"
    assert_container_route_identity "$container" "$expected_route_identity" "$role"
}

container_runtime_sha256()
{
    local contract
    contract=$(container_static_contract_json "$1") \
        || fail 'container static runtime contract capture failed'
    printf '%s' "$contract" | sha256sum | awk '{print $1}'
}

container_field()
{
    container_json "$1" | jq -r "$2"
}

systemd_value()
{
    systemctl show docker.service --property="$1" --value
}

systemd_unit_snapshot()
{
    local unit=$1 property
    local -a arguments=(show "$unit" --no-pager)
    for property in $SYSTEMD_PROPERTIES; do
        arguments+=(--property="$property")
    done
    systemctl "${arguments[@]}"
}

systemd_snapshot_property()
{
    local snapshot=$1 unit=$2 property=$3 count value
    count=$(grep -E -c "^${property}=" <<< "$snapshot" || true)
    [[ $count -eq 1 ]] || fail "systemd property is absent or duplicated: $unit $property"
    value=$(sed -n "s/^${property}=//p" <<< "$snapshot")
    [[ $value != *$'\n'* ]] || fail "systemd property contains a newline: $unit $property"
    if [[ $property == ExecStart ]]; then
        value=${value%% ; start_time=*}
    fi
    case "$property" in
        After|Before|Wants|Requires|BindsTo|PartOf|DropInPaths|CapabilityBoundingSet|\
        AmbientCapabilities|SupplementaryGroups|ReadWritePaths|ReadOnlyPaths|\
        InaccessiblePaths|RestrictAddressFamilies|RestrictNamespaces|SystemCallFilter|\
        SystemCallArchitectures|DeviceAllow|IPAddressAllow|IPAddressDeny)
            value=$(tr ' ' '\n' <<< "$value" | sed '/^$/d' | LC_ALL=C sort -u | paste -sd' ' -)
            ;;
    esac
    printf '%s' "$value"
}

systemd_fragment_directory()
{
    if [[ $test_mode == 1 && -n ${CONTROL_PLANE_RUNTIME_TEST_SYSTEMD_DIRECTORY:-} ]]; then
        printf '%s\n' "$CONTROL_PLANE_RUNTIME_TEST_SYSTEMD_DIRECTORY"
    else
        printf '%s\n' "$SYSTEMD_DIRECTORY"
    fi
}

systemd_unit_file_manifest()
{
    local unit=$1 snapshot=$2 expected_fragment fragment drop_in drop_in_paths
    expected_fragment=$(systemd_fragment_directory)/$unit
    fragment=$(systemd_snapshot_property "$snapshot" "$unit" FragmentPath)
    [[ $fragment == "$expected_fragment" ]] \
        || fail "systemd unit fragment path is not canonical: $unit"
    assert_root_path "$fragment" 644 "$unit-fragment"
    printf 'fragment|%s|%s|%s\n' "$fragment" "$(sha256_file "$fragment")" \
        "$(stat -c '%u:%g:%a:%s' "$fragment")"
    drop_in_paths=$(systemd_snapshot_property "$snapshot" "$unit" DropInPaths)
    if [[ -z $drop_in_paths ]]; then
        printf 'drop-in|absent\n'
        return
    fi
    for drop_in in $drop_in_paths; do
        [[ $drop_in == /* && $drop_in != *$'\n'* ]] \
            || fail "systemd drop-in path is unsafe: $unit"
        assert_root_path "$drop_in" 644 "$unit-drop-in"
        printf 'drop-in|%s|%s|%s\n' "$drop_in" "$(sha256_file "$drop_in")" \
            "$(stat -c '%u:%g:%a:%s' "$drop_in")"
    done
}

systemd_unit_contract()
{
    local unit=$1 snapshot actual_properties expected_properties
    snapshot=$(systemd_unit_snapshot "$unit")
    actual_properties=$(cut -d= -f1 <<< "$snapshot" | LC_ALL=C sort)
    expected_properties=$(tr ' ' '\n' <<< "$SYSTEMD_PROPERTIES" | LC_ALL=C sort)
    [[ $actual_properties == "$expected_properties" ]] \
        || fail "systemd unit property inventory changed: $unit expected=$(tr '\n' ',' <<< "$expected_properties") actual=$(tr '\n' ',' <<< "$actual_properties")"
    printf 'unit=%s\n' "$unit"
    systemd_unit_file_manifest "$unit" "$snapshot"
    sed -E '/^ExecStart=/ s/ ; start_time=.*$//' <<< "$snapshot" \
        | LC_ALL=C sort | sed 's/^/property|/'
}

systemd_contract_sha256()
{
    {
        printf 'contract_version=1\n'
        systemd_unit_contract "$RESTORE_SERVICE"
        systemd_unit_contract "$WATCHDOG_SERVICE"
    } | sha256sum | awk '{print $1}'
}

assert_systemd_contract()
{
    local expected
    expected=$(state_value systemd_contract_sha256)
    validate_sha256 systemd-contract "$expected"
    [[ $(systemd_contract_sha256) == "$expected" ]] \
        || fail 'systemd runtime fence unit contract changed'
}

runtime_value()
{
    case "$1" in
        boot_id) tr -d '\n' < "$boot_id_file" ;;
        dockerd_pid) systemd_value MainPID ;;
        dockerd_invocation_id) systemd_value InvocationID ;;
        docker_daemon_id) docker_daemon_id ;;
        docker_socket) stat -Lc '%d:%i' "$docker_socket" ;;
        proxy_json) container_json "$proxy_container" ;;
        legacy_json) container_json "$legacy_container" ;;
        retired_b_json)
            [[ $pool_retired_member_count == 2 ]] \
                || fail 'bootstrap generation has no retired member B'
            container_json "$pool_retired_member_b_name"
            ;;
        docker_version) tool_version docker ;;
        nft_version) tool_version nft ;;
        conntrack_version) tool_version conntrack ;;
        ssh_version) tool_version ssh ;;
        systemd_version) tool_version systemd ;;
        *) fail "unknown runtime value: $1" ;;
    esac
}

assert_live_runtime_shape()
{
    local boot_id dockerd_pid dockerd_invocation_id daemon_id socket_identity
    local proxy_json legacy_json current_json
    boot_id=$(runtime_value boot_id)
    dockerd_pid=$(runtime_value dockerd_pid)
    dockerd_invocation_id=$(runtime_value dockerd_invocation_id)
    daemon_id=$(runtime_value docker_daemon_id)
    socket_identity=$(runtime_value docker_socket)
    proxy_json=$(runtime_value proxy_json)
    legacy_json=$(runtime_value legacy_json)
    [[ $boot_id =~ ^[a-f0-9-]{36}$ ]] || fail 'boot ID is malformed'
    [[ $dockerd_pid =~ ^[1-9][0-9]*$ ]] || fail 'dockerd MainPID is malformed'
    [[ $dockerd_invocation_id =~ ^[a-f0-9]{32}$ ]] \
        || fail 'dockerd InvocationID is malformed'
    [[ $daemon_id =~ ^[A-Za-z0-9:_-]+$ ]] \
        || fail 'Docker daemon identity is malformed'
    [[ $socket_identity =~ ^[0-9]+:[0-9]+$ ]] \
        || fail 'Docker socket device/inode identity is malformed'
    for current_json in "$proxy_json" "$legacy_json"; do
        [[ $(jq -r .id <<< "$current_json") =~ ^[a-f0-9]{64}$ \
            && $(jq -r .image_id <<< "$current_json") =~ ^sha256:[a-f0-9]{64}$ \
            && $(jq -r .started_at <<< "$current_json") =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[^[:space:]]+Z$ \
            && $(jq -r .status <<< "$current_json") == running \
            && $(jq -r .running <<< "$current_json") == true \
            && $(jq -r .health <<< "$current_json") =~ ^(healthy|not-configured)$ \
            && $(jq -r .restart_count <<< "$current_json") =~ ^[0-9]+$ ]] \
            || fail 'container runtime identity is malformed, stopped, or unhealthy'
    done
    [[ $(jq -r .id <<< "$legacy_json") == "$pool_retired_member_a_id" ]] \
        || fail 'retired member A differs from the exact pool plan identity'
    if [[ $pool_retired_member_count == 2 ]]; then
        current_json=$(runtime_value retired_b_json)
        [[ $(jq -r .id <<< "$current_json") == "$pool_retired_member_b_id" \
            && $(jq -r .image_id <<< "$current_json") =~ ^sha256:[a-f0-9]{64}$ \
            && $(jq -r .started_at <<< "$current_json") \
                =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[^[:space:]]+Z$ \
            && $(jq -r .status <<< "$current_json") == running \
            && $(jq -r .running <<< "$current_json") == true \
            && $(jq -r .health <<< "$current_json") =~ ^(healthy|not-configured)$ \
            && $(jq -r .restart_count <<< "$current_json") =~ ^[0-9]+$ ]] \
            || fail 'retired member B runtime identity is malformed, stopped, unhealthy, or unplanned'
    fi
    [[ -n $legacy_json ]] || fail 'legacy container identity is absent'
}

assert_runtime_value()
{
    local key=$1 current expected
    current=$(runtime_value "$key")
    expected=$(expected_runtime_value "$key")
    [[ $current == "$expected" ]] \
        || fail "same-boot runtime identity changed: $key expected=$expected current=$current action=${FUNCNAME[2]:-unknown}"
}

runtime_recovery_value()
{
    local file=$1 key=$2 count result
    count=$(grep -E -c "^${key}=" "$file" || true)
    [[ $count -eq 1 ]] || fail "runtime-recovery key is absent or duplicated: $key"
    result=$(sed -n "s/^${key}=//p" "$file")
    [[ $result != *$'\n'* ]] || fail "runtime-recovery key contains a newline: $key"
    printf '%s\n' "$result"
}

runtime_recovery_status()
{
    local file=$1 sequence status
    sequence=$(sed -n 's/^status=//p' "$file" | paste -sd, -)
    case "$sequence" in
        intent|intent,fence-verified|intent,fence-verified,runtime-verified|\
        intent,fence-verified,runtime-verified,route-verified|\
        intent,fence-verified,runtime-verified,route-verified,verified)
            ;;
        *) fail "runtime-recovery status lineage is malformed: $sequence" ;;
    esac
    status=${sequence##*,}
    printf '%s\n' "$status"
}

validate_runtime_recovery_document()
{
    local file=$1 keys key status expected_transition_keys
    keys=$(
        {
            runtime_recovery_document_keys
            runtime_recovery_transition_keys
        } | paste -sd' ' -
    )
    awk -F= -v keys="$keys" '
        BEGIN {
            split(keys, inventory, /[[:space:]]+/)
            for (key_index in inventory) allowed[inventory[key_index]] = 1
        }
        NF < 2 || $1 == "" { invalid = 1; exit }
        $1 == "status" {
            status_count++
            next
        }
        !($1 in allowed) || seen[$1]++ { invalid = 1; exit }
        END { exit(!invalid && status_count >= 1 && status_count <= 5 ? 0 : 1) }
    ' "$file" || fail 'runtime-recovery document has an unknown, duplicate, or malformed record'
    while IFS= read -r key; do
        runtime_recovery_value "$file" "$key" >/dev/null
    done < <(runtime_recovery_document_keys)
    status=$(runtime_recovery_status "$file")
    expected_transition_keys=$(runtime_recovery_transition_keys_for_status "$status" | paste -sd' ' -)
    while IFS= read -r key; do
        [[ -n $key ]] || continue
        if [[ " $expected_transition_keys " == *" $key "* ]]; then
            runtime_recovery_value "$file" "$key" >/dev/null
        elif [[ $(grep -E -c "^${key}=" "$file" || true) -ne 0 ]]; then
            fail "runtime-recovery transition record is present before its status boundary: $key"
        fi
    done < <(runtime_recovery_transition_keys)
}

runtime_recovery_document_keys()
{
    local role
    printf '%s\n' \
        version operation_id generation parent_generation prior_generation_sha256 \
        direction color parent_phase prior_provider_expectation provider_expectation recovery_kind \
        old_boot_id old_dockerd_pid old_dockerd_invocation_id old_docker_daemon_id \
        old_docker_socket new_boot_id new_dockerd_pid new_dockerd_invocation_id \
        new_docker_daemon_id new_docker_socket retired_member_count \
        pool_member_set_sha256
    for role in $RUNTIME_RECOVERY_ROLES; do
        printf '%s\n' "${role}_name" "${role}_id" \
            "old_${role}_snapshot_sha256" "new_${role}_snapshot_sha256" \
            "old_${role}_static_sha256" "new_${role}_static_sha256" \
            "old_${role}_snapshot_b64" "new_${role}_snapshot_b64"
    done
    for role in web_a web_b; do
        printf '%s\n' "old_${role}_network_sha256" "new_${role}_network_sha256" \
            "old_${role}_network_b64" "new_${role}_network_b64"
    done
    printf '%s\n' \
        old_network_inventory_sha256 new_network_inventory_sha256 \
        old_network_inventory_b64 new_network_inventory_b64 \
        release_provider_sha256 release_queue_sha256 release_terminal_sha256 \
        release_files_sha256 intent_at_epoch
}

runtime_recovery_transition_keys()
{
    printf '%s\n' \
        fence_proof_sha256 fence_provider_sha256 fence_verified_at_epoch \
        runtime_provider_sha256 runtime_queue_sha256 runtime_terminal_sha256 \
        runtime_verified_at_epoch route_proof_sha256 route_verified_at_epoch verified_at_epoch
}

runtime_recovery_transition_keys_for_status()
{
    case "$1" in
        intent) ;;
        fence-verified)
            printf '%s\n' fence_proof_sha256 fence_provider_sha256 fence_verified_at_epoch
            ;;
        runtime-verified)
            printf '%s\n' fence_proof_sha256 fence_provider_sha256 fence_verified_at_epoch \
                runtime_provider_sha256 runtime_queue_sha256 runtime_terminal_sha256 \
                runtime_verified_at_epoch
            ;;
        route-verified)
            printf '%s\n' fence_proof_sha256 fence_provider_sha256 fence_verified_at_epoch \
                runtime_provider_sha256 runtime_queue_sha256 runtime_terminal_sha256 \
                runtime_verified_at_epoch route_proof_sha256 route_verified_at_epoch
            ;;
        verified)
            printf '%s\n' fence_proof_sha256 fence_provider_sha256 fence_verified_at_epoch \
                runtime_provider_sha256 runtime_queue_sha256 runtime_terminal_sha256 \
                runtime_verified_at_epoch route_proof_sha256 route_verified_at_epoch \
                verified_at_epoch
            ;;
        *) fail 'runtime-recovery status has no transition-key schema' ;;
    esac
}

decode_runtime_recovery_value()
{
    runtime_recovery_value "$1" "$2" | base64 --decode \
        || fail "runtime-recovery base64 value is malformed: $2"
}

runtime_recovery_kind()
{
    local kind
    kind=$(runtime_recovery_value "$1" recovery_kind)
    [[ $kind == daemon || $kind == candidate ]] \
        || fail 'runtime-recovery kind is malformed'
    printf '%s\n' "$kind"
}

assert_runtime_recovery_kind()
{
    local file=$1 expected=$2
    [[ $(runtime_recovery_kind "$file") == "$expected" ]] \
        || fail "unfinished runtime-recovery generation is not $expected recovery"
}

runtime_recovery_create_candidate()
{
    local destination=$1 basename=${1##*/}
    [[ $destination == "$operation_directory"/runtime-recovery-g??????.state \
        && $basename =~ ^runtime-recovery-g[0-9]{6}\.state$ ]] \
        || fail 'runtime-recovery generation destination is not canonical'
    printf '%s/.%s.create\n' "$operation_directory" "$basename"
}

reconcile_unpublished_runtime_recovery_candidate()
{
    local destination=$1 candidate
    candidate=$(runtime_recovery_create_candidate "$destination")
    if [[ ! -e $candidate && ! -L $candidate ]]; then
        return
    fi
    [[ ! -e $destination && ! -L $destination ]] \
        || fail 'runtime-recovery generation candidate exists beside a published destination'
    [[ -f $candidate && ! -L $candidate \
        && $(stat -c '%u:%g:%a' "$candidate") \
            == "$immutable_uid:$immutable_gid:600" \
        && $(stat -c '%h' "$candidate") -eq 1 ]] \
        || fail 'unpublished runtime-recovery generation candidate is unsafe'
    rm -f -- "$candidate" \
        || fail 'unpublished runtime-recovery generation candidate could not be removed'
    sync "$operation_directory"
    [[ ! -e $candidate && ! -L $candidate \
        && ! -e $destination && ! -L $destination ]] \
        || fail 'unpublished runtime-recovery generation candidate cleanup was incomplete'
}

reconcile_runtime_recovery_create_link()
{
    local destination=$1 candidate destination_metadata candidate_metadata link_count
    candidate=$(runtime_recovery_create_candidate "$destination")
    link_count=$(stat -c '%h' "$destination")
    case "$link_count" in
        1)
            [[ ! -e $candidate && ! -L $candidate ]] \
                || fail 'runtime-recovery generation has an unexpected detached transient sibling'
            return
            ;;
        2)
            [[ -f $candidate && ! -L $candidate ]] \
                || fail 'runtime-recovery generation has an unknown second hard link'
            destination_metadata=$(stat -Lc '%d:%i:%u:%g:%a:%s' "$destination")
            candidate_metadata=$(stat -Lc '%d:%i:%u:%g:%a:%s' "$candidate")
            [[ $candidate_metadata == "$destination_metadata" \
                && $(stat -c '%h' "$candidate") -eq 2 ]] \
                || fail 'runtime-recovery generation transient sibling identity is unsafe'
            rm -f -- "$candidate" \
                || fail 'runtime-recovery generation transient sibling could not be removed'
            sync "$operation_directory"
            [[ $(stat -Lc '%d:%i:%u:%g:%a:%s' "$destination") == "$destination_metadata" \
                && $(stat -c '%h' "$destination") -eq 1 ]] \
                || fail 'runtime-recovery generation transient sibling reconciliation was incomplete'
            ;;
        *)
            fail 'runtime-recovery generation has multiple or external hard links'
            ;;
    esac
}

load_runtime_recovery_lineage()
{
    local file basename generation parent_generation expected_generation=1
    local previous_sha256=none previous_file=none status kind key decoded role
    local previous_provider_expectation=$provider_capture_expectation
    local -a recovery_files
    shopt -s nullglob
    recovery_files=("$operation_directory"/runtime-recovery-g??????.state)
    shopt -u nullglob
    latest_runtime_recovery_file=none
    latest_runtime_recovery_generation=0
    latest_runtime_recovery_status=none
    latest_runtime_recovery_kind=none
    for file in "${recovery_files[@]}"; do
        assert_root_path "$file" 600 runtime-recovery-generation
        reconcile_runtime_recovery_create_link "$file"
        [[ $(stat -c '%h' "$file") -eq 1 ]] \
            || fail 'runtime-recovery generation must have exactly one hard link'
        validate_runtime_recovery_document "$file"
        kind=$(runtime_recovery_kind "$file")
        basename=${file##*/}
        [[ $basename =~ ^runtime-recovery-g([0-9]{6})\.state$ ]] \
            || fail 'runtime-recovery generation filename is malformed'
        generation=$((10#${BASH_REMATCH[1]}))
        [[ $(runtime_recovery_value "$file" generation) =~ ^[1-9][0-9]*$ \
            && $(runtime_recovery_value "$file" parent_generation) =~ ^[0-9]+$ ]] \
            || fail 'runtime-recovery generation counters are malformed'
        parent_generation=$(runtime_recovery_value "$file" parent_generation)
        [[ $generation -eq $expected_generation \
            && $(runtime_recovery_value "$file" version) == "$RUNTIME_RECOVERY_VERSION" \
            && $(runtime_recovery_value "$file" operation_id) == "$operation_id" \
            && $(runtime_recovery_value "$file" generation) == "$generation" \
            && $parent_generation -eq $((generation - 1)) \
            && $(runtime_recovery_value "$file" prior_generation_sha256) == "$previous_sha256" ]] \
            || fail 'runtime-recovery generation or hash-chain identity is malformed'
        [[ $(runtime_recovery_value "$file" direction) =~ ^(forward|reverse)$ \
            && $(runtime_recovery_value "$file" color) =~ ^(green|blue)$ \
            && $(runtime_recovery_value "$file" parent_phase) =~ ^[a-z0-9-]+$ \
            && $(runtime_recovery_value "$file" prior_provider_expectation) \
                == "$previous_provider_expectation" \
            && $(runtime_recovery_value "$file" provider_expectation) =~ ^(incumbent|absent)$ ]] \
            || fail 'runtime-recovery route context is malformed'
        [[ $(runtime_recovery_value "$file" old_docker_daemon_id) \
                == "$(runtime_recovery_value "$file" new_docker_daemon_id)" ]] \
            || fail 'Docker daemon identity changed during runtime recovery'
        [[ $(runtime_recovery_value "$file" retired_member_count) == "$pool_retired_member_count" ]] \
            || fail 'runtime-recovery retired member count differs from the immutable pool plan'
        validate_sha256 runtime-recovery-pool-member-set \
            "$(runtime_recovery_value "$file" pool_member_set_sha256)"
        [[ $(runtime_recovery_value "$file" pool_member_set_sha256) \
                == "$(state_value pool_runtime_member_set_sha256)" ]] \
            || fail 'runtime-recovery pool member set differs from the pinned runtime pool'
        for key in old_boot_id old_dockerd_pid old_dockerd_invocation_id \
            old_docker_daemon_id old_docker_socket new_boot_id new_dockerd_pid \
            new_dockerd_invocation_id new_docker_daemon_id new_docker_socket; do
            [[ -n $(runtime_recovery_value "$file" "$key") ]] \
                || fail "runtime-recovery identity is empty: $key"
        done
        for key in old_boot_id new_boot_id; do
            [[ $(runtime_recovery_value "$file" "$key") =~ ^[a-f0-9-]{36}$ ]] \
                || fail "runtime-recovery boot identity is malformed: $key"
        done
        for key in old_dockerd_pid new_dockerd_pid; do
            [[ $(runtime_recovery_value "$file" "$key") =~ ^[1-9][0-9]*$ ]] \
                || fail "runtime-recovery dockerd PID is malformed: $key"
        done
        for key in old_dockerd_invocation_id new_dockerd_invocation_id; do
            [[ $(runtime_recovery_value "$file" "$key") =~ ^[a-f0-9]{32}$ ]] \
                || fail "runtime-recovery dockerd invocation is malformed: $key"
        done
        for key in old_docker_daemon_id new_docker_daemon_id; do
            [[ $(runtime_recovery_value "$file" "$key") =~ ^[A-Za-z0-9:_-]+$ ]] \
                || fail "runtime-recovery Docker daemon identity is malformed: $key"
        done
        for key in old_docker_socket new_docker_socket; do
            [[ $(runtime_recovery_value "$file" "$key") =~ ^[0-9]+:[0-9]+$ ]] \
                || fail "runtime-recovery Docker socket identity is malformed: $key"
        done
        for role in $RUNTIME_RECOVERY_ROLES; do
            [[ $(runtime_recovery_value "$file" "${role}_name") \
                    == "$(runtime_recovery_role_name "$role")" \
                && $(runtime_recovery_value "$file" "${role}_id") \
                    == "$(runtime_recovery_role_id "$role")" ]] \
                || fail "runtime-recovery $role role identity differs from the immutable pool plan"
            for key in "old_${role}_snapshot_sha256" "new_${role}_snapshot_sha256" \
                "old_${role}_static_sha256" "new_${role}_static_sha256"; do
                validate_sha256 "$key" "$(runtime_recovery_value "$file" "$key")"
            done
            for key in old new; do
                decoded=$(decode_runtime_recovery_value "$file" "${key}_${role}_snapshot_b64")
                [[ $(sha256_text "$decoded") \
                        == "$(runtime_recovery_value "$file" \
                            "${key}_${role}_snapshot_sha256")" ]] \
                    || fail "runtime-recovery $role $key snapshot hash does not match its bytes"
            done
        done
        for role in web_a web_b; do
            for key in "old_${role}_network_sha256" "new_${role}_network_sha256"; do
                validate_sha256 "$key" "$(runtime_recovery_value "$file" "$key")"
            done
            for key in old new; do
                decoded=$(decode_runtime_recovery_value "$file" "${key}_${role}_network_b64")
                [[ $(sha256_text "$decoded") \
                        == "$(runtime_recovery_value "$file" \
                            "${key}_${role}_network_sha256")" ]] \
                    || fail "runtime-recovery $role $key network hash does not match its bytes"
            done
        done
        for key in old_network_inventory_sha256 new_network_inventory_sha256; do
            validate_sha256 "$key" "$(runtime_recovery_value "$file" "$key")"
        done
        for key in old new; do
            decoded=$(decode_runtime_recovery_value "$file" "${key}_network_inventory_b64")
            [[ $(sha256_text "$decoded") \
                    == "$(runtime_recovery_value "$file" \
                        "${key}_network_inventory_sha256")" ]] \
                || fail "runtime-recovery $key network inventory hash does not match its bytes"
        done
        for key in release_provider_sha256 release_queue_sha256 release_terminal_sha256 \
            release_files_sha256; do
            [[ $(runtime_recovery_value "$file" "$key") =~ ^(none|[a-f0-9]{64})$ ]] \
                || fail "runtime-recovery release proof identity is malformed: $key"
        done
        if [[ $previous_file != none ]]; then
            for key in boot_id dockerd_pid dockerd_invocation_id docker_daemon_id docker_socket; do
                [[ $(runtime_recovery_value "$file" "old_$key") \
                        == "$(runtime_recovery_value "$previous_file" "new_$key")" ]] \
                    || fail "runtime-recovery generation does not continue prior $key"
            done
            for role in $RUNTIME_RECOVERY_ROLES; do
                [[ $(runtime_recovery_value "$file" "old_${role}_snapshot_sha256") \
                        == "$(runtime_recovery_value "$previous_file" \
                            "new_${role}_snapshot_sha256")" \
                    && $(runtime_recovery_value "$file" "old_${role}_static_sha256") \
                        == "$(runtime_recovery_value "$previous_file" \
                            "new_${role}_static_sha256")" ]] \
                    || fail "runtime-recovery generation does not continue prior $role contract"
            done
            for role in web_a web_b; do
                [[ $(runtime_recovery_value "$file" "old_${role}_network_sha256") \
                        == "$(runtime_recovery_value "$previous_file" \
                            "new_${role}_network_sha256")" ]] \
                    || fail "runtime-recovery generation does not continue prior $role network"
            done
            [[ $(runtime_recovery_value "$file" old_network_inventory_sha256) \
                    == "$(runtime_recovery_value "$previous_file" \
                        new_network_inventory_sha256)" ]] \
                || fail 'runtime-recovery generation does not continue prior network inventory'
        fi
        assert_runtime_recovery_document_invariants "$file"
        status=$(runtime_recovery_status "$file")
        [[ $(runtime_recovery_value "$file" intent_at_epoch) =~ ^[1-9][0-9]*$ ]] \
            || fail 'runtime-recovery intent timestamp is malformed'
        case "$status" in
            fence-verified|runtime-verified|route-verified|verified)
                validate_sha256 fence-proof "$(runtime_recovery_value "$file" fence_proof_sha256)"
                validate_sha256 fence-provider "$(runtime_recovery_value "$file" fence_provider_sha256)"
                [[ $(runtime_recovery_value "$file" fence_verified_at_epoch) =~ ^[1-9][0-9]*$ ]] \
                    || fail 'runtime-recovery fence timestamp is malformed'
                ;;
        esac
        case "$status" in
            runtime-verified|route-verified|verified)
                validate_sha256 runtime-provider "$(runtime_recovery_value "$file" runtime_provider_sha256)"
                [[ $(runtime_recovery_value "$file" runtime_queue_sha256) =~ ^(none|[a-f0-9]{64})$ \
                    && $(runtime_recovery_value "$file" runtime_terminal_sha256) \
                        =~ ^(none|[a-f0-9]{64})$ ]] \
                    || fail 'runtime-recovery phase proof hashes are malformed'
                [[ $(runtime_recovery_value "$file" runtime_verified_at_epoch) =~ ^[1-9][0-9]*$ ]] \
                    || fail 'runtime-recovery runtime timestamp is malformed'
                ;;
        esac
        case "$status" in
            route-verified|verified)
                validate_sha256 route-proof "$(runtime_recovery_value "$file" route_proof_sha256)"
                [[ $(runtime_recovery_value "$file" route_verified_at_epoch) =~ ^[1-9][0-9]*$ ]] \
                    || fail 'runtime-recovery route timestamp is malformed'
                ;;
        esac
        if [[ $status == verified ]]; then
            [[ $(runtime_recovery_value "$file" verified_at_epoch) =~ ^[1-9][0-9]*$ ]] \
                || fail 'runtime-recovery terminal timestamp is malformed'
        elif [[ $file != "${recovery_files[-1]}" ]]; then
            fail 'only the latest runtime-recovery generation may be incomplete'
        fi
        latest_runtime_recovery_file=$file
        latest_runtime_recovery_generation=$generation
        latest_runtime_recovery_status=$status
        latest_runtime_recovery_kind=$kind
        previous_provider_expectation=$(runtime_recovery_value "$file" provider_expectation)
        previous_sha256=$(sha256_file "$file")
        previous_file=$file
        expected_generation=$((expected_generation + 1))
    done
}

expected_runtime_value()
{
    local key=$1
    case "$key" in
        boot_id|dockerd_pid|dockerd_invocation_id|docker_daemon_id|docker_socket) ;;
        *)
            state_value "$key"
            return
            ;;
    esac
    load_runtime_recovery_lineage
    case "$latest_runtime_recovery_status" in
        none) state_value "$key" ;;
        runtime-verified|route-verified|verified)
            runtime_recovery_value "$latest_runtime_recovery_file" "new_$key"
            ;;
        *) fail 'runtime recovery has not reached its durable runtime-verification boundary' ;;
    esac
}

expected_container_snapshot()
{
    local role
    role=$(runtime_recovery_canonical_role "$1")
    load_runtime_recovery_lineage
    case "$latest_runtime_recovery_status" in
        none) printf '%s\n' none ;;
        runtime-verified|route-verified|verified)
            runtime_recovery_value "$latest_runtime_recovery_file" "new_${role}_snapshot_b64" \
                | base64 --decode
            ;;
        *) fail 'runtime recovery has not reached its durable runtime-verification boundary' ;;
    esac
}

expected_container_static_sha256()
{
    local role fallback=$2
    role=$(runtime_recovery_canonical_role "$1")
    load_runtime_recovery_lineage
    case "$latest_runtime_recovery_status" in
        none) printf '%s\n' "$fallback" ;;
        runtime-verified|route-verified|verified)
            runtime_recovery_value "$latest_runtime_recovery_file" "new_${role}_static_sha256"
            ;;
        *) fail 'runtime recovery has not reached its durable runtime-verification boundary' ;;
    esac
}

expected_candidate_network_json()
{
    load_runtime_recovery_lineage
    case "$latest_runtime_recovery_status" in
        none) printf '%s\n' none ;;
        runtime-verified|route-verified|verified)
            runtime_recovery_value "$latest_runtime_recovery_file" new_web_a_network_b64 \
                | base64 --decode
            ;;
        *) fail 'runtime recovery has not reached its durable runtime-verification boundary' ;;
    esac
}

runtime_recovery_phase_class()
{
    local direction=$1 color=$2 parent_phase=$3 provider_expectation=$4 class
    case "$direction:$color:$parent_phase" in
        forward:green:green-https-routed|forward:green:green-routed|\
        forward:green:blue-scheduler-stopping|forward:green:blue-scheduler-stopped|\
        forward:green:blue-horizon-pausing|forward:green:blue-horizon-paused|\
        forward:green:blue-drain-inventory-recording|forward:green:blue-drain-inventory-recorded|\
        forward:green:blue-background-drain-first|forward:green:blue-background-zero-first|\
        forward:green:blue-background-zero-proven|forward:green:blue-horizon-stopping|\
        forward:green:blue-horizon-stopped|forward:green:blue-nightwatch-stopping|\
        forward:green:blue-nightwatch-stopped|forward:green:blue-http-drain-first|\
        forward:green:blue-http-zero-first|forward:green:blue-drained|\
        forward:green:green-final-ingress-verifying|\
        forward:green:green-final-ingress-acknowledged|forward:green:blue-revoking)
            class=active-incumbent
            ;;
        forward:green:blue-revoked|forward:green:proxy-mutation-freeze-activating|\
        forward:green:proxy-mutation-freeze-active)
            class=active-absent
            ;;
        forward:green:fence-release-intent|forward:green:fence-released|\
        forward:green:green-writer-promoting|forward:green:green-writer-promoted-freeze-active|\
        forward:green:green-promoted-finalizing|forward:green:green-writer-promoted|\
        forward:green:reverse-fence-artifacts-preparing|\
        forward:green:reverse-fence-prepare-intent|forward:green:reverse-fence-prepared|\
        forward:green:reverse-fence-captured|forward:green:reverse-fence-armed|\
        forward:green:reverse-fence-active|forward:green:failback-blue-starting|\
        forward:green:failback-blue-started-unproven|forward:green:failback-blue-started|\
        forward:green:failback-blue-web-activating|\
        forward:green:failback-blue-web-activated|forward:green:failback-blue-https-routing)
            class=release
            ;;
        reverse:blue:failback-blue-https-routed|reverse:blue:blue-failback-routed|\
        reverse:blue:failback-green-scheduler-stopping|\
        reverse:blue:failback-green-scheduler-stopped|reverse:blue:failback-green-horizon-pausing|\
        reverse:blue:failback-green-horizon-paused|\
        reverse:blue:failback-green-drain-inventory-recording|\
        reverse:blue:failback-green-drain-inventory-recorded|\
        reverse:blue:failback-green-background-drain-first|\
        reverse:blue:failback-green-background-zero-first|\
        reverse:blue:failback-green-background-zero-proven|\
        reverse:blue:failback-green-horizon-stopping|reverse:blue:failback-green-horizon-stopped|\
        reverse:blue:failback-green-nightwatch-stopping|\
        reverse:blue:failback-green-nightwatch-stopped|\
        reverse:blue:failback-green-http-drain-first|reverse:blue:failback-green-http-zero-first|\
        reverse:blue:failback-green-drained|\
        reverse:blue:failback-blue-final-ingress-verifying|\
        reverse:blue:failback-blue-final-ingress-acknowledged|\
        reverse:blue:failback-green-revoking)
            class=active-incumbent
            ;;
        reverse:blue:failback-green-revoked|\
        reverse:blue:reverse-proxy-mutation-freeze-activating|\
        reverse:blue:reverse-proxy-mutation-freeze-active)
            class=active-absent
            ;;
        reverse:blue:reverse-fence-release-intent|reverse:blue:reverse-fence-released|\
        reverse:blue:blue-writer-promoting|reverse:blue:blue-writer-promoted-freeze-active|\
        reverse:blue:blue-promoted-finalizing|reverse:blue:blue-writer-promoted)
            class=release
            ;;
        *)
            fail 'daemon recovery is forbidden before first ingress or outside a routed continuation phase'
            ;;
    esac
    case "$class:$provider_expectation" in
        active-incumbent:incumbent|active-absent:absent|release:absent) ;;
        *) fail 'daemon recovery provider expectation contradicts its persisted direction and phase' ;;
    esac
    printf '%s\n' "$class"
}

runtime_recovery_fence_mode()
{
    local phase_class=$1 fence_phase
    fence_phase=$(state_value phase)
    case "$fence_phase:$phase_class" in
        active:active-incumbent|active:active-absent|active:release)
            printf '%s\n' active
            ;;
        released:release)
            printf '%s\n' released
            ;;
        release-in-progress:release)
            fail 'interrupted runtime-fence release must converge before daemon recovery'
            ;;
        *)
            fail "runtime-fence phase is ineligible for routed daemon recovery: $fence_phase"
            ;;
    esac
}

runtime_recovery_canonical_role()
{
    case "$1" in
        proxy) printf '%s\n' proxy ;;
        incumbent|retired_a) printf '%s\n' retired_a ;;
        retired_b) printf '%s\n' retired_b ;;
        candidate|web_a) printf '%s\n' web_a ;;
        web_b) printf '%s\n' web_b ;;
        *) fail 'unknown runtime-recovery role' ;;
    esac
}

runtime_recovery_absent_snapshot()
{
    printf '%s\n' '{"presence":"absent"}'
}

runtime_recovery_role_name()
{
    case "$(runtime_recovery_canonical_role "$1")" in
        proxy) printf '%s\n' "$proxy_container" ;;
        retired_a) printf '%s\n' "$legacy_container" ;;
        retired_b)
            if [[ $pool_retired_member_count == 2 ]]; then
                printf '%s\n' "$pool_retired_member_b_name"
            else
                printf '%s\n' absent
            fi
            ;;
        web_a) printf '%s\n' "$candidate_container" ;;
        web_b) printf '%s\n' "$pool_web_b_container" ;;
    esac
}

runtime_recovery_role_id()
{
    case "$(runtime_recovery_canonical_role "$1")" in
        proxy) state_value proxy_id ;;
        retired_a) printf '%s\n' "$pool_retired_member_a_id" ;;
        retired_b)
            if [[ $pool_retired_member_count == 2 ]]; then
                printf '%s\n' "$pool_retired_member_b_id"
            else
                printf '%s\n' absent
            fi
            ;;
        web_a) state_value candidate_denial_id ;;
        web_b) state_value web_b_denial_id ;;
    esac
}

runtime_recovery_role_is_present()
{
    local role expectation=$2
    role=$(runtime_recovery_canonical_role "$1")
    case "$role" in
        proxy|web_a|web_b) return 0 ;;
        retired_a) [[ $expectation == incumbent ]] ;;
        retired_b) [[ $expectation == incumbent && $pool_retired_member_count == 2 ]] ;;
    esac
}

runtime_recovery_base_snapshot()
{
    local role expectation=$2
    role=$(runtime_recovery_canonical_role "$1")
    if ! runtime_recovery_role_is_present "$role" "$expectation"; then
        runtime_recovery_absent_snapshot
        return
    fi
    case "$role" in
        proxy) state_value proxy_snapshot_b64 | base64 --decode ;;
        retired_a) state_value legacy_snapshot_b64 | base64 --decode ;;
        retired_b) state_value retired_member_b_snapshot_b64 | base64 --decode ;;
        web_a) state_value candidate_denial_snapshot_b64 | base64 --decode ;;
        web_b) state_value web_b_denial_snapshot_b64 | base64 --decode ;;
    esac
}

runtime_recovery_base_static_sha256()
{
    local role expectation=$2
    role=$(runtime_recovery_canonical_role "$1")
    if ! runtime_recovery_role_is_present "$role" "$expectation"; then
        sha256_text '{"presence":"absent"}'
        return
    fi
    case "$role" in
        proxy) state_value proxy_runtime_sha256 ;;
        retired_a) state_value legacy_runtime_sha256 ;;
        retired_b) state_value retired_member_b_runtime_sha256 ;;
        web_a) state_value candidate_runtime_sha256 ;;
        web_b) state_value web_b_runtime_sha256 ;;
    esac
}

runtime_recovery_previous_snapshot()
{
    local role
    role=$(runtime_recovery_canonical_role "$1")
    if [[ $latest_runtime_recovery_status == verified ]]; then
        decode_runtime_recovery_value "$latest_runtime_recovery_file" "new_${role}_snapshot_b64"
    else
        runtime_recovery_base_snapshot "$role" "$provider_capture_expectation"
    fi
}

runtime_recovery_previous_static_sha256()
{
    local role
    role=$(runtime_recovery_canonical_role "$1")
    if [[ $latest_runtime_recovery_status == verified ]]; then
        runtime_recovery_value "$latest_runtime_recovery_file" "new_${role}_static_sha256"
    else
        runtime_recovery_base_static_sha256 "$role" "$provider_capture_expectation"
    fi
}

runtime_recovery_prior_provider_expectation()
{
    if [[ $latest_runtime_recovery_status == verified ]]; then
        runtime_recovery_value "$latest_runtime_recovery_file" provider_expectation
    else
        printf '%s\n' "$provider_capture_expectation"
    fi
}

runtime_recovery_provider_transition()
{
    local prior current=$1
    prior=$(runtime_recovery_prior_provider_expectation)
    case "$prior:$current" in
        incumbent:incumbent|absent:absent) printf '%s\n' stable ;;
        incumbent:absent) printf '%s\n' retired ;;
        absent:incumbent)
            fail 'runtime-recovery provider expectation cannot return to incumbent after absence'
            ;;
        *) fail 'runtime-recovery provider expectation transition is malformed' ;;
    esac
}

semantic_network_inventory()
{
    awk -F '\t' 'BEGIN { OFS="\t" } NF == 6 { print $1, $2, $3, $5, $6 }' "$1" \
        | LC_ALL=C sort -u
}

runtime_recovery_previous_network_inventory()
{
    if [[ $latest_runtime_recovery_status == verified ]]; then
        decode_runtime_recovery_value "$latest_runtime_recovery_file" new_network_inventory_b64
    else
        semantic_network_inventory "$network_inventory_file"
    fi
}

runtime_recovery_previous_web_network()
{
    local role
    role=$(runtime_recovery_canonical_role "$1")
    [[ $role == web_a || $role == web_b ]] \
        || fail 'runtime-recovery network role is not a web pool member'
    if [[ $latest_runtime_recovery_status == verified ]]; then
        decode_runtime_recovery_value "$latest_runtime_recovery_file" "new_${role}_network_b64"
    elif [[ $role == web_a ]]; then
        state_value candidate_denial_networks_b64 | base64 --decode
    else
        state_value web_b_denial_networks_b64 | base64 --decode
    fi
}

runtime_recovery_previous_candidate_network()
{
    runtime_recovery_previous_web_network web_a
}

runtime_recovery_network_ids()
{
    local expectation=$1 role container inspect_json
    for role in $RUNTIME_RECOVERY_ROLES; do
        runtime_recovery_role_is_present "$role" "$expectation" || continue
        container=$(runtime_recovery_role_name "$role")
        inspect_json=$(require_docker_container_json "$container")
        jq -r '.[0].NetworkSettings.Networks | to_entries[].value.NetworkID' <<< "$inspect_json"
    done
    [[ -z $additional_network_ids ]] || tr ',' '\n' <<< "$additional_network_ids"
    tr ',' '\n' <<< "$candidate_network_ids"
    tr ',' '\n' <<< "$pool_web_b_network_ids"
}

assert_runtime_recovery_snapshot_transition()
{
    local role old_snapshot new_snapshot required_policy=${4:-} provider_transition=${5:-stable}
    role=$(runtime_recovery_canonical_role "$1")
    old_snapshot=$2
    new_snapshot=$3
    if [[ $provider_transition == retired && ($role == retired_a || $role == retired_b) ]]; then
        [[ $new_snapshot == '{"presence":"absent"}' ]] \
            || fail "$role remained present across the incumbent-to-absent provider transition"
        return
    fi
    if [[ $old_snapshot == '{"presence":"absent"}' ]]; then
        [[ $new_snapshot == "$old_snapshot" ]] \
            || fail "$role unexpectedly appeared during daemon recovery"
        return
    fi
    jq --exit-status -n --argjson old "$old_snapshot" --argjson new "$new_snapshot" '
        ($old | del(.started_at, .restart_count)) == ($new | del(.started_at, .restart_count))
        and $new.running == true
        and $new.status == "running"
        and ($new.health == "healthy" or $new.health == "not-configured")
        and ($new.started_at | test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[^[:space:]]+Z$"))
        and ($new.restart_count | type == "number" and . >= 0)
    ' >/dev/null || fail "$role ID, image, static snapshot, or runtime state changed across daemon recovery"
    if [[ -n $required_policy ]]; then
        [[ $(jq -r .restart_policy <<< "$new_snapshot") == "$required_policy" ]] \
            || fail "$role restart policy is not $required_policy during daemon recovery"
    fi
}

runtime_recovery_current_snapshot()
{
    local role expectation=$2 container snapshot
    role=$(runtime_recovery_canonical_role "$1")
    if ! runtime_recovery_role_is_present "$role" "$expectation"; then
        runtime_recovery_absent_snapshot
        return
    fi
    container=$(runtime_recovery_role_name "$role")
    snapshot=$(container_json "$container")
    [[ $(jq -r .id <<< "$snapshot") == "$(runtime_recovery_role_id "$role")" ]] \
        || fail "$role differs from its exact immutable pool identity"
    printf '%s\n' "$snapshot"
}

runtime_recovery_current_static_sha256()
{
    local role expectation=$2
    role=$(runtime_recovery_canonical_role "$1")
    if runtime_recovery_role_is_present "$role" "$expectation"; then
        container_runtime_sha256 "$(runtime_recovery_role_name "$role")"
    else
        sha256_text '{"presence":"absent"}'
    fi
}

capture_runtime_recovery_observation()
{
    local provider_expectation=$1 inventory=$operation_directory/.runtime-recovery-networks.$$
    local requested_network_ids old_snapshot old_static role required_policy web_network
    local provider_transition absent_static_sha256
    [[ $provider_expectation == incumbent || $provider_expectation == absent ]] \
        || fail 'runtime-recovery provider expectation is invalid'
    if [[ $provider_expectation == absent ]]; then
        retired_pool_is_absent \
            || fail 'recorded retired pool exists in an absence-required recovery phase'
    fi
    provider_transition=$(runtime_recovery_provider_transition "$provider_expectation")
    absent_static_sha256=$(sha256_text '{"presence":"absent"}')
    recovery_new_boot_id=$(runtime_value boot_id)
    recovery_new_dockerd_pid=$(runtime_value dockerd_pid)
    recovery_new_dockerd_invocation_id=$(runtime_value dockerd_invocation_id)
    recovery_new_docker_daemon_id=$(runtime_value docker_daemon_id)
    recovery_new_docker_socket=$(runtime_value docker_socket)
    for role in $RUNTIME_RECOVERY_ROLES; do
        RECOVERY_NEW_SNAPSHOT[$role]=$(runtime_recovery_current_snapshot "$role" \
            "$provider_expectation")
        required_policy=
        [[ $role != web_a && $role != web_b ]] || required_policy=always
        old_snapshot=$(runtime_recovery_previous_snapshot "$role" "$provider_expectation")
        assert_runtime_recovery_snapshot_transition "$role" "$old_snapshot" \
            "${RECOVERY_NEW_SNAPSHOT[$role]}" "$required_policy" "$provider_transition"
        RECOVERY_NEW_STATIC_SHA256[$role]=$(runtime_recovery_current_static_sha256 "$role" \
            "$provider_expectation")
        old_static=$(runtime_recovery_previous_static_sha256 "$role" "$provider_expectation")
        if [[ $provider_transition == retired \
            && ($role == retired_a || $role == retired_b) ]]; then
            [[ ${RECOVERY_NEW_STATIC_SHA256[$role]} == "$absent_static_sha256" ]] \
                || fail "$role absence static contract is malformed after retirement"
        else
            [[ ${RECOVERY_NEW_STATIC_SHA256[$role]} == "$old_static" ]] \
                || fail "$role static Config/HostConfig contract changed across daemon recovery"
        fi
    done
    for role in web_a web_b; do
        web_network=$(candidate_network_inventory_json "$(runtime_recovery_role_name "$role")")
        if [[ $role == web_a ]]; then
            assert_candidate_network_plan "$web_network"
        else
            assert_candidate_network_plan "$web_network" "$pool_web_b_network_ids"
        fi
        RECOVERY_NEW_WEB_NETWORK[$role]=$web_network
        [[ $web_network == "$(runtime_recovery_previous_web_network "$role")" ]] \
            || fail "$role complete network identity changed across daemon recovery"
    done
    requested_network_ids=$(runtime_recovery_network_ids "$provider_expectation" | LC_ALL=C sort -u)
    capture_network_inventory "$inventory" "$requested_network_ids"
    recovery_new_network_inventory=$(semantic_network_inventory "$inventory")
    rm -f -- "$inventory"
    [[ $recovery_new_network_inventory == "$(runtime_recovery_previous_network_inventory)" ]] \
        || fail 'Docker network semantics changed across daemon recovery'
}

runtime_recovery_atomic_create()
{
    local source=$1 destination=$2 source_metadata destination_metadata
    chmod 0600 "$source"
    test_crash after-runtime-recovery-generation-candidate-metadata
    sync "$source"
    test_crash after-runtime-recovery-generation-candidate-fsync
    [[ ! -e $destination && ! -L $destination ]] \
        || fail 'runtime-recovery generation destination already exists'
    source_metadata=$(stat -Lc '%d:%i:%u:%g:%a:%s' "$source")
    [[ $(stat -c '%h' "$source") -eq 1 ]] \
        || fail 'runtime-recovery generation candidate must have exactly one hard link'
    if ! ln "$source" "$destination" 2>/dev/null; then
        fail 'runtime-recovery generation no-replace publication failed'
    fi
    test_crash after-runtime-recovery-generation-link
    destination_metadata=$(stat -Lc '%d:%i:%u:%g:%a:%s' "$destination" 2>/dev/null || true)
    [[ -f $destination && ! -L $destination \
        && $destination_metadata == "$source_metadata" \
        && $(stat -c '%h' "$source") -eq 2 \
        && $(stat -c '%h' "$destination") -eq 2 ]] \
        || fail 'runtime-recovery generation publication identity is unsafe'
    rm -f -- "$source" \
        || fail 'runtime-recovery generation candidate cleanup failed after publication'
    test_crash after-runtime-recovery-generation-candidate-unlink
    sync "$operation_directory"
    assert_root_path "$destination" 600 runtime-recovery-generation
    [[ $(stat -Lc '%d:%i:%u:%g:%a:%s' "$destination") == "$source_metadata" \
        && $(stat -c '%h' "$destination") -eq 1 ]] \
        || fail 'runtime-recovery generation publication did not freeze one inode'
}

append_runtime_recovery_status()
{
    local file=$1 status=$2 candidate=$operation_directory/.runtime-recovery-transition.$$
    local original_identity candidate_metadata
    shift 2
    assert_root_path "$file" 600 runtime-recovery-generation
    [[ $(stat -c '%h' "$file") -eq 1 ]] \
        || fail 'runtime-recovery generation must have exactly one hard link'
    original_identity=$(stat -Lc '%d:%i' "$file")
    [[ ! -e $candidate && ! -L $candidate ]] \
        || fail 'runtime-recovery transition candidate already exists'
    cp -- "$file" "$candidate"
    while (($#)); do
        [[ $1 =~ ^[a-z0-9_]+=[^[:space:]]+$ ]] \
            || fail 'runtime-recovery transition record is malformed'
        printf '%s\n' "$1" >> "$candidate"
        shift
    done
    printf 'status=%s\n' "$status" >> "$candidate"
    chmod 0600 "$candidate"
    sync "$candidate"
    candidate_metadata=$(stat -Lc '%d:%i:%u:%g:%a:%s' "$candidate")
    [[ $(stat -c '%h' "$candidate") -eq 1 \
        && $(stat -Lc '%d:%i' "$file") == "$original_identity" \
        && $(stat -c '%h' "$file") -eq 1 ]] \
        || fail 'runtime-recovery transition target changed before publication'
    mv -f -- "$candidate" "$file"
    sync "$operation_directory"
    assert_root_path "$file" 600 runtime-recovery-generation
    [[ $(stat -Lc '%d:%i:%u:%g:%a:%s' "$file") == "$candidate_metadata" \
        && $(stat -c '%h' "$file") -eq 1 ]] \
        || fail 'runtime-recovery transition publication identity is unsafe'
}

runtime_recovery_old_tuple_value()
{
    local key=$1
    if [[ $latest_runtime_recovery_status == verified ]]; then
        runtime_recovery_value "$latest_runtime_recovery_file" "new_$key"
    else
        state_value "$key"
    fi
}

runtime_recovery_tuple_changed()
{
    [[ $recovery_new_boot_id != "$(runtime_recovery_old_tuple_value boot_id)" \
        || $recovery_new_dockerd_pid != "$(runtime_recovery_old_tuple_value dockerd_pid)" \
        || $recovery_new_dockerd_invocation_id \
            != "$(runtime_recovery_old_tuple_value dockerd_invocation_id)" \
        || $recovery_new_docker_socket != "$(runtime_recovery_old_tuple_value docker_socket)" ]]
}

assert_candidate_runtime_restart_transition()
{
    local old_snapshot=$1 new_snapshot=$2
    jq --exit-status -n --argjson old "$old_snapshot" --argjson new "$new_snapshot" '
        ($old | del(.started_at, .restart_count)) == ($new | del(.started_at, .restart_count))
        and $new.restart_policy == "always"
        and $new.running == true
        and $new.status == "running"
        and $new.health == "healthy"
        and ($new.started_at | test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[^[:space:]]+Z$"))
        and $new.started_at != $old.started_at
        and ($old.restart_count | type == "number" and . >= 0)
        and ($new.restart_count | type == "number" and . >= $old.restart_count)
    ' >/dev/null || fail 'candidate restart is not the sole allowed same-daemon runtime change'
}

assert_runtime_recovery_document_invariants()
{
    local file=$1 kind role key old_snapshot new_snapshot
    local old_network new_network old_inventory new_inventory tuple_changed=false
    local prior_provider_expectation provider_expectation provider_transition
    local absent_static_sha256
    kind=$(runtime_recovery_kind "$file")
    prior_provider_expectation=$(runtime_recovery_value "$file" prior_provider_expectation)
    provider_expectation=$(runtime_recovery_value "$file" provider_expectation)
    case "$prior_provider_expectation:$provider_expectation" in
        incumbent:incumbent|absent:absent) provider_transition=stable ;;
        incumbent:absent) provider_transition=retired ;;
        *) fail 'runtime-recovery document contains an invalid provider expectation transition' ;;
    esac
    absent_static_sha256=$(sha256_text '{"presence":"absent"}')
    for key in boot_id dockerd_pid dockerd_invocation_id docker_socket; do
        [[ $(runtime_recovery_value "$file" "old_$key") \
                == "$(runtime_recovery_value "$file" "new_$key")" ]] \
            || tuple_changed=true
    done
    case "$kind:$tuple_changed" in
        daemon:true|candidate:false) ;;
        daemon:false) fail 'daemon recovery document does not contain a daemon lifecycle transition' ;;
        candidate:true) fail 'same-daemon candidate recovery document changed the daemon lifecycle tuple' ;;
    esac
    for role in $RUNTIME_RECOVERY_ROLES; do
        old_snapshot=$(decode_runtime_recovery_value "$file" "old_${role}_snapshot_b64")
        new_snapshot=$(decode_runtime_recovery_value "$file" "new_${role}_snapshot_b64")
        if [[ $provider_transition == retired \
            && ($role == retired_a || $role == retired_b) ]]; then
            [[ $(runtime_recovery_value "$file" "new_${role}_static_sha256") \
                    == "$absent_static_sha256" ]] \
                || fail "runtime-recovery $role retirement static contract is malformed"
        else
            [[ $(runtime_recovery_value "$file" "old_${role}_static_sha256") \
                    == "$(runtime_recovery_value "$file" "new_${role}_static_sha256")" ]] \
                || fail "runtime-recovery $role static contract changed in its immutable document"
        fi
        if [[ $role == web_a || $role == web_b ]]; then
            assert_runtime_recovery_snapshot_transition "$role" "$old_snapshot" "$new_snapshot" \
                always "$provider_transition"
        else
            assert_runtime_recovery_snapshot_transition "$role" "$old_snapshot" "$new_snapshot" \
                '' "$provider_transition"
        fi
        if [[ $kind == candidate && $role != web_a ]]; then
            [[ $old_snapshot == "$new_snapshot" ]] \
                || fail "$role changed during same-daemon candidate recovery"
        fi
    done
    if [[ $kind == candidate ]]; then
        old_snapshot=$(decode_runtime_recovery_value "$file" old_web_a_snapshot_b64)
        new_snapshot=$(decode_runtime_recovery_value "$file" new_web_a_snapshot_b64)
        [[ $old_snapshot != "$new_snapshot" ]] \
            || fail 'same-daemon candidate recovery did not record a fresh web-a runtime'
        assert_candidate_runtime_restart_transition "$old_snapshot" "$new_snapshot"
    fi
    for role in web_a web_b; do
        old_network=$(decode_runtime_recovery_value "$file" "old_${role}_network_b64")
        new_network=$(decode_runtime_recovery_value "$file" "new_${role}_network_b64")
        [[ $old_network == "$new_network" ]] \
            || fail "$role network inventory changed in runtime recovery"
    done
    old_inventory=$(decode_runtime_recovery_value "$file" old_network_inventory_b64)
    new_inventory=$(decode_runtime_recovery_value "$file" new_network_inventory_b64)
    [[ $old_inventory == "$new_inventory" ]] \
        || fail 'full Docker network inventory changed in runtime recovery'
}

classify_routed_runtime_recovery()
{
    local provider_expectation=$1 old_daemon old_snapshot role old_web_a
    old_daemon=$(runtime_recovery_old_tuple_value docker_daemon_id)
    [[ $recovery_new_docker_daemon_id == "$old_daemon" ]] \
        || fail 'Docker daemon ID changed; routed recovery is forbidden'
    if runtime_recovery_tuple_changed; then
        printf '%s\n' daemon
        return
    fi

    for role in proxy retired_a retired_b web_b; do
        old_snapshot=$(runtime_recovery_previous_snapshot "$role" "$provider_expectation")
        [[ ${RECOVERY_NEW_SNAPSHOT[$role]} == "$old_snapshot" ]] \
            || fail "$role changed during same-daemon web-a recovery"
    done
    old_web_a=$(runtime_recovery_previous_snapshot web_a "$provider_expectation")
    if [[ ${RECOVERY_NEW_SNAPSHOT[web_a]} == "$old_web_a" ]]; then
        printf '%s\n' current
        return
    fi
    assert_candidate_runtime_restart_transition "$old_web_a" \
        "${RECOVERY_NEW_SNAPSHOT[web_a]}"
    printf '%s\n' candidate
}

write_runtime_recovery_intent()
{
    local direction=$1 color=$2 parent_phase=$3 provider_expectation=$4
    local recovery_kind=${5:-daemon}
    local generation parent_generation prior_sha256 destination candidate role old_snapshot old_static
    local old_web_network old_network_inventory
    [[ $recovery_kind == daemon || $recovery_kind == candidate ]] \
        || fail 'runtime-recovery generation kind is invalid'
    generation=$((latest_runtime_recovery_generation + 1))
    ((generation <= 999999)) || fail 'runtime-recovery generation inventory is exhausted'
    parent_generation=$latest_runtime_recovery_generation
    if [[ $latest_runtime_recovery_status == verified ]]; then
        prior_sha256=$(sha256_file "$latest_runtime_recovery_file")
    else
        prior_sha256=none
    fi
    printf -v destination '%s/runtime-recovery-g%06d.state' "$operation_directory" "$generation"
    candidate=$(runtime_recovery_create_candidate "$destination")
    reconcile_unpublished_runtime_recovery_candidate "$destination"
    if ! (umask 077; set -C; : > "$candidate") 2>/dev/null; then
        fail 'could not reserve runtime-recovery generation candidate'
    fi
    test_crash after-runtime-recovery-generation-candidate-reserve
    {
        printf 'version=%s\noperation_id=%s\ngeneration=%s\nparent_generation=%s\n' \
            "$RUNTIME_RECOVERY_VERSION" "$operation_id" "$generation" "$parent_generation"
        printf 'prior_generation_sha256=%s\ndirection=%s\ncolor=%s\nparent_phase=%s\n' \
            "$prior_sha256" "$direction" "$color" "$parent_phase"
        printf 'prior_provider_expectation=%s\nprovider_expectation=%s\n' \
            "$(runtime_recovery_prior_provider_expectation)" "$provider_expectation"
        printf 'recovery_kind=%s\n' "$recovery_kind"
        test_crash after-runtime-recovery-generation-candidate-header
        for role in boot_id dockerd_pid dockerd_invocation_id docker_daemon_id docker_socket; do
            printf 'old_%s=%s\n' "$role" "$(runtime_recovery_old_tuple_value "$role")"
        done
        printf 'new_boot_id=%s\nnew_dockerd_pid=%s\nnew_dockerd_invocation_id=%s\n' \
            "$recovery_new_boot_id" "$recovery_new_dockerd_pid" \
            "$recovery_new_dockerd_invocation_id"
        printf 'new_docker_daemon_id=%s\nnew_docker_socket=%s\n' \
            "$recovery_new_docker_daemon_id" "$recovery_new_docker_socket"
        printf 'retired_member_count=%s\npool_member_set_sha256=%s\n' \
            "$pool_retired_member_count" "$(state_value pool_runtime_member_set_sha256)"
        for role in $RUNTIME_RECOVERY_ROLES; do
            printf '%s_name=%s\n%s_id=%s\n' "$role" \
                "$(runtime_recovery_role_name "$role")" "$role" \
                "$(runtime_recovery_role_id "$role")"
            old_snapshot=$(runtime_recovery_previous_snapshot "$role" "$provider_expectation")
            old_static=$(runtime_recovery_previous_static_sha256 "$role" "$provider_expectation")
            printf 'old_%s_snapshot_sha256=%s\nnew_%s_snapshot_sha256=%s\n' \
                "$role" "$(sha256_text "$old_snapshot")" "$role" \
                "$(sha256_text "${RECOVERY_NEW_SNAPSHOT[$role]}")"
            printf 'old_%s_static_sha256=%s\nnew_%s_static_sha256=%s\n' \
                "$role" "$old_static" "$role" "${RECOVERY_NEW_STATIC_SHA256[$role]}"
            printf 'old_%s_snapshot_b64=%s\nnew_%s_snapshot_b64=%s\n' \
                "$role" "$(printf '%s' "$old_snapshot" | encode)" "$role" \
                "$(printf '%s' "${RECOVERY_NEW_SNAPSHOT[$role]}" | encode)"
        done
        for role in web_a web_b; do
            old_web_network=$(runtime_recovery_previous_web_network "$role")
            printf 'old_%s_network_sha256=%s\nnew_%s_network_sha256=%s\n' \
                "$role" "$(sha256_text "$old_web_network")" "$role" \
                "$(sha256_text "${RECOVERY_NEW_WEB_NETWORK[$role]}")"
            printf 'old_%s_network_b64=%s\nnew_%s_network_b64=%s\n' \
                "$role" "$(printf '%s' "$old_web_network" | encode)" "$role" \
                "$(printf '%s' "${RECOVERY_NEW_WEB_NETWORK[$role]}" | encode)"
        done
        old_network_inventory=$(runtime_recovery_previous_network_inventory)
        printf 'old_network_inventory_sha256=%s\nnew_network_inventory_sha256=%s\n' \
            "$(sha256_text "$old_network_inventory")" \
            "$(sha256_text "$recovery_new_network_inventory")"
        printf 'old_network_inventory_b64=%s\nnew_network_inventory_b64=%s\n' \
            "$(printf '%s' "$old_network_inventory" | encode)" \
            "$(printf '%s' "$recovery_new_network_inventory" | encode)"
        printf 'release_provider_sha256=%s\nrelease_queue_sha256=%s\n' \
            "$recovery_release_provider_sha256" "$recovery_release_queue_sha256"
        printf 'release_terminal_sha256=%s\nrelease_files_sha256=%s\n' \
            "$recovery_release_terminal_sha256" "$recovery_release_files_sha256"
        printf 'intent_at_epoch=%s\nstatus=intent\n' "$(date -u +%s)"
    } >> "$candidate"
    test_crash after-runtime-recovery-generation-candidate-write
    runtime_recovery_atomic_create "$candidate" "$destination"
    latest_runtime_recovery_file=$destination
    latest_runtime_recovery_generation=$generation
    latest_runtime_recovery_status=intent
}

assert_probe_file()
{
    local path=$1 expected=$2 label=$3
    assert_root_path "$path" 700 "$label"
    validate_sha256 "$label checksum" "$expected"
    [[ -x $path && $(sha256_file "$path") == "$expected" ]] \
        || fail "$label differs from its reviewed checksum"
}

probe_value()
{
    local key=$1 file=$2 count result
    count=$(grep -E -c "^${key}=" "$file" || true)
    [[ $count -eq 1 ]] || fail "probe key is absent or duplicated: $key"
    result=$(sed -n "s/^${key}=//p" "$file")
    [[ $result != *$'\n'* ]] || fail "probe value contains a newline: $key"
    printf '%s\n' "$result"
}

assert_probe_shape()
{
    local file=$1 pattern=$2 label=$3
    [[ -f $file && ! -L $file ]] || fail "$label output is not a regular file"
    [[ $(grep -E -c "$pattern" "$file") -eq $(wc -l < "$file") ]] \
        || fail "$label output contains an unknown or malformed record"
}

fresh_epoch()
{
    local observed=$1 now
    [[ $observed =~ ^[1-9][0-9]*$ ]] || fail 'probe timestamp is malformed'
    now=$(date -u +%s)
    ((observed <= now + 5 && now - observed <= probe_max_age_seconds)) \
        || fail 'probe evidence is stale or from the future'
}

run_provider_probe()
{
    local expectation=$1 retired_a_id=${2:-$pool_retired_member_a_id}
    local retired_b_id=${3:-$pool_retired_member_b_id}
    local retired_b_name=$pool_retired_member_b_name output=$operation_directory/.provider.$$
    local expected_member_set_sha256 expected_backend_set_sha256 output_sha256
    local retired_a_backend_url retired_b_backend_url
    [[ $expectation == incumbent || $expectation == absent ]] \
        || fail 'provider probe expectation is invalid'
    if [[ $pool_retired_member_count == 1 ]]; then
        retired_b_name=absent
        retired_b_id=absent
    fi
    expected_member_set_sha256=$(provider_member_set_sha256 "$expectation" "$retired_a_id" \
        "$retired_b_id")
    assert_probe_file "$provider_probe" "$provider_probe_sha256" provider-probe
    CONTROL_PLANE_RUNTIME_OPERATION_ID=$operation_id \
    CONTROL_PLANE_RUNTIME_PROXY_ID=$(state_value proxy_id) \
    CONTROL_PLANE_RUNTIME_PROVIDER_EXPECTATION=$expectation \
    CONTROL_PLANE_RUNTIME_RETIRED_MEMBER_COUNT=$pool_retired_member_count \
    CONTROL_PLANE_RUNTIME_RETIRED_A_NAME=$legacy_container \
    CONTROL_PLANE_RUNTIME_RETIRED_A_ID=$retired_a_id \
    CONTROL_PLANE_RUNTIME_RETIRED_B_NAME=$retired_b_name \
    CONTROL_PLANE_RUNTIME_RETIRED_B_ID=$retired_b_id \
        "$provider_probe" > "$output"
    assert_probe_shape "$output" \
        '^(version=2|status=fresh|observed_at_epoch=[1-9][0-9]*|operation_id=[A-Za-z0-9_.-]+|proxy_id=[a-f0-9]{64}|proxy_pid=[1-9][0-9]*|expectation=(incumbent|absent)|retired_member_count=[12]|retired_a_name=[A-Za-z0-9][A-Za-z0-9_.-]{0,127}|retired_a_id=[a-f0-9]{64}|retired_b_name=(absent|[A-Za-z0-9][A-Za-z0-9_.-]{0,127})|retired_b_id=(absent|[a-f0-9]{64})|retired_a_backend_url=(absent|https?://[^[:space:]]+)|retired_b_backend_url=(absent|https?://[^[:space:]]+)|member_set_sha256=[a-f0-9]{64}|backend_set_sha256=[a-f0-9]{64}|snapshot_sha256=[a-f0-9]{64})$' \
        provider-probe
    retired_a_backend_url=$(probe_value retired_a_backend_url "$output")
    retired_b_backend_url=$(probe_value retired_b_backend_url "$output")
    expected_backend_set_sha256=$(
        {
            printf '%s\0' retired_a "$retired_a_backend_url"
            printf '%s\0' retired_b "$retired_b_backend_url"
        } | sha256sum | awk '{print $1}'
    )
    [[ $(wc -l < "$output") -eq 17 \
        && $(probe_value version "$output") == 2 \
        && $(probe_value status "$output") == fresh \
        && $(probe_value operation_id "$output") == "$operation_id" \
        && $(probe_value proxy_id "$output") == "$(state_value proxy_id)" \
        && $(probe_value proxy_pid "$output") =~ ^[1-9][0-9]*$ \
        && $(probe_value expectation "$output") == "$expectation" \
        && $(probe_value retired_member_count "$output") == "$pool_retired_member_count" \
        && $(probe_value retired_a_name "$output") == "$legacy_container" \
        && $(probe_value retired_a_id "$output") == "$retired_a_id" \
        && $(probe_value retired_b_name "$output") == "$retired_b_name" \
        && $(probe_value retired_b_id "$output") == "$retired_b_id" \
        && $(probe_value member_set_sha256 "$output") == "$expected_member_set_sha256" \
        && $(probe_value backend_set_sha256 "$output") == "$expected_backend_set_sha256" ]] \
        || fail 'provider probe did not attest the exact requested runtime'
    if [[ $expectation == absent ]]; then
        [[ $retired_a_backend_url == absent && $retired_b_backend_url == absent ]] \
            || fail 'absent provider probe returned a retired backend assignment'
    else
        [[ $retired_a_backend_url =~ ^https?:// ]] \
            || fail 'incumbent provider probe omitted retired A backend assignment'
        if [[ $pool_retired_member_count == 1 ]]; then
            [[ $retired_b_backend_url == absent ]] \
                || fail 'one-member provider probe returned a retired B backend assignment'
        else
            [[ $retired_b_backend_url =~ ^https?:// \
                && $retired_b_backend_url != "$retired_a_backend_url" ]] \
                || fail 'two-member provider probe omitted distinct A/B backend assignments'
        fi
    fi
    validate_sha256 provider-backend-set "$(probe_value backend_set_sha256 "$output")"
    validate_sha256 provider-snapshot "$(probe_value snapshot_sha256 "$output")"
    fresh_epoch "$(probe_value observed_at_epoch "$output")"
    output_sha256=$(sha256_file "$output")
    rm -f "$output"
    printf '%s\n' "$output_sha256"
}

provider_member_set_sha256()
{
    local expectation=$1 retired_a_id=$2 retired_b_id=$3 retired_b_name=$pool_retired_member_b_name
    if [[ $pool_retired_member_count == 1 ]]; then
        retired_b_name=absent
        retired_b_id=absent
    fi
    {
        printf '%s\0' "$expectation" "$pool_retired_member_count"
        printf '%s\0' retired_a "$legacy_container" "$retired_a_id"
        printf '%s\0' retired_b "$retired_b_name" "$retired_b_id"
    } | sha256sum | awk '{print $1}'
}

provider_capture_identity()
{
    case "$provider_capture_expectation" in
        incumbent|absent) printf '%s\n' "$provider_capture_expectation" ;;
        *)
            fail 'provider capture expectation is invalid'
            ;;
    esac
}

run_retired_reaper()
{
    local role=$1 container=$2 container_id=$3
    local output=$operation_directory/.reaper-${role}.$$
    assert_probe_file "$reaper" "$reaper_sha256" controlmaster-reaper
    CONTROL_PLANE_RUNTIME_RETIRED_ROLE=$role \
    CONTROL_PLANE_RUNTIME_RETIRED_CONTAINER=$container \
    CONTROL_PLANE_RUNTIME_RETIRED_ID=$container_id \
    CONTROL_PLANE_RUNTIME_NETWORK_INVENTORY=$network_inventory_file \
    CONTROL_PLANE_RUNTIME_SELF_SSH_TARGET=$self_ssh_target \
        "$reaper" > "$output"
    assert_probe_shape "$output" \
        '^(version=2|role=retired_[ab]|container_name=[A-Za-z0-9][A-Za-z0-9_.-]{0,127}|container_id=[a-f0-9]{64}|terminated=[0-9]+|conntrack_scope=attested-bridge-subnets:tcp22)$' \
        controlmaster-reaper
    [[ $(wc -l < "$output") -eq 6 \
        && $(probe_value version "$output") == 2 \
        && $(probe_value role "$output") == "$role" \
        && $(probe_value container_name "$output") == "$container" \
        && $(probe_value container_id "$output") == "$container_id" \
        && $(probe_value terminated "$output") =~ ^[0-9]+$ \
        && $(probe_value conntrack_scope "$output") == attested-bridge-subnets:tcp22 ]] \
        || fail 'controlmaster reaper did not attest the exact retired pool member'
    rm -f "$output"
}

network_ids()
{
    local proxy_inspect legacy_inspect retired_b_inspect
    proxy_inspect=$(require_docker_container_json "$proxy_container")
    legacy_inspect=$(require_docker_container_json "$legacy_container")
    {
        jq -r '.[0].NetworkSettings.Networks | to_entries[].value.NetworkID' \
            <<< "$proxy_inspect"
        jq -r '.[0].NetworkSettings.Networks | to_entries[].value.NetworkID' \
            <<< "$legacy_inspect"
        if [[ $pool_retired_member_count == 2 ]]; then
            retired_b_inspect=$(require_docker_container_json "$pool_retired_member_b_name")
            jq -r '.[0].NetworkSettings.Networks | to_entries[].value.NetworkID' \
                <<< "$retired_b_inspect"
        fi
        if [[ -n $additional_network_ids ]]; then
            tr ',' '\n' <<< "$additional_network_ids"
        fi
        tr ',' '\n' <<< "$candidate_network_ids"
        tr ',' '\n' <<< "$pool_web_b_network_ids"
    } | sed '/^$/d' | LC_ALL=C sort -u
}

capture_network_inventory()
{
    local destination=${1:-$network_inventory_file} requested_network_ids=${2:-}
    local id driver bridge ifindex network_json network_rows network_id_inventory inventory
    inventory=$(mktemp "${TMPDIR:-/tmp}/coolify-runtime-networks.XXXXXX")
    if [[ -n $requested_network_ids ]]; then
        network_id_inventory=$requested_network_ids
    else
        network_id_inventory=$(network_ids)
    fi
    while IFS= read -r id; do
        [[ $id =~ ^[a-f0-9]{64}$ ]] || fail 'Docker network ID is malformed'
        inspect_docker_network "$id"
        [[ $docker_api_state == present ]] \
            || fail "required Docker network is absent: $id"
        network_json=$docker_api_json
        driver=$(jq -r '.[0].Driver' <<< "$network_json")
        [[ $driver == bridge ]] || fail "attested network is not a bridge: $id"
        network_rows=$(jq -r '
            .[0] as $network
            | ($network.Options["com.docker.network.bridge.name"]
                // ("br-" + ($network.Id[0:12]))) as $bridge
            | ($network.IPAM.Config // [])[]
            | select(.Subnet != null and .Gateway != null)
            | [$network.Id, $network.Name, $bridge, .Subnet, .Gateway]
            | @tsv
        ' <<< "$network_json")
        while IFS=$'\t' read -r _ name bridge subnet gateway; do
            [[ -n $name ]] || continue
            [[ $name =~ ^[A-Za-z0-9_.-]+$ && $bridge =~ ^[A-Za-z0-9_.-]+$ \
                && $subnet != *[[:space:]]* && $gateway != *[[:space:]]* ]] \
                || fail 'Docker network inventory contains unsafe fields'
            ifindex=$(ip -o link show dev "$bridge" | awk -F: 'NR == 1 { gsub(/ /, "", $1); print $1 }')
            [[ $ifindex =~ ^[1-9][0-9]*$ ]] \
                || fail "Docker bridge interface is unavailable: $bridge"
            printf '%s\t%s\t%s\t%s\t%s\t%s\n' \
                "$id" "$name" "$bridge" "$ifindex" "$subnet" "$gateway" >> "$inventory"
        done <<< "$network_rows"
    done <<< "$network_id_inventory"
    LC_ALL=C sort -u -o "$inventory" "$inventory"
    [[ -s $inventory ]] || fail 'no Coolify bridge subnet was discovered'
    while IFS=$'\t' read -r id name bridge ifindex subnet gateway; do
        [[ $id =~ ^[a-f0-9]{64}$ && $name =~ ^[A-Za-z0-9_.-]+$ \
            && $bridge =~ ^[A-Za-z0-9_.-]+$ && $ifindex =~ ^[1-9][0-9]*$ ]] \
            || fail 'Docker network inventory identity is malformed'
        ipcalc -c "$subnet" >/dev/null 2>&1 || fail "invalid Docker bridge subnet: $subnet"
        ipcalc -c "$gateway" >/dev/null 2>&1 || fail "invalid Docker bridge gateway: $gateway"
    done < "$inventory"
    mv "$inventory" "$destination"
    chmod 0600 "$destination"
}

render_ruleset()
{
    local bridge v4 v6
    local -a bridge_interfaces
    mapfile -t bridge_interfaces < <(cut -f3 "$network_inventory_file" | LC_ALL=C sort -u)
    v4=$(awk -F '\t' '$5 !~ /:/ {print $5}' "$network_inventory_file" | LC_ALL=C sort -u | paste -sd, -)
    v6=$(awk -F '\t' '$5 ~ /:/ {print $5}' "$network_inventory_file" | LC_ALL=C sort -u | paste -sd, -)
    {
        printf 'add table %s %s\n' "$TABLE_FAMILY" "$TABLE_NAME"
        printf 'add set %s %s bridge_ipv4 { type ipv4_addr; flags interval;' "$TABLE_FAMILY" "$TABLE_NAME"
        [[ -z $v4 ]] || printf ' elements = { %s };' "$v4"
        printf ' }\n'
        printf 'add set %s %s bridge_ipv6 { type ipv6_addr; flags interval;' "$TABLE_FAMILY" "$TABLE_NAME"
        [[ -z $v6 ]] || printf ' elements = { %s };' "$v6"
        printf ' }\n'
        printf 'add chain %s %s %s { type filter hook input priority -5; policy accept; }\n' \
            "$TABLE_FAMILY" "$TABLE_NAME" "$CHAIN_NAME"
        printf 'add rule %s %s %s meta iifkind "bridge" tcp dport 22 drop\n' \
            "$TABLE_FAMILY" "$TABLE_NAME" "$CHAIN_NAME"
        printf 'add rule %s %s %s iifname "br-*" tcp dport 22 drop\n' \
            "$TABLE_FAMILY" "$TABLE_NAME" "$CHAIN_NAME"
        printf 'add rule %s %s %s iifname "docker0" tcp dport 22 drop\n' \
            "$TABLE_FAMILY" "$TABLE_NAME" "$CHAIN_NAME"
        printf 'add rule %s %s %s iifname "docker_gwbridge" tcp dport 22 drop\n' \
            "$TABLE_FAMILY" "$TABLE_NAME" "$CHAIN_NAME"
        for bridge in "${bridge_interfaces[@]}"; do
            printf 'add rule %s %s %s iifname "%s" tcp dport 22 drop\n' \
                "$TABLE_FAMILY" "$TABLE_NAME" "$CHAIN_NAME" "$bridge"
            [[ -z $v4 ]] || printf 'add rule %s %s %s iifname "%s" ip saddr @bridge_ipv4 tcp dport 22 drop\n' \
                "$TABLE_FAMILY" "$TABLE_NAME" "$CHAIN_NAME" "$bridge"
            [[ -z $v6 ]] || printf 'add rule %s %s %s iifname "%s" ip6 saddr @bridge_ipv6 tcp dport 22 drop\n' \
                "$TABLE_FAMILY" "$TABLE_NAME" "$CHAIN_NAME" "$bridge"
        done
    } > "$ruleset_file"
    chmod 0600 "$ruleset_file"
    nft --check --file "$ruleset_file"
}

table_exists()
{
    nft list table "$TABLE_FAMILY" "$TABLE_NAME" >/dev/null 2>&1
}

nft_identity()
{
    nft --json list table "$TABLE_FAMILY" "$TABLE_NAME" \
        | jq -S -c 'del(.. | .handle?) | .nftables |= map(select(has("metainfo") | not))' \
        | sha256sum | awk '{print $1}'
}

expected_nft_identity()
{
    if [[ $test_mode == 1 ]]; then
        validate_sha256 test-expected-nft-identity \
            "${CONTROL_PLANE_RUNTIME_TEST_EXPECTED_NFT_IDENTITY:-}"
        printf '%s\n' "$CONTROL_PLANE_RUNTIME_TEST_EXPECTED_NFT_IDENTITY"
        return
    fi
    require_command unshare
    # The isolated script reads only the explicitly passed immutable ruleset path.
    # shellcheck disable=SC2016
    unshare --net env CONTROL_PLANE_EXPECTED_RULESET="$ruleset_file" \
        bash -Eeuo pipefail -c '
            nft --file "$CONTROL_PLANE_EXPECTED_RULESET"
            nft --json list table inet coolify_control_plane_ssh_fence \
                | jq -S -c '\''del(.. | .handle?) | .nftables |= map(select(has("metainfo") | not))'\''
        ' | sha256sum | awk '{print $1}'
}

apply_or_verify_fence()
{
    local expected
    expected=$(state_value nft_identity)
    if table_exists; then
        [[ $expected != pending && $(nft_identity) == "$expected" ]] \
            || fail 'managed nft table exists with an unexpected identity'
        return
    fi
    [[ $(sha256_file "$ruleset_file") == "$(state_value ruleset_sha256)" ]] \
        || fail 'durable nft ruleset differs from attested bytes'
    apply_fence_ruleset
    [[ $(nft_identity) == "$expected" ]] \
        || fail 'recreated nft table differs from its canonical identity'
}

apply_fence_ruleset()
{
    # Recheck immediately before every host ruleset application.  A capture can
    # resume after a crash, or a watchdog can restore a missing table, after the
    # management link topology has changed.
    assert_management_ingress_is_not_bridged
    nft --file "$ruleset_file"
}

assert_fence_identity()
{
    table_exists || fail 'managed nft table is absent'
    [[ $(nft_identity) == "$(state_value nft_identity)" ]] \
        || fail 'managed nft table identity changed'
}

write_capture_state()
{
    local phase=$1 nft_hash=$2 candidate=$operation_directory/.state.$$
    local proxy_json legacy_json proxy_runtime_hash legacy_runtime_hash systemd_hash retired_b_json
    proxy_json=$(runtime_value proxy_json)
    legacy_json=$(runtime_value legacy_json)
    proxy_runtime_hash=$(container_runtime_sha256 "$proxy_container")
    legacy_runtime_hash=$(container_runtime_sha256 "$legacy_container")
    systemd_hash=$(systemd_contract_sha256)
    {
        printf 'version=%s\nruntime_digest_version=%s\nphase=%s\noperation_id=%s\n' \
            "$STATE_VERSION" "$RUNTIME_DIGEST_VERSION" "$phase" "$operation_id"
        printf 'pool_plan_manifest=%s\npool_plan_manifest_sha256=%s\npool_plan_manifest_metadata=%s\n' \
            "$pool_plan_manifest" "$pool_plan_manifest_sha256" "$pool_plan_manifest_metadata"
        printf 'pool_direction=%s\npool_color=%s\npool_generation=%s\npool_plan_set_sha256=%s\n' \
            "$pool_direction" "$pool_color" "$pool_generation" "$pool_plan_set_sha256_value"
        printf 'pool_writer_member=%s\npool_writer_container=%s\npool_label_key=%s\npool_label_value=%s\n' \
            "$pool_writer_member" "$pool_writer_container" "$pool_label_key" "$pool_label_value"
        printf 'pool_member_count=2\npool_retired_member_count=%s\n' "$pool_retired_member_count"
        printf 'ingress_pool_manifest=%s\ningress_pool_manifest_sha256=pending\n' \
            "$ingress_pool_manifest"
        printf 'ingress_pool_manifest_metadata=pending\npool_runtime_member_set_sha256=pending\n'
        printf 'proxy_container=%s\nlegacy_container=%s\ncandidate_container=%s\n' \
            "$proxy_container" "$legacy_container" "$candidate_container"
        printf 'candidate_image_reference=%s\ncandidate_image_id=%s\n' \
            "$candidate_image_reference" "$candidate_image_id"
        printf 'candidate_route_identity=%s\n' "$candidate_route_identity"
        printf 'candidate_runtime_sha256=none\n'
        printf 'web_b_container=%s\nweb_b_image_reference=%s\nweb_b_image_id=%s\n' \
            "$pool_web_b_container" "$pool_web_b_image_reference" "$pool_web_b_image_id"
        printf 'web_b_route_identity=%s\n' "$pool_web_b_route_identity"
        printf 'web_b_network_ids_b64=%s\nweb_b_runtime_sha256=none\n' \
            "$(printf '%s' "$pool_web_b_network_ids" | encode)"
        printf 'boot_id=%s\n' "$(runtime_value boot_id)"
        printf 'dockerd_pid=%s\n' "$(runtime_value dockerd_pid)"
        printf 'dockerd_invocation_id=%s\n' "$(runtime_value dockerd_invocation_id)"
        printf 'docker_daemon_id=%s\n' "$(runtime_value docker_daemon_id)"
        printf 'docker_socket=%s\n' "$(runtime_value docker_socket)"
        printf 'proxy_id=%s\nproxy_image_id=%s\nproxy_started_at=%s\nproxy_status=%s\nproxy_health=%s\nproxy_restart_count=%s\n' \
            "$(jq -r .id <<< "$proxy_json")" "$(jq -r .image_id <<< "$proxy_json")" \
            "$(jq -r .started_at <<< "$proxy_json")" "$(jq -r .status <<< "$proxy_json")" \
            "$(jq -r .health <<< "$proxy_json")" "$(jq -r .restart_count <<< "$proxy_json")"
        printf 'proxy_bindings_b64=%s\n' "$(jq -S -c '{configured_bindings,published_bindings}' <<< "$proxy_json" | encode)"
        printf 'proxy_snapshot_b64=%s\nproxy_runtime_sha256=%s\n' \
            "$(printf '%s' "$proxy_json" | encode)" "$proxy_runtime_hash"
        printf 'legacy_id=%s\nlegacy_image_id=%s\nlegacy_started_at=%s\nlegacy_status=%s\nlegacy_health=%s\nlegacy_restart_count=%s\n' \
            "$(jq -r .id <<< "$legacy_json")" "$(jq -r .image_id <<< "$legacy_json")" \
            "$(jq -r .started_at <<< "$legacy_json")" "$(jq -r .status <<< "$legacy_json")" \
            "$(jq -r .health <<< "$legacy_json")" "$(jq -r .restart_count <<< "$legacy_json")"
        printf 'legacy_image_reference=%s\n' "$(jq -r .image_reference <<< "$legacy_json")"
        printf 'legacy_runtime_sha256=%s\n' "$legacy_runtime_hash"
        printf 'legacy_snapshot_b64=%s\n' "$(printf '%s' "$legacy_json" | encode)"
        printf 'retired_member_a_name=%s\nretired_member_a_id=%s\n' \
            "$legacy_container" "$pool_retired_member_a_id"
        if [[ $pool_retired_member_count == 2 ]]; then
            retired_b_json=$(runtime_value retired_b_json)
            printf 'retired_member_b_name=%s\nretired_member_b_id=%s\n' \
                "$pool_retired_member_b_name" "$pool_retired_member_b_id"
            printf 'retired_member_b_runtime_sha256=%s\nretired_member_b_snapshot_b64=%s\n' \
                "$(container_runtime_sha256 "$pool_retired_member_b_name")" \
                "$(printf '%s' "$retired_b_json" | encode)"
        fi
        printf 'docker_version=%s\n' "$(runtime_value docker_version)"
        printf 'nft_version=%s\n' "$(runtime_value nft_version)"
        printf 'conntrack_version=%s\n' "$(runtime_value conntrack_version)"
        printf 'ssh_version=%s\n' "$(runtime_value ssh_version)"
        printf 'systemd_version=%s\n' "$(runtime_value systemd_version)"
        printf 'controller_sha256=%s\n' "$(sha256_file "$controller_path")"
        printf 'systemd_contract_sha256=%s\n' "$systemd_hash"
        printf 'semantic_config_sha256=%s\n' "$semantic_config_sha256"
        printf 'release_files_sha256=%s\n' "$release_files_sha256"
        printf 'provider_probe_sha256=%s\nreaper_sha256=%s\nqueue_probe_sha256=%s\nterminal_probe_sha256=%s\n' \
            "$provider_probe_sha256" "$reaper_sha256" "$queue_probe_sha256" "$terminal_probe_sha256"
        printf 'network_inventory_sha256=%s\nruleset_sha256=%s\nnft_identity=%s\n' \
            "$(sha256_file "$network_inventory_file")" "$(sha256_file "$ruleset_file")" "$nft_hash"
        printf 'captured_at_epoch=%s\n' "$(date -u +%s)"
        printf 'provider_capture_sha256=%s\n' "${provider_capture_sha256:-pending}"
        printf 'management_endpoints_b64=%s\n' "$(printf '%s' "$management_endpoints" | encode)"
        printf 'additional_network_ids_b64=%s\n' "$(printf '%s' "$additional_network_ids" | encode)"
        printf 'candidate_network_ids_b64=%s\n' "$(printf '%s' "$candidate_network_ids" | encode)"
        printf 'self_ssh_target=%s\nprobe_max_age_seconds=%s\n' "$self_ssh_target" "$probe_max_age_seconds"
    } > "$candidate"
    atomic_state "$candidate"
}

transition_state()
{
    local phase=$1 nft_hash=$2 provider_hash=$3 candidate=$operation_directory/.state.transition.$$
    awk -F= -v phase="$phase" -v nft_hash="$nft_hash" -v provider_hash="$provider_hash" '
        $1 == "phase" { print "phase=" phase; next }
        $1 == "nft_identity" { print "nft_identity=" nft_hash; next }
        $1 == "provider_capture_sha256" { print "provider_capture_sha256=" provider_hash; next }
        { print }
    ' "$state_file" > "$candidate"
    atomic_state "$candidate"
}

assert_common_identity()
{
    local key current_json expected_proxy_json
    [[ $(state_value version) == "$STATE_VERSION" \
        && $(state_value runtime_digest_version) == "$RUNTIME_DIGEST_VERSION" \
        && $(state_value operation_id) == "$operation_id" ]] \
        || fail 'state belongs to another operation or version'
    [[ $(state_value pool_plan_manifest) == "$pool_plan_manifest" \
        && $(state_value pool_plan_manifest_sha256) == "$pool_plan_manifest_sha256" \
        && $(state_value pool_plan_manifest_metadata) == "$pool_plan_manifest_metadata" \
        && $(state_value pool_direction) == "$pool_direction" \
        && $(state_value pool_color) == "$pool_color" \
        && $(state_value pool_generation) == "$pool_generation" \
        && $(state_value pool_plan_set_sha256) == "$pool_plan_set_sha256_value" \
        && $(state_value pool_writer_member) == "$pool_writer_member" \
        && $(state_value pool_writer_container) == "$pool_writer_container" \
        && $(state_value pool_label_key) == "$pool_label_key" \
        && $(state_value pool_label_value) == "$pool_label_value" \
        && $(state_value pool_member_count) == 2 \
        && $(state_value pool_retired_member_count) == "$pool_retired_member_count" \
        && $(state_value ingress_pool_manifest) == "$ingress_pool_manifest" ]] \
        || fail 'pool plan manifest or generation identity changed during this operation'
    [[ $(state_value proxy_container) == "$proxy_container" \
        && $(state_value legacy_container) == "$legacy_container" \
        && $(state_value candidate_container) == "$candidate_container" ]] \
        || fail 'container names changed during this operation'
    [[ $(state_value candidate_image_reference) == "$candidate_image_reference" \
        && $(state_value candidate_image_id) == "$candidate_image_id" \
        && $(state_value candidate_route_identity) == "$candidate_route_identity" \
        && $(state_value web_b_container) == "$pool_web_b_container" \
        && $(state_value web_b_image_reference) == "$pool_web_b_image_reference" \
        && $(state_value web_b_image_id) == "$pool_web_b_image_id" \
        && $(state_value web_b_route_identity) == "$pool_web_b_route_identity" \
        && $(state_value web_b_network_ids_b64) \
            == "$(printf '%s' "$pool_web_b_network_ids" | encode)" ]] \
        || fail 'two-member immutable pre-start plan changed during this operation'
    assert_candidate_runtime_state
    assert_pool_runtime_state
    if [[ $(state_value ingress_pool_manifest_sha256) != pending ]]; then
        validate_ingress_pool_manifest "$(state_value ingress_pool_manifest_sha256)"
        [[ $ingress_pool_manifest_metadata == "$(state_value ingress_pool_manifest_metadata)" ]] \
            || fail 'ingress pool manifest metadata changed after pinning'
    else
        [[ $(state_value ingress_pool_manifest_metadata) == pending ]] \
            || fail 'unpinned ingress pool manifest has partial metadata evidence'
    fi
    for key in boot_id dockerd_pid dockerd_invocation_id docker_daemon_id docker_socket \
        docker_version nft_version conntrack_version ssh_version systemd_version; do
        assert_runtime_value "$key"
    done
    [[ $(sha256_file "$controller_path") == "$(state_value controller_sha256)" ]] \
        || fail 'controller bytes changed during this operation'
    assert_systemd_contract
    [[ $semantic_config_sha256 == "$(state_value semantic_config_sha256)" \
        && $(semantic_configuration_sha256) == "$semantic_config_sha256" ]] \
        || fail 'immutable semantic fence configuration changed'
    [[ $release_files_sha256 == "$(state_value release_files_sha256)" \
        && $(release_files_manifest_sha256) == "$release_files_sha256" ]] \
        || fail 'release authorization file identity changed'
    [[ $(state_value provider_probe_sha256) == "$provider_probe_sha256" \
        && $(state_value reaper_sha256) == "$reaper_sha256" \
        && $(state_value queue_probe_sha256) == "$queue_probe_sha256" \
        && $(state_value terminal_probe_sha256) == "$terminal_probe_sha256" ]] \
        || fail 'reviewed helper checksums changed'
    assert_probe_file "$provider_probe" "$provider_probe_sha256" provider-probe
    assert_probe_file "$reaper" "$reaper_sha256" controlmaster-reaper
    assert_probe_file "$queue_probe" "$queue_probe_sha256" queue-probe
    assert_probe_file "$terminal_probe" "$terminal_probe_sha256" terminal-probe
    assert_root_path "$network_inventory_file" 600 network-inventory
    assert_root_path "$ruleset_file" 600 nft-ruleset
    [[ $(sha256_file "$network_inventory_file") == "$(state_value network_inventory_sha256)" \
        && $(sha256_file "$ruleset_file") == "$(state_value ruleset_sha256)" ]] \
        || fail 'durable network inventory or nft ruleset changed'
    current_json=$(runtime_value proxy_json)
    expected_proxy_json=$(expected_container_snapshot proxy)
    if [[ $expected_proxy_json == none ]]; then
        [[ $(jq -r .id <<< "$current_json") == "$(state_value proxy_id)" \
            && $(jq -r .image_id <<< "$current_json") == "$(state_value proxy_image_id)" \
            && $(jq -r .started_at <<< "$current_json") == "$(state_value proxy_started_at)" \
            && $(jq -r .status <<< "$current_json") == "$(state_value proxy_status)" \
            && $(jq -r .health <<< "$current_json") == "$(state_value proxy_health)" \
            && $(jq -r .restart_count <<< "$current_json") == "$(state_value proxy_restart_count)" \
            && $(jq -S -c '{configured_bindings,published_bindings}' <<< "$current_json" | encode) == "$(state_value proxy_bindings_b64)" ]] \
            || fail 'proxy container identity or published bindings changed'
    else
        [[ $current_json == "$expected_proxy_json" ]] \
            || fail 'proxy container differs from the latest verified recovery generation'
    fi
    [[ $(printf '%s' "$management_endpoints" | encode) == "$(state_value management_endpoints_b64)" ]] \
        || fail 'management endpoint inventory changed during this operation'
    [[ $(printf '%s' "$additional_network_ids" | encode) == "$(state_value additional_network_ids_b64)" \
        && $(printf '%s' "$candidate_network_ids" | encode) == "$(state_value candidate_network_ids_b64)" \
        && $(state_value self_ssh_target) == "$self_ssh_target" \
        && $(state_value probe_max_age_seconds) == "$probe_max_age_seconds" ]] \
        || fail 'network/probe fence configuration changed during this operation'
    if [[ $(state_value phase) == preparing ]]; then
        [[ $(state_value provider_capture_sha256) == pending ]] \
            || fail 'preparing state has unexpected provider evidence'
    else
        validate_sha256 provider-capture "$(state_value provider_capture_sha256)"
    fi
    assert_fence_identity
}

assert_network_membership()
{
    local expected actual
    expected=$(cut -f1 "$network_inventory_file" | LC_ALL=C sort -u)
    actual=$(network_ids)
    [[ $actual == "$expected" ]] || fail 'proxy/legacy Coolify bridge membership changed'
}

assert_legacy_identity()
{
    local current_json expected_legacy_json
    current_json=$(runtime_value legacy_json)
    expected_legacy_json=$(expected_container_snapshot incumbent)
    if [[ $expected_legacy_json != none ]]; then
        [[ $current_json == "$expected_legacy_json" \
            && $(container_runtime_sha256 "$legacy_container") \
                == "$(expected_container_static_sha256 incumbent \
                    "$(state_value legacy_runtime_sha256)")" ]] \
            || fail 'legacy container differs from the latest verified recovery generation'
        return
    fi
    [[ $(jq -r .id <<< "$current_json") == "$(state_value legacy_id)" \
        && $(jq -r .image_id <<< "$current_json") == "$(state_value legacy_image_id)" \
        && $(jq -r .started_at <<< "$current_json") == "$(state_value legacy_started_at)" \
        && $(jq -r .status <<< "$current_json") == "$(state_value legacy_status)" \
        && $(jq -r .health <<< "$current_json") == "$(state_value legacy_health)" \
        && $(jq -r .restart_count <<< "$current_json") == "$(state_value legacy_restart_count)" \
        && $(jq -r .image_reference <<< "$current_json") == "$(state_value legacy_image_reference)" \
        && $(container_runtime_sha256 "$legacy_container") == "$(state_value legacy_runtime_sha256)" ]] \
        || fail 'legacy container identity or published bindings changed'
}

assert_retired_pool_identity()
{
    local current_json expected_json
    assert_legacy_identity
    [[ $pool_retired_member_count == 2 ]] || return 0
    current_json=$(container_json "$pool_retired_member_b_name")
    expected_json=$(state_value retired_member_b_snapshot_b64 | base64 --decode)
    [[ $(jq -r .id <<< "$current_json") == "$pool_retired_member_b_id" \
        && $current_json == "$expected_json" \
        && $(container_runtime_sha256 "$pool_retired_member_b_name") \
            == "$(state_value retired_member_b_runtime_sha256)" ]] \
        || fail 'retired member B differs from its exact captured runtime identity'
}

prove_management_unaffected()
{
    local endpoint interface address found=0
    ssh-keyscan -T 3 -p 22 127.0.0.1 >/dev/null 2>&1 \
        || fail 'loopback SSH handshake is not reachable with the fence active'
    for endpoint in $management_endpoints; do
        [[ $endpoint == *=* ]] || fail 'management endpoint must be interface=address'
        interface=${endpoint%%=*}
        address=${endpoint#*=}
        [[ $interface =~ ^[A-Za-z0-9_.-]+$ && $address != *[[:space:]]* ]] \
            || fail 'management endpoint contains unsafe characters'
        ip -o address show dev "$interface" \
            | awk '{sub(/\/.*/, "", $4); print $4}' | grep -F -x -q "$address" \
            || fail "management address is not assigned to its interface: $endpoint"
        ssh-keyscan -T 3 -p 22 "$address" >/dev/null 2>&1 \
            || fail "management SSH handshake is not reachable: $endpoint"
        found=1
    done
    [[ $found -eq 1 ]] || fail 'at least one Tailscale or management endpoint is required'
}

assert_management_ingress_is_not_bridged()
{
    local endpoint interface current_interface link_json link_details link_kind master_interface
    local found=0 hops chain
    for endpoint in $management_endpoints; do
        [[ $endpoint == *=* ]] || fail 'management endpoint must be interface=address'
        interface=${endpoint%%=*}
        [[ $interface =~ ^[A-Za-z0-9_.-]+$ ]] \
            || fail "management SSH interface is unsafe: $interface"

        # The fence drops every packet whose ingress device is a Linux bridge.  Walk
        # the kernel-reported master chain instead of inspecting host sysfs so the
        # proof is both namespace-aware and directly testable through iproute2.
        current_interface=$interface
        hops=0
        chain=" $interface "
        while :; do
            ((hops < 16)) \
                || fail "management SSH interface master chain is too deep: $interface"
            hops=$((hops + 1))
            link_json=$(ip -d -j link show dev "$current_interface") \
                || fail "management SSH interface is unavailable: $current_interface"
            link_details=$(jq -r --arg interface "$current_interface" '
                if type == "array" and length == 1
                    and .[0].ifname == $interface
                    and ((.[0].linkinfo // {}) | type == "object")
                    and (((.[0].linkinfo.info_kind // "") | type) == "string")
                    and (((.[0].master // "") | type) == "string")
                then [.[0].linkinfo.info_kind // "", .[0].master // ""] | @tsv
                else empty
                end
            ' <<< "$link_json")
            [[ $link_details == *$'\t'* ]] \
                || fail "management SSH interface link metadata is malformed: $current_interface"
            IFS=$'\t' read -r link_kind master_interface <<< "$link_details"
            [[ $link_kind != bridge ]] \
                || fail "management SSH interface is a Linux bridge or is attached through one: $interface (bridge=$current_interface)"
            [[ -z $master_interface ]] && break
            [[ $master_interface =~ ^[A-Za-z0-9_.-]+$ ]] \
                || fail "management SSH interface master is unsafe: $master_interface"
            [[ $chain != *" $master_interface "* ]] \
                || fail "management SSH interface master chain contains a cycle: $interface"
            chain+="$master_interface "
            current_interface=$master_interface
        done
        found=1
    done
    [[ $found -eq 1 ]] || fail 'at least one non-bridge management endpoint is required'
}

stage_capture()
{
    local phase expected_nft_hash
    if [[ -f $state_file ]]; then
        phase=$(state_value phase)
        [[ $(state_value operation_id) == "$operation_id" ]] || fail 'another operation owns the runtime fence state'
        case "$phase" in
            preparing)
                assert_restore_artifacts
                if table_exists; then
                    assert_fence_identity
                else
                    candidate_is_absent \
                        || fail 'planned candidate exists before the runtime fence is active'
                fi
                note "capture_stage=passed operation_id=$operation_id phase=preparing already_staged=true"
                return
                ;;
            active)
                assert_restore_artifacts
                assert_fence_identity
                note "capture_stage=passed operation_id=$operation_id phase=active already_active=true"
                return
                ;;
            *) fail "capture staging cannot resume from phase: $phase" ;;
        esac
    fi

    table_exists && fail 'managed nft table already exists without owned state'
    assert_pool_candidates_absent
    assert_live_runtime_shape
    assert_management_ingress_is_not_bridged
    capture_network_inventory
    render_ruleset
    expected_nft_hash=$(expected_nft_identity)
    provider_capture_sha256=pending
    write_capture_state preparing "$expected_nft_hash"
    test_crash after-preparing-state
    note "capture_stage=passed operation_id=$operation_id phase=preparing"
}

capture()
{
    local phase
    [[ -f $state_file ]] || fail 'runtime fence capture requires a boot-restorable staged state'
    phase=$(state_value phase)
    [[ $(state_value operation_id) == "$operation_id" ]] || fail 'another operation owns the runtime fence state'
    case "$phase" in
        preparing)
            [[ $armed == 1 ]] \
                || fail 'runtime fence capture requires boot restoration to be armed before nft mutation'
            if ! table_exists; then
                candidate_is_absent \
                    || fail 'planned candidate exists before the runtime fence is active'
            fi
            apply_or_verify_fence
            test_crash after-nft-apply
            ;;
        active)
            verify
            return
            ;;
        *) fail "capture cannot resume from phase: $phase" ;;
    esac
    assert_common_identity
    assert_retired_pool_identity
    assert_network_membership
    run_retired_reaper retired_a "$legacy_container" "$(state_value legacy_id)"
    if [[ $pool_retired_member_count == 2 ]]; then
        run_retired_reaper retired_b "$pool_retired_member_b_name" \
            "$pool_retired_member_b_id"
    fi
    prove_management_unaffected
    provider_capture_sha256=$(run_provider_probe "$(provider_capture_identity)")
    transition_state active "$(nft_identity)" "$provider_capture_sha256"
    note "capture=passed operation_id=$operation_id phase=active"
}

verify()
{
    local web_a_state web_b_state
    [[ -f $state_file && $(state_value phase) == active ]] \
        || fail 'active runtime fence state is absent'
    assert_common_identity
    assert_retired_pool_identity
    assert_network_membership
    inspect_docker_container "$candidate_container"
    web_a_state=$docker_api_state
    inspect_docker_container "$pool_web_b_container"
    web_b_state=$docker_api_state
    if grep -E -q '^(candidate_denial_id|web_b_denial_id)=' "$state_file"; then
        [[ $(state_value candidate_runtime_sha256) != none \
            && $(state_value web_b_runtime_sha256) != none ]] \
            || fail 'active fence has a partial two-member denial proof'
        if [[ $web_a_state == present || $web_b_state == present ]]; then
            [[ $web_a_state == present && $web_b_state == present ]] \
                || fail 'exactly one denied pool member is missing'
            assert_pool_denial
        else
            candidate_is_absent || fail 'denied pool absence is not exact'
        fi
    else
        [[ $web_a_state == "$web_b_state" ]] \
            || fail 'unproven pool has exactly one present member'
        if [[ $web_a_state == absent ]]; then
            [[ -z $(pool_selector_rows) ]] \
                || fail 'unproven absent pool selector contains an unexpected member'
        else
            assert_unproven_pool_baseline
        fi
    fi
    run_provider_probe "$(provider_capture_identity)" >/dev/null
    prove_management_unaffected
    note "verify=passed operation_id=$operation_id phase=active"
}

assert_unproven_pool_baseline()
{
    local web_a_json web_b_json actual expected
    web_a_json=$(container_json "$candidate_container")
    web_b_json=$(container_json "$pool_web_b_container")
    [[ $(jq -r .image_id <<< "$web_a_json") == "$candidate_image_id" \
        && $(jq -r .image_reference <<< "$web_a_json") == "$candidate_image_reference" \
        && $(jq -r .restart_policy <<< "$web_a_json") == unless-stopped \
        && $(jq -r .image_id <<< "$web_b_json") == "$pool_web_b_image_id" \
        && $(jq -r .image_reference <<< "$web_b_json") == "$pool_web_b_image_reference" \
        && $(jq -r .restart_policy <<< "$web_b_json") == unless-stopped ]] \
        || fail 'unproven two-member pool differs from its immutable image/restart plan'
    assert_candidate_network_plan "$(candidate_network_inventory_json "$candidate_container")" \
        "$candidate_network_ids"
    assert_candidate_network_plan "$(candidate_network_inventory_json "$pool_web_b_container")" \
        "$pool_web_b_network_ids"
    assert_candidate_static_baseline "$candidate_container"
    assert_candidate_static_baseline "$pool_web_b_container"
    actual=$(pool_selector_rows)
    expected=$(printf '%s\t/%s\n%s\t/%s\n' \
        "$(jq -r .id <<< "$web_a_json")" "$candidate_container" \
        "$(jq -r .id <<< "$web_b_json")" "$pool_web_b_container" | LC_ALL=C sort)
    [[ $actual == "$expected" ]] \
        || fail 'unproven pool selector contains missing, duplicate, or unexpected members'
}

post_revoke_network_ids()
{
    local proxy_inspect candidate_inspect web_b_inspect
    proxy_inspect=$(require_docker_container_json "$proxy_container")
    candidate_inspect=$(require_docker_container_json "$(state_value candidate_denial_name)")
    web_b_inspect=$(require_docker_container_json "$(state_value web_b_denial_name)")
    {
        jq -r '.[0].NetworkSettings.Networks | to_entries[].value.NetworkID' \
            <<< "$proxy_inspect"
        jq -r '.[0].NetworkSettings.Networks | to_entries[].value.NetworkID' \
            <<< "$candidate_inspect"
        jq -r '.[0].NetworkSettings.Networks | to_entries[].value.NetworkID' \
            <<< "$web_b_inspect"
        if [[ -n $additional_network_ids ]]; then
            tr ',' '\n' <<< "$additional_network_ids"
        fi
        tr ',' '\n' <<< "$candidate_network_ids"
        tr ',' '\n' <<< "$pool_web_b_network_ids"
    } | sed '/^$/d' | LC_ALL=C sort -u
}

verify_post_revoke()
{
    local expected_network_ids actual_network_ids
    [[ -f $state_file && $(state_value phase) == active ]] \
        || fail 'active runtime fence state is absent'
    assert_common_identity
    retired_pool_is_absent || fail 'retired container name or exact ID still exists after revocation'
    assert_pool_denial
    expected_network_ids=$(cut -f1 "$network_inventory_file" | LC_ALL=C sort -u)
    actual_network_ids=$(post_revoke_network_ids)
    [[ $actual_network_ids == "$expected_network_ids" ]] \
        || fail 'post-revoke proxy/candidate bridge membership changed'
    run_provider_probe absent >/dev/null
    prove_management_unaffected
    note "verify_post_revoke=passed operation_id=$operation_id phase=active"
}

assert_container_ssh_denied()
{
    local candidate_id=$1 gateway=$2 output=$operation_directory/.candidate-ssh.$$
    set +e
    docker exec "$candidate_id" timeout 5 ssh -F /dev/null -o BatchMode=yes \
        -o ConnectTimeout=2 -o ConnectionAttempts=1 -o StrictHostKeyChecking=no \
        -o UserKnownHostsFile=/dev/null -p 22 "root@$gateway" true > /dev/null 2> "$output"
    local status=$?
    set -e
    [[ $status -eq 255 && $(cat "$output") == *'timed out'* ]] \
        || fail "fresh candidate-to-host SSH was not denied by the nft fence: status=$status output=$(tr '\n' ' ' < "$output")"
    rm -f "$output"
}

candidate_network_inventory_json()
{
    local inspect_json
    inspect_json=$(require_docker_container_json "$1")
    jq -S -c '
        [.[0].NetworkSettings.Networks
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
    ' <<< "$inspect_json"
}

assert_candidate_network_plan()
{
    local inventory_json=$1 planned_network_ids=${2:-$candidate_network_ids}
    local expected_ids observed_ids
    expected_ids=$(tr ',' '\n' <<< "$planned_network_ids" | LC_ALL=C sort -u)
    observed_ids=$(jq -r '.[].network_id' <<< "$inventory_json" | LC_ALL=C sort -u)
    [[ $observed_ids == "$expected_ids" \
        && $(jq -r 'all(.[]; (.name | test("^[A-Za-z0-9_.-]+$"))
            and (.network_id | test("^[a-f0-9]{64}$"))
            and (.endpoint_id | test("^$|^[a-f0-9]{64}$"))
            and (.ipv4_address | test("^[A-Fa-f0-9:./]*$"))
            and (.ipv4_gateway | test("^[A-Fa-f0-9:./]*$"))
            and (.ipv6_address | test("^[A-Fa-f0-9:./]*$"))
            and (.ipv6_gateway | test("^[A-Fa-f0-9:./]*$"))
            and (.mac_address | test("^$|^[A-Fa-f0-9:.-]+$")))' \
            <<< "$inventory_json") == true ]] \
        || fail 'candidate complete network inventory differs from the immutable plan'
}

container_network_sha256()
{
    candidate_network_inventory_json "$1" | sha256sum | awk '{print $1}'
}

container_bindings_sha256()
{
    container_json "$1" \
        | jq -S -c '{configured_bindings, published_bindings}' \
        | sha256sum | awk '{print $1}'
}

assert_candidate_network_ssh_denied()
{
    local candidate_id=$1 inventory_json=$2 network_id gateway gateway_count=0
    while IFS= read -r network_id; do
        while IFS= read -r gateway; do
            [[ -n $gateway ]] || continue
            assert_container_ssh_denied "$candidate_id" "$gateway"
            gateway_count=$((gateway_count + 1))
        done < <(awk -F '\t' -v id="$network_id" '$1 == id { print $6 }' \
            "$network_inventory_file" | LC_ALL=C sort -u)
    done < <(jq -r '.[].network_id' <<< "$inventory_json")
    [[ $gateway_count -ge $(jq 'length' <<< "$inventory_json") ]] \
        || fail 'candidate network plan has no attested bridge gateway'
}

assert_candidate_baseline_action()
{
    local candidate=${1:-} candidate_json candidate_network_json expected_policy
    [[ -f $state_file && $(state_value phase) == active ]] \
        || fail 'candidate baseline assertion requires an active runtime fence'
    [[ $candidate == "$candidate_container" ]] \
        || fail 'candidate baseline requested for a name outside the immutable plan'
    assert_common_identity
    candidate_json=$(container_json "$candidate")
    candidate_network_json=$(candidate_network_inventory_json "$candidate")
    assert_candidate_network_plan "$candidate_network_json"
    assert_candidate_static_baseline "$candidate"
    [[ $(jq -r .image_id <<< "$candidate_json") == "$candidate_image_id" \
        && $(jq -r .image_reference <<< "$candidate_json") == "$candidate_image_reference" ]] \
        || fail 'candidate baseline image identity differs from the immutable plan'
    if [[ $(state_value candidate_runtime_sha256) == none ]]; then
        expected_policy=unless-stopped
    else
        expected_policy=$(state_value candidate_restart_policy)
        [[ $(container_runtime_sha256 "$candidate") == "$(state_value candidate_runtime_sha256)" ]] \
            || fail 'candidate baseline differs from its pinned static runtime'
    fi
    [[ $(jq -r .restart_policy <<< "$candidate_json") == "$expected_policy" ]] \
        || fail 'candidate baseline restart policy differs from its authorized state'
    note "candidate_baseline=passed operation_id=$operation_id candidate=$(jq -r .id <<< "$candidate_json") policy=$expected_policy"
}

prove_candidate_denied()
{
    local candidate=${1:-} candidate_id candidate_id_before_proof candidate_json candidate_network_json
    local observed_runtime_sha256 pinned_runtime_sha256 candidate_state
    validate_identifier candidate "$candidate"
    [[ $candidate == "$candidate_container" ]] \
        || fail 'candidate denial requested for a name outside the immutable plan'
    if [[ $(state_value candidate_runtime_sha256) == none ]]; then
        verify >/dev/null
    else
        assert_common_identity
        assert_retired_pool_identity
        assert_network_membership
        run_provider_probe "$(provider_capture_identity)" >/dev/null
        prove_management_unaffected
    fi
    candidate_json=$(container_json "$candidate")
    candidate_id=$(jq -r .id <<< "$candidate_json")
    [[ $candidate_id =~ ^[a-f0-9]{64}$ ]] || fail 'candidate container ID is malformed'
    candidate_id_before_proof=$candidate_id
    candidate_network_json=$(candidate_network_inventory_json "$candidate")
    assert_candidate_network_plan "$candidate_network_json"
    assert_candidate_static_baseline "$candidate"
    [[ $(jq -r .image_reference <<< "$candidate_json") == "$candidate_image_reference" \
        && $(jq -r .image_id <<< "$candidate_json") == "$candidate_image_id" ]] \
        || fail 'candidate image identity differs from the immutable pre-start plan'
    assert_candidate_network_ssh_denied "$candidate_id" "$candidate_network_json"
    candidate_json=$(container_json "$candidate")
    candidate_id=$(jq -r .id <<< "$candidate_json")
    [[ $candidate_id == "$candidate_id_before_proof" ]] \
        || fail 'candidate container ID changed during denial proof'
    candidate_network_json=$(candidate_network_inventory_json "$candidate")
    assert_candidate_network_plan "$candidate_network_json"
    [[ $(jq -r .image_reference <<< "$candidate_json") == "$candidate_image_reference" \
        && $(jq -r .image_id <<< "$candidate_json") == "$candidate_image_id" ]] \
        || fail 'candidate image identity changed during denial proof'
    observed_runtime_sha256=$(container_runtime_sha256 "$candidate")
    pinned_runtime_sha256=$(state_value candidate_runtime_sha256)
    if [[ $pinned_runtime_sha256 != none ]]; then
        [[ $observed_runtime_sha256 == "$pinned_runtime_sha256" ]] \
            || fail 'candidate runtime identity differs from the first denied runtime'
        [[ $(jq -r .restart_policy <<< "$candidate_json") \
            == "$(state_value candidate_restart_policy)" ]] \
            || fail 'candidate restart policy differs from its authorized transition'
    else
        [[ $(jq -r .restart_policy <<< "$candidate_json") == unless-stopped ]] \
            || fail 'candidate must be born with restart policy unless-stopped'
    fi
    if [[ $pinned_runtime_sha256 != none ]]; then
        [[ $(state_value candidate_denial_name) == "$candidate" \
            && $(state_value candidate_denial_id) == "$candidate_id" \
            && $(state_value candidate_denial_image_id) == "$(jq -r .image_id <<< "$candidate_json")" \
            && $(state_value candidate_denial_image_reference) == "$(jq -r .image_reference <<< "$candidate_json")" \
            && $(state_value candidate_denial_started_at) == "$(jq -r .started_at <<< "$candidate_json")" \
            && $(state_value candidate_denial_restart_count) == "$(jq -r .restart_count <<< "$candidate_json")" \
            && $(state_value candidate_denial_networks_b64) == "$(printf '%s' "$candidate_network_json" | encode)" ]] \
            || fail 'candidate denial evidence belongs to a different runtime'
    else
        test_crash after-candidate-denial-before-runtime-pin
        candidate_state=$operation_directory/.state.candidate-denial.$$
        awk -F= -v runtime_sha256="$observed_runtime_sha256" '
            $1 == "candidate_runtime_sha256" { print "candidate_runtime_sha256=" runtime_sha256; next }
            { print }
        ' "$state_file" > "$candidate_state"
        {
            printf 'candidate_denial_name=%s\n' "$candidate"
            printf 'candidate_denial_id=%s\n' "$candidate_id"
            printf 'candidate_denial_image_id=%s\n' "$(jq -r .image_id <<< "$candidate_json")"
            printf 'candidate_denial_image_reference=%s\n' "$(jq -r .image_reference <<< "$candidate_json")"
            printf 'candidate_denial_started_at=%s\n' "$(jq -r .started_at <<< "$candidate_json")"
            printf 'candidate_denial_restart_count=%s\n' "$(jq -r .restart_count <<< "$candidate_json")"
            printf 'candidate_restart_policy=unless-stopped\n'
            printf 'candidate_denial_networks_b64=%s\n' \
                "$(printf '%s' "$candidate_network_json" | encode)"
            printf 'candidate_denial_snapshot_b64=%s\n' \
                "$(printf '%s' "$candidate_json" | encode)"
            printf 'candidate_denial_proven_at_epoch=%s\n' "$(date -u +%s)"
            printf 'candidate_repin_intent_sha256=none\n'
            printf 'candidate_repinned_at_epoch=none\n'
        } >> "$candidate_state"
        atomic_state "$candidate_state"
    fi
    assert_candidate_runtime_state
    note "candidate_denial=passed operation_id=$operation_id candidate_id=$candidate_id"
}

pool_runtime_member_set_sha256_from_values()
{
    local web_b_id=$1 web_b_runtime_sha256=$2 web_b_network_sha256=$3
    local web_b_bindings_sha256=$4
    local web_a_network_sha256=${5:-$(state_value web_a_network_sha256)}
    local web_a_bindings_sha256=${6:-$(state_value web_a_bindings_sha256)} key
    {
        for key in pool_direction pool_color pool_generation pool_plan_manifest_sha256 \
            candidate_denial_name candidate_denial_id candidate_denial_image_reference \
            candidate_denial_image_id candidate_route_identity candidate_runtime_sha256; do
            printf '%s\0' "$(state_value "$key")"
        done
        printf '%s\0' "$web_a_network_sha256" "$web_a_bindings_sha256"
        printf '%s\0' web-b "$pool_web_b_container" "$web_b_id" \
            "$pool_web_b_route_identity" "$pool_web_b_image_reference" \
            "$pool_web_b_image_id" "$web_b_runtime_sha256" \
            "$web_b_network_sha256" "$web_b_bindings_sha256" "$pool_writer_member"
    } | sha256sum | awk '{print $1}'
}

pool_runtime_member_set_sha256_live()
{
    pool_runtime_member_set_sha256_from_values \
        "$(state_value web_b_denial_id)" "$(state_value web_b_runtime_sha256)" \
        "$(state_value web_b_network_sha256)" "$(state_value web_b_bindings_sha256)"
}

prove_web_b_denied()
{
    local web_b_json web_b_id web_b_id_before_proof web_b_network_json
    local web_b_runtime_sha256 web_b_network_sha256 web_b_bindings_sha256
    local web_a_network_sha256 web_a_bindings_sha256
    local member_set_sha256 candidate_state pinned_runtime_sha256
    web_b_json=$(container_json "$pool_web_b_container")
    web_b_id=$(jq -r .id <<< "$web_b_json")
    [[ $web_b_id =~ ^[a-f0-9]{64}$ ]] || fail 'web-b container ID is malformed'
    web_b_id_before_proof=$web_b_id
    web_b_network_json=$(candidate_network_inventory_json "$pool_web_b_container")
    assert_candidate_network_plan "$web_b_network_json" "$pool_web_b_network_ids"
    assert_candidate_static_baseline "$pool_web_b_container"
    [[ $(jq -r .image_reference <<< "$web_b_json") == "$pool_web_b_image_reference" \
        && $(jq -r .image_id <<< "$web_b_json") == "$pool_web_b_image_id" ]] \
        || fail 'web-b image identity differs from the immutable pre-start plan'
    assert_candidate_network_ssh_denied "$web_b_id" "$web_b_network_json"
    web_b_json=$(container_json "$pool_web_b_container")
    web_b_id=$(jq -r .id <<< "$web_b_json")
    [[ $web_b_id == "$web_b_id_before_proof" ]] \
        || fail 'web-b container ID changed during denial proof'
    web_b_network_json=$(candidate_network_inventory_json "$pool_web_b_container")
    assert_candidate_network_plan "$web_b_network_json" "$pool_web_b_network_ids"
    [[ $(jq -r .image_reference <<< "$web_b_json") == "$pool_web_b_image_reference" \
        && $(jq -r .image_id <<< "$web_b_json") == "$pool_web_b_image_id" ]] \
        || fail 'web-b image identity changed during denial proof'
    web_b_runtime_sha256=$(container_runtime_sha256 "$pool_web_b_container")
    pinned_runtime_sha256=$(state_value web_b_runtime_sha256)
    if [[ $pinned_runtime_sha256 != none ]]; then
        [[ $web_b_runtime_sha256 == "$pinned_runtime_sha256" \
            && $(state_value web_b_denial_id) == "$web_b_id" ]] \
            || fail 'web-b runtime identity differs from the first denied runtime'
        return
    fi
    [[ $(jq -r .restart_policy <<< "$web_b_json") == unless-stopped ]] \
        || fail 'web-b must be born with restart policy unless-stopped'
    web_b_network_sha256=$(container_network_sha256 "$pool_web_b_container")
    web_b_bindings_sha256=$(printf '%s' "$web_b_json" \
        | jq -S -c '{configured_bindings, published_bindings}' \
        | sha256sum | awk '{print $1}')
    web_a_network_sha256=$(container_network_sha256 "$candidate_container")
    web_a_bindings_sha256=$(container_bindings_sha256 "$candidate_container")
    member_set_sha256=$(pool_runtime_member_set_sha256_from_values "$web_b_id" \
        "$web_b_runtime_sha256" "$web_b_network_sha256" "$web_b_bindings_sha256" \
        "$web_a_network_sha256" "$web_a_bindings_sha256")
    test_crash after-web-b-denial-before-runtime-pin
    candidate_state=$operation_directory/.state.pool-denial.$$
    awk -F= -v runtime_sha256="$web_b_runtime_sha256" \
        -v member_set_sha256="$member_set_sha256" '
        $1 == "web_b_runtime_sha256" { print "web_b_runtime_sha256=" runtime_sha256; next }
        $1 == "pool_runtime_member_set_sha256" {
            print "pool_runtime_member_set_sha256=" member_set_sha256; next
        }
        { print }
    ' "$state_file" > "$candidate_state"
    {
        printf 'web_a_network_sha256=%s\n' "$web_a_network_sha256"
        printf 'web_a_bindings_sha256=%s\n' "$web_a_bindings_sha256"
        printf 'web_b_network_sha256=%s\n' "$web_b_network_sha256"
        printf 'web_b_bindings_sha256=%s\n' "$web_b_bindings_sha256"
        printf 'web_b_denial_name=%s\nweb_b_denial_id=%s\n' "$pool_web_b_container" "$web_b_id"
        printf 'web_b_denial_image_id=%s\nweb_b_denial_image_reference=%s\n' \
            "$(jq -r .image_id <<< "$web_b_json")" \
            "$(jq -r .image_reference <<< "$web_b_json")"
        printf 'web_b_denial_started_at=%s\nweb_b_denial_restart_count=%s\n' \
            "$(jq -r .started_at <<< "$web_b_json")" "$(jq -r .restart_count <<< "$web_b_json")"
        printf 'web_b_restart_policy=unless-stopped\n'
        printf 'web_b_denial_networks_b64=%s\nweb_b_denial_snapshot_b64=%s\n' \
            "$(printf '%s' "$web_b_network_json" | encode)" \
            "$(printf '%s' "$web_b_json" | encode)"
        printf 'web_b_denial_proven_at_epoch=%s\n' "$(date -u +%s)"
        printf 'web_b_repin_intent_sha256=none\nweb_b_repinned_at_epoch=none\n'
    } >> "$candidate_state"
    atomic_state "$candidate_state"
    test_crash after-pool-denial-state
}

prove_pool_denied()
{
    [[ -f $state_file && $(state_value phase) == active ]] \
        || fail 'pool denial requires an active runtime fence'
    prove_candidate_denied "$candidate_container"
    prove_web_b_denied
    assert_pool_runtime_state
    assert_pool_denial
    note "pool_denial=passed operation_id=$operation_id web_a_id=$(state_value candidate_denial_id) web_b_id=$(state_value web_b_denial_id)"
}

assert_candidate_denial()
{
    local candidate candidate_json candidate_network_json observed_runtime_sha256 pinned_runtime_sha256
    local expected_candidate_json expected_candidate_network_json_value
    assert_candidate_runtime_state
    candidate=$(state_value candidate_denial_name)
    validate_identifier candidate-denial-name "$candidate"
    candidate_json=$(container_json "$candidate")
    candidate_network_json=$(candidate_network_inventory_json "$candidate")
    assert_candidate_static_baseline "$candidate"
    observed_runtime_sha256=$(container_runtime_sha256 "$candidate")
    pinned_runtime_sha256=$(state_value candidate_runtime_sha256)
    assert_candidate_network_plan "$candidate_network_json"
    [[ $observed_runtime_sha256 == "$pinned_runtime_sha256" ]] \
        || fail 'candidate runtime identity differs from the first denied runtime'
    expected_candidate_json=$(expected_container_snapshot candidate)
    expected_candidate_network_json_value=$(expected_candidate_network_json)
    if [[ $expected_candidate_json != none ]]; then
        [[ $candidate_json == "$expected_candidate_json" \
            && $candidate_network_json == "$expected_candidate_network_json_value" \
            && $observed_runtime_sha256 \
                == "$(expected_container_static_sha256 candidate "$pinned_runtime_sha256")" ]] \
            || fail 'candidate differs from the latest verified recovery generation'
        assert_candidate_network_ssh_denied "$(state_value candidate_denial_id)" \
            "$candidate_network_json"
        return
    fi
    [[ $(jq -r .image_reference <<< "$candidate_json") == "$candidate_image_reference" \
        && $(jq -r .image_id <<< "$candidate_json") == "$candidate_image_id" \
        && $(state_value candidate_denial_id) =~ ^[a-f0-9]{64}$ \
        && $(state_value candidate_denial_id) == "$(jq -r .id <<< "$candidate_json")" \
        && $(state_value candidate_denial_image_id) == "$(jq -r .image_id <<< "$candidate_json")" \
        && $(state_value candidate_denial_image_reference) == "$(jq -r .image_reference <<< "$candidate_json")" \
        && $(state_value candidate_denial_started_at) == "$(jq -r .started_at <<< "$candidate_json")" \
        && $(state_value candidate_denial_restart_count) == "$(jq -r .restart_count <<< "$candidate_json")" \
        && $(state_value candidate_restart_policy) == "$(jq -r .restart_policy <<< "$candidate_json")" \
        && $(state_value candidate_denial_networks_b64) == "$(printf '%s' "$candidate_network_json" | encode)" \
        && $(state_value candidate_denial_proven_at_epoch) =~ ^[1-9][0-9]*$ ]] \
        || fail 'candidate SSH-denial evidence is absent or no longer matches the exact candidate'
    assert_candidate_network_ssh_denied "$(state_value candidate_denial_id)" \
        "$candidate_network_json"
}

assert_web_b_denial()
{
    local web_b_json web_b_network_json
    web_b_json=$(container_json "$pool_web_b_container")
    web_b_network_json=$(candidate_network_inventory_json "$pool_web_b_container")
    assert_candidate_network_plan "$web_b_network_json" "$pool_web_b_network_ids"
    assert_candidate_static_baseline "$pool_web_b_container"
    [[ $(jq -r .id <<< "$web_b_json") == "$(state_value web_b_denial_id)" \
        && $(jq -r .image_id <<< "$web_b_json") == "$pool_web_b_image_id" \
        && $(jq -r .image_reference <<< "$web_b_json") == "$pool_web_b_image_reference" \
        && $(jq -r .started_at <<< "$web_b_json") == "$(state_value web_b_denial_started_at)" \
        && $(jq -r .restart_count <<< "$web_b_json") \
            == "$(state_value web_b_denial_restart_count)" \
        && $(jq -r .restart_policy <<< "$web_b_json") == "$(state_value web_b_restart_policy)" \
        && $(printf '%s' "$web_b_network_json" | encode) \
            == "$(state_value web_b_denial_networks_b64)" \
        && $(container_runtime_sha256 "$pool_web_b_container") \
            == "$(state_value web_b_runtime_sha256)" \
        && $(container_network_sha256 "$pool_web_b_container") \
            == "$(state_value web_b_network_sha256)" \
        && $(printf '%s' "$web_b_json" | jq -S -c '{configured_bindings, published_bindings}' \
            | sha256sum | awk '{print $1}') == "$(state_value web_b_bindings_sha256)" ]] \
        || fail 'web-b SSH-denial evidence no longer matches the exact pool member'
    assert_candidate_network_ssh_denied "$(state_value web_b_denial_id)" "$web_b_network_json"
}

assert_pool_denial()
{
    assert_candidate_denial
    assert_web_b_denial
    [[ $(container_network_sha256 "$candidate_container") == "$(state_value web_a_network_sha256)" \
        && $(container_bindings_sha256 "$candidate_container") \
            == "$(state_value web_a_bindings_sha256)" \
        && $(pool_runtime_member_set_sha256_live) \
            == "$(state_value pool_runtime_member_set_sha256)" ]] \
        || fail 'web-a runtime or canonical two-member set hash drifted'
    assert_exact_pool_selector_members
}

assert_candidate_repin_intent()
{
    local expected_id=$1 intent_sha256
    assert_root_path "$candidate_repin_intent_file" 600 candidate-repin-intent
    awk -F= -v operation="$operation_id" -v candidate="$candidate_container" \
        -v candidate_id="$expected_id" -v image_id="$candidate_image_id" \
        -v image_reference="$candidate_image_reference" '
        BEGIN {
            expected[1] = "version=2"
            expected[2] = "operation_id=" operation
            expected[3] = "member_role=web-a"
            expected[4] = "candidate_name=" candidate
            expected[5] = "candidate_id=" candidate_id
            expected[6] = "candidate_image_id=" image_id
            expected[7] = "candidate_image_reference=" image_reference
            expected[8] = "restart_policy=always"
        }
        $0 != expected[NR] { exit 1 }
        END { exit(NR == 8 ? 0 : 1) }
    ' "$candidate_repin_intent_file" \
        || fail 'candidate restart-policy intent is malformed or belongs to another runtime'
    intent_sha256=$(sha256_file "$candidate_repin_intent_file")
    validate_sha256 candidate-repin-intent "$intent_sha256"
    printf '%s\n' "$intent_sha256"
}

repin_candidate()
{
    local expected_id=${1:-} candidate_json candidate_network_json
    local observed_runtime_sha256 intent_sha256 repinned_state candidate_id_after_proof
    [[ -f $state_file && $(state_value phase) == active ]] \
        || fail 'candidate repin requires an active runtime fence'
    [[ $expected_id =~ ^[a-f0-9]{64}$ \
        && $expected_id == "$(state_value candidate_denial_id)" ]] \
        || fail 'candidate repin ID differs from the denied exact runtime'
    assert_common_identity
    intent_sha256=$(assert_candidate_repin_intent "$expected_id")
    candidate_json=$(container_json "$candidate_container")
    candidate_network_json=$(candidate_network_inventory_json "$candidate_container")
    assert_candidate_network_plan "$candidate_network_json"
    assert_candidate_static_baseline "$candidate_container"
    observed_runtime_sha256=$(container_runtime_sha256 "$candidate_container")
    [[ $(jq -r .id <<< "$candidate_json") == "$expected_id" \
        && $(jq -r .image_id <<< "$candidate_json") == "$candidate_image_id" \
        && $(jq -r .image_reference <<< "$candidate_json") == "$candidate_image_reference" \
        && $(jq -r .restart_policy <<< "$candidate_json") == always \
        && $observed_runtime_sha256 == "$(state_value candidate_runtime_sha256)" ]] \
        || fail 'candidate repin changed identity, image, static runtime, or expected policy'
    assert_candidate_network_ssh_denied "$expected_id" "$candidate_network_json"
    test_crash after-candidate-repin-denial
    candidate_json=$(container_json "$candidate_container")
    candidate_id_after_proof=$(jq -r .id <<< "$candidate_json")
    candidate_network_json=$(candidate_network_inventory_json "$candidate_container")
    assert_candidate_network_plan "$candidate_network_json"
    assert_candidate_static_baseline "$candidate_container"
    [[ $candidate_id_after_proof == "$expected_id" \
        && $(jq -r .image_id <<< "$candidate_json") == "$candidate_image_id" \
        && $(jq -r .image_reference <<< "$candidate_json") == "$candidate_image_reference" \
        && $(jq -r .restart_policy <<< "$candidate_json") == always \
        && $(container_runtime_sha256 "$candidate_container") \
            == "$(state_value candidate_runtime_sha256)" ]] \
        || fail 'candidate changed during restart-policy repin denial proof'
    load_runtime_recovery_lineage
    if [[ $latest_runtime_recovery_status =~ ^(runtime-verified|route-verified|verified)$ ]]; then
        assert_candidate_denial
        note "candidate_repin=passed operation_id=$operation_id candidate_id=$expected_id policy=always recovery_generation=$latest_runtime_recovery_generation"
        return
    fi
    repinned_state=$operation_directory/.state.candidate-repin.$$
    awk -F= -v started_at="$(jq -r .started_at <<< "$candidate_json")" \
        -v restart_count="$(jq -r .restart_count <<< "$candidate_json")" \
        -v networks_b64="$(printf '%s' "$candidate_network_json" | encode)" \
        -v snapshot_b64="$(printf '%s' "$candidate_json" | encode)" \
        -v proven_at="$(date -u +%s)" -v intent_sha256="$intent_sha256" '
        $1 == "candidate_denial_started_at" { print "candidate_denial_started_at=" started_at; next }
        $1 == "candidate_denial_restart_count" { print "candidate_denial_restart_count=" restart_count; next }
        $1 == "candidate_restart_policy" { print "candidate_restart_policy=always"; next }
        $1 == "candidate_denial_networks_b64" { print "candidate_denial_networks_b64=" networks_b64; next }
        $1 == "candidate_denial_snapshot_b64" { print "candidate_denial_snapshot_b64=" snapshot_b64; next }
        $1 == "candidate_denial_proven_at_epoch" { print "candidate_denial_proven_at_epoch=" proven_at; next }
        $1 == "candidate_repin_intent_sha256" { print "candidate_repin_intent_sha256=" intent_sha256; next }
        $1 == "candidate_repinned_at_epoch" { print "candidate_repinned_at_epoch=" proven_at; next }
        { print }
    ' "$state_file" > "$repinned_state"
    atomic_state "$repinned_state"
    test_crash after-candidate-repin-state
    assert_candidate_denial
    note "candidate_repin=passed operation_id=$operation_id candidate_id=$expected_id policy=always"
}

assert_web_b_repin_intent()
{
    local expected_id=$1 intent_sha256
    assert_root_path "$pool_web_b_repin_intent_file" 600 web-b-repin-intent
    awk -F= -v operation="$operation_id" -v candidate="$pool_web_b_container" \
        -v candidate_id="$expected_id" -v image_id="$pool_web_b_image_id" \
        -v image_reference="$pool_web_b_image_reference" '
        BEGIN {
            expected[1] = "version=2"
            expected[2] = "operation_id=" operation
            expected[3] = "member_role=web-b"
            expected[4] = "candidate_name=" candidate
            expected[5] = "candidate_id=" candidate_id
            expected[6] = "candidate_image_id=" image_id
            expected[7] = "candidate_image_reference=" image_reference
            expected[8] = "restart_policy=always"
        }
        $0 != expected[NR] { exit 1 }
        END { exit(NR == 8 ? 0 : 1) }
    ' "$pool_web_b_repin_intent_file" \
        || fail 'web-b restart-policy intent is malformed or belongs to another runtime'
    intent_sha256=$(sha256_file "$pool_web_b_repin_intent_file")
    validate_sha256 web-b-repin-intent "$intent_sha256"
    printf '%s\n' "$intent_sha256"
}

repin_web_b()
{
    local expected_id=$1 web_b_json web_b_network_json intent_sha256 repinned_state proven_at
    [[ $expected_id =~ ^[a-f0-9]{64}$ \
        && $expected_id == "$(state_value web_b_denial_id)" ]] \
        || fail 'web-b repin ID differs from the denied exact runtime'
    intent_sha256=$(assert_web_b_repin_intent "$expected_id")
    web_b_json=$(container_json "$pool_web_b_container")
    web_b_network_json=$(candidate_network_inventory_json "$pool_web_b_container")
    assert_candidate_network_plan "$web_b_network_json" "$pool_web_b_network_ids"
    assert_candidate_static_baseline "$pool_web_b_container"
    [[ $(jq -r .id <<< "$web_b_json") == "$expected_id" \
        && $(jq -r .image_id <<< "$web_b_json") == "$pool_web_b_image_id" \
        && $(jq -r .image_reference <<< "$web_b_json") == "$pool_web_b_image_reference" \
        && $(jq -r .restart_policy <<< "$web_b_json") == always \
        && $(container_runtime_sha256 "$pool_web_b_container") \
            == "$(state_value web_b_runtime_sha256)" ]] \
        || fail 'web-b repin changed identity, image, static runtime, or expected policy'
    assert_candidate_network_ssh_denied "$expected_id" "$web_b_network_json"
    proven_at=$(date -u +%s)
    repinned_state=$operation_directory/.state.web-b-repin.$$
    awk -F= -v started_at="$(jq -r .started_at <<< "$web_b_json")" \
        -v restart_count="$(jq -r .restart_count <<< "$web_b_json")" \
        -v networks_b64="$(printf '%s' "$web_b_network_json" | encode)" \
        -v snapshot_b64="$(printf '%s' "$web_b_json" | encode)" \
        -v proven_at="$proven_at" -v intent_sha256="$intent_sha256" '
        $1 == "web_b_denial_started_at" { print "web_b_denial_started_at=" started_at; next }
        $1 == "web_b_denial_restart_count" { print "web_b_denial_restart_count=" restart_count; next }
        $1 == "web_b_restart_policy" { print "web_b_restart_policy=always"; next }
        $1 == "web_b_denial_networks_b64" { print "web_b_denial_networks_b64=" networks_b64; next }
        $1 == "web_b_denial_snapshot_b64" { print "web_b_denial_snapshot_b64=" snapshot_b64; next }
        $1 == "web_b_denial_proven_at_epoch" { print "web_b_denial_proven_at_epoch=" proven_at; next }
        $1 == "web_b_repin_intent_sha256" { print "web_b_repin_intent_sha256=" intent_sha256; next }
        $1 == "web_b_repinned_at_epoch" { print "web_b_repinned_at_epoch=" proven_at; next }
        { print }
    ' "$state_file" > "$repinned_state"
    atomic_state "$repinned_state"
    test_crash after-web-b-repin-state
}

repin_pool()
{
    local web_a_id=${1:-} web_b_id=${2:-}
    [[ $# -eq 2 ]] || fail 'pool repin requires exact web-a and web-b IDs'
    [[ -f $state_file && $(state_value phase) == active ]] \
        || fail 'pool repin requires an active runtime fence'
    assert_common_identity
    repin_candidate "$web_a_id"
    repin_web_b "$web_b_id"
    assert_pool_denial
    [[ $(state_value candidate_restart_policy) == always \
        && $(state_value web_b_restart_policy) == always ]] \
        || fail 'pool repin did not authorize both exact members'
    note "pool_repin=passed operation_id=$operation_id web_a_id=$web_a_id web_b_id=$web_b_id policy=always"
}

assert_pool_baseline_action()
{
    [[ -f $state_file && $(state_value phase) == active ]] \
        || fail 'pool baseline requires an active runtime fence'
    assert_common_identity
    if [[ $(state_value candidate_runtime_sha256) == none \
        && $(state_value web_b_runtime_sha256) == none ]]; then
        assert_unproven_pool_baseline
    else
        assert_pool_denial
    fi
    note "pool_baseline=passed operation_id=$operation_id member_count=2"
}

run_release_probe()
{
    local kind=$1 path=$2 checksum=$3 output
    output=$operation_directory/.release-$kind.$$
    assert_probe_file "$path" "$checksum" "$kind-probe"
    if [[ $kind == queue ]]; then
        CONTROL_PLANE_RUNTIME_OPERATION_ID=$operation_id \
        CONTROL_PLANE_RUNTIME_QUEUE_CONTAINER=$pool_writer_container \
            "$path" > "$output"
    else
        CONTROL_PLANE_RUNTIME_OPERATION_ID=$operation_id \
        CONTROL_PLANE_RUNTIME_PROXY_ID=$(state_value proxy_id) \
        CONTROL_PLANE_RUNTIME_LEGACY_ID=$(state_value legacy_id) \
        CONTROL_PLANE_RUNTIME_PROVIDER_EXPECTATION=absent \
        CONTROL_PLANE_RUNTIME_RETIRED_MEMBER_COUNT=$pool_retired_member_count \
        CONTROL_PLANE_RUNTIME_RETIRED_A_NAME=$legacy_container \
        CONTROL_PLANE_RUNTIME_RETIRED_A_ID=$pool_retired_member_a_id \
        CONTROL_PLANE_RUNTIME_RETIRED_B_NAME=$(runtime_recovery_role_name retired_b) \
        CONTROL_PLANE_RUNTIME_RETIRED_B_ID=$(runtime_recovery_role_id retired_b) \
        CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST=$pool_plan_manifest \
        CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256=$pool_plan_manifest_sha256 \
        CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_METADATA=$pool_plan_manifest_metadata \
        CONTROL_PLANE_INGRESS_POOL_MANIFEST=$ingress_pool_manifest \
        CONTROL_PLANE_INGRESS_POOL_MANIFEST_SHA256=$(state_value ingress_pool_manifest_sha256) \
        CONTROL_PLANE_INGRESS_POOL_MANIFEST_METADATA=$(state_value ingress_pool_manifest_metadata) \
        CONTROL_PLANE_RUNTIME_CONTROLLER_PATH=$controller_path \
        CONTROL_PLANE_RUNTIME_CONTROLLER_SHA256=$(sha256_file "$controller_path") \
        CONTROL_PLANE_RUNTIME_QUEUE_PROBE=$queue_probe \
        CONTROL_PLANE_RUNTIME_QUEUE_PROBE_SHA256=$queue_probe_sha256 \
        CONTROL_PLANE_RUNTIME_SEMANTIC_CONFIG_SHA256=$semantic_config_sha256 \
        CONTROL_PLANE_RUNTIME_PROBE_MAX_AGE_SECONDS=$probe_max_age_seconds \
            "$path" > "$output"
    fi
    case "$kind" in
        queue)
            assert_probe_shape "$output" \
                '^(version=2|pending=0|reserved=0|delayed=0|running=0|observed_at_epoch=[1-9][0-9]*|operation_id=[A-Za-z0-9_.-]+)$' queue-probe
            [[ $(wc -l < "$output") -eq 7 && $(probe_value version "$output") == 2 \
                && $(probe_value pending "$output") == 0 && $(probe_value reserved "$output") == 0 \
                && $(probe_value delayed "$output") == 0 && $(probe_value running "$output") == 0 \
                && $(probe_value operation_id "$output") == "$operation_id" ]] \
                || fail 'proxy queue probe was not exactly zero'
            ;;
        terminal)
            assert_probe_shape "$output" \
                '^(version=3|terminal_state=passed|observed_at_epoch=[1-9][0-9]*|operation_id=[A-Za-z0-9_.-]+|semantic_config_sha256=[a-f0-9]{64}|active_web_a_id=[a-f0-9]{64}|active_web_b_id=[a-f0-9]{64}|pool_manifest_sha256=[a-f0-9]{64}|pool_plan_manifest_sha256=[a-f0-9]{64}|pool_generation=[1-9][0-9]*|pool_member_set_sha256=[a-f0-9]{64}|https_pool_ack_sha256=[a-f0-9]{64}|local_ingress_pool_ack_sha256=[a-f0-9]{64}|retired_incumbent_set=absent|provider_fresh=passed|provider_snapshot_sha256=[a-f0-9]{64}|queue_zero=passed|queue_observed_at_epoch=[1-9][0-9]*|queue_probe_sha256=[a-f0-9]{64}|queue_evidence_sha256=[a-f0-9]{64}|queue_pending=0|queue_reserved=0|queue_delayed=0|queue_running=0|mutation_freeze_epoch=[A-Za-z0-9._:-]{16,128})$' terminal-probe
            [[ $(wc -l < "$output") -eq 25 && $(probe_value version "$output") == 3 \
                && $(probe_value terminal_state "$output") == passed \
                && $(probe_value operation_id "$output") == "$operation_id" \
                && $(probe_value semantic_config_sha256 "$output") \
                    == "$semantic_config_sha256" \
                && $(probe_value active_web_a_id "$output") == "$(state_value candidate_denial_id)" \
                && $(probe_value active_web_b_id "$output") == "$(state_value web_b_denial_id)" \
                && $(probe_value pool_manifest_sha256 "$output") \
                    == "$(state_value ingress_pool_manifest_sha256)" \
                && $(probe_value pool_plan_manifest_sha256 "$output") \
                    == "$pool_plan_manifest_sha256" \
                && $(probe_value pool_generation "$output") == "$pool_generation" \
                && $(probe_value pool_member_set_sha256 "$output") \
                    == "$(ingress_value member_set_sha256)" \
                && $(probe_value https_pool_ack_sha256 "$output") \
                    == "$(manifest_value pool_ack_sha256)" \
                && $(probe_value local_ingress_pool_ack_sha256 "$output") \
                    == "$(manifest_value pool_ack_sha256)" \
                && $(probe_value retired_incumbent_set "$output") == absent \
                && $(probe_value provider_fresh "$output") == passed \
                && $(probe_value queue_zero "$output") == passed \
                && $(probe_value queue_probe_sha256 "$output") == "$queue_probe_sha256" \
                && $(probe_value queue_pending "$output") == 0 \
                && $(probe_value queue_reserved "$output") == 0 \
                && $(probe_value queue_delayed "$output") == 0 \
                && $(probe_value queue_running "$output") == 0 \
                && $(probe_value mutation_freeze_epoch "$output") \
                    == "$(manifest_value mutation_freeze_epoch)" ]] \
                || fail 'terminal-state probe did not provide the exact release attestation'
            fresh_epoch "$(probe_value queue_observed_at_epoch "$output")"
            validate_sha256 terminal-queue-evidence \
                "$(probe_value queue_evidence_sha256 "$output")"
            ;;
    esac
    fresh_epoch "$(probe_value observed_at_epoch "$output")"
    sha256_file "$output"
}

retired_pool_is_absent()
{
    inspect_docker_container "$legacy_container"
    [[ $docker_api_state == absent ]] || return 1
    if container_id_is_listed "$(state_value legacy_id)"; then
        return 1
    fi
    if [[ $pool_retired_member_count == 2 ]]; then
        inspect_docker_container "$pool_retired_member_b_name"
        [[ $docker_api_state == absent ]] || return 1
        if container_id_is_listed "$pool_retired_member_b_id"; then
            return 1
        fi
    fi
    return 0
}

prove_watchdog_responsive()
{
    [[ $test_mode == 0 ]] || return 0
    local service=coolify-runtime-attestation-ssh-fence-watchdog.service
    local challenge=$state_directory/watchdog.challenge
    local response=$state_directory/watchdog.response
    local candidate=$state_directory/.watchdog.challenge.$$
    local watchdog_pid watchdog_start nonce response_epoch now

    systemctl is-active --quiet "$service" \
        || fail 'runtime fence watchdog must be active before terminal fence removal'
    watchdog_pid=$(systemctl show "$service" --property MainPID --value)
    [[ $watchdog_pid =~ ^[1-9][0-9]*$ ]] \
        || fail 'runtime fence watchdog has no live MainPID'
    watchdog_start=$(process_start_time "$watchdog_pid")
    if [[ -n ${pinned_watchdog_pid:-} ]]; then
        [[ $watchdog_pid == "$pinned_watchdog_pid" \
            && $watchdog_start == "$pinned_watchdog_start_time" ]] \
            || fail 'runtime fence watchdog process changed during terminal authorization'
    else
        pinned_watchdog_pid=$watchdog_pid
        pinned_watchdog_start_time=$watchdog_start
    fi
    if [[ -e $response || -L $response ]]; then
        assert_root_path "$response" 600 watchdog-response
        rm -f -- "$response"
    fi
    nonce=$(printf '%s:%s:%s:%s\n' "$operation_id" "$$" "$(date -u +%s%N)" "$RANDOM" \
        | sha256sum | awk '{print $1}')
    {
        printf 'version=1\nnonce=%s\n' "$nonce"
        printf 'operation_id=%s\nsemantic_config_sha256=%s\n' \
            "$operation_id" "$semantic_config_sha256"
        printf 'controller_sha256=%s\nrequested_at_epoch=%s\n' \
            "$(sha256_file "$controller_path")" "$(date -u +%s)"
    } > "$candidate"
    chmod 0600 "$candidate"
    sync "$candidate"
    mv -f -- "$candidate" "$challenge"
    sync "$state_directory"
    for _ in {1..20}; do
        [[ -f $response && ! -L $response ]] && break
        sleep 0.25
    done
    assert_root_path "$response" 600 watchdog-response
    [[ $(wc -l < "$response") -eq 8 \
        && $(grep -E -c '^(version=1|nonce=[a-f0-9]{64}|operation_id=[A-Za-z0-9_.-]+|semantic_config_sha256=[a-f0-9]{64}|controller_sha256=[a-f0-9]{64}|pid=[1-9][0-9]*|process_start_time=[1-9][0-9]*|responded_at_epoch=[1-9][0-9]*)$' "$response") -eq 8 \
        && $(sed -n 's/^nonce=//p' "$response") == "$nonce" \
        && $(sed -n 's/^operation_id=//p' "$response") == "$operation_id" \
        && $(sed -n 's/^semantic_config_sha256=//p' "$response") == "$semantic_config_sha256" \
        && $(sed -n 's/^controller_sha256=//p' "$response") == "$(sha256_file "$controller_path")" \
        && $(sed -n 's/^pid=//p' "$response") == "$pinned_watchdog_pid" \
        && $(sed -n 's/^process_start_time=//p' "$response") == "$pinned_watchdog_start_time" ]] \
        || fail 'runtime fence watchdog response has the wrong process or immutable identity'
    response_epoch=$(sed -n 's/^responded_at_epoch=//p' "$response")
    now=$(date -u +%s)
    ((response_epoch <= now + 1 && now - response_epoch <= 5)) \
        || fail 'runtime fence watchdog response is stale'
    rm -f -- "$challenge" "$response"
}

pool_coordination_source()
{
    local inspect_json coordination_volume source
    inspect_json=$(require_docker_container_json "$pool_writer_container")
    coordination_volume=$(manifest_value coordination_volume)
    source=$(jq -e -r --arg volume "$coordination_volume" '
        [.[0].Mounts[]? | select(.Type == "volume" and .Name == $volume
            and .Destination == "/var/lib/coolify-control-plane/coordination")] as $mount
        | select(($mount | length) == 1) | $mount[0].Source
    ' <<< "$inspect_json") || fail 'writer coordination volume has no exact mounted source'
    [[ $source == /* && -d $source && ! -L $source && $(readlink -f -- "$source") == "$source" ]] \
        || fail 'writer coordination volume source is unsafe'
    printf '%s\n' "$source"
}

mutation_authority_host_path()
{
    local manifest_key=$1 configured source relative
    configured=$(manifest_value "$manifest_key")
    [[ $configured == /var/lib/coolify-control-plane/coordination/* ]] \
        || fail "$manifest_key is outside the writer coordination volume"
    source=$(pool_coordination_source)
    relative=${configured#/var/lib/coolify-control-plane/coordination/}
    printf '%s/%s\n' "$source" "$relative"
}

assert_mutation_freeze_under_exclusive_lease()
{
    local marker lease expected_uid expected_gid lease_identity descriptor_path fd_identity
    local marker_snapshot marker_epoch marker_size
    marker=$(mutation_authority_host_path mutation_freeze_marker_path)
    lease=$(mutation_authority_host_path mutation_lease_path)
    if [[ $test_mode == 1 ]]; then
        expected_uid=$immutable_uid
        expected_gid=$immutable_gid
    else
        expected_uid=0
        expected_gid=9999
    fi
    validate_absolute_path mutation-freeze-marker-path "$marker"
    validate_absolute_path mutation-lease-path "$lease"
    [[ -f $lease && ! -L $lease && $(readlink -f -- "$lease") == "$lease" ]] \
        || fail 'mutation lease is not a regular canonical non-symlink file'
    lease_identity=$(stat -c '%d:%i:%f:%u:%g:%a:%h:%s' -- "$lease") \
        || fail 'mutation lease identity cannot be read'
    [[ $lease_identity == *":$expected_uid:$expected_gid:660:1:0" ]] \
        || fail 'mutation lease has unsafe ownership, mode, link count, or size'
    if [[ -z ${mutation_lease_fd:-} ]]; then
        exec {mutation_lease_fd}<>"$lease" || fail 'mutation lease could not be opened'
        descriptor_path=/proc/self/fd/$mutation_lease_fd
        [[ -e $descriptor_path ]] || descriptor_path=/dev/fd/$mutation_lease_fd
        fd_identity=$(stat -Lc '%d:%i:%f:%u:%g:%a:%h:%s' -- "$descriptor_path") \
            || fail 'mutation lease descriptor identity cannot be read'
        if [[ $test_mode == 1 && $(uname -s) == Darwin ]]; then
            [[ $(awk -F: '{ print $2 ":" $4 ":" $5 ":" $7 ":" $8 }' \
                <<< "$fd_identity") == "$(awk -F: '{ print $2 ":" $4 ":" $5 ":" $7 ":" $8 }' \
                <<< "$lease_identity")" ]] \
                || fail 'mutation lease changed between path attestation and descriptor open'
        else
            [[ $fd_identity == "$lease_identity" ]] \
                || fail 'mutation lease changed between path attestation and descriptor open'
        fi
        flock -x -w "$probe_max_age_seconds" "$mutation_lease_fd" \
            || fail 'mutation lease could not be acquired exclusively'
    fi
    descriptor_path=/proc/self/fd/$mutation_lease_fd
    [[ -e $descriptor_path ]] || descriptor_path=/dev/fd/$mutation_lease_fd
    fd_identity=$(stat -Lc '%d:%i:%f:%u:%g:%a:%h:%s' -- "$descriptor_path") \
        || fail 'held mutation lease descriptor identity cannot be read'
    if [[ $test_mode == 1 && $(uname -s) == Darwin ]]; then
        [[ $(awk -F: '{ print $2 ":" $4 ":" $5 ":" $7 ":" $8 }' \
            <<< "$fd_identity") == "$(awk -F: '{ print $2 ":" $4 ":" $5 ":" $7 ":" $8 }' \
            <<< "$lease_identity")" ]] \
            || fail 'held mutation lease no longer matches its canonical path identity'
    else
        [[ $fd_identity == "$lease_identity" ]] \
            || fail 'held mutation lease no longer matches its canonical path identity'
    fi
    [[ $(stat -c '%d:%i:%f:%u:%g:%a:%h:%s' -- "$lease") == "$lease_identity" ]] \
        || fail 'mutation lease path changed while held exclusively'
    safe_snapshot_file "$marker" '' '' "$expected_uid" "$expected_gid" 440 mutation-freeze-marker
    marker_snapshot=$SAFE_SNAPSHOT_PATH
    marker_epoch=$(<"$marker_snapshot")
    marker_size=$(wc -c < "$marker_snapshot" | tr -d '[:space:]')
    [[ $marker_epoch == "$(manifest_value mutation_freeze_epoch)" \
        && $marker_size == "${#marker_epoch}" ]] \
        || fail 'durable mutation-freeze marker differs from its operation epoch'
    [[ $(stat -c '%d:%i:%f:%u:%g:%a:%h:%s' -- "$lease") == "$lease_identity" ]] \
        || fail 'mutation lease changed during freeze verification'
}

assert_release_authorization()
{
    local final_queue_sha256
    validate_sha256 release-provider "$(state_value release_provider_sha256)"
    validate_sha256 release-queue "$(state_value release_queue_sha256)"
    validate_sha256 release-terminal "$(state_value release_terminal_sha256)"
    final_queue_sha256=$(state_value release_final_queue_sha256)
    [[ $final_queue_sha256 == pending || $final_queue_sha256 =~ ^[a-f0-9]{64}$ ]] \
        || fail 'final release queue evidence is malformed'
    if [[ $(state_value phase) == released ]]; then
        validate_sha256 final-release-queue "$final_queue_sha256"
    fi
    [[ $(state_value release_operation_id) == "$operation_id" \
        && $(state_value release_semantic_config_sha256) == "$semantic_config_sha256" \
        && $(state_value release_files_sha256) == "$release_files_sha256" \
        && $(state_value release_candidate_runtime_sha256) \
            == "$(state_value candidate_runtime_sha256)" \
        && $(state_value release_web_b_runtime_sha256) \
            == "$(state_value web_b_runtime_sha256)" \
        && $(state_value release_pool_runtime_member_set_sha256) \
            == "$(state_value pool_runtime_member_set_sha256)" \
        && $(state_value release_ingress_pool_manifest_sha256) \
            == "$(state_value ingress_pool_manifest_sha256)" \
        && $(state_value release_ingress_member_set_sha256) \
            == "$(ingress_value member_set_sha256)" \
        && $(state_value release_authorized_at_epoch) =~ ^[1-9][0-9]*$ ]] \
        || fail 'release authorization belongs to different operation, configuration, or runtime evidence'
}

assert_released_candidate_identity()
{
    local candidate_json candidate_network_json expected_candidate_json expected_candidate_network
    assert_candidate_runtime_state
    candidate_json=$(container_json "$candidate_container")
    candidate_network_json=$(candidate_network_inventory_json "$candidate_container")
    assert_candidate_network_plan "$candidate_network_json"
    assert_candidate_static_baseline "$candidate_container"
    expected_candidate_json=$(expected_container_snapshot candidate)
    expected_candidate_network=$(expected_candidate_network_json)
    if [[ $expected_candidate_json != none ]]; then
        [[ $candidate_json == "$expected_candidate_json" \
            && $candidate_network_json == "$expected_candidate_network" \
            && $(container_runtime_sha256 "$candidate_container") \
                == "$(expected_container_static_sha256 candidate \
                    "$(state_value candidate_runtime_sha256)")" ]] \
            || fail 'released candidate differs from the latest verified recovery generation'
        return
    fi
    [[ $(jq -r .id <<< "$candidate_json") == "$(state_value candidate_denial_id)" \
        && $(jq -r .image_id <<< "$candidate_json") == "$candidate_image_id" \
        && $(jq -r .image_reference <<< "$candidate_json") == "$candidate_image_reference" \
        && $(jq -r .started_at <<< "$candidate_json") \
            == "$(state_value candidate_denial_started_at)" \
        && $(jq -r .restart_count <<< "$candidate_json") \
            == "$(state_value candidate_denial_restart_count)" \
        && $(jq -r .restart_policy <<< "$candidate_json") \
            == "$(state_value candidate_restart_policy)" \
        && $(printf '%s' "$candidate_network_json" | encode) \
            == "$(state_value candidate_denial_networks_b64)" \
        && $(container_runtime_sha256 "$candidate_container") \
            == "$(state_value candidate_runtime_sha256)" ]] \
        || fail 'released candidate differs from its pinned runtime and denial evidence'
}

assert_released_web_b_identity()
{
    local web_b_json web_b_network_json
    assert_pool_runtime_state
    web_b_json=$(container_json "$pool_web_b_container")
    web_b_network_json=$(candidate_network_inventory_json "$pool_web_b_container")
    assert_candidate_network_plan "$web_b_network_json" "$pool_web_b_network_ids"
    assert_candidate_static_baseline "$pool_web_b_container"
    [[ $(jq -r .id <<< "$web_b_json") == "$(state_value web_b_denial_id)" \
        && $(jq -r .image_id <<< "$web_b_json") == "$pool_web_b_image_id" \
        && $(jq -r .image_reference <<< "$web_b_json") == "$pool_web_b_image_reference" \
        && $(jq -r .started_at <<< "$web_b_json") == "$(state_value web_b_denial_started_at)" \
        && $(jq -r .restart_count <<< "$web_b_json") \
            == "$(state_value web_b_denial_restart_count)" \
        && $(jq -r .restart_policy <<< "$web_b_json") == "$(state_value web_b_restart_policy)" \
        && $(printf '%s' "$web_b_network_json" | encode) \
            == "$(state_value web_b_denial_networks_b64)" \
        && $(container_runtime_sha256 "$pool_web_b_container") \
            == "$(state_value web_b_runtime_sha256)" \
        && $(container_network_sha256 "$pool_web_b_container") \
            == "$(state_value web_b_network_sha256)" \
        && $(container_bindings_sha256 "$pool_web_b_container") \
            == "$(state_value web_b_bindings_sha256)" ]] \
        || fail 'released web-b differs from its pinned runtime evidence'
}

assert_released_pool_identity()
{
    assert_released_candidate_identity
    assert_released_web_b_identity
    [[ $(container_network_sha256 "$candidate_container") == "$(state_value web_a_network_sha256)" \
        && $(container_bindings_sha256 "$candidate_container") \
            == "$(state_value web_a_bindings_sha256)" \
        && $(pool_runtime_member_set_sha256_live) \
            == "$(state_value pool_runtime_member_set_sha256)" ]] \
        || fail 'released web-a or exact two-member set differs from its pinned evidence'
    assert_exact_pool_selector_members
}

verify_released_terminal()
{
    [[ -f $state_file && $(state_value phase) == released ]] \
        || fail 'released terminal runtime fence state is absent'
    assert_restore_artifacts
    table_exists && fail 'released terminal state unexpectedly has a managed nft table'
    assert_release_authorization
    [[ $(state_value released_at_epoch) =~ ^[1-9][0-9]*$ \
        && $(state_value released_at_epoch) -ge $(state_value release_authorized_at_epoch) ]] \
        || fail 'released terminal timestamps are malformed or reversed'
    retired_pool_is_absent || fail 'released terminal state retained the revoked retired pool'
    assert_released_pool_identity
    run_provider_probe absent >/dev/null
    run_release_probe queue "$queue_probe" "$queue_probe_sha256" >/dev/null
    run_release_probe terminal "$terminal_probe" "$terminal_probe_sha256" >/dev/null
    note "verify_released=passed operation_id=$operation_id phase=released"
}

release()
{
    local candidate queue_hash terminal_hash provider_hash final_queue_hash phase
    [[ -f $state_file ]] || fail 'runtime fence state is absent'
    phase=$(state_value phase)
    if [[ $phase == released ]]; then
        verify_released_terminal
        note "release=passed operation_id=$operation_id phase=released already_terminal=true"
        return
    fi
    [[ $phase == active || $phase == release-in-progress ]] \
        || fail 'active or interrupted release fence state is absent'
    if [[ $phase == active ]]; then
        prove_watchdog_responsive
        assert_common_identity
        retired_pool_is_absent || fail 'retired container name or exact ID still exists'
        [[ $(state_value ingress_pool_manifest_sha256) != pending ]] \
            || fail 'release requires a pinned ingress pool manifest'
        validate_ingress_pool_manifest "$(state_value ingress_pool_manifest_sha256)"
        assert_pool_denial
        [[ $(state_value candidate_restart_policy) == always \
            && $(state_value web_b_restart_policy) == always ]] \
            || fail 'release requires both exact pool members to be restart-policy repinned'
        assert_mutation_freeze_under_exclusive_lease
        provider_hash=$(run_provider_probe absent)
        queue_hash=$(run_release_probe queue "$queue_probe" "$queue_probe_sha256")
        terminal_hash=$(run_release_probe terminal "$terminal_probe" "$terminal_probe_sha256")
        candidate=$operation_directory/.state.release.$$
        sed 's/^phase=active$/phase=release-in-progress/' "$state_file" > "$candidate"
        {
            printf 'release_provider_sha256=%s\n' "$provider_hash"
            printf 'release_queue_sha256=%s\n' "$queue_hash"
            printf 'release_terminal_sha256=%s\n' "$terminal_hash"
            printf 'release_final_queue_sha256=pending\n'
            printf 'release_operation_id=%s\n' "$operation_id"
            printf 'release_semantic_config_sha256=%s\n' "$semantic_config_sha256"
            printf 'release_candidate_runtime_sha256=%s\n' \
                "$(state_value candidate_runtime_sha256)"
            printf 'release_web_b_runtime_sha256=%s\n' \
                "$(state_value web_b_runtime_sha256)"
            printf 'release_pool_runtime_member_set_sha256=%s\n' \
                "$(state_value pool_runtime_member_set_sha256)"
            printf 'release_ingress_pool_manifest_sha256=%s\n' \
                "$(state_value ingress_pool_manifest_sha256)"
            printf 'release_ingress_member_set_sha256=%s\n' \
                "$(ingress_value member_set_sha256)"
            printf 'release_authorized_at_epoch=%s\n' "$(date -u +%s)"
        } >> "$candidate"
        atomic_state "$candidate"
        test_crash after-release-state
    else
        assert_restore_artifacts
        assert_release_authorization
        assert_mutation_freeze_under_exclusive_lease
    fi
    if table_exists; then
        prove_watchdog_responsive
        assert_common_identity
        retired_pool_is_absent || fail 'retired container name or exact ID reappeared before fence removal'
        assert_pool_denial
        run_provider_probe absent >/dev/null
        sleep "$queue_stable_seconds"
        run_release_probe terminal "$terminal_probe" "$terminal_probe_sha256" >/dev/null
        assert_mutation_freeze_under_exclusive_lease
        final_queue_hash=$(run_release_probe queue "$queue_probe" "$queue_probe_sha256")
        assert_fence_identity
        nft delete table "$TABLE_FAMILY" "$TABLE_NAME"
        test_crash after-nft-delete
    else
        retired_pool_is_absent || fail 'interrupted fence release retained the revoked retired pool'
        assert_released_pool_identity
        run_provider_probe absent >/dev/null
        run_release_probe terminal "$terminal_probe" "$terminal_probe_sha256" >/dev/null
        assert_mutation_freeze_under_exclusive_lease
        final_queue_hash=$(run_release_probe queue "$queue_probe" "$queue_probe_sha256")
    fi
    mark_released "$final_queue_hash"
    verify_released_terminal
    note "release=passed operation_id=$operation_id phase=released"
}

assert_recorded_runtime_recovery_observation()
{
    local file=$1 provider_expectation=$2 inventory=$operation_directory/.runtime-recovery-check.$$
    local requested_network_ids current_network current_snapshot expected_snapshot role container
    for role in boot_id dockerd_pid dockerd_invocation_id docker_daemon_id docker_socket; do
        [[ $(runtime_value "$role") == "$(runtime_recovery_value "$file" "new_$role")" ]] \
            || fail "runtime-recovery live tuple changed after intent: $role"
    done
    if [[ $provider_expectation == absent ]]; then
        retired_pool_is_absent || fail 'retired pool reappeared after runtime-recovery intent'
    fi
    for role in $RUNTIME_RECOVERY_ROLES; do
        expected_snapshot=$(decode_runtime_recovery_value "$file" "new_${role}_snapshot_b64")
        if runtime_recovery_role_is_present "$role" "$provider_expectation"; then
            container=$(runtime_recovery_role_name "$role")
            current_snapshot=$(container_json "$container")
            [[ $current_snapshot == "$expected_snapshot" \
                && $(container_runtime_sha256 "$container") \
                    == "$(runtime_recovery_value "$file" \
                        "new_${role}_static_sha256")" ]] \
                || fail "$role changed after runtime-recovery intent"
            if [[ $role == web_a || $role == web_b ]]; then
                [[ $(jq -r .restart_policy <<< "$current_snapshot") == always ]] \
                    || fail "$role restart policy changed after runtime-recovery intent"
            fi
        else
            [[ $expected_snapshot == '{"presence":"absent"}' ]] \
                || fail "runtime-recovery $role absence snapshot is malformed"
        fi
    done
    for role in web_a web_b; do
        container=$(runtime_recovery_role_name "$role")
        current_snapshot=$(candidate_network_inventory_json "$container")
        [[ $current_snapshot \
                == "$(decode_runtime_recovery_value "$file" "new_${role}_network_b64")" ]] \
            || fail "$role network changed after runtime-recovery intent"
    done
    requested_network_ids=$(runtime_recovery_network_ids "$provider_expectation" | LC_ALL=C sort -u)
    capture_network_inventory "$inventory" "$requested_network_ids"
    current_network=$(semantic_network_inventory "$inventory")
    rm -f -- "$inventory"
    [[ $current_network == "$(decode_runtime_recovery_value "$file" new_network_inventory_b64)" ]] \
        || fail 'Docker network semantics changed after runtime-recovery intent'
}

runtime_recovery_provider_identity()
{
    local file=$1 provider_expectation=$2
    [[ $(runtime_recovery_value "$file" provider_expectation) == "$provider_expectation" ]] \
        || fail 'runtime-recovery provider expectation changed after intent'
    printf '%s\n' "$provider_expectation"
}

prove_runtime_recovery_phase()
{
    local file=$1 phase_class=$2 fence_mode=$3 provider_expectation=$4
    local candidate_snapshot candidate_network provider_identity
    assert_recorded_runtime_recovery_observation "$file" "$provider_expectation"
    provider_identity=$(runtime_recovery_provider_identity "$file" "$provider_expectation")
    recovery_proof_provider_sha256=$(run_provider_probe "$provider_identity")
    recovery_proof_queue_sha256=none
    recovery_proof_terminal_sha256=none
    if [[ $fence_mode == active ]]; then
        [[ $(state_value phase) == active && $armed == 1 ]] \
            || fail 'active daemon recovery requires an armed active runtime fence'
        assert_restore_artifacts
        if [[ $test_mode == 0 ]]; then
            systemctl is-active --quiet "$RESTORE_SERVICE" "$WATCHDOG_SERVICE" \
                || fail 'runtime fence restore/watchdog services are not active during recovery'
        fi
        prove_watchdog_responsive
        assert_fence_identity
        prove_management_unaffected
        candidate_snapshot=$(decode_runtime_recovery_value "$file" new_web_a_snapshot_b64)
        candidate_network=$(decode_runtime_recovery_value "$file" new_web_a_network_b64)
        assert_candidate_network_ssh_denied "$(jq -r .id <<< "$candidate_snapshot")" \
            "$candidate_network"
        recovery_fence_proof_sha256=$(printf '%s\n%s\n%s\n%s\n' \
            "$(state_value systemd_contract_sha256)" "$(state_value nft_identity)" \
            "$recovery_proof_provider_sha256" "$(sha256_text "$candidate_network")" \
            | sha256sum | awk '{print $1}')
        if [[ $provider_expectation == absent ]]; then
            recovery_proof_queue_sha256=$(run_release_probe queue "$queue_probe" \
                "$queue_probe_sha256")
            recovery_proof_terminal_sha256=$(run_release_probe terminal "$terminal_probe" \
                "$terminal_probe_sha256")
        fi
        return
    fi

    [[ $(state_value phase) == released ]] \
        || fail 'released daemon recovery requires released runtime-fence state'
    assert_restore_artifacts
    table_exists && fail 'released daemon recovery must never recreate the runtime fence'
    assert_release_authorization
    [[ $(runtime_recovery_value "$file" release_provider_sha256) \
            == "$(state_value release_provider_sha256)" \
        && $(runtime_recovery_value "$file" release_queue_sha256) \
            == "$(state_value release_queue_sha256)" \
        && $(runtime_recovery_value "$file" release_terminal_sha256) \
            == "$(state_value release_terminal_sha256)" \
        && $(runtime_recovery_value "$file" release_files_sha256) \
            == "$(state_value release_files_sha256)" ]] \
        || fail 'released daemon recovery authorization hashes changed'
    prove_management_unaffected
    recovery_proof_queue_sha256=$(run_release_probe queue "$queue_probe" "$queue_probe_sha256")
    recovery_proof_terminal_sha256=$(run_release_probe terminal "$terminal_probe" \
        "$terminal_probe_sha256")
    recovery_fence_proof_sha256=$(printf '%s\n%s\n%s\n%s\n' released \
        "$recovery_proof_provider_sha256" "$recovery_proof_queue_sha256" \
        "$recovery_proof_terminal_sha256" | sha256sum | awk '{print $1}')
}

initialize_runtime_recovery_release_hashes()
{
    local fence_mode=$1
    recovery_release_provider_sha256=none
    recovery_release_queue_sha256=none
    recovery_release_terminal_sha256=none
    recovery_release_files_sha256=none
    if [[ $fence_mode == released ]]; then
        assert_release_authorization
        recovery_release_provider_sha256=$(state_value release_provider_sha256)
        recovery_release_queue_sha256=$(state_value release_queue_sha256)
        recovery_release_terminal_sha256=$(state_value release_terminal_sha256)
        recovery_release_files_sha256=$(state_value release_files_sha256)
    fi
}

assert_runtime_recovery_context()
{
    local file=$1 direction=$2 color=$3 parent_phase=$4 provider_expectation=$5
    [[ $(runtime_recovery_value "$file" direction) == "$direction" \
        && $(runtime_recovery_value "$file" color) == "$color" \
        && $(runtime_recovery_value "$file" parent_phase) == "$parent_phase" \
        && $(runtime_recovery_value "$file" provider_expectation) \
            == "$provider_expectation" ]] \
        || fail 'unfinished runtime-recovery generation belongs to another route context'
}

recover_daemon()
{
    local direction=${1:-} color=${2:-} parent_phase=${3:-} provider_expectation=${4:-}
    local phase_class fence_mode old_daemon
    [[ $# -eq 4 ]] \
        || fail 'usage: runtime-attestation-ssh-fence.sh recover-daemon DIRECTION COLOR PARENT_PHASE PROVIDER_EXPECTATION'
    phase_class=$(runtime_recovery_phase_class "$direction" "$color" "$parent_phase" \
        "$provider_expectation")
    fence_mode=$(runtime_recovery_fence_mode "$phase_class")
    [[ -f $state_file ]] || fail 'runtime fence state is absent'
    load_runtime_recovery_lineage
    case "$latest_runtime_recovery_status" in
        intent|fence-verified|runtime-verified|route-verified)
            assert_runtime_recovery_context "$latest_runtime_recovery_file" "$direction" "$color" \
                "$parent_phase" "$provider_expectation"
            assert_runtime_recovery_kind "$latest_runtime_recovery_file" daemon
            ;;
        none|verified) ;;
        *) fail 'runtime-recovery lineage status is not recoverable' ;;
    esac

    if [[ $latest_runtime_recovery_status == none \
        || $latest_runtime_recovery_status == verified ]]; then
        capture_runtime_recovery_observation "$provider_expectation"
        old_daemon=$(runtime_recovery_old_tuple_value docker_daemon_id)
        [[ $recovery_new_docker_daemon_id == "$old_daemon" ]] \
            || fail 'Docker daemon ID changed; routed recovery is forbidden'
        if ! runtime_recovery_tuple_changed; then
            [[ $latest_runtime_recovery_status == verified ]] \
                || fail 'daemon recovery requires a changed boot/dockerd tuple'
            note "recover_daemon=passed operation_id=$operation_id generation=$latest_runtime_recovery_generation unchanged=true"
            return
        fi
        initialize_runtime_recovery_release_hashes "$fence_mode"
        write_runtime_recovery_intent "$direction" "$color" "$parent_phase" \
            "$provider_expectation" daemon
        test_crash after-runtime-recovery-intent
    else
        assert_recorded_runtime_recovery_observation "$latest_runtime_recovery_file" \
            "$provider_expectation"
    fi

    if [[ $latest_runtime_recovery_status == intent ]]; then
        prove_runtime_recovery_phase "$latest_runtime_recovery_file" "$phase_class" "$fence_mode" \
            "$provider_expectation"
        append_runtime_recovery_status "$latest_runtime_recovery_file" fence-verified \
            "fence_proof_sha256=$recovery_fence_proof_sha256" \
            "fence_provider_sha256=$recovery_proof_provider_sha256" \
            "fence_verified_at_epoch=$(date -u +%s)"
        latest_runtime_recovery_status=fence-verified
        test_crash after-runtime-recovery-fence-verified
    fi
    if [[ $latest_runtime_recovery_status == fence-verified ]]; then
        prove_runtime_recovery_phase "$latest_runtime_recovery_file" "$phase_class" "$fence_mode" \
            "$provider_expectation"
        append_runtime_recovery_status "$latest_runtime_recovery_file" runtime-verified \
            "runtime_provider_sha256=$recovery_proof_provider_sha256" \
            "runtime_queue_sha256=$recovery_proof_queue_sha256" \
            "runtime_terminal_sha256=$recovery_proof_terminal_sha256" \
            "runtime_verified_at_epoch=$(date -u +%s)"
        latest_runtime_recovery_status=runtime-verified
        test_crash after-runtime-recovery-runtime-verified
    fi
    [[ $latest_runtime_recovery_status =~ ^(runtime-verified|route-verified)$ ]] \
        || fail 'daemon recovery did not reach its runtime-verification boundary'
    note "recover_daemon=passed operation_id=$operation_id generation=$latest_runtime_recovery_generation status=$latest_runtime_recovery_status"
}

finalize_daemon_recovery()
{
    local direction=${1:-} color=${2:-} parent_phase=${3:-} provider_expectation=${4:-}
    local route_proof_sha256=${5:-} phase_class fence_mode
    [[ $# -eq 5 ]] \
        || fail 'usage: runtime-attestation-ssh-fence.sh finalize-daemon-recovery DIRECTION COLOR PARENT_PHASE PROVIDER_EXPECTATION ROUTE_PROOF_SHA256'
    validate_sha256 route-proof "$route_proof_sha256"
    phase_class=$(runtime_recovery_phase_class "$direction" "$color" "$parent_phase" \
        "$provider_expectation")
    fence_mode=$(runtime_recovery_fence_mode "$phase_class")
    load_runtime_recovery_lineage
    [[ $latest_runtime_recovery_status =~ ^(runtime-verified|route-verified|verified)$ ]] \
        || fail 'daemon recovery cannot finalize before durable runtime verification'
    assert_runtime_recovery_context "$latest_runtime_recovery_file" "$direction" "$color" \
        "$parent_phase" "$provider_expectation"
    assert_runtime_recovery_kind "$latest_runtime_recovery_file" daemon
    if [[ $latest_runtime_recovery_status == verified ]]; then
        [[ $(runtime_recovery_value "$latest_runtime_recovery_file" route_proof_sha256) \
                == "$route_proof_sha256" ]] \
            || fail 'verified runtime-recovery route proof changed'
        prove_runtime_recovery_phase "$latest_runtime_recovery_file" "$phase_class" "$fence_mode" \
            "$provider_expectation"
        note "finalize_daemon_recovery=passed operation_id=$operation_id generation=$latest_runtime_recovery_generation already_verified=true"
        return
    fi
    prove_runtime_recovery_phase "$latest_runtime_recovery_file" "$phase_class" "$fence_mode" \
        "$provider_expectation"
    if [[ $latest_runtime_recovery_status == runtime-verified ]]; then
        append_runtime_recovery_status "$latest_runtime_recovery_file" route-verified \
            "route_proof_sha256=$route_proof_sha256" \
            "route_verified_at_epoch=$(date -u +%s)"
        latest_runtime_recovery_status=route-verified
        test_crash after-runtime-recovery-route-verified
    else
        [[ $(runtime_recovery_value "$latest_runtime_recovery_file" route_proof_sha256) \
                == "$route_proof_sha256" ]] \
            || fail 'runtime-recovery route proof changed after durable acknowledgement'
    fi
    prove_runtime_recovery_phase "$latest_runtime_recovery_file" "$phase_class" "$fence_mode" \
        "$provider_expectation"
    append_runtime_recovery_status "$latest_runtime_recovery_file" verified \
        "verified_at_epoch=$(date -u +%s)"
    sync "$latest_runtime_recovery_file"
    sync "$operation_directory"
    latest_runtime_recovery_status=verified
    test_crash after-runtime-recovery-verified
    note "finalize_daemon_recovery=passed operation_id=$operation_id generation=$latest_runtime_recovery_generation status=verified"
}

classify_routed_runtime()
{
    local direction=${1:-} color=${2:-} parent_phase=${3:-} provider_expectation=${4:-}
    local phase_class recovery_kind
    [[ $# -eq 4 ]] \
        || fail 'usage: runtime-attestation-ssh-fence.sh classify-routed-runtime DIRECTION COLOR PARENT_PHASE PROVIDER_EXPECTATION'
    phase_class=$(runtime_recovery_phase_class "$direction" "$color" "$parent_phase" \
        "$provider_expectation")
    runtime_recovery_fence_mode "$phase_class" >/dev/null
    [[ -f $state_file ]] || fail 'runtime fence state is absent'
    load_runtime_recovery_lineage
    [[ $latest_runtime_recovery_status =~ ^(none|verified)$ ]] \
        || fail 'routed runtime classification is forbidden during an unfinished recovery generation'
    capture_runtime_recovery_observation "$provider_expectation"
    recovery_kind=$(classify_routed_runtime_recovery "$provider_expectation")
    [[ $recovery_kind =~ ^(current|daemon|candidate)$ ]] \
        || fail 'routed runtime classification returned an unknown result'
    printf '%s\n' "$recovery_kind"
}

recover_routed_runtime()
{
    local direction=${1:-} color=${2:-} parent_phase=${3:-} provider_expectation=${4:-}
    local phase_class fence_mode recovery_kind
    [[ $# -eq 4 ]] \
        || fail 'usage: runtime-attestation-ssh-fence.sh recover-routed-runtime DIRECTION COLOR PARENT_PHASE PROVIDER_EXPECTATION'
    phase_class=$(runtime_recovery_phase_class "$direction" "$color" "$parent_phase" \
        "$provider_expectation")
    fence_mode=$(runtime_recovery_fence_mode "$phase_class")
    [[ -f $state_file ]] || fail 'runtime fence state is absent'
    load_runtime_recovery_lineage
    case "$latest_runtime_recovery_status" in
        intent|fence-verified|runtime-verified|route-verified)
            assert_runtime_recovery_context "$latest_runtime_recovery_file" "$direction" "$color" \
                "$parent_phase" "$provider_expectation"
            recovery_kind=$(runtime_recovery_kind "$latest_runtime_recovery_file")
            ;;
        none|verified)
            capture_runtime_recovery_observation "$provider_expectation"
            recovery_kind=$(classify_routed_runtime_recovery "$provider_expectation")
            initialize_runtime_recovery_release_hashes "$fence_mode"
            write_runtime_recovery_intent "$direction" "$color" "$parent_phase" \
                "$provider_expectation" "$recovery_kind"
            test_crash after-runtime-recovery-intent
            ;;
        *)
            fail 'runtime-recovery lineage status is not recoverable'
            ;;
    esac

    assert_recorded_runtime_recovery_observation "$latest_runtime_recovery_file" \
        "$provider_expectation"

    if [[ $latest_runtime_recovery_status == intent ]]; then
        prove_runtime_recovery_phase "$latest_runtime_recovery_file" "$phase_class" "$fence_mode" \
            "$provider_expectation"
        append_runtime_recovery_status "$latest_runtime_recovery_file" fence-verified \
            "fence_proof_sha256=$recovery_fence_proof_sha256" \
            "fence_provider_sha256=$recovery_proof_provider_sha256" \
            "fence_verified_at_epoch=$(date -u +%s)"
        latest_runtime_recovery_status=fence-verified
        test_crash after-runtime-recovery-fence-verified
    fi
    if [[ $latest_runtime_recovery_status == fence-verified ]]; then
        prove_runtime_recovery_phase "$latest_runtime_recovery_file" "$phase_class" "$fence_mode" \
            "$provider_expectation"
        append_runtime_recovery_status "$latest_runtime_recovery_file" runtime-verified \
            "runtime_provider_sha256=$recovery_proof_provider_sha256" \
            "runtime_queue_sha256=$recovery_proof_queue_sha256" \
            "runtime_terminal_sha256=$recovery_proof_terminal_sha256" \
            "runtime_verified_at_epoch=$(date -u +%s)"
        latest_runtime_recovery_status=runtime-verified
        test_crash after-runtime-recovery-runtime-verified
    fi
    [[ $latest_runtime_recovery_status =~ ^(runtime-verified|route-verified)$ ]] \
        || fail 'routed runtime recovery did not reach its runtime-verification boundary'
    note "recover_routed_runtime=passed operation_id=$operation_id generation=$latest_runtime_recovery_generation kind=$recovery_kind status=$latest_runtime_recovery_status"
}

finalize_routed_runtime_recovery()
{
    local direction=${1:-} color=${2:-} parent_phase=${3:-} provider_expectation=${4:-}
    local route_proof_sha256=${5:-} phase_class fence_mode recovery_kind
    [[ $# -eq 5 ]] \
        || fail 'usage: runtime-attestation-ssh-fence.sh finalize-routed-runtime-recovery DIRECTION COLOR PARENT_PHASE PROVIDER_EXPECTATION ROUTE_PROOF_SHA256'
    validate_sha256 route-proof "$route_proof_sha256"
    phase_class=$(runtime_recovery_phase_class "$direction" "$color" "$parent_phase" \
        "$provider_expectation")
    fence_mode=$(runtime_recovery_fence_mode "$phase_class")
    load_runtime_recovery_lineage
    [[ $latest_runtime_recovery_status =~ ^(runtime-verified|route-verified|verified)$ ]] \
        || fail 'routed runtime recovery cannot finalize before durable runtime verification'
    assert_runtime_recovery_context "$latest_runtime_recovery_file" "$direction" "$color" \
        "$parent_phase" "$provider_expectation"
    recovery_kind=$(runtime_recovery_kind "$latest_runtime_recovery_file")
    if [[ $latest_runtime_recovery_status == verified ]]; then
        [[ $(runtime_recovery_value "$latest_runtime_recovery_file" route_proof_sha256) \
                == "$route_proof_sha256" ]] \
            || fail 'verified runtime-recovery route proof changed'
        prove_runtime_recovery_phase "$latest_runtime_recovery_file" "$phase_class" "$fence_mode" \
            "$provider_expectation"
        note "finalize_routed_runtime_recovery=passed operation_id=$operation_id generation=$latest_runtime_recovery_generation kind=$recovery_kind already_verified=true"
        return
    fi
    prove_runtime_recovery_phase "$latest_runtime_recovery_file" "$phase_class" "$fence_mode" \
        "$provider_expectation"
    if [[ $latest_runtime_recovery_status == runtime-verified ]]; then
        append_runtime_recovery_status "$latest_runtime_recovery_file" route-verified \
            "route_proof_sha256=$route_proof_sha256" \
            "route_verified_at_epoch=$(date -u +%s)"
        latest_runtime_recovery_status=route-verified
        test_crash after-runtime-recovery-route-verified
    else
        [[ $(runtime_recovery_value "$latest_runtime_recovery_file" route_proof_sha256) \
                == "$route_proof_sha256" ]] \
            || fail 'runtime-recovery route proof changed after durable acknowledgement'
    fi
    prove_runtime_recovery_phase "$latest_runtime_recovery_file" "$phase_class" "$fence_mode" \
        "$provider_expectation"
    append_runtime_recovery_status "$latest_runtime_recovery_file" verified \
        "verified_at_epoch=$(date -u +%s)"
    sync "$latest_runtime_recovery_file"
    sync "$operation_directory"
    latest_runtime_recovery_status=verified
    test_crash after-runtime-recovery-verified
    note "finalize_routed_runtime_recovery=passed operation_id=$operation_id generation=$latest_runtime_recovery_generation kind=$recovery_kind status=verified"
}

candidate_is_absent()
{
    inspect_docker_container "$(state_value candidate_container)"
    [[ $docker_api_state == absent ]] || return 1
    inspect_docker_container "$(state_value web_b_container)"
    [[ $docker_api_state == absent ]] || return 1
    if grep -E -q '^candidate_denial_id=' "$state_file"; then
        if container_id_is_listed "$(state_value candidate_denial_id)"; then
            return 1
        fi
    fi
    if grep -E -q '^web_b_denial_id=' "$state_file"; then
        if container_id_is_listed "$(state_value web_b_denial_id)"; then
            return 1
        fi
    fi
    [[ -z $(pool_selector_rows) ]] || return 1
    return 0
}

assert_adopted_rollback_incumbent()
{
    local current_json current_id
    [[ $(state_value rollback_incumbent_adopted) == 1 ]] \
        || fail 'rollback incumbent adoption evidence is absent'
    current_json=$(container_json "$legacy_container")
    current_id=$(jq -r .id <<< "$current_json")
    [[ $current_id == "$(state_value rollback_incumbent_id)" \
        && $current_id != "$(state_value legacy_id)" \
        && $(jq -r .image_id <<< "$current_json") == "$(state_value rollback_incumbent_image_id)" \
        && $(jq -r .image_reference <<< "$current_json") == "$(state_value rollback_incumbent_image_reference)" \
        && $(jq -r .started_at <<< "$current_json") == "$(state_value rollback_incumbent_started_at)" \
        && $(jq -r .status <<< "$current_json") == running \
        && $(jq -r .running <<< "$current_json") == true \
        && $(container_runtime_sha256 "$legacy_container") == "$(state_value rollback_incumbent_runtime_sha256)" ]] \
        || fail 'adopted rollback incumbent no longer matches its exact appended identity'
    if container_id_is_listed "$(state_value legacy_id)"; then
        fail 'original captured incumbent ID reappeared after rollback adoption'
    fi
    candidate_is_absent \
        || fail 'candidate exists while validating the adopted rollback incumbent'
    assert_network_membership
    run_provider_probe incumbent "$current_id" >/dev/null
}

adopt_rollback_incumbent()
{
    local requested=${1:-} current_json current_id provider_hash candidate
    validate_identifier rollback-incumbent "$requested"
    [[ $requested == "$legacy_container" ]] \
        || fail 'rollback incumbent adoption requested for a name outside the captured incumbent'
    [[ -f $state_file && $(state_value phase) == active ]] \
        || fail 'active runtime fence state is absent'
    [[ $provider_capture_expectation == incumbent ]] \
        || fail 'rollback incumbent adoption is forbidden for an absent-provider capture generation'
    assert_common_identity
    if grep -F -x -q rollback_incumbent_adopted=1 "$state_file"; then
        assert_adopted_rollback_incumbent
        prove_management_unaffected
        note "rollback_incumbent_adoption=passed operation_id=$operation_id already_adopted=true"
        return
    fi
    current_json=$(container_json "$legacy_container")
    current_id=$(jq -r .id <<< "$current_json")
    [[ $current_id =~ ^[a-f0-9]{64}$ && $current_id != "$(state_value legacy_id)" ]] \
        || fail 'rollback incumbent must be a newly created exact container ID'
    if container_id_is_listed "$(state_value legacy_id)"; then
        fail 'original captured incumbent ID is still present during rollback adoption'
    fi
    candidate_is_absent \
        || fail 'candidate must be exactly absent before rollback incumbent adoption'
    [[ $(jq -r .image_id <<< "$current_json") == "$(state_value legacy_image_id)" \
        && $(jq -r .image_reference <<< "$current_json") == "$(state_value legacy_image_reference)" \
        && $(jq -r .status <<< "$current_json") == running \
        && $(jq -r .running <<< "$current_json") == true \
        && $(container_runtime_sha256 "$legacy_container") == "$(state_value legacy_runtime_sha256)" ]] \
        || fail 'replacement rollback incumbent differs from the captured immutable runtime contract'
    assert_network_membership
    provider_hash=$(run_provider_probe incumbent "$current_id")
    prove_management_unaffected
    candidate=$operation_directory/.state.rollback-incumbent.$$
    cp -- "$state_file" "$candidate"
    {
        printf 'rollback_incumbent_adopted=1\n'
        printf 'rollback_incumbent_id=%s\n' "$current_id"
        printf 'rollback_incumbent_image_id=%s\n' "$(jq -r .image_id <<< "$current_json")"
        printf 'rollback_incumbent_image_reference=%s\n' "$(jq -r .image_reference <<< "$current_json")"
        printf 'rollback_incumbent_started_at=%s\n' "$(jq -r .started_at <<< "$current_json")"
        printf 'rollback_incumbent_runtime_sha256=%s\n' "$(container_runtime_sha256 "$legacy_container")"
        printf 'rollback_incumbent_provider_sha256=%s\n' "$provider_hash"
        printf 'rollback_incumbent_adopted_at_epoch=%s\n' "$(date -u +%s)"
    } >> "$candidate"
    atomic_state "$candidate"
    note "rollback_incumbent_adoption=passed operation_id=$operation_id incumbent_id=$current_id"
}

assert_recovery_static_identity()
{
    [[ $(state_value proxy_container) == "$proxy_container" \
        && $(state_value legacy_container) == "$legacy_container" \
        && $(state_value candidate_container) == "$candidate_container" \
        && $(state_value candidate_image_reference) == "$candidate_image_reference" \
        && $(state_value candidate_image_id) == "$candidate_image_id" ]] \
        || fail 'recovery-abort immutable container plan changed'
    assert_restore_artifacts
    [[ $(state_value provider_probe_sha256) == "$provider_probe_sha256" \
        && $(state_value reaper_sha256) == "$reaper_sha256" \
        && $(state_value queue_probe_sha256) == "$queue_probe_sha256" \
        && $(state_value terminal_probe_sha256) == "$terminal_probe_sha256" ]] \
        || fail 'recovery-abort reviewed helper checksums changed'
    assert_probe_file "$provider_probe" "$provider_probe_sha256" provider-probe
    assert_probe_file "$reaper" "$reaper_sha256" controlmaster-reaper
    assert_probe_file "$queue_probe" "$queue_probe_sha256" queue-probe
    assert_probe_file "$terminal_probe" "$terminal_probe_sha256" terminal-probe
    [[ $(printf '%s' "$management_endpoints" | encode) == "$(state_value management_endpoints_b64)" \
        && $(printf '%s' "$additional_network_ids" | encode) == "$(state_value additional_network_ids_b64)" \
        && $(printf '%s' "$candidate_network_ids" | encode) == "$(state_value candidate_network_ids_b64)" \
        && $(state_value self_ssh_target) == "$self_ssh_target" \
        && $(state_value probe_max_age_seconds) == "$probe_max_age_seconds" ]] \
        || fail 'recovery-abort network/probe configuration changed'
    validate_sha256 provider-capture "$(state_value provider_capture_sha256)"
    assert_fence_identity
}

recovery_runtime_changed()
{
    local key
    for key in boot_id dockerd_pid dockerd_invocation_id docker_daemon_id docker_socket; do
        [[ $(runtime_value "$key") == "$(state_value "$key")" ]] || return 0
    done
    return 1
}

assert_recovery_incumbent_contract()
{
    local current_json current_id
    current_json=$(runtime_value legacy_json)
    current_id=$(jq -r .id <<< "$current_json")
    [[ $current_id =~ ^[a-f0-9]{64}$ \
        && $(jq -r .image_id <<< "$current_json") == "$(state_value legacy_image_id)" \
        && $(jq -r .image_reference <<< "$current_json") == "$(state_value legacy_image_reference)" \
        && $(jq -r .status <<< "$current_json") == running \
        && $(jq -r .running <<< "$current_json") == true \
        && $(jq -r .health <<< "$current_json") =~ ^(healthy|not-configured)$ \
        && $(container_runtime_sha256 "$legacy_container") == "$(state_value legacy_runtime_sha256)" ]] \
        || fail 'recovery-abort incumbent differs from the captured immutable runtime contract'
    candidate_is_absent \
        || fail 'candidate must be exactly absent before recovery-abort authorization'
    assert_network_membership
    run_provider_probe incumbent "$current_id" >/dev/null
    prove_management_unaffected
}

assert_recovery_generation()
{
    local key proxy_json incumbent_json
    [[ $(state_value recovery_direction) == recovery-abort \
        && $(state_value recovery_generation) == 1 ]] \
        || fail 'recovery-abort generation identity is malformed'
    for key in boot_id dockerd_pid dockerd_invocation_id docker_daemon_id docker_socket; do
        [[ $(runtime_value "$key") == "$(state_value "recovery_$key")" ]] \
            || fail "recovery-abort runtime identity changed: $key"
    done
    proxy_json=$(runtime_value proxy_json)
    incumbent_json=$(runtime_value legacy_json)
    [[ $(jq -r .id <<< "$proxy_json") == "$(state_value recovery_proxy_id)" \
        && $(jq -r .image_id <<< "$proxy_json") == "$(state_value recovery_proxy_image_id)" \
        && $(jq -r .started_at <<< "$proxy_json") == "$(state_value recovery_proxy_started_at)" \
        && $(jq -r .restart_count <<< "$proxy_json") == "$(state_value recovery_proxy_restart_count)" \
        && $(jq -S -c '{configured_bindings,published_bindings}' <<< "$proxy_json" | encode) == "$(state_value recovery_proxy_bindings_b64)" \
        && $(jq -r .id <<< "$incumbent_json") == "$(state_value recovery_incumbent_id)" \
        && $(jq -r .started_at <<< "$incumbent_json") == "$(state_value recovery_incumbent_started_at)" \
        && $(jq -r .restart_count <<< "$incumbent_json") == "$(state_value recovery_incumbent_restart_count)" ]] \
        || fail 'recovery-abort proxy or incumbent generation identity changed'
    assert_recovery_incumbent_contract
}

capture_recovery_generation()
{
    local candidate proxy_json incumbent_json
    assert_recovery_static_identity
    recovery_runtime_changed \
        || fail 'recovery-abort is forbidden while the original same-boot runtime identity remains valid'
    assert_live_runtime_shape
    proxy_json=$(runtime_value proxy_json)
    incumbent_json=$(runtime_value legacy_json)
    [[ $(jq -r .id <<< "$proxy_json") == "$(state_value proxy_id)" \
        && $(jq -r .image_id <<< "$proxy_json") == "$(state_value proxy_image_id)" \
        && $(jq -S -c '{configured_bindings,published_bindings}' <<< "$proxy_json" | encode) == "$(state_value proxy_bindings_b64)" ]] \
        || fail 'recovery-abort proxy differs from the captured exact container contract'
    assert_recovery_incumbent_contract
    candidate=$operation_directory/.state.recovery-abort.$$
    sed 's/^phase=active$/phase=recovery-abort-active/' "$state_file" > "$candidate"
    {
        printf 'recovery_direction=recovery-abort\nrecovery_generation=1\n'
        printf 'recovery_boot_id=%s\n' "$(runtime_value boot_id)"
        printf 'recovery_dockerd_pid=%s\n' "$(runtime_value dockerd_pid)"
        printf 'recovery_dockerd_invocation_id=%s\n' "$(runtime_value dockerd_invocation_id)"
        printf 'recovery_docker_daemon_id=%s\n' "$(runtime_value docker_daemon_id)"
        printf 'recovery_docker_socket=%s\n' "$(runtime_value docker_socket)"
        printf 'recovery_proxy_id=%s\nrecovery_proxy_image_id=%s\n' \
            "$(jq -r .id <<< "$proxy_json")" "$(jq -r .image_id <<< "$proxy_json")"
        printf 'recovery_proxy_started_at=%s\nrecovery_proxy_restart_count=%s\n' \
            "$(jq -r .started_at <<< "$proxy_json")" "$(jq -r .restart_count <<< "$proxy_json")"
        printf 'recovery_proxy_bindings_b64=%s\n' \
            "$(jq -S -c '{configured_bindings,published_bindings}' <<< "$proxy_json" | encode)"
        printf 'recovery_incumbent_id=%s\nrecovery_incumbent_started_at=%s\nrecovery_incumbent_restart_count=%s\n' \
            "$(jq -r .id <<< "$incumbent_json")" "$(jq -r .started_at <<< "$incumbent_json")" \
            "$(jq -r .restart_count <<< "$incumbent_json")"
        printf 'recovery_captured_at_epoch=%s\n' "$(date -u +%s)"
    } >> "$candidate"
    atomic_state "$candidate"
}

prove_recovery_watchdog_or_unarmed()
{
    if [[ $armed == 1 ]]; then
        prove_watchdog_responsive
        return
    fi
    candidate_is_absent \
        || fail 'unarmed recovery-abort is allowed only before the planned candidate exists'
}

recover_abort()
{
    local candidate phase
    [[ -f $state_file ]] || fail 'runtime fence state is absent'
    phase=$(state_value phase)
    if [[ $phase == recovery-aborted ]]; then
        assert_restore_artifacts
        table_exists && fail 'recovery-aborted state unexpectedly has a managed nft table'
        note "recover_abort=passed operation_id=$operation_id phase=recovery-aborted already_terminal=true"
        return
    fi
    [[ $phase =~ ^(active|recovery-abort-active|recovery-abort-in-progress)$ ]] \
        || fail 'recovery-abort requires an active or interrupted recovery generation'
    prove_recovery_watchdog_or_unarmed
    if [[ $phase == active ]]; then
        capture_recovery_generation
        phase=recovery-abort-active
    fi
    if [[ $phase == recovery-abort-in-progress ]]; then
        assert_restore_artifacts
        if table_exists; then
            assert_fence_identity
        else
            apply_fence_ruleset
            [[ $(nft_identity) == "$(state_value nft_identity)" ]] \
                || fail 'recovery-abort could not restore the exact interrupted fence'
        fi
        rollback_interrupted_recovery_abort
    fi
    assert_recovery_static_identity
    assert_recovery_generation
    prove_recovery_watchdog_or_unarmed
    candidate=$operation_directory/.state.recovery-removal.$$
    sed 's/^phase=recovery-abort-active$/phase=recovery-abort-in-progress/' \
        "$state_file" > "$candidate"
    printf 'recovery_abort_authorized_at_epoch=%s\n' "$(date -u +%s)" >> "$candidate"
    atomic_state "$candidate"
    test_crash after-recovery-abort-state
    prove_recovery_watchdog_or_unarmed
    assert_recovery_static_identity
    assert_recovery_generation
    nft delete table "$TABLE_FAMILY" "$TABLE_NAME"
    test_crash after-recovery-abort-nft-delete
    candidate=$operation_directory/.state.recovery-aborted.$$
    sed 's/^phase=recovery-abort-in-progress$/phase=recovery-aborted/' \
        "$state_file" > "$candidate"
    printf 'recovery_aborted_at_epoch=%s\n' "$(date -u +%s)" >> "$candidate"
    atomic_state "$candidate"
    note "recover_abort=passed operation_id=$operation_id phase=recovery-aborted"
}

abort()
{
    local candidate phase
    [[ -f $state_file ]] || fail 'runtime fence state is absent'
    phase=$(state_value phase)
    if [[ $phase == aborted ]]; then
        assert_restore_artifacts
        table_exists && fail 'aborted state unexpectedly has a managed nft table'
        [[ $(state_value aborted_at_epoch) =~ ^[1-9][0-9]*$ ]] \
            || fail 'aborted terminal timestamp is malformed'
        note "abort=passed operation_id=$operation_id phase=aborted already_terminal=true"
        return
    fi
    if [[ $phase == preparing ]]; then
        assert_restore_artifacts
        candidate_is_absent \
            || fail 'planned candidate exists during pre-candidate fence cancellation'
        if table_exists; then
            assert_fence_identity
            nft delete table "$TABLE_FAMILY" "$TABLE_NAME"
        fi
        candidate=$operation_directory/.state.aborted.$$
        sed 's/^phase=preparing$/phase=aborted/' "$state_file" > "$candidate"
        printf 'abort_authorized_at_epoch=%s\naborted_at_epoch=%s\n' \
            "$(date -u +%s)" "$(date -u +%s)" >> "$candidate"
        atomic_state "$candidate"
        note "abort=passed operation_id=$operation_id phase=aborted pre_candidate=true"
        return
    fi
    [[ $phase == active ]] || fail 'active fence state is absent'
    prove_watchdog_responsive
    if grep -F -x -q rollback_incumbent_adopted=1 "$state_file"; then
        assert_common_identity
        assert_adopted_rollback_incumbent
        prove_management_unaffected
    else
        verify >/dev/null
    fi
    candidate_is_absent \
        || fail 'recorded candidate name or exact ID still exists during rollback'
    candidate=$operation_directory/.state.abort.$$
    sed 's/^phase=active$/phase=abort-in-progress/' "$state_file" > "$candidate"
    printf 'abort_authorized_at_epoch=%s\n' "$(date -u +%s)" >> "$candidate"
    atomic_state "$candidate"
    test_crash after-abort-state
    prove_watchdog_responsive
    assert_common_identity
    if grep -F -x -q rollback_incumbent_adopted=1 "$state_file"; then
        assert_adopted_rollback_incumbent
    else
        assert_retired_pool_identity
        assert_network_membership
        run_provider_probe "$(provider_capture_identity)" >/dev/null
    fi
    candidate_is_absent \
        || fail 'recorded candidate name or exact ID reappeared before rollback fence removal'
    assert_fence_identity
    nft delete table "$TABLE_FAMILY" "$TABLE_NAME"
    test_crash after-abort-nft-delete
    candidate=$operation_directory/.state.aborted.$$
    sed 's/^phase=abort-in-progress$/phase=aborted/' "$state_file" > "$candidate"
    printf 'aborted_at_epoch=%s\n' "$(date -u +%s)" >> "$candidate"
    atomic_state "$candidate"
    note "abort=passed operation_id=$operation_id phase=aborted"
}

mark_released()
{
    local final_queue_sha256=$1 candidate=$operation_directory/.state.released.$$
    validate_sha256 final-release-queue "$final_queue_sha256"
    awk -F= -v queue_sha256="$final_queue_sha256" '
        $1 == "phase" { print "phase=released"; next }
        $1 == "release_final_queue_sha256" {
            print "release_final_queue_sha256=" queue_sha256; next
        }
        { print }
    ' "$state_file" > "$candidate"
    printf 'released_at_epoch=%s\n' "$(date -u +%s)" >> "$candidate"
    atomic_state "$candidate"
}

rollback_interrupted_release()
{
    local candidate=$operation_directory/.state.release-rollback.$$
    awk -F= '
        $1 == "phase" { print "phase=active"; next }
        $1 == "release_files_sha256" { print; next }
        $1 ~ /^release_/ { next }
        $1 == "abort_authorized_at_epoch" { next }
        { print }
    ' "$state_file" > "$candidate"
    atomic_state "$candidate"
}

rollback_interrupted_recovery_abort()
{
    local candidate=$operation_directory/.state.recovery-rollback.$$
    awk -F= '
        $1 == "phase" { print "phase=recovery-abort-active"; next }
        $1 == "recovery_abort_authorized_at_epoch" { next }
        { print }
    ' "$state_file" > "$candidate"
    atomic_state "$candidate"
}

assert_restore_artifacts()
{
    [[ $(state_value version) == "$STATE_VERSION" \
        && $(state_value runtime_digest_version) == "$RUNTIME_DIGEST_VERSION" \
        && $(state_value operation_id) == "$operation_id" ]] \
        || fail 'durable state belongs to another operation or version'
    assert_candidate_runtime_state
    assert_pool_runtime_state
    [[ $(state_value pool_plan_manifest) == "$pool_plan_manifest" \
        && $(state_value pool_plan_manifest_sha256) == "$pool_plan_manifest_sha256" \
        && $(state_value pool_plan_manifest_metadata) == "$pool_plan_manifest_metadata" \
        && $(state_value pool_plan_set_sha256) == "$pool_plan_set_sha256_value" \
        && $(state_value pool_generation) == "$pool_generation" \
        && $(state_value ingress_pool_manifest) == "$ingress_pool_manifest" ]] \
        || fail 'restore pool-plan identity differs from captured state'
    if [[ $(state_value ingress_pool_manifest_sha256) != pending ]]; then
        validate_ingress_pool_manifest "$(state_value ingress_pool_manifest_sha256)"
        [[ $ingress_pool_manifest_metadata == "$(state_value ingress_pool_manifest_metadata)" ]] \
            || fail 'restore ingress-pool metadata differs from captured state'
    else
        [[ $(state_value ingress_pool_manifest_metadata) == pending ]] \
            || fail 'restore has partial ingress-pool identity evidence'
    fi
    [[ $(sha256_file "$controller_path") == "$(state_value controller_sha256)" ]] \
        || fail 'installed restore controller differs from captured bytes'
    assert_systemd_contract
    [[ $semantic_config_sha256 == "$(state_value semantic_config_sha256)" \
        && $(semantic_configuration_sha256) == "$semantic_config_sha256" ]] \
        || fail 'restore semantic fence configuration changed'
    [[ $release_files_sha256 == "$(state_value release_files_sha256)" \
        && $(release_files_manifest_sha256) == "$release_files_sha256" ]] \
        || fail 'restore release authorization file identity changed'
    assert_root_path "$network_inventory_file" 600 network-inventory
    assert_root_path "$ruleset_file" 600 nft-ruleset
    [[ $(sha256_file "$network_inventory_file") == "$(state_value network_inventory_sha256)" \
        && $(sha256_file "$ruleset_file") == "$(state_value ruleset_sha256)" ]] \
        || fail 'durable restore artifact changed'
}

status()
{
    local phase runtime_recovery_sha256=none
    [[ -f $state_file ]] || fail 'durable fence state is absent'
    assert_restore_artifacts
    load_runtime_recovery_lineage
    if [[ $latest_runtime_recovery_file != none ]]; then
        runtime_recovery_sha256=$(sha256_file "$latest_runtime_recovery_file")
    fi
    phase=$(state_value phase)
    [[ $phase =~ ^(preparing|active|release-in-progress|abort-in-progress|recovery-abort-active|recovery-abort-in-progress|released|aborted|recovery-aborted)$ ]] \
        || fail 'durable state phase is invalid'
    note "status=passed operation_id=$operation_id phase=$phase semantic_config_sha256=$semantic_config_sha256 runtime_recovery_generation=$latest_runtime_recovery_generation runtime_recovery_kind=$latest_runtime_recovery_kind runtime_recovery_status=$latest_runtime_recovery_status runtime_recovery_sha256=$runtime_recovery_sha256"
}

restore()
{
    if [[ $armed == 0 ]]; then
        note 'restore=skipped armed=false'
        return
    fi
    [[ -f $state_file ]] || fail 'durable fence state is absent'
    case "$(state_value phase)" in
        preparing)
            assert_restore_artifacts
            apply_or_verify_fence
            note 'restore=passed phase=preparing'
            ;;
        active)
            assert_restore_artifacts
            apply_or_verify_fence
            note 'restore=passed phase=active'
            ;;
        recovery-abort-active)
            assert_restore_artifacts
            apply_or_verify_fence
            note 'restore=passed phase=recovery-abort-active'
            ;;
        recovery-abort-in-progress)
            assert_restore_artifacts
            if table_exists; then
                assert_fence_identity
            else
                apply_fence_ruleset
                [[ $(nft_identity) == "$(state_value nft_identity)" ]] \
                    || fail 'recreated recovery-abort nft table differs from its canonical identity'
            fi
            rollback_interrupted_recovery_abort
            note 'restore=passed phase=recovery-abort-active interrupted_removal=rolled-back'
            ;;
        release-in-progress|abort-in-progress)
            assert_restore_artifacts
            if [[ $(state_value phase) == release-in-progress ]]; then
                validate_sha256 release-provider "$(state_value release_provider_sha256)"
                validate_sha256 release-queue "$(state_value release_queue_sha256)"
                validate_sha256 release-terminal "$(state_value release_terminal_sha256)"
                [[ $(state_value release_final_queue_sha256) \
                    =~ ^(pending|[a-f0-9]{64})$ ]] \
                    || fail 'final release queue evidence is malformed'
                [[ $(state_value release_authorized_at_epoch) =~ ^[1-9][0-9]*$ ]] \
                    || fail 'release authorization timestamp is malformed'
            else
                [[ $(state_value abort_authorized_at_epoch) =~ ^[1-9][0-9]*$ ]] \
                    || fail 'abort authorization timestamp is malformed'
            fi
            if table_exists; then
                assert_fence_identity
            else
                apply_fence_ruleset
                [[ $(nft_identity) == "$(state_value nft_identity)" ]] \
                    || fail 'recreated nft table differs from its canonical identity'
            fi
            rollback_interrupted_release
            note 'restore=passed phase=active interrupted_removal=rolled-back'
            ;;
        released|aborted|recovery-aborted)
            table_exists && fail 'terminal state unexpectedly has a managed nft table'
            note "restore=passed phase=$(state_value phase) fence=absent"
            ;;
        *) fail 'durable state phase is invalid' ;;
    esac
}

watch()
{
    local iteration=0 limit=${CONTROL_PLANE_RUNTIME_WATCH_ITERATIONS:-0}
    if [[ $armed == 0 ]]; then
        note 'watch=skipped armed=false'
        return
    fi
    while true; do
        write_watchdog_heartbeat
        respond_watchdog_challenge
        exec 9> "$lock_file"
        if flock -x -n 9; then
            restore >/dev/null
            flock -u 9
        fi
        respond_watchdog_challenge
        iteration=$((iteration + 1))
        if [[ $limit =~ ^[1-9][0-9]*$ && $iteration -ge $limit ]]; then
            return
        fi
        sleep 1
    done
}

write_watchdog_heartbeat()
{
    local heartbeat=$state_directory/watchdog.heartbeat candidate=$state_directory/.watchdog.heartbeat.$$
    local watchdog_start_time=1
    [[ $test_mode == 1 ]] || watchdog_start_time=$(process_start_time "$$")
    {
        printf 'version=1\n'
        printf 'observed_at_epoch=%s\n' "$(date -u +%s)"
        printf 'pid=%s\n' "$$"
        printf 'process_start_time=%s\n' "$watchdog_start_time"
        printf 'controller_sha256=%s\n' "$(sha256_file "$controller_path")"
        printf 'operation_id=%s\n' "$operation_id"
        printf 'semantic_config_sha256=%s\n' "$semantic_config_sha256"
    } > "$candidate"
    chmod 0600 "$candidate"
    sync "$candidate"
    mv -f -- "$candidate" "$heartbeat"
    sync "$state_directory"
}

respond_watchdog_challenge()
{
    local challenge=$state_directory/watchdog.challenge
    local response=$state_directory/watchdog.response
    local candidate=$state_directory/.watchdog.response.$$
    local nonce watchdog_start_time=1

    [[ -e $challenge || -L $challenge ]] || return 0
    assert_root_path "$challenge" 600 watchdog-challenge
    [[ $(wc -l < "$challenge") -eq 6 \
        && $(grep -E -c '^(version=1|nonce=[a-f0-9]{64}|operation_id=[A-Za-z0-9_.-]+|semantic_config_sha256=[a-f0-9]{64}|controller_sha256=[a-f0-9]{64}|requested_at_epoch=[1-9][0-9]*)$' "$challenge") -eq 6 \
        && $(sed -n 's/^operation_id=//p' "$challenge") == "$operation_id" \
        && $(sed -n 's/^semantic_config_sha256=//p' "$challenge") == "$semantic_config_sha256" \
        && $(sed -n 's/^controller_sha256=//p' "$challenge") == "$(sha256_file "$controller_path")" ]] \
        || fail 'watchdog challenge has the wrong operation or immutable identity'
    nonce=$(sed -n 's/^nonce=//p' "$challenge")
    [[ $test_mode == 1 ]] || watchdog_start_time=$(process_start_time "$$")
    {
        printf 'version=1\nnonce=%s\n' "$nonce"
        printf 'operation_id=%s\nsemantic_config_sha256=%s\n' \
            "$operation_id" "$semantic_config_sha256"
        printf 'controller_sha256=%s\n' "$(sha256_file "$controller_path")"
        printf 'pid=%s\nprocess_start_time=%s\nresponded_at_epoch=%s\n' \
            "$$" "$watchdog_start_time" "$(date -u +%s)"
    } > "$candidate"
    chmod 0600 "$candidate"
    sync "$candidate"
    mv -f -- "$candidate" "$response"
    sync "$state_directory"
}

load_configuration()
{
    test_mode=${CONTROL_PLANE_RUNTIME_TEST_MODE:-0}
    [[ $test_mode == 0 || $test_mode == 1 ]] || fail 'test mode must be 0 or 1'
    [[ $test_mode == 1 || $(id -u) -eq 0 ]] || fail 'runtime fence controller requires root'
    if [[ $test_mode == 1 ]]; then
        immutable_uid=$(id -u)
        immutable_gid=$(id -g)
        runtime_artifact_uid=$immutable_uid
        runtime_artifact_gid=$immutable_gid
    else
        immutable_uid=0
        immutable_gid=0
        runtime_artifact_uid=9999
        runtime_artifact_gid=9999
    fi
    armed=${CONTROL_PLANE_RUNTIME_ARMED:-1}
    [[ $armed == 0 || $armed == 1 ]] || fail 'armed state must be 0 or 1'
    if [[ $action == restore || $action == watch ]]; then
        if [[ $armed == 0 ]]; then
            state_directory=${CONTROL_PLANE_RUNTIME_STATE_DIR:-/var/lib/coolify-runtime-attestation-ssh-fence}
            return
        fi
    fi
    operation_id=${CONTROL_PLANE_RUNTIME_OPERATION_ID:-}
    state_directory=${CONTROL_PLANE_RUNTIME_STATE_DIR:-/var/lib/coolify-runtime-attestation-ssh-fence}
    proxy_container=${CONTROL_PLANE_RUNTIME_PROXY_CONTAINER:-coolify-proxy}
    pool_plan_manifest=${CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST:-}
    pool_plan_manifest_sha256=${CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256:-}
    pool_plan_manifest_metadata=${CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_METADATA:-}
    provider_probe=${CONTROL_PLANE_RUNTIME_PROVIDER_PROBE:-}
    provider_probe_sha256=${CONTROL_PLANE_RUNTIME_PROVIDER_PROBE_SHA256:-}
    reaper=${CONTROL_PLANE_RUNTIME_CONTROLMASTER_REAPER:-}
    reaper_sha256=${CONTROL_PLANE_RUNTIME_CONTROLMASTER_REAPER_SHA256:-}
    queue_probe=${CONTROL_PLANE_RUNTIME_QUEUE_PROBE:-}
    queue_probe_sha256=${CONTROL_PLANE_RUNTIME_QUEUE_PROBE_SHA256:-}
    terminal_probe=${CONTROL_PLANE_RUNTIME_TERMINAL_PROBE:-}
    terminal_probe_sha256=${CONTROL_PLANE_RUNTIME_TERMINAL_PROBE_SHA256:-}
    management_endpoints=${CONTROL_PLANE_RUNTIME_MANAGEMENT_ENDPOINTS:-}
    management_endpoints=${management_endpoints//,/ }
    additional_network_ids=${CONTROL_PLANE_RUNTIME_ADDITIONAL_NETWORK_IDS:-}
    self_ssh_target=${CONTROL_PLANE_RUNTIME_SELF_SSH_TARGET:-host.docker.internal}
    probe_max_age_seconds=${CONTROL_PLANE_RUNTIME_PROBE_MAX_AGE_SECONDS:-30}
    queue_stable_seconds=${CONTROL_PLANE_RUNTIME_QUEUE_STABLE_SECONDS:-2}
    provider_capture_expectation=${CONTROL_PLANE_RUNTIME_PROVIDER_CAPTURE_EXPECTATION:-incumbent}
    semantic_config_sha256=${CONTROL_PLANE_RUNTIME_SEMANTIC_CONFIG_SHA256:-}
    release_files_sha256=${CONTROL_PLANE_RUNTIME_RELEASE_FILES_SHA256:-}
    boot_id_file=${CONTROL_PLANE_RUNTIME_BOOT_ID_FILE:-/proc/sys/kernel/random/boot_id}
    docker_socket=${CONTROL_PLANE_RUNTIME_DOCKER_SOCKET:-/var/run/docker.sock}
    controller_path=$(readlink -f -- "${BASH_SOURCE[0]}")

    validate_identifier CONTROL_PLANE_RUNTIME_OPERATION_ID "$operation_id"
    load_pool_plan
    validate_sha256 semantic-config "$semantic_config_sha256"
    [[ $(semantic_configuration_sha256) == "$semantic_config_sha256" ]] \
        || fail 'live semantic fence configuration differs from its prepared digest'
    validate_sha256 release-files "$release_files_sha256"
    [[ $(release_files_manifest_sha256) == "$release_files_sha256" ]] \
        || fail 'live release authorization files differ from their prepared identity'
    for command in base64 flock jq nft readlink sha256sum stat systemctl; do
        require_command "$command"
    done
    ensure_state_directory
    operation_directory=$state_directory/$operation_id
    if [[ ! -e $operation_directory ]]; then
        install -d -m 0700 "$operation_directory"
    fi
    [[ -d $operation_directory && ! -L $operation_directory ]] || fail 'operation directory is unsafe'
    if [[ $test_mode == 0 ]]; then
        [[ $(stat -c '%u:%g:%a' "$operation_directory") == 0:0:700 ]] \
            || fail 'operation directory must be root:root mode 0700'
    fi
    state_file=$operation_directory/state
    network_inventory_file=$operation_directory/coolify-bridge-networks.tsv
    ruleset_file=$operation_directory/ssh-fence.nft
    lock_file=$state_directory/controller.lock
    if [[ $action != watch ]]; then
        exec 9> "$lock_file"
        flock -x -w 15 9 || fail 'another runtime fence controller owns the lock'
    fi
    if [[ -e $state_file ]]; then
        assert_root_path "$state_file" 600 durable-state
    fi
    if [[ $action == restore || $action == watch ]]; then
        return
    fi
    validate_identifier CONTROL_PLANE_RUNTIME_PROXY_CONTAINER "$proxy_container"
    validate_identifier CONTROL_PLANE_RUNTIME_LEGACY_CONTAINER "$legacy_container"
    validate_identifier CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER "$candidate_container"
    validate_identifier CONTROL_PLANE_RUNTIME_POOL_WEB_B_CONTAINER "$pool_web_b_container"
    [[ $probe_max_age_seconds =~ ^[1-9][0-9]*$ ]] || fail 'probe max age must be positive'
    [[ $queue_stable_seconds =~ ^[1-9][0-9]*$ ]] || fail 'queue stable interval must be positive'
    [[ $provider_capture_expectation == incumbent || $provider_capture_expectation == absent ]] \
        || fail 'provider capture expectation must be incumbent or absent'
    [[ $self_ssh_target =~ ^[A-Za-z0-9_.:-]+$ ]] || fail 'self-SSH target is unsafe'
    for command in base64 conntrack curl docker ip ipcalc jq mktemp ssh ssh-keyscan systemctl; do
        require_command "$command"
    done
    [[ -r $boot_id_file && -S $docker_socket ]] || fail 'boot ID or Docker socket is unavailable'
    assert_probe_file "$provider_probe" "$provider_probe_sha256" provider-probe
    assert_probe_file "$reaper" "$reaper_sha256" controlmaster-reaper
    assert_probe_file "$queue_probe" "$queue_probe_sha256" queue-probe
    assert_probe_file "$terminal_probe" "$terminal_probe_sha256" terminal-probe
}

action=${1:-}
case "$action" in
    container-runtime-sha256|systemd-contract-sha256)
        test_mode=${CONTROL_PLANE_RUNTIME_TEST_MODE:-0}
        [[ $test_mode == 0 || $test_mode == 1 ]] || fail 'test mode must be 0 or 1'
        [[ $test_mode == 1 || $(id -u) -eq 0 ]] \
            || fail 'runtime identity inspection requires root'
        if [[ $test_mode == 1 ]]; then
            immutable_uid=$(id -u)
            immutable_gid=$(id -g)
        else
            immutable_uid=0
            immutable_gid=0
        fi
        for command in base64 jq readlink sha256sum stat; do
            require_command "$command"
        done
        if [[ $action == container-runtime-sha256 ]]; then
            [[ $# -eq 2 ]] || fail 'usage: runtime-attestation-ssh-fence.sh container-runtime-sha256 CONTAINER'
            for command in curl docker jq mktemp; do
                require_command "$command"
            done
            docker_socket=${CONTROL_PLANE_RUNTIME_DOCKER_SOCKET:-/var/run/docker.sock}
            [[ -S $docker_socket ]] || fail 'Docker socket is unavailable'
            container_runtime_sha256 "${2:-}"
        else
            [[ $# -eq 1 ]] || fail 'usage: runtime-attestation-ssh-fence.sh systemd-contract-sha256'
            require_command systemctl
            systemd_contract_sha256
        fi
        exit 0
        ;;
esac
load_configuration
case "$action" in
    stage-capture) stage_capture ;;
    capture) capture ;;
    assert-pool-baseline)
        [[ $# -eq 1 ]] || fail 'pool baseline does not accept a singular member argument'
        assert_pool_baseline_action
        ;;
    verify) verify ;;
    verify-post-revoke) verify_post_revoke ;;
    prove-pool-denied)
        [[ $# -eq 1 ]] || fail 'two-member pool denial does not accept a singular container argument'
        prove_pool_denied
        ;;
    pin-ingress-pool-manifest)
        [[ $# -eq 2 ]] || fail 'ingress pool pin requires exactly one manifest SHA-256'
        pin_ingress_pool_manifest "$2"
        ;;
    repin-pool)
        [[ $# -eq 3 ]] || fail 'pool repin requires exact web-a and web-b IDs'
        repin_pool "$2" "$3"
        ;;
    adopt-rollback-incumbent) adopt_rollback_incumbent "${2:-}" ;;
    recover-daemon) recover_daemon "${2:-}" "${3:-}" "${4:-}" "${5:-}" ;;
    finalize-daemon-recovery) finalize_daemon_recovery "${2:-}" "${3:-}" "${4:-}" \
        "${5:-}" "${6:-}" ;;
    classify-routed-runtime) classify_routed_runtime "${2:-}" "${3:-}" "${4:-}" "${5:-}" ;;
    recover-routed-runtime) recover_routed_runtime "${2:-}" "${3:-}" "${4:-}" "${5:-}" ;;
    finalize-routed-runtime-recovery) finalize_routed_runtime_recovery "${2:-}" "${3:-}" \
        "${4:-}" "${5:-}" "${6:-}" ;;
    recover-abort) recover_abort ;;
    release) release ;;
    verify-released) verify_released_terminal ;;
    restore) restore ;;
    watch) watch ;;
    abort) abort ;;
    status) status ;;
    *) fail 'usage: runtime-attestation-ssh-fence.sh {container-runtime-sha256 CONTAINER|systemd-contract-sha256|stage-capture|capture|assert-pool-baseline|verify|verify-post-revoke|prove-pool-denied|pin-ingress-pool-manifest INGRESS_SHA256|repin-pool WEB_A_ID WEB_B_ID|adopt-rollback-incumbent CONTAINER|recover-daemon DIRECTION COLOR PARENT_PHASE PROVIDER_EXPECTATION|finalize-daemon-recovery DIRECTION COLOR PARENT_PHASE PROVIDER_EXPECTATION ROUTE_PROOF_SHA256|classify-routed-runtime DIRECTION COLOR PARENT_PHASE PROVIDER_EXPECTATION|recover-routed-runtime DIRECTION COLOR PARENT_PHASE PROVIDER_EXPECTATION|finalize-routed-runtime-recovery DIRECTION COLOR PARENT_PHASE PROVIDER_EXPECTATION ROUTE_PROOF_SHA256|recover-abort|release|verify-released|abort|status|restore|watch}' ;;
esac
