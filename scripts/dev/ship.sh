#!/usr/bin/env bash
# Publish one immutable signed fork release from the exact main tip.
set -Eeuo pipefail

readonly RELEASE_BRANCH='main'
readonly RELEASE_REPOSITORY='williamacallahan/coolify'
readonly RELEASE_REMOTE='origin'
readonly PUBLISH_WORKFLOW='publish-fork.yml'
readonly ALLOWED_SIGNERS_FILE='docker/fork-release-tag-allowed-signers'
readonly RELEASE_TAG_PATTERN='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)-fork$'

fail()
{
  printf 'ship: %s\n' "$*" >&2
  exit 1
}

require_command()
{
  command -v "$1" >/dev/null 2>&1 || fail "required command is unavailable: $1"
}

validate_positive_integer()
{
  [[ "$2" =~ ^[1-9][0-9]*$ ]] || fail "$1 must be a positive integer"
}

validate_release_tag()
{
  [[ "$1" =~ $RELEASE_TAG_PATTERN ]] || fail "release version must be an exact X.Y.Z-fork tag: $1"
}

validate_sha()
{
  [[ "$1" =~ ^[0-9a-f]{40}$ ]] || fail "commit SHA must be 40 lowercase hexadecimal characters: $1"
}

usage()
{
  cat <<'USAGE'
usage:
  scripts/dev/ship.sh [--dry-run]
  scripts/dev/ship.sh status --tag X.Y.Z-fork --sha <40-hex-sha>

`ship` only releases a fully committed local main checkout whose HEAD exactly
matches origin/main. It derives the tag from config/constants.php and
versions.json, creates an annotated signed tag, and watches publish-fork.yml.
USAGE
}

REPO_ROOT="$(git rev-parse --show-toplevel)" || fail 'must run inside a Git worktree'
cd "$REPO_ROOT"

SHIP_STATUS_LIMIT="${SHIP_STATUS_LIMIT:-100}"
SHIP_STATUS_DISCOVERY_ATTEMPTS="${SHIP_STATUS_DISCOVERY_ATTEMPTS:-20}"
SHIP_STATUS_DISCOVERY_DELAY_SECONDS="${SHIP_STATUS_DISCOVERY_DELAY_SECONDS:-15}"

command_name='ship'
dry_run=0
status_tag=''
status_sha=''
ORIGIN_FETCH_URL=''
ORIGIN_PUSH_URL=''
GH_REPO=''
RELEASE_TAG=''
SOURCE_SHA=''
PUBLISH_RUN=''
PUBLISH_RUN_IDS_BEFORE_PUSH='[]'

while [ "$#" -gt 0 ]; do
  case "$1" in
  status)
    [ "$command_name" = ship ] || fail 'status may only be specified once'
    command_name='status'
    ;;
  --dry-run)
    dry_run=1
    ;;
  --tag)
    [ "$#" -ge 2 ] || fail '--tag requires a value'
    status_tag="$2"
    shift
    ;;
  --sha)
    [ "$#" -ge 2 ] || fail '--sha requires a value'
    status_sha="$2"
    shift
    ;;
  -h | --help)
    usage
    exit 0
    ;;
  *)
    fail "unknown argument: $1"
    ;;
  esac
  shift
done

if [ "$command_name" = status ]; then
  [ "$dry_run" -eq 0 ] || fail '--dry-run cannot be combined with status'
  [ -n "$status_tag" ] || fail 'status requires --tag X.Y.Z-fork'
  [ -n "$status_sha" ] || fail 'status requires --sha <40-hex-sha>'
else
  [ -z "$status_tag$status_sha" ] || fail '--tag and --sha are only valid with status'
fi

resolve_single_origin_url()
{
  local direction urls origin_url selected_url url_count

  direction="$1"
  case "$direction" in
  fetch)
    urls="$(git remote get-url --all "$RELEASE_REMOTE" 2>/dev/null)" || \
      fail "cannot resolve $RELEASE_REMOTE fetch URL"
    ;;
  push)
    urls="$(git remote get-url --push --all "$RELEASE_REMOTE" 2>/dev/null)" || \
      fail "cannot resolve $RELEASE_REMOTE push URL"
    ;;
  *) fail "unsupported $RELEASE_REMOTE URL direction: $direction" ;;
  esac

  [ -n "$urls" ] || fail "$RELEASE_REMOTE must have exactly one $direction URL"
  selected_url=''
  url_count=0
  while IFS= read -r origin_url; do
    [ -n "$origin_url" ] || fail "$RELEASE_REMOTE must have exactly one $direction URL"
    selected_url="$origin_url"
    url_count=$((url_count + 1))
  done <<<"$urls"
  [ "$url_count" -eq 1 ] || fail "$RELEASE_REMOTE must have exactly one $direction URL"
  printf '%s' "$selected_url"
}

