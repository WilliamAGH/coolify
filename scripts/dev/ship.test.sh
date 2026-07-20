#!/usr/bin/env bash
# Contract tests for the v4.x candidate ship path. They make no network calls.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/../.." && pwd)"

fail() {
  printf 'FAIL: %s\n' "$1" >&2
  exit 1
}

pass() {
  printf 'ok: %s\n' "$1"
}

test_dry_run_plan() {
  local work sha base generation candidate_ref out calls
  work="$(mktemp -d)"
  mkdir -p "$work/bin"
  sha='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
  base='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
  generation='cccccccccccccccccccccccccccccccccccccccc'
  candidate_ref="ship/v4x/$sha/$generation"
  calls="$work/git.calls"

  cat >"$work/bin/git" <<'FAKE_GIT'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >>"$MOCK_GIT_CALLS"
case "${1:-} ${2:-}" in
  'rev-parse --show-toplevel') printf '%s\n' "$MOCK_ROOT" ;;
  'rev-parse HEAD^{commit}') printf '%s\n' "$MOCK_SHA" ;;
  'rev-parse refs/remotes/origin/v4.x^{commit}') printf '%s\n' "$MOCK_BASE" ;;
  'remote get-url') printf '%s\n' 'https://github.com/WilliamAGH/coolify.git' ;;
  'hash-object --stdin') cat >/dev/null; printf '%s\n' "$MOCK_GENERATION" ;;
  *) printf 'unexpected git arguments: %s\n' "$*" >&2; exit 2 ;;
esac
FAKE_GIT
  cat >"$work/bin/gh" <<'FAKE_GH'
#!/usr/bin/env bash
printf 'gh must not run during ship dry-run: %s\n' "$*" >&2
exit 2
FAKE_GH
  chmod +x "$work/bin/git" "$work/bin/gh"

  out="$(cd "$ROOT" && BASH_ENV=/dev/null PATH="$work/bin:$PATH" MOCK_ROOT="$ROOT" MOCK_SHA="$sha" MOCK_BASE="$base" \
    MOCK_GENERATION="$generation" MOCK_GIT_CALLS="$calls" make --no-print-directory ship SHIP_DRY_RUN=1)" \
    || fail 'make ship SHIP_DRY_RUN=1 exited non-zero'
  printf '%s\n' "$out" | grep -Fq "[dry-run] git push origin $sha:refs/heads/$candidate_ref" \
    || fail 'dry-run must plan the exact candidate push'
  printf '%s\n' "$out" | grep -Fq "[dry-run] gh workflow run gate-v4x-candidate.yml --ref v4.x --repo williamacallahan/coolify -f candidate_sha=$sha -f candidate_ref=$candidate_ref -f base_sha=$base" \
    || fail 'dry-run must plan the exact trusted dispatch'
  printf '%s\n' "$out" | grep -Fq -- "--repo williamacallahan/coolify" \
    || fail 'dry-run must target the repository derived from the selected remote'
  printf '%s\n' "$out" | grep -Fq "Gate v4.x $sha $candidate_ref from $base" \
    || fail 'dry-run must expose the fully bound run title'
  if grep -Eq '^(fetch|push)( |$)' "$calls"; then
    fail 'dry-run must not fetch or push'
  fi
  rm -rf "$work"
  pass 'dry_run_plan'
}

test_canonical_contract() {
  local sha base generation candidate_ref title
  sha='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
  base='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
  generation='cccccccccccccccccccccccccccccccccccccccc'
  # shellcheck source=scripts/ci/v4x-candidate-contract.sh
  # shellcheck disable=SC1091
  source "$ROOT/scripts/ci/v4x-candidate-contract.sh"
  [ "$V4X_CANDIDATE_BASE_BRANCH" = v4.x ] || fail 'base branch must be canonical'
  [ "$V4X_CANDIDATE_WORKFLOW" = gate-v4x-candidate.yml ] || fail 'workflow must be canonical'
  candidate_ref="$(v4x_candidate_ref_for_sha "$sha" "$generation")"
  [ "$candidate_ref" = "ship/v4x/$sha/$generation" ] || fail 'candidate ref shape must be canonical'
  v4x_candidate_ref_matches "$candidate_ref" "$sha" || fail 'candidate ref must bind its SHA'
  title="$(v4x_candidate_run_title "$sha" "$candidate_ref" "$base")"
  [ "$title" = "Gate v4.x $sha $candidate_ref from $base" ] || fail 'title must bind SHA/ref/base'
  grep -Fq "source \"\$SCRIPT_DIR/../ci/v4x-candidate-contract.sh\"" "$ROOT/scripts/dev/ship.sh" \
    || fail 'ship must source the canonical contract'
  grep -Fq "source \"\$SCRIPT_DIR/v4x-candidate-contract.sh\"" "$ROOT/scripts/ci/verify-v4x-candidate-gate.sh" \
    || fail 'verifier must source the canonical contract'
  pass 'canonical_contract'
}

