import { access } from "node:fs/promises";
import path from "node:path";

const [mode, ...argumentsList] = process.argv.slice(2);
const dockerRouterName = "legacyrouter@docker";
const dockerServiceName = "legacyservice@docker";
const routingRule = "PathPrefix(`/`)";
const steadyPriority = routingRule.length;
const adoptionPriority = steadyPriority + 1;

function fail(message) {
  throw new Error(message);
}

function positiveInteger(rawValue, fieldName, fallback) {
  const value = Number.parseInt(rawValue ?? String(fallback), 10);
  if (!Number.isSafeInteger(value) || value < 1) {
    fail(`${fieldName} must be a positive integer`);
  }
  return value;
}

function output(proof) {
  process.stdout.write(`${JSON.stringify({ mode, proof, timestamp: new Date().toISOString() })}\n`);
}

function pause(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

async function request(url, timeoutMilliseconds = 3_000) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMilliseconds);

  try {
    return await fetch(url, { redirect: "manual", signal: controller.signal });
  } finally {
    clearTimeout(timer);
  }
}

async function jsonRequest(url, timeoutMilliseconds = 3_000) {
  const response = await request(url, timeoutMilliseconds);
  const body = await response.text();
  let payload;

  try {
    payload = JSON.parse(body);
  } catch (error) {
    fail(`${url} returned non-JSON HTTP ${response.status}: ${error.message}`);
  }

  return { payload, response };
}

function exactStrings(actual, expected) {
  return Array.isArray(actual)
    && actual.every((entry) => typeof entry === "string")
    && [...actual].sort().join("\n") === [...expected].sort().join("\n");
}

async function rawData() {
  const { payload, response } = await jsonRequest("http://traefik:8080/api/rawdata");
  if (response.status !== 200) {
    fail(`Traefik rawdata returned HTTP ${response.status}`);
  }
  return payload;
}

function assertDockerProviderPresent(configuration) {
  const router = configuration.routers?.[dockerRouterName];
  const service = configuration.services?.[dockerServiceName];

  if (router?.status !== "enabled"
    || router.rule !== routingRule
    || router.service !== "legacyservice"
    || router.priority !== steadyPriority
    || !exactStrings(router.entryPoints, ["web"])
    || !exactStrings(router.using, ["web"])
    || service?.status !== "enabled") {
    fail("the exact legacy Docker router and service are not enabled with the expected routing state");
  }

  return { router, service };
}

function assertDockerProviderActive(configuration) {
  const { router, service } = assertDockerProviderPresent(configuration);
  if (!exactStrings(service.usedBy, [dockerRouterName])) {
    fail("the exact legacy Docker service is not attached to its router");
  }

  const servers = service?.loadBalancer?.servers;
  const serverStatus = service?.serverStatus;
  if (!Array.isArray(servers)
    || servers.length !== 1
    || serverStatus === null
    || typeof serverStatus !== "object") {
    fail("the exact legacy Docker service does not expose one healthy backend");
  }

  const serverUrls = servers.map((server) => server?.url);
  if (serverUrls.some((url) => typeof url !== "string" || !/^http:\/\/[^/]+:3000$/u.test(url))
    || Object.keys(serverStatus).sort().join("\n") !== [...serverUrls].sort().join("\n")
    || Object.values(serverStatus).some((status) => status !== "UP")) {
    fail("the exact legacy Docker service backend is not healthy");
  }

  return {
    router: dockerRouterName,
    routerPriority: router.priority,
    routerStatus: router.status,
    serverStatus,
    service: dockerServiceName,
    serviceStatus: service.status,
  };
}

function assertDockerProviderEvicted(configuration) {
  if (Object.hasOwn(configuration.routers ?? {}, dockerRouterName)
    || Object.hasOwn(configuration.services ?? {}, dockerServiceName)) {
    fail("the exact legacy Docker router or service remains after provider eviction");
  }

  return { absentRouter: dockerRouterName, absentService: dockerServiceName };
}

function assertFileRouter(configuration, routerName, serviceName, priority) {
  const qualifiedRouterName = `${routerName}@file`;
  const qualifiedServiceName = `${serviceName}@file`;
  const router = configuration.routers?.[qualifiedRouterName];
  const service = configuration.services?.[qualifiedServiceName];

  if (router?.status !== "enabled"
    || router.rule !== routingRule
    || router.service !== serviceName
    || router.priority !== priority
    || !exactStrings(router.entryPoints, ["web"])
    || !exactStrings(router.using, ["web"])) {
    fail(`the exact file router ${qualifiedRouterName} is not enabled at priority ${priority}`);
  }

  if (service?.status !== "enabled" || !exactStrings(service.usedBy, [qualifiedRouterName])) {
    fail(`the exact file service ${qualifiedServiceName} is not enabled for ${qualifiedRouterName}`);
  }

  return { priority: router.priority, router: qualifiedRouterName, service: qualifiedServiceName };
}

