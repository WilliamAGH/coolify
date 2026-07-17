#!/bin/sh

set -eu

repository_root=$(CDPATH='' cd -- "$(dirname "$0")/../../.." && pwd)
entrypoint="$repository_root/docker/production/bin/coolify-entrypoint"
healthcheck="$repository_root/docker/production/bin/control-plane-direct-probe-healthcheck"
temporary_directory=$(mktemp -d "${TMPDIR:-/tmp}/coolify-entrypoint-contract.XXXXXX")
image_tag="coolify-entrypoint-contract-$$"

fail()
{
    printf 'control-plane simulation entrypoint runtime contract failed: %s\n' "$1" >&2
    exit 1
}

cleanup()
{
    docker image rm "$image_tag" >/dev/null 2>&1 || :
    rm -rf "$temporary_directory"
}

trap cleanup EXIT HUP INT TERM

[ -x "$entrypoint" ] || fail "entrypoint is not executable: $entrypoint"
[ -x "$healthcheck" ] || fail "healthcheck is not executable: $healthcheck"

cat > "$temporary_directory/Dockerfile" <<'DOCKERFILE'
FROM alpine:3.24@sha256:28bd5fe8b56d1bd048e5babf5b10710ebe0bae67db86916198a6eec434943f8b

RUN apk add --no-cache php85 s6 su-exec \
    && adduser -D -H -G www-data www-data \
    && ln -s /usr/bin/php85 /usr/local/bin/php \
    && mkdir -p /command /var/www/html \
    && ln -s /usr/bin/s6-svc /command/s6-svc \
    && ln -s /usr/bin/s6-svscan /command/s6-svscan \
    && ln -s /usr/bin/s6-svstat /command/s6-svstat
DOCKERFILE

docker build --tag "$image_tag" "$temporary_directory" >/dev/null

docker run --rm --interactive \
    --volume "$entrypoint:/usr/local/bin/coolify-entrypoint:ro" \
    --volume "$healthcheck:/usr/local/bin/control-plane-direct-probe-healthcheck:ro" \
    --env CONTROL_PLANE_MODE=active \
    --env CONTROL_PLANE_STARTUP_MODE=web-only \
    --env CONTROL_PLANE_WRITER_EPOCH=writer-epoch-0123456789 \
    --env CONTROL_PLANE_WRITER_MARKER_PATH=/var/lib/coolify-control-plane/private/writer-epoch \
    --env HORIZON_ENABLED=true \
    --env SCHEDULER_ENABLED=true \
    --env NIGHTWATCH_ENABLED=false \
    --env CONTROL_PLANE_BACKEND_PORT=8000 \
    --env CONTROL_PLANE_DIRECT_PROBE_PATH=/api/internal/control-plane/ready \
    --entrypoint /bin/sh "$image_tag" -s <<'CONTAINER'
set -eu

test_directory=/tmp/control-plane-entrypoint-contract
direct_probe_token=direct-probe-token-0123456789
applied_acknowledgement=applied-acknowledgement-0123456789
s6_scan_pid=

fail()
{
    printf 'container runtime contract failed: %s\n' "$1" >&2
    exit 1
}

cleanup()
{
    [ -z "$s6_scan_pid" ] || kill "$s6_scan_pid" >/dev/null 2>&1 || :
}

wait_for_expected_artisan()
{
    service_name=$1
    artisan_command=$2
    attempt=0

    while [ "$attempt" -lt 50 ]; do
        if [ "$(/command/s6-svstat -o up "/run/service/$service_name" 2>/dev/null || true)" = true ]; then
            service_pid=$(/command/s6-svstat -o pid "/run/service/$service_name")
            if tr '\000' '\n' < "/proc/$service_pid/cmdline" | awk \
                -v expected_artisan_command="$artisan_command" '
                    BEGIN { valid = 1; repeated_argv0 = 0; artisan = 0; command = 0 }
                    NR == 1 {
                        if ($0 != "php" && $0 !~ /(^|\/)php$/) valid = 0
                        next
                    }
                    NR == 2 {
                        if ($0 == "artisan") artisan = 1
                        else if ($0 == "php") repeated_argv0 = 1
                        else valid = 0
                        next
                    }
                    NR == 3 {
                        if (repeated_argv0) {
                            if ($0 != "artisan") valid = 0
                            else artisan = 1
                        } else if ($0 == expected_artisan_command) command = 1
                        else valid = 0
                        next
                    }
                    NR == 4 {
                        if (!repeated_argv0 || $0 != expected_artisan_command) valid = 0
                        else command = 1
                        next
                    }
                    { valid = 0 }
                    END { exit !(valid && artisan && command && (NR == 3 || NR == 4)) }
                '; then
                printf '%s\n' "$service_pid"
                return 0
            fi
        fi
        attempt=$((attempt + 1))
        sleep 0.1
    done

    [ ! -r /tmp/s6-svscan.log ] || cat /tmp/s6-svscan.log >&2
    [ ! -r "$test_directory/$service_name.log" ] \
        || cat "$test_directory/$service_name.log" >&2
    fail "expected Artisan command did not start: $service_name"
}

