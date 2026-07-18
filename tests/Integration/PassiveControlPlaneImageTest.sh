#!/bin/sh

set -eu

repository_root="$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)"
image="${PREFLIGHT_IMAGE:-coolify:passive-control-plane-test}"
project="coolify-preflight-test-$$"
compose_file="$repository_root/docker-compose.preflight.yml"
preflight_port="${PREFLIGHT_PORT:-$((18080 + ($$ % 1000)))}"
invalid_mode_log="/tmp/${project}-invalid-mode.log"
invalid_startup_mode_log="/tmp/${project}-invalid-startup-mode.log"
invalid_writer_epoch_log="/tmp/${project}-invalid-writer-epoch.log"
invalid_writer_path_log="/tmp/${project}-invalid-writer-path.log"
hostile_web_only_container="${project}-hostile-web-only"
promotion_web_only_container="${project}-promotion-web-only"
writer_volume="${project}-writer-state"
writer_state_mount="type=volume,source=${writer_volume},target=/var/lib/coolify-control-plane"
writer_marker_path=/var/lib/coolify-control-plane/writer-epoch
writer_epoch="deployment-${project}"
stale_writer_epoch="replacement-${project}"
unpromoted_writer_epoch="unpromoted-${project}"
base_url=http://127.0.0.1:8080

export PREFLIGHT_PORT="$preflight_port"

cleanup()
{
    PREFLIGHT_IMAGE="$image" docker compose --project-name "$project" --file "$compose_file" down --volumes --remove-orphans >/dev/null 2>&1 || true
    docker rm --force "$hostile_web_only_container" "$promotion_web_only_container" >/dev/null 2>&1 || true
    docker volume rm --force "$writer_volume" >/dev/null 2>&1 || true
    rm -f "$invalid_mode_log" "$invalid_startup_mode_log" "$invalid_writer_epoch_log" "$invalid_writer_path_log"
}

assert_environment()
{
    environment_output="$1"
    expected="$2"

    if ! printf '%s\n' "$environment_output" | grep -qxF "$expected"; then
        printf 'Expected passive image environment to contain %s\n' "$expected" >&2
        exit 1
    fi
}

wait_for_s6()
{
    container="$1"
    attempt=0

    until docker logs "$container" 2>&1 | grep -qF 'NGINX + PHP-FPM is running correctly.'; do
        attempt=$((attempt + 1))
        if [ "$(docker inspect --format '{{.State.Running}}' "$container")" != true ] || [ "$attempt" -ge 30 ]; then
            docker logs "$container" >&2
            printf 'Web-only candidate %s did not start safely.\n' "$container" >&2
            exit 1
        fi
        sleep 1
    done
}

preflight_curl()
{
    docker exec "$application_container" curl "$@"
}

assert_preflight_status()
{
    expected_status="$1"
    shift
    actual_status="$(preflight_curl --silent --output /dev/null --write-out '%{http_code}' "$@")"

    if [ "$actual_status" != "$expected_status" ]; then
        docker logs "$application_container" >&2
        printf 'Expected %s to return HTTP %s, got %s.\n' "$*" "$expected_status" "$actual_status" >&2
        exit 1
    fi
}

wait_for_passive_preflight()
{
    attempt=0

    until preflight_curl --fail --silent "$base_url/api/preflight/database" | grep -q '"read_only":true'; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 60 ]; then
            PREFLIGHT_IMAGE="$image" docker compose --project-name "$project" --file "$compose_file" logs
            printf '%s\n' 'Passive preflight stack did not become ready.' >&2
            exit 1
        fi
        sleep 1
    done
}

