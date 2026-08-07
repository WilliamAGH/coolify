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
external_allowlist="$fixture/allowed-signers"

git init --bare "$remote" >/dev/null
git init "$source_repository" >/dev/null
git -C "$source_repository" config user.name 'Release Test'
git -C "$source_repository" config user.email 'release-test@example.invalid'
git -C "$source_repository" config commit.gpgSign false
git -C "$source_repository" config gpg.format openpgp
git -C "$source_repository" config gpg.ssh.program ssh-keygen
ssh-keygen -q -t ed25519 -N '' -f "$allowed_key"
ssh-keygen -q -t ed25519 -N '' -f "$unlisted_key"
mkdir -p "$source_repository/docker"
printf 'release-test@example.invalid %s\n' "$(cat "$allowed_key.pub")" > "$source_repository/docker/fork-release-tag-allowed-signers"
printf '%s\n' release > "$source_repository/release.txt"
git -C "$source_repository" add release.txt docker/fork-release-tag-allowed-signers
git -C "$source_repository" commit -m release >/dev/null
source_revision=$(git -C "$source_repository" rev-parse HEAD)

printf 'release-test@example.invalid %s\n' "$(cat "$allowed_key.pub")" > "$external_allowlist"

create_signed_tag()
{
  tag=$1
  signing_key=$2
  tag_revision=${3:-$source_revision}
  git -C "$source_repository" config user.signingkey "$signing_key"
  git -C "$source_repository" -c gpg.format=ssh tag -s -m "$tag" "$tag" "$tag_revision"
  git -C "$source_repository" push --force "$remote" "refs/tags/$tag" >/dev/null
}

create_signed_tag 4.13.3-fork "$allowed_key"
create_signed_tag 4.13.4-fork "$unlisted_key"
git -C "$source_repository" -c tag.gpgSign=false tag 4.13.5-fork "$source_revision"
git -C "$source_repository" push "$remote" refs/tags/4.13.5-fork >/dev/null
git -C "$source_repository" rm docker/fork-release-tag-allowed-signers >/dev/null
git -C "$source_repository" commit -m 'remove canonical signer inventory' >/dev/null
missing_inventory_revision=$(git -C "$source_repository" rev-parse HEAD)
create_signed_tag 4.13.6-fork "$allowed_key" "$missing_inventory_revision"
git init "$verification_repository" >/dev/null
git -C "$verification_repository" config gpg.format openpgp
mkdir -p "$verification_repository/docker"
printf 'release-test@example.invalid %s\n' "$(cat "$allowed_key.pub")" > "$verification_repository/docker/fork-release-tag-allowed-signers"

run_verifier()
{
  version=$1
  revision=$2
  inventory_path=${3:-docker/fork-release-tag-allowed-signers}
  (
    cd "$verification_repository"
    # Local bare remotes are intentional fixtures; production CI sets
    # GITHUB_ACTIONS=true and would reject any non-repository remote.
    env -u GITHUB_ACTIONS -u GITHUB_REPOSITORY \
      GITHUB_REF="refs/tags/$version" GITHUB_SHA="$revision" \
      "$repository_root/scripts/ci/verify-fork-release-tag.sh" \
      "$version" "$revision" "$remote" "$inventory_path"
  )
}

run_verifier 4.13.3-fork "$source_revision"
printf 'release-test@example.invalid %s\n' "$(cat "$unlisted_key.pub")" > "$verification_repository/docker/fork-release-tag-allowed-signers"
if run_verifier 4.13.4-fork "$source_revision" >/dev/null 2>&1; then
  printf '%s\n' 'unlisted release tag signer was accepted through a mutable working-tree allowlist' >&2
  exit 1
fi
if run_verifier 4.13.3-fork "$source_revision" "$external_allowlist" >/dev/null 2>&1; then
  printf '%s\n' 'an external release signer inventory path was accepted' >&2
  exit 1
fi
if run_verifier 4.13.6-fork "$missing_inventory_revision" >/dev/null 2>&1; then
  printf '%s\n' 'a tag target without the canonical signer inventory was accepted' >&2
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
