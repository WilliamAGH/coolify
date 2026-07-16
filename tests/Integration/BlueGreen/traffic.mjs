import { randomBytes, randomUUID } from "node:crypto";
import { existsSync } from "node:fs";
import net from "node:net";

const durationMilliseconds = Number.parseInt(process.argv[2] ?? "120000", 10);
const stopFile = process.argv[3] ?? "";
const allowedRevision = new Set(["legacy", "candidate"]);

if (!Number.isSafeInteger(durationMilliseconds) || durationMilliseconds < 20_000 || durationMilliseconds > 180_000) {
  throw new Error("traffic duration must be between 20000 and 180000 milliseconds");
}

class HttpStatusError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

function protocolCounter() {
  return { attempted: 0, completed: 0, status: {}, success: 0 };
}

const result = {
  durationMilliseconds,
  failures: [],
  protocols: {
    get: protocolCounter(),
    held: protocolCounter(),
    sse: protocolCounter(),
    websocket: protocolCounter(),
    write: protocolCounter(),
  },
  requests: { attempted: 0, completed: 0, status: {} },
  revision: {},
  stopFile: stopFile || null,
};
const deadline = Date.now() + durationMilliseconds;

function sleep(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

function shouldContinue() {
  return Date.now() < deadline && (stopFile === "" || !existsSync(stopFile));
}

function startRequest(protocol) {
  result.protocols[protocol].attempted += 1;
  result.requests.attempted += 1;
}

function recordStatus(protocol, status) {
  const key = String(status);
  result.protocols[protocol].completed += 1;
  result.protocols[protocol].status[key] = (result.protocols[protocol].status[key] ?? 0) + 1;
  result.requests.completed += 1;
  result.requests.status[key] = (result.requests.status[key] ?? 0) + 1;
}

function recordSuccess(protocol) {
  result.protocols[protocol].success += 1;
}

function recordRevision(revision) {
  if (!allowedRevision.has(revision)) {
    throw new Error(`unexpected backend revision: ${revision ?? "missing"}`);
  }
  result.revision[revision] = (result.revision[revision] ?? 0) + 1;
}

function recordFailure(protocol, error) {
  const message = error instanceof Error ? error.message : String(error);
  result.failures.push(`${protocol}: ${message}`);
}

async function request(url, init = {}, timeoutMilliseconds = 5_000) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMilliseconds);

  try {
    return await fetch(url, { ...init, redirect: "manual", signal: controller.signal });
  } finally {
    clearTimeout(timer);
  }
}

async function requestJson(url, init = {}, timeoutMilliseconds = 5_000) {
  const response = await request(url, init, timeoutMilliseconds);
  const body = await response.text();
  let payload;

  try {
    payload = JSON.parse(body);
  } catch (error) {
    return { parseError: new Error(`non-JSON HTTP ${response.status}: ${error.message}`), payload: null, response };
  }

  return { parseError: null, payload, response };
}

function expectStatus(response, expectedStatus) {
  if (response.status !== expectedStatus) {
    throw new HttpStatusError(response.status, `expected HTTP ${expectedStatus}, received HTTP ${response.status}`);
  }
}

async function getWorker() {
  while (shouldContinue()) {
    startRequest("get");
    try {
      const response = await requestJson("http://traefik/revision");
      recordStatus("get", response.response.status);
      if (response.parseError !== null) {
        throw response.parseError;
      }
      expectStatus(response.response, 200);
      recordRevision(response.payload.revision);
      recordSuccess("get");
    } catch (error) {
      recordFailure("get", error);
    }
    await sleep(40);
  }
}

async function idempotentWriteWorker() {
  while (shouldContinue()) {
    const idempotencyKey = `bluegreen-provider-${randomUUID()}`;
    let createdTransaction;

    startRequest("write");
    try {
      const created = await requestJson("http://traefik/transactions", {
        body: JSON.stringify({ idempotencyKey }),
        headers: {
          "content-type": "application/json",
          "idempotency-key": idempotencyKey,
        },
        method: "POST",
      });
      recordStatus("write", created.response.status);
      if (created.parseError !== null) {
        throw created.parseError;
      }
      expectStatus(created.response, 201);
      if (created.payload.created !== true || created.payload.transaction?.writeCount !== 1) {
        throw new Error("idempotent create response did not create one transaction");
      }
      recordRevision(created.payload.transaction.createdByRevision);
      createdTransaction = created.payload.transaction;
    } catch (error) {
      recordFailure("write-create", error);
      await sleep(50);
      continue;
    }

    startRequest("write");
    try {
      const replay = await requestJson("http://traefik/transactions", {
        body: JSON.stringify({ idempotencyKey }),
        headers: {
          "content-type": "application/json",
          "idempotency-key": idempotencyKey,
        },
        method: "POST",
      });
      recordStatus("write", replay.response.status);
      if (replay.parseError !== null) {
        throw replay.parseError;
      }
      expectStatus(replay.response, 200);
      if (replay.payload.created !== false
        || replay.payload.transaction?.transactionId !== createdTransaction.transactionId
        || replay.payload.transaction?.createdByRevision !== createdTransaction.createdByRevision
        || replay.payload.transaction?.writeCount !== 1) {
        throw new Error("idempotent replay did not return the original transaction exactly once");
      }
      recordRevision(replay.payload.transaction.createdByRevision);
      recordSuccess("write");
    } catch (error) {
      recordFailure("write-replay", error);
    }
    await sleep(40);
  }
}

