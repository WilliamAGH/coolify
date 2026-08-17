#!/usr/bin/env bash
# Contract tests for the main-only signed fork release entrypoint.
set -Eeuo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/../.." && pwd)"
TAG='4.13.77-fork'
SHA='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
OTHER_SHA='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
FETCH_URL='https://github.com/williamacallahan/coolify.git'
PUSH_URL='ssh://git@github.com/williamacallahan/coolify.git'

fail()
{
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

pass()
{
  printf 'ok: %s\n' "$1"
}

require_contains()
{
  printf '%s\n' "$1" | grep -Fq -- "$2" || fail "$3"
}

require_not_contains()
{
  if printf '%s\n' "$1" | grep -Fq -- "$2"; then
    fail "$3"
  fi
}

fixture=''
cleanup()
{
  [ -z "$fixture" ] || rm -rf "$fixture"
}
trap cleanup EXIT

create_fixture()
{
  local repository

  cleanup
  fixture="$(mktemp -d)"
  repository="$fixture/repository"
  mkdir -p "$repository/scripts/dev" "$repository/scripts/ci" "$repository/make" \
    "$repository/config" "$repository/docker" "$fixture/bin"
  cp "$ROOT/scripts/dev/assert-branch-current.sh" "$repository/scripts/dev/assert-branch-current.sh"
  cp "$ROOT/scripts/dev/ship.sh" "$repository/scripts/dev/ship.sh"
  cp "$ROOT/Makefile" "$repository/Makefile"
  cp "$ROOT/make/ship.mk" "$repository/make/ship.mk"
  : >"$repository/make/docker.mk"
  : >"$repository/make/test.mk"
  chmod +x "$repository/scripts/dev/assert-branch-current.sh" "$repository/scripts/dev/ship.sh"
  printf '%s\n' "<?php return ['coolify' => ['version' => '$TAG']];" >"$repository/config/constants.php"
  printf '{"coolify":{"v4":{"version":"%s"' "$TAG" >"$repository/versions.json"
  printf '}}}\n' >>"$repository/versions.json"
  printf '%s\n' 'release-test ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICleanupFixtureKey' \
    >"$repository/docker/fork-release-tag-allowed-signers"

cat >"$repository/scripts/ci/verify-fork-release-tag.sh" <<'FAKE_VERIFIER'
#!/usr/bin/env bash
set -Eeuo pipefail
printf 'ref=%s sha=%s args=%s\n' "${GITHUB_REF:-}" "${GITHUB_SHA:-}" "$*" >>"$MOCK_VERIFIER_CALLS"
[[ "${MOCK_VERIFIER_FAILURE:-0}" != 1 ]]
FAKE_VERIFIER
  chmod +x "$repository/scripts/ci/verify-fork-release-tag.sh"

  cat >"$fixture/bin/git" <<'FAKE_GIT'
#!/usr/bin/env bash
set -Eeuo pipefail
printf '%s\n' "$*" >>"$MOCK_GIT_CALLS"
last_argument=''
for argument in "$@"; do
  last_argument="$argument"
done

case "${1:-}" in
symbolic-ref)
  [ "$*" = 'symbolic-ref --quiet --short HEAD' ] || exit 2
  [ "${MOCK_DETACHED:-0}" != 1 ] || exit 1
  printf '%s\n' "${MOCK_BRANCH:-main}"
  ;;
rev-parse)
  case "${2:-}" in
  --show-toplevel) printf '%s\n' "$MOCK_ROOT" ;;
  --verify)
    case "${3:-}" in
    'HEAD^{commit}') printf '%s\n' "$MOCK_SHA" ;;
    'refs/remotes/origin/main^{commit}') printf '%s\n' "${MOCK_ORIGIN_SHA:-$MOCK_SHA}" ;;
    "refs/tags/${MOCK_TAG}^{commit}") printf '%s\n' "$MOCK_SHA" ;;
    *) printf 'unexpected git rev-parse --verify: %s\n' "$*" >&2; exit 2 ;;
    esac
    ;;
  *) printf 'unexpected git rev-parse: %s\n' "$*" >&2; exit 2 ;;
  esac
  ;;
branch)
  [ "${2:-}" = '--show-current' ] || exit 2
  printf '%s\n' "${MOCK_BRANCH:-main}"
  ;;
status)
  [ "${2:-}" = '--porcelain' ] || exit 2
  [ "${MOCK_DIRTY:-0}" = 1 ] && printf '%s\n' ' M foreign-change'
  exit 0
  ;;
remote)
  case "$*" in
  'remote get-url --all origin')
    printf '%s\n' "${MOCK_FETCH_URLS:-$MOCK_FETCH_URL}"
    ;;
  'remote get-url --push --all origin')
    printf '%s\n' "${MOCK_PUSH_URLS:-$MOCK_PUSH_URL}"
    ;;
  *) exit 2 ;;
  esac
  ;;