extract_remote_path()
{
  local origin_url authority_and_path remote_authority

  origin_url="$1"
  case "$origin_url" in
  git@github.com:*)
    printf '%s' "${origin_url#git@github.com:}"
    ;;
  ssh://*)
    authority_and_path="${origin_url#ssh://}"
    [ "$authority_and_path" != "${authority_and_path#*/}" ] || return 1
    remote_authority="${authority_and_path%%/*}"
    [ "$remote_authority" = 'git@github.com' ] || return 1
    printf '%s' "${authority_and_path#*/}"
    ;;
  https://*)
    authority_and_path="${origin_url#https://}"
    [ "$authority_and_path" != "${authority_and_path#*/}" ] || return 1
    remote_authority="${authority_and_path%%/*}"
    [ "$remote_authority" = github.com ] || return 2
    printf '%s' "${authority_and_path#*/}"
    ;;
  *) return 1 ;;
  esac
}

validate_origin_url()
{
  local direction origin_url remote_path owner repository owner_lower repository_lower parse_status

  direction="$1"
  origin_url="$2"
  parse_status=0
  remote_path="$(extract_remote_path "$origin_url")" || parse_status=$?
  case "$parse_status" in
  0) ;;
  2) fail "$RELEASE_REMOTE $direction URL must use HTTPS host exactly github.com without userinfo" ;;
  *) fail "$RELEASE_REMOTE $direction URL must identify a supported github.com repository" ;;
  esac

  while [[ "$remote_path" == */ ]]; do
    remote_path="${remote_path%/}"
  done
  remote_path="${remote_path%.git}"
  [[ "$remote_path" =~ ^([^/]+)/([^/]+)$ ]] || \
    fail "$RELEASE_REMOTE $direction URL must identify one GitHub owner/repository"
  owner="${BASH_REMATCH[1]}"
  repository="${BASH_REMATCH[2]}"
  owner_lower="$(printf '%s' "$owner" | tr '[:upper:]' '[:lower:]')"
  repository_lower="$(printf '%s' "$repository" | tr '[:upper:]' '[:lower:]')"
  [ "$owner_lower/$repository_lower" = "$RELEASE_REPOSITORY" ] || \
    fail "$RELEASE_REMOTE $direction URL must identify $RELEASE_REPOSITORY"
}

configure_origin()
{
  ORIGIN_FETCH_URL="$(resolve_single_origin_url fetch)"
  ORIGIN_PUSH_URL="$(resolve_single_origin_url push)"
  validate_origin_url fetch "$ORIGIN_FETCH_URL"
  validate_origin_url push "$ORIGIN_PUSH_URL"

  GH_REPO="$RELEASE_REPOSITORY"
}

load_release_tag()
{
  local constants_version versions_json_version

  [[ -f config/constants.php && ! -L config/constants.php ]] || fail 'config/constants.php must be a regular file'
  [[ -f versions.json && ! -L versions.json ]] || fail 'versions.json must be a regular file'
  [[ -f "$ALLOWED_SIGNERS_FILE" && ! -L "$ALLOWED_SIGNERS_FILE" ]] || \
    fail "$ALLOWED_SIGNERS_FILE must be a regular file"

  # shellcheck disable=SC2016 # PHP receives this literal program.
  constants_version="$(php -r '
    function env($key, $default = null) { return $default; }
    $constants = require $argv[1];
    $version = $constants["coolify"]["version"] ?? null;
    if (! is_string($version) || $version === "") { exit(1); }
    echo $version;
  ' config/constants.php)" || fail 'could not read the Coolify version from config/constants.php'
  versions_json_version="$(jq -er '.coolify.v4.version | strings' versions.json)" || \
    fail 'could not read the Coolify v4 version from versions.json'

  validate_release_tag "$constants_version"
  validate_release_tag "$versions_json_version"
  [ "$constants_version" = "$versions_json_version" ] || \
    fail "config/constants.php version $constants_version does not equal versions.json version $versions_json_version"
  RELEASE_TAG="$constants_version"
}

