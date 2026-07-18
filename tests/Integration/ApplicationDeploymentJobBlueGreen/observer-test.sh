#!/bin/sh

set -eu

script_directory=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
work_directory=$(mktemp -d "${TMPDIR:-/tmp}/application-deployment-observer-test.XXXXXX")
observer_pid=
observer_log="$work_directory/observer.log"
print_observer_log()
{
    [ -f "$observer_log" ] || return 0
    printf 'Observer log follows:\n' >&2
    sed 's/^/  /' "$observer_log" >&2
}
require_observer_running()
{
    if ! kill -0 "$observer_pid" 2>/dev/null; then
        printf 'Observer exited before completing the race test.\n' >&2
        print_observer_log
        return 1
    fi
}
assert_world_readable_evidence()
{
    evidence_file=$1
    evidence_mode=$(stat -c '%a' "$evidence_file" 2>/dev/null \
        || stat -f '%Lp' "$evidence_file")
    [ "$evidence_mode" = 644 ]
}
cleanup()
{
    [ -z "$observer_pid" ] || kill "$observer_pid" >/dev/null 2>&1 || true
    [ -z "$observer_pid" ] || wait "$observer_pid" >/dev/null 2>&1 || true
    rm -rf "$work_directory"
}
trap cleanup EXIT INT TERM

coolify_data="$work_directory/coolify"
runtime_evidence="$work_directory/runtime-evidence"
mock_bin="$work_directory/bin"
route="$coolify_data/proxy/dynamic/coolify-blue-green-race.yaml"
compose="$coolify_data/applications/fixture/docker-compose.yaml"
cp_fault_count="$work_directory/cp-fault-count"
install -d "$coolify_data/proxy/dynamic" "$coolify_data/applications/fixture" \
    "$runtime_evidence" "$mock_bin"
printf 'http:\n  revision: seeded\n' >"$route"
printf '0\n' >"$cp_fault_count"

cat >"$mock_bin/docker" <<'EOF'
#!/bin/sh
case "$1" in
    events)
        while :; do sleep 1; done
        ;;
    ps)
        exit 0
        ;;
    *)
        exit 0
        ;;
esac
EOF
chmod +x "$mock_bin/docker"

cat >"$mock_bin/cp" <<'EOF'
#!/bin/sh
set -eu

if [ "$1" = "$OBSERVER_TEST_CP_FAULT_SOURCE" ]; then
    fault_count=$(cat "$OBSERVER_TEST_CP_FAULT_COUNT")
    if [ "$fault_count" -lt 2 ]; then
        fault_count=$((fault_count + 1))
        printf '# copy-race-generation-%s\n' "$fault_count" >>"$1"
        printf '%s\n' "$fault_count" >"$OBSERVER_TEST_CP_FAULT_COUNT"
        exit 1
    fi
    if [ "$fault_count" -eq 2 ]; then
        "$OBSERVER_TEST_REAL_CP" "$@"
        printf '# successful-copy-race\n' >>"$1"
        printf '3\n' >"$OBSERVER_TEST_CP_FAULT_COUNT"
        exit 0
    fi
fi

exec "$OBSERVER_TEST_REAL_CP" "$@"
EOF
chmod +x "$mock_bin/cp"

env \
    OBSERVER_COOLIFY_DATA_DIRECTORY="$coolify_data" \
    OBSERVER_RUNTIME_EVIDENCE_DIRECTORY="$runtime_evidence" \
    OBSERVER_TEST_CP_FAULT_COUNT="$cp_fault_count" \
    OBSERVER_TEST_CP_FAULT_SOURCE="$route" \
    OBSERVER_TEST_REAL_CP="$(command -v cp)" \
    PATH="$mock_bin:$PATH" \
    sh "$script_directory/observer.sh" >"$observer_log" 2>&1 &
observer_pid=$!

attempt=0
until [ -s "$runtime_evidence/observer-heartbeat" ]; do
    require_observer_running
    attempt=$((attempt + 1))
    [ "$attempt" -lt 100 ]
    sleep 0.02
done
assert_world_readable_evidence "$runtime_evidence/observer-heartbeat"

attempt=0
until [ "$(cat "$cp_fault_count")" -eq 3 ]; do
    require_observer_running
    attempt=$((attempt + 1))
    [ "$attempt" -lt 100 ]
    sleep 0.02
done

fault_heartbeat=$(cat "$runtime_evidence/observer-heartbeat")
attempt=0
while [ "$(cat "$runtime_evidence/observer-heartbeat")" = "$fault_heartbeat" ]; do
    require_observer_running
    attempt=$((attempt + 1))
    [ "$attempt" -lt 100 ]
    sleep 0.02
done
jq -s -e 'any(.[]; .present == true)' "$runtime_evidence/proxy-route.jsonl" >/dev/null

iteration=0
while [ "$iteration" -lt 200 ]; do
    route_replacement="$coolify_data/proxy/dynamic/route.$iteration"
    compose_replacement="$coolify_data/applications/fixture/compose.$iteration"
    printf 'http:\n  revision: %s\n' "$iteration" >"$route_replacement"
    printf 'services:\n  fixture:\n    image: fixture:%s\n' "$iteration" >"$compose_replacement"
    mv "$route_replacement" "$route"
    mv "$compose_replacement" "$compose"
    rm -f "$route" "$compose"
    iteration=$((iteration + 1))
done

require_observer_running
heartbeat_before=$(cat "$runtime_evidence/observer-heartbeat")
flush_token=observer-race-final
flush_request_snapshot="$runtime_evidence/observer-final-flush.request.$$"
printf '%s\n' "$flush_token" >"$flush_request_snapshot"
mv "$flush_request_snapshot" "$runtime_evidence/observer-final-flush.request"

attempt=0
until [ -s "$runtime_evidence/observer-final-flush.ack" ] \
    && [ "$(cat "$runtime_evidence/observer-final-flush.ack")" = "$flush_token" ]; do
    require_observer_running
    attempt=$((attempt + 1))
    [ "$attempt" -lt 200 ]
    sleep 0.02
done
assert_world_readable_evidence "$runtime_evidence/observer-final-flush.ack"

require_observer_running
attempt=0
while [ "$(cat "$runtime_evidence/observer-heartbeat")" = "$heartbeat_before" ]; do
    require_observer_running
    attempt=$((attempt + 1))
    [ "$attempt" -lt 100 ]
    sleep 0.02
done
jq -s -e 'last.present == false and last.finalFlush == true' \
    "$runtime_evidence/proxy-route.jsonl" >/dev/null
if grep -qE 'snapshot failed|exited unexpectedly' "$observer_log"; then
    print_observer_log
    exit 1
fi

printf 'APPLICATION_DEPLOYMENT_OBSERVER_TEST_PASS\n'
