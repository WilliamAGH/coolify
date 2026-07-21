#!/usr/bin/env bash

set -Eeuo pipefail

repository_root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT
remote="$fixture/remote.git"
source_repository="$fixture/source"
verification_repository="$fixture/verification"
allowed_key="$fixture/allowed"
unlisted_key="$fixture/unlisted"
allowlist="$fixture/allowed-signers"

git init --bare "$remote" >/dev/null
git init "$source_repository" >/dev/null
git -C "$source_repository" config user.name 'Release Test'
git -C "$source_repository" config user.email 'release-test@example.invalid'
git -C "$source_repository" config commit.gpgSign false
git -C "$source_repository" config gpg.ssh.program ssh-keygen
printf '%s\n' release > "$source_repository/release.txt"
git -C "$source_repository" add release.txt
git -C "$source_repository" commit -m release >/dev/null
source_revision=$(git -C "$source_repository" rev-parse HEAD)

ssh-keygen -q -t ed25519 -N '' -f "$allowed_key"
ssh-keygen -q -t ed25519 -N '' -f "$unlisted_key"
printf 'release-test@example.invalid %s\n' "$(cat "$allowed_key.pub")" > "$allowlist"

create_signed_tag()
{
  tag=$1
  signing_key=$2
  git -C "$source_repository" config gpg.format ssh
  git -C "$source_repository" config user.signingkey "$signing_key"
  git -C "$source_repository" tag -s -m "$tag" "$tag" "$source_revision"
  git -C "$source_repository" push --force "$remote" "refs/tags/$tag" >/dev/null
}

create_signed_tag 4.13.3-fork "$allowed_key"
create_signed_tag 4.13.4-fork "$unlisted_key"
git -C "$source_repository" -c tag.gpgSign=false tag 4.13.5-fork "$source_revision"
git -C "$source_repository" push "$remote" refs/tags/4.13.5-fork >/dev/null
git init "$verification_repository" >/dev/null

run_verifier()
{
  version=$1
  revision=$2
  (
    cd "$verification_repository"
    # Local bare remotes are intentional fixtures; production CI sets
    # GITHUB_ACTIONS=true and would reject any non-repository remote.
    env -u GITHUB_ACTIONS -u GITHUB_REPOSITORY \
      GITHUB_REF="refs/tags/$version" GITHUB_SHA="$revision" \
      "$repository_root/scripts/ci/verify-fork-release-tag.sh" \
      "$version" "$revision" "$remote" "$allowlist"
  )
}

run_verifier 4.13.3-fork "$source_revision"
if run_verifier 4.13.4-fork "$source_revision" >/dev/null 2>&1; then
  printf '%s\n' 'unlisted release tag signer was accepted' >&2
  exit 1
fi
if run_verifier 4.13.5-fork "$source_revision" >/dev/null 2>&1; then
  printf '%s\n' 'lightweight release tag was accepted' >&2
  exit 1
fi
if run_verifier 4.13.3-fork "$(printf 'f%.0s' {1..40})" >/dev/null 2>&1; then
  printf '%s\n' 'mismatched release source revision was accepted' >&2
  exit 1
fi

printf '%s\n' 'Fork release tag signer verification: PASS'