assert_passive_route_surface()
{
    [ "$(preflight_curl --fail --silent "$base_url/api/health")" = OK ]
    preflight_curl --fail --silent "$base_url/api/preflight/version" | grep -q '"mode":"passive"'
    preflight_curl --fail --silent "$base_url/api/preflight/database" | grep -q '"status":"ok"'

    assert_preflight_status 503 "$base_url/"
    assert_preflight_status 503 --head "$base_url/api/health"
    assert_preflight_status 503 --request POST "$base_url/api/health"
    assert_preflight_status 503 --request POST --header 'X-HTTP-Method-Override: GET' "$base_url/api/health"
    assert_preflight_status 503 "$base_url/api/health?"
    assert_preflight_status 503 "$base_url/api/preflight/version?probe=1"
    assert_preflight_status 503 "$base_url/api/preflight/version/"
    assert_preflight_status 503 "$base_url/api/v1/health"
    assert_preflight_status 503 "$base_url/api/control-plane/probe"
    assert_preflight_status 404 "$base_url/api/control-plane/route-health"
    assert_preflight_status 503 "$base_url/api/control-plane/route-health?probe=1"
}

assert_read_only_database_mutation_canary()
{
    readonly_state="$(PREFLIGHT_IMAGE="$image" docker compose \
        --project-name "$project" \
        --file "$compose_file" \
        exec --no-TTY --env PGPASSWORD=coolify-preflight-readonly database \
        psql --host 127.0.0.1 --username coolify_preflight_readonly --dbname coolify_preflight \
        --tuples-only --no-align --command "select current_setting('transaction_read_only');")"

    [ "$readonly_state" = on ]

    if PREFLIGHT_IMAGE="$image" docker compose \
        --project-name "$project" \
        --file "$compose_file" \
        exec --no-TTY --env PGPASSWORD=coolify-preflight-readonly database \
        psql --host 127.0.0.1 --username coolify_preflight_readonly --dbname coolify_preflight \
        --command 'SET default_transaction_read_only = off; CREATE TABLE passive_mode_must_not_write (id integer);' >/dev/null 2>&1
    then
        printf '%s\n' 'The preflight database role unexpectedly accepted a write.' >&2
        exit 1
    fi
}

assert_passive_artisan_workloads_absent()
{
    if PREFLIGHT_IMAGE="$image" docker compose \
        --project-name "$project" \
        --file "$compose_file" \
        top coolify-preflight | grep -Eq 'artisan (horizon|schedule:work|app:init|start:migration|start:seeder|nightwatch:agent|queue:work)'
    then
        printf '%s\n' 'A passive S6 service started an Artisan workload.' >&2
        exit 1
    fi
}

assert_writer_state_contract()
{
    docker run --rm --user 0 --entrypoint /bin/sh \
        --mount "$writer_state_mount" \
        "$image" -ec '
            test ! -L /var/lib/coolify-control-plane
            test "$(stat -c %u:%g:%a /var/lib/coolify-control-plane)" = 0:9999:750
            test ! -L /var/lib/coolify-control-plane/mutation-inflight.lock
            test -f /var/lib/coolify-control-plane/mutation-inflight.lock
            test "$(stat -c %u:%g:%a /var/lib/coolify-control-plane/mutation-inflight.lock)" = 0:9999:660
        '
}

assert_writer_marker_absent()
{
    docker run --rm --user 0 --entrypoint /bin/sh \
        --mount "$writer_state_mount" \
        "$image" -ec 'test ! -e /var/lib/coolify-control-plane/writer-epoch'
}

install_writer_marker()
{
    writer_marker_epoch="$1"

    docker run --rm --user 0 --entrypoint /bin/sh \
        --mount "$writer_state_mount" \
        --env "CONTROL_PLANE_TEST_WRITER_EPOCH=$writer_marker_epoch" \
        "$image" -ec '
            umask 027
            printf %s "$CONTROL_PLANE_TEST_WRITER_EPOCH" > /var/lib/coolify-control-plane/writer-epoch
            chown root:www-data /var/lib/coolify-control-plane/writer-epoch
            chmod 0440 /var/lib/coolify-control-plane/writer-epoch
            sync
        '
}

