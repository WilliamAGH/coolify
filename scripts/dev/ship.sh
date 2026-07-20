#!/usr/bin/env bash
# Submit a frozen v4.x candidate without advancing v4.x itself.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ci/v4x-candidate-contract.sh
# shellcheck disable=SC1091
source "$SCRIPT_DIR/../ci/v4x-candidate-contract.sh"

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

SHIP_REMOTE="${SHIP_REMOTE:-origin}"
SHIP_STATUS_LIMIT="${SHIP_STATUS_LIMIT:-1000}"
SHIP_STATUS_DISCOVERY_ATTEMPTS="${SHIP_STATUS_DISCOVERY_ATTEMPTS:-15}"
SHIP_STATUS_DISCOVERY_DELAY_SECONDS="${SHIP_STATUS_DISCOVERY_DELAY_SECONDS:-2}"
subcommand='ship'
dry_run=0
revision='HEAD'
revision_explicit=0

fail() {
  printf 'ship: %s\n' "$*" >&2
  exit 1
}

usage() {
  cat <<'USAGE'
usage:
  scripts/dev/ship.sh [--dry-run] [--revision <ref>] [--remote <name>]
  scripts/dev/ship.sh status

make ship SHIP_DRY_RUN=1 prints the exact candidate push and workflow dispatch
without contacting GitHub or changing Git state.
USAGE
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    status) subcommand='status' ;;
    --dry-run) dry_run=1 ;;
    --revision)
      [ "$#" -ge 2 ] || fail '--revision requires a value'
      revision="$2"; revision_explicit=1; shift
      ;;
    --remote)
      [ "$#" -ge 2 ] || fail '--remote requires a value'
      SHIP_REMOTE="$2"; shift
      ;;
    -h|--help) usage; exit 0 ;;
    *) fail "unknown argument: $1" ;;
  esac
  shift
done

candidate_commit() {
  git rev-parse "${1}^{commit}"
}

remote_base_commit() {
  git rev-parse "refs/remotes/$SHIP_REMOTE/$V4X_CANDIDATE_BASE_BRANCH^{commit}"
}

gate_runs_json() {
  gh run list --workflow "$V4X_CANDIDATE_WORKFLOW" --branch "$V4X_CANDIDATE_BASE_BRANCH" \
    --event workflow_dispatch --limit "$SHIP_STATUS_LIMIT" \
    --json databaseId,displayTitle,status,conclusion
}

