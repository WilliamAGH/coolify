'use strict';

const crypto = require('crypto');
const fs = require('fs');
const http = require('http');

const applicationStateDirectory = process.env.APPLICATION_STATE_DIRECTORY;
const stateDirectory = process.env.STATE_DIRECTORY;
const privateHeader = 'x-coolify-control-plane-health-proof';
const authenticationProofHeader = 'x-coolify-control-plane-authentication-proof';
const healthProof = process.env.CONTROL_PLANE_HEALTH_PROOF;
const instance = process.env.BACKEND_INSTANCE;
const providerHost = 'control-plane.test';
const gracefulShutdownTimeoutMilliseconds = 35_000;
const maximumTransactionBodyBytes = 65_536;
const transactionLockRetryMilliseconds = 25;
const transactionLockTimeoutMilliseconds = 10_000;
const transactionReleasePollMilliseconds = 25;
const transactionReleaseTimeoutMilliseconds = 60_000;
const transportEventIntervalMilliseconds = 100;
const activeHeldConnections = new Set();
let serverClosed = false;
let shuttingDown = false;

for (const directory of ['locks', 'releases', 'transactions']) {
    const path = `${applicationStateDirectory}/${directory}`;
    fs.mkdirSync(path, {recursive: true});
    fs.chmodSync(path, 0o777);
}

function append(filename, values) {
    fs.appendFileSync(`${stateDirectory}/${filename}`, [String(Date.now()), ...values].join('|') + '\n');
}

function fsyncDirectory(directory) {
    const descriptor = fs.openSync(directory, 'r');

    try {
        fs.fsyncSync(descriptor);
    } finally {
        fs.closeSync(descriptor);
    }
}

function durableAppend(filename, value) {
    const descriptor = fs.openSync(`${applicationStateDirectory}/${filename}`, 'a', 0o644);

    try {
        fs.writeSync(descriptor, `${value}\n`);
        fs.fsyncSync(descriptor);
    } finally {
        fs.closeSync(descriptor);
    }
}

function durableReplace(filename, value) {
    const directory = `${applicationStateDirectory}/transactions`;
    const stagedPath = `${directory}/.transaction-${process.pid}-${crypto.randomUUID()}`;

    fs.writeFileSync(stagedPath, `${JSON.stringify(value)}\n`, {flag: 'wx', mode: 0o644});
    const descriptor = fs.openSync(stagedPath, 'r');
    try {
        fs.fsyncSync(descriptor);
    } finally {
        fs.closeSync(descriptor);
    }
    fs.renameSync(stagedPath, filename);
    fsyncDirectory(directory);
}

function registerHeldConnection() {
    const token = Symbol('held-connection');
    let released = false;

    activeHeldConnections.add(token);

    return () => {
        if (released) {
            return;
        }
        released = true;
        activeHeldConnections.delete(token);
        finishGracefulShutdownWhenDrained();
    };
}

function finishGracefulShutdownWhenDrained() {
    if (shuttingDown && serverClosed && activeHeldConnections.size === 0) {
        process.exit(0);
    }
}

function isHealthy() {
    return fs.existsSync(`${stateDirectory}/healthy`);
}

function identity() {
    const [color, generation, dynamicSha] = fs.readFileSync(`${stateDirectory}/identity`, 'utf8').trim().split('|');

    return {color, generation, dynamicSha};
}

function responseHeaders(current = identity()) {
    return {
        'X-Coolify-Control-Plane-Dynamic-Sha': current.dynamicSha,
        'X-Integration-Backend': instance,
        'X-Backend-Color': current.color,
        'X-Backend-Generation': current.generation,
    };
}

function recordRequest(requestPath, request) {
    append('route.log', [instance, requestPath]);
    append('forwarded-identity.log', [
        request.headers.host || '',
        request.headers[authenticationProofHeader] || '',
        request.headers['x-forwarded-for'] || '',
    ]);
}

function transportEvent(transport, sequence, connectionId, current) {
    return {
        backend: instance,
        color: current.color,
        connectionId,
        dynamicSha: current.dynamicSha,
        emittedAt: Date.now(),
        generation: current.generation,
        sequence,
        transport,
    };
}

function streamTransportEvents(write, transport, current) {
    const connectionId = crypto.randomUUID();
    let sequence = 0;
    const interval = setInterval(() => {
        sequence += 1;
        write(transportEvent(transport, sequence, connectionId, current));
    }, transportEventIntervalMilliseconds);

    return () => clearInterval(interval);
}

