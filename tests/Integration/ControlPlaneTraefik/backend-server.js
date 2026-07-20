'use strict';

const crypto = require('crypto');
const fs = require('fs');
const http = require('http');

const stateDirectory = process.env.STATE_DIRECTORY;
const privateHeader = 'x-coolify-control-plane-health-proof';
const authenticationProofHeader = 'x-coolify-control-plane-authentication-proof';
const healthProof = process.env.CONTROL_PLANE_HEALTH_PROOF;
const instance = process.env.BACKEND_INSTANCE;
const providerHost = 'control-plane.test';
const transportEventIntervalMilliseconds = 100;

function append(filename, values) {
    fs.appendFileSync(`${stateDirectory}/${filename}`, [String(Date.now()), ...values].join('|') + '\n');
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
    const requestPath = new URL(request.url, 'http://localhost').pathname;
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
        request.once('close', cancel);

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
    socket.once('close', cancel);
    socket.once('error', cancel);
});

server.listen(8080, '0.0.0.0');
