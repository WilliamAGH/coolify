#!/bin/sh

set -eu

# CONTROL_PLANE_LAB_PORT8000_V2_SINGLE_TARGET_ADAPTER=1
# This lab adapter validates the v2 single-target web-a route only. It does not
# emulate HAProxy pool membership, balancing, or member drain.

fail()
{
    printf 'CONTROL_PLANE_LAB_PORT8000_CONTROLLER_FAILURE %s\n' "$1" >&2
    exit 1
}

require_value()
{
    [ -n "$2" ] || fail "required setting is empty: $1"
}

validate_sha256()
{
    printf '%s' "$2" | grep -Eq '^[a-f0-9]{64}$' \
        || fail "$1 is not a lowercase SHA-256"
}

validate_absolute_path()
{
    case "$2" in
        /*)
            case "$2" in
                *//*|*/./*|*/../*|*/.|*/..)
                    fail "$1 is not a canonical absolute path"
                    ;;
            esac
            ;;
        *) fail "$1 is not an absolute path" ;;
    esac
}

validate_port()
{
    printf '%s' "$2" | grep -Eq '^[1-9][0-9]{0,4}$' \
        && [ "$2" -le 65535 ] \
        || fail "$1 is not a valid TCP port"
}

validate_generation()
{
    printf '%s' "$2" | grep -Eq '^[1-9][0-9]*$' \
        || fail "$1 is not a positive generation"
}

checksum()
{
    sha256sum "$1" | awk '{print $1}'
}

artifact_value()
{
    artifact_file=$1
    artifact_key=$2
    artifact_label=$3
    artifact_result=$(awk -F= -v expected_key="$artifact_key" '
        $1 == expected_key {
            count++
            value = substr($0, index($0, "=") + 1)
        }
        END {
            if (count != 1 || value == "") exit 1
            print value
        }
    ' "$artifact_file") || fail "$artifact_label key is absent, empty, or duplicated: $artifact_key"
    printf '%s\n' "$artifact_result"
}

read_safe_token_file()
{
    token_file=$1
    token_label=$2
    [ -f "$token_file" ] && [ ! -L "$token_file" ] \
        || fail "$token_label is not a regular non-symlink file"
    token_value=$(cat "$token_file")
    [ "$(wc -c < "$token_file" | tr -d '[:space:]')" = "${#token_value}" ] \
        || fail "$token_label must not contain a newline"
    printf '%s' "$token_value" | grep -Eq '^[A-Za-z0-9._:-]{16,128}$' \
        || fail "$token_label is not a safe token"
    printf '%s\n' "$token_value"
}

read_ack()
{
    read_safe_token_file "$ack_file" 'ack file'
}

read_probe_token()
{
    read_safe_token_file "$direct_probe_token_file" 'direct-probe token file'
}

write_state()
{
    phase=$1
    route_color=$2
    route_backend=$3
    route_port=$4
    route_ack=$5
    route_owner=${6:-none}
    if [ -f "$state_file" ]; then
        persisted_backup_checksum=$(state_value backup_checksum)
        [ -f "$backup_file" ] \
            && [ "$(checksum "$backup_file")" = "$persisted_backup_checksum" ] \
            || fail 'durable :8000 rollback backup changed'
        case "$backup_checksum" in
            none)
                backup_checksum=$persisted_backup_checksum
                ;;
            "$persisted_backup_checksum")
                ;;
            *)
                fail 'in-memory :8000 rollback backup identity changed'
                ;;
        esac
    fi
    state_candidate="$operation_directory/.state.$$"
    {
        printf 'operation_id=%s\n' "$operation_id"
        printf 'phase=%s\n' "$phase"
        printf 'color=%s\n' "$route_color"
        printf 'backend=%s\n' "$route_backend"
        printf 'port=%s\n' "$route_port"
        printf 'ack=%s\n' "$route_ack"
        printf 'owner=%s\n' "$route_owner"
        printf 'generation=%s\n' "$route_generation"
        printf 'pool_manifest_sha256=%s\n' "$route_pool_manifest_sha256"
        printf 'pool_plan_manifest_sha256=%s\n' "$route_pool_plan_manifest_sha256"
        printf 'backup_checksum=%s\n' "$backup_checksum"
    } > "$state_candidate"
    sync
    mv "$state_candidate" "$state_file"
    sync
}

write_runtime()
{
    route_color=$1
    route_backend=$2
    route_port=$3
    route_ack=$4
    route_owner=$5
    runtime_candidate="$config_directory/.route-${operation_id}-$$"
    {
        printf 'backend=%s\n' "$route_backend"
        printf 'port=%s\n' "$route_port"
        printf 'ack=%s\n' "$route_ack"
        printf 'owner=%s\n' "$route_owner"
        printf 'color=%s\n' "$route_color"
    } > "$runtime_candidate"
    sync
    # The lab proxy reads a macOS bind mount whose file-sharing layer cannot reopen
    # an atomically replaced inode. Preserve that lab-only inode; production HAProxy
    # configuration installation remains atomic in the production controller.
    cp "$runtime_candidate" "$config_file"
    rm -f "$runtime_candidate"
    sync
}

