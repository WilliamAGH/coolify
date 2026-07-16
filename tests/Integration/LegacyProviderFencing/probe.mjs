const argumentsList = process.argv.slice(2);
const mode = argumentsList[0];
const expected = argumentsList[1];
const acknowledgement = mode === "await-route" ? argumentsList[2] : undefined;
const rawTimeout = mode === "await-route" ? (argumentsList[3] ?? "30000") : (argumentsList[2] ?? "30000");
const timeoutMilliseconds = Number.parseInt(rawTimeout, 10);
const dockerRouterName = "legacy-router@docker";
const dockerServiceName = "legacy-service@docker";
const dockerRule = "PathPrefix(`/`)";

if (!Number.isSafeInteger(timeoutMilliseconds) || timeoutMilliseconds < 1) {
  throw new Error("probe timeout must be a positive integer");
}

const sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

async function request(url) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 3_000);
  try {
    return await fetch(url, { redirect: "manual", signal: controller.signal });
  } finally {
    clearTimeout(timer);
  }
}

function exactStrings(actual, expectedValues) {
  return Array.isArray(actual)
    && actual.every((entry) => typeof entry === "string")
    && [...actual].sort().join("\n") === [...expectedValues].sort().join("\n");
}

function assertProviderActive(rawData) {
  const router = rawData.routers?.[dockerRouterName];
  const service = rawData.services?.[dockerServiceName];
  if (router?.status !== "enabled"
    || router.rule !== dockerRule
    || router.service !== "legacy-service"
    || router.priority !== dockerRule.length
    || !exactStrings(router.entryPoints, ["web"])
    || !exactStrings(router.using, ["web"])) {
    throw new Error("the exact legacy Docker router is not enabled with canonical routing state");
  }
  const servers = service?.loadBalancer?.servers;
  const serverStatus = service?.serverStatus;
  if (service?.status !== "enabled"
    || !exactStrings(service.usedBy, [dockerRouterName])
    || !Array.isArray(servers)
    || servers.length === 0
    || serverStatus === null
    || typeof serverStatus !== "object") {
    throw new Error("the exact legacy Docker service has no typed healthy backend state");
  }
  const urls = servers.map((server) => server?.url);
  if (urls.some((url) => typeof url !== "string" || !/^http:\/\/[^/]+:3000$/u.test(url))
    || Object.keys(serverStatus).sort().join("\n") !== [...urls].sort().join("\n")
    || Object.values(serverStatus).some((status) => status !== "UP")) {
    throw new Error("the exact legacy Docker service backend inventory is not healthy");
  }
}

function assertProviderEvicted(rawData) {
  if (Object.hasOwn(rawData.routers ?? {}, dockerRouterName)
    || Object.hasOwn(rawData.services ?? {}, dockerServiceName)) {
    throw new Error("the exact legacy Docker route has not been fully evicted");
  }
}

async function providerState(state) {
  if (state !== "active" && state !== "evicted") {
    throw new Error(`unsupported provider state: ${state ?? "missing"}`);
  }
  const response = await request("http://traefik:8080/api/rawdata");
  if (response.status !== 200) {
    throw new Error(`Traefik rawdata returned HTTP ${response.status}`);
  }
  const rawData = await response.json();
  state === "active" ? assertProviderActive(rawData) : assertProviderEvicted(rawData);

  return state === "active"
    ? {
        router: dockerRouterName,
        routerStatus: rawData.routers[dockerRouterName].status,
        service: dockerServiceName,
        serviceStatus: rawData.services[dockerServiceName].status,
        serverStatus: rawData.services[dockerServiceName].serverStatus,
      }
    : { absentRouter: dockerRouterName, absentService: dockerServiceName };
}

async function routeState(revision, acknowledgement) {
  const response = await request("http://traefik/revision");
  const payload = await response.json();
  const actualAcknowledgement = response.headers.get("x-coolify-probe-ack");
  if (response.status !== 200 || payload.revision !== revision) {
    throw new Error(`expected ${revision} via HTTP 200, received ${payload.revision ?? "missing"} via HTTP ${response.status}`);
  }
  if ((acknowledgement === "none" && actualAcknowledgement !== null)
    || (acknowledgement !== "none" && actualAcknowledgement !== acknowledgement)) {
    throw new Error(`route acknowledgement mismatch: ${actualAcknowledgement ?? "missing"}`);
  }

  return { acknowledgement: actualAcknowledgement, revision: payload.revision, status: response.status };
}

async function awaitProof(proof) {
  const deadline = Date.now() + timeoutMilliseconds;
  let latestFailure = "no proof attempted";
  while (Date.now() < deadline) {
    try {
      return await proof();
    } catch (error) {
      latestFailure = error.message;
      await sleep(100);
    }
  }
  throw new Error(`proof timed out: ${latestFailure}`);
}

let proof;
if (mode === "assert-provider") {
  proof = await providerState(expected);
} else if (mode === "await-provider") {
  proof = await awaitProof(() => providerState(expected));
} else if (mode === "await-route") {
  if (acknowledgement === undefined) {
    throw new Error("await-route requires an acknowledgement or none");
  }
  proof = await awaitProof(() => routeState(expected, acknowledgement));
} else {
  throw new Error(`unsupported probe mode: ${mode ?? "missing"}`);
}

process.stdout.write(`${JSON.stringify({ expected, mode, proof, timeoutMilliseconds })}\n`);