test_ship_guards_and_dispatch_wiring() {
  local ship="$ROOT/scripts/dev/ship.sh"
  grep -Fq 'git branch --show-current' "$ship" || fail 'ship must guard the current branch'
  grep -Fq 'git status --porcelain' "$ship" || fail 'ship must guard an uncommitted HEAD snapshot'
  grep -Fq "git fetch --quiet \"\$SHIP_REMOTE\" \"refs/heads/\$V4X_CANDIDATE_BASE_BRANCH:refs/remotes/\$SHIP_REMOTE/\$V4X_CANDIDATE_BASE_BRANCH\"" "$ship" \
    || fail 'ship must refresh origin/v4.x before shipping'
  grep -Fq "git merge-base --is-ancestor \"\$base_sha\" \"\$candidate_sha\"" "$ship" \
    || fail 'ship must require the remote base to be an ancestor'
  grep -Fq "gh workflow run \"\$V4X_CANDIDATE_WORKFLOW\" --ref \"\$V4X_CANDIDATE_BASE_BRANCH\"" "$ship" \
    || fail 'ship must dispatch the canonical workflow from v4.x'
  grep -Fq -- "--repo \"\$GH_REPO\"" "$ship" \
    || fail 'every gh operation must explicitly target the pushed repository'
  grep -Fq -- "-f \"candidate_sha=\$candidate_sha\" -f \"candidate_ref=\$candidate_ref\" -f \"base_sha=\$base_sha\"" "$ship" \
    || fail 'ship must bind all workflow_dispatch inputs'
  grep -Fq 'print_reattach' "$ship" || fail 'ship must provide reattach guidance'
  if grep -R -n -- '--no-verify' "$ROOT/Makefile" "$ROOT/make/ship.mk" "$ROOT/scripts/ci/v4x-candidate-contract.sh" \
    "$ROOT/scripts/ci/verify-v4x-candidate-gate.sh" "$ROOT/scripts/dev/ship.sh" >/dev/null; then
    fail 'candidate ship tooling must preserve Git hooks'
  fi
  pass 'ship_guards_and_dispatch_wiring'
}

test_verifier_wiring() {
  local verifier="$ROOT/scripts/ci/verify-v4x-candidate-gate.sh"
  grep -Fq 'GH_TOKEN is required' "$verifier" || fail 'verifier must require GH_TOKEN'
  grep -Fq 'gh api' "$verifier" || fail 'verifier must read GitHub through gh'
  grep -Fq "capture(\$title_pattern)" "$verifier" || fail 'verifier must parse the exact title contract'
  grep -Fq '.event == "workflow_dispatch"' "$verifier" || fail 'verifier must require workflow_dispatch'
  grep -Fq '.conclusion == "success"' "$verifier" || fail 'verifier must require a successful verdict'
  grep -Fq "v4x_candidate_run_title \"\$candidate_sha\" \"\$candidate_ref\" \"\$base_sha\"" "$verifier" \
    || fail 'verifier must bind candidate SHA/ref/base through the canonical title'
  pass 'verifier_wiring'
}

