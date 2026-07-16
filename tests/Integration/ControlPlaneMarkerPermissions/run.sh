#!/bin/sh

set -eu

repository_root="$(CDPATH='' cd -- "$(dirname -- "$0")/../../.." && pwd)"
image="${CONTROL_PLANE_MARKER_PERMISSIONS_IMAGE:-coolify:control-plane-marker-permissions}"
container="control-plane-marker-permissions-$$"
volume="control-plane-marker-permissions-$$"
state_directory=/var/lib/coolify-control-plane
writer_epoch=green-writer-marker-permissions-0123456789
web_epoch=green-web-marker-permissions-0123456789
route_drain_epoch=green-route-drain-marker-permissions-0123456789
freeze_epoch=green-freeze-marker-permissions-0123456789
private_writer_marker="$state_directory/private-writer/writer-epoch"
private_web_marker="$state_directory/private-web/web-epoch"
private_route_drain_marker="$state_directory/private-route-drain/route-drain-epoch"
coordination_freeze_marker="$state_directory/coordination/mutation-freeze-epoch"

fail()
{
    printf 'CONTROL_PLANE_MARKER_PERMISSIONS_FAIL %s\n' "$1" >&2
    exit 1
}

cleanup()
{
    docker rm --force "$container" >/dev/null 2>&1 || true
    docker volume rm --force "$volume" >/dev/null 2>&1 || true
}

wait_for_s6()
{
    attempt=0
    until docker logs "$container" 2>&1 | grep -qF 'NGINX + PHP-FPM is running correctly.'; do
        attempt=$((attempt + 1))
        if [ "$(docker inspect --format '{{.State.Running}}' "$container")" != true ] \
            || [ "$attempt" -ge 30 ]; then
            docker logs "$container" >&2
            fail 'control-plane image did not reach supervised-up state'
        fi
        sleep 1
    done
}

root_shell()
{
    docker exec --user 0 "$container" /bin/sh -ec "$1"
}

split_marker_entrypoint()
{
    docker exec \
        --env HORIZON_ENABLED=true \
        --env CONTROL_PLANE_WRITER_MARKER_PATH="$private_writer_marker" \
        --env CONTROL_PLANE_WEB_MARKER_PATH="$private_web_marker" \
        --env CONTROL_PLANE_ROUTE_DRAIN_MARKER_PATH="$private_route_drain_marker" \
        --env CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH="$coordination_freeze_marker" \
        "$container" /usr/local/bin/coolify-entrypoint "$@"
}

restore_markers()
{
    # Marker epochs expand inside the container.
    # shellcheck disable=SC2016
    root_shell '
        rm -rf \
            /var/lib/coolify-control-plane/writer-epoch \
            /var/lib/coolify-control-plane/web-epoch \
            /var/lib/coolify-control-plane/route-drain-epoch \
            /var/lib/coolify-control-plane/mutation-freeze-epoch
        umask 027
        printf %s "$CONTROL_PLANE_WRITER_EPOCH" \
            > /var/lib/coolify-control-plane/writer-epoch
        printf %s "$CONTROL_PLANE_WEB_EPOCH" \
            > /var/lib/coolify-control-plane/web-epoch
        printf %s "$CONTROL_PLANE_ROUTE_DRAIN_EPOCH" \
            > /var/lib/coolify-control-plane/route-drain-epoch
        printf %s "$CONTROL_PLANE_MUTATION_FREEZE_EPOCH" \
            > /var/lib/coolify-control-plane/mutation-freeze-epoch
        chown root:www-data \
            /var/lib/coolify-control-plane/writer-epoch \
            /var/lib/coolify-control-plane/web-epoch \
            /var/lib/coolify-control-plane/route-drain-epoch \
            /var/lib/coolify-control-plane/mutation-freeze-epoch
        chmod 0440 \
            /var/lib/coolify-control-plane/writer-epoch \
            /var/lib/coolify-control-plane/web-epoch \
            /var/lib/coolify-control-plane/route-drain-epoch \
            /var/lib/coolify-control-plane/mutation-freeze-epoch
        sync
    '
}

restore_lease()
{
    root_shell '
        rm -rf /var/lib/coolify-control-plane/mutation-inflight.lock
        install -m 0660 -o root -g www-data /dev/null \
            /var/lib/coolify-control-plane/mutation-inflight.lock
        sync
    '
}

assert_entrypoint_rejects_invalid_metadata()
{
    description=$1
    if docker exec "$container" /usr/local/bin/coolify-entrypoint startup-mode \
        >/dev/null 2>&1; then
        fail "entrypoint accepted $description"
    fi
}