state_value()
{
    sed -n "s/^${1}=//p" "$state_file"
}

runtime_value()
{
    sed -n "s/^${1}=//p" "$config_file"
}

assert_single_header()
{
    header_file=$1
    header_name=$2
    header_expected=$3
    awk -F: -v expected_name="$header_name" -v expected_value="$header_expected" '
        tolower($1) == tolower(expected_name) {
            count++
            observed = substr($0, index($0, ":") + 1)
            sub(/^[[:space:]]*/, "", observed)
            sub(/\r$/, "", observed)
        }
        END { if (count != 1 || observed != expected_value) exit 1 }
    ' "$header_file"
}

file_checksum_or_absent()
{
    if [ -f "$1" ]; then
        checksum "$1"
    else
        printf '%s\n' absent
    fi
}

write_sanitized_probe_headers()
{
    sanitized_headers_source=$1
    sanitized_headers_destination=$2

    if [ ! -f "$sanitized_headers_source" ]; then
        : > "$sanitized_headers_destination"
        return
    fi

    awk -F: '
        {
            header_name = tolower($1)
            sub(/\r$/, "", header_name)
            if (header_name == "authorization" || header_name == "set-cookie" \
                || header_name == "x-control-plane-probe" \
                || header_name == "x-control-plane-applied-config" \
                || header_name == "x-control-plane-route-ack") {
                next
            }
            print
        }
    ' "$sanitized_headers_source" > "$sanitized_headers_destination"
}

preserve_probe_failure()
{
    failure_color=$1
    failure_owner=$2
    failure_curl_status=$3
    failure_directory="$probe_directory/final-probe-failure"
    mkdir -p "$failure_directory"
    chmod 700 "$failure_directory"
    failure_status_candidate="$failure_directory/.status.$$"
    failure_headers_candidate="$failure_directory/.headers.$$"

    failure_http_status=000
    if [ -f "$headers" ]; then
        failure_http_status=$(awk 'NR == 1 { print $2 }' "$headers")
    fi
    {
        printf 'expected_color=%s\n' "$failure_color"
        printf 'expected_owner=%s\n' "$failure_owner"
        printf 'curl_status=%s\n' "$failure_curl_status"
        printf 'http_status=%s\n' "${failure_http_status:-000}"
        printf 'body_sha256=%s\n' "$(file_checksum_or_absent "$body")"
    } > "$failure_status_candidate"
    write_sanitized_probe_headers "$headers" "$failure_headers_candidate"
    mv "$failure_status_candidate" "$failure_directory/status"
    mv "$failure_headers_candidate" "$failure_directory/headers"
}

test_crash()
{
    [ "${CONTROL_PLANE_TEST_PORT8000_CRASH_AT:-}" != "$1" ] || kill -KILL "$$"
}

pool_manifest_value()
{
    artifact_value "$pool_manifest" "$1" 'ingress-pool manifest'
}

pool_plan_value()
{
    artifact_value "$pool_plan_manifest" "$1" 'pool-plan manifest'
}

assert_pinned_artifact()
{
    pinned_path=$1
    pinned_sha256=$2
    pinned_label=$3
    validate_absolute_path "$pinned_label path" "$pinned_path"
    validate_sha256 "$pinned_label SHA-256" "$pinned_sha256"
    [ -f "$pinned_path" ] && [ ! -L "$pinned_path" ] \
        || fail "$pinned_label is not a regular non-symlink file"
    [ "$(checksum "$pinned_path")" = "$pinned_sha256" ] \
        || fail "$pinned_label differs from its pinned SHA-256"
}