test_status_and_verifier_exact_binding() {
  local work sha base generation candidate_ref wrong_ref title runs run_json out
  work="$(mktemp -d)"
  mkdir -p "$work/bin"
  sha='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
  base='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
  generation='cccccccccccccccccccccccccccccccccccccccc'
  candidate_ref="ship/v4x/$sha/$generation"
  wrong_ref="ship/v4x/eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee/dddddddddddddddddddddddddddddddddddddddd"
  title="Gate v4.x $sha $candidate_ref from $base"
  runs="[{\"workflow_runs\":[{\"id\":4111,\"display_title\":\"Gate v4.x $sha $wrong_ref from $base\",\"event\":\"workflow_dispatch\",\"status\":\"completed\",\"conclusion\":\"success\",\"created_at\":\"2026-07-20T00:00:00Z\"},{\"id\":4242,\"display_title\":\"$title\",\"event\":\"workflow_dispatch\",\"status\":\"completed\",\"conclusion\":\"success\",\"created_at\":\"2026-07-20T00:01:00Z\"}]}]"
  run_json="{\"workflow_id\":77,\"path\":\".github/workflows/gate-v4x-candidate.yml@refs/heads/v4.x\",\"event\":\"workflow_dispatch\",\"head_branch\":\"v4.x\",\"head_sha\":\"$base\",\"status\":\"completed\",\"conclusion\":\"success\",\"display_title\":\"$title\"}"

  cat >"$work/bin/git" <<'FAKE_GIT'
#!/usr/bin/env bash
set -euo pipefail
case "${1:-} ${2:-}" in
  'rev-parse --show-toplevel') printf '%s\n' "$MOCK_ROOT" ;;
  'remote get-url') printf '%s\n' 'https://github.com/WilliamAGH/coolify.git' ;;
  *) printf 'unexpected git arguments: %s\n' "$*" >&2; exit 2 ;;
esac
FAKE_GIT
  cat >"$work/bin/gh" <<'FAKE_GH'
#!/usr/bin/env bash
set -euo pipefail
case "${1:-} ${2:-}" in
  'run list')
    [[ "$*" == *"--repo williamacallahan/coolify"* ]] || exit 3
    printf '%s\n' "$MOCK_RUN_LIST"
    ;;
  'run watch')
    [[ "$*" == *"--repo williamacallahan/coolify"* ]] || exit 3
    printf 'gh %s\n' "$*" >>"$MOCK_GH_CALLS"
    ;;
  'api repos/'*)
    case "$2" in
      *'/actions/workflows/gate-v4x-candidate.yml') printf '77\n' ;;
      *'/actions/workflows/77/runs?'*) printf '%s\n' "$MOCK_API_RUNS" ;;
      *'/actions/runs/4242') printf '%s\n' "$MOCK_RUN_JSON" ;;
      *) printf 'unexpected gh api endpoint: %s\n' "$2" >&2; exit 2 ;;
    esac
    ;;
  *) printf 'unexpected gh arguments: %s\n' "$*" >&2; exit 2 ;;
esac
FAKE_GH
  chmod +x "$work/bin/git" "$work/bin/gh"

  out="$(cd "$ROOT" && BASH_ENV=/dev/null PATH="$work/bin:$PATH" MOCK_ROOT="$ROOT" FOLLOW="$sha" CANDIDATE_REF="$candidate_ref" BASE_SHA="$base" \
    MOCK_RUN_LIST="$(printf '%s' "$runs" | jq '.[0].workflow_runs | map({databaseId: .id, displayTitle: .display_title, status, conclusion})')" \
    MOCK_GH_CALLS="$work/gh.calls" scripts/dev/ship.sh status)" \
    || fail 'ship-status must watch the exact titled run'
  printf '%s\n' "$out" | grep -Fq 'Streaming v4.x candidate gate run 4242.' \
    || fail 'ship-status must resolve the exact display title'
  grep -Fq 'run watch 4242 --exit-status' "$work/gh.calls" \
    || fail 'ship-status must stream the exact resolved run'

  out="$(cd "$ROOT" && BASH_ENV=/dev/null PATH="$work/bin:$PATH" GH_TOKEN='test-token' GITHUB_REPOSITORY='williamacallahan/coolify' \
    MOCK_API_RUNS="$runs" MOCK_RUN_JSON="$run_json" scripts/ci/verify-v4x-candidate-gate.sh "$sha" "$base")" \
    || fail 'verifier must accept the exact successful workflow_dispatch run'
  printf '%s\n' "$out" | grep -Fq 'Verified successful v4.x candidate gate run 4242' \
    || fail 'verifier must report the exact bound run'
  if (cd "$ROOT" && BASH_ENV=/dev/null PATH="$work/bin:$PATH" GH_TOKEN='test-token' GITHUB_REPOSITORY='williamacallahan/coolify' \
    MOCK_API_RUNS="$(printf '%s' "$runs" | jq --arg title "Gate v4.x $sha $wrong_ref from $base" \
      '[{workflow_runs: [.[]?.workflow_runs[] | select(.id == 4111) | .display_title = $title]}]')" \
    MOCK_RUN_JSON="$run_json" scripts/ci/verify-v4x-candidate-gate.sh "$sha" "$base") >/dev/null 2>&1; then
    fail 'verifier must reject a title whose candidate ref binds a different SHA'
  fi
  if (cd "$ROOT" && BASH_ENV=/dev/null PATH="$work/bin:$PATH" GH_TOKEN='test-token' GITHUB_REPOSITORY='williamacallahan/coolify' \
    MOCK_API_RUNS="$runs" MOCK_RUN_JSON="$(printf '%s' "$run_json" | jq --arg head_sha 'dddddddddddddddddddddddddddddddddddddddd' '.head_sha = $head_sha')" \
    scripts/ci/verify-v4x-candidate-gate.sh "$sha" "$base") >/dev/null 2>&1; then
    fail 'verifier must reject a run whose head SHA does not equal the bound base SHA'
  fi
  rm -rf "$work"
  pass 'status_and_verifier_exact_binding'
}

