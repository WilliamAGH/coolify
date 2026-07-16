import { randomBytes, randomUUID } from "node:crypto";
import net from "node:net";

const rawDuration = process.argv[2] ?? "45000";
const durationMilliseconds = Number.parseInt(rawDuration, 10);
const allowedRevision = new Set(["legacy", "candidate"]);
const deadline = Date.now() + durationMilliseconds;
const result = {
  durationMilliseconds,
  failures: [],
  held: 0,
  http: 0,
  revision: {},
  sse: 0,
  sseEvent: 0,
  websocket: 0,
  write: 0,
};

if (!Number.isSafeInteger(durationMilliseconds) || durationMilliseconds < 10_000 || durationMilliseconds > 120_000) {
  throw new Error("traffic duration must be between 10000 and 120000 milliseconds");
}

const sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

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
    throw new Error(`non-JSON HTTP ${response.status}: ${error.message}`);
  }

  return { payload, response };
}

function assertRevision(revision) {
  if (!allowedRevision.has(revision)) {
    throw new Error(`unexpected backend revision: ${revision ?? "missing"}`);
  }
  result.revision[revision] = (result.revision[revision] ?? 0) + 1;
}

function recordFailure(protocol, error) {
  result.failures.push(`${protocol}: ${error instanceof Error ? error.message : String(error)}`);
}

async function httpWorker() {
  while (Date.now() < deadline) {
    try {
      const response = await requestJson("http://traefik/revision");
      if (response.response.status !== 200) {
        throw new Error(`HTTP ${response.response.status}`);
      }
      assertRevision(response.payload.revision);
      result.http += 1;
    } catch (error) {
      recordFailure("http", error);
    }
    await sleep(10);
  }
}

async function writeWorker() {
  while (Date.now() < deadline) {
    try {
      const idempotencyKey = `legacy-fencing-${randomUUID()}`;
      const response = await requestJson("http://traefik/transactions", {
        body: JSON.stringify({ idempotencyKey }),
        headers: {
          "content-type": "application/json",
          "idempotency-key": idempotencyKey,
        },
        method: "POST",
      });
      if (response.response.status !== 201 || response.payload.created !== true) {
        throw new Error(`unexpected write response HTTP ${response.response.status}`);
      }
      assertRevision(response.payload.transaction?.createdByRevision);
      result.write += 1;
    } catch (error) {
      recordFailure("write", error);
    }
    await sleep(20);
  }
}

async function sseWorker() {
  while (Date.now() < deadline) {
    try {
      const stream = randomUUID();
      const response = await request(`http://traefik/sse?count=5&interval=100&stream=${stream}`);
      if (response.status !== 200) {
        throw new Error(`HTTP ${response.status}`);
      }
      const event = (await response.text())
        .split("\n")
        .filter((line) => line.startsWith("data: "))
        .map((line) => JSON.parse(line.slice("data: ".length)));
      if (event.length !== 5) {
        throw new Error(`expected 5 events, received ${event.length}`);
      }
      event.forEach((entry) => assertRevision(entry.revision));
      result.sse += 1;
      result.sseEvent += event.length;
    } catch (error) {
      recordFailure("sse", error);
    }
  }
}

function websocketPayload() {
  const key = randomBytes(16).toString("base64");

  return new Promise((resolve, reject) => {
    let received = Buffer.alloc(0);
    const socket = net.createConnection({ host: "traefik", port: 80 });
    const fail = (error) => {
      socket.destroy();
      reject(error instanceof Error ? error : new Error(String(error)));
    };
    socket.setTimeout(5_000, () => fail("websocket timed out"));
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
      if (!header.startsWith("HTTP/1.1 101")) {
        fail(`upgrade failed: ${header.split("\r\n", 1)[0]}`);
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
      socket.end();
      try {
        resolve(JSON.parse(frame.subarray(2, length + 2).toString("utf8")));
      } catch (error) {
        fail(error);
      }
    });
  });
}

async function websocketWorker() {
  while (Date.now() < deadline) {
    try {
      const payload = await websocketPayload();
      assertRevision(payload.revision);
      result.websocket += 1;
    } catch (error) {
      recordFailure("websocket", error);
    }
    await sleep(20);
  }
}

async function heldWorker() {
  while (Date.now() < deadline) {
    try {
      const requestId = randomUUID();
      const response = await requestJson(`http://traefik/delay?ms=2500&request=${requestId}`, {}, 5_000);
      if (response.response.status !== 200 || response.payload.requestId !== requestId) {
        throw new Error(`unexpected held response HTTP ${response.response.status}`);
      }
      assertRevision(response.payload.revision);
      result.held += 1;
    } catch (error) {
      recordFailure("held", error);
    }
  }
}

process.stdout.write(`${JSON.stringify({ event: "traffic-started", durationMilliseconds })}\n`);
await Promise.all([
  httpWorker(),
  httpWorker(),
  httpWorker(),
  httpWorker(),
  writeWorker(),
  sseWorker(),
  websocketWorker(),
  heldWorker(),
  heldWorker(),
]);

const minimum = { held: 5, http: 100, sse: 5, websocket: 10, write: 10 };
for (const [protocol, count] of Object.entries(minimum)) {
  if (result[protocol] < count) {
    result.failures.push(`${protocol}: expected at least ${count} successes, received ${result[protocol]}`);
  }
}
process.stdout.write(`${JSON.stringify({ ...result, failures: result.failures.slice(0, 50) })}\n`);
if (result.failures.length > 0) {
  throw new Error(`continuous traffic recorded ${result.failures.length} failure(s)`);
}
