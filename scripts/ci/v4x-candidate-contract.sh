#!/usr/bin/env bash
# Canonical identity contract for a v4.x candidate and its trusted gate.

# shellcheck disable=SC2034 # Sourced by ship, verifier, and workflow callers.
readonly V4X_CANDIDATE_BASE_BRANCH='v4.x'
# shellcheck disable=SC2034 # Sourced by ship, verifier, and workflow callers.
readonly V4X_CANDIDATE_REF_PREFIX='ship/v4x'
# shellcheck disable=SC2034 # Sourced by ship, verifier, and workflow callers.
readonly V4X_CANDIDATE_WORKFLOW='gate-v4x-candidate.yml'
# shellcheck disable=SC2034 # Sourced by ship, verifier, and workflow callers.
readonly V4X_CANDIDATE_RUN_TITLE_REGEX='^Gate v4[.]x (?<candidate_sha>[0-9a-f]{40}) (?<candidate_ref>ship/v4x/[0-9a-f]{40}/[0-9a-f]{40}) from (?<base_sha>[0-9a-f]{40})$'

v4x_candidate_is_sha() {
  [[ "${1:-}" =~ ^[0-9a-f]{40}$ ]]
}

v4x_candidate_generation() {
  local candidate_sha="$1" generation
  v4x_candidate_is_sha "$candidate_sha" || return 64
  generation="$(printf '%s:%s:%s:%s:%s\n' "$candidate_sha" "$(date +%s)" "$$" "$RANDOM" "$RANDOM" | git hash-object --stdin)"
  v4x_candidate_is_sha "$generation" || return 1
  printf '%s\n' "$generation"
}

v4x_candidate_ref_for_sha() {
  local candidate_sha="$1" generation="$2"
  v4x_candidate_is_sha "$candidate_sha" && v4x_candidate_is_sha "$generation" || return 64
  printf '%s/%s/%s\n' "$V4X_CANDIDATE_REF_PREFIX" "$candidate_sha" "$generation"
}

v4x_candidate_full_ref_for_sha() {
  printf 'refs/heads/%s\n' "$(v4x_candidate_ref_for_sha "$1" "$2")"
}

v4x_candidate_ref_matches() {
  local candidate_ref="$1" candidate_sha="$2" generation
  generation="${candidate_ref##*/}"
  [ "$candidate_ref" = "$(v4x_candidate_ref_for_sha "$candidate_sha" "$generation")" ]
}

v4x_candidate_run_title() {
  local candidate_sha="$1" candidate_ref="$2" base_sha="$3"
  v4x_candidate_is_sha "$candidate_sha" && v4x_candidate_is_sha "$base_sha" || return 64
  v4x_candidate_ref_matches "$candidate_ref" "$candidate_sha" || return 64
  printf 'Gate v4.x %s %s from %s\n' "$candidate_sha" "$candidate_ref" "$base_sha"
}
