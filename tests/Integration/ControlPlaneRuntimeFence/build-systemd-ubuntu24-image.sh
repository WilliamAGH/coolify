#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

SCRIPT_DIRECTORY=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
readonly SCRIPT_DIRECTORY
REPOSITORY_ROOT=$(cd -- "$SCRIPT_DIRECTORY/../../.." && pwd -P)
readonly REPOSITORY_ROOT
readonly DOCKERFILE=$SCRIPT_DIRECTORY/Dockerfile.systemd-ubuntu24
readonly BOOT_SCRIPT=$SCRIPT_DIRECTORY/systemd-host-boot.sh
readonly BOOT_UNIT=$SCRIPT_DIRECTORY/coolify-runtime-fence-host-boot.service
readonly BUILD_CA_SECRET_ID=bootstrap-ca
readonly DEFAULT_BUILD_CA_FILE=/etc/ssl/cert.pem
readonly LOCAL_REGISTRY_IMAGE='docker.io/library/registry:2.8.3@sha256:a3d8aaa63ed8681a604f1dea0aa03f100d5895b6a58ace528858a7b332415373'
readonly LOCAL_REGISTRY_REPOSITORY=coolify-runtime-fence/systemd-host

fail()
{
    printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_IMAGE_BUILD_FAILURE %s\n' "$1" >&2
    exit 1
}

source_attestation_sha256()
{
    local path

    {
        for path in "$DOCKERFILE" "$BOOT_SCRIPT" "$BOOT_UNIT" "$SCRIPT_DIRECTORY/build-systemd-ubuntu24-image.sh"; do
            printf '%s\0%s\0' "${path#"$REPOSITORY_ROOT"/}" "$(sha256sum "$path" | awk '{print $1}')"
        done
    } | sha256sum | awk '{print $1}'
}

