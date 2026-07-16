import { createHash } from 'node:crypto';
import { createServer } from 'node:http';

const acknowledgement = createHash('sha256')
  .update(process.env.SOURCE_COMMIT ?? 'missing-source-commit')
  .digest('hex');
const idempotencyCount = new Map();
const sockets = new Set();

function json(response, body) {
  response.setHeader('Content-Type', 'application/json');
  response.end(JSON.stringify(body));
}

function websocketFrame(payload) {
  const body = Buffer.from(payload);
  return Buffer.concat([Buffer.from([0x81, body.length]), body]);
}

const server = createServer((request, response) => {
  const url = new URL(request.url ?? '/', 'http://fixture');
  if (request.method === 'GET' && url.pathname === '/hold') {
    const delay = Math.min(Number(url.searchParams.get('ms') ?? 75000), 120000);
    response.setHeader('Content-Type', 'application/json');
    response.setHeader('X-Fixture-Ack', acknowledgement);
    response.flushHeaders();
    setTimeout(() => response.end(JSON.stringify({ acknowledgement })), delay);
    return;
  }
  if (request.method === 'GET' && url.pathname === '/sse') {
    response.writeHead(200, {
      'Cache-Control': 'no-cache',
      Connection: 'keep-alive',
      'Content-Type': 'text/event-stream',
      'X-Fixture-Ack': acknowledgement,
    });
    const interval = setInterval(() => response.write(`data: ${acknowledgement}\n\n`), 250);
    setTimeout(() => {
      clearInterval(interval);
      response.end(`event: complete\ndata: ${acknowledgement}\n\n`);
    }, 75000);
    return;
  }
  if (request.method === 'POST' && url.pathname === '/write') {
    const key = request.headers['idempotency-key'];
    if (typeof key !== 'string' || key.length < 16) {
      response.statusCode = 400;
      json(response, { error: 'invalid idempotency key' });
      return;
    }
    const previous = idempotencyCount.get(key) ?? 0;
    const applied = previous === 0;
    idempotencyCount.set(key, applied ? 1 : previous);
    const result = { acknowledgement, applied, keyCount: idempotencyCount.get(key) };
    process.stdout.write(`${JSON.stringify({ event: 'idempotent-write', ...result })}\n`);
    response.setHeader('X-Fixture-Ack', acknowledgement);
    json(response, result);
    return;
  }
  response.setHeader('X-Fixture-Ack', acknowledgement);
  json(response, { acknowledgement });
});

server.on('upgrade', (request, socket) => {
  if (request.url !== '/ws' || request.headers.upgrade?.toLowerCase() !== 'websocket') {
    socket.destroy();
    return;
  }
  const key = request.headers['sec-websocket-key'];
  const accept = createHash('sha1')
    .update(`${key}258EAFA5-E914-47DA-95CA-C5AB0DC85B11`)
    .digest('base64');
  socket.write([
    'HTTP/1.1 101 Switching Protocols',
    'Connection: Upgrade',
    'Upgrade: websocket',
    `Sec-WebSocket-Accept: ${accept}`,
    `X-Fixture-Ack: ${acknowledgement}`,
    '',
    '',
  ].join('\r\n'));
  sockets.add(socket);
  const interval = setInterval(() => socket.write(websocketFrame(acknowledgement)), 250);
  setTimeout(() => {
    clearInterval(interval);
    socket.end(Buffer.from([0x88, 0x02, 0x03, 0xe8]));
  }, 75000);
  socket.on('close', () => {
    clearInterval(interval);
    sockets.delete(socket);
  });
});

server.listen(3000);
process.on('SIGTERM', () => process.exit(0));