require_clean_release_checkout()
{
  local branch dirty

  branch="$(git branch --show-current)" || fail 'could not resolve the current branch'
  [ "$branch" = "$RELEASE_BRANCH" ] || fail "current branch is $branch; expected $RELEASE_BRANCH"
  dirty="$(git status --porcelain)" || fail 'could not inspect the working tree'
  [ -z "$dirty" ] || fail 'working tree must be fully committed and clean before release tagging'
  SOURCE_SHA="$(git rev-parse --verify 'HEAD^{commit}')" || fail 'could not resolve HEAD to a commit'
  validate_sha "$SOURCE_SHA"
}

require_exact_origin_release_tip()
{
  local origin_sha

  git fetch --quiet "$ORIGIN_FETCH_URL" "refs/heads/$RELEASE_BRANCH:refs/remotes/origin/$RELEASE_BRANCH" || \
    fail "could not fetch origin/$RELEASE_BRANCH"
  origin_sha="$(git rev-parse --verify "refs/remotes/origin/$RELEASE_BRANCH^{commit}")" || \
    fail "could not resolve origin/$RELEASE_BRANCH"
  validate_sha "$origin_sha"
  [ "$SOURCE_SHA" = "$origin_sha" ] || \
    fail "HEAD $SOURCE_SHA must exactly equal origin/$RELEASE_BRANCH $origin_sha before release tagging"
}

require_absent_release_tag()
{
  local local_status remote_status

  if git show-ref --verify --quiet "refs/tags/$RELEASE_TAG"; then
    fail "release tag $RELEASE_TAG already exists locally"
  else
    local_status=$?
    [ "$local_status" -eq 1 ] || fail "could not determine whether a local tag named $RELEASE_TAG already exists"
  fi

  if git ls-remote --exit-code --tags "$ORIGIN_FETCH_URL" "refs/tags/$RELEASE_TAG" >/dev/null 2>&1; then
    fail "release tag $RELEASE_TAG already exists on origin"
  else
    remote_status=$?
    [ "$remote_status" -eq 2 ] || fail "could not determine whether origin already has tag $RELEASE_TAG"
  fi
}

create_and_verify_local_tag()
{
  local tag_type tag_target

  git -c gpg.format=ssh tag -s -a "$RELEASE_TAG" "$SOURCE_SHA" -m "Release $RELEASE_TAG" || \
    fail "could not create signed tag $RELEASE_TAG"
  tag_type="$(git cat-file -t "refs/tags/$RELEASE_TAG")" || fail "could not inspect local tag $RELEASE_TAG"
  [ "$tag_type" = tag ] || fail "local release tag $RELEASE_TAG must be annotated"
  tag_target="$(git rev-parse --verify "refs/tags/$RELEASE_TAG^{commit}")" || \
    fail "could not resolve local tag target for $RELEASE_TAG"
  [ "$tag_target" = "$SOURCE_SHA" ] || \
    fail "local release tag $RELEASE_TAG must target $SOURCE_SHA, not $tag_target"
  if ! git -c gpg.format=ssh \
    -c "gpg.ssh.allowedSignersFile=$ALLOWED_SIGNERS_FILE" verify-tag "$RELEASE_TAG"; then
    fail "local signed tag verification failed for $RELEASE_TAG; the tag was intentionally not deleted"
  fi
}

push_and_verify_remote_tag()
{
  local remote_tag_lines remote_tag_target remote_tag_reference

  if ! git push "$ORIGIN_PUSH_URL" "refs/tags/$RELEASE_TAG:refs/tags/$RELEASE_TAG"; then
    fail "could not push signed tag $RELEASE_TAG; the local tag was intentionally not deleted"
  fi

  remote_tag_reference="refs/tags/$RELEASE_TAG^{}"
  if ! remote_tag_lines="$(git ls-remote --exit-code --tags "$ORIGIN_FETCH_URL" "$remote_tag_reference")"; then
    fail "could not read the remote dereferenced target for $RELEASE_TAG; the tag was intentionally not deleted"
  fi
  remote_tag_target="$(printf '%s\n' "$remote_tag_lines" | awk -v ref="$remote_tag_reference" '$2 == ref { print $1 }')" || \
    fail "could not parse the remote dereferenced target for $RELEASE_TAG; the tag was intentionally not deleted"
  [ "$remote_tag_target" = "$SOURCE_SHA" ] || \
    fail "remote tag $RELEASE_TAG must dereference to $SOURCE_SHA, not ${remote_tag_target:-nothing}; the tag was intentionally not deleted"

  if ! GITHUB_REF="refs/tags/$RELEASE_TAG" GITHUB_SHA="$SOURCE_SHA" \
    "$REPO_ROOT/scripts/ci/verify-fork-release-tag.sh" "$RELEASE_TAG" "$SOURCE_SHA" \
    "$ORIGIN_FETCH_URL" "$ALLOWED_SIGNERS_FILE"; then
    fail "repository tag verifier failed for $RELEASE_TAG; the tag was intentionally not deleted"
  fi
}

