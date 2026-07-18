#!/bin/sh

set -eu
umask 077

fail()
{
    [ -z "${plan_identity_file:-}" ] || rm -f "$plan_identity_file"
    printf 'CONTROL_PLANE_INGRESS_CONTROLLER_FAILURE %s\n' "$1" >&2
    exit 1
}

require_value()
{
    [ -n "$2" ] || fail "required setting is empty: $1"
}

checksum()
{
    sha256sum "$1" | awk '{print $1}'
}

count_exact_line()
{
    exact_line=$1
    inspected_file=$2
    printf '%s\n' "$exact_line" | grep -F -x -c -f - "$inspected_file"
}

validate_single_line()
{
    sanitized_value=$(printf '%s' "$2" | LC_ALL=C tr -d '\r\n')
    [ "$sanitized_value" = "$2" ] || fail "$1 must be one complete line"
}

validate_sha256()
{
    validate_single_line "$1" "$2"
    printf '%s\n' "$2" | grep -Eq '^[a-f0-9]{64}$' \
        || fail "$1 must be a lowercase SHA-256"
}

validate_token()
{
    validate_single_line "$1" "$2"
    printf '%s\n' "$2" | grep -Eq '^[A-Za-z0-9._:-]{16,128}$' \
        || fail "$1 is not a safe token"
}

validate_identifier()
{
    validate_single_line "$1" "$2"
    printf '%s\n' "$2" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$' \
        || fail "$1 is not a safe identifier"
}

validate_positive_integer()
{
    validate_single_line "$1" "$2"
    printf '%s\n' "$2" | grep -Eq '^[1-9][0-9]*$' \
        || fail "$1 must be a positive integer"
}

validate_port()
{
    validate_positive_integer "$1" "$2"
    [ "$2" -le 65535 ] || fail "$1 must be at most 65535"
}

validate_absolute_path()
{
    validate_single_line "$1" "$2"
    case "$2" in
        /*) ;;
        *) fail "$1 must be absolute" ;;
    esac
    case "$2" in
        *//*|*'/../'*|*'/./'*|*/..|*/.|*[!A-Za-z0-9_./-]*) fail "$1 must be canonical and normalized" ;;
    esac
}

validate_hostname()
{
    validate_single_line "$1" "$2"
    if [ "${#2}" -gt 253 ] \
        || ! printf '%s\n' "$2" | grep -Eq '^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$'; then
        fail "$1 must be a safe DNS hostname"
    fi
    case "$2" in *..*|*.-*|*-.*) fail "$1 is not a canonical DNS hostname" ;; esac
}

validate_http_url()
{
    validate_single_line "$1" "$2"
    case "$2" in http://*|https://*) ;; *) fail "$1 must use HTTP or HTTPS" ;; esac
    printf '%s\n' "$2" | grep -Eq '^[A-Za-z0-9_./:@?&=%+-]+$' \
        || fail "$1 contains unsafe URL bytes"
}

validate_provider_api_url()
{
    validate_single_line "$1" "$2"
    python3 - "$2" <<'PY' || fail "$1 must use exact loopback HTTP authority and /api/rawdata"
import re
import sys
import urllib.parse

url = sys.argv[1]
parsed = urllib.parse.urlsplit(url)
try:
    port = parsed.port
except ValueError:
    port = None
expected_authorities = set()
if port is not None:
    expected_authorities = {f"127.0.0.1:{port}", f"[::1]:{port}"}
query_pattern = re.compile(r"(?:[A-Za-z0-9._~/:+,=&@?-]|%[A-Fa-f0-9]{2})*")
valid = (
    url.startswith("http://")
    and all(0x21 <= ord(character) <= 0x7e for character in url)
    and parsed.scheme == "http"
    and parsed.hostname in ("127.0.0.1", "::1")
    and parsed.netloc in expected_authorities
    and port is not None
    and 1 <= port <= 65535
    and parsed.path == "/api/rawdata"
    and query_pattern.fullmatch(parsed.query) is not None
    and not parsed.fragment
    and parsed.username is None
    and parsed.password is None
)
raise SystemExit(0 if valid else 1)
PY
}

validate_http_host_header()
{
    validate_single_line "$1" "$2"
    printf '%s\n' "$2" \
        | grep -Eq '^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(:[1-9][0-9]{0,4})?$' \
        || fail "$1 must be a safe DNS host with an optional port"
}

validate_ipv4()
{
    validate_single_line "$1" "$2"
    printf '%s\n' "$2" | awk -F. '
        NF != 4 { exit 1 }
        {
            for (part = 1; part <= 4; part++) {
                if ($part !~ /^(0|[1-9][0-9]{0,2})$/ || $part + 0 > 255) {
                    exit 1
                }
            }
        }
    ' || fail "$1 must be one canonical IPv4 address"
}

validate_globally_routable_ipv4()
{
    validate_ipv4 "$1" "$2"
    printf '%s\n' "$2" | awk -F. '
        ($1 == 0 || $1 == 10 || $1 == 127 || $1 >= 224) { exit 1 }
        ($1 == 100 && $2 >= 64 && $2 <= 127) { exit 1 }
        ($1 == 169 && $2 == 254) { exit 1 }
        ($1 == 172 && $2 >= 16 && $2 <= 31) { exit 1 }
        ($1 == 192 && $2 == 0 && ($3 == 0 || $3 == 2)) { exit 1 }
        ($1 == 192 && $2 == 31 && $3 == 196) { exit 1 }
        ($1 == 192 && $2 == 52 && $3 == 193) { exit 1 }
        ($1 == 192 && $2 == 88 && $3 == 99) { exit 1 }
        ($1 == 192 && $2 == 168) { exit 1 }
        ($1 == 192 && $2 == 175 && $3 == 48) { exit 1 }
        ($1 == 198 && ($2 == 18 || $2 == 19)) { exit 1 }
        ($1 == 198 && $2 == 51 && $3 == 100) { exit 1 }
        ($1 == 203 && $2 == 0 && $3 == 113) { exit 1 }
    ' || fail "$1 must be a globally routable public IPv4 address"
}

assert_distinct_values()
{
    distinct_label=$1
    shift
    [ "$#" -gt 1 ] || fail "$distinct_label requires multiple authority values"
    for distinct_value in "$@"; do
        [ -n "$distinct_value" ] || fail "$distinct_label contains an empty authority value"
        validate_single_line "$distinct_label" "$distinct_value"
    done
    distinct_total=$#
    distinct_unique=$(printf '%s\n' "$@" | LC_ALL=C sort -u | wc -l | tr -d ' ')
    [ "$distinct_total" -eq "$distinct_unique" ] \
        || fail "$distinct_label reuses an authority value"
}

assert_sorted_unique_network_ids()
{
    network_label=$1
    network_ids=$2
    validate_single_line "$network_label" "$network_ids"
    printf '%s\n' "$network_ids" \
        | grep -Eq '^[a-f0-9]{64}(,[a-f0-9]{64})*$' \
        || fail "$network_label is malformed"
    canonical_network_ids=$(printf '%s\n' "$network_ids" | tr ',' '\n' \
        | LC_ALL=C sort -u | paste -sd, -)
    [ "$network_ids" = "$canonical_network_ids" ] \
        || fail "$network_label must be sorted and contain no duplicates"
}

run_safe_read_test_hook()
{
    hook_stage=$1
    hook_label=$2
    hook_path=$3
    safe_read_hook=${CONTROL_PLANE_INGRESS_TEST_SAFE_READ_HOOK:-}
    [ -n "$safe_read_hook" ] || return 0
    [ "$test_mode" = 1 ] || fail 'safe-read test hook is forbidden outside test mode'
    validate_absolute_path CONTROL_PLANE_INGRESS_TEST_SAFE_READ_HOOK "$safe_read_hook"
    [ -f "$safe_read_hook" ] && [ ! -L "$safe_read_hook" ] && [ -x "$safe_read_hook" ] \
        || fail 'safe-read test hook must be an executable non-symlink file'
    "$safe_read_hook" "$hook_stage" "$hook_label" "$hook_path"
}

file_metadata()
{
    if stat -Lc '%u:%g:%a:%s:%d:%i' "$1" >/dev/null 2>&1; then
        stat -Lc '%u:%g:%a:%s:%d:%i' "$1"
    else
        stat -f '%u:%g:%Lp:%z:%d:%i' "$1"
    fi
}

open_file_metadata()
{
    descriptor=$1
    if [ -e "/proc/$$/fd/$descriptor" ]; then
        stat -Lc '%u:%g:%a:%s:%d:%i' "/proc/$$/fd/$descriptor"
    else
        python3 -c 'import os, stat, sys; descriptor=int(sys.argv[1]); value=os.fstat(descriptor); print(f"{value.st_uid}:{value.st_gid}:{stat.S_IMODE(value.st_mode):o}:{value.st_size}:{value.st_dev}:{value.st_ino}")' "$descriptor"
    fi
}

assert_safe_file()
{
    inspected_path=$1
    expected_sha256=$2
    expected_metadata=$3
    label=$4
    expected_mode=${5:-600}
    expected_uid=${6:-0}
    expected_gid=${7:-0}

    validate_absolute_path "$label path" "$inspected_path"
    [ -f "$inspected_path" ] && [ ! -L "$inspected_path" ] && [ -r "$inspected_path" ] \
        || fail "$label is not a readable regular non-symlink file"
    resolved_file=$(python3 -c 'import os, sys; print(os.path.realpath(sys.argv[1]))' "$inspected_path")
    [ "$resolved_file" = "$inspected_path" ] || fail "$label path contains a symlinked component"
    actual_metadata=$(file_metadata "$inspected_path")
    actual_public_metadata=${actual_metadata%:*:*}
    [ "$actual_public_metadata" = "$expected_metadata" ] \
        || fail "$label metadata differs from its pinned identity"
    case "$expected_metadata" in
        *:*:"$expected_mode":*) ;;
        *) fail "$label pinned mode must be 0$expected_mode" ;;
    esac
    if [ "$test_mode" = 0 ]; then
        case "$expected_metadata" in
            "$expected_uid":"$expected_gid":"$expected_mode":*) ;;
            *) fail "$label has incorrect production ownership or mode" ;;
        esac
    fi
    [ "$(checksum "$inspected_path")" = "$expected_sha256" ] \
        || fail "$label differs from its pinned SHA-256"
}

read_attested_file()
{
    inspected_path=$1
    expected_sha256=$2
    expected_metadata=$3
    label=$4
    descriptor=$5
    expected_mode=${6:-600}
    expected_uid=${7:-0}
    expected_gid=${8:-0}
    identity_file=${9:-}
    identity_key=${10:-}

    validate_absolute_path "$label path" "$inspected_path"
    [ -f "$inspected_path" ] && [ ! -L "$inspected_path" ] && [ -r "$inspected_path" ] \
        || fail "$label is not a readable regular non-symlink file"
    attested_metadata=$(file_metadata "$inspected_path")
    assert_safe_file "$inspected_path" "$expected_sha256" "$expected_metadata" "$label" \
        "$expected_mode" "$expected_uid" "$expected_gid"
    [ "$(file_metadata "$inspected_path")" = "$attested_metadata" ] \
        || fail "$label changed during path attestation"
    eval "exec ${descriptor}<\"\$inspected_path\""
    descriptor_metadata=$(open_file_metadata "$descriptor")
    after_metadata=$(file_metadata "$inspected_path")
    [ "$attested_metadata" = "$descriptor_metadata" ] \
        && [ "$descriptor_metadata" = "$after_metadata" ] \
        || fail "$label changed while it was opened"
    bytes=$(eval "cat <&${descriptor}")
    eval "exec ${descriptor}<&-"
    [ "$(file_metadata "$inspected_path")" = "$attested_metadata" ] \
        || fail "$label path changed while its descriptor was read"
    byte_count=${expected_metadata##*:}
    [ "$byte_count" = "${#bytes}" ] || fail "$label must not contain a trailing newline"
    [ "$(printf '%s' "$bytes" | sha256sum | awk '{print $1}')" = "$expected_sha256" ] \
        || fail "$label bytes changed after it was opened"
    if [ -n "$identity_file" ]; then
        [ -n "$identity_key" ] || fail "$label identity key is empty"
        printf '%s=%s\n' "$identity_key" \
            "$(printf '%s\n' "$attested_metadata" | awk -F: '{print $5 ":" $6}')" \
            >> "$identity_file"
    fi
    printf '%s\n' "$bytes"
}

manifest_value()
{
    key=$1
    value=$(sed -n "s/^${key}=//p" "$pool_manifest")
    [ "$(awk -F= -v expected_key="$key" \
        '$1 == expected_key { count++ } END { print count + 0 }' "$pool_manifest")" -eq 1 ] \
        || fail "ingress-pool manifest key is absent or duplicated: $key"
    printf '%s\n' "$value"
}

expected_manifest_keys()
{
    printf '%s\n' \
        version operation_id direction color generation parent_pool_plan_sha256 \
        pool_ack_file pool_ack_sha256 pool_ack_metadata member_count \
        member_a_role member_a_name member_a_route_identity member_a_id member_a_address member_a_port \
        member_a_image_reference member_a_image_id member_a_runtime_sha256 \
        member_a_network_sha256 member_a_bindings_sha256 member_a_applied_ack_file \
        member_a_applied_ack_sha256 member_a_applied_ack_metadata \
        member_b_role member_b_name member_b_route_identity member_b_id member_b_address member_b_port \
        member_b_image_reference member_b_image_id member_b_runtime_sha256 \
        member_b_network_sha256 member_b_bindings_sha256 member_b_applied_ack_file \
        member_b_applied_ack_sha256 member_b_applied_ack_metadata writer_member \
        pool_label_key pool_label_value member_set_sha256 route_health_path \
        route_health_token_file route_health_token_sha256 route_health_token_metadata
}

plan_value()
{
    key=$1
    value=$(sed -n "s/^${key}=//p" "$pool_plan_manifest")
    [ "$(awk -F= -v expected_key="$key" \
        '$1 == expected_key { count++ } END { print count + 0 }' "$pool_plan_manifest")" -eq 1 ] \
        || fail "pool-plan manifest key is absent or duplicated: $key"
    printf '%s\n' "$value"
}

plan_member_keys()
{
    member=$1
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

expected_pool_plan_keys()
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
    if [ "$(plan_value retired_member_count)" = 2 ]; then
        printf '%s\n' retired_member_b_name retired_member_b_id
    fi
    printf '%s\n' ingress_pool_manifest_path pool_plan_set_sha256
}

compute_pool_plan_set_sha256()
{
    for member in a b; do
        plan_member_keys "$member" | while IFS= read -r key; do
            printf '%s\0' "$(plan_value "$key")"
        done
    done
    printf '%s\0' "$(plan_value writer_member)"
}

read_plan_artifact()
{
    artifact_prefix=$1
    artifact_label=$2
    artifact_descriptor=$3
    artifact_mode=${4:-600}
    artifact_uid=${5:-0}
    artifact_gid=${6:-0}
    artifact_path=$(plan_value "${artifact_prefix}_file")
    artifact_sha256=$(plan_value "${artifact_prefix}_sha256")
    artifact_metadata=$(plan_value "${artifact_prefix}_metadata")
    validate_sha256 "$artifact_label SHA-256" "$artifact_sha256"
    read_attested_file "$artifact_path" "$artifact_sha256" "$artifact_metadata" \
        "$artifact_label" "$artifact_descriptor" "$artifact_mode" "$artifact_uid" "$artifact_gid" \
        "$plan_identity_file" "$artifact_prefix"
}

plan_artifact_identity()
{
    identity_prefix=$1
    identity_value=$(sed -n "s/^${identity_prefix}=//p" "$plan_identity_file")
    [ "$(awk -F= -v key="$identity_prefix" '$1 == key { count++ } END { print count + 0 }' \
        "$plan_identity_file")" -eq 1 ] \
        || fail "pool plan artifact identity is absent or duplicated: $identity_prefix"
    printf '%s\n' "$identity_value"
}

load_pool_plan()
{
    require_value CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST "$pool_plan_manifest_source"
    require_value CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST_SHA256 "$pool_plan_manifest_sha256"
    validate_sha256 CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST_SHA256 "$pool_plan_manifest_sha256"
    validate_absolute_path CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST "$pool_plan_manifest_source"
    [ -f "$pool_plan_manifest_source" ] && [ ! -L "$pool_plan_manifest_source" ] \
        && [ -r "$pool_plan_manifest_source" ] \
        || fail 'pool-plan manifest is not a readable regular non-symlink file'
    plan_source_metadata=$(file_metadata "$pool_plan_manifest_source")
    case "$plan_source_metadata" in *:*:600:*:*) ;; *) fail 'pool-plan manifest must be mode 0600' ;; esac
    if [ "$test_mode" = 0 ]; then
        case "$plan_source_metadata" in 0:0:600:*:*) ;; *) fail 'pool-plan manifest must be root-owned in production' ;; esac
    fi
    [ -z "${expected_pool_plan_metadata:-}" ] \
        || [ "$plan_source_metadata" = "$expected_pool_plan_metadata" ] \
        || fail 'pool-plan manifest differs from immutable restore metadata'
    plan_validated_metadata=$plan_source_metadata
    run_safe_read_test_hook after-validation pool-plan-manifest "$pool_plan_manifest_source"

    pool_plan_manifest="$operation_directory/pool-plan.manifest"
    plan_candidate="$operation_directory/.pool-plan.manifest.$$"
    exec 6<"$pool_plan_manifest_source"
    plan_before=$(file_metadata "$pool_plan_manifest_source")
    plan_open=$(open_file_metadata 6)
    cat <&6 > "$plan_candidate"
    plan_after=$(file_metadata "$pool_plan_manifest_source")
    exec 6<&-
    [ "$plan_open" = "$plan_validated_metadata" ] \
        && [ "$plan_before" = "$plan_open" ] && [ "$plan_open" = "$plan_after" ] \
        || fail 'pool-plan manifest changed while it was opened'
    [ "$(checksum "$plan_candidate")" = "$pool_plan_manifest_sha256" ] \
        || fail 'pool-plan manifest differs from its pinned SHA-256'
    if [ -e "$pool_plan_manifest" ]; then
        [ -f "$pool_plan_manifest" ] && [ ! -L "$pool_plan_manifest" ] \
            && [ "$(checksum "$pool_plan_manifest")" = "$pool_plan_manifest_sha256" ] \
            || fail 'durable pool-plan manifest snapshot differs from the requested plan'
        rm -f "$plan_candidate"
    else
        atomic_replace "$plan_candidate" "$pool_plan_manifest" 600
    fi
    plan_identity_file="$operation_directory/.plan-artifact-identities.$$"
    : > "$plan_identity_file"
    chmod 600 "$plan_identity_file"
    printf 'pool_plan_source=%s\n' \
        "$(printf '%s\n' "$plan_validated_metadata" | awk -F: '{print $5 ":" $6}')" \
        >> "$plan_identity_file"

    actual_plan_keys=$(awk -F= '{print $1}' "$pool_plan_manifest")
    [ "$actual_plan_keys" = "$(expected_pool_plan_keys)" ] \
        || fail 'pool-plan manifest keys are unknown, duplicated, absent, or out of canonical order'
    [ "$(plan_value version)" = 2 ] \
        && [ "$(plan_value operation_id)" = "$operation_id" ] \
        && [ "$(plan_value direction)" = "$direction" ] \
        && [ "$(plan_value color)" = "$color" ] \
        && [ "$(plan_value generation)" = "$generation" ] \
        && [ "$(plan_value member_count)" = 2 ] \
        || fail 'pool-plan manifest does not match the exact ingress pool'
    plan_direction=$(plan_value direction)
    case "$plan_direction" in bootstrap-forward|forward|reverse) ;; *) fail 'pool plan direction is invalid' ;; esac
    validate_positive_integer pool-plan-generation "$(plan_value generation)"
    validate_port pool-plan-backend-port "$(plan_value backend_port)"
    validate_token mutation-freeze-epoch "$(plan_value mutation_freeze_epoch)"
    validate_identifier coordination-volume "$(plan_value coordination_volume)"
    validate_identifier pool-label-key "$(plan_value pool_label_key)"
    validate_identifier pool-label-value "$(plan_value pool_label_value)"
    for plan_global_path_key in mutation_freeze_marker_path mutation_lease_path ingress_pool_manifest_path; do
        validate_absolute_path "$plan_global_path_key" "$(plan_value "$plan_global_path_key")"
    done
    [ "$(plan_value route_health_path)" = /api/control-plane/route-health ] \
        || fail 'pool plan route-health path is malformed'
    [ "$pool_plan_manifest_sha256" = "$parent_pool_plan_sha256" ] \
        || fail 'ingress pool does not descend from the supplied pool plan'
    [ "$(plan_value ingress_pool_manifest_path)" = "$pool_manifest_source" ] \
        || fail 'pool plan names a different ingress-pool manifest path'
    [ "$(plan_value route_health_path)" = "$route_health_path" ] \
        && [ "$(plan_value route_health_token_file)" = "$route_health_token_file" ] \
        && [ "$(plan_value route_health_token_sha256)" = "$route_health_token_sha256" ] \
        && [ "$(plan_value route_health_token_metadata)" = "$route_health_token_metadata" ] \
        && [ "$(plan_value pool_ack_file)" = "$pool_ack_file" ] \
        && [ "$(plan_value pool_ack_sha256)" = "$pool_ack_sha256" ] \
        && [ "$(plan_value pool_ack_metadata)" = "$pool_ack_metadata" ] \
        || fail 'pool plan and ingress pool disagree on authenticated route-health artifacts'
    [ "$(plan_value route_health_token_file)" != "$(plan_value route_health_runtime_file)" ] \
        && [ "$(plan_value route_health_token_sha256)" = "$(plan_value route_health_runtime_sha256)" ] \
        && [ "$(plan_value pool_ack_file)" != "$(plan_value pool_ack_runtime_file)" ] \
        && [ "$(plan_value pool_ack_sha256)" = "$(plan_value pool_ack_runtime_sha256)" ] \
        || fail 'pool plan root and runtime artifact pairs are not distinct byte-identical identities'
    plan_route_health_root=$(read_plan_artifact route_health_token route-health-token 8)
    route_health_runtime=$(read_plan_artifact route_health_runtime route-health-runtime 8 400 9999 9999)
    plan_pool_ack_root=$(read_plan_artifact pool_ack pool-ack 8)
    pool_ack_runtime=$(read_plan_artifact pool_ack_runtime pool-ack-runtime 8 400 9999 9999)
    [ "$plan_route_health_root" = "$route_health_token" ] \
        && [ "$route_health_runtime" = "$route_health_token" ] \
        && [ "$plan_pool_ack_root" = "$pool_ack" ] \
        && [ "$pool_ack_runtime" = "$pool_ack" ] \
        || fail 'pool plan runtime route-health artifacts differ from their root copies'
    [ "$(plan_value backend_port)" = "$member_a_port" ] \
        && [ "$(plan_value backend_port)" = "$member_b_port" ] \
        || fail 'pool plan backend port differs from a concrete ingress member port'
    [ "$(plan_value writer_member)" = "$writer_member" ] \
        && [ "$(plan_value pool_label_key)" = "$pool_label_key" ] \
        && [ "$(plan_value pool_label_value)" = "$pool_label_value" ] \
        || fail 'pool plan and ingress pool disagree on writer or pool label identity'
    plan_writer_member=$(plan_value writer_member)
    case "$plan_writer_member" in web-a|web-b) ;; *) fail 'pool plan must declare exactly one writer member' ;; esac
    for member in a b; do
        case "$member" in
            a)
                ingress_role=$member_a_role
                ingress_name=$member_a_name
                ingress_route_identity=$member_a_route_identity
                ingress_image_reference=$member_a_image_reference
                ingress_image_id=$member_a_image_id
                ingress_ack_file=$member_a_applied_ack_file
                ingress_ack_sha256=$member_a_applied_ack_sha256
                ingress_ack_metadata=$member_a_applied_ack_metadata
                ingress_applied_ack=$member_a_applied_ack
                ;;
            b)
                ingress_role=$member_b_role
                ingress_name=$member_b_name
                ingress_route_identity=$member_b_route_identity
                ingress_image_reference=$member_b_image_reference
                ingress_image_id=$member_b_image_id
                ingress_ack_file=$member_b_applied_ack_file
                ingress_ack_sha256=$member_b_applied_ack_sha256
                ingress_ack_metadata=$member_b_applied_ack_metadata
                ingress_applied_ack=$member_b_applied_ack
                ;;
        esac
        [ "$(plan_value "member_${member}_role")" = "$ingress_role" ] \
            && [ "$(plan_value "member_${member}_name")" = "$ingress_name" ] \
            && [ "$(plan_value "member_${member}_route_identity")" = "$ingress_route_identity" ] \
            && [ "$(plan_value "member_${member}_image_reference")" = "$ingress_image_reference" ] \
            && [ "$(plan_value "member_${member}_image_id")" = "$ingress_image_id" ] \
            && [ "$(plan_value "member_${member}_applied_ack_file")" = "$ingress_ack_file" ] \
            && [ "$(plan_value "member_${member}_applied_ack_sha256")" = "$ingress_ack_sha256" ] \
            && [ "$(plan_value "member_${member}_applied_ack_metadata")" = "$ingress_ack_metadata" ] \
            || fail "pool plan and ingress pool disagree on web-$member identity"
        plan_member_role=$(plan_value "member_${member}_role")
        [ "$plan_member_role" = "web-$member" ] \
            || fail 'pool plan member roles are not canonical web-a/web-b'
        validate_identifier "member-$member name" "$(plan_value "member_${member}_name")"
        validate_token "member-$member route identity" \
            "$(plan_value "member_${member}_route_identity")"
        validate_image_reference "member-$member image reference" \
            "$(plan_value "member_${member}_image_reference")"
        plan_member_image_id=$(plan_value "member_${member}_image_id")
        case "$plan_member_image_id" in sha256:*) ;; *) fail "member-$member image ID lacks sha256 prefix" ;; esac
        validate_sha256 "member-$member image ID" "${plan_member_image_id#sha256:}"
        assert_sorted_unique_network_ids "member-$member network plan" \
            "$(plan_value "member_${member}_network_ids")"
        validate_identifier "member-$member private volume" \
            "$(plan_value "member_${member}_private_volume")"
        validate_port "member-$member expected loopback port" \
            "$(plan_value "member_${member}_expected_loopback_port")"
        validate_token "member-$member web epoch" \
            "$(plan_value "member_${member}_web_epoch")"
        drain_epoch=$(plan_value "member_${member}_route_drain_epoch")
        validate_token "member_${member}_route_drain_epoch" "$drain_epoch"
        for plan_member_path_suffix in repin_intent_file web_marker_path route_drain_marker_path; do
            validate_absolute_path "member_${member}_${plan_member_path_suffix}" \
                "$(plan_value "member_${member}_${plan_member_path_suffix}")"
        done
        if [ "$plan_member_role" = "$plan_writer_member" ]; then
            validate_token "member-$member writer epoch" \
                "$(plan_value "member_${member}_writer_epoch")"
            validate_absolute_path "member-$member writer marker" \
                "$(plan_value "member_${member}_writer_marker_path")"
        else
            [ "$(plan_value "member_${member}_writer_epoch")" = absent ] \
                && [ "$(plan_value "member_${member}_writer_marker_path")" = absent ] \
                || fail 'non-writer member has writer marker authority'
        fi
        [ "$(plan_value "member_${member}_direct_probe_token_file")" \
                != "$(plan_value "member_${member}_direct_probe_runtime_file")" ] \
            && [ "$(plan_value "member_${member}_direct_probe_token_sha256")" \
                = "$(plan_value "member_${member}_direct_probe_runtime_sha256")" ] \
            && [ "$(plan_value "member_${member}_applied_ack_file")" \
                != "$(plan_value "member_${member}_applied_ack_runtime_file")" ] \
            && [ "$(plan_value "member_${member}_applied_ack_sha256")" \
                = "$(plan_value "member_${member}_applied_ack_runtime_sha256")" ] \
            || fail "pool plan web-$member root/runtime artifact identity is malformed"
        direct_probe_root=$(read_plan_artifact \
            "member_${member}_direct_probe_token" "web-$member-direct-probe-root" 8)
        direct_probe_runtime=$(read_plan_artifact \
            "member_${member}_direct_probe_runtime" "web-$member-direct-probe-runtime" 8 400 9999 9999)
        applied_ack_root=$(read_plan_artifact \
            "member_${member}_applied_ack" "web-$member-applied-ack-root" 8)
        applied_ack_runtime=$(read_plan_artifact \
            "member_${member}_applied_ack_runtime" "web-$member-applied-ack-runtime" 8 400 9999 9999)
        [ "$direct_probe_root" = "$direct_probe_runtime" ] \
            && [ "$applied_ack_root" = "$ingress_applied_ack" ] \
            && [ "$applied_ack_runtime" = "$ingress_applied_ack" ] \
            || fail "pool plan web-$member runtime artifact bytes differ from their root copies"
        validate_token "member_${member}_direct_probe_token" "$direct_probe_root"
    done
    validate_sha256 pool_plan_set_sha256 "$(plan_value pool_plan_set_sha256)"
    [ "$(compute_pool_plan_set_sha256 | sha256sum | awk '{print $1}')" \
        = "$(plan_value pool_plan_set_sha256)" ] \
        || fail 'pool-plan set SHA-256 does not match its canonical NUL-delimited tuple'
    assert_distinct_values 'pool artifact device/inode identity' \
        "$(plan_artifact_identity pool_plan_source)" \
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
        "$(plan_value member_a_name)" "$(plan_value member_b_name)"
    assert_distinct_values 'pool route identity' \
        "$(plan_value member_a_route_identity)" "$(plan_value member_b_route_identity)"
    assert_distinct_values 'pool loopback port' \
        "$(plan_value member_a_expected_loopback_port)" \
        "$(plan_value member_b_expected_loopback_port)"
    assert_distinct_values 'pool volume authority' \
        "$(plan_value coordination_volume)" \
        "$(plan_value member_a_private_volume)" \
        "$(plan_value member_b_private_volume)"
    assert_distinct_values 'pool direct-probe content' \
        "$(plan_value member_a_direct_probe_token_sha256)" \
        "$(plan_value member_b_direct_probe_token_sha256)"
    assert_distinct_values 'pool applied-ack content' \
        "$(plan_value member_a_applied_ack_sha256)" \
        "$(plan_value member_b_applied_ack_sha256)"
    assert_distinct_values 'pool web epoch' \
        "$(plan_value member_a_web_epoch)" "$(plan_value member_b_web_epoch)"
    assert_distinct_values 'pool route-drain epoch' \
        "$(plan_value member_a_route_drain_epoch)" \
        "$(plan_value member_b_route_drain_epoch)"
    assert_distinct_values 'pool authority path' \
        "$pool_plan_manifest_source" \
        "$(plan_value mutation_freeze_marker_path)" \
        "$(plan_value mutation_lease_path)" \
        "$(plan_value ingress_pool_manifest_path)" \
        "$(plan_value route_health_token_file)" \
        "$(plan_value route_health_runtime_file)" \
        "$(plan_value pool_ack_file)" \
        "$(plan_value pool_ack_runtime_file)" \
        "$(plan_value member_a_repin_intent_file)" \
        "$(plan_value member_b_repin_intent_file)" \
        "$(plan_value member_a_direct_probe_token_file)" \
        "$(plan_value member_b_direct_probe_token_file)" \
        "$(plan_value member_a_direct_probe_runtime_file)" \
        "$(plan_value member_b_direct_probe_runtime_file)" \
        "$(plan_value member_a_applied_ack_file)" \
        "$(plan_value member_b_applied_ack_file)" \
        "$(plan_value member_a_applied_ack_runtime_file)" \
        "$(plan_value member_b_applied_ack_runtime_file)"
    if [ "$plan_writer_member" = web-a ]; then
        plan_writer_marker_authority="$(plan_value member_a_private_volume)|$(plan_value member_a_writer_marker_path)"
    else
        plan_writer_marker_authority="$(plan_value member_b_private_volume)|$(plan_value member_b_writer_marker_path)"
    fi
    assert_distinct_values 'pool private marker authority tuple' \
        "$(plan_value member_a_private_volume)|$(plan_value member_a_web_marker_path)" \
        "$(plan_value member_a_private_volume)|$(plan_value member_a_route_drain_marker_path)" \
        "$(plan_value member_b_private_volume)|$(plan_value member_b_web_marker_path)" \
        "$(plan_value member_b_private_volume)|$(plan_value member_b_route_drain_marker_path)" \
        "$plan_writer_marker_authority"
    retired_count=$(plan_value retired_member_count)
    case "$plan_direction:$retired_count" in
        bootstrap-forward:1|forward:2|reverse:2) ;;
        *) fail 'pool plan retired set contradicts its migration direction' ;;
    esac
    validate_identifier retired-member-a-name "$(plan_value retired_member_a_name)"
    validate_sha256 retired-member-a-id "$(plan_value retired_member_a_id)"
    if [ "$retired_count" = 2 ]; then
        validate_identifier retired-member-b-name "$(plan_value retired_member_b_name)"
        validate_sha256 retired-member-b-id "$(plan_value retired_member_b_id)"
        assert_distinct_values 'retired member name' \
            "$(plan_value retired_member_a_name)" "$(plan_value retired_member_b_name)"
        assert_distinct_values 'retired member ID' \
            "$(plan_value retired_member_a_id)" "$(plan_value retired_member_b_id)"
    fi
    [ "$(plan_value member_a_name)" != "$(plan_value retired_member_a_name)" ] \
        && [ "$(plan_value member_b_name)" != "$(plan_value retired_member_a_name)" ] \
        || fail 'planned member name collides with retired member A'
    if [ "$retired_count" = 2 ]; then
        [ "$(plan_value member_a_name)" != "$(plan_value retired_member_b_name)" ] \
            && [ "$(plan_value member_b_name)" != "$(plan_value retired_member_b_name)" ] \
            || fail 'planned member name collides with retired member B'
    fi
    rm -f "$plan_identity_file"
    plan_identity_file=
}

