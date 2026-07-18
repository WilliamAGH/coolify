#!/bin/sh

set -eu

[ -z "${DOCKER_HOST+x}" ]
[ -S /var/run/docker.sock ]
[ "$(stat -c '%F %u %g %a' /var/run/docker.sock)" = 'socket 0 2375 660' ]
daemon_id="$(docker info --format '{{.ID}}')"
[ -n "$daemon_id" ]
printf '%s\n' "$daemon_id" >/data/coolify/application-deployment-job-daemon-id

docker load --input /evidence/nested-images.tar >/dev/null
assert_image()
{
    actual_id="$(docker image inspect "$1" --format '{{.Id}}')"
    [ "$actual_id" = "$2" ] || {
        printf 'Unexpected image id for %s: %s\n' "$1" "$actual_id" >&2
        exit 1
    }
    printf '%s %s\n' "$1" "$actual_id"
}
assert_image application-deployment-job-fixture:manifest sha256:44d49aefac1d1f238c33199c24fc935e5e42e61d1bb7a296104fa56d05256874
assert_image ghcr.io/coollabsio/coolify-helper:1.0.14 sha256:976e161c2b9463b2e4cfac9a697856a285260e9c790653eb4ee325715581e924
assert_image registry:2.8.3 sha256:33eeff39e0aaabe61ca826fd7502396183462451be0783133e1a8fa944fc7350
: "${TRAEFIK_IMAGE_ID:?TRAEFIK_IMAGE_ID is required}"
: "${TRAEFIK_PLATFORM:?TRAEFIK_PLATFORM is required}"
: "${TRAEFIK_VERSION:?TRAEFIK_VERSION is required}"
assert_image "traefik:v$TRAEFIK_VERSION" "$TRAEFIK_IMAGE_ID"
[ "$(docker image inspect "traefik:v$TRAEFIK_VERSION" --format '{{.Os}}/{{.Architecture}}')" = "$TRAEFIK_PLATFORM" ]
: "${COOLIFY_TESTING_HOST_FIXTURE_ARCHITECTURE:?COOLIFY_TESTING_HOST_FIXTURE_ARCHITECTURE is required}"
: "${COOLIFY_TESTING_HOST_FIXTURE_ID:?COOLIFY_TESTING_HOST_FIXTURE_ID is required}"
: "${COOLIFY_TESTING_HOST_FIXTURE_IMAGE:?COOLIFY_TESTING_HOST_FIXTURE_IMAGE is required}"
assert_image "$COOLIFY_TESTING_HOST_FIXTURE_IMAGE" "$COOLIFY_TESTING_HOST_FIXTURE_ID"
[ "$(docker image inspect "$COOLIFY_TESTING_HOST_FIXTURE_IMAGE" --format '{{.Os}}')" = linux ]
[ "$(docker image inspect "$COOLIFY_TESTING_HOST_FIXTURE_IMAGE" --format '{{.Architecture}}')" = \
    "$COOLIFY_TESTING_HOST_FIXTURE_ARCHITECTURE" ]
bash -c 'set -eu -o pipefail; printf fixture-bash-ok' | grep -Fx fixture-bash-ok
docker run --rm --pull never --entrypoint bash "$COOLIFY_TESTING_HOST_FIXTURE_IMAGE" \
    -c 'set -eu -o pipefail; command -v ssh-keygen; command -v sshd; command -v docker; docker compose version' \
    >/dev/null
docker network inspect coolify >/dev/null 2>&1 || docker network create coolify >/dev/null
install -d -m 0755 /data/coolify/proxy/dynamic
install -m 0644 /lab/traefik.yml /data/coolify/proxy/traefik.yml
/bin/sh /lab/verify-traefik-tombstone.sh

docker run --detach --pull never --name fixture-registry --network coolify --publish 5000:5000 \
    registry:2.8.3 >/dev/null
attempt=0
until curl --fail --silent http://127.0.0.1:5000/v2/ >/dev/null; do
    attempt=$((attempt + 1))
    [ "$attempt" -lt 30 ] || exit 1
    sleep 1
done
docker tag application-deployment-job-fixture:manifest \
    127.0.0.1:5000/application-deployment-job-fixture:manifest
docker push 127.0.0.1:5000/application-deployment-job-fixture:manifest >/dev/null

docker run --detach --pull never --name coolify-proxy --network coolify --publish 80:80 \
    --volume /data/coolify/proxy/dynamic:/dynamic:ro \
    --volume /data/coolify/proxy/traefik.yml:/etc/traefik/traefik.yml:ro \
    "traefik:v$TRAEFIK_VERSION" \
    --configFile=/etc/traefik/traefik.yml >/dev/null

[ "$(docker inspect fixture-registry --format '{{.Image}}')" = sha256:33eeff39e0aaabe61ca826fd7502396183462451be0783133e1a8fa944fc7350 ]
[ "$(docker inspect coolify-proxy --format '{{.Image}}')" = "$TRAEFIK_IMAGE_ID" ]

attempt=0
until curl --fail --silent http://127.0.0.1:80/ping >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    [ "$attempt" -lt 10 ] || break
    sleep 1
done
printf 'daemon=%s socket=%s docker_host=unset version=%s\n' \
    "$daemon_id" \
    "$(stat -c '%F:%u:%g:%a' /var/run/docker.sock)" \
    "$(docker info --format '{{.ServerVersion}}')"