fetch)
  [ "${MOCK_FETCH_FAILURE:-0}" != 1 ] || exit 1
  case "$*" in
  "fetch --quiet $MOCK_FETCH_URL refs/heads/main:refs/remotes/origin/main") ;;
  "fetch --quiet origin refs/heads/${MOCK_BRANCH:-main}:refs/remotes/origin/${MOCK_BRANCH:-main}") ;;
  *) exit 2 ;;
  esac
  ;;
rev-list)
  [ "$*" = "rev-list --count HEAD..origin/${MOCK_BRANCH:-main}" ] || exit 2
  printf '%s\n' "${MOCK_BEHIND_COUNT:-0}"
  ;;
show-ref)
  [ "$*" = "show-ref --verify --quiet refs/tags/$MOCK_TAG" ] || exit 2
  [ "${MOCK_LOCAL_TAG_EXISTS:-0}" = 1 ]
  ;;
ls-remote)
  if [ "${2:-}" != '--exit-code' ] || [ "${3:-}" != '--tags' ] || [ "${4:-}" != "$MOCK_FETCH_URL" ]; then
    exit 2
  fi
  case "$last_argument" in
  "refs/tags/${MOCK_TAG}")
    if [ "${MOCK_REMOTE_TAG_EXISTS:-0}" = 1 ]; then
      exit 0
    fi
    exit 2
    ;;
  "refs/tags/${MOCK_TAG}^{}")
    printf '%s\trefs/tags/%s^{}\n' "${MOCK_REMOTE_PEELED_TARGET:-$MOCK_SHA}" "$MOCK_TAG"
    if [ "${MOCK_REMOTE_PEELED_DUPLICATE:-0}" = 1 ]; then
      printf '%s\trefs/tags/%s^{}\n' "${MOCK_REMOTE_PEELED_SECOND_TARGET:-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb}" "$MOCK_TAG"
    fi
    ;;
  *)
    printf 'unexpected git ls-remote: %s\n' "$*" >&2
    exit 2
    ;;
  esac
  ;;
tag)
  [ "$*" = "tag -s -a $MOCK_TAG $MOCK_SHA -m Release $MOCK_TAG" ] || exit 2
  ;;
cat-file)
  [ "$*" = "cat-file -t refs/tags/$MOCK_TAG" ] || exit 2
  printf '%s\n' tag
  ;;
-c)
  case "$*" in
  "-c gpg.format=ssh tag -s -a $MOCK_TAG $MOCK_SHA -m Release $MOCK_TAG") ;;
  "-c gpg.format=ssh -c gpg.ssh.allowedSignersFile=docker/fork-release-tag-allowed-signers verify-tag $MOCK_TAG")
    [ "${MOCK_VERIFY_TAG_FAILURE:-0}" != 1 ]
    ;;
  *) exit 2 ;;
  esac
  ;;
push)
  [ "$*" = "push $MOCK_PUSH_URL refs/tags/$MOCK_TAG:refs/tags/$MOCK_TAG" ] || exit 2
  ;;
*)
  printf 'unexpected git command: %s\n' "$*" >&2
  exit 2
  ;;
esac
FAKE_GIT

  cat >"$fixture/bin/gh" <<'FAKE_GH'
