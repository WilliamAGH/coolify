#!/usr/bin/env bash
# shellcheck disable=SC2016 # Single-quoted programs are intentionally evaluated by isolated child shells.

set -Eeuo pipefail
umask 077

SCRIPT_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
readonly SCRIPT_DIRECTORY
ASSET_DIRECTORY=$(cd -- "$SCRIPT_DIRECTORY/../port8000" && pwd -P)
readonly ASSET_DIRECTORY

fail()
{
    printf 'CONTROL_PLANE_PORT8000_CONTROLLER_FAILURE %s\n' "$1" >&2
    exit 1
}

note()
{
    printf 'CONTROL_PLANE_PORT8000_CONTROLLER %s\n' "$1" >&2
}

require_value()
{
    [[ -n $2 ]] || fail "required setting is empty: $1"
}

require_command()
{
    command -v "$1" >/dev/null 2>&1 || fail "required command is unavailable: $1"
}

checksum()
{
    sha256sum "$1" | awk '{print $1}'
}

assert_regular_file()
{
    [[ -f $1 && ! -L $1 ]] || fail "$2 is not a regular non-symlink file: $1"
}

assert_root_file()
{
    local path=$1 mode=$2 label=$3 resolved
    [[ $path == /* ]] || fail "$label path must be absolute"
    assert_safe_parent_chain "$(dirname -- "$path")" "$label"
    assert_regular_file "$path" "$label"
    resolved=$(readlink -f -- "$path")
    [[ $resolved == "$path" ]] || fail "$label path has a symlink component"
    [[ $(stat -c '%a:%u:%g' "$path") == "$mode:0:0" ]] \
        || fail "$label must be root-owned mode 0$mode"
}

assert_safe_parent_chain()
{
    local path=$1 current=/ component mode
    [[ $path == /* ]] || fail "$2 path must be absolute"
    IFS=/ read -r -a components <<< "${path#/}"
    for component in "${components[@]}"; do
        [[ -n $component ]] || continue
        current=${current%/}/$component
        [[ -e $current ]] || break
        [[ -d $current && ! -L $current ]] || fail "$2 has a non-directory or symlink parent: $current"
        [[ $(stat -c '%u:%g' "$current") == 0:0 ]] || fail "$2 parent is not root-owned: $current"
        mode=$(stat -c '%a' "$current")
        (((8#$mode & 8#022) == 0)) || fail "$2 parent is group/world writable: $current"
    done
}

ensure_root_directory()
{
    local path=$1 mode=$2 label=$3 parent
    assert_safe_parent_chain "$path" "$label"
    parent=$(dirname -- "$path")
    [[ -d $parent ]] || fail "$label parent directory does not exist"
    if [[ ! -e $path ]]; then
        install -d -m "$mode" -o root -g root "$path"
        sync "$parent"
    fi
    [[ -d $path && ! -L $path ]] || fail "$label is not a regular directory"
    [[ $(readlink -f -- "$path") == "$path" ]] || fail "$label has a symlink component"
    [[ $(stat -c '%a:%u:%g' "$path") == "$mode:0:0" ]] \
        || fail "$label must be root-owned mode 0$mode"
}

validate_identifier()
{
    [[ $2 =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ ]] \
        || fail "$1 is not a safe identifier"
}

validate_positive_integer()
{
    [[ $2 =~ ^[1-9][0-9]*$ ]] || fail "$1 must be a positive integer"
}

validate_port()
{
    validate_positive_integer "$1" "$2"
    ((10#$2 <= 65535)) || fail "$1 must be at most 65535"
}

validate_http_host_header_v2()
{
    [[ $2 != *$'\n'* && $2 != *$'\r'* \
        && $2 =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(:[1-9][0-9]{0,4})?$ ]] \
        || fail "$1 must be a safe DNS host with an optional port"
    if [[ $2 == *:* ]]; then
        validate_port "$1 port" "${2##*:}"
    fi
}

validate_ip_or_none()
{
    local setting=$1 address=$2 family=$3
    if [[ $address == none ]]; then
        return
    fi
    if [[ $family == 4 ]]; then
        [[ $address =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || fail "$setting must be an IPv4 address or none"
        ipcalc -c "$address" >/dev/null 2>&1 || fail "$setting is not a valid IPv4 address"
    else
        [[ $address == *:* && $address != *[' ']* ]] || fail "$setting must be an IPv6 address or none"
        ipcalc -c "$address" >/dev/null 2>&1 || fail "$setting is not a valid IPv6 address"
    fi
}

safe_state_value()
{
    local key=$1 file=$2 result
    result=$(sed -n "s/^${key}=//p" "$file")
    [[ $(awk -F= -v expected_key="$key" \
        '$1 == expected_key { count++ } END { print count + 0 }' "$file") -eq 1 ]] \
        || fail "state key is absent or duplicated: $key"
    [[ $result != *$'\n'* ]] || fail "state value contains a newline: $key"
    printf '%s\n' "$result"
}

atomic_replace()
{
    local candidate=$1 destination=$2 mode=$3
    chmod "$mode" "$candidate"
    sync "$candidate"
    mv -f -- "$candidate" "$destination"
    sync "$(dirname -- "$destination")"
}

validate_sha256()
{
    [[ $2 =~ ^[a-f0-9]{64}$ ]] || fail "$1 must be a lowercase SHA-256"
}

validate_token()
{
    [[ $2 =~ ^[A-Za-z0-9._:-]{16,128}$ ]] || fail "$1 is not a safe token"
}

validate_absolute_path()
{
    [[ $2 == /* && $2 != *'//'* && $2 =~ ^/[A-Za-z0-9_./-]+$ \
        && $2 != '/..' && $2 != '/../'* && $2 != *'/../'* && $2 != *'/..' \
        && $2 != '/.' && $2 != '/./'* && $2 != *'/./'* && $2 != *'/.' ]] \
        || fail "$1 is not a canonical absolute path"
}

pool_file_metadata()
{
    stat -c '%u:%g:%a:%s' -- "$1"
}

pool_file_identity()
{
    stat -c '%d:%i:%f:%u:%g:%a:%s' -- "$1"
}

pool_file_device_inode()
{
    stat -c '%d:%i' -- "$1"
}

assert_pool_file()
{
    local path=$1 expected_mode=$2 expected_uid=$3 expected_gid=$4 label=$5
    local expected_metadata=${6:-} metadata
    validate_absolute_path "$label path" "$path"
    assert_safe_parent_chain "$(dirname -- "$path")" "$label"
    assert_regular_file "$path" "$label"
    [[ $(readlink -f -- "$path") == "$path" ]] || fail "$label path has a symlink component"
    metadata=$(pool_file_metadata "$path")
    [[ $metadata == "$expected_uid:$expected_gid:$expected_mode:"* ]] \
        || fail "$label has incorrect ownership or mode"
    [[ -z $expected_metadata || $metadata == "$expected_metadata" ]] \
        || fail "$label differs from its pinned metadata"
}

read_pool_artifact()
{
    local path=$1 expected_sha256=$2 expected_metadata=$3 expected_mode=$4
    local expected_uid=$5 expected_gid=$6 label=$7 descriptor_path before open after bytes size
    assert_pool_file "$path" "$expected_mode" "$expected_uid" "$expected_gid" "$label" \
        "$expected_metadata"
    validate_sha256 "$label SHA-256" "$expected_sha256"
    before=$(pool_file_identity "$path")
    exec {pool_artifact_fd}<"$path" || fail "$label could not be opened"
    descriptor_path="/proc/self/fd/$pool_artifact_fd"
    [[ -e $descriptor_path ]] || descriptor_path="/dev/fd/$pool_artifact_fd"
    open=$(stat -Lc '%d:%i:%f:%u:%g:%a:%s' -- "$descriptor_path") \
        || fail "$label open descriptor identity cannot be read"
    [[ $open == "$before" ]] || fail "$label changed while it was opened"
    bytes=$(cat <&"$pool_artifact_fd")
    exec {pool_artifact_fd}<&-
    after=$(pool_file_identity "$path")
    [[ $after == "$before" ]] || fail "$label changed while it was read"
    size=${expected_metadata##*:}
    [[ $size == "${#bytes}" ]] || fail "$label must not contain a trailing newline"
    [[ $(printf '%s' "$bytes" | sha256sum | awk '{print $1}') == "$expected_sha256" ]] \
        || fail "$label bytes changed after it was opened"
    printf '%s\n' "$bytes"
}

snapshot_pool_document()
{
    local source=$1 expected_sha256=$2 destination=$3 label=$4 candidate
    local before open after descriptor_path
    validate_absolute_path "$label path" "$source"
    assert_pool_file "$source" 600 "$immutable_uid" "$immutable_gid" "$label"
    validate_sha256 "$label SHA-256" "$expected_sha256"
    candidate="$operation_directory/.${destination##*/}.$$"
    before=$(pool_file_identity "$source")
    exec {pool_document_fd}<"$source" || fail "$label could not be opened"
    descriptor_path="/proc/self/fd/$pool_document_fd"
    [[ -e $descriptor_path ]] || descriptor_path="/dev/fd/$pool_document_fd"
    open=$(stat -Lc '%d:%i:%f:%u:%g:%a:%s' -- "$descriptor_path") \
        || fail "$label open descriptor identity cannot be read"
    [[ $open == "$before" ]] || fail "$label changed while it was opened"
    cat <&"$pool_document_fd" > "$candidate"
    exec {pool_document_fd}<&-
    after=$(pool_file_identity "$source")
    [[ $after == "$before" ]] || fail "$label changed while it was snapshotted"
    [[ $(checksum "$candidate") == "$expected_sha256" ]] \
        || fail "$label differs from its pinned SHA-256"
    if [[ -e $destination ]]; then
        assert_pool_file "$destination" 600 "$immutable_uid" "$immutable_gid" \
            "durable $label"
        [[ $(checksum "$destination") == "$expected_sha256" ]] \
            || fail "durable $label snapshot differs from the requested document"
        rm -f -- "$candidate"
    else
        atomic_replace "$candidate" "$destination" 600
    fi
}

pool_manifest_value()
{
    local key=$1 count result
    count=$(grep -E -c "^${key}=" "$pool_manifest_document" || true)
    [[ $count -eq 1 ]] || fail "ingress-pool manifest key is absent or duplicated: $key"
    result=$(sed -n "s/^${key}=//p" "$pool_manifest_document")
    [[ $result != *$'\n'* ]] || fail "ingress-pool manifest value contains a newline: $key"
    printf '%s\n' "$result"
}

pool_plan_value()
{
    local key=$1 count result
    count=$(grep -E -c "^${key}=" "$pool_plan_document" || true)
    [[ $count -eq 1 ]] || fail "pool-plan manifest key is absent or duplicated: $key"
    result=$(sed -n "s/^${key}=//p" "$pool_plan_document")
    [[ $result != *$'\n'* ]] || fail "pool-plan manifest value contains a newline: $key"
    printf '%s\n' "$result"
}

pool_plan_member_keys()
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

pool_plan_keys_v2()
{
    printf '%s\n' version operation_id direction color generation coordination_volume \
        mutation_freeze_epoch mutation_freeze_marker_path mutation_lease_path \
        route_health_path route_health_token_file route_health_token_sha256 \
        route_health_token_metadata route_health_runtime_file route_health_runtime_sha256 \
        route_health_runtime_metadata pool_ack_file pool_ack_sha256 pool_ack_metadata \
        pool_ack_runtime_file pool_ack_runtime_sha256 pool_ack_runtime_metadata \
        backend_port member_count
    pool_plan_member_keys a
    pool_plan_member_keys b
    printf '%s\n' writer_member pool_label_key pool_label_value retired_member_count \
        retired_member_a_name retired_member_a_id
    if [[ $(pool_plan_value retired_member_count) == 2 ]]; then
        printf '%s\n' retired_member_b_name retired_member_b_id
    fi
    printf '%s\n' ingress_pool_manifest_path pool_plan_set_sha256
}

pool_member_keys_v2()
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

pool_manifest_keys_v2()
{
    printf '%s\n' version operation_id direction color generation parent_pool_plan_sha256 \
        pool_ack_file pool_ack_sha256 pool_ack_metadata member_count
    pool_member_keys_v2 a
    pool_member_keys_v2 b
    printf '%s\n' writer_member pool_label_key pool_label_value member_set_sha256 \
        route_health_path route_health_token_file route_health_token_sha256 \
        route_health_token_metadata
}

assert_exact_pool_keys()
{
    local document=$1 expected=$2 label=$3 actual
    actual=$(sed 's/=.*//' "$document")
    [[ $actual == "$expected" \
        && $(wc -l < "$document" | tr -d '[:space:]') \
            -eq $(wc -l <<< "$expected" | tr -d '[:space:]') ]] \
        || fail "$label keys are reordered, duplicated, absent, or unknown"
}

compute_pool_plan_set_sha256_v2()
{
    local member key
    for member in a b; do
        while IFS= read -r key; do
            printf '%s\0' "$(pool_plan_value "$key")"
        done < <(pool_plan_member_keys "$member")
    done
    printf '%s\0' "$(pool_plan_value writer_member)"
}

compute_member_set_sha256_v2()
{
    local member key
    printf '%s\0' "$(pool_manifest_value generation)" \
        "$(pool_manifest_value parent_pool_plan_sha256)"
    for member in a b; do
        while IFS= read -r key; do
            printf '%s\0' "$(pool_manifest_value "$key")"
        done < <(pool_member_keys_v2 "$member")
    done
    printf '%s\0' "$(pool_manifest_value writer_member)" \
        "$(pool_manifest_value pool_label_key)" \
        "$(pool_manifest_value pool_label_value)"
}

pool_artifact_bytes()
{
    local document=$1 prefix=$2 expected_mode=$3 expected_uid=$4 expected_gid=$5 label=$6
    local path sha256 metadata
    if [[ $document == plan ]]; then
        path=$(pool_plan_value "${prefix}_file")
        sha256=$(pool_plan_value "${prefix}_sha256")
        metadata=$(pool_plan_value "${prefix}_metadata")
    else
        path=$(pool_manifest_value "${prefix}_file")
        sha256=$(pool_manifest_value "${prefix}_sha256")
        metadata=$(pool_manifest_value "${prefix}_metadata")
    fi
    validate_absolute_path "$label path" "$path"
    read_pool_artifact "$path" "$sha256" "$metadata" "$expected_mode" \
        "$expected_uid" "$expected_gid" "$label"
}

pool_plan_artifact_path()
{
    pool_plan_value "${1}_file"
}

assert_distinct_pool_values()
{
    local label=$1 first second
    shift
    while (($#)); do
        first=$1
        shift
        for second in "$@"; do
            [[ $first != "$second" ]] || fail "$label contains a duplicate value"
        done
    done
}

validate_network_id_set()
{
    local label=$1 network_ids=$2
    [[ $network_ids =~ ^[a-f0-9]{64}(,[a-f0-9]{64})*$ ]] \
        || fail "$label is malformed"
    [[ $(tr ',' '\n' <<< "$network_ids" | LC_ALL=C sort -u | paste -sd, -) == "$network_ids" ]] \
        || fail "$label is not sorted and unique"
}

validate_pool_plan_v2()
{
    local expected_keys direction retired_count member role key writer_member
    local route_health_runtime pool_ack_runtime direct_root direct_runtime ack_runtime
    local volume writer_marker
    local -a marker_authorities=()
    expected_keys=$(pool_plan_keys_v2)
    assert_exact_pool_keys "$pool_plan_document" "$expected_keys" pool-plan-manifest
    assert_pool_plan_header_v2
    direction=$(pool_plan_value direction)
    [[ $direction =~ ^(bootstrap-forward|forward|reverse)$ \
        && $(pool_plan_value color) =~ ^(green|blue)$ \
        && $(pool_plan_value generation) =~ ^[1-9][0-9]*$ ]] \
        || fail 'pool plan direction, color, or generation is malformed'
    validate_port pool-plan-backend-port "$(pool_plan_value backend_port)"
    validate_identifier pool-plan-coordination-volume "$(pool_plan_value coordination_volume)"
    validate_token pool-plan-mutation-freeze-epoch "$(pool_plan_value mutation_freeze_epoch)"
    validate_identifier pool-plan-label-key "$(pool_plan_value pool_label_key)"
    validate_identifier pool-plan-label-value "$(pool_plan_value pool_label_value)"
    [[ $(pool_plan_value route_health_path) == /api/control-plane/route-health ]] \
        || fail 'pool plan route-health path is not canonical'
    for key in mutation_freeze_marker_path mutation_lease_path ingress_pool_manifest_path; do
        validate_absolute_path "pool-plan $key" "$(pool_plan_value "$key")"
    done
    route_health_runtime=$(pool_artifact_bytes plan route_health_runtime 400 \
        "$runtime_artifact_uid" "$runtime_artifact_gid" pool-plan-route-health-runtime)
    pool_ack_runtime=$(pool_artifact_bytes plan pool_ack_runtime 400 \
        "$runtime_artifact_uid" "$runtime_artifact_gid" pool-plan-pool-ack-runtime)
    [[ $(pool_plan_value route_health_token_file) \
            != "$(pool_plan_value route_health_runtime_file)" \
        && $(pool_plan_value route_health_token_sha256) \
            == "$(pool_plan_value route_health_runtime_sha256)" \
        && $(pool_plan_value pool_ack_file) != "$(pool_plan_value pool_ack_runtime_file)" \
        && $(pool_plan_value pool_ack_sha256) == "$(pool_plan_value pool_ack_runtime_sha256)" \
        && $route_health_runtime == "$pool_route_health_token" \
        && $pool_ack_runtime == "$pool_acknowledgement" ]] \
        || fail 'pool plan root/runtime route-health artifact pairs are not distinct and byte-identical'
    writer_member=$(pool_plan_value writer_member)
    [[ $writer_member == web-a || $writer_member == web-b ]] \
        || fail 'pool plan must declare exactly one writer member'
    for member in a b; do
        role=$(pool_plan_value "member_${member}_role")
        [[ $role == "web-$member" ]] \
            || fail 'pool plan member roles are not canonical web-a/web-b'
        validate_identifier "pool-plan member-$member name" \
            "$(pool_plan_value "member_${member}_name")"
        validate_token "pool-plan member-$member route identity" \
            "$(pool_plan_value "member_${member}_route_identity")"
        validate_identifier "pool-plan member-$member private volume" \
            "$(pool_plan_value "member_${member}_private_volume")"
        [[ $(pool_plan_value "member_${member}_image_reference") \
                =~ ^[A-Za-z0-9][A-Za-z0-9._/:@-]*@sha256:[a-f0-9]{64}$ \
            && $(pool_plan_value "member_${member}_image_id") =~ ^sha256:[a-f0-9]{64}$ ]] \
            || fail "pool plan member $member image identity is malformed"
        validate_network_id_set "pool plan member $member network IDs" \
            "$(pool_plan_value "member_${member}_network_ids")"
        validate_port "pool plan member $member loopback port" \
            "$(pool_plan_value "member_${member}_expected_loopback_port")"
        validate_token "pool-plan member-$member web epoch" \
            "$(pool_plan_value "member_${member}_web_epoch")"
        validate_token "pool-plan member-$member route-drain epoch" \
            "$(pool_plan_value "member_${member}_route_drain_epoch")"
        for key in repin_intent_file web_marker_path route_drain_marker_path; do
            validate_absolute_path "pool-plan member-$member $key" \
                "$(pool_plan_value "member_${member}_${key}")"
        done
        direct_root=$(pool_artifact_bytes plan "member_${member}_direct_probe_token" 600 \
            "$immutable_uid" "$immutable_gid" "pool-plan-web-$member-direct-probe-root")
        direct_runtime=$(pool_artifact_bytes plan "member_${member}_direct_probe_runtime" 400 \
            "$runtime_artifact_uid" "$runtime_artifact_gid" \
            "pool-plan-web-$member-direct-probe-runtime")
        ack_runtime=$(pool_artifact_bytes plan "member_${member}_applied_ack_runtime" 400 \
            "$runtime_artifact_uid" "$runtime_artifact_gid" \
            "pool-plan-web-$member-applied-ack-runtime")
        [[ $(pool_plan_value "member_${member}_direct_probe_token_file") \
                != "$(pool_plan_value "member_${member}_direct_probe_runtime_file")" \
            && $(pool_plan_value "member_${member}_direct_probe_token_sha256") \
                == "$(pool_plan_value "member_${member}_direct_probe_runtime_sha256")" \
            && $(pool_plan_value "member_${member}_applied_ack_file") \
                != "$(pool_plan_value "member_${member}_applied_ack_runtime_file")" \
            && $(pool_plan_value "member_${member}_applied_ack_sha256") \
                == "$(pool_plan_value "member_${member}_applied_ack_runtime_sha256")" \
            && $direct_root == "$direct_runtime" ]] \
            || fail "pool plan web-$member root/runtime artifacts are not distinct and byte-identical"
        if [[ $member == a ]]; then
            [[ $ack_runtime == "$pool_member_a_applied_ack" ]] \
                || fail 'pool plan web-a runtime acknowledgement differs from its root copy'
        else
            [[ $ack_runtime == "$pool_member_b_applied_ack" ]] \
                || fail 'pool plan web-b runtime acknowledgement differs from its root copy'
        fi
        if [[ $role == "$writer_member" ]]; then
            validate_token "pool-plan member-$member writer epoch" \
                "$(pool_plan_value "member_${member}_writer_epoch")"
            validate_absolute_path "pool-plan member-$member writer marker" \
                "$(pool_plan_value "member_${member}_writer_marker_path")"
        else
            [[ $(pool_plan_value "member_${member}_writer_epoch") == absent \
                && $(pool_plan_value "member_${member}_writer_marker_path") == absent ]] \
                || fail 'non-writer member has writer marker authority'
        fi
    done
    assert_distinct_pool_values 'pool plan member identity' \
        "$(pool_plan_value member_a_name)" "$(pool_plan_value member_b_name)"
    assert_distinct_pool_values 'pool plan route identity' \
        "$(pool_plan_value member_a_route_identity)" \
        "$(pool_plan_value member_b_route_identity)"
    assert_distinct_pool_values 'pool plan loopback port' \
        "$(pool_plan_value member_a_expected_loopback_port)" \
        "$(pool_plan_value member_b_expected_loopback_port)"
    assert_distinct_pool_values 'pool plan volume authority' \
        "$(pool_plan_value coordination_volume)" \
        "$(pool_plan_value member_a_private_volume)" \
        "$(pool_plan_value member_b_private_volume)"
    assert_distinct_pool_values 'pool plan direct-probe content' \
        "$(pool_plan_value member_a_direct_probe_token_sha256)" \
        "$(pool_plan_value member_b_direct_probe_token_sha256)"
    assert_distinct_pool_values 'pool plan applied acknowledgement content' \
        "$(pool_plan_value member_a_applied_ack_sha256)" \
        "$(pool_plan_value member_b_applied_ack_sha256)"
    assert_distinct_pool_values 'pool plan web epoch' \
        "$(pool_plan_value member_a_web_epoch)" \
        "$(pool_plan_value member_b_web_epoch)"
    assert_distinct_pool_values 'pool plan drain epoch' \
        "$(pool_plan_value member_a_route_drain_epoch)" \
        "$(pool_plan_value member_b_route_drain_epoch)"
    assert_distinct_pool_values 'pool plan artifact device/inode identity' \
        "$(pool_file_device_inode "$pool_plan_manifest_source")" \
        "$(pool_file_device_inode "$(pool_plan_artifact_path route_health_token)")" \
        "$(pool_file_device_inode "$(pool_plan_artifact_path route_health_runtime)")" \
        "$(pool_file_device_inode "$(pool_plan_artifact_path pool_ack)")" \
        "$(pool_file_device_inode "$(pool_plan_artifact_path pool_ack_runtime)")" \
        "$(pool_file_device_inode "$(pool_plan_artifact_path member_a_direct_probe_token)")" \
        "$(pool_file_device_inode "$(pool_plan_artifact_path member_a_direct_probe_runtime)")" \
        "$(pool_file_device_inode "$(pool_plan_artifact_path member_a_applied_ack)")" \
        "$(pool_file_device_inode "$(pool_plan_artifact_path member_a_applied_ack_runtime)")" \
        "$(pool_file_device_inode "$(pool_plan_artifact_path member_b_direct_probe_token)")" \
        "$(pool_file_device_inode "$(pool_plan_artifact_path member_b_direct_probe_runtime)")" \
        "$(pool_file_device_inode "$(pool_plan_artifact_path member_b_applied_ack)")" \
        "$(pool_file_device_inode "$(pool_plan_artifact_path member_b_applied_ack_runtime)")"
    assert_distinct_pool_values 'pool plan authority path' \
        "$pool_plan_manifest_source" \
        "$(pool_plan_value mutation_freeze_marker_path)" \
        "$(pool_plan_value mutation_lease_path)" \
        "$(pool_plan_value ingress_pool_manifest_path)" \
        "$(pool_plan_value route_health_token_file)" \
        "$(pool_plan_value route_health_runtime_file)" \
        "$(pool_plan_value pool_ack_file)" \
        "$(pool_plan_value pool_ack_runtime_file)" \
        "$(pool_plan_value member_a_repin_intent_file)" \
        "$(pool_plan_value member_b_repin_intent_file)" \
        "$(pool_plan_value member_a_direct_probe_token_file)" \
        "$(pool_plan_value member_b_direct_probe_token_file)" \
        "$(pool_plan_value member_a_direct_probe_runtime_file)" \
        "$(pool_plan_value member_b_direct_probe_runtime_file)" \
        "$(pool_plan_value member_a_applied_ack_file)" \
        "$(pool_plan_value member_b_applied_ack_file)" \
        "$(pool_plan_value member_a_applied_ack_runtime_file)" \
        "$(pool_plan_value member_b_applied_ack_runtime_file)"
    for member in a b; do
        volume=$(pool_plan_value "member_${member}_private_volume")
        marker_authorities+=(
            "$volume|$(pool_plan_value "member_${member}_web_marker_path")"
            "$volume|$(pool_plan_value "member_${member}_route_drain_marker_path")"
        )
        writer_marker=$(pool_plan_value "member_${member}_writer_marker_path")
        [[ $writer_marker == absent ]] || marker_authorities+=("$volume|$writer_marker")
    done
    assert_distinct_pool_values 'pool plan private marker authority tuple' \
        "${marker_authorities[@]}"
    retired_count=$(pool_plan_value retired_member_count)
    case "$direction:$retired_count" in
        bootstrap-forward:1|forward:2|reverse:2) ;;
        *) fail 'pool plan retired set contradicts its migration direction' ;;
    esac
    validate_identifier pool-plan-retired-member-a-name \
        "$(pool_plan_value retired_member_a_name)"
    validate_sha256 pool-plan-retired-member-a-id "$(pool_plan_value retired_member_a_id)"
    if [[ $retired_count == 2 ]]; then
        validate_identifier pool-plan-retired-member-b-name \
            "$(pool_plan_value retired_member_b_name)"
        validate_sha256 pool-plan-retired-member-b-id "$(pool_plan_value retired_member_b_id)"
        assert_distinct_pool_values 'pool plan retired member name' \
            "$(pool_plan_value retired_member_a_name)" \
            "$(pool_plan_value retired_member_b_name)"
        assert_distinct_pool_values 'pool plan retired member ID' \
            "$(pool_plan_value retired_member_a_id)" \
            "$(pool_plan_value retired_member_b_id)"
    fi
    [[ $(pool_plan_value member_a_name) != "$(pool_plan_value retired_member_a_name)" \
        && $(pool_plan_value member_b_name) != "$(pool_plan_value retired_member_a_name)" ]] \
        || fail 'planned member name collides with retired member A'
    if [[ $retired_count == 2 ]]; then
        [[ $(pool_plan_value member_a_name) != "$(pool_plan_value retired_member_b_name)" \
            && $(pool_plan_value member_b_name) != "$(pool_plan_value retired_member_b_name)" ]] \
            || fail 'planned member name collides with retired member B'
    fi
    validate_sha256 pool-plan-set "$(pool_plan_value pool_plan_set_sha256)"
    [[ $(compute_pool_plan_set_sha256_v2 | sha256sum | awk '{print $1}') \
        == "$(pool_plan_value pool_plan_set_sha256)" ]] \
        || fail 'pool plan set hash does not match its canonical NUL-delimited member tuple'
}

assert_pool_plan_header_v2()
{
    [[ $(pool_plan_value version) == 2 \
        && $(pool_plan_value operation_id) == "$operation_id" \
        && $(pool_plan_value member_count) == 2 ]] \
        || fail 'pool plan does not belong to this exact two-member operation'
}

assert_pool_manifest_header_v2()
{
    [[ $(pool_manifest_value version) == 2 \
        && $(pool_manifest_value operation_id) == "$operation_id" \
        && $(pool_manifest_value member_count) == 2 ]] \
        || fail 'ingress pool does not belong to this exact two-member operation'
}

load_pool_manifest_v2()
{
    local expected_keys member key address image_id
    pool_manifest_source=$pool_manifest
    snapshot_pool_document "$pool_manifest_source" "$pool_manifest_sha256" \
        "$pool_manifest_snapshot" ingress-pool-manifest
    pool_manifest_document=$pool_manifest_snapshot
    expected_keys=$(pool_manifest_keys_v2)
    assert_exact_pool_keys "$pool_manifest_document" "$expected_keys" ingress-pool-manifest
    assert_pool_manifest_header_v2
    pool_direction=$(pool_manifest_value direction)
    pool_color=$(pool_manifest_value color)
    pool_generation=$(pool_manifest_value generation)
    pool_parent_plan_sha256=$(pool_manifest_value parent_pool_plan_sha256)
    [[ $pool_direction =~ ^(bootstrap-forward|forward|reverse)$ \
        && $pool_color =~ ^(green|blue)$ ]] \
        || fail 'ingress pool direction or color is invalid'
    validate_positive_integer ingress-pool-generation "$pool_generation"
    validate_sha256 ingress-pool-parent-plan "$pool_parent_plan_sha256"
    [[ $pool_color == "$color" && $pool_generation == "$requested_generation" ]] \
        || fail 'requested color or generation differs from the ingress pool'
    for member in a b; do
        [[ $(pool_manifest_value "member_${member}_role") == "web-$member" ]] \
            || fail 'ingress pool members are not canonically ordered web-a then web-b'
        validate_identifier "ingress member $member name" \
            "$(pool_manifest_value "member_${member}_name")"
        validate_token "ingress member $member route identity" \
            "$(pool_manifest_value "member_${member}_route_identity")"
        validate_sha256 "ingress member $member ID" \
            "$(pool_manifest_value "member_${member}_id")"
        address=$(pool_manifest_value "member_${member}_address")
        [[ $address =~ ^[A-Fa-f0-9:.]+$ ]] \
            || fail "ingress member $member address is not numeric"
        ipcalc -c "$address" >/dev/null 2>&1 \
            || fail "ingress member $member address is invalid"
        validate_port "ingress member $member port" \
            "$(pool_manifest_value "member_${member}_port")"
        [[ $(pool_manifest_value "member_${member}_image_reference") \
                =~ ^[A-Za-z0-9][A-Za-z0-9._/:@-]*@sha256:[a-f0-9]{64}$ ]] \
            || fail "ingress member $member image reference is not immutable"
        image_id=$(pool_manifest_value "member_${member}_image_id")
        [[ $image_id == sha256:* ]] || fail "ingress member $member image ID is malformed"
        validate_sha256 "ingress member $member image ID" "${image_id#sha256:}"
        for key in runtime_sha256 network_sha256 bindings_sha256 applied_ack_sha256; do
            validate_sha256 "ingress member $member $key" \
                "$(pool_manifest_value "member_${member}_${key}")"
        done
    done
    assert_distinct_pool_values 'ingress pool member name' \
        "$(pool_manifest_value member_a_name)" "$(pool_manifest_value member_b_name)"
    assert_distinct_pool_values 'ingress pool member ID' \
        "$(pool_manifest_value member_a_id)" "$(pool_manifest_value member_b_id)"
    assert_distinct_pool_values 'ingress pool route identity' \
        "$(pool_manifest_value member_a_route_identity)" \
        "$(pool_manifest_value member_b_route_identity)"
    assert_distinct_pool_values 'ingress route and Docker identity' \
        "$(pool_manifest_value member_a_route_identity)" \
        "$(pool_manifest_value member_b_route_identity)" \
        "$(pool_manifest_value member_a_id)" \
        "$(pool_manifest_value member_b_id)"
    assert_distinct_pool_values 'ingress pool endpoint' \
        "$(pool_manifest_value member_a_address):$(pool_manifest_value member_a_port)" \
        "$(pool_manifest_value member_b_address):$(pool_manifest_value member_b_port)"
    pool_writer_member=$(pool_manifest_value writer_member)
    [[ $pool_writer_member == web-a || $pool_writer_member == web-b ]] \
        || fail 'ingress pool must identify exactly one writer member'
    validate_identifier ingress-pool-label-key "$(pool_manifest_value pool_label_key)"
    validate_identifier ingress-pool-label-value "$(pool_manifest_value pool_label_value)"
    [[ $(pool_manifest_value route_health_path) == /api/control-plane/route-health ]] \
        || fail 'ingress pool route-health path is not canonical'
    pool_member_set_sha256=$(pool_manifest_value member_set_sha256)
    validate_sha256 ingress-member-set "$pool_member_set_sha256"
    [[ $(compute_member_set_sha256_v2 | sha256sum | awk '{print $1}') \
        == "$pool_member_set_sha256" ]] \
        || fail 'ingress member-set hash does not match its canonical NUL-delimited tuple'
    pool_acknowledgement=$(pool_artifact_bytes manifest pool_ack 600 \
        "$immutable_uid" "$immutable_gid" ingress-pool-ack)
    pool_route_health_token=$(pool_artifact_bytes manifest route_health_token 600 \
        "$immutable_uid" "$immutable_gid" ingress-route-health-token)
    pool_member_a_applied_ack=$(pool_artifact_bytes manifest member_a_applied_ack 600 \
        "$immutable_uid" "$immutable_gid" ingress-web-a-applied-ack)
    pool_member_b_applied_ack=$(pool_artifact_bytes manifest member_b_applied_ack 600 \
        "$immutable_uid" "$immutable_gid" ingress-web-b-applied-ack)
    validate_token ingress-pool-ack "$pool_acknowledgement"
    validate_token ingress-route-health-token "$pool_route_health_token"
    validate_token ingress-web-a-applied-ack "$pool_member_a_applied_ack"
    validate_token ingress-web-b-applied-ack "$pool_member_b_applied_ack"
    assert_distinct_pool_values 'ingress artifact device/inode identity' \
        "$(pool_file_device_inode "$pool_manifest_source")" \
        "$(pool_file_device_inode "$(pool_manifest_value pool_ack_file)")" \
        "$(pool_file_device_inode "$(pool_manifest_value route_health_token_file)")" \
        "$(pool_file_device_inode "$(pool_manifest_value member_a_applied_ack_file)")" \
        "$(pool_file_device_inode "$(pool_manifest_value member_b_applied_ack_file)")"
}

load_pool_plan_v2()
{
    local key member
    snapshot_pool_document "$pool_plan_manifest_source" "$pool_plan_manifest_sha256" \
        "$pool_plan_snapshot" pool-plan-manifest
    pool_plan_document=$pool_plan_snapshot
    validate_pool_plan_v2
    [[ $(pool_file_device_inode "$pool_plan_manifest_source") \
        != "$(pool_file_device_inode "$pool_manifest_source")" ]] \
        || fail 'pool plan and ingress-pool manifest share one file identity'
    [[ $pool_plan_manifest_sha256 == "$pool_parent_plan_sha256" \
        && $(pool_plan_value ingress_pool_manifest_path) == "$pool_manifest_source" \
        && $(pool_plan_value direction) == "$pool_direction" \
        && $(pool_plan_value color) == "$pool_color" \
        && $(pool_plan_value generation) == "$pool_generation" \
        && $(pool_plan_value writer_member) == "$pool_writer_member" \
        && $(pool_plan_value pool_label_key) == "$(pool_manifest_value pool_label_key)" \
        && $(pool_plan_value pool_label_value) == "$(pool_manifest_value pool_label_value)" ]] \
        || fail 'ingress pool does not descend from the exact supplied pool plan'
    for key in pool_ack_file pool_ack_sha256 pool_ack_metadata route_health_path \
        route_health_token_file route_health_token_sha256 route_health_token_metadata; do
        [[ $(pool_plan_value "$key") == "$(pool_manifest_value "$key")" ]] \
            || fail "ingress pool changed a plan-owned semantic input: $key"
    done
    for member in a b; do
        for key in role name route_identity image_reference image_id applied_ack_file \
            applied_ack_sha256 applied_ack_metadata; do
            [[ $(pool_plan_value "member_${member}_${key}") \
                == "$(pool_manifest_value "member_${member}_${key}")" ]] \
                || fail "ingress pool member $member changed plan-owned identity: $key"
        done
        [[ $(pool_manifest_value "member_${member}_port") == "$(pool_plan_value backend_port)" ]] \
            || fail "ingress pool member $member port differs from the plan backend port"
    done
    pool_member_a_loopback_port=$(pool_plan_value member_a_expected_loopback_port)
    pool_member_b_loopback_port=$(pool_plan_value member_b_expected_loopback_port)
    assert_distinct_pool_values 'pool loopback endpoint' \
        "$pool_loopback_address:$pool_member_a_loopback_port" \
        "$pool_loopback_address:$pool_member_b_loopback_port"
    pool_member_a_route_identity=$(pool_manifest_value member_a_route_identity)
    pool_member_b_route_identity=$(pool_manifest_value member_b_route_identity)
    pool_member_a_drain_epoch=$(pool_plan_value member_a_route_drain_epoch)
    pool_member_b_drain_epoch=$(pool_plan_value member_b_route_drain_epoch)
}

compute_pool_route_ack_v2()
{
    local identity
    identity=$(printf '%s\0%s\0%s\0%s\0%s\0%s\0' \
        "$pool_manifest_sha256" "$pool_direction" "$pool_color" "$pool_generation" \
        "$pool_parent_plan_sha256" "$pool_member_set_sha256" \
        | sha256sum | awk '{print $1}')
    printf 'cp-pool-v2.%s\n' "$identity"
}

compute_pool_drain_ack_v2()
{
    local drained_role=$1 drain_epoch=$2 identity
    identity=$(printf '%s\0%s\0%s\0%s\0%s\0%s\0%s\0%s\0' \
        "$pool_manifest_sha256" "$pool_direction" "$pool_color" "$pool_generation" \
        "$pool_parent_plan_sha256" "$pool_member_set_sha256" "$drained_role" \
        "$drain_epoch" | sha256sum | awk '{print $1}')
    printf 'cp-pool-v2-drain.%s\n' "$identity"
}

load_pool_inputs_v2()
{
    load_pool_manifest_v2
    load_pool_plan_v2
    pool_route_ack=$(compute_pool_route_ack_v2)
    pool_member_a_drain_ack=$(compute_pool_drain_ack_v2 web-a "$pool_member_a_drain_epoch")
    pool_member_b_drain_ack=$(compute_pool_drain_ack_v2 web-b "$pool_member_b_drain_epoch")
}

test_crash()
{
    local crash_at=${CONTROL_PLANE_INGRESS_TEST_CRASH_AT:-${CONTROL_PLANE_TEST_PORT8000_CRASH_AT:-}}
    [[ $crash_at != "$1" ]] || kill -KILL "$$"
}

read_probe_token()
{
    local token size
    assert_root_file "$direct_probe_token_file" 600 CONTROL_PLANE_INGRESS_DIRECT_PROBE_TOKEN_FILE
    token=$(<"$direct_probe_token_file")
    size=$(wc -c < "$direct_probe_token_file" | tr -d '[:space:]')
    [[ $size == "${#token}" ]] || fail 'direct-probe token must not contain a newline'
    [[ $token =~ ^[A-Za-z0-9._:-]{16,128}$ ]] || fail 'direct-probe token is not a safe token'
    printf '%s\n' "$token"
}

host_local_path()
{
    local without_scheme=${host_local_url#http://} path
    [[ $without_scheme != "$host_local_url" ]] || fail 'host-local :8000 URL must use plain HTTP'
    [[ $without_scheme != *@* ]] || fail 'host-local :8000 URL must not contain credentials'
    if [[ $without_scheme == */* ]]; then
        path=/${without_scheme#*/}
    else
        path=/
    fi
    printf '%s\n' "$path"
}

assert_host_local_url()
{
    local authority=${host_local_url#http://}
    authority=${authority%%/*}
    [[ $authority == 127.0.0.1:8000 || $authority == '[::1]:8000' ]] \
        || fail 'host-local probe URL must bind resolution to exact loopback 127.0.0.1 or ::1 on TCP/8000'
    [[ $host_local_url != *[' ']* && $host_local_url != *$'\n'* ]] \
        || fail 'host-local probe URL contains unsafe whitespace'
}

probe_url_for_path()
{
    local base=${1%%\?*} without_scheme authority
    without_scheme=${base#http://}
    authority=${without_scheme%%/*}
    printf 'http://%s%s\n' "$authority" "$direct_probe_path"
}

assert_single_ack_header()
{
    local headers=$1 expected=$2 count observed
    count=$(awk -F: 'tolower($1) == "x-control-plane-applied-config" { count++ } \
        END { print count + 0 }' "$headers")
    [[ $count -eq 1 ]] || return 1
    observed=$(awk -F: 'tolower($1) == "x-control-plane-applied-config" {
        value = substr($0, index($0, ":") + 1)
        sub(/^[[:space:]]*/, "", value)
        sub(/\r$/, "", value)
        print value
    }' "$headers")
    [[ $observed == "$expected" ]]
}

assert_single_owner_header()
{
    local headers=$1 expected=$2 count observed
    count=$(awk -F: 'tolower($1) == "x-control-plane-port-owner" { count++ } \
        END { print count + 0 }' "$headers")
    [[ $count -eq 1 ]] || return 1
    observed=$(awk -F: 'tolower($1) == "x-control-plane-port-owner" {
        value = substr($0, index($0, ":") + 1)
        sub(/^[[:space:]]*/, "", value)
        sub(/\r$/, "", value)
        print value
    }' "$headers")
    [[ $observed == "$expected" ]]
}

write_probe_header_file()
{
    local destination=$1 token
    token=$(read_probe_token)
    printf 'X-Control-Plane-Probe: %s\n' "$token" > "$destination"
    chmod 600 "$destination"
}

inventory_values()
{
    sed -n "s/^$1=//p" "$ipv6_inventory_file"
}

assert_inventory_group()
{
    local key=$1 values
    values=$(inventory_values "$key")
    [[ -n $values ]] || fail "IPv6 inventory group is absent: $key"
    if grep -F -x -q none <<< "$values"; then
        [[ $values == none ]] || fail "IPv6 inventory none is not exclusive: $key"
    else
        [[ $(LC_ALL=C sort -u <<< "$values") == "$values" ]] \
            || fail "IPv6 inventory values are not unique and sorted: $key"
    fi
}

assert_ipv6_inventory()
{
    local actual_checksum address_count denial_count evidence_host inventory_host inventory_timestamp
    local now probe_output route_hash routes values
    local -a inventory_address=() denial_endpoint=() live_interface=() live_dns=()
    require_value CONTROL_PLANE_PORT8000_IPV6_INVENTORY_FILE "$ipv6_inventory_file"
    require_value CONTROL_PLANE_PORT8000_IPV6_INVENTORY_SHA256 "$ipv6_inventory_sha256"
    require_value CONTROL_PLANE_PORT8000_IPV6_INVENTORY_PROBE "$ipv6_inventory_probe"
    require_value CONTROL_PLANE_PORT8000_IPV6_INVENTORY_PROBE_SHA256 "$ipv6_inventory_probe_sha256"
    assert_root_file "$ipv6_inventory_file" 600 CONTROL_PLANE_PORT8000_IPV6_INVENTORY_FILE
    assert_root_file "$ipv6_inventory_probe" 700 CONTROL_PLANE_PORT8000_IPV6_INVENTORY_PROBE
    [[ -x $ipv6_inventory_probe ]] || fail 'IPv6 inventory probe must be executable'
    [[ $ipv6_inventory_sha256 =~ ^[a-f0-9]{64}$ \
        && $ipv6_inventory_probe_sha256 =~ ^[a-f0-9]{64}$ ]] \
        || fail 'IPv6 inventory and probe checksums must be lowercase SHA-256'
    actual_checksum=$(checksum "$ipv6_inventory_file")
    [[ $actual_checksum == "$ipv6_inventory_sha256" \
        && $(checksum "$ipv6_inventory_probe") == "$ipv6_inventory_probe_sha256" ]] \
        || fail 'IPv6 inventory or authoritative probe differs from its reviewed checksum'
    [[ $(grep -E -c '^(version|host|observed_at_epoch|interface_global_ipv6|default_route_sha256|dns_aaaa|provider_endpoint|external_vantage|external_denial)=' "$ipv6_inventory_file") \
        -eq $(wc -l < "$ipv6_inventory_file" | tr -d '[:space:]') ]] \
        || fail 'IPv6 inventory contains an unknown or malformed record'
    [[ $(inventory_values version) == 1 \
        && $(grep -F -c 'version=' "$ipv6_inventory_file") -eq 1 \
        && $(grep -F -c 'host=' "$ipv6_inventory_file") -eq 1 \
        && $(grep -F -c 'observed_at_epoch=' "$ipv6_inventory_file") -eq 1 \
        && $(grep -F -c 'default_route_sha256=' "$ipv6_inventory_file") -eq 1 ]] \
        || fail 'IPv6 inventory singleton metadata is absent or duplicated'
    [[ $(sed -n '1p' "$ipv6_inventory_file") == version=1 \
        && $(sed -n '2p' "$ipv6_inventory_file") == host=* \
        && $(sed -n '3p' "$ipv6_inventory_file") == observed_at_epoch=* \
        && $(sed -n '4p' "$ipv6_inventory_file") == default_route_sha256=* ]] \
        || fail 'IPv6 inventory singleton metadata is not in canonical order'
    awk -F= '
        BEGIN { rank["interface_global_ipv6"]=1; rank["dns_aaaa"]=2; rank["provider_endpoint"]=3; rank["external_vantage"]=4; rank["external_denial"]=5 }
        NR > 4 { if (!( $1 in rank ) || rank[$1] < previous) exit 1; previous=rank[$1] }
    ' "$ipv6_inventory_file" || fail 'IPv6 inventory address groups are not in canonical order'
    evidence_host=${public_host_header%:8000}
    inventory_host=$(inventory_values host)
    [[ $inventory_host == "$evidence_host" ]] || fail 'IPv6 inventory belongs to another public host'
    inventory_timestamp=$(inventory_values observed_at_epoch)
    [[ $inventory_timestamp =~ ^[1-9][0-9]{9}$ ]] || fail 'IPv6 inventory timestamp is malformed'
    now=$(date -u +%s)
    ((inventory_timestamp <= now + 30)) \
        || fail 'IPv6 inventory timestamp is unreasonably far in the future'
    for key in interface_global_ipv6 dns_aaaa provider_endpoint external_vantage external_denial; do
        assert_inventory_group "$key"
    done

    values=$(inventory_values interface_global_ipv6)
    mapfile -t live_interface < <(
        ip -o -6 address show scope global \
            | awk '{sub(/\/.*/, "", $4); if (tolower(substr($4, 1, 1)) ~ /^[23]$/) print tolower($4)}' \
            | LC_ALL=C sort -u
    )
    [[ ${live_interface[*]:-none} == "${values//$'\n'/ }" ]] \
        || { [[ ${#live_interface[@]} -eq 0 && $values == none ]] \
            || fail 'IPv6 inventory does not match all live globally routable interface addresses'; }
    values=$(inventory_values dns_aaaa)
    mapfile -t live_dns < <(getent ahostsv6 "$inventory_host" 2>/dev/null \
        | awk '$1 ~ /:/ {print tolower($1)}' | LC_ALL=C sort -u)
    [[ ${live_dns[*]:-none} == "${values//$'\n'/ }" ]] \
        || { [[ ${#live_dns[@]} -eq 0 && $values == none ]] \
            || fail 'IPv6 inventory does not match the complete live DNS AAAA set'; }
    routes=$(ip -6 route show default | sed 's/[[:space:]]\+/ /g; s/^ //; s/ $//' | LC_ALL=C sort -u)
    if [[ -z $routes ]]; then
        route_hash=none
    else
        route_hash=$(printf '%s\n' "$routes" | sha256sum | awk '{print $1}')
    fi
    [[ $(inventory_values default_route_sha256) == "$route_hash" ]] \
        || fail 'IPv6 inventory default-route identity differs from the live host'

    mapfile -t inventory_address < <(
        for key in interface_global_ipv6 dns_aaaa provider_endpoint external_vantage; do
            inventory_values "$key"
        done | grep -F -x -v none | LC_ALL=C sort -u
    )
    for address in "${inventory_address[@]}"; do
        if [[ $address != *:* || $address != "${address,,}" ]] \
            || ! ipcalc -c "$address" >/dev/null 2>&1; then
            fail 'IPv6 inventory contains a non-canonical address'
        fi
    done
    mapfile -t denial_endpoint < <(inventory_values external_denial)
    if ((${#inventory_address[@]} == 0)); then
        [[ ${denial_endpoint[*]} == none ]] \
            || fail 'IPv6 absence inventory must contain exactly external_denial=none'
    else
        ((${#denial_endpoint[@]} == ${#inventory_address[@]})) \
            || fail 'IPv6 inventory lacks one external denial for every discovered address'
        for index in "${!inventory_address[@]}"; do
            [[ ${denial_endpoint[$index]} == "[${inventory_address[$index]}]:8000" ]] \
                || fail 'IPv6 external-denial inventory does not exactly cover the discovered address set'
        done
    fi
    address_count=${#inventory_address[@]}
    if ((address_count == 0)); then
        denial_count=0
    else
        denial_count=${#denial_endpoint[@]}
    fi
    probe_output=$("$ipv6_inventory_probe" --inventory-file "$ipv6_inventory_file" --timeout-seconds 5) \
        || fail 'authoritative IPv6 inventory probe did not validate interface/DNS/provider/external sources'
    [[ $probe_output == "CONTROL_PLANE_PORT8000_IPV6_INVENTORY result=pass inventory_sha256=$actual_checksum address_count=$address_count denial_count=$denial_count" ]] \
        || fail 'authoritative IPv6 inventory probe returned an inexact attestation'
    for endpoint in "${denial_endpoint[@]}"; do
        [[ $endpoint == none ]] && continue
        probe_output=$("$external_policy_probe" --endpoint "$endpoint" --timeout-seconds 5) \
            || fail "reviewed external policy probe could not attest blocked IPv6 TCP/8000: $endpoint"
        [[ $probe_output == "CONTROL_PLANE_PORT8000_EXTERNAL_POLICY result=blocked endpoint=$endpoint" ]] \
            || fail 'external policy probe returned an inexact IPv6 denial attestation'
    done
    if ((address_count == 0)); then
        public_ipv6_status=absent
    else
        public_ipv6_status=blocked
    fi
}

assert_external_policy_blocked()
{
    local address actual_checksum output
    if [[ $target == lab && -z $external_policy_probe ]]; then
        return 0
    fi
    require_value CONTROL_PLANE_PORT8000_EXTERNAL_POLICY_PROBE "$external_policy_probe"
    require_value CONTROL_PLANE_PORT8000_EXTERNAL_POLICY_PROBE_SHA256 "$external_policy_probe_sha256"
    require_value CONTROL_PLANE_PORT8000_EXTERNAL_BLOCKED_ENDPOINT "$external_blocked_endpoint"
    [[ $external_policy_probe == /* ]] || fail 'external policy probe path must be absolute'
    assert_regular_file "$external_policy_probe" CONTROL_PLANE_PORT8000_EXTERNAL_POLICY_PROBE
    [[ -x $external_policy_probe && $(stat -c '%a:%u:%g' "$external_policy_probe") == 700:0:0 ]] \
        || fail 'external policy probe must be root-owned mode 0700 and executable'
    [[ $external_policy_probe_sha256 =~ ^[a-f0-9]{64}$ ]] \
        || fail 'external policy probe checksum must be lowercase SHA-256'
    actual_checksum=$(checksum "$external_policy_probe")
    [[ $actual_checksum == "$external_policy_probe_sha256" ]] \
        || fail 'external policy probe differs from the reviewed checksum'
    [[ $external_blocked_endpoint =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}:8000$ ]] \
        || fail 'external blocked endpoint must be a numeric IPv4 address on TCP/8000'
    address=${external_blocked_endpoint%:8000}
    ipcalc -c "$address" >/dev/null 2>&1 || fail 'external blocked endpoint IPv4 address is invalid'
    output=$("$external_policy_probe" --endpoint "$external_blocked_endpoint" --timeout-seconds 5) \
        || fail 'reviewed external policy probe could not attest blocked TCP/8000'
    [[ $output == "CONTROL_PLANE_PORT8000_EXTERNAL_POLICY result=blocked endpoint=$external_blocked_endpoint" ]] \
        || fail 'external policy probe did not return the exact blocked attestation'

    assert_ipv6_inventory
}

probe_route()
{
    local expected_color=$1 expected_ack=$2 expected_owner=$3 url=${4:-$host_local_url}
    local attempt=0 headers body secret_header status
    headers="$operation_directory/.probe-headers.$$"
    body="$operation_directory/.probe-body.$$"
    secret_header="$operation_directory/.probe-secret.$$"
    trap 'rm -f -- "$headers" "$body" "$secret_header"' RETURN

    if [[ $expected_color != legacy ]]; then
        write_probe_header_file "$secret_header"
        url=$(probe_url_for_path "$url")
    fi

    while ((attempt < probe_attempts)); do
        rm -f -- "$headers" "$body"
        status=0
        if [[ $expected_color == legacy ]]; then
            curl --noproxy '*' --fail --silent --show-error --max-time 5 \
                --header "Host: $public_host_header" \
                --dump-header "$headers" --output "$body" "$url" || status=$?
        else
            curl --noproxy '*' --fail --silent --show-error --max-time 5 \
                --header "@$secret_header" \
                --header "Host: $public_host_header" \
                --header 'Forwarded: for=forged;proto=https;host=forged.invalid' \
                --header 'X-Forwarded-For: forged-client' \
                --header 'X-Forwarded-Proto: forged-proto' \
                --header 'X-Forwarded-Host: forged.invalid' \
                --header 'X-Real-IP: forged-client' \
                --dump-header "$headers" --output "$body" "$url" || status=$?
        fi
        if ((status == 0)); then
            if [[ $expected_color == legacy ]]; then
                if ! grep -E -i -q '^X-Control-Plane-(Route-Ack|Applied-Config):' "$headers"; then
                    trap - RETURN
                    rm -f -- "$headers" "$body" "$secret_header"
                    return
                fi
            elif assert_single_ack_header "$headers" "$expected_ack" \
                && assert_single_owner_header "$headers" "$expected_owner"; then
                trap - RETURN
                rm -f -- "$headers" "$body" "$secret_header"
                return
            fi
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    fail "host-local :8000 route did not acknowledge color=$expected_color owner=$expected_owner"
}

probe_backend_identity()
{
    local expected_ack=$1 attempt=0 headers body secret_header status url
    headers="$operation_directory/.backend-probe-headers.$$"
    body="$operation_directory/.backend-probe-body.$$"
    secret_header="$operation_directory/.backend-probe-secret.$$"
    trap 'rm -f -- "$headers" "$body" "$secret_header"' RETURN
    write_probe_header_file "$secret_header"
    if [[ $backend == *:* ]]; then
        url="http://[${backend}]:${backend_port}${direct_probe_path}"
    else
        url="http://${backend}:${backend_port}${direct_probe_path}"
    fi
    while ((attempt < probe_attempts)); do
        rm -f -- "$headers" "$body"
        status=0
        curl --noproxy '*' --fail --silent --show-error --max-time 5 \
            --header "@$secret_header" --dump-header "$headers" --output "$body" "$url" || status=$?
        if ((status == 0)) && assert_single_ack_header "$headers" "$expected_ack"; then
            trap - RETURN
            rm -f -- "$headers" "$body" "$secret_header"
            return
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    fail "selected backend did not prove its direct-probe identity: $backend:$backend_port"
}

assert_single_pool_header_v2()
{
    local headers=$1 name=$2 expected=$3 count observed
    count=$(awk -F: -v expected_name="$name" '
        BEGIN { IGNORECASE=1 }
        tolower($1) == tolower(expected_name) { count++ }
        END { print count + 0 }
    ' "$headers")
    [[ $count -eq 1 ]] || return 1
    observed=$(awk -F: -v expected_name="$name" '
        BEGIN { IGNORECASE=1 }
        tolower($1) == tolower(expected_name) {
            value = substr($0, index($0, ":") + 1)
            sub(/^[[:space:]]*/, "", value)
            sub(/\r$/, "", value)
            print value
        }
    ' "$headers")
    [[ $observed == "$expected" ]]
}

pool_member_url_v2()
{
    local port=$1 route_health_path
    route_health_path=$(pool_manifest_value route_health_path)
    [[ $route_health_path == /api/control-plane/route-health ]] \
        || fail 'ingress pool route-health path is not canonical'
    if [[ $pool_loopback_address == *:* ]]; then
        printf 'http://[%s]:%s%s\n' "$pool_loopback_address" "$port" \
            "$route_health_path"
    else
        printf 'http://%s:%s%s\n' "$pool_loopback_address" "$port" \
            "$route_health_path"
    fi
}

write_pool_route_health_curl_config_v2()
{
    local destination=$1 candidate expected_sha256
    candidate="$operation_directory/.pool-route-health-curl.$$"
    {
        printf 'header = "Host: %s"\n' "$public_host_header"
        printf 'header = "X-Control-Plane-Route-Health: %s"\n' \
            "$pool_route_health_token"
    } > "$candidate"
    chmod 600 "$candidate"
    expected_sha256=$(checksum "$candidate")
    atomic_replace "$candidate" "$destination" 600
    assert_pool_file "$destination" 600 "$immutable_uid" "$immutable_gid" \
        'ephemeral authenticated pool probe configuration'
    [[ $(checksum "$destination") == "$expected_sha256" ]] \
        || fail 'authenticated pool probe configuration changed before use'
    pool_curl_config_sha256=$expected_sha256
    pool_curl_config_identity=$(pool_file_identity "$destination")
}

probe_pool_member_v2()
{
    local member=$1 expected_state=$2 expected_status expected_identity expected_ack
    local expected_epoch port url headers body status_file curl_config
    local curl_config_descriptor curl_config_fd curl_config_open_identity curl_config_bytes
    local attempt=0 curl_status http_status
    if [[ $member == a ]]; then
        expected_identity=$pool_member_a_route_identity
        expected_ack=$pool_member_a_applied_ack
        expected_epoch=$pool_member_a_drain_epoch
        port=$pool_member_a_loopback_port
    else
        expected_identity=$pool_member_b_route_identity
        expected_ack=$pool_member_b_applied_ack
        expected_epoch=$pool_member_b_drain_epoch
        port=$pool_member_b_loopback_port
    fi
    case "$expected_state" in
        active) expected_status=204 ;;
        draining) expected_status=503 ;;
        *) fail 'pool member probe state must be active or draining' ;;
    esac
    url=$(pool_member_url_v2 "$port")
    headers="$operation_directory/.pool-web-$member-headers.$$"
    body="$operation_directory/.pool-web-$member-body.$$"
    status_file="$operation_directory/.pool-web-$member-status.$$"
    curl_config="$operation_directory/.pool-web-$member-curl.$$"
    write_pool_route_health_curl_config_v2 "$curl_config"
    if [[ -n ${pool_probe_fd_race_hook:-} ]]; then
        [[ $test_mode == 1 ]] || fail 'pool probe FD race hook is unavailable in production mode'
        assert_regular_file "$pool_probe_fd_race_hook" 'pool probe FD race hook'
        "$pool_probe_fd_race_hook" "$curl_config"
    fi
    exec {curl_config_fd}<"$curl_config" \
        || fail 'authenticated pool probe configuration could not be opened'
    curl_config_descriptor="/proc/self/fd/$curl_config_fd"
    [[ -e $curl_config_descriptor ]] || curl_config_descriptor="/dev/fd/$curl_config_fd"
    curl_config_open_identity=$(stat -Lc '%d:%i:%f:%u:%g:%a:%s' -- \
        "$curl_config_descriptor") \
        || fail 'authenticated pool probe descriptor identity could not be read'
    [[ $curl_config_open_identity == "$pool_curl_config_identity" \
        && $(checksum "$curl_config_descriptor") == "$pool_curl_config_sha256" ]] \
        || fail 'authenticated pool probe configuration changed while it was opened'
    curl_config_bytes=$(cat "$curl_config_descriptor") \
        || fail 'authenticated pool probe configuration could not be read from its pinned descriptor'
    rm -f -- "$curl_config"
    exec {curl_config_fd}<&-
    trap 'rm -f -- "$headers" "$body" "$status_file" "$curl_config"' RETURN
    while ((attempt < probe_attempts)); do
        rm -f -- "$headers" "$body" "$status_file"
        curl_status=0
        printf '%s\n' "$curl_config_bytes" \
            | curl --config - --noproxy '*' --silent --show-error --max-time 5 \
                --dump-header "$headers" --output "$body" --write-out '%{http_code}' "$url" \
                > "$status_file" || curl_status=$?
        http_status=$(<"$status_file")
        if ((curl_status == 0)) && [[ $http_status == "$expected_status" ]] \
            && assert_single_pool_header_v2 "$headers" X-Control-Plane-Applied-Config \
                "$expected_ack" \
            && assert_single_pool_header_v2 "$headers" X-Control-Plane-Pool-Ack \
                "$pool_acknowledgement" \
            && assert_single_pool_header_v2 "$headers" X-Control-Plane-Member \
                "$expected_identity"; then
            if [[ $expected_state == active ]]; then
                if ! grep -E -i -q '^X-Control-Plane-Route-(State|Drain-Epoch):' "$headers"; then
                    trap - RETURN
                    rm -f -- "$headers" "$body" "$status_file" "$curl_config"
                    return
                fi
            elif assert_single_pool_header_v2 "$headers" X-Control-Plane-Route-State draining \
                && assert_single_pool_header_v2 "$headers" X-Control-Plane-Route-Drain-Epoch \
                    "$expected_epoch"; then
                trap - RETURN
                rm -f -- "$headers" "$body" "$status_file" "$curl_config"
                return
            fi
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    fail "web-$member did not prove exact authenticated $expected_state route identity"
}

probe_pool_route_v2()
{
    local expected_ack=$1 url=${2:-$host_local_url} headers body attempt=0 status
    headers="$operation_directory/.pool-route-headers.$$"
    body="$operation_directory/.pool-route-body.$$"
    trap 'rm -f -- "$headers" "$body"' RETURN
    while ((attempt < probe_attempts)); do
        rm -f -- "$headers" "$body"
        status=0
        curl --noproxy '*' --fail --silent --show-error --max-time 5 \
            --header "Host: $public_host_header" \
            --header 'Forwarded: for=forged;proto=https;host=forged.invalid' \
            --header 'X-Forwarded-For: forged-client' \
            --header 'X-Forwarded-Proto: forged-proto' \
            --header 'X-Forwarded-Host: forged.invalid' \
            --header 'X-Real-IP: forged-client' \
            --dump-header "$headers" --output "$body" "$url" || status=$?
        if ((status == 0)) \
            && assert_single_pool_header_v2 "$headers" X-Control-Plane-Route-Ack \
                "$expected_ack" \
            && assert_single_owner_header "$headers" permanent-b; then
            trap - RETURN
            rm -f -- "$headers" "$body"
            return
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    fail 'host-local :8000 route did not return one exact generation-bound pool acknowledgement'
}

probe_pool_namespace_address_v2()
{
    local family=$1 source=$2 target_address=$3 expected_ack=$4 url
    if [[ $family == 4 ]]; then
        ip -n "$canary_netns" -o -4 address show | grep -E "[[:space:]]${source}/" >/dev/null \
            || fail 'attested IPv4 canary source is not assigned in the canary namespace'
        url="http://${target_address}:8000$(host_local_path)"
    else
        ip -n "$canary_netns" -o -6 address show | grep -E "[[:space:]]${source}/" >/dev/null \
            || fail 'attested IPv6 canary source is not assigned in the canary namespace'
        url="http://[${target_address}]:8000$(host_local_path)"
    fi
    ip netns exec "$canary_netns" env \
        CONTROL_PLANE_CANARY_URL="$url" \
        CONTROL_PLANE_CANARY_HOST="$public_host_header" \
        CONTROL_PLANE_CANARY_ACK="$expected_ack" \
        bash -euo pipefail -c '
            headers=$(mktemp)
            trap '\''rm -f "$headers"'\'' EXIT
            curl --noproxy '\''*'\'' --fail --silent --show-error --max-time 5 \
                --header "Host: $CONTROL_PLANE_CANARY_HOST" \
                --dump-header "$headers" --output /dev/null "$CONTROL_PLANE_CANARY_URL"
            [[ $(awk '\''BEGIN { IGNORECASE=1 } /^X-Control-Plane-Route-Ack:[[:space:]]*/ { count++ } END { print count+0 }'\'' "$headers") -eq 1 ]]
            [[ $(sed -n '\''s/^X-Control-Plane-Route-Ack:[[:space:]]*//Ip'\'' "$headers" | tr -d '\''\r'\'') == "$CONTROL_PLANE_CANARY_ACK" ]]
            [[ $(awk '\''BEGIN { IGNORECASE=1 } /^X-Control-Plane-Port-Owner:[[:space:]]*/ { count++ } END { print count+0 }'\'' "$headers") -eq 1 ]]
            [[ $(sed -n '\''s/^X-Control-Plane-Port-Owner:[[:space:]]*//Ip'\'' "$headers" | tr -d '\''\r'\'') == permanent-b ]]
        ' || fail "IPv$family pinned canary did not acknowledge the exact phase-B pool"
}

probe_pool_canary_v2()
{
    local expected_ack=$1
    [[ -n $canary_netns ]] || fail 'pinned canary requires CONTROL_PLANE_PORT8000_CANARY_NETNS'
    ip netns list | awk '{print $1}' | grep -F -x "$canary_netns" >/dev/null \
        || fail 'pinned canary network namespace is absent'
    if [[ $canary_ipv4 != none ]]; then
        probe_pool_namespace_address_v2 4 "$canary_ipv4" "$canary_target_ipv4" "$expected_ack"
    fi
    if [[ $canary_ipv6 != none ]]; then
        probe_pool_namespace_address_v2 6 "$canary_ipv6" "$canary_target_ipv6" "$expected_ack"
    fi
}

probe_namespace_route()
{
    local expected_color=$1 expected_ack=$2 expected_owner=$3 path url source secret_header
    if [[ $expected_color == legacy ]]; then
        path=$(host_local_path)
    else
        path=$direct_probe_path
        secret_header="$operation_directory/.canary-probe-secret.$$"
        write_probe_header_file "$secret_header"
        trap 'rm -f -- "$secret_header"' RETURN
    fi
    [[ -n $canary_netns ]] || fail 'pinned canary requires CONTROL_PLANE_PORT8000_CANARY_NETNS'
    ip netns list | awk '{print $1}' | grep -F -x "$canary_netns" >/dev/null \
        || fail 'pinned canary network namespace is absent'

    if [[ $canary_ipv4 != none ]]; then
        source=$canary_ipv4
        ip -n "$canary_netns" -o -4 address show | grep -E "[[:space:]]${source}/" >/dev/null \
            || fail 'attested IPv4 canary source is not assigned in the canary namespace'
        url="http://${canary_target_ipv4}:8000${path}"
        ip netns exec "$canary_netns" env \
            CONTROL_PLANE_CANARY_URL="$url" \
            CONTROL_PLANE_CANARY_HOST="$public_host_header" \
            CONTROL_PLANE_CANARY_COLOR="$expected_color" \
            CONTROL_PLANE_CANARY_ACK="$expected_ack" \
            CONTROL_PLANE_CANARY_OWNER="$expected_owner" \
            CONTROL_PLANE_CANARY_SECRET_HEADER="${secret_header:-none}" \
            bash -euo pipefail -c '
                headers=$(mktemp)
                trap '\''rm -f "$headers"'\'' EXIT
                if [[ $CONTROL_PLANE_CANARY_COLOR == legacy ]]; then
                    curl --noproxy '\''*'\'' --fail --silent --show-error --max-time 5 \
                        --header "Host: $CONTROL_PLANE_CANARY_HOST" \
                        --dump-header "$headers" --output /dev/null "$CONTROL_PLANE_CANARY_URL"
                    ! grep -E -i -q "^X-Control-Plane-(Route-Ack|Applied-Config):" "$headers"
                else
                    curl --noproxy '\''*'\'' --fail --silent --show-error --max-time 5 \
                        --header "@$CONTROL_PLANE_CANARY_SECRET_HEADER" \
                        --header "Host: $CONTROL_PLANE_CANARY_HOST" \
                        --dump-header "$headers" --output /dev/null "$CONTROL_PLANE_CANARY_URL"
                    [[ $(awk '\''BEGIN { IGNORECASE=1 } /^X-Control-Plane-Applied-Config:[[:space:]]*/ { count++ } END { print count+0 }'\'' "$headers") -eq 1 ]]
                    [[ $(sed -n '\''s/^X-Control-Plane-Applied-Config:[[:space:]]*//Ip'\'' "$headers" | tr -d '\''\r'\'') == "$CONTROL_PLANE_CANARY_ACK" ]]
                    [[ $(awk '\''BEGIN { IGNORECASE=1 } /^X-Control-Plane-Port-Owner:[[:space:]]*/ { count++ } END { print count+0 }'\'' "$headers") -eq 1 ]]
                    [[ $(sed -n '\''s/^X-Control-Plane-Port-Owner:[[:space:]]*//Ip'\'' "$headers" | tr -d '\''\r'\'') == "$CONTROL_PLANE_CANARY_OWNER" ]]
                fi
            ' || fail "IPv4 pinned canary did not acknowledge owner $expected_owner"
    fi
    if [[ $canary_ipv6 != none ]]; then
        source=$canary_ipv6
        ip -n "$canary_netns" -o -6 address show | grep -E "[[:space:]]${source}/" >/dev/null \
            || fail 'attested IPv6 canary source is not assigned in the canary namespace'
        url="http://[${canary_target_ipv6}]:8000${path}"
        ip netns exec "$canary_netns" env \
            CONTROL_PLANE_CANARY_URL="$url" \
            CONTROL_PLANE_CANARY_HOST="$public_host_header" \
            CONTROL_PLANE_CANARY_COLOR="$expected_color" \
            CONTROL_PLANE_CANARY_ACK="$expected_ack" \
            CONTROL_PLANE_CANARY_OWNER="$expected_owner" \
            CONTROL_PLANE_CANARY_SECRET_HEADER="${secret_header:-none}" \
            bash -euo pipefail -c '
                headers=$(mktemp)
                trap '\''rm -f "$headers"'\'' EXIT
                if [[ $CONTROL_PLANE_CANARY_COLOR == legacy ]]; then
                    curl --noproxy '\''*'\'' --fail --silent --show-error --max-time 5 \
                        --header "Host: $CONTROL_PLANE_CANARY_HOST" \
                        --dump-header "$headers" --output /dev/null "$CONTROL_PLANE_CANARY_URL"
                    ! grep -E -i -q "^X-Control-Plane-(Route-Ack|Applied-Config):" "$headers"
                else
                    curl --noproxy '\''*'\'' --fail --silent --show-error --max-time 5 \
                        --header "@$CONTROL_PLANE_CANARY_SECRET_HEADER" \
                        --header "Host: $CONTROL_PLANE_CANARY_HOST" \
                        --dump-header "$headers" --output /dev/null "$CONTROL_PLANE_CANARY_URL"
                    [[ $(awk '\''BEGIN { IGNORECASE=1 } /^X-Control-Plane-Applied-Config:[[:space:]]*/ { count++ } END { print count+0 }'\'' "$headers") -eq 1 ]]
                    [[ $(sed -n '\''s/^X-Control-Plane-Applied-Config:[[:space:]]*//Ip'\'' "$headers" | tr -d '\''\r'\'') == "$CONTROL_PLANE_CANARY_ACK" ]]
                    [[ $(awk '\''BEGIN { IGNORECASE=1 } /^X-Control-Plane-Port-Owner:[[:space:]]*/ { count++ } END { print count+0 }'\'' "$headers") -eq 1 ]]
                    [[ $(sed -n '\''s/^X-Control-Plane-Port-Owner:[[:space:]]*//Ip'\'' "$headers" | tr -d '\''\r'\'') == "$CONTROL_PLANE_CANARY_OWNER" ]]
                fi
            ' || fail "IPv6 pinned canary did not acknowledge owner $expected_owner"
    fi
    if [[ $expected_color != legacy ]]; then
        trap - RETURN
        rm -f -- "$secret_header"
    fi
}

probe_direct_bootstrap_denied()
{
    ! curl --noproxy '*' --fail --silent --show-error --connect-timeout 2 --max-time 2 \
        "http://127.0.0.1:${bootstrap_port}/" >/dev/null 2>&1 \
        || fail 'host-local IPv4 reached the direct bootstrap port'
    ! curl --noproxy '*' --fail --silent --show-error --connect-timeout 2 --max-time 2 \
        "http://[::1]:${bootstrap_port}/" >/dev/null 2>&1 \
        || fail 'host-local IPv6 reached the direct bootstrap port'
    if [[ $canary_ipv4 != none ]]; then
        ! ip netns exec "$canary_netns" curl --noproxy '*' --fail --silent --show-error \
            --connect-timeout 2 --max-time 2 "http://${canary_target_ipv4}:${bootstrap_port}/" \
            >/dev/null 2>&1 || fail 'pinned IPv4 canary reached the direct bootstrap port'
    fi
    if [[ $canary_ipv6 != none ]]; then
        ! ip netns exec "$canary_netns" curl --noproxy '*' --fail --silent --show-error \
            --connect-timeout 2 --max-time 2 "http://[${canary_target_ipv6}]:${bootstrap_port}/" \
            >/dev/null 2>&1 || fail 'pinned IPv6 canary reached the direct bootstrap port'
    fi
}

docker_owner_snapshot()
{
    local docker_error_file docker_inspect_file docker_list_file expected_ids
    local inspect_status list_status snapshot
    local -a ids=()
    local -A seen_id=()

    docker_list_file=$(mktemp /tmp/control-plane-port8000-docker-list.XXXXXX)
    docker_error_file=$(mktemp /tmp/control-plane-port8000-docker-error.XXXXXX)
    set +e
    docker ps -aq --no-trunc > "$docker_list_file" 2> "$docker_error_file"
    list_status=$?
    set -e
    if ((list_status != 0)); then
        rm -f -- "$docker_list_file" "$docker_error_file"
        fail 'Docker Engine container list is unavailable'
    fi
    rm -f -- "$docker_error_file"

    mapfile -t ids < "$docker_list_file"
    rm -f -- "$docker_list_file"
    for id in "${ids[@]}"; do
        [[ $id =~ ^[a-f0-9]{64}$ ]] \
            || fail 'Docker Engine container list returned a malformed container ID'
        [[ -z ${seen_id[$id]:-} ]] \
            || fail 'Docker Engine container list returned a duplicate container ID'
        seen_id[$id]=1
    done

    if ((${#ids[@]} > 0)); then
        docker_inspect_file=$(mktemp /tmp/control-plane-port8000-docker-inspect.XXXXXX)
        docker_error_file=$(mktemp /tmp/control-plane-port8000-docker-error.XXXXXX)
        set +e
        docker inspect "${ids[@]}" > "$docker_inspect_file" 2> "$docker_error_file"
        inspect_status=$?
        set -e
        if ((inspect_status != 0)); then
            rm -f -- "$docker_inspect_file" "$docker_error_file"
            fail 'Docker Engine container inspection is unavailable'
        fi
        rm -f -- "$docker_error_file"
        [[ -s $docker_inspect_file ]] || {
            rm -f -- "$docker_inspect_file"
            fail 'Docker Engine container inspection returned an empty response'
        }
        expected_ids=$(printf '%s\n' "${ids[@]}" \
            | jq -R -s -c 'split("\n") | map(select(length > 0)) | sort')
        jq -e -s --argjson expected_ids "$expected_ids" '
            length == 1
            and (.[0] | type == "array"
                and length == ($expected_ids | length)
                and all(.[].Id; type == "string" and test("^[a-f0-9]{64}$"))
                and ([.[].Id] | sort) == $expected_ids)
        ' "$docker_inspect_file" >/dev/null || {
            rm -f -- "$docker_inspect_file"
            fail 'Docker Engine container inspection returned malformed or mismatched inventory'
        }
        # HostConfig proves declared ownership; NetworkSettings attests the dynamically assigned v4/v6 endpoints.
        snapshot=$(jq -e -S --arg port "$public_port" '
            [ .[]
              | select(
                  (.HostConfig.PortBindings // {} | to_entries
                   | any(.[]; any((.value // [])[]?; .HostPort == $port)))
                )
              | {
                  id: .Id,
                  name: (.Name | ltrimstr("/")),
                  running: .State.Running,
                  imageId: .Image,
                  imageReference: .Config.Image,
                  compose: {
                      project: ((.Config.Labels // {})["com.docker.compose.project"] // ""),
                      service: ((.Config.Labels // {})["com.docker.compose.service"] // ""),
                      configFiles: ((.Config.Labels // {})["com.docker.compose.project.config_files"] // "")
                  },
                  runtime: {
                      networkMode: .HostConfig.NetworkMode,
                      restartPolicy: (.HostConfig.RestartPolicy | {Name, MaximumRetryCount}),
                      mounts: [
                          (.Mounts // [])[]
                          | {type: .Type, source: (.Source // ""), destination: .Destination, readWrite: .RW}
                      ] | sort_by(.type, .source, .destination, .readWrite)
                  },
                  bindings: [
                      (.HostConfig.PortBindings // {} | to_entries[]
                       | .key as $containerPort
                       | .value[]?
                       | select(.HostPort == $port)
                       | {containerPort: $containerPort, hostIp: .HostIp, hostPort: .HostPort})
                  ] | sort_by(.containerPort, .hostIp, .hostPort),
                  endpoints: [
                      (.NetworkSettings.Networks // {} | to_entries[]
                       | {network: .key, ipv4: .value.IPAddress, ipv6: .value.GlobalIPv6Address})
                  ] | sort_by(.network)
                }
            ] | sort_by(.id)
        ' "$docker_inspect_file") || {
            rm -f -- "$docker_inspect_file"
            fail 'Docker Engine container inspection returned malformed ownership data'
        }
        rm -f -- "$docker_inspect_file"
        printf '%s\n' "$snapshot"
    else
        printf '[]\n'
    fi
}

published_owner_count()
{
    docker_owner_snapshot | jq 'length'
}

assert_legacy_owner_present()
{
    local current current_checksum expected_snapshot
    assert_legacy_owner_lineage
    expected_snapshot=$(legacy_owner_active_snapshot)
    current=$(docker_owner_snapshot)
    [[ $(jq 'length' <<< "$current") -eq 1 ]] || fail 'legacy Docker :8000 ownership is absent or ambiguous'
    current_checksum=$(sha256sum <<< "$current" | awk '{print $1}')
    [[ $current_checksum == "$(checksum "$expected_snapshot")" ]] \
        || fail 'legacy Docker :8000 published endpoint identity changed'
}

docker_proxy_owns_port()
{
    local process cmdline
    for process in /proc/[0-9]*; do
        [[ -r $process/cmdline ]] || continue
        cmdline=$(tr '\0' ' ' < "$process/cmdline")
        if [[ $cmdline == *docker-proxy* && $cmdline =~ -host-port[[:space:]]+${public_port}([[:space:]]|$) ]]; then
            return 0
        fi
    done
    return 1
}

assert_no_legacy_port_ownership()
{
    local socket_lines
    [[ $(published_owner_count) -eq 0 ]] || fail 'a Docker container still declares host port 8000'
    docker_proxy_owns_port && fail 'docker-proxy still owns host port 8000'
    socket_lines=$(ss -H -ltnp "sport = :$public_port" || true)
    [[ -z $socket_lines ]] || fail 'a host process still listens on port 8000 before phase B'

    if command -v iptables-save >/dev/null 2>&1; then
        ! iptables-save -t nat 2>/dev/null \
            | grep -E -- "--dport ${public_port}([^0-9]|$).*(-j DNAT|-j REDIRECT)" >/dev/null \
            || fail 'an iptables NAT rule still owns host port 8000'
    fi
    if command -v ip6tables-save >/dev/null 2>&1; then
        ! ip6tables-save -t nat 2>/dev/null \
            | grep -E -- "--dport ${public_port}([^0-9]|$).*(-j DNAT|-j REDIRECT)" >/dev/null \
            || fail 'an ip6tables NAT rule still owns host port 8000'
    fi
    # Native Docker nftables can use generated maps. The canary is the final external proof,
    # but any visible stale DNAT for the port is an immediate hard failure.
    ! nft list ruleset 2>/dev/null \
        | grep -E "dport[[:space:]]+${public_port}([^0-9]|$).*dnat" >/dev/null \
        || fail 'an nftables DNAT/redirect rule still owns host port 8000'
}

manifest_value()
{
    safe_state_value "$1" "$manifest_file"
}

load_manifest()
{
    assert_root_file "$manifest_file" 600 'immutable :8000 manifest'
    manifest_operation_id=$(manifest_value operation_id)
    [[ $manifest_operation_id == "$operation_id" ]] || fail 'immutable manifest belongs to a different operation'
    manifest_controller_sha256=$(manifest_value controller_sha256)
    manifest_legacy_snapshot_sha256=$(manifest_value legacy_snapshot_sha256)
    manifest_host_local_url=$(manifest_value host_local_url)
    manifest_public_host_header=$(manifest_value public_host_header)
    manifest_canary_target_ipv4=$(manifest_value canary_target_ipv4)
    manifest_canary_target_ipv6=$(manifest_value canary_target_ipv6)
    manifest_bootstrap_port=$(manifest_value bootstrap_port)
    manifest_nft_priority=$(manifest_value nft_priority)
    manifest_nft_table=$(manifest_value nft_table)
    manifest_canary_netns=$(manifest_value canary_netns)
    manifest_canary_ipv4=$(manifest_value canary_ipv4)
    manifest_canary_ipv6=$(manifest_value canary_ipv6)
    manifest_external_policy_probe=$(manifest_value external_policy_probe)
    manifest_external_policy_probe_sha256=$(manifest_value external_policy_probe_sha256)
    manifest_external_blocked_endpoint=$(manifest_value external_blocked_endpoint)
    manifest_ipv6_inventory_file=$(manifest_value ipv6_inventory_file)
    manifest_ipv6_inventory_probe=$(manifest_value ipv6_inventory_probe)
    manifest_ipv6_inventory_probe_sha256=$(manifest_value ipv6_inventory_probe_sha256)
    manifest_direct_probe_path=$(manifest_value direct_probe_path)
    manifest_docker_version=$(manifest_value docker_version)
    manifest_kernel_release=$(manifest_value kernel_release)
    [[ $manifest_controller_sha256 == "$(checksum "$0")" ]] || fail 'controller checksum changed during operation'
    assert_root_file "$legacy_snapshot_file" 600 'durable legacy Docker endpoint snapshot'
    [[ $(checksum "$legacy_snapshot_file") == "$manifest_legacy_snapshot_sha256" ]] \
        || fail 'durable legacy Docker endpoint snapshot changed'
    [[ $manifest_host_local_url == "$host_local_url" \
        && $manifest_public_host_header == "$public_host_header" \
        && $manifest_canary_target_ipv4 == "$canary_target_ipv4" \
        && $manifest_canary_target_ipv6 == "$canary_target_ipv6" \
        && $manifest_bootstrap_port == "$bootstrap_port" \
        && $manifest_nft_priority == "$nft_priority" \
        && $manifest_nft_table == "$nft_table" \
        && $manifest_canary_netns == "${canary_netns:-none}" \
        && $manifest_canary_ipv4 == "$canary_ipv4" \
        && $manifest_canary_ipv6 == "$canary_ipv6" \
        && $manifest_external_policy_probe == "${external_policy_probe:-none}" \
        && $manifest_external_policy_probe_sha256 == "$external_policy_probe_sha256" \
        && $manifest_external_blocked_endpoint == "$external_blocked_endpoint" \
        && $manifest_ipv6_inventory_file == "$ipv6_inventory_file" \
        && $manifest_ipv6_inventory_probe == "$ipv6_inventory_probe" \
        && $manifest_ipv6_inventory_probe_sha256 == "$ipv6_inventory_probe_sha256" \
        && $manifest_direct_probe_path == "$direct_probe_path" \
        && $manifest_docker_version == "$(docker version --format '{{.Server.Version}}')" \
        && $manifest_kernel_release == "$(uname -r)" ]] \
        || fail 'immutable :8000 operation inputs changed'
}

legacy_owner_active_snapshot()
{
    if [[ $state_legacy_owner_active_snapshot_sha256 == "$state_legacy_owner_original_snapshot_sha256" ]]; then
        printf '%s\n' "$legacy_snapshot_file"
        return
    fi
    if [[ $state_legacy_owner_active_snapshot_sha256 == "$state_legacy_owner_adoption_snapshot_sha256" ]]; then
        printf '%s\n' "$legacy_adoption_snapshot_file"
        return
    fi
    fail 'legacy Docker owner lineage has no active immutable snapshot'
}

assert_adopted_rollback_owner_snapshot()
{
    jq -e --slurpfile original "$legacy_snapshot_file" '
        length == 1
        and ($original | length == 1)
        and ($original[0] | length == 1)
        and .[0].id != $original[0][0].id
        and .[0].name == $original[0][0].name
        and .[0].running == true
        and .[0].imageId == $original[0][0].imageId
        and .[0].imageReference == $original[0][0].imageReference
        and .[0].compose == $original[0][0].compose
        and .[0].runtime == $original[0][0].runtime
        and .[0].bindings == $original[0][0].bindings
        and ([.[0].endpoints[].network] | sort) == ([$original[0][0].endpoints[].network] | sort)
    ' "$legacy_adoption_snapshot_file" >/dev/null \
        || fail 'adopted rollback Docker owner does not match the immutable legacy ownership contract'
}

assert_legacy_owner_lineage()
{
    [[ $state_legacy_owner_original_snapshot_sha256 == "$manifest_legacy_snapshot_sha256" ]] \
        || fail 'legacy Docker owner lineage no longer starts at the immutable captured snapshot'
    case "$state_legacy_owner_adoption_snapshot_sha256" in
        none)
            [[ $state_legacy_owner_active_snapshot_sha256 == "$state_legacy_owner_original_snapshot_sha256" ]] \
                || fail 'legacy Docker owner lineage has an unrecorded active replacement'
            ;;
        [a-f0-9][a-f0-9]*)
            [[ $state_legacy_owner_adoption_snapshot_sha256 =~ ^[a-f0-9]{64}$ ]] \
                || fail 'adopted rollback Docker owner checksum is malformed'
            [[ $state_legacy_owner_active_snapshot_sha256 == "$state_legacy_owner_adoption_snapshot_sha256" ]] \
                || fail 'adopted rollback Docker owner is not the active lineage identity'
            assert_root_file "$legacy_adoption_snapshot_file" 600 'adopted rollback Docker owner snapshot'
            [[ $(checksum "$legacy_adoption_snapshot_file") == "$state_legacy_owner_adoption_snapshot_sha256" ]] \
                || fail 'adopted rollback Docker owner snapshot changed'
            assert_adopted_rollback_owner_snapshot
            ;;
        *)
            fail 'legacy Docker owner adoption lineage is malformed'
            ;;
    esac
}

initialize_legacy_owner_lineage()
{
    state_legacy_owner_original_snapshot_sha256=$(checksum "$legacy_snapshot_file")
    state_legacy_owner_adoption_snapshot_sha256=none
    state_legacy_owner_active_snapshot_sha256=$state_legacy_owner_original_snapshot_sha256
}

load_state()
{
    assert_root_file "$state_file" 600 'durable :8000 state'
    [[ $(safe_state_value version "$state_file") == 3 ]] || fail 'durable :8000 state version changed'
    [[ $(safe_state_value operation_id "$state_file") == "$operation_id" ]] \
        || fail 'durable :8000 state belongs to a different operation'
    state_phase=$(safe_state_value phase "$state_file")
    state_color=$(safe_state_value color "$state_file")
    state_backend=$(safe_state_value backend "$state_file")
    state_backend_port=$(safe_state_value backend_port "$state_file")
    state_ack=$(safe_state_value acknowledgement "$state_file")
    state_probe_token_sha256=$(safe_state_value probe_token_sha256 "$state_file")
    state_a_config_sha256=$(safe_state_value phase_a_config_sha256 "$state_file")
    state_b_config_sha256=$(safe_state_value phase_b_config_sha256 "$state_file")
    state_nft_mode=$(safe_state_value nft_mode "$state_file")
    state_nft_artifact_sha256=$(safe_state_value nft_artifact_sha256 "$state_file")
    state_phase_a_main_pid=$(safe_state_value phase_a_main_pid "$state_file")
    state_phase_a_main_start_time=$(safe_state_value phase_a_main_start_time "$state_file")
    state_phase_a_main_boot_id=$(safe_state_value phase_a_main_boot_id "$state_file")
    state_legacy_owner_original_snapshot_sha256=$(safe_state_value legacy_owner_original_snapshot_sha256 "$state_file")
    state_legacy_owner_adoption_snapshot_sha256=$(safe_state_value legacy_owner_adoption_snapshot_sha256 "$state_file")
    state_legacy_owner_active_snapshot_sha256=$(safe_state_value legacy_owner_active_snapshot_sha256 "$state_file")
    assert_legacy_owner_lineage
    if ! phase_a_main_process_identity_is_absent; then
        assert_phase_a_main_process_identity
    fi
}

legacy_restore_status()
{
    local phase_a_config phase_b_config
    phase_a_config=$(instance_config_file phase-a)
    phase_b_config=$(instance_config_file phase-b)
    case "$state_phase" in
        prepared|capture-intent|phase-a-running|capture-installed|captured|restored)
            assert_legacy_owner_present
            if [[ -e $phase_a_config ]]; then
                assert_regular_file "$phase_a_config" 'phase-A HAProxy configuration'
                grep -F -x -q "# control-plane-operation-id: $operation_id" "$phase_a_config" \
                    || fail 'phase-A HAProxy configuration ownership changed'
            fi
            [[ ! -e $phase_b_config ]] || fail 'phase-B HAProxy configuration exists before the irreversible boundary'
            assert_nft_owned_or_absent
            if [[ $state_phase == capture-installed || $state_phase == captured ]]; then
                assert_nft_artifact_identity capture
            fi
            note "legacy-restore-status=available phase=$state_phase"
            ;;
        rollback-owner-adoption-intent)
            fail 'legacy-restore-status=unavailable phase=rollback-owner-adoption-intent adoption-incomplete=true'
            ;;
        permanent-intent|phase-b-running|canary-installed|canary-acknowledged|redirect-remove-intent|permanent-acknowledged|phase-a-drain-intent|phase-a-stopped|nft-removal-intent|nft-removed|permanent|permanent-update-intent)
            fail "legacy-restore-status=unavailable phase=$state_phase irreversible-boundary=true"
            ;;
        *)
            fail "legacy-restore-status=ambiguous phase=$state_phase"
            ;;
    esac
}

policy_status()
{
    local phase
    if [[ ! -f $manifest_file && ! -f $state_file ]]; then
        preflight
        probe_namespace_route legacy none legacy
        probe_direct_bootstrap_denied
        phase=legacy-unprepared
    else
        [[ -f $manifest_file && -f $state_file ]] \
            || fail 'policy status found partial durable operation state'
        load_manifest
        load_state
        phase=$state_phase
        case "$state_phase" in
            prepared|restored)
                legacy_restore_status
                probe_route legacy none legacy
                probe_namespace_route legacy none legacy
                ;;
            captured)
                assert_config_identity phase-a "$state_a_config_sha256"
                assert_nft_artifact_identity capture
                probe_route "$state_color" "$state_ack" bootstrap-a
                probe_namespace_route "$state_color" "$state_ack" bootstrap-a
                ;;
            permanent-acknowledged)
                assert_config_identity phase-b "$state_b_config_sha256"
                assert_nft_artifact_identity post-switch
                probe_route "$state_color" "$state_ack" permanent-b
                probe_namespace_route "$state_color" "$state_ack" permanent-b
                ;;
            permanent)
                assert_config_identity phase-b "$state_b_config_sha256"
                assert_phase_a_finalization_complete
                probe_route "$state_color" "$state_ack" permanent-b
                probe_namespace_route "$state_color" "$state_ack" permanent-b
                ;;
            *)
                fail "policy status refuses transitional phase: $state_phase"
                ;;
        esac
        probe_direct_bootstrap_denied
        assert_external_policy_blocked
    fi
    printf 'CONTROL_PLANE_PORT8000_POLICY result=pass phase=%s host_local=pass pinned_netns=pass direct_bootstrap=denied external_ipv4_tcp8000=blocked external_ipv6_tcp8000=%s\n' \
        "$phase" "$public_ipv6_status"
}

write_state()
{
    local phase=$1 candidate
    candidate="$operation_directory/.state.$$"
    {
        printf 'version=3\n'
        printf 'operation_id=%s\n' "$operation_id"
        printf 'phase=%s\n' "$phase"
        printf 'color=%s\n' "$state_color"
        printf 'backend=%s\n' "$state_backend"
        printf 'backend_port=%s\n' "$state_backend_port"
        printf 'acknowledgement=%s\n' "$state_ack"
        printf 'probe_token_sha256=%s\n' "$state_probe_token_sha256"
        printf 'phase_a_config_sha256=%s\n' "$state_a_config_sha256"
        printf 'phase_b_config_sha256=%s\n' "$state_b_config_sha256"
        printf 'nft_mode=%s\n' "$state_nft_mode"
        printf 'nft_artifact_sha256=%s\n' "$state_nft_artifact_sha256"
        printf 'phase_a_main_pid=%s\n' "$state_phase_a_main_pid"
        printf 'phase_a_main_start_time=%s\n' "$state_phase_a_main_start_time"
        printf 'phase_a_main_boot_id=%s\n' "$state_phase_a_main_boot_id"
        printf 'legacy_owner_original_snapshot_sha256=%s\n' "$state_legacy_owner_original_snapshot_sha256"
        printf 'legacy_owner_adoption_snapshot_sha256=%s\n' "$state_legacy_owner_adoption_snapshot_sha256"
        printf 'legacy_owner_active_snapshot_sha256=%s\n' "$state_legacy_owner_active_snapshot_sha256"
    } > "$candidate"
    atomic_replace "$candidate" "$state_file" 600
    state_phase=$phase
}

rollback_owner_candidate_matches()
{
    local snapshot=$1
    jq -e --arg expected_id "$rollback_owner_id" --slurpfile original "$legacy_snapshot_file" '
        length == 1
        and ($original | length == 1)
        and ($original[0] | length == 1)
        and .[0].id == $expected_id
        and .[0].id != $original[0][0].id
        and .[0].name == $original[0][0].name
        and .[0].running == true
        and .[0].imageId == $original[0][0].imageId
        and .[0].imageReference == $original[0][0].imageReference
        and .[0].compose == $original[0][0].compose
        and .[0].runtime == $original[0][0].runtime
        and .[0].bindings == $original[0][0].bindings
        and ([.[0].endpoints[].network] | sort) == ([$original[0][0].endpoints[].network] | sort)
    ' "$snapshot" >/dev/null
}

assert_rollback_owner_candidate()
{
    rollback_owner_candidate_matches "$1" \
        || fail 'rollback Docker owner does not exactly reattest the captured legacy ownership contract'
}

adopt_rollback_owner()
{
    local candidate candidate_checksum adopted_owner_id owner_snapshot
    [[ $rollback_owner_id =~ ^[a-f0-9]{64}$ ]] \
        || fail 'rollback Docker owner ID must be a full lowercase Docker ID'
    case "$state_phase" in
        captured)
            if [[ $state_legacy_owner_adoption_snapshot_sha256 != none ]]; then
                adopted_owner_id=$(jq -r '.[0].id' "$legacy_adoption_snapshot_file")
                [[ $adopted_owner_id == "$rollback_owner_id" ]] \
                    || fail 'rollback Docker owner ID differs from the immutable adopted lineage'
                assert_legacy_owner_present
                return
            fi
            candidate="$operation_directory/.legacy-owner-adoption-preflight.$$"
            owner_snapshot=$(docker_owner_snapshot)
            printf '%s\n' "$owner_snapshot" > "$candidate"
            chmod 600 "$candidate"
            if ! rollback_owner_candidate_matches "$candidate"; then
                rm -f -- "$candidate"
                fail 'rollback Docker owner does not exactly reattest the captured legacy ownership contract'
            fi
            rm -f -- "$candidate"
            write_state rollback-owner-adoption-intent
            test_crash after-rollback-owner-adoption-intent
            ;;
        rollback-owner-adoption-intent)
            [[ $state_legacy_owner_adoption_snapshot_sha256 == none \
                && $state_legacy_owner_active_snapshot_sha256 == "$state_legacy_owner_original_snapshot_sha256" ]] \
                || fail 'rollback Docker owner adoption intent has an invalid lineage state'
            ;;
        *)
            fail "rollback Docker owner adoption is forbidden from phase: $state_phase"
            ;;
    esac

    candidate="$operation_directory/.legacy-owner-adoption.$$"
    owner_snapshot=$(docker_owner_snapshot)
    printf '%s\n' "$owner_snapshot" > "$candidate"
    chmod 600 "$candidate"
    assert_rollback_owner_candidate "$candidate"
    candidate_checksum=$(checksum "$candidate")
    if [[ -e $legacy_adoption_snapshot_file ]]; then
        assert_root_file "$legacy_adoption_snapshot_file" 600 'adopted rollback Docker owner snapshot'
        [[ $(checksum "$legacy_adoption_snapshot_file") == "$candidate_checksum" ]] \
            || fail 'interrupted rollback Docker owner adoption differs from the recreated incumbent'
        rm -f -- "$candidate"
    else
        atomic_replace "$candidate" "$legacy_adoption_snapshot_file" 600
    fi
    test_crash after-rollback-owner-adoption-snapshot

    state_legacy_owner_adoption_snapshot_sha256=$candidate_checksum
    state_legacy_owner_active_snapshot_sha256=$candidate_checksum
    write_state captured
    test_crash after-rollback-owner-adoption
    assert_legacy_owner_present
}

prepare_operation()
{
    local legacy_snapshot manifest_candidate legacy_candidate owner_count
    if [[ -f $manifest_file && ! -e $state_file ]]; then
        load_manifest
        state_phase=prepared
        state_color=legacy
        state_backend=none
        state_backend_port=0
        state_ack=none
        state_probe_token_sha256=none
        state_a_config_sha256=none
        state_b_config_sha256=none
        state_nft_mode=absent
        state_nft_artifact_sha256=none
        state_phase_a_main_pid=none
        state_phase_a_main_start_time=none
        state_phase_a_main_boot_id=none
        initialize_legacy_owner_lineage
        write_state prepared
        return
    fi
    if [[ -e $manifest_file || -e $state_file ]]; then
        [[ -f $manifest_file && -f $state_file ]] || fail 'partial :8000 durable operation state exists'
        load_manifest
        load_state
        return
    fi

    legacy_snapshot=$(docker_owner_snapshot)
    owner_count=$(jq 'length' <<< "$legacy_snapshot")
    [[ $owner_count -eq 1 ]] || fail 'prepare requires exactly one Docker container publishing host port 8000'
    jq -e '.[0].running == true and (.[0].bindings | length) >= 1' <<< "$legacy_snapshot" >/dev/null \
        || fail 'legacy Docker port owner is not running or has no exact binding'
    mkdir -p -- "$operation_directory"
    chmod 700 "$operation_directory"
    legacy_candidate="$operation_directory/.legacy-owner.$$"
    printf '%s\n' "$legacy_snapshot" > "$legacy_candidate"
    atomic_replace "$legacy_candidate" "$legacy_snapshot_file" 600

    manifest_candidate="$operation_directory/.manifest.$$"
    {
        printf 'version=1\n'
        printf 'operation_id=%s\n' "$operation_id"
        printf 'controller_sha256=%s\n' "$(checksum "$0")"
        printf 'legacy_snapshot_sha256=%s\n' "$(checksum "$legacy_snapshot_file")"
        printf 'host_local_url=%s\n' "$host_local_url"
        printf 'public_host_header=%s\n' "$public_host_header"
        printf 'canary_target_ipv4=%s\n' "$canary_target_ipv4"
        printf 'canary_target_ipv6=%s\n' "$canary_target_ipv6"
        printf 'bootstrap_port=%s\n' "$bootstrap_port"
        printf 'nft_priority=%s\n' "$nft_priority"
        printf 'nft_table=%s\n' "$nft_table"
        printf 'canary_netns=%s\n' "${canary_netns:-none}"
        printf 'canary_ipv4=%s\n' "$canary_ipv4"
        printf 'canary_ipv6=%s\n' "$canary_ipv6"
        printf 'external_policy_probe=%s\n' "${external_policy_probe:-none}"
        printf 'external_policy_probe_sha256=%s\n' "$external_policy_probe_sha256"
        printf 'external_blocked_endpoint=%s\n' "$external_blocked_endpoint"
        printf 'ipv6_inventory_file=%s\n' "$ipv6_inventory_file"
        printf 'ipv6_inventory_probe=%s\n' "$ipv6_inventory_probe"
        printf 'ipv6_inventory_probe_sha256=%s\n' "$ipv6_inventory_probe_sha256"
        printf 'direct_probe_path=%s\n' "$direct_probe_path"
        printf 'docker_version=%s\n' "$(docker version --format '{{.Server.Version}}')"
        printf 'kernel_release=%s\n' "$(uname -r)"
    } > "$manifest_candidate"
    atomic_replace "$manifest_candidate" "$manifest_file" 600

    state_phase=prepared
    state_color=legacy
    state_backend=none
    state_backend_port=0
    state_ack=none
    state_probe_token_sha256=none
    state_a_config_sha256=none
    state_b_config_sha256=none
    state_nft_mode=absent
    state_nft_artifact_sha256=none
    state_phase_a_main_pid=none
    state_phase_a_main_start_time=none
    state_phase_a_main_boot_id=none
    initialize_legacy_owner_lineage
    write_state prepared
    load_manifest
}

render_haproxy_config()
{
    local instance=$1 listen_port=$2 owner=$3 destination=$4 destination_port=$5 color=$6 acknowledgement=$7
    local config=$8 state_directory socket backend_identity backend_name server_name
    backend_identity=$(printf '%s\0%s\0%s\0%s' "$destination" "$destination_port" "$color" "$acknowledgement" \
        | sha256sum | awk '{print substr($1, 1, 20)}')
    state_directory="$haproxy_state_directory/$instance/$backend_identity"
    socket="$runtime_directory/$instance.sock"
    backend_name="control_plane_backend_${backend_identity}"
    server_name="control_plane_${backend_identity}"
    ensure_root_directory "$haproxy_state_directory" 700 'HAProxy state root'
    ensure_root_directory "$haproxy_state_directory/$instance" 700 "HAProxy $instance state root"
    ensure_root_directory "$state_directory" 700 "HAProxy $instance backend state"
    ensure_root_directory "$runtime_directory" 755 'HAProxy runtime directory'
    ensure_root_directory "$config_directory" 700 'HAProxy configuration directory'

    {
        printf '# control-plane-operation-id: %s\n' "$operation_id"
        printf '# control-plane-instance: %s\n' "$instance"
        printf '# control-plane-port-owner: %s\n' "$owner"
        printf '# control-plane-backend-identity: %s\n' "$backend_identity"
        printf 'global\n'
        printf '    user %s\n' "$haproxy_user"
        printf '    group %s\n' "$haproxy_group"
        printf '    stats socket %s mode 600 level admin\n' "$socket"
        printf '    server-state-base %s\n' "$state_directory"
        printf '    server-state-file server-state\n'
        printf 'defaults\n'
        printf '    mode http\n'
        printf '    option http-keep-alive\n'
        printf '    timeout connect 5s\n'
        printf '    timeout client 1h\n'
        printf '    timeout server 1h\n'
        printf '    timeout tunnel 1h\n'
        printf '    timeout http-request 30s\n'
        printf '    timeout http-keep-alive 5m\n'
        printf '    load-server-state-from-file global\n'
        printf 'frontend control_plane_port8000_%s\n' "$instance"
        printf '    bind :::%s v4v6\n' "$listen_port"
        printf '    http-request del-header Forwarded\n'
        printf '    http-request del-header X-Real-IP\n'
        printf '    http-request set-header X-Forwarded-For %%[src]\n'
        printf '    http-request set-header X-Forwarded-Proto http\n'
        printf '    http-request set-header X-Forwarded-Host %%[req.hdr(host)]\n'
        printf '    http-request set-header X-Real-IP %%[src]\n'
        printf '    http-response del-header X-Control-Plane-Port-Owner\n'
        printf '    http-response set-header X-Control-Plane-Port-Owner %s\n' "$owner"
        if [[ $test_mode == 1 && ${CONTROL_PLANE_TEST_PORT8000_DUPLICATE_PHASE_OWNER:-0} == 1 ]]; then
            printf '    http-response add-header X-Control-Plane-Port-Owner %s\n' "$owner"
        fi
        printf '    default_backend %s\n' "$backend_name"
        printf 'backend %s\n' "$backend_name"
        printf '    option httpchk GET /api/health\n'
        printf '    server %s %s:%s check inter 1s fall 2 rise 2 id 1\n' "$server_name" "$destination" "$destination_port"
    } > "$config"
    chmod 600 "$config"
    "$haproxy_binary" -c -f "$config" >/dev/null \
        || fail "HAProxy rejected the $instance configuration"
}

install_pool_map_v2()
{
    local destination=$1 first=$2 second=$3 label=$4 candidate candidate_sha256
    candidate="$operation_directory/.${destination##*/}.$$"
    {
        printf 'web-a %s\n' "$first"
        printf 'web-b %s\n' "$second"
    } > "$candidate"
    candidate_sha256=$(checksum "$candidate")
    if [[ -e $destination ]]; then
        assert_pool_file "$destination" 600 "$immutable_uid" "$immutable_gid" "$label"
        [[ $(checksum "$destination") == "$candidate_sha256" ]] \
            || fail "$label differs from the immutable two-slot pool"
        rm -f -- "$candidate"
    else
        atomic_replace "$candidate" "$destination" 600
    fi
}

render_pool_haproxy_config_v2()
{
    local config=$1 state_directory route_health_path
    pool_backend_identity=$(printf '%s\0%s\0%s\0' "$pool_parent_plan_sha256" \
        "$pool_member_set_sha256" "$pool_manifest_sha256" | sha256sum | awk '{print substr($1, 1, 20)}')
    pool_backend_name=control_plane_pool_v2
    state_directory="$haproxy_state_directory/phase-b/$pool_backend_identity"
    pool_server_state_file="$state_directory/server-state"
    route_health_path=$(pool_manifest_value route_health_path)
    [[ $route_health_path == /api/control-plane/route-health ]] \
        || fail 'phase-B HAProxy route-health path is not canonical'
    ensure_root_directory "$haproxy_state_directory" 700 'HAProxy state root'
    ensure_root_directory "$haproxy_state_directory/phase-b" 700 'HAProxy phase-B state root'
    ensure_root_directory "$state_directory" 700 'HAProxy phase-B pool state root'
    ensure_root_directory "$runtime_directory" 755 'HAProxy runtime directory'
    ensure_root_directory "$config_directory" 700 'HAProxy configuration directory'

    {
        printf '# control-plane-operation-id: %s\n' "$operation_id"
        printf '# control-plane-instance: phase-b\n'
        printf '# control-plane-port-owner: permanent-b\n'
        printf '# control-plane-pool-version: 2\n'
        printf '# control-plane-generation: %s\n' "$pool_generation"
        printf '# control-plane-pool-manifest-sha256: %s\n' "$pool_manifest_sha256"
        printf '# control-plane-pool-plan-sha256: %s\n' "$pool_parent_plan_sha256"
        printf '# control-plane-member-set-sha256: %s\n' "$pool_member_set_sha256"
        printf '# control-plane-backend-identity: %s\n' "$pool_backend_identity"
        printf 'global\n'
        printf '    user %s\n' "$haproxy_user"
        printf '    group %s\n' "$haproxy_group"
        printf '    stats socket %s/phase-b.sock mode 600 level admin\n' "$runtime_directory"
        printf '    server-state-base %s\n' "$state_directory"
        printf '    server-state-file server-state\n'
        printf 'defaults\n'
        printf '    mode http\n'
        printf '    option http-keep-alive\n'
        printf '    retries 0\n'
        printf '    timeout connect 5s\n'
        printf '    timeout client 1h\n'
        printf '    timeout server 1h\n'
        printf '    timeout tunnel 1h\n'
        printf '    timeout http-request 30s\n'
        printf '    timeout http-keep-alive 5m\n'
        printf '    load-server-state-from-file global\n'
        printf 'frontend control_plane_port8000_phase-b\n'
        printf '    bind :::%s v4v6\n' "$public_port"
        printf '    http-request del-header Forwarded\n'
        printf '    http-request del-header X-Real-IP\n'
        printf '    http-request set-header X-Forwarded-For %%[src]\n'
        printf '    http-request set-header X-Forwarded-Proto http\n'
        printf '    http-request set-header X-Forwarded-Host %%[req.hdr(host)]\n'
        printf '    http-request set-header X-Real-IP %%[src]\n'
        printf '    acl pool_web_a_up srv_is_up(%s/web-a)\n' "$pool_backend_name"
        printf '    acl pool_web_b_up srv_is_up(%s/web-b)\n' "$pool_backend_name"
        printf '    http-response del-header X-Control-Plane-Port-Owner\n'
        printf '    http-response set-header X-Control-Plane-Port-Owner permanent-b\n'
        printf '    http-response del-header X-Control-Plane-Route-Ack\n'
        printf '    http-response set-header X-Control-Plane-Route-Ack %s if pool_web_a_up pool_web_b_up\n' \
            "$pool_route_ack"
        printf '    http-response set-header X-Control-Plane-Route-Ack %s if !pool_web_a_up pool_web_b_up\n' \
            "$pool_member_a_drain_ack"
        printf '    http-response set-header X-Control-Plane-Route-Ack %s if pool_web_a_up !pool_web_b_up\n' \
            "$pool_member_b_drain_ack"
        printf '    default_backend %s\n' "$pool_backend_name"
        printf 'backend %s\n' "$pool_backend_name"
        printf '    balance roundrobin\n'
        printf '    option httpchk\n'
        printf '    http-check send meth GET uri %s ver HTTP/1.1 hdr Host "%s" hdr X-Control-Plane-Route-Health "%s"\n' \
            "$route_health_path" "$public_host_header" \
            "$pool_route_health_token"
        printf '    http-check expect status 204\n'
        printf '    http-check expect fhdr name X-Control-Plane-Applied-Config value-lf "%%[srv_name,map_str(%s)]"\n' \
            "$pool_slot_ack_map_file"
        printf '    http-check expect fhdr name X-Control-Plane-Pool-Ack value "%s"\n' \
            "$pool_acknowledgement"
        printf '    http-check expect fhdr name X-Control-Plane-Member value-lf "%%[srv_name,map_str(%s)]"\n' \
            "$pool_slot_identity_map_file"
        printf '    server web-a %s:1 check inter 1s fall 1 rise 1 id 1 init-addr last,libc,none\n' \
            "$pool_loopback_address"
        printf '    server web-b %s:1 check inter 1s fall 1 rise 1 id 2 init-addr last,libc,none\n' \
            "$pool_loopback_address"
    } > "$config"
    chmod 600 "$config"
    "$haproxy_binary" -c -f "$config" >/dev/null \
        || fail 'HAProxy rejected the static phase-B pool configuration'
}

pool_state_keys_v2()
{
    printf '%s\n' version operation_id status pool_manifest pool_manifest_sha256 \
        pool_manifest_metadata pool_plan_manifest pool_plan_manifest_sha256 \
        pool_plan_manifest_metadata direction color generation member_set_sha256 \
        backend_name slot_count config_sha256 config_metadata \
        haproxy_binary haproxy_binary_sha256 haproxy_version \
        slot_identity_map_sha256 slot_identity_map_metadata slot_ack_map_sha256 \
        slot_ack_map_metadata server_state_sha256 server_state_metadata main_pid \
        main_start_time main_boot_id worker_pid worker_start_time \
        member_a_address member_a_port member_a_admin_state \
        member_b_address member_b_port member_b_admin_state drained_role drain_epoch route_ack
}

pool_state_value()
{
    safe_state_value "$1" "$pool_state_file"
}

write_pool_state_v2()
{
    local status=$1 candidate
    candidate="$operation_directory/.pool-state.$$"
    {
        printf 'version=3\n'
        printf 'operation_id=%s\n' "$operation_id"
        printf 'status=%s\n' "$status"
        printf 'pool_manifest=%s\n' "$pool_manifest_source"
        printf 'pool_manifest_sha256=%s\n' "$pool_manifest_sha256"
        printf 'pool_manifest_metadata=%s\n' "$pool_manifest_metadata"
        printf 'pool_plan_manifest=%s\n' "$pool_plan_manifest_source"
        printf 'pool_plan_manifest_sha256=%s\n' "$pool_plan_manifest_sha256"
        printf 'pool_plan_manifest_metadata=%s\n' "$pool_plan_manifest_metadata"
        printf 'direction=%s\n' "$pool_direction"
        printf 'color=%s\n' "$pool_color"
        printf 'generation=%s\n' "$pool_generation"
        printf 'member_set_sha256=%s\n' "$pool_member_set_sha256"
        printf 'backend_name=%s\n' "$pool_backend_name"
        printf 'slot_count=2\n'
        printf 'config_sha256=%s\n' "$pool_config_sha256"
        printf 'config_metadata=%s\n' "$pool_config_metadata"
        printf 'haproxy_binary=%s\n' "$pool_haproxy_binary"
        printf 'haproxy_binary_sha256=%s\n' "$pool_haproxy_binary_sha256"
        printf 'haproxy_version=%s\n' "$pool_haproxy_version"
        printf 'slot_identity_map_sha256=%s\n' "$pool_slot_identity_map_sha256"
        printf 'slot_identity_map_metadata=%s\n' "$pool_slot_identity_map_metadata"
        printf 'slot_ack_map_sha256=%s\n' "$pool_slot_ack_map_sha256"
        printf 'slot_ack_map_metadata=%s\n' "$pool_slot_ack_map_metadata"
        printf 'server_state_sha256=%s\n' "$pool_server_state_sha256"
        printf 'server_state_metadata=%s\n' "$pool_server_state_metadata"
        printf 'main_pid=%s\n' "$pool_main_pid"
        printf 'main_start_time=%s\n' "$pool_main_start_time"
        printf 'main_boot_id=%s\n' "$pool_main_boot_id"
        printf 'worker_pid=%s\n' "$pool_worker_pid"
        printf 'worker_start_time=%s\n' "$pool_worker_start_time"
        printf 'member_a_address=%s\n' "$pool_state_member_a_address"
        printf 'member_a_port=%s\n' "$pool_state_member_a_port"
        printf 'member_a_admin_state=%s\n' "$pool_state_member_a_admin_state"
        printf 'member_b_address=%s\n' "$pool_state_member_b_address"
        printf 'member_b_port=%s\n' "$pool_state_member_b_port"
        printf 'member_b_admin_state=%s\n' "$pool_state_member_b_admin_state"
        printf 'drained_role=%s\n' "$pool_drained_role"
        printf 'drain_epoch=%s\n' "$pool_drain_epoch"
        printf 'route_ack=%s\n' "$pool_state_route_ack"
    } > "$candidate"
    atomic_replace "$candidate" "$pool_state_file" 600
    pool_status=$status
}

load_pool_state_v2()
{
    local expected_keys actual_keys
    assert_pool_file "$pool_state_file" 600 "$immutable_uid" "$immutable_gid" \
        'durable phase-B pool state'
    expected_keys=$(pool_state_keys_v2)
    actual_keys=$(sed 's/=.*//' "$pool_state_file")
    [[ $actual_keys == "$expected_keys" \
        && $(wc -l < "$pool_state_file" | tr -d '[:space:]') \
            -eq $(wc -l <<< "$expected_keys" | tr -d '[:space:]') ]] \
        || fail 'durable phase-B pool state keys are reordered, duplicated, absent, or unknown'
    [[ $(pool_state_value version) == 3 \
        && $(pool_state_value operation_id) == "$operation_id" \
        && $(pool_state_value pool_manifest) == "$pool_manifest_source" \
        && $(pool_state_value pool_manifest_sha256) == "$pool_manifest_sha256" \
        && $(pool_state_value pool_plan_manifest) == "$pool_plan_manifest_source" \
        && $(pool_state_value pool_plan_manifest_sha256) == "$pool_plan_manifest_sha256" \
        && $(pool_state_value direction) == "$pool_direction" \
        && $(pool_state_value color) == "$pool_color" \
        && $(pool_state_value generation) == "$pool_generation" \
        && $(pool_state_value member_set_sha256) == "$pool_member_set_sha256" \
        && $(pool_state_value backend_name) == control_plane_pool_v2 \
        && $(pool_state_value slot_count) == 2 ]] \
        || fail 'durable phase-B pool state differs from the requested exact pool'
    pool_status=$(pool_state_value status)
    pool_manifest_metadata=$(pool_state_value pool_manifest_metadata)
    pool_plan_manifest_metadata=$(pool_state_value pool_plan_manifest_metadata)
    pool_backend_name=$(pool_state_value backend_name)
    pool_config_sha256=$(pool_state_value config_sha256)
    pool_config_metadata=$(pool_state_value config_metadata)
    pool_haproxy_binary=$(pool_state_value haproxy_binary)
    pool_haproxy_binary_sha256=$(pool_state_value haproxy_binary_sha256)
    pool_haproxy_version=$(pool_state_value haproxy_version)
    pool_slot_identity_map_sha256=$(pool_state_value slot_identity_map_sha256)
    pool_slot_identity_map_metadata=$(pool_state_value slot_identity_map_metadata)
    pool_slot_ack_map_sha256=$(pool_state_value slot_ack_map_sha256)
    pool_slot_ack_map_metadata=$(pool_state_value slot_ack_map_metadata)
    pool_server_state_sha256=$(pool_state_value server_state_sha256)
    pool_server_state_metadata=$(pool_state_value server_state_metadata)
    pool_main_pid=$(pool_state_value main_pid)
    pool_main_start_time=$(pool_state_value main_start_time)
    pool_main_boot_id=$(pool_state_value main_boot_id)
    pool_worker_pid=$(pool_state_value worker_pid)
    pool_worker_start_time=$(pool_state_value worker_start_time)
    pool_state_member_a_address=$(pool_state_value member_a_address)
    pool_state_member_a_port=$(pool_state_value member_a_port)
    pool_state_member_a_admin_state=$(pool_state_value member_a_admin_state)
    pool_state_member_b_address=$(pool_state_value member_b_address)
    pool_state_member_b_port=$(pool_state_value member_b_port)
    pool_state_member_b_admin_state=$(pool_state_value member_b_admin_state)
    pool_drained_role=$(pool_state_value drained_role)
    pool_drain_epoch=$(pool_state_value drain_epoch)
    pool_state_route_ack=$(pool_state_value route_ack)
    [[ $pool_manifest_metadata == "$(pool_file_metadata "$pool_manifest_source")" \
        && $pool_plan_manifest_metadata == "$(pool_file_metadata "$pool_plan_manifest_source")" ]] \
        || fail 'durable pool state source metadata differs from the attested manifests'
    case "$pool_status" in switch-intent|active|drain-intent|drained) ;; *)
        fail 'durable phase-B pool status is malformed' ;;
    esac
    validate_sha256 phase-B-config "$pool_config_sha256"
    validate_absolute_path phase-B-haproxy-binary "$pool_haproxy_binary"
    validate_sha256 phase-B-haproxy-binary "$pool_haproxy_binary_sha256"
    [[ $pool_haproxy_version =~ ^2\.8\.26(-[0-9A-Fa-f]+)?$ \
        && $(checksum "$pool_haproxy_binary") == "$pool_haproxy_binary_sha256" ]] \
        || fail 'durable phase-B HAProxy binary identity changed'
    validate_sha256 phase-B-slot-identity-map "$pool_slot_identity_map_sha256"
    validate_sha256 phase-B-slot-ack-map "$pool_slot_ack_map_sha256"
    [[ $pool_config_metadata == "$immutable_uid:$immutable_gid:600:"* \
        && $pool_slot_identity_map_metadata == "$immutable_uid:$immutable_gid:600:"* \
        && $pool_slot_ack_map_metadata == "$immutable_uid:$immutable_gid:600:"* ]] \
        || fail 'durable phase-B configuration metadata is malformed'
    if [[ $pool_server_state_sha256 == none ]]; then
        [[ $pool_server_state_metadata == none ]] \
            || fail 'absent phase-B server state has metadata'
    else
        validate_sha256 phase-B-server-state "$pool_server_state_sha256"
        [[ $pool_server_state_metadata == "$immutable_uid:$immutable_gid:600:"* ]] \
            || fail 'durable phase-B server state metadata is malformed'
    fi
    if [[ $pool_main_pid == none ]]; then
        [[ $pool_main_start_time == none && $pool_main_boot_id == none \
            && $pool_worker_pid == none && $pool_worker_start_time == none ]] \
            || fail 'absent phase-B MainPID has a partial process identity'
    else
        [[ $pool_main_pid =~ ^[1-9][0-9]*$ \
            && $pool_main_start_time =~ ^[0-9]+$ \
            && $pool_main_boot_id =~ ^[a-f0-9-]{36}$ ]] \
            || fail 'durable phase-B MainPID identity is malformed'
        [[ $pool_worker_pid =~ ^[1-9][0-9]*$ \
            && $pool_worker_start_time =~ ^[0-9]+$ ]] \
            || fail 'durable phase-B worker identity is malformed'
    fi
    [[ $pool_state_member_a_address == none \
            || $pool_state_member_a_address == "$pool_loopback_address" ]] \
        && [[ $pool_state_member_b_address == none \
            || $pool_state_member_b_address == "$pool_loopback_address" ]] \
        || fail 'durable phase-B slot address is not the canonical loopback'
    [[ $pool_state_member_a_port =~ ^[0-9]+$ \
        && $pool_state_member_b_port =~ ^[0-9]+$ ]] \
        || fail 'durable phase-B slot port is malformed'
    [[ $pool_state_member_a_admin_state =~ ^(maint|ready|drain)$ \
        && $pool_state_member_b_admin_state =~ ^(maint|ready|drain)$ ]] \
        || fail 'durable phase-B slot administrative state is malformed'
}

initialize_pool_state_v2()
{
    local version_line
    pool_manifest_metadata=$(pool_file_metadata "$pool_manifest_source")
    pool_plan_manifest_metadata=$(pool_file_metadata "$pool_plan_manifest_source")
    pool_config_sha256=$(checksum "$pool_config_file")
    pool_config_metadata=$(pool_file_metadata "$pool_config_file")
    pool_haproxy_binary=$(readlink -f -- "$haproxy_binary")
    validate_absolute_path phase-B-haproxy-binary "$pool_haproxy_binary"
    assert_regular_file "$pool_haproxy_binary" 'phase-B HAProxy binary'
    pool_haproxy_binary_sha256=$(checksum "$pool_haproxy_binary")
    version_line=$("$pool_haproxy_binary" -v 2>&1 | sed -n '1s/^HAProxy version \([^ ]*\).*/\1/p')
    [[ $version_line =~ ^2\.8\.26(-[0-9A-Fa-f]+)?$ ]] \
        || fail 'phase-B HAProxy binary is not the exact pinned 2.8.26 runtime'
    if [[ $service_mode == systemd ]]; then
        "$pool_haproxy_binary" -vv 2>&1 | grep -F '+SYSTEMD' >/dev/null \
            || fail 'phase-B HAProxy 2.8.26 binary was not built with USE_SYSTEMD=1'
    fi
    pool_haproxy_version=$version_line
    pool_slot_identity_map_sha256=$(checksum "$pool_slot_identity_map_file")
    pool_slot_identity_map_metadata=$(pool_file_metadata "$pool_slot_identity_map_file")
    pool_slot_ack_map_sha256=$(checksum "$pool_slot_ack_map_file")
    pool_slot_ack_map_metadata=$(pool_file_metadata "$pool_slot_ack_map_file")
    pool_server_state_sha256=none
    pool_server_state_metadata=none
    pool_main_pid=none
    pool_main_start_time=none
    pool_main_boot_id=none
    pool_worker_pid=none
    pool_worker_start_time=none
    pool_state_member_a_address=$pool_loopback_address
    pool_state_member_a_port=1
    pool_state_member_a_admin_state=ready
    pool_state_member_b_address=$pool_loopback_address
    pool_state_member_b_port=1
    pool_state_member_b_admin_state=ready
    pool_drained_role=none
    pool_drain_epoch=none
    pool_state_route_ack=$pool_route_ack
    write_pool_state_v2 switch-intent
}

save_server_state()
{
    local instance=$1 socket destination config backend_identity
    socket="$runtime_directory/$instance.sock"
    config=$(instance_config_file "$instance")
    [[ -f $config ]] || return 0
    backend_identity=$(sed -n 's/^# control-plane-backend-identity: //p' "$config")
    [[ $backend_identity =~ ^[a-f0-9]{20}$ ]] || fail "HAProxy $instance backend state identity is malformed"
    destination="$haproxy_state_directory/$instance/$backend_identity/server-state"
    if [[ -S $socket ]]; then
        printf 'show servers state\n' | socat - "UNIX-CONNECT:$socket" > "$destination.new"
        chmod 600 "$destination.new"
        atomic_replace "$destination.new" "$destination" 600
    fi
}

instance_pid_file()
{
    printf '%s/%s.pid\n' "$runtime_directory" "$1"
}

instance_config_file()
{
    printf '%s/%s.cfg\n' "$config_directory" "$1"
}

current_boot_id()
{
    local boot_id
    boot_id=$(< /proc/sys/kernel/random/boot_id)
    [[ $boot_id =~ ^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$ ]] \
        || fail 'Linux boot ID is malformed'
    printf '%s\n' "$boot_id"
}

process_start_time()
{
    local pid=$1 process_directory process_stat_file process_stat start_time
    local -a process_fields
    [[ $pid =~ ^[1-9][0-9]*$ ]] || fail 'HAProxy MainPID is malformed'

    process_directory="$process_root/$pid"
    if [[ ! -e $process_directory && ! -L $process_directory ]]; then
        return 1
    fi
    [[ -d $process_directory && ! -L $process_directory ]] || return 2

    process_stat_file="$process_directory/stat"
    [[ -e $process_stat_file && ! -L $process_stat_file && ! -d $process_stat_file && -r $process_stat_file ]] || return 2
    if ! process_stat=$(< "$process_stat_file"); then
        return 2
    fi
    read -r -a process_fields <<< "${process_stat##*) }"
    [[ ${#process_fields[@]} -ge 20 ]] || return 2
    start_time=${process_fields[19]}
    [[ $start_time =~ ^[0-9]+$ ]] || return 2
    printf '%s\n' "$start_time"
}

process_parent_pid_v2()
{
    local pid=$1 process_stat process_stat_file
    local -a process_fields
    process_stat_file="$process_root/$pid/stat"
    [[ -f $process_stat_file && ! -L $process_stat_file && -r $process_stat_file ]] \
        || fail 'HAProxy worker process stat is unavailable'
    process_stat=$(<"$process_stat_file")
    read -r -a process_fields <<< "${process_stat##*) }"
    [[ ${#process_fields[@]} -ge 2 && ${process_fields[1]} =~ ^[1-9][0-9]*$ ]] \
        || fail 'HAProxy worker parent PID is malformed'
    printf '%s\n' "${process_fields[1]}"
}

assert_pool_process_command_v2()
{
    local pid=$1 observed_executable mode index cmdline
    local -a observed_argument=() expected_argument=()
    cmdline="$process_root/$pid/cmdline"
    [[ -f $cmdline && ! -L $cmdline && -r $cmdline ]] \
        || fail 'HAProxy process command line is unavailable'
    mapfile -d '' -t observed_argument < "$cmdline"
    if [[ $service_mode == systemd ]]; then
        mode=-Ws
    else
        mode=-D
    fi
    expected_argument=("$pool_haproxy_binary" "$mode" -f "$pool_config_file" \
        -p "$(instance_pid_file phase-b)")
    [[ ${#observed_argument[@]} -eq ${#expected_argument[@]} ]] \
        || fail 'HAProxy process argv contains an absent, duplicate, or extra argument'
    for index in "${!expected_argument[@]}"; do
        [[ ${observed_argument[$index]} == "${expected_argument[$index]}" ]] \
            || fail 'HAProxy process argv differs from the exact pinned NUL grammar'
    done
    observed_executable=$(readlink -f -- "$process_root/$pid/exe") \
        || fail 'HAProxy process executable path cannot be resolved'
    [[ $observed_executable == "$pool_haproxy_binary" ]] \
        || fail 'HAProxy process executable path differs from its pinned binary'
    [[ $(checksum "$process_root/$pid/exe") == "$pool_haproxy_binary_sha256" ]] \
        || fail 'HAProxy process executable bytes differ from its pinned binary'
}

systemd_activity_state()
{
    local instance=$1 unit active_state

    unit="coolify-port8000-haproxy@${instance}.service"
    active_state=$(systemctl show --property=ActiveState --value "$unit") || return 1
    case $active_state in
        active | inactive) ;;
        *) return 2 ;;
    esac
    printf '%s\n' "$active_state"
}

assert_systemd_activity_state()
{
    local instance=$1 expected_state=$2 active_state

    if ! active_state=$(systemd_activity_state "$instance"); then
        fail "systemd could not safely determine HAProxy $instance activity state"
    fi
    if [[ $active_state != "$expected_state" ]]; then
        if [[ $expected_state == active ]]; then
            fail "systemd reports HAProxy $instance $active_state before finalization stop"
        fi
        fail "HAProxy $instance remained $active_state after systemd stop"
    fi
}

phase_a_main_process_identity_is_absent()
{
    [[ $state_phase_a_main_pid == none \
        && $state_phase_a_main_start_time == none \
        && $state_phase_a_main_boot_id == none ]]
}

assert_phase_a_main_process_identity()
{
    [[ $state_phase_a_main_pid =~ ^[1-9][0-9]*$ \
        && $state_phase_a_main_start_time =~ ^[0-9]+$ \
        && $state_phase_a_main_boot_id =~ ^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$ ]] \
        || fail 'durable phase-A HAProxy MainPID identity is malformed'
}

capture_phase_a_main_process_identity()
{
    local unit main_pid main_start_time process_status
    [[ $service_mode == systemd ]] || return 0
    if ! phase_a_main_process_identity_is_absent; then
        assert_phase_a_main_process_identity
        return
    fi
    unit='coolify-port8000-haproxy@phase-a.service'
    assert_systemd_activity_state phase-a active
    main_pid=$(systemctl show --property=MainPID --value "$unit") \
        || fail 'systemd could not report phase-A HAProxy MainPID before finalization stop'
    [[ $main_pid =~ ^[1-9][0-9]*$ ]] \
        || fail 'systemd reported no live phase-A HAProxy MainPID before finalization stop'
    if main_start_time=$(process_start_time "$main_pid"); then
        :
    else
        process_status=$?
        if [[ $process_status -eq 1 ]]; then
            fail 'phase-A HAProxy MainPID disappeared before finalization stop attestation'
        fi
        fail 'phase-A HAProxy MainPID process stat could not be safely attested before finalization stop'
    fi
    state_phase_a_main_pid=$main_pid
    state_phase_a_main_start_time=$main_start_time
    state_phase_a_main_boot_id=$(current_boot_id)
}

assert_phase_a_main_process_exited()
{
    local observed_start_time process_status
    [[ $service_mode == systemd ]] || return 0
    assert_phase_a_main_process_identity
    [[ $(current_boot_id) == "$state_phase_a_main_boot_id" ]] || return 0
    if observed_start_time=$(process_start_time "$state_phase_a_main_pid"); then
        [[ $observed_start_time != "$state_phase_a_main_start_time" ]] \
            || fail "captured phase-A HAProxy MainPID $state_phase_a_main_pid remains live after systemd stop"
    else
        process_status=$?
        [[ $process_status -eq 1 ]] \
            || fail "captured phase-A HAProxy MainPID $state_phase_a_main_pid process stat could not be safely attested after systemd stop"
    fi
    return 0
}

instance_is_running()
{
    local instance=$1 pid_file pid active_state
    if [[ $service_mode == systemd ]]; then
        if ! active_state=$(systemd_activity_state "$instance"); then
            fail "systemd could not safely determine HAProxy $instance activity state"
        fi
        case $active_state in
            active) return 0 ;;
            inactive) return 1 ;;
        esac
    fi
    pid_file=$(instance_pid_file "$instance")
    [[ -f $pid_file ]] || return 1
    pid=$(<"$pid_file")
    [[ $pid =~ ^[1-9][0-9]*$ ]] && kill -0 "$pid" 2>/dev/null
}

assert_admin_socket()
{
    local instance=$1 socket attempt=0
    socket="$runtime_directory/$instance.sock"
    while ((attempt < 50)); do
        if [[ -S $socket ]]; then
            [[ $(stat -c '%a:%u' "$socket") == 600:0 ]] \
                || fail "HAProxy $instance admin socket is not root-owned mode 0600"
            printf 'show info\n' | socat - "UNIX-CONNECT:$socket" | grep -F 'Name: HAProxy' >/dev/null \
                || fail "HAProxy $instance admin socket did not answer"
            return
        fi
        attempt=$((attempt + 1))
        sleep 0.1
    done
    fail "HAProxy $instance admin socket did not appear"
}

configure_pool_paths_v2()
{
    pool_backend_identity=$(printf '%s\0%s\0%s\0' "$pool_parent_plan_sha256" \
        "$pool_member_set_sha256" "$pool_manifest_sha256" | sha256sum | awk '{print substr($1, 1, 20)}')
    pool_backend_name=control_plane_pool_v2
    pool_config_file=$(instance_config_file phase-b)
    pool_slot_identity_map_file="$operation_directory/phase-b-slot-route-identity.map"
    pool_slot_ack_map_file="$operation_directory/phase-b-slot-applied-ack.map"
    pool_server_state_file="$haproxy_state_directory/phase-b/$pool_backend_identity/server-state"
    pool_mutation_intent_file="$operation_directory/phase-b-mutation.intent"
    pool_start_authorization_file="$runtime_directory/phase-b.start-authorization"
}

pool_start_authorization_keys_v2()
{
    printf '%s\n' version instance operation_id generation boot_id controller_path \
        controller_sha256 issuer_pid issuer_start_time controller_lock haproxy_binary \
        haproxy_binary_sha256 config_file config_sha256 pool_state_file pool_state_sha256 \
        server_state_file server_state_sha256
}

pool_boot_identity_keys_v2()
{
    printf '%s\n' version operation_id generation controller_path controller_sha256 \
        haproxy_binary haproxy_binary_sha256 provenance_file provenance_sha256 \
        config_file config_sha256 pool_state_file pool_state_sha256 server_state_file \
        server_state_sha256 operation_state_file operation_state_sha256
}

pool_boot_identity_file_v2()
{
    printf '%s\n' /var/lib/coolify-control-plane-port8000/phase-b.boot-identity
}

pool_boot_receipt_file_v2()
{
    printf '%s\n' /var/lib/coolify-control-plane-port8000/phase-b.boot-receipt
}

write_pool_boot_receipt_v2()
{
    local identity=$1 receipt candidate
    receipt=$(pool_boot_receipt_file_v2)
    candidate="${receipt}.candidate.$$"
    {
        printf 'version=1\n'
        printf 'boot_id=%s\n' "$(current_boot_id)"
        printf 'identity_sha256=%s\n' "$(checksum "$identity")"
    } > "$candidate"
    atomic_replace "$candidate" "$receipt" 600
}

consume_pool_boot_allowance_v2()
{
    local identity=$1 receipt receipt_boot
    receipt=$(pool_boot_receipt_file_v2)
    if [[ -e $receipt ]]; then
        assert_pool_file "$receipt" 600 0 0 'phase-B boot receipt'
        receipt_boot=$(safe_state_value boot_id "$receipt")
        [[ $receipt_boot != "$(current_boot_id)" ]] \
            || fail 'phase-B boot start allowance is already consumed for this boot'
    fi

    # Spend the durable allowance before anything authorizing a start appears in /run.
    # A crash after this point strands this boot fail-closed instead of allowing replay.
    write_pool_boot_receipt_v2 "$identity"
    test_crash after-phase-b-boot-allowance-consumed
}

publish_pool_boot_identity_v2()
{
    local identity candidate installed_controller provenance
    [[ $service_mode == systemd ]] || return 0
    identity=$(pool_boot_identity_file_v2)
    candidate="${identity}.candidate.$$"
    installed_controller=/usr/local/libexec/coolify-haproxy-port8000-controller
    provenance=/usr/local/lib/coolify-control-plane-port8000/haproxy-2.8.26/provenance
    assert_pool_file "$installed_controller" 700 0 0 'installed phase-B boot controller'
    assert_pool_file "$provenance" 600 0 0 'HAProxy runtime provenance'
    assert_pool_file "$pool_server_state_file" 600 "$immutable_uid" "$immutable_gid" \
        'phase-B boot server state'
    [[ $(safe_state_value phase "$state_file") == permanent ]] \
        || fail 'phase-B boot identity requires permanent operation state'
    {
        printf 'version=1\n'
        printf 'operation_id=%s\n' "$operation_id"
        printf 'generation=%s\n' "$pool_generation"
        printf 'controller_path=%s\n' "$installed_controller"
        printf 'controller_sha256=%s\n' "$(checksum "$installed_controller")"
        printf 'haproxy_binary=%s\n' "$pool_haproxy_binary"
        printf 'haproxy_binary_sha256=%s\n' "$pool_haproxy_binary_sha256"
        printf 'provenance_file=%s\n' "$provenance"
        printf 'provenance_sha256=%s\n' "$(checksum "$provenance")"
        printf 'config_file=%s\n' "$pool_config_file"
        printf 'config_sha256=%s\n' "$pool_config_sha256"
        printf 'pool_state_file=%s\n' "$pool_state_file"
        printf 'pool_state_sha256=%s\n' "$(checksum "$pool_state_file")"
        printf 'server_state_file=%s\n' "$pool_server_state_file"
        printf 'server_state_sha256=%s\n' "$(checksum "$pool_server_state_file")"
        printf 'operation_state_file=%s\n' "$state_file"
        printf 'operation_state_sha256=%s\n' "$(checksum "$state_file")"
    } > "$candidate"
    if [[ -e $identity ]]; then
        assert_pool_file "$identity" 600 0 0 'phase-B boot identity'
    fi
    atomic_replace "$candidate" "$identity" 600
    write_pool_boot_receipt_v2 "$identity"
}

write_pool_start_authorization_v2()
{
    local candidate controller_path server_state_sha256 issuer_start
    [[ $service_mode == systemd ]] || return 0
    [[ ! -e $pool_start_authorization_file && ! -L $pool_start_authorization_file ]] \
        || fail 'a phase-B start authorization already exists'
    controller_path=$(readlink -f -- "$0") \
        || fail 'phase-B start authorization cannot resolve the controller path'
    issuer_start=$(process_start_time "$$") \
        || fail 'phase-B start authorization cannot bind the controller process'
    if [[ -e $pool_server_state_file ]]; then
        assert_pool_file "$pool_server_state_file" 600 "$immutable_uid" "$immutable_gid" \
            'phase-B start authorization server state'
        server_state_sha256=$(checksum "$pool_server_state_file")
    else
        [[ ! -L $pool_server_state_file ]] \
            || fail 'phase-B start authorization found a symlinked absent server state'
        server_state_sha256=absent
    fi
    candidate="$runtime_directory/.phase-b.start-authorization.$$"
    {
        printf 'version=1\n'
        printf 'instance=phase-b\n'
        printf 'operation_id=%s\n' "$operation_id"
        printf 'generation=%s\n' "$pool_generation"
        printf 'boot_id=%s\n' "$(current_boot_id)"
        printf 'controller_path=%s\n' "$controller_path"
        printf 'controller_sha256=%s\n' "$(checksum "$controller_path")"
        printf 'issuer_pid=%s\n' "$$"
        printf 'issuer_start_time=%s\n' "$issuer_start"
        printf 'controller_lock=%s\n' "$lock_file"
        printf 'haproxy_binary=%s\n' "$pool_haproxy_binary"
        printf 'haproxy_binary_sha256=%s\n' "$pool_haproxy_binary_sha256"
        printf 'config_file=%s\n' "$pool_config_file"
        printf 'config_sha256=%s\n' "$pool_config_sha256"
        printf 'pool_state_file=%s\n' "$pool_state_file"
        printf 'pool_state_sha256=%s\n' "$(checksum "$pool_state_file")"
        printf 'server_state_file=%s\n' "$pool_server_state_file"
        printf 'server_state_sha256=%s\n' "$server_state_sha256"
    } > "$candidate"
    atomic_replace "$candidate" "$pool_start_authorization_file" 600
}

consume_pool_start_authorization_v2()
{
    local instance=$1 authorization expected_keys actual_keys test_mode expected_uid expected_gid
    local controller_path controller_lock issuer_pid issuer_start observed_start config_file
    local pool_state_path server_state_path server_state_sha consumed
    process_root=/proc
    test_mode=${CONTROL_PLANE_PORT8000_START_AUTH_TEST_MODE:-0}
    [[ $test_mode == 0 || $test_mode == 1 ]] \
        || fail 'phase-B start authorization test mode must be 0 or 1'
    if [[ $test_mode == 0 ]]; then
        authorization=/run/coolify-control-plane-port8000/phase-b.start-authorization
        expected_uid=0
        expected_gid=0
    else
        authorization=${CONTROL_PLANE_PORT8000_START_AUTH_FILE:?}
        expected_uid=$(id -u)
        expected_gid=$(id -g)
    fi
    [[ $instance == phase-b ]] || fail 'phase-B start authorization instance is not exact'
    assert_pool_file "$authorization" 600 "$expected_uid" "$expected_gid" \
        'one-shot phase-B start authorization'
    expected_keys=$(pool_start_authorization_keys_v2)
    actual_keys=$(sed 's/=.*//' "$authorization")
    [[ $actual_keys == "$expected_keys" \
        && $(wc -l < "$authorization" | tr -d '[:space:]') \
            -eq $(wc -l <<< "$expected_keys" | tr -d '[:space:]') ]] \
        || fail 'phase-B start authorization keys are reordered, duplicated, absent, or unknown'
    [[ $(safe_state_value version "$authorization") == 1 \
        && $(safe_state_value instance "$authorization") == phase-b \
        && $(safe_state_value operation_id "$authorization") \
            =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ \
        && $(safe_state_value generation "$authorization") =~ ^[1-9][0-9]*$ \
        && $(safe_state_value boot_id "$authorization") == "$(current_boot_id)" ]] \
        || fail 'phase-B start authorization identity or boot binding is invalid'
    controller_path=$(safe_state_value controller_path "$authorization")
    validate_absolute_path phase-B-start-controller "$controller_path"
    assert_pool_file "$controller_path" 700 "$expected_uid" "$expected_gid" \
        'phase-B start authorization controller'
    [[ $controller_path == "$(readlink -f -- "$0")" \
        && $(checksum "$controller_path") \
            == "$(safe_state_value controller_sha256 "$authorization")" ]] \
        || fail 'phase-B start authorization controller identity changed'
    issuer_pid=$(safe_state_value issuer_pid "$authorization")
    issuer_start=$(safe_state_value issuer_start_time "$authorization")
    [[ $issuer_pid =~ ^[1-9][0-9]*$ && $issuer_start =~ ^[0-9]+$ ]] \
        || fail 'phase-B start authorization issuer identity is malformed'
    observed_start=$(process_start_time "$issuer_pid") \
        || fail 'phase-B start authorization issuer is no longer live'
    [[ $observed_start == "$issuer_start" ]] \
        || fail 'phase-B start authorization issuer process was replaced'
    controller_lock=$(safe_state_value controller_lock "$authorization")
    validate_absolute_path phase-B-controller-lock "$controller_lock"
    assert_pool_file "$controller_lock" 600 "$expected_uid" "$expected_gid" \
        'phase-B controller lock'
    exec {authorization_lock_fd}<>"$controller_lock"
    if flock -n "$authorization_lock_fd"; then
        flock -u "$authorization_lock_fd"
        exec {authorization_lock_fd}>&-
        fail 'phase-B start authorization is not backed by the live controller lock'
    fi
    exec {authorization_lock_fd}>&-
    [[ $(safe_state_value haproxy_binary "$authorization") \
        == /usr/local/lib/coolify-control-plane-port8000/haproxy-2.8.26/haproxy ]] \
        || fail 'phase-B start authorization binary path differs from the unit'
    [[ $(checksum /usr/local/lib/coolify-control-plane-port8000/haproxy-2.8.26/haproxy) \
        == "$(safe_state_value haproxy_binary_sha256 "$authorization")" ]] \
        || fail 'phase-B start authorization binary bytes changed'
    config_file=$(safe_state_value config_file "$authorization")
    [[ $config_file == /etc/coolify-control-plane-port8000/phase-b.cfg ]] \
        || fail 'phase-B start authorization config path differs from the unit'
    assert_pool_file "$config_file" 600 "$expected_uid" "$expected_gid" \
        'phase-B authorized configuration'
    [[ $(checksum "$config_file") == "$(safe_state_value config_sha256 "$authorization")" \
        && $(sed -n 's/^# control-plane-operation-id: //p' "$config_file") \
            == "$(safe_state_value operation_id "$authorization")" \
        && $(sed -n 's/^# control-plane-generation: //p' "$config_file") \
            == "$(safe_state_value generation "$authorization")" ]] \
        || fail 'phase-B authorized configuration identity changed'
    pool_state_path=$(safe_state_value pool_state_file "$authorization")
    validate_absolute_path phase-B-authorized-pool-state "$pool_state_path"
    assert_pool_file "$pool_state_path" 600 "$expected_uid" "$expected_gid" \
        'phase-B authorized pool state'
    [[ $(checksum "$pool_state_path") == "$(safe_state_value pool_state_sha256 "$authorization")" \
        && $(safe_state_value operation_id "$pool_state_path") \
            == "$(safe_state_value operation_id "$authorization")" \
        && $(safe_state_value generation "$pool_state_path") \
            == "$(safe_state_value generation "$authorization")" ]] \
        || fail 'phase-B authorized pool state identity changed'
    server_state_path=$(safe_state_value server_state_file "$authorization")
    server_state_sha=$(safe_state_value server_state_sha256 "$authorization")
    validate_absolute_path phase-B-authorized-server-state "$server_state_path"
    if [[ $server_state_sha == absent ]]; then
        [[ ! -e $server_state_path && ! -L $server_state_path ]] \
            || fail 'phase-B authorized absent server state appeared before start'
    else
        validate_sha256 phase-B-authorized-server-state "$server_state_sha"
        assert_pool_file "$server_state_path" 600 "$expected_uid" "$expected_gid" \
            'phase-B authorized server state'
        [[ $(checksum "$server_state_path") == "$server_state_sha" ]] \
            || fail 'phase-B authorized server state changed'
    fi
    consumed="${authorization}.consumed.$$"
    mv -- "$authorization" "$consumed"
    sync "$(dirname -- "$authorization")"
    rm -f -- "$consumed"
    sync "$(dirname -- "$authorization")"
}

issue_pool_boot_start_authorization_v2()
{
    local identity expected_keys actual_keys operation_state provenance
    local pid attempt=0
    identity=$(pool_boot_identity_file_v2)
    assert_pool_file "$identity" 600 0 0 'phase-B boot identity'
    expected_keys=$(pool_boot_identity_keys_v2)
    actual_keys=$(sed 's/=.*//' "$identity")
    [[ $actual_keys == "$expected_keys" \
        && $(wc -l < "$identity" | tr -d '[:space:]') \
            -eq $(wc -l <<< "$expected_keys" | tr -d '[:space:]') ]] \
        || fail 'phase-B boot identity keys are reordered, duplicated, absent, or unknown'
    operation_id=$(safe_state_value operation_id "$identity")
    pool_generation=$(safe_state_value generation "$identity")
    pool_haproxy_binary=$(safe_state_value haproxy_binary "$identity")
    pool_haproxy_binary_sha256=$(safe_state_value haproxy_binary_sha256 "$identity")
    pool_config_file=$(safe_state_value config_file "$identity")
    pool_config_sha256=$(safe_state_value config_sha256 "$identity")
    pool_state_file=$(safe_state_value pool_state_file "$identity")
    pool_server_state_file=$(safe_state_value server_state_file "$identity")
    operation_state=$(safe_state_value operation_state_file "$identity")
    provenance=$(safe_state_value provenance_file "$identity")
    [[ $operation_id =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ \
        && $pool_generation =~ ^[1-9][0-9]*$ ]] \
        || fail 'phase-B boot identity operation or generation is malformed'
    [[ $(safe_state_value controller_path "$identity") == "$(readlink -f -- "$0")" \
        && $(checksum "$0") == "$(safe_state_value controller_sha256 "$identity")" ]] \
        || fail 'phase-B boot controller identity changed'
    [[ $pool_haproxy_binary \
            == /usr/local/lib/coolify-control-plane-port8000/haproxy-2.8.26/haproxy \
        && $(checksum "$pool_haproxy_binary") == "$pool_haproxy_binary_sha256" ]] \
        || fail 'phase-B boot HAProxy binary identity changed'
    assert_pool_file "$provenance" 600 0 0 'phase-B boot HAProxy provenance'
    [[ $(checksum "$provenance") == "$(safe_state_value provenance_sha256 "$identity")" \
        && $(safe_state_value haproxy_binary "$provenance") == "$pool_haproxy_binary" \
        && $(safe_state_value haproxy_binary_sha256 "$provenance") \
            == "$pool_haproxy_binary_sha256" \
        && $(safe_state_value haproxy_version "$provenance") == 2.8.26 \
        && $(safe_state_value haproxy_source_sha256 "$provenance") \
            == 88c28dae25ea46672e66f8db0dadd1fb5920e06ee2415ceb9f281c256b537727 \
        && $(safe_state_value haproxy_build_options "$provenance") \
            == 'TARGET=linux-glibc USE_SYSTEMD=1' ]] \
        || fail 'phase-B boot HAProxy provenance changed'
    assert_pool_file "$pool_config_file" 600 0 0 'phase-B boot configuration'
    assert_pool_file "$pool_state_file" 600 0 0 'phase-B boot pool state'
    assert_pool_file "$pool_server_state_file" 600 0 0 'phase-B boot server state'
    assert_pool_file "$operation_state" 600 0 0 'phase-B boot operation state'
    [[ $(checksum "$pool_config_file") == "$pool_config_sha256" \
        && $(checksum "$pool_state_file") == "$(safe_state_value pool_state_sha256 "$identity")" \
        && $(checksum "$pool_server_state_file") \
            == "$(safe_state_value server_state_sha256 "$identity")" \
        && $(checksum "$operation_state") \
            == "$(safe_state_value operation_state_sha256 "$identity")" \
        && $(safe_state_value phase "$operation_state") == permanent \
        && $(safe_state_value operation_id "$pool_state_file") == "$operation_id" \
        && $(safe_state_value generation "$pool_state_file") == "$pool_generation" ]] \
        || fail 'phase-B boot durable state identity changed'
    "$pool_haproxy_binary" -c -f "$pool_config_file" >/dev/null \
        || fail 'phase-B boot configuration validation failed'

    runtime_directory=/run/coolify-control-plane-port8000
    pool_start_authorization_file="$runtime_directory/phase-b.start-authorization"
    lock_file="$runtime_directory/phase-b.boot-authorizer.lock"
    service_mode=systemd
    immutable_uid=0
    immutable_gid=0
    exec 9> "$lock_file"
    chmod 600 "$lock_file"
    flock -n 9 || fail 'phase-B boot authorizer lock is already held'
    consume_pool_boot_allowance_v2 "$identity"
    write_pool_start_authorization_v2
    /usr/bin/systemd-notify --ready --status='phase-B boot authorization issued' \
        || fail 'phase-B boot authorizer could not notify systemd'
    while ((attempt < 100)); do
        if [[ ! -e $pool_start_authorization_file && -f $runtime_directory/phase-b.pid ]]; then
            pid=$(<"$runtime_directory/phase-b.pid")
            if [[ $pid =~ ^[1-9][0-9]*$ ]] && kill -0 "$pid" 2>/dev/null; then
                return
            fi
        fi
        attempt=$((attempt + 1))
        sleep 0.1
    done
    rm -f -- "$pool_start_authorization_file"
    fail 'phase-B boot authorization was not consumed by a live HAProxy process'
}

assert_pool_config_assets_v2()
{
    assert_pool_file "$pool_config_file" 600 "$immutable_uid" "$immutable_gid" \
        'phase-B pool configuration' "$pool_config_metadata"
    assert_pool_file "$pool_slot_identity_map_file" 600 "$immutable_uid" "$immutable_gid" \
        'phase-B slot identity map' "$pool_slot_identity_map_metadata"
    assert_pool_file "$pool_slot_ack_map_file" 600 "$immutable_uid" "$immutable_gid" \
        'phase-B slot acknowledgement map' "$pool_slot_ack_map_metadata"
    [[ $(checksum "$pool_config_file") == "$pool_config_sha256" \
        && $(checksum "$pool_slot_identity_map_file") == "$pool_slot_identity_map_sha256" \
        && $(checksum "$pool_slot_ack_map_file") == "$pool_slot_ack_map_sha256" ]] \
        || fail 'phase-B configuration or slot map differs from durable identity'
    [[ $(grep -E -c '^[[:space:]]+server web-(a|b) ' "$pool_config_file") -eq 2 \
        && $(grep -F -c '    server web-a ' "$pool_config_file") -eq 1 \
        && $(grep -F -c '    server web-b ' "$pool_config_file") -eq 1 \
        && $(grep -F -x -c 'backend control_plane_pool_v2' "$pool_config_file") -eq 1 ]] \
        || fail 'phase-B configuration no longer contains one exact two-slot backend'
    grep -F -x -q "# control-plane-pool-manifest-sha256: $pool_manifest_sha256" \
        "$pool_config_file" || fail 'phase-B configuration belongs to another ingress pool'
}

install_pool_config_assets_v2()
{
    local config_candidate candidate_sha256
    install_pool_map_v2 "$pool_slot_identity_map_file" "$pool_member_a_route_identity" \
        "$pool_member_b_route_identity" 'phase-B slot identity map'
    install_pool_map_v2 "$pool_slot_ack_map_file" "$pool_member_a_applied_ack" \
        "$pool_member_b_applied_ack" 'phase-B slot acknowledgement map'
    config_candidate="$operation_directory/.phase-b-pool.$$"
    render_pool_haproxy_config_v2 "$config_candidate"
    candidate_sha256=$(checksum "$config_candidate")
    if [[ -e $pool_config_file ]]; then
        assert_pool_file "$pool_config_file" 600 "$immutable_uid" "$immutable_gid" \
            'phase-B pool configuration'
        [[ $(checksum "$pool_config_file") == "$candidate_sha256" ]] \
            || fail 'refusing to reload or replace a different phase-B configuration'
        rm -f -- "$config_candidate"
    else
        atomic_replace "$config_candidate" "$pool_config_file" 600
    fi
}

pool_current_main_pid_v2()
{
    local pid
    if [[ $service_mode == systemd ]]; then
        pid=$(systemctl show --property=MainPID --value \
            coolify-port8000-haproxy@phase-b.service) \
            || fail 'systemd could not report phase-B HAProxy MainPID'
    else
        assert_regular_file "$(instance_pid_file phase-b)" 'phase-B HAProxy PID file'
        pid=$(<"$(instance_pid_file phase-b)")
    fi
    [[ $pid =~ ^[1-9][0-9]*$ ]] || fail 'phase-B HAProxy MainPID is malformed or absent'
    printf '%s\n' "$pid"
}

capture_or_attest_pool_main_pid_v2()
{
    local current_pid current_start current_boot
    current_pid=$(pool_current_main_pid_v2)
    current_start=$(process_start_time "$current_pid") \
        || fail 'phase-B HAProxy MainPID process identity cannot be read'
    current_boot=$(current_boot_id)
    if [[ $pool_main_pid == none || $pool_main_boot_id != "$current_boot" ]]; then
        pool_main_pid=$current_pid
        pool_main_start_time=$current_start
        pool_main_boot_id=$current_boot
        pool_worker_pid=none
        pool_worker_start_time=none
        return
    fi
    [[ $pool_main_pid == "$current_pid" \
        && $pool_main_start_time == "$current_start" \
        && $pool_main_boot_id == "$current_boot" ]] \
        || fail 'phase-B HAProxy MainPID identity changed without a cold boot'
}

start_pool_instance_once_v2()
{
    local pid_file
    if instance_is_running phase-b; then
        assert_pool_runtime_identity_v2
        return
    fi
    [[ $pool_main_pid == none || $pool_main_boot_id != "$(current_boot_id)" ]] \
        || fail 'phase-B HAProxy stopped after its same-boot MainPID was pinned; restart is forbidden'
    if [[ $service_mode == systemd ]]; then
        write_pool_start_authorization_v2
        if ! systemctl start coolify-port8000-haproxy@phase-b.service; then
            rm -f -- "$pool_start_authorization_file"
            sync "$runtime_directory"
            fail 'systemd failed to consume authorization and start the immutable phase-B pool'
        fi
        [[ ! -e $pool_start_authorization_file && ! -L $pool_start_authorization_file ]] \
            || fail 'systemd started phase-B without consuming its one-shot authorization'
    else
        pid_file=$(instance_pid_file phase-b)
        "$haproxy_binary" -D -f "$pool_config_file" -p "$pid_file" 9>&-
    fi
    assert_admin_socket phase-b
    assert_pool_runtime_identity_v2
}

pool_runtime_command_v2()
{
    local command=$1 output socket info_file state_snapshot
    socket="$runtime_directory/phase-b.sock"
    info_file="$operation_directory/.phase-b-mutation-info.$$"
    state_snapshot="$operation_directory/.phase-b-mutation-state.$$"
    capture_pool_runtime_snapshot_v2 "$info_file" "$state_snapshot"
    rm -f -- "$info_file" "$state_snapshot"
    [[ -S $socket && ! -L $socket \
        && $(stat -c '%a:%u:%g' "$socket") == "600:$immutable_uid:$immutable_gid" ]] \
        || fail 'phase-B HAProxy admin socket identity is unsafe'
    output=$(printf '%s\n' "$command" | socat - "UNIX-CONNECT:$socket") \
        || fail "HAProxy rejected runtime command: ${command%% *}"
    case "$command" in
        "set server $pool_backend_name/"web-?" addr "*)
            [[ $output =~ ^no\ need\ to\ change\ the\ addr,\ (port\ changed\ from\ \'[0-9]+\'\ to\ \'[0-9]+\'|no\ need\ to\ change\ the\ port)\ by\ \'stats\ socket\ command\'$ ]] \
                || fail 'HAProxy address mutation returned an unexpected response'
            ;;
        "set server $pool_backend_name/"web-?" state "*)
            [[ -z $output ]] \
                || fail 'HAProxy administrative-state mutation returned an unexpected response'
            ;;
        *) fail 'HAProxy runtime mutation command is outside the exact pool grammar' ;;
    esac
}

assert_pool_server_state_shape_v2()
{
    local state_file=$1 counts expected_header
    expected_header='# be_id be_name srv_id srv_name srv_addr srv_op_state srv_admin_state srv_uweight srv_iweight srv_time_since_last_change srv_check_status srv_check_result srv_check_health srv_check_state srv_agent_state bk_f_forced_id srv_f_forced_id srv_fqdn srv_port srvrecord srv_use_ssl srv_check_port srv_check_addr srv_agent_addr srv_agent_port'
    [[ $(sed -n '1p' "$state_file") == 1 \
        && $(grep -F -x -c "$expected_header" "$state_file") -eq 1 ]] \
        || fail 'canonical HAProxy 2.8 server-state header is absent or changed'
    counts=$(awk -v backend="$pool_backend_name" '
        $1 == "#" {
            for (column = 2; column <= NF; column++) {
                field[$column] = column - 1
            }
            next
        }
        field["be_name"] && NF > 1 {
            total++
            if ($field["be_name"] != backend) invalid++
            server = $field["srv_name"]
            if (server == "web-a" && $field["srv_id"] == 1) a++
            else if (server == "web-b" && $field["srv_id"] == 2) b++
            else invalid++
            if ($field["srv_op_state"] !~ /^[0-3]$/) invalid++
            if ($field["srv_admin_state"] !~ /^(0|1|8)$/) invalid++
            if ($field["srv_addr"] !~ /^[A-Fa-f0-9:.]+$/) invalid++
            if ($field["srv_port"] !~ /^[1-9][0-9]{0,4}$/ \
                || $field["srv_port"] + 0 > 65535) invalid++
        }
        END { print total + 0 ":" a + 0 ":" b + 0 ":" invalid + 0 }
    ' "$state_file")
    [[ $counts == 2:1:1:0 ]] \
        || fail 'canonical HAProxy 2.8 server state is not the exact typed web-a/web-b inventory'
}

pool_server_state_field_from_v2()
{
    local state_file=$1 member=$2 field_name=$3
    awk -v backend="$pool_backend_name" -v server="web-$member" -v wanted="$field_name" '
        $1 == "#" {
            for (column = 2; column <= NF; column++) {
                field[$column] = column - 1
            }
            next
        }
        field["be_name"] && $field["be_name"] == backend \
            && $field["srv_name"] == server {
            count++
            result = $field[wanted]
        }
        END {
            if (count != 1 || result == "") exit 1
            print result
        }
    ' "$state_file"
}

pool_server_state_field_v2()
{
    pool_server_state_field_from_v2 "$pool_server_state_file" "$1" "$2"
}

pool_runtime_info_value_v2()
{
    local info_file=$1 key=$2 value
    [[ $(grep -F -c "$key: " "$info_file") -eq 1 ]] \
        || fail "HAProxy show info field is absent or duplicated: $key"
    value=$(sed -n "s/^${key}: //p" "$info_file")
    [[ $value != *$'\n'* ]] || fail "HAProxy show info field is multiline: $key"
    printf '%s\n' "$value"
}

assert_pool_process_identity_v2()
{
    local info_file=$1 current_pid worker_pid worker_start current_boot
    [[ $(pool_runtime_info_value_v2 "$info_file" Name) == HAProxy \
        && $(pool_runtime_info_value_v2 "$info_file" Version) == "$pool_haproxy_version" \
        && $(pool_runtime_info_value_v2 "$info_file" Nbproc) == 1 \
        && $(pool_runtime_info_value_v2 "$info_file" Process_num) == 1 \
        && $(pool_runtime_info_value_v2 "$info_file" Stopping) == 0 ]] \
        || fail 'HAProxy show info differs from the pinned single-process 2.8 runtime'
    worker_pid=$(pool_runtime_info_value_v2 "$info_file" Pid)
    [[ $worker_pid =~ ^[1-9][0-9]*$ ]] || fail 'HAProxy show info PID is malformed'
    current_pid=$(pool_current_main_pid_v2)
    current_boot=$(current_boot_id)
    if [[ $service_mode == systemd ]]; then
        [[ $worker_pid != "$current_pid" \
            && $(process_parent_pid_v2 "$worker_pid") == "$current_pid" ]] \
            || fail 'HAProxy show info worker is not the direct child of systemd MainPID'
    else
        [[ $worker_pid == "$current_pid" ]] \
            || fail 'HAProxy show info PID differs from the managed phase-B PID file'
    fi
    assert_pool_process_command_v2 "$current_pid"
    assert_pool_process_command_v2 "$worker_pid"
    worker_start=$(process_start_time "$worker_pid") \
        || fail 'HAProxy show info worker process identity cannot be read'
    capture_or_attest_pool_main_pid_v2
    if [[ $pool_worker_pid == none || $pool_main_boot_id != "$current_boot" ]]; then
        pool_worker_pid=$worker_pid
        pool_worker_start_time=$worker_start
    else
        [[ $pool_worker_pid == "$worker_pid" \
            && $pool_worker_start_time == "$worker_start" ]] \
            || fail 'phase-B HAProxy worker changed without a cold boot; reload/restart is forbidden'
    fi
}

capture_pool_runtime_snapshot_v2()
{
    local info_file=$1 state_snapshot=$2 socket post_info_file
    socket="$runtime_directory/phase-b.sock"
    post_info_file="${info_file}.post"
    assert_pool_config_assets_v2
    [[ -S $socket && ! -L $socket \
        && $(stat -c '%a:%u:%g' "$socket") == "600:$immutable_uid:$immutable_gid" ]] \
        || fail 'phase-B HAProxy admin socket is not the pinned mode-0600 identity'
    printf 'show info\n' | socat - "UNIX-CONNECT:$socket" > "$info_file" \
        || fail 'HAProxy could not produce fresh show info'
    printf 'show servers state\n' | socat - "UNIX-CONNECT:$socket" > "$state_snapshot" \
        || fail 'HAProxy could not produce fresh show servers state'
    printf 'show info\n' | socat - "UNIX-CONNECT:$socket" > "$post_info_file" \
        || fail 'HAProxy could not produce post-state show info'
    [[ -s $info_file && -s $state_snapshot && -s $post_info_file ]] \
        || fail 'HAProxy produced an empty runtime attestation response'
    assert_pool_process_identity_v2 "$info_file"
    assert_pool_server_state_shape_v2 "$state_snapshot"
    assert_pool_process_identity_v2 "$post_info_file"
    [[ $(pool_runtime_info_value_v2 "$info_file" Pid) \
            == "$(pool_runtime_info_value_v2 "$post_info_file" Pid)" \
        && $(pool_runtime_info_value_v2 "$info_file" Version) \
            == "$(pool_runtime_info_value_v2 "$post_info_file" Version)" \
        && $(pool_runtime_info_value_v2 "$info_file" Start_time_sec) \
            == "$(pool_runtime_info_value_v2 "$post_info_file" Start_time_sec)" ]] \
        || fail 'HAProxy process identity changed across fresh show servers state'
    rm -f -- "$post_info_file"
}

persist_pool_server_state_v2()
{
    local candidate info_file
    candidate="$operation_directory/.phase-b-server-state.$$"
    info_file="$operation_directory/.phase-b-show-info.$$"
    capture_pool_runtime_snapshot_v2 "$info_file" "$candidate"
    rm -f -- "$info_file"
    atomic_replace "$candidate" "$pool_server_state_file" 600
    pool_server_state_sha256=$(checksum "$pool_server_state_file")
    pool_server_state_metadata=$(pool_file_metadata "$pool_server_state_file")
}

assert_pool_server_state_v2()
{
    assert_pool_file "$pool_server_state_file" 600 "$immutable_uid" "$immutable_gid" \
        'canonical phase-B HAProxy server state' "$pool_server_state_metadata"
    validate_sha256 phase-B-server-state "$pool_server_state_sha256"
    [[ $(checksum "$pool_server_state_file") == "$pool_server_state_sha256" ]] \
        || fail 'canonical phase-B HAProxy server state changed'
    assert_pool_server_state_shape_v2 "$pool_server_state_file"
}

assert_pool_runtime_identity_v2()
{
    local info_file state_snapshot member field_name
    info_file="$operation_directory/.phase-b-assert-info.$$"
    state_snapshot="$operation_directory/.phase-b-assert-state.$$"
    capture_pool_runtime_snapshot_v2 "$info_file" "$state_snapshot"
    if [[ $pool_server_state_sha256 != none ]]; then
        assert_pool_server_state_v2
        for member in a b; do
            for field_name in srv_addr srv_port srv_admin_state; do
                [[ $(pool_server_state_field_from_v2 "$state_snapshot" "$member" "$field_name") \
                    == "$(pool_server_state_field_from_v2 "$pool_server_state_file" "$member" "$field_name")" ]] \
                    || fail "fresh HAProxy web-$member $field_name differs from canonical persisted state"
            done
        done
    fi
    rm -f -- "$info_file" "$state_snapshot"
}

pool_test_crash_v2()
{
    local stage=$1
    test_crash "$stage"
    test_crash "${stage/after-pool-/after-}"
}

pool_mutation_intent_keys_v2()
{
    printf '%s\n' version operation_id action status member kind \
        pre_address pre_port pre_admin_state post_address post_port post_admin_state \
        pre_pool_state_sha256 pre_server_state_sha256
}

pool_mutation_intent_value_v2()
{
    safe_state_value "$1" "$pool_mutation_intent_file"
}

pool_admin_state_code_v2()
{
    case "$1" in
        ready) printf '0\n' ;;
        maint) printf '1\n' ;;
        drain) printf '8\n' ;;
        *) fail 'pool mutation intent contains an unknown administrative state' ;;
    esac
}

load_pool_mutation_intent_v2()
{
    local expected_keys actual_keys
    assert_pool_file "$pool_mutation_intent_file" 600 "$immutable_uid" "$immutable_gid" \
        'durable phase-B mutation intent'
    expected_keys=$(pool_mutation_intent_keys_v2)
    actual_keys=$(sed 's/=.*//' "$pool_mutation_intent_file")
    [[ $actual_keys == "$expected_keys" \
        && $(wc -l < "$pool_mutation_intent_file" | tr -d '[:space:]') \
            -eq $(wc -l <<< "$expected_keys" | tr -d '[:space:]') ]] \
        || fail 'phase-B mutation intent keys are reordered, duplicated, absent, or unknown'
    [[ $(pool_mutation_intent_value_v2 version) == 1 \
        && $(pool_mutation_intent_value_v2 operation_id) == "$operation_id" ]] \
        || fail 'phase-B mutation intent belongs to another controller operation'
    pool_mutation_action=$(pool_mutation_intent_value_v2 action)
    pool_mutation_status=$(pool_mutation_intent_value_v2 status)
    pool_mutation_member=$(pool_mutation_intent_value_v2 member)
    pool_mutation_kind=$(pool_mutation_intent_value_v2 kind)
    pool_mutation_pre_address=$(pool_mutation_intent_value_v2 pre_address)
    pool_mutation_pre_port=$(pool_mutation_intent_value_v2 pre_port)
    pool_mutation_pre_admin_state=$(pool_mutation_intent_value_v2 pre_admin_state)
    pool_mutation_post_address=$(pool_mutation_intent_value_v2 post_address)
    pool_mutation_post_port=$(pool_mutation_intent_value_v2 post_port)
    pool_mutation_post_admin_state=$(pool_mutation_intent_value_v2 post_admin_state)
    pool_mutation_pre_pool_state_sha256=$(pool_mutation_intent_value_v2 pre_pool_state_sha256)
    pool_mutation_pre_server_state_sha256=$(pool_mutation_intent_value_v2 pre_server_state_sha256)
    [[ $pool_mutation_action == switch || $pool_mutation_action == drain ]] \
        || fail 'phase-B mutation intent action is malformed'
    [[ $pool_mutation_status == "$pool_status" \
        && $pool_mutation_member =~ ^[ab]$ \
        && $pool_mutation_kind =~ ^(endpoint|admin)$ ]] \
        || fail 'phase-B mutation intent does not match the durable pool transition'
    [[ $pool_mutation_pre_address == "$pool_loopback_address" \
        && $pool_mutation_post_address == "$pool_loopback_address" \
        && $pool_mutation_pre_port =~ ^[1-9][0-9]{0,4}$ \
        && $pool_mutation_post_port =~ ^[1-9][0-9]{0,4}$ ]] \
        || fail 'phase-B mutation intent endpoint is malformed'
    ((10#$pool_mutation_pre_port <= 65535 \
        && 10#$pool_mutation_post_port <= 65535)) \
        || fail 'phase-B mutation intent endpoint exceeds 65535'
    pool_admin_state_code_v2 "$pool_mutation_pre_admin_state" >/dev/null
    pool_admin_state_code_v2 "$pool_mutation_post_admin_state" >/dev/null
    validate_sha256 phase-B-pre-mutation-pool-state "$pool_mutation_pre_pool_state_sha256"
    validate_sha256 phase-B-pre-mutation-server-state "$pool_mutation_pre_server_state_sha256"
    case "$pool_mutation_kind" in
        endpoint)
            [[ $pool_mutation_pre_port != "$pool_mutation_post_port" \
                && $pool_mutation_pre_admin_state == "$pool_mutation_post_admin_state" ]] \
                || fail 'phase-B endpoint mutation intent is not one exact slot change'
            ;;
        admin)
            [[ $pool_mutation_pre_address == "$pool_mutation_post_address" \
                && $pool_mutation_pre_port == "$pool_mutation_post_port" \
                && $pool_mutation_pre_admin_state != "$pool_mutation_post_admin_state" ]] \
                || fail 'phase-B admin mutation intent is not one exact slot change'
            ;;
    esac
}

pool_state_slot_matches_v2()
{
    local member=$1 address=$2 port=$3 admin_state=$4
    if [[ $member == a ]]; then
        [[ $pool_state_member_a_address == "$address" \
            && $pool_state_member_a_port == "$port" \
            && $pool_state_member_a_admin_state == "$admin_state" ]]
    else
        [[ $pool_state_member_b_address == "$address" \
            && $pool_state_member_b_port == "$port" \
            && $pool_state_member_b_admin_state == "$admin_state" ]]
    fi
}

pool_snapshot_slot_matches_v2()
{
    local state_snapshot=$1 member=$2 address=$3 port=$4 admin_state=$5
    [[ $(pool_server_state_field_from_v2 "$state_snapshot" "$member" srv_addr) == "$address" \
        && $(pool_server_state_field_from_v2 "$state_snapshot" "$member" srv_port) == "$port" \
        && $(pool_server_state_field_from_v2 "$state_snapshot" "$member" srv_admin_state) \
            == "$(pool_admin_state_code_v2 "$admin_state")" ]]
}

classify_pool_mutation_snapshot_v2()
{
    local state_snapshot=$1 layer=$2
    if pool_snapshot_slot_matches_v2 "$state_snapshot" "$pool_mutation_member" \
            "$pool_mutation_pre_address" "$pool_mutation_pre_port" \
            "$pool_mutation_pre_admin_state"; then
        printf 'pre\n'
    elif pool_snapshot_slot_matches_v2 "$state_snapshot" "$pool_mutation_member" \
            "$pool_mutation_post_address" "$pool_mutation_post_port" \
            "$pool_mutation_post_admin_state"; then
        printf 'post\n'
    else
        fail "phase-B mutation $layer is neither the exact pre-state nor post-state"
    fi
}

classify_pool_mutation_state_v2()
{
    if pool_state_slot_matches_v2 "$pool_mutation_member" \
            "$pool_mutation_pre_address" "$pool_mutation_pre_port" \
            "$pool_mutation_pre_admin_state"; then
        printf 'pre\n'
    elif pool_state_slot_matches_v2 "$pool_mutation_member" \
            "$pool_mutation_post_address" "$pool_mutation_post_port" \
            "$pool_mutation_post_admin_state"; then
        printf 'post\n'
    else
        fail 'phase-B durable pool-state is neither the exact mutation pre-state nor post-state'
    fi
}

classify_pool_mutation_layers_v2()
{
    local info_file live_state
    info_file="$operation_directory/.phase-b-journal-info.$$"
    live_state="$operation_directory/.phase-b-journal-state.$$"
    capture_pool_runtime_snapshot_v2 "$info_file" "$live_state"
    rm -f -- "$info_file"
    assert_pool_file "$pool_server_state_file" 600 "$immutable_uid" "$immutable_gid" \
        'canonical phase-B HAProxy server state during mutation recovery'
    assert_pool_server_state_shape_v2 "$pool_server_state_file"
    pool_mutation_live_class=$(classify_pool_mutation_snapshot_v2 "$live_state" live-state)
    pool_mutation_server_class=$(classify_pool_mutation_snapshot_v2 \
        "$pool_server_state_file" server-state)
    pool_mutation_pool_class=$(classify_pool_mutation_state_v2)
    rm -f -- "$live_state"
    if [[ $pool_mutation_server_class == pre ]]; then
        [[ $(checksum "$pool_server_state_file") \
            == "$pool_mutation_pre_server_state_sha256" ]] \
            || fail 'pre-classified canonical server-state differs from the mutation journal'
    fi
    if [[ $pool_mutation_pool_class == pre ]]; then
        [[ $(checksum "$pool_state_file") == "$pool_mutation_pre_pool_state_sha256" ]] \
            || fail 'pre-classified durable pool-state differs from the mutation journal'
    fi
}

apply_pool_mutation_post_state_v2()
{
    if [[ $pool_mutation_member == a ]]; then
        pool_state_member_a_address=$pool_mutation_post_address
        pool_state_member_a_port=$pool_mutation_post_port
        pool_state_member_a_admin_state=$pool_mutation_post_admin_state
    else
        pool_state_member_b_address=$pool_mutation_post_address
        pool_state_member_b_port=$pool_mutation_post_port
        pool_state_member_b_admin_state=$pool_mutation_post_admin_state
    fi
    pool_server_state_sha256=$(checksum "$pool_server_state_file")
    pool_server_state_metadata=$(pool_file_metadata "$pool_server_state_file")
    write_pool_state_v2 "$pool_status"
}

pool_mutation_command_v2()
{
    case "$pool_mutation_kind" in
        endpoint)
            printf 'set server %s/web-%s addr %s port %s\n' "$pool_backend_name" \
                "$pool_mutation_member" "$pool_mutation_post_address" "$pool_mutation_post_port"
            ;;
        admin)
            printf 'set server %s/web-%s state %s\n' "$pool_backend_name" \
                "$pool_mutation_member" "$pool_mutation_post_admin_state"
            ;;
    esac
}

reconcile_pool_mutation_intent_v2()
{
    [[ -e $pool_mutation_intent_file ]] || return 0
    while [[ -e $pool_mutation_intent_file ]]; do
        load_pool_mutation_intent_v2
        classify_pool_mutation_layers_v2
        case "$pool_mutation_live_class:$pool_mutation_server_class:$pool_mutation_pool_class" in
            pre:pre:pre)
                pool_runtime_command_v2 "$(pool_mutation_command_v2)"
                pool_test_crash_v2 "after-pool-${pool_mutation_action}-runtime-mutation"
                ;;
            post:pre:pre)
                persist_pool_server_state_v2
                pool_test_crash_v2 "after-pool-${pool_mutation_action}-state-persist"
                ;;
            post:post:pre)
                apply_pool_mutation_post_state_v2
                pool_test_crash_v2 "after-pool-${pool_mutation_action}-pool-state"
                ;;
            post:post:post)
                rm -f -- "$pool_mutation_intent_file"
                sync "$operation_directory"
                ;;
            *)
                fail "phase-B mutation layers are not an ordered pre/post transition: $pool_mutation_live_class/$pool_mutation_server_class/$pool_mutation_pool_class"
                ;;
        esac
    done
}

begin_pool_slot_mutation_v2()
{
    local action=$1 member=$2 kind=$3 post_address=$4 post_port=$5 post_admin_state=$6 candidate
    [[ ! -e $pool_mutation_intent_file ]] \
        || fail 'a prior phase-B mutation intent must be reconciled before another slot change'
    assert_pool_runtime_identity_v2
    if [[ $member == a ]]; then
        pool_mutation_pre_address=$pool_state_member_a_address
        pool_mutation_pre_port=$pool_state_member_a_port
        pool_mutation_pre_admin_state=$pool_state_member_a_admin_state
    else
        pool_mutation_pre_address=$pool_state_member_b_address
        pool_mutation_pre_port=$pool_state_member_b_port
        pool_mutation_pre_admin_state=$pool_state_member_b_admin_state
    fi
    candidate="$operation_directory/.phase-b-mutation.$$"
    {
        printf 'version=1\n'
        printf 'operation_id=%s\n' "$operation_id"
        printf 'action=%s\n' "$action"
        printf 'status=%s\n' "$pool_status"
        printf 'member=%s\n' "$member"
        printf 'kind=%s\n' "$kind"
        printf 'pre_address=%s\n' "$pool_mutation_pre_address"
        printf 'pre_port=%s\n' "$pool_mutation_pre_port"
        printf 'pre_admin_state=%s\n' "$pool_mutation_pre_admin_state"
        printf 'post_address=%s\n' "$post_address"
        printf 'post_port=%s\n' "$post_port"
        printf 'post_admin_state=%s\n' "$post_admin_state"
        printf 'pre_pool_state_sha256=%s\n' "$(checksum "$pool_state_file")"
        printf 'pre_server_state_sha256=%s\n' "$(checksum "$pool_server_state_file")"
    } > "$candidate"
    atomic_replace "$candidate" "$pool_mutation_intent_file" 600
    load_pool_mutation_intent_v2
    pool_test_crash_v2 "after-pool-${action}-mutation-intent"
    reconcile_pool_mutation_intent_v2
}

mutate_pool_slot_v2()
{
    local mutation=$1 member=$2 target_port=$3 target_state=$4 current_port current_state
    if [[ $member == a ]]; then
        current_port=$pool_state_member_a_port
        current_state=$pool_state_member_a_admin_state
    else
        current_port=$pool_state_member_b_port
        current_state=$pool_state_member_b_admin_state
    fi
    if [[ $current_port != "$target_port" ]]; then
        begin_pool_slot_mutation_v2 "$mutation" "$member" endpoint \
            "$pool_loopback_address" "$target_port" "$current_state"
    fi
    if [[ $current_state != "$target_state" ]]; then
        begin_pool_slot_mutation_v2 "$mutation" "$member" admin \
            "$pool_loopback_address" "$target_port" "$target_state"
    fi
}

mutate_pool_slot_admin_state_v2()
{
    local mutation=$1 member=$2 target_state=$3 current_state
    if [[ $member == a ]]; then
        current_state=$pool_state_member_a_admin_state
    else
        current_state=$pool_state_member_b_admin_state
    fi
    [[ $current_state != "$target_state" ]] || return 0
    if [[ $member == a ]]; then
        begin_pool_slot_mutation_v2 "$mutation" "$member" admin \
            "$pool_state_member_a_address" "$pool_state_member_a_port" "$target_state"
    else
        begin_pool_slot_mutation_v2 "$mutation" "$member" admin \
            "$pool_state_member_b_address" "$pool_state_member_b_port" "$target_state"
    fi
}

assert_pool_snapshot_endpoints_v2()
{
    local state_snapshot=$1
    [[ $(pool_server_state_field_from_v2 "$state_snapshot" a srv_addr) \
            == "$pool_loopback_address" \
        && $(pool_server_state_field_from_v2 "$state_snapshot" a srv_port) \
            == "$pool_member_a_loopback_port" \
        && $(pool_server_state_field_from_v2 "$state_snapshot" b srv_addr) \
            == "$pool_loopback_address" \
        && $(pool_server_state_field_from_v2 "$state_snapshot" b srv_port) \
            == "$pool_member_b_loopback_port" ]] \
        || fail 'canonical HAProxy server state differs from the exact loopback slot plan'
}

assert_pool_snapshot_member_state_v2()
{
    local state_snapshot=$1 member=$2 expected=$3 expected_op expected_admin
    case "$expected" in
        ready) expected_op=2; expected_admin=0 ;;
        down-ready) expected_op=0; expected_admin=0 ;;
        drain) expected_op=0; expected_admin=8 ;;
        maint) expected_op=0; expected_admin=1 ;;
        *) fail 'unknown expected HAProxy 2.8 slot state' ;;
    esac
    pool_snapshot_member_state_matches_v2 "$state_snapshot" "$member" \
        "$expected_op" "$expected_admin" \
        || fail "HAProxy web-$member is not exact 2.8 op/admin state $expected"
}

pool_snapshot_member_state_matches_v2()
{
    local state_snapshot=$1 member=$2 expected_op=$3 expected_admin=$4
    [[ $(pool_server_state_field_from_v2 "$state_snapshot" "$member" srv_op_state) \
            == "$expected_op" \
        && $(pool_server_state_field_from_v2 "$state_snapshot" "$member" srv_admin_state) \
            == "$expected_admin" ]]
}

assert_pool_live_membership_v2()
{
    local expected_a=$1 expected_b=$2 info_file state_snapshot attempt=0
    local expected_a_op expected_a_admin expected_b_op expected_b_admin
    case "$expected_a" in
        ready) expected_a_op=2; expected_a_admin=0 ;;
        down-ready) expected_a_op=0; expected_a_admin=0 ;;
        drain) expected_a_op=0; expected_a_admin=8 ;;
        maint) expected_a_op=0; expected_a_admin=1 ;;
        *) fail 'unknown expected HAProxy web-a state' ;;
    esac
    case "$expected_b" in
        ready) expected_b_op=2; expected_b_admin=0 ;;
        down-ready) expected_b_op=0; expected_b_admin=0 ;;
        drain) expected_b_op=0; expected_b_admin=8 ;;
        maint) expected_b_op=0; expected_b_admin=1 ;;
        *) fail 'unknown expected HAProxy web-b state' ;;
    esac
    info_file="$operation_directory/.phase-b-membership-info.$$"
    state_snapshot="$operation_directory/.phase-b-membership-state.$$"
    while ((attempt < probe_attempts)); do
        capture_pool_runtime_snapshot_v2 "$info_file" "$state_snapshot"
        assert_pool_snapshot_endpoints_v2 "$state_snapshot"
        if pool_snapshot_member_state_matches_v2 "$state_snapshot" a \
                "$expected_a_op" "$expected_a_admin" \
            && pool_snapshot_member_state_matches_v2 "$state_snapshot" b \
                "$expected_b_op" "$expected_b_admin"; then
            rm -f -- "$info_file" "$state_snapshot"
            return 0
        fi
        rm -f -- "$info_file" "$state_snapshot"
        attempt=$((attempt + 1))
        sleep 1
    done
    capture_pool_runtime_snapshot_v2 "$info_file" "$state_snapshot"
    fail "HAProxy did not converge to exact 2.8 membership web-a=$expected_a web-b=$expected_b; observed web-a=$(pool_server_state_field_from_v2 "$state_snapshot" a srv_op_state)/$(pool_server_state_field_from_v2 "$state_snapshot" a srv_admin_state) web-b=$(pool_server_state_field_from_v2 "$state_snapshot" b srv_op_state)/$(pool_server_state_field_from_v2 "$state_snapshot" b srv_admin_state)"
}

assert_pool_slot_endpoints_v2()
{
    local info_file state_snapshot
    info_file="$operation_directory/.phase-b-endpoints-info.$$"
    state_snapshot="$operation_directory/.phase-b-endpoints-state.$$"
    capture_pool_runtime_snapshot_v2 "$info_file" "$state_snapshot"
    assert_pool_snapshot_endpoints_v2 "$state_snapshot"
    rm -f -- "$info_file" "$state_snapshot"
}

start_or_reload_phase_a()
{
    local config_candidate=$1 instance=phase-a config_file pid_file old_pid
    config_file=$(instance_config_file "$instance")
    pid_file=$(instance_pid_file "$instance")
    if [[ -e $config_file ]]; then
        assert_regular_file "$config_file" "HAProxy $instance configuration"
        grep -F -x -q "# control-plane-operation-id: $operation_id" "$config_file" \
            || fail "refusing to overwrite unmanaged HAProxy $instance configuration"
    fi
    if instance_is_running "$instance"; then
        save_server_state "$instance"
    fi
    atomic_replace "$config_candidate" "$config_file" 600
    if [[ $service_mode == systemd ]]; then
        if instance_is_running "$instance"; then
            systemctl reload "coolify-port8000-haproxy@${instance}.service"
        else
            systemctl start "coolify-port8000-haproxy@${instance}.service"
        fi
    else
        if [[ -f $pid_file ]]; then
            old_pid=$(<"$pid_file")
            if [[ $old_pid =~ ^[1-9][0-9]*$ ]] && kill -0 "$old_pid" 2>/dev/null; then
                "$haproxy_binary" -D -f "$config_file" -p "$pid_file" -sf "$old_pid" 9>&-
            else
                "$haproxy_binary" -D -f "$config_file" -p "$pid_file" 9>&-
            fi
        else
            "$haproxy_binary" -D -f "$config_file" -p "$pid_file" 9>&-
        fi
    fi
    assert_admin_socket "$instance"
}

stop_instance()
{
    local instance=$1 pid_file pid config_file unit phase_a_finalization=0
    pid_file=$(instance_pid_file "$instance")
    config_file=$(instance_config_file "$instance")
    if [[ $instance == phase-a && $state_phase == phase-a-drain-intent ]]; then
        phase_a_finalization=1
    fi
    if [[ $service_mode == systemd ]]; then
        unit="coolify-port8000-haproxy@${instance}.service"
        systemctl stop "$unit" >/dev/null \
            || fail "systemd failed to stop HAProxy $instance"
        if ((phase_a_finalization)); then
            test_crash after-phase-a-systemd-stop
        fi
        assert_systemd_activity_state "$instance" inactive
        if [[ $instance == phase-a ]]; then
            assert_phase_a_main_process_exited
        fi
    elif [[ -f $pid_file ]]; then
        pid=$(<"$pid_file")
        if [[ $pid =~ ^[1-9][0-9]*$ ]] && kill -0 "$pid" 2>/dev/null; then
            kill -TERM "$pid"
            for _ in {1..50}; do
                kill -0 "$pid" 2>/dev/null || break
                sleep 0.1
            done
            kill -0 "$pid" 2>/dev/null && fail "HAProxy $instance did not stop"
        fi
        rm -f -- "$pid_file"
    fi
    if [[ -e $config_file ]]; then
        grep -F -x -q "# control-plane-operation-id: $operation_id" "$config_file" \
            || fail "refusing to remove unmanaged HAProxy $instance configuration"
        rm -f -- "$config_file"
        if ((phase_a_finalization)); then
            test_crash after-phase-a-config-removed
        fi
    fi
    rm -f -- "$runtime_directory/$instance.sock"
    if ((phase_a_finalization)); then
        sync "$(dirname -- "$config_file")" || fail 'could not synchronize removed phase-A HAProxy configuration directory'
        sync "$runtime_directory" || fail 'could not synchronize removed phase-A HAProxy runtime directory'
    fi
}

render_nft_artifact()
{
    local mode=$1 candidate=$2
    local canary_v4_rule='' canary_v6_rule='' nat_v4='' nat_v6=''
    case "$mode" in
        capture)
            ;;
        canary)
            if [[ $canary_ipv4 != none ]]; then
                canary_v4_rule="ip saddr $canary_ipv4 tcp dport $public_port counter return comment \"canary-ipv4\""
            fi
            if [[ $canary_ipv6 != none ]]; then
                canary_v6_rule="ip6 saddr $canary_ipv6 tcp dport $public_port counter return comment \"canary-ipv6\""
            fi
            ;;
        post-switch)
            ;;
        *)
            fail 'unknown nftables artifact mode'
            ;;
    esac
    if [[ $mode != post-switch ]]; then
        nat_v4="meta nfproto ipv4 fib daddr type local tcp dport $public_port counter redirect to :$bootstrap_port comment \"capture-ipv4\""
        nat_v6="meta nfproto ipv6 fib daddr type local tcp dport $public_port counter redirect to :$bootstrap_port comment \"capture-ipv6\""
    fi
    {
        printf 'table inet %s {\n' "$nft_table"
        printf '    comment "coolify-port8000 operation=%s mode=%s"\n' "$operation_id" "$mode"
        if [[ $mode != post-switch ]]; then
            printf '    chain capture_prerouting {\n'
            printf '        type nat hook prerouting priority %s; policy accept;\n' "$nft_priority"
            [[ -z $canary_v4_rule ]] || printf '        %s\n' "$canary_v4_rule"
            [[ -z $canary_v6_rule ]] || printf '        %s\n' "$canary_v6_rule"
            printf '        %s\n' "$nat_v4"
            printf '        %s\n' "$nat_v6"
            printf '    }\n'
            printf '    chain capture_output {\n'
            printf '        type nat hook output priority %s; policy accept;\n' "$nft_priority"
            printf '        %s\n' "$nat_v4"
            printf '        %s\n' "$nat_v6"
            printf '    }\n'
        fi
        printf '    chain protect_input {\n'
        printf '        type filter hook input priority %s; policy accept;\n' "$nft_priority"
        printf '        tcp dport %s ct original proto-dst %s counter accept comment "redirected-input"\n' "$bootstrap_port" "$public_port"
        printf '        tcp dport %s counter reject with tcp reset comment "deny-direct-input"\n' "$bootstrap_port"
        printf '    }\n'
        printf '    chain protect_output {\n'
        printf '        type filter hook output priority %s; policy accept;\n' "$nft_priority"
        printf '        tcp dport %s ct original proto-dst %s counter accept comment "redirected-output"\n' "$bootstrap_port" "$public_port"
        printf '        tcp dport %s counter reject with tcp reset comment "deny-direct-output"\n' "$bootstrap_port"
        printf '    }\n'
        printf '}\n'
    } > "$candidate"
    chmod 600 "$candidate"
}

nft_table_exists()
{
    nft list table inet "$nft_table" >/dev/null 2>&1
}

assert_nft_owned_or_absent()
{
    local expected_mode=${1:-} live_table
    nft_table_exists || return 0
    live_table=$(nft -s list table inet "$nft_table")
    grep -F -q "operation=$operation_id" <<< "$live_table" \
        || fail 'the nftables table name is owned by another operator'
    if [[ -n $expected_mode ]]; then
        grep -F -q "mode=$expected_mode" <<< "$live_table" \
            || fail "the live nftables table is not in expected mode $expected_mode: $(grep -m1 -F 'comment ' <<< "$live_table" || true)"
    fi
}

normalized_nft_checksum()
{
    nft -s -n list table inet "$nft_table" | sha256sum | awk '{print $1}'
}

expected_nft_checksum()
{
    local artifact=$1
    unshare --net bash -euo pipefail -c '
        nft -f "$1"
        nft -s -n list table inet "$2"
    ' _ "$artifact" "$nft_table" | sha256sum | awk '{print $1}'
}

publish_active_nft_artifact()
{
    local mode=$1 artifact=${2:-} active_candidate payload_candidate
    active_candidate="$config_directory/.active.nft.$$"
    payload_candidate="$config_directory/.active.nft.payload.$$"
    {
        printf '# coolify-port8000-mode=%s\n' "$mode"
        printf '# coolify-port8000-operation=%s\n' "$operation_id"
        printf '# coolify-port8000-table=%s\n' "$nft_table"
        [[ $mode == absent ]] || cat -- "$artifact"
    } > "$payload_candidate"
    {
        printf '# coolify-port8000-active-bundle-format=1\n'
        printf '# payload-sha256=%s\n' "$(checksum "$payload_candidate")"
        cat -- "$payload_candidate"
    } > "$active_candidate"
    rm -f -- "$payload_candidate"
    atomic_replace "$active_candidate" "$config_directory/active.nft" 600
}

apply_nft_artifact()
{
    local mode=$1 artifact candidate expected actual
    artifact="$operation_directory/nft-${mode}.nft"
    candidate="$operation_directory/.nft-${mode}.$$"
    if [[ -e $artifact ]]; then
        assert_root_file "$artifact" 600 "nftables $mode artifact"
    else
        render_nft_artifact "$mode" "$candidate"
        atomic_replace "$candidate" "$artifact" 600
    fi
    expected=$(expected_nft_checksum "$artifact") \
        || fail 'could not derive isolated expected nftables checksum'
    assert_nft_owned_or_absent
    candidate="$operation_directory/.nft-transaction.$$"
    if nft_table_exists; then
        printf 'delete table inet %s\n' "$nft_table" > "$candidate"
    else
        : > "$candidate"
    fi
    cat "$artifact" >> "$candidate"
    nft -c -f "$candidate" || fail "nftables rejected the $mode transaction"
    publish_active_nft_artifact "$mode" "$artifact"
    nft -f "$candidate" || fail "nftables failed the atomic $mode transaction"
    rm -f -- "$candidate"
    assert_nft_owned_or_absent "$mode"
    actual=$(normalized_nft_checksum)
    [[ $actual == "$expected" ]] || fail "live nftables $mode rules differ from isolated validated rules"
    state_nft_mode=$mode
    state_nft_artifact_sha256=$(checksum "$artifact")
}

assert_nft_artifact_identity()
{
    local mode=$1 artifact expected actual
    artifact="$operation_directory/nft-${mode}.nft"
    assert_root_file "$artifact" 600 "nftables $mode artifact"
    [[ $(checksum "$artifact") == "$state_nft_artifact_sha256" ]] \
        || fail "durable nftables $mode artifact changed"
    assert_nft_owned_or_absent "$mode"
    expected=$(expected_nft_checksum "$artifact") \
        || fail 'could not derive isolated expected nftables checksum'
    actual=$(normalized_nft_checksum)
    [[ $actual == "$expected" ]] || fail "live nftables $mode rules changed"
}

remove_nft_table()
{
    local candidate
    assert_nft_owned_or_absent
    candidate="$operation_directory/.nft-delete.$$"
    if nft_table_exists; then
        printf 'delete table inet %s\n' "$nft_table" > "$candidate"
    else
        : > "$candidate"
    fi
    nft -c -f "$candidate" || fail 'nftables rejected owned-table removal'
    publish_active_nft_artifact absent
    test_crash after-nft-absent-published
    nft -f "$candidate" || fail 'nftables failed owned-table removal'
    rm -f -- "$candidate"
    nft_table_exists && fail 'owned nftables table survived atomic removal'
    state_nft_mode=absent
    state_nft_artifact_sha256=none
    test_crash after-nft-table-removed
}

assert_absent_nft_boot_bundle()
{
    local active_bundle declared_payload_sha256 actual_payload_sha256
    active_bundle="$config_directory/active.nft"
    assert_root_file "$active_bundle" 600 'active absent nftables boot bundle'
    declared_payload_sha256=$(sed -n '2s/^# payload-sha256=//p' "$active_bundle")
    actual_payload_sha256=$(sed -n '3,$p' "$active_bundle" | sha256sum | awk '{print $1}')
    [[ $(sed -n '1s/^# coolify-port8000-active-bundle-format=//p' "$active_bundle") == 1 \
        && $declared_payload_sha256 =~ ^[a-f0-9]{64}$ \
        && $actual_payload_sha256 == "$declared_payload_sha256" \
        && $(sed -n '3s/^# coolify-port8000-mode=//p' "$active_bundle") == absent \
        && $(sed -n '4s/^# coolify-port8000-operation=//p' "$active_bundle") == "$operation_id" \
        && $(sed -n '5s/^# coolify-port8000-table=//p' "$active_bundle") == "$nft_table" \
        && $(wc -l < "$active_bundle" | tr -d '[:space:]') == 5 ]] \
        || fail 'active absent nftables boot bundle is malformed'
}

assert_config_identity()
{
    local instance=$1 expected=$2 config
    config=$(instance_config_file "$instance")
    assert_root_file "$config" 600 "HAProxy $instance configuration"
    [[ $(checksum "$config") == "$expected" ]] || fail "HAProxy $instance configuration changed"
    grep -F -x -q "# control-plane-operation-id: $operation_id" "$config" \
        || fail "HAProxy $instance ownership marker changed"
    instance_is_running "$instance" || fail "HAProxy $instance is not running"
    assert_admin_socket "$instance"
}

route_target_matches_state()
{
    local requested_ack=$1
    [[ $state_color == "$color" \
        && $state_backend == "$backend" \
        && $state_backend_port == "$backend_port" \
        && $state_ack == "$requested_ack" \
        && $state_probe_token_sha256 == "$(checksum "$direct_probe_token_file")" ]]
}

set_route_intent()
{
    local requested_ack=$1
    state_color=$color
    state_backend=$backend
    state_backend_port=$backend_port
    state_ack=$requested_ack
    state_probe_token_sha256=$(checksum "$direct_probe_token_file")
}

reconcile_phase_a()
{
    local config_candidate requested_ack=$1
    config_candidate="$operation_directory/.phase-a.$$"
    render_haproxy_config phase-a "$bootstrap_port" bootstrap-a "$backend" "$backend_port" \
        "$color" "$requested_ack" "$config_candidate"
    state_a_config_sha256=$(checksum "$config_candidate")
    write_state capture-intent
    test_crash after-capture-intent
    start_or_reload_phase_a "$config_candidate"
    write_state phase-a-running
    test_crash after-phase-a-start
    apply_nft_artifact capture
    write_state capture-installed
    test_crash after-nft-capture
    probe_route "$color" "$requested_ack" bootstrap-a
    write_state captured
    test_crash after-public-ack
}

rollback_to_phase_a()
{
    apply_nft_artifact capture
    write_state captured
    probe_route "$state_color" "$state_ack" bootstrap-a
    if instance_is_running phase-b; then
        stop_instance phase-b
    fi
    state_b_config_sha256=none
    write_state captured
}

phase_a_session_count()
{
    local socket="$runtime_directory/phase-a.sock"
    [[ -S $socket ]] || { printf '0\n'; return; }
    # The stats command itself appears as a GLOBAL unix-socket session. Only sessions accepted by
    # the phase-A public frontend are drain blockers.
    printf 'show sess\n' | socat - "UNIX-CONNECT:$socket" \
        | awk 'index($0, " fe=control_plane_port8000_phase-a ") {count++} END {print count + 0}'
}

phase_a_conntrack_count()
{
    local entries status
    set +e
    entries=$(conntrack -L -p tcp 2>/dev/null)
    status=$?
    set -e
    [[ $status -eq 0 ]] || fail 'conntrack could not enumerate phase-A TCP state'
    awk -v port="$bootstrap_port" '
        $0 ~ "(sport|dport)=" port "([^0-9]|$)" {count++}
        END {print count + 0}
    ' <<< "$entries"
}

assert_phase_a_stopped()
{
    local config socket
    config=$(instance_config_file phase-a)
    socket="$runtime_directory/phase-a.sock"
    assert_phase_a_main_process_exited
    if [[ $service_mode == systemd ]]; then
        assert_systemd_activity_state phase-a inactive
    else
        ! instance_is_running phase-a || fail 'phase-A HAProxy remains running during permanent finalization'
    fi
    [[ ! -e $config ]] || fail 'phase-A HAProxy configuration remains during permanent finalization'
    [[ ! -e $socket ]] || fail 'phase-A HAProxy socket remains during permanent finalization'
}

assert_phase_a_finalization_complete()
{
    assert_phase_a_stopped
    ! nft_table_exists || fail 'capture nftables table remains during permanent finalization'
    assert_absent_nft_boot_bundle
    [[ $state_nft_mode == absent && $state_nft_artifact_sha256 == none ]] \
        || fail 'durable nftables state did not record permanent finalization'
}

finalize_phase_a_drain()
{
    while true; do
        case "$state_phase" in
            permanent-acknowledged)
                capture_phase_a_main_process_identity
                write_state phase-a-drain-intent
                test_crash after-phase-a-drain-intent
                ;;
            phase-a-drain-intent)
                stop_instance phase-a
                assert_phase_a_stopped
                write_state phase-a-stopped
                test_crash after-phase-a-stop
                ;;
            phase-a-stopped)
                assert_phase_a_stopped
                write_state nft-removal-intent
                test_crash after-nft-removal-intent
                ;;
            nft-removal-intent)
                remove_nft_table
                write_state nft-removed
                test_crash after-nft-removed
                ;;
            nft-removed)
                assert_phase_a_finalization_complete
                write_state permanent
                publish_pool_boot_identity_v2
                test_crash after-phase-a-drain
                ;;
            permanent)
                assert_phase_a_finalization_complete
                publish_pool_boot_identity_v2
                return
                ;;
            *)
                fail "phase-A finalization cannot reconcile phase: $state_phase"
                ;;
        esac
    done
}

