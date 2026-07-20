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

case "${OCI_CONTENT_POLICY:-none}" in
    none|control-plane-main) ;;
    *) fail "unsupported OCI_CONTENT_POLICY: ${OCI_CONTENT_POLICY}" ;;
esac

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

archive_index="$(tar -xOf "$OCI_ARCHIVE" index.json)"
direct_native_count="$(printf '%s' "$archive_index" | jq -r --arg architecture "$oci_architecture" '
    [.manifests[] | select(.platform.os == "linux" and .platform.architecture == $architecture)] | length
')"
if [ "$direct_native_count" = 0 ]; then
    nested_index_digest="$(printf '%s' "$archive_index" | jq -er '
        [.manifests[] |
            select(.mediaType == "application/vnd.oci.image.index.v1+json") |
            select(.annotations["vnd.docker.reference.type"] != "attestation-manifest")] |
        if length == 1 then .[0].digest else error("expected one nested image index") end
    ')"
    require_sha256_digest "$nested_index_digest" 'nested OCI index digest'
    assert_blob_digest "$nested_index_digest"
    nested_index_path="blobs/sha256/${nested_index_digest#sha256:}"
    archive_index="$(tar -xOf "$OCI_ARCHIVE" "$nested_index_path")"
elif [ "$direct_native_count" != 1 ]; then
    fail "expected one native manifest, found ${direct_native_count}"
fi

archive_child_digest="$(printf '%s' "$archive_index" | jq -er --arg architecture "$oci_architecture" '
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
runtime_container_id=

cleanup()
{
    if [ -n "$runtime_container_id" ]; then
        docker rm "$runtime_container_id" >/dev/null 2>&1 || true
    fi
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
    indexes = [index]
    for descriptor in index['manifests']:
        if descriptor.get('mediaType') != 'application/vnd.oci.image.index.v1+json':
            continue
        nested_digest = descriptor['digest'].split(':', 1)[1]
        indexes.append(json.load(source.extractfile(f'blobs/sha256/{nested_digest}')))
    child_descriptor = next((
        descriptor
        for candidate_index in indexes
        for descriptor in candidate_index['manifests']
        if descriptor['digest'] == expected_child_digest
    ), None)
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

if [ "${OCI_CONTENT_POLICY:-none}" = control-plane-main ]; then
    runtime_rootfs_archive="$runtime_archive_directory/runtime-rootfs.tar"
    runtime_container_id="$(docker create "$OCI_IMAGE")"
    docker export --output "$runtime_rootfs_archive" "$runtime_container_id"
    docker rm "$runtime_container_id" >/dev/null
    runtime_container_id=

    python3 - "$runtime_rootfs_archive" <<'PY'
import sys
import tarfile

archive_path = sys.argv[1]
required_attestor = 'var/www/html/scripts/control-plane-traefik-attestor'
allowed_service = 'etc/s6-overlay/s6-rc.d/control-plane-traefik-attestor'
allowed_service_members = {
    allowed_service,
    f'{allowed_service}/dependencies.d',
    f'{allowed_service}/dependencies.d/init-script',
    f'{allowed_service}/run',
    f'{allowed_service}/type',
}
required_service_members = {
    allowed_service: 'directory',
    f'{allowed_service}/dependencies.d/init-script': 'file',
    f'{allowed_service}/run': 'executable',
    f'{allowed_service}/type': 'file',
    'etc/s6-overlay/s6-rc.d/user/contents.d/control-plane-traefik-attestor': 'file',
}
forbidden = []

with tarfile.open(archive_path, 'r:*') as archive:
    members = {
        member.name.removeprefix('./').rstrip('/'): member
        for member in archive.getmembers()
    }

    attestor = members.get(required_attestor)
    if attestor is None or not attestor.isfile() or attestor.mode & 0o111 == 0:
        raise SystemExit('bounded control-plane Traefik attestor is absent or not executable')

    for path, expected_type in required_service_members.items():
        member = members.get(path)
        if member is None:
            raise SystemExit(f'bounded attestor service member is absent: {path}')
        if expected_type == 'directory' and not member.isdir():
            raise SystemExit(f'bounded attestor service member is not a directory: {path}')
        if expected_type in ('file', 'executable') and not member.isfile():
            raise SystemExit(f'bounded attestor service member is not a regular file: {path}')
        if expected_type == 'executable' and member.mode & 0o111 == 0:
            raise SystemExit(f'bounded attestor service member is not executable: {path}')

    service_type = archive.extractfile(members[f'{allowed_service}/type']).read().decode().strip()
    if service_type != 'longrun':
        raise SystemExit('bounded attestor service type is not longrun')

    for path, member in members.items():
        lowered = path.lower()
        parts = lowered.split('/')
        basename = parts[-1]

        if any('haproxy' in component for component in parts) or basename == 'traefik-ingress.sh':
            forbidden.append(path)
            continue

        if (
            lowered == 'var/www/html/docker/control-plane-blue-green/controllers'
            or lowered.startswith('var/www/html/docker/control-plane-blue-green/controllers/')
        ):
            forbidden.append(path)
            continue

        if lowered.startswith('var/www/html/scripts/') and lowered != required_attestor:
            forbidden.append(path)
            continue

        if lowered.startswith(f'{allowed_service}/') and path not in allowed_service_members:
            forbidden.append(path)
            continue

        if lowered.startswith('etc/s6-overlay/s6-rc.d/'):
            service = '/'.join(path.split('/')[:4])
            service_name = path.split('/')[3] if len(path.split('/')) > 3 else ''
            if service != allowed_service and any(
                marker in service_name.lower()
                for marker in ('control-plane', 'traefik', 'ingress')
            ):
                forbidden.append(path)
                continue

        if (
            member.isfile()
            and member.mode & 0o111
            and lowered != required_attestor
            and (
                lowered.startswith('usr/local/bin/')
            )
            and any(marker in basename for marker in ('control-plane', 'traefik', 'ingress'))
        ):
            forbidden.append(path)

if forbidden:
    rendered = '\n'.join(f'  {path}' for path in sorted(set(forbidden)))
    raise SystemExit(f'forbidden ingress/controller release content found:\n{rendered}')
PY
fi

if [ -n "${GITHUB_OUTPUT:-}" ]; then
    printf 'image=%s\n' "$OCI_IMAGE" >> "$GITHUB_OUTPUT"
fi

printf 'OCI_ARCHIVE_RUNTIME_VERIFIED archive_sha256=%s child_digest=%s image_id=%s platform=%s image=%s\n' \
    "$OCI_ARCHIVE_SHA256" "$OCI_CHILD_DIGEST" "$config_digest" "$OCI_PLATFORM" "$OCI_IMAGE"