assert_writer_marker()
{
    expected_writer_marker_epoch="$1"

    docker run --rm --user 0 --entrypoint /bin/sh \
        --mount "$writer_state_mount" \
        --env "CONTROL_PLANE_TEST_WRITER_EPOCH=$expected_writer_marker_epoch" \
        "$image" -ec '
            test ! -L /var/lib/coolify-control-plane/writer-epoch
            test -f /var/lib/coolify-control-plane/writer-epoch
            test "$(stat -c %u:%g:%a /var/lib/coolify-control-plane/writer-epoch)" = 0:9999:440
            test "$(cat /var/lib/coolify-control-plane/writer-epoch)" = "$CONTROL_PLANE_TEST_WRITER_EPOCH"
        '
}

trap cleanup EXIT INT TERM

if [ "${PREFLIGHT_SKIP_BUILD:-false}" != true ]; then
    docker buildx build \
        --load \
        --file "$repository_root/docker/production/Dockerfile" \
        --tag "$image" \
        "$repository_root"
fi

docker volume create "$writer_volume" >/dev/null

docker run --rm --user 0 --entrypoint /bin/sh "$image" -ec '
    apk info -e su-exec=0.3-r0 >/dev/null
    [ -x /sbin/su-exec ] && [ ! -L /sbin/su-exec ]
    [ "$(stat -c %u:%g:%a /sbin/su-exec)" = 0:0:755 ]
    [ -x /bin/busybox ] && [ ! -L /bin/busybox ]
    [ "$(stat -c %u:%g:%a /bin/busybox)" = 0:0:755 ]
    /sbin/su-exec 9999:9999 /bin/sh -ec '\''
        [ "$(/usr/bin/id -u)" = 9999 ]
        [ "$(/usr/bin/id -g)" = 9999 ]
        [ "$(/usr/bin/id -G)" = 9999 ]
    '\''
'

hostile_environment="$(docker run --rm \
    --entrypoint /usr/local/bin/coolify-entrypoint \
    --env CONTROL_PLANE_MODE=passive \
    --env APP_DEBUG=true \
    --env AUTORUN_ENABLED=true \
    --env AUTORUN_LARAVEL_MIGRATION=true \
    --env MIGRATION_ENABLED=true \
    --env SEEDER_ENABLED=true \
    --env INIT_ENABLED=true \
    --env HORIZON_ENABLED=true \
    --env SCHEDULER_ENABLED=true \
    --env NIGHTWATCH_ENABLED=true \
    --env SENTRY_DSN=https://public@example.test/1 \
    --env SENTRY_LARAVEL_DSN=https://public@example.test/2 \
    --env SENTRY_ENABLE_TRACING=true \
    --env SENTRY_ENABLE_LOGS=true \
    --env SENTRY_SPOTLIGHT=true \
    --env TELESCOPE_ENABLED=true \
    --env DEBUGBAR_ENABLED=true \
    --env RAY_ENABLED=true \
    --env QUEUE_CONNECTION=redis \
    --env CACHE_STORE=redis \
    --env CACHE_DRIVER=redis \
    --env SESSION_DRIVER=database \
    --env BROADCAST_DRIVER=pusher \
    --env MAIL_MAILER=smtp \
    --env LOG_CHANNEL=stack \
    "$image" /usr/bin/env)"

for expected in \
    APP_DEBUG=false \
    AUTORUN_ENABLED=false \
    AUTORUN_LARAVEL_MIGRATION=false \
    MIGRATION_ENABLED=false \
    SEEDER_ENABLED=false \
    INIT_ENABLED=false \
    HORIZON_ENABLED=false \
    SCHEDULER_ENABLED=false \
    NIGHTWATCH_ENABLED=false \
    SENTRY_DSN= \
    SENTRY_LARAVEL_DSN= \
    SENTRY_ENABLE_TRACING=false \
    SENTRY_ENABLE_LOGS=false \
    SENTRY_SPOTLIGHT=false \
    TELESCOPE_ENABLED=false \
    DEBUGBAR_ENABLED=false \
    RAY_ENABLED=false \
    QUEUE_CONNECTION=null \
    CACHE_STORE=array \
    CACHE_DRIVER=array \
    SESSION_DRIVER=array \
    BROADCAST_DRIVER=null \
    MAIL_MAILER=array \
    LOG_CHANNEL=stderr \
    LOG_STACK=stderr
