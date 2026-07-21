#!/usr/bin/env bash
# Read-only preflight for the shared Nexus Docker hosted repository.

set -euo pipefail
set +x

fail() {
    printf 'Nexus Docker write-policy preflight failed: %s\n' "$1" >&2
    exit 1
}

if [[ -z "${NEXUS_USERNAME:-}" || -z "${NEXUS_PASSWORD:-}" ]]; then
    fail 'NEXUS_USERNAME and NEXUS_PASSWORD must both be non-empty.'
fi

if [[ -z "${NEXUS_BASE_URL:-}" || -z "${NEXUS_DOCKER_REPOSITORY:-}" ]]; then
    fail 'NEXUS_BASE_URL and NEXUS_DOCKER_REPOSITORY must both be non-empty.'
fi

command -v curl >/dev/null 2>&1 || fail 'curl is required.'
command -v jq >/dev/null 2>&1 || fail 'jq is required.'

repository_url="${NEXUS_BASE_URL%/}/service/rest/v1/repositories/docker/hosted/${NEXUS_DOCKER_REPOSITORY}"
repository_json="$(mktemp "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/nexus-docker-write-policy.XXXXXX")"
trap 'rm -f "${repository_json}"' EXIT

if ! curl --fail --silent --show-error \
    --connect-timeout 10 --max-time 30 \
    --retry 3 --retry-all-errors --retry-delay 1 \
    --user "${NEXUS_USERNAME}:${NEXUS_PASSWORD}" \
    --header 'Accept: application/json' \
    --output "${repository_json}" \
    "${repository_url}"; then
    fail 'could not retrieve hosted repository configuration.'
fi

if ! jq -e 'type == "object"' "${repository_json}" >/dev/null 2>&1; then
    fail 'received malformed repository JSON.'
fi

if ! jq -e --arg repository "${NEXUS_DOCKER_REPOSITORY}" \
    '.name == $repository and .format == "docker" and .type == "hosted"' \
    "${repository_json}" >/dev/null 2>&1; then
    fail 'repository identity must exactly match the requested hosted Docker repository.'
fi

if ! jq -e '.online == true' "${repository_json}" >/dev/null 2>&1; then
    fail 'repository must be online.'
fi

if ! jq -e '(.storage | if type == "object" then .writePolicy == "ALLOW" else false end)' \
    "${repository_json}" >/dev/null 2>&1; then
    fail 'storage.writePolicy must be ALLOW; change the repository-owned release contract instead of mutating shared infrastructure.'
fi

printf 'Nexus Docker write-policy preflight passed for %s.\n' "${NEXUS_DOCKER_REPOSITORY}"