wait_for_supervised_sleeper()
{
    service_name=$1
    attempt=0

    while [ "$attempt" -lt 50 ]; do
        if [ "$(/command/s6-svstat -o up "/run/service/$service_name" 2>/dev/null || true)" = true ]; then
            service_pid=$(/command/s6-svstat -o pid "/run/service/$service_name")
            if tr '\000' '\n' < "/proc/$service_pid/cmdline" | awk '
                BEGIN { valid = 1; duration = 0; repeated_argv0 = 0 }
                NR == 1 {
                    if ($0 !~ /(^|\/)sleep$/) valid = 0
                    next
                }
                NR == 2 {
                    if ($0 == "infinity") duration = 1
                    else if ($0 == "sleep") repeated_argv0 = 1
                    else valid = 0
                    next
                }
                NR == 3 {
                    if (!repeated_argv0 || $0 != "infinity") valid = 0
                    else duration = 1
                    next
                }
                { valid = 0 }
                END { exit !(valid && duration && (NR == 2 || NR == 3)) }
            '; then
                return 0
            fi
        fi
        attempt=$((attempt + 1))
        sleep 0.1
    done

    [ ! -r /tmp/s6-svscan.log ] || cat /tmp/s6-svscan.log >&2
    [ ! -r "$test_directory/$service_name.log" ] \
        || cat "$test_directory/$service_name.log" >&2
    fail "fenced sleeper did not start: $service_name"
}

wait_for_service_down()
{
    service_name=$1
    attempt=0

    while [ "$attempt" -lt 50 ]; do
        [ "$(/command/s6-svstat -o up "/run/service/$service_name" 2>/dev/null || true)" = false ] \
            && return 0
        attempt=$((attempt + 1))
        sleep 0.1
    done
    fail "service did not become supervised-down: $service_name"
}

wait_for_unknown_process()
{
    service_name=$1
    attempt=0

    while [ "$attempt" -lt 50 ]; do
        if [ "$(/command/s6-svstat -o up "/run/service/$service_name" 2>/dev/null || true)" = true ]; then
            service_pid=$(/command/s6-svstat -o pid "/run/service/$service_name")
            if tr '\000' '\n' < "/proc/$service_pid/cmdline" 2>/dev/null | awk '
                BEGIN { valid = 1; duration = 0; repeated_argv0 = 0 }
                NR == 1 { valid = ($0 ~ /(^|\/)sleep$/); next }
                NR == 2 {
                    if ($0 == "123") duration = 1
                    else if ($0 == "sleep") repeated_argv0 = 1
                    else valid = 0
                    next
                }
                NR == 3 {
                    if (!repeated_argv0 || $0 != "123") valid = 0
                    else duration = 1
                    next
                }
                { valid = 0 }
                END { exit !(valid && duration && (NR == 2 || NR == 3)) }
            '; then
                printf '%s\n' "$service_pid"
                return 0
            fi
        fi
        attempt=$((attempt + 1))
        sleep 0.1
    done
    fail "unknown supervised process did not start: $service_name"
}

assert_no_writer_artisan()
{
    for process_path in /proc/[0-9]*; do
        [ -r "$process_path/cmdline" ] || continue
        if { tr '\000' '\n' < "$process_path/cmdline"; } 2>/dev/null | awk '
            BEGIN { valid = 1; repeated_argv0 = 0; artisan = 0 }
            NR == 1 { valid = ($0 ~ /(^|\/)php$/); next }
            NR == 2 {
                if ($0 == "artisan") artisan = 1
                else if ($0 == "php") repeated_argv0 = 1
                else valid = 0
                next
            }
            NR == 3 && repeated_argv0 {
                if ($0 == "artisan") artisan = 1
                else valid = 0
            }
            END { exit !(valid && artisan) }
        '; then
            fail "writer Artisan remained after promotion failure: ${process_path##*/}"
        fi
    done
}

