#!/bin/sh

set -eu

script_directory=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
work_directory=$(mktemp -d "${TMPDIR:-/tmp}/application-deployment-observer-test.XXXXXX")
observer_pid=
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
install -d "$coolify_data/proxy/dynamic" "$coolify_data/applications/fixture" \
    "$runtime_evidence" "$mock_bin"

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

env \
    OBSERVER_COOLIFY_DATA_DIRECTORY="$coolify_data" \
    OBSERVER_RUNTIME_EVIDENCE_DIRECTORY="$runtime_evidence" \
    PATH="$mock_bin:$PATH" \
    sh "$script_directory/observer.sh" >"$work_directory/observer.log" 2>&1 &
observer_pid=$!

attempt=0
until [ -s "$runtime_evidence/observer-heartbeat" ]; do
    kill -0 "$observer_pid"
    attempt=$((attempt + 1))
    [ "$attempt" -lt 100 ]
    sleep 0.02
done

route="$coolify_data/proxy/dynamic/coolify-blue-green-race.yaml"
compose="$coolify_data/applications/fixture/docker-compose.yaml"
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

kill -0 "$observer_pid"
heartbeat_before=$(cat "$runtime_evidence/observer-heartbeat")
flush_token=observer-race-final
flush_request_snapshot="$runtime_evidence/observer-final-flush.request.$$"
printf '%s\n' "$flush_token" >"$flush_request_snapshot"
mv "$flush_request_snapshot" "$runtime_evidence/observer-final-flush.request"

attempt=0
until [ -s "$runtime_evidence/observer-final-flush.ack" ] \
    && [ "$(cat "$runtime_evidence/observer-final-flush.ack")" = "$flush_token" ]; do
    kill -0 "$observer_pid"
    attempt=$((attempt + 1))
    [ "$attempt" -lt 200 ]
    sleep 0.02
done

kill -0 "$observer_pid"
attempt=0
while [ "$(cat "$runtime_evidence/observer-heartbeat")" = "$heartbeat_before" ]; do
    kill -0 "$observer_pid"
    attempt=$((attempt + 1))
    [ "$attempt" -lt 100 ]
    sleep 0.02
done
jq -s -e 'last.present == false and last.finalFlush == true' \
    "$runtime_evidence/proxy-route.jsonl" >/dev/null
if grep -qE 'snapshot failed|exited unexpectedly' "$work_directory/observer.log"; then
    exit 1
fi

printf 'APPLICATION_DEPLOYMENT_OBSERVER_TEST_PASS\n'
