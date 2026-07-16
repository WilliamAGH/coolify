import { randomBytes } from "node:crypto";
import { mkdir, writeFile } from "node:fs/promises";
import net from "node:net";
import path from "node:path";

const [mode, marker, expectedRevision, rawDurationMilliseconds] = process.argv.slice(2);
const stateDirectory = process.env.STATE_DIRECTORY ?? "/state";
const eventIntervalMilliseconds = 500;

function fail(message) {
  throw new Error(message);
}

function positiveInteger(rawValue, fieldName, maximum) {
  const value = Number.parseInt(rawValue ?? "", 10);
  if (!Number.isSafeInteger(value) || value < 1 || value > maximum) {
    fail(`${fieldName} must be an integer from 1 to ${maximum}`);
  }
  return value;
}

if (!/^[A-Za-z0-9_]{1,128}$/u.test(marker ?? "")) {
  fail("marker must use only letters, numbers, and underscores");
}
if (expectedRevision === undefined) {
  fail("expected revision is required");
}

const durationMilliseconds = positiveInteger(rawDurationMilliseconds, "hold duration", 120_000);

function output(proof) {
  process.stdout.write(`${JSON.stringify({ durationMilliseconds, marker, mode, proof, timestamp: new Date().toISOString() })}\n`);
}

async function request(url) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), durationMilliseconds + 15_000);

  try {
    return await fetch(url, { redirect: "manual", signal: controller.signal });
  } finally {
    clearTimeout(timer);
  }
}

async function responseJson(response, operation) {
  const body = await response.text();
  if (response.status !== 200) {
    fail(`${operation} returned HTTP ${response.status}: ${body}`);
  }
  try {
    return JSON.parse(body);
  } catch (error) {
    fail(`${operation} returned invalid JSON: ${error.message}`);
  }
}

function assertRevision(payload, operation) {
  if (payload.revision !== expectedRevision) {
    fail(`${operation} reached ${payload.revision ?? "missing"}, expected ${expectedRevision}`);
  }
}

async function holdDelay() {
  const response = await request(`http://traefik/delay?ms=${durationMilliseconds}&request=${encodeURIComponent(marker)}`);
  const payload = await responseJson(response, "held HTTP request");
  assertRevision(payload, "held HTTP request");
  if (payload.requestId !== marker || payload.delayedForMilliseconds !== durationMilliseconds) {
    fail("held HTTP request returned an unexpected marker or duration");
  }
  return { revision: payload.revision, status: response.status };
}

async function holdSse() {
  const eventCount = Math.ceil(durationMilliseconds / eventIntervalMilliseconds);
  const response = await request(
    `http://traefik/sse?count=${eventCount}&interval=${eventIntervalMilliseconds}&stream=${encodeURIComponent(marker)}`,
  );
  if (response.status !== 200) {
    fail(`held SSE stream returned HTTP ${response.status}`);
  }
  const events = (await response.text())
    .split("\n")
    .filter((line) => line.startsWith("data: "))
    .map((line) => JSON.parse(line.slice("data: ".length)));
  if (events.length !== eventCount) {
    fail(`held SSE stream returned ${events.length} events, expected ${eventCount}`);
  }
  events.forEach((event, index) => {
    assertRevision(event, "held SSE stream");
    if (event.sequence !== index + 1 || event.streamId !== marker) {
      fail("held SSE stream returned an unexpected marker or sequence");
    }
  });
  return { eventCount, revision: expectedRevision, status: response.status };
}

async function markWebSocketReady() {
  const markerPath = path.join(stateDirectory, "inflight", `${marker}.started`);
  await mkdir(path.dirname(markerPath), { recursive: true });
  await writeFile(markerPath, JSON.stringify({ marker, revision: expectedRevision, startedAt: new Date().toISOString() }), "utf8");
  return markerPath;
}

function readWebSocketPayload(received) {
  const headerEnd = received.indexOf("\r\n\r\n");
  if (headerEnd === -1) {
    return null;
  }
  const header = received.subarray(0, headerEnd).toString("utf8");
  const status = Number.parseInt(header.match(/^HTTP\/1\.1\s+(\d{3})/u)?.[1] ?? "0", 10);
  if (status !== 101) {
    fail(`held WebSocket upgrade returned HTTP ${status || "unknown"}`);
  }
  const frame = received.subarray(headerEnd + 4);
  if (frame.length < 2 || (frame[0] & 0x0f) !== 0x1 || (frame[1] & 0x80) !== 0) {
    return null;
  }
  const length = frame[1] & 0x7f;
  if (length >= 126 || frame.length < length + 2) {
    return null;
  }
  try {
    return JSON.parse(frame.subarray(2, length + 2).toString("utf8"));
  } catch (error) {
    fail(`held WebSocket returned invalid JSON: ${error.message}`);
  }
}

function holdWebSocket() {
  return new Promise((resolve, reject) => {
    const key = randomBytes(16).toString("base64");
    const socket = net.createConnection({ host: "traefik", port: 80 });
    let received = Buffer.alloc(0);
    let settled = false;
    let markerPromise;
    let openedAt;

    const settle = (callback, value) => {
      if (settled) {
        return;
      }
      settled = true;
      socket.destroy();
      callback(value);
    };
    const rejectWith = (error) => settle(reject, error instanceof Error ? error : new Error(String(error)));
    const resolveAfterMarker = () => {
      if (markerPromise === undefined) {
        rejectWith(new Error("held WebSocket closed before its first revision frame"));
        return;
      }
      const heldForMilliseconds = Date.now() - openedAt;
      if (heldForMilliseconds < durationMilliseconds - 500) {
        rejectWith(new Error(`held WebSocket ended after ${heldForMilliseconds}ms, before ${durationMilliseconds}ms`));
        return;
      }
      markerPromise.then(
        (markerPath) => settle(resolve, { heldForMilliseconds, markerPath, revision: expectedRevision, status: 101 }),
        rejectWith,
      );
    };

    socket.setTimeout(durationMilliseconds + 15_000, () => rejectWith(new Error("held WebSocket timed out")));
    socket.on("error", rejectWith);
    socket.on("connect", () => {
      socket.write([
        `GET /ws?hold=${durationMilliseconds} HTTP/1.1`,
        "Host: traefik",
        "Connection: Upgrade",
        "Upgrade: websocket",
        "Sec-WebSocket-Version: 13",
        `Sec-WebSocket-Key: ${key}`,
        "",
        "",
      ].join("\r\n"));
    });
    socket.on("data", (chunk) => {
      if (markerPromise !== undefined) {
        return;
      }
      received = Buffer.concat([received, chunk]);
      try {
        const payload = readWebSocketPayload(received);
        if (payload === null) {
          return;
        }
        assertRevision(payload, "held WebSocket");
        openedAt = Date.now();
        markerPromise = markWebSocketReady();
      } catch (error) {
        rejectWith(error);
      }
    });
    socket.on("end", resolveAfterMarker);
    socket.on("close", (hadError) => {
      if (settled) {
        return;
      }
      if (hadError) {
        rejectWith(new Error("held WebSocket closed with a socket error"));
        return;
      }
      resolveAfterMarker();
    });
  });
}

const actions = {
  delay: holdDelay,
  sse: holdSse,
  websocket: holdWebSocket,
};

try {
  const action = actions[mode];
  if (action === undefined) {
    fail(`unsupported held-traffic mode: ${mode ?? "missing"}`);
  }
  output(await action());
} catch (error) {
  process.stderr.write(`BLUEGREEN_HELD_TRAFFIC_FAILURE ${error.message}\n`);
  process.exitCode = 1;
}
