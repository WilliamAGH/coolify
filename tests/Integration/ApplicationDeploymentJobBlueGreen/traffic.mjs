import { createHash, randomBytes } from 'node:crypto';
import { request } from 'node:http';
import { connect } from 'node:net';
import { writeFile } from 'node:fs/promises';

const host = process.env.TRAFFIC_HOST ?? 'application.test';
const expected = new Set([process.env.EXPECTED_FIRST_ACK, process.env.EXPECTED_SECOND_ACK]);
const firstAck = process.env.EXPECTED_FIRST_ACK;
const secondAck = process.env.EXPECTED_SECOND_ACK;
const evidence = '/evidence/traffic-summary.json';
const ready = '/evidence/traffic-ready';
const greenReady = '/evidence/traffic-green-ready';
const summary = { errors: [], heldHttp: null, http: [], sse: null, websocket: null, write: [] };

function fail(message) {
  summary.errors.push(message);
  throw new Error(message);
}

function http(path, { method = 'GET', headers = {}, timeout = 5000 } = {}) {
  return new Promise((resolve, reject) => {
    const startedAt = Date.now();
    const req = request({ host: 'coolify-proxy', port: 80, path, method, headers: { Host: host, ...headers } }, response => {
      const chunks = [];
      response.on('data', chunk => chunks.push(chunk));
      response.on('end', () => resolve({
        body: Buffer.concat(chunks).toString(),
        fixtureAck: response.headers['x-fixture-ack'] ?? null,
        proxyAck: response.headers['x-coolify-probe-ack'] ?? null,
        status: response.statusCode,
        startedAt,
        endedAt: Date.now(),
      }));
    });
    req.setTimeout(timeout, () => req.destroy(new Error(`timeout ${method} ${path}`)));
    req.on('error', reject);
    req.end();
  });
}

function assertResponse(response, context) {
  if (response.status !== 200) fail(`${context} status ${response.status}`);
  if (!expected.has(response.fixtureAck)) fail(`${context} unknown fixture acknowledgement`);
  if (typeof response.proxyAck !== 'string' || response.proxyAck.length < 32) {
    fail(`${context} missing opaque proxy acknowledgement`);
  }
}

function stream(path, kind) {
  let markStarted;
  const started = new Promise(resolve => { markStarted = resolve; });
  const finished = new Promise((resolve, reject) => {
    const startedAt = Date.now();
    const req = request({ host: 'coolify-proxy', port: 80, path, headers: { Host: host } }, response => {
      const chunks = [];
      const fixtureAck = response.headers['x-fixture-ack'] ?? null;
      markStarted({ fixtureAck, status: response.statusCode });
      response.on('data', chunk => chunks.push(chunk));
      response.on('end', () => resolve({
        body: Buffer.concat(chunks).toString(), fixtureAck, status: response.statusCode,
        startedAt, endedAt: Date.now(),
      }));
    });
    req.on('error', reject);
    req.setTimeout(100000, () => req.destroy(new Error(`${kind} timeout`)));
    req.end();
  });
  return { started, finished };
}

