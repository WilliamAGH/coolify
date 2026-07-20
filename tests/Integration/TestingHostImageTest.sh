#!/bin/sh

set -eu

repository_root="$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)"
dockerfile="${TESTING_HOST_DOCKERFILE:-$repository_root/docker/testing-host/Dockerfile}"
development_authorized_keys="$repository_root/docker/testing-host/development-authorized_keys"
development_private_key="$repository_root/docker/testing-host/development-private_key"
image="${TESTING_HOST_IMAGE:?TESTING_HOST_IMAGE is required}"
project="coolify-testing-host-runtime-$$"
private_volume="${project}-private"
public_volume="${project}-public"
network="${project}-network"
development_server_container="${project}-development-sshd"
server_container="${project}-sshd"
seed_private_key="$(mktemp)"
windows_private_key="$(mktemp)"

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
    docker rm --force "$development_server_container" >/dev/null 2>&1 || true
    docker rm --force "$server_container" >/dev/null 2>&1 || true
    docker network rm "$network" >/dev/null 2>&1 || true
    docker volume rm "$private_volume" "$public_volume" >/dev/null 2>&1 || true
    rm -f "$seed_private_key" "$windows_private_key"
}

assert_compose_authorized_keys_mount()
{
    service=$1
    shift
    compose_config="$(docker compose "$@" config --no-env-resolution --no-interpolate --format json)" \
        || fail "${service} Compose configuration did not render"
    printf '%s\n' "$compose_config" |
        jq -e --arg service "$service" --arg source "$development_authorized_keys" '
            .services[$service].volumes
            | any(
                .type == "bind"
                and .source == $source
                and .target == "/run/coolify-testing-host/public/authorized_keys"
                and .read_only == true
            )
        ' >/dev/null || fail "${service} does not mount the development testing-host identity"
}

assert_compose_private_key_mount()
{
    service=$1
    source=$2
    shift 2
    compose_config="$(docker compose "$@" config --no-env-resolution --no-interpolate --format json)" \
        || fail "${service} Compose configuration did not render"
    printf '%s\n' "$compose_config" |
        jq -e --arg service "$service" --arg source "$source" '
            .services[$service].volumes
            | any(
                .type == "bind"
                and .source == $source
                and .target == "/run/coolify-testing-host/private/testing-host"
                and .read_only == true
            )
        ' >/dev/null || fail "${service} does not mount the development testing-host private key read-only"
}

assert_ssh_access()
{
    target_host=$1
    target_container=$2
    private_key_mount=$3
    expected_ssh_output='0
0
testing-host-runtime-command'
    attempt=0
    until ssh_output="$(docker run --rm --pull never \
        --network "$network" \
        --mount "$private_key_mount" \
        --env TARGET_HOST="$target_host" \
        --entrypoint /bin/sh \
        "$image" -ec '
            ssh -i /run/coolify-testing-host/private/testing-host \
                -o BatchMode=yes \
                -o ConnectTimeout=2 \
                -o LogLevel=ERROR \
                -o StrictHostKeyChecking=no \
                -o UserKnownHostsFile=/dev/null \
                "root@$TARGET_HOST" "id -u; id -g; printf testing-host-runtime-command"
        '
    )" && [ "$ssh_output" = "$expected_ssh_output" ]; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 30 ]; then
            docker logs "$target_container" >&2 || true
            fail "the exact testing-host image did not accept the ${target_host} SSH key"
        fi
        sleep 1
    done
}

extract_testing_host_private_key()
{
    key_name=$1
    source_file=$2
    destination=$3
    awk -v key_name="$key_name" '
        /name/ && index($0, key_name) { testing_host = 1 }
        testing_host && /private_key/ && index($0, "-----BEGIN OPENSSH PRIVATE KEY-----") {
            capture = 1
            print "-----BEGIN OPENSSH PRIVATE KEY-----"
            next
        }
        capture && /^-----END OPENSSH PRIVATE KEY-----/ {
            print "-----END OPENSSH PRIVATE KEY-----"
            exit
        }
        capture { print }
    ' "$source_file" > "$destination"
    chmod 600 "$destination"
    ssh-keygen -y -f "$destination" >/dev/null 2>&1 \
        || fail "${key_name} seeded private key is missing or invalid"
}

assert_private_key_matches_authorized_keys()
{
    key_name=$1
    private_key=$2
    [ "$(ssh-keygen -y -f "$private_key" | awk '{ print $1 " " $2 }')" = \
        "$(awk '{ print $1 " " $2 }' "$development_authorized_keys")" ] \
        || fail "${key_name} seeded private key does not match the development testing-host public key"
}

trap cleanup EXIT INT TERM

test -f "$dockerfile" || fail "testing-host Dockerfile is absent: ${dockerfile}"
test -s "$development_authorized_keys" || fail 'development testing-host public key is absent'
ssh-keygen -lf "$development_authorized_keys" >/dev/null 2>&1 || fail 'development testing-host public key is invalid'
test -s "$development_private_key" || fail 'development testing-host private key is absent'
cp "$development_private_key" "$windows_private_key"
chmod 600 "$windows_private_key"
ssh-keygen -y -f "$windows_private_key" >/dev/null 2>&1 || fail 'development testing-host private key is invalid'
assert_compose_authorized_keys_mount testing-host \
    -f "$repository_root/docker-compose.yml" \
    -f "$repository_root/docker-compose.dev.yml"
assert_compose_authorized_keys_mount testing-host \
    -f "$repository_root/docker-compose.yml" \
    -f "$repository_root/docker-compose-maxio.dev.yml"
assert_compose_authorized_keys_mount coolify-testing-host \
    -f "$repository_root/docker-compose.windows.yml"
assert_compose_authorized_keys_mount coolify-testing-host \
    -f "$repository_root/other/nightly/docker-compose.windows.yml"
assert_compose_private_key_mount coolify "$development_private_key" \
    -f "$repository_root/docker-compose.windows.yml"
assert_compose_private_key_mount coolify "$development_private_key" \
    -f "$repository_root/other/nightly/docker-compose.windows.yml"

extract_testing_host_private_key 'Testing Host Key' \
    "$repository_root/database/seeders/PrivateKeySeeder.php" "$seed_private_key"
assert_private_key_matches_authorized_keys 'development' "$seed_private_key"
assert_private_key_matches_authorized_keys 'Windows' "$windows_private_key"

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

docker run --detach --pull never --name "$development_server_container" \
    --network "$network" \
    --network-alias development-testing-host \
    --mount "type=bind,source=${development_authorized_keys},target=/run/coolify-testing-host/public/authorized_keys,readonly" \
    "$image" >/dev/null

assert_ssh_access development-testing-host "$development_server_container" \
    "type=bind,source=${seed_private_key},target=/run/coolify-testing-host/private/testing-host,readonly"
assert_ssh_access development-testing-host "$development_server_container" \
    "type=bind,source=${windows_private_key},target=/run/coolify-testing-host/private/testing-host,readonly"
docker inspect --format '{{.State.Running}}' "$development_server_container" | grep -qx true
docker rm --force "$development_server_container" >/dev/null

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

assert_ssh_access testing-host "$server_container" \
    "type=volume,source=${private_volume},target=/run/coolify-testing-host/private,readonly"

docker inspect --format '{{.State.Running}}' "$server_container" | grep -qx true
docker exec "$server_container" /usr/sbin/sshd -T | grep -qx 'port 22'

printf 'TESTING_HOST_RUNTIME_CONTRACT_PASS image=%s docker=%s compose=%s buildx=%s\n' \
    "$image" "$docker_version" "$compose_version" "$buildx_version"