do
    assert_environment "$hostile_environment" "$expected"
done

if docker run --rm --env CONTROL_PLANE_MODE=standby "$image" /bin/true >"$invalid_mode_log" 2>&1; then
    printf '%s\n' 'Unknown CONTROL_PLANE_MODE unexpectedly started the image.' >&2
    exit 1
fi

grep -qF 'CONTROL_PLANE_MODE must be one of: active, passive.' "$invalid_mode_log"

if docker run --rm --env CONTROL_PLANE_STARTUP_MODE=standby "$image" /bin/true >"$invalid_startup_mode_log" 2>&1; then
    printf '%s\n' 'Unknown CONTROL_PLANE_STARTUP_MODE unexpectedly started the image.' >&2
    exit 1
fi

grep -qF 'CONTROL_PLANE_STARTUP_MODE must be one of: full, web-only.' "$invalid_startup_mode_log"

docker run --rm \
    --env CONTROL_PLANE_MODE=active \
    --env CONTROL_PLANE_STARTUP_MODE=web-only \
    "$image" /bin/true >/dev/null

if docker run --rm \
    --env CONTROL_PLANE_MODE=active \
    --env CONTROL_PLANE_STARTUP_MODE=web-only \
    --env CONTROL_PLANE_WRITER_MARKER_PATH="$writer_marker_path" \
    "$image" /bin/true >"$invalid_writer_epoch_log" 2>&1
then
    printf '%s\n' 'Active web-only mode unexpectedly accepted a partial writer identity.' >&2
    exit 1
fi

grep -qF 'CONTROL_PLANE_WRITER_EPOCH must contain 16 to 128 safe token characters.' "$invalid_writer_epoch_log"

if docker run --rm \
    --env CONTROL_PLANE_MODE=active \
    --env CONTROL_PLANE_STARTUP_MODE=web-only \
    --env CONTROL_PLANE_WRITER_EPOCH="$writer_epoch" \
    --env CONTROL_PLANE_WRITER_MARKER_PATH=/tmp/writer-epoch \
    "$image" /bin/true >"$invalid_writer_path_log" 2>&1
then
    printf '%s\n' 'Active web-only mode unexpectedly accepted an ephemeral writer marker.' >&2
    exit 1
fi

grep -qF 'CONTROL_PLANE_WRITER_MARKER_PATH must be an absolute persistent path without dot segments.' "$invalid_writer_path_log"

active_environment="$(docker run --rm \
    --env CONTROL_PLANE_MODE=active \
    --env CONTROL_PLANE_STARTUP_MODE=full \
    --env AUTORUN_ENABLED=false \
    "$image" /usr/bin/env)"

assert_environment "$active_environment" 'CONTROL_PLANE_MODE=active'
assert_environment "$active_environment" 'CONTROL_PLANE_STARTUP_MODE=full'

docker run --rm \
    --env CONTROL_PLANE_MODE=active \
    --env CONTROL_PLANE_STARTUP_MODE=full \
    --env AUTORUN_ENABLED=false \
    --env INIT_ENABLED=false \
    "$image" php artisan app:init | grep -qF 'Initialization is disabled on this server.'

