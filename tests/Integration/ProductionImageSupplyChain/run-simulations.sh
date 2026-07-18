#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
readonly SCRIPT_DIRECTORY
REPOSITORY_ROOT=$(cd -- "${1:?repository root is required}" && pwd -P)
readonly REPOSITORY_ROOT
ORIGINAL_PATH=$PATH
readonly ORIGINAL_PATH
SIMULATION_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/production-image-supply-chain.XXXXXX")
SIMULATION_ROOT=$(cd "$SIMULATION_ROOT" && pwd -P)
readonly SIMULATION_ROOT

trap 'rm -rf -- "$SIMULATION_ROOT"' EXIT

fail()
{
    printf 'PRODUCTION_IMAGE_SUPPLY_CHAIN_SIMULATION_FAILURE %s\n' "$1" >&2
    exit 1
}

run_provenance_verifier_simulation()
{
    local status verifier
    verifier="$REPOSITORY_ROOT/docker/verify-source-provenance.sh"

    for dockerfile in docker/production/Dockerfile docker/testing-host/Dockerfile; do
        if ! (
            cd "$REPOSITORY_ROOT"
            PATH="$SCRIPT_DIRECTORY/bin:$ORIGINAL_PATH" /bin/sh "$verifier" "$dockerfile"
        ) >/dev/null 2>&1; then
            fail "pinned source provenance verifier rejected $dockerfile under its simulated official tag mapping"
        fi
    done

    set +e
    (
        cd "$REPOSITORY_ROOT"
        PRODUCTION_IMAGE_SUPPLY_CHAIN_SIMULATION_MISMATCH=1 \
            PATH="$SCRIPT_DIRECTORY/bin:$ORIGINAL_PATH" \
            /bin/sh "$verifier" docker/production/Dockerfile >/dev/null 2>&1
    )
    status=$?
    set -e

    [[ $status -ne 0 ]] || fail 'pinned source provenance verifier accepted a simulated tag-to-commit mismatch'
}

file_mode()
{
    stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"
}

run_testing_host_keygen_simulation()
{
    local transformed_keygen runtime_directory private_key public_key derived_public_key_before derived_public_key_after
    local private_key_snapshot public_key_snapshot private_hash_before private_hash_after public_hash_before public_hash_after
    local private_key_mode public_key_mode uid gid
    transformed_keygen="$SIMULATION_ROOT/keygen.sh"
    runtime_directory="$SIMULATION_ROOT/runtime"
    private_key="$runtime_directory/private/testing-host"
    public_key="$runtime_directory/public/authorized_keys"
    derived_public_key_before="$SIMULATION_ROOT/derived-public-key.before"
    derived_public_key_after="$SIMULATION_ROOT/derived-public-key.after"
    private_key_snapshot="$SIMULATION_ROOT/private-key.snapshot"
    public_key_snapshot="$SIMULATION_ROOT/public-key.snapshot"
    uid=$(id -u)
    gid=$(id -g)

    sed \
        -e "s|/run/coolify-testing-host|$runtime_directory|g" \
        -e "s|-o root -g root|-o $uid -g $gid|g" \
        -e "s|root:root|$uid:$gid|g" \
        "$REPOSITORY_ROOT/docker/testing-host/keygen.sh" > "$transformed_keygen"
    chmod 700 "$transformed_keygen"

    COOLIFY_TESTING_KEY_UID="$uid" COOLIFY_TESTING_KEY_GID="$gid" "$transformed_keygen" >/dev/null

    [[ -f $private_key && -f $public_key ]] \
        || fail 'testing-host keygen simulation did not create both isolated key files'
    private_key_mode=$(file_mode "$private_key")
    public_key_mode=$(file_mode "$public_key")
    [[ $private_key_mode == 600 && $public_key_mode == 644 ]] \
        || fail "testing-host keygen simulation did not apply the expected key file modes: private=$private_key_mode public=$public_key_mode"
    ssh-keygen -lf "$public_key" >/dev/null 2>&1 \
        || fail 'testing-host keygen simulation wrote an invalid public key'
    ssh-keygen -y -f "$private_key" > "$derived_public_key_before"
    cmp -s "$derived_public_key_before" "$public_key" \
        || fail 'testing-host keygen simulation public key does not derive from its private key'
    [[ ! -e "$runtime_directory/public/testing-host" ]] \
        || fail 'testing-host keygen simulation exposed the private key in the public runtime directory'

    cp "$private_key" "$private_key_snapshot"
    cp "$public_key" "$public_key_snapshot"
    private_hash_before=$(sha256sum "$private_key" | awk '{print $1}')
    public_hash_before=$(sha256sum "$public_key" | awk '{print $1}')

    COOLIFY_TESTING_KEY_UID="$uid" COOLIFY_TESTING_KEY_GID="$gid" "$transformed_keygen" >/dev/null

    private_hash_after=$(sha256sum "$private_key" | awk '{print $1}')
    public_hash_after=$(sha256sum "$public_key" | awk '{print $1}')
    [[ $private_hash_after == "$private_hash_before" && $public_hash_after == "$public_hash_before" ]] \
        || fail 'testing-host keygen simulation changed established private or public key bytes on restart'
    cmp -s "$private_key_snapshot" "$private_key" \
        || fail 'testing-host keygen simulation changed the established private key on restart'
    cmp -s "$public_key_snapshot" "$public_key" \
        || fail 'testing-host keygen simulation changed the established public key on restart'
    ssh-keygen -y -f "$private_key" > "$derived_public_key_after"
    cmp -s "$derived_public_key_after" "$public_key" \
        || fail 'testing-host keygen simulation current public key does not derive from the current private key after restart'
    cmp -s "$derived_public_key_before" "$derived_public_key_after" \
        || fail 'testing-host keygen simulation changed the private-key derivation on restart'
}

if [[ ${2:-} == --keygen-only ]]; then
    run_testing_host_keygen_simulation
    printf '%s\n' 'PRODUCTION_IMAGE_SUPPLY_CHAIN_SIMULATION keygen=pass production_image_acceptance=false'

    exit 0
fi

bash -n \
    "$REPOSITORY_ROOT/docker/testing-host/keygen.sh" \
    "$REPOSITORY_ROOT/docker/verify-source-provenance.sh"

run_provenance_verifier_simulation

printf '%s\n' 'PRODUCTION_IMAGE_SUPPLY_CHAIN_SIMULATION result=pass production_image_acceptance=false'
