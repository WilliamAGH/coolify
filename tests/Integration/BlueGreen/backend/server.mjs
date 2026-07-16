import { createHash, randomUUID } from "node:crypto";
import { promises as fileSystem } from "node:fs";
import http from "node:http";
import path from "node:path";

const port = Number(process.env.PORT ?? 3000);
const revision = process.env.REVISION ?? "unknown";
const stateDirectory = process.env.STATE_DIRECTORY ?? "/state";
const isUnhealthy = process.env.START_UNHEALTHY === "1";
const websocketMagic = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";
const activeRequest = new Set();
const activeWebSocket = new Set();

await fileSystem.mkdir(stateDirectory, { recursive: true });

const pause = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

function log(event, fields = {}) {
  process.stdout.write(`${JSON.stringify({ event, revision, ...fields })}\n`);
}

function sendJson(response, status, payload) {
  const body = JSON.stringify(payload);
  response.writeHead(status, {
    "content-length": Buffer.byteLength(body),
    "content-type": "application/json; charset=utf-8",
  });
  response.end(body);
}

function boundedInteger(rawValue, fallback, minimum, maximum) {
  const parsed = Number.parseInt(rawValue ?? "", 10);
  return Number.isSafeInteger(parsed) && parsed >= minimum && parsed <= maximum ? parsed : fallback;
}

async function readJson(request) {
  const chunk = [];
  let length = 0;
  for await (const receivedChunk of request) {
    length += receivedChunk.length;
    if (length > 64 * 1024) {
      throw new Error("request body exceeds 64 KiB");
    }
    chunk.push(receivedChunk);
  }
  const rawBody = Buffer.concat(chunk).toString("utf8");
  return rawBody === "" ? {} : JSON.parse(rawBody);
}

function recordLocation(idempotencyKey) {
  return path.join(stateDirectory, createHash("sha256").update(idempotencyKey).digest("hex"));
}

function inFlightMarkerName(rawValue) {
  return /^[A-Za-z0-9_-]{1,128}$/.test(rawValue ?? "") ? rawValue : randomUUID();
}

async function markInFlight(markerName) {
  const inFlightDirectory = path.join(stateDirectory, "inflight");
  await fileSystem.mkdir(inFlightDirectory, { recursive: true });
  await fileSystem.writeFile(
    path.join(inFlightDirectory, `${markerName}.started`),
    JSON.stringify({ markerName, revision, startedAt: new Date().toISOString() }),
    "utf8",
  );
}

async function readTransaction(recordPath) {
  try {
    return JSON.parse(await fileSystem.readFile(`${recordPath}.json`, "utf8"));
  } catch (error) {
    if (error.code === "ENOENT") {
      return null;
    }
    throw error;
  }
}

async function waitForTransaction(recordPath) {
  for (let attempt = 0; attempt < 200; attempt += 1) {
    const transaction = await readTransaction(recordPath);
    if (transaction !== null) {
      return transaction;
    }
    await pause(25);
  }
  throw new Error("idempotency lock did not publish a transaction");
}

async function createOrReplayTransaction(idempotencyKey, requestBody) {
  const recordPath = recordLocation(idempotencyKey);
  const existing = await readTransaction(recordPath);
  if (existing !== null) {
    return { created: false, transaction: existing };
  }

  const lockPath = `${recordPath}.lock`;
  let lock;
  try {
    lock = await fileSystem.open(lockPath, "wx");
  } catch (error) {
    if (error.code === "EEXIST") {
      return { created: false, transaction: await waitForTransaction(recordPath) };
    }
    throw error;
  }

  try {
    const createdWhileWaiting = await readTransaction(recordPath);
    if (createdWhileWaiting !== null) {
      return { created: false, transaction: createdWhileWaiting };
    }

    const transaction = {
      createdByRevision: revision,
      idempotencyKey,
      payload: requestBody,
      transactionId: randomUUID(),
      writeCount: 1,
    };
    const temporaryPath = `${recordPath}.${randomUUID()}.tmp`;
    await fileSystem.writeFile(temporaryPath, JSON.stringify(transaction), "utf8");
    await fileSystem.rename(temporaryPath, `${recordPath}.json`);
    log("transaction-created", { transactionId: transaction.transactionId });
    return { created: true, transaction };
  } finally {
    await lock?.close();
    await fileSystem.rm(lockPath, { force: true });
  }
}

function websocketFrame(payload) {
  const encodedPayload = Buffer.from(payload);
  if (encodedPayload.length >= 126) {
    throw new Error("test websocket payload is unexpectedly large");
  }
  return Buffer.concat([Buffer.from([0x81, encodedPayload.length]), encodedPayload]);
}

