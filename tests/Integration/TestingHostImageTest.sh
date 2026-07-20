#!/bin/sh

set -eu

repository_root="$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)"
dockerfile="${TESTING_HOST_DOCKERFILE:-$repository_root/docker/testing-host/Dockerfile}"
development_authorized_keys="$repository_root/docker/testing-host/development-authorized_keys"
image="${TESTING_HOST_IMAGE:?TESTING_HOST_IMAGE is required}"
production_image="${PRODUCTION_IMAGE:?PRODUCTION_IMAGE is required}"
database_image="${TESTING_HOST_DATABASE_IMAGE:-docker.io/library/postgres:16-alpine@sha256:57c72fd2a128e416c7fcc499958864df5301e940bca0a56f58fddf30ffc07777}"
redis_image="${TESTING_HOST_REDIS_IMAGE:-docker.io/library/redis:7-alpine@sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99}"
project="coolify-testing-host-runtime-$$"
private_volume="${project}-private"
public_volume="${project}-public"
network="${project}-network"
server_container="${project}-sshd"
database_container="${project}-postgres"
redis_container="${project}-redis"

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
    docker rm --force "$database_container" >/dev/null 2>&1 || true
    docker rm --force "$redis_container" >/dev/null 2>&1 || true
    docker network rm "$network" >/dev/null 2>&1 || true
    docker volume rm "$private_volume" "$public_volume" >/dev/null 2>&1 || true
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

