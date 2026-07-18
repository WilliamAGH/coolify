#!/usr/bin/env bash

# Runtime transition and teardown operations for the real Coolify/Laravel stack.

live_stack_write_route_configuration()
{
    local member_a=$1 member_b=$2 candidate

    candidate="$LIVE_STACK_DYNAMIC_DIRECTORY/.route.yml.$$"
    cat > "$candidate" <<EOF
http:
  routers:
    runtime-fence-websecure:
      rule: "PathPrefix(\`/\`)"
      entryPoints:
        - websecure
      service: runtime-fence-live-route
      middlewares:
        - runtime-fence-route-health
      tls: {}
    runtime-fence-local:
      rule: "PathPrefix(\`/\`)"
      entryPoints:
        - coolify-local
      service: runtime-fence-live-route
      middlewares:
        - runtime-fence-route-health
  middlewares:
    runtime-fence-route-health:
      headers:
        customRequestHeaders:
          X-Control-Plane-Route-Health: "${LIVE_STACK_ROUTE_HEALTH_TOKEN}"
  services:
    runtime-fence-live-route:
      loadBalancer:
        strategy: wrr
        servers:
          - url: "http://${member_a}:8080"
          - url: "http://${member_b}:8080"
        healthCheck:
          path: "/api/control-plane/route-health"
          method: GET
          status: 204
          interval: "1s"
          unhealthyInterval: "1s"
          timeout: "1s"
          followRedirects: false
          headers:
            X-Control-Plane-Route-Health: "${LIVE_STACK_ROUTE_HEALTH_TOKEN}"
EOF
    chown root:root "$candidate"
    chmod 0600 "$candidate"
    mv -f -- "$candidate" "$LIVE_STACK_DYNAMIC_DIRECTORY/route.yml"
}

live_stack_start_proxy()
{
    docker run --detach --pull never --name "$LIVE_STACK_PROXY_CONTAINER" --restart unless-stopped \
        --network "$LIVE_STACK_NETWORK" --ip "$LIVE_STACK_PROXY_IP" \
        --publish 127.0.0.1:8443:8443 --publish 127.0.0.1:8000:8000 --publish 127.0.0.1:18080:18080 \
        --label traefik.enable=true \
        --label 'traefik.http.routers.runtime-fence-provider.rule=Path(`/api/rawdata`)' \
        --label traefik.http.routers.runtime-fence-provider.entrypoints=provider \
        --label 'traefik.http.routers.runtime-fence-provider.service=api@internal' \
        --label 'traefik.http.routers.runtime-fence-provider.middlewares=runtime-fence-provider-auth' \
        --label "traefik.http.middlewares.runtime-fence-provider-auth.basicauth.users=${LIVE_STACK_PROVIDER_AUTH}" \
        --mount type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock,readonly \
        --mount "type=bind,source=${LIVE_STACK_TRAEFIK_DIRECTORY}/traefik.yml,target=/etc/traefik/traefik.yml,readonly" \
        --mount "type=bind,source=${LIVE_STACK_DYNAMIC_DIRECTORY},target=/etc/traefik/dynamic,readonly" \
        --mount "type=bind,source=${LIVE_STACK_TLS_DIRECTORY},target=/etc/traefik/tls,readonly" \
        "$LIVE_STACK_PROXY_IMAGE" --configFile=/etc/traefik/traefik.yml >/dev/null
    live_stack_wait_for_provider '(.errors // []) == []'
}

