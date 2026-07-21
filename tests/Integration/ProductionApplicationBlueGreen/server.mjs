import { createHash } from 'node:crypto';
import { createServer } from 'node:http';

const sourceCommit = process.env.SOURCE_COMMIT ?? 'missing-source-commit';
const acknowledgement = createHash('sha256').update(sourceCommit).digest('hex');
const releaseProof = process.env.COOLIFY_DEPLOYMENT_RELEASE_PROOF ?? 'missing-release-proof';
const startupDelayMilliseconds = 3000;

const server = createServer((_request, response) => {
  response.setHeader('Content-Type', 'application/json');
  response.setHeader('X-Fixture-Ack', acknowledgement);
  response.setHeader('X-Coolify-Deployment', releaseProof);
  response.end(JSON.stringify({ acknowledgement, sourceCommit }));
});

setTimeout(() => server.listen(3000), startupDelayMilliseconds);
process.on('SIGTERM', () => server.close(() => process.exit(0)));