#!/usr/bin/env bash
set -Eeuo pipefail
printf '%s\n' "$*" >>"$MOCK_GH_CALLS"
case "${1:-} ${2:-}" in
'run list')
  count=0
  if [ -f "$MOCK_GH_LIST_COUNT" ]; then
    count="$(cat "$MOCK_GH_LIST_COUNT")"
  fi
  count=$((count + 1))
  printf '%s\n' "$count" >"$MOCK_GH_LIST_COUNT"
  case "${MOCK_GH_MODE:-exact}" in
  delayed)
    if [ "$count" -eq 1 ]; then
      printf '%s\n' '[]'
    else
      printf '[{"databaseId":4242,"createdAt":"2026-08-07T00:00:02Z","event":"push","headBranch":"%s","headSha":"%s","status":"in_progress","conclusion":null,"url":"https://example.invalid/runs/4242","workflowName":"Publish fork images"}]\n' "$MOCK_TAG" "$MOCK_SHA"
    fi
    ;;
  missing)
    printf '%s\n' '[]'
    ;;
  exact)
    printf '[{"databaseId":1111,"createdAt":"2026-08-07T00:00:01Z","event":"push","headBranch":"wrong-tag","headSha":"%s","status":"in_progress","conclusion":null,"url":"https://example.invalid/runs/1111","workflowName":"Publish fork images"},{"databaseId":4242,"createdAt":"2026-08-07T00:00:02Z","event":"push","headBranch":"%s","headSha":"%s","status":"completed","conclusion":"success","url":"https://example.invalid/runs/4242","workflowName":"Publish fork images"}]\n' "$MOCK_SHA" "$MOCK_TAG" "$MOCK_SHA"
    ;;
  recreated-tag-failed)
    if [ "$count" -eq 1 ]; then
      printf '[{"databaseId":3131,"createdAt":"2026-08-07T00:00:01Z","event":"push","headBranch":"%s","headSha":"%s","status":"completed","conclusion":"success","url":"https://example.invalid/runs/3131","workflowName":"Publish fork images"}]\n' "$MOCK_TAG" "$MOCK_SHA"
    else
      printf '[{"databaseId":4242,"createdAt":"2026-08-07T00:00:02Z","event":"push","headBranch":"%s","headSha":"%s","status":"completed","conclusion":"failure","url":"https://example.invalid/runs/4242","workflowName":"Publish fork images"},{"databaseId":3131,"createdAt":"2026-08-07T00:00:01Z","event":"push","headBranch":"%s","headSha":"%s","status":"completed","conclusion":"success","url":"https://example.invalid/runs/3131","workflowName":"Publish fork images"}]\n' "$MOCK_TAG" "$MOCK_SHA" "$MOCK_TAG" "$MOCK_SHA"
    fi
    ;;
  recreated-tag-stale-only)
    printf '[{"databaseId":3131,"createdAt":"2026-08-07T00:00:01Z","event":"push","headBranch":"%s","headSha":"%s","status":"completed","conclusion":"success","url":"https://example.invalid/runs/3131","workflowName":"Publish fork images"}]\n' "$MOCK_TAG" "$MOCK_SHA"
    ;;
  hidden-old-success-new-failure)
    if [ "$count" -eq 1 ]; then
      printf '%s\n' '[]'
    else
      printf '[{"databaseId":3131,"createdAt":"2026-08-07T00:00:01Z","event":"push","headBranch":"%s","headSha":"%s","status":"completed","conclusion":"success","url":"https://example.invalid/runs/3131","workflowName":"Publish fork images"},{"databaseId":4242,"createdAt":"2026-08-07T00:00:02Z","event":"push","headBranch":"%s","headSha":"%s","status":"completed","conclusion":"failure","url":"https://example.invalid/runs/4242","workflowName":"Publish fork images"}]\n' "$MOCK_TAG" "$MOCK_SHA" "$MOCK_TAG" "$MOCK_SHA"
    fi
    ;;
  mixed-status)
    printf '[{"databaseId":3131,"createdAt":"2026-08-07T00:00:01Z","event":"push","headBranch":"%s","headSha":"%s","status":"completed","conclusion":"success","url":"https://example.invalid/runs/3131","workflowName":"Publish fork images"},{"databaseId":4242,"createdAt":"2026-08-07T00:00:02Z","event":"push","headBranch":"%s","headSha":"%s","status":"completed","conclusion":"failure","url":"https://example.invalid/runs/4242","workflowName":"Publish fork images"}]\n' "$MOCK_TAG" "$MOCK_SHA" "$MOCK_TAG" "$MOCK_SHA"
    ;;
  failed)
    printf '[{"databaseId":4242,"createdAt":"2026-08-07T00:00:02Z","event":"push","headBranch":"%s","headSha":"%s","status":"completed","conclusion":"failure","url":"https://example.invalid/runs/4242","workflowName":"Publish fork images"}]\n' "$MOCK_TAG" "$MOCK_SHA"
    ;;
  *) exit 2 ;;
  esac
  ;;
'run watch')
  [ "${3:-}" = 4242 ] || exit 2
  [ "${MOCK_GH_WATCH_FAILURE:-0}" != 1 ]
  ;;
*)
  printf 'unexpected gh command: %s\n' "$*" >&2
  exit 2
  ;;
esac
FAKE_GH
  chmod +x "$fixture/bin/git" "$fixture/bin/gh"
}

run_make()
{
  (
    cd "$fixture/repository"
    BASH_ENV=/dev/null PATH="$fixture/bin:$PATH" \
      MOCK_ROOT="$fixture/repository" MOCK_TAG="$TAG" MOCK_SHA="$SHA" \
      MOCK_FETCH_URL="${MOCK_FETCH_URL:-$FETCH_URL}" MOCK_PUSH_URL="${MOCK_PUSH_URL:-$PUSH_URL}" \
      MOCK_GIT_CALLS="$fixture/git.calls" MOCK_GH_CALLS="$fixture/gh.calls" \
      MOCK_GH_LIST_COUNT="$fixture/gh-list-count" MOCK_VERIFIER_CALLS="$fixture/verifier.calls" \
      make --no-print-directory -f make/ship.mk "$@"
  )
}

run_bare_make()
{
  (
    cd "$fixture/repository"
    BASH_ENV=/dev/null PATH="$fixture/bin:$PATH" \
      MOCK_ROOT="$fixture/repository" MOCK_TAG="$TAG" MOCK_SHA="$SHA" \
      MOCK_FETCH_URL="${MOCK_FETCH_URL:-$FETCH_URL}" MOCK_PUSH_URL="${MOCK_PUSH_URL:-$PUSH_URL}" \
      MOCK_GIT_CALLS="$fixture/git.calls" MOCK_GH_CALLS="$fixture/gh.calls" \
      MOCK_GH_LIST_COUNT="$fixture/gh-list-count" MOCK_VERIFIER_CALLS="$fixture/verifier.calls" \
      make --no-print-directory
  )
}