drain_phase_a_if_possible()
{
    local attempt=0 stable=0 sessions connections
    case "$state_phase" in
        phase-a-drain-intent|phase-a-stopped|nft-removal-intent|nft-removed|permanent)
            finalize_phase_a_drain
            return
            ;;
        permanent-acknowledged)
            ;;
        *)
            fail "phase-A drain cannot start from phase: $state_phase"
            ;;
    esac
    while ((attempt < drain_attempts)); do
        sessions=$(phase_a_session_count)
        connections=$(phase_a_conntrack_count)
        if [[ $sessions == 0 && $connections == 0 ]]; then
            stable=$((stable + 1))
            if ((stable >= drain_stable_samples)); then
                finalize_phase_a_drain
                return
            fi
        else
            stable=0
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    note "phase-a-retained sessions=$sessions conntrack=$connections; rerun assert after drain"
}

restore_legacy()
{
    case "$state_phase" in
        prepared)
            probe_route legacy none legacy
            write_state restored
            ;;
        capture-intent|phase-a-running|capture-installed|captured)
            assert_legacy_owner_present
            remove_nft_table
            test_crash after-restore-redirect-remove
            stop_instance phase-a
            state_a_config_sha256=none
            state_color=legacy
            state_backend=none
            state_backend_port=0
            state_ack=none
            state_probe_token_sha256=none
            probe_route legacy none legacy
            write_state restored
            ;;
        permanent-intent|phase-b-running|canary-installed|canary-acknowledged|redirect-remove-intent)
            rollback_to_phase_a
            restore_legacy
            ;;
        permanent-acknowledged|phase-a-drain-intent|phase-a-stopped|nft-removal-intent|nft-removed|permanent|permanent-update-intent)
            fail 'legacy restoration is forbidden after Docker port ownership was removed; use controlled blue failback'
            ;;
        restored)
            probe_route legacy none legacy
            ;;
        *)
            fail "unknown durable :8000 phase: $state_phase"
            ;;
    esac
}

