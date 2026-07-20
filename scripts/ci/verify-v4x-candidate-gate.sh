#!/usr/bin/env bash
# Read-only proof that GitHub accepted this exact candidate/base binding.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ci/v4x-candidate-contract.sh
# shellcheck disable=SC1091
source "$SCRIPT_DIR/v4x-candidate-contract.sh"

candidate_sha="${1:-}"
base_sha="${2:-}"
repository="${SHIP_REPOSITORY:-${GITHUB_REPOSITORY:-williamacallahan/coolify}}"

usage() {
  printf 'usage: %s <candidate-sha> <base-sha>\n' "${0##*/}" >&2
  exit 64
}

[ -n "$candidate_sha" ] && [ -n "$base_sha" ] || usage
if ! v4x_candidate_is_sha "$candidate_sha" || ! v4x_candidate_is_sha "$base_sha"; then
  usage
fi
: "${GH_TOKEN:?GH_TOKEN is required for read-only GitHub Actions verification}"
command -v gh >/dev/null || { printf 'gh is required\n' >&2; exit 69; }
command -v jq >/dev/null || { printf 'jq is required\n' >&2; exit 69; }

workflow_id="$(gh api "repos/$repository/actions/workflows/$V4X_CANDIDATE_WORKFLOW" --jq .id)"
runs_endpoint="repos/$repository/actions/workflows/$workflow_id/runs?branch=$V4X_CANDIDATE_BASE_BRANCH&event=workflow_dispatch"
run_binding="$(
  gh api "$runs_endpoint&per_page=100" --paginate --slurp |
    jq -r --arg candidate_sha "$candidate_sha" --arg base_sha "$base_sha" --arg title_pattern "$V4X_CANDIDATE_RUN_TITLE_REGEX" '
      [ .[].workflow_runs[]?
        | ((.display_title // "") | try capture($title_pattern) catch empty) as $binding
        | select($binding.candidate_sha == $candidate_sha and $binding.base_sha == $base_sha)
        | select($binding.candidate_ref | test("^ship/v4x/" + $candidate_sha + "/[0-9a-f]{40}$"))
        | select(.event == "workflow_dispatch" and .status == "completed" and .conclusion == "success")
        | {id, candidate_ref: $binding.candidate_ref, display_title, created_at}
      ] | sort_by(.created_at) | last
      | if . == null then empty else [.id, .candidate_ref, .display_title] | @tsv end'
)"

IFS=$'\t' read -r run_id candidate_ref expected_title <<<"$run_binding"

[ -n "$run_id" ] || {
  printf 'No successful workflow_dispatch gate exists for Gate v4.x %s ship/v4x/%s/<40hex> from %s\n' \
    "$candidate_sha" "$candidate_sha" "$base_sha" >&2
  exit 1
}
expected_title="$(v4x_candidate_run_title "$candidate_sha" "$candidate_ref" "$base_sha")"

run_json="$(gh api "repos/$repository/actions/runs/$run_id")"
jq -e \
  --arg workflow_id "$workflow_id" \
  --arg workflow_path ".github/workflows/$V4X_CANDIDATE_WORKFLOW" \
  --arg branch "$V4X_CANDIDATE_BASE_BRANCH" \
  --arg base_sha "$base_sha" \
  --arg title "$expected_title" '
    (.workflow_id | tostring) == $workflow_id
    and ((.path // "") == $workflow_path or ((.path // "") | startswith($workflow_path + "@")))
    and .event == "workflow_dispatch"
    and .head_branch == $branch
    and .head_sha == $base_sha
    and .status == "completed"
    and .conclusion == "success"
    and .display_title == $title
  ' <<<"$run_json" >/dev/null || {
  printf 'Trusted gate run %s did not preserve the required workflow/ref/title binding.\n' "$run_id" >&2
  exit 1
}

printf 'Verified successful v4.x candidate gate run %s for %s from %s.\n' "$run_id" "$candidate_sha" "$base_sha"