assert_build_ca_file()
{
    local ca_file=$1

    [[ $ca_file == /* && $ca_file != *,* && $ca_file != *$'\n'* \
        && -f $ca_file && ! -L $ca_file && -s $ca_file && -r $ca_file ]] \
        || fail 'the bootstrap CA file must be an absolute, non-symlink, nonempty readable regular file'
    if ! grep -F -q -- '-----BEGIN CERTIFICATE-----' "$ca_file" \
        || ! grep -F -q -- '-----END CERTIFICATE-----' "$ca_file"; then
        fail 'the bootstrap CA file is not PEM certificate data'
    fi
    openssl crl2pkcs7 -nocrl -certfile "$ca_file" 2>/dev/null \
        | openssl pkcs7 -print_certs -noout 2>/dev/null \
        | grep -q '^subject=' \
        || fail 'the bootstrap CA file does not contain a parseable PEM certificate'
}

assert_image_tag()
{
    [[ $1 =~ ^[A-Za-z0-9][A-Za-z0-9._/:@-]*:[A-Za-z0-9][A-Za-z0-9._-]*$ && $1 != *@* ]] \
        || fail 'image repository must include a mutable publication tag and no digest'
}

static_check()
{
    local path

    for path in "$DOCKERFILE" "$BOOT_SCRIPT" "$BOOT_UNIT" "$SCRIPT_DIRECTORY/build-systemd-ubuntu24-image.sh"; do
        [[ -f $path && ! -L $path ]] || fail "required source is absent or unsafe: $path"
    done
    grep -F -q -- 'RUN --mount=type=secret,id=bootstrap-ca,target=/run/secrets/bootstrap-ca,required=true' \
        "$DOCKERFILE" \
        || fail 'the host Dockerfile no longer requires a BuildKit bootstrap CA secret'
    if ! grep -F -q -- 'apt_ca_copy=/tmp/runtime-fence-bootstrap-ca.pem' "$DOCKERFILE" \
        || ! grep -F -q -- "chmod 0644 \"\$apt_ca_copy\"" "$DOCKERFILE" \
        || ! grep -F -q -- "cmp -s \"\$bootstrap_ca\" \"\$apt_ca_copy\"" "$DOCKERFILE" \
        || ! grep -F -q -- 'Acquire::https::CaInfo' "$DOCKERFILE" \
        || ! grep -F -q -- "shred --remove --zero \"\$apt_ca_copy\"" "$DOCKERFILE" \
        || ! grep -F -q -- "test ! -e \"\$apt_ca_copy\" && test ! -e \"\$apt_ca_configuration\"" "$DOCKERFILE"; then
        fail 'the host Dockerfile no longer creates, verifies, and removes the apt-readable bootstrap CA copy'
    fi
    if ! grep -F -q -- 'Acquire::AllowInsecureRepositories=false' "$DOCKERFILE" \
        || ! grep -F -q -- 'APT::Get::AllowUnauthenticated=false' "$DOCKERFILE"; then
        fail 'the host Dockerfile no longer enforces signed apt package verification'
    fi
    if ! grep -F -q -- '--provenance=mode=max' "$SCRIPT_DIRECTORY/build-systemd-ubuntu24-image.sh" \
        || ! grep -F -q -- '--sbom=true' "$SCRIPT_DIRECTORY/build-systemd-ubuntu24-image.sh"; then
        fail 'the host image build no longer emits explicit provenance and SBOM attestations'
    fi
    source_attestation_sha256 >/dev/null
    printf 'CONTROL_PLANE_RUNTIME_FENCE_HOST_IMAGE_BUILD check=passed\n'
}

usage()
{
    printf '%s\n' \
        'usage: CONTROL_PLANE_RUNTIME_HOST_IMAGE_BUILD_MODE=loopback-registry|push build-systemd-ubuntu24-image.sh IMAGE_REPOSITORY:TAG' \
        'Default mode is loopback-registry: it pushes only to an ephemeral 127.0.0.1 registry, pulls the resulting RepoDigest locally, and prints it.' \
        'Set CONTROL_PLANE_RUNTIME_HOST_IMAGE_BUILD_MODE=push to explicitly publish IMAGE_REPOSITORY:TAG externally.' \
        'CONTROL_PLANE_RUNTIME_BUILD_CA_FILE defaults to /etc/ssl/cert.pem and must be a readable non-symlink PEM bundle.'
}

build_and_push()
{
    local destination=$1 source_sha256=$2 ca_file=$3

    docker buildx build --platform linux/amd64 --provenance=mode=max --sbom=true \
        --secret "id=${BUILD_CA_SECRET_ID},src=${ca_file}" \
        --build-arg "HOST_SOURCE_SHA256=$source_sha256" \
        --file "$DOCKERFILE" --push --tag "$destination" "$REPOSITORY_ROOT"
}

build_and_load()
{
    local destination=$1 source_sha256=$2 ca_file=$3

    docker buildx build --platform linux/amd64 --provenance=mode=max --sbom=true \
        --secret "id=${BUILD_CA_SECRET_ID},src=${ca_file}" \
        --build-arg "HOST_SOURCE_SHA256=$source_sha256" \
        --file "$DOCKERFILE" --load --tag "$destination" "$REPOSITORY_ROOT"
}

published_reference()
{
    local image_tag=$1 digest

    digest=$(docker buildx imagetools inspect --format '{{.Digest}}' "$image_tag")
    [[ $digest =~ ^sha256:[a-f0-9]{64}$ ]] || fail 'published image did not resolve to an immutable digest'
    printf '%s@%s' "${image_tag%:*}" "$digest"
}

local_registry_container=

cleanup_local_registry()
{
    if [[ -n $local_registry_container ]]; then
        docker rm -f "$local_registry_container" >/dev/null 2>&1 || true
    fi
}

start_local_registry()
{
    local binding

    local_registry_container="coolify-runtime-fence-build-registry-$$"
    ! docker container inspect "$local_registry_container" >/dev/null 2>&1 \
        || fail 'refusing to reuse a local build registry container'
    docker pull "$LOCAL_REGISTRY_IMAGE" >/dev/null \
        || fail 'could not pull the pinned local loopback registry image'
    docker run --detach --pull never --name "$local_registry_container" \
        --tmpfs /var/lib/registry:rw,mode=0700 \
        --publish 127.0.0.1::5000 "$LOCAL_REGISTRY_IMAGE" >/dev/null
    binding=$(docker port "$local_registry_container" 5000/tcp)
    [[ $binding =~ ^127\.0\.0\.1:([1-9][0-9]{0,4})$ ]] \
        || fail 'the local build registry is not bound only to 127.0.0.1'
    LOCAL_REGISTRY_PORT=${BASH_REMATCH[1]}
    ((LOCAL_REGISTRY_PORT <= 65535)) || fail 'the local build registry exposed an invalid port'
    for _ in {1..30}; do
        curl --fail --silent --show-error --max-time 2 "http://127.0.0.1:${LOCAL_REGISTRY_PORT}/v2/" \
            >/dev/null 2>&1 && return
        sleep 1
    done
    fail 'the local loopback registry did not become ready'
}

local_repo_digest()
{
    local image_tag=$1 candidate repo_digest=

    docker pull "$image_tag" >/dev/null \
        || fail 'could not pull the image back from the local loopback registry'
    while IFS= read -r candidate; do
        if [[ $candidate == "${image_tag%:*}"@sha256:* ]]; then
            repo_digest=$candidate
            break
        fi
    done < <(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$image_tag")
    [[ $repo_digest =~ ^127\.0\.0\.1:[1-9][0-9]{0,4}/[A-Za-z0-9._/-]+@sha256:[a-f0-9]{64}$ ]] \
        || fail 'the local loopback registry image did not retain a RepoDigest'
    docker image inspect "$repo_digest" >/dev/null \
        || fail 'the local loopback RepoDigest is not available to the host gate'
    printf '%s' "$repo_digest"
}

if [[ ${1:-} == --check ]]; then
    [[ $# -eq 1 ]] || {
        usage >&2
        exit 64
    }
    static_check
    exit 0
fi

[[ $# -eq 1 ]] || {
    usage >&2
    exit 64
}

image_tag=$1
assert_image_tag "$image_tag"
build_mode=${CONTROL_PLANE_RUNTIME_HOST_IMAGE_BUILD_MODE:-loopback-registry}
case "$build_mode" in
    loopback-registry|push) ;;
    *) fail 'CONTROL_PLANE_RUNTIME_HOST_IMAGE_BUILD_MODE must be loopback-registry or push' ;;
esac

for command in docker grep openssl sha256sum; do
    command -v "$command" >/dev/null || fail "required command is unavailable: $command"
done
docker buildx version >/dev/null || fail 'docker buildx is unavailable'

build_ca_file=${CONTROL_PLANE_RUNTIME_BUILD_CA_FILE:-$DEFAULT_BUILD_CA_FILE}
assert_build_ca_file "$build_ca_file"
source_sha256=$(source_attestation_sha256)

case "$build_mode" in
    push)
        build_and_push "$image_tag" "$source_sha256" "$build_ca_file"
        host_reference=$(published_reference "$image_tag")
        ;;
    loopback-registry)
        command -v curl >/dev/null || fail 'curl is required for loopback-registry mode'
        trap cleanup_local_registry EXIT
        start_local_registry
        local_image_tag="127.0.0.1:${LOCAL_REGISTRY_PORT}/${LOCAL_REGISTRY_REPOSITORY}:${image_tag##*:}"
        build_and_load "$local_image_tag" "$source_sha256" "$build_ca_file"
        docker push "$local_image_tag" >/dev/null
        host_reference=$(local_repo_digest "$local_image_tag")
        cleanup_local_registry
        trap - EXIT
        ;;
esac

printf 'CONTROL_PLANE_RUNTIME_HOST_IMAGE=%s\n' "$host_reference"
