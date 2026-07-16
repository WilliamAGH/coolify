#!/bin/sh

set -eu

repository_root="$(CDPATH='' cd -- "$(dirname -- "$0")/../../.." && pwd)"
operator="$repository_root/docker/control-plane-blue-green/control-plane-blue-green.sh"
image="${CONTROL_PLANE_OPERATOR_MARKER_IMAGE:-coolify:control-plane-marker-permissions}"
volume="control-plane-operator-markers-$$"
container="control-plane-operator-markers-$$"
holder="control-plane-operator-marker-holder-$$"
work_directory="$(mktemp -d "${TMPDIR:-/tmp}/control-plane-operator-markers.XXXXXX")"
operator_harness="$work_directory/operator-marker-functions.sh"
state_directory=/var/lib/coolify-control-plane
writer_epoch='operator-writer-epoch-0123456789'
replacement_writer_epoch='operator-writer-epoch-9876543210'
web_epoch='operator-web-epoch-0123456789'

fail()
{
    printf 'CONTROL_PLANE_OPERATOR_MARKERS_FAIL %s\n' "$1" >&2
    exit 1
}

cleanup()
{
    docker rm --force "$holder" "$container" >/dev/null 2>&1 || true
    docker volume rm --force "$volume" >/dev/null 2>&1 || true
    rm -rf "$work_directory"
}

volume_shell()
{
    docker run --rm --user 0 --network none \
        --mount "type=volume,source=${volume},target=/state" \
        --entrypoint /bin/sh "$image" -ec "$1"
}

wait_for_s6()
{
    attempt=0
    until docker logs "$container" 2>&1 | grep -qF 'NGINX + PHP-FPM is running correctly.'; do
        attempt=$((attempt + 1))
        if [ "$(docker inspect --format '{{.State.Running}}' "$container")" != true ] \
            || [ "$attempt" -ge 30 ]; then
            docker logs "$container" >&2
            fail 'production image did not reach supervised-up state'
        fi
        sleep 1
    done
}

run_operator_harness()
{
    CONTROL_PLANE_OPERATOR_MARKER_IMAGE="$image" \
        CONTROL_PLANE_OPERATOR_MARKER_WORK_DIRECTORY="$work_directory" \
        CONTROL_PLANE_TEST_CRASH_AT="${CONTROL_PLANE_TEST_CRASH_AT:-}" \
        /bin/sh -ec '. "$1"; shift; "$@"' sh "$operator_harness" "$@"
}

run_operator_harness_with_crash()
{
    operator_crash_point=$1
    shift
    CONTROL_PLANE_OPERATOR_MARKER_IMAGE="$image" \
        CONTROL_PLANE_OPERATOR_MARKER_WORK_DIRECTORY="$work_directory" \
        CONTROL_PLANE_TEST_CRASH_AT="$operator_crash_point" \
        /bin/sh -ec '. "$1"; shift; "$@"' sh "$operator_harness" "$@"
}

trap cleanup EXIT INT TERM

command -v docker >/dev/null 2>&1 || fail 'docker is required'
docker info >/dev/null 2>&1 || fail 'a running Docker daemon is required'
docker image inspect "$image" >/dev/null \
    || fail "run ControlPlaneMarkerPermissions first; image is unavailable: $image"
built_image_id="$(docker image inspect --format '{{.Id}}' "$image")"

{
    printf '%s\n' '#!/bin/sh' 'set -eu'
    printf '%s\n' \
        "green_image=\${CONTROL_PLANE_OPERATOR_MARKER_IMAGE:?}" \
        'test_mode=1' \
        "operation_directory=\${CONTROL_PLANE_OPERATOR_MARKER_WORK_DIRECTORY:-/tmp}" \
        "fail() { printf 'operator marker harness failure: %s\\n' \"\$1\" >&2; exit 1; }" \
        "is_test_mode() { [ \"\$test_mode\" = 1 ]; }" \
        'test_crash() { :; }' \
        "container_restart_policy() { docker inspect --format '{{.HostConfig.RestartPolicy.Name}}' \"\$1\"; }" \
        'wait_for_container_health() { :; }' \
        'prove_promoted_background_services() { :; }'
    awk '/^marker_status\(\)/ { copy = 1 }
        /^prove_mutation_freeze_quiesced\(\)/ { copy = 0 }
        copy { print }' "$operator"
    awk '/^ensure_volume\(\)/ { copy = 1 }
        /^assert_marker_mount\(\)/ { copy = 0 }
        copy { print }' "$operator"
    awk '/^promote_writer\(\)/ { copy = 1 }
        /^prove_promoted_background_service\(\)/ { copy = 0 }
        copy { print }' "$operator"
    awk '/^activate_color_web\(\)/ { copy = 1 }
        /^recover_cutover_to_legacy\(\)/ { copy = 0 }
        copy { print }' "$operator"
} > "$operator_harness"

grep -qF "activate_marker \"\$promotion_volume\"" "$operator_harness" \
    || fail 'actual promote_writer does not invoke the operator marker lifecycle'
grep -qF "activate_marker \"\$activation_volume\"" "$operator_harness" \
    || fail 'actual activate_color_web does not invoke the operator marker lifecycle'

docker volume create "$volume" >/dev/null
run_operator_harness ensure_volume "$volume" test
lease_identity_before="$(volume_shell 'stat -c %d:%i /state/mutation-inflight.lock')"
[ "$(run_operator_harness marker_status "$volume" "$writer_epoch")" = absent ] \
    || fail 'fresh operator volume contained a writer marker'

