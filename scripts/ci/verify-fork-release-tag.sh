#!/usr/bin/env bash

set -Eeuo pipefail

semantic_version=${1:-}
source_revision=${2:-}
remote_url=${3:-}
allowed_signers_file=${4:-}
version_pattern='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)-fork$'

[[ "$semantic_version" =~ $version_pattern ]]
[[ "$source_revision" =~ ^[0-9a-f]{40}$ ]]
[[ -n "$remote_url" ]]
[[ "$allowed_signers_file" == docker/fork-release-tag-allowed-signers ]]
[[ "${GITHUB_REF:-}" == "refs/tags/$semantic_version" ]]
[[ "${GITHUB_SHA:-}" == "$source_revision" ]]
git rev-parse --show-toplevel >/dev/null

if [[ "${GITHUB_ACTIONS:-}" == true ]]; then
  [[ "$remote_url" == "https://github.com/${GITHUB_REPOSITORY:?}.git" ]]
fi

verification_ref="refs/coolify-release-verification/${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-0}-$$"
git check-ref-format "$verification_ref"
allowed_signers_snapshot=$(mktemp)
chmod 600 "$allowed_signers_snapshot"
cleanup_verification_ref()
{
  git update-ref -d "$verification_ref" >/dev/null 2>&1 || true
  rm -f "$allowed_signers_snapshot"
}
trap cleanup_verification_ref EXIT

git fetch --no-tags --force "$remote_url" \
  "refs/tags/$semantic_version:$verification_ref"
[[ "$(git cat-file -t "$verification_ref")" == tag ]]
tag_object_name=$(git cat-file tag "$verification_ref" | awk '$1 == "tag" { print $2; exit }')
[[ "$tag_object_name" == "$semantic_version" ]]
tag_target=$(git rev-parse --verify "${verification_ref}^{commit}")
[[ "$tag_target" == "$source_revision" ]]
inventory_entry=$(git ls-tree "$tag_target" -- "$allowed_signers_file")
[[ $(printf '%s\n' "$inventory_entry" | wc -l | tr -d ' ') == 1 ]]
inventory_mode=$(printf '%s\n' "$inventory_entry" | awk '{ print $1 }')
inventory_type=$(printf '%s\n' "$inventory_entry" | awk '{ print $2 }')
inventory_object=$(printf '%s\n' "$inventory_entry" | awk '{ print $3 }')
[[ "$inventory_mode" == 100644 ]]
[[ "$inventory_type" == blob ]]
[[ "$inventory_object" =~ ^[0-9a-f]{40,64}$ ]]
git show "$tag_target:$allowed_signers_file" > "$allowed_signers_snapshot"
[[ -s "$allowed_signers_snapshot" ]]
git -c gpg.ssh.allowedSignersFile="$allowed_signers_snapshot" verify-tag "$verification_ref"