find_exact_publish_run()
{
  local runs excluded_run_ids

  excluded_run_ids="${3:-[]}"

  if ! runs="$(gh run list --workflow "$PUBLISH_WORKFLOW" --event push --commit "$2" \
    --limit "$SHIP_STATUS_LIMIT" --repo "$GH_REPO" \
    --json databaseId,createdAt,event,headBranch,headSha,status,conclusion,url,workflowName)"; then
    fail "could not query $PUBLISH_WORKFLOW runs for tag $1 at $2"
  fi
  PUBLISH_RUN="$(printf '%s\n' "$runs" | jq -r --arg tag "$1" --arg sha "$2" \
    --argjson excludedRunIds "$excluded_run_ids" '
    [ .[]
      | select(.event == "push")
      | select(.headBranch == $tag)
      | select(.headSha == $sha)
      | select(.databaseId as $databaseId | ($excludedRunIds | index($databaseId)) == null)
      | select((.createdAt | type) == "string" and (.createdAt | length) > 0)
      | select((.databaseId | type) == "number")
      | {databaseId, createdAt, status, conclusion: (.conclusion // "pending"), url: (.url // "")}
    ]
    | sort_by([.createdAt, .databaseId])
    | last
    | if . == null then empty else [.databaseId, .status, .conclusion, .url] | @tsv end
  ')" || fail "could not select an exact $PUBLISH_WORKFLOW run for tag $1 at $2"
}

capture_existing_exact_publish_run_ids()
{
  local runs

  if ! runs="$(gh run list --workflow "$PUBLISH_WORKFLOW" --event push --commit "$2" \
    --limit "$SHIP_STATUS_LIMIT" --repo "$GH_REPO" \
    --json databaseId,createdAt,event,headBranch,headSha,status,conclusion,url,workflowName)"; then
    fail "could not snapshot existing $PUBLISH_WORKFLOW runs for tag $1 at $2 before tag push"
  fi
  PUBLISH_RUN_IDS_BEFORE_PUSH="$(printf '%s\n' "$runs" | jq -c --arg tag "$1" --arg sha "$2" '
    [ .[]
      | select(.event == "push")
      | select(.headBranch == $tag)
      | select(.headSha == $sha)
      | .databaseId
    ]
  ')" || fail "could not snapshot existing exact $PUBLISH_WORKFLOW run IDs for tag $1 at $2 before tag push"
}

wait_for_exact_publish_run()
{
  local attempt

  for ((attempt = 1; attempt <= SHIP_STATUS_DISCOVERY_ATTEMPTS; attempt++)); do
    find_exact_publish_run "$RELEASE_TAG" "$SOURCE_SHA" "$PUBLISH_RUN_IDS_BEFORE_PUSH"
    [ -z "$PUBLISH_RUN" ] || return 0
    [ "$attempt" -eq "$SHIP_STATUS_DISCOVERY_ATTEMPTS" ] || \
      sleep "$SHIP_STATUS_DISCOVERY_DELAY_SECONDS" || fail 'could not wait for publish workflow discovery'
  done
  fail "no newly observed exact $PUBLISH_WORKFLOW run appeared after tag push for $RELEASE_TAG at $SOURCE_SHA after $SHIP_STATUS_DISCOVERY_ATTEMPTS attempts; the tag was intentionally not deleted"
}

report_publish_run()
{
  local run_id run_status conclusion url

  IFS=$'\t' read -r run_id run_status conclusion url <<<"$PUBLISH_RUN"
  conclusion="${conclusion:-pending}"
  printf 'Publish fork run %s for tag %s at %s: status=%s conclusion=%s\n' \
    "$run_id" "$1" "$2" "$run_status" "$conclusion"
  [ -z "$url" ] || printf 'Run URL: %s\n' "$url"
}

fail_if_publish_run_completed_unsuccessfully()
{
  local run_status conclusion

  IFS=$'\t' read -r _ run_status conclusion _ <<<"$PUBLISH_RUN"
  if [ "$run_status" = completed ] && [ "$conclusion" != success ]; then
    fail "publish-fork workflow completed with conclusion ${conclusion:-unknown} for tag $1 at $2"
  fi
}

watch_publish_run()
{
  local run_id

  IFS=$'\t' read -r run_id _ <<<"$PUBLISH_RUN"
  report_publish_run "$RELEASE_TAG" "$SOURCE_SHA"
  if gh run watch "$run_id" --exit-status --repo "$GH_REPO"; then
    printf 'Publish fork workflow succeeded for tag %s at %s.\n' "$RELEASE_TAG" "$SOURCE_SHA"
  else
    fail "publish-fork workflow did not finish successfully for tag $RELEASE_TAG at $SOURCE_SHA; the tag was intentionally not deleted"
  fi
}

print_dry_run_plan()
{
  printf '[dry-run] git fetch --quiet <origin-fetch-url> refs/heads/%s:refs/remotes/origin/%s\n' "$RELEASE_BRANCH" "$RELEASE_BRANCH"
  printf '[dry-run] require HEAD %s == origin/%s after fetch\n' "$SOURCE_SHA" "$RELEASE_BRANCH"
  printf '[dry-run] require refs/tags/%s absent locally and through <origin-fetch-url>\n' "$RELEASE_TAG"
  printf '[dry-run] snapshot existing exact %s run IDs for headBranch=%s and headSha=%s before tag push\n' \
    "$PUBLISH_WORKFLOW" "$RELEASE_TAG" "$SOURCE_SHA"
  printf '[dry-run] git -c gpg.format=ssh tag -s -a %s %s -m Release\ %s\n' "$RELEASE_TAG" "$SOURCE_SHA" "$RELEASE_TAG"
  printf '[dry-run] git -c gpg.format=ssh -c gpg.ssh.allowedSignersFile=%s verify-tag %s\n' "$ALLOWED_SIGNERS_FILE" "$RELEASE_TAG"
  printf '[dry-run] git push <origin-push-url> refs/tags/%s:refs/tags/%s\n' "$RELEASE_TAG" "$RELEASE_TAG"
  printf '[dry-run] require <origin-fetch-url> refs/tags/%s^{} == %s\n' "$RELEASE_TAG" "$SOURCE_SHA"
  printf '[dry-run] GITHUB_REF=refs/tags/%s GITHUB_SHA=%s scripts/ci/verify-fork-release-tag.sh %s %s <origin-fetch-url> %s\n' \
    "$RELEASE_TAG" "$SOURCE_SHA" "$RELEASE_TAG" "$SOURCE_SHA" "$ALLOWED_SIGNERS_FILE"
  printf '[dry-run] poll gh run list --workflow %s --event push --commit %s --repo %s for a newly observed headBranch=%s and headSha=%s run\n' \
    "$PUBLISH_WORKFLOW" "$SOURCE_SHA" "$GH_REPO" "$RELEASE_TAG" "$SOURCE_SHA"
  printf '[dry-run] gh run watch <exact-run-id> --exit-status --repo %s\n' "$GH_REPO"
}

case "$command_name" in
ship)
  require_command git
  require_command php
  require_command jq
  require_command gh
  validate_positive_integer SHIP_STATUS_LIMIT "$SHIP_STATUS_LIMIT"
  validate_positive_integer SHIP_STATUS_DISCOVERY_ATTEMPTS "$SHIP_STATUS_DISCOVERY_ATTEMPTS"
  [[ "$SHIP_STATUS_DISCOVERY_DELAY_SECONDS" =~ ^[0-9]+$ ]] || \
    fail 'SHIP_STATUS_DISCOVERY_DELAY_SECONDS must be a non-negative integer'
  configure_origin
  require_clean_release_checkout
  load_release_tag
  if [ "$dry_run" -eq 1 ]; then
    print_dry_run_plan
    exit 0
  fi
  require_exact_origin_release_tip
  require_absent_release_tag
  capture_existing_exact_publish_run_ids "$RELEASE_TAG" "$SOURCE_SHA"
  create_and_verify_local_tag
  push_and_verify_remote_tag
  wait_for_exact_publish_run
  watch_publish_run
  ;;
status)
  require_command git
  require_command jq
  require_command gh
  validate_positive_integer SHIP_STATUS_LIMIT "$SHIP_STATUS_LIMIT"
  validate_release_tag "$status_tag"
  validate_sha "$status_sha"
  configure_origin
  find_exact_publish_run "$status_tag" "$status_sha"
  [ -n "$PUBLISH_RUN" ] || fail "no exact $PUBLISH_WORKFLOW run found for tag $status_tag at $status_sha"
  report_publish_run "$status_tag" "$status_sha"
  fail_if_publish_run_completed_unsuccessfully "$status_tag" "$status_sha"
  ;;
esac