function respondAfterRelease(response, releaseName, sendResponse) {
    if (releaseName === null) {
        sendResponse();

        return;
    }

    const releasePath = `${applicationStateDirectory}/releases/${releaseName}`;
    const releaseHeldConnection = registerHeldConnection();
    const deadline = Date.now() + transactionReleaseTimeoutMilliseconds;
    let completed = false;
    let interval;

    const complete = (shouldRespond) => {
        if (completed) {
            return;
        }
        completed = true;
        clearInterval(interval);
        releaseHeldConnection();
        if (shouldRespond && !response.destroyed) {
            sendResponse();
        }
    };

    interval = setInterval(() => {
        if (fs.existsSync(releasePath)) {
            complete(true);

            return;
        }
        if (Date.now() >= deadline) {
            complete(false);
            if (!response.destroyed) {
                response.writeHead(504, responseHeaders());
                response.end();
            }
        }
    }, transactionReleasePollMilliseconds);
    response.once('close', () => complete(false));
}

function createOrReplayTransaction(idempotencyKey, payload, startedAt, callback) {
    const idempotencyHash = crypto.createHash('sha256').update(idempotencyKey).digest('hex');
    const lockPath = `${applicationStateDirectory}/locks/${idempotencyHash}`;
    const transactionPath = `${applicationStateDirectory}/transactions/${idempotencyHash}.json`;

    try {
        fs.mkdirSync(lockPath);
    } catch (error) {
        if (error.code === 'EEXIST' && Date.now() - startedAt < transactionLockTimeoutMilliseconds) {
            setTimeout(
                () => createOrReplayTransaction(idempotencyKey, payload, startedAt, callback),
                transactionLockRetryMilliseconds,
            );

            return;
        }
        callback(error);

        return;
    }

    try {
        const payloadSha256 = crypto.createHash('sha256').update(payload).digest('hex');
        let created = false;
        let transaction;

        if (fs.existsSync(transactionPath)) {
            transaction = JSON.parse(fs.readFileSync(transactionPath, 'utf8'));
            if (transaction.payloadSha256 !== payloadSha256) {
                throw new Error('The idempotency key was reused with a different payload.');
            }
        } else {
            created = true;
            transaction = {
                committedAt: Date.now(),
                createdByBackend: instance,
                idempotencyHash,
                payloadSha256,
                transactionId: crypto
                    .createHash('sha256')
                    .update(`coolify-runtime-transaction\0${idempotencyKey}`)
                    .digest('hex')
                    .slice(0, 32),
                writeCount: 1,
            };
            durableReplace(transactionPath, transaction);
            durableAppend(
                'durable-writes.log',
                [transaction.committedAt, transaction.transactionId, transaction.idempotencyHash, instance].join('|'),
            );
        }
        durableAppend(
            'transaction-attempts.log',
            [Date.now(), transaction.transactionId, created ? 'created' : 'replayed', instance].join('|'),
        );
        callback(null, {created, transaction});
    } catch (error) {
        callback(error);
    } finally {
        fs.rmdirSync(lockPath);
    }
}

function handleTransaction(request, response, requestUrl) {
    const idempotencyKey = request.headers['idempotency-key'];
    const releaseName = requestUrl.searchParams.get('hold');
    let body = '';
    let tooLarge = false;

    if (typeof idempotencyKey !== 'string' || idempotencyKey.length < 8 || idempotencyKey.length > 200) {
        response.writeHead(400, responseHeaders());
        response.end();

        return;
    }
    if (releaseName !== null && !/^[A-Za-z0-9_-]{1,80}$/.test(releaseName)) {
        response.writeHead(400, responseHeaders());
        response.end();

        return;
    }

    request.setEncoding('utf8');
    request.on('data', (chunk) => {
        body += chunk;
        if (Buffer.byteLength(body) > maximumTransactionBodyBytes) {
            tooLarge = true;
        }
    });
    request.once('end', () => {
        if (tooLarge) {
            response.writeHead(413, responseHeaders());
            response.end();

            return;
        }
        createOrReplayTransaction(idempotencyKey, body, Date.now(), (error, result) => {
            if (error) {
                response.writeHead(error.message.includes('different payload') ? 409 : 500, responseHeaders());
                response.end();

                return;
            }

            respondAfterRelease(response, releaseName, () => {
                response.writeHead(result.created ? 201 : 200, {
                    ...responseHeaders(),
                    'Content-Type': 'application/json',
                });
                response.end(JSON.stringify({
                    backend: instance,
                    created: result.created,
                    replayedByBackend: result.created ? null : instance,
                    transactionId: result.transaction.transactionId,
                    writeCount: result.transaction.writeCount,
                }));
            });
        });
    });
}

