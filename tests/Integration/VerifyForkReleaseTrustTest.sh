#!/usr/bin/env bash

set -Eeuo pipefail

repository_root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT
bin="$fixture/bin"
mkdir -p "$bin"
verification_log="$fixture/verification.log"
ruleset_state="$fixture/ruleset-state"
printf '%s\n' matching > "$ruleset_state"

cat > "$bin/tag-verifier" <<'SH'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> "${VERIFICATION_LOG:?}"
SH
cat > "$bin/gh" <<'SH'
#!/bin/sh
set -eu
endpoint=''
for argument in "$@"; do
  case "$argument" in
    repos/*) endpoint=$argument ;;
  esac
done

case "$endpoint" in
  'repos/williamacallahan/coolify/rulesets?targets=tag&includes_parents=true&per_page=100')
    if [ "$(cat "${RULESET_STATE:?}")" = duplicate ]; then
      printf '[{"id":42,"name":"Protect Coolify fork release tags","enforcement":"active"},{"id":43,"name":"Protect Coolify fork release tags","enforcement":"active"}]\n'
    else
      printf '[{"id":42,"name":"Protect Coolify fork release tags","enforcement":"active"}]\n'
    fi
    ;;
  'repos/williamacallahan/coolify/rulesets/42')
    case "$(cat "${RULESET_STATE:?}")" in
      missing-bypass)
        printf '{"name":"Protect Coolify fork release tags","target":"tag","enforcement":"active","conditions":{"ref_name":{"include":["refs/tags/*.*.*-fork*"],"exclude":[]}},"rules":[{"type":"creation"},{"type":"update"},{"type":"deletion"}]}\n'
        exit 0
        ;;
      bypass)
        bypass='[{"actor_id":1}]'
        rules='[{"type":"creation"},{"type":"update"},{"type":"deletion"}]'
        ;;
      missing-deletion)
        bypass='[]'
        rules='[{"type":"creation"},{"type":"update"}]'
        ;;
      *)
        bypass='[]'
        rules='[{"type":"creation"},{"type":"update"},{"type":"deletion"}]'
        ;;
    esac
    printf '{"name":"Protect Coolify fork release tags","target":"tag","enforcement":"active","bypass_actors":%s,"conditions":{"ref_name":{"include":["refs/tags/*.*.*-fork*"],"exclude":[]}},"rules":%s}\n' "$bypass" "$rules"
    ;;
  *)
    printf 'unexpected endpoint: %s\n' "$endpoint" >&2
    exit 64
    ;;
esac
SH
chmod +x "$bin/tag-verifier" "$bin/gh"

run_verifier()
{
  FORK_RELEASE_TAG_VERIFIER="$bin/tag-verifier" \
  GH_CLI="$bin/gh" \
  RULESET_STATE="$ruleset_state" \
  RUNNER_TEMP="$fixture" \
  VERIFICATION_LOG="$verification_log" \
    "$repository_root/scripts/ci/verify-fork-release-trust.sh" \
    4.13.4-fork "$(printf 'a%.0s' {1..40})" \
    https://github.com/williamacallahan/coolify.git \
    docker/fork-release-tag-allowed-signers \
    williamacallahan/coolify
}

run_verifier
grep -Fx "4.13.4-fork $(printf 'a%.0s' {1..40}) https://github.com/williamacallahan/coolify.git docker/fork-release-tag-allowed-signers" "$verification_log" >/dev/null

for rejected_state in bypass missing-bypass missing-deletion duplicate; do
  printf '%s\n' "$rejected_state" > "$ruleset_state"
  if run_verifier >/dev/null 2>&1; then
    printf 'unsafe fork release ruleset state was accepted: %s\n' "$rejected_state" >&2
    exit 1
  fi
done

printf '%s\n' 'Fork release trust verification: PASS'
