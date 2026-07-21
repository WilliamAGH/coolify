#!/bin/sh

set -eu

fail()
{
    printf 'PRODUCTION_APPLICATION_BLUE_GREEN_REMOTE_FAIL %s\n' "$1" >&2
    exit 1
}

remote_timeout_seconds="${PRODUCTION_APPLICATION_BLUE_GREEN_REMOTE_TIMEOUT_SECONDS:?remote timeout is required}"
case "$remote_timeout_seconds" in
    ''|*[!0-9]*) fail 'remote timeout must be a positive integer' ;;
esac
test "$remote_timeout_seconds" -gt 0 || fail 'remote timeout must be positive'

assert_image()
{
    image=$1
    expected_id=$2
    actual_id=$(docker image inspect "$image" --format '{{.Id}}')
    test "$actual_id" = "$expected_id" || fail "unexpected image identity for $image"
    printf '%s %s\n' "$image" "$actual_id"
}

test -z "${DOCKER_HOST+x}" || fail 'testing-host SSH inherited DOCKER_HOST'
test -S /var/run/docker.sock || fail 'testing-host Docker socket is absent'
daemon_id=$(docker info --format '{{.ID}}')
test -n "$daemon_id" || fail 'nested Docker daemon identity is empty'
printf '%s\n' "$daemon_id" >/data/coolify/production-application-blue-green-daemon-id

docker load --input /runtime-evidence/images.tar >/dev/null
assert_image "${FIXTURE_IMAGE:?}" "${FIXTURE_IMAGE_ID:?}"
assert_image "${TRAEFIK_RUNTIME_IMAGE:?}" "${TRAEFIK_IMAGE_ID:?}"
assert_image "${HELPER_RUNTIME_IMAGE:?}" "${HELPER_IMAGE_ID:?}"
assert_image "${REGISTRY_RUNTIME_IMAGE:?}" "${REGISTRY_IMAGE_ID:?}"
docker tag "$HELPER_RUNTIME_IMAGE" ghcr.io/coollabsio/coolify-helper:1.0.14

docker network inspect coolify >/dev/null 2>&1 || docker network create coolify >/dev/null
install -d -m 0755 /data/coolify/proxy/dynamic
install -m 0644 /lab/traefik.yml /data/coolify/proxy/traefik.yml

docker run --detach --pull never --name fixture-registry --network coolify --publish 5000:5000 "$REGISTRY_RUNTIME_IMAGE" >/dev/null
attempt=0
until curl --connect-timeout 2 --max-time 3 --fail --silent http://127.0.0.1:5000/v2/ >/dev/null; do
    attempt=$((attempt + 1))
    test "$attempt" -lt 30 || fail 'fixture registry did not become ready'
    sleep 1
done
docker tag "$FIXTURE_IMAGE" 127.0.0.1:5000/production-application-fixture:manifest
docker push 127.0.0.1:5000/production-application-fixture:manifest >/dev/null

docker run --detach --pull never --name coolify-proxy --network coolify --publish 80:80 \
    --volume /var/run/docker.sock:/var/run/docker.sock:ro \
    --volume /data/coolify/proxy/dynamic:/dynamic:ro \
    --volume /data/coolify/proxy/traefik.yml:/etc/traefik/traefik.yml:ro \
    "$TRAEFIK_RUNTIME_IMAGE" --configFile=/etc/traefik/traefik.yml >/dev/null

for container in fixture-registry coolify-proxy; do
    container_id=$(docker inspect "$container" --format '{{.Id}}')
    printf '%s\n' "$container_id" | grep -Eq '^[a-f0-9]{64}$' || fail "$container did not expose a full Docker ID"
    test "$(docker ps --all --quiet --no-trunc --filter name=^/${container}$)" = "$container_id" \
        || fail "$container full-ID inventory drifted"
done

attempt=0
until curl --connect-timeout 2 --max-time 3 --fail --silent http://127.0.0.1/ping >/dev/null; do
    attempt=$((attempt + 1))
    test "$attempt" -lt 30 || fail 'Traefik did not become ready'
    sleep 1
done

printf 'daemon=%s socket=%s\n' "$daemon_id" "$(stat -c '%F:%u:%g:%a' /var/run/docker.sock)"
