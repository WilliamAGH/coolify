<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class ManagedTraefikDocumentWriter
{
    use AsAction;

    public const APPLIED_OUTPUT = 'coolify-managed-traefik-document:applied';

    public const ROLLED_BACK_OUTPUT = 'coolify-managed-traefik-document:rolled-back';

    public const WRITER_AUTHORITY_PROMOTED_OUTPUT = 'coolify-managed-traefik-writer-authority:promoted';

    private const ARTIFACT_MAGIC = 'coolify-managed-traefik-document-rollback-v1';

    private const JOURNAL_MAGIC = 'coolify-managed-traefik-document-journal-v1';

    public function writeCommandFor(ManagedTraefikDocumentMutation $mutation): string
    {
        return $this->commandFor($mutation, false);
    }

    public function rollbackCommandFor(
        ManagedTraefikDocumentMutation $mutation,
        bool $allowMissingArtifactNoop = false,
    ): string {
        return $this->commandFor(
            mutation: $mutation,
            rollback: true,
            allowMissingArtifactNoop: $allowMissingArtifactNoop,
        );
    }

    public function writeCommandForRequiringAuthority(
        ManagedTraefikDocumentMutation $mutation,
        ManagedTraefikDocumentWriterAuthority $activeAuthority,
        bool $allowBootstrap,
    ): string {
        $this->assertDocumentWriteAuthority($mutation, $activeAuthority, $allowBootstrap);

        return $this->commandFor(
            mutation: $mutation,
            rollback: false,
            requiredAuthority: $activeAuthority,
            allowAuthorityBootstrap: $allowBootstrap,
        );
    }

    public function promoteWriterAuthorityCommandFor(
        string $stateDirectory,
        string $filename,
        ManagedTraefikDocumentWriterAuthority $expectedAuthority,
        ManagedTraefikDocumentWriterAuthority $nextAuthority,
    ): string {
        $this->assertWriterAuthorityContext($stateDirectory, $filename);
        $this->assertAuthorityPromotion($expectedAuthority, $nextAuthority);

        return $this->authorityPromotionCommandFor(
            stateDirectory: $stateDirectory,
            filename: $filename,
            expectedAuthority: $expectedAuthority,
            nextAuthority: $nextAuthority,
        );
    }

    public function rollbackCommandForRequiringPredecessorAuthority(
        ManagedTraefikDocumentMutation $mutation,
        ManagedTraefikDocumentWriterAuthority $predecessorAuthority,
        bool $allowInitialOrPreWriteReconciliation = false,
        bool $allowMissingArtifactNoop = false,
    ): string {
        $this->assertRollbackAuthority($mutation, $predecessorAuthority);

        return $this->commandFor(
            mutation: $mutation,
            rollback: true,
            requiredAuthority: $predecessorAuthority,
            allowMissingArtifactNoop: $allowMissingArtifactNoop,
            allowAuthorityAbsence: $allowInitialOrPreWriteReconciliation,
        );
    }

    private function commandFor(
        ManagedTraefikDocumentMutation $mutation,
        bool $rollback,
        ?ManagedTraefikDocumentWriterAuthority $requiredAuthority = null,
        bool $allowMissingArtifactNoop = false,
        bool $allowAuthorityBootstrap = false,
        bool $allowAuthorityAbsence = false,
    ): string {
        $expectedSidecar = $mutation->expectedSidecar();
        $authorityMode = $requiredAuthority === null ? 'none' : 'require';
        $requiredAuthority = $requiredAuthority?->toJson();

        return implode("\n", [
            'set -eu',
            'umask 077',
            'dynamic_directory='.escapeshellarg($mutation->dynamicDirectory),
            'state_directory='.escapeshellarg($mutation->stateDirectory),
            'filename='.escapeshellarg($mutation->filename),
            'operation_id='.escapeshellarg($mutation->operationId),
            'revision='.escapeshellarg((string) $mutation->revision),
            'mode='.escapeshellarg($rollback ? 'rollback' : 'write'),
            'document_path='.escapeshellarg($mutation->documentPath()),
            'sidecar_path='.escapeshellarg($mutation->sidecarPath()),
            'lock_path='.escapeshellarg($mutation->lockPath()),
            'journal_path='.escapeshellarg($mutation->journalPath()),
            'artifact_path='.escapeshellarg($mutation->rollbackArtifactPath()),
            'authority_path='.escapeshellarg($mutation->writerAuthorityPath()),
            'authority_mode='.escapeshellarg($authorityMode),
            'authority_required_base64='.escapeshellarg($requiredAuthority === null ? 'absent' : base64_encode($requiredAuthority)),
            'authority_maximum_bytes='.escapeshellarg((string) ManagedTraefikDocumentWriterAuthority::MAXIMUM_SERIALIZED_BYTES),
            'allow_authority_bootstrap='.escapeshellarg($allowAuthorityBootstrap ? 'true' : 'false'),
            'allow_authority_absence='.escapeshellarg($allowAuthorityAbsence ? 'true' : 'false'),
            'allow_missing_artifact_noop='.escapeshellarg($allowMissingArtifactNoop ? 'true' : 'false'),
            'original_expected_document_sha='.escapeshellarg($mutation->expectedSha256 ?? 'absent'),
            'original_replacement_document_sha='.escapeshellarg($mutation->replacementSha256()),
            'original_expected_sidecar_base64='.escapeshellarg($expectedSidecar === null ? 'absent' : base64_encode($expectedSidecar)),
            'original_replacement_sidecar_base64='.escapeshellarg(base64_encode($mutation->replacementSidecar())),
            'original_replacement_payload_base64='.escapeshellarg(base64_encode($mutation->replacementBytes)),
            'artifact_magic='.escapeshellarg(self::ARTIFACT_MAGIC),
            'journal_magic='.escapeshellarg(self::JOURNAL_MAGIC),
            'applied_output='.escapeshellarg(self::APPLIED_OUTPUT),
            'rolled_back_output='.escapeshellarg(self::ROLLED_BACK_OUTPUT),
            '',
            'fail() { exit 1; }',
            'assert_directory() { test -d "$1" && test ! -L "$1" || fail; }',
            'assert_regular_or_absent() {',
            '  if [ -e "$1" ] || [ -L "$1" ]; then',
            '    test ! -L "$1" && test -f "$1" || fail',
            '  fi',
            '}',
            'document_matches() {',
            '  document_candidate=$1',
            '  expected_checksum=$2',
            '  if [ "$expected_checksum" = absent ]; then',
            '    test ! -e "$document_candidate" && test ! -L "$document_candidate"',
            '    return',
            '  fi',
            '  test -e "$document_candidate" && test ! -L "$document_candidate" && test -f "$document_candidate" || return 1',
            '  document_checksum=$(sha256sum "$document_candidate")',
            '  test "${document_checksum%% *}" = "$expected_checksum"',
            '}',
            'sidecar_matches() {',
            '  sidecar_candidate=$1',
            '  expected_sidecar_base64=$2',
            '  expected_sidecar_file=$3',
            '  if [ "$expected_sidecar_base64" = absent ]; then',
            '    test ! -e "$sidecar_candidate" && test ! -L "$sidecar_candidate"',
            '    return',
            '  fi',
            '  test -e "$sidecar_candidate" && test ! -L "$sidecar_candidate" && test -f "$sidecar_candidate" || return 1',
            '  cmp -s "$sidecar_candidate" "$expected_sidecar_file"',
            '}',
            'authority_matches() {',
            '  authority_candidate=$1',
            '  authority_expected=$2',
            '  test -e "$authority_candidate" && test ! -L "$authority_candidate" && test -f "$authority_candidate" || return 1',
            '  authority_size=$(wc -c < "$authority_candidate" | tr -d "[:space:]") || return 1',
            '  case "$authority_size" in ""|*[!0-9]*) return 1 ;; esac',
            '  test "$authority_size" -le "$authority_maximum_bytes" || return 1',
            '  cmp -s "$authority_candidate" "$authority_expected"',
            '}',
            'authorize_writer() {',
            '  case "$authority_mode" in',
            '    none)',
            '      test ! -e "$authority_path" && test ! -L "$authority_path" || fail',
            '      ;;',
            '    require)',
            '      if [ -e "$authority_path" ] || [ -L "$authority_path" ]; then',
            '        authority_matches "$authority_path" "$authority_required_file" || fail',
            '        return',
            '      fi',
            '      if [ "$allow_authority_bootstrap" = true ]; then',
            '        atomic_replace "$authority_path" "$authority_required_file" "$state_directory"',
            '        authority_matches "$authority_path" "$authority_required_file" || fail',
            '        return',
            '      fi',
            '      test "$allow_authority_absence" = true || fail',
            '      ;;',
            '    *)',
            '      fail',
            '      ;;',
            '  esac',
            '}',
            'crash_after_authority_if_requested() {',
            '  if [ "$mode" = write ] && [ "${COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_AUTHORITY:-}" = 1 ]; then exit 75; fi',
            '}',
            'atomic_replace() {',
            '  replacement_target=$1',
            '  replacement_source=$2',
            '  replacement_directory=$3',
            '  replacement_stage=$(mktemp "$replacement_directory/.managed-traefik-document.XXXXXX") || fail',
            '  cp "$replacement_source" "$replacement_stage" || fail',
            '  chmod 600 "$replacement_stage" || fail',
            '  sync -f "$replacement_stage" || fail',
            '  mv -f "$replacement_stage" "$replacement_target" || fail',
            '  sync -f "$replacement_directory" || fail',
            '}',
            'atomic_remove() {',
            '  removal_target=$1',
            '  removal_directory=$2',
            '  assert_regular_or_absent "$removal_target"',
            '  rm -f "$removal_target" || fail',
            '  sync -f "$removal_directory" || fail',
            '}',
            'write_record() {',
            '  record_target=$1',
            '  record_mode=$2',
            '  record_expected_document_sha=$3',
            '  record_replacement_document_sha=$4',
            '  record_expected_sidecar_base64=$5',
            '  record_replacement_sidecar_base64=$6',
            '  record_replacement_payload_base64=$7',
            '  {',
            '    printf "%s\\n" "$journal_magic"',
            '    printf "%s\\n" "$record_mode"',
            '    printf "%s\\n" "$filename"',
            '    printf "%s\\n" "$operation_id"',
            '    printf "%s\\n" "$revision"',
            '    printf "%s\\n" "$record_expected_document_sha"',
            '    printf "%s\\n" "$record_replacement_document_sha"',
            '    printf "%s\\n" "$record_expected_sidecar_base64"',
            '    printf "%s\\n" "$record_replacement_sidecar_base64"',
            '    printf "%s\\n" "$record_replacement_payload_base64"',
            '  } > "$record_target" || fail',
            '}',
            'write_journal() {',
            '  if [ -e "$journal_path" ] || [ -L "$journal_path" ]; then',
            '    assert_regular_or_absent "$journal_path"',
            '    cmp -s "$journal_path" "$expected_journal" || fail',
            '    return',
            '  fi',
            '  journal_stage=$(mktemp "$state_directory/.managed-traefik-journal.XXXXXX") || fail',
            '  cp "$expected_journal" "$journal_stage" || fail',
            '  chmod 600 "$journal_stage" || fail',
            '  sync -f "$journal_stage" || fail',
            '  mv -f "$journal_stage" "$journal_path" || fail',
            '  sync -f "$state_directory" || fail',
            '}',
            'validate_artifact() {',
            '  assert_regular_or_absent "$artifact_path"',
            '  test -e "$artifact_path" || fail',
            '  exec 8< "$artifact_path" || fail',
            '  IFS= read -r artifact_line_1 <&8 || fail',
            '  IFS= read -r artifact_line_2 <&8 || fail',
            '  IFS= read -r artifact_line_3 <&8 || fail',
            '  IFS= read -r artifact_line_4 <&8 || fail',
            '  IFS= read -r artifact_line_5 <&8 || fail',
            '  IFS= read -r artifact_line_6 <&8 || fail',
            '  IFS= read -r artifact_line_7 <&8 || fail',
            '  IFS= read -r artifact_line_8 <&8 || fail',
            '  IFS= read -r artifact_extra <&8 && { : "$artifact_extra"; fail; }',
            '  exec 8<&- || fail',
            '  test "$artifact_line_1" = "$artifact_magic" || fail',
            '  test "$artifact_line_2" = "$filename" || fail',
            '  test "$artifact_line_3" = "$operation_id" || fail',
            '  test "$artifact_line_4" = "$revision" || fail',
            '  test "$artifact_line_5" = "$original_expected_document_sha" || fail',
            '  test "$artifact_line_6" = "$original_replacement_document_sha" || fail',
            '  test "$artifact_line_7" = "$original_expected_sidecar_base64" || fail',
            '  artifact_predecessor_payload_base64=$artifact_line_8',
            '  if [ "$original_expected_document_sha" = absent ]; then',
            '    test "$artifact_predecessor_payload_base64" = absent || fail',
            '  else',
            '    test "$artifact_predecessor_payload_base64" != absent || fail',
            '    printf %s "$artifact_predecessor_payload_base64" | base64 -d > "$scratch/predecessor-document" || fail',
            '    artifact_checksum=$(sha256sum "$scratch/predecessor-document")',
            '    test "${artifact_checksum%% *}" = "$original_expected_document_sha" || fail',
            '  fi',
            '}',
            'create_artifact_if_missing() {',
            '  if [ -e "$artifact_path" ] || [ -L "$artifact_path" ]; then',
            '    validate_artifact',
            '    return',
            '  fi',
            '  artifact_stage=$(mktemp "$state_directory/.managed-traefik-artifact.XXXXXX") || fail',
            '  {',
            '    printf "%s\\n" "$artifact_magic"',
            '    printf "%s\\n" "$filename"',
            '    printf "%s\\n" "$operation_id"',
            '    printf "%s\\n" "$revision"',
            '    printf "%s\\n" "$original_expected_document_sha"',
            '    printf "%s\\n" "$original_replacement_document_sha"',
            '    printf "%s\\n" "$original_expected_sidecar_base64"',
            '    if [ "$original_expected_document_sha" = absent ]; then',
            '      printf "%s\\n" absent',
            '    else',
            '      base64 < "$document_path" | tr -d "\\n"',
            '      printf "\\n"',
            '    fi',
            '  } > "$artifact_stage" || fail',
            '  chmod 600 "$artifact_stage" || fail',
            '  sync -f "$artifact_stage" || fail',
            '  mv -f "$artifact_stage" "$artifact_path" || fail',
            '  sync -f "$state_directory" || fail',
            '  validate_artifact',
            '}',
            'apply_journal() {',
            '  if document_matches "$document_path" "$expected_document_sha" && sidecar_matches "$sidecar_path" "$expected_sidecar_base64" "$expected_sidecar_file"; then',
            '    if [ "$replacement_document_sha" = absent ]; then',
            '      atomic_remove "$document_path" "$dynamic_directory"',
            '    else',
            '      atomic_replace "$document_path" "$replacement_payload_file" "$dynamic_directory"',
            '    fi',
            '    if [ "${COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_DOCUMENT:-}" = 1 ]; then exit 75; fi',
            '    if [ "$replacement_sidecar_base64" = absent ]; then',
            '      atomic_remove "$sidecar_path" "$state_directory"',
            '    else',
            '      atomic_replace "$sidecar_path" "$replacement_sidecar_file" "$state_directory"',
            '    fi',
            '  elif document_matches "$document_path" "$replacement_document_sha" && sidecar_matches "$sidecar_path" "$expected_sidecar_base64" "$expected_sidecar_file"; then',
            '    if [ "$replacement_sidecar_base64" = absent ]; then',
            '      atomic_remove "$sidecar_path" "$state_directory"',
            '    else',
            '      atomic_replace "$sidecar_path" "$replacement_sidecar_file" "$state_directory"',
            '    fi',
            '  elif document_matches "$document_path" "$replacement_document_sha" && sidecar_matches "$sidecar_path" "$replacement_sidecar_base64" "$replacement_sidecar_file"; then',
            '    :',
            '  else',
            '    fail',
            '  fi',
            '  document_matches "$document_path" "$replacement_document_sha" || fail',
            '  sidecar_matches "$sidecar_path" "$replacement_sidecar_base64" "$replacement_sidecar_file" || fail',
            '  atomic_remove "$journal_path" "$state_directory"',
            '}',
            '',
            'mkdir -p "$dynamic_directory" "$state_directory" || fail',
            'assert_directory "$dynamic_directory"',
            'assert_directory "$state_directory"',
            'assert_regular_or_absent "$document_path"',
            'assert_regular_or_absent "$sidecar_path"',
            'assert_regular_or_absent "$journal_path"',
            'assert_regular_or_absent "$artifact_path"',
            'assert_regular_or_absent "$authority_path"',
            'assert_regular_or_absent "$lock_path"',
            'command -v flock >/dev/null 2>&1 || fail',
            'exec 9> "$lock_path" || fail',
            'flock -x 9 || fail',
            'assert_regular_or_absent "$document_path"',
            'assert_regular_or_absent "$sidecar_path"',
            'assert_regular_or_absent "$journal_path"',
            'assert_regular_or_absent "$artifact_path"',
            'assert_regular_or_absent "$authority_path"',
            'scratch=$(mktemp -d "$state_directory/.managed-traefik-document.XXXXXX") || fail',
            'expected_sidecar_file="$scratch/expected-sidecar"',
            'replacement_sidecar_file="$scratch/replacement-sidecar"',
            'replacement_payload_file="$scratch/replacement-payload"',
            'expected_journal="$scratch/expected-journal"',
            'authority_required_file="$scratch/authority-required"',
            'cleanup() { rm -f "$expected_sidecar_file" "$replacement_sidecar_file" "$replacement_payload_file" "$expected_journal" "$authority_required_file" "$scratch/predecessor-document"; rmdir "$scratch" 2>/dev/null || true; }',
            'trap cleanup 0 HUP INT TERM',
            'expected_document_sha=$original_expected_document_sha',
            'replacement_document_sha=$original_replacement_document_sha',
            'expected_sidecar_base64=$original_expected_sidecar_base64',
            'replacement_sidecar_base64=$original_replacement_sidecar_base64',
            'replacement_payload_base64=$original_replacement_payload_base64',
            'rollback_without_artifact=false',
            'if [ "$mode" = rollback ]; then',
            '  if [ -e "$artifact_path" ] || [ -L "$artifact_path" ]; then',
            '    validate_artifact',
            '    expected_document_sha=$original_replacement_document_sha',
            '    replacement_document_sha=$original_expected_document_sha',
            '    expected_sidecar_base64=$original_replacement_sidecar_base64',
            '    replacement_sidecar_base64=$original_expected_sidecar_base64',
            '    replacement_payload_base64=$artifact_predecessor_payload_base64',
            '  else',
            '    test "$allow_missing_artifact_noop" = true || fail',
            '    rollback_without_artifact=true',
            '    replacement_document_sha=$original_expected_document_sha',
            '    replacement_sidecar_base64=$original_expected_sidecar_base64',
            '    replacement_payload_base64=absent',
            '  fi',
            'fi',
            'if [ "$expected_sidecar_base64" = absent ]; then : > "$expected_sidecar_file"; else printf %s "$expected_sidecar_base64" | base64 -d > "$expected_sidecar_file" || fail; fi',
            'if [ "$replacement_sidecar_base64" = absent ]; then : > "$replacement_sidecar_file"; else printf %s "$replacement_sidecar_base64" | base64 -d > "$replacement_sidecar_file" || fail; fi',
            'if [ "$replacement_payload_base64" = absent ]; then : > "$replacement_payload_file"; else printf %s "$replacement_payload_base64" | base64 -d > "$replacement_payload_file" || fail; fi',
            'case "$allow_missing_artifact_noop" in true|false) ;; *) fail ;; esac',
            'if [ "$authority_required_base64" = absent ]; then : > "$authority_required_file"; else printf %s "$authority_required_base64" | base64 -d > "$authority_required_file" || fail; fi',
            'case "$allow_authority_bootstrap" in true|false) ;; *) fail ;; esac',
            'case "$allow_authority_absence" in true|false) ;; *) fail ;; esac',
            'case "$authority_mode" in',
            '  none)',
            '    test "$authority_required_base64" = absent && test "$allow_authority_bootstrap" = false && test "$allow_authority_absence" = false || fail',
            '    ;;',
            '  require)',
            '    test "$authority_required_base64" != absent || fail',
            '    if [ "$allow_authority_bootstrap" = true ] && [ "$allow_authority_absence" = true ]; then fail; fi',
            '    ;;',
            '  *) fail ;;',
            'esac',
            'if [ "$rollback_without_artifact" = true ]; then',
            '  authorize_writer',
            '  test ! -e "$journal_path" && test ! -L "$journal_path" || fail',
            '  document_matches "$document_path" "$original_expected_document_sha" || fail',
            '  sidecar_matches "$sidecar_path" "$original_expected_sidecar_base64" "$expected_sidecar_file" || fail',
            '  printf %s "$rolled_back_output"',
            '  exit 0',
            'fi',
            'if [ "$replacement_document_sha" != absent ]; then',
            '  replacement_checksum=$(sha256sum "$replacement_payload_file")',
            '  test "${replacement_checksum%% *}" = "$replacement_document_sha" || fail',
            'fi',
            'write_record "$expected_journal" "$mode" "$expected_document_sha" "$replacement_document_sha" "$expected_sidecar_base64" "$replacement_sidecar_base64" "$replacement_payload_base64"',
            'if [ -e "$journal_path" ] || [ -L "$journal_path" ]; then',
            '  assert_regular_or_absent "$journal_path"',
            '  cmp -s "$journal_path" "$expected_journal" || fail',
            '  if [ "$mode" = write ]; then validate_artifact; fi',
            '  authorize_writer',
            '  crash_after_authority_if_requested',
            '  apply_journal',
            'elif document_matches "$document_path" "$replacement_document_sha" && sidecar_matches "$sidecar_path" "$replacement_sidecar_base64" "$replacement_sidecar_file"; then',
            '  if [ "$mode" = write ]; then validate_artifact; fi',
            '  authorize_writer',
            '  crash_after_authority_if_requested',
            'elif document_matches "$document_path" "$expected_document_sha" && sidecar_matches "$sidecar_path" "$expected_sidecar_base64" "$expected_sidecar_file"; then',
            '  if [ "$mode" = write ] && { [ -e "$artifact_path" ] || [ -L "$artifact_path" ]; }; then validate_artifact; fi',
            '  authorize_writer',
            '  crash_after_authority_if_requested',
            '  if [ "$mode" = write ]; then create_artifact_if_missing; fi',
            '  write_journal',
            '  apply_journal',
            'else',
            '  fail',
            'fi',
            'if [ "$mode" = rollback ]; then printf %s "$rolled_back_output"; else printf %s "$applied_output"; fi',
        ]);
    }

    private function authorityPromotionCommandFor(
        string $stateDirectory,
        string $filename,
        ManagedTraefikDocumentWriterAuthority $expectedAuthority,
        ManagedTraefikDocumentWriterAuthority $nextAuthority,
    ): string {
        $lockPath = rtrim($stateDirectory, '/').'/.'.$filename.'.lock';
        $authorityPath = rtrim($stateDirectory, '/').'/.'.$filename.'.writer-authority.json';

        return implode("\n", [
            'set -eu',
            'umask 077',
            'state_directory='.escapeshellarg($stateDirectory),
            'lock_path='.escapeshellarg($lockPath),
            'authority_path='.escapeshellarg($authorityPath),
            'expected_authority_base64='.escapeshellarg(base64_encode($expectedAuthority->toJson())),
            'next_authority_base64='.escapeshellarg(base64_encode($nextAuthority->toJson())),
            'authority_maximum_bytes='.escapeshellarg((string) ManagedTraefikDocumentWriterAuthority::MAXIMUM_SERIALIZED_BYTES),
            'promoted_output='.escapeshellarg(self::WRITER_AUTHORITY_PROMOTED_OUTPUT),
            '',
            'fail() { exit 1; }',
            'assert_directory() { test -d "$1" && test ! -L "$1" || fail; }',
            'assert_regular_or_absent() {',
            '  if [ -e "$1" ] || [ -L "$1" ]; then',
            '    test ! -L "$1" && test -f "$1" || fail',
            '  fi',
            '}',
            'authority_matches() {',
            '  authority_candidate=$1',
            '  authority_expected=$2',
            '  test -e "$authority_candidate" && test ! -L "$authority_candidate" && test -f "$authority_candidate" || return 1',
            '  authority_size=$(wc -c < "$authority_candidate" | tr -d "[:space:]") || return 1',
            '  case "$authority_size" in ""|*[!0-9]*) return 1 ;; esac',
            '  test "$authority_size" -le "$authority_maximum_bytes" || return 1',
            '  cmp -s "$authority_candidate" "$authority_expected"',
            '}',
            'atomic_replace() {',
            '  replacement_target=$1',
            '  replacement_source=$2',
            '  replacement_stage=$(mktemp "$state_directory/.managed-traefik-authority.XXXXXX") || fail',
            '  cp "$replacement_source" "$replacement_stage" || fail',
            '  chmod 600 "$replacement_stage" || fail',
            '  sync -f "$replacement_stage" || fail',
            '  mv -f "$replacement_stage" "$replacement_target" || fail',
            '  sync -f "$state_directory" || fail',
            '}',
            '',
            'mkdir -p "$state_directory" || fail',
            'assert_directory "$state_directory"',
            'assert_regular_or_absent "$authority_path"',
            'assert_regular_or_absent "$lock_path"',
            'command -v flock >/dev/null 2>&1 || fail',
            'exec 9> "$lock_path" || fail',
            'flock -x 9 || fail',
            'assert_regular_or_absent "$authority_path"',
            'scratch=$(mktemp -d "$state_directory/.managed-traefik-authority.XXXXXX") || fail',
            'expected_authority_file="$scratch/expected-authority"',
            'next_authority_file="$scratch/next-authority"',
            'cleanup() { rm -f "$expected_authority_file" "$next_authority_file"; rmdir "$scratch" 2>/dev/null || true; }',
            'trap cleanup 0 HUP INT TERM',
            'printf %s "$expected_authority_base64" | base64 -d > "$expected_authority_file" || fail',
            'printf %s "$next_authority_base64" | base64 -d > "$next_authority_file" || fail',
            'if authority_matches "$authority_path" "$next_authority_file"; then',
            '  printf %s "$promoted_output"',
            '  exit 0',
            'fi',
            'authority_matches "$authority_path" "$expected_authority_file" || fail',
            'atomic_replace "$authority_path" "$next_authority_file"',
            'authority_matches "$authority_path" "$next_authority_file" || fail',
            'if [ "${COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_AUTHORITY:-}" = 1 ]; then exit 75; fi',
            'printf %s "$promoted_output"',
        ]);
    }

    private function assertDocumentWriteAuthority(
        ManagedTraefikDocumentMutation $mutation,
        ManagedTraefikDocumentWriterAuthority $activeAuthority,
        bool $allowBootstrap,
    ): void {
        if ($allowBootstrap) {
            if ($activeAuthority->epoch !== 1 || ! $activeAuthority->matchesPredecessor($mutation)) {
                throw new InvalidArgumentException('Managed Traefik document writer authority bootstrap is limited to the exact initial epoch-one predecessor authority.');
            }

            return;
        }

        if (! $activeAuthority->matchesPredecessor($mutation)) {
            throw new InvalidArgumentException('The managed Traefik document write must require the exact active predecessor authority.');
        }
    }

    private function assertAuthorityPromotion(
        ManagedTraefikDocumentWriterAuthority $expectedAuthority,
        ManagedTraefikDocumentWriterAuthority $nextAuthority,
    ): void {
        if ($nextAuthority->epoch !== $expectedAuthority->epoch + 1) {
            throw new InvalidArgumentException('A managed Traefik document writer authority promotion must advance exactly one epoch.');
        }
    }

    private function assertWriterAuthorityContext(string $stateDirectory, string $filename): void
    {
        if (! str_starts_with($stateDirectory, '/') || str_contains($stateDirectory, "\0")) {
            throw new InvalidArgumentException('The managed Traefik document writer authority state directory must be an absolute NUL-free path.');
        }

        if (basename($filename) !== $filename
            || preg_match('/\A[a-z0-9][a-z0-9.-]{0,127}\.ya?ml\z/D', $filename) !== 1) {
            throw new InvalidArgumentException('The managed Traefik document writer authority filename is invalid.');
        }
    }

    private function assertRollbackAuthority(
        ManagedTraefikDocumentMutation $mutation,
        ManagedTraefikDocumentWriterAuthority $predecessorAuthority,
    ): void {
        if (! $predecessorAuthority->matchesPredecessor($mutation)) {
            throw new InvalidArgumentException('The managed Traefik document rollback must require the exact predecessor authority and cannot downgrade a successor authority.');
        }
    }
}