assert_php_rejects_invalid_metadata()
{
    description=$1
    if docker exec "$container" php -r '
        function env(string $name, mixed $default = null): mixed {
            $environmentValue = getenv($name);
            return $environmentValue === false ? $default : $environmentValue;
        }
        function config(string $name, mixed $default = null): mixed {
            return $default;
        }
        require "/var/www/html/app/Support/ControlPlaneMode.php";
        App\Support\ControlPlaneMode::webOwnershipProven();
        App\Support\ControlPlaneMode::mutationFreezeActive();
        App\Support\ControlPlaneMode::mutationLeasePath();
    ' >/dev/null 2>&1; then
        fail "ControlPlaneMode accepted $description"
    fi
}

trap cleanup EXIT INT TERM

command -v docker >/dev/null 2>&1 || fail 'docker is required'
docker info >/dev/null 2>&1 || fail 'a running Docker daemon is required'

if [ "${CONTROL_PLANE_MARKER_PERMISSIONS_SKIP_BUILD:-false}" != true ]; then
    docker buildx build \
        --load \
        --file "$repository_root/docker/production/Dockerfile" \
        --tag "$image" \
        "$repository_root"
fi

docker image inspect "$image" >/dev/null \
    || fail "control-plane image is not locally available: $image"
built_image_id="$(docker image inspect --format '{{.Id}}' "$image")"
case "$built_image_id" in
    sha256:*)
        ;;
    *)
        fail 'built control-plane image did not resolve to a content-addressed image ID'
        ;;
esac
docker volume create "$volume" >/dev/null

docker run --detach \
    --name "$container" \
    --network none \
    --mount "type=volume,source=${volume},target=${state_directory}" \
    --env CONTROL_PLANE_MODE=active \
    --env CONTROL_PLANE_STARTUP_MODE=web-only \
    --env "CONTROL_PLANE_WRITER_EPOCH=$writer_epoch" \
    --env CONTROL_PLANE_WRITER_MARKER_PATH="$state_directory/writer-epoch" \
    --env "CONTROL_PLANE_WEB_EPOCH=$web_epoch" \
    --env CONTROL_PLANE_WEB_MARKER_PATH="$state_directory/web-epoch" \
    --env "CONTROL_PLANE_ROUTE_DRAIN_EPOCH=$route_drain_epoch" \
    --env CONTROL_PLANE_ROUTE_DRAIN_MARKER_PATH="$state_directory/route-drain-epoch" \
    --env "CONTROL_PLANE_MUTATION_FREEZE_EPOCH=$freeze_epoch" \
    --env CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH="$state_directory/mutation-freeze-epoch" \
    --env APP_ENV=production \
    --env APP_DEBUG=false \
    --env APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    --env AUTORUN_ENABLED=false \
    --env INIT_ENABLED=false \
    --env MIGRATION_ENABLED=false \
    --env SEEDER_ENABLED=false \
    --env HORIZON_ENABLED=false \
    --env SCHEDULER_ENABLED=false \
    --env NIGHTWATCH_ENABLED=false \
    "$image" >/dev/null

[ "$(docker inspect --format '{{.Image}}' "$container")" = "$built_image_id" ] \
    || fail 'marker acceptance container did not run the exact built production image ID'

wait_for_s6

# Identity lookups run inside the container.
# shellcheck disable=SC2016
root_shell '
    test "$(id -u www-data)" = 9999
    test "$(id -g www-data)" = 9999
    test ! -L /var/lib/coolify-control-plane
    test -d /var/lib/coolify-control-plane
    test "$(stat -c %u:%g:%a /var/lib/coolify-control-plane)" = 0:9999:750
    test ! -L /var/lib/coolify-control-plane/mutation-inflight.lock
    test -f /var/lib/coolify-control-plane/mutation-inflight.lock
    test "$(stat -c %u:%g:%a /var/lib/coolify-control-plane/mutation-inflight.lock)" = 0:9999:660
    test ! -e /var/lib/coolify-control-plane/writer-epoch
    test ! -e /var/lib/coolify-control-plane/web-epoch
    test ! -e /var/lib/coolify-control-plane/route-drain-epoch
    test ! -e /var/lib/coolify-control-plane/mutation-freeze-epoch
' || fail 'fresh named volume did not inherit the image-owned state contract'

