#!/usr/bin/env bash
set -euo pipefail

script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
derive_image_tags="$script_dir/deriveImageTags.mjs"
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

assert_equals() {
    local expected=$1 actual=$2 description=$3
    [[ "$actual" == "$expected" ]] || fail "$description: expected <$expected>, got <$actual>"
}

github_output="$fixture/github-output"
expected_tags=$'docker.iocloudhost.net/williamagh/coolify:fork-latest\ndocker.iocloudhost.net/williamagh/coolify:fork-4.13.3-fork\ndocker.iocloudhost.net/williamagh/coolify:fork-4.13.3-fork-dfaa830'
printf 'sentinel\n' >"$github_output"
actual=$(GITHUB_OUTPUT="$github_output" node "$derive_image_tags" \
    --branch fork --version 4.13.3-fork --sha dfaa8302d43845dcb586802216db0b886c072233 \
    --image-base docker.iocloudhost.net/williamagh/coolify --format tags)
assert_equals "$expected_tags" "$actual" 'explicit tags format'
assert_equals sentinel "$(<"$github_output")" 'explicit format must leave GITHUB_OUTPUT unchanged'

expected_args='-t docker.iocloudhost.net/williamagh/coolify:fork-latest -t docker.iocloudhost.net/williamagh/coolify:fork-4.13.3-fork -t docker.iocloudhost.net/williamagh/coolify:fork-4.13.3-fork-dfaa830'
actual=$(node "$derive_image_tags" \
    --branch fork --version 4.13.3-fork --sha dfaa8302d43845dcb586802216db0b886c072233 \
    --image-base docker.iocloudhost.net/williamagh/coolify --format docker-args)
assert_equals "$expected_args" "$actual" 'explicit docker-args format'

: >"$github_output"
actual=$(GITHUB_OUTPUT="$github_output" node "$derive_image_tags" \
    --branch refs/heads/fork --version 4.13.3-fork --sha dfaa8302d43845dcb586802216db0b886c072233 \
    --image-base docker.iocloudhost.net/williamagh/coolify)
assert_equals '' "$actual" 'implicit format must write action output only'
grep -Fqx 'branch=fork' "$github_output" || fail 'branch output missing'
grep -Fqx 'short_sha=dfaa830' "$github_output" || fail 'short SHA output missing'
grep -Fqx 'latest_tag=docker.iocloudhost.net/williamagh/coolify:fork-latest' "$github_output" || fail 'latest tag scalar output missing'
grep -Fqx 'version_tag=docker.iocloudhost.net/williamagh/coolify:fork-4.13.3-fork' "$github_output" || fail 'version tag scalar output missing'
grep -Fqx 'version_sha_tag=docker.iocloudhost.net/williamagh/coolify:fork-4.13.3-fork-dfaa830' "$github_output" || fail 'version SHA tag scalar output missing'
grep -Fqx 'docker.iocloudhost.net/williamagh/coolify:fork-latest' "$github_output" || fail 'latest tag output missing'
grep -Fqx 'docker.iocloudhost.net/williamagh/coolify:fork-4.13.3-fork-dfaa830' "$github_output" || fail 'hash tag output missing'

oversized_version="1.0.1$(printf '0%.0s' {1..106})-fork"
if node "$derive_image_tags" \
    --branch fork --version "$oversized_version" --sha dfaa8302d43845dcb586802216db0b886c072233 \
    --image-base docker.iocloudhost.net/williamagh/coolify --format tags >/dev/null 2>&1; then
    fail 'derived OCI tags longer than 128 characters must be rejected'
fi

printf 'ok: deriveImageTags action output modes\n'