docker run --detach \
    --name "$hostile_web_only_container" \
    --network none \
    --mount "$writer_state_mount" \
    --env CONTROL_PLANE_MODE=active \
    --env CONTROL_PLANE_STARTUP_MODE=web-only \
    --env CONTROL_PLANE_WRITER_EPOCH="$writer_epoch" \
    --env CONTROL_PLANE_WRITER_MARKER_PATH="$writer_marker_path" \
    --env APP_ENV=production \
    --env APP_DEBUG=false \
    --env APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    --env AUTORUN_ENABLED=false \
    --env INIT_ENABLED=true \
    --env MIGRATION_ENABLED=true \
    --env SEEDER_ENABLED=true \
    --env HORIZON_ENABLED=true \
    --env SCHEDULER_ENABLED=true \
    --env NIGHTWATCH_ENABLED=true \
    "$image" >/dev/null

wait_for_s6 "$hostile_web_only_container"
assert_writer_state_contract
assert_writer_marker_absent

for expected_log in \
    'Control plane initialization is disabled.' \
    'Control plane database migration is disabled.' \
    'Control plane database seeding is disabled.' \
    'Horizon is disabled, sleeping.' \
    'Scheduler is disabled, sleeping.' \
    'Nightwatch is disabled, sleeping.'
do
    docker logs "$hostile_web_only_container" 2>&1 | grep -qF "$expected_log"
done

if docker top "$hostile_web_only_container" | grep -Eq 'artisan (horizon|schedule:work|app:init|start:migration|start:seeder|nightwatch:agent)'; then
    printf '%s\n' 'An active web-only candidate started a writer before promotion.' >&2
    exit 1
fi

docker rm --force "$hostile_web_only_container" >/dev/null

docker run --detach \
    --name "$promotion_web_only_container" \
    --network none \
    --mount "$writer_state_mount" \
    --env CONTROL_PLANE_MODE=active \
    --env CONTROL_PLANE_STARTUP_MODE=web-only \
    --env CONTROL_PLANE_WRITER_EPOCH="$writer_epoch" \
    --env CONTROL_PLANE_WRITER_MARKER_PATH="$writer_marker_path" \
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

wait_for_s6 "$promotion_web_only_container"
install_writer_marker "$writer_epoch"
assert_writer_marker "$writer_epoch"

docker exec \
    --env CONTROL_PLANE_PROMOTION_CONFIRM=blue-stopped \
    "$promotion_web_only_container" \
    /usr/local/bin/coolify-entrypoint promote-writer | grep -qF 'Control plane writer promotion completed.'

docker exec "$promotion_web_only_container" test -f "$writer_marker_path"
assert_writer_marker "$writer_epoch"

docker exec \
    --env CONTROL_PLANE_PROMOTION_CONFIRM=blue-stopped \
    "$promotion_web_only_container" \
    /usr/local/bin/coolify-entrypoint promote-writer | grep -qF 'Control plane writer promotion completed.'

if docker top "$promotion_web_only_container" | grep -Eq 'artisan (horizon|schedule:work|app:init|start:migration|start:seeder|nightwatch:agent)'; then
    printf '%s\n' 'Writer promotion ignored explicitly disabled services.' >&2
    exit 1
fi

docker restart "$promotion_web_only_container" >/dev/null
assert_writer_marker "$writer_epoch"

attempt=0
until docker exec \
    --env HORIZON_ENABLED=true \
    "$promotion_web_only_container" \
    /usr/local/bin/coolify-entrypoint service-enabled HORIZON_ENABLED true
do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        docker logs "$promotion_web_only_container" >&2
        printf '%s\n' 'A matching persistent writer marker did not survive restart.' >&2
        exit 1
    fi
    sleep 1
done

docker rm --force "$promotion_web_only_container" >/dev/null

docker run --detach \
    --name "$promotion_web_only_container" \
    --network none \
    --mount "$writer_state_mount" \
    --env CONTROL_PLANE_MODE=active \
    --env CONTROL_PLANE_STARTUP_MODE=web-only \
    --env CONTROL_PLANE_WRITER_EPOCH="$stale_writer_epoch" \
    --env CONTROL_PLANE_WRITER_MARKER_PATH="$writer_marker_path" \
    --env APP_ENV=production \
    --env APP_DEBUG=false \
    --env APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    --env AUTORUN_ENABLED=false \
    --env INIT_ENABLED=true \
    --env MIGRATION_ENABLED=true \
    --env SEEDER_ENABLED=true \
    --env HORIZON_ENABLED=true \
    --env SCHEDULER_ENABLED=true \
    --env NIGHTWATCH_ENABLED=true \
    "$image" >/dev/null

