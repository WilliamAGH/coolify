'use strict';

const crypto = require('crypto');
const fs = require('fs');
const https = require('https');
const path = require('path');
const tls = require('tls');

const [
    reportPath,
    readyPath,
    releasePath,
    host,
    minimumEventCountArgument,
    timeoutMillisecondsArgument,
] = process.argv.slice(2);
const minimumEventCount = Number(minimumEventCountArgument);
const timeoutMilliseconds = Number(timeoutMillisecondsArgument);

if (
    !reportPath
    || !readyPath
    || !releasePath
    || !host
    || !Number.isInteger(minimumEventCount)
    || minimumEventCount < 1
    || !Number.isInteger(timeoutMilliseconds)
    || timeoutMilliseconds < 1
) {
    throw new Error(
        'Usage: transport-observer.js <report-path> <ready-path> <release-path> <host> '
        + '<minimum-event-count> <timeout-milliseconds>',
    );
}

function writeJsonAtomically(destination, value) {
    const temporaryPath = path.join(
        path.dirname(destination),
        '.' + path.basename(destination) + '.' + process.pid + '.tmp',
    );

    fs.writeFileSync(temporaryPath, JSON.stringify(value) + '\n');
    fs.renameSync(temporaryPath, destination);
}

function readReleaseBarrier() {
    let release;

    try {
        release = JSON.parse(fs.readFileSync(releasePath, 'utf8'));
    } catch (error) {
        if (error.code === 'ENOENT') {
            return null;
        }

        throw error;
    }

    if (
        typeof release.transition !== 'string'
        || release.transition.length === 0
        || !Number.isInteger(release.appliedAt)
        || release.appliedAt < 1
    ) {
        throw new Error('Transport release barrier is malformed.');
    }

    return release;
}

const ready = {};

function markReady(transport, startedAt, firstEventAt) {
    ready[transport] = {firstEventAt, startedAt};
    if (ready.sse && ready.websocket) {
        writeJsonAtomically(readyPath, ready);
    }
}

function recordTransportEvent(payload) {
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
        throw new Error('Transport observer received a malformed event payload.');
    }

    return {
        ...payload,
        receivedAt: Date.now(),
    };
}

function releaseForEvent(event, eventCount) {
    if (eventCount < minimumEventCount) {
        return null;
    }

    const release = readReleaseBarrier();
    if (!release || event.receivedAt < release.appliedAt) {
        return null;
    }

    return release;
}

function observeSse() {
    return new Promise((resolve, reject) => {
        const events = [];
        let buffer = '';
        let settled = false;
        let startedAt = 0;
        let timeout;

        const rejectOnce = (error) => {
            if (!settled) {
                settled = true;
                clearTimeout(timeout);
                reject(error);
            }
        };
        const request = https.request({
            headers: {Host: host},
            hostname: 'traefik',
            method: 'GET',
            path: '/transport/sse',
            port: 8443,
            rejectUnauthorized: false,
            servername: host,
        }, (response) => {
            startedAt = Date.now();
            if (response.statusCode !== 200) {
                response.resume();
                rejectOnce(new Error('SSE observer received HTTP ' + response.statusCode));

                return;
            }

            response.setEncoding('utf8');
            response.on('data', (chunk) => {
                try {
                    buffer += chunk;
                    let separator;
                    while (!settled && (separator = buffer.indexOf('\n\n')) !== -1) {
                        const message = buffer.slice(0, separator);
                        const data = message.split('\n').find((line) => line.startsWith('data: '));

                        buffer = buffer.slice(separator + 2);
                        if (!data) {
                            continue;
                        }

                        const event = recordTransportEvent(JSON.parse(data.slice('data: '.length)));

                        events.push(event);
                        if (events.length === 1) {
                            markReady('sse', startedAt, event.receivedAt);
                        }

                        const release = releaseForEvent(event, events.length);
                        if (release) {
                            settled = true;
                            clearTimeout(timeout);
                            request.destroy();
                            resolve({
                                completedAt: Date.now(),
                                events,
                                release,
                                startedAt,
                                status: response.statusCode,
                            });
                        }
                    }
                } catch (error) {
                    request.destroy();
                    rejectOnce(error);
                }
            });
            response.once('aborted', () => rejectOnce(new Error('SSE connection was aborted before release.')));
            response.once('error', rejectOnce);
            response.once('end', () => rejectOnce(new Error('SSE connection ended before release.')));
        });

        timeout = setTimeout(
            () => request.destroy(new Error('SSE observation timed out before release.')),
            timeoutMilliseconds,
        );
        request.once('error', rejectOnce);
        request.end();
    });
}