load_v2_single_target_contract()
{
    require_value CONTROL_PLANE_INGRESS_GENERATION "$generation"
    require_value CONTROL_PLANE_INGRESS_POOL_MANIFEST "$pool_manifest"
    require_value CONTROL_PLANE_INGRESS_POOL_MANIFEST_SHA256 "$pool_manifest_sha256"
    require_value CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST "$pool_plan_manifest"
    require_value CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST_SHA256 "$pool_plan_manifest_sha256"
    require_value CONTROL_PLANE_INGRESS_BACKEND_PORT "$requested_backend_port"
    require_value CONTROL_PLANE_INGRESS_ACK_FILE "$ack_file"
    require_value CONTROL_PLANE_INGRESS_DIRECT_PROBE_TOKEN_FILE \
        "$requested_direct_probe_token_file"
    case "$color" in
        green)
            expected_member_a=$green_container
            expected_member_b=$green_web_b_container
            ;;
        blue)
            expected_member_a=$blue_container
            expected_member_b=$blue_web_b_container
            ;;
        *)
            fail 'managed :8000 routing color must be green or blue'
            ;;
    esac
    require_value 'managed member-a container' "$expected_member_a"
    require_value 'managed member-b container' "$expected_member_b"
    [ "$expected_member_a" != "$expected_member_b" ] \
        || fail 'managed v2 pool members must be distinct'
    validate_generation CONTROL_PLANE_INGRESS_GENERATION "$generation"
    validate_port CONTROL_PLANE_INGRESS_BACKEND_PORT "$requested_backend_port"
    assert_pinned_artifact "$pool_manifest" "$pool_manifest_sha256" \
        'ingress-pool manifest'
    assert_pinned_artifact "$pool_plan_manifest" "$pool_plan_manifest_sha256" \
        'pool-plan manifest'

    manifest_direction=$(pool_manifest_value direction)
    plan_direction=$(pool_plan_value direction)
    case "$manifest_direction" in
        bootstrap-forward|reverse) ;;
        *) fail 'ingress-pool manifest direction is invalid' ;;
    esac
    [ "$(pool_manifest_value version)" = 2 ] \
        && [ "$(pool_plan_value version)" = 2 ] \
        && [ "$(pool_manifest_value operation_id)" = "$operation_id" ] \
        && [ "$(pool_plan_value operation_id)" = "$operation_id" ] \
        && [ "$(pool_manifest_value color)" = "$color" ] \
        && [ "$(pool_plan_value color)" = "$color" ] \
        && [ "$(pool_manifest_value generation)" = "$generation" ] \
        && [ "$(pool_plan_value generation)" = "$generation" ] \
        && [ "$manifest_direction" = "$plan_direction" ] \
        && [ "$(pool_manifest_value member_count)" = 2 ] \
        && [ "$(pool_plan_value member_count)" = 2 ] \
        || fail 'managed routing requires ingress-pool.manifest v2 for the exact color and generation'
    [ "$(pool_manifest_value parent_pool_plan_sha256)" = "$pool_plan_manifest_sha256" ] \
        && [ "$(pool_plan_value ingress_pool_manifest_path)" = "$pool_manifest" ] \
        || fail 'ingress-pool and pool-plan manifests do not share exact lineage'

    plan_backend_port=$(pool_plan_value backend_port)
    validate_port 'pool-plan backend port' "$plan_backend_port"
    [ "$plan_backend_port" = "$backend_port" ] \
        || fail 'pool-plan backend port differs from the lab backend port'

    # The v2 envelope must declare distinct canonical members, although only
    # member a supplies the lab proxy's single runtime target.
    for pool_member in a b; do
        member_role=$(pool_manifest_value "member_${pool_member}_role")
        [ "$member_role" = "web-$pool_member" ] \
            && [ "$(pool_plan_value "member_${pool_member}_role")" = "$member_role" ] \
            || fail "pool member $pool_member role is not canonical"
        member_name=$(pool_manifest_value "member_${pool_member}_name")
        [ "$(pool_plan_value "member_${pool_member}_name")" = "$member_name" ] \
            || fail "pool member $pool_member name differs between manifests"
        case "$pool_member" in
            a) expected_member=$expected_member_a ;;
            b) expected_member=$expected_member_b ;;
        esac
        [ "$member_name" = "$expected_member" ] \
            || fail "pool member $pool_member is not the expected lab container"

        member_port=$(pool_manifest_value "member_${pool_member}_port")
        member_loopback_port=$(pool_plan_value \
            "member_${pool_member}_expected_loopback_port")
        validate_port "pool member $pool_member backend port" "$member_port"
        validate_port "pool member $pool_member loopback port" "$member_loopback_port"
        [ "$member_port" = "$plan_backend_port" ] \
            || fail "pool member $pool_member backend port differs from the pool plan"

        case "$pool_member" in
            a)
                primary_backend=$member_name
                primary_port=$member_port
                primary_loopback_port=$member_loopback_port
                ;;
            b)
                secondary_backend=$member_name
                secondary_loopback_port=$member_loopback_port
                ;;
        esac
    done

    [ "$primary_backend" != "$secondary_backend" ] \
        || fail 'pool members do not have distinct lab containers'
    [ "$primary_loopback_port" = "$requested_backend_port" ] \
        || fail 'requested active route does not select pool-plan member-a loopback port'
    [ "$primary_loopback_port" != "$secondary_loopback_port" ] \
        || fail 'pool members do not have distinct loopback ports'

    primary_ack_file=$(pool_manifest_value member_a_applied_ack_file)
    primary_ack_sha256=$(pool_manifest_value member_a_applied_ack_sha256)
    [ "$(pool_plan_value member_a_applied_ack_file)" = "$primary_ack_file" ] \
        && [ "$(pool_plan_value member_a_applied_ack_sha256)" = "$primary_ack_sha256" ] \
        || fail 'pool member a acknowledgement lineage differs'
    assert_pinned_artifact "$primary_ack_file" "$primary_ack_sha256" \
        'pool member a acknowledgement'
    primary_ack=$(read_safe_token_file "$primary_ack_file" \
        'pool member a acknowledgement')

    primary_probe_file=$(pool_plan_value member_a_direct_probe_token_file)
    primary_probe_sha256=$(pool_plan_value member_a_direct_probe_token_sha256)
    assert_pinned_artifact "$primary_probe_file" "$primary_probe_sha256" \
        'pool member a direct-probe token'
    primary_probe_token=$(read_safe_token_file "$primary_probe_file" \
        'pool member a direct-probe token')

    [ "$(read_ack)" = "$primary_ack" ] \
        || fail 'requested active route acknowledgement differs from manifest member-a'
    [ "$(read_safe_token_file "$requested_direct_probe_token_file" \
            'requested direct-probe token')" = "$primary_probe_token" ] \
        || fail 'requested active direct-probe token differs from pool-plan member-a'

    route_generation=$generation
    route_pool_manifest_sha256=$pool_manifest_sha256
    route_pool_plan_manifest_sha256=$pool_plan_manifest_sha256
}