async function routeState(expectedRevision, expectedPhase) {
  const { payload, response } = await jsonRequest("http://traefik/revision");
  const phase = response.headers.get("x-coolify-blue-green-phase");

  if (response.status !== 200 || payload.revision !== expectedRevision) {
    fail(`expected ${expectedRevision} via HTTP 200, received ${payload.revision ?? "missing"} via HTTP ${response.status}`);
  }
  if ((expectedPhase === "none" && phase !== null)
    || (expectedPhase !== "none" && phase !== expectedPhase)) {
    fail(`route phase mismatch: expected ${expectedPhase}, received ${phase ?? "none"}`);
  }

  return { phase, revision: payload.revision, status: response.status };
}

async function directHealth(host, expectedRevision) {
  const { payload, response } = await jsonRequest(`http://${host}:3000/health`);
  if (response.status !== 200 || payload.revision !== expectedRevision || payload.healthy !== true) {
    fail(`${host} direct health is not healthy ${expectedRevision}: HTTP ${response.status}`);
  }
  return { host, revision: payload.revision, status: response.status };
}

async function connectionState(host, expectedRevision, minimumActiveRequest, minimumActiveWebSocket) {
  const { payload, response } = await jsonRequest(`http://${host}:3000/connections`);
  if (response.status !== 200 || payload.revision !== expectedRevision) {
    fail(`${host} did not expose connection state for ${expectedRevision}: HTTP ${response.status}`);
  }
  if (!Number.isSafeInteger(payload.activeRequest) || !Number.isSafeInteger(payload.activeWebSocket)) {
    fail(`${host} returned malformed active-request or active-websocket counts`);
  }
  if (payload.activeRequest < minimumActiveRequest || payload.activeWebSocket < minimumActiveWebSocket) {
    fail(
      `${host} does not retain the required live legacy work: requests=${payload.activeRequest}/${minimumActiveRequest} websockets=${payload.activeWebSocket}/${minimumActiveWebSocket}`,
    );
  }
  return {
    activeRequest: payload.activeRequest,
    activeWebSocket: payload.activeWebSocket,
    host,
    revision: payload.revision,
    status: response.status,
  };
}

async function awaitProof(proof, timeoutMilliseconds) {
  const deadline = Date.now() + timeoutMilliseconds;
  let latestFailure = "no proof attempted";

  while (Date.now() < deadline) {
    try {
      return await proof();
    } catch (error) {
      latestFailure = error.message;
      await pause(100);
    }
  }

  fail(`proof timed out after ${timeoutMilliseconds}ms: ${latestFailure}`);
}

async function awaitProvider() {
  const state = argumentsList[0];
  const timeoutMilliseconds = positiveInteger(argumentsList[1], "provider timeout", 30_000);
  const startedAt = argumentsList[2] === undefined ? undefined : positiveInteger(argumentsList[2], "eviction startedAt", 1);
  const minimumDelayMilliseconds = argumentsList[3] === undefined
    ? undefined
    : positiveInteger(argumentsList[3], "minimum eviction delay", 1);

  if (state !== "active" && state !== "present" && state !== "evicted") {
    fail(`unsupported provider state: ${state ?? "missing"}`);
  }

  const proof = await awaitProof(async () => {
    const configuration = await rawData();
    if (state === "active") {
      return assertDockerProviderActive(configuration);
    }
    if (state === "present") {
      const { router, service } = assertDockerProviderPresent(configuration);
      return { router: dockerRouterName, routerStatus: router.status, service: dockerServiceName, serviceStatus: service.status };
    }
    return assertDockerProviderEvicted(configuration);
  }, timeoutMilliseconds);
  const elapsedMilliseconds = startedAt === undefined ? undefined : Date.now() - startedAt;

  if (minimumDelayMilliseconds !== undefined && (elapsedMilliseconds === undefined || elapsedMilliseconds < minimumDelayMilliseconds)) {
    fail(`Docker provider eviction was not throttled for ${minimumDelayMilliseconds}ms; observed ${elapsedMilliseconds ?? 0}ms`);
  }

  output({ ...proof, elapsedMilliseconds, state });
}

async function assertProvider() {
  const state = argumentsList[0];
  if (state !== "active" && state !== "present" && state !== "evicted") {
    fail(`unsupported provider state: ${state ?? "missing"}`);
  }
  const configuration = await rawData();
  let proof;
  if (state === "active") {
    proof = assertDockerProviderActive(configuration);
  } else if (state === "present") {
    const { router, service } = assertDockerProviderPresent(configuration);
    proof = { router: dockerRouterName, routerStatus: router.status, service: dockerServiceName, serviceStatus: service.status };
  } else {
    proof = assertDockerProviderEvicted(configuration);
  }
  output({ ...proof, state });
}