configure_pool_phase_a_target_v2()
{
    color=$pool_color
    backend=$pool_loopback_address
    backend_port=$pool_member_a_loopback_port
    direct_probe_token_file=$(pool_plan_value member_a_direct_probe_token_file)
}

assert_pool_phase_a_target_v2()
{
    [[ $state_color == "$pool_color" \
        && $state_backend == "$pool_loopback_address" \
        && $state_backend_port == "$pool_member_a_loopback_port" \
        && $state_ack == "$pool_member_a_applied_ack" \
        && $state_probe_token_sha256 == "$(pool_plan_value member_a_direct_probe_token_sha256)" ]] \
        || fail 'durable phase-A route does not belong to the ingress pool web-a bootstrap target'
}

reconcile_pool_phase_a_v2()
{
    local owner_count
    probe_backend_identity "$pool_member_a_applied_ack"
    case "$state_phase" in
        prepared|capture-intent|phase-a-running|capture-installed)
            set_route_intent "$pool_member_a_applied_ack"
            reconcile_phase_a "$pool_member_a_applied_ack"
            ;;
        captured)
            if route_target_matches_state "$pool_member_a_applied_ack"; then
                assert_config_identity phase-a "$state_a_config_sha256"
                assert_nft_artifact_identity capture
                probe_route "$pool_color" "$pool_member_a_applied_ack" bootstrap-a
            else
                set_route_intent "$pool_member_a_applied_ack"
                reconcile_phase_a "$pool_member_a_applied_ack"
            fi
            ;;
        restored)
            fail 'a restored legacy operation cannot switch to an ingress pool'
            ;;
        *)
            assert_pool_phase_a_target_v2
            return 0
            ;;
    esac
    owner_count=$(published_owner_count)
    case "$owner_count" in
        0) return 0 ;;
        1)
            assert_legacy_owner_present
            note 'pool-phase-a=ready legacy-owner=present; rerun switch after the reviewed ownership removal'
            return 1
            ;;
        *) fail 'ingress pool cutover found ambiguous Docker port-8000 ownership' ;;
    esac
}