async function sseWorker() {
  while (shouldContinue()) {
    const stream = randomUUID();
    startRequest("sse");
    try {
      const response = await request(`http://traefik/sse?count=5&interval=100&stream=${stream}`);
      recordStatus("sse", response.status);
      expectStatus(response, 200);
      const event = (await response.text())
        .split("\n")
        .filter((line) => line.startsWith("data: "))
        .map((line) => JSON.parse(line.slice("data: ".length)));
      if (event.length !== 5 || event.some((entry) => entry.streamId !== stream)) {
        throw new Error(`SSE stream ${stream} did not contain its five expected events`);
      }
      event.forEach((entry) => recordRevision(entry.revision));
      recordSuccess("sse");
    } catch (error) {
      recordFailure("sse", error);
    }
  }
}

function websocketPayload() {
  const key = randomBytes(16).toString("base64");

  return new Promise((resolve, reject) => {
    let received = Buffer.alloc(0);
    let settled = false;
    const socket = net.createConnection({ host: "traefik", port: 80 });

    const settle = (callback, value) => {
      if (settled) {
        return;
      }
      settled = true;
      socket.destroy();
      callback(value);
    };
    const fail = (error) => settle(reject, error instanceof Error ? error : new Error(String(error)));

    socket.setTimeout(5_000, () => fail(new Error("websocket timed out")));
    socket.on("error", fail);
    socket.on("connect", () => {
      socket.write([
        "GET /ws HTTP/1.1",
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
      received = Buffer.concat([received, chunk]);
      const headerEnd = received.indexOf("\r\n\r\n");
      if (headerEnd === -1) {
        return;
      }

      const header = received.subarray(0, headerEnd).toString("utf8");
      const status = Number.parseInt(header.match(/^HTTP\/1\.1\s+(\d{3})/u)?.[1] ?? "0", 10);
      if (status !== 101) {
        fail(new HttpStatusError(status, `websocket upgrade returned HTTP ${status || "unknown"}`));
        return;
      }

      const frame = received.subarray(headerEnd + 4);
      if (frame.length < 2 || (frame[0] & 0x0f) !== 0x1 || (frame[1] & 0x80) !== 0) {
        return;
      }
      const length = frame[1] & 0x7f;
      if (length >= 126 || frame.length < length + 2) {
        return;
      }

      try {
        settle(resolve, { payload: JSON.parse(frame.subarray(2, length + 2).toString("utf8")), status });
      } catch (error) {
        fail(error);
      }
    });
  });
}

async function websocketWorker() {
  while (shouldContinue()) {
    startRequest("websocket");
    try {
      const websocket = await websocketPayload();
      recordStatus("websocket", websocket.status);
      recordRevision(websocket.payload.revision);
      recordSuccess("websocket");
    } catch (error) {
      if (error instanceof HttpStatusError) {
        recordStatus("websocket", error.status);
      }
      recordFailure("websocket", error);
    }
    await sleep(40);
  }
}

async function heldWorker() {
  while (shouldContinue()) {
    const requestId = randomUUID();
    startRequest("held");
    try {
      const response = await requestJson(`http://traefik/delay?ms=2500&request=${requestId}`, {}, 5_000);
      recordStatus("held", response.response.status);
      if (response.parseError !== null) {
        throw response.parseError;
      }
      expectStatus(response.response, 200);
      if (response.payload.requestId !== requestId) {
        throw new Error(`held request ${requestId} returned a different request id`);
      }
      recordRevision(response.payload.revision);
      recordSuccess("held");
    } catch (error) {
      recordFailure("held", error);
    }
  }
}

function addMinimumFailure(protocol, minimum) {
  const success = result.protocols[protocol].success;
  if (success < minimum) {
    result.failures.push(`${protocol}: expected at least ${minimum} successes, received ${success}`);
  }
}

function assertNoForbiddenStatus() {
  for (const [status, count] of Object.entries(result.requests.status)) {
    if (status === "404" || status.startsWith("5")) {
      result.failures.push(`forbidden HTTP ${status} observed ${count} time(s)`);
    }
  }
}

process.stdout.write(`${JSON.stringify({ event: "traffic-started", durationMilliseconds, stopFile: stopFile || null })}\n`);
await Promise.all([
  getWorker(),
  getWorker(),
  getWorker(),
  heldWorker(),
  idempotentWriteWorker(),
  sseWorker(),
  websocketWorker(),
]);

addMinimumFailure("get", 100);
addMinimumFailure("held", 3);
addMinimumFailure("sse", 4);
addMinimumFailure("websocket", 8);
addMinimumFailure("write", 8);
assertNoForbiddenStatus();
process.stdout.write(`${JSON.stringify({ event: "traffic-complete", ...result, failures: result.failures.slice(0, 100) })}\n`);

if (result.failures.length > 0) {
  process.stderr.write(`BLUEGREEN_TRAFFIC_FAILURE ${result.failures.length} failure(s)\n`);
  process.exitCode = 1;
}