select_primary_v2_member()
{
    route_backend=$primary_backend
    route_port=$primary_port
    route_ack=$primary_ack
    direct_probe_token_file=$primary_probe_file
}

legacy_owner_present()
{
    docker inspect "$legacy_container" >/dev/null 2>&1
}

legacy_owner_running()
{
    [ "$(docker inspect --format '{{.State.Running}}' "$legacy_container" 2>/dev/null)" = true ]
}

probe()
{
    expected_color=$1
    expected_ack=$2
    expected_owner=$3
    attempts=0
    probe_directory=$config_directory
    [ ! -d "$operation_directory" ] || probe_directory=$operation_directory
    headers="$probe_directory/.probe-headers.$$"
    body="$probe_directory/.probe-body.$$"
    secret_header="$probe_directory/.probe-secret.$$"
    probe_url=$public_url
    if [ "$expected_color" != legacy ]; then
        probe_token=$(read_probe_token)
        printf 'X-Control-Plane-Probe: %s\n' "$probe_token" > "$secret_header"
        chmod 600 "$secret_header"
        public_authority=${public_url#http://}
        public_authority=${public_authority%%/*}
        probe_url="http://${public_authority}${direct_probe_path}"
    fi
    trap 'rm -f "$headers" "$body" "$secret_header"' EXIT HUP INT TERM

    while [ "$attempts" -lt "$probe_attempts" ]; do
        rm -f "$headers" "$body"
        probe_status=0
        if [ "$expected_color" = legacy ]; then
            curl --fail --silent --show-error --max-time 5 \
                --header "Host: $public_host_header" \
                --header 'X-Forwarded-For: forged-client' \
                --header 'X-Forwarded-Proto: forged-proto' \
                --dump-header "$headers" --output "$body" "$probe_url" || probe_status=$?
            if [ "$probe_status" -eq 0 ] \
                && assert_single_header "$headers" X-Control-Plane-Color "$expected_color" \
                && assert_single_header "$headers" X-Control-Plane-Port-Owner "$expected_owner" \
                && assert_single_header "$headers" X-Control-Plane-Lab-Host "$public_host_header" \
                && grep -F -i -q "X-Observed-Host: $public_host_header" "$headers" \
                && grep -E -i -q 'X-Observed-Forwarded-For: ([0-9]{1,3}\.){3}[0-9]{1,3}' "$headers" \
                && grep -F -i -q 'X-Observed-Forwarded-Proto: http' "$headers" \
                && ! grep -F -i -q 'forged-client' "$headers" \
                && ! grep -F -i -q 'forged-proto' "$headers" \
                && ! grep -E -i -q '^X-Control-Plane-(Route-Ack|Applied-Config):' "$headers"; then
                rm -f "$headers" "$body" "$secret_header"
                trap - EXIT HUP INT TERM
                return
            fi
        else
            curl --fail --silent --show-error --max-time 5 \
                --header "@$secret_header" \
                --header "Host: $public_host_header" \
                --header 'X-Forwarded-For: forged-client' \
                --header 'X-Forwarded-Proto: forged-proto' \
                --dump-header "$headers" --output "$body" "$probe_url" || probe_status=$?
            if [ "$probe_status" -eq 0 ] \
                && assert_single_header "$headers" X-Control-Plane-Applied-Config "$expected_ack" \
                && assert_single_header "$headers" X-Control-Plane-Port-Owner "$expected_owner" \
                && assert_single_header "$headers" X-Control-Plane-Lab-Host "$public_host_header"; then
                rm -f "$headers" "$body" "$secret_header"
                trap - EXIT HUP INT TERM
                return
            fi
        fi
        attempts=$((attempts + 1))
        sleep 1
    done

    preserve_probe_failure "$expected_color" "$expected_owner" "$probe_status"
    rm -f "$headers" "$body" "$secret_header"
    trap - EXIT HUP INT TERM
    fail 'host-local :8000 route did not prove target, acknowledgement, Host, and overwritten forwarding headers'
}

preflight()
{
    [ "$target" = lab ] || fail 'lab controller refuses non-lab targets'
    [ "$expected_ipv4" = 127.0.0.1 ] || fail 'lab public IPv4 baseline changed'
    nc -z -w 1 127.0.0.1 "$bootstrap_port" >/dev/null 2>&1 \
        && fail 'bootstrap port is unexpectedly reachable'
    [ -f "$config_file" ] && [ ! -L "$config_file" ] || fail 'port proxy runtime config is missing'
    if [ "$color" = legacy ]; then
        [ "$(runtime_value color)" = legacy ] || fail 'preflight requires the legacy :8000 route'
        probe legacy none legacy
        return
    fi

    load_v2_single_target_contract
    case "$(runtime_value color):$(runtime_value owner)" in
        green:bootstrap-a|green:permanent-b|blue:bootstrap-a|blue:permanent-b)
            ;;
        *)
            fail 'managed preflight requires an acknowledged incumbent :8000 route'
            ;;
    esac
}

prepare()
{
    [ "$color" = legacy ] || load_v2_single_target_contract
    mkdir -p "$operation_directory"
    chmod 700 "$operation_directory"
    if [ -f "$state_file" ]; then
        backup_checksum=$(state_value backup_checksum)
        [ -f "$backup_file" ] && [ "$(checksum "$backup_file")" = "$backup_checksum" ] \
            || fail 'durable :8000 rollback backup changed'
        return
    fi
    cp -p "$config_file" "$backup_file"
    backup_checksum=$(checksum "$backup_file")
    backup_color=$(runtime_value color)
    backup_owner=$(runtime_value owner)
    backup_ack=$(runtime_value ack)
    write_state prepared "$backup_color" "$(runtime_value backend)" \
        "$(runtime_value port)" "$backup_ack" "$backup_owner"
}

switch_route()
{
    load_v2_single_target_contract
    select_primary_v2_member
    prepare

    write_state switch-intent "$color" "$route_backend" "$route_port" "$route_ack" \
        bootstrap-a
    test_crash after-switch-intent
    write_runtime "$color" "$route_backend" "$route_port" "$route_ack" bootstrap-a
    probe "$color" "$route_ack" bootstrap-a
    write_state bootstrap-a-acknowledged "$color" "$route_backend" "$route_port" \
        "$route_ack" bootstrap-a
    test_crash after-bootstrap-a

    write_runtime "$color" "$route_backend" "$route_port" "$route_ack" bootstrap-a
    probe "$color" "$route_ack" bootstrap-a
    write_state captured "$color" "$route_backend" "$route_port" "$route_ack" \
        bootstrap-a
    test_crash after-original-direct-drain

    if legacy_owner_present; then
        return
    fi
    reconcile_permanent_b
}

reconcile_permanent_b()
{
    load_v2_single_target_contract
    select_primary_v2_member
    legacy_owner_present \
        && fail 'phase B is forbidden while the legacy Docker owner still exists'
    write_state permanent-intent "$color" "$route_backend" "$route_port" "$route_ack" \
        permanent-b
    write_runtime "$color" "$route_backend" "$route_port" "$route_ack" permanent-b
    probe "$color" "$route_ack" permanent-b
    write_state permanent-b-acknowledged "$color" "$route_backend" "$route_port" \
        "$route_ack" permanent-b
    test_crash after-permanent-b

    write_runtime "$color" "$route_backend" "$route_port" "$route_ack" permanent-b
    probe "$color" "$route_ack" permanent-b
    write_state complete "$color" "$route_backend" "$route_port" "$route_ack" \
        permanent-b
}

assert_primary_v2_target()
{
    select_primary_v2_member
    [ "$(state_value color)" = "$color" ] \
        && [ "$(state_value backend)" = "$route_backend" ] \
        && [ "$(state_value port)" = "$route_port" ] \
        && [ "$(state_value ack)" = "$route_ack" ] \
        && [ "$(state_value generation)" = "$generation" ] \
        && [ "$(state_value pool_manifest_sha256)" = "$pool_manifest_sha256" ] \
        && [ "$(state_value pool_plan_manifest_sha256)" = "$pool_plan_manifest_sha256" ] \
        && [ "$(runtime_value color)" = "$color" ] \
        && [ "$(runtime_value backend)" = "$route_backend" ] \
        && [ "$(runtime_value port)" = "$route_port" ] \
        && [ "$(runtime_value ack)" = "$route_ack" ] \
        || fail ':8000 durable single-target v2 intent and runtime target differ'
}

assert_target()
{
    [ -f "$state_file" ] || fail ':8000 state is missing'
    load_v2_single_target_contract
    assert_primary_v2_target
    if legacy_owner_present; then
        legacy_owner_running \
            || fail ':8000 phase A refuses a stopped legacy Docker owner'
        [ "$(state_value phase)" = captured ] \
            && [ "$(state_value owner)" = bootstrap-a ] \
            && [ "$(runtime_value owner)" = bootstrap-a ] \
            || fail ':8000 phase A changed before legacy Docker ownership ended'
        return
    fi
    case "$(state_value phase)" in
        captured|permanent-intent|permanent-b-acknowledged)
            reconcile_permanent_b
            ;;
    esac
    assert_primary_v2_target
    [ "$(state_value phase)" = complete ] \
        && [ "$(state_value owner)" = permanent-b ] \
        && [ "$(runtime_value owner)" = permanent-b ] \
        || fail ':8000 did not converge to permanent phase B after legacy removal'
}

verify_target_ack()
{
    [ -f "$state_file" ] || fail ':8000 state is missing'
    load_v2_single_target_contract
    assert_primary_v2_target
    select_primary_v2_member
    if legacy_owner_present; then
        legacy_owner_running \
            || fail ':8000 pure verification refuses a stopped legacy Docker owner'
        [ "$(state_value phase)" = captured ] \
            && [ "$(state_value owner)" = bootstrap-a ] \
            && [ "$(runtime_value owner)" = bootstrap-a ] \
            || fail ':8000 pure verification requires captured phase A before legacy removal'
        probe "$color" "$route_ack" bootstrap-a
        return
    fi
    [ "$(state_value phase)" = complete ] \
        && [ "$(state_value owner)" = permanent-b ] \
        && [ "$(runtime_value owner)" = permanent-b ] \
        || fail ':8000 pure verification refuses an unreconciled permanent phase'
    probe "$color" "$route_ack" permanent-b
}

acknowledge()
{
    if [ "$color" = legacy ]; then
        probe legacy none legacy
    else
        [ -f "$state_file" ] || fail ':8000 state is missing during acknowledgement'
        load_v2_single_target_contract
        assert_primary_v2_target
        route_owner=$(runtime_value owner)
        case "$route_owner" in
            bootstrap-a|permanent-b)
                ;;
            *)
                fail ':8000 runtime owner is invalid during acknowledgement'
                ;;
        esac
        probe "$color" "$route_ack" "$route_owner"
    fi
}

restore()
{
    [ -f "$state_file" ] && [ -f "$backup_file" ] || fail ':8000 rollback was not prepared'
    [ "$(state_value operation_id)" = "$operation_id" ] \
        || fail ':8000 rollback state belongs to another operation'
    backup_checksum=$(state_value backup_checksum)
    [ "$(checksum "$backup_file")" = "$backup_checksum" ] || fail ':8000 rollback backup changed'
    restore_candidate="$config_directory/.restore-${operation_id}-$$"
    cp -p "$backup_file" "$restore_candidate"
    sync
    # Keep the lab bind-mounted inode stable for the same reason as write_runtime().
    cp "$restore_candidate" "$config_file"
    rm -f "$restore_candidate"
    sync
    backup_backend=$(runtime_value backend)
    backup_port=$(runtime_value port)
    backup_color=$(runtime_value color)
    backup_owner=$(runtime_value owner)
    backup_ack=$(runtime_value ack)
    case "$backup_color:$backup_owner" in
        legacy:legacy)
            [ "$backup_ack" = none ] \
                || fail ':8000 legacy rollback backup contains an acknowledgement'
            ;;
        green:bootstrap-a|green:permanent-b|blue:bootstrap-a|blue:permanent-b)
            printf '%s' "$backup_ack" | grep -Eq '^[A-Za-z0-9._:-]{16,128}$' \
                || fail ':8000 managed rollback backup acknowledgement is malformed'
            ;;
        *)
            fail ':8000 rollback backup color or owner is invalid'
            ;;
    esac
    route_generation=none
    route_pool_manifest_sha256=none
    route_pool_plan_manifest_sha256=none
    write_state restored "$backup_color" "$backup_backend" "$backup_port" "$backup_ack" \
        "$backup_owner"
}

legacy_restore_status()
{
    if ! legacy_owner_present || ! legacy_owner_running; then
        fail 'legacy restore is forbidden unless the original blue container is present and running'
    fi
}

policy_status()
{
    acknowledge
    nc -z -w 1 127.0.0.1 "$bootstrap_port" >/dev/null 2>&1 \
        && fail 'direct bootstrap port is reachable during policy attestation'
    printf 'CONTROL_PLANE_PORT8000_POLICY result=passed color=%s\n' "$color"
}

external_blocked_status()
{
    require_value CONTROL_PLANE_PORT8000_IPV6_INVENTORY_FILE "$ipv6_inventory_file"
    require_value CONTROL_PLANE_PORT8000_IPV6_INVENTORY_SHA256 "$ipv6_inventory_sha256"
    require_value CONTROL_PLANE_PORT8000_IPV6_INVENTORY_PROBE "$ipv6_inventory_probe"
    require_value CONTROL_PLANE_PORT8000_IPV6_INVENTORY_PROBE_SHA256 \
        "$ipv6_inventory_probe_sha256"
    [ -f "$external_policy_probe" ] && [ ! -L "$external_policy_probe" ] \
        && [ -x "$external_policy_probe" ] \
        || fail 'external policy probe is not an executable regular file'
    [ "$(checksum "$external_policy_probe")" = "$external_policy_probe_sha256" ] \
        || fail 'external policy probe checksum changed'
    if ! external_policy_output=$("$external_policy_probe" \
        --endpoint "$external_blocked_endpoint" --timeout-seconds 5); then
        fail 'external policy probe execution failed'
    fi
    [ "$external_policy_output" = \
        "CONTROL_PLANE_PORT8000_EXTERNAL_POLICY result=blocked endpoint=${external_blocked_endpoint}" ] \
        || fail 'external policy probe did not prove blocked :8000 ingress'
    [ -f "$ipv6_inventory_file" ] && [ ! -L "$ipv6_inventory_file" ] \
        || fail 'IPv6 inventory is not a regular non-symlink file'
    [ "$(checksum "$ipv6_inventory_file")" = "$ipv6_inventory_sha256" ] \
        || fail 'IPv6 inventory checksum changed'
    [ -f "$ipv6_inventory_probe" ] && [ ! -L "$ipv6_inventory_probe" ] \
        && [ -x "$ipv6_inventory_probe" ] \
        || fail 'IPv6 inventory probe is not an executable regular file'
    [ "$(checksum "$ipv6_inventory_probe")" = "$ipv6_inventory_probe_sha256" ] \
        || fail 'IPv6 inventory probe checksum changed'
    inventory_host=${public_host_header%:8000}
    inventory_timestamp=$(sed -n 's/^observed_at_epoch=//p' "$ipv6_inventory_file")
    printf '%s' "$inventory_timestamp" | grep -Eq '^[1-9][0-9]{9}$' \
        || fail 'IPv6 inventory timestamp is malformed'
    inventory_now=$(date -u +%s)
    [ "$inventory_timestamp" -le $((inventory_now + 30)) ] \
        || fail 'IPv6 inventory timestamp is unreasonably far in the future'
    [ "$(sed -n '1p' "$ipv6_inventory_file")" = version=1 ] \
        && [ "$(sed -n '2p' "$ipv6_inventory_file")" = "host=$inventory_host" ] \
        && [ "$(sed -n '3p' "$ipv6_inventory_file")" = "observed_at_epoch=$inventory_timestamp" ] \
        && [ "$(sed -n '4p' "$ipv6_inventory_file")" = default_route_sha256=none ] \
        && [ "$(sed -n '5p' "$ipv6_inventory_file")" = interface_global_ipv6=none ] \
        && [ "$(sed -n '6p' "$ipv6_inventory_file")" = dns_aaaa=none ] \
        && [ "$(sed -n '7p' "$ipv6_inventory_file")" = provider_endpoint=none ] \
        && [ "$(sed -n '8p' "$ipv6_inventory_file")" = external_vantage=none ] \
        && [ "$(sed -n '9p' "$ipv6_inventory_file")" = external_denial=none ] \
        && [ "$(wc -l < "$ipv6_inventory_file" | tr -d '[:space:]')" = 9 ] \
        || fail 'IPv6 inventory is not the exact canonical lab absence inventory'
    ipv6_inventory_output=$("$ipv6_inventory_probe" \
        --inventory-file "$ipv6_inventory_file" --timeout-seconds 5)
    [ "$ipv6_inventory_output" = \
        "CONTROL_PLANE_PORT8000_IPV6_INVENTORY result=pass inventory_sha256=$ipv6_inventory_sha256 address_count=0 denial_count=0" ] \
        || fail 'IPv6 inventory probe returned an inexact attestation'
    printf '%s\n' "$external_policy_output"
}

action=${1:-}
operation_id=${CONTROL_PLANE_INGRESS_OPERATION_ID:-}
operation_directory=${CONTROL_PLANE_INGRESS_OPERATION_DIR:-}
public_url=${CONTROL_PLANE_INGRESS_PUBLIC_URL:-}
public_host_header=${CONTROL_PLANE_INGRESS_PUBLIC_HOST_HEADER:-}
probe_attempts=${CONTROL_PLANE_INGRESS_PROBE_ATTEMPTS:-}
target=${CONTROL_PLANE_INGRESS_TARGET:-}
expected_ipv4=${CONTROL_PLANE_INGRESS_EXPECTED_IPV4:-}
bootstrap_port=${CONTROL_PLANE_INGRESS_BOOTSTRAP_PORT:-}
color=${CONTROL_PLANE_INGRESS_COLOR:-}
generation=${CONTROL_PLANE_INGRESS_GENERATION:-}
pool_manifest=${CONTROL_PLANE_INGRESS_POOL_MANIFEST:-}
pool_manifest_sha256=${CONTROL_PLANE_INGRESS_POOL_MANIFEST_SHA256:-}
pool_plan_manifest=${CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST:-}
pool_plan_manifest_sha256=${CONTROL_PLANE_INGRESS_POOL_PLAN_MANIFEST_SHA256:-}
backend_port=${CONTROL_PLANE_BACKEND_PORT:-8080}
requested_backend_port=${CONTROL_PLANE_INGRESS_BACKEND_PORT:-}
ack_file=${CONTROL_PLANE_INGRESS_ACK_FILE:-}
direct_probe_token_file=${CONTROL_PLANE_INGRESS_DIRECT_PROBE_TOKEN_FILE:-none}
requested_direct_probe_token_file=$direct_probe_token_file
direct_probe_path=${CONTROL_PLANE_INGRESS_DIRECT_PROBE_PATH:-}
config_file=${CONTROL_PLANE_LAB_PORT_CONFIG:-}
legacy_container=${CONTROL_PLANE_BLUE_CONTAINER:-}
green_container=${CONTROL_PLANE_GREEN_CONTAINER:-}
green_web_b_container=${CONTROL_PLANE_GREEN_WEB_B_CONTAINER:-}
blue_container=${CONTROL_PLANE_REPLACEMENT_BLUE_CONTAINER:-}
blue_web_b_container=${CONTROL_PLANE_BLUE_WEB_B_CONTAINER:-}
external_policy_probe=${CONTROL_PLANE_PORT8000_EXTERNAL_POLICY_PROBE:-}
external_policy_probe_sha256=${CONTROL_PLANE_PORT8000_EXTERNAL_POLICY_PROBE_SHA256:-}
external_blocked_endpoint=${CONTROL_PLANE_PORT8000_EXTERNAL_BLOCKED_ENDPOINT:-}
ipv6_inventory_file=${CONTROL_PLANE_PORT8000_IPV6_INVENTORY_FILE:-}
ipv6_inventory_sha256=${CONTROL_PLANE_PORT8000_IPV6_INVENTORY_SHA256:-}
ipv6_inventory_probe=${CONTROL_PLANE_PORT8000_IPV6_INVENTORY_PROBE:-}
ipv6_inventory_probe_sha256=${CONTROL_PLANE_PORT8000_IPV6_INVENTORY_PROBE_SHA256:-}

require_value CONTROL_PLANE_INGRESS_OPERATION_ID "$operation_id"
require_value CONTROL_PLANE_INGRESS_OPERATION_DIR "$operation_directory"
require_value CONTROL_PLANE_INGRESS_PUBLIC_URL "$public_url"
require_value CONTROL_PLANE_INGRESS_PUBLIC_HOST_HEADER "$public_host_header"
require_value CONTROL_PLANE_INGRESS_PROBE_ATTEMPTS "$probe_attempts"
require_value CONTROL_PLANE_INGRESS_DIRECT_PROBE_PATH "$direct_probe_path"
require_value CONTROL_PLANE_LAB_PORT_CONFIG "$config_file"
config_directory=$(dirname -- "$config_file")
[ -d "$config_directory" ] || fail 'port proxy config directory is absent'
state_file="$operation_directory/state"
backup_file="$operation_directory/before"
backup_checksum=none
route_generation=none
route_pool_manifest_sha256=none
route_pool_plan_manifest_sha256=none

case "$action" in
    preflight)
        preflight
        ;;
    prepare)
        prepare
        ;;
    switch)
        require_value CONTROL_PLANE_INGRESS_COLOR "$color"
        require_value CONTROL_PLANE_INGRESS_ACK_FILE "$ack_file"
        switch_route
        ;;
    assert)
        assert_target
        ;;
    verify-ack)
        verify_target_ack
        ;;
    ack)
        acknowledge
        ;;
    restore)
        restore
        ;;
    legacy-restore-status)
        legacy_restore_status
        ;;
    policy-status)
        policy_status
        ;;
    external-blocked-status)
        external_blocked_status
        ;;
    *)
        fail 'usage: port8000-controller.sh {preflight|prepare|switch|assert|ack|verify-ack|restore|legacy-restore-status|policy-status|external-blocked-status}; lab adapter requires ingress-pool.manifest v2'
        ;;
esac
