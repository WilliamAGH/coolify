#!/usr/bin/env bash

set -Eeuo pipefail

semantic_version=${1:-}
source_revision=${2:-}
remote_url=${3:-}
allowed_signers_file=${4:-}
repository=${5:-}
repository_pattern='^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$'
fork_ruleset_name='Protect Coolify fork release tags'
fork_tag_pattern='refs/tags/*.*.*-fork*'
ruleset_token=${FORK_RELEASE_RULESET_TOKEN:-}
unset FORK_RELEASE_RULESET_TOKEN GH_TOKEN

[[ "$repository" =~ $repository_pattern ]]
[[ -n "$ruleset_token" ]]

script_directory=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
tag_verifier=${FORK_RELEASE_TAG_VERIFIER:-$script_directory/verify-fork-release-tag.sh}
gh_cli=${GH_CLI:-gh}

if [[ "$gh_cli" == */* ]]; then
  [[ -x "$gh_cli" ]]
else
  command -v "$gh_cli" >/dev/null
fi

"$tag_verifier" \
  "$semantic_version" "$source_revision" \
  "$remote_url" "$allowed_signers_file"

rulesets_file=$(mktemp "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/coolify-fork-rulesets.XXXXXX")
ruleset_file=$(mktemp "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/coolify-fork-ruleset.XXXXXX")
trap 'rm -f "$rulesets_file" "$ruleset_file"' EXIT

GH_TOKEN="$ruleset_token" "$gh_cli" api --method GET \
  --header 'Accept: application/vnd.github+json' \
  --header 'X-GitHub-Api-Version: 2022-11-28' \
  "repos/$repository/rulesets?targets=tag&includes_parents=true&per_page=100" > "$rulesets_file"
ruleset_id=$(jq -er --arg name "$fork_ruleset_name" '
  [.[] | select(.name == $name and .enforcement == "active")] |
  if length == 1 then .[0].id else error("expected exactly one active fork tag ruleset") end
' "$rulesets_file")
printf '%s' "$ruleset_id" | grep -Eq '^[1-9][0-9]*$'

GH_TOKEN="$ruleset_token" "$gh_cli" api --method GET \
  --header 'Accept: application/vnd.github+json' \
  --header 'X-GitHub-Api-Version: 2022-11-28' \
  "repos/$repository/rulesets/$ruleset_id" > "$ruleset_file"
jq -e --arg name "$fork_ruleset_name" --arg pattern "$fork_tag_pattern" '
  (
    .name == $name and
    .target == "tag" and
    .enforcement == "active" and
    (.bypass_actors | type == "array") and
    (.bypass_actors == []) and
    .conditions.ref_name.include == [$pattern] and
    ((.conditions.ref_name.exclude // []) == [])
  ) and
  (([.rules[].type] | unique) as $rule_types |
    (($rule_types | index("creation")) != null) and
    (($rule_types | index("update")) != null) and
    (($rule_types | index("deletion")) != null)
  )
' "$ruleset_file" >/dev/null || {
  printf 'The active fork release tag ruleset does not exactly protect %s with no bypass actors and creation, update, and deletion controls.\n' \
    "$fork_tag_pattern" >&2
  exit 1
}