run_branch_guard()
{
  (
    cd "$fixture/repository"
    BASH_ENV=/dev/null PATH="$fixture/bin:$PATH" \
      MOCK_ROOT="$fixture/repository" MOCK_TAG="$TAG" MOCK_SHA="$SHA" \
      MOCK_FETCH_URL="${MOCK_FETCH_URL:-$FETCH_URL}" MOCK_PUSH_URL="${MOCK_PUSH_URL:-$PUSH_URL}" \
      MOCK_GIT_CALLS="$fixture/git.calls" \
      scripts/dev/assert-branch-current.sh "$@"
  )
}

test_dry_run_has_no_network_or_mutation()
{
  local output calls

  create_fixture
  output="$(run_make ship SHIP_DRY_RUN=1 2>&1)" || fail "dry-run must succeed: $output"
  require_contains "$output" '[dry-run] git fetch --quiet <origin-fetch-url> refs/heads/main:refs/remotes/origin/main' \
    'dry-run must print the main fetch'
  require_contains "$output" "[dry-run] git -c gpg.format=ssh tag -s -a $TAG $SHA" \
    'dry-run must print the exact signed tag command'
  require_contains "$output" '[dry-run] poll gh run list --workflow publish-fork.yml' \
    'dry-run must print exact publish workflow discovery'
  require_contains "$output" '[dry-run] git push <origin-push-url> refs/tags/' \
    'dry-run must print the tag push without disclosing its URL'
  require_not_contains "$output" "$FETCH_URL" \
    'dry-run must not disclose the configured fetch URL'
  require_not_contains "$output" "$PUSH_URL" \
    'dry-run must not disclose the configured push URL'
  calls="$(cat "$fixture/git.calls")"
  require_not_contains "$calls" 'fetch --quiet' 'dry-run must not fetch'
  require_not_contains "$calls" 'ls-remote' 'dry-run must not contact origin'
  require_not_contains "$calls" 'tag -s -a' 'dry-run must not create a tag'
  require_not_contains "$calls" "push $PUSH_URL refs/tags/" 'dry-run must not push a tag'
  [ ! -e "$fixture/gh.calls" ] || fail 'dry-run must not query GitHub'
  [ ! -e "$fixture/verifier.calls" ] || fail 'dry-run must not run the repository verifier'
  pass 'dry_run_has_no_network_or_mutation'
}

test_bare_make_is_inert()
{
  local output

  create_fixture
  output="$(run_bare_make 2>&1)" || fail "bare make must succeed without selecting ship: $output"
  [ -z "$output" ] || fail "bare make must be silent, got: $output"
  [ ! -e "$fixture/git.calls" ] || fail 'bare make must not invoke Git'
  [ ! -e "$fixture/gh.calls" ] || fail 'bare make must not query or watch GitHub Actions'
  [ ! -e "$fixture/verifier.calls" ] || fail 'bare make must not invoke the release verifier'
  pass 'bare_make_is_inert'
}

test_requires_unambiguous_origin_and_exact_main_tip()
{
  local output calls

  create_fixture
  if output="$(MOCK_PUSH_URL='https://github.com/someone-else/coolify.git' run_make ship 2>&1)"; then
    fail 'a split origin must fail before release checks'
  fi
  require_contains "$output" 'origin push URL must identify williamacallahan/coolify' \
    'release must reject a push URL for another repository'
  calls="$(cat "$fixture/git.calls")"
  require_not_contains "$calls" 'fetch ' 'split origin rejection must run before network access'

  create_fixture
  if output="$(MOCK_FETCH_URL='ssh://git@github.com/someone-else/coolify.git' run_make ship 2>&1)"; then
    fail 'a fetch URL for another repository must fail before release checks'
  fi
  require_contains "$output" 'origin fetch URL must identify williamacallahan/coolify' \
    'release must reject a fetch URL for another repository'
  calls="$(cat "$fixture/git.calls")"
  require_not_contains "$calls" 'fetch ' 'invalid fetch URL rejection must run before network access'

  create_fixture
  if output="$(MOCK_FETCH_URLS="$FETCH_URL"$'\n'"$FETCH_URL" run_make ship 2>&1)"; then
    fail 'multiple fetch URLs must fail'
  fi
  require_contains "$output" 'origin must have exactly one fetch URL' \
    'release must reject ambiguous fetch URLs'
  calls="$(cat "$fixture/git.calls")"
  require_not_contains "$calls" 'fetch ' 'multiple fetch URL rejection must run before network access'

  create_fixture
  if output="$(MOCK_PUSH_URLS="$PUSH_URL"$'\n'"$PUSH_URL" run_make ship 2>&1)"; then
    fail 'multiple push URLs must fail'
  fi
  require_contains "$output" 'origin must have exactly one push URL' \
    'release must reject ambiguous push URLs'
  calls="$(cat "$fixture/git.calls")"
  require_not_contains "$calls" 'fetch ' 'multiple push URL rejection must run before network access'

  create_fixture
  if output="$(MOCK_BRANCH=v4.x run_make ship 2>&1)"; then
    fail 'shipping any branch other than main must fail'
  fi
  require_contains "$output" 'expected main' 'release must reject a non-main branch'
  calls="$(cat "$fixture/git.calls")"
  require_not_contains "$calls" 'fetch ' 'branch guard must run before network access'

  create_fixture
  if output="$(MOCK_DIRTY=1 run_make ship 2>&1)"; then
    fail 'shipping a dirty main checkout must fail'
  fi
  require_contains "$output" 'fully committed and clean' 'release must reject a dirty checkout'
  calls="$(cat "$fixture/git.calls")"
  require_not_contains "$calls" 'fetch ' 'clean-tree guard must run before network access'

  create_fixture
  if output="$(MOCK_ORIGIN_SHA="$OTHER_SHA" run_make ship 2>&1)"; then
    fail 'shipping an ahead or divergent main checkout must fail'
  fi
  require_contains "$output" 'must exactly equal origin/main' \
    'release must require equality, not only zero commits behind'
  calls="$(cat "$fixture/git.calls")"
  require_contains "$calls" "fetch --quiet $FETCH_URL refs/heads/main:refs/remotes/origin/main" \
    'release must fetch origin/main through the explicit fetch URL before equality check'
  require_not_contains "$calls" 'tag -s -a' 'failed equality guard must not create a tag'
  pass 'requires_unambiguous_origin_and_exact_main_tip'
}

