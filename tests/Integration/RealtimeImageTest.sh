#!/bin/sh

set -eu

image="${PRODUCTION_IMAGE:?PRODUCTION_IMAGE is required}"
container="coolify-main-realtime-runtime-$$"
container_id=''
runtime_env=''

fail()
{
    printf 'MAIN_IMAGE_REALTIME_RUNTIME_FAILURE %s\n' "$1" >&2
    if [ -n "$container_id" ] && docker container inspect "$container_id" >/dev/null 2>&1; then
        docker logs "$container_id" >&2 2>/dev/null || true
    fi
    exit 1
}

cleanup()
{
    if [ -n "$container_id" ]; then
        docker rm --force "$container_id" >/dev/null 2>&1 || true
    fi
    if [ -n "$runtime_env" ]; then
        rm -f "$runtime_env"
    fi
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

runtime_env="$(mktemp)"

printf '%s\n' \
    'APP_ENV=staging' \
    'HORIZON_ENABLED=false' \
    'NIGHTWATCH_ENABLED=false' \
    'PUSHER_ENABLED=true' \
    'SCHEDULER_ENABLED=false' \
    'TERMINAL_ENABLED=true' \
    > "$runtime_env"
chmod 0444 "$runtime_env"

docker image inspect "$image" >/dev/null || fail 'the exact production image is absent'

created_container_id=''
if ! created_container_id="$(
    docker create --pull never --name "$container" \
    --env APP_DEBUG=false \
    --env APP_ENV=staging \
    --env APP_KEY=base64:8VEfVNVkXQ9mH2L33WBWNMF4eQ0BWD5CTzB8mIxcl+k= \
    --env APP_URL=http://localhost \
    --env BROADCAST_CONNECTION=log \
    --env CACHE_STORE=array \
    --env DB_CONNECTION=testing \
    --env DB_DATABASE=:memory: \
    --env HORIZON_ENABLED=false \
    --env LOG_CHANNEL=stderr \
    --env MIGRATION_ENABLED=false \
    --env NIGHTWATCH_ENABLED=false \
    --env PUSHER_APP_ID=coolify \
    --env PUSHER_APP_KEY=coolify-key \
    --env PUSHER_APP_SECRET=coolify-secret \
    --env PUSHER_BACKEND_PORT=6001 \
    --env PUSHER_ENABLED=true \
    --env PUSHER_PORT=6001 \
    --env PUSHER_SCHEME=http \
    --env QUEUE_CONNECTION=null \
    --env QUEUE_FAILED_DRIVER=null \
    --env REVERB_SCALING_ENABLED=false \
    --env REVERB_SERVER_HOST=0.0.0.0 \
    --env SCHEDULER_ENABLED=false \
    --env SEEDER_ENABLED=false \
    --env SELF_HOSTED=false \
    --env SESSION_DRIVER=array \
    --env TERMINAL_BACKEND_PORT=6002 \
    --env TERMINAL_ENABLED=true \
    "$image"
)"; then
    fail 'the exact production image could not be created'
fi
container_id="$created_container_id"

docker cp "$runtime_env" "${container_id}:/var/www/html/.env" \
    || fail 'the runtime environment could not be copied through the Docker API'
docker start "$container_id" >/dev/null \
    || fail 'the production image could not be started after runtime environment injection'

docker exec "$container_id" test -s /var/www/html/versions.json \
    || fail 'the production image does not contain the runtime version catalog'
docker exec "$container_id" jq -er '.coolify.v4.version | strings | select(length > 0)' /var/www/html/versions.json >/dev/null \
    || fail 'the production image runtime version catalog is invalid'

attempt=0
until docker exec "$container_id" curl --fail --silent --show-error http://127.0.0.1:6001/up >/dev/null \
    && docker exec "$container_id" curl --fail --silent --show-error http://127.0.0.1:6002/ready >/dev/null
do
    if [ "$(docker inspect --format '{{.State.Running}}' "$container_id" 2>/dev/null || true)" != true ]; then
        fail 'the production image exited before Reverb and terminal became ready'
    fi

    attempt=$((attempt + 1))
    if [ "$attempt" -ge 60 ]; then
        fail 'Reverb and terminal readiness endpoints did not become healthy'
    fi
    sleep 1
done

docker exec --workdir /terminal "$container_id" node --input-type=module -e '
    import WebSocket from "ws";

    const websocket = new WebSocket("ws://127.0.0.1:6001/app/coolify-key?protocol=7&client=js&version=8.4.0&flash=false");
    const timeout = setTimeout(() => process.exit(2), 5000);

    websocket.on("message", (payload) => {
        const message = JSON.parse(payload.toString());
        if (message.event === "pusher:connection_established") {
            clearTimeout(timeout);
            websocket.close();
            process.exit(0);
        }
    });
    websocket.on("unexpected-response", () => process.exit(3));
    websocket.on("error", () => process.exit(4));
' || fail 'the bundled Reverb server did not complete a real Pusher WebSocket handshake'

docker exec --workdir /terminal "$container_id" node --input-type=module -e '
    import pty from "node-pty";

    const expected = "coolify-main-image-node-pty-pass";
    let output = "";
    const terminal = pty.spawn("/bin/sh", ["-c", `printf "%s\\n" ${expected}`], {
        cols: 80,
        cwd: "/tmp",
        env: { ...process.env, TERM: "xterm-256color" },
        name: "xterm-256color",
        rows: 24,
    });
    const timeout = setTimeout(() => {
        terminal.kill();
        process.exit(2);
    }, 5000);

    terminal.onData((data) => {
        output += data;
    });
    terminal.onExit(({ exitCode }) => {
        clearTimeout(timeout);
        process.exit(exitCode === 0 && output.includes(expected) ? 0 : 3);
    });
' || fail 'the bundled node-pty addon could not spawn, echo, and exit cleanly'

docker stop --time 20 "$container_id" >/dev/null \
    || fail 'the production image did not stop within the s6 shutdown deadline'
[ "$(docker inspect --format '{{.State.ExitCode}}' "$container_id")" -eq 0 ] \
    || fail 'the production image did not complete a clean s6 shutdown'

printf 'MAIN_IMAGE_REALTIME_RUNTIME_PASS image=%s reverb=%s terminal=%s\n' \
    "$image" 6001 6002
