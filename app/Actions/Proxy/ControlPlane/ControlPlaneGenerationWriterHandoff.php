<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class ControlPlaneGenerationWriterHandoff
{
    use AsAction;

    public const CONTAINER_MARKER_PATH = '/var/www/html/storage/framework/cache/.coolify-control-plane-writer-authority.json';

    public const COMPLETION_MARKER = 'coolify-control-plane-generation-writer-handoff:complete';

    private const MAX_MARKER_BYTES = 1024;

    public function handle(ControlPlaneGenerationPromotionState $state): string
    {
        return $this->commandFor($state);
    }

    public function commandFor(ControlPlaneGenerationPromotionState $state): string
    {
        $allowMarkerCreate = $this->allowMarkerCreate($state);
        $writerIdentity = $state->runtime->writerIdentity();
        $marker = $this->markerFor($state, $writerIdentity);

        $script = <<<'SH'
set -eu
expected_docker_id=__EXPECTED_DOCKER_ID__
expected_container_name=__EXPECTED_CONTAINER_NAME__
expected_image_id=__EXPECTED_IMAGE_ID__
marker_path=__MARKER_PATH__
expected_marker=__EXPECTED_MARKER__
allow_marker_create=__ALLOW_MARKER_CREATE__
completion_marker=__COMPLETION_MARKER__

fail() { exit 1; }

writer_inspection=$(docker inspect --type container --format '{{.Id}}|{{.Name}}|{{.Image}}|{{.State.Running}}' "$expected_docker_id" 2>/dev/null) || fail
[ "$writer_inspection" = "$expected_docker_id|/$expected_container_name|$expected_image_id|true" ] || fail

docker exec "$expected_docker_id" sh -ceu __WRITE_MARKER_SCRIPT__ 'coolify-control-plane-writer-authority' "$marker_path" "$expected_marker" "$allow_marker_create" || fail
writer_marker_readback=$(docker exec "$expected_docker_id" sh -ceu __READ_MARKER_SCRIPT__ 'coolify-control-plane-writer-authority-readback' "$marker_path" "$expected_marker") || fail
[ "$writer_marker_readback" = "$expected_marker" ] || fail
printf '%s\n' "$completion_marker"
SH;

        return strtr($script, [
            '__EXPECTED_DOCKER_ID__' => escapeshellarg($writerIdentity['container_id']),
            '__EXPECTED_CONTAINER_NAME__' => escapeshellarg($writerIdentity['name']),
            '__EXPECTED_IMAGE_ID__' => escapeshellarg($writerIdentity['image_id']),
            '__MARKER_PATH__' => escapeshellarg(self::CONTAINER_MARKER_PATH),
            '__EXPECTED_MARKER__' => escapeshellarg($marker),
            '__ALLOW_MARKER_CREATE__' => escapeshellarg($allowMarkerCreate ? 'true' : 'false'),
            '__COMPLETION_MARKER__' => escapeshellarg(self::COMPLETION_MARKER),
            '__WRITE_MARKER_SCRIPT__' => escapeshellarg($this->writeMarkerScript()),
            '__READ_MARKER_SCRIPT__' => escapeshellarg($this->readMarkerScript()),
        ]);
    }

    private function allowMarkerCreate(ControlPlaneGenerationPromotionState $state): bool
    {
        return match ($state->phase) {
            ControlPlaneGenerationPromotionPhase::WriterPromoting => true,
            ControlPlaneGenerationPromotionPhase::FenceReleasing,
            ControlPlaneGenerationPromotionPhase::Unfreezing => false,
            default => throw new InvalidArgumentException('The control-plane writer handoff requires WriterPromoting; FenceReleasing and Unfreezing only permit marker replay.'),
        };
    }

    /**
     * @param  array{name: string, container_id: string, image_id: string}  $writerIdentity
     */
    private function markerFor(ControlPlaneGenerationPromotionState $state, array $writerIdentity): string
    {
        $marker = json_encode([
            'operation_id' => $state->operationId,
            'writer_epoch' => $state->writerEpoch,
            'writer_member' => $state->writerMember,
            'writer_container_id' => $writerIdentity['container_id'],
            'writer_image_id' => $writerIdentity['image_id'],
            'successor_dynamic_sha256' => $state->successor['dynamic_sha256'],
            'successor_release_revision' => $state->successor['release_revision'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        if (strlen($marker) > self::MAX_MARKER_BYTES) {
            throw new InvalidArgumentException('The control-plane writer authority marker exceeds its bounded size.');
        }

        return $marker;
    }

    private function writeMarkerScript(): string
    {
        return <<<'SH'
marker_path=$1
expected_marker=$2
allow_marker_create=$3
marker_directory=${marker_path%/*}
[ -n "$marker_directory" ] || exit 64
[ -d "$marker_directory" ] || exit 65
[ ! -L "$marker_directory" ] || exit 65
case "$allow_marker_create" in true|false) ;; *) exit 64 ;; esac
if [ -L "$marker_path" ]; then exit 65; fi

temporary_path=''
cleanup() {
    if [ -n "$temporary_path" ]; then
        rm -f "$temporary_path"
    fi
}
trap 'cleanup' 0 HUP INT TERM
umask 077
temporary_path=$(mktemp "$marker_directory/.coolify-control-plane-writer-authority.XXXXXX") || exit 65
printf '%s' "$expected_marker" > "$temporary_path"
chmod 0600 "$temporary_path" || exit 65
[ -f "$temporary_path" ] || exit 65
[ ! -L "$temporary_path" ] || exit 65
sync -f "$temporary_path" || exit 65

if [ -e "$marker_path" ]; then
    [ -f "$marker_path" ] || exit 65
    [ ! -L "$marker_path" ] || exit 65
    cmp -s "$temporary_path" "$marker_path" || exit 65
    sync -f "$marker_path" || exit 65
    sync -f "$marker_directory" || exit 65
    exit 0
fi
[ "$allow_marker_create" = true ] || exit 65
[ -d "$marker_directory" ] || exit 65
[ ! -L "$marker_directory" ] || exit 65
if [ -e "$marker_path" ]; then
    [ -f "$marker_path" ] || exit 65
    [ ! -L "$marker_path" ] || exit 65
    cmp -s "$temporary_path" "$marker_path" || exit 65
    sync -f "$marker_path" || exit 65
    sync -f "$marker_directory" || exit 65
    exit 0
fi
mv -f "$temporary_path" "$marker_path" || exit 65
temporary_path=''
sync -f "$marker_directory" || exit 65
[ -f "$marker_path" ] || exit 65
[ ! -L "$marker_path" ] || exit 65
SH;
    }

    private function readMarkerScript(): string
    {
        return <<<'SH'
marker_path=$1
expected_marker=$2
marker_directory=${marker_path%/*}
[ -n "$marker_directory" ] || exit 64
[ -d "$marker_directory" ] || exit 65
[ ! -L "$marker_directory" ] || exit 65
[ -f "$marker_path" ] || exit 65
[ ! -L "$marker_path" ] || exit 65

readback_path=''
cleanup() {
    if [ -n "$readback_path" ]; then
        rm -f "$readback_path"
    fi
}
trap 'cleanup' 0 HUP INT TERM
umask 077
readback_path=$(mktemp "$marker_directory/.coolify-control-plane-writer-authority-readback.XXXXXX") || exit 65
printf '%s' "$expected_marker" > "$readback_path"
chmod 0600 "$readback_path" || exit 65
[ -f "$readback_path" ] || exit 65
[ ! -L "$readback_path" ] || exit 65
cmp -s "$readback_path" "$marker_path" || exit 65
cat "$marker_path"
[ -f "$marker_path" ] || exit 65
[ ! -L "$marker_path" ] || exit 65
cmp -s "$readback_path" "$marker_path" || exit 65
SH;
    }
}