test_rejects_spoofed_and_credentialed_https_origin_urls()
{
  local output calls path_marker_url userinfo_url

  path_marker_url='https://evil.example/x@github.com/williamacallahan/coolify.git'
  userinfo_url='https://release-read-token@github.com/williamacallahan/coolify.git'

  create_fixture
  if output="$(MOCK_FETCH_URL="$path_marker_url" run_make ship SHIP_DRY_RUN=1 2>&1)"; then
    fail 'an HTTPS path-marker spoof must fail before release checks'
  fi
  require_contains "$output" 'HTTPS host exactly github.com without userinfo' \
    'release must require the HTTPS authority host to be exactly github.com'
  calls="$(cat "$fixture/git.calls")"
  require_not_contains "$calls" 'fetch ' 'a path-marker spoof must not reach git fetch'

  create_fixture
  if output="$(MOCK_FETCH_URL="$userinfo_url" run_make ship SHIP_DRY_RUN=1 2>&1)"; then
    fail 'a credential-bearing HTTPS fetch URL must fail before release checks'
  fi
  require_contains "$output" 'HTTPS host exactly github.com without userinfo' \
    'release must reject HTTPS fetch userinfo'
  require_not_contains "$output" "$userinfo_url" \
    'HTTPS fetch userinfo rejection must not disclose the URL'
  calls="$(cat "$fixture/git.calls")"
  require_not_contains "$calls" 'fetch ' 'HTTPS fetch userinfo must not reach git fetch'
  require_not_contains "$calls" "$userinfo_url" \
    'HTTPS fetch userinfo must not reach a later Git subprocess'

  create_fixture
  if output="$(MOCK_PUSH_URL="$userinfo_url" run_make ship SHIP_DRY_RUN=1 2>&1)"; then
    fail 'a credential-bearing HTTPS push URL must fail before release checks'
  fi
  require_contains "$output" 'HTTPS host exactly github.com without userinfo' \
    'release must reject HTTPS push userinfo'
  require_not_contains "$output" "$userinfo_url" \
    'HTTPS push userinfo rejection must not disclose the URL'
  calls="$(cat "$fixture/git.calls")"
  require_not_contains "$calls" 'fetch ' 'HTTPS push userinfo must not reach git fetch'
  require_not_contains "$calls" "$userinfo_url" \
    'HTTPS push userinfo must not reach a later Git subprocess'
  pass 'rejects_spoofed_and_credentialed_https_origin_urls'
}

test_accepts_canonical_origin_url_forms()
{
  local origin_url output

  for origin_url in \
    'git@github.com:williamacallahan/coolify.git' \
    'ssh://git@github.com/williamacallahan/coolify.git' \
    'https://github.com/williamacallahan/coolify.git'; do
    create_fixture
    output="$(MOCK_FETCH_URL="$origin_url" MOCK_PUSH_URL="$origin_url" run_make ship SHIP_DRY_RUN=1 2>&1)" || \
      fail "a canonical origin URL must be accepted: $origin_url: $output"
    require_contains "$output" '[dry-run] git fetch --quiet <origin-fetch-url>' \
      'a canonical fetch URL must reach the release dry-run plan'
    require_contains "$output" '[dry-run] git push <origin-push-url>' \
      'a canonical push URL must reach the release dry-run plan'
  done
  pass 'accepts_canonical_origin_url_forms'
}

