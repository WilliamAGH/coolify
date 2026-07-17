#!/bin/sh

set -eu

fixture_directory=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd -P)
repository_root=$(CDPATH='' cd -- "$fixture_directory/../../.." && pwd -P)
canonical_runtime_fence_controller=$repository_root/docker/control-plane-blue-green/controllers/runtime-attestation-ssh-fence.sh
action=${1:-}

fail()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE_LAB_FAILURE %s\n' "$1" >&2
    exit 1
}

canonical_container_runtime_sha256()
{
    runtime_container=${1:-}
    [ -n "$runtime_container" ] || fail 'runtime identity requires a container name'
    [ -x "$canonical_runtime_fence_controller" ] \
        || fail 'canonical runtime-fence controller is unavailable'
    observed_runtime_sha256=$(CONTROL_PLANE_RUNTIME_TEST_MODE=1 \
        "$canonical_runtime_fence_controller" container-runtime-sha256 "$runtime_container") \
        || fail "canonical runtime identity inspection failed: $runtime_container"
    printf '%s\n' "$observed_runtime_sha256" | grep -E -q '^[a-f0-9]{64}$' \
        || fail 'canonical runtime identity is not a SHA-256 digest'
    printf '%s\n' "$observed_runtime_sha256"
}

case "$action" in
    container-runtime-sha256)
        [ "$#" -eq 2 ] || {
            printf '%s\n' 'usage: runtime-fence-provisioner.sh container-runtime-sha256 CONTAINER' >&2
            exit 64
        }
        canonical_container_runtime_sha256 "$2"
        exit
        ;;
esac

state=${CONTROL_PLANE_RUNTIME_FENCE_LAB_STATE:?CONTROL_PLANE_RUNTIME_FENCE_LAB_STATE is required}
log=${state}.log
environment=${state}.environment
candidate_denial=${state}.candidate-denial
candidate_runtime_digest=${state}.candidate-runtime-sha256
member_b_runtime_digest=${state}.member-b-runtime-sha256
member_b_identity=${state}.member-b-id
ingress_pool_manifest_pin=${state}.ingress-pool-manifest-sha256
daemon_recovery=${state}.daemon-recovery

phase()
{
    if [ -f "$state" ]; then
        cat "$state"
    else
        printf '%s\n' new
    fi
}

transition()
{
    printf '%s\n' "$1" > "${state}.new"
    mv "${state}.new" "$state"
    printf '%s\n' "$action" >> "$log"
}

environment_value()
{
    environment_key=$1
    environment_source=${2:-$environment}
    environment_key_count=$(awk -F= -v key="$environment_key" \
        '$1 == key { count++ } END { print count + 0 }' "$environment_source")
    if [ "$environment_key_count" -eq 1 ]; then
        sed -n "s/^${environment_key}=//p" "$environment_source"
        return
    fi
    [ "$environment_key_count" -eq 0 ] \
        || fail "runtime-fence environment has an invalid ${environment_key} record"

    case "$environment_key" in
        CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER) plan_key=member_a_name ;;
        CONTROL_PLANE_RUNTIME_CANDIDATE_IMAGE_ID) plan_key=member_a_image_id ;;
        CONTROL_PLANE_RUNTIME_CANDIDATE_IMAGE_REFERENCE) plan_key=member_a_image_reference ;;
        CONTROL_PLANE_RUNTIME_CANDIDATE_NETWORK_IDS) plan_key=member_a_network_ids ;;
        CONTROL_PLANE_RUNTIME_CANDIDATE_REPIN_INTENT_FILE) plan_key=member_a_repin_intent_file ;;
        CONTROL_PLANE_RUNTIME_LEGACY_CONTAINER) plan_key=retired_member_a_name ;;
        *) fail "runtime-fence environment has an invalid ${environment_key} record" ;;
    esac
    pool_plan_value "$plan_key" "$environment_source"
}

environment_required_value()
{
    required_key=$1
    required_source=${2:-$environment}
    required_count=$(awk -F= -v key="$required_key" \
        '$1 == key { count++ } END { print count + 0 }' "$required_source")
    [ "$required_count" -eq 1 ] \
        || fail "runtime-fence environment has an invalid ${required_key} record"
    sed -n "s/^${required_key}=//p" "$required_source"
}

pool_plan_value()
{
    plan_key=$1
    plan_environment=${2:-$environment}
    plan_path=$(environment_required_value CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST \
        "$plan_environment")
    plan_sha256=$(environment_required_value CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256 \
        "$plan_environment")
    plan_metadata=$(environment_required_value CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_METADATA \
        "$plan_environment")
    [ -f "$plan_path" ] && [ ! -L "$plan_path" ] \
        && printf '%s\n' "$plan_sha256" | grep -E -q '^[a-f0-9]{64}$' \
        && printf '%s\n' "$plan_metadata" | grep -E -q '^[0-9]+:[0-9]+:600:[0-9]+$' \
        && [ "$(stat -c '%u:%g:%a:%s' "$plan_path")" = "$plan_metadata" ] \
        && [ "$(sha256sum "$plan_path" | awk '{print $1}')" = "$plan_sha256" ] \
        || fail 'runtime-fence pool plan differs from its signed identity'
    plan_key_count=$(awk -F= -v key="$plan_key" \
        '$1 == key { count++ } END { print count + 0 }' "$plan_path")
    [ "$plan_key_count" -eq 1 ] \
        || fail "runtime-fence pool plan has an invalid ${plan_key} record"
    sed -n "s/^${plan_key}=//p" "$plan_path"
}

pool_member_value()
{
    printf '%s\n' "$(pool_plan_value "member_${1}_${2}")"
}

