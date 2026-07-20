#!/usr/bin/env node
import { appendFileSync } from "node:fs";
import { parseArgs } from "node:util";

const { values } = parseArgs({
    options: {
        branch: { type: "string" },
        version: { type: "string" },
        sha: { type: "string" },
        "image-base": { type: "string" },
        format: { type: "string" },
    },
});

const rawBranch = values.branch ?? process.env.BRANCH ?? "unknown";
const version = values.version ?? process.env.VERSION;
const sha = values.sha ?? process.env.SHA ?? "0000000";
const imageBase = values["image-base"] ?? process.env.IMAGE_BASE;
const formatWasExplicit = values.format !== undefined;
const format = values.format ?? "json";

if (!imageBase) {
    throw new Error("--image-base or IMAGE_BASE is required");
}

const branch = sanitizeDockerToken(
    rawBranch.replace(/^refs\/(heads|tags)\//, ""),
);
const shortSha = sha.slice(0, 7);
const tags = [`${imageBase}:${branch}-latest`];

if (version) {
    const safeVersion = sanitizeDockerToken(version);
    tags.push(`${imageBase}:${branch}-${safeVersion}`);
    tags.push(`${imageBase}:${branch}-${safeVersion}-${shortSha}`);
} else {
    tags.push(`${imageBase}:${branch}-${shortSha}`);
}

if (process.env.GITHUB_OUTPUT && !formatWasExplicit) {
    appendFileSync(process.env.GITHUB_OUTPUT, `branch=${branch}\n`);
    appendFileSync(process.env.GITHUB_OUTPUT, `short_sha=${shortSha}\n`);
    appendFileSync(process.env.GITHUB_OUTPUT, `image_base=${imageBase}\n`);
    appendFileSync(
        process.env.GITHUB_OUTPUT,
        `tags<<EOF\n${tags.join("\n")}\nEOF\n`,
    );
} else if (format === "docker-args") {
    console.log(tags.map((tag) => `-t ${tag}`).join(" "));
} else if (format === "tags") {
    console.log(tags.join("\n"));
} else {
    console.log(
        JSON.stringify(
            { branch, short_sha: shortSha, image_base: imageBase, tags },
            null,
            2,
        ),
    );
}

function sanitizeDockerToken(value) {
    return value
        .replace(/\//g, "-")
        .toLowerCase()
        .replace(/[^a-z0-9._-]/g, "-")
        .replace(/^-+/, "")
        .slice(0, 128);
}
