<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Actions\Proxy\DurableRemoteArtifact;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class BootstrapControlPlaneEnrollmentWriterAuthority
{
    use AsAction;

    public const APPLIED_OUTPUT = 'coolify-control-plane-enrollment-writer-authority:bootstrapped';

    private const AUTHORITY_GROUP = '9999';

    private const AUTHORITY_MODE = '0640';

    public function authorityFor(
        ControlPlaneProxyEnrollmentState $state,
        ControlPlaneEnrollmentWriterIdentity $identity,
    ): ManagedTraefikDocumentWriterAuthority {
        return new ManagedTraefikDocumentWriterAuthority(
            epoch: 1,
            operationId: $state->operationId,
            member: $state->expectedMember,
            containerId: $identity->containerId,
            containerName: $identity->containerName,
            imageId: $identity->imageId,
            dynamicRevision: $state->dynamicRevision,
            dynamicSha256: hash('sha256', $state->dynamicReplacementBytes),
        );
    }

    public function rolledBackAuthorityFor(
        ControlPlaneProxyEnrollmentState $state,
        ControlPlaneEnrollmentWriterIdentity $identity,
    ): ManagedTraefikDocumentWriterAuthority {
        return new ManagedTraefikDocumentWriterAuthority(
            epoch: 2,
            operationId: $state->operationId,
            member: $state->expectedMember,
            containerId: $identity->containerId,
            containerName: $identity->containerName,
            imageId: $identity->imageId,
            dynamicRevision: $state->dynamicRevision,
            dynamicSha256: hash('sha256', $state->dynamicPredecessorBytes ?? ''),
        );
    }

    public function handle(
        ManagedTraefikDocumentMutation $mutation,
        ManagedTraefikDocumentWriterAuthority $authority,
    ): string {
        return $this->commandFor($mutation, $authority);
    }

    public function commandFor(
        ManagedTraefikDocumentMutation $mutation,
        ManagedTraefikDocumentWriterAuthority $authority,
    ): string {
        $this->assertBootstrapContext($mutation, $authority);

        $script = <<<'SH'
set -eu
umask 077
state_directory=__STATE_DIRECTORY__
document_path=__DOCUMENT_PATH__
sidecar_path=__SIDECAR_PATH__
lock_path=__LOCK_PATH__
authority_path=__AUTHORITY_PATH__
expected_docker_id=__EXPECTED_DOCKER_ID__
expected_container_name=__EXPECTED_CONTAINER_NAME__
expected_image_id=__EXPECTED_IMAGE_ID__
expected_document_base64=__EXPECTED_DOCUMENT_BASE64__
expected_sidecar_base64=__EXPECTED_SIDECAR_BASE64__
expected_authority_base64=__EXPECTED_AUTHORITY_BASE64__
authority_group=__AUTHORITY_GROUP__
authority_mode=__AUTHORITY_MODE__
authority_maximum_bytes=__AUTHORITY_MAXIMUM_BYTES__
applied_output=__APPLIED_OUTPUT__

fail() { exit 1; }
SH;

        return implode("\n", [
            strtr($script, [
                '__STATE_DIRECTORY__' => escapeshellarg($mutation->stateDirectory),
                '__DOCUMENT_PATH__' => escapeshellarg($mutation->documentPath()),
                '__SIDECAR_PATH__' => escapeshellarg($mutation->sidecarPath()),
                '__LOCK_PATH__' => escapeshellarg($mutation->lockPath()),
                '__AUTHORITY_PATH__' => escapeshellarg($mutation->writerAuthorityPath()),
                '__EXPECTED_DOCKER_ID__' => escapeshellarg($authority->containerId),
                '__EXPECTED_CONTAINER_NAME__' => escapeshellarg($authority->containerName),
                '__EXPECTED_IMAGE_ID__' => escapeshellarg($authority->imageId),
                '__EXPECTED_DOCUMENT_BASE64__' => escapeshellarg(base64_encode($mutation->replacementBytes)),
                '__EXPECTED_SIDECAR_BASE64__' => escapeshellarg(base64_encode($mutation->replacementSidecar())),
                '__EXPECTED_AUTHORITY_BASE64__' => escapeshellarg(base64_encode($authority->toJson())),
                '__AUTHORITY_GROUP__' => escapeshellarg(self::AUTHORITY_GROUP),
                '__AUTHORITY_MODE__' => escapeshellarg(self::AUTHORITY_MODE),
                '__AUTHORITY_MAXIMUM_BYTES__' => escapeshellarg((string) ManagedTraefikDocumentWriterAuthority::MAXIMUM_SERIALIZED_BYTES),
                '__APPLIED_OUTPUT__' => escapeshellarg(self::APPLIED_OUTPUT),
            ]),
            ...DurableRemoteArtifact::shellFunctions(),
            'assert_directory() { durable_remote_assert_owned_directory "$1" || fail; }',
            'assert_regular_or_absent() {',
            '  if [ -e "$1" ] || [ -L "$1" ]; then durable_remote_assert_owned_regular "$1" || fail; fi',
            '}',
            'authority_owner_group_mode() {',
            '  if stat -c "%u:%g:%a" "$1" >/dev/null 2>&1; then stat -c "%u:%g:%a" "$1"; else stat -f "%u:%g:%Lp" "$1"; fi',
            '}',
            'authority_metadata_matches() {',
            '  authority_metadata=$(authority_owner_group_mode "$1") || return 1',
            '  [ "$authority_metadata" = "0:$authority_group:${authority_mode#0}" ]',
            '}',
            'authority_matches() {',
            '  authority_candidate=$1',
            '  authority_expected=$2',
            '  [ -f "$authority_candidate" ] && [ ! -L "$authority_candidate" ] || return 1',
            '  durable_remote_assert_owned_regular "$authority_candidate" || return 1',
            '  authority_size=$(wc -c < "$authority_candidate" | tr -d "[:space:]") || return 1',
            '  case "$authority_size" in ""|*[!0-9]*) return 1 ;; esac',
            '  [ "$authority_size" -le "$authority_maximum_bytes" ] || return 1',
            '  authority_metadata_matches "$authority_candidate" || return 1',
            '  cmp -s "$authority_candidate" "$authority_expected"',
            '}',
            'assert_exact_runtime() {',
            '  runtime_inspection=$(docker inspect --type container --format "{{.Id}}|{{.Name}}|{{.Image}}|{{.State.Running}}" "$expected_docker_id" 2>/dev/null) || fail',
            '  [ "$runtime_inspection" = "$expected_docker_id|/$expected_container_name|$expected_image_id|true" ] || fail',
            '}',
            'assert_exact_document() {',
            '  durable_remote_assert_owned_regular "$document_path" || fail',
            '  durable_remote_assert_owned_regular "$sidecar_path" || fail',
            '  cmp -s "$document_path" "$expected_document_file" || fail',
            '  cmp -s "$sidecar_path" "$expected_sidecar_file" || fail',
            '}',
            'create_authority_if_absent() {',
            '  authority_stage=$(mktemp "$state_directory/.control-plane-enrollment-writer-authority.XXXXXX") || fail',
            '  printf %s "$expected_authority_base64" | base64 -d > "$authority_stage" || fail',
            '  chown root:"$authority_group" "$authority_stage" || fail',
            '  chmod "$authority_mode" "$authority_stage" || fail',
            '  durable_remote_assert_owned_regular "$authority_stage" || fail',
            '  authority_metadata_matches "$authority_stage" || fail',
            '  sync "$authority_stage" || fail',
            '  mv -n -- "$authority_stage" "$authority_path" || fail',
            '  if [ ! -e "$authority_stage" ] && [ ! -L "$authority_stage" ]; then',
            '    authority_stage=',
            '    durable_remote_reaffirm "$authority_path" "$state_directory" || fail',
            '    return',
            '  fi',
            '  authority_matches "$authority_path" "$expected_authority_file" || fail',
            '  rm -f -- "$authority_stage" || fail',
            '  authority_stage=',
            '}',
            '',
            'assert_directory "$state_directory"',
            'assert_regular_or_absent "$document_path"',
            'assert_regular_or_absent "$sidecar_path"',
            'assert_regular_or_absent "$authority_path"',
            'assert_regular_or_absent "$lock_path"',
            'command -v flock >/dev/null 2>&1 || fail',
            'exec 9> "$lock_path" || fail',
            'durable_remote_assert_owned_regular "$lock_path" || fail',
            'flock -x 9 || fail',
            'assert_regular_or_absent "$document_path"',
            'assert_regular_or_absent "$sidecar_path"',
            'assert_regular_or_absent "$authority_path"',
            'scratch=$(mktemp -d "$state_directory/.control-plane-enrollment-writer-authority-scratch.XXXXXX") || fail',
            'expected_document_file="$scratch/document"',
            'expected_sidecar_file="$scratch/sidecar"',
            'expected_authority_file="$scratch/authority"',
            'authority_stage=',
            'cleanup() {',
            '  if [ -n "$authority_stage" ]; then rm -f -- "$authority_stage"; fi',
            '  rm -f -- "$expected_document_file" "$expected_sidecar_file" "$expected_authority_file"',
            '  rmdir "$scratch" 2>/dev/null || true',
            '}',
            'trap cleanup 0 HUP INT TERM',
            'printf %s "$expected_document_base64" | base64 -d > "$expected_document_file" || fail',
            'printf %s "$expected_sidecar_base64" | base64 -d > "$expected_sidecar_file" || fail',
            'printf %s "$expected_authority_base64" | base64 -d > "$expected_authority_file" || fail',
            'expected_authority_size=$(wc -c < "$expected_authority_file" | tr -d "[:space:]") || fail',
            'case "$expected_authority_size" in ""|*[!0-9]*) fail ;; esac',
            '[ "$expected_authority_size" -le "$authority_maximum_bytes" ] || fail',
            'assert_exact_runtime',
            'assert_exact_document',
            'if [ -e "$authority_path" ] || [ -L "$authority_path" ]; then',
            '  authority_matches "$authority_path" "$expected_authority_file" || fail',
            'else',
            '  create_authority_if_absent',
            'fi',
            'authority_matches "$authority_path" "$expected_authority_file" || fail',
            'assert_exact_runtime',
            'assert_exact_document',
            'durable_remote_reaffirm "$authority_path" "$state_directory" || fail',
            'printf %s "$applied_output"',
        ]);
    }

    private function assertBootstrapContext(
        ManagedTraefikDocumentMutation $mutation,
        ManagedTraefikDocumentWriterAuthority $authority,
    ): void {
        if ($authority->epoch !== 1 || ! $authority->matchesReplacement($mutation)) {
            throw new InvalidArgumentException('The control-plane enrollment writer authority must be the exact epoch-one replacement authority.');
        }
    }
}
