#!/usr/bin/env bash

# Bootstrap operations for the real Coolify/Laravel stack. This is sourced only
# by live-stack-adapter.sh inside the disposable systemd/DinD host.

readonly LIVE_STACK_POSTGRES_IMAGE='postgres:15.18-alpine3.24@sha256:3d0f7584ed7d04e27fa050d6683a74746608faf21f202be78460d679cc56461f'
readonly LIVE_STACK_REDIS_IMAGE='redis:7-alpine@sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99'

live_stack_wait_for_health()
{
    local container=$1

    for _ in {1..90}; do
        [[ $(docker inspect --format '{{.State.Health.Status}}' "$container" 2>/dev/null || true) == healthy ]] \
            && return
        sleep 1
    done
    live_stack_blocked "real container did not become healthy: $container"
}

live_stack_wait_for_direct_probe()
{
    local container=$1

    for _ in {1..90}; do
        docker exec "$container" /usr/local/bin/control-plane-direct-probe-healthcheck >/dev/null 2>&1 \
            && return
        sleep 1
    done
    live_stack_blocked "real Coolify direct probe did not become healthy: $container"
}

live_stack_wait_for_terminal()
{
    for _ in {1..90}; do
        curl --fail --silent --show-error --max-time 3 \
            https://127.0.0.1:8443/api/control-plane/route-health >/dev/null 2>&1 \
            && curl --fail --silent --show-error --max-time 3 \
                http://127.0.0.1:8000/api/control-plane/route-health >/dev/null 2>&1 \
            && return
        sleep 1
    done
    live_stack_blocked 'real TLS and native Traefik loopback route-health endpoints did not become available'
}

live_stack_wait_for_provider()
{
    local expected=$1 output

    for _ in {1..90}; do
        output=$(mktemp /run/coolify-runtime-fence-provider.XXXXXX)
        if curl --fail --silent --show-error --max-time 3 \
            --header "@${LIVE_STACK_ARTIFACT_DIRECTORY}/provider-header" \
            http://127.0.0.1:18080/api/rawdata > "$output" 2>/dev/null \
            && jq -e "$expected" "$output" >/dev/null; then
            rm -f -- "$output"
            return
        fi
        rm -f -- "$output"
        sleep 1
    done
    live_stack_blocked 'real protected Traefik rawdata endpoint did not converge'
}

live_stack_assert_real_images()
{
    local image

    # The operation-owned archives are loaded before the adapter runs. The
    # local config digests are intentionally pull-proof: a loopback registry
    # from the outer daemon is not reachable from this nested daemon.
    for image in "$LIVE_STACK_WEB_IMAGE" "$LIVE_STACK_PROXY_IMAGE"; do
        docker image inspect "$image" >/dev/null \
            || live_stack_blocked "inner Docker is missing the imported immutable real image: $image"
    done
    for image in "$LIVE_STACK_POSTGRES_IMAGE" "$LIVE_STACK_REDIS_IMAGE"; do
        docker pull "$image" >/dev/null \
            || live_stack_blocked "inner Docker could not pull immutable real image: $image"
        docker image inspect "$image" >/dev/null \
            || live_stack_blocked "inner Docker could not inspect immutable real image: $image"
    done
    docker run --rm --pull never --network none --entrypoint /bin/sh "$LIVE_STACK_WEB_IMAGE" -ec '
        test -x /usr/local/bin/coolify-entrypoint
        test -x /usr/local/bin/control-plane-direct-probe-healthcheck
        test -f /var/www/html/artisan
        test -f /var/www/html/app/Support/ControlPlaneMode.php
        test "$(id -u www-data)" = 9999
        test "$(id -g www-data)" = 9999
        php -r "require \"/var/www/html/vendor/autoload.php\"; exit(class_exists(\"App\\\\Support\\\\ControlPlaneMode\") ? 0 : 1);"
    ' >/dev/null \
        || live_stack_blocked 'the supplied web digest is not the real Coolify/Laravel production image contract'
    docker run --rm --pull never --network none --entrypoint traefik "$LIVE_STACK_PROXY_IMAGE" version \
        | grep -q '^Version:' \
        || live_stack_blocked 'the supplied proxy digest is not a runnable Traefik image'
}