function websocket() {
  let markStarted;
  const started = new Promise(resolve => { markStarted = resolve; });
  const finished = new Promise((resolve, reject) => {
    const startedAt = Date.now();
    const key = randomBytes(16).toString('base64');
    const expectedAccept = createHash('sha1').update(`${key}258EAFA5-E914-47DA-95CA-C5AB0DC85B11`).digest('base64');
    const socket = connect(80, 'coolify-proxy');
    let buffer = Buffer.alloc(0);
    let fixtureAck = null;
    let handshake = false;
    let messageCount = 0;
    const timer = setTimeout(() => socket.destroy(new Error('websocket timeout')), 100000);
    socket.on('connect', () => socket.write([
      'GET /ws HTTP/1.1', `Host: ${host}`, 'Connection: Upgrade', 'Upgrade: websocket',
      `Sec-WebSocket-Key: ${key}`, 'Sec-WebSocket-Version: 13', '', '',
    ].join('\r\n')));
    socket.on('data', chunk => {
      buffer = Buffer.concat([buffer, chunk]);
      if (!handshake) {
        const end = buffer.indexOf('\r\n\r\n');
        if (end < 0) return;
        const headers = buffer.subarray(0, end).toString();
        if (!headers.startsWith('HTTP/1.1 101 ')) return socket.destroy(new Error('websocket handshake status'));
        if (!headers.toLowerCase().includes(`sec-websocket-accept: ${expectedAccept.toLowerCase()}`)) {
          return socket.destroy(new Error('websocket handshake acknowledgement'));
        }
        fixtureAck = /\r\nx-fixture-ack:\s*([^\r]+)/i.exec(headers)?.[1] ?? null;
        handshake = true;
        buffer = buffer.subarray(end + 4);
        markStarted({ fixtureAck, status: 101 });
      }
      while (buffer.length >= 2) {
        const length = buffer[1] & 0x7f;
        if (buffer.length < length + 2) break;
        const opcode = buffer[0] & 0x0f;
        const payload = buffer.subarray(2, length + 2);
        buffer = buffer.subarray(length + 2);
        if (opcode === 1) {
          if (payload.toString() !== fixtureAck) return socket.destroy(new Error('websocket acknowledgement changed'));
          messageCount += 1;
        }
        if (opcode === 8) socket.end();
      }
    });
    socket.on('error', reject);
    socket.on('close', hadError => {
      clearTimeout(timer);
      if (!hadError && handshake) resolve({ fixtureAck, messageCount, startedAt, endedAt: Date.now() });
    });
  });
  return { started, finished };
}

async function writeStage(acknowledgement) {
  const key = `saga-${acknowledgement}`;
  const stage = [];
  for (let attempt = 0; attempt < 5; attempt += 1) {
    const response = await http('/write', { method: 'POST', headers: { 'Idempotency-Key': key } });
    assertResponse(response, 'idempotent write');
    const body = JSON.parse(response.body);
    if (body.acknowledgement !== acknowledgement || body.keyCount !== 1) fail('idempotent write count drifted');
    stage.push({ acknowledgement: body.acknowledgement, applied: body.applied, keyCount: body.keyCount });
  }
  if (stage.filter(write => write.applied).length !== 1) fail('idempotent write was not applied exactly once');
  summary.write.push(stage);
}

try {
  let initial;
  do {
    initial = await http('/');
  } while (initial.status !== 200 || initial.fixtureAck !== firstAck);
  assertResponse(initial, 'initial route');
  await writeStage(firstAck);

  const held = stream('/hold?ms=75000', 'held HTTP');
  const sse = stream('/sse', 'SSE');
  const ws = websocket();
  const starts = await Promise.all([held.started, sse.started, ws.started]);
  if (starts.some(start => start.fixtureAck !== firstAck)) fail('long-lived connection did not originate on first target');
  await writeFile(ready, `${Date.now()}\n`);

  let sawSecond = false;
  const deadline = Date.now() + 45000;
  while (Date.now() < deadline && !sawSecond) {
    try {
      const response = await http('/');
      assertResponse(response, 'continuous route');
      summary.http.push({ fixtureAck: response.fixtureAck, proxyAck: response.proxyAck, status: response.status });
      sawSecond = response.fixtureAck === secondAck;
    } catch (error) {
      fail(`continuous route error: ${error.message}`);
    }
    await new Promise(resolve => setTimeout(resolve, 25));
  }
  if (!sawSecond) fail('second target was never observed through Traefik');
  await writeStage(secondAck);
  await writeFile(greenReady, `${Date.now()}\n`);
  [summary.heldHttp, summary.sse, summary.websocket] = await Promise.all([held.finished, sse.finished, ws.finished]);
  if (summary.heldHttp.fixtureAck !== firstAck || summary.heldHttp.status !== 200) fail('held HTTP did not drain on first target');
  if (summary.sse.fixtureAck !== firstAck || summary.sse.status !== 200 || !summary.sse.body.includes('event: complete')) {
    fail('SSE did not drain on first target');
  }
  if (summary.websocket.fixtureAck !== firstAck || summary.websocket.messageCount < 10) fail('WebSocket did not drain on first target');
  await writeFile(evidence, `${JSON.stringify(summary, null, 2)}\n`);
} catch (error) {
  summary.errors.push(error.stack ?? error.message);
  await writeFile(evidence, `${JSON.stringify(summary, null, 2)}\n`);
  process.exitCode = 1;
}
