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
[[ -n "$allowed_signers_file" ]]
[[ "${GITHUB_REF:-}" == "refs/tags/$semantic_version" ]]
[[ "${GITHUB_SHA:-}" == "$source_revision" ]]

repository_root=$(git rev-parse --show-toplevel)
case "$allowed_signers_file" in
  /*) ;;
  *) allowed_signers_file="$repository_root/$allowed_signers_file" ;;
esac
[[ -f "$allowed_signers_file" && ! -L "$allowed_signers_file" ]]

if [[ "${GITHUB_ACTIONS:-}" == true ]]; then
  [[ "$remote_url" == "https://github.com/${GITHUB_REPOSITORY:?}.git" ]]
fi

verification_ref="refs/coolify-release-verification/${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-0}-$$"
git check-ref-format "$verification_ref"
cleanup_verification_ref()
{
  git update-ref -d "$verification_ref" >/dev/null 2>&1 || true
}
trap cleanup_verification_ref EXIT

git fetch --no-tags --force "$remote_url" \
  "refs/tags/$semantic_version:$verification_ref"
[[ "$(git cat-file -t "$verification_ref")" == tag ]]
tag_object_name=$(git cat-file tag "$verification_ref" | awk '$1 == "tag" { print $2; exit }')
[[ "$tag_object_name" == "$semantic_version" ]]
git -c gpg.ssh.allowedSignersFile="$allowed_signers_file" verify-tag "$verification_ref"
[[ "$(git rev-parse --verify "${verification_ref}^{commit}")" == "$source_revision" ]]