live_stack_assert_absent()
{
    local resource

    for resource in "$LIVE_STACK_PROXY_CONTAINER" "$LIVE_STACK_POSTGRES_CONTAINER" \
        "$LIVE_STACK_REDIS_CONTAINER" "$LIVE_STACK_BLUE_WEB_A_CONTAINER" \
        "$LIVE_STACK_BLUE_WEB_B_CONTAINER" "$LIVE_STACK_GREEN_WEB_A_CONTAINER" \
        "$LIVE_STACK_GREEN_WEB_B_CONTAINER"; do
        ! docker container inspect "$resource" >/dev/null 2>&1 \
            || live_stack_fail "refusing to reuse live-stack container: $resource"
    done
    for resource in "$LIVE_STACK_NETWORK" "$LIVE_STACK_ESCAPE_NETWORK"; do
        ! docker network inspect "$resource" >/dev/null 2>&1 \
            || live_stack_fail "refusing to reuse live-stack network: $resource"
    done
    for resource in "$LIVE_STACK_OPERATION-wrong-coordination" "$LIVE_STACK_OPERATION-wrong-private" \
        "$LIVE_STACK_COORDINATION_VOLUME" "$LIVE_STACK_BLUE_WEB_A_PRIVATE_VOLUME" \
        "$LIVE_STACK_BLUE_WEB_B_PRIVATE_VOLUME" "$LIVE_STACK_GREEN_WEB_A_PRIVATE_VOLUME" \
        "$LIVE_STACK_GREEN_WEB_B_PRIVATE_VOLUME"; do
        ! docker volume inspect "$resource" >/dev/null 2>&1 \
            || live_stack_fail "refusing to reuse live-stack volume: $resource"
    done
}

live_stack_assert_initial_volume_plan()
{
    local expected actual

    expected=$(printf '%s\n' "$LIVE_STACK_COORDINATION_VOLUME" "$LIVE_STACK_BLUE_WEB_A_PRIVATE_VOLUME" \
        "$LIVE_STACK_BLUE_WEB_B_PRIVATE_VOLUME" "$LIVE_STACK_GREEN_WEB_A_PRIVATE_VOLUME" \
        "$LIVE_STACK_GREEN_WEB_B_PRIVATE_VOLUME" | LC_ALL=C sort)
    actual=$(docker volume ls --format '{{.Name}}' \
        | awk -v prefix="${LIVE_STACK_OPERATION}-" 'index($0, prefix) == 1 { print }' \
        | LC_ALL=C sort)
    [[ $actual == "$expected" ]] \
        || live_stack_fail 'the bootstrap stack must own exactly five named operation volumes'
}

live_stack_create_resources()
{
    docker network create --driver bridge --subnet 172.30.0.0/24 --gateway 172.30.0.1 \
        "$LIVE_STACK_NETWORK" >/dev/null
    docker network create --driver bridge --ipv6 --subnet 172.31.0.0/24 --gateway 172.31.0.1 \
        --subnet fd20:ca11:feed:31::/64 --gateway fd20:ca11:feed:31::1 \
        "$LIVE_STACK_ESCAPE_NETWORK" >/dev/null
    docker volume create "$LIVE_STACK_COORDINATION_VOLUME" >/dev/null
    docker volume create "$LIVE_STACK_BLUE_WEB_A_PRIVATE_VOLUME" >/dev/null
    docker volume create "$LIVE_STACK_BLUE_WEB_B_PRIVATE_VOLUME" >/dev/null
    docker volume create "$LIVE_STACK_GREEN_WEB_A_PRIVATE_VOLUME" >/dev/null
    docker volume create "$LIVE_STACK_GREEN_WEB_B_PRIVATE_VOLUME" >/dev/null
    live_stack_assert_initial_volume_plan
}

live_stack_initialize_private_volume()
{
    local volume=$1 epoch=$2 writer_epoch=${3:-}

    docker run --rm --pull never --network none --user 0:0 --entrypoint /bin/sh \
        --mount "type=volume,source=${volume},target=/state" "$LIVE_STACK_WEB_IMAGE" -ec '
            mkdir -p /state
            chown 0:9999 /state
            chmod 0750 /state
            printf %s "$WEB_EPOCH" > /state/web-epoch
            chown 0:9999 /state/web-epoch
            chmod 0440 /state/web-epoch
            if [ -n "$WRITER_EPOCH" ]; then
                printf %s "$WRITER_EPOCH" > /state/writer-epoch
                chown 0:9999 /state/writer-epoch
                chmod 0440 /state/writer-epoch
            fi
        ' \
        --env "WEB_EPOCH=$epoch" --env "WRITER_EPOCH=$writer_epoch" >/dev/null
}