test_remote_resolution_and_dispatch_cleanup() {
  local work sha base generation candidate_ref out
  work="$(mktemp -d)"
  mkdir -p "$work/bin"
  sha='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
  base='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
  generation='cccccccccccccccccccccccccccccccccccccccc'
  candidate_ref="ship/v4x/$sha/$generation"

  cat >"$work/bin/git" <<'FAKE_GIT'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >>"$MOCK_GIT_CALLS"
case "${1:-} ${2:-}" in
  'rev-parse --show-toplevel') printf '%s\n' "$MOCK_ROOT" ;;
  'rev-parse HEAD^{commit}') printf '%s\n' "$MOCK_SHA" ;;
  'rev-parse refs/remotes/origin/v4.x^{commit}') printf '%s\n' "$MOCK_BASE" ;;
  'remote get-url') printf '%s\n' "$MOCK_REMOTE_URL" ;;
  'hash-object --stdin') cat >/dev/null; printf '%s\n' "$MOCK_GENERATION" ;;
  'fetch --quiet') ;;
  'merge-base --is-ancestor') ;;
  'push origin')
    if [[ "$*" == *"--force-with-lease="* ]] && [ "${MOCK_CLEANUP_FAIL:-0}" = 1 ]; then
      exit 23
    fi
    ;;
  *) printf 'unexpected git arguments: %s\n' "$*" >&2; exit 2 ;;
esac
FAKE_GIT
  cat >"$work/bin/gh" <<'FAKE_GH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >>"$MOCK_GH_CALLS"
case "${1:-} ${2:-}" in
  'run list') printf '[]\n' ;;
  'workflow run') exit 17 ;;
  *) printf 'unexpected gh arguments: %s\n' "$*" >&2; exit 2 ;;