assert_windows_runtime_key_contract()
{
    compose_file=$1
    compose_config="$(docker compose \
        -f "$compose_file" \
        config --no-env-resolution --no-interpolate --format json)" \
        || fail 'Windows Compose configuration did not render'

    printf '%s\n' "$compose_config" | jq -e '
        .services["coolify-testing-host"] as $host
        | .services.coolify as $coolify
        | ($host.environment.COOLIFY_TESTING_KEY_UID == "9999")
        and ($host.environment.COOLIFY_TESTING_KEY_GID == "9999")
        and ($host.restart == "always")
        and ($host.entrypoint | join(" ") | contains("coolify-testing-host-keygen"))
        and ($host.volumes | any(.source == "coolify-testing-host-private" and .target == "/run/coolify-testing-host/private" and ((.read_only // false) == false)))
        and ($host.volumes | any(.source == "coolify-testing-host-public" and .target == "/run/coolify-testing-host/public" and ((.read_only // false) == false)))
        and ($coolify.volumes | any(.source == "coolify-testing-host-private" and .target == "/run/coolify-testing-host/private" and .read_only == true))
        and ($coolify.environment | any(. == "COOLIFY_TESTING_HOST_PRIVATE_KEY_PATH=/run/coolify-testing-host/private/testing-host"))
        and ($coolify.depends_on["coolify-testing-host"].condition == "service_healthy")
    ' >/dev/null || fail 'Windows Compose does not share one generated testing-host identity'
}

assert_production_seed_bridge()
{
    # shellcheck disable=SC2016 # PHP is passed literally through the container environment.
    bridge_assertion='require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$record = App\Models\PrivateKey::query()->findOrFail(0);
$server = App\Models\Server::query()->findOrFail(0);
$runtime = file_get_contents(getenv("COOLIFY_TESTING_HOST_PRIVATE_KEY_PATH"));
$authorized = file_get_contents("/run/coolify-testing-host/public/authorized_keys");
$stored = $record->private_key;
$derived = App\Models\PrivateKey::extractPublicKeyFromPrivate($stored);
$identity = static function (string $key): string {
    $parts = preg_split("/\\s+/", trim($key));
    return implode(" ", array_slice($parts, 0, 2));
};
if (! is_string($runtime) || ! hash_equals($runtime, $stored)) {
    fwrite(STDERR, "stored private key differs from runtime volume\n");
    exit(72);
}
if (! is_string($authorized) || ! is_string($derived) || ! hash_equals($identity($authorized), $identity($derived))) {
    fwrite(STDERR, "derived public key differs from testing-host authorized key\n");
    exit(73);
}
if ($server->uuid !== "coolify-testing-host" || $server->ip !== "coolify-testing-host" || (int) $server->private_key_id !== 0 || (int) $server->team_id !== 0) {
    fwrite(STDERR, "Windows testing-host server identity is incorrect\n");
    exit(74);
}
printf("TESTING_HOST_PRODUCTION_SEED_BRIDGE_PASS private_sha256=%s public_sha256=%s\n", hash("sha256", $stored), hash("sha256", $identity($derived)));'

    docker run --rm --pull never --user 9999:9999 \
        --network "$network" \
        --workdir /var/www/html \
        --mount "type=volume,source=${private_volume},target=/run/coolify-testing-host/private,readonly" \
        --mount "type=volume,source=${public_volume},target=/run/coolify-testing-host/public,readonly" \
        --env APP_ENV=staging \
        --env APP_KEY=base64:8VEfVNVkXQ9mH2L33WBWNMF4eQ0BWD5CTzB8mIxcl+k= \
        --env APP_URL=http://localhost \
        --env BROADCAST_CONNECTION=log \
        --env CACHE_STORE=array \
        --env COOLIFY_TESTING_HOST_PRIVATE_KEY_PATH=/run/coolify-testing-host/private/testing-host \
        --env DB_CONNECTION=pgsql \
        --env DB_HOST=testing-postgres \
        --env DB_PORT=5432 \
        --env DB_DATABASE=coolify \
        --env DB_USERNAME=coolify \
        --env DB_PASSWORD=coolify-testing-host \
        --env IS_WINDOWS_DOCKER_DESKTOP=true \
        --env LOG_CHANNEL=stderr \
        --env QUEUE_CONNECTION=redis \
        --env REDIS_HOST=testing-redis \
        --env REDIS_PORT=6379 \
        --env SELF_HOSTED=true \
        --env SESSION_DRIVER=array \
        --env SSH_MUX_ENABLED=false \
        --env BRIDGE_ASSERTION="$bridge_assertion" \
        --entrypoint /bin/sh \
        "$production_image" -ec '
            php -r '\''exit(extension_loaded("pdo_sqlite") ? 0 : 71);'\''
            test "$(id -u):$(id -g)" = 9999:9999
            test "$(stat -c %u:%g:%a /run/coolify-testing-host/private/testing-host)" = 9999:9999:600
            php artisan migrate:fresh --force --no-interaction
            php artisan db:seed --force --no-interaction
            php -r "$BRIDGE_ASSERTION"
        '
}

trap cleanup EXIT INT TERM

test -f "$dockerfile" || fail "testing-host Dockerfile is absent: ${dockerfile}"
test -s "$development_authorized_keys" || fail 'development testing-host public key is absent'
ssh-keygen -lf "$development_authorized_keys" >/dev/null 2>&1 || fail 'development testing-host public key is invalid'
assert_compose_authorized_keys_mount testing-host \
    -f "$repository_root/docker-compose.yml" \
    -f "$repository_root/docker-compose.dev.yml"
assert_compose_authorized_keys_mount testing-host \
    -f "$repository_root/docker-compose.yml" \
    -f "$repository_root/docker-compose-maxio.dev.yml"
assert_windows_runtime_key_contract "$repository_root/docker-compose.windows.yml"
assert_windows_runtime_key_contract "$repository_root/other/nightly/docker-compose.windows.yml"

docker_version="$(argument_value DOCKER_VERSION)"
compose_version="$(argument_value DOCKER_COMPOSE_VERSION)"
buildx_version="$(argument_value DOCKER_BUILDX_VERSION)"
docker image inspect "$image" >/dev/null
docker image inspect "$production_image" >/dev/null

docker run --rm --pull never --entrypoint /usr/local/bin/docker "$image" --version |
    grep -Eq "^Docker version ${docker_version}, build "
docker run --rm --pull never --entrypoint /usr/local/bin/docker "$image" compose version --short |
    grep -Fx "$compose_version"
docker run --rm --pull never --entrypoint /usr/local/bin/docker "$image" buildx version |
    grep -Eq "^github.com/docker/buildx v${buildx_version}( |$)"

docker volume create "$private_volume" >/dev/null
docker volume create "$public_volume" >/dev/null
docker network create "$network" >/dev/null

docker run --detach --pull never --name "$database_container" \
    --network "$network" \
    --network-alias testing-postgres \
    --env POSTGRES_DB=coolify \
    --env POSTGRES_USER=coolify \
    --env POSTGRES_PASSWORD=coolify-testing-host \
    "$database_image" >/dev/null

database_attempt=0
until docker exec "$database_container" pg_isready --dbname coolify --username coolify >/dev/null 2>&1; do
    database_attempt=$((database_attempt + 1))
    if [ "$database_attempt" -ge 30 ]; then
        docker logs "$database_container" >&2 || true
        fail 'the disposable PostgreSQL bridge database did not become ready'
    fi
    sleep 1
done

docker run --detach --pull never --name "$redis_container" \
    --network "$network" \
    --network-alias testing-redis \
    "$redis_image" >/dev/null

redis_attempt=0
until docker exec "$redis_container" redis-cli ping 2>/dev/null | grep -qx PONG; do
    redis_attempt=$((redis_attempt + 1))
    if [ "$redis_attempt" -ge 30 ]; then
        docker logs "$redis_container" >&2 || true
        fail 'the disposable Redis bridge queue did not become ready'
    fi
    sleep 1
done

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

assert_production_seed_bridge

docker run --detach --pull never --name "$server_container" \
    --network "$network" \
    --network-alias testing-host \
    --mount "type=volume,source=${public_volume},target=/run/coolify-testing-host/public,readonly" \
    "$image" >/dev/null

assert_ssh_access testing-host "$server_container" \
    "type=volume,source=${private_volume},target=/run/coolify-testing-host/private,readonly"

docker restart "$server_container" >/dev/null
assert_ssh_access testing-host "$server_container" \
    "type=volume,source=${private_volume},target=/run/coolify-testing-host/private,readonly"

docker inspect --format '{{.State.Running}}' "$server_container" | grep -qx true
docker exec "$server_container" /usr/sbin/sshd -T | grep -qx 'port 22'

printf 'TESTING_HOST_RUNTIME_CONTRACT_PASS image=%s docker=%s compose=%s buildx=%s\n' \
    "$image" "$docker_version" "$compose_version" "$buildx_version"
