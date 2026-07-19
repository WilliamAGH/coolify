#!/bin/sh

set -eu

repository_root="$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)"
dockerfile="${TESTING_HOST_DOCKERFILE:-$repository_root/docker/testing-host/Dockerfile}"
image="${TESTING_HOST_IMAGE:?TESTING_HOST_IMAGE is required}"
project="coolify-testing-host-runtime-$$"
private_volume="${project}-private"
public_volume="${project}-public"
network="${project}-network"
server_container="${project}-sshd"

fail()
{
    printf 'TESTING_HOST_RUNTIME_CONTRACT_FAILURE %s\n' "$1" >&2
    exit 1
}

argument_value()
{
    name=$1
    value=$(sed -n "s/^ARG $name=//p" "$dockerfile" | sed -n '1p')
    [ -n "$value" ] || fail "missing Dockerfile argument: ${name}"
    printf '%s\n' "$value"
}

cleanup()
{
    docker rm --force "$server_container" >/dev/null 2>&1 || true
    docker network rm "$network" >/dev/null 2>&1 || true
    docker volume rm "$private_volume" "$public_volume" >/dev/null 2>&1 || true
}

trap cleanup EXIT INT TERM

test -f "$dockerfile" || fail "testing-host Dockerfile is absent: ${dockerfile}"
docker_version="$(argument_value DOCKER_VERSION)"
compose_version="$(argument_value DOCKER_COMPOSE_VERSION)"
buildx_version="$(argument_value DOCKER_BUILDX_VERSION)"
docker image inspect "$image" >/dev/null

docker run --rm --pull never --entrypoint /usr/local/bin/docker "$image" --version |
    grep -Eq "^Docker version ${docker_version}, build "
docker run --rm --pull never --entrypoint /usr/local/bin/docker "$image" compose version --short |
    grep -Fx "$compose_version"
docker run --rm --pull never --entrypoint /usr/local/bin/docker "$image" buildx version |
    grep -Eq "^github.com/docker/buildx v${buildx_version}( |$)"

docker volume create "$private_volume" >/dev/null
docker volume create "$public_volume" >/dev/null
docker network create "$network" >/dev/null

for _ in first second; do
    docker run --rm --pull never --user root \
        --mount "type=volume,source=${private_volume},target=/run/coolify-testing-host/private" \
        --mount "type=volume,source=${public_volume},target=/run/coolify-testing-host/public" \
        --env COOLIFY_TESTING_KEY_UID=9999 \
        --env COOLIFY_TESTING_KEY_GID=9999 \
        --entrypoint /bin/sh \
        "$image" /usr/local/bin/coolify-testing-host-keygen
done

docker run --rm --pull never --user root \
    --mount "type=volume,source=${private_volume},target=/run/coolify-testing-host/private,readonly" \
    --mount "type=volume,source=${public_volume},target=/run/coolify-testing-host/public,readonly" \
    --entrypoint /bin/sh \
    "$image" -ec '
        test -s /run/coolify-testing-host/private/testing-host
        test -s /run/coolify-testing-host/public/authorized_keys
        test "$(stat -c %u:%g:%a /run/coolify-testing-host/private/testing-host)" = 9999:9999:600
        test "$(stat -c %u:%g:%a /run/coolify-testing-host/public/authorized_keys)" = 0:0:644
        ssh-keygen -lf /run/coolify-testing-host/public/authorized_keys >/dev/null
        test "$(ssh-keygen -y -f /run/coolify-testing-host/private/testing-host)" = "$(cat /run/coolify-testing-host/public/authorized_keys)"
    '

docker run --detach --pull never --name "$server_container" \
    --network "$network" \
    --network-alias testing-host \
    --mount "type=volume,source=${public_volume},target=/run/coolify-testing-host/public,readonly" \
    "$image" >/dev/null

expected_ssh_output='0
0
testing-host-runtime-command'
attempt=0
until ssh_output="$(docker run --rm --pull never \
    --network "$network" \
    --mount "type=volume,source=${private_volume},target=/run/coolify-testing-host/private,readonly" \
    --entrypoint /bin/sh \
    "$image" -ec '
        ssh -i /run/coolify-testing-host/private/testing-host \
            -o BatchMode=yes \
            -o ConnectTimeout=2 \
            -o LogLevel=ERROR \
            -o StrictHostKeyChecking=no \
            -o UserKnownHostsFile=/dev/null \
            root@testing-host "id -u; id -g; printf testing-host-runtime-command"
    '
)" && [ "$ssh_output" = "$expected_ssh_output" ]; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        docker logs "$server_container" >&2 || true
        fail 'the exact testing-host image did not accept its generated SSH key'
    fi
    sleep 1
done

docker inspect --format '{{.State.Running}}' "$server_container" | grep -qx true
docker exec "$server_container" /usr/sbin/sshd -T | grep -qx 'port 22'

printf 'TESTING_HOST_RUNTIME_CONTRACT_PASS image=%s docker=%s compose=%s buildx=%s\n' \
    "$image" "$docker_version" "$compose_version" "$buildx_version"
