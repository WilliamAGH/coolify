#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

fail()
{
    printf 'CONTROL_PLANE_PROVIDER_PROBE_FAILURE %s\n' "$1" >&2
    exit 1
}

validate_provider_api_url()
{
    local url=$1 authority_and_resource authority resource port
    local resource_pattern='^/([A-Za-z0-9._~/:+,=@-]|%[A-Fa-f0-9]{2})*(\?([A-Za-z0-9._~/:+,=&@?-]|%[A-Fa-f0-9]{2})+)?$'

    [[ $url == http://* && $url != *'#'* ]] || return 1
    authority_and_resource=${url#http://}
    [[ $authority_and_resource == */* ]] || return 1
    authority=${authority_and_resource%%/*}
    resource=/${authority_and_resource#*/}
    case "$authority" in
        127.0.0.1:*) port=${authority#127.0.0.1:} ;;
        \[::1\]:*) port=${authority#\[::1\]:} ;;
        *) return 1 ;;
    esac
    [[ $port =~ ^[1-9][0-9]{0,4}$ ]] && ((port <= 65535)) \
        && [[ $resource =~ $resource_pattern ]]
}

provider_member_set_sha256()
{
    {
        printf '%s\0' "$expectation" "$retired_member_count"
        printf '%s\0' retired_a "$retired_a_name" "$retired_a_id"
        printf '%s\0' retired_b "$retired_b_name" "$retired_b_id"
    } | sha256sum | awk '{print $1}'
}

provider_backend_assignment_sha256()
{
    {
        printf '%s\0' retired_a "$retired_a_backend_url"
        printf '%s\0' retired_b "$retired_b_backend_url"
    } | sha256sum | awk '{print $1}'
}

assert_retired_member_absent()
{
    local role=$1 name=$2 id=$3
    if docker inspect --format '{{.Id}}' "$name" >/dev/null 2>&1; then
        fail "$role name still resolves while the provider expectation is absent"
    fi
    if docker inspect --format '{{.Id}}' "$id" >/dev/null 2>&1; then
        fail "$role exact ID still resolves while the provider expectation is absent"
    fi
}

api_url=${CONTROL_PLANE_RUNTIME_PROVIDER_API_URL:-}
router=${CONTROL_PLANE_RUNTIME_PROVIDER_ROUTER:-}
service=${CONTROL_PLANE_RUNTIME_PROVIDER_SERVICE:-}
header_file=${CONTROL_PLANE_RUNTIME_PROVIDER_HEADER_FILE:-}
legacy_port=${CONTROL_PLANE_RUNTIME_PROVIDER_LEGACY_PORT:-}
operation_id=${CONTROL_PLANE_RUNTIME_OPERATION_ID:-}
proxy_id=${CONTROL_PLANE_RUNTIME_PROXY_ID:-}
expectation=${CONTROL_PLANE_RUNTIME_PROVIDER_EXPECTATION:-}
retired_member_count=${CONTROL_PLANE_RUNTIME_RETIRED_MEMBER_COUNT:-}
retired_a_name=${CONTROL_PLANE_RUNTIME_RETIRED_A_NAME:-}
retired_a_id=${CONTROL_PLANE_RUNTIME_RETIRED_A_ID:-}
retired_b_name=${CONTROL_PLANE_RUNTIME_RETIRED_B_NAME:-}
retired_b_id=${CONTROL_PLANE_RUNTIME_RETIRED_B_ID:-}
output=$(mktemp /tmp/control-plane-provider.XXXXXX)
trap 'rm -f "$output"' EXIT HUP INT TERM

validate_provider_api_url "$api_url" \
    || fail 'provider API URL must use exact loopback HTTP authority and a valid port/path'
[[ $router =~ ^[A-Za-z0-9_.-]+@docker$ && $service =~ ^[A-Za-z0-9_.-]+@docker$ ]] \
    || fail 'provider router/service must be exact Docker-provider keys'
[[ $operation_id =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ \
    && $proxy_id =~ ^[a-f0-9]{64}$ \
    && $expectation =~ ^(incumbent|absent)$ \
    && $retired_member_count =~ ^[12]$ \
    && $retired_a_name =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ \
    && $retired_a_id =~ ^[a-f0-9]{64}$ \
    && $legacy_port =~ ^[1-9][0-9]{0,4}$ ]] \
    || fail 'requested pooled provider identity is malformed'
((legacy_port <= 65535)) || fail 'legacy provider backend port is out of range'
if [[ $retired_member_count == 1 ]]; then
    [[ $retired_b_name == absent && $retired_b_id == absent ]] \
        || fail 'one-member retired pool must encode retired B as absent'
else
    [[ $retired_b_name =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ \
        && $retired_b_id =~ ^[a-f0-9]{64}$ \
        && $retired_b_name != "$retired_a_name" \
        && $retired_b_id != "$retired_a_id" ]] \
        || fail 'two-member retired pool B identity is malformed or duplicated'
fi

proxy_runtime=$(docker inspect --format '{{.Id}}|{{.State.Running}}|{{.State.Pid}}' "$proxy_id") \
    || fail 'exact proxy container is unavailable'
IFS='|' read -r observed_proxy_id proxy_running proxy_pid <<< "$proxy_runtime"
[[ $observed_proxy_id == "$proxy_id" && $proxy_running == true \
    && $proxy_pid =~ ^[1-9][0-9]*$ ]] \
    || fail 'exact proxy container process identity is malformed or stopped'

curl_arguments=(--fail --silent --show-error --max-time 5)
if [[ -n $header_file ]]; then
    [[ -f $header_file && ! -L $header_file && $(stat -c '%u:%g:%a' "$header_file") == 0:0:600 ]] \
        || fail 'provider header file must be root:root mode 0600 and non-symlink'
    curl_arguments+=(--header "@$header_file")
fi
nsenter --target "$proxy_pid" --net -- curl "${curl_arguments[@]}" "$api_url" > "$output"
[[ $(docker inspect --format '{{.Id}}|{{.State.Running}}|{{.State.Pid}}' "$proxy_id") \
    == "$proxy_runtime" ]] \
    || fail 'exact proxy container process changed during provider retrieval'

member_set_sha256=$(provider_member_set_sha256)
if [[ $expectation == absent ]]; then
    assert_retired_member_absent retired_a "$retired_a_name" "$retired_a_id"
    if [[ $retired_member_count == 2 ]]; then
        assert_retired_member_absent retired_b "$retired_b_name" "$retired_b_id"
    fi
    jq -e --arg router "$router" --arg service "$service" '
        (.routers[$router] == null)
        and (.services[$service] == null)
        and ((.errors // []) | length == 0)
    ' "$output" >/dev/null \
        || fail 'Traefik Docker provider still exposes the removed retired-pool router/service or reports errors'
    retired_a_backend_url=absent
    retired_b_backend_url=absent
    backend_set_sha256=$(provider_backend_assignment_sha256)
else
    declare -a retired_name=() retired_id=()
    retired_name=("$retired_a_name")
    retired_id=("$retired_a_id")
    if [[ $retired_member_count == 2 ]]; then
        retired_name+=("$retired_b_name")
        retired_id+=("$retired_b_id")
    fi
    proxy_inventory=$(docker inspect "$proxy_id") \
        || fail 'exact proxy runtime inventory is unavailable'
    member_address_map='[]'
    for member_index in "${!retired_name[@]}"; do
        member_role=retired_a
        [[ $member_index == 0 ]] || member_role=retired_b
        observed_member_id=$(docker inspect --format '{{.Id}}' \
            "${retired_name[$member_index]}") \
            || fail "retired member name is unavailable: ${retired_name[$member_index]}"
        [[ $observed_member_id == "${retired_id[$member_index]}" ]] \
            || fail "retired member name does not resolve to its exact ID: ${retired_name[$member_index]}"
        member_inventory=$(docker inspect "${retired_id[$member_index]}") \
            || fail "retired member exact runtime inventory is unavailable: ${retired_name[$member_index]}"
        member_addresses=$(jq -c --arg member "${retired_id[$member_index]}" \
            --argjson proxy "$proxy_inventory" '
                if (($proxy | length) == 1 and $proxy[0].Id != ""
                    and $proxy[0].State.Running == true) then .
                else error("proxy inventory is malformed or stopped") end
                | if ((length) == 1 and .[0].Id == $member
                    and .[0].State.Running == true) then .
                  else error("retired member inventory is malformed or stopped") end
                | .[0] as $retired
                | $proxy[0] as $proxy_container
                | ([
                    $retired.NetworkSettings.Networks
                    | to_entries[]
                    | select(
                        .value.NetworkID as $network_id
                        | any($proxy_container.NetworkSettings.Networks[];
                            .NetworkID == $network_id)
                    )
                    | .value.IPAddress, .value.GlobalIPv6Address
                    | select(type == "string" and length > 0)
                ] | unique | sort)
            ' <<< "$member_inventory") \
            || fail "retired member has no exact address on a network shared with the proxy: $member_role"
        [[ $(jq -r 'length' <<< "$member_addresses") -ge 1 ]] \
            || fail "retired member has no exact address on a network shared with the proxy: $member_role"
        member_address_map=$(jq -c -n --argjson members "$member_address_map" \
            --arg role "$member_role" --arg name "${retired_name[$member_index]}" \
            --arg id "${retired_id[$member_index]}" --argjson addresses "$member_addresses" \
            '$members + [{role: $role, name: $name, id: $id, addresses: $addresses}]')
    done
    service_name=${service%@docker}
    backend_assignments=$(jq -e -c --arg router "$router" --arg service "$service" \
        --arg service_name "$service_name" --arg port "$legacy_port" \
        --argjson members "$member_address_map" '
        def rendered_host($address):
            if ($address | contains(":")) then "[" + $address + "]" else $address end;
        def backend_url_for($address; $url):
            $url == ("http://" + rendered_host($address) + ":" + $port)
                or $url == ("https://" + rendered_host($address) + ":" + $port);
        if ((.routers[$router].provider == "docker")
        and (.routers[$router].status == "enabled")
        and (.routers[$router].service == $service_name)
        and (.services[$service].provider == "docker")
        and (.services[$service].status == "enabled")
        and (.services[$service].usedBy == [$router])
        and ([.services[$service].loadBalancer.servers[]?.url | rtrimstr("/")] as $urls
            | ($urls | length) == ($members | length)
            and ($urls | length) == ($urls | unique | length)
            and all($members[];
                . as $member
                | ([$urls[] as $url
                    | select(any($member.addresses[]; backend_url_for(.; $url)))
                    | $url]
                    | length) == 1)
            and all($urls[];
                . as $url
                | ([$members[] as $member
                    | select(any($member.addresses[]; backend_url_for(.; $url)))
                    | $member.role]
                    | length) == 1)
            and ((.services[$service].serverStatus // {}) as $statuses
                | ($statuses | keys | map(rtrimstr("/")) | sort) == ($urls | sort)
                and all($statuses[]; . == "UP")))
        and ((.errors // []) | length == 0))
        then [.services[$service].loadBalancer.servers[]?.url | rtrimstr("/")] as $urls
            | [$members[] as $member
                | {role: $member.role,
                   backend_url: ($urls[] as $url
                       | select(any($member.addresses[]; backend_url_for(.; $url)))
                       | $url)}]
        else error("provider backend assignment is not exact") end
    ' "$output") \
        || fail 'Traefik rawdata does not bind the exact retired pool to the captured Docker backend set'
    retired_a_backend_url=$(jq -r '.[] | select(.role == "retired_a") | .backend_url' \
        <<< "$backend_assignments")
    if [[ $retired_member_count == 2 ]]; then
        retired_b_backend_url=$(jq -r '.[] | select(.role == "retired_b") | .backend_url' \
            <<< "$backend_assignments")
    else
        retired_b_backend_url=absent
    fi
    backend_set_sha256=$(provider_backend_assignment_sha256)
fi
snapshot=$(jq -S -c . "$output" | sha256sum | awk '{print $1}')
printf 'version=2\nstatus=fresh\nobserved_at_epoch=%s\noperation_id=%s\nproxy_id=%s\nproxy_pid=%s\n' \
    "$(date -u +%s)" "$operation_id" "$proxy_id" "$proxy_pid"
printf 'expectation=%s\nretired_member_count=%s\nretired_a_name=%s\nretired_a_id=%s\n' \
    "$expectation" "$retired_member_count" "$retired_a_name" "$retired_a_id"
printf 'retired_b_name=%s\nretired_b_id=%s\nretired_a_backend_url=%s\nretired_b_backend_url=%s\n' \
    "$retired_b_name" "$retired_b_id" "$retired_a_backend_url" "$retired_b_backend_url"
printf 'member_set_sha256=%s\nbackend_set_sha256=%s\nsnapshot_sha256=%s\n' \
    "$member_set_sha256" "$backend_set_sha256" "$snapshot"