async function awaitRoute() {
  const expectedRevision = argumentsList[0];
  const expectedPhase = argumentsList[1];
  const timeoutMilliseconds = positiveInteger(argumentsList[2], "route timeout", 15_000);
  if (expectedRevision === undefined || expectedPhase === undefined) {
    fail("await-route requires a revision and phase");
  }
  output(await awaitProof(() => routeState(expectedRevision, expectedPhase), timeoutMilliseconds));
}

async function awaitAdoption() {
  const timeoutMilliseconds = positiveInteger(argumentsList[0], "adoption timeout", 15_000);
  const proof = await awaitProof(async () => {
    const configuration = await rawData();
    const { router, service } = assertDockerProviderPresent(configuration);
    const docker = { router: dockerRouterName, routerStatus: router.status, service: dockerServiceName, serviceStatus: service.status };
    const file = assertFileRouter(configuration, "candidateadoption", "candidateservice", adoptionPriority);
    const route = await routeState("candidate", "candidate-adoption");
    const connection = await connectionState("legacy", "legacy", 2, 1);
    return { connection, docker, file, route };
  }, timeoutMilliseconds);
  output(proof);
}

async function awaitSteady() {
  const timeoutMilliseconds = positiveInteger(argumentsList[0], "steady timeout", 15_000);
  const proof = await awaitProof(async () => {
    const configuration = await rawData();
    const docker = assertDockerProviderEvicted(configuration);
    const file = assertFileRouter(configuration, "candidatesteady", "candidateservice", steadyPriority);
    const route = await routeState("candidate", "steady");
    return { docker, file, route };
  }, timeoutMilliseconds);
  output(proof);
}

async function awaitBridge() {
  const timeoutMilliseconds = positiveInteger(argumentsList[0], "bridge timeout", 15_000);
  const proof = await awaitProof(async () => {
    const configuration = await rawData();
    const file = assertFileRouter(configuration, "legacybridge", "legacybridgeservice", adoptionPriority);
    const health = await directHealth("legacy", "legacy");
    const route = await routeState("legacy", "legacy-bridge");
    return { file, health, route };
  }, timeoutMilliseconds);
  output(proof);
}

async function awaitLegacyAuthority() {
  const timeoutMilliseconds = positiveInteger(argumentsList[0], "legacy authority timeout", 15_000);
  const proof = await awaitProof(async () => {
    const configuration = await rawData();
    const docker = assertDockerProviderActive(configuration);
    const route = await routeState("legacy", "none");
    return { docker, route };
  }, timeoutMilliseconds);
  output(proof);
}

async function awaitHealth() {
  const host = argumentsList[0];
  const expectedRevision = argumentsList[1];
  const timeoutMilliseconds = positiveInteger(argumentsList[2], "health timeout", 15_000);
  if (host === undefined || expectedRevision === undefined) {
    fail("await-health requires a host and revision");
  }
  output(await awaitProof(() => directHealth(host, expectedRevision), timeoutMilliseconds));
}

async function awaitMarker() {
  const marker = argumentsList[0];
  const timeoutMilliseconds = positiveInteger(argumentsList[1], "marker timeout", 5_000);
  if (!/^[A-Za-z0-9_-]{1,128}$/u.test(marker ?? "")) {
    fail("marker contains unsupported characters");
  }
  const markerPath = path.join(process.env.STATE_DIRECTORY ?? "/state", "inflight", `${marker}.started`);
  const proof = await awaitProof(async () => {
    await access(markerPath);
    return { marker, markerPath };
  }, timeoutMilliseconds);
  output(proof);
}

async function metrics() {
  const response = await request("http://traefik:8082/metrics");
  if (response.status !== 200) {
    fail(`metrics endpoint returned HTTP ${response.status}`);
  }
  const body = await response.text();
  const entryPointSeries = body.split("\n").filter((line) => line.startsWith("traefik_entrypoint_requests_total{")).length;
  if (entryPointSeries === 0) {
    fail("Prometheus output did not contain entry-point request metrics");
  }
  output({ entryPointSeries, status: response.status });
}

const actions = {
  "assert-provider": assertProvider,
  "await-adoption": awaitAdoption,
  "await-bridge": awaitBridge,
  "await-health": awaitHealth,
  "await-legacy-authority": awaitLegacyAuthority,
  "await-marker": awaitMarker,
  "await-provider": awaitProvider,
  "await-route": awaitRoute,
  "await-steady": awaitSteady,
  metrics,
};

try {
  const action = actions[mode];
  if (action === undefined) {
    fail(`unsupported mode: ${mode ?? "missing"}`);
  }
  await action();
} catch (error) {
  process.stderr.write(`BLUEGREEN_PROBE_FAILURE ${error.message}\n`);
  process.exitCode = 1;
}