validate_address()
{
    validate_single_line "$1" "$2"
    python3 -c 'import ipaddress, sys; ipaddress.ip_address(sys.argv[1])' "$2" >/dev/null 2>&1 \
        || fail "$1 must be one canonical numeric IP address"
    canonical_address=$(python3 -c 'import ipaddress, sys; print(ipaddress.ip_address(sys.argv[1]))' "$2")
    [ "$canonical_address" = "$2" ] || fail "$1 is not canonical"
}

validate_image_reference()
{
    validate_single_line "$1" "$2"
    if [ "${#2}" -gt 512 ] \
        || ! printf '%s\n' "$2" \
            | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._/:@-]*@sha256:[a-f0-9]{64}$'; then
        fail "$1 must be an immutable repository digest reference"
    fi
}

assert_safe_directory()
{
    inspected_directory=$1
    expected_mode=$2
    label=$3
    validate_absolute_path "$label" "$inspected_directory"
    [ -d "$inspected_directory" ] && [ ! -L "$inspected_directory" ] \
        || fail "$label is absent or is not a real directory"
    resolved_directory=$(python3 -c 'import os, sys; print(os.path.realpath(sys.argv[1]))' \
        "$inspected_directory")
    [ "$resolved_directory" = "$inspected_directory" ] \
        || fail "$label contains a symlinked path component"
    directory_metadata=$(file_metadata "$inspected_directory")
    case "$directory_metadata" in *:*:"$expected_mode":*:*) ;; *) fail "$label mode changed" ;; esac
    if [ "$test_mode" = 0 ]; then
        case "$directory_metadata" in 0:0:"$expected_mode":*:*) ;; *) fail "$label must be root-owned" ;; esac
    fi
}

attest_proxy_runtime()
{
    proxy_inspect=$(docker inspect "$proxy_container" 2>/dev/null) \
        || fail 'exact Traefik proxy container is unavailable'
    proxy_inventory_count=$(printf '%s\n' "$proxy_inspect" | jq -r 'length')
    [ "$proxy_inventory_count" -eq 1 ] || fail 'Traefik proxy lookup is not singular'
    proxy_id=$(printf '%s\n' "$proxy_inspect" | jq -r '.[0].Id')
    proxy_running=$(printf '%s\n' "$proxy_inspect" | jq -r '.[0].State.Running')
    proxy_pid=$(printf '%s\n' "$proxy_inspect" | jq -r '.[0].State.Pid')
    proxy_started_at=$(printf '%s\n' "$proxy_inspect" | jq -r '.[0].State.StartedAt')
    proxy_restart_count=$(printf '%s\n' "$proxy_inspect" | jq -r '.[0].RestartCount')
    proxy_image_id=$(printf '%s\n' "$proxy_inspect" | jq -r '.[0].Image')
    proxy_runtime_user=$(printf '%s\n' "$proxy_inspect" | jq -r '.[0].Config.User // ""')
    proxy_command_json=$(printf '%s\n' "$proxy_inspect" | jq -c '.[0].Config.Cmd // []')
    proxy_command_sha256=$(printf '%s' "$proxy_command_json" | sha256sum | awk '{print $1}')
    proxy_static_api_json=$(printf '%s\n' "$proxy_inspect" | jq -c '
        [.[0].Config.Cmd[]?
            | select(. == "--api.insecure" or startswith("--api.insecure="))]
    ')
    proxy_static_api_sha256=$(printf '%s' "$proxy_static_api_json" | sha256sum | awk '{print $1}')
    [ "$(printf '%s\n' "$proxy_static_api_json" | jq -r 'length')" -eq 1 ] \
        && [ "$(printf '%s\n' "$proxy_static_api_json" | jq -r '.[0]')" = '--api.insecure=false' ] \
        || fail 'exact Traefik proxy must declare one authoritative --api.insecure=false and no duplicate or true override'
    proxy_config_mount_destination=$(printf '%s\n' "$proxy_inspect" | jq -r --arg source "$dynamic_directory" '
        [.[0].Mounts[] | select(.Source == $source and .RW == false) | .Destination]
        | if length == 1 then .[0] else empty end
    ')
    validate_sha256 proxy-id "$proxy_id"
    validate_sha256 proxy-image-id "${proxy_image_id#sha256:}"
    validate_positive_integer proxy-pid "$proxy_pid"
    validate_positive_integer proxy-restart-count "$((proxy_restart_count + 1))"
    [ "$proxy_running" = true ] || fail 'exact Traefik proxy container is stopped'
    case "$proxy_runtime_user" in ''|0|root) ;; *) fail 'Traefik proxy must own its mounted configuration as root' ;; esac
    validate_absolute_path proxy-config-mount "$proxy_config_mount_destination"
    proxy_runtime_tuple="$proxy_id|$proxy_pid|$proxy_started_at|$proxy_restart_count|$proxy_image_id|$proxy_config_mount_destination|$proxy_command_sha256|$proxy_static_api_sha256"
}

assert_public_proxy_binding()
{
    public_url_identity=$(python3 - "$public_url" <<'PY'
import sys
import urllib.parse

parsed = urllib.parse.urlsplit(sys.argv[1])
if parsed.scheme not in ("http", "https") or not parsed.hostname:
    raise SystemExit(1)
print(f"{parsed.scheme}|{parsed.hostname}|{parsed.port or (443 if parsed.scheme == 'https' else 80)}")
PY
    ) || fail 'public route URL has no canonical TCP port'
    public_url_remainder=${public_url_identity#*|}
    public_url_hostname=${public_url_remainder%%|*}
    public_binding_port=${public_url_remainder##*|}
    validate_port public-route-port "$public_binding_port"
    [ "$public_host_header" = "$control_plane_host" ] \
        || fail 'public route proof must use the exact control-plane Host header'
    [ "$public_url_hostname" = "$control_plane_host" ] \
        || [ "$public_url_hostname" = "$expected_ipv4" ] \
        || fail 'public route URL host differs from both the exact control-plane host and expected IPv4'
    proxy_binding_inventory=$(docker inspect "$proxy_id" | jq -r \
        --arg port "$public_binding_port" --arg expected_ipv4 "$expected_ipv4" '
        [.[0].NetworkSettings.Ports // {} | to_entries[] as $binding
            | $binding.value[]?
            | select(.HostPort == $port)
            | select(.HostIp == $expected_ipv4 or .HostIp == "0.0.0.0")
            | select(.HostIp | contains(":") | not)
            | [$binding.key, .HostIp, .HostPort]]
        | if length == 1 then .[0] | @tsv else empty end
    ')
    [ -n "$proxy_binding_inventory" ] \
        || fail 'expected IPv4 and public port are not singularly published by the exact Traefik proxy'
    proxy_public_container_binding=${proxy_binding_inventory%%	*}
    proxy_binding_remainder=${proxy_binding_inventory#*	}
    proxy_public_host_ip=${proxy_binding_remainder%%	*}
    proxy_public_host_port=${proxy_binding_remainder##*	}
    [ "$proxy_public_host_port" = "$public_binding_port" ] \
        || fail 'exact Traefik public binding port changed during attestation'
    proxy_public_binding_tuple="$proxy_public_container_binding|$proxy_public_host_ip|$proxy_public_host_port|$expected_ipv4"
}

assert_local_ingress_proxy_binding()
{
    case "$local_ingress_url" in
        "http://127.0.0.1:${local_ingress_host_port}/"*) ;;
        *) fail 'local ingress URL must use the exact native Traefik loopback endpoint' ;;
    esac
    proxy_local_binding_inventory=$(docker inspect "$proxy_id" | jq -er \
        --arg host_port "$local_ingress_host_port" '
        .[0].NetworkSettings.Ports["8000/tcp"] as $bindings
        | select(($bindings | type) == "array" and ($bindings | length) == 1)
        | $bindings[0]
        | select(.HostIp == "127.0.0.1" and .HostPort == $host_port)
        | ["8000/tcp", .HostIp, .HostPort]
        | @tsv
    ') || fail 'native Traefik container port must have one exact loopback host binding'
    [ -n "$proxy_local_binding_inventory" ] \
        || fail 'native Traefik loopback port is not singularly published on the configured APP_PORT'
    proxy_local_container_binding=${proxy_local_binding_inventory%%	*}
    proxy_local_binding_remainder=${proxy_local_binding_inventory#*	}
    proxy_local_host_ip=${proxy_local_binding_remainder%%	*}
    proxy_local_host_port=${proxy_local_binding_remainder##*	}
    [ "$proxy_local_host_ip:$proxy_local_host_port" = "127.0.0.1:${local_ingress_host_port}" ] \
        || fail 'native Traefik loopback binding changed during attestation'

    running_container_ids=$(docker ps --no-trunc --quiet) \
        || fail 'could not enumerate running containers for loopback binding ownership proof'
    [ -n "$running_container_ids" ] \
        || fail 'running container inventory is empty during loopback binding ownership proof'
    global_local_binding_inventory=
    while IFS= read -r running_container_id; do
        validate_sha256 running-container-id "$running_container_id"
        running_container_inspect=$(docker inspect "$running_container_id" 2>/dev/null) \
            || fail 'running container disappeared during loopback binding ownership proof'
        [ "$(printf '%s\n' "$running_container_inspect" | jq -r 'length')" -eq 1 ] \
            || fail 'running container lookup was not singular during loopback binding ownership proof'
        running_container_bindings=$(printf '%s\n' "$running_container_inspect" | jq -r \
            --arg container_id "$running_container_id" \
            --arg host_port "$local_ingress_host_port" '
            .[0].NetworkSettings.Ports // {}
            | to_entries[] as $binding
            | $binding.value[]?
            | select($binding.key == "8000/tcp" or .HostPort == $host_port)
            | [$container_id, $binding.key, .HostIp, .HostPort]
            | @tsv
        ') || fail 'could not inspect loopback binding ownership for a running container'
        if [ -n "$running_container_bindings" ]; then
            if [ -n "$global_local_binding_inventory" ]; then
                global_local_binding_inventory="$global_local_binding_inventory
$running_container_bindings"
            else
                global_local_binding_inventory=$running_container_bindings
            fi
        fi
    done <<EOF
$running_container_ids
EOF
    expected_global_local_binding=$(printf '%s\t8000/tcp\t127.0.0.1\t%s' \
        "$proxy_id" "$local_ingress_host_port")
    [ "$global_local_binding_inventory" = "$expected_global_local_binding" ] \
        || fail 'configured APP_PORT or native Traefik container port has another or ambiguous owner'
    proxy_local_binding_tuple="$proxy_local_container_binding|$proxy_local_host_ip|$proxy_local_host_port"
}

write_pinned_curl_config()
{
    curl_config_path=$1
    curl_target_url=$2
    curl_extra_header=${3:-}
    {
        printf 'silent\nshow-error\nmax-time = 5\n'
        printf 'url = "%s"\n' "$curl_target_url"
        printf 'header = "Host: %s"\n' "$public_host_header"
        [ "$test_mode" = 0 ] || printf 'insecure\n'
        [ -z "$curl_extra_header" ] || printf 'header = "%s"\n' "$curl_extra_header"
        [ "$public_url_hostname" = "$expected_ipv4" ] \
            || printf 'resolve = "%s:%s:%s"\n' \
                "$public_url_hostname" "$public_binding_port" "$expected_ipv4"
    } > "$curl_config_path"
    chmod 600 "$curl_config_path"
}

write_local_ingress_curl_config()
{
    curl_config_path=$1
    {
        printf 'silent\nshow-error\nmax-time = 5\n'
        printf 'url = "%s"\n' "$local_ingress_url"
        printf 'header = "Host: %s"\n' "$public_host_header"
    } > "$curl_config_path"
    chmod 600 "$curl_config_path"
}

assert_proxy_config_file()
{
    expected_proxy_route_sha256=$1
    proxy_route_path="$proxy_config_mount_destination/$dynamic_filename"
    proxy_route_identity=$(docker exec "$proxy_id" sh -c \
        'stat -c "%u:%g:%a:%s" "$1" 2>/dev/null || stat -f "%u:%g:%Lp:%z" "$1"' \
        sh "$proxy_route_path" 2>/dev/null) || return 1
    case "$proxy_route_identity" in *:*:600:*) ;; *) return 1 ;; esac
    if [ "$test_mode" = 0 ]; then
        case "$proxy_route_identity" in 0:0:600:*) ;; *) return 1 ;; esac
    fi
    proxy_route_sha256=$(docker exec "$proxy_id" sha256sum "$proxy_route_path" 2>/dev/null \
        | awk '{print $1}') || return 1
    [ "$proxy_route_sha256" = "$expected_proxy_route_sha256" ]
}

write_live_proxy_expectation()
{
    expected_members=$1
    expectation_file=$2
    rule_quote=$(printf '\140')
    {
        printf 'router_key=control-plane-blue-green@file\n'
        printf 'provider_router_key=control-plane-provider-proof@file\n'
        printf 'service_key=control-plane-%s-pool@file\n' "$color"
        printf 'service_name=control-plane-%s-pool\n' "$color"
        printf 'middleware_key=control-plane-route-ack@file\n'
        printf 'route_ack=%s\n' "$route_ack"
        printf 'route_health_path=%s\n' "$route_health_path"
        printf 'route_health_token=%s\n' "$route_health_token"
        printf 'control_plane_host=%s\n' "$control_plane_host"
        printf 'entrypoint=%s\n' "$entrypoint"
        printf 'local_router_key=control-plane-blue-green-local@file\n'
        printf 'local_entrypoint=%s\n' "$local_ingress_entrypoint"
        printf 'router_priority=%s\nprovider_router_priority=%s\n' \
            "$router_priority" "$provider_router_priority"
        printf 'tls=%s\ncert_resolver=%s\n' "$tls" "${cert_resolver:-none}"
        printf 'provider_rule=Host(%s%s%s) && Path(%s/api/rawdata%s) && Header(%sX-Control-Plane-Provider-Proof%s, %s%s%s)\n' \
            "$rule_quote" "$control_plane_host" "$rule_quote" "$rule_quote" "$rule_quote" \
            "$rule_quote" "$rule_quote" "$rule_quote" "$route_health_token" "$rule_quote"
        printf 'router_rule=Host(%s%s%s)\n' "$rule_quote" "$control_plane_host" "$rule_quote"
        printf 'local_router_rule=PathPrefix(%s/%s)\n' "$rule_quote" "$rule_quote"
        printf 'member_a_service=%s@docker\n' "$member_a_docker_service"
        printf 'member_b_service=%s@docker\n' "$member_b_docker_service"
        case "$expected_members" in
            both)
                printf 'weighted_a=%s@docker\nweighted_b=%s@docker\n' \
                    "$member_a_docker_service" "$member_b_docker_service"
                printf 'member_a_status=UP\nmember_b_status=UP\n'
                ;;
            web-a)
                printf 'weighted_a=%s@docker\nweighted_b=absent\n' "$member_a_docker_service"
                printf 'member_a_status=UP\nmember_b_status=DOWN\n'
                ;;
            web-b)
                printf 'weighted_a=%s@docker\nweighted_b=absent\n' "$member_b_docker_service"
                printf 'member_a_status=DOWN\nmember_b_status=UP\n'
                ;;
            drain-pending-web-a)
                printf 'weighted_a=%s@docker\nweighted_b=%s@docker\n' \
                    "$member_a_docker_service" "$member_b_docker_service"
                printf 'member_a_status=DOWN\nmember_b_status=UP\n'
                ;;
            drain-pending-web-b)
                printf 'weighted_a=%s@docker\nweighted_b=%s@docker\n' \
                    "$member_a_docker_service" "$member_b_docker_service"
                printf 'member_a_status=UP\nmember_b_status=DOWN\n'
                ;;
            *) fail 'live proxy expectation names an invalid active member set' ;;
        esac
    } > "$expectation_file"
    chmod 600 "$expectation_file"
}