wait_for_s6 "$promotion_web_only_container"

if docker exec \
    "$promotion_web_only_container" \
    /usr/local/bin/coolify-entrypoint service-enabled HORIZON_ENABLED true
then
    printf '%s\n' 'A stale writer marker unexpectedly enabled a replacement deployment.' >&2
    exit 1
fi

if docker top "$promotion_web_only_container" | grep -Eq 'artisan (horizon|schedule:work|app:init|start:migration|start:seeder|nightwatch:agent)'; then
    printf '%s\n' 'A stale writer marker started a writer in a replacement deployment.' >&2
    exit 1
fi

docker rm --force "$promotion_web_only_container" >/dev/null

if docker run --rm \
    --entrypoint /usr/local/bin/coolify-entrypoint \
    --mount "$writer_state_mount" \
    --env CONTROL_PLANE_MODE=active \
    --env CONTROL_PLANE_STARTUP_MODE=web-only \
    --env CONTROL_PLANE_WRITER_EPOCH="$unpromoted_writer_epoch" \
    --env CONTROL_PLANE_WRITER_MARKER_PATH="$writer_marker_path" \
    "$image" service-enabled HORIZON_ENABLED true
then
    printf '%s\n' 'An unpromoted web-only candidate unexpectedly enabled Horizon.' >&2
    exit 1
fi

docker run --rm \
    --entrypoint /usr/local/bin/coolify-entrypoint \
    --env CONTROL_PLANE_MODE=active \
    --env CONTROL_PLANE_STARTUP_MODE=full \
    "$image" service-enabled HORIZON_ENABLED true

for setting in INIT_ENABLED MIGRATION_ENABLED SEEDER_ENABLED HORIZON_ENABLED SCHEDULER_ENABLED NIGHTWATCH_ENABLED; do
    if docker run --rm \
        --entrypoint /usr/local/bin/coolify-entrypoint \
        --env CONTROL_PLANE_MODE=active \
        --env CONTROL_PLANE_STARTUP_MODE=full \
        --env "$setting=false" \
        "$image" service-enabled "$setting" true
    then
        printf '%s=false was not authoritative over the active default.\n' "$setting" >&2
        exit 1
    fi
done

docker run --rm \
    --entrypoint /usr/local/bin/coolify-entrypoint \
    --env CONTROL_PLANE_MODE=active \
    --env CONTROL_PLANE_STARTUP_MODE=full \
    --env NIGHTWATCH_ENABLED=true \
    "$image" service-enabled NIGHTWATCH_ENABLED false

if docker run --rm \
    --entrypoint /usr/local/bin/coolify-entrypoint \
    --mount "$writer_state_mount" \
    --env CONTROL_PLANE_MODE=active \
    --env CONTROL_PLANE_STARTUP_MODE=web-only \
    --env CONTROL_PLANE_WRITER_EPOCH="$unpromoted_writer_epoch" \
    --env CONTROL_PLANE_WRITER_MARKER_PATH="$writer_marker_path" \
    --env CONTROL_PLANE_PROMOTION_IN_PROGRESS="$unpromoted_writer_epoch" \
    "$image" service-enabled HORIZON_ENABLED true
then
    printf '%s\n' 'External promotion state unexpectedly bypassed writer fencing.' >&2
    exit 1
fi

