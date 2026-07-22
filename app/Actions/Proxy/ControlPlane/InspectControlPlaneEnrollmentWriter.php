<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class InspectControlPlaneEnrollmentWriter
{
    use AsAction;

    public const TRANSCRIPT_BEGIN = '__COOLIFY_ENROLLMENT_WRITER_INSPECTION_BEGIN__';

    public const TRANSCRIPT_RECORD = '__COOLIFY_ENROLLMENT_WRITER_INSPECTION_RECORD__';

    public const TRANSCRIPT_END = '__COOLIFY_ENROLLMENT_WRITER_INSPECTION_END__';

    public function handle(string $transcript, string $expectedContainerName): ControlPlaneEnrollmentWriterIdentity
    {
        return $this->identityFromTranscript($transcript, $expectedContainerName);
    }

    public function commandFor(string $expectedContainerName): string
    {
        $this->assertContainerName($expectedContainerName);
        $script = <<<'SH'
set -eu
expected_container_name=__EXPECTED_CONTAINER_NAME__

fail() { exit 1; }

inspection=$(docker inspect --type container --format '{{.Id}}|{{.Name}}|{{.Image}}|{{.State.Running}}' "$expected_container_name" 2>/dev/null) || fail
container_id=${inspection%%|*}
remainder=${inspection#*|}
[ "$remainder" != "$inspection" ] || fail
container_name=${remainder%%|*}
remainder=${remainder#*|}
[ "$remainder" != "$container_name" ] || fail
image_id=${remainder%%|*}
running=${remainder#*|}
[ "$running" != "$image_id" ] || fail
case "$container_id" in ''|*[!a-f0-9]*) fail ;; esac
[ "${#container_id}" = 64 ] || fail
[ "$container_name" = "/$expected_container_name" ] || fail
case "$image_id" in sha256:*) image_sha256=${image_id#sha256:} ;; *) fail ;; esac
case "$image_sha256" in ''|*[!a-f0-9]*) fail ;; esac
[ "${#image_sha256}" = 64 ] || fail
[ "$running" = true ] || fail
printf '%s\n' __TRANSCRIPT_BEGIN__
printf '%s %s %s %s %s\n' __TRANSCRIPT_RECORD__ "$container_id" "$container_name" "$image_id" "$running"
printf '%s' __TRANSCRIPT_END__
SH;

        return strtr($script, [
            '__EXPECTED_CONTAINER_NAME__' => escapeshellarg($expectedContainerName),
            '__TRANSCRIPT_BEGIN__' => escapeshellarg(self::TRANSCRIPT_BEGIN),
            '__TRANSCRIPT_RECORD__' => escapeshellarg(self::TRANSCRIPT_RECORD),
            '__TRANSCRIPT_END__' => escapeshellarg(self::TRANSCRIPT_END),
        ]);
    }

    private function identityFromTranscript(string $transcript, string $expectedContainerName): ControlPlaneEnrollmentWriterIdentity
    {
        $this->assertContainerName($expectedContainerName);
        $lines = preg_split('/\r\n|\n|\r/', $transcript);
        if (! is_array($lines)) {
            throw new InvalidArgumentException('The control-plane enrollment writer inspection transcript could not be parsed.');
        }
        if (($lines[array_key_last($lines)] ?? null) === '') {
            array_pop($lines);
        }
        if (count($lines) !== 3
            || $lines[0] !== self::TRANSCRIPT_BEGIN
            || $lines[2] !== self::TRANSCRIPT_END) {
            throw new InvalidArgumentException('The control-plane enrollment writer inspection transcript has invalid sentinel boundaries.');
        }

        $recordPattern = '/\A'.preg_quote(self::TRANSCRIPT_RECORD, '/').' ([a-f0-9]{64}) \/'.preg_quote($expectedContainerName, '/').' (sha256:[a-f0-9]{64}) true\z/D';
        if (preg_match($recordPattern, $lines[1], $record) !== 1) {
            throw new InvalidArgumentException('The control-plane enrollment writer inspection transcript record is invalid.');
        }

        return new ControlPlaneEnrollmentWriterIdentity(
            containerId: $record[1],
            containerName: $expectedContainerName,
            imageId: $record[2],
        );
    }

    private function assertContainerName(string $containerName): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $containerName) !== 1) {
            throw new InvalidArgumentException('The control-plane enrollment writer container name is invalid.');
        }
    }
}