write_service()
{
    service_name=$1
    setting_name=$2
    default_value=$3
    artisan_command=$4

    mkdir -p "/run/service/$service_name"
    chown www-data:www-data "/run/service/$service_name"
    cat > "/run/service/$service_name/run" <<SERVICE
#!/bin/sh
exec /usr/local/bin/coolify-entrypoint run-background $setting_name $default_value $artisan_command >$test_directory/$service_name.log 2>&1
SERVICE
    chmod 755 "/run/service/$service_name/run"
}

write_unknown_service()
{
    service_name=$1

    cat > "/run/service/$service_name/run" <<'SERVICE'
#!/bin/sh
exec sleep 123
SERVICE
    chmod 755 "/run/service/$service_name/run"
}

replace_service_with_unknown_process()
{
    service_name=$1

    /command/s6-svc -d "/run/service/$service_name"
    wait_for_service_down "$service_name"
    write_unknown_service "$service_name"
    /command/s6-svc -u "/run/service/$service_name"
    wait_for_unknown_process "$service_name" >/dev/null
}

restore_service_sleeper()
{
    service_name=$1
    setting_name=$2
    default_value=$3
    artisan_command=$4
    expected_process=$5

    /command/s6-svc -d "/run/service/$service_name"
    wait_for_service_down "$service_name"
    write_service "$service_name" "$setting_name" "$default_value" "$artisan_command"
    /command/s6-svc -u "/run/service/$service_name"
    case "$expected_process" in
        artisan) wait_for_expected_artisan "$service_name" "$artisan_command" >/dev/null ;;
        sleeper) wait_for_supervised_sleeper "$service_name" ;;
        *) fail "invalid restored service expectation: $expected_process" ;;
    esac
}

trap cleanup EXIT HUP INT TERM

mkdir -p /run/service "$test_directory/bin" /run/secrets \
    /var/lib/coolify-control-plane/private
chown -R www-data:www-data /run/service "$test_directory"
chown root:www-data /var/lib/coolify-control-plane/private
chmod 750 /var/lib/coolify-control-plane/private
printf '%s' "$direct_probe_token" > /run/secrets/control-plane-direct-probe-token
printf '%s' "$applied_acknowledgement" > /run/secrets/control-plane-applied-ack
chmod 444 /run/secrets/control-plane-direct-probe-token /run/secrets/control-plane-applied-ack

cat > /var/www/html/artisan <<'ARTISAN'
<?php
while (true) {
    sleep(1);
}
ARTISAN

write_service horizon HORIZON_ENABLED true horizon
write_service scheduler-worker SCHEDULER_ENABLED true schedule:work
write_service nightwatch-agent NIGHTWATCH_ENABLED false nightwatch:agent

su-exec www-data:www-data /command/s6-svscan /run/service >/tmp/s6-svscan.log 2>&1 &
s6_scan_pid=$!
wait_for_supervised_sleeper horizon
wait_for_supervised_sleeper scheduler-worker
wait_for_supervised_sleeper nightwatch-agent

printf '%s' "$CONTROL_PLANE_WRITER_EPOCH" \
    > /var/lib/coolify-control-plane/private/writer-epoch
chown root:www-data /var/lib/coolify-control-plane/private/writer-epoch
chmod 440 /var/lib/coolify-control-plane/private/writer-epoch

horizon_sleeper_pid=$(/command/s6-svstat -o pid /run/service/horizon)
nightwatch_sleeper_pid=$(/command/s6-svstat -o pid /run/service/nightwatch-agent)
replace_service_with_unknown_process scheduler-worker
if su-exec www-data:www-data env CONTROL_PLANE_PROMOTION_CONFIRM=blue-stopped \
    /usr/local/bin/coolify-entrypoint promote-writer >/tmp/scheduler-unknown.log 2>&1; then
    fail 'writer promotion accepted an unknown Scheduler process'
fi
[ "$(/command/s6-svstat -o pid /run/service/horizon)" = "$horizon_sleeper_pid" ] \
    || fail 'Scheduler preflight failure mutated Horizon'
[ "$(/command/s6-svstat -o pid /run/service/nightwatch-agent)" = "$nightwatch_sleeper_pid" ] \
    || fail 'Scheduler preflight failure mutated Nightwatch'
restore_service_sleeper scheduler-worker SCHEDULER_ENABLED true schedule:work artisan