# Marker directories are member-private. Only the separate freeze-coordination
# directory carries the shared mutation lease.
# shellcheck disable=SC2016
root_shell '
    install -d -m 0750 -o root -g www-data \
        /var/lib/coolify-control-plane/private-writer \
        /var/lib/coolify-control-plane/private-web \
        /var/lib/coolify-control-plane/private-route-drain \
        /var/lib/coolify-control-plane/coordination
    printf %s "$CONTROL_PLANE_WRITER_EPOCH" \
        > /var/lib/coolify-control-plane/private-writer/writer-epoch
    printf %s "$CONTROL_PLANE_WEB_EPOCH" \
        > /var/lib/coolify-control-plane/private-web/web-epoch
    printf %s "$CONTROL_PLANE_ROUTE_DRAIN_EPOCH" \
        > /var/lib/coolify-control-plane/private-route-drain/route-drain-epoch
    printf %s "$CONTROL_PLANE_MUTATION_FREEZE_EPOCH" \
        > /var/lib/coolify-control-plane/coordination/mutation-freeze-epoch
    chown root:www-data \
        /var/lib/coolify-control-plane/private-writer/writer-epoch \
        /var/lib/coolify-control-plane/private-web/web-epoch \
        /var/lib/coolify-control-plane/private-route-drain/route-drain-epoch \
        /var/lib/coolify-control-plane/coordination/mutation-freeze-epoch
    chmod 0440 \
        /var/lib/coolify-control-plane/private-writer/writer-epoch \
        /var/lib/coolify-control-plane/private-web/web-epoch \
        /var/lib/coolify-control-plane/private-route-drain/route-drain-epoch \
        /var/lib/coolify-control-plane/coordination/mutation-freeze-epoch
    test ! -e /var/lib/coolify-control-plane/private-writer/mutation-inflight.lock
    test ! -e /var/lib/coolify-control-plane/private-web/mutation-inflight.lock
    test ! -e /var/lib/coolify-control-plane/private-route-drain/mutation-inflight.lock
    test ! -e /var/lib/coolify-control-plane/coordination/mutation-inflight.lock
' || fail 'could not prepare split member marker directories'

if split_marker_entrypoint startup-mode >/dev/null 2>&1; then
    fail 'entrypoint accepted a coordination freeze marker without its mutation lease'
fi

root_shell '
    install -m 0660 -o root -g www-data /dev/null \
        /var/lib/coolify-control-plane/coordination/mutation-inflight.lock
'

split_marker_entrypoint startup-mode | grep -qx web-only \
    || fail 'entrypoint rejected split private marker directories with a coordinated mutation lease'
split_marker_entrypoint web-activated \
    || fail 'entrypoint rejected a private web ownership marker without a local mutation lease'
split_marker_entrypoint service-enabled HORIZON_ENABLED true \
    || fail 'entrypoint rejected a private writer ownership marker without a local mutation lease'

if docker exec "$container" /usr/local/bin/coolify-entrypoint web-activated; then
    fail 'web ownership was accepted before root installed the marker'
fi
if docker exec "$container" /usr/local/bin/coolify-entrypoint \
    service-enabled HORIZON_ENABLED true; then
    fail 'writer ownership was accepted before root installed the marker'
fi

restore_markers

root_shell 'printf %s stale-web-marker-0123456789 > /var/lib/coolify-control-plane/web-epoch'
if docker exec --env CONTROL_PLANE_WEB_ACTIVATION_CONFIRM=route-switch-pending \
    "$container" /usr/local/bin/coolify-entrypoint activate-web >/dev/null 2>&1; then
    fail 'web activation accepted a mismatched operator-owned marker'
fi
restore_markers

root_shell 'printf %s stale-writer-marker-0123456789 > /var/lib/coolify-control-plane/writer-epoch'
if docker exec --env CONTROL_PLANE_PROMOTION_CONFIRM=blue-stopped \
    "$container" /usr/local/bin/coolify-entrypoint promote-writer >/dev/null 2>&1; then
    fail 'writer promotion accepted a mismatched operator-owned marker'
fi
restore_markers

marker_identity_before="$(root_shell 'stat -c %d:%i /var/lib/coolify-control-plane/web-epoch')"
lease_identity_before="$(root_shell 'stat -c %d:%i /var/lib/coolify-control-plane/mutation-inflight.lock')"