emit_live_proxy_diagnostic()
{
    [ -s "$raw_api_file" ] || {
        printf 'CONTROL_PLANE_HTTPS_PROVIDER_DIAGNOSTIC api_response=absent proxy_id=%s proxy_pid=%s binding=%s config_sha256=%s\n' \
            "${proxy_id:-absent}" "${proxy_pid:-absent}" \
            "${proxy_public_binding_tuple:-absent}" "${expected_route_sha256:-absent}" >&2
        return
    }
    python3 - "$raw_api_file" "$expectation_file" <<'PY' >&2
import hashlib
import json
import sys

try:
    raw = json.load(open(sys.argv[1], encoding="utf-8"))
    expected = dict(
        line.rstrip("\n").split("=", 1)
        for line in open(sys.argv[2], encoding="ascii")
    )
except Exception as error:
    print(f"CONTROL_PLANE_HTTPS_PROVIDER_DIAGNOSTIC parse_error={type(error).__name__}")
    raise SystemExit(0)

router = raw.get("routers", {}).get(expected.get("router_key"), {})
local_router = raw.get("routers", {}).get(expected.get("local_router_key"), {})
provider = raw.get("routers", {}).get(expected.get("provider_router_key"), {})
service = raw.get("services", {}).get(expected.get("service_key"), {})
health = service.get("loadBalancer", {}).get("healthCheck", {})
def digest(value):
    return hashlib.sha256(str(value).encode()).hexdigest()
print(
    "CONTROL_PLANE_HTTPS_PROVIDER_DIAGNOSTIC "
    f"errors={len(raw.get('errors', []))} "
    f"router_status={router.get('status', 'absent')} "
    f"router_service={router.get('service', 'absent')} "
    f"router_rule_sha256={digest(router.get('rule', 'absent'))} "
    f"router_priority={router.get('priority', 'absent')} "
    f"router_tls={json.dumps(router.get('tls', 'absent'), sort_keys=True)} "
    f"local_router_status={local_router.get('status', 'absent')} "
    f"local_router_service={local_router.get('service', 'absent')} "
    f"local_router_rule_sha256={digest(local_router.get('rule', 'absent'))} "
    f"local_router_priority={local_router.get('priority', 'absent')} "
    f"provider_status={provider.get('status', 'absent')} "
    f"provider_rule_sha256={digest(provider.get('rule', 'absent'))} "
    f"provider_priority={provider.get('priority', 'absent')} "
    f"health_interval={health.get('interval', 'absent')} "
    f"health_unhealthy_interval={health.get('unhealthyInterval', 'absent')} "
    f"health_timeout={health.get('timeout', 'absent')}"
)
PY
}

assert_live_proxy_route()
{
    expected_members=$1
    expected_route_sha256=$2
    raw_api_file="$operation_directory/.traefik-raw-api.$$"
    expectation_file="$operation_directory/.traefik-live-expectation.$$"
    write_live_proxy_expectation "$expected_members" "$expectation_file"
    attest_proxy_runtime
    assert_public_proxy_binding
    captured_public_binding_tuple=$proxy_public_binding_tuple
    assert_local_ingress_proxy_binding
    captured_local_binding_tuple=$proxy_local_binding_tuple
    provider_api_url=$(python3 - "$public_url" <<'PY'
import sys
import urllib.parse

parsed = urllib.parse.urlsplit(sys.argv[1])
print(urllib.parse.urlunsplit((parsed.scheme, parsed.netloc, "/api/rawdata", "", "")))
PY
    ) || fail 'could not derive the protected Traefik provider API URL'
    provider_curl_config="$operation_directory/.traefik-provider-curl.$$"
    write_pinned_curl_config "$provider_curl_config" "$provider_api_url" \
        "X-Control-Plane-Provider-Proof: $route_health_token"
    attempt=0
    trap 'rm -f "$raw_api_file" "$expectation_file" "$provider_curl_config"' EXIT HUP INT TERM
    while [ "$attempt" -lt "$probe_attempts" ]; do
        attest_proxy_runtime
        captured_proxy_tuple=$proxy_runtime_tuple
        if assert_proxy_config_file "$expected_route_sha256" \
            && assert_public_proxy_binding \
            && [ "$proxy_public_binding_tuple" = "$captured_public_binding_tuple" ] \
            && assert_local_ingress_proxy_binding \
            && [ "$proxy_local_binding_tuple" = "$captured_local_binding_tuple" ] \
            && curl --config "$provider_curl_config" > "$raw_api_file" 2>/dev/null \
            && python3 - "$raw_api_file" "$expectation_file" <<'PY'
import json
import re
import sys

try:
    with open(sys.argv[1], encoding="utf-8") as source:
        raw = json.load(source)
except (OSError, json.JSONDecodeError):
    raise SystemExit(1)
expected = dict(line.rstrip("\n").split("=", 1) for line in open(sys.argv[2], encoding="ascii"))
provider_objects = [
    value
    for section in ("routers", "services", "middlewares")
    for value in raw.get(section, {}).values()
    if isinstance(value, dict)
]

def rule_can_serve_api(rule, host, path):
    if not isinstance(rule, str):
        return True

    class RuleParser:
        def __init__(self, source):
            self.source = source
            self.offset = 0

        def whitespace(self):
            while self.offset < len(self.source) and self.source[self.offset].isspace():
                self.offset += 1

        @staticmethod
        def combine(left, right, operator):
            return {
                (left_value and right_value) if operator == "and" else (left_value or right_value)
                for left_value in left
                for right_value in right
            }

        def expression(self):
            result = self.conjunction()
            while True:
                self.whitespace()
                if not self.source.startswith("||", self.offset):
                    return result
                self.offset += 2
                result = self.combine(result, self.conjunction(), "or")

        def conjunction(self):
            result = self.unary()
            while True:
                self.whitespace()
                if not self.source.startswith("&&", self.offset):
                    return result
                self.offset += 2
                result = self.combine(result, self.unary(), "and")

        def unary(self):
            self.whitespace()
            if self.offset < len(self.source) and self.source[self.offset] == "!":
                self.offset += 1
                return {not outcome for outcome in self.unary()}
            if self.offset < len(self.source) and self.source[self.offset] == "(":
                self.offset += 1
                result = self.expression()
                self.whitespace()
                if self.offset >= len(self.source) or self.source[self.offset] != ")":
                    return {True, False}
                self.offset += 1
                return result
            return self.matcher()

        def matcher(self):
            self.whitespace()
            start = self.offset
            while self.offset < len(self.source) and (
                self.source[self.offset].isalnum() or self.source[self.offset] == "_"
            ):
                self.offset += 1
            name = self.source[start:self.offset]
            self.whitespace()
            if not name or self.offset >= len(self.source) or self.source[self.offset] != "(":
                self.offset = len(self.source)
                return {True, False}
            self.offset += 1
            body_start = self.offset
            quote = None
            escaped = False
            while self.offset < len(self.source):
                character = self.source[self.offset]
                if quote == '"':
                    if escaped:
                        escaped = False
                    elif character == "\\":
                        escaped = True
                    elif character == '"':
                        quote = None
                elif quote == "`":
                    if character == "`":
                        quote = None
                elif character in ('"', "`"):
                    quote = character
                elif character == ")":
                    break
                self.offset += 1
            if self.offset >= len(self.source) or quote is not None:
                return {True, False}
            body = self.source[body_start:self.offset]
            self.offset += 1

            def literal_arguments(source):
                arguments = []
                position = 0
                while True:
                    while position < len(source) and source[position].isspace():
                        position += 1
                    if position == len(source):
                        return arguments, bool(arguments)
                    if source[position] == "`":
                        end = source.find("`", position + 1)
                        if end < 0:
                            return [], False
                        arguments.append(source[position + 1:end])
                        position = end + 1
                    elif source[position] == '"':
                        start = position
                        position += 1
                        escaped_character = False
                        while position < len(source):
                            character = source[position]
                            if escaped_character:
                                escaped_character = False
                            elif character == "\\":
                                escaped_character = True
                            elif character == '"':
                                position += 1
                                break
                            position += 1
                        else:
                            return [], False
                        try:
                            arguments.append(json.loads(source[start:position]))
                        except (TypeError, ValueError, json.JSONDecodeError):
                            return [], False
                    else:
                        return [], False
                    while position < len(source) and source[position].isspace():
                        position += 1
                    if position == len(source):
                        return arguments, True
                    if source[position] != ",":
                        return [], False
                    position += 1

            arguments, arguments_complete = literal_arguments(body)
            if not arguments_complete:
                return {True, False}
            if name == "Host":
                normalized_host = host.lower()
                normalized_arguments = [argument.lower() for argument in arguments]
                if normalized_host in normalized_arguments:
                    return {True}
                canonical_label = re.compile(
                    r"^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$"
                )
                def concrete_hostname(argument):
                    labels = argument.split(".")
                    return (
                        len(argument) <= 253
                        and all(canonical_label.fullmatch(label) is not None for label in labels)
                    )
                if all(concrete_hostname(argument) for argument in normalized_arguments):
                    return {False}
                return {True, False}
            if name == "HostRegexp":
                return {True, False}
            if name == "Path":
                return {path in arguments}
            if name == "PathPrefix":
                return {any(path.startswith(prefix) for prefix in arguments)}
            if name == "Method":
                return {"GET" in arguments}
            return {True, False}

    parser = RuleParser(rule)
    outcomes = parser.expression()
    parser.whitespace()
    if parser.offset != len(rule):
        outcomes = {True, False}
    return True in outcomes

router = raw.get("routers", {}).get(expected["router_key"])
local_router = raw.get("routers", {}).get(expected["local_router_key"])
provider_router = raw.get("routers", {}).get(expected["provider_router_key"])
service = raw.get("services", {}).get(expected["service_key"])
middleware = raw.get("middlewares", {}).get(expected["middleware_key"])
member_a_service = raw.get("services", {}).get(expected["member_a_service"])
member_b_service = raw.get("services", {}).get(expected["member_b_service"])
weighted_services = [{"name": expected["weighted_a"], "weight": 1}]
if expected["weighted_b"] != "absent":
    weighted_services.append({"name": expected["weighted_b"], "weight": 1})
valid = (
    isinstance(router, dict)
    and router.get("status") == "enabled"
    and router.get("service") == expected["service_name"]
    and router.get("rule") == expected["router_rule"]
    and router.get("entryPoints") == [expected["entrypoint"]]
    and router.get("middlewares") == [expected["middleware_key"]]
    and router.get("priority") == int(expected["router_priority"])
    and isinstance(local_router, dict)
    and local_router.get("status") == "enabled"
    and local_router.get("service") == expected["service_name"]
    and local_router.get("rule") == expected["local_router_rule"]
    and local_router.get("entryPoints") == [expected["local_entrypoint"]]
    and local_router.get("middlewares") == [expected["middleware_key"]]
    and local_router.get("priority") == int(expected["router_priority"])
    and isinstance(provider_router, dict)
    and provider_router.get("status") == "enabled"
    and provider_router.get("service") == "api@internal"
    and provider_router.get("rule") == expected["provider_rule"]
    and provider_router.get("entryPoints") == [expected["entrypoint"]]
    and provider_router.get("priority") == int(expected["provider_router_priority"])
    and isinstance(service, dict)
    and service.get("status") == "enabled"
    and sorted(service.get("usedBy", [])) == sorted([
        expected["router_key"], expected["local_router_key"],
    ])
    and isinstance(middleware, dict)
    and middleware.get("status") == "enabled"
    and middleware.get("headers", {}).get("customResponseHeaders", {}).get(
        "X-Control-Plane-Route-Ack"
    ) == expected["route_ack"]
    and not raw.get("errors")
    and all(not value.get("error") and value.get("status") != "disabled" for value in provider_objects)
)
expected_tls = expected["tls"] == "true"
expected_cert_resolver = expected["cert_resolver"]
for inspected_router in (router, provider_router):
    inspected_tls = inspected_router.get("tls")
    if expected_tls:
        valid = valid and isinstance(inspected_tls, dict)
        if expected_cert_resolver == "none":
            valid = valid and not inspected_tls.get("certResolver")
        else:
            valid = valid and inspected_tls.get("certResolver") == expected_cert_resolver
    else:
        valid = valid and inspected_tls is None
valid = valid and local_router.get("tls") is None
if not valid:
    raise SystemExit(1)
weighted = service.get("weighted", {})

def docker_service_matches(candidate, expected_status):
    if not isinstance(candidate, dict) or candidate.get("status") != "enabled":
        return False
    load_balancer = candidate.get("loadBalancer", {})
    health = load_balancer.get("healthCheck", {})
    statuses = candidate.get("serverStatus", {})
    return (
        health.get("path") == "/api/control-plane/route-health"
        and health.get("hostname") == expected["control_plane_host"]
        and health.get("method") == "GET"
        and health.get("status") == 204
        and health.get("interval") == "1s"
        and health.get("unhealthyInterval") == "1s"
        and health.get("timeout") == "3s"
        and health.get("followRedirects") is False
        and health.get("headers", {}).get("X-Control-Plane-Route-Health")
            == expected["route_health_token"]
        and len(statuses) == 1
        and next(iter(statuses.values()), None) == expected_status
        and not candidate.get("error")
    )

conflicting_api_router = [
    key
    for key, candidate in raw.get("routers", {}).items()
    if key != expected["provider_router_key"]
    and isinstance(candidate, dict)
    and candidate.get("status") == "enabled"
    and candidate.get("service") == "api@internal"
    and expected["entrypoint"] in candidate.get("entryPoints", [])
    and rule_can_serve_api(candidate.get("rule"), expected["control_plane_host"], "/api/rawdata")
]
member_backend_router = [
    key
    for key, candidate in raw.get("routers", {}).items()
    if isinstance(candidate, dict)
    and candidate.get("status") == "enabled"
    and candidate.get("service") in {
        expected["member_a_service"], expected["member_b_service"],
    }
]
valid = (
    weighted.get("services") == weighted_services
    and isinstance(weighted.get("healthCheck"), dict)
    and docker_service_matches(member_a_service, expected["member_a_status"])
    and docker_service_matches(member_b_service, expected["member_b_status"])
    and not conflicting_api_router
    and not member_backend_router
)
raise SystemExit(0 if valid else 1)
PY
        then
            attest_proxy_runtime
            [ "$proxy_runtime_tuple" = "$captured_proxy_tuple" ] \
                || fail 'exact Traefik proxy process changed during live provider proof'
            assert_public_proxy_binding
            [ "$proxy_public_binding_tuple" = "$captured_public_binding_tuple" ] \
                || fail 'exact Traefik public binding changed during live provider proof'
            assert_local_ingress_proxy_binding
            [ "$proxy_local_binding_tuple" = "$captured_local_binding_tuple" ] \
                || fail 'native Traefik loopback binding changed during live provider proof'
            rm -f "$raw_api_file" "$expectation_file" "$provider_curl_config"
            trap - EXIT HUP INT TERM
            return
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    emit_live_proxy_diagnostic
    fail 'exact Traefik protected provider API did not prove the managed live route'
}

ensure_operation_directory()
{
    create_if_absent=$1
    if [ ! -e "$operation_directory" ]; then
        [ "$create_if_absent" = 1 ] || fail 'operation directory is absent'
        mkdir -p "$operation_directory"
        chmod 700 "$operation_directory"
    fi
    assert_safe_directory "$operation_directory" 700 'operation directory'
}

acquire_operation_lock()
{
    command -v flock >/dev/null 2>&1 || fail 'flock is required for serialized ingress mutation'
    exec 9>"$lock_file"
    chmod 600 "$lock_file"
    flock -n 9 || fail 'another HTTPS controller process holds the operation lock'
}

test_crash()
{
    crash_stage=$1
    [ -z "$test_crash_at" ] || [ "$test_mode" = 1 ] \
        || fail 'crash injection is forbidden outside test mode'
    if [ "$test_mode" = 1 ] && [ "$test_crash_at" = "$crash_stage" ]; then
        kill -9 "$$"
    fi
}

test_provider_health_barrier()
{
    [ -n "$test_provider_health_barrier_directory" ] || return 0
    [ "$test_mode" = 1 ] \
        || fail 'provider-health test barrier is forbidden outside test mode'
    barrier_reached="$test_provider_health_barrier_directory/reached"
    barrier_release="$test_provider_health_barrier_directory/release"
    [ ! -e "$barrier_reached" ] && [ ! -L "$barrier_reached" ] \
        || fail 'provider-health test barrier was already reached'
    touch "$barrier_reached"
    chmod 600 "$barrier_reached"
    barrier_attempt=0
    while [ ! -f "$barrier_release" ] || [ -L "$barrier_release" ]; do
        barrier_attempt=$((barrier_attempt + 1))
        [ "$barrier_attempt" -lt 1200 ] \
            || fail 'provider-health test barrier was not released'
        sleep 0.1
    done
}

compute_member_set_sha256()
{
    printf '%s\0' "$generation" "$parent_pool_plan_sha256" \
        "$member_a_role" "$member_a_name" "$member_a_route_identity" \
        "$member_a_id" "$member_a_address" \
        "$member_a_port" "$member_a_image_reference" "$member_a_image_id" \
        "$member_a_runtime_sha256" "$member_a_network_sha256" "$member_a_bindings_sha256" \
        "$member_a_applied_ack_file" "$member_a_applied_ack_sha256" \
        "$member_a_applied_ack_metadata" "$member_b_role" "$member_b_name" \
        "$member_b_route_identity" "$member_b_id" \
        "$member_b_address" "$member_b_port" "$member_b_image_reference" "$member_b_image_id" \
        "$member_b_runtime_sha256" "$member_b_network_sha256" "$member_b_bindings_sha256" \
        "$member_b_applied_ack_file" "$member_b_applied_ack_sha256" \
        "$member_b_applied_ack_metadata" "$writer_member" "$pool_label_key" "$pool_label_value" \
        | sha256sum | awk '{print $1}'
}

compute_route_ack()
{
    route_identity=$(printf '%s\0' \
        "$pool_manifest_sha256" "$direction" "$color" "$generation" \
        "$parent_pool_plan_sha256" "$member_set_sha256" | sha256sum | awk '{print $1}')
    printf 'cp-pool-v2.%s\n' "$route_identity"
}

compute_drain_route_ack()
{
    drained_role=$1
    drain_epoch=$2
    drain_identity=$(printf '%s\0%s\0%s\0%s\0%s\0%s\0%s\0%s\0' \
        "$pool_manifest_sha256" "$direction" "$color" "$generation" \
        "$parent_pool_plan_sha256" "$member_set_sha256" "$drained_role" "$drain_epoch" \
        | sha256sum | awk '{print $1}')
    printf 'cp-pool-v2-drain.%s\n' "$drain_identity"
}