test_branch_guard_fails_closed_and_leaves_exact_equality_to_ship()
{
  local output calls

  create_fixture
  if output="$(MOCK_DETACHED=1 run_branch_guard 2>&1)"; then
    fail 'the branch guard must reject detached HEAD'
  fi
  require_contains "$output" 'HEAD is detached' 'detached HEAD refusal must be explicit'

  create_fixture
  if output="$(MOCK_FETCH_FAILURE=1 run_branch_guard 2>&1)"; then
    fail 'the branch guard must fail when origin fetch fails'
  fi
  require_contains "$output" 'git fetch origin main failed' 'fetch failure must be explicit'

  create_fixture
  if output="$(MOCK_BEHIND_COUNT=2 run_branch_guard 2>&1)"; then
    fail 'the branch guard must reject a behind checkout'
  fi
  require_contains "$output" 'is 2 commit(s) behind origin/main' 'behind refusal must report the count'

  create_fixture
  if output="$(MOCK_DIRTY=1 run_branch_guard --require-clean 2>&1)"; then
    fail 'the clean branch guard must reject tracked or untracked dirt'
  fi
  require_contains "$output" 'working tree is dirty' 'dirty checkout refusal must be explicit'

  create_fixture
  output="$(MOCK_BEHIND_COUNT=0 run_branch_guard 2>&1)" || fail "a current branch must pass the preflight guard: $output"
  require_contains "$output" '0 behind' 'the preflight guard must report its deliberately narrow contract'
  calls="$(cat "$fixture/git.calls")"
  require_not_contains "$calls" 'rev-parse --verify HEAD^{commit}' \
    'the preflight guard must leave ahead and divergence equality to ship.sh'
  pass 'branch_guard_fails_closed_and_leaves_exact_equality_to_ship'
}

test_accepts_git_directory_urls_with_a_trailing_slash()
{
  local output

  create_fixture
  output="$(MOCK_FETCH_URL="${FETCH_URL}/" run_make ship SHIP_DRY_RUN=1 2>&1)" || \
    fail "a fetch URL ending in .git/ must be accepted: $output"
  require_contains "$output" '[dry-run] git fetch --quiet <origin-fetch-url>' \
    'a normalized fetch URL must still reach the release dry-run plan'

  create_fixture
  output="$(MOCK_PUSH_URL="${PUSH_URL}/" run_make ship SHIP_DRY_RUN=1 2>&1)" || \
    fail "a push URL ending in .git/ must be accepted: $output"
  require_contains "$output" '[dry-run] git push <origin-push-url>' \
    'a normalized push URL must still reach the release dry-run plan'
  pass 'accepts_git_directory_urls_with_a_trailing_slash'
}

test_refuses_existing_tags_and_mismatched_versions()
{
  local output calls

  create_fixture
  if output="$(MOCK_LOCAL_TAG_EXISTS=1 run_make ship 2>&1)"; then
    fail 'an existing local release tag must fail'
  fi
  require_contains "$output" 'already exists locally' 'local tag refusal must be explicit'
  calls="$(cat "$fixture/git.calls")"
  require_not_contains "$calls" 'tag -s -a' 'existing local tag must not be replaced'

  create_fixture
  if output="$(MOCK_REMOTE_TAG_EXISTS=1 run_make ship 2>&1)"; then
    fail 'an existing remote release tag must fail'
  fi
  require_contains "$output" 'already exists on origin' 'remote tag refusal must be explicit'
  calls="$(cat "$fixture/git.calls")"
  require_not_contains "$calls" 'tag -s -a' 'existing remote tag must not create a local tag'

  printf '%s\n' "<?php return ['coolify' => ['version' => '4.13.78-fork']];" \
    >"$fixture/repository/config/constants.php"
  if output="$(run_make ship 2>&1)"; then
    fail 'mismatched release version files must fail'
  fi
  require_contains "$output" 'does not equal versions.json version' \
    'version mismatch must identify both canonical sources'
  pass 'refuses_existing_tags_and_mismatched_versions'
}