prepare_pool_runtime_v2()
{
    configure_pool_paths_v2
    if [[ -e $pool_state_file ]]; then
        load_pool_state_v2
        case "$pool_status" in
            switch-intent|active|drain-intent|drained) ;;
            *) fail "unknown durable phase-B pool status: $pool_status" ;;
        esac
        install_pool_config_assets_v2
        assert_pool_config_assets_v2
        reconcile_pool_mutation_intent_v2
    else
        install_pool_config_assets_v2
        initialize_pool_state_v2
        pool_test_crash_v2 after-pool-switch-intent
    fi
    state_b_config_sha256=$pool_config_sha256
}

activate_pool_runtime_v2()
{
    local previous_pid=$pool_main_pid previous_start=$pool_main_start_time
    local previous_boot=$pool_main_boot_id
    case "$pool_status" in
        drain-intent|drained)
            fail 'a reduced ingress pool cannot be replayed as a full two-member switch'
            ;;
        switch-intent|active) ;;
        *) fail "phase-B activation refuses pool status: $pool_status" ;;
    esac
    start_pool_instance_once_v2
    capture_or_attest_pool_main_pid_v2
    if [[ $pool_main_pid != "$previous_pid" \
        || $pool_main_start_time != "$previous_start" \
        || $pool_main_boot_id != "$previous_boot" ]]; then
        persist_pool_server_state_v2
        write_pool_state_v2 "$pool_status"
    elif [[ $pool_server_state_sha256 == none ]]; then
        persist_pool_server_state_v2
        write_pool_state_v2 "$pool_status"
    fi
    assert_pool_runtime_identity_v2
    if [[ $pool_status == switch-intent ]]; then
        if [[ $pool_state_member_a_port != "$pool_member_a_loopback_port" ]]; then
            mutate_pool_slot_admin_state_v2 switch a maint
        fi
        if [[ $pool_state_member_b_port != "$pool_member_b_loopback_port" ]]; then
            mutate_pool_slot_admin_state_v2 switch b maint
        fi
        mutate_pool_slot_v2 switch a "$pool_member_a_loopback_port" ready
        mutate_pool_slot_v2 switch b "$pool_member_b_loopback_port" ready
        assert_pool_slot_endpoints_v2
        probe_pool_member_v2 a active
        probe_pool_member_v2 b active
        assert_pool_live_membership_v2 ready ready
        pool_state_route_ack=$pool_route_ack
        write_pool_state_v2 active
        pool_test_crash_v2 after-pool-switch-state
    else
        [[ $pool_state_member_a_admin_state == ready \
            && $pool_state_member_b_admin_state == ready \
            && $pool_drained_role == none \
            && $pool_drain_epoch == none \
            && $pool_state_route_ack == "$pool_route_ack" ]] \
            || fail 'active pool state is not an exact full two-member membership'
        assert_pool_slot_endpoints_v2
        probe_pool_member_v2 a active
        probe_pool_member_v2 b active
        assert_pool_live_membership_v2 ready ready
    fi
}