load_pool_manifest()
{
    pool_manifest_source=$pool_manifest
    require_value CONTROL_PLANE_INGRESS_POOL_MANIFEST "$pool_manifest"
    require_value CONTROL_PLANE_INGRESS_POOL_MANIFEST_SHA256 "$pool_manifest_sha256"
    validate_sha256 CONTROL_PLANE_INGRESS_POOL_MANIFEST_SHA256 "$pool_manifest_sha256"
    validate_absolute_path CONTROL_PLANE_INGRESS_POOL_MANIFEST "$pool_manifest"
    [ -f "$pool_manifest" ] && [ ! -L "$pool_manifest" ] && [ -r "$pool_manifest" ] \
        || fail 'ingress-pool manifest is not a readable regular non-symlink file'
    manifest_metadata=$(file_metadata "$pool_manifest")
    case "$manifest_metadata" in
        *:*:600:*:*) ;;
        *) fail 'ingress-pool manifest must be mode 0600' ;;
    esac
    if [ "$test_mode" = 0 ]; then
        case "$manifest_metadata" in
            0:0:600:*:*) ;;
            *) fail 'ingress-pool manifest must be root-owned mode 0600 in production' ;;
        esac
    fi
    [ -z "${expected_pool_manifest_metadata:-}" ] \
        || [ "$manifest_metadata" = "$expected_pool_manifest_metadata" ] \
        || fail 'ingress-pool manifest differs from immutable restore metadata'
    manifest_validated_metadata=$manifest_metadata
    run_safe_read_test_hook after-validation ingress-pool-manifest "$pool_manifest"
    manifest_snapshot="$operation_directory/ingress-pool.manifest"
    manifest_snapshot_candidate="$operation_directory/.ingress-pool.manifest.$$"
    exec 6<"$pool_manifest"
    manifest_before=$(file_metadata "$pool_manifest")
    manifest_open=$(open_file_metadata 6)
    cat <&6 > "$manifest_snapshot_candidate"
    manifest_after=$(file_metadata "$pool_manifest")
    exec 6<&-
    [ "$manifest_open" = "$manifest_validated_metadata" ] \
        && [ "$manifest_before" = "$manifest_open" ] \
        && [ "$manifest_open" = "$manifest_after" ] \
        || fail 'ingress-pool manifest changed while it was opened'
    [ "$(checksum "$manifest_snapshot_candidate")" = "$pool_manifest_sha256" ] \
        || fail 'ingress-pool manifest differs from its pinned SHA-256'
    if [ -e "$manifest_snapshot" ]; then
        [ -f "$manifest_snapshot" ] && [ ! -L "$manifest_snapshot" ] \
            && [ "$(checksum "$manifest_snapshot")" = "$pool_manifest_sha256" ] \
            || fail 'durable ingress-pool manifest snapshot differs from the requested pool'
        rm -f "$manifest_snapshot_candidate"
    else
        atomic_replace "$manifest_snapshot_candidate" "$manifest_snapshot" 600
    fi
    pool_manifest=$manifest_snapshot

    actual_keys=$(awk -F= '{print $1}' "$pool_manifest")
    [ "$actual_keys" = "$(expected_manifest_keys)" ] \
        || fail 'ingress-pool manifest keys are unknown, duplicated, absent, or out of canonical order'

    version=$(manifest_value version)
    manifest_operation_id=$(manifest_value operation_id)
    direction=$(manifest_value direction)
    manifest_color=$(manifest_value color)
    generation=$(manifest_value generation)
    parent_pool_plan_sha256=$(manifest_value parent_pool_plan_sha256)
    pool_ack_file=$(manifest_value pool_ack_file)
    pool_ack_sha256=$(manifest_value pool_ack_sha256)
    pool_ack_metadata=$(manifest_value pool_ack_metadata)
    member_count=$(manifest_value member_count)
    member_a_role=$(manifest_value member_a_role)
    member_a_name=$(manifest_value member_a_name)
    member_a_route_identity=$(manifest_value member_a_route_identity)
    member_a_id=$(manifest_value member_a_id)
    member_a_address=$(manifest_value member_a_address)
    member_a_port=$(manifest_value member_a_port)
    member_a_image_reference=$(manifest_value member_a_image_reference)
    member_a_image_id=$(manifest_value member_a_image_id)
    member_a_runtime_sha256=$(manifest_value member_a_runtime_sha256)
    member_a_network_sha256=$(manifest_value member_a_network_sha256)
    member_a_bindings_sha256=$(manifest_value member_a_bindings_sha256)
    member_a_applied_ack_file=$(manifest_value member_a_applied_ack_file)
    member_a_applied_ack_sha256=$(manifest_value member_a_applied_ack_sha256)
    member_a_applied_ack_metadata=$(manifest_value member_a_applied_ack_metadata)
    member_b_role=$(manifest_value member_b_role)
    member_b_name=$(manifest_value member_b_name)
    member_b_route_identity=$(manifest_value member_b_route_identity)
    member_b_id=$(manifest_value member_b_id)
    member_b_address=$(manifest_value member_b_address)
    member_b_port=$(manifest_value member_b_port)
    member_b_image_reference=$(manifest_value member_b_image_reference)
    member_b_image_id=$(manifest_value member_b_image_id)
    member_b_runtime_sha256=$(manifest_value member_b_runtime_sha256)
    member_b_network_sha256=$(manifest_value member_b_network_sha256)
    member_b_bindings_sha256=$(manifest_value member_b_bindings_sha256)
    member_b_applied_ack_file=$(manifest_value member_b_applied_ack_file)
    member_b_applied_ack_sha256=$(manifest_value member_b_applied_ack_sha256)
    member_b_applied_ack_metadata=$(manifest_value member_b_applied_ack_metadata)
    writer_member=$(manifest_value writer_member)
    pool_label_key=$(manifest_value pool_label_key)
    pool_label_value=$(manifest_value pool_label_value)
    member_set_sha256=$(manifest_value member_set_sha256)
    route_health_path=$(manifest_value route_health_path)
    route_health_token_file=$(manifest_value route_health_token_file)
    route_health_token_sha256=$(manifest_value route_health_token_sha256)
    route_health_token_metadata=$(manifest_value route_health_token_metadata)

    [ "$version" = 2 ] || fail 'ingress-pool manifest version must be exactly 2'
    [ "$manifest_operation_id" = "$operation_id" ] \
        || fail 'ingress-pool manifest belongs to another operation'
    case "$direction" in bootstrap-forward|forward|reverse) ;; *) fail 'ingress-pool direction is invalid' ;; esac
    case "$manifest_color" in blue|green) ;; *) fail 'ingress-pool color must be blue or green' ;; esac
    [ "$manifest_color" = "$color" ] || fail 'requested color differs from ingress-pool manifest'
    validate_positive_integer generation "$generation"
    [ "$generation" = "$requested_generation" ] \
        || fail 'requested generation differs from ingress-pool manifest'
    validate_sha256 parent_pool_plan_sha256 "$parent_pool_plan_sha256"
    [ "$member_count" = 2 ] || fail 'ingress-pool manifest must contain exactly two members'
    [ "$member_a_role" = web-a ] && [ "$member_b_role" = web-b ] \
        || fail 'ingress-pool members must be canonically ordered web-a then web-b'
    [ "$member_a_name" != "$member_b_name" ] && [ "$member_a_id" != "$member_b_id" ] \
        && [ "$member_a_route_identity" != "$member_b_route_identity" ] \
        && [ "$member_a_route_identity" != "$member_a_id" ] \
        && [ "$member_a_route_identity" != "$member_b_id" ] \
        && [ "$member_b_route_identity" != "$member_a_id" ] \
        && [ "$member_b_route_identity" != "$member_b_id" ] \
        && [ "$member_a_address:$member_a_port" != "$member_b_address:$member_b_port" ] \
        || fail 'ingress-pool members must have distinct names, route identities, IDs, and endpoints'
    validate_identifier member_a_name "$member_a_name"
    validate_identifier member_b_name "$member_b_name"
    validate_token member_a_route_identity "$member_a_route_identity"
    validate_token member_b_route_identity "$member_b_route_identity"
    validate_sha256 member_a_id "$member_a_id"
    validate_sha256 member_b_id "$member_b_id"
    validate_address member_a_address "$member_a_address"
    validate_address member_b_address "$member_b_address"
    validate_port member_a_port "$member_a_port"
    validate_port member_b_port "$member_b_port"
    validate_image_reference member_a_image_reference "$member_a_image_reference"
    validate_image_reference member_b_image_reference "$member_b_image_reference"
    case "$member_a_image_id" in sha256:*) ;; *) fail 'member_a_image_id must use the sha256: prefix' ;; esac
    case "$member_b_image_id" in sha256:*) ;; *) fail 'member_b_image_id must use the sha256: prefix' ;; esac
    validate_sha256 member_a_image_id "${member_a_image_id#sha256:}"
    validate_sha256 member_a_runtime_sha256 "$member_a_runtime_sha256"
    validate_sha256 member_a_network_sha256 "$member_a_network_sha256"
    validate_sha256 member_a_bindings_sha256 "$member_a_bindings_sha256"
    validate_sha256 member_b_image_id "${member_b_image_id#sha256:}"
    validate_sha256 member_b_runtime_sha256 "$member_b_runtime_sha256"
    validate_sha256 member_b_network_sha256 "$member_b_network_sha256"
    validate_sha256 member_b_bindings_sha256 "$member_b_bindings_sha256"
    validate_sha256 pool_ack_sha256 "$pool_ack_sha256"
    validate_sha256 member_a_applied_ack_sha256 "$member_a_applied_ack_sha256"
    validate_sha256 member_b_applied_ack_sha256 "$member_b_applied_ack_sha256"
    validate_sha256 member_set_sha256 "$member_set_sha256"
    validate_sha256 route_health_token_sha256 "$route_health_token_sha256"
    case "$writer_member" in web-a|web-b) ;; *) fail 'writer_member must identify exactly one pool member' ;; esac
    validate_identifier pool_label_key "$pool_label_key"
    validate_identifier pool_label_value "$pool_label_value"
    [ "$route_health_path" = /api/control-plane/route-health ] \
        || fail 'route_health_path must be the authenticated route-health endpoint'
    [ "$(compute_member_set_sha256)" = "$member_set_sha256" ] \
        || fail 'member_set_sha256 does not match the canonical ordered member tuple'

    pool_ack=$(read_attested_file "$pool_ack_file" "$pool_ack_sha256" "$pool_ack_metadata" 'pool acknowledgement' 7)
    member_a_applied_ack=$(read_attested_file "$member_a_applied_ack_file" \
        "$member_a_applied_ack_sha256" "$member_a_applied_ack_metadata" 'web-a applied acknowledgement' 7)
    member_b_applied_ack=$(read_attested_file "$member_b_applied_ack_file" \
        "$member_b_applied_ack_sha256" "$member_b_applied_ack_metadata" 'web-b applied acknowledgement' 7)
    route_health_token=$(read_attested_file "$route_health_token_file" \
        "$route_health_token_sha256" "$route_health_token_metadata" 'route-health token' 7)
    validate_token pool_ack "$pool_ack"
    validate_token member_a_applied_ack "$member_a_applied_ack"
    validate_token member_b_applied_ack "$member_b_applied_ack"
    validate_token route_health_token "$route_health_token"
    member_a_docker_service="control-plane-${color}-${member_a_role}"
    member_b_docker_service="control-plane-${color}-${member_b_role}"
    validate_identifier member_a_docker_service "$member_a_docker_service"
    validate_identifier member_b_docker_service "$member_b_docker_service"
    route_ack=$(compute_route_ack)
}

inspect_response_header()
{
    response_header_file=$1
    response_header_name=$2
    response_header_name_lower=$(printf '%s' "$response_header_name" | LC_ALL=C tr '[:upper:]' '[:lower:]')
    response_header_count=0
    response_header_value=
    response_header_folded=0
    response_header_previous_target=0
    response_header_tab=$(printf '\t')
    response_header_cr=$(printf '\r')

    while IFS= read -r response_header_line || [ -n "$response_header_line" ]; do
        case "$response_header_line" in *"$response_header_cr") response_header_line=${response_header_line%"$response_header_cr"} ;; esac
        case "$response_header_line" in
            ' '*|"$response_header_tab"*)
                [ "$response_header_previous_target" -eq 0 ] || response_header_folded=1
                continue
                ;;
            *) response_header_previous_target=0 ;;
        esac
        case "$response_header_line" in *:*) ;; *) continue ;; esac
        response_header_actual_name=${response_header_line%%:*}
        response_header_actual_name_lower=$(printf '%s' "$response_header_actual_name" | LC_ALL=C tr '[:upper:]' '[:lower:]')
        [ "$response_header_actual_name_lower" = "$response_header_name_lower" ] || continue
        response_header_count=$((response_header_count + 1))
        response_header_previous_target=1
        response_header_value=${response_header_line#*:}
        while :; do
            case "$response_header_value" in
                ' '*) response_header_value=${response_header_value#' '} ;;
                "$response_header_tab"*) response_header_value=${response_header_value#"$response_header_tab"} ;;
                *) break ;;
            esac
        done
    done < "$response_header_file"
}

has_single_exact_response_header()
{
    inspect_response_header "$1" "$2"
    [ "$response_header_count" -eq 1 ] && [ "$response_header_folded" -eq 0 ] \
        && [ "$response_header_value" = "$3" ]
}

member_url()
{
    case "$1" in
        *:*) printf 'http://[%s]:%s%s\n' "$1" "$2" "$route_health_path" ;;
        *) printf 'http://%s:%s%s\n' "$1" "$2" "$route_health_path" ;;
    esac
}

write_route_probe_request()
{
    request_path=$1
    {
        printf 'GET %s HTTP/1.1\r\n' "$route_health_path"
        printf 'Host: %s\r\n' "$control_plane_host"
        printf 'X-Control-Plane-Route-Health: %s\r\n' "$route_health_token"
        printf 'Connection: close\r\n\r\n'
    } > "$request_path"
    chmod 600 "$request_path"
}

probe_member()
{
    role=$1
    address=$2
    port=$3
    expected_member=$4
    expected_applied_ack=$5
    response="$operation_directory/.${role}-response.$$"
    request="$operation_directory/.${role}-request.$$"
    attempt=0
    write_route_probe_request "$request"
    trap 'rm -f "$response" "$request"' EXIT HUP INT TERM
    while [ "$attempt" -lt "$probe_attempts" ]; do
        rm -f "$response"
        attest_proxy_runtime
        member_proxy_tuple=$proxy_runtime_tuple
        status=0
        docker exec -i "$proxy_id" nc -w 5 "$address" "$port" \
            < "$request" > "$response" 2>/dev/null || status=$?
        http_status=$(head -n 1 "$response" 2>/dev/null | tr -d '\r' | awk '{print $2}')
        if [ "$status" -eq 0 ] && [ "$http_status" = 204 ] \
            && has_single_exact_response_header "$response" X-Control-Plane-Applied-Config "$expected_applied_ack" \
            && has_single_exact_response_header "$response" X-Control-Plane-Pool-Ack "$pool_ack" \
            && has_single_exact_response_header "$response" X-Control-Plane-Member "$expected_member"; then
            attest_proxy_runtime
            [ "$proxy_runtime_tuple" = "$member_proxy_tuple" ] \
                || fail 'exact Traefik proxy changed during direct member proof'
            rm -f "$response" "$request"
            trap - EXIT HUP INT TERM
            return
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    fail "$role did not prove exact authenticated route-health identity (endpoint=$address:$port transport=$status http=${http_status:-absent} request_sha256=$(checksum "$request"))"
}

probe_draining_member()
{
    role=$1
    address=$2
    port=$3
    expected_member=$4
    expected_applied_ack=$5
    expected_drain_epoch=$6
    response="$operation_directory/.${role}-drain-response.$$"
    request="$operation_directory/.${role}-drain-request.$$"
    attempt=0
    write_route_probe_request "$request"
    trap 'rm -f "$response" "$request"' EXIT HUP INT TERM
    while [ "$attempt" -lt "$probe_attempts" ]; do
        rm -f "$response"
        attest_proxy_runtime
        member_proxy_tuple=$proxy_runtime_tuple
        status=0
        docker exec -i "$proxy_id" nc -w 5 "$address" "$port" \
            < "$request" > "$response" 2>/dev/null || status=$?
        http_status=$(head -n 1 "$response" 2>/dev/null | tr -d '\r' | awk '{print $2}')
        if [ "$status" -eq 0 ] && [ "$http_status" = 503 ] \
            && has_single_exact_response_header "$response" X-Control-Plane-Applied-Config "$expected_applied_ack" \
            && has_single_exact_response_header "$response" X-Control-Plane-Pool-Ack "$pool_ack" \
            && has_single_exact_response_header "$response" X-Control-Plane-Member "$expected_member" \
            && has_single_exact_response_header "$response" X-Control-Plane-Route-State draining \
            && has_single_exact_response_header "$response" X-Control-Plane-Route-Drain-Epoch "$expected_drain_epoch"; then
            attest_proxy_runtime
            [ "$proxy_runtime_tuple" = "$member_proxy_tuple" ] \
                || fail 'exact Traefik proxy changed during draining member proof'
            rm -f "$response" "$request"
            trap - EXIT HUP INT TERM
            return
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    fail "$role did not prove its exact authenticated generation-bound drain state (transport=$status http=${http_status:-absent})"
}