if docker run --rm \
    --entrypoint /usr/local/bin/coolify-entrypoint \
    --mount "$writer_state_mount" \
    --env CONTROL_PLANE_MODE=active \
    --env CONTROL_PLANE_STARTUP_MODE=web-only \
    --env CONTROL_PLANE_WRITER_EPOCH="$unpromoted_writer_epoch" \
    --env CONTROL_PLANE_WRITER_MARKER_PATH="$writer_marker_path" \
    "$image" promote-writer >/dev/null 2>&1
then
    printf '%s\n' 'Web-only writer promotion unexpectedly ran without blue-stopped confirmation.' >&2
    exit 1
fi

if docker run --rm \
    --entrypoint /usr/local/bin/coolify-entrypoint \
    --env CONTROL_PLANE_MODE=passive \
    --env CONTROL_PLANE_STARTUP_MODE=web-only \
    --env CONTROL_PLANE_PROMOTION_CONFIRM=blue-stopped \
    "$image" promote-writer >/dev/null 2>&1
then
    printf '%s\n' 'Passive mode unexpectedly accepted writer promotion.' >&2
    exit 1
fi

PREFLIGHT_IMAGE="$image" docker compose \
    --project-name "$project" \
    --file "$compose_file" \
    up --detach --no-build

application_container="$(PREFLIGHT_IMAGE="$image" docker compose \
    --project-name "$project" \
    --file "$compose_file" \
    ps --quiet coolify-preflight)"

[ -n "$application_container" ]

wait_for_passive_preflight
assert_passive_route_surface

PREFLIGHT_IMAGE="$image" docker compose \
    --project-name "$project" \
    --file "$compose_file" \
    exec --no-TTY --env PGPASSWORD=coolify-preflight-owner database \
    psql --username coolify_preflight_owner --dbname coolify_preflight \
    --command 'GRANT CREATE, TEMPORARY ON DATABASE coolify_preflight TO coolify_preflight_readonly;' >/dev/null

[ "$(preflight_curl --silent --output /dev/null --write-out '%{http_code}' "$base_url/api/preflight/database")" = 503 ]

PREFLIGHT_IMAGE="$image" docker compose \
    --project-name "$project" \
    --file "$compose_file" \
    exec --no-TTY --env PGPASSWORD=coolify-preflight-owner database \
    psql --username coolify_preflight_owner --dbname coolify_preflight \
    --command 'REVOKE CREATE, TEMPORARY ON DATABASE coolify_preflight FROM coolify_preflight_readonly;' >/dev/null

preflight_curl --fail --silent "$base_url/api/preflight/database" | grep -q '"read_only":true'
assert_read_only_database_mutation_canary

[ "$(docker inspect --format '{{len .Mounts}}' "$application_container")" = 0 ]
[ "$(docker inspect --format '{{.HostConfig.SecurityOpt}}' "$application_container")" = '[no-new-privileges:true]' ]
docker inspect --format '{{json .HostConfig.CapDrop}}' "$application_container" | grep -q 'ALL'
docker exec "$application_container" test ! -e /var/run/docker.sock

if docker exec "$application_container" sh -c "find /var/www/html/storage/app/ssh/keys -type f -print -quit 2>/dev/null | grep -q ."; then
    printf '%s\n' 'The passive image unexpectedly contains SSH key material.' >&2
    exit 1
fi

if docker exec "$application_container" curl --fail --silent --connect-timeout 2 http://example.com >/dev/null 2>&1; then
    printf '%s\n' 'The quarantined passive container unexpectedly reached the public network.' >&2
    exit 1
fi

network_id="$(docker inspect --format '{{range .NetworkSettings.Networks}}{{.NetworkID}}{{end}}' "$application_container")"
[ "$(docker network inspect --format '{{.Internal}}' "$network_id")" = true ]

assert_passive_artisan_workloads_absent

docker restart "$application_container" >/dev/null
wait_for_passive_preflight
assert_passive_route_surface
assert_read_only_database_mutation_canary
assert_passive_artisan_workloads_absent

printf 'PASSIVE_CONTROL_PLANE_IMAGE_TEST passed\n'
