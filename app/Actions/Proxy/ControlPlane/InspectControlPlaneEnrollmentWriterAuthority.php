<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Actions\Proxy\DurableRemoteArtifact;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class InspectControlPlaneEnrollmentWriterAuthority
{
    use AsAction;

    public const TRANSCRIPT_BEGIN = '__COOLIFY_ENROLLMENT_WRITER_AUTHORITY_BEGIN__';

    public const TRANSCRIPT_ABSENT = '__COOLIFY_ENROLLMENT_WRITER_AUTHORITY_ABSENT__';

    public const TRANSCRIPT_RECORD = '__COOLIFY_ENROLLMENT_WRITER_AUTHORITY_RECORD__';

    public const TRANSCRIPT_END = '__COOLIFY_ENROLLMENT_WRITER_AUTHORITY_END__';

    public function commandFor(ManagedTraefikDocumentMutation $mutation): string
    {
        return implode("\n", [
            'set -eu',
            'umask 077',
            'state_directory='.escapeshellarg($mutation->stateDirectory),
            'authority_path='.escapeshellarg($mutation->writerAuthorityPath()),
            'lock_path='.escapeshellarg($mutation->lockPath()),
            'maximum_bytes='.escapeshellarg((string) ManagedTraefikDocumentWriterAuthority::MAXIMUM_SERIALIZED_BYTES),
            'transcript_begin='.escapeshellarg(self::TRANSCRIPT_BEGIN),
            'transcript_absent='.escapeshellarg(self::TRANSCRIPT_ABSENT),
            'transcript_record='.escapeshellarg(self::TRANSCRIPT_RECORD),
            'transcript_end='.escapeshellarg(self::TRANSCRIPT_END),
            'fail() { exit 1; }',
            ...DurableRemoteArtifact::shellFunctions(),
            'current_uid=$(id -u) || fail',
            'is_trusted_owner() {',
            '  case "$1" in 0|9999|"$current_uid") return 0 ;; *) return 1 ;; esac',
            '}',
            'has_private_permissions() {',
            '  trusted_permissions=$(durable_remote_permissions "$1") || return 1',
            '  case "$trusted_permissions" in ???|????) ;; *) return 1 ;; esac',
            '  case "$trusted_permissions" in *[!0-7]*) return 1 ;; esac',
            '  trusted_other_permissions=${trusted_permissions#"${trusted_permissions%?}"}',
            '  trusted_owner_group_permissions=${trusted_permissions%?}',
            '  trusted_group_permissions=${trusted_owner_group_permissions#"${trusted_owner_group_permissions%?}"}',
            '  case "${trusted_group_permissions}${trusted_other_permissions}" in *[2367]*) return 1 ;; esac',
            '}',
            'assert_trusted_directory() {',
            '  [ -d "$1" ] && [ ! -L "$1" ] || return 1',
            '  is_trusted_owner "$(durable_remote_owner_uid "$1")" || return 1',
            '  has_private_permissions "$1"',
            '}',
            'assert_trusted_regular() {',
            '  [ -f "$1" ] && [ ! -L "$1" ] || return 1',
            '  is_trusted_owner "$(durable_remote_owner_uid "$1")" || return 1',
            '  [ "$(durable_remote_link_count "$1")" = 1 ] || return 1',
            '  has_private_permissions "$1"',
            '}',
            'if [ ! -e "$state_directory" ] && [ ! -L "$state_directory" ]; then',
            '  printf "%s\n%s\n%s" "$transcript_begin" "$transcript_absent" "$transcript_end"',
            '  exit 0',
            'fi',
            'assert_trusted_directory "$state_directory" || fail',
            'assert_trusted_regular "$lock_path" || fail',
            'if [ -e "$authority_path" ] || [ -L "$authority_path" ]; then assert_trusted_regular "$authority_path" || fail; fi',
            'command -v flock >/dev/null 2>&1 || fail',
            'exec 9< "$lock_path" || fail',
            'flock -s 9 || fail',
            'assert_trusted_directory "$state_directory" || fail',
            'assert_trusted_regular "$lock_path" || fail',
            'printf "%s\n" "$transcript_begin"',
            'if [ ! -e "$authority_path" ] && [ ! -L "$authority_path" ]; then',
            '  printf "%s\n" "$transcript_absent"',
            'else',
            '  assert_trusted_regular "$authority_path" || fail',
            '  authority_size=$(wc -c < "$authority_path" | tr -d "[:space:]") || fail',
            '  case "$authority_size" in ""|*[!0-9]*) fail ;; esac',
            '  [ "$authority_size" -le "$maximum_bytes" ] || fail',
            '  authority_base64=$(base64 < "$authority_path" | tr -d "\n") || fail',
            '  printf "%s %s\n" "$transcript_record" "$authority_base64"',
            'fi',
            'printf %s "$transcript_end"',
        ]);
    }

    public function handle(string $transcript): ?ManagedTraefikDocumentWriterAuthority
    {
        $lines = preg_split('/\r\n|\n|\r/', $transcript);
        if (! is_array($lines)) {
            throw new InvalidArgumentException('The control-plane writer authority transcript could not be parsed.');
        }
        if (($lines[array_key_last($lines)] ?? null) === '') {
            array_pop($lines);
        }
        if (count($lines) !== 3 || $lines[0] !== self::TRANSCRIPT_BEGIN || $lines[2] !== self::TRANSCRIPT_END) {
            throw new InvalidArgumentException('The control-plane writer authority transcript has invalid sentinel boundaries.');
        }
        if ($lines[1] === self::TRANSCRIPT_ABSENT) {
            return null;
        }

        $prefix = self::TRANSCRIPT_RECORD.' ';
        if (! str_starts_with($lines[1], $prefix)) {
            throw new InvalidArgumentException('The control-plane writer authority transcript record is invalid.');
        }
        $encoded = substr($lines[1], strlen($prefix));
        if ($encoded === '' || preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/D', $encoded) !== 1) {
            throw new InvalidArgumentException('The control-plane writer authority transcript payload is invalid.');
        }
        $json = base64_decode($encoded, true);
        if (! is_string($json) || strlen($json) > ManagedTraefikDocumentWriterAuthority::MAXIMUM_SERIALIZED_BYTES) {
            throw new InvalidArgumentException('The control-plane writer authority transcript payload is invalid.');
        }
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_keys($data) !== [
            'epoch',
            'operation_id',
            'member',
            'container_id',
            'container_name',
            'image_id',
            'dynamic_revision',
            'dynamic_sha256',
        ]) {
            throw new InvalidArgumentException('The control-plane writer authority payload has an invalid shape.');
        }

        $authority = new ManagedTraefikDocumentWriterAuthority(
            epoch: $data['epoch'],
            operationId: $data['operation_id'],
            member: $data['member'],
            containerId: $data['container_id'],
            containerName: $data['container_name'],
            imageId: $data['image_id'],
            dynamicRevision: $data['dynamic_revision'],
            dynamicSha256: $data['dynamic_sha256'],
        );
        if (! hash_equals($authority->toJson(), $json)) {
            throw new InvalidArgumentException('The control-plane writer authority payload is not canonical.');
        }

        return $authority;
    }
}