scheduler_sleeper_pid=$(/command/s6-svstat -o pid /run/service/scheduler-worker)
replace_service_with_unknown_process nightwatch-agent
if su-exec www-data:www-data env CONTROL_PLANE_PROMOTION_CONFIRM=blue-stopped \
    /usr/local/bin/coolify-entrypoint promote-writer >/tmp/nightwatch-unknown.log 2>&1; then
    fail 'writer promotion accepted an unknown Nightwatch process'
fi
[ "$(/command/s6-svstat -o pid /run/service/horizon)" = "$horizon_sleeper_pid" ] \
    || fail 'Nightwatch preflight failure mutated Horizon'
[ "$(/command/s6-svstat -o pid /run/service/scheduler-worker)" = "$scheduler_sleeper_pid" ] \
    || fail 'Nightwatch preflight failure mutated Scheduler'
restore_service_sleeper nightwatch-agent NIGHTWATCH_ENABLED false nightwatch:agent sleeper

first_promotion_output=$(su-exec www-data:www-data env \
    CONTROL_PLANE_PROMOTION_CONFIRM=blue-stopped \
    /usr/local/bin/coolify-entrypoint promote-writer 2>&1)
[ "$first_promotion_output" = 'Control plane writer promotion completed.' ] \
    || fail "first promotion did not return the exact acknowledgement: $first_promotion_output"
horizon_pid=$(wait_for_expected_artisan horizon horizon)
scheduler_pid=$(wait_for_expected_artisan scheduler-worker schedule:work)
[ "$(/command/s6-svstat -o up /run/service/nightwatch-agent)" = true ] \
    || fail 'disabled Nightwatch service became unavailable'

second_promotion_output=$(su-exec www-data:www-data env \
    CONTROL_PLANE_PROMOTION_CONFIRM=blue-stopped \
    /usr/local/bin/coolify-entrypoint promote-writer 2>&1)
[ "$second_promotion_output" = 'Control plane writer promotion completed.' ] \
    || fail 'second promotion did not return the exact acknowledgement'
[ "$(/command/s6-svstat -o pid /run/service/horizon)" = "$horizon_pid" ] \
    || fail 'second promotion restarted Horizon'
[ "$(/command/s6-svstat -o pid /run/service/scheduler-worker)" = "$scheduler_pid" ] \
    || fail 'second promotion restarted Scheduler'

set +e
su-exec www-data:www-data env \
    CONTROL_PLANE_PROMOTION_CONFIRM=blue-stopped \
    CONTROL_PLANE_TEST_PROMOTION_FAIL_AFTER_SERVICE=horizon \
    /usr/local/bin/coolify-entrypoint promote-writer \
    >/tmp/unattested-promotion-fault.log 2>&1
unattested_fault_status=$?
set -e
[ "$unattested_fault_status" -eq 64 ] \
    || fail 'production entrypoint accepted an unattested promotion fault control'
[ "$(wait_for_expected_artisan horizon horizon)" = "$horizon_pid" ] \
    || fail 'unattested promotion fault mutated Horizon'

printf '%s\n' writer-promotion-faults-v1 \
    > /etc/coolify-control-plane-entrypoint-test-attestation
chown root:root /etc/coolify-control-plane-entrypoint-test-attestation
chmod 444 /etc/coolify-control-plane-entrypoint-test-attestation

set +e
su-exec www-data:www-data env \
    CONTROL_PLANE_PROMOTION_CONFIRM=blue-stopped \
    CONTROL_PLANE_TEST_PROMOTION_FAIL_AFTER_SERVICE=horizon \
    CONTROL_PLANE_TEST_FORCE_DOWN_FAILURE=1 \
    /usr/local/bin/coolify-entrypoint promote-writer \
    >/tmp/unproven-promotion-rollback.log 2>&1
unproven_rollback_status=$?
set -e
[ "$unproven_rollback_status" -eq 70 ] \
    || fail 'unproven promotion rollback did not return its distinct status'
[ -f /var/lib/coolify-control-plane/private/writer-epoch ] \
    || fail 'unproven promotion rollback changed the root-owned writer marker'
[ "$(wait_for_expected_artisan horizon horizon)" = "$horizon_pid" ] \
    || fail 'unproven promotion rollback did not preserve evidence of a live writer'

for service_name in horizon scheduler-worker nightwatch-agent; do
    /command/s6-svc -d "/run/service/$service_name"
    wait_for_service_down "$service_name"
done
if su-exec www-data:www-data env \
    CONTROL_PLANE_PROMOTION_CONFIRM=blue-stopped \
    CONTROL_PLANE_TEST_PROMOTION_FAIL_AFTER_SERVICE=scheduler-worker \
    /usr/local/bin/coolify-entrypoint promote-writer >/tmp/late-promotion-failure.log 2>&1; then
    fail 'late Scheduler apply failure injection unexpectedly promoted the writer'