assert_member_docker_service_contract()
{
    member_role=$1
    member_name=$2
    member_id=$3
    member_address=$4
    member_port=$5
    member_service=$6
    member_router="${member_service}-discovery"
    member_rule="Host(\`${member_service}.invalid\`) && !Host(\`${member_service}.invalid\`)"
    member_inspect=$(docker inspect "$member_id" 2>/dev/null) \
        || fail "$member_role Docker-provider service container is unavailable"
    printf '%s\n' "$member_inspect" | jq --exit-status \
        --arg id "$member_id" \
        --arg name "/$member_name" \
        --arg address "$member_address" \
        --arg port "$member_port" \
        --arg service "$member_service" \
        --arg router "$member_router" \
        --arg rule "$member_rule" \
        --arg entrypoint "$local_ingress_entrypoint" \
        --arg host "$control_plane_host" \
        --arg token "$route_health_token" '
        length == 1
        and (.[0] as $container
        | ($container.Config.Labels // {}) as $labels
        | ("traefik.http.routers." + $router + ".") as $router_prefix
        | ("traefik.http.services." + $service + ".loadbalancer.") as $service_prefix
        | ($labels["traefik.docker.network"] // "") as $network
        | ($container.Id == $id)
        and ($container.Name == $name)
        and ($container.State.Running == true)
        and ($container.State.Paused == false)
        and ($container.State.Restarting == false)
        and ($container.State.Dead == false)
        and ($network | length > 0)
        and ($container.NetworkSettings.Networks[$network] != null)
        and ([$container.NetworkSettings.Networks[$network].IPAddress,
            $container.NetworkSettings.Networks[$network].GlobalIPv6Address]
            | map(select(type == "string" and length > 0))
            | index($address) != null)
        and ($labels["traefik.enable"] == "true")
        and ($labels[$router_prefix + "rule"] == $rule)
        and ($labels[$router_prefix + "entrypoints"] == $entrypoint)
        and ($labels[$router_prefix + "service"] == $service)
        and ($labels[$router_prefix + "priority"] == "1")
        and ($labels[$service_prefix + "server.port"] == $port)
        and ($labels[$service_prefix + "server.scheme"] == "http")
        and ($labels[$service_prefix + "passhostheader"] == "true")
        and ($labels[$service_prefix + "healthcheck.path"] == "/api/control-plane/route-health")
        and ($labels[$service_prefix + "healthcheck.hostname"] == $host)
        and ($labels[$service_prefix + "healthcheck.headers.X-Control-Plane-Route-Health"] == $token)
        and ($labels[$service_prefix + "healthcheck.method"] == "GET")
        and ($labels[$service_prefix + "healthcheck.status"] == "204")
        and ($labels[$service_prefix + "healthcheck.interval"] == "1s")
        and ($labels[$service_prefix + "healthcheck.unhealthyinterval"] == "1s")
        and ($labels[$service_prefix + "healthcheck.timeout"] == "3s")
        and ($labels[$service_prefix + "healthcheck.followredirects"] == "false")
        and ([$labels | keys[] | select(startswith("traefik.http.routers."))] | sort) == ([
            $router_prefix + "entrypoints",
            $router_prefix + "priority",
            $router_prefix + "rule",
            $router_prefix + "service"
        ] | sort)
        and ([$labels | keys[] | select(startswith("traefik.http.services."))] | sort) == ([
            $service_prefix + "healthcheck.followredirects",
            $service_prefix + "healthcheck.headers.X-Control-Plane-Route-Health",
            $service_prefix + "healthcheck.hostname",
            $service_prefix + "healthcheck.interval",
            $service_prefix + "healthcheck.method",
            $service_prefix + "healthcheck.path",
            $service_prefix + "healthcheck.status",
            $service_prefix + "healthcheck.timeout",
            $service_prefix + "healthcheck.unhealthyinterval",
            $service_prefix + "passhostheader",
            $service_prefix + "server.port",
            $service_prefix + "server.scheme"
        ] | sort))
    ' >/dev/null || fail "$member_role Docker-provider service labels differ from the exact authenticated backend contract"
}

assert_member_docker_services()
{
    assert_member_docker_service_contract web-a "$member_a_name" "$member_a_id" \
        "$member_a_address" "$member_a_port" "$member_a_docker_service"
    assert_member_docker_service_contract web-b "$member_b_name" "$member_b_id" \
        "$member_b_address" "$member_b_port" "$member_b_docker_service"
}

prove_pool()
{
    assert_member_docker_services
    probe_member web-a "$member_a_address" "$member_a_port" \
        "$member_a_route_identity" "$member_a_applied_ack"
    probe_member web-b "$member_b_address" "$member_b_port" \
        "$member_b_route_identity" "$member_b_applied_ack"
}

wait_for_member_provider_health()
{
    provider_health_file="$operation_directory/.traefik-provider-health.$$"
    attempt=0
    trap 'rm -f "$provider_health_file"' EXIT HUP INT TERM
    while [ "$attempt" -lt "$probe_attempts" ]; do
        attest_proxy_runtime
        provider_health_proxy_tuple=$proxy_runtime_tuple
        rm -f "$provider_health_file"
        provider_health_curl_status=0
        if [ "$test_mode" = 1 ]; then
            [ -z "$provider_header_file" ] \
                || fail 'lab provider-health retrieval does not accept a host-only header file'
            docker exec "$proxy_id" wget -qO- "$provider_api_url" \
                > "$provider_health_file" 2>/dev/null \
                || provider_health_curl_status=$?
        elif [ -n "$provider_header_file" ]; then
            nsenter --target "$proxy_pid" --net -- \
                curl --fail --silent --show-error --max-time 5 \
                --header "@$provider_header_file" "$provider_api_url" \
                > "$provider_health_file" 2>/dev/null \
                || provider_health_curl_status=$?
        else
            nsenter --target "$proxy_pid" --net -- \
                curl --fail --silent --show-error --max-time 5 "$provider_api_url" \
                > "$provider_health_file" 2>/dev/null \
                || provider_health_curl_status=$?
        fi
        if [ "$provider_health_curl_status" -eq 0 ] \
            && python3 - "$provider_health_file" \
                "${member_a_docker_service}@docker" \
                "${member_b_docker_service}@docker" \
                "$member_a_address" "$member_a_port" \
                "$member_b_address" "$member_b_port" \
                "$control_plane_host" "$route_health_token" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as source:
    raw = json.load(source)

expected_host = sys.argv[8]
expected_token = sys.argv[9]

def backend_origin(address, port):
    authority = f"[{address}]" if ":" in address else address
    return f"http://{authority}:{port}"

def service_ready(key, address, port):
    service = raw.get("services", {}).get(key)
    if not isinstance(service, dict) or service.get("status") != "enabled" or service.get("error"):
        return False
    load_balancer = service.get("loadBalancer", {})
    health = load_balancer.get("healthCheck", {})
    statuses = service.get("serverStatus", {})
    expected_origin = backend_origin(address, port)
    return (
        load_balancer.get("servers") == [{"url": expected_origin}]
        and health.get("path") == "/api/control-plane/route-health"
        and health.get("hostname") == expected_host
        and health.get("method") == "GET"
        and health.get("status") == 204
        and health.get("interval") == "1s"
        and health.get("unhealthyInterval") == "1s"
        and health.get("timeout") == "3s"
        and health.get("followRedirects") is False
        and health.get("headers", {}).get("X-Control-Plane-Route-Health") == expected_token
        and statuses == {expected_origin: "UP"}
    )

ready = (
    service_ready(sys.argv[2], sys.argv[4], sys.argv[5])
    and service_ready(sys.argv[3], sys.argv[6], sys.argv[7])
)
raise SystemExit(0 if ready else 1)
PY
        then
            assert_member_docker_services
            attest_proxy_runtime
            [ "$proxy_runtime_tuple" = "$provider_health_proxy_tuple" ] \
                || fail 'exact Traefik proxy process changed during candidate provider health proof'
            rm -f "$provider_health_file"
            trap - EXIT HUP INT TERM
            return
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    fail 'Traefik Docker provider did not mark both candidate pool members healthy before route publication'
}

state_value()
{
    key=$1
    value=$(sed -n "s/^${key}=//p" "$state_file")
    [ "$(awk -F= -v expected_key="$key" \
        '$1 == expected_key { count++ } END { print count + 0 }' "$state_file")" -eq 1 ] \
        || fail "state key is absent or duplicated: $key"
    printf '%s\n' "$value"
}

is_managed_route()
{
    inspected_route=${1:-$route_file}
    [ -f "$inspected_route" ] && [ ! -L "$inspected_route" ] \
        && grep -F -x -q '# control-plane-ingress-pool-version: 2' "$inspected_route" \
        && grep -F -x -q "# control-plane-operation-id: $operation_id" "$inspected_route"
}

is_any_managed_v2_route()
{
    inspected_route=$1
    [ -f "$inspected_route" ] && [ ! -L "$inspected_route" ] \
        && grep -F -x -q '# control-plane-ingress-pool-version: 2' "$inspected_route"
}

managed_route_comment_value()
{
    inspected_route=$1
    comment_name=$2
    comment_prefix="# control-plane-${comment_name}: "
    comment_value=$(awk -v prefix="$comment_prefix" '
        index($0, prefix) == 1 {
            count++
            value = substr($0, length(prefix) + 1)
        }
        END {
            if (count != 1) {
                exit 1
            }
            print value
        }
    ' "$inspected_route") \
        || fail "managed HTTPS predecessor route comment is absent or duplicated: $comment_name"
    validate_single_line "managed HTTPS predecessor $comment_name" "$comment_value"
    printf '%s\n' "$comment_value"
}

managed_route_ack_value()
{
    inspected_route=$1
    ack_prefix='          X-Control-Plane-Route-Ack: "'
    ack_value=$(awk -v prefix="$ack_prefix" '
        index($0, "X-Control-Plane-Route-Ack:") > 0 {
            total++
            if (index($0, prefix) == 1 && substr($0, length($0), 1) == "\"") {
                exact++
                value = substr($0, length(prefix) + 1, length($0) - length(prefix) - 1)
            }
        }
        END {
            if (total != 1 || exact != 1) {
                exit 1
            }
            print value
        }
    ' "$inspected_route") \
        || fail 'managed HTTPS predecessor route acknowledgement is absent, duplicated, or malformed'
    validate_token managed-HTTPS-predecessor-route-ack "$ack_value"
    printf '%s\n' "$ack_value"
}

write_predecessor_identity()
{
    printf 'predecessor_operation_id=%s\n' "$predecessor_operation_id"
    printf 'predecessor_manifest_sha256=%s\n' "$predecessor_manifest_sha256"
    printf 'predecessor_pool_plan_sha256=%s\n' "$predecessor_pool_plan_sha256"
    printf 'predecessor_color=%s\n' "$predecessor_color"
    printf 'predecessor_generation=%s\n' "$predecessor_generation"
}

assert_persisted_predecessor_identity()
{
    predecessor_state=$1
    predecessor_state_label=$2
    [ "$managed_predecessor_requested" = 1 ] \
        || fail "$predecessor_state_label requires the exact managed predecessor inputs"
    [ "$(state_value_from "$predecessor_state" predecessor_operation_id)" \
            = "$predecessor_operation_id" ] \
        && [ "$(state_value_from "$predecessor_state" predecessor_manifest_sha256)" \
            = "$predecessor_manifest_sha256" ] \
        && [ "$(state_value_from "$predecessor_state" predecessor_pool_plan_sha256)" \
            = "$predecessor_pool_plan_sha256" ] \
        && [ "$(state_value_from "$predecessor_state" predecessor_color)" \
            = "$predecessor_color" ] \
        && [ "$(state_value_from "$predecessor_state" predecessor_generation)" \
            = "$predecessor_generation" ] \
        || fail "$predecessor_state_label differs from the requested managed predecessor"
}

assert_managed_predecessor_route()
{
    inspected_route=$1
    if ! { [ -f "$inspected_route" ] && [ ! -L "$inspected_route" ] \
        && is_any_managed_v2_route "$inspected_route"; }; then
        fail 'managed HTTPS predecessor route is absent or unsafe'
    fi
    route_predecessor_operation_id=$(managed_route_comment_value \
        "$inspected_route" operation-id)
    route_predecessor_manifest_sha256=$(managed_route_comment_value \
        "$inspected_route" manifest-sha256)
    route_predecessor_pool_plan_sha256=$(managed_route_comment_value \
        "$inspected_route" parent-pool-plan-sha256)
    route_predecessor_direction=$(managed_route_comment_value "$inspected_route" direction)
    route_predecessor_color=$(managed_route_comment_value "$inspected_route" color)
    route_predecessor_generation=$(managed_route_comment_value "$inspected_route" generation)
    route_predecessor_ack=$(managed_route_ack_value "$inspected_route")
    validate_identifier managed-HTTPS-predecessor-operation-id "$route_predecessor_operation_id"
    validate_sha256 managed-HTTPS-predecessor-manifest-sha256 \
        "$route_predecessor_manifest_sha256"
    validate_sha256 managed-HTTPS-predecessor-pool-plan-sha256 \
        "$route_predecessor_pool_plan_sha256"
    validate_positive_integer managed-HTTPS-predecessor-generation \
        "$route_predecessor_generation"
    case "$route_predecessor_direction" in
        bootstrap-forward|forward) ;;
        *) fail 'managed HTTPS predecessor route is not a forward route' ;;
    esac
    [ "$route_predecessor_operation_id" = "$predecessor_operation_id" ] \
        && [ "$route_predecessor_manifest_sha256" = "$predecessor_manifest_sha256" ] \
        && [ "$route_predecessor_pool_plan_sha256" = "$predecessor_pool_plan_sha256" ] \
        && [ "$route_predecessor_color" = "$predecessor_color" ] \
        && [ "$route_predecessor_generation" = "$predecessor_generation" ] \
        || fail 'managed HTTPS predecessor route differs from the exact predecessor identity'
}

assert_requested_managed_route()
{
    inspected_route=$1
    is_managed_route "$inspected_route" \
        || fail 'current managed HTTPS route belongs to another operation'
    [ "$(managed_route_comment_value "$inspected_route" manifest-sha256)" \
            = "$pool_manifest_sha256" ] \
        && [ "$(managed_route_comment_value "$inspected_route" parent-pool-plan-sha256)" \
            = "$parent_pool_plan_sha256" ] \
        && [ "$(managed_route_comment_value "$inspected_route" direction)" = "$direction" ] \
        && [ "$(managed_route_comment_value "$inspected_route" color)" = "$color" ] \
        && [ "$(managed_route_comment_value "$inspected_route" generation)" = "$generation" ] \
        && [ "$(managed_route_ack_value "$inspected_route")" = "$route_ack" ] \
        || fail 'current managed HTTPS route differs from the exact requested ingress pool'
}

rollback_backup_kind()
{
    inspected_rollback_state=$1
    backup_kind_count=$(awk -F= '$1 == "backup_kind" { count++ } END { print count + 0 }' \
        "$inspected_rollback_state")
    case "$backup_kind_count" in
        0)
            case "$(state_value_from "$inspected_rollback_state" backup_status)" in
                present) printf '%s\n' legacy ;;
                absent) printf '%s\n' absent ;;
                *) fail 'durable HTTPS rollback state has no valid backup kind' ;;
            esac
            ;;
        1) state_value_from "$inspected_rollback_state" backup_kind ;;
        *) fail 'durable HTTPS rollback state contains duplicate backup kind' ;;
    esac
}

assert_reverse_predecessor_context()
{
    [ "$managed_predecessor_requested" = 1 ] || return 0
    [ "$direction" = reverse ] \
        || fail 'managed HTTPS predecessor handoff is allowed only for a reverse ingress pool'
    [ "$color" != "$predecessor_color" ] \
        || fail 'reverse ingress pool color must differ from its managed predecessor'
}

assert_managed_predecessor_backup()
{
    inspected_rollback_state=$1
    assert_persisted_predecessor_identity "$inspected_rollback_state" \
        'durable HTTPS rollback predecessor identity'
    managed_backup_checksum=$(state_value_from "$inspected_rollback_state" backup_checksum)
    validate_sha256 managed-HTTPS-predecessor-backup-sha256 "$managed_backup_checksum"
    [ -f "$backup_file" ] && [ ! -L "$backup_file" ] \
        && [ "$(checksum "$backup_file")" = "$managed_backup_checksum" ] \
        || fail 'durable managed HTTPS predecessor backup changed'
    assert_managed_predecessor_route "$backup_file"
}

atomic_replace()
{
    candidate=$1
    destination=$2
    mode=$3
    chmod "$mode" "$candidate"
    sync "$candidate"
    mv -f "$candidate" "$destination"
    sync "$(dirname "$destination")"
}

prepare()
{
    assert_safe_directory "$operation_directory" 700 'operation directory'
    if [ -f "$rollback_state_file" ]; then
        [ "$(state_value_from "$rollback_state_file" version)" = 2 ] \
            && [ "$(state_value_from "$rollback_state_file" operation_id)" = "$operation_id" ] \
            || fail 'durable HTTPS rollback state belongs to another controller generation'
        rollback_status=$(state_value_from "$rollback_state_file" backup_status)
        rollback_checksum=$(state_value_from "$rollback_state_file" backup_checksum)
        backup_kind=$(rollback_backup_kind "$rollback_state_file")
        case "$rollback_status" in
            present)
                case "$backup_kind" in
                    legacy)
                        [ "$managed_predecessor_requested" = 0 ] \
                            || fail 'durable HTTPS rollback state is legacy, not the requested managed predecessor'
                        if [ ! -f "$backup_file" ] \
                            || [ "$(checksum "$backup_file")" != "$rollback_checksum" ] \
                            || is_any_managed_v2_route "$backup_file"; then
                            fail 'durable HTTPS rollback backup changed or is another managed v2 route'
                        fi
                        ;;
                    managed-v2)
                        assert_managed_predecessor_backup "$rollback_state_file"
                        ;;
                    *) fail 'durable HTTPS rollback backup kind is invalid' ;;
                esac
                ;;
            absent)
                [ "$backup_kind" = absent ] \
                    || fail 'absent HTTPS rollback state has an invalid backup kind'
                [ "$managed_predecessor_requested" = 0 ] \
                    || fail 'managed HTTPS predecessor route was absent during durable prepare'
                [ ! -e "$backup_file" ] || fail 'unexpected HTTPS rollback backup exists'
                ;;
            *) fail 'durable HTTPS rollback state is invalid' ;;
        esac
        return
    fi
    rollback_candidate="$operation_directory/.rollback-state.$$"
    if [ -e "$route_file" ]; then
        [ -f "$route_file" ] && [ ! -L "$route_file" ] || fail 'existing HTTPS route is unsafe'
        if [ "$managed_predecessor_requested" = 1 ]; then
            assert_reverse_predecessor_context
            assert_managed_predecessor_route "$route_file"
            original_checksum=$(checksum "$route_file")
            cp -p "$route_file" "$backup_file"
            backup_checksum=$(checksum "$backup_file")
            [ "$backup_checksum" = "$original_checksum" ] \
                && [ "$(checksum "$route_file")" = "$original_checksum" ] \
                || fail 'managed HTTPS predecessor route changed while it was captured'
            assert_managed_predecessor_route "$backup_file"
            backup_kind=managed-v2
        else
            ! is_any_managed_v2_route "$route_file" \
                || fail 'refusing to capture another managed v2 route as legacy rollback state'
            cp -p "$route_file" "$backup_file"
            backup_checksum=$(checksum "$backup_file")
            original_checksum=$(checksum "$route_file")
            backup_kind=legacy
        fi
        backup_status=present
    else
        [ "$managed_predecessor_requested" = 0 ] \
            || fail 'managed HTTPS predecessor route is absent'
        backup_status=absent
        backup_kind=absent
        backup_checksum=none
        original_checksum=absent
    fi
    {
        printf 'version=2\n'
        printf 'operation_id=%s\n' "$operation_id"
        printf 'backup_status=%s\n' "$backup_status"
        printf 'backup_kind=%s\n' "$backup_kind"
        printf 'backup_checksum=%s\n' "$backup_checksum"
        printf 'original_checksum=%s\n' "$original_checksum"
        [ "$backup_kind" != managed-v2 ] || write_predecessor_identity
    } > "$rollback_candidate"
    atomic_replace "$rollback_candidate" "$rollback_state_file" 600
}

capture_legacy_public_fingerprint()
{
    required_legacy_public_ack_proof=${1:-}
    legacy_headers="$operation_directory/.legacy-public-headers.$$"
    legacy_body="$operation_directory/.legacy-public-body.$$"
    legacy_status_file="$operation_directory/.legacy-public-status.$$"
    legacy_curl_config="$operation_directory/.legacy-public-curl.$$"
    attempt=0
    while [ "$attempt" -lt "$probe_attempts" ]; do
        rm -f "$legacy_headers" "$legacy_body" "$legacy_status_file"
        attest_proxy_runtime
        legacy_proxy_tuple=$proxy_runtime_tuple
        assert_public_proxy_binding
        legacy_public_binding_tuple=$proxy_public_binding_tuple
        write_pinned_curl_config "$legacy_curl_config" "$public_url"
        status=0
        curl --config "$legacy_curl_config" --silent --show-error --max-time 5 \
            --dump-header "$legacy_headers" --output "$legacy_body" \
            --write-out '%{http_code}' > "$legacy_status_file" || status=$?
        legacy_public_status=$(cat "$legacy_status_file" 2>/dev/null || true)
        case "$legacy_public_status" in 2??) legacy_status_valid=1 ;; *) legacy_status_valid=0 ;; esac
        if [ "$status" -eq 0 ] && [ "$legacy_status_valid" -eq 1 ]; then
            legacy_public_body_sha256=$(checksum "$legacy_body")
            inspect_response_header "$legacy_headers" X-Control-Plane-Route-Ack
            [ "$response_header_count" -le 1 ] && [ "$response_header_folded" -eq 0 ] \
                || fail 'legacy public response contains an ambiguous route acknowledgement'
            if [ "$response_header_count" -eq 1 ]; then
                legacy_public_response_ack=$response_header_value
                validate_token legacy-public-response-ack "$legacy_public_response_ack"
            else
                legacy_public_response_ack=absent
            fi
            legacy_public_ack_proof="cp-legacy.$(printf '%s\0' \
                "$operation_id" "$backup_status" "$backup_checksum" "$original_checksum" \
                "$public_url" "$public_host_header" "$expected_ipv4" \
                "$legacy_public_binding_tuple" "$legacy_proxy_tuple" "$legacy_public_status" \
                "$legacy_public_body_sha256" "$legacy_public_response_ack" \
                | sha256sum | awk '{print $1}')"
            if [ -z "$required_legacy_public_ack_proof" ] \
                || [ "$legacy_public_ack_proof" = "$required_legacy_public_ack_proof" ]; then
                attest_proxy_runtime
                [ "$proxy_runtime_tuple" = "$legacy_proxy_tuple" ] \
                    || fail 'exact Traefik proxy changed during legacy public proof'
                assert_public_proxy_binding
                [ "$proxy_public_binding_tuple" = "$legacy_public_binding_tuple" ] \
                    || fail 'exact Traefik public binding changed during legacy public proof'
                rm -f "$legacy_headers" "$legacy_body" "$legacy_status_file" "$legacy_curl_config"
                return
            fi
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    observed_ack_sha256=absent
    [ "${legacy_public_response_ack:-absent}" = absent ] \
        || observed_ack_sha256=$(printf '%s' "$legacy_public_response_ack" | sha256sum | awk '{print $1}')
    expected_ack_sha256=absent
    [ "${expected_legacy_public_response_ack:-absent}" = absent ] \
        || expected_ack_sha256=$(printf '%s' "$expected_legacy_public_response_ack" | sha256sum | awk '{print $1}')
    printf '%s\n' \
        "CONTROL_PLANE_HTTPS_LEGACY_DIAGNOSTIC proxy_id=${proxy_id:-absent} proxy_pid=${proxy_pid:-absent} binding=${proxy_public_binding_tuple:-absent} route_sha256=$( [ -f "$route_file" ] && checksum "$route_file" || printf absent ) public_status=${legacy_public_status:-absent} body_sha256=${legacy_public_body_sha256:-absent} ack_sha256=$observed_ack_sha256 expected_status=${expected_legacy_public_status:-capture} expected_body_sha256=${expected_legacy_public_body_sha256:-capture} expected_ack_sha256=$expected_ack_sha256 provider_api=not-installed-in-exact-legacy-route" >&2
    rm -f "$legacy_headers" "$legacy_body" "$legacy_status_file" "$legacy_curl_config"
    fail 'legacy public route did not produce a stable success response through the exact proxy'
}

capture_managed_predecessor_public_ack()
{
    required_managed_predecessor_public_ack_proof=${1:-}
    assert_managed_predecessor_route "$backup_file"
    expected_managed_predecessor_route_ack=$route_predecessor_ack
    managed_predecessor_headers="$operation_directory/.managed-predecessor-public-headers.$$"
    managed_predecessor_curl_config="$operation_directory/.managed-predecessor-public-curl.$$"
    write_pinned_curl_config "$managed_predecessor_curl_config" "$public_url"
    attempt=0
    trap 'rm -f "$managed_predecessor_headers" "$managed_predecessor_curl_config"' \
        EXIT HUP INT TERM
    while [ "$attempt" -lt "$probe_attempts" ]; do
        rm -f "$managed_predecessor_headers"
        attest_proxy_runtime
        managed_predecessor_proxy_tuple=$proxy_runtime_tuple
        assert_public_proxy_binding
        managed_predecessor_public_binding_tuple=$proxy_public_binding_tuple
        status=0
        curl --config "$managed_predecessor_curl_config" --fail \
            --dump-header "$managed_predecessor_headers" --output /dev/null || status=$?
        if [ "$status" -eq 0 ] \
            && has_single_exact_response_header "$managed_predecessor_headers" \
                X-Control-Plane-Route-Ack "$expected_managed_predecessor_route_ack"; then
            managed_predecessor_public_ack_proof="cp-managed-v2.$(printf '%s\0' \
                "$operation_id" "$backup_checksum" "$predecessor_operation_id" \
                "$predecessor_manifest_sha256" "$predecessor_pool_plan_sha256" \
                "$predecessor_color" "$predecessor_generation" \
                "$expected_managed_predecessor_route_ack" "$public_url" \
                "$public_host_header" "$expected_ipv4" \
                "$managed_predecessor_public_binding_tuple" "$managed_predecessor_proxy_tuple" \
                | sha256sum | awk '{print $1}')"
            if [ -z "$required_managed_predecessor_public_ack_proof" ] \
                || [ "$managed_predecessor_public_ack_proof" \
                    = "$required_managed_predecessor_public_ack_proof" ]; then
                attest_proxy_runtime
                [ "$proxy_runtime_tuple" = "$managed_predecessor_proxy_tuple" ] \
                    || fail 'exact Traefik proxy changed during managed predecessor public proof'
                assert_public_proxy_binding
                [ "$proxy_public_binding_tuple" = "$managed_predecessor_public_binding_tuple" ] \
                    || fail 'exact Traefik public binding changed during managed predecessor public proof'
                rm -f "$managed_predecessor_headers" "$managed_predecessor_curl_config"
                trap - EXIT HUP INT TERM
                return
            fi
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    rm -f "$managed_predecessor_headers" "$managed_predecessor_curl_config"
    fail 'managed HTTPS predecessor did not produce its exact public route acknowledgement'
}