live_stack_wait_for_legacy_provider()
{
    live_stack_wait_for_provider '
        (.routers["coolify@docker"].provider == "docker")
        and ((.services["coolify@docker"].loadBalancer.servers | length) == 2)
        and ((.services["coolify@docker"].serverStatus // {}) | length == 2)
        and all((.services["coolify@docker"].serverStatus // {})[]; . == "UP")
        and ((.errors // []) == [])
    '
}

live_stack_wait_for_route_members()
{
    local first_member=$1 second_member=$2

    live_stack_wait_for_provider "
        ((.services[\"runtime-fence-live-route@file\"].loadBalancer.servers | map(.url | rtrimstr(\"/\")) | sort)
            == [\"http://${first_member}:8080\", \"http://${second_member}:8080\"])
        and ((.errors // []) == [])
    "
}

live_stack_run_member()
{
    local container=$1 volume=$2 address=$3 loopback_port=$4 member_id=$5 web_epoch=$6
    local route_drain_epoch=$7 writer_epoch=${8:-} color=$9 legacy=${10:-false}
    local coordination_volume=${11:-$LIVE_STACK_COORDINATION_VOLUME} direct_probe_runtime=${12}
    local applied_ack_runtime=${13}
    local -a labels=(--label "${LIVE_STACK_POOL_LABEL_KEY}=${LIVE_STACK_OPERATION}")
    local -a writer_options=()

    if [[ $legacy == true ]]; then
        labels=(
            --label traefik.enable=true
            --label "traefik.docker.network=${LIVE_STACK_NETWORK}"
            --label "traefik.http.routers.coolify.rule=Host(\`runtime-fence-provider.invalid\`)" \
            --label traefik.http.routers.coolify.entrypoints=websecure \
            --label traefik.http.routers.coolify.tls=true \
            --label traefik.http.routers.coolify.service=coolify \
            --label traefik.http.services.coolify.loadbalancer.server.port=8080 \
            --label traefik.http.services.coolify.loadbalancer.healthcheck.path=/api/v1/health \
            --label traefik.http.services.coolify.loadbalancer.healthcheck.interval=1s \
            --label traefik.http.services.coolify.loadbalancer.healthcheck.timeout=1s
        )
    fi
    if [[ -n $writer_epoch ]]; then
        writer_options=(
            --env "CONTROL_PLANE_WRITER_EPOCH=${writer_epoch}"
            --env CONTROL_PLANE_WRITER_MARKER_PATH=/var/lib/coolify-control-plane/private/writer-epoch
        )
    fi
    docker run --detach --pull never --name "$container" --restart unless-stopped --ipc private \
        --network "$LIVE_STACK_NETWORK" --ip "$address" --workdir /var/www/html --expose 8080 \
        --publish "127.0.0.1:${loopback_port}:8080" \
        --health-cmd /usr/local/bin/control-plane-direct-probe-healthcheck \
        --health-interval 5s --health-timeout 2s --health-retries 10 \
        --env-file "$LIVE_STACK_APPLICATION_ENVIRONMENT" \
        --env "CONTROL_PLANE_COLOR=${color}" --env "CONTROL_PLANE_MEMBER_ID=${member_id}" \
        --env "CONTROL_PLANE_WEB_EPOCH=${web_epoch}" \
        --env CONTROL_PLANE_WEB_MARKER_PATH=/var/lib/coolify-control-plane/private/web-epoch \
        --env "CONTROL_PLANE_ROUTE_DRAIN_EPOCH=${route_drain_epoch}" \
        --env CONTROL_PLANE_ROUTE_DRAIN_MARKER_PATH=/var/lib/coolify-control-plane/private/route-drain-epoch \
        "${writer_options[@]}" \
        --mount "type=bind,source=${LIVE_STACK_APPLICATION_ENVIRONMENT},target=/var/www/html/.env,readonly" \
        --mount "type=bind,source=${direct_probe_runtime},target=/run/secrets/control-plane-direct-probe-token,readonly" \
        --mount "type=bind,source=${applied_ack_runtime},target=/run/secrets/control-plane-applied-ack,readonly" \
        --mount "type=bind,source=${LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY}/route-health-token,target=/run/secrets/control-plane-route-health-token,readonly" \
        --mount "type=bind,source=${LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY}/pool-ack,target=/run/secrets/control-plane-pool-ack,readonly" \
        --mount "type=volume,source=${volume},target=/var/lib/coolify-control-plane/private" \
        --mount "type=volume,source=${coordination_volume},target=/var/lib/coolify-control-plane/coordination" \
        "${labels[@]}" "$LIVE_STACK_WEB_IMAGE" >/dev/null
}

live_stack_start_blue()
{
    live_stack_run_member "$LIVE_STACK_BLUE_WEB_A_CONTAINER" "$LIVE_STACK_BLUE_WEB_A_PRIVATE_VOLUME" \
        "$LIVE_STACK_BLUE_WEB_A_IP" 18071 "${LIVE_STACK_WEB_A_ROUTE_IDENTITY}-blue" \
        "$LIVE_STACK_WEB_A_EPOCH" "$LIVE_STACK_WEB_A_ROUTE_DRAIN_EPOCH" '' blue true \
        "$LIVE_STACK_COORDINATION_VOLUME" \
        "$LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY/web-a-direct-probe-token" \
        "$LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY/web-a-applied-ack"
    live_stack_run_member "$LIVE_STACK_BLUE_WEB_B_CONTAINER" "$LIVE_STACK_BLUE_WEB_B_PRIVATE_VOLUME" \
        "$LIVE_STACK_BLUE_WEB_B_IP" 18072 "${LIVE_STACK_WEB_B_ROUTE_IDENTITY}-blue" \
        "$LIVE_STACK_WEB_B_EPOCH" "$LIVE_STACK_WEB_B_ROUTE_DRAIN_EPOCH" '' blue true \
        "$LIVE_STACK_COORDINATION_VOLUME" \
        "$LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY/web-b-direct-probe-token" \
        "$LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY/web-b-applied-ack"
    live_stack_wait_for_direct_probe "$LIVE_STACK_BLUE_WEB_A_CONTAINER"
    live_stack_wait_for_direct_probe "$LIVE_STACK_BLUE_WEB_B_CONTAINER"
    live_stack_wait_for_legacy_provider
}

live_stack_start_green_with_volumes()
{
    local web_a_volume=$1 web_b_volume=$2 coordination_volume=${3:-$LIVE_STACK_COORDINATION_VOLUME}

    live_stack_run_member "$LIVE_STACK_GREEN_WEB_A_CONTAINER" "$web_a_volume" \
        "$LIVE_STACK_GREEN_WEB_A_IP" 18081 "$LIVE_STACK_WEB_A_ROUTE_IDENTITY" \
        "$LIVE_STACK_WEB_A_EPOCH" "$LIVE_STACK_WEB_A_ROUTE_DRAIN_EPOCH" \
        "$LIVE_STACK_WEB_A_WRITER_EPOCH" green false "$coordination_volume" \
        "$LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY/web-a-direct-probe-token" \
        "$LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY/web-a-applied-ack"
    live_stack_run_member "$LIVE_STACK_GREEN_WEB_B_CONTAINER" "$web_b_volume" \
        "$LIVE_STACK_GREEN_WEB_B_IP" 18082 "$LIVE_STACK_WEB_B_ROUTE_IDENTITY" \
        "$LIVE_STACK_WEB_B_EPOCH" "$LIVE_STACK_WEB_B_ROUTE_DRAIN_EPOCH" '' green false \
        "$coordination_volume" "$LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY/web-b-direct-probe-token" \
        "$LIVE_STACK_RUNTIME_ARTIFACT_DIRECTORY/web-b-applied-ack"
}

live_stack_start_green()
{
    live_stack_start_green_with_volumes "$LIVE_STACK_GREEN_WEB_A_PRIVATE_VOLUME" \
        "$LIVE_STACK_GREEN_WEB_B_PRIVATE_VOLUME"
    live_stack_wait_for_direct_probe "$LIVE_STACK_GREEN_WEB_A_CONTAINER"
    live_stack_wait_for_direct_probe "$LIVE_STACK_GREEN_WEB_B_CONTAINER"
    live_stack_write_route_configuration "$LIVE_STACK_GREEN_WEB_A_CONTAINER" "$LIVE_STACK_GREEN_WEB_B_CONTAINER"
    live_stack_wait_for_route_members "$LIVE_STACK_GREEN_WEB_A_CONTAINER" "$LIVE_STACK_GREEN_WEB_B_CONTAINER"
    live_stack_wait_for_terminal
}

live_stack_start_wrong_mount()
{
    local kind=$1 wrong_volume

    wrong_volume="${LIVE_STACK_OPERATION}-wrong-${kind}"
    docker volume create "$wrong_volume" >/dev/null
    case "$kind" in
        coordination)
            live_stack_start_green_with_volumes "$LIVE_STACK_GREEN_WEB_A_PRIVATE_VOLUME" \
                "$LIVE_STACK_GREEN_WEB_B_PRIVATE_VOLUME" "$wrong_volume"
            ;;
        private)
            live_stack_start_green_with_volumes "$wrong_volume" "$wrong_volume"
            ;;
        *) live_stack_fail 'unsupported wrong-mount action' ;;
    esac
}

live_stack_remove_retired()
{
    docker rm -f "$LIVE_STACK_BLUE_WEB_A_CONTAINER" "$LIVE_STACK_BLUE_WEB_B_CONTAINER" >/dev/null
    live_stack_wait_for_provider '.routers["coolify@docker"] == null and .services["coolify@docker"] == null and (.errors // []) == []'
}

live_stack_hold_lease()
{
    local mountpoint lease holder ready

    mountpoint=$(docker volume inspect --format '{{.Mountpoint}}' "$LIVE_STACK_COORDINATION_VOLUME")
    lease="$mountpoint/mutation-inflight.lock"
    holder="$LIVE_STACK_ROOT/mutation-lease-holder.pid"
    ready="$LIVE_STACK_ROOT/mutation-lease-held"
    [[ -f $lease && ! -L $lease && ! -e $holder ]] \
        || live_stack_fail 'mutation lease cannot be safely held'
    setsid bash -c "
        exec 9>\"\$1\"
        flock -n -x 9 || exit 1
        : > \"\$2\"
        while :; do sleep 60; done
    " bash "$lease" "$ready" </dev/null > /dev/null 2>&1 &
    printf '%s\n' "$!" > "$holder"
    for _ in {1..20}; do
        [[ -f $ready ]] && return
        sleep 0.1
    done
    live_stack_fail 'mutation lease holder did not acquire the real coordination lock'
}

live_stack_release_lease()
{
    local holder=$LIVE_STACK_ROOT/mutation-lease-holder.pid process

    [[ -f $holder && ! -L $holder ]] || return
    process=$(<"$holder")
    [[ $process =~ ^[1-9][0-9]*$ ]] || live_stack_fail 'mutation lease holder PID is malformed'
    kill "$process" >/dev/null 2>&1 || true
    wait "$process" 2>/dev/null || true
    rm -f -- "$holder" "$LIVE_STACK_ROOT/mutation-lease-held"
}

live_stack_mutate_freeze_marker()
{
    local action=$1

    case "$action" in
        corrupt)
            docker run --rm --pull never --network none --user 0:0 --entrypoint /bin/sh \
                --mount "type=volume,source=${LIVE_STACK_COORDINATION_VOLUME},target=/state" \
                --mount "type=bind,source=${LIVE_STACK_ROOT},target=/host" "$LIVE_STACK_WEB_IMAGE" -ec '
                    cp /state/mutation-freeze-epoch /host/mutation-freeze-epoch.backup
                    printf corrupted > /state/mutation-freeze-epoch
                    chown 0:9999 /state/mutation-freeze-epoch
                    chmod 0440 /state/mutation-freeze-epoch
                ' >/dev/null
            ;;
        restore)
            docker run --rm --pull never --network none --user 0:0 --entrypoint /bin/sh \
                --mount "type=volume,source=${LIVE_STACK_COORDINATION_VOLUME},target=/state" \
                --mount "type=bind,source=${LIVE_STACK_ROOT},target=/host" "$LIVE_STACK_WEB_IMAGE" -ec '
                    test -f /host/mutation-freeze-epoch.backup
                    cp /host/mutation-freeze-epoch.backup /state/mutation-freeze-epoch
                    chown 0:9999 /state/mutation-freeze-epoch
                    chmod 0440 /state/mutation-freeze-epoch
                    rm -f /host/mutation-freeze-epoch.backup
                ' >/dev/null
            ;;
        *) live_stack_fail 'unsupported mutation marker action' ;;
    esac
}

live_stack_cleanup()
{
    local resource ca_destination

    live_stack_release_lease
    docker rm -f "$LIVE_STACK_PROXY_CONTAINER" "$LIVE_STACK_POSTGRES_CONTAINER" \
        "$LIVE_STACK_REDIS_CONTAINER" "$LIVE_STACK_BLUE_WEB_A_CONTAINER" \
        "$LIVE_STACK_BLUE_WEB_B_CONTAINER" "$LIVE_STACK_GREEN_WEB_A_CONTAINER" \
        "$LIVE_STACK_GREEN_WEB_B_CONTAINER" >/dev/null 2>&1 || true
    for resource in "$LIVE_STACK_OPERATION-wrong-coordination" "$LIVE_STACK_OPERATION-wrong-private" \
        "$LIVE_STACK_COORDINATION_VOLUME" "$LIVE_STACK_BLUE_WEB_A_PRIVATE_VOLUME" \
        "$LIVE_STACK_BLUE_WEB_B_PRIVATE_VOLUME" "$LIVE_STACK_GREEN_WEB_A_PRIVATE_VOLUME" \
        "$LIVE_STACK_GREEN_WEB_B_PRIVATE_VOLUME"; do
        docker volume rm "$resource" >/dev/null 2>&1 || true
    done
    docker network rm "$LIVE_STACK_NETWORK" "$LIVE_STACK_ESCAPE_NETWORK" >/dev/null 2>&1 || true
    ca_destination="/usr/local/share/ca-certificates/coolify-runtime-fence-${LIVE_STACK_OPERATION}.crt"
    rm -f -- "$ca_destination"
    update-ca-certificates >/dev/null 2>&1 || true
    rm -rf -- "$LIVE_STACK_ROOT"
    for resource in "$LIVE_STACK_PROXY_CONTAINER" "$LIVE_STACK_POSTGRES_CONTAINER" \
        "$LIVE_STACK_REDIS_CONTAINER" "$LIVE_STACK_BLUE_WEB_A_CONTAINER" \
        "$LIVE_STACK_BLUE_WEB_B_CONTAINER" "$LIVE_STACK_GREEN_WEB_A_CONTAINER" \
        "$LIVE_STACK_GREEN_WEB_B_CONTAINER"; do
        ! docker container inspect "$resource" >/dev/null 2>&1 \
            || live_stack_fail "live-stack container remained after cleanup: $resource"
    done
    for resource in "$LIVE_STACK_OPERATION-wrong-coordination" "$LIVE_STACK_OPERATION-wrong-private" \
        "$LIVE_STACK_COORDINATION_VOLUME" "$LIVE_STACK_BLUE_WEB_A_PRIVATE_VOLUME" \
        "$LIVE_STACK_BLUE_WEB_B_PRIVATE_VOLUME" "$LIVE_STACK_GREEN_WEB_A_PRIVATE_VOLUME" \
        "$LIVE_STACK_GREEN_WEB_B_PRIVATE_VOLUME"; do
        ! docker volume inspect "$resource" >/dev/null 2>&1 \
            || live_stack_fail "live-stack volume remained after cleanup: $resource"
    done
    for resource in "$LIVE_STACK_NETWORK" "$LIVE_STACK_ESCAPE_NETWORK"; do
        ! docker network inspect "$resource" >/dev/null 2>&1 \
            || live_stack_fail "live-stack network remained after cleanup: $resource"
    done
    [[ ! -e $LIVE_STACK_ROOT ]] \
        || live_stack_fail 'live-stack operation directory remained after cleanup'
}