assert_pool_member_plan()
{
    pool_member=$1
    [ "$(pool_member_value "$pool_member" role)" = "web-${pool_member}" ] \
        || fail "pool member $pool_member role is malformed"
    printf '%s\n' "$(pool_member_value "$pool_member" name)" | grep -E -q \
        '^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$' \
        || fail "pool member $pool_member name is malformed"
    printf '%s\n' "$(pool_member_value "$pool_member" route_identity)" | grep -E -q \
        '^[A-Za-z0-9._:-]{16,128}$' \
        || fail "pool member $pool_member route identity is malformed"
    printf '%s\n' "$(pool_member_value "$pool_member" image_reference)" | grep -E -q \
        '^[A-Za-z0-9][A-Za-z0-9._/:@-]*$' \
        || fail "pool member $pool_member image reference is malformed"
    printf '%s\n' "$(pool_member_value "$pool_member" image_id)" | grep -E -q \
        '^sha256:[a-f0-9]{64}$' \
        || fail "pool member $pool_member image ID is malformed"
    pool_network_ids=$(pool_member_value "$pool_member" network_ids)
    printf '%s\n' "$pool_network_ids" | grep -E -q '^([a-f0-9]{64})(,[a-f0-9]{64})*$' \
        && [ "$(printf '%s' "$pool_network_ids" | tr ',' '\n' | LC_ALL=C sort -u | paste -sd, -)" \
            = "$pool_network_ids" ] \
        || fail "pool member $pool_member network IDs are malformed"
    pool_repin_intent=$(pool_member_value "$pool_member" repin_intent_file)
    case "$pool_repin_intent" in
        /*)
            ;;
        *)
            fail "pool member $pool_member repin intent is malformed"
            ;;
    esac
    printf '%s\n' "$pool_repin_intent" | grep -E -q '^/[A-Za-z0-9_./-]+$' \
        || fail "pool member $pool_member repin intent is malformed"
}

assert_pool_plan()
{
    [ "$(pool_plan_value version)" = 2 ] \
        && [ "$(pool_plan_value operation_id)" = \
            "$(environment_required_value CONTROL_PLANE_RUNTIME_OPERATION_ID)" ] \
        && [ "$(pool_plan_value member_count)" = 2 ] \
        || fail 'pool plan does not belong to the active two-member operation'
    assert_pool_member_plan a
    assert_pool_member_plan b
    [ "$(pool_member_value a name)" != "$(pool_member_value b name)" ] \
        && [ "$(pool_member_value a route_identity)" != "$(pool_member_value b route_identity)" ] \
        || fail 'pool plan duplicates a member authority'
}

assert_candidate_runtime_digest_absent()
{
    [ ! -e "$candidate_runtime_digest" ] && [ ! -L "$candidate_runtime_digest" ] \
        || fail 'candidate runtime digest exists before denial proof'
}

candidate_runtime_digest_value()
{
    [ -f "$candidate_runtime_digest" ] && [ ! -L "$candidate_runtime_digest" ] \
        || fail 'candidate runtime digest is absent or unsafe'
    runtime_digest=$(cat "$candidate_runtime_digest")
    printf '%s\n' "$runtime_digest" | grep -E -q '^[a-f0-9]{64}$' \
        || fail 'candidate runtime digest is malformed'
    [ "$(wc -c < "$candidate_runtime_digest" | tr -d '[:space:]')" = 65 ] \
        || fail 'candidate runtime digest has unexpected bytes'
    printf '%s\n' "$runtime_digest"
}

pin_candidate_runtime_digest()
{
    runtime_container=$1
    observed_runtime_digest=$(canonical_container_runtime_sha256 "$runtime_container")

    if [ -e "$candidate_runtime_digest" ] || [ -L "$candidate_runtime_digest" ]; then
        [ "$(candidate_runtime_digest_value)" = "$observed_runtime_digest" ] \
            || fail 'candidate runtime differs from its post-denial pin'
        return
    fi

    runtime_digest_candidate=${candidate_runtime_digest}.new.$$
    (umask 077; printf '%s\n' "$observed_runtime_digest" > "$runtime_digest_candidate")
    mv "$runtime_digest_candidate" "$candidate_runtime_digest"
    sync
}

assert_candidate_runtime_digest()
{
    runtime_container=$1
    [ "$(candidate_runtime_digest_value)" = \
        "$(canonical_container_runtime_sha256 "$runtime_container")" ] \
        || fail 'candidate runtime differs from its post-denial pin'
}

candidate_container_id()
{
    candidate_name=$1
    candidate_id=$(docker inspect --format '{{.Id}}' "$candidate_name")
    printf '%s\n' "$candidate_id" | grep -E -q '^[a-f0-9]{64}$' \
        || fail 'candidate runtime has a malformed container ID'
    printf '%s\n' "$candidate_id"
}

candidate_started_at()
{
    candidate_name=$1
    candidate_started=$(docker inspect --format '{{.State.StartedAt}}' "$candidate_name")
    printf '%s\n' "$candidate_started" \
        | grep -E -q '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[^[:space:]]+Z$' \
        || fail 'candidate runtime has a malformed StartedAt value'
    printf '%s\n' "$candidate_started"
}

candidate_restart_count()
{
    candidate_name=$1
    candidate_restarts=$(docker inspect --format '{{.RestartCount}}' "$candidate_name")
    printf '%s\n' "$candidate_restarts" | grep -E -q '^[0-9]+$' \
        || fail 'candidate runtime has a malformed RestartCount value'
    printf '%s\n' "$candidate_restarts"
}

candidate_restart_policy()
{
    candidate_name=$1
    candidate_policy=$(docker inspect --format '{{.HostConfig.RestartPolicy.Name}}' "$candidate_name")
    case "$candidate_policy" in
        unless-stopped|always)
            ;;
        *)
            fail 'candidate runtime has an unauthorized restart policy'
            ;;
    esac
    printf '%s\n' "$candidate_policy"
}

candidate_image_id()
{
    candidate_name=$1
    candidate_image=$(docker inspect --format '{{.Image}}' "$candidate_name")
    printf '%s\n' "$candidate_image" | grep -E -q '^sha256:[a-f0-9]{64}$' \
        || fail 'candidate runtime has a malformed image ID'
    printf '%s\n' "$candidate_image"
}

candidate_network_ids()
{
    candidate_name=$1
    candidate_networks=$(docker inspect --format \
        '{{range .NetworkSettings.Networks}}{{.NetworkID}}{{"\n"}}{{end}}' "$candidate_name" \
        | sed '/^$/d' | LC_ALL=C sort -u | paste -sd, -)
    printf '%s\n' "$candidate_networks" \
        | grep -E -q '^([a-f0-9]{64})(,[a-f0-9]{64})*$' \
        || fail 'candidate runtime has an invalid network inventory'
    printf '%s\n' "$candidate_networks"
}

expected_candidate_network_ids()
{
    expected_networks=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_NETWORK_IDS)
    printf '%s\n' "$expected_networks" \
        | grep -E -q '^([a-f0-9]{64})(,[a-f0-9]{64})*$' \
        || fail 'candidate runtime expectation has an invalid network inventory'
    normalized_expected_networks=$(printf '%s' "$expected_networks" \
        | tr ',' '\n' | LC_ALL=C sort -u | paste -sd, -)
    [ "$normalized_expected_networks" = "$expected_networks" ] \
        || fail 'candidate runtime expectation has duplicate or unordered networks'
    printf '%s\n' "$expected_networks"
}

candidate_repin_intent_path()
{
    repin_environment=${1:-$environment}
    repin_intent_file=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_REPIN_INTENT_FILE \
        "$repin_environment")
    case "$repin_intent_file" in
        /*)
            ;;
        *)
            fail 'candidate repin intent path is malformed'
            ;;
    esac
    printf '%s\n' "$repin_intent_file" | grep -E -q '^/[A-Za-z0-9_./-]+$' \
        || fail 'candidate repin intent path is malformed'
    case "$repin_intent_file" in
        *'//'*|'/..'|'/../'*|*'/../'*|*'/..')
            fail 'candidate repin intent path is malformed'
            ;;
    esac
    printf '%s\n' "$repin_intent_file"
}

assert_candidate_repin_intent()
{
    expected_candidate_id=$1
    repin_intent_file=$(candidate_repin_intent_path)
    [ -f "$repin_intent_file" ] && [ ! -L "$repin_intent_file" ] \
        || fail 'candidate repin intent is absent or unsafe'
    awk -F= \
        -v operation="$(environment_value CONTROL_PLANE_RUNTIME_OPERATION_ID)" \
        -v candidate="$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)" \
        -v candidate_id="$expected_candidate_id" \
        -v image_id="$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_IMAGE_ID)" \
        -v image_reference="$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_IMAGE_REFERENCE)" '
        BEGIN {
            expected[1] = "version=1"
            expected[2] = "operation_id=" operation
            expected[3] = "candidate_name=" candidate
            expected[4] = "candidate_id=" candidate_id
            expected[5] = "candidate_image_id=" image_id
            expected[6] = "candidate_image_reference=" image_reference
            expected[7] = "restart_policy=always"
        }
        $0 != expected[NR] { exit 1 }
        END { exit(NR == 7 ? 0 : 1) }
    ' "$repin_intent_file" \
        || fail 'candidate repin intent is malformed or belongs to another runtime'
}

candidate_denial_value()
{
    denial_key=$1
    denial_count=$(grep -E -c "^${denial_key}=" "$candidate_denial" || true)
    [ "$denial_count" -eq 1 ] || fail "candidate denial evidence has an invalid ${denial_key} record"
    sed -n "s/^${denial_key}=//p" "$candidate_denial"
}

probe_candidate_ssh_denied()
{
    denial_candidate=$1
    gateway_file=${state}.candidate-gateways.$$
    probe_output=${state}.candidate-ssh.$$
    candidate_denial_endpoint=
    candidate_denial_result=

    docker inspect --format '{{range .NetworkSettings.Networks}}{{.Gateway}}{{"\n"}}{{end}}' \
        "$denial_candidate" | sed '/^$/d' | LC_ALL=C sort -u > "$gateway_file"
    [ -s "$gateway_file" ] || fail 'candidate has no bridge gateway to prove SSH denial'

    gateway_count=$(wc -l < "$gateway_file" | tr -d '[:space:]')
    gateway_index=1
    while [ "$gateway_index" -le "$gateway_count" ]; do
        gateway=$(sed -n "${gateway_index}p" "$gateway_file")
        case "$gateway" in
            *:*)
                printf '%s\n' "$gateway" | grep -E -q '^[0-9A-Fa-f:.]+$' \
                    || {
                        rm -f "$gateway_file" "$probe_output"
                        fail "candidate bridge gateway is not a valid IPv6 address: $gateway"
                    }
                observed_endpoint="[${gateway}]:22"
                ;;
            *)
                printf '%s\n' "$gateway" | grep -E -q '^([0-9]{1,3}\.){3}[0-9]{1,3}$' \
                    || {
                        rm -f "$gateway_file" "$probe_output"
                        fail "candidate bridge gateway is not a numeric IPv4 address: $gateway"
                    }
                observed_endpoint="${gateway}:22"
                ;;
        esac

        probe_status=0
        docker exec "$denial_candidate" timeout 5 ssh -F /dev/null -o BatchMode=yes \
            -o ConnectTimeout=2 -o ConnectionAttempts=1 -o StrictHostKeyChecking=no \
            -o UserKnownHostsFile=/dev/null -p 22 "root@$gateway" true \
            > /dev/null 2> "$probe_output" || probe_status=$?
        if [ "$probe_status" -ne 255 ] || ! grep -F -q 'timed out' "$probe_output"; then
            observed_output=$(tr '\n' ' ' < "$probe_output")
            rm -f "$gateway_file" "$probe_output"
            fail "candidate SSH probe did not produce the required timeout: status=${probe_status} output=${observed_output}"
        fi
        observed_result=timeout

        candidate_denial_endpoint="${candidate_denial_endpoint:+${candidate_denial_endpoint},}${observed_endpoint}"
        candidate_denial_result="${candidate_denial_result:+${candidate_denial_result},}${observed_result}"
        gateway_index=$((gateway_index + 1))
    done

    rm -f "$gateway_file" "$probe_output"
    [ -n "$candidate_denial_endpoint" ] && [ -n "$candidate_denial_result" ] \
        || fail 'candidate SSH denial probe did not observe a bridge gateway'
}

record_candidate_denial()
{
    denial_candidate=$1
    denial_id=$2
    denial_candidate_file=${candidate_denial}.new.$$
    denial_started_at=$(candidate_started_at "$denial_candidate")
    denial_restart_count=$(candidate_restart_count "$denial_candidate")
    denial_restart_policy=$(candidate_restart_policy "$denial_candidate")
    {
        printf 'candidate_denial_operation_id=%s\n' \
            "$(environment_value CONTROL_PLANE_RUNTIME_OPERATION_ID)"
        printf 'candidate_denial_name=%s\n' "$denial_candidate"
        printf 'candidate_denial_id=%s\n' "$denial_id"
        printf 'candidate_denial_started_at=%s\n' "$denial_started_at"
        printf 'candidate_denial_restart_count=%s\n' "$denial_restart_count"
        printf 'candidate_restart_policy=%s\n' "$denial_restart_policy"
        printf 'candidate_denial_endpoint=%s\n' "$candidate_denial_endpoint"
        printf 'candidate_denial_result=%s\n' "$candidate_denial_result"
        printf 'candidate_denial_proven_at_epoch=%s\n' "$(date -u +%s)"
    } > "$denial_candidate_file"
    sync
    mv "$denial_candidate_file" "$candidate_denial"
    sync
}

assert_candidate_denial()
{
    denial_candidate=$1
    [ -f "$candidate_denial" ] && [ ! -L "$candidate_denial" ] \
        || fail 'candidate SSH-denial evidence is absent or unsafe'
    denial_id=$(candidate_container_id "$denial_candidate")
    denial_started_at=$(candidate_started_at "$denial_candidate")
    denial_restart_count=$(candidate_restart_count "$denial_candidate")
    denial_restart_policy=$(candidate_restart_policy "$denial_candidate")
    recorded_endpoint=$(candidate_denial_value candidate_denial_endpoint)
    recorded_result=$(candidate_denial_value candidate_denial_result)
    recorded_epoch=$(candidate_denial_value candidate_denial_proven_at_epoch)
    [ "$(candidate_denial_value candidate_denial_operation_id)" = \
        "$(environment_value CONTROL_PLANE_RUNTIME_OPERATION_ID)" ] \
        && [ "$(candidate_denial_value candidate_denial_name)" = "$denial_candidate" ] \
        && [ "$(candidate_denial_value candidate_denial_id)" = "$denial_id" ] \
        && [ "$(candidate_denial_value candidate_denial_started_at)" = "$denial_started_at" ] \
        && [ "$(candidate_denial_value candidate_denial_restart_count)" = "$denial_restart_count" ] \
        && [ "$(candidate_denial_value candidate_restart_policy)" = "$denial_restart_policy" ] \
        || fail 'candidate SSH-denial evidence does not match the active candidate'
    if ! {
        printf '%s\n' "$recorded_endpoint" | grep -E -q \
            '^(([0-9]{1,3}\.){3}[0-9]{1,3}|\[[0-9A-Fa-f:.]+\]):22(,(([0-9]{1,3}\.){3}[0-9]{1,3}|\[[0-9A-Fa-f:.]+\]):22)*$' \
            && printf '%s\n' "$recorded_result" | grep -E -q \
                '^timeout(,timeout)*$' \
            && printf '%s\n' "$recorded_epoch" | grep -E -q '^[1-9][0-9]*$'
    }; then
        fail 'candidate SSH-denial evidence has malformed probe details'
    fi
    recorded_endpoint_count=$(printf '%s\n' "$recorded_endpoint" | awk -F, '{print NF}')
    recorded_result_count=$(printf '%s\n' "$recorded_result" | awk -F, '{print NF}')
    [ "$recorded_endpoint_count" = "$recorded_result_count" ] \
        || fail 'candidate SSH-denial evidence has an incomplete result inventory'

    probe_candidate_ssh_denied "$denial_candidate"
    [ "$recorded_endpoint" = "$candidate_denial_endpoint" ] \
        && [ "$recorded_result" = "$candidate_denial_result" ] \
        || fail 'candidate SSH-denial evidence does not cover each active bridge gateway'
}

member_runtime_digest_value()
{
    member_digest_file=$1
    [ -f "$member_digest_file" ] && [ ! -L "$member_digest_file" ] \
        || fail 'pool member runtime digest is absent or unsafe'
    member_runtime_digest=$(cat "$member_digest_file")
    printf '%s\n' "$member_runtime_digest" | grep -E -q '^[a-f0-9]{64}$' \
        && [ "$(wc -c < "$member_digest_file" | tr -d '[:space:]')" = 65 ] \
        || fail 'pool member runtime digest is malformed'
    printf '%s\n' "$member_runtime_digest"
}

pin_member_runtime_digest()
{
    member_container=$1
    member_digest_file=$2
    member_observed_digest=$(canonical_container_runtime_sha256 "$member_container")
    if [ -e "$member_digest_file" ] || [ -L "$member_digest_file" ]; then
        [ "$(member_runtime_digest_value "$member_digest_file")" = "$member_observed_digest" ] \
            || fail 'pool member runtime differs from its denial pin'
        return
    fi
    member_digest_candidate=${member_digest_file}.new.$$
    (umask 077; printf '%s\n' "$member_observed_digest" > "$member_digest_candidate")
    mv "$member_digest_candidate" "$member_digest_file"
    sync
}

assert_member_runtime_digest()
{
    member_container=$1
    member_digest_file=$2
    [ "$(member_runtime_digest_value "$member_digest_file")" = \
        "$(canonical_container_runtime_sha256 "$member_container")" ] \
        || fail 'pool member runtime differs from its denial pin'
}

member_b_identity_value()
{
    [ -f "$member_b_identity" ] && [ ! -L "$member_b_identity" ] \
        || fail 'web-b identity is absent or unsafe'
    member_b_recorded_id=$(cat "$member_b_identity")
    printf '%s\n' "$member_b_recorded_id" | grep -E -q '^[a-f0-9]{64}$' \
        && [ "$(wc -c < "$member_b_identity" | tr -d '[:space:]')" = 65 ] \
        || fail 'web-b identity is malformed'
    printf '%s\n' "$member_b_recorded_id"
}

assert_pool_member_static()
{
    member_role=$1
    member_container=$(pool_member_value "$member_role" name)
    docker inspect "$member_container" >/dev/null
    [ "$(candidate_image_id "$member_container")" = \
        "$(pool_member_value "$member_role" image_id)" ] \
        && [ "$(docker inspect --format '{{.Config.Image}}' "$member_container")" \
            = "$(pool_member_value "$member_role" image_reference)" ] \
        && [ "$(candidate_network_ids "$member_container")" \
            = "$(pool_member_value "$member_role" network_ids)" ] \
        || fail "pool member $member_role differs from its signed static plan"
}

assert_pool_member_repin_intent()
{
    member_role=$1
    member_id=$2
    member_intent=$(pool_member_value "$member_role" repin_intent_file)
    [ -f "$member_intent" ] && [ ! -L "$member_intent" ] \
        && [ "$(stat -c '%a' "$member_intent")" = 600 ] \
        || fail "pool member $member_role repin intent is absent or unsafe"
    awk -F= \
        -v operation="$(environment_required_value CONTROL_PLANE_RUNTIME_OPERATION_ID)" \
        -v candidate="$(pool_member_value "$member_role" name)" \
        -v candidate_id="$member_id" \
        -v image_id="$(pool_member_value "$member_role" image_id)" \
        -v image_reference="$(pool_member_value "$member_role" image_reference)" '
        BEGIN {
            expected[1] = "version=1"
            expected[2] = "operation_id=" operation
            expected[3] = "candidate_name=" candidate
            expected[4] = "candidate_id=" candidate_id
            expected[5] = "candidate_image_id=" image_id
            expected[6] = "candidate_image_reference=" image_reference
            expected[7] = "restart_policy=always"
        }
        $0 != expected[NR] { exit 1 }
        END { exit(NR == 7 ? 0 : 1) }
    ' "$member_intent" \
        || fail "pool member $member_role repin intent is malformed"
}

assert_pool_members_pinned()
{
    pool_member_a=$(pool_member_value a name)
    pool_member_b=$(pool_member_value b name)
    assert_candidate_denial "$pool_member_a"
    assert_candidate_runtime_digest "$pool_member_a"
    [ "$(member_b_identity_value)" = "$(candidate_container_id "$pool_member_b")" ] \
        || fail 'web-b identity changed after denial proof'
    assert_member_runtime_digest "$pool_member_b" "$member_b_runtime_digest"
    probe_candidate_ssh_denied "$pool_member_b"
}

routed_runtime_recovery_kind()
{
    recovery_kind_count=$(grep -E -c '^recovery_kind=' "$daemon_recovery" || true)
    case "$recovery_kind_count" in
        0) printf '%s\n' daemon ;;
        1)
            recovery_kind=$(sed -n 's/^recovery_kind=//p' "$daemon_recovery")
            case "$recovery_kind" in
                daemon|candidate) printf '%s\n' "$recovery_kind" ;;
                *) fail 'routed runtime recovery kind is invalid' ;;
            esac
            ;;
        *) fail 'routed runtime recovery kind is duplicated' ;;
    esac
}

assert_daemon_recovery_kind()
{
    [ "$(routed_runtime_recovery_kind)" = daemon ] \
        || fail 'legacy daemon recovery cannot use a candidate runtime lineage'
}

daemon_recovery_value()
{
    recovery_key=$1
    recovery_count=$(grep -E -c "^${recovery_key}=" "$daemon_recovery" || true)
    [ "$recovery_count" -eq 1 ] \
        || fail "daemon recovery has an invalid ${recovery_key} record"
    sed -n "s/^${recovery_key}=//p" "$daemon_recovery"
}

assert_daemon_recovery_context()
{
    expected_direction=$1
    expected_color=$2
    expected_parent_phase=$3
    expected_provider_expectation=$4
    [ "$(daemon_recovery_value operation_id)" = \
        "$(environment_value CONTROL_PLANE_RUNTIME_OPERATION_ID)" ] \
        && [ "$(daemon_recovery_value direction)" = "$expected_direction" ] \
        && [ "$(daemon_recovery_value color)" = "$expected_color" ] \
        && [ "$(daemon_recovery_value parent_phase)" = "$expected_parent_phase" ] \
        && [ "$(daemon_recovery_value provider_expectation)" = "$expected_provider_expectation" ] \
        || fail 'daemon recovery belongs to another routed candidate context'
}

assert_daemon_recovery_current()
{
    recovery_candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
    recovery_id=$(candidate_container_id "$recovery_candidate")
    recovery_started_at=$(candidate_started_at "$recovery_candidate")
    recovery_restart_count=$(candidate_restart_count "$recovery_candidate")
    recovery_restart_policy=$(candidate_restart_policy "$recovery_candidate")
    [ -f "$daemon_recovery" ] && [ ! -L "$daemon_recovery" ] \
        || fail 'daemon recovery evidence is absent or unsafe'
    assert_daemon_recovery_kind
    printf '%s\n' "$(daemon_recovery_value generation)" | grep -E -q '^[1-9][0-9]*$' \
        || fail 'daemon recovery generation is malformed'
    case "$(daemon_recovery_value status)" in
        runtime-verified|verified)
            ;;
        *)
            fail 'daemon recovery status is not durable'
            ;;
    esac
    [ "$(daemon_recovery_value candidate_id)" = "$recovery_id" ] \
        && [ "$(daemon_recovery_value candidate_started_at)" = "$recovery_started_at" ] \
        && [ "$(daemon_recovery_value candidate_restart_count)" = "$recovery_restart_count" ] \
        && [ "$(daemon_recovery_value candidate_restart_policy)" = "$recovery_restart_policy" ] \
        && [ "$recovery_restart_policy" = always ] \
        || fail 'daemon recovery evidence does not match the fresh exact candidate runtime'
}

write_daemon_recovery()
{
    recovery_direction=$1
    recovery_color=$2
    recovery_parent_phase=$3
    recovery_provider_expectation=$4
    recovery_generation=$5
    recovery_candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
    recovery_candidate_file=${daemon_recovery}.new.$$
    {
        printf 'version=1\n'
        printf 'operation_id=%s\n' "$(environment_value CONTROL_PLANE_RUNTIME_OPERATION_ID)"
        printf 'generation=%s\n' "$recovery_generation"
        printf 'direction=%s\n' "$recovery_direction"
        printf 'color=%s\n' "$recovery_color"
        printf 'parent_phase=%s\n' "$recovery_parent_phase"
        printf 'provider_expectation=%s\n' "$recovery_provider_expectation"
        printf 'candidate_id=%s\n' "$(candidate_container_id "$recovery_candidate")"
        printf 'candidate_started_at=%s\n' "$(candidate_started_at "$recovery_candidate")"
        printf 'candidate_restart_count=%s\n' "$(candidate_restart_count "$recovery_candidate")"
        printf 'candidate_restart_policy=%s\n' "$(candidate_restart_policy "$recovery_candidate")"
        printf 'status=runtime-verified\n'
    } > "$recovery_candidate_file"
    sync
    mv "$recovery_candidate_file" "$daemon_recovery"
    sync
}

observe_routed_candidate_recovery()
{
    recovery_candidate=$1
    recovery_candidate_id=$(candidate_container_id "$recovery_candidate")
    recovery_candidate_image_id=$(candidate_image_id "$recovery_candidate")
    recovery_candidate_network_ids=$(candidate_network_ids "$recovery_candidate")
    recovery_candidate_started_at=$(candidate_started_at "$recovery_candidate")
    recovery_candidate_restart_count=$(candidate_restart_count "$recovery_candidate")
    recovery_candidate_restart_policy=$(candidate_restart_policy "$recovery_candidate")
    expected_candidate_image_id=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_IMAGE_ID)
    printf '%s\n' "$expected_candidate_image_id" | grep -E -q '^sha256:[a-f0-9]{64}$' \
        || fail 'candidate runtime expectation has a malformed image ID'
    expected_candidate_networks=$(expected_candidate_network_ids)

    [ "$(candidate_denial_value candidate_denial_id)" = "$recovery_candidate_id" ] \
        && [ "$recovery_candidate_image_id" = "$expected_candidate_image_id" ] \
        && [ "$recovery_candidate_network_ids" = "$expected_candidate_networks" ] \
        && [ "$recovery_candidate_restart_policy" = always ] \
        || fail 'routed candidate recovery changed its exact ID, image, network, or restart policy'
    assert_candidate_runtime_digest "$recovery_candidate"
    recovery_candidate_runtime_sha256=$(candidate_runtime_digest_value)
}

assert_routed_candidate_recovery_fresh()
{
    [ "$recovery_candidate_started_at" \
        != "$(candidate_denial_value candidate_denial_started_at)" ] \
        || fail 'routed candidate recovery did not observe a fresh StartedAt value'
    [ "$recovery_candidate_restart_count" \
        -ge "$(candidate_denial_value candidate_denial_restart_count)" ] \
        || fail 'routed candidate recovery restart count moved backwards'
}

assert_routed_candidate_recovery_context()
{
    expected_direction=$1
    expected_color=$2
    expected_parent_phase=$3
    expected_provider_expectation=$4
    [ "$(routed_runtime_recovery_kind)" = candidate ] \
        || fail 'routed candidate recovery does not use the candidate runtime lineage'
    assert_daemon_recovery_context "$expected_direction" "$expected_color" \
        "$expected_parent_phase" "$expected_provider_expectation"
}

assert_routed_candidate_recovery_current()
{
    recovery_candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
    [ -f "$daemon_recovery" ] && [ ! -L "$daemon_recovery" ] \
        || fail 'routed candidate recovery evidence is absent or unsafe'
    printf '%s\n' "$(daemon_recovery_value generation)" | grep -E -q '^[1-9][0-9]*$' \
        || fail 'routed candidate recovery generation is malformed'
    case "$(daemon_recovery_value status)" in
        runtime-verified|verified)
            ;;
        *)
            fail 'routed candidate recovery status is not durable'
            ;;
    esac
    observe_routed_candidate_recovery "$recovery_candidate"
    [ "$(daemon_recovery_value candidate_id)" = "$recovery_candidate_id" ] \
        && [ "$(daemon_recovery_value candidate_image_id)" = "$recovery_candidate_image_id" ] \
        && [ "$(daemon_recovery_value candidate_network_ids)" \
            = "$recovery_candidate_network_ids" ] \
        && [ "$(daemon_recovery_value candidate_runtime_sha256)" \
            = "$recovery_candidate_runtime_sha256" ] \
        && [ "$(daemon_recovery_value candidate_started_at)" \
            = "$recovery_candidate_started_at" ] \
        && [ "$(daemon_recovery_value candidate_restart_count)" \
            = "$recovery_candidate_restart_count" ] \
        && [ "$(daemon_recovery_value candidate_restart_policy)" \
            = "$recovery_candidate_restart_policy" ] \
        || fail 'routed candidate recovery evidence does not match the fresh exact runtime'
}

write_routed_candidate_recovery()
{
    recovery_direction=$1
    recovery_color=$2
    recovery_parent_phase=$3
    recovery_provider_expectation=$4
    recovery_generation=$5
    recovery_candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
    observe_routed_candidate_recovery "$recovery_candidate"
    assert_routed_candidate_recovery_fresh
    recovery_candidate_file=${daemon_recovery}.new.$$
    {
        printf 'version=2\n'
        printf 'operation_id=%s\n' "$(environment_value CONTROL_PLANE_RUNTIME_OPERATION_ID)"
        printf 'generation=%s\n' "$recovery_generation"
        printf 'direction=%s\n' "$recovery_direction"
        printf 'color=%s\n' "$recovery_color"
        printf 'parent_phase=%s\n' "$recovery_parent_phase"
        printf 'provider_expectation=%s\n' "$recovery_provider_expectation"
        printf '%s\n' 'recovery_kind=candidate'
        printf 'candidate_id=%s\n' "$recovery_candidate_id"
        printf 'candidate_image_id=%s\n' "$recovery_candidate_image_id"
        printf 'candidate_network_ids=%s\n' "$recovery_candidate_network_ids"
        printf 'candidate_runtime_sha256=%s\n' "$recovery_candidate_runtime_sha256"
        printf 'candidate_started_at=%s\n' "$recovery_candidate_started_at"
        printf 'candidate_restart_count=%s\n' "$recovery_candidate_restart_count"
        printf 'candidate_restart_policy=%s\n' "$recovery_candidate_restart_policy"
        printf '%s\n' 'status=runtime-verified'
    } > "$recovery_candidate_file"
    sync
    mv "$recovery_candidate_file" "$daemon_recovery"
    sync
}

ingress_pool_value()
{
    ingress_key=$1
    ingress_key_count=$(awk -F= -v key="$ingress_key" \
        '$1 == key { count++ } END { print count + 0 }' "$ingress_pool_manifest")
    [ "$ingress_key_count" -eq 1 ] \
        || fail "ingress pool has an invalid ${ingress_key} record"
    sed -n "s/^${ingress_key}=//p" "$ingress_pool_manifest"
}

assert_ingress_pool_member()
{
    ingress_member=$1
    ingress_container=$(pool_member_value "$ingress_member" name)
    [ "$(ingress_pool_value "member_${ingress_member}_role")" = "web-${ingress_member}" ] \
        && [ "$(ingress_pool_value "member_${ingress_member}_name")" = "$ingress_container" ] \
        && [ "$(ingress_pool_value "member_${ingress_member}_route_identity")" \
            = "$(pool_member_value "$ingress_member" route_identity)" ] \
        && [ "$(ingress_pool_value "member_${ingress_member}_id")" \
            = "$(candidate_container_id "$ingress_container")" ] \
        && [ "$(ingress_pool_value "member_${ingress_member}_image_reference")" \
            = "$(pool_member_value "$ingress_member" image_reference)" ] \
        && [ "$(ingress_pool_value "member_${ingress_member}_image_id")" \
            = "$(pool_member_value "$ingress_member" image_id)" ] \
        && [ "$(ingress_pool_value "member_${ingress_member}_runtime_sha256")" \
            = "$(member_runtime_digest_value "$( [ "$ingress_member" = a ] \
                && printf '%s\n' "$candidate_runtime_digest" \
                || printf '%s\n' "$member_b_runtime_digest" )")" ] \
        || fail "ingress pool member $ingress_member differs from its denial pin"
}

pin_ingress_pool_manifest()
{
    ingress_expected_sha256=$1
    printf '%s\n' "$ingress_expected_sha256" | grep -E -q '^[a-f0-9]{64}$' \
        || fail 'ingress pool pin checksum is malformed'
    ingress_pool_manifest=$(pool_plan_value ingress_pool_manifest_path)
    [ -f "$ingress_pool_manifest" ] && [ ! -L "$ingress_pool_manifest" ] \
        && [ "$(sha256sum "$ingress_pool_manifest" | awk '{print $1}')" = "$ingress_expected_sha256" ] \
        || fail 'ingress pool manifest differs from its requested checksum'
    [ "$(ingress_pool_value version)" = 2 ] \
        && [ "$(ingress_pool_value operation_id)" = \
            "$(environment_required_value CONTROL_PLANE_RUNTIME_OPERATION_ID)" ] \
        && [ "$(ingress_pool_value parent_pool_plan_sha256)" = \
            "$(environment_required_value CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST_SHA256)" ] \
        && [ "$(ingress_pool_value member_count)" = 2 ] \
        || fail 'ingress pool does not descend from the active signed plan'
    assert_ingress_pool_member a
    assert_ingress_pool_member b
    if ! {
        [ "$(ingress_pool_value member_a_id)" != "$(ingress_pool_value member_b_id)" ] \
            && printf '%s\n' "$(ingress_pool_value member_set_sha256)" \
                | grep -E -q '^[a-f0-9]{64}$'
    }; then
        fail 'ingress pool member set is malformed'
    fi
    if [ -e "$ingress_pool_manifest_pin" ] || [ -L "$ingress_pool_manifest_pin" ]; then
        [ -f "$ingress_pool_manifest_pin" ] && [ ! -L "$ingress_pool_manifest_pin" ] \
            && [ "$(cat "$ingress_pool_manifest_pin")" = "$ingress_expected_sha256" ] \
            || fail 'ingress pool manifest changed after its first pin'
    else
        ingress_pin_candidate=${ingress_pool_manifest_pin}.new.$$
        (umask 077; printf '%s\n' "$ingress_expected_sha256" > "$ingress_pin_candidate")
        mv "$ingress_pin_candidate" "$ingress_pool_manifest_pin"
        sync
    fi
    [ "$(sha256sum "$ingress_pool_manifest" | awk '{print $1}')" = "$ingress_expected_sha256" ] \
        || fail 'ingress pool manifest changed while it was being pinned'
}

case "$action" in
    prepare)
        environment_file=${2:-}
        [ -f "$environment_file" ] && [ ! -L "$environment_file" ]
        candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER "$environment_file")
        incumbent=$(environment_value CONTROL_PLANE_RUNTIME_LEGACY_CONTAINER "$environment_file")
        operation=$(environment_value CONTROL_PLANE_RUNTIME_OPERATION_ID "$environment_file")
        [ -n "$candidate" ] && [ -n "$incumbent" ] && [ -n "$operation" ]
        candidate_repin_intent_path "$environment_file" >/dev/null
        case "$(phase)" in
            new|prepared)
                ;;
            finalized|aborted)
                rm -f "$candidate_denial" "$candidate_runtime_digest" "$member_b_runtime_digest" \
                    "$member_b_identity" "$ingress_pool_manifest_pin" "$daemon_recovery"
                ;;
            *)
                exit 1
                ;;
        esac
        assert_candidate_runtime_digest_absent
        cp "$environment_file" "${environment}.new"
        mv "${environment}.new" "$environment"
        if awk -F= '$1 == "CONTROL_PLANE_RUNTIME_POOL_PLAN_MANIFEST" { found = 1 }
            END { exit(found ? 0 : 1) }' "$environment"; then
            assert_pool_plan
        fi
        transition prepared
        ;;
    capture)
        [ "$(phase)" = prepared ] || [ "$(phase)" = captured ]
        candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
        incumbent=$(environment_value CONTROL_PLANE_RUNTIME_LEGACY_CONTAINER)
        ! docker inspect "$candidate" >/dev/null 2>&1
        docker inspect "$incumbent" >/dev/null
        assert_candidate_runtime_digest_absent
        transition captured
        ;;
    arm)
        [ "$(phase)" = captured ] || [ "$(phase)" = active ]
        candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
        ! docker inspect "$candidate" >/dev/null 2>&1
        assert_candidate_runtime_digest_absent
        transition active
        ;;
    assert-pool-baseline)
        [ "$#" -eq 1 ] && [ "$(phase)" = active ] || fail 'pool baseline is invalid'
        assert_pool_plan
        pool_web_a=$(pool_member_value a name)
        pool_web_b=$(pool_member_value b name)
        assert_pool_member_static a
        assert_pool_member_static b
        if [ -e "$candidate_denial" ] || [ -L "$candidate_denial" ] \
            || [ -e "$member_b_identity" ] || [ -L "$member_b_identity" ] \
            || [ -e "$member_b_runtime_digest" ] || [ -L "$member_b_runtime_digest" ]; then
            [ -f "$candidate_denial" ] && [ ! -L "$candidate_denial" ] \
                && [ -f "$candidate_runtime_digest" ] && [ ! -L "$candidate_runtime_digest" ] \
                && [ -f "$member_b_identity" ] && [ ! -L "$member_b_identity" ] \
                && [ -f "$member_b_runtime_digest" ] && [ ! -L "$member_b_runtime_digest" ] \
                || fail 'pool baseline has partial denial evidence'
            assert_pool_members_pinned
        else
            [ "$(candidate_restart_policy "$pool_web_a")" = unless-stopped ] \
                && [ "$(candidate_restart_policy "$pool_web_b")" = unless-stopped ] \
                || fail 'unproven pool member restart policy is not unless-stopped'
            assert_candidate_runtime_digest_absent
            [ ! -e "$member_b_runtime_digest" ] && [ ! -L "$member_b_runtime_digest" ] \
                || fail 'web-b runtime digest exists before denial proof'
        fi
        printf '%s\n' assert-pool-baseline >> "$log"
        ;;
    prove-pool-denied)
        [ "$#" -eq 1 ] && [ "$(phase)" = active ] || fail 'pool denial is invalid'
        assert_pool_plan
        pool_web_a=$(pool_member_value a name)
        pool_web_b=$(pool_member_value b name)
        assert_pool_member_static a
        assert_pool_member_static b
        pool_web_a_id=$(candidate_container_id "$pool_web_a")
        pool_web_b_id=$(candidate_container_id "$pool_web_b")
        if [ -e "$candidate_denial" ] || [ -L "$candidate_denial" ]; then
            assert_candidate_denial "$pool_web_a"
        else
            [ "$(candidate_restart_policy "$pool_web_a")" = unless-stopped ] \
                || fail 'initial web-a denial requires restart policy unless-stopped'
            probe_candidate_ssh_denied "$pool_web_a"
            record_candidate_denial "$pool_web_a" "$pool_web_a_id"
        fi
        pin_candidate_runtime_digest "$pool_web_a"
        if [ -e "$member_b_identity" ] || [ -L "$member_b_identity" ] \
            || [ -e "$member_b_runtime_digest" ] || [ -L "$member_b_runtime_digest" ]; then
            [ "$(member_b_identity_value)" = "$pool_web_b_id" ] \
                || fail 'web-b changed after its first denial proof'
        else
            [ "$(candidate_restart_policy "$pool_web_b")" = unless-stopped ] \
                || fail 'initial web-b denial requires restart policy unless-stopped'
            probe_candidate_ssh_denied "$pool_web_b"
            (umask 077; printf '%s\n' "$pool_web_b_id" > "${member_b_identity}.new.$$")
            mv "${member_b_identity}.new.$$" "$member_b_identity"
        fi
        pin_member_runtime_digest "$pool_web_b" "$member_b_runtime_digest"
        assert_pool_members_pinned
        printf '%s\n' prove-pool-denied prove-candidate-denied >> "$log"
        ;;
    pin-ingress-pool-manifest)
        [ "$#" -eq 2 ] && [ "$(phase)" = active ] \
            || fail 'ingress pool pin is invalid'
        assert_pool_plan
        assert_pool_members_pinned
        pin_ingress_pool_manifest "$2"
        printf '%s\n' pin-ingress-pool-manifest >> "$log"
        ;;
    repin-pool)
        [ "$#" -eq 3 ] && [ "$(phase)" = active ] || fail 'pool repin is invalid'
        printf '%s\n%s\n' "$2" "$3" | grep -E -q '^[a-f0-9]{64}$' \
            && [ "$(printf '%s\n%s\n' "$2" "$3" | grep -E -c '^[a-f0-9]{64}$')" = 2 ] \
            || fail 'pool repin IDs are malformed'
        assert_pool_plan
        pool_web_a=$(pool_member_value a name)
        pool_web_b=$(pool_member_value b name)
        assert_pool_member_static a
        assert_pool_member_static b
        [ "$(candidate_container_id "$pool_web_a")" = "$2" ] \
            && [ "$(candidate_denial_value candidate_denial_id)" = "$2" ] \
            && [ "$(candidate_container_id "$pool_web_b")" = "$3" ] \
            && [ "$(member_b_identity_value)" = "$3" ] \
            || fail 'pool repin changed an exact denied member ID'
        assert_pool_member_repin_intent a "$2"
        assert_pool_member_repin_intent b "$3"
        [ "$(candidate_restart_policy "$pool_web_a")" = always ] \
            && [ "$(candidate_restart_policy "$pool_web_b")" = always ] \
            || fail 'pool repin did not observe restart policy always for both members'
        assert_candidate_runtime_digest "$pool_web_a"
        assert_member_runtime_digest "$pool_web_b" "$member_b_runtime_digest"
        probe_candidate_ssh_denied "$pool_web_a"
        record_candidate_denial "$pool_web_a" "$2"
        probe_candidate_ssh_denied "$pool_web_b"
        assert_pool_members_pinned
        printf '%s\n' repin-pool repin-candidate >> "$log"
        ;;
    assert-candidate-baseline)
        [ "$(phase)" = active ]
        candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
        [ "${2:-}" = "$candidate" ]
        docker inspect "$candidate" >/dev/null
        if [ -e "$candidate_denial" ] || [ -L "$candidate_denial" ]; then
            assert_candidate_denial "$candidate"
            assert_candidate_runtime_digest "$candidate"
        else
            [ "$(candidate_restart_policy "$candidate")" = unless-stopped ] \
                || fail 'unproven candidate restart policy is not unless-stopped'
            assert_candidate_runtime_digest_absent
        fi
        printf '%s\n' assert-candidate-baseline >> "$log"
        ;;
    verify)
        [ "$(phase)" = active ]
        [ ! -e "${state}.runtime-drift" ]
        incumbent=$(environment_value CONTROL_PLANE_RUNTIME_LEGACY_CONTAINER)
        docker inspect "$incumbent" >/dev/null
        candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
        if docker inspect "$candidate" >/dev/null 2>&1; then
            if [ -e "$candidate_denial" ] || [ -L "$candidate_denial" ]; then
                assert_candidate_denial "$candidate"
                assert_candidate_runtime_digest "$candidate"
            else
                assert_candidate_runtime_digest_absent
            fi
        else
            assert_candidate_runtime_digest_absent
        fi
        printf '%s\n' verify >> "$log"
        ;;
    verify-post-revoke)
        [ "$(phase)" = active ]
        [ ! -e "${state}.runtime-drift" ]
        incumbent=$(environment_value CONTROL_PLANE_RUNTIME_LEGACY_CONTAINER)
        candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
        ! docker inspect "$incumbent" >/dev/null 2>&1
        assert_candidate_denial "$candidate"
        assert_candidate_runtime_digest "$candidate"
        printf '%s\n' verify-post-revoke >> "$log"
        ;;
    prove-candidate-denied)
        [ "$(phase)" = active ]
        candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
        [ "${2:-}" = "$candidate" ]
        candidate_id=$(candidate_container_id "$candidate")
        [ "$(candidate_restart_policy "$candidate")" = unless-stopped ] \
            || fail 'initial candidate denial requires restart policy unless-stopped'
        if [ -e "$candidate_denial" ] || [ -L "$candidate_denial" ]; then
            assert_candidate_denial "$candidate"
        else
            probe_candidate_ssh_denied "$candidate"
            record_candidate_denial "$candidate" "$candidate_id"
        fi
        pin_candidate_runtime_digest "$candidate"
        assert_candidate_runtime_digest "$candidate"
        printf '%s\n' prove-candidate-denied >> "$log"
        ;;
    repin-candidate)
        [ "$(phase)" = active ]
        candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
        [ "$#" -eq 2 ]
        candidate_id=$(candidate_container_id "$candidate")
        printf '%s\n' "${2:-}" | grep -E -q '^[a-f0-9]{64}$' \
            || fail 'candidate repin target has a malformed container ID'
        [ "$candidate_id" = "$2" ] \
            && [ "$(candidate_denial_value candidate_denial_id)" = "$candidate_id" ] \
            || fail 'candidate repin changed the exact denied container ID'
        assert_candidate_repin_intent "$candidate_id"
        [ "$(candidate_restart_policy "$candidate")" = always ] \
            || fail 'candidate repin did not observe restart policy always'
        assert_candidate_runtime_digest "$candidate"
        probe_candidate_ssh_denied "$candidate"
        record_candidate_denial "$candidate" "$candidate_id"
        assert_candidate_denial "$candidate"
        printf '%s\n' repin-candidate >> "$log"
        ;;
    recover-daemon)
        [ "$#" -eq 5 ] || fail 'daemon recovery has an invalid argument count'
        recovery_direction=$2
        recovery_color=$3
        recovery_parent_phase=$4
        recovery_provider_expectation=$5
        case "$recovery_direction:$recovery_color" in
            forward:green|reverse:blue)
                ;;
            *)
                fail 'daemon recovery direction or candidate color is invalid'
                ;;
        esac
        case "$recovery_provider_expectation" in
            incumbent|absent)
                ;;
            *)
                fail 'daemon recovery provider expectation is invalid'
                ;;
        esac
        printf '%s\n' "$recovery_parent_phase" | grep -E -q '^[A-Za-z0-9_.-]+$' \
            || fail 'daemon recovery parent phase is invalid'
        case "$(phase)" in
            active|released|finalized)
                ;;
            *)
                fail 'daemon recovery requires an active or durably released fence'
                ;;
        esac
        candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
        candidate_id=$(candidate_container_id "$candidate")
        candidate_started=$(candidate_started_at "$candidate")
        candidate_restarts=$(candidate_restart_count "$candidate")
        [ "$(candidate_denial_value candidate_denial_id)" = "$candidate_id" ] \
            && [ "$(candidate_restart_policy "$candidate")" = always ] \
            || fail 'daemon recovery changed the exact routed candidate or its restart policy'
        [ "$candidate_restarts" -ge "$(candidate_denial_value candidate_denial_restart_count)" ] \
            || fail 'daemon recovery restart count moved backwards'
        [ "$candidate_started" != "$(candidate_denial_value candidate_denial_started_at)" ] \
            || [ "$candidate_restarts" != "$(candidate_denial_value candidate_denial_restart_count)" ] \
            || fail 'daemon recovery did not observe a fresh candidate runtime'
        assert_candidate_runtime_digest "$candidate"
        probe_candidate_ssh_denied "$candidate"
        recovery_generation=1
        recovery_reused=false
        if [ -e "$daemon_recovery" ] || [ -L "$daemon_recovery" ]; then
            [ -f "$daemon_recovery" ] && [ ! -L "$daemon_recovery" ] \
                || fail 'daemon recovery evidence is unsafe'
            assert_daemon_recovery_kind
            recovery_generation=$(daemon_recovery_value generation)
            printf '%s\n' "$recovery_generation" | grep -E -q '^[1-9][0-9]*$' \
                || fail 'daemon recovery generation is malformed'
            if [ "$(daemon_recovery_value candidate_id)" = "$candidate_id" ] \
                && [ "$(daemon_recovery_value candidate_started_at)" = "$candidate_started" ] \
                && [ "$(daemon_recovery_value candidate_restart_count)" = "$candidate_restarts" ]; then
                assert_daemon_recovery_context "$recovery_direction" "$recovery_color" \
                    "$recovery_parent_phase" "$recovery_provider_expectation"
                assert_daemon_recovery_current
                recovery_reused=true
            else
                [ "$(daemon_recovery_value status)" = verified ] \
                    || fail 'daemon recovery changed before its previous generation was finalized'
                recovery_generation=$((recovery_generation + 1))
            fi
        fi
        if [ "$recovery_reused" = false ]; then
            write_daemon_recovery "$recovery_direction" "$recovery_color" \
                "$recovery_parent_phase" "$recovery_provider_expectation" "$recovery_generation"
            assert_daemon_recovery_context "$recovery_direction" "$recovery_color" \
                "$recovery_parent_phase" "$recovery_provider_expectation"
            assert_daemon_recovery_current
        fi
        printf '%s\n' recover-daemon >> "$log"
        ;;
    finalize-daemon-recovery)
        [ "$#" -eq 6 ] || fail 'daemon recovery finalization has an invalid argument count'
        recovery_direction=$2
        recovery_color=$3
        recovery_parent_phase=$4
        recovery_provider_expectation=$5
        recovery_route_proof_sha256=$6
        if ! {
            [ "${#recovery_route_proof_sha256}" -eq 64 ] \
                && printf '%s' "$recovery_route_proof_sha256" \
                    | grep -E -q '^[a-f0-9]{64}$'
        }; then
            fail 'daemon recovery route proof is malformed'
        fi
        assert_daemon_recovery_context "$recovery_direction" "$recovery_color" \
            "$recovery_parent_phase" "$recovery_provider_expectation"
        assert_daemon_recovery_current
        case "$(daemon_recovery_value status)" in
            verified)
                [ "$(daemon_recovery_value route_proof_sha256)" = \
                    "$recovery_route_proof_sha256" ] \
                    || fail 'daemon recovery route proof changed after finalization'
                ;;
            runtime-verified)
                recovery_finalized=${daemon_recovery}.new.$$
                awk -F= -v route_proof_sha256="$recovery_route_proof_sha256" \
                    -v finalized_at_epoch="$(date -u +%s)" '
                    $1 == "status" { print "status=verified"; next }
                    { print }
                    END {
                        print "route_proof_sha256=" route_proof_sha256
                        print "verified_at_epoch=" finalized_at_epoch
                    }
                ' "$daemon_recovery" > "$recovery_finalized"
                sync
                mv "$recovery_finalized" "$daemon_recovery"
                sync
                ;;
            *)
                fail 'daemon recovery cannot finalize before runtime verification'
                ;;
        esac
        printf '%s\n' finalize-daemon-recovery >> "$log"
        ;;
    classify-routed-runtime)
        [ "$#" -eq 5 ] || fail 'routed runtime classification has an invalid argument count'
        recovery_direction=$2
        recovery_color=$3
        recovery_parent_phase=$4
        recovery_provider_expectation=$5
        case "$recovery_direction:$recovery_color" in
            forward:green|reverse:blue)
                ;;
            *)
                fail 'routed runtime classification direction or candidate color is invalid'
                ;;
        esac
        case "$recovery_provider_expectation" in
            incumbent|absent)
                ;;
            *)
                fail 'routed runtime classification provider expectation is invalid'
                ;;
        esac
        printf '%s\n' "$recovery_parent_phase" | grep -E -q '^[A-Za-z0-9_.-]+$' \
            || fail 'routed runtime classification parent phase is invalid'
        case "$(phase)" in
            active|released|finalized)
                ;;
            *)
                fail 'routed runtime classification requires an active or durably released fence'
                ;;
        esac
        if [ -e "${state}.runtime-drift" ]; then
            printf '%s\n' daemon
        else
            candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
            observe_routed_candidate_recovery "$candidate"
            if [ "$recovery_candidate_started_at" \
                    = "$(candidate_denial_value candidate_denial_started_at)" ] \
                && [ "$recovery_candidate_restart_count" \
                    = "$(candidate_denial_value candidate_denial_restart_count)" ]; then
                printf '%s\n' current
            else
                assert_routed_candidate_recovery_fresh
                printf '%s\n' candidate
            fi
        fi
        ;;
    recover-routed-runtime)
        [ "$#" -eq 5 ] || fail 'routed runtime recovery has an invalid argument count'
        recovery_direction=$2
        recovery_color=$3
        recovery_parent_phase=$4
        recovery_provider_expectation=$5
        case "$recovery_direction:$recovery_color" in
            forward:green|reverse:blue)
                ;;
            *)
                fail 'routed runtime recovery direction or candidate color is invalid'
                ;;
        esac
        case "$recovery_provider_expectation" in
            incumbent|absent)
                ;;
            *)
                fail 'routed runtime recovery provider expectation is invalid'
                ;;
        esac
        printf '%s\n' "$recovery_parent_phase" | grep -E -q '^[A-Za-z0-9_.-]+$' \
            || fail 'routed runtime recovery parent phase is invalid'
        case "$(phase)" in
            active|released|finalized)
                ;;
            *)
                fail 'routed runtime recovery requires an active or durably released fence'
                ;;
        esac
        candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
        observe_routed_candidate_recovery "$candidate"
        probe_candidate_ssh_denied "$candidate"
        recovery_generation=1
        recovery_reused=false
        if [ -e "$daemon_recovery" ] || [ -L "$daemon_recovery" ]; then
            [ -f "$daemon_recovery" ] && [ ! -L "$daemon_recovery" ] \
                || fail 'routed candidate recovery evidence is unsafe'
            [ "$(routed_runtime_recovery_kind)" = candidate ] \
                || fail 'routed runtime recovery cannot replace a daemon recovery lineage'
            recovery_generation=$(daemon_recovery_value generation)
            printf '%s\n' "$recovery_generation" | grep -E -q '^[1-9][0-9]*$' \
                || fail 'routed candidate recovery generation is malformed'
            if [ "$(daemon_recovery_value candidate_id)" = "$recovery_candidate_id" ] \
                && [ "$(daemon_recovery_value candidate_image_id)" \
                    = "$recovery_candidate_image_id" ] \
                && [ "$(daemon_recovery_value candidate_network_ids)" \
                    = "$recovery_candidate_network_ids" ] \
                && [ "$(daemon_recovery_value candidate_runtime_sha256)" \
                    = "$recovery_candidate_runtime_sha256" ] \
                && [ "$(daemon_recovery_value candidate_started_at)" \
                    = "$recovery_candidate_started_at" ] \
                && [ "$(daemon_recovery_value candidate_restart_count)" \
                    = "$recovery_candidate_restart_count" ]; then
                assert_routed_candidate_recovery_context "$recovery_direction" \
                    "$recovery_color" "$recovery_parent_phase" \
                    "$recovery_provider_expectation"
                assert_routed_candidate_recovery_current
                recovery_reused=true
            else
                [ "$(daemon_recovery_value status)" = verified ] \
                    || fail 'routed candidate recovery changed before its previous generation was finalized'
                recovery_generation=$((recovery_generation + 1))
            fi
        fi
        if [ "$recovery_reused" = false ]; then
            write_routed_candidate_recovery "$recovery_direction" "$recovery_color" \
                "$recovery_parent_phase" "$recovery_provider_expectation" "$recovery_generation"
            assert_routed_candidate_recovery_context "$recovery_direction" "$recovery_color" \
                "$recovery_parent_phase" "$recovery_provider_expectation"
            assert_routed_candidate_recovery_current
        fi
        printf '%s\n' recover-routed-runtime >> "$log"
        ;;
    finalize-routed-runtime-recovery)
        [ "$#" -eq 6 ] \
            || fail 'routed runtime recovery finalization has an invalid argument count'
        recovery_direction=$2
        recovery_color=$3
        recovery_parent_phase=$4
        recovery_provider_expectation=$5
        recovery_route_proof_sha256=$6
        if ! {
            [ "${#recovery_route_proof_sha256}" -eq 64 ] \
                && printf '%s' "$recovery_route_proof_sha256" \
                    | grep -E -q '^[a-f0-9]{64}$'
        }; then
            fail 'routed runtime recovery route proof is malformed'
        fi
        assert_routed_candidate_recovery_context "$recovery_direction" "$recovery_color" \
            "$recovery_parent_phase" "$recovery_provider_expectation"
        assert_routed_candidate_recovery_current
        case "$(daemon_recovery_value status)" in
            verified)
                [ "$(daemon_recovery_value route_proof_sha256)" = \
                    "$recovery_route_proof_sha256" ] \
                    || fail 'routed runtime recovery route proof changed after finalization'
                ;;
            runtime-verified)
                recovery_finalized=${daemon_recovery}.new.$$
                awk -F= -v route_proof_sha256="$recovery_route_proof_sha256" \
                    -v finalized_at_epoch="$(date -u +%s)" '
                    $1 == "status" { print "status=verified"; next }
                    { print }
                    END {
                        print "route_proof_sha256=" route_proof_sha256
                        print "verified_at_epoch=" finalized_at_epoch
                    }
                ' "$daemon_recovery" > "$recovery_finalized"
                sync
                mv "$recovery_finalized" "$daemon_recovery"
                sync
                ;;
            *)
                fail 'routed runtime recovery cannot finalize before runtime verification'
                ;;
        esac
        printf '%s\n' finalize-routed-runtime-recovery >> "$log"
        ;;
    adopt-rollback-incumbent)
        [ "$(phase)" = active ]
        incumbent=$(environment_value CONTROL_PLANE_RUNTIME_LEGACY_CONTAINER)
        candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
        [ "${2:-}" = "$incumbent" ]
        docker inspect "$incumbent" >/dev/null
        ! docker inspect "$candidate" >/dev/null 2>&1
        printf '%s\n' adopt-rollback-incumbent >> "$log"
        ;;
    recover-abort)
        [ "$(phase)" = active ] || [ "$(phase)" = recovery-aborted ]
        [ -e "${state}.runtime-drift" ] || [ "$(phase)" = recovery-aborted ]
        incumbent=$(environment_value CONTROL_PLANE_RUNTIME_LEGACY_CONTAINER)
        candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
        docker inspect "$incumbent" >/dev/null
        ! docker inspect "$candidate" >/dev/null 2>&1
        transition recovery-aborted
        rm -f "${state}.runtime-drift"
        ;;
    abort)
        [ "$(phase)" = active ] || [ "$(phase)" = aborted ]
        candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
        ! docker inspect "$candidate" >/dev/null 2>&1
        transition aborted
        ;;
    release)
        [ "$(phase)" = active ] || [ "$(phase)" = released ]
        incumbent=$(environment_value CONTROL_PLANE_RUNTIME_LEGACY_CONTAINER)
        candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
        expected_freeze=$(pool_plan_value mutation_freeze_epoch)
        ! docker inspect "$incumbent" >/dev/null 2>&1
        assert_candidate_denial "$candidate"
        assert_candidate_runtime_digest "$candidate"
        docker exec --env "CONTROL_PLANE_EXPECTED_FREEZE=$expected_freeze" \
            "$candidate" /bin/sh -ec '
                marker=${CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH:-}
                test -n "$marker"
                test -f "$marker"
                test ! -L "$marker"
                marker_identity=$(stat -c %d:%i "$marker")
                exec 9< "$marker"
                test "$(stat -Lc %d:%i /proc/self/fd/9)" = "$marker_identity"
                test ! -L "$marker"
                test "$(stat -c %d:%i "$marker")" = "$marker_identity"
                printf %s "$CONTROL_PLANE_EXPECTED_FREEZE" | cmp -s - /proc/self/fd/9
                test "$(stat -c %d:%i "$marker")" = "$marker_identity"
            '
        transition released
        ;;
    verify-released)
        [ "$(phase)" = released ] || [ "$(phase)" = finalized ]
        candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
        assert_candidate_denial "$candidate"
        assert_candidate_runtime_digest "$candidate"
        if [ -e "$daemon_recovery" ] || [ -L "$daemon_recovery" ]; then
            case "$(routed_runtime_recovery_kind)" in
                daemon) assert_daemon_recovery_current ;;
                candidate) assert_routed_candidate_recovery_current ;;
            esac
        fi
        printf '%s\n' verify-released >> "$log"
        ;;
    finalize-release)
        [ "$(phase)" = released ] || [ "$(phase)" = finalized ]
        candidate=$(environment_value CONTROL_PLANE_RUNTIME_CANDIDATE_CONTAINER)
        docker exec "$candidate" /bin/sh -ec '
            marker=${CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH:-}
            test -n "$marker"
            lease=${marker%/*}/mutation-inflight.lock
            test -f "$lease"
            test ! -L "$lease"
            lease_identity=$(stat -c %d:%i "$lease")
            exec 8< "$lease"
            test "$(stat -Lc %d:%i /proc/self/fd/8)" = "$lease_identity"
            flock -s 8
            test "$(stat -c %d:%i "$lease")" = "$lease_identity"
            test ! -e "$marker"
            test ! -L "$marker"
            test ! -e "$marker"
            test "$(stat -c %d:%i "$lease")" = "$lease_identity"
        '
        transition finalized
        ;;
    status)
        if [ -e "$daemon_recovery" ] || [ -L "$daemon_recovery" ]; then
            [ -f "$daemon_recovery" ] && [ ! -L "$daemon_recovery" ] \
                || fail 'daemon recovery evidence is unsafe'
            recovery_generation=$(daemon_recovery_value generation)
            recovery_kind=$(routed_runtime_recovery_kind)
            recovery_status=$(daemon_recovery_value status)
        else
            recovery_generation=none
            recovery_kind=none
            recovery_status=none
        fi
        printf 'status=passed phase=%s runtime_recovery_generation=%s runtime_recovery_status=%s runtime_recovery_kind=%s\n' \
            "$(phase)" "$recovery_generation" "$recovery_status" "$recovery_kind"
        ;;
    *)
        printf 'unsupported runtime fence lab action: %s\n' "$action" >&2
        exit 64
        ;;
esac
