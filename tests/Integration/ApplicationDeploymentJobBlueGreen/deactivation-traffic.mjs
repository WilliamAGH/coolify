import { request } from 'node:http';
import { writeFile } from 'node:fs/promises';

const expectedTombstoneAck = process.env.EXPECTED_TOMBSTONE_ACK;
const result = { error: null, firstNotFoundAt: null, firstTombstoneAt: null, request: [] };
const deadline = Date.now() + 150000;
let ready = false;

function route() {
  return new Promise((resolve, reject) => {
    const startedAt = Date.now();
    const req = request({
      host: 'coolify-proxy', port: 80, path: '/', headers: { Host: process.env.TRAFFIC_HOST ?? 'application.test' },
    }, response => {
      response.resume();
      response.on('end', () => resolve({
        acknowledgement: response.headers['x-coolify-probe-ack'] ?? null,
        startedAt,
        endedAt: Date.now(),
        status: response.statusCode,
      }));
    });
    req.setTimeout(2000, () => req.destroy(new Error('timeout')));
    req.on('error', reject);
    req.end();
  });
}

try {
  while (Date.now() < deadline) {
    const observation = await route();
    result.request.push(observation);
    if (!ready && observation.status === 200) {
      await writeFile('/evidence/deactivation-ready', `${observation.endedAt}\n`);
      ready = true;
    }
    if (observation.status >= 500) throw new Error(`unsafe gateway/server status ${observation.status}`);
    if (observation.status === 418) {
      if (observation.acknowledgement !== expectedTombstoneAck) {
        throw new Error('tombstone returned an inexact acknowledgement');
      }
      result.firstTombstoneAt ??= observation.endedAt;
    }
    if (observation.status === 404) {
      if (observation.acknowledgement !== null) throw new Error('absent route retained an acknowledgement');
      if (result.firstTombstoneAt === null) throw new Error('route disappeared before tombstone acknowledgement');
      result.firstNotFoundAt = observation.endedAt;
      break;
    }
    if (![200, 418].includes(observation.status)) throw new Error(`unexpected status ${observation.status}`);
    await new Promise(resolve => setTimeout(resolve, 5));
  }
  if (result.firstNotFoundAt === null) throw new Error('Traefik route was not evicted');
} catch (error) {
  result.error = error.stack ?? error.message;
  process.exitCode = 1;
}
await writeFile('/evidence/deactivation-traffic.json', `${JSON.stringify(result, null, 2)}\n`);
