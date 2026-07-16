import { readFile } from "node:fs/promises";

const accessLogPath = process.argv[2];
if (accessLogPath === undefined) {
  throw new Error("usage: node access-log-summary.mjs <traefik-access-log>");
}

const status = {};
const originStatus = {};
let forbidden404 = 0;
let forbidden5xx = 0;
let malformedAccessRecord = 0;
let requestCount = 0;
const accessLog = await readFile(accessLogPath, "utf8");

for (const line of accessLog.split("\n")) {
  if (line === "") {
    continue;
  }

  let record;
  try {
    record = JSON.parse(line.slice(line.indexOf("{")));
  } catch {
    continue;
  }
  if (record.RequestPath === undefined) {
    continue;
  }
  if (!Number.isSafeInteger(record.DownstreamStatus)) {
    malformedAccessRecord += 1;
    continue;
  }

  requestCount += 1;
  const code = String(record.DownstreamStatus);
  status[code] = (status[code] ?? 0) + 1;
  if (Number.isSafeInteger(record.OriginStatus)) {
    const originCode = String(record.OriginStatus);
    originStatus[originCode] = (originStatus[originCode] ?? 0) + 1;
  }
  if (record.DownstreamStatus === 404 || record.OriginStatus === 404) {
    forbidden404 += 1;
  }
  if ((record.DownstreamStatus >= 500 && record.DownstreamStatus <= 599)
    || (record.OriginStatus >= 500 && record.OriginStatus <= 599)) {
    forbidden5xx += 1;
  }
}

const summary = {
  event: "traefik-access-summary",
  forbidden404,
  forbidden5xx,
  malformedAccessRecord,
  originStatus: Object.fromEntries(Object.entries(originStatus).sort(([left], [right]) => Number(left) - Number(right))),
  requestCount,
  status: Object.fromEntries(Object.entries(status).sort(([left], [right]) => Number(left) - Number(right))),
};

process.stdout.write(`${JSON.stringify(summary)}\n`);
if (summary.requestCount === 0 || summary.forbidden404 > 0 || summary.forbidden5xx > 0 || summary.malformedAccessRecord > 0) {
  process.exitCode = 1;
}