test_release_flow_binds_tag_verifier_and_publish_run()
{
  local output calls verifier_calls gh_calls

  create_fixture
  output="$(MOCK_GH_MODE=delayed SHIP_STATUS_DISCOVERY_ATTEMPTS=2 \
    SHIP_STATUS_DISCOVERY_DELAY_SECONDS=0 run_make ship 2>&1)" || fail "release flow must succeed: $output"
  calls="$(cat "$fixture/git.calls")"
  require_contains "$calls" "fetch --quiet $FETCH_URL refs/heads/main:refs/remotes/origin/main" \
    'release must fetch through the validated explicit fetch URL'
  require_contains "$calls" "-c gpg.format=ssh tag -s -a $TAG $SHA -m Release $TAG" \
    'release must create an annotated signed tag at HEAD'
  require_contains "$calls" "-c gpg.format=ssh -c gpg.ssh.allowedSignersFile=docker/fork-release-tag-allowed-signers verify-tag $TAG" \
    'release must verify the local tag signature'
  require_contains "$calls" "push $PUSH_URL refs/tags/$TAG:refs/tags/$TAG" \
    'release must push only the exact tag ref through the validated explicit push URL'
  require_contains "$calls" "ls-remote --exit-code --tags $FETCH_URL refs/tags/$TAG^{}" \
    'release must verify the peeled remote tag target through the explicit fetch URL'
  require_not_contains "$calls" "push $PUSH_URL :refs/tags/$TAG" 'release must never delete a tag after a later failure'
  verifier_calls="$(cat "$fixture/verifier.calls")"
  require_contains "$verifier_calls" "ref=refs/tags/$TAG sha=$SHA" \
    'release must invoke the repository verifier with the exact tag binding'
  require_contains "$verifier_calls" "args=$TAG $SHA $FETCH_URL docker/fork-release-tag-allowed-signers" \
    'release verifier must read through the validated explicit fetch URL'
  gh_calls="$(cat "$fixture/gh.calls")"
  require_contains "$gh_calls" "run list --workflow publish-fork.yml --event push --commit $SHA" \
    'release must poll publish-fork.yml by source SHA'
  require_contains "$gh_calls" '--json databaseId,createdAt,event,headBranch,headSha,status,conclusion,url,workflowName' \
    'release must request provider creation timestamps for deterministic newest-run selection'
  require_contains "$gh_calls" 'run watch 4242 --exit-status --repo williamacallahan/coolify' \
    'release must watch only the exact resolved workflow run'
  require_contains "$output" "Publish fork workflow succeeded for tag $TAG at $SHA." \
    'release must report the exact successful binding'
  pass 'release_flow_binds_tag_verifier_and_publish_run'
}

test_post_tag_failures_never_delete_release_identity()
{
  local output calls

  create_fixture
  if output="$(MOCK_VERIFY_TAG_FAILURE=1 run_make ship 2>&1)"; then
    fail 'local signature verification failure must stop the release'
  fi
  require_contains "$output" 'local signed tag verification failed' \
    'local signature failure must identify the retained tag'
  calls="$(cat "$fixture/git.calls")"
  require_not_contains "$calls" "push $PUSH_URL :refs/tags/$TAG" \
    'local signature failure must not delete the tag'

  create_fixture
  if output="$(MOCK_REMOTE_PEELED_TARGET="$OTHER_SHA" run_make ship 2>&1)"; then
    fail 'a mismatched remote peeled target must stop the release'
  fi
  require_contains "$output" "must dereference to $SHA" \
    'remote target mismatch must identify the expected source'
  calls="$(cat "$fixture/git.calls")"
  require_not_contains "$calls" "push $PUSH_URL :refs/tags/$TAG" \
    'remote target mismatch must not delete the tag'

  create_fixture
  if output="$(MOCK_REMOTE_PEELED_DUPLICATE=1 run_make ship 2>&1)"; then
    fail 'multiple remote peeled targets must stop the release'
  fi
  require_contains "$output" "must dereference to $SHA" \
    'ambiguous remote target output must fail closed'
  calls="$(cat "$fixture/git.calls")"
  require_not_contains "$calls" "push $PUSH_URL :refs/tags/$TAG" \
    'ambiguous remote target output must not delete the tag'

  create_fixture
  if output="$(MOCK_VERIFIER_FAILURE=1 run_make ship 2>&1)"; then
    fail 'repository verifier failure must stop the release'
  fi
  require_contains "$output" 'repository tag verifier failed' \
    'repository verifier failure must identify the retained tag'
  calls="$(cat "$fixture/git.calls")"
  require_not_contains "$calls" "push $PUSH_URL :refs/tags/$TAG" \
    'repository verifier failure must not delete the tag'

  create_fixture
  if output="$(MOCK_GH_MODE=delayed MOCK_GH_WATCH_FAILURE=1 \
    SHIP_STATUS_DISCOVERY_ATTEMPTS=2 SHIP_STATUS_DISCOVERY_DELAY_SECONDS=0 run_make ship 2>&1)"; then
    fail 'publish workflow watch failure must stop the release'
  fi
  require_contains "$output" 'publish-fork workflow did not finish successfully' \
    'workflow watch failure must identify the retained tag'
  calls="$(cat "$fixture/git.calls")"
  require_not_contains "$calls" "push $PUSH_URL :refs/tags/$TAG" \
    'workflow watch failure must not delete the tag'
  pass 'post_tag_failures_never_delete_release_identity'
}