write_lineage_state()
{
    lineage_status_count=$(awk -F= '$1 == "lineage_status" { count++ } END { print count + 0 }' \
        "$rollback_state_file")
    case "$lineage_status_count" in
        0) lineage_status=unbound ;;
        1) lineage_status=$(state_value_from "$rollback_state_file" lineage_status) ;;
        *) fail 'rollback state contains duplicate lineage status' ;;
    esac
    if [ "$lineage_status" = bound ]; then
        load_restore_lineage
        lineage_sha256=$(checksum "$lineage_file")
        return
    fi
    case "$lineage_status" in unbound|installing) ;; *) fail 'rollback lineage transition is invalid' ;; esac
    backup_status=$(state_value_from "$rollback_state_file" backup_status)
    backup_checksum=$(state_value_from "$rollback_state_file" backup_checksum)
    original_checksum=$(state_value_from "$rollback_state_file" original_checksum)
    backup_kind=$(rollback_backup_kind "$rollback_state_file")
    current_rollback_checksum=absent
    [ ! -e "$route_file" ] || current_rollback_checksum=$(checksum "$route_file")
    [ "$current_rollback_checksum" = "$original_checksum" ] \
        || fail 'unbound rollback lineage requires the exact captured route to remain installed'
    attest_proxy_runtime
    assert_public_proxy_binding
    assert_local_ingress_proxy_binding
    case "$backup_kind" in
        legacy|absent)
            [ "$managed_predecessor_requested" = 0 ] \
                || fail 'legacy HTTPS rollback lineage cannot bind managed predecessor inputs'
            capture_legacy_public_fingerprint
            ;;
        managed-v2)
            assert_reverse_predecessor_context
            [ "$backup_status" = present ] \
                || fail 'managed HTTPS predecessor rollback must contain a backup'
            assert_managed_predecessor_backup "$rollback_state_file"
            capture_managed_predecessor_public_ack
            ;;
        *) fail 'HTTPS rollback lineage has an invalid backup kind' ;;
    esac
    lineage_candidate="$operation_directory/.lineage.$$"
    {
        printf 'version=1\noperation_id=%s\n' "$operation_id"
        printf 'backup_kind=%s\n' "$backup_kind"
        printf 'pool_manifest=%s\npool_manifest_sha256=%s\npool_manifest_metadata=%s\n' \
            "$pool_manifest_source" "$pool_manifest_sha256" "$manifest_validated_metadata"
        printf 'pool_plan_manifest=%s\npool_plan_manifest_sha256=%s\npool_plan_manifest_metadata=%s\n' \
            "$pool_plan_manifest_source" "$pool_plan_manifest_sha256" "$plan_validated_metadata"
        printf 'color=%s\ngeneration=%s\n' "$color" "$generation"
        printf 'control_plane_host=%s\nentrypoint=%s\ntls=%s\ncert_resolver=%s\nrouter_priority=%s\n' \
            "$control_plane_host" "$entrypoint" "$tls" "${cert_resolver:-none}" "$router_priority"
        printf 'proxy_container=%s\nproxy_id=%s\nproxy_pid=%s\nproxy_started_at=%s\n' \
            "$proxy_container" "$proxy_id" "$proxy_pid" "$proxy_started_at"
        printf 'proxy_restart_count=%s\nproxy_image_id=%s\nproxy_config_mount_destination=%s\n' \
            "$proxy_restart_count" "$proxy_image_id" "$proxy_config_mount_destination"
        printf 'proxy_command_sha256=%s\nproxy_static_api_sha256=%s\n' \
            "$proxy_command_sha256" "$proxy_static_api_sha256"
        printf 'public_url=%s\npublic_host_header=%s\nexpected_ipv4=%s\n' \
            "$public_url" "$public_host_header" "$expected_ipv4"
        printf 'proxy_public_binding_tuple=%s\n' "$proxy_public_binding_tuple"
        printf 'local_ingress_url=%s\nlocal_ingress_entrypoint=%s\nproxy_local_binding_tuple=%s\n' \
            "$local_ingress_url" "$local_ingress_entrypoint" "$proxy_local_binding_tuple"
        case "$backup_kind" in
            managed-v2)
                write_predecessor_identity
                printf 'managed_predecessor_route_ack=%s\n' \
                    "$expected_managed_predecessor_route_ack"
                printf 'managed_predecessor_public_ack_proof=%s\n' \
                    "$managed_predecessor_public_ack_proof"
                ;;
            legacy|absent)
                printf 'legacy_public_status=%s\nlegacy_public_body_sha256=%s\n' \
                    "$legacy_public_status" "$legacy_public_body_sha256"
                printf 'legacy_public_response_ack=%s\nlegacy_public_ack_proof=%s\n' \
                    "$legacy_public_response_ack" "$legacy_public_ack_proof"
                ;;
        esac
    } > "$lineage_candidate"
    chmod 600 "$lineage_candidate"
    lineage_sha256=$(checksum "$lineage_candidate")
    if [ "$lineage_status" = unbound ]; then
        bind_rollback_lineage installing
        lineage_status=installing
    else
        [ "$(state_value_from "$rollback_state_file" lineage_file)" = "$lineage_file" ] \
            && [ "$(state_value_from "$rollback_state_file" lineage_sha256)" = "$lineage_sha256" ] \
            || fail 'installing rollback lineage differs from its durable binding intent'
    fi
    test_crash after-lineage-bind-intent
    if [ -e "$lineage_file" ]; then
        [ -f "$lineage_file" ] && [ ! -L "$lineage_file" ] \
            && [ "$(checksum "$lineage_file")" = "$(checksum "$lineage_candidate")" ] \
            || fail 'durable HTTPS lineage differs from the exact requested generation'
        rm -f "$lineage_candidate"
    else
        atomic_replace "$lineage_candidate" "$lineage_file" 600
    fi
    lineage_sha256=$(checksum "$lineage_file")
    test_crash after-lineage-state
    bind_rollback_lineage bound
}

bind_rollback_lineage()
{
    binding_status=${1:-bound}
    case "$binding_status" in installing|bound) ;; *) fail 'rollback lineage binding status is invalid' ;; esac
    rollback_status=$(state_value_from "$rollback_state_file" backup_status)
    backup_kind=$(rollback_backup_kind "$rollback_state_file")
    rollback_checksum=$(state_value_from "$rollback_state_file" backup_checksum)
    original_checksum=$(state_value_from "$rollback_state_file" original_checksum)
    if [ "$backup_kind" = managed-v2 ]; then
        assert_persisted_predecessor_identity "$rollback_state_file" \
            'durable HTTPS rollback predecessor identity'
    fi
    rollback_candidate="$operation_directory/.rollback-lineage.$$"
    {
        printf 'version=2\noperation_id=%s\n' "$operation_id"
        printf 'backup_status=%s\nbackup_kind=%s\nbackup_checksum=%s\noriginal_checksum=%s\n' \
            "$rollback_status" "$backup_kind" "$rollback_checksum" "$original_checksum"
        [ "$backup_kind" != managed-v2 ] || write_predecessor_identity
        printf 'lineage_status=%s\nlineage_file=%s\nlineage_sha256=%s\n' \
            "$binding_status" "$lineage_file" "$lineage_sha256"
    } > "$rollback_candidate"
    atomic_replace "$rollback_candidate" "$rollback_state_file" 600
}

load_restore_lineage()
{
    [ -f "$rollback_state_file" ] && [ ! -L "$rollback_state_file" ] \
        || fail 'HTTPS rollback state was not prepared'
    [ "$(state_value_from "$rollback_state_file" lineage_status)" = bound ] \
        || fail 'HTTPS rollback lineage is not durably bound'
    bound_lineage_file=$(state_value_from "$rollback_state_file" lineage_file)
    bound_lineage_sha256=$(state_value_from "$rollback_state_file" lineage_sha256)
    [ "$bound_lineage_file" = "$lineage_file" ] \
        || fail 'rollback state names a different immutable lineage file'
    validate_sha256 rollback-lineage-sha256 "$bound_lineage_sha256"
    [ -f "$lineage_file" ] && [ ! -L "$lineage_file" ] \
        && [ "$(checksum "$lineage_file")" = "$bound_lineage_sha256" ] \
        || fail 'immutable HTTPS rollback lineage is absent or changed'
    lineage_metadata=$(file_metadata "$lineage_file")
    case "$lineage_metadata" in *:*:600:*:*) ;; *) fail 'immutable HTTPS lineage mode is unsafe' ;; esac
    if [ "$test_mode" = 0 ]; then
        case "$lineage_metadata" in 0:0:600:*:*) ;; *) fail 'immutable HTTPS lineage must be root-owned' ;; esac
    fi
    [ "$(state_value_from "$lineage_file" version)" = 1 ] \
        && [ "$(state_value_from "$lineage_file" operation_id)" = "$operation_id" ] \
        || fail 'immutable HTTPS lineage belongs to another operation'
    backup_kind=$(rollback_backup_kind "$rollback_state_file")
    lineage_backup_kind_count=$(awk -F= \
        '$1 == "backup_kind" { count++ } END { print count + 0 }' "$lineage_file")
    case "$lineage_backup_kind_count" in
        0)
            case "$backup_kind" in
                legacy|absent) lineage_backup_kind=$backup_kind ;;
                *) fail 'immutable managed HTTPS lineage has no backup kind' ;;
            esac
            ;;
        1) lineage_backup_kind=$(state_value_from "$lineage_file" backup_kind) ;;
        *) fail 'immutable HTTPS lineage contains duplicate backup kind' ;;
    esac
    [ "$lineage_backup_kind" = "$backup_kind" ] \
        || fail 'immutable HTTPS lineage backup kind differs from rollback state'
    case "$backup_kind" in
        managed-v2)
            [ "$(state_value_from "$rollback_state_file" backup_status)" = present ] \
                || fail 'managed HTTPS predecessor lineage has no rollback backup'
            assert_managed_predecessor_backup "$rollback_state_file"
            assert_persisted_predecessor_identity "$lineage_file" \
                'immutable HTTPS lineage predecessor identity'
            expected_managed_predecessor_route_ack=$(state_value_from \
                "$lineage_file" managed_predecessor_route_ack)
            expected_managed_predecessor_public_ack_proof=$(state_value_from \
                "$lineage_file" managed_predecessor_public_ack_proof)
            validate_token managed-HTTPS-predecessor-lineage-route-ack \
                "$expected_managed_predecessor_route_ack"
            validate_token managed-HTTPS-predecessor-public-ack-proof \
                "$expected_managed_predecessor_public_ack_proof"
            assert_managed_predecessor_route "$backup_file"
            [ "$route_predecessor_ack" = "$expected_managed_predecessor_route_ack" ] \
                || fail 'immutable HTTPS predecessor route acknowledgement changed'
            ;;
        legacy|absent)
            [ "$managed_predecessor_requested" = 0 ] \
                || fail 'immutable HTTPS lineage is legacy, not the requested managed predecessor'
            ;;
        *) fail 'immutable HTTPS lineage backup kind is invalid' ;;
    esac
    restored_pool_manifest=$(state_value_from "$lineage_file" pool_manifest)
    restored_pool_manifest_sha256=$(state_value_from "$lineage_file" pool_manifest_sha256)
    restored_pool_plan=$(state_value_from "$lineage_file" pool_plan_manifest)
    restored_pool_plan_sha256=$(state_value_from "$lineage_file" pool_plan_manifest_sha256)
    requested_restore_pool_manifest=${pool_manifest_source:-$pool_manifest}
    [ -z "$requested_restore_pool_manifest" ] \
        || [ "$requested_restore_pool_manifest" = "$restored_pool_manifest" ] \
        || fail 'restore caller ingress manifest differs from immutable lineage'
    [ -z "$pool_manifest_sha256" ] || [ "$pool_manifest_sha256" = "$restored_pool_manifest_sha256" ] \
        || fail 'restore caller ingress hash differs from immutable lineage'
    [ -z "$pool_plan_manifest_source" ] || [ "$pool_plan_manifest_source" = "$restored_pool_plan" ] \
        || fail 'restore caller pool plan differs from immutable lineage'
    [ -z "$pool_plan_manifest_sha256" ] || [ "$pool_plan_manifest_sha256" = "$restored_pool_plan_sha256" ] \
        || fail 'restore caller pool-plan hash differs from immutable lineage'
    pool_manifest=$restored_pool_manifest
    pool_manifest_sha256=$restored_pool_manifest_sha256
    pool_plan_manifest_source=$restored_pool_plan
    pool_plan_manifest_sha256=$restored_pool_plan_sha256
    expected_pool_manifest_metadata=$(state_value_from "$lineage_file" pool_manifest_metadata)
    expected_pool_plan_metadata=$(state_value_from "$lineage_file" pool_plan_manifest_metadata)
    lineage_color=$(state_value_from "$lineage_file" color)
    lineage_generation=$(state_value_from "$lineage_file" generation)
    lineage_control_plane_host=$(state_value_from "$lineage_file" control_plane_host)
    lineage_entrypoint=$(state_value_from "$lineage_file" entrypoint)
    lineage_local_ingress_url=$(state_value_from "$lineage_file" local_ingress_url)
    lineage_local_ingress_entrypoint=$(state_value_from "$lineage_file" local_ingress_entrypoint)
    lineage_tls=$(state_value_from "$lineage_file" tls)
    restored_cert_resolver=$(state_value_from "$lineage_file" cert_resolver)
    lineage_router_priority=$(state_value_from "$lineage_file" router_priority)
    requested_lineage_color=$color
    case "$action:$requested_lineage_color" in restore:legacy|ack:legacy) requested_lineage_color= ;; esac
    [ -z "$requested_lineage_color" ] || [ "$requested_lineage_color" = "$lineage_color" ] \
        || fail 'caller color differs from immutable HTTPS lineage'
    [ -z "$requested_generation" ] || [ "$requested_generation" = "$lineage_generation" ] \
        || fail 'caller generation differs from immutable HTTPS lineage'
    [ -z "$control_plane_host" ] || [ "$control_plane_host" = "$lineage_control_plane_host" ] \
        || fail 'caller host differs from immutable HTTPS lineage'
    [ -z "$entrypoint" ] || [ "$entrypoint" = "$lineage_entrypoint" ] \
        || fail 'caller entrypoint differs from immutable HTTPS lineage'
    [ -z "$local_ingress_url" ] || [ "$local_ingress_url" = "$lineage_local_ingress_url" ] \
        || fail 'caller loopback URL differs from immutable ingress lineage'
    [ -z "$local_ingress_entrypoint" ] \
        || [ "$local_ingress_entrypoint" = "$lineage_local_ingress_entrypoint" ] \
        || fail 'caller loopback entrypoint differs from immutable ingress lineage'
    [ -z "$tls" ] || [ "$tls" = "$lineage_tls" ] \
        || fail 'caller TLS setting differs from immutable HTTPS lineage'
    requested_cert_resolver=${cert_resolver:-none}
    [ "$requested_cert_resolver" = "$restored_cert_resolver" ] \
        || fail 'caller certificate resolver differs from immutable HTTPS lineage'
    [ -z "$router_priority" ] || [ "$router_priority" = "$lineage_router_priority" ] \
        || fail 'caller router priority differs from immutable HTTPS lineage'
    color=$lineage_color
    requested_generation=$lineage_generation
    control_plane_host=$lineage_control_plane_host
    entrypoint=$lineage_entrypoint
    local_ingress_url=$lineage_local_ingress_url
    local_ingress_entrypoint=$lineage_local_ingress_entrypoint
    tls=$lineage_tls
    case "$restored_cert_resolver" in none) cert_resolver= ;; *) cert_resolver=$restored_cert_resolver ;; esac
    router_priority=$lineage_router_priority
    lineage_proxy_container=$(state_value_from "$lineage_file" proxy_container)
    [ -z "$proxy_container" ] || [ "$proxy_container" = "$lineage_proxy_container" ] \
        || fail 'restore caller proxy differs from immutable lineage'
    proxy_container=$lineage_proxy_container
    lineage_public_url=$(state_value_from "$lineage_file" public_url)
    lineage_public_host_header=$(state_value_from "$lineage_file" public_host_header)
    lineage_expected_ipv4=$(state_value_from "$lineage_file" expected_ipv4)
    [ -z "$public_url" ] || [ "$public_url" = "$lineage_public_url" ] \
        || fail 'restore caller public URL differs from immutable lineage'
    [ -z "$public_host_header" ] || [ "$public_host_header" = "$lineage_public_host_header" ] \
        || fail 'restore caller public Host differs from immutable lineage'
    [ "$expected_ipv4" = "$lineage_expected_ipv4" ] \
        || fail 'restore caller expected IPv4 differs from immutable lineage'
    public_url=$lineage_public_url
    public_host_header=$lineage_public_host_header
    if [ "$backup_kind" != managed-v2 ]; then
        expected_legacy_public_status=$(state_value_from "$lineage_file" legacy_public_status)
        expected_legacy_public_body_sha256=$(state_value_from "$lineage_file" legacy_public_body_sha256)
        expected_legacy_public_response_ack=$(state_value_from "$lineage_file" legacy_public_response_ack)
        expected_legacy_public_ack_proof=$(state_value_from "$lineage_file" legacy_public_ack_proof)
    fi
    load_pool_manifest
    load_pool_plan
    assert_reverse_predecessor_context
    attest_proxy_runtime
    [ "$proxy_id" = "$(state_value_from "$lineage_file" proxy_id)" ] \
        && [ "$proxy_pid" = "$(state_value_from "$lineage_file" proxy_pid)" ] \
        && [ "$proxy_started_at" = "$(state_value_from "$lineage_file" proxy_started_at)" ] \
        && [ "$proxy_restart_count" = "$(state_value_from "$lineage_file" proxy_restart_count)" ] \
        && [ "$proxy_image_id" = "$(state_value_from "$lineage_file" proxy_image_id)" ] \
        && [ "$proxy_config_mount_destination" \
            = "$(state_value_from "$lineage_file" proxy_config_mount_destination)" ] \
        && [ "$proxy_command_sha256" = "$(state_value_from "$lineage_file" proxy_command_sha256)" ] \
        && [ "$proxy_static_api_sha256" = "$(state_value_from "$lineage_file" proxy_static_api_sha256)" ] \
        || fail 'exact Traefik proxy differs from immutable rollback lineage'
    assert_public_proxy_binding
    [ "$proxy_public_binding_tuple" = "$(state_value_from "$lineage_file" proxy_public_binding_tuple)" ] \
        || fail 'exact Traefik public binding differs from immutable rollback lineage'
    assert_local_ingress_proxy_binding
    [ "$proxy_local_binding_tuple" = "$(state_value_from "$lineage_file" proxy_local_binding_tuple)" ] \
        || fail 'native Traefik loopback binding differs from immutable rollback lineage'
}

state_value_from()
{
    inspected_state=$1
    key=$2
    value=$(sed -n "s/^${key}=//p" "$inspected_state")
    [ "$(awk -F= -v expected_key="$key" \
        '$1 == expected_key { count++ } END { print count + 0 }' "$inspected_state")" -eq 1 ] \
        || fail "rollback state key is absent or duplicated: $key"
    printf '%s\n' "$value"
}

assert_route_switchable()
{
    target_route_sha256=$1
    [ -f "$rollback_state_file" ] || fail 'HTTPS rollback state was not prepared'
    original_checksum=$(state_value_from "$rollback_state_file" original_checksum)
    current_checksum=absent
    [ ! -e "$route_file" ] || current_checksum=$(checksum "$route_file")
    case "$original_checksum" in
        absent)
            [ "$current_checksum" = absent ] \
                || { [ "$current_checksum" = "$target_route_sha256" ] && is_managed_route; } \
                || fail 'route-written recovery differs from the exact managed target'
            ;;
        *)
            [ "$current_checksum" = "$original_checksum" ] \
                || { [ "$current_checksum" = "$target_route_sha256" ] && is_managed_route; } \
                || fail 'HTTPS route differs from both the captured legacy route and exact managed target'
            ;;
    esac
}

render_route()
{
    candidate=$1
    active_members=${2:-both}
    rule_quote=$(printf '\140')
    {
        printf '# control-plane-ingress-pool-version: 2\n'
        printf '# control-plane-operation-id: %s\n' "$operation_id"
        printf '# control-plane-manifest-sha256: %s\n' "$pool_manifest_sha256"
        printf '# control-plane-parent-pool-plan-sha256: %s\n' "$parent_pool_plan_sha256"
        printf '# control-plane-direction: %s\n' "$direction"
        printf '# control-plane-color: %s\n' "$color"
        printf '# control-plane-generation: %s\n' "$generation"
        printf '# control-plane-member-set-sha256: %s\n' "$member_set_sha256"
        printf '# control-plane-active-members: %s\n' "$active_members"
        printf 'http:\n'
        printf '  routers:\n'
        printf '    control-plane-blue-green:\n'
        printf '%s%s%s\n' '      rule: "Host(`' "$control_plane_host" '`)"'
        printf '      entryPoints:\n'
        printf '        - %s\n' "$entrypoint"
        printf '      service: control-plane-%s-pool\n' "$color"
        printf '      priority: %s\n' "$router_priority"
        printf '      middlewares:\n'
        printf '        - control-plane-route-ack\n'
        if [ "$tls" = true ]; then
            if [ -n "$cert_resolver" ]; then
                printf '      tls:\n'
                printf '        certResolver: %s\n' "$cert_resolver"
            else
                printf '      tls: {}\n'
            fi
        fi
        printf '    control-plane-blue-green-local:\n'
        printf '      rule: "PathPrefix(%s/%s)"\n' "$rule_quote" "$rule_quote"
        printf '      entryPoints:\n'
        printf '        - %s\n' "$local_ingress_entrypoint"
        printf '      service: control-plane-%s-pool\n' "$color"
        printf '      priority: %s\n' "$router_priority"
        printf '      middlewares:\n'
        printf '        - control-plane-route-ack\n'
        printf '    control-plane-provider-proof:\n'
        printf '      rule: "Host(%s%s%s) && Path(%s/api/rawdata%s) && Header(%sX-Control-Plane-Provider-Proof%s, %s%s%s)"\n' \
            "$rule_quote" "$control_plane_host" "$rule_quote" "$rule_quote" "$rule_quote" \
            "$rule_quote" "$rule_quote" "$rule_quote" "$route_health_token" "$rule_quote"
        printf '      entryPoints:\n'
        printf '        - %s\n' "$entrypoint"
        printf '      service: api@internal\n'
        printf '      priority: %s\n' "$provider_router_priority"
        if [ "$tls" = true ]; then
            if [ -n "$cert_resolver" ]; then
                printf '      tls:\n'
                printf '        certResolver: %s\n' "$cert_resolver"
            else
                printf '      tls: {}\n'
            fi
        fi
        printf '  middlewares:\n'
        printf '    control-plane-route-ack:\n'
        printf '      headers:\n'
        printf '        customResponseHeaders:\n'
        printf '          X-Control-Plane-Route-Ack: "%s"\n' "$route_ack"
        printf '  services:\n'
        printf '    control-plane-%s-pool:\n' "$color"
        printf '      weighted:\n'
        printf '        healthCheck: {}\n'
        printf '        services:\n'
        if [ "$active_members" = both ] || [ "$active_members" = web-a ]; then
            printf '          - name: "%s@docker"\n' "$member_a_docker_service"
            printf '            weight: 1\n'
        fi
        if [ "$active_members" = both ] || [ "$active_members" = web-b ]; then
            printf '          - name: "%s@docker"\n' "$member_b_docker_service"
            printf '            weight: 1\n'
        fi
    } > "$candidate"
}