fi
for service_name in horizon scheduler-worker nightwatch-agent; do
    [ "$(/command/s6-svstat -o up "/run/service/$service_name")" = false ] \
        || fail "promotion failure did not force service down: $service_name"
done
assert_no_writer_artisan

rm -f /var/lib/coolify-control-plane/private/writer-epoch
fencing_output=$(su-exec www-data:www-data \
    /usr/local/bin/coolify-entrypoint fence-writer 2>&1)
[ "$fencing_output" = 'Control plane writer fencing completed.' ] \
    || fail 'post-revocation writer fencing did not return the exact acknowledgement'
wait_for_supervised_sleeper horizon
wait_for_supervised_sleeper scheduler-worker
wait_for_supervised_sleeper nightwatch-agent
assert_no_writer_artisan

cat > "$test_directory/bin/curl" <<'CURL'
#!/bin/sh
set -eu

printf '%s\n' "$@" > "$TEST_DIRECTORY/curl-argv"
env > "$TEST_DIRECTORY/curl-environment"
headers_file=
while [ "$#" -gt 0 ]; do
    case "$1" in
        --dump-header)
            headers_file=$2
            shift 2
            ;;
        *)
            shift
            ;;
    esac
done

cat >/dev/null

case "${FAKE_CURL_RESPONSE_MODE-exact}" in
    exact)
        printf '%s\r\nX-Control-Plane-Applied-Config: %s\r\n\r\n' \
            'HTTP/1.1 204 No Content' "$(cat /run/secrets/control-plane-applied-ack)" \
            > "$headers_file"
        ;;
    duplicate)
        printf '%s\r\nX-Control-Plane-Applied-Config: %s\r\nX-Control-Plane-Applied-Config: %s\r\n\r\n' \
            'HTTP/1.1 204 No Content' \
            "$(cat /run/secrets/control-plane-applied-ack)" \
            "$(cat /run/secrets/control-plane-applied-ack)" > "$headers_file"
        ;;
    missing)
        printf '%s\r\n\r\n' 'HTTP/1.1 204 No Content' > "$headers_file"
        ;;
    wrong)
        printf '%s\r\nX-Control-Plane-Applied-Config: wrong\r\n\r\n' \
            'HTTP/1.1 204 No Content' > "$headers_file"
        ;;
    wrong-status)
        printf '%s\r\nX-Control-Plane-Applied-Config: %s\r\n\r\n' \
            'HTTP/1.1 200 OK' "$(cat /run/secrets/control-plane-applied-ack)" \
            > "$headers_file"
        ;;
    *)
        exit 64
        ;;
esac
CURL
chmod 755 "$test_directory/bin/curl"

healthcheck_output=$(su-exec www-data:www-data env \
    PATH="$test_directory/bin:$PATH" \
    TEST_DIRECTORY="$test_directory" \
    /usr/local/bin/control-plane-direct-probe-healthcheck 2>&1) \
    || fail 'direct probe healthcheck rejected the exact response'
[ -z "$healthcheck_output" ] || fail 'direct probe healthcheck emitted output'
grep -F -x -q -- '--config' "$test_directory/curl-argv" \
    || fail 'healthcheck did not provide curl configuration through stdin'
grep -F -x -q -- '-' "$test_directory/curl-argv" \
    || fail 'healthcheck did not use stdin for curl configuration'
if grep -F -q "$direct_probe_token" \
    "$test_directory/curl-argv" "$test_directory/curl-environment" \
    || grep -F -q "$applied_acknowledgement" \
        "$test_directory/curl-argv" "$test_directory/curl-environment"; then
    fail 'healthcheck exposed a direct-probe secret to curl argv or environment'
fi

for response_mode in duplicate missing wrong wrong-status; do
    if su-exec www-data:www-data env \
        PATH="$test_directory/bin:$PATH" \
        TEST_DIRECTORY="$test_directory" \
        FAKE_CURL_RESPONSE_MODE="$response_mode" \
        /usr/local/bin/control-plane-direct-probe-healthcheck >/dev/null 2>&1; then
        fail "direct probe healthcheck accepted a $response_mode acknowledgement"
    fi
done

printf '%s\n' 'CONTROL_PLANE_SIMULATION_ENTRYPOINT_RUNTIME_CONTRACT_PASS'
CONTAINER
