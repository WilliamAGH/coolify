#!/bin/sh

set -eu

fail()
{
    printf 'OCI_ARCHIVE_RUNTIME_VERIFICATION_FAILURE %s\n' "$1" >&2
    exit 1
}

require_sha256_digest()
{
    digest=$1
    description=$2

    printf '%s\n' "$digest" | grep -Eq '^sha256:[0-9a-f]{64}$' ||
        fail "invalid ${description}: ${digest}"
}

assert_blob_digest()
{
    expected_digest=$1
    blob_path="blobs/sha256/${expected_digest#sha256:}"

    if ! tar -tf "$OCI_ARCHIVE" | grep -Fqx "$blob_path"; then
        fail "archive does not contain ${blob_path}"
    fi

    actual_digest="sha256:$(tar -xOf "$OCI_ARCHIVE" "$blob_path" | sha256sum | awk '{print $1}')"
    [ "$actual_digest" = "$expected_digest" ] ||
        fail "archive blob digest mismatch for ${blob_path}"
}

: "${OCI_ARCHIVE:?OCI_ARCHIVE is required}"
: "${OCI_ARCHIVE_SHA256:?OCI_ARCHIVE_SHA256 is required}"
: "${OCI_CHILD_DIGEST:?OCI_CHILD_DIGEST is required}"
: "${OCI_PLATFORM:?OCI_PLATFORM is required}"
: "${OCI_IMAGE:?OCI_IMAGE is required}"

case "$OCI_PLATFORM" in
    linux/amd64) oci_architecture=amd64 ;;
    linux/arm64) oci_architecture=arm64 ;;
    *) fail "unsupported OCI_PLATFORM: ${OCI_PLATFORM}" ;;
esac

printf '%s\n' "$OCI_ARCHIVE_SHA256" | grep -Eq '^[0-9a-f]{64}$' ||
    fail "invalid OCI archive SHA-256: ${OCI_ARCHIVE_SHA256}"
require_sha256_digest "$OCI_CHILD_DIGEST" 'OCI child digest'
test -f "$OCI_ARCHIVE" || fail "OCI archive is absent: ${OCI_ARCHIVE}"
test "$(sha256sum "$OCI_ARCHIVE" | awk '{print $1}')" = "$OCI_ARCHIVE_SHA256" ||
    fail 'OCI archive SHA-256 differs from the recorded candidate metadata'
tar -tf "$OCI_ARCHIVE" >/dev/null

archive_child_digest="$(tar -xOf "$OCI_ARCHIVE" index.json | jq -er --arg architecture "$oci_architecture" '
    [.manifests[] | select(.platform.os == "linux" and .platform.architecture == $architecture)]
    | if length == 1 then .[0].digest else error("expected one native manifest") end
')"
require_sha256_digest "$archive_child_digest" 'archive child digest'
[ "$archive_child_digest" = "$OCI_CHILD_DIGEST" ] ||
    fail 'OCI index child digest differs from the recorded candidate metadata'
assert_blob_digest "$OCI_CHILD_DIGEST"

child_manifest_path="blobs/sha256/${OCI_CHILD_DIGEST#sha256:}"
config_digest="$(tar -xOf "$OCI_ARCHIVE" "$child_manifest_path" | jq -er '.config.digest')"
require_sha256_digest "$config_digest" 'OCI config digest'
assert_blob_digest "$config_digest"

# BuildKit's OCI exporter deliberately writes index.json rather than Docker's
# manifest.json. Docker Engine can load the same blobs when wrapped in that
# compatibility envelope, so make it directly from this already-verified OCI
# archive without rebuilding or changing the image config/layers.
runtime_archive_directory="$(mktemp -d "${RUNNER_TEMP:-/tmp}/coolify-oci-runtime.XXXXXX")"
docker_load_archive="$runtime_archive_directory/docker-load.tar"

cleanup()
{
    rm -rf "$runtime_archive_directory"
}

trap cleanup EXIT INT TERM

if docker image inspect "$config_digest" >/dev/null 2>&1; then
    fail 'candidate config digest already exists before the OCI archive import'
fi

python3 - "$OCI_ARCHIVE" "$docker_load_archive" "$OCI_IMAGE" "$OCI_CHILD_DIGEST" <<'PY'
import io
import json
import sys
import tarfile

source_path, destination_path, image_name, expected_child_digest = sys.argv[1:]

with tarfile.open(source_path, 'r:*') as source:
    index = json.load(source.extractfile('index.json'))
    child_descriptor = next(
        (descriptor for descriptor in index['manifests'] if descriptor['digest'] == expected_child_digest),
        None,
    )
    if child_descriptor is None:
        raise SystemExit('verified OCI child manifest is absent from the archive index')

    child_digest = expected_child_digest.split(':', 1)[1]
    child_manifest = json.load(source.extractfile(f'blobs/sha256/{child_digest}'))
    config_digest = child_manifest['config']['digest'].split(':', 1)[1]
    layer_sources = {layer['digest']: layer for layer in child_manifest['layers']}
    docker_manifest = [{
        'Config': f'blobs/sha256/{config_digest}',
        'RepoTags': [image_name],
        'Layers': [f"blobs/sha256/{layer['digest'].split(':', 1)[1]}" for layer in child_manifest['layers']],
        'LayerSources': layer_sources,
    }]

    with tarfile.open(destination_path, 'w') as destination:
        for member in source.getmembers():
            if member.name == 'manifest.json':
                continue
            destination.addfile(member, source.extractfile(member) if member.isfile() else None)

        manifest_bytes = json.dumps(docker_manifest, separators=(',', ':')).encode()
        manifest_member = tarfile.TarInfo('manifest.json')
        manifest_member.mode = 0o644
        manifest_member.size = len(manifest_bytes)
        destination.addfile(manifest_member, io.BytesIO(manifest_bytes))
PY

docker load --input "$docker_load_archive"
loaded_image_id="$(docker image inspect "$config_digest" --format '{{.Id}}')"
[ "$loaded_image_id" = "$config_digest" ] ||
    fail 'loaded image ID does not match the OCI config digest'
loaded_platform="$(docker image inspect "$config_digest" --format '{{.Os}}/{{.Architecture}}')"
[ "$loaded_platform" = "$OCI_PLATFORM" ] ||
    fail "loaded image platform ${loaded_platform} does not match ${OCI_PLATFORM}"
docker tag "$config_digest" "$OCI_IMAGE"
[ "$(docker image inspect "$OCI_IMAGE" --format '{{.Id}}')" = "$config_digest" ] ||
    fail 'runtime image tag does not resolve to the loaded OCI config digest'

if [ -n "${GITHUB_OUTPUT:-}" ]; then
    printf 'image=%s\n' "$OCI_IMAGE" >> "$GITHUB_OUTPUT"
fi

printf 'OCI_ARCHIVE_RUNTIME_VERIFIED archive_sha256=%s child_digest=%s image_id=%s platform=%s image=%s\n' \
    "$OCI_ARCHIVE_SHA256" "$OCI_CHILD_DIGEST" "$config_digest" "$OCI_PLATFORM" "$OCI_IMAGE"
