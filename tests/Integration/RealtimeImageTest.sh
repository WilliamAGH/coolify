#!/bin/sh

set -eu

image="${REALTIME_IMAGE:?REALTIME_IMAGE is required}"
container="coolify-realtime-runtime-$$"

fail()
{
    printf 'REALTIME_IMAGE_RUNTIME_FAILURE %s\n' "$1" >&2
    docker logs "$container" >&2 2>/dev/null || true
    exit 1
}

cleanup()
{
    docker rm --force "$container" >/dev/null 2>&1 || true
}

trap cleanup EXIT INT TERM

docker image inspect "$image" >/dev/null || fail 'the exact realtime image is absent'

docker run --rm --pull never --entrypoint node "$image" -e '
    const uWS = require("/app/node_modules/uWebSockets.js");
    if (process.versions.modules !== "137"
        || typeof uWS.App !== "function"
        || typeof uWS.SSLApp !== "function"
        || Object.hasOwn(uWS, "H3App")) {
        process.exit(1);
    }
    uWS.App();
    uWS.SSLApp({});
' || fail 'the patched Node 24 uWebSockets addon did not load App and SSLApp'

docker run --rm --pull never --entrypoint node "$image" -e '
    const pty = require("/terminal/node_modules/node-pty");
    const expected = "coolify-node-pty-runtime-pass";
    let output = "";
    const terminal = pty.spawn("/bin/sh", ["-c", `printf %s ${expected}`], {
        cols: 80,
        cwd: "/tmp",
        env: { TERM: "xterm-256color" },
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
' || fail 'the packaged node-pty addon could not spawn, echo, and exit cleanly'

docker run --detach --pull never --name "$container" \
    --env SOKETI_DEFAULT_APP_ID=coolify \
    --env SOKETI_DEFAULT_APP_KEY=coolify-key \
    --env SOKETI_DEFAULT_APP_SECRET=coolify-secret \
    --env SOKETI_HOST=0.0.0.0 \
    "$image" >/dev/null

attempt=0
until docker exec "$container" curl --fail --silent --show-error http://127.0.0.1:6001/ready >/dev/null \
    && docker exec "$container" curl --fail --silent --show-error http://127.0.0.1:6002/ready >/dev/null
do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 60 ]; then
        fail 'Soketi and terminal readiness endpoints did not become healthy'
    fi
    sleep 1
done

docker exec "$container" node -e '
    const WebSocket = require("/terminal/node_modules/ws");
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
    websocket.on("error", () => process.exit(3));
' || fail 'the real Pusher WebSocket handshake failed'

docker stop --time 15 "$container" >/dev/null || fail 'the realtime entrypoint did not stop within its signal deadline'
[ "$(docker inspect --format '{{.State.ExitCode}}' "$container")" -eq 0 ] \
    || fail 'the realtime entrypoint did not exit cleanly after SIGTERM'
docker logs "$container" 2>&1 | grep -F 'Forwarding signal TERM' >/dev/null \
    || fail 'the realtime entrypoint did not record signal forwarding'

printf 'REALTIME_IMAGE_RUNTIME_PASS image=%s node_modules=%s\n' "$image" 137
