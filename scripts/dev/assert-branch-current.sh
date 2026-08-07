#!/usr/bin/env bash
# Fail when the local checkout has drifted from origin before commit/tag/release work.
#
# Usage:
#   scripts/dev/assert-branch-current.sh                  # fetch + behind check
#   scripts/dev/assert-branch-current.sh --require-clean  # also require a clean tree
set -euo pipefail

REQUIRE_CLEAN=0
for arg in "$@"; do
  case "$arg" in
    --require-clean) REQUIRE_CLEAN=1 ;;
    *) printf 'error: unknown argument: %s\n' "$arg" >&2; exit 2 ;;
  esac
done

log() { printf '%s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

branch="$(git symbolic-ref --quiet --short HEAD)" || die "HEAD is detached; check out a branch first"

git fetch --quiet origin "refs/heads/${branch}:refs/remotes/origin/${branch}" || die "git fetch origin ${branch} failed"

behind="$(git rev-list --count "HEAD..origin/${branch}")"
if [ "$behind" != "0" ]; then
  die "local ${branch} is ${behind} commit(s) behind origin/${branch}; pull or rebase before continuing"
fi

if [ "$REQUIRE_CLEAN" = "1" ] && [ -n "$(git status --porcelain)" ]; then
  die "working tree is dirty; commit or stash changes before tagging (drop --require-clean to skip)"
fi

if [ "$REQUIRE_CLEAN" = "1" ]; then
  log "OK: ${branch} is current with origin/${branch} (0 behind) and the working tree is clean"
else
  log "OK: ${branch} is current with origin/${branch} (0 behind)"
fi