const server = http.createServer(async (request, response) => {
  const requestId = randomUUID();
  activeRequest.add(requestId);
  let released = false;
  const release = () => {
    if (!released) {
      released = true;
      activeRequest.delete(requestId);
    }
  };
  response.once("close", release);
  response.once("finish", release);

  try {
    const url = new URL(request.url, `http://${request.headers.host ?? "localhost"}`);

    if (request.method === "GET" && url.pathname === "/health") {
      sendJson(response, isUnhealthy ? 503 : 200, { revision, healthy: !isUnhealthy });
      return;
    }

    if (request.method === "GET" && url.pathname === "/revision") {
      sendJson(response, 200, { revision });
      return;
    }

    if (request.method === "GET" && url.pathname === "/connections") {
      sendJson(response, 200, {
        activeRequest: Math.max(0, activeRequest.size - 1),
        activeWebSocket: activeWebSocket.size,
        revision,
      });
      return;
    }

    if (request.method === "GET" && url.pathname === "/delay") {
      const milliseconds = boundedInteger(url.searchParams.get("ms"), 1000, 1, 120_000);
      const requestId = inFlightMarkerName(url.searchParams.get("request"));
      log("delay-start", { milliseconds, requestId });
      await markInFlight(requestId);
      await pause(milliseconds);
      log("delay-complete", { requestId });
      sendJson(response, 200, { delayedForMilliseconds: milliseconds, requestId, revision });
      return;
    }

    if (request.method === "GET" && url.pathname === "/sse") {
      const eventCount = boundedInteger(url.searchParams.get("count"), 5, 1, 240);
      const intervalMilliseconds = boundedInteger(url.searchParams.get("interval"), 200, 10, 1000);
      const streamId = inFlightMarkerName(url.searchParams.get("stream"));
      log("sse-start", { streamId });
      await markInFlight(streamId);
      response.writeHead(200, {
        "cache-control": "no-cache",
        connection: "keep-alive",
        "content-type": "text/event-stream",
      });
      for (let sequence = 1; sequence <= eventCount; sequence += 1) {
        response.write(`event: revision\ndata: ${JSON.stringify({ revision, sequence, streamId })}\n\n`);
        await pause(intervalMilliseconds);
      }
      log("sse-complete", { streamId });
      response.end();
      return;
    }

    if (request.method === "POST" && url.pathname === "/transactions") {
      const idempotencyKey = request.headers["idempotency-key"];
      if (typeof idempotencyKey !== "string" || idempotencyKey.length === 0) {
        sendJson(response, 400, { error: "Idempotency-Key is required" });
        return;
      }
      const result = await createOrReplayTransaction(idempotencyKey, await readJson(request));
      sendJson(response, result.created ? 201 : 200, result);
      return;
    }

    if (request.method === "GET" && url.pathname.startsWith("/transactions/")) {
      const idempotencyKey = decodeURIComponent(url.pathname.slice("/transactions/".length));
      const transaction = await readTransaction(recordLocation(idempotencyKey));
      if (transaction === null) {
        sendJson(response, 404, { error: "transaction not found" });
        return;
      }
      sendJson(response, 200, { transaction });
      return;
    }

    sendJson(response, 404, { error: "not found", revision });
  } catch (error) {
    log("request-error", { message: error.message });
    sendJson(response, 500, { error: error.message, revision });
  }
});

server.on("upgrade", (request, socket) => {
  const url = new URL(request.url, `http://${request.headers.host ?? "localhost"}`);
  const websocketKey = request.headers["sec-websocket-key"];
  if (url.pathname !== "/ws" || typeof websocketKey !== "string") {
    socket.destroy();
    return;
  }
  const connectionId = randomUUID();
  activeWebSocket.add(connectionId);
  socket.once("close", () => activeWebSocket.delete(connectionId));
  const holdMilliseconds = boundedInteger(url.searchParams.get("hold"), 0, 0, 120_000);
  const accept = createHash("sha1").update(`${websocketKey}${websocketMagic}`).digest("base64");
  socket.write(
    [
      "HTTP/1.1 101 Switching Protocols",
      "Connection: Upgrade",
      "Upgrade: websocket",
      `Sec-WebSocket-Accept: ${accept}`,
      "",
      "",
    ].join("\r\n"),
  );
  socket.write(websocketFrame(JSON.stringify({ revision })));
  if (holdMilliseconds === 0) {
    socket.end();
    log("websocket-complete");
    return;
  }
  log("websocket-hold-start", { holdMilliseconds });
  setTimeout(() => {
    if (!socket.destroyed) {
      socket.end();
    }
    log("websocket-hold-complete", { holdMilliseconds });
  }, holdMilliseconds);
});

server.listen(port, "0.0.0.0", () => log("started", { port }));

process.once("SIGTERM", () => {
  log("graceful-shutdown-start", { activeRequest: activeRequest.size, activeWebSocket: activeWebSocket.size });
  server.close((error) => {
    if (error !== undefined) {
      log("graceful-shutdown-error", { message: error.message });
      process.exitCode = 1;
      return;
    }
    log("graceful-shutdown-complete");
  });
});