rollback_pool_bootstrap_v2()
{
    apply_nft_artifact capture
    write_state captured
    probe_route "$state_color" "$state_ack" bootstrap-a
    if instance_is_running phase-b; then
        persist_pool_server_state_v2
        stop_instance phase-b
    fi
    pool_main_pid=none
    pool_main_start_time=none
    pool_main_boot_id=none
    pool_worker_pid=none
    pool_worker_start_time=none
    pool_state_member_a_admin_state=ready
    pool_state_member_b_admin_state=ready
    pool_drained_role=none
    pool_drain_epoch=none
    pool_state_route_ack=$pool_route_ack
    write_pool_state_v2 switch-intent
    state_b_config_sha256=none
    write_state captured
}

assert_pool_runtime_active_v2()
{
    local previous_pid=$pool_main_pid previous_start=$pool_main_start_time
    local previous_boot=$pool_main_boot_id
    [[ $pool_status == active \
        && $pool_state_member_a_admin_state == ready \
        && $pool_state_member_b_admin_state == ready \
        && $pool_drained_role == none \
        && $pool_drain_epoch == none \
        && $pool_state_route_ack == "$pool_route_ack" ]] \
        || fail 'phase-B pool is not the exact full two-member active set'
    assert_pool_runtime_identity_v2
    if [[ $pool_main_pid != "$previous_pid" \
        || $pool_main_start_time != "$previous_start" \
        || $pool_main_boot_id != "$previous_boot" ]]; then
        persist_pool_server_state_v2
        write_pool_state_v2 active
    fi
    assert_pool_slot_endpoints_v2
    probe_pool_member_v2 a active
    probe_pool_member_v2 b active
    assert_pool_live_membership_v2 ready ready
}