esac
FAKE_GH
  chmod +x "$work/bin/git" "$work/bin/gh"

  if out="$(cd "$ROOT" && BASH_ENV=/dev/null PATH="$work/bin:$PATH" MOCK_ROOT="$ROOT" MOCK_SHA="$sha" MOCK_BASE="$base" \
    MOCK_GENERATION="$generation" MOCK_REMOTE_URL='https://github.com/WilliamAGH/coolify.git' \
    MOCK_CANDIDATE_REF="$candidate_ref" MOCK_GIT_CALLS="$work/git.calls" MOCK_GH_CALLS="$work/gh.calls" \
    scripts/dev/ship.sh --revision HEAD 2>&1)"; then
    fail 'ship must fail when workflow dispatch fails'
  fi
  grep -Fq "workflow run gate-v4x-candidate.yml --ref v4.x --repo williamacallahan/coolify" "$work/gh.calls" \
    || fail 'dispatch must target the canonical repository derived from the selected remote'
  grep -Fq "push origin --force-with-lease=refs/heads/$candidate_ref:$sha :refs/heads/$candidate_ref" "$work/git.calls" \
    || fail 'failed dispatch must delete only the exact pushed candidate ref with force-with-lease'
  printf '%s\n' "$out" | grep -Fq 'dispatch failed; deleted the exact candidate ref' \
    || fail 'successful cleanup must be reported with the dispatch failure'

  : >"$work/git.calls"
  : >"$work/gh.calls"
  if out="$(cd "$ROOT" && BASH_ENV=/dev/null PATH="$work/bin:$PATH" MOCK_ROOT="$ROOT" MOCK_SHA="$sha" MOCK_BASE="$base" \
    MOCK_GENERATION="$generation" MOCK_REMOTE_URL='https://github.com/WilliamAGH/coolify.git' \
    MOCK_CANDIDATE_REF="$candidate_ref" MOCK_CLEANUP_FAIL=1 MOCK_GIT_CALLS="$work/git.calls" MOCK_GH_CALLS="$work/gh.calls" \
    scripts/dev/ship.sh --revision HEAD 2>&1)"; then
    fail 'ship must fail hard when dispatch and candidate-ref cleanup both fail'
  fi
  printf '%s\n' "$out" | grep -Fq 'dispatch failed and cleanup failed' \
    || fail 'cleanup failure must be an explicit hard failure'

  if (cd "$ROOT" && BASH_ENV=/dev/null PATH="$work/bin:$PATH" MOCK_ROOT="$ROOT" MOCK_SHA="$sha" MOCK_BASE="$base" \
    MOCK_GENERATION="$generation" MOCK_REMOTE_URL='https://github.com/coollabsio/coolify.git' \
    MOCK_CANDIDATE_REF="$candidate_ref" MOCK_GIT_CALLS="$work/git.calls" MOCK_GH_CALLS="$work/gh.calls" \
    scripts/dev/ship.sh --dry-run) >/dev/null 2>&1; then
    fail 'ship must fail closed for a selected remote outside williamacallahan/coolify'
  fi
  rm -rf "$work"
  pass 'remote_resolution_and_dispatch_cleanup'
}

test_history_limit() {
  local work out
  work="$(mktemp -d)"
  mkdir -p "$work/bin"
  cat >"$work/bin/git" <<'FAKE_GIT'
#!/usr/bin/env bash
set -euo pipefail
case "${1:-} ${2:-}" in
  'rev-parse --show-toplevel') printf '%s\n' "$MOCK_ROOT" ;;
  'remote get-url') printf '%s\n' 'https://github.com/WilliamAGH/coolify.git' ;;
  *) printf 'unexpected git arguments: %s\n' "$*" >&2; exit 2 ;;
esac
FAKE_GIT
  cat >"$work/bin/gh" <<'FAKE_GH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >>"$MOCK_GH_CALLS"
printf '%s\n' '[{"databaseId":1,"displayTitle":"Gate v4.x aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ship/v4x/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/cccccccccccccccccccccccccccccccccccccccc from bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","status":"completed","conclusion":"success"}]'
FAKE_GH
  chmod +x "$work/bin/git" "$work/bin/gh"
  out="$(cd "$ROOT" && BASH_ENV=/dev/null PATH="$work/bin:$PATH" MOCK_ROOT="$ROOT" MOCK_GH_CALLS="$work/gh.calls" \
    HISTORY=1 HISTORY_N=7 scripts/dev/ship.sh status)" || fail 'history mode must succeed'
  [ -n "$out" ] || fail 'history mode must print matching runs'
  grep -Fq 'run list --workflow gate-v4x-candidate.yml --branch v4.x --event workflow_dispatch --limit 7 --repo williamacallahan/coolify' "$work/gh.calls" \
    || fail 'HISTORY_N must limit the GitHub history query'
  rm -rf "$work"
  pass 'history_limit'
}

test_dry_run_plan
test_canonical_contract
test_ship_guards_and_dispatch_wiring
test_verifier_wiring
test_status_and_verifier_exact_binding
test_remote_resolution_and_dispatch_cleanup
test_history_limit
printf 'ALL V4.X SHIP TESTS PASSED\n'
