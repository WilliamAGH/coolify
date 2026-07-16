#!/bin/sh

set -eu

fail()
{
    printf 'CONTROL_PLANE_LAB_PORT8000_CONTROLLER_FAILURE %s\n' "$1" >&2
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

read_ack()
{
    [ -f "$ack_file" ] && [ ! -L "$ack_file" ] || fail 'ack file is not a regular non-symlink file'
    acknowledgement=$(cat "$ack_file")
    [ "$(wc -c < "$ack_file" | tr -d '[:space:]')" = "${#acknowledgement}" ] \
        || fail 'ack file must not contain a newline'
    printf '%s' "$acknowledgement" | grep -Eq '^[A-Za-z0-9._:-]{16,128}$' \
        || fail 'ack file is not a safe token'
    printf '%s\n' "$acknowledgement"
}

read_probe_token()
{
    [ -f "$direct_probe_token_file" ] && [ ! -L "$direct_probe_token_file" ] \
        || fail 'direct-probe token file is not a regular non-symlink file'
    probe_token=$(cat "$direct_probe_token_file")
    [ "$(wc -c < "$direct_probe_token_file" | tr -d '[:space:]')" = "${#probe_token}" ] \
        || fail 'direct-probe token file must not contain a newline'
    printf '%s' "$probe_token" | grep -Eq '^[A-Za-z0-9._:-]{16,128}$' \
        || fail 'direct-probe token file is not a safe token'
    printf '%s\n' "$probe_token"
}

write_state()
{
    phase=$1
    route_color=$2
    route_backend=$3
    route_port=$4
    route_ack=$5
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

backend_for_color()
{
    case "$1" in
        legacy)
            printf '%s\n' "$legacy_container"
            ;;
        green)
            printf '%s\n' "$green_container"
            ;;
        blue)
            printf '%s\n' "$blue_container"
            ;;
        *)
            fail 'unknown route color'
            ;;
    esac
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
    [ "$(runtime_value color)" = legacy ] || fail 'preflight requires the legacy :8000 route'
    probe legacy none legacy
}

prepare()
{
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
    write_state prepared legacy "$(runtime_value backend)" "$(runtime_value port)" none
}

switch_route()
{
    prepare
    route_ack=$(read_ack)
    route_backend=$(backend_for_color "$color")
    route_port=$backend_port

    write_state switch-intent "$color" "$route_backend" "$route_port" "$route_ack"
    test_crash after-switch-intent
    write_runtime "$color" "$route_backend" "$route_port" "$route_ack" bootstrap-a
    probe "$color" "$route_ack" bootstrap-a
    write_state bootstrap-a-acknowledged "$color" "$route_backend" "$route_port" "$route_ack"
    test_crash after-bootstrap-a

    write_runtime "$color" "$route_backend" "$route_port" "$route_ack" bootstrap-a
    probe "$color" "$route_ack" bootstrap-a
    write_state captured "$color" "$route_backend" "$route_port" "$route_ack"
    test_crash after-original-direct-drain

    if legacy_owner_present; then
        return
    fi
    reconcile_permanent_b
}

reconcile_permanent_b()
{
    route_ack=$(read_ack)
    route_backend=$(backend_for_color "$color")
    route_port=$backend_port
    legacy_owner_present \
        && fail 'phase B is forbidden while the legacy Docker owner still exists'
    write_state permanent-intent "$color" "$route_backend" "$route_port" "$route_ack"
    write_runtime "$color" "$route_backend" "$route_port" "$route_ack" permanent-b
    probe "$color" "$route_ack" permanent-b
    write_state permanent-b-acknowledged "$color" "$route_backend" "$route_port" "$route_ack"
    test_crash after-permanent-b

    write_runtime "$color" "$route_backend" "$route_port" "$route_ack" permanent-b
    probe "$color" "$route_ack" permanent-b
    write_state complete "$color" "$route_backend" "$route_port" "$route_ack"
}

assert_target()
{
    [ -f "$state_file" ] || fail ':8000 state is missing'
    route_ack=$(read_ack)
    route_backend=$(backend_for_color "$color")
    [ "$(state_value color)" = "$color" ] \
        && [ "$(state_value ack)" = "$route_ack" ] \
        && [ "$(runtime_value backend)" = "$route_backend" ] \
        && [ "$(runtime_value port)" = "$backend_port" ] \
        && [ "$(runtime_value ack)" = "$route_ack" ] \
        || fail ':8000 durable intent and runtime target differ'
    if legacy_owner_present; then
        legacy_owner_running \
            || fail ':8000 phase A refuses a stopped legacy Docker owner'
        [ "$(state_value phase)" = captured ] \
            && [ "$(runtime_value owner)" = bootstrap-a ] \
            || fail ':8000 phase A changed before legacy Docker ownership ended'
        return
    fi
    case "$(state_value phase)" in
        captured|permanent-intent|permanent-b-acknowledged)
            reconcile_permanent_b
            ;;
    esac
    [ "$(state_value phase)" = complete ] \
        && [ "$(runtime_value owner)" = permanent-b ] \
        || fail ':8000 did not converge to permanent phase B after legacy removal'
}

verify_target_ack()
{
    [ -f "$state_file" ] || fail ':8000 state is missing'
    route_ack=$(read_ack)
    route_backend=$(backend_for_color "$color")
    [ "$(state_value color)" = "$color" ] \
        && [ "$(state_value ack)" = "$route_ack" ] \
        && [ "$(runtime_value backend)" = "$route_backend" ] \
        && [ "$(runtime_value port)" = "$backend_port" ] \
        && [ "$(runtime_value ack)" = "$route_ack" ] \
        || fail ':8000 durable intent and runtime target differ'
    if legacy_owner_present; then
        legacy_owner_running \
            || fail ':8000 pure verification refuses a stopped legacy Docker owner'
        [ "$(state_value phase)" = captured ] \
            && [ "$(runtime_value owner)" = bootstrap-a ] \
            || fail ':8000 pure verification requires captured phase A before legacy removal'
        probe "$color" "$route_ack" bootstrap-a
        return
    fi
    [ "$(state_value phase)" = complete ] \
        && [ "$(runtime_value owner)" = permanent-b ] \
        || fail ':8000 pure verification refuses an unreconciled permanent phase'
    probe "$color" "$route_ack" permanent-b
}

acknowledge()
{
    if [ "$color" = legacy ]; then
        probe legacy none legacy
    else
        route_ack=$(read_ack)
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
    write_state restored legacy "$backup_backend" "$backup_port" none
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
backend_port=${CONTROL_PLANE_BACKEND_PORT:-8080}
ack_file=${CONTROL_PLANE_INGRESS_ACK_FILE:-}
direct_probe_token_file=${CONTROL_PLANE_INGRESS_DIRECT_PROBE_TOKEN_FILE:-none}
direct_probe_path=${CONTROL_PLANE_INGRESS_DIRECT_PROBE_PATH:-}
config_file=${CONTROL_PLANE_LAB_PORT_CONFIG:-}
legacy_container=${CONTROL_PLANE_BLUE_CONTAINER:-}
green_container=${CONTROL_PLANE_GREEN_CONTAINER:-}
blue_container=${CONTROL_PLANE_REPLACEMENT_BLUE_CONTAINER:-}
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
        fail 'usage: port8000-controller.sh {preflight|prepare|switch|assert|ack|verify-ack|restore|legacy-restore-status|policy-status|external-blocked-status}'
        ;;
esac
