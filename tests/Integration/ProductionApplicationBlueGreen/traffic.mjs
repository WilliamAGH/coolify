import { request } from 'node:http';
import { access, writeFile } from 'node:fs/promises';

const firstAcknowledgement = process.env.EXPECTED_FIRST_ACK;
const secondAcknowledgement = process.env.EXPECTED_SECOND_ACK;
const reportPath = '/evidence/continuity.json';
const readyPath = '/evidence/continuity-ready';
const replayCompletePath = '/evidence/replay-complete';
const deadline = Date.now() + 60000;
const summary = {
  startedAt: Date.now(),
  endedAt: null,
  transitionAt: null,
  requestCount: 0,
  firstCount: 0,
  secondCount: 0,
  postReplayCount: 0,
  replayCompletedAt: null,
  finalAcknowledgement: null,
  maxGapMilliseconds: 0,
  errors: [],
  samples: [],
};
let lastSuccessAt = null;

function recordSuccessfulSample(response, afterTerminalReplay) {
  summary.samples.push({
    acknowledgement: response.acknowledgement,
    afterTerminalReplay,
    endedAt: response.endedAt,
    status: response.status,
  });
  summary.requestCount += 1;
  if (response.acknowledgement === firstAcknowledgement) {
    summary.firstCount += 1;
  } else {
    summary.secondCount += 1;
    summary.transitionAt ??= response.endedAt;
  }
  summary.finalAcknowledgement = response.acknowledgement;
  if (lastSuccessAt !== null) {
    summary.maxGapMilliseconds = Math.max(summary.maxGapMilliseconds, response.endedAt - lastSuccessAt);
  }
  lastSuccessAt = response.endedAt;
}

function probe() {
  return new Promise((resolve, reject) => {
    const probeStartedAt = Date.now();
    const probeRequest = request({
      host: 'coolify-proxy',
      port: 80,
      path: '/',
      headers: { Host: 'application.test' },
    }, response => {
      response.resume();
      response.on('end', () => resolve({
        acknowledgement: response.headers['x-fixture-ack'] ?? null,
        status: response.statusCode,
        endedAt: Date.now(),
      }));
    });
    probeRequest.setTimeout(1500, () => probeRequest.destroy(new Error(`request timed out after ${Date.now() - probeStartedAt}ms`)));
    probeRequest.on('error', reject);
    probeRequest.end();
  });
}

async function persistAndFail(error) {
  summary.errors.push(error instanceof Error ? error.message : String(error));
  summary.endedAt = Date.now();
  await writeFile(reportPath, `${JSON.stringify(summary, null, 2)}\n`);
  process.exitCode = 1;
}

try {
  let initial;
  while (Date.now() < deadline) {
    try {
      initial = await probe();
      if (initial.status === 200 && initial.acknowledgement === firstAcknowledgement) {
        break;
      }
    } catch {
      // The observer starts before it declares the continuity window open.
    }
    await new Promise(resolve => setTimeout(resolve, 50));
  }
  if (initial?.status !== 200 || initial.acknowledgement !== firstAcknowledgement) {
    throw new Error('initial blue route was not observable');
  }

  summary.startedAt = initial.endedAt;
  recordSuccessfulSample(initial, false);
  await writeFile(readyPath, `${summary.startedAt}\n`);

  while (Date.now() < deadline) {
    let replayComplete = false;
    try {
      await access(replayCompletePath);
      replayComplete = true;
    } catch {
      // Terminal replay has not completed yet.
    }
    const response = await probe();
    if (response.status !== 200) {
      throw new Error(`route returned HTTP ${response.status}`);
    }
    if (response.acknowledgement !== firstAcknowledgement && response.acknowledgement !== secondAcknowledgement) {
      throw new Error(`route returned unknown acknowledgement ${response.acknowledgement}`);
    }
    if (replayComplete && response.acknowledgement !== secondAcknowledgement) {
      throw new Error('terminal activation replay left the blue acknowledgement routable');
    }

    recordSuccessfulSample(response, replayComplete);
    if (replayComplete) {
      summary.replayCompletedAt ??= response.endedAt;
      summary.postReplayCount += 1;
    }

    const elapsed = response.endedAt - summary.startedAt;
    if (summary.secondCount >= 3 && summary.firstCount >= 5 && summary.requestCount >= 30
      && summary.postReplayCount >= 5 && elapsed >= 3000) {
      summary.endedAt = response.endedAt;
      await writeFile(reportPath, `${JSON.stringify(summary, null, 2)}\n`);
      break;
    }
    await new Promise(resolve => setTimeout(resolve, 50));
  }

  if (summary.endedAt === null) {
    throw new Error('continuity observer deadline expired');
  }
} catch (error) {
  await persistAndFail(error);
}