switch_pool_v2()
{
    prepare_operation
    load_state
    load_pool_inputs_v2
    configure_pool_phase_a_target_v2
    probe_pool_member_v2 a active
    probe_pool_member_v2 b active
    case "$state_phase" in
        prepared|capture-intent|phase-a-running|capture-installed|captured)
            reconcile_pool_phase_a_v2 || return 0
            ;;
        permanent-intent|phase-b-running|canary-installed|canary-acknowledged|redirect-remove-intent|\
        permanent-acknowledged|phase-a-drain-intent|phase-a-stopped|nft-removal-intent|nft-removed|permanent)
            assert_pool_phase_a_target_v2
            ;;
        restored) fail 'a restored legacy operation cannot switch to an ingress pool' ;;
        *) fail "pool switch refuses durable :8000 phase: $state_phase" ;;
    esac

    prepare_pool_runtime_v2
    if [[ $state_phase == captured ]]; then
        [[ $(published_owner_count) -eq 0 ]] \
            || fail 'phase-B pool cannot bind while Docker still declares public port 8000'
        if ! instance_is_running phase-b; then
            assert_no_legacy_port_ownership
        fi
        write_state permanent-intent
        pool_test_crash_v2 after-pool-permanent-intent
    fi

    while true; do
        case "$state_phase" in
            permanent-intent)
                activate_pool_runtime_v2
                write_state phase-b-running
                pool_test_crash_v2 after-pool-phase-b-start
                ;;
            phase-b-running)
                assert_pool_runtime_active_v2
                apply_nft_artifact canary
                write_state canary-installed
                pool_test_crash_v2 after-pool-canary-rule
                ;;
            canary-installed)
                assert_pool_runtime_active_v2
                if ! (probe_pool_canary_v2 "$pool_route_ack"); then
                    rollback_pool_bootstrap_v2
                    fail 'phase-B pool canary failed; phase A was restored without a reload or restart'
                fi
                write_state canary-acknowledged
                pool_test_crash_v2 after-pool-canary-ack
                ;;
            canary-acknowledged)
                assert_pool_runtime_active_v2
                if ! (assert_external_policy_blocked); then
                    rollback_pool_bootstrap_v2
                    fail 'external TCP/8000 policy changed; phase A was restored without a reload or restart'
                fi
                write_state redirect-remove-intent
                pool_test_crash_v2 after-pool-redirect-remove-intent
                ;;
            redirect-remove-intent)
                assert_pool_runtime_active_v2
                apply_nft_artifact post-switch
                pool_test_crash_v2 after-pool-redirect-remove
                if ! (probe_pool_route_v2 "$pool_route_ack"); then
                    rollback_pool_bootstrap_v2
                    fail 'phase-B pool public acknowledgement failed; phase A was restored'
                fi
                write_state permanent-acknowledged
                pool_test_crash_v2 after-pool-public-ack
                ;;
            permanent-acknowledged)
                assert_pool_runtime_active_v2
                assert_nft_artifact_identity post-switch
                probe_pool_route_v2 "$pool_route_ack"
                drain_phase_a_if_possible
                [[ $state_phase == permanent ]] || return 0
                ;;
            phase-a-drain-intent|phase-a-stopped|nft-removal-intent|nft-removed)
                assert_pool_runtime_active_v2
                probe_pool_route_v2 "$pool_route_ack"
                finalize_phase_a_drain
                ;;
            permanent)
                assert_pool_runtime_active_v2
                assert_phase_a_finalization_complete
                probe_pool_route_v2 "$pool_route_ack"
                return 0
                ;;
            *) fail "pool switch cannot reconcile durable :8000 phase: $state_phase" ;;
        esac
    done
}