write_active_state()
{
    route_sha256=$1
    state_candidate="$operation_directory/.state.$$"
    {
        printf 'version=2\n'
        printf 'operation_id=%s\n' "$operation_id"
        printf 'status=active\n'
        printf 'pool_manifest=%s\n' "$pool_manifest_source"
        printf 'pool_manifest_sha256=%s\n' "$pool_manifest_sha256"
        printf 'pool_plan_manifest=%s\n' "$pool_plan_manifest_source"
        printf 'pool_plan_manifest_sha256=%s\n' "$pool_plan_manifest_sha256"
        printf 'parent_pool_plan_sha256=%s\n' "$parent_pool_plan_sha256"
        printf 'proxy_container=%s\n' "$proxy_container"
        printf 'proxy_id=%s\n' "$proxy_id"
        printf 'proxy_pid=%s\n' "$proxy_pid"
        printf 'proxy_started_at=%s\n' "$proxy_started_at"
        printf 'proxy_restart_count=%s\n' "$proxy_restart_count"
        printf 'proxy_image_id=%s\n' "$proxy_image_id"
        printf 'proxy_config_mount_destination=%s\n' "$proxy_config_mount_destination"
        printf 'proxy_command_sha256=%s\nproxy_static_api_sha256=%s\n' \
            "$proxy_command_sha256" "$proxy_static_api_sha256"
        printf 'expected_ipv4=%s\nproxy_public_binding_tuple=%s\n' \
            "$expected_ipv4" "$proxy_public_binding_tuple"
        printf 'local_ingress_url=%s\nlocal_ingress_entrypoint=%s\nproxy_local_binding_tuple=%s\n' \
            "$local_ingress_url" "$local_ingress_entrypoint" "$proxy_local_binding_tuple"
        printf 'control_plane_host=%s\n' "$control_plane_host"
        printf 'entrypoint=%s\n' "$entrypoint"
        printf 'tls=%s\n' "$tls"
        printf 'cert_resolver=%s\n' "${cert_resolver:-none}"
        printf 'router_priority=%s\n' "$router_priority"
        printf 'direction=%s\n' "$direction"
        printf 'color=%s\n' "$color"
        printf 'generation=%s\n' "$generation"
        printf 'member_set_sha256=%s\n' "$member_set_sha256"
        printf 'route_ack=%s\n' "$route_ack"
        printf 'route_sha256=%s\n' "$route_sha256"
    } > "$state_candidate"
    atomic_replace "$state_candidate" "$state_file" 600
}

write_drain_state()
{
    drain_status=$1
    pre_drain_route_sha256=$2
    route_sha256_value=$3
    state_candidate="$operation_directory/.state.$$"
    {
        printf 'version=2\n'
        printf 'operation_id=%s\n' "$operation_id"
        printf 'status=%s\n' "$drain_status"
        printf 'pool_manifest=%s\n' "$pool_manifest_source"
        printf 'pool_manifest_sha256=%s\n' "$pool_manifest_sha256"
        printf 'parent_pool_plan_sha256=%s\n' "$parent_pool_plan_sha256"
        printf 'proxy_container=%s\n' "$proxy_container"
        printf 'proxy_id=%s\n' "$proxy_id"
        printf 'proxy_pid=%s\n' "$proxy_pid"
        printf 'proxy_started_at=%s\n' "$proxy_started_at"
        printf 'proxy_restart_count=%s\n' "$proxy_restart_count"
        printf 'proxy_image_id=%s\n' "$proxy_image_id"
        printf 'proxy_config_mount_destination=%s\n' "$proxy_config_mount_destination"
        printf 'proxy_command_sha256=%s\nproxy_static_api_sha256=%s\n' \
            "$proxy_command_sha256" "$proxy_static_api_sha256"
        printf 'expected_ipv4=%s\nproxy_public_binding_tuple=%s\n' \
            "$expected_ipv4" "$proxy_public_binding_tuple"
        printf 'local_ingress_url=%s\nlocal_ingress_entrypoint=%s\nproxy_local_binding_tuple=%s\n' \
            "$local_ingress_url" "$local_ingress_entrypoint" "$proxy_local_binding_tuple"
        printf 'control_plane_host=%s\n' "$control_plane_host"
        printf 'entrypoint=%s\n' "$entrypoint"
        printf 'tls=%s\n' "$tls"
        printf 'cert_resolver=%s\n' "${cert_resolver:-none}"
        printf 'router_priority=%s\n' "$router_priority"
        printf 'direction=%s\n' "$direction"
        printf 'color=%s\n' "$color"
        printf 'generation=%s\n' "$generation"
        printf 'member_set_sha256=%s\n' "$member_set_sha256"
        printf 'route_ack=%s\n' "$route_ack"
        printf 'pre_drain_route_sha256=%s\n' "$pre_drain_route_sha256"
        printf 'route_sha256=%s\n' "$route_sha256_value"
        printf 'drained_role=%s\n' "$drain_member"
        printf 'drain_epoch=%s\n' "$selected_drain_epoch"
        printf 'pool_plan_manifest=%s\n' "$pool_plan_manifest_source"
        printf 'pool_plan_manifest_sha256=%s\n' "$pool_plan_manifest_sha256"
    } > "$state_candidate"
    atomic_replace "$state_candidate" "$state_file" 600
}

assert_drain_state_lineage()
{
    assert_state_lineage
    [ "$(state_value drained_role)" = "$drain_member" ] \
        && [ "$(state_value drain_epoch)" = "$selected_drain_epoch" ] \
        && [ "$(state_value pool_plan_manifest)" = "$pool_plan_manifest_source" ] \
        && [ "$(state_value pool_plan_manifest_sha256)" = "$pool_plan_manifest_sha256" ] \
        || fail 'managed HTTPS drain state differs from the exact plan and member'
    validate_sha256 pre_drain_route_sha256 "$(state_value pre_drain_route_sha256)"
    validate_sha256 route_sha256 "$(state_value route_sha256)"
}

assert_drained_route()
{
    attest_proxy_runtime
    [ "$(state_value status)" = drained ] || fail 'managed HTTPS route is not durably drained'
    assert_drain_state_lineage
    is_managed_route || fail 'managed HTTPS drained route is absent'
    expected_route_sha256=$(state_value route_sha256)
    validate_sha256 route_sha256 "$expected_route_sha256"
    [ "$(checksum "$route_file")" = "$expected_route_sha256" ] \
        || fail 'managed HTTPS drained route changed'
    case "$drain_member" in
        web-a)
            [ "$(grep -F -x -c "          - name: \"${member_a_docker_service}@docker\"" "$route_file")" -eq 0 ] \
                && [ "$(grep -F -x -c "          - name: \"${member_b_docker_service}@docker\"" "$route_file")" -eq 1 ] \
                && [ "$(grep -F -x -c '# control-plane-active-members: web-b' "$route_file")" -eq 1 ] \
                || fail 'managed HTTPS drain removed the wrong member'
            ;;
        web-b)
            [ "$(grep -F -x -c "          - name: \"${member_a_docker_service}@docker\"" "$route_file")" -eq 1 ] \
                && [ "$(grep -F -x -c "          - name: \"${member_b_docker_service}@docker\"" "$route_file")" -eq 0 ] \
                && [ "$(grep -F -x -c '# control-plane-active-members: web-a' "$route_file")" -eq 1 ] \
                || fail 'managed HTTPS drain removed the wrong member'
            ;;
    esac
    [ "$(grep -F -c '          - name: ' "$route_file")" -eq 1 ] \
        && ! grep -F -q '          - url: ' "$route_file" \
        && [ "$(count_exact_line "          X-Control-Plane-Route-Ack: \"$route_ack\"" "$route_file")" -eq 1 ] \
        || fail 'managed HTTPS drained route is not an exact one-survivor route'
    case "$drain_member" in web-a) live_survivor=web-b ;; web-b) live_survivor=web-a ;; esac
    assert_live_proxy_route "$live_survivor" "$expected_route_sha256"
}

assert_state_and_route()
{
    live_expected_members=${1:-both}
    attest_proxy_runtime
    [ -f "$state_file" ] && [ ! -L "$state_file" ] || fail 'managed HTTPS pool state is absent'
    [ "$(state_value version)" = 2 ] && [ "$(state_value status)" = active ] \
        || fail 'managed HTTPS pool state is not active v2'
    assert_state_lineage
    is_managed_route || fail 'managed HTTPS pool route is absent'
    expected_route_sha256=$(state_value route_sha256)
    validate_sha256 route_sha256 "$expected_route_sha256"
    [ "$(checksum "$route_file")" = "$expected_route_sha256" ] \
        || fail 'managed HTTPS pool route changed'
    [ "$(grep -F -x -c "          - name: \"${member_a_docker_service}@docker\"" "$route_file")" -eq 1 ] \
        && [ "$(grep -F -x -c "          - name: \"${member_b_docker_service}@docker\"" "$route_file")" -eq 1 ] \
        && [ "$(count_exact_line '        healthCheck: {}' "$route_file")" -eq 1 ] \
        && ! grep -F -q '          - url: ' "$route_file" \
        && [ "$(count_exact_line '    control-plane-blue-green-local:' "$route_file")" -eq 1 ] \
        && [ "$(count_exact_line "        - $local_ingress_entrypoint" "$route_file")" -eq 1 ] \
        && [ "$(count_exact_line "          X-Control-Plane-Route-Ack: \"$route_ack\"" "$route_file")" -eq 1 ] \
        && [ "$(grep -F -x -c '# control-plane-active-members: both' "$route_file")" -eq 1 ] \
        || fail 'managed HTTPS pool route does not contain the exact two-member contract'
    assert_live_proxy_route "$live_expected_members" "$expected_route_sha256"
}

assert_state_lineage()
{
    assert_public_proxy_binding
    assert_local_ingress_proxy_binding
    [ "$(state_value operation_id)" = "$operation_id" ] \
        && [ "$(state_value pool_manifest)" = "$pool_manifest_source" ] \
        && [ "$(state_value pool_manifest_sha256)" = "$pool_manifest_sha256" ] \
        && [ "$(state_value pool_plan_manifest)" = "$pool_plan_manifest_source" ] \
        && [ "$(state_value pool_plan_manifest_sha256)" = "$pool_plan_manifest_sha256" ] \
        && [ "$(state_value parent_pool_plan_sha256)" = "$parent_pool_plan_sha256" ] \
        && [ "$(state_value proxy_container)" = "$proxy_container" ] \
        && [ "$(state_value proxy_id)" = "$proxy_id" ] \
        && [ "$(state_value proxy_pid)" = "$proxy_pid" ] \
        && [ "$(state_value proxy_started_at)" = "$proxy_started_at" ] \
        && [ "$(state_value proxy_restart_count)" = "$proxy_restart_count" ] \
        && [ "$(state_value proxy_image_id)" = "$proxy_image_id" ] \
        && [ "$(state_value proxy_config_mount_destination)" = "$proxy_config_mount_destination" ] \
        && [ "$(state_value proxy_command_sha256)" = "$proxy_command_sha256" ] \
        && [ "$(state_value proxy_static_api_sha256)" = "$proxy_static_api_sha256" ] \
        && [ "$(state_value expected_ipv4)" = "$expected_ipv4" ] \
        && [ "$(state_value proxy_public_binding_tuple)" = "$proxy_public_binding_tuple" ] \
        && [ "$(state_value local_ingress_url)" = "$local_ingress_url" ] \
        && [ "$(state_value local_ingress_entrypoint)" = "$local_ingress_entrypoint" ] \
        && [ "$(state_value proxy_local_binding_tuple)" = "$proxy_local_binding_tuple" ] \
        && [ "$(state_value control_plane_host)" = "$control_plane_host" ] \
        && [ "$(state_value entrypoint)" = "$entrypoint" ] \
        && [ "$(state_value tls)" = "$tls" ] \
        && [ "$(state_value cert_resolver)" = "${cert_resolver:-none}" ] \
        && [ "$(state_value router_priority)" = "$router_priority" ] \
        && [ "$(state_value direction)" = "$direction" ] \
        && [ "$(state_value color)" = "$color" ] \
        && [ "$(state_value generation)" = "$generation" ] \
        && [ "$(state_value member_set_sha256)" = "$member_set_sha256" ] \
        || fail 'managed HTTPS pool state differs from the requested manifest'
    [ "$(state_value route_ack)" = "$route_ack" ] \
        || fail 'managed HTTPS pool route acknowledgement differs from durable state'
}

durable_state_status()
{
    [ -f "$state_file" ] && [ ! -L "$state_file" ] \
        || fail 'managed HTTPS pool state is unsafe'
    [ "$(state_value version)" = 2 ] \
        && [ "$(state_value operation_id)" = "$operation_id" ] \
        || fail 'managed HTTPS pool state belongs to another controller generation'
    state_value status
}

load_reverse_predecessor_switch_context()
{
    load_pool_manifest
    load_pool_plan
    assert_reverse_predecessor_context
}

switch_pool()
{
    attest_proxy_runtime
    if [ "$managed_predecessor_requested" = 1 ]; then
        load_reverse_predecessor_switch_context
        prepare
    else
        prepare
        load_pool_manifest
        load_pool_plan
    fi
    write_lineage_state
    candidate="$dynamic_directory/.${dynamic_filename}.${operation_id}.new"
    trap 'rm -f "$candidate"' EXIT HUP INT TERM
    render_route "$candidate"
    [ "$(grep -F -c '          - name: ' "$candidate")" -eq 2 ] \
        || fail 'rendered ingress route does not contain exactly two weighted Docker services'
    ! grep -F -q '          - url: ' "$candidate" \
        || fail 'rendered ingress route does not contain exactly two weighted Docker services'
    if [ "$test_invalid_route" = 1 ]; then
        printf '\n%s\n' 'http: [' >> "$candidate"
    fi
    target_route_sha256=$(checksum "$candidate")
    if [ -e "$state_file" ]; then
        current_status=$(durable_state_status)
        case "$current_status" in
            active)
                assert_state_and_route
                [ "$(state_value route_sha256)" = "$target_route_sha256" ] \
                    || fail 'active HTTPS route settings differ from the exact idempotent switch target'
                prove_pool
                rm -f "$candidate"
                trap - EXIT HUP INT TERM
                return
                ;;
            restored-legacy)
                ack_restored_legacy >/dev/null
                rm -f "$state_file"
                sync "$operation_directory"
                test_crash after-restored-legacy-rearm
                ;;
            restored-managed-v2|drain-intent|drained)
                fail "switch replay is forbidden from terminal or reduced pool state: $current_status"
                ;;
            *) fail "switch replay refuses unknown durable state: $current_status" ;;
        esac
    fi
    assert_route_switchable "$target_route_sha256"
    prove_pool
    test_provider_health_barrier
    wait_for_member_provider_health
    if [ ! -e "$route_file" ] || [ "$(checksum "$route_file")" != "$target_route_sha256" ]; then
        atomic_replace "$candidate" "$route_file" 600
        test_crash after-switch-route
    else
        rm -f "$candidate"
    fi
    trap - EXIT HUP INT TERM
    assert_live_proxy_route both "$target_route_sha256"
    attest_proxy_runtime
    write_active_state "$target_route_sha256"
}

probe_public()
{
    attest_proxy_runtime
    public_proxy_tuple=$proxy_runtime_tuple
    assert_public_proxy_binding
    public_binding_tuple=$proxy_public_binding_tuple
    headers="$operation_directory/.public-headers.$$"
    public_curl_config="$operation_directory/.public-curl.$$"
    write_pinned_curl_config "$public_curl_config" "$public_url"
    attempt=0
    trap 'rm -f "$headers" "$public_curl_config"' EXIT HUP INT TERM
    while [ "$attempt" -lt "$probe_attempts" ]; do
        rm -f "$headers"
        status=0
        curl --config "$public_curl_config" --fail \
            --dump-header "$headers" --output /dev/null || status=$?
        if [ "$status" -eq 0 ] \
            && has_single_exact_response_header "$headers" X-Control-Plane-Route-Ack "$route_ack"; then
            attest_proxy_runtime
            [ "$proxy_runtime_tuple" = "$public_proxy_tuple" ] \
                || fail 'exact Traefik proxy process changed during public acknowledgement proof'
            assert_public_proxy_binding
            [ "$proxy_public_binding_tuple" = "$public_binding_tuple" ] \
                || fail 'exact Traefik public binding changed during acknowledgement proof'
            rm -f "$headers" "$public_curl_config"
            trap - EXIT HUP INT TERM
            return
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    fail 'public HTTPS route did not acknowledge the exact generation-bound pool'
}

probe_local_ingress()
{
    attest_proxy_runtime
    local_proxy_tuple=$proxy_runtime_tuple
    assert_local_ingress_proxy_binding
    local_binding_tuple=$proxy_local_binding_tuple
    headers="$operation_directory/.local-ingress-headers.$$"
    local_curl_config="$operation_directory/.local-ingress-curl.$$"
    write_local_ingress_curl_config "$local_curl_config"
    attempt=0
    trap 'rm -f "$headers" "$local_curl_config"' EXIT HUP INT TERM
    while [ "$attempt" -lt "$probe_attempts" ]; do
        rm -f "$headers"
        status=0
        curl --config "$local_curl_config" --fail \
            --dump-header "$headers" --output /dev/null || status=$?
        if [ "$status" -eq 0 ] \
            && has_single_exact_response_header "$headers" X-Control-Plane-Route-Ack "$route_ack"; then
            attest_proxy_runtime
            [ "$proxy_runtime_tuple" = "$local_proxy_tuple" ] \
                || fail 'exact Traefik proxy process changed during loopback acknowledgement proof'
            assert_local_ingress_proxy_binding
            [ "$proxy_local_binding_tuple" = "$local_binding_tuple" ] \
                || fail 'native Traefik loopback binding changed during acknowledgement proof'
            rm -f "$headers" "$local_curl_config"
            trap - EXIT HUP INT TERM
            return
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    fail 'native Traefik loopback route did not acknowledge the exact generation-bound pool'
}

assert_pool()
{
    load_pool_manifest
    load_pool_plan
    assert_state_and_route
    prove_pool
}

verify_pool()
{
    assert_pool
    probe_public
    probe_local_ingress
}

select_drain_member()
{
    case "$drain_member" in
        web-a)
            selected_drain_epoch=$(plan_value member_a_route_drain_epoch)
            drained_address=$member_a_address
            drained_port=$member_a_port
            drained_route_identity=$member_a_route_identity
            drained_applied_ack=$member_a_applied_ack
            surviving_role=web-b
            surviving_address=$member_b_address
            surviving_port=$member_b_port
            surviving_route_identity=$member_b_route_identity
            surviving_applied_ack=$member_b_applied_ack
            ;;
        web-b)
            selected_drain_epoch=$(plan_value member_b_route_drain_epoch)
            drained_address=$member_b_address
            drained_port=$member_b_port
            drained_route_identity=$member_b_route_identity
            drained_applied_ack=$member_b_applied_ack
            surviving_role=web-a
            surviving_address=$member_a_address
            surviving_port=$member_a_port
            surviving_route_identity=$member_a_route_identity
            surviving_applied_ack=$member_a_applied_ack
            ;;
        *) fail 'CONTROL_PLANE_INGRESS_DRAIN_MEMBER must be exactly web-a or web-b' ;;
    esac
    route_ack=$(compute_drain_route_ack "$drain_member" "$selected_drain_epoch")
}

drain_pool_member()
{
    attest_proxy_runtime
    load_pool_manifest
    load_pool_plan
    select_drain_member
    [ -f "$state_file" ] && [ ! -L "$state_file" ] || fail 'managed HTTPS pool state is absent'
    current_status=$(state_value status)
    case "$current_status" in
        active)
            full_route_ack=$(compute_route_ack)
            route_ack=$full_route_ack
            assert_state_and_route "drain-pending-$drain_member"
            pre_drain_route_sha256=$(checksum "$route_file")
            route_ack=$(compute_drain_route_ack "$drain_member" "$selected_drain_epoch")
            ;;
        drain-intent)
            assert_drain_state_lineage
            pre_drain_route_sha256=$(state_value pre_drain_route_sha256)
            ;;
        drained)
            assert_drained_route
            probe_draining_member "$drain_member" "$drained_address" "$drained_port" \
                "$drained_route_identity" "$drained_applied_ack" "$selected_drain_epoch"
            probe_member "$surviving_role" "$surviving_address" "$surviving_port" \
                "$surviving_route_identity" "$surviving_applied_ack"
            return
            ;;
        *) fail 'only a full active pool or the same in-progress drain may remove one member' ;;
    esac

    probe_member "$surviving_role" "$surviving_address" "$surviving_port" \
        "$surviving_route_identity" "$surviving_applied_ack"
    probe_draining_member "$drain_member" "$drained_address" "$drained_port" \
        "$drained_route_identity" "$drained_applied_ack" "$selected_drain_epoch"
    candidate="$dynamic_directory/.${dynamic_filename}.${operation_id}.drain"
    trap 'rm -f "$candidate"' EXIT HUP INT TERM
    render_route "$candidate" "$surviving_role"
    [ "$(grep -F -c '          - name: ' "$candidate")" -eq 1 ] \
        || fail 'rendered ingress drain route did not retain exactly one Docker service'
    ! grep -F -q '          - url: ' "$candidate" \
        || fail 'rendered ingress drain route did not retain exactly one Docker service'
    target_route_sha256=$(checksum "$candidate")
    current_route_sha256=$(checksum "$route_file")
    case "$current_status" in
        active)
            [ "$current_route_sha256" = "$pre_drain_route_sha256" ] \
                || fail 'managed HTTPS route changed before drain intent persistence'
            write_drain_state drain-intent "$pre_drain_route_sha256" "$target_route_sha256"
            test_crash after-drain-intent
            ;;
        drain-intent)
            [ "$target_route_sha256" = "$(state_value route_sha256)" ] \
                || fail 'reconciled HTTPS drain target differs from durable intent'
            [ "$current_route_sha256" = "$pre_drain_route_sha256" \
                ] || [ "$current_route_sha256" = "$target_route_sha256" ] \
                || fail 'managed HTTPS route differs from both sides of durable drain intent'
            ;;
    esac
    if [ "$current_route_sha256" != "$target_route_sha256" ]; then
        atomic_replace "$candidate" "$route_file" 600
    else
        rm -f "$candidate"
    fi
    test_crash after-drain-route
    trap - EXIT HUP INT TERM
    assert_live_proxy_route "$surviving_role" "$target_route_sha256"
    attest_proxy_runtime
    write_drain_state drained "$pre_drain_route_sha256" "$target_route_sha256"
    test_crash after-drain-state
    assert_drained_route
    probe_member "$surviving_role" "$surviving_address" "$surviving_port" \
        "$surviving_route_identity" "$surviving_applied_ack"
}

assert_drained_pool()
{
    load_pool_manifest
    load_pool_plan
    select_drain_member
    assert_drained_route
    probe_draining_member "$drain_member" "$drained_address" "$drained_port" \
        "$drained_route_identity" "$drained_applied_ack" "$selected_drain_epoch"
    probe_member "$surviving_role" "$surviving_address" "$surviving_port" \
        "$surviving_route_identity" "$surviving_applied_ack"
}

verify_drained_pool()
{
    assert_drained_pool
    probe_public
    probe_local_ingress
}