docker exec "$container" /bin/sh -ec '
    test "$(id -u)" = 9999
    test "$(id -g)" = 9999

    denied()
    {
        if "$@" >/dev/null 2>&1; then
            printf "unexpectedly allowed: %s\n" "$*" >&2
            exit 1
        fi
    }

    for marker in \
        /var/lib/coolify-control-plane/writer-epoch \
        /var/lib/coolify-control-plane/web-epoch \
        /var/lib/coolify-control-plane/route-drain-epoch \
        /var/lib/coolify-control-plane/mutation-freeze-epoch
    do
        denied rm -f "$marker"
        denied mv "$marker" "${marker}.replacement"
        denied ln "$marker" "${marker}.hardlink"
        denied ln -sfn /etc/passwd "$marker"
        denied chmod 0660 "$marker"
        if { printf tampered > "$marker"; } 2>/dev/null; then
            printf "unexpectedly wrote marker: %s\n" "$marker" >&2
            exit 1
        fi
        test "$(stat -c %u:%g:%a "$marker")" = 0:9999:440
    done

    lease=/var/lib/coolify-control-plane/mutation-inflight.lock
    denied rm -f "$lease"
    denied mv "$lease" "${lease}.replacement"
    denied ln "$lease" "${lease}.hardlink"
    denied ln -sfn /etc/passwd "$lease"
    denied chmod 0666 "$lease"
    php -r '\''
        $path = "/var/lib/coolify-control-plane/mutation-inflight.lock";
        $lease = fopen($path, "c+");
        if ($lease === false || ! flock($lease, LOCK_SH | LOCK_NB)) {
            exit(1);
        }
        $metadata = fstat($lease);
        flock($lease, LOCK_UN);
        fclose($lease);
        if (! is_array($metadata) || ($metadata["mode"] & 07777) !== 0660) {
            exit(1);
        }
    '\''
' || fail 'UID 9999 changed a root-owned marker or could not take the shared lease lock'

[ "$(root_shell 'stat -c %d:%i /var/lib/coolify-control-plane/web-epoch')" = "$marker_identity_before" ] \
    || fail 'UID 9999 replaced the web marker inode'
[ "$(root_shell 'stat -c %d:%i /var/lib/coolify-control-plane/mutation-inflight.lock')" = "$lease_identity_before" ] \
    || fail 'UID 9999 replaced the mutation lease inode'

docker exec --env CONTROL_PLANE_WEB_ACTIVATION_CONFIRM=route-switch-pending \
    "$container" /usr/local/bin/coolify-entrypoint activate-web \
    | grep -qF 'Control plane web activation completed.'
docker exec "$container" /usr/local/bin/coolify-entrypoint web-activated
docker exec --env CONTROL_PLANE_PROMOTION_CONFIRM=blue-stopped \
    "$container" /usr/local/bin/coolify-entrypoint promote-writer \
    | grep -qF 'Control plane writer promotion completed.'

[ "$(root_shell 'stat -c %d:%i /var/lib/coolify-control-plane/web-epoch')" = "$marker_identity_before" ] \
    || fail 'web activation replaced the operator marker'
[ "$(root_shell 'stat -c %d:%i /var/lib/coolify-control-plane/mutation-inflight.lock')" = "$lease_identity_before" ] \
    || fail 'writer promotion replaced the mutation lease'

docker exec "$container" php -r '
    function env(string $name, mixed $default = null): mixed {
        $environmentValue = getenv($name);
        return $environmentValue === false ? $default : $environmentValue;
    }
    function config(string $name, mixed $default = null): mixed {
        return $default;
    }
    require "/var/www/html/app/Support/ControlPlaneMode.php";
    if (! App\Support\ControlPlaneMode::writerOwnershipProven()
        || ! App\Support\ControlPlaneMode::webOwnershipProven()
        || ! App\Support\ControlPlaneMode::routeDrainActive()
        || ! App\Support\ControlPlaneMode::mutationFreezeActive()
        || App\Support\ControlPlaneMode::mutationLeasePath()
            !== "/var/lib/coolify-control-plane/mutation-inflight.lock") {
        exit(1);
    }
' || fail 'ControlPlaneMode rejected valid operator-owned marker metadata'

root_shell 'chmod 0640 /var/lib/coolify-control-plane/web-epoch'
assert_entrypoint_rejects_invalid_metadata 'a mode-0640 marker'
assert_php_rejects_invalid_metadata 'a mode-0640 marker'
restore_markers