assert_pool_v2()
{
    load_manifest
    load_state
    load_pool_inputs_v2
    configure_pool_phase_a_target_v2
    assert_pool_phase_a_target_v2
    prepare_pool_runtime_v2
    case "$state_phase" in
        permanent-acknowledged)
            assert_pool_runtime_active_v2
            probe_pool_route_v2 "$pool_route_ack"
            drain_phase_a_if_possible
            ;;
        phase-a-drain-intent|phase-a-stopped|nft-removal-intent|nft-removed)
            assert_pool_runtime_active_v2
            probe_pool_route_v2 "$pool_route_ack"
            finalize_phase_a_drain
            ;;
        permanent)
            assert_pool_runtime_active_v2
            assert_phase_a_finalization_complete
            probe_pool_route_v2 "$pool_route_ack"
            ;;
        *) fail "active pool assertion refuses transitional :8000 phase: $state_phase" ;;
    esac
}

verify_pool_v2()
{
    assert_pool_v2
    probe_pool_route_v2 "$pool_route_ack"
}

select_pool_drain_member_v2()
{
    case "$drain_member" in
        web-a)
            pool_drain_member=a
            pool_surviving_member=b
            selected_pool_drain_epoch=$pool_member_a_drain_epoch
            selected_pool_drain_ack=$pool_member_a_drain_ack
            ;;
        web-b)
            pool_drain_member=b
            pool_surviving_member=a
            selected_pool_drain_epoch=$pool_member_b_drain_epoch
            selected_pool_drain_ack=$pool_member_b_drain_ack
            ;;
        *) fail 'CONTROL_PLANE_INGRESS_DRAIN_MEMBER must be exactly web-a or web-b' ;;
    esac
}

pool_member_admin_state_v2()
{
    case "$1" in
        a) printf '%s\n' "$pool_state_member_a_admin_state" ;;
        b) printf '%s\n' "$pool_state_member_b_admin_state" ;;
        *) fail 'pool member administrative-state lookup requires slot a or b' ;;
    esac
}

assert_pool_drain_lineage_v2()
{
    case "$pool_status" in
        active)
            [[ $pool_state_member_a_admin_state == ready \
                && $pool_state_member_b_admin_state == ready ]] \
                || fail 'last-member protection requires two ready members before drain'
            ;;
        drain-intent)
            [[ $pool_drained_role == "$drain_member" \
                && $pool_drain_epoch == "$selected_pool_drain_epoch" \
                && $pool_state_route_ack == "$selected_pool_drain_ack" ]] \
                || fail 'a different member drain is already in progress; draining the last peer is forbidden'
            ;;
        drained)
            [[ $pool_drained_role == "$drain_member" ]] \
                || fail 'one member is already drained; draining the last active peer is forbidden'
            ;;
        *) fail "pool drain refuses durable pool status: $pool_status" ;;
    esac
}

assert_drained_pool_runtime_v2()
{
    local previous_pid=$pool_main_pid previous_start=$pool_main_start_time
    local previous_boot=$pool_main_boot_id
    [[ $pool_status == drained \
        && $pool_drained_role == "$drain_member" \
        && $pool_drain_epoch == "$selected_pool_drain_epoch" \
        && $pool_state_route_ack == "$selected_pool_drain_ack" ]] \
        || fail 'durable drained pool does not match the exact requested member and epoch'
    if [[ $pool_drain_member == a ]]; then
        [[ $pool_state_member_a_admin_state == drain \
            && $pool_state_member_b_admin_state == ready ]] \
            || fail 'drained pool membership is not exactly drain web-a, ready web-b'
    else
        [[ $pool_state_member_a_admin_state == ready \
            && $pool_state_member_b_admin_state == drain ]] \
            || fail 'drained pool membership is not exactly ready web-a, drain web-b'
    fi
    assert_pool_runtime_identity_v2
    if [[ $pool_main_pid != "$previous_pid" \
        || $pool_main_start_time != "$previous_start" \
        || $pool_main_boot_id != "$previous_boot" ]]; then
        persist_pool_server_state_v2
        write_pool_state_v2 drained
    fi
    assert_pool_slot_endpoints_v2
    probe_pool_member_v2 "$pool_drain_member" draining
    probe_pool_member_v2 "$pool_surviving_member" active
    if [[ $pool_drain_member == a ]]; then
        assert_pool_live_membership_v2 drain ready
    else
        assert_pool_live_membership_v2 ready drain
    fi
}

drain_pool_member_v2()
{
    load_manifest
    load_state
    [[ $state_phase == permanent ]] \
        || fail 'pool member drain requires completed phase-A finalization'
    load_pool_inputs_v2
    configure_pool_phase_a_target_v2
    assert_pool_phase_a_target_v2
    prepare_pool_runtime_v2
    select_pool_drain_member_v2
    assert_pool_drain_lineage_v2
    case "$pool_status" in
        active)
            probe_pool_member_v2 "$pool_surviving_member" active
            probe_pool_member_v2 "$pool_drain_member" draining
            if [[ $pool_drain_member == a ]]; then
                assert_pool_live_membership_v2 down-ready ready
            else
                assert_pool_live_membership_v2 ready down-ready
            fi
            pool_drained_role=$drain_member
            pool_drain_epoch=$selected_pool_drain_epoch
            pool_state_route_ack=$selected_pool_drain_ack
            write_pool_state_v2 drain-intent
            pool_test_crash_v2 after-pool-drain-intent
            ;;
        drain-intent)
            probe_pool_member_v2 "$pool_surviving_member" active
            probe_pool_member_v2 "$pool_drain_member" draining
            case "$(pool_member_admin_state_v2 "$pool_drain_member")" in
                ready)
                    if [[ $pool_drain_member == a ]]; then
                        assert_pool_live_membership_v2 down-ready ready
                    else
                        assert_pool_live_membership_v2 ready down-ready
                    fi
                    ;;
                drain)
                    if [[ $pool_drain_member == a ]]; then
                        assert_pool_live_membership_v2 drain ready
                    else
                        assert_pool_live_membership_v2 ready drain
                    fi
                    ;;
                *) fail 'drain intent slot is neither its exact pre-state nor post-state' ;;
            esac
            ;;
        drained)
            assert_drained_pool_runtime_v2
            probe_pool_route_v2 "$selected_pool_drain_ack"
            publish_pool_boot_identity_v2
            return 0
            ;;
    esac
    case "$(pool_member_admin_state_v2 "$pool_drain_member")" in
        ready)
            if [[ $pool_drain_member == a ]]; then
                assert_pool_live_membership_v2 down-ready ready
            else
                assert_pool_live_membership_v2 ready down-ready
            fi
            ;;
        drain)
            if [[ $pool_drain_member == a ]]; then
                assert_pool_live_membership_v2 drain ready
            else
                assert_pool_live_membership_v2 ready drain
            fi
            ;;
        *) fail 'drain mutation slot is neither its exact pre-state nor post-state' ;;
    esac
    mutate_pool_slot_v2 drain "$pool_drain_member" \
        "$(pool_plan_value "member_${pool_drain_member}_expected_loopback_port")" drain
    if [[ $pool_drain_member == a ]]; then
        [[ $pool_state_member_b_admin_state == ready ]] \
            || fail 'web-b stopped being the sole ready survivor during web-a drain'
    else
        [[ $pool_state_member_a_admin_state == ready ]] \
            || fail 'web-a stopped being the sole ready survivor during web-b drain'
    fi
    probe_pool_member_v2 "$pool_surviving_member" active
    probe_pool_member_v2 "$pool_drain_member" draining
    if [[ $pool_drain_member == a ]]; then
        assert_pool_live_membership_v2 drain ready
    else
        assert_pool_live_membership_v2 ready drain
    fi
    probe_pool_route_v2 "$selected_pool_drain_ack"
    pool_test_crash_v2 after-pool-drain-route
    write_pool_state_v2 drained
    pool_test_crash_v2 after-pool-drain-state
    assert_drained_pool_runtime_v2
    publish_pool_boot_identity_v2
}