test_recreated_tag_requires_a_new_publish_run()
{
  local output gh_calls

  create_fixture
  if output="$(MOCK_GH_MODE=recreated-tag-failed MOCK_GH_WATCH_FAILURE=1 \
    SHIP_STATUS_DISCOVERY_ATTEMPTS=1 SHIP_STATUS_DISCOVERY_DELAY_SECONDS=0 run_make ship 2>&1)"; then
    fail 'a failed replay must not be replaced by an older successful run for the same tag and SHA'
  fi
  gh_calls="$(cat "$fixture/gh.calls")"
  require_contains "$gh_calls" 'run watch 4242 --exit-status --repo williamacallahan/coolify' \
    'release must watch the newly observed failed replay'
  require_not_contains "$gh_calls" 'run watch 3131' \
    'release must not watch an older successful run that existed before the tag push'

  create_fixture
  if output="$(MOCK_GH_MODE=hidden-old-success-new-failure MOCK_GH_WATCH_FAILURE=1 \
    SHIP_STATUS_DISCOVERY_ATTEMPTS=1 SHIP_STATUS_DISCOVERY_DELAY_SECONDS=0 run_make ship 2>&1)"; then
    fail 'a newly visible older success must not replace the newer failed post-push run'
  fi
  gh_calls="$(cat "$fixture/gh.calls")"
  require_contains "$gh_calls" 'run watch 4242 --exit-status --repo williamacallahan/coolify' \
    'release must select the newer failed run when an older success was absent from the snapshot'
  require_not_contains "$gh_calls" 'run watch 3131' \
    'release must not rely on snapshot visibility to reject an older success'

  create_fixture
  if output="$(MOCK_GH_MODE=recreated-tag-stale-only SHIP_STATUS_DISCOVERY_ATTEMPTS=1 \
    SHIP_STATUS_DISCOVERY_DELAY_SECONDS=0 run_make ship 2>&1)"; then
    fail 'an older successful run alone must not satisfy post-push workflow discovery'
  fi
  require_contains "$output" 'no newly observed exact publish-fork.yml run appeared after tag push' \
    'release must fail closed when no new run appears after the tag push'
  gh_calls="$(cat "$fixture/gh.calls")"
  require_not_contains "$gh_calls" 'run watch' \
    'release must not watch a run that existed before the tag push'
  pass 'recreated_tag_requires_a_new_publish_run'
}

test_missing_publish_run_preserves_tag_and_status_is_read_only()
{
  local output calls status_calls

  create_fixture
  if output="$(MOCK_GH_MODE=missing SHIP_STATUS_DISCOVERY_ATTEMPTS=1 \
    SHIP_STATUS_DISCOVERY_DELAY_SECONDS=0 run_make ship 2>&1)"; then
    fail 'release must fail when no exact publish run appears'
  fi
  require_contains "$output" 'the tag was intentionally not deleted' \
    'missing run failure must retain the immutable tag'
  calls="$(cat "$fixture/git.calls")"
  require_contains "$calls" "tag -s -a $TAG $SHA -m Release $TAG" \
    'missing run path must still have created the signed tag'
  require_not_contains "$calls" "push $PUSH_URL :refs/tags/$TAG" 'missing run path must not delete the tag'

  create_fixture
  output="$(MOCK_GH_MODE=exact run_make ship-status SHIP_TAG="$TAG" SHIP_SHA="$SHA")" || \
    fail 'status must report the exact existing run'
  require_contains "$output" "Publish fork run 4242 for tag $TAG at $SHA" \
    'status must select tag and SHA together'
  status_calls="$(cat "$fixture/git.calls")"
  require_not_contains "$status_calls" 'fetch ' 'status must not fetch'
  require_not_contains "$status_calls" 'ls-remote' 'status must not query Git remotes'
  require_not_contains "$status_calls" 'tag ' 'status must not create a tag'
  require_not_contains "$status_calls" "push $PUSH_URL refs/tags/" 'status must not push'
  status_calls="$(cat "$fixture/gh.calls")"
  require_not_contains "$status_calls" 'run watch' 'status must report without watching or mutating'
  pass 'missing_publish_run_preserves_tag_and_status_is_read_only'
}

test_status_reports_the_newest_exact_run()
{
  local output status_calls

  create_fixture
  if output="$(MOCK_GH_MODE=mixed-status run_make ship-status SHIP_TAG="$TAG" SHIP_SHA="$SHA" 2>&1)"; then
    fail 'status must not replace the newest failed run with an older successful run'
  fi
  require_contains "$output" "Publish fork run 4242 for tag $TAG at $SHA: status=completed conclusion=failure" \
    'status must report the newest exact failed run before returning nonzero'
  require_contains "$output" 'publish-fork workflow completed with conclusion failure' \
    'status failure must identify the terminal workflow conclusion'
  status_calls="$(cat "$fixture/gh.calls")"
  require_not_contains "$status_calls" 'run watch' 'status must remain a read-only report on failure'
  pass 'status_reports_the_newest_exact_run'
}

test_bare_make_is_inert
test_dry_run_has_no_network_or_mutation
test_requires_unambiguous_origin_and_exact_main_tip
test_rejects_spoofed_and_credentialed_https_origin_urls
test_accepts_canonical_origin_url_forms
test_branch_guard_fails_closed_and_leaves_exact_equality_to_ship
test_accepts_git_directory_urls_with_a_trailing_slash
test_refuses_existing_tags_and_mismatched_versions
test_release_flow_binds_tag_verifier_and_publish_run
test_recreated_tag_requires_a_new_publish_run
test_post_tag_failures_never_delete_release_identity
test_missing_publish_run_preserves_tag_and_status_is_read_only
test_status_reports_the_newest_exact_run
printf '%s\n' 'ALL MAIN FORK RELEASE SHIP TESTS PASSED'