live_stack_initialize_state()
{
    local volume

    docker run --rm --pull never --network none --user 0:0 --entrypoint /bin/sh \
        --mount "type=volume,source=${LIVE_STACK_COORDINATION_VOLUME},target=/state" \
        "$LIVE_STACK_WEB_IMAGE" -ec '
            mkdir -p /state
            chown 0:9999 /state
            chmod 0750 /state
            printf %s "$FREEZE_EPOCH" > /state/mutation-freeze-epoch
            chown 0:9999 /state/mutation-freeze-epoch
            chmod 0440 /state/mutation-freeze-epoch
            : > /state/mutation-inflight.lock
            chown 0:9999 /state/mutation-inflight.lock
            chmod 0660 /state/mutation-inflight.lock
        ' --env "FREEZE_EPOCH=$LIVE_STACK_MUTATION_FREEZE_EPOCH" >/dev/null
    live_stack_initialize_private_volume "$LIVE_STACK_BLUE_WEB_A_PRIVATE_VOLUME" "$LIVE_STACK_WEB_A_EPOCH"
    live_stack_initialize_private_volume "$LIVE_STACK_BLUE_WEB_B_PRIVATE_VOLUME" "$LIVE_STACK_WEB_B_EPOCH"
    live_stack_initialize_private_volume "$LIVE_STACK_GREEN_WEB_A_PRIVATE_VOLUME" \
        "$LIVE_STACK_WEB_A_EPOCH" "$LIVE_STACK_WEB_A_WRITER_EPOCH"
    live_stack_initialize_private_volume "$LIVE_STACK_GREEN_WEB_B_PRIVATE_VOLUME" "$LIVE_STACK_WEB_B_EPOCH"
    for volume in "$LIVE_STACK_COORDINATION_VOLUME" "$LIVE_STACK_BLUE_WEB_A_PRIVATE_VOLUME" \
        "$LIVE_STACK_BLUE_WEB_B_PRIVATE_VOLUME" "$LIVE_STACK_GREEN_WEB_A_PRIVATE_VOLUME" \
        "$LIVE_STACK_GREEN_WEB_B_PRIVATE_VOLUME"; do
        docker volume inspect "$volume" >/dev/null
    done
}

live_stack_start_dependencies()
{
    local identity
    local -a postgres_identity=()

    mapfile -t postgres_identity < <(docker run --rm --pull never --network none \
        --entrypoint /bin/sh "$LIVE_STACK_POSTGRES_IMAGE" -ec 'id -u postgres; id -g postgres')
    [[ ${#postgres_identity[@]} == 2 && ${postgres_identity[0]} =~ ^[0-9]+$ \
        && ${postgres_identity[1]} =~ ^[0-9]+$ ]] \
        || live_stack_blocked 'the pinned PostgreSQL image has no usable postgres runtime identity'
    install -d -m 0700 -o "${postgres_identity[0]}" -g "${postgres_identity[1]}" \
        "$LIVE_STACK_POSTGRES_DIRECTORY"
    docker run --detach --pull never --name "$LIVE_STACK_POSTGRES_CONTAINER" --restart unless-stopped \
        --network "$LIVE_STACK_NETWORK" --ip "$LIVE_STACK_POSTGRES_IP" \
        --mount "type=bind,source=${LIVE_STACK_POSTGRES_DIRECTORY},target=/var/lib/postgresql/data" \
        --health-cmd 'pg_isready -U coolify -d coolify' --health-interval 2s --health-timeout 2s \
        --health-retries 60 --env POSTGRES_DB=coolify --env POSTGRES_USER=coolify \
        --env "POSTGRES_PASSWORD=${LIVE_STACK_DATABASE_PASSWORD}" "$LIVE_STACK_POSTGRES_IMAGE" >/dev/null
    docker run --detach --pull never --name "$LIVE_STACK_REDIS_CONTAINER" --restart unless-stopped \
        --network "$LIVE_STACK_NETWORK" --ip "$LIVE_STACK_REDIS_IP" \
        --tmpfs /data:rw,mode=1777 \
        --health-cmd 'redis-cli ping' --health-interval 2s --health-timeout 2s --health-retries 60 \
        "$LIVE_STACK_REDIS_IMAGE" redis-server --save '' --appendonly no >/dev/null
    live_stack_wait_for_health "$LIVE_STACK_POSTGRES_CONTAINER"
    live_stack_wait_for_health "$LIVE_STACK_REDIS_CONTAINER"
    identity=$(docker inspect --format '{{.State.Running}}' "$LIVE_STACK_POSTGRES_CONTAINER")
    [[ $identity == true ]] || live_stack_blocked 'real PostgreSQL container stopped after health verification'
}