function webSocketFrame(payload) {
    const body = Buffer.from(JSON.stringify(payload));
    if (body.length < 126) {
        return Buffer.concat([Buffer.from([0x81, body.length]), body]);
    }
    if (body.length <= 65_535) {
        const header = Buffer.alloc(4);

        header[0] = 0x81;
        header[1] = 126;
        header.writeUInt16BE(body.length, 2);

        return Buffer.concat([header, body]);
    }

    throw new Error('Transport test payload unexpectedly exceeds a 16-bit WebSocket frame.');
}

const server = http.createServer((request, response) => {
    const requestUrl = new URL(request.url, 'http://localhost');
    const requestPath = requestUrl.pathname;
    if (requestPath === '/api/health') {
        const validProof = request.headers[privateHeader] === healthProof;
        const requestHost = request.headers.host;
        const providerRequest = requestHost === providerHost || requestHost === `${providerHost}:8080`;
        const directRequest = request.headers['x-integration-direct-probe'] === 'true';
        const healthy = isHealthy();

        if (providerRequest) {
            append('provider-health.log', [validProof ? '1' : '0', healthy ? '1' : '0']);
        }
        if (directRequest) {
            append('direct-health.log', [validProof ? '1' : '0', healthy ? '1' : '0']);
        }
        if (!validProof) {
            response.writeHead(401);
            response.end();

            return;
        }
        if (!healthy) {
            response.writeHead(503);
            response.end();

            return;
        }

        response.writeHead(204, responseHeaders());
        response.end();

        return;
    }

    if (!isHealthy()) {
        response.writeHead(503);
        response.end();

        return;
    }

    recordRequest(requestPath, request);
    if (requestPath === '/transactions' && request.method === 'POST') {
        handleTransaction(request, response, requestUrl);

        return;
    }
    if (requestPath === '/transport/http') {
        const current = identity();

        response.writeHead(200, {
            ...responseHeaders(current),
            'Content-Type': 'application/json',
        });
        response.end(JSON.stringify(transportEvent('http', 1, crypto.randomUUID(), current)));

        return;
    }
    if (requestPath === '/transport/sse') {
        const current = identity();

        response.writeHead(200, {
            ...responseHeaders(current),
            'Cache-Control': 'no-cache, no-transform',
            Connection: 'keep-alive',
            'Content-Type': 'text/event-stream',
            'X-Accel-Buffering': 'no',
        });
        response.flushHeaders();
        const cancel = streamTransportEvents(
            (event) => response.write(`event: control-plane\ndata: ${JSON.stringify(event)}\n\n`),
            'sse',
            current,
        );
        const releaseHeldConnection = registerHeldConnection();
        const close = () => {
            cancel();
            releaseHeldConnection();
        };
        request.once('close', close);
        response.once('close', close);

        return;
    }

    response.writeHead(200, responseHeaders());
    response.end(instance);
});

server.on('upgrade', (request, socket) => {
    const requestPath = new URL(request.url, 'http://localhost').pathname;
    if (requestPath !== '/transport/ws' || !isHealthy()) {
        socket.end('HTTP/1.1 503 Service Unavailable\r\nConnection: close\r\n\r\n');

        return;
    }

    const webSocketKey = request.headers['sec-websocket-key'];
    if (typeof webSocketKey !== 'string') {
        socket.end('HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n');

        return;
    }

    const current = identity();
    const accept = crypto
        .createHash('sha1')
        .update(`${webSocketKey}258EAFA5-E914-47DA-95CA-C5AB0DC85B11`)
        .digest('base64');
    const headers = Object.entries(responseHeaders(current))
        .map(([name, value]) => `${name}: ${value}`)
        .join('\r\n');

    recordRequest(requestPath, request);
    socket.write(`HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: ${accept}\r\n${headers}\r\n\r\n`);
    const cancel = streamTransportEvents(
        (event) => socket.write(webSocketFrame(event)),
        'websocket',
        current,
    );
    const releaseHeldConnection = registerHeldConnection();
    const close = () => {
        cancel();
        releaseHeldConnection();
    };
    socket.once('close', close);
    socket.once('error', close);
});

server.listen(8080, '0.0.0.0');

process.once('SIGTERM', () => {
    if (shuttingDown) {
        return;
    }
    shuttingDown = true;
    append('shutdown.log', [instance, 'SIGTERM']);
    server.close(() => {
        serverClosed = true;
        finishGracefulShutdownWhenDrained();
    });
    const forcedShutdown = setTimeout(() => process.exit(1), gracefulShutdownTimeoutMilliseconds);
    forcedShutdown.unref();
});