restore()
{
    load_restore_lineage
    backup_status=$(state_value_from "$rollback_state_file" backup_status)
    backup_checksum=$(state_value_from "$rollback_state_file" backup_checksum)
    restore_candidate="$dynamic_directory/.${dynamic_filename}.${operation_id}.restore"
    trap 'rm -f "$restore_candidate"' EXIT HUP INT TERM
    case "$backup_status" in
        present)
            case "$backup_kind" in
                legacy)
                    if [ ! -f "$backup_file" ] \
                        || [ "$(checksum "$backup_file")" != "$backup_checksum" ] \
                        || is_any_managed_v2_route "$backup_file"; then
                        fail 'durable HTTPS rollback backup changed'
                    fi
                    if [ -e "$route_file" ] && ! is_managed_route \
                        && [ "$(checksum "$route_file")" != "$backup_checksum" ]; then
                        fail 'refusing to overwrite an unmanaged HTTPS route'
                    fi
                    cp -p "$backup_file" "$restore_candidate"
                    atomic_replace "$restore_candidate" "$route_file" 600
                    restored_status=restored-legacy
                    ;;
                managed-v2)
                    assert_managed_predecessor_backup "$rollback_state_file"
                    if [ -e "$route_file" ] \
                        && [ "$(checksum "$route_file")" = "$backup_checksum" ]; then
                        assert_managed_predecessor_route "$route_file"
                    else
                        [ -e "$route_file" ] \
                            || fail 'current managed HTTPS reverse route is absent before predecessor restore'
                        assert_requested_managed_route "$route_file"
                        cp -p "$backup_file" "$restore_candidate"
                        atomic_replace "$restore_candidate" "$route_file" 600
                    fi
                    [ "$(checksum "$route_file")" = "$backup_checksum" ] \
                        || fail 'restored managed HTTPS predecessor differs from its immutable backup'
                    assert_managed_predecessor_route "$route_file"
                    restored_status=restored-managed-v2
                    ;;
                *) fail 'present HTTPS rollback state has an invalid backup kind' ;;
            esac
            ;;
        absent)
            [ "$backup_kind" = absent ] \
                || fail 'absent HTTPS rollback state has an invalid backup kind'
            if [ -e "$route_file" ] && ! is_managed_route; then
                fail 'refusing to remove an unmanaged HTTPS route'
            fi
            rm -f "$route_file"
            sync "$dynamic_directory"
            restored_status=restored-legacy
            ;;
        *) fail 'HTTPS rollback state is invalid' ;;
    esac
    test_crash after-restore-route
    restored_candidate="$operation_directory/.state.$$"
    {
        printf 'version=2\n'
        printf 'operation_id=%s\n' "$operation_id"
        printf 'status=%s\n' "$restored_status"
        if [ "$restored_status" = restored-managed-v2 ]; then
            printf 'restored_route_sha256=%s\n' "$backup_checksum"
            write_predecessor_identity
        fi
    } > "$restored_candidate"
    atomic_replace "$restored_candidate" "$state_file" 600
    test_crash after-restore-state
    rm -f "$restore_candidate"
    trap - EXIT HUP INT TERM
}

ack_restored_legacy()
{
    load_restore_lineage
    [ "$(state_value version)" = 2 ] \
        && [ "$(state_value operation_id)" = "$operation_id" ] \
        && [ "$(state_value status)" = restored-legacy ] \
        || fail 'legacy acknowledgement requires the exact durable restored state'
    backup_status=$(state_value_from "$rollback_state_file" backup_status)
    backup_checksum=$(state_value_from "$rollback_state_file" backup_checksum)
    original_checksum=$(state_value_from "$rollback_state_file" original_checksum)
    case "$backup_status" in
        present)
            [ -f "$route_file" ] && [ ! -L "$route_file" ] \
                && [ "$(checksum "$route_file")" = "$backup_checksum" ] \
                || fail 'legacy acknowledgement route differs from the immutable rollback target'
            ;;
        absent) [ ! -e "$route_file" ] || fail 'legacy acknowledgement expected no managed file route' ;;
        *) fail 'legacy acknowledgement rollback target is invalid' ;;
    esac
    attest_proxy_runtime
    assert_public_proxy_binding
    capture_legacy_public_fingerprint "$expected_legacy_public_ack_proof"
    [ "$legacy_public_status" = "$expected_legacy_public_status" ] \
        && [ "$legacy_public_body_sha256" = "$expected_legacy_public_body_sha256" ] \
        && [ "$legacy_public_response_ack" = "$expected_legacy_public_response_ack" ] \
        && [ "$legacy_public_ack_proof" = "$expected_legacy_public_ack_proof" ] \
        || fail 'restored legacy public acknowledgement differs from immutable pre-switch proof'
    printf '%s\n' "$legacy_public_ack_proof"
}

ack_restored_managed_predecessor()
{
    load_restore_lineage
    [ "$(state_value version)" = 2 ] \
        && [ "$(state_value operation_id)" = "$operation_id" ] \
        && [ "$(state_value status)" = restored-managed-v2 ] \
        || fail 'managed predecessor acknowledgement requires the exact durable restored state'
    assert_persisted_predecessor_identity "$state_file" \
        'durable restored HTTPS predecessor identity'
    backup_status=$(state_value_from "$rollback_state_file" backup_status)
    backup_checksum=$(state_value_from "$rollback_state_file" backup_checksum)
    [ "$backup_kind" = managed-v2 ] && [ "$backup_status" = present ] \
        || fail 'managed predecessor acknowledgement has no managed rollback backup'
    [ "$(state_value restored_route_sha256)" = "$backup_checksum" ] \
        || fail 'durable restored HTTPS predecessor checksum differs from rollback state'
    assert_managed_predecessor_backup "$rollback_state_file"
    [ -f "$route_file" ] && [ ! -L "$route_file" ] \
        && [ "$(checksum "$route_file")" = "$backup_checksum" ] \
        || fail 'managed predecessor acknowledgement route differs from the immutable rollback target'
    assert_managed_predecessor_route "$route_file"
    attest_proxy_runtime
    assert_public_proxy_binding
    capture_managed_predecessor_public_ack \
        "$expected_managed_predecessor_public_ack_proof"
    [ "$expected_managed_predecessor_route_ack" = "$route_predecessor_ack" ] \
        && [ "$managed_predecessor_public_ack_proof" \
            = "$expected_managed_predecessor_public_ack_proof" ] \
        || fail 'restored managed HTTPS predecessor acknowledgement differs from immutable lineage'
    printf '%s\n' "$managed_predecessor_public_ack_proof"
}

action=${1:-}
operation_id=${CONTROL_PLANE_INGRESS_OPERATION_ID:-}
operation_directory=${CONTROL_PLANE_INGRESS_OPERATION_DIR:-}
dynamic_directory=${CONTROL_PLANE_INGRESS_DYNAMIC_DIR:-}
dynamic_filename=${CONTROL_PLANE_INGRESS_DYNAMIC_FILENAME:-}
control_plane_host=${CONTROL_PLANE_INGRESS_HOST:-}
entrypoint=${CONTROL_PLANE_INGRESS_TRAEFIK_ENTRYPOINT:-}
local_ingress_entrypoint=${CONTROL_PLANE_INGRESS_TRAEFIK_LOCAL_ENTRYPOINT:-}
tls=${CONTROL_PLANE_INGRESS_TRAEFIK_TLS:-}
cert_resolver=${CONTROL_PLANE_INGRESS_TRAEFIK_CERT_RESOLVER:-}
router_priority=${CONTROL_PLANE_INGRESS_TRAEFIK_ROUTER_PRIORITY:-}
color=${CONTROL_PLANE_INGRESS_COLOR:-}
requested_generation=${CONTROL_PLANE_INGRESS_GENERATION:-}
pool_manifest=${CONTROL_PLANE_INGRESS_POOL_MANIFEST:-}
pool_manifest_sha256=${CONTROL_PLANE_INGRESS_POOL_MANIFEST_SHA256:-}
pool_plan_manifest_source=${CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST:-}
pool_plan_manifest_sha256=${CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST_SHA256:-}
proxy_container=${CONTROL_PLANE_INGRESS_PROXY_CONTAINER:-}
provider_api_url=${CONTROL_PLANE_INGRESS_PROVIDER_API_URL:-}
provider_header_file=${CONTROL_PLANE_INGRESS_PROVIDER_HEADER_FILE:-}
drain_member=${CONTROL_PLANE_INGRESS_DRAIN_MEMBER:-}
public_url=${CONTROL_PLANE_INGRESS_PUBLIC_URL:-}
local_ingress_url=${CONTROL_PLANE_INGRESS_LOCAL_URL:-}
local_ingress_host_port=${CONTROL_PLANE_INGRESS_APP_PORT:-}
public_host_header=${CONTROL_PLANE_INGRESS_PUBLIC_HOST_HEADER:-}
expected_ipv4=${CONTROL_PLANE_INGRESS_EXPECTED_IPV4:-}
probe_attempts=${CONTROL_PLANE_INGRESS_PROBE_ATTEMPTS:-20}
test_mode=${CONTROL_PLANE_INGRESS_TEST_MODE:-0}
test_crash_at=${CONTROL_PLANE_INGRESS_TEST_CRASH_AT:-}
test_invalid_route=${CONTROL_PLANE_INGRESS_TEST_INVALID_ROUTE:-0}
test_provider_health_barrier_directory=${CONTROL_PLANE_INGRESS_TEST_PROVIDER_HEALTH_BARRIER_DIR:-}
predecessor_operation_id=${CONTROL_PLANE_INGRESS_PREDECESSOR_OPERATION_ID:-}
predecessor_manifest_sha256=${CONTROL_PLANE_INGRESS_PREDECESSOR_MANIFEST_SHA256:-}
predecessor_pool_plan_sha256=${CONTROL_PLANE_INGRESS_PREDECESSOR_POOL_PLAN_SHA256:-}
predecessor_color=${CONTROL_PLANE_INGRESS_PREDECESSOR_COLOR:-}
predecessor_generation=${CONTROL_PLANE_INGRESS_PREDECESSOR_GENERATION:-}
expected_pool_manifest_metadata=
expected_pool_plan_metadata=

require_value CONTROL_PLANE_INGRESS_OPERATION_ID "$operation_id"
require_value CONTROL_PLANE_INGRESS_OPERATION_DIR "$operation_directory"
require_value CONTROL_PLANE_INGRESS_DYNAMIC_DIR "$dynamic_directory"
require_value CONTROL_PLANE_INGRESS_DYNAMIC_FILENAME "$dynamic_filename"
require_value CONTROL_PLANE_INGRESS_PROXY_CONTAINER "$proxy_container"
require_value CONTROL_PLANE_INGRESS_PROVIDER_API_URL "$provider_api_url"
require_value CONTROL_PLANE_INGRESS_EXPECTED_IPV4 "$expected_ipv4"
require_value CONTROL_PLANE_INGRESS_LOCAL_URL "$local_ingress_url"
require_value CONTROL_PLANE_INGRESS_APP_PORT "$local_ingress_host_port"
require_value CONTROL_PLANE_INGRESS_TRAEFIK_LOCAL_ENTRYPOINT "$local_ingress_entrypoint"
validate_identifier CONTROL_PLANE_INGRESS_OPERATION_ID "$operation_id"
validate_absolute_path CONTROL_PLANE_INGRESS_OPERATION_DIR "$operation_directory"
validate_absolute_path CONTROL_PLANE_INGRESS_DYNAMIC_DIR "$dynamic_directory"
validate_identifier CONTROL_PLANE_INGRESS_DYNAMIC_FILENAME "$dynamic_filename"
validate_identifier CONTROL_PLANE_INGRESS_PROXY_CONTAINER "$proxy_container"
validate_provider_api_url CONTROL_PLANE_INGRESS_PROVIDER_API_URL "$provider_api_url"
if [ -n "$provider_header_file" ]; then
    validate_absolute_path CONTROL_PLANE_INGRESS_PROVIDER_HEADER_FILE "$provider_header_file"
    [ -f "$provider_header_file" ] && [ ! -L "$provider_header_file" ] \
        && [ -r "$provider_header_file" ] \
        || fail 'provider header file must be a readable regular non-symlink file'
    provider_header_expected_uid=0
    provider_header_expected_gid=0
    if [ "$test_mode" = 1 ]; then
        provider_header_expected_uid=$(id -u)
        provider_header_expected_gid=$(id -g)
    fi
    provider_header_metadata_prefix="${provider_header_expected_uid}:${provider_header_expected_gid}:600:"
    case "$(file_metadata "$provider_header_file")" in
        "$provider_header_metadata_prefix"*:*) ;;
        *) fail 'provider header file must have immutable-owner mode 0600 metadata' ;;
    esac
fi
validate_http_url CONTROL_PLANE_INGRESS_LOCAL_URL "$local_ingress_url"
validate_port CONTROL_PLANE_INGRESS_APP_PORT "$local_ingress_host_port"
validate_identifier CONTROL_PLANE_INGRESS_TRAEFIK_LOCAL_ENTRYPOINT "$local_ingress_entrypoint"
case "$local_ingress_url" in
    "http://127.0.0.1:${local_ingress_host_port}/"*) ;;
    *) fail 'CONTROL_PLANE_INGRESS_LOCAL_URL must exactly match CONTROL_PLANE_INGRESS_APP_PORT' ;;
esac
validate_positive_integer CONTROL_PLANE_INGRESS_PROBE_ATTEMPTS "$probe_attempts"
case "$test_mode" in 0|1) ;; *) fail 'CONTROL_PLANE_INGRESS_TEST_MODE must be 0 or 1' ;; esac
if [ "$test_mode" = 0 ]; then
    validate_globally_routable_ipv4 CONTROL_PLANE_INGRESS_EXPECTED_IPV4 "$expected_ipv4"
else
    validate_ipv4 CONTROL_PLANE_INGRESS_EXPECTED_IPV4 "$expected_ipv4"
fi
case "$test_invalid_route" in 0|1) ;; *) fail 'CONTROL_PLANE_INGRESS_TEST_INVALID_ROUTE must be 0 or 1' ;; esac
[ -z "$test_crash_at" ] || [ "$test_mode" = 1 ] \
    || fail 'CONTROL_PLANE_INGRESS_TEST_CRASH_AT is forbidden outside test mode'
[ "$test_invalid_route" = 0 ] || [ "$test_mode" = 1 ] \
    || fail 'CONTROL_PLANE_INGRESS_TEST_INVALID_ROUTE is forbidden outside test mode'
if [ -n "$test_provider_health_barrier_directory" ]; then
    [ "$test_mode" = 1 ] \
        || fail 'CONTROL_PLANE_INGRESS_TEST_PROVIDER_HEALTH_BARRIER_DIR is forbidden outside test mode'
    validate_absolute_path CONTROL_PLANE_INGRESS_TEST_PROVIDER_HEALTH_BARRIER_DIR \
        "$test_provider_health_barrier_directory"
    [ -d "$test_provider_health_barrier_directory" ] \
        && [ ! -L "$test_provider_health_barrier_directory" ] \
        || fail 'provider-health test barrier directory is absent or unsafe'
fi
managed_predecessor_requested=0
if [ -n "$predecessor_operation_id" ] \
    || [ -n "$predecessor_manifest_sha256" ] \
    || [ -n "$predecessor_pool_plan_sha256" ] \
    || [ -n "$predecessor_color" ] \
    || [ -n "$predecessor_generation" ]; then
    require_value CONTROL_PLANE_INGRESS_PREDECESSOR_OPERATION_ID "$predecessor_operation_id"
    require_value CONTROL_PLANE_INGRESS_PREDECESSOR_MANIFEST_SHA256 "$predecessor_manifest_sha256"
    require_value CONTROL_PLANE_INGRESS_PREDECESSOR_POOL_PLAN_SHA256 \
        "$predecessor_pool_plan_sha256"
    require_value CONTROL_PLANE_INGRESS_PREDECESSOR_COLOR "$predecessor_color"
    require_value CONTROL_PLANE_INGRESS_PREDECESSOR_GENERATION "$predecessor_generation"
    validate_identifier CONTROL_PLANE_INGRESS_PREDECESSOR_OPERATION_ID \
        "$predecessor_operation_id"
    validate_sha256 CONTROL_PLANE_INGRESS_PREDECESSOR_MANIFEST_SHA256 \
        "$predecessor_manifest_sha256"
    validate_sha256 CONTROL_PLANE_INGRESS_PREDECESSOR_POOL_PLAN_SHA256 \
        "$predecessor_pool_plan_sha256"
    case "$predecessor_color" in
        blue|green) ;;
        *) fail 'CONTROL_PLANE_INGRESS_PREDECESSOR_COLOR must be blue or green' ;;
    esac
    validate_positive_integer CONTROL_PLANE_INGRESS_PREDECESSOR_GENERATION \
        "$predecessor_generation"
    managed_predecessor_requested=1
fi
[ -d "$dynamic_directory" ] && [ ! -L "$dynamic_directory" ] \
    || fail 'Traefik dynamic directory is absent or unsafe'
resolved_dynamic_directory=$(python3 -c 'import os, sys; print(os.path.realpath(sys.argv[1]))' \
    "$dynamic_directory")
[ "$resolved_dynamic_directory" = "$dynamic_directory" ] \
    || fail 'Traefik dynamic directory contains a symlinked path component'
route_file="$dynamic_directory/$dynamic_filename"
state_file="$operation_directory/state"
rollback_state_file="$operation_directory/rollback.state"
backup_file="$operation_directory/before.yaml"
lock_file="$operation_directory/controller.lock"
lineage_file="$operation_directory/lineage.state"

case "$action" in
    preflight) ;;
    prepare|switch)
        ensure_operation_directory 1
        acquire_operation_lock
        ;;
    assert|verify|ack|drain|assert-drained|verify-drained|restore)
        ensure_operation_directory 0
        acquire_operation_lock
        ;;
esac

if [ "$action" = ack ] && [ -f "$state_file" ]; then
    if grep -F -x -q 'status=restored-legacy' "$state_file"; then
        ack_restored_legacy
        exit 0
    fi
    if grep -F -x -q 'status=restored-managed-v2' "$state_file"; then
        ack_restored_managed_predecessor
        exit 0
    fi
fi

case "$action" in
    preflight)
        require_value CONTROL_PLANE_INGRESS_HOST "$control_plane_host"
        require_value CONTROL_PLANE_INGRESS_PUBLIC_URL "$public_url"
        require_value CONTROL_PLANE_INGRESS_TRAEFIK_ENTRYPOINT "$entrypoint"
        validate_hostname CONTROL_PLANE_INGRESS_HOST "$control_plane_host"
        validate_http_url CONTROL_PLANE_INGRESS_PUBLIC_URL "$public_url"
        validate_identifier CONTROL_PLANE_INGRESS_TRAEFIK_ENTRYPOINT "$entrypoint"
        [ "$entrypoint" != "$local_ingress_entrypoint" ] \
            || fail 'public and loopback Traefik entrypoints must be distinct'
        [ -z "$public_host_header" ] \
            || validate_http_host_header CONTROL_PLANE_INGRESS_PUBLIC_HOST_HEADER "$public_host_header"
        attest_proxy_runtime
        assert_public_proxy_binding
        assert_local_ingress_proxy_binding
        ;;
    prepare)
        attest_proxy_runtime
        if [ "$managed_predecessor_requested" = 1 ]; then
            load_reverse_predecessor_switch_context
        fi
        prepare
        ;;
    switch|assert|verify|ack|drain|assert-drained|verify-drained)
        require_value CONTROL_PLANE_INGRESS_COLOR "$color"
        require_value CONTROL_PLANE_INGRESS_GENERATION "$requested_generation"
        require_value CONTROL_PLANE_INGRESS_POOL_MANIFEST "$pool_manifest"
        require_value CONTROL_PLANE_INGRESS_POOL_MANIFEST_SHA256 "$pool_manifest_sha256"
        require_value CONTROL_PLANE_INGRESS_HOST "$control_plane_host"
        validate_hostname CONTROL_PLANE_INGRESS_HOST "$control_plane_host"
        require_value CONTROL_PLANE_INGRESS_TRAEFIK_ENTRYPOINT "$entrypoint"
        require_value CONTROL_PLANE_INGRESS_TRAEFIK_TLS "$tls"
        require_value CONTROL_PLANE_INGRESS_TRAEFIK_ROUTER_PRIORITY "$router_priority"
        validate_identifier CONTROL_PLANE_INGRESS_TRAEFIK_ENTRYPOINT "$entrypoint"
        [ "$entrypoint" != "$local_ingress_entrypoint" ] \
            || fail 'public and loopback Traefik entrypoints must be distinct'
        case "$tls" in true|false) ;; *) fail 'Traefik TLS setting must be true or false' ;; esac
        validate_positive_integer CONTROL_PLANE_INGRESS_TRAEFIK_ROUTER_PRIORITY "$router_priority"
        [ "$router_priority" -lt 2147483647 ] \
            || fail 'Traefik router priority leaves no reserved provider-proof priority'
        provider_router_priority=$((router_priority + 1))
        if [ "$tls" = true ]; then
            if [ -n "$cert_resolver" ]; then
                validate_identifier CONTROL_PLANE_INGRESS_TRAEFIK_CERT_RESOLVER "$cert_resolver"
            elif [ "$test_mode" != 1 ]; then
                fail 'Traefik certificate resolver is required outside the lab'
            fi
        else
            [ -z "$cert_resolver" ] \
                || fail 'Traefik certificate resolver must be empty when TLS is disabled'
        fi
        require_value CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST "$pool_plan_manifest_source"
        require_value CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST_SHA256 "$pool_plan_manifest_sha256"
        case "$action" in
            drain|assert-drained|verify-drained)
                require_value CONTROL_PLANE_INGRESS_DRAIN_MEMBER "$drain_member"
                ;;
        esac
        case "$action" in
            switch) switch_pool ;;
            assert) assert_pool ;;
            verify|ack)
                require_value CONTROL_PLANE_INGRESS_PUBLIC_URL "$public_url"
                validate_http_url CONTROL_PLANE_INGRESS_PUBLIC_URL "$public_url"
                [ -z "$public_host_header" ] \
                    || validate_http_host_header CONTROL_PLANE_INGRESS_PUBLIC_HOST_HEADER "$public_host_header"
                verify_pool
                ;;
            drain) drain_pool_member ;;
            assert-drained) assert_drained_pool ;;
            verify-drained)
                require_value CONTROL_PLANE_INGRESS_PUBLIC_URL "$public_url"
                validate_http_url CONTROL_PLANE_INGRESS_PUBLIC_URL "$public_url"
                [ -z "$public_host_header" ] \
                    || validate_http_host_header CONTROL_PLANE_INGRESS_PUBLIC_HOST_HEADER "$public_host_header"
                verify_drained_pool
                ;;
        esac
        ;;
    restore)
        restore
        ;;
    *)
        fail 'usage: traefik-ingress.sh {preflight|prepare|switch|assert|verify|ack|drain|assert-drained|verify-drained|restore}; managed routing requires ingress-pool.manifest v2'
        ;;
esac