docker run --detach --name "$container" --network none \
    --mount "type=volume,source=${volume},target=${state_directory}" \
    --env CONTROL_PLANE_MODE=active \
    --env CONTROL_PLANE_STARTUP_MODE=web-only \
    --env "CONTROL_PLANE_WRITER_EPOCH=$writer_epoch" \
    --env CONTROL_PLANE_WRITER_MARKER_PATH="$state_directory/writer-epoch" \
    --env "CONTROL_PLANE_WEB_EPOCH=$web_epoch" \
    --env CONTROL_PLANE_WEB_MARKER_PATH="$state_directory/web-epoch" \
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

wait_for_s6

[ "$(docker inspect --format '{{.Image}}' "$container")" = "$built_image_id" ] \
    || fail 'operator marker container did not use the exact built production image ID'

run_operator_harness activate_color_web "$container" "$volume" test "$web_epoch"
[ "$(run_operator_harness marker_status "$volume" "$web_epoch" web-epoch)" = matching ] \
    || fail 'actual operator web activation did not persist the exact marker'

run_operator_harness promote_writer "$container" "$volume" test "$writer_epoch"
[ "$(run_operator_harness marker_status "$volume" "$writer_epoch")" = matching ] \
    || fail 'actual operator promotion did not persist the exact marker'
[ "$(docker inspect --format '{{.HostConfig.RestartPolicy.Name}}' "$container")" = always ] \
    || fail 'actual operator promotion did not converge the restart policy'

writer_identity_before="$(volume_shell 'stat -c %d:%i /state/writer-epoch')"
run_operator_harness activate_marker "$volume" test "$writer_epoch" writer-epoch
[ "$(volume_shell 'stat -c %d:%i /state/writer-epoch')" = "$writer_identity_before" ] \
    || fail 'idempotent activation replaced the writer marker inode'

docker run --detach --name "$holder" --network none \
    --mount "type=volume,source=${volume},target=/state,readonly" \
    --entrypoint /bin/sh "$image" -ec '
        exec 9< /state/writer-epoch
        stat -Lc %d:%i /proc/self/fd/9 > /tmp/stale-identity
        : > /tmp/stale-ready
        while [ ! -e /tmp/read-stale ]; do sleep 1; done
        cat <&9 > /tmp/stale-bytes
        sleep 300
    ' >/dev/null
attempt=0
until docker exec "$holder" test -e /tmp/stale-ready; do
    attempt=$((attempt + 1))
    [ "$attempt" -lt 20 ] || fail 'stale descriptor holder did not open the writer marker'
    sleep 1
done

if run_operator_harness_with_crash after-test-writer-epoch-unlink \
    revoke_marker "$volume" test "$writer_epoch" matching writer-epoch \
    >/dev/null 2>&1; then
    fail 'operator revoke crash injection unexpectedly completed'
fi
[ "$(run_operator_harness marker_status "$volume" "$writer_epoch")" = absent ] \
    || fail 'unlink crash did not leave the writer marker absent'
run_operator_harness revoke_marker "$volume" test "$writer_epoch" matching writer-epoch

if run_operator_harness_with_crash after-test-writer-epoch-candidate-fsync \
    activate_marker "$volume" test "$replacement_writer_epoch" writer-epoch \
    >/dev/null 2>&1; then
    fail 'operator activation crash injection unexpectedly completed'
fi
[ "$(run_operator_harness marker_status "$volume" "$replacement_writer_epoch")" = absent ] \
    || fail 'candidate-fsync crash published a partial writer marker'
volume_shell "test -n \"\$(find /state -maxdepth 1 -name '.writer-epoch.activate.*' -print -quit)\"" \
    || fail 'candidate-fsync crash did not leave a recovery temp'
run_operator_harness activate_marker "$volume" test "$replacement_writer_epoch" writer-epoch
[ "$(run_operator_harness marker_status "$volume" "$replacement_writer_epoch")" = matching ] \
    || fail 'activation retry did not recover the exact replacement marker'
volume_shell "test -z \"\$(find /state -maxdepth 1 -name '.writer-epoch.activate.*' -print -quit)\"" \
    || fail 'activation retry left a crash temp behind'

docker exec "$holder" touch /tmp/read-stale
attempt=0
until docker exec "$holder" test -s /tmp/stale-bytes; do
    attempt=$((attempt + 1))
    [ "$attempt" -lt 20 ] || fail 'stale descriptor holder did not report its retained bytes'
    sleep 1
done
[ "$(docker exec "$holder" cat /tmp/stale-bytes)" = "$writer_epoch" ] \
    || fail 'stale descriptor did not retain only the revoked marker bytes'
[ "$(docker exec "$holder" cat /tmp/stale-identity)" = "$writer_identity_before" ] \
    || fail 'stale descriptor identity changed across operator replacement'
[ "$(volume_shell 'stat -c %d:%i /state/writer-epoch')" != "$writer_identity_before" ] \
    || fail 'replacement marker reused the still-open revoked inode'

volume_shell 'ln /state/writer-epoch /state/writer-epoch.hardlink'
if run_operator_harness marker_status "$volume" "$replacement_writer_epoch" writer-epoch \
    >/dev/null 2>&1; then
    fail 'operator marker status accepted a hard-linked authorization marker'
fi
volume_shell 'rm -f /state/writer-epoch.hardlink; sync /state'
[ "$(run_operator_harness marker_status "$volume" "$replacement_writer_epoch")" = matching ] \
    || fail 'operator marker status did not recover after hardlink removal'

[ "$(volume_shell 'stat -c %d:%i /state/mutation-inflight.lock')" = "$lease_identity_before" ] \
    || fail 'operator marker lifecycle replaced the stable mutation lease inode'

printf 'CONTROL_PLANE_OPERATOR_MARKERS_PASS image=%s image_id=%s\n' "$image" "$built_image_id"