# Emit id, ref, base, status, conclusion for a title that proves all bindings.
gate_binding_for() {
  local candidate_sha="$1" candidate_ref="${2:-}" base_sha="${3:-}"
  gate_runs_json | jq -r \
    --arg candidate_sha "$candidate_sha" \
    --arg candidate_ref "$candidate_ref" \
    --arg base_sha "$base_sha" \
    --arg title_pattern "$V4X_CANDIDATE_RUN_TITLE_REGEX" '
      [ .[]
        | ((.displayTitle // "") | try capture($title_pattern) catch empty) as $binding
        | select($binding.candidate_sha == $candidate_sha)
        | select($binding.candidate_ref | test("^ship/v4x/" + $candidate_sha + "/[0-9a-f]{40}$"))
        | select($candidate_ref == "" or $binding.candidate_ref == $candidate_ref)
        | select($base_sha == "" or $binding.base_sha == $base_sha)
        | {databaseId, candidate_ref: $binding.candidate_ref, base_sha: $binding.base_sha, status, conclusion}
      ]
      | ((map(select(.status == "completed" and .conclusion == "success")) | first)
         // (map(select(.status != "completed")) | first)
         // first)
      | if . == null then empty else [.databaseId, .candidate_ref, .base_sha, .status, (.conclusion // "")] | @tsv end'
}

newest_gate_binding() {
  gate_runs_json | jq -r --arg title_pattern "$V4X_CANDIDATE_RUN_TITLE_REGEX" '
    [ .[]
      | ((.displayTitle // "") | try capture($title_pattern) catch empty) as $binding
      | select($binding.candidate_ref | test("^ship/v4x/" + $binding.candidate_sha + "/[0-9a-f]{40}$"))
      | {databaseId, candidate_sha: $binding.candidate_sha, candidate_ref: $binding.candidate_ref,
         base_sha: $binding.base_sha, status, conclusion}
    ]
    | first
    | if . == null then empty else [.databaseId, .candidate_sha, .candidate_ref, .base_sha, .status, (.conclusion // "")] | @tsv end'
}

wait_for_exact_gate() {
  local candidate_sha="$1" candidate_ref="$2" base_sha="$3" binding attempt
  for ((attempt = 1; attempt <= SHIP_STATUS_DISCOVERY_ATTEMPTS; attempt++)); do
    binding="$(gate_binding_for "$candidate_sha" "$candidate_ref" "$base_sha")"
    [ -z "$binding" ] || { printf '%s\n' "$binding"; return; }
    [ "$attempt" -eq "$SHIP_STATUS_DISCOVERY_ATTEMPTS" ] || sleep "$SHIP_STATUS_DISCOVERY_DELAY_SECONDS"
  done
  fail "no exact gate run found for $(v4x_candidate_run_title "$candidate_sha" "$candidate_ref" "$base_sha")"
}

print_reattach() {
  printf 'Reattach: make ship-status FOLLOW=%s CANDIDATE_REF=%s BASE_SHA=%s\n' "$1" "$2" "$3"
}

stream_gate() {
  local run_id="$1" candidate_sha="$2" candidate_ref="$3" base_sha="$4"
  printf 'Streaming v4.x candidate gate run %s.\n' "$run_id"
  print_reattach "$candidate_sha" "$candidate_ref" "$base_sha"
  if gh run watch "$run_id" --exit-status; then
    printf 'Candidate gate is green for %s.\n' "$candidate_sha"
  else
    printf 'ship: gate did not finish successfully. ' >&2
    print_reattach "$candidate_sha" "$candidate_ref" "$base_sha" >&2
    return 1
  fi
}

guard_default_head() {
  local branch dirty
  branch="$(git branch --show-current)"
  [ "$branch" = "$V4X_CANDIDATE_BASE_BRANCH" ] || fail "current branch is $branch; expected $V4X_CANDIDATE_BASE_BRANCH"
  dirty="$(git status --porcelain)"
  [ -z "$dirty" ] || fail 'working tree must be fully committed when shipping HEAD'
}

cmd_ship() {
  local candidate_sha candidate_generation candidate_ref base_sha binding run_id existing_ref existing_base existing_status existing_conclusion
  if [ "$dry_run" -eq 0 ] && [ "$revision_explicit" -eq 0 ]; then
    guard_default_head
  fi

  candidate_sha="$(candidate_commit "$revision")"
  candidate_generation="$(v4x_candidate_generation "$candidate_sha")"
  candidate_ref="$(v4x_candidate_ref_for_sha "$candidate_sha" "$candidate_generation")"

  if [ "$dry_run" -eq 1 ]; then
    base_sha="$(remote_base_commit 2>/dev/null || printf '<%s/%s-sha>' "$SHIP_REMOTE" "$V4X_CANDIDATE_BASE_BRANCH")"
    printf '[dry-run] git push %s %s:refs/heads/%s\n' "$SHIP_REMOTE" "$candidate_sha" "$candidate_ref"
    printf '[dry-run] gh workflow run %s --ref %s -f candidate_sha=%s -f candidate_ref=%s -f base_sha=%s\n' \
      "$V4X_CANDIDATE_WORKFLOW" "$V4X_CANDIDATE_BASE_BRANCH" "$candidate_sha" "$candidate_ref" "$base_sha"
    printf '[dry-run] expected run title: Gate v4.x %s %s from %s\n' "$candidate_sha" "$candidate_ref" "$base_sha"
    return
  fi

  git fetch --quiet "$SHIP_REMOTE" "refs/heads/$V4X_CANDIDATE_BASE_BRANCH:refs/remotes/$SHIP_REMOTE/$V4X_CANDIDATE_BASE_BRANCH"
  base_sha="$(remote_base_commit)"
  git merge-base --is-ancestor "$base_sha" "$candidate_sha" || \
    fail "$SHIP_REMOTE/$V4X_CANDIDATE_BASE_BRANCH is not an ancestor of $candidate_sha; pull/rebase before shipping"

  binding="$(gate_binding_for "$candidate_sha" '' "$base_sha")"
  if [ -n "$binding" ]; then
    IFS=$'\t' read -r run_id existing_ref existing_base existing_status existing_conclusion <<<"$binding"
    if [ "$existing_status" = completed ] && [ "$existing_conclusion" = success ]; then
      printf 'Exact candidate gate is already green for %s.\n' "$candidate_sha"
      print_reattach "$candidate_sha" "$existing_ref" "$existing_base"
      return
    fi
    if [ "$existing_status" != completed ]; then
      printf 'Reusing active exact candidate gate run %s for %s.\n' "$run_id" "$candidate_sha"
      stream_gate "$run_id" "$candidate_sha" "$existing_ref" "$existing_base"
      return
    fi
  fi

  git push "$SHIP_REMOTE" "$candidate_sha:refs/heads/$candidate_ref"
  gh workflow run "$V4X_CANDIDATE_WORKFLOW" --ref "$V4X_CANDIDATE_BASE_BRANCH" \
    -f "candidate_sha=$candidate_sha" -f "candidate_ref=$candidate_ref" -f "base_sha=$base_sha"
  printf 'Submitted exact candidate gate for %s.\n' "$candidate_sha"
  print_reattach "$candidate_sha" "$candidate_ref" "$base_sha"
  binding="$(wait_for_exact_gate "$candidate_sha" "$candidate_ref" "$base_sha")"
  IFS=$'\t' read -r run_id _ _ _ _ <<<"$binding"
  stream_gate "$run_id" "$candidate_sha" "$candidate_ref" "$base_sha"
}

cmd_status() {
  local candidate_sha="${FOLLOW:-}" candidate_ref="${CANDIDATE_REF:-}" base_sha="${BASE_SHA:-}" binding run_id
  if [ -n "${HISTORY:-}" ]; then
    gate_runs_json | jq -r --arg title_pattern "$V4X_CANDIDATE_RUN_TITLE_REGEX" '
      .[] | select((.displayTitle // "") | test($title_pattern))
      | [.databaseId, .status, (.conclusion // "pending"), .displayTitle] | @tsv'
    return
  fi
  if [ -z "$candidate_sha" ] && [ -n "${WATCH:-}" ]; then
    candidate_sha="$(candidate_commit HEAD)"
  fi
  if [ -n "$candidate_sha" ]; then
    if ! v4x_candidate_is_sha "$candidate_sha"; then
      candidate_sha="$(candidate_commit "$candidate_sha")"
    fi
    binding="$(gate_binding_for "$candidate_sha" "$candidate_ref" "$base_sha")"
    [ -n "$binding" ] || fail 'no exact v4.x candidate gate run found'
    IFS=$'\t' read -r run_id candidate_ref base_sha _ _ <<<"$binding"
  else
    binding="$(newest_gate_binding)"
    [ -n "$binding" ] || fail 'no exact v4.x candidate gate run found'
    IFS=$'\t' read -r run_id candidate_sha candidate_ref base_sha _ _ <<<"$binding"
  fi
  stream_gate "$run_id" "$candidate_sha" "$candidate_ref" "$base_sha"
}

case "$subcommand" in
  ship) cmd_ship ;;
  status) cmd_status ;;
esac