root_shell 'chmod 0640 /var/lib/coolify-control-plane/route-drain-epoch'
assert_entrypoint_rejects_invalid_metadata 'a mode-0640 route-drain marker'
if docker exec "$container" php -r '
    function env(string $name, mixed $default = null): mixed {
        $environmentValue = getenv($name);
        return $environmentValue === false ? $default : $environmentValue;
    }
    function config(string $name, mixed $default = null): mixed {
        return $default;
    }
    require "/var/www/html/app/Support/ControlPlaneMode.php";
    App\Support\ControlPlaneMode::routeDrainActive();
' >/dev/null 2>&1; then
    fail 'ControlPlaneMode accepted a mode-0640 route-drain marker'
fi
restore_markers

root_shell 'chown www-data:www-data /var/lib/coolify-control-plane/web-epoch'
assert_entrypoint_rejects_invalid_metadata 'an app-owned marker'
assert_php_rejects_invalid_metadata 'an app-owned marker'
restore_markers

root_shell 'chown root:root /var/lib/coolify-control-plane/web-epoch'
assert_entrypoint_rejects_invalid_metadata 'a root-group marker'
assert_php_rejects_invalid_metadata 'a root-group marker'
restore_markers

root_shell '
    rm -f /var/lib/coolify-control-plane/web-epoch
    mkdir /var/lib/coolify-control-plane/web-epoch
'
assert_entrypoint_rejects_invalid_metadata 'a directory marker'
assert_php_rejects_invalid_metadata 'a directory marker'
restore_markers

root_shell '
    rm -f /var/lib/coolify-control-plane/web-epoch
    ln -s /etc/passwd /var/lib/coolify-control-plane/web-epoch
'
assert_entrypoint_rejects_invalid_metadata 'a symlink marker'
assert_php_rejects_invalid_metadata 'a symlink marker'
restore_markers

root_shell 'chmod 0666 /var/lib/coolify-control-plane/mutation-inflight.lock'
assert_entrypoint_rejects_invalid_metadata 'a mode-0666 mutation lease'
assert_php_rejects_invalid_metadata 'a mode-0666 mutation lease'
restore_lease

root_shell 'chown www-data:www-data /var/lib/coolify-control-plane/mutation-inflight.lock'
assert_entrypoint_rejects_invalid_metadata 'an app-owned mutation lease'
assert_php_rejects_invalid_metadata 'an app-owned mutation lease'
restore_lease

root_shell 'chown root:root /var/lib/coolify-control-plane/mutation-inflight.lock'
assert_entrypoint_rejects_invalid_metadata 'a root-group mutation lease'
assert_php_rejects_invalid_metadata 'a root-group mutation lease'
restore_lease

root_shell '
    rm -f /var/lib/coolify-control-plane/mutation-inflight.lock
    mkdir /var/lib/coolify-control-plane/mutation-inflight.lock
'
assert_entrypoint_rejects_invalid_metadata 'a directory mutation lease'
assert_php_rejects_invalid_metadata 'a directory mutation lease'
restore_lease

root_shell '
    rm -f /var/lib/coolify-control-plane/mutation-inflight.lock
    ln -s /etc/passwd /var/lib/coolify-control-plane/mutation-inflight.lock
'
assert_entrypoint_rejects_invalid_metadata 'a symlink mutation lease'
assert_php_rejects_invalid_metadata 'a symlink mutation lease'
restore_lease

root_shell 'chmod 0770 /var/lib/coolify-control-plane'
assert_entrypoint_rejects_invalid_metadata 'a group-writable state directory'
assert_php_rejects_invalid_metadata 'a group-writable state directory'
root_shell 'chmod 0750 /var/lib/coolify-control-plane'

root_shell 'chown www-data:www-data /var/lib/coolify-control-plane'
assert_entrypoint_rejects_invalid_metadata 'an app-owned state directory'
assert_php_rejects_invalid_metadata 'an app-owned state directory'
root_shell 'chown root:www-data /var/lib/coolify-control-plane'

root_shell '
    rm -f \
        /var/lib/coolify-control-plane/writer-epoch \
        /var/lib/coolify-control-plane/web-epoch \
        /var/lib/coolify-control-plane/route-drain-epoch \
        /var/lib/coolify-control-plane/mutation-freeze-epoch
    sync
'

if docker exec "$container" /usr/local/bin/coolify-entrypoint web-activated; then
    fail 'root marker revocation left web ownership active'
fi
if docker exec "$container" /usr/local/bin/coolify-entrypoint \
    service-enabled HORIZON_ENABLED true; then
    fail 'root marker revocation left writer ownership active'
fi

printf 'CONTROL_PLANE_MARKER_PERMISSIONS_PASS image=%s image_id=%s\n' "$image" "$built_image_id"