function parseWebSocketFrames(buffer, receiveEvent) {
    let remaining = buffer;

    while (remaining.length >= 2) {
        const first = remaining[0];
        const second = remaining[1];
        const opcode = first & 0x0f;
        const masked = (second & 0x80) !== 0;
        let length = second & 0x7f;
        let offset = 2;

        if ((first & 0x80) === 0 || opcode !== 1 || masked) {
            throw new Error('WebSocket observer received an unexpected frame.');
        }
        if (length === 126) {
            if (remaining.length < 4) {
                break;
            }

            length = remaining.readUInt16BE(2);
            offset = 4;
        } else if (length === 127) {
            throw new Error('WebSocket observer received an unexpectedly large frame.');
        }
        if (remaining.length < offset + length) {
            break;
        }

        const payload = JSON.parse(remaining.subarray(offset, offset + length).toString('utf8'));

        remaining = remaining.subarray(offset + length);
        if (receiveEvent(payload)) {
            return Buffer.alloc(0);
        }
    }

    return remaining;
}

function observeWebSocket() {
    return new Promise((resolve, reject) => {
        const socket = tls.connect({
            host: 'traefik',
            port: 8443,
            rejectUnauthorized: false,
            servername: host,
        });
        const key = crypto.randomBytes(16).toString('base64');
        const expectedAccept = crypto
            .createHash('sha1')
            .update(key + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')
            .digest('base64');
        const events = [];
        let buffer = Buffer.alloc(0);
        let handshakeCompleted = false;
        let settled = false;
        let startedAt = 0;
        let status = 0;
        let timeout;

        const rejectOnce = (error) => {
            if (!settled) {
                settled = true;
                clearTimeout(timeout);
                reject(error);
            }
        };
        const resolveOnce = (release) => {
            if (!settled) {
                settled = true;
                clearTimeout(timeout);
                socket.destroy();
                resolve({
                    completedAt: Date.now(),
                    events,
                    release,
                    startedAt,
                    status,
                });
            }
        };

        timeout = setTimeout(
            () => socket.destroy(new Error('WebSocket observation timed out before release.')),
            timeoutMilliseconds,
        );
        socket.once('secureConnect', () => {
            socket.write([
                'GET /transport/ws HTTP/1.1',
                'Host: ' + host,
                'Connection: Upgrade',
                'Upgrade: websocket',
                'Sec-WebSocket-Key: ' + key,
                'Sec-WebSocket-Version: 13',
                '',
                '',
            ].join('\r\n'));
        });
        socket.on('data', (chunk) => {
            try {
                buffer = Buffer.concat([buffer, chunk]);
                if (!handshakeCompleted) {
                    const boundary = buffer.indexOf('\r\n\r\n');
                    if (boundary === -1) {
                        return;
                    }

                    const headers = buffer.subarray(0, boundary).toString('utf8').split('\r\n');
                    const statusMatch = /^HTTP\/1\.1 ([0-9]{3})/.exec(headers[0]);
                    const accept = headers
                        .slice(1)
                        .map((header) => header.split(/:\s*/, 2))
                        .find(([name]) => name.toLowerCase() === 'sec-websocket-accept')?.[1];

                    status = Number(statusMatch?.[1] ?? 0);
                    if (status !== 101 || accept !== expectedAccept) {
                        throw new Error('WebSocket handshake failed with HTTP ' + status + '.');
                    }

                    handshakeCompleted = true;
                    startedAt = Date.now();
                    buffer = buffer.subarray(boundary + 4);
                }

                buffer = parseWebSocketFrames(buffer, (payload) => {
                    const event = recordTransportEvent(payload);

                    events.push(event);
                    if (events.length === 1) {
                        markReady('websocket', startedAt, event.receivedAt);
                    }

                    const release = releaseForEvent(event, events.length);
                    if (release) {
                        resolveOnce(release);

                        return true;
                    }

                    return false;
                });
            } catch (error) {
                socket.destroy();
                rejectOnce(error);
            }
        });
        socket.once('end', () => rejectOnce(new Error('WebSocket connection ended before release.')));
        socket.once('close', () => rejectOnce(new Error('WebSocket connection closed before release.')));
        socket.once('error', rejectOnce);
    });
}

Promise.all([observeSse(), observeWebSocket()])
    .then(([sse, websocket]) => {
        if (
            sse.release.appliedAt !== websocket.release.appliedAt
            || sse.release.transition !== websocket.release.transition
        ) {
            throw new Error('SSE and WebSocket observers crossed different release barriers.');
        }

        writeJsonAtomically(reportPath, {
            completedAt: Date.now(),
            minimumEventCount,
            release: sse.release,
            sse,
            websocket,
        });
    })
    .catch((error) => {
        process.stderr.write((error.stack ?? error) + '\n');
        process.exitCode = 1;
    });