assert_drained_pool_v2()
{
    load_manifest
    load_state
    [[ $state_phase == permanent ]] \
        || fail 'drained pool assertion requires completed phase-A finalization'
    load_pool_inputs_v2
    configure_pool_phase_a_target_v2
    assert_pool_phase_a_target_v2
    prepare_pool_runtime_v2
    select_pool_drain_member_v2
    assert_drained_pool_runtime_v2
    probe_pool_route_v2 "$selected_pool_drain_ack"
    publish_pool_boot_identity_v2
}

verify_drained_pool_v2()
{
    assert_drained_pool_v2
    probe_pool_route_v2 "$selected_pool_drain_ack"
}

restore_legacy_pool_safe_v2()
{
    [[ ! -e $pool_state_file ]] \
        || fail 'legacy restoration is forbidden after durable phase-B pool intent exists'
    case "$state_phase" in
        prepared|capture-intent|phase-a-running|capture-installed|captured|restored)
            restore_legacy
            ;;
        *) fail 'legacy restoration is available only while legacy ownership remains the bootstrap rollback target' ;;
    esac
}

assert_production_prerequisites()
{
    local installed competing_unit first_nftables_directive provenance
    [[ $(id -u) -eq 0 ]] || fail 'production :8000 controller must run as root'
    [[ $service_mode == systemd ]] || fail 'production requires systemd-managed host-native HAProxy'
    [[ -n $expected_docker_version ]] || fail 'production requires exact CONTROL_PLANE_PORT8000_EXPECTED_DOCKER_VERSION'
    [[ $(docker version --format '{{.Server.Version}}') == "$expected_docker_version" ]] \
        || fail 'Docker Engine does not match the explicitly attested production version'
    # shellcheck disable=SC1091 # Runtime distribution metadata has a fixed host path.
    . /etc/os-release
    [[ $(printf '%s:%s' "$ID" "$VERSION_ID") == ubuntu:24.04 ]] \
        || fail 'production host is not the attested Ubuntu 24.04 baseline'
    [[ -n $expected_kernel_release ]] \
        || fail 'production requires exact CONTROL_PLANE_PORT8000_EXPECTED_KERNEL_RELEASE'
    [[ $expected_kernel_release == 6.17.0-35-generic ]] \
        || fail 'production expected kernel must equal the attested 6.17.0-35-generic release'
    [[ $(uname -r) == "$expected_kernel_release" ]] \
        || fail 'production kernel release differs from the exact attested baseline'
    assert_regular_file "$source_env_file" CONTROL_PLANE_PORT8000_SOURCE_ENV_FILE
    [[ $(stat -c '%a:%u:%g' "$source_env_file") == 600:0:0 ]] \
        || fail 'Coolify source .env must be root-owned mode 0600 before control-plane migration'
    assert_regular_file /etc/nftables.conf 'host nftables configuration'
    first_nftables_directive=$(sed -E '/^[[:space:]]*(#|$)/d; s/^[[:space:]]+//; q' /etc/nftables.conf)
    [[ $first_nftables_directive == 'flush ruleset' ]] \
        || fail 'host nftables.conf identity changed from the attested global-flush baseline'
    ! systemctl is-enabled --quiet nftables.service 2>/dev/null \
        || fail 'global nftables.service must remain disabled because nftables.conf flushes Docker and Tailscale rules'
    ! systemctl is-active --quiet nftables.service 2>/dev/null \
        || fail 'global nftables.service is active and can flush Docker/Tailscale rules'
    competing_unit=/etc/systemd/system/coolify-proxy-rebind.service
    if [[ -e $competing_unit ]]; then
        [[ -f $competing_unit && ! -L $competing_unit ]] \
            || fail 'coolify-proxy-rebind.service ownership is ambiguous'
    fi
    ! systemctl is-enabled --quiet coolify-proxy-rebind.service 2>/dev/null \
        || fail 'enabled coolify-proxy-rebind.service restarts ingress on Docker lifecycle events; zero-disruption migration is blocked'
    ! systemctl is-active --quiet coolify-proxy-rebind.service 2>/dev/null \
        || fail 'coolify-proxy-rebind.service is active; zero-disruption migration is blocked'
    installed=$($haproxy_binary -v 2>&1 | sed -n '1s/^HAProxy version \([^ ]*\).*/\1/p')
    [[ $installed =~ ^2\.8\.26(-[0-9A-Fa-f]+)?$ ]] \
        || fail 'HAProxy runtime must be exactly official 2.8.26'
    $haproxy_binary -vv 2>&1 | grep -F '+SYSTEMD' >/dev/null \
        || fail 'HAProxy 2.8.26 runtime was not compiled with USE_SYSTEMD=1'
    provenance=/usr/local/lib/coolify-control-plane-port8000/haproxy-2.8.26/provenance
    assert_regular_file "$provenance" 'HAProxy runtime provenance'
    [[ $(stat -c '%a:%u:%g' "$provenance") == 600:0:0 \
        && $(sed 's/=.*//' "$provenance") \
            == $'version\nhaproxy_version\nhaproxy_source_sha256\nhaproxy_build_options\nhaproxy_binary\nhaproxy_binary_sha256' \
        && $(safe_state_value version "$provenance") == 1 \
        && $(safe_state_value haproxy_version "$provenance") == 2.8.26 \
        && $(safe_state_value haproxy_source_sha256 "$provenance") \
            == 88c28dae25ea46672e66f8db0dadd1fb5920e06ee2415ceb9f281c256b537727 \
        && $(safe_state_value haproxy_build_options "$provenance") \
            == 'TARGET=linux-glibc USE_SYSTEMD=1' \
        && $(safe_state_value haproxy_binary "$provenance") == "$haproxy_binary" \
        && $(safe_state_value haproxy_binary_sha256 "$provenance") \
            == "$(checksum "$haproxy_binary")" ]] \
        || fail 'HAProxy runtime provenance differs from the current binary identity'
    installed=$(dpkg-query -W -f='${Version}' nftables 2>/dev/null || true)
    [[ $installed == "$expected_nftables_package" ]] || fail "nftables package must be exactly $expected_nftables_package"
    installed=$(dpkg-query -W -f='${Version}' conntrack 2>/dev/null || true)
    [[ $installed == "$expected_conntrack_package" ]] || fail "conntrack package must be exactly $expected_conntrack_package"
    assert_regular_file "$cold_boot_attestation" CONTROL_PLANE_PORT8000_COLD_BOOT_ATTESTATION_FILE
    grep -F -x -q 'result=pass' "$cold_boot_attestation" || fail 'real-Linux cold-boot attestation has not passed'
    grep -F -x -q "hostname=$(hostname -f)" "$cold_boot_attestation" || fail 'cold-boot attestation belongs to a different host'
    grep -F -x -q "controller_sha256=$(checksum "$0")" "$cold_boot_attestation" \
        || fail 'cold-boot attestation belongs to a different controller build'

    for asset in coolify-port8000-haproxy@.service \
        coolify-port8000-phase-b-authorizer.service coolify-port8000-nft.service \
        apply-active-nft.sh; do
        assert_regular_file "$ASSET_DIRECTORY/$asset" "repository $asset"
    done
    assert_regular_file /etc/systemd/system/coolify-port8000-haproxy@.service 'installed HAProxy systemd unit'
    assert_regular_file /etc/systemd/system/coolify-port8000-phase-b-authorizer.service \
        'installed phase-B authorizer systemd unit'
    assert_regular_file /etc/systemd/system/coolify-port8000-nft.service 'installed nftables systemd unit'
    assert_regular_file /usr/local/libexec/coolify-port8000-apply-active-nft 'installed nftables apply helper'
    assert_regular_file /usr/local/libexec/coolify-haproxy-port8000-controller \
        'installed phase-B boot controller'
    [[ $(checksum /etc/systemd/system/coolify-port8000-haproxy@.service) \
        == "$(checksum "$ASSET_DIRECTORY/coolify-port8000-haproxy@.service")" ]] \
        || fail 'installed HAProxy systemd unit differs from the reviewed repository unit'
    [[ $(checksum /etc/systemd/system/coolify-port8000-phase-b-authorizer.service) \
        == "$(checksum "$ASSET_DIRECTORY/coolify-port8000-phase-b-authorizer.service")" ]] \
        || fail 'installed phase-B authorizer unit differs from the reviewed repository unit'
    [[ $(checksum /etc/systemd/system/coolify-port8000-nft.service) \
        == "$(checksum "$ASSET_DIRECTORY/coolify-port8000-nft.service")" ]] \
        || fail 'installed nftables systemd unit differs from the reviewed repository unit'
    [[ $(checksum /usr/local/libexec/coolify-port8000-apply-active-nft) \
        == "$(checksum "$ASSET_DIRECTORY/apply-active-nft.sh")" ]] \
        || fail 'installed nftables apply helper differs from the reviewed repository helper'
    [[ $(checksum /usr/local/libexec/coolify-haproxy-port8000-controller) \
        == "$(checksum "$0")" ]] \
        || fail 'installed phase-B boot controller differs from the active controller'
    systemctl is-enabled --quiet coolify-port8000-haproxy@phase-a.service \
        || fail 'phase-A HAProxy boot service is not enabled'
    systemctl is-enabled --quiet coolify-port8000-haproxy@phase-b.service \
        || fail 'phase-B HAProxy boot service is not enabled'
    systemctl is-enabled --quiet coolify-port8000-phase-b-authorizer.service \
        || fail 'phase-B boot authorizer service is not enabled'
    systemctl is-enabled --quiet coolify-port8000-nft.service \
        || fail 'nftables boot service is not enabled'
}

preflight()
{
    local owner_count firewall_backend firewall_backend_json socket_lines
    for command in awk conntrack curl docker flock grep haproxy install ip ipcalc jq nft readlink sed sha256sum socat ss stat sync unshare; do
        require_command "$command"
    done
    [[ $service_mode != systemd ]] || require_command systemctl
    assert_host_local_url
    validate_ip_or_none CONTROL_PLANE_PORT8000_CANARY_TARGET_IPV4 "$canary_target_ipv4" 4
    validate_ip_or_none CONTROL_PLANE_PORT8000_CANARY_TARGET_IPV6 "$canary_target_ipv6" 6
    [[ $canary_target_ipv4 != none ]] || fail 'an explicit internal canary target IPv4 address is required'
    ip -o -4 address show | grep -E "[[:space:]]${canary_target_ipv4}/" >/dev/null \
        || fail 'internal canary target IPv4 address is not assigned to the host'
    if [[ $canary_target_ipv6 != none ]]; then
        ip -o -6 address show | grep -E "[[:space:]]${canary_target_ipv6}/" >/dev/null \
            || fail 'internal canary target IPv6 address is not assigned to the host'
    fi
    ((nft_priority > -200 && nft_priority < -100)) \
        || fail 'nftables capture priority must be after conntrack (-200) and strictly before Docker destination NAT (-100)'
    getent passwd "$haproxy_user" >/dev/null || fail 'HAProxy runtime user is absent'
    getent group "$haproxy_group" >/dev/null || fail 'HAProxy runtime group is absent'
    if [[ $target == production ]]; then
        assert_production_prerequisites
    elif [[ $target != lab ]]; then
        fail 'controller target must be production or lab'
    fi
    if [[ $expected_firewall_backend == iptables ]]; then
        require_command iptables
        require_command iptables-save
    fi
    firewall_backend_json=$(docker info --format '{{json .FirewallBackend}}' 2>/dev/null) \
        || fail 'Docker Engine information is unavailable'
    jq -e -s 'length == 1' <<< "$firewall_backend_json" >/dev/null 2>&1 \
        || fail 'Docker Engine information returned malformed JSON'
    firewall_backend=$(
        jq -r '
            if type == "object" then (.Driver // .driver // empty)
            elif type == "string" then .
            else empty
            end
        ' <<< "$firewall_backend_json" 2>/dev/null || true
    )
    if [[ -z $firewall_backend ]]; then
        if nft list table ip docker-bridges >/dev/null 2>&1; then
            firewall_backend=nftables
        elif iptables-save 2>/dev/null | grep -F 'DOCKER' >/dev/null; then
            firewall_backend=iptables
        else
            fail 'Docker firewall backend could not be proven'
        fi
    fi
    [[ $firewall_backend == "$expected_firewall_backend" ]] \
        || fail "Docker firewall backend is '$firewall_backend', expected '$expected_firewall_backend'"
    if [[ $expected_firewall_backend == iptables ]]; then
        iptables --version | grep -F 'nf_tables' >/dev/null \
            || fail 'iptables backend is not the attested nftables compatibility implementation'
    fi

    if [[ -f $manifest_file ]]; then
        load_manifest
        load_state
        assert_nft_owned_or_absent
        note "preflight-resume phase=$state_phase docker=$expected_docker_version firewall=$expected_firewall_backend"
        return
    fi
    owner_count=$(published_owner_count)
    [[ $owner_count -eq 1 ]] || fail 'preflight requires exactly one Docker container publishing host port 8000'
    if [[ $expect_userland_proxy == true ]]; then
        docker_proxy_owns_port || fail 'attested userland docker-proxy is not present for host port 8000'
        socket_lines=$(ss -H -ltnp "sport = :$public_port")
        grep -F -q 'docker-proxy' <<< "$socket_lines" \
            || fail 'docker-proxy does not own the host listening socket'
        grep -E -q "(^|[[:space:]])0\.0\.0\.0:${public_port}([[:space:]]|$)" <<< "$socket_lines" \
            || fail 'docker-proxy does not own the attested IPv4 wildcard listener'
        grep -E -q "(^|[[:space:]])(\[::\]|\*):${public_port}([[:space:]]|$)" <<< "$socket_lines" \
            || fail 'docker-proxy does not own the attested IPv6 wildcard listener'
    elif docker_proxy_owns_port; then
        fail 'docker-proxy is present although userland proxy was attested false'
    fi
    nft_table_exists && fail 'operator nftables table exists before operation preparation'
    ss -H -ltn "sport = :$bootstrap_port" | grep . >/dev/null \
        && fail 'bootstrap port is already listening before phase A'
    assert_external_policy_blocked
    probe_route legacy none legacy
    note "preflight-passed docker=$expected_docker_version firewall=$expected_firewall_backend userland_proxy=$expect_userland_proxy"
}

if [[ ${BASH_SOURCE[0]} != "$0" ]]; then
    return 0
fi

if [[ ${1:-} == consume-phase-b-start-authorization ]]; then
    [[ $# -eq 2 && $2 == phase-b ]] \
        || fail 'phase-B start authorization requires one exact phase-b argument'
    consume_pool_start_authorization_v2 "$2"
    exit 0
fi

if [[ ${1:-} == issue-phase-b-boot-start-authorization ]]; then
    [[ $# -eq 1 ]] || fail 'phase-B boot authorization accepts no arguments'
    issue_pool_boot_start_authorization_v2
    exit 0
fi

action=${1:-}
operation_id=${CONTROL_PLANE_INGRESS_OPERATION_ID:-}
operation_directory=${CONTROL_PLANE_INGRESS_OPERATION_DIR:-}
host_local_url=${CONTROL_PLANE_PORT8000_HOST_LOCAL_URL:-}
public_host_header=${CONTROL_PLANE_INGRESS_PUBLIC_HOST_HEADER:-}
probe_attempts=${CONTROL_PLANE_INGRESS_PROBE_ATTEMPTS:-20}
target=${CONTROL_PLANE_INGRESS_TARGET:-production}
test_mode=${CONTROL_PLANE_INGRESS_TEST_MODE:-0}
canary_target_ipv4=${CONTROL_PLANE_PORT8000_CANARY_TARGET_IPV4:-}
canary_target_ipv6=${CONTROL_PLANE_PORT8000_CANARY_TARGET_IPV6:-none}
bootstrap_port=${CONTROL_PLANE_INGRESS_BOOTSTRAP_PORT:-18000}
public_port=8000
color=${CONTROL_PLANE_INGRESS_COLOR:-legacy}
backend=${CONTROL_PLANE_INGRESS_BACKEND:-none}
backend_port=${CONTROL_PLANE_INGRESS_BACKEND_PORT:-0}
direct_probe_token_file=${CONTROL_PLANE_INGRESS_DIRECT_PROBE_TOKEN_FILE:-none}
direct_probe_path=${CONTROL_PLANE_INGRESS_DIRECT_PROBE_PATH:-/api/control-plane/probe}
requested_generation=${CONTROL_PLANE_INGRESS_GENERATION:-}
pool_manifest=${CONTROL_PLANE_INGRESS_POOL_MANIFEST:-}
pool_manifest_sha256=${CONTROL_PLANE_INGRESS_POOL_MANIFEST_SHA256:-}
pool_plan_manifest_source=${CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST:-}
pool_plan_manifest_sha256=${CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST_SHA256:-}
drain_member=${CONTROL_PLANE_INGRESS_DRAIN_MEMBER:-}
pool_loopback_address=127.0.0.1
rollback_owner_id=${CONTROL_PLANE_PORT8000_ROLLBACK_OWNER_ID:-none}
service_mode=${CONTROL_PLANE_PORT8000_SERVICE_MODE:-systemd}
process_root=${CONTROL_PLANE_PORT8000_TEST_PROCESS_ROOT:-/proc}
config_directory=${CONTROL_PLANE_PORT8000_CONFIG_DIR:-/etc/coolify-control-plane-port8000}
runtime_directory=${CONTROL_PLANE_PORT8000_RUNTIME_DIR:-/run/coolify-control-plane-port8000}
haproxy_state_directory=${CONTROL_PLANE_PORT8000_HAPROXY_STATE_DIR:-/var/lib/coolify-control-plane-port8000/haproxy}
haproxy_binary=${CONTROL_PLANE_PORT8000_HAPROXY_BIN:-/usr/local/lib/coolify-control-plane-port8000/haproxy-2.8.26/haproxy}
haproxy_user=${CONTROL_PLANE_PORT8000_HAPROXY_USER:-haproxy}
haproxy_group=${CONTROL_PLANE_PORT8000_HAPROXY_GROUP:-haproxy}
nft_table=${CONTROL_PLANE_PORT8000_NFT_TABLE:-coolify_cp_port8000}
nft_priority=${CONTROL_PLANE_PORT8000_NFT_PRIORITY:--110}
canary_netns=${CONTROL_PLANE_PORT8000_CANARY_NETNS:-}
canary_ipv4=${CONTROL_PLANE_PORT8000_CANARY_IPV4:-none}
canary_ipv6=${CONTROL_PLANE_PORT8000_CANARY_IPV6:-none}
external_policy_probe=${CONTROL_PLANE_PORT8000_EXTERNAL_POLICY_PROBE:-}
external_policy_probe_sha256=${CONTROL_PLANE_PORT8000_EXTERNAL_POLICY_PROBE_SHA256:-none}
external_blocked_endpoint=${CONTROL_PLANE_PORT8000_EXTERNAL_BLOCKED_ENDPOINT:-none}
ipv6_inventory_file=${CONTROL_PLANE_PORT8000_IPV6_INVENTORY_FILE:-}
ipv6_inventory_sha256=${CONTROL_PLANE_PORT8000_IPV6_INVENTORY_SHA256:-none}
ipv6_inventory_probe=${CONTROL_PLANE_PORT8000_IPV6_INVENTORY_PROBE:-}
ipv6_inventory_probe_sha256=${CONTROL_PLANE_PORT8000_IPV6_INVENTORY_PROBE_SHA256:-none}
public_ipv6_status=unknown
drain_attempts=${CONTROL_PLANE_PORT8000_DRAIN_ATTEMPTS:-60}
drain_stable_samples=${CONTROL_PLANE_PORT8000_DRAIN_STABLE_SAMPLES:-2}
pool_probe_fd_race_hook=${CONTROL_PLANE_TEST_PORT8000_POOL_PROBE_FD_RACE_HOOK:-}
expected_docker_version=${CONTROL_PLANE_PORT8000_EXPECTED_DOCKER_VERSION:-}
expected_kernel_release=${CONTROL_PLANE_PORT8000_EXPECTED_KERNEL_RELEASE:-}
expected_firewall_backend=${CONTROL_PLANE_PORT8000_EXPECTED_FIREWALL_BACKEND:-iptables}
expect_userland_proxy=${CONTROL_PLANE_PORT8000_EXPECT_USERLAND_PROXY:-true}
expected_nftables_package=${CONTROL_PLANE_PORT8000_EXPECTED_NFTABLES_PACKAGE:-1.0.9-1ubuntu0.1}
expected_conntrack_package=${CONTROL_PLANE_PORT8000_EXPECTED_CONNTRACK_PACKAGE:-1:1.4.8-1ubuntu1}
cold_boot_attestation=${CONTROL_PLANE_PORT8000_COLD_BOOT_ATTESTATION_FILE:-}
source_env_file=${CONTROL_PLANE_PORT8000_SOURCE_ENV_FILE:-/data/coolify/source/.env}

case "${test_mode}:${target}" in
    0:production|1:lab)
        ;;
    *)
        fail 'CONTROL_PLANE_INGRESS_TEST_MODE and CONTROL_PLANE_INGRESS_TARGET must be exactly 0:production or 1:lab'
        ;;
esac
if [[ $test_mode == 0 ]]; then
    immutable_uid=0
    immutable_gid=0
    runtime_artifact_uid=9999
    runtime_artifact_gid=9999
else
    immutable_uid=$(id -u)
    immutable_gid=$(id -g)
    runtime_artifact_uid=$immutable_uid
    runtime_artifact_gid=$immutable_gid
fi
if [[ $test_mode == 0 ]]; then
    [[ -z ${CONTROL_PLANE_TEST_PORT8000_CRASH_AT:-} \
        && -z ${CONTROL_PLANE_INGRESS_TEST_CRASH_AT:-} \
        && -z ${CONTROL_PLANE_TEST_PORT8000_POOL_PROBE_FD_RACE_HOOK:-} \
        && -z ${CONTROL_PLANE_TEST_PORT8000_RETAIN_CAPTURE_AFTER_REDIRECT:-} \
        && -z ${CONTROL_PLANE_TEST_PORT8000_DUPLICATE_PHASE_OWNER:-} ]] \
        || fail 'port-8000 fault injection is unavailable in production mode'
    [[ $process_root == /proc ]] \
        || fail 'test process root is unavailable in production mode'
fi
[[ $process_root == /* ]] || fail 'HAProxy process root must be absolute'
case "$action" in
    preflight|prepare|switch|assert|verify|ack|verify-ack|drain|assert-drained|verify-drained|\
    restore|adopt-rollback-owner|policy-status|external-blocked-status|legacy-restore-status)
        ;;
    *)
        fail 'controller action is absent or unsupported'
        ;;
esac

require_value CONTROL_PLANE_INGRESS_OPERATION_ID "$operation_id"
require_value CONTROL_PLANE_INGRESS_OPERATION_DIR "$operation_directory"
require_value CONTROL_PLANE_PORT8000_HOST_LOCAL_URL "$host_local_url"
require_value CONTROL_PLANE_PORT8000_CANARY_TARGET_IPV4 "$canary_target_ipv4"
require_value CONTROL_PLANE_INGRESS_BOOTSTRAP_PORT "$bootstrap_port"
validate_identifier CONTROL_PLANE_INGRESS_OPERATION_ID "$operation_id"
validate_identifier CONTROL_PLANE_PORT8000_NFT_TABLE "$nft_table"
validate_port CONTROL_PLANE_INGRESS_BOOTSTRAP_PORT "$bootstrap_port"
validate_positive_integer CONTROL_PLANE_INGRESS_PROBE_ATTEMPTS "$probe_attempts"
validate_positive_integer CONTROL_PLANE_PORT8000_DRAIN_ATTEMPTS "$drain_attempts"
validate_positive_integer CONTROL_PLANE_PORT8000_DRAIN_STABLE_SAMPLES "$drain_stable_samples"
[[ $direct_probe_path =~ ^/[A-Za-z0-9._~/%:@+-]{1,255}$ \
    && $direct_probe_path != *//* && $direct_probe_path != *..* ]] \
    || fail 'CONTROL_PLANE_INGRESS_DIRECT_PROBE_PATH must be one absolute normalized HTTP path'
assert_host_local_url
[[ -n $public_host_header ]] \
    || public_host_header="${CONTROL_PLANE_INGRESS_HOST:-coolify.iocloudhost.net}:8000"
validate_http_host_header_v2 CONTROL_PLANE_INGRESS_PUBLIC_HOST_HEADER "$public_host_header"
case "$action" in
    switch|assert|verify|ack|verify-ack|drain|assert-drained|verify-drained)
        require_value CONTROL_PLANE_INGRESS_COLOR "$color"
        [[ $color == green || $color == blue ]] \
            || fail 'managed :8000 routing requires a blue or green ingress-pool manifest v2'
        require_value CONTROL_PLANE_INGRESS_GENERATION "$requested_generation"
        validate_positive_integer CONTROL_PLANE_INGRESS_GENERATION "$requested_generation"
        require_value CONTROL_PLANE_INGRESS_POOL_MANIFEST "$pool_manifest"
        require_value CONTROL_PLANE_INGRESS_POOL_MANIFEST_SHA256 "$pool_manifest_sha256"
        require_value CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST "$pool_plan_manifest_source"
        require_value CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST_SHA256 "$pool_plan_manifest_sha256"
        validate_absolute_path CONTROL_PLANE_INGRESS_POOL_MANIFEST "$pool_manifest"
        validate_absolute_path CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST "$pool_plan_manifest_source"
        validate_sha256 CONTROL_PLANE_INGRESS_POOL_MANIFEST_SHA256 "$pool_manifest_sha256"
        validate_sha256 CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST_SHA256 \
            "$pool_plan_manifest_sha256"
        case "$action" in
            drain|assert-drained|verify-drained)
                require_value CONTROL_PLANE_INGRESS_DRAIN_MEMBER "$drain_member"
                [[ $drain_member == web-a || $drain_member == web-b ]] \
                    || fail 'CONTROL_PLANE_INGRESS_DRAIN_MEMBER must be exactly web-a or web-b'
                ;;
        esac
        ;;
esac
case "$action" in
    preflight|prepare|external-blocked-status|policy-status)
        assert_external_policy_blocked
        ;;
esac
[[ $expect_userland_proxy == true || $expect_userland_proxy == false ]] \
    || fail 'CONTROL_PLANE_PORT8000_EXPECT_USERLAND_PROXY must be true or false'
[[ $canary_ipv4 != none || $canary_ipv6 != none || -z $canary_netns ]] \
    || fail 'canary network namespace requires at least one pinned source address'

manifest_file="$operation_directory/manifest"
state_file="$operation_directory/state"
pool_state_file="$operation_directory/pool-state"
pool_manifest_snapshot="$operation_directory/ingress-pool.manifest"
pool_plan_snapshot="$operation_directory/pool-plan.manifest"
legacy_snapshot_file="$operation_directory/legacy-docker-owner.json"
legacy_adoption_snapshot_file="$operation_directory/legacy-docker-owner-adopted.json"
lock_file="$operation_directory/controller.lock"
if [[ $action == legacy-restore-status ]]; then
    [[ -d $operation_directory && ! -L $operation_directory ]] \
        || fail 'legacy restore status requires an existing non-symlink operation directory'
    ensure_root_directory "$operation_directory" 700 'legacy restore operation directory'
    load_manifest
    load_state
    legacy_restore_status
    exit 0
fi
ensure_root_directory "$operation_directory" 700 'operation directory'
ensure_root_directory "$config_directory" 700 'HAProxy configuration directory'
ensure_root_directory "$runtime_directory" 755 'HAProxy runtime directory'
ensure_root_directory "$haproxy_state_directory" 700 'HAProxy state directory'
exec 9>"$lock_file"
chmod 600 "$lock_file"
flock -n 9 || fail 'another :8000 controller process holds the operation lock'

case "$action" in
    preflight)
        preflight
        ;;
    prepare)
        preflight
        prepare_operation
        ;;
    switch)
        switch_pool_v2
        ;;
    assert)
        assert_pool_v2
        ;;
    verify|ack|verify-ack)
        verify_pool_v2
        ;;
    drain)
        drain_pool_member_v2
        ;;
    assert-drained)
        assert_drained_pool_v2
        ;;
    verify-drained)
        verify_drained_pool_v2
        ;;
    restore)
        load_manifest
        load_state
        restore_legacy_pool_safe_v2
        ;;
    adopt-rollback-owner)
        [[ $rollback_owner_id =~ ^[a-f0-9]{64}$ ]] \
            || fail 'CONTROL_PLANE_PORT8000_ROLLBACK_OWNER_ID must be a full lowercase Docker ID'
        load_manifest
        load_state
        adopt_rollback_owner
        ;;
    policy-status)
        if [[ -f $pool_state_file ]]; then
            require_value CONTROL_PLANE_INGRESS_COLOR "$color"
            require_value CONTROL_PLANE_INGRESS_GENERATION "$requested_generation"
            require_value CONTROL_PLANE_INGRESS_POOL_MANIFEST "$pool_manifest"
            require_value CONTROL_PLANE_INGRESS_POOL_MANIFEST_SHA256 "$pool_manifest_sha256"
            require_value CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST "$pool_plan_manifest_source"
            require_value CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST_SHA256 \
                "$pool_plan_manifest_sha256"
            load_manifest
            load_state
            load_pool_inputs_v2
            configure_pool_phase_a_target_v2
            assert_pool_phase_a_target_v2
            prepare_pool_runtime_v2
            case "$pool_status" in
                active)
                    assert_pool_runtime_active_v2
                    probe_pool_route_v2 "$pool_route_ack"
                    ;;
                drained)
                    require_value CONTROL_PLANE_INGRESS_DRAIN_MEMBER "$drain_member"
                    select_pool_drain_member_v2
                    assert_drained_pool_runtime_v2
                    probe_pool_route_v2 "$selected_pool_drain_ack"
                    ;;
                *) fail "policy status refuses transitional pool status: $pool_status" ;;
            esac
            probe_direct_bootstrap_denied
            assert_external_policy_blocked
            printf 'CONTROL_PLANE_PORT8000_POLICY result=pass phase=%s pool_status=%s host_local=pass direct_bootstrap=denied external_ipv4_tcp8000=blocked external_ipv6_tcp8000=%s\n' \
                "$state_phase" "$pool_status" "$public_ipv6_status"
        else
            if [[ -f $state_file ]]; then
                load_manifest
                load_state
                if [[ $state_color != legacy ]]; then
                    require_value CONTROL_PLANE_INGRESS_DIRECT_PROBE_TOKEN_FILE "$direct_probe_token_file"
                    read_probe_token >/dev/null
                fi
            fi
            policy_status
        fi
        ;;
    external-blocked-status)
        [[ ! -f $manifest_file ]] || load_manifest
        assert_external_policy_blocked
        printf 'CONTROL_PLANE_PORT8000_EXTERNAL_POLICY_STATUS result=pass endpoint=%s probe_sha256=%s\n' \
            "$external_blocked_endpoint" "$external_policy_probe_sha256"
        ;;
    *)
        fail 'usage: haproxy-port8000.sh {preflight|prepare|switch|assert|verify|ack|verify-ack|drain|assert-drained|verify-drained|restore|adopt-rollback-owner|legacy-restore-status|policy-status|external-blocked-status}; managed routing requires ingress-pool.manifest v2'
        ;;
esac
