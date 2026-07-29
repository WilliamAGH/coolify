<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Actions\Proxy\DurableRemoteArtifact;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class ControlPlaneStaticListenerHandoff
{
    use AsAction;

    public const APPLIED_OUTPUT = 'coolify-control-plane-static-listener:active';

    public const ROLLED_BACK_OUTPUT = 'coolify-control-plane-static-listener:rolled-back';

    public function __construct(
        private string $proxyComposePath = '/data/coolify/proxy/docker-compose.yml',
        private string $sourceComposePath = '/data/coolify/source/docker-compose.yml',
        private string $sourceProductionComposePath = '/data/coolify/source/docker-compose.prod.yml',
        private string $sourceOverridePath = '/data/coolify/source/docker-compose.control-plane-listener.yml',
        private string $sourceEnvironmentPath = '/data/coolify/source/.env',
        ?string $sourceCustomComposePath = null,
        ?string $sourcePostgresUpgradeComposePath = null,
        ?string $enrollmentLockPath = null,
        ?string $attestorStateDirectory = null,
    ) {
        $sourceDirectory = dirname($sourceComposePath);
        $this->sourceCustomComposePath = $sourceCustomComposePath
            ?? $sourceDirectory.'/docker-compose.custom.yml';
        $this->sourcePostgresUpgradeComposePath = $sourcePostgresUpgradeComposePath
            ?? $sourceDirectory.'/docker-compose.postgres-upgrade.yml';
        foreach ([
            'proxy Compose path' => $proxyComposePath,
            'source Compose path' => $sourceComposePath,
            'source production Compose path' => $sourceProductionComposePath,
            'source override path' => $sourceOverridePath,
            'source environment path' => $sourceEnvironmentPath,
            'source custom Compose path' => $this->sourceCustomComposePath,
            'source PostgreSQL upgrade Compose path' => $this->sourcePostgresUpgradeComposePath,
        ] as $label => $path) {
            $this->assertAbsolutePath($path, $label);
        }

        if (dirname($sourceProductionComposePath) !== $sourceDirectory
            || dirname($sourceOverridePath) !== $sourceDirectory
            || dirname($sourceEnvironmentPath) !== $sourceDirectory
            || dirname($this->sourceCustomComposePath) !== $sourceDirectory
            || dirname($this->sourcePostgresUpgradeComposePath) !== $sourceDirectory) {
            throw new InvalidArgumentException('The control-plane source Compose file list must have one canonical directory.');
        }

        $this->enrollmentLockPath = $enrollmentLockPath
            ?? dirname($proxyComposePath).'/.control-plane-static-listener-enrollment.lock';
        $this->assertAbsolutePath($this->enrollmentLockPath, 'enrollment lock path');
        $this->attestorStateDirectory = $attestorStateDirectory
            ?? '/data/coolify/control-plane-attestor';
        $this->assertAbsolutePath($this->attestorStateDirectory, 'attestor state directory');
    }

    private string $attestorStateDirectory;

    private string $enrollmentLockPath;

    private string $sourceCustomComposePath;

    private string $sourcePostgresUpgradeComposePath;

    public function commandFor(ControlPlaneProxyEnrollmentState $state): string
    {
        $proxyDirectory = dirname($this->proxyComposePath);
        $sourceDirectory = dirname($this->sourceComposePath);
        $rollbackJournalPath = $proxyDirectory.'/.control-plane-static-listener-rollback.'.$state->operationId.'.journal';
        $this->assertAbsolutePath($rollbackJournalPath, 'rollback journal path');
        $expectedProxyBinding = match ($state->exposure) {
            ControlPlaneProxyExposure::Public => '0.0.0.0:'.$state->appPort,
            ControlPlaneProxyExposure::Loopback => '127.0.0.1:'.$state->appPort,
        };

        return implode("\n", [
            'set -eu',
            'umask 077',
            'proxy_compose_path='.escapeshellarg($this->proxyComposePath),
            'proxy_directory='.escapeshellarg($proxyDirectory),
            'source_compose_path='.escapeshellarg($this->sourceComposePath),
            'source_production_compose_path='.escapeshellarg($this->sourceProductionComposePath),
            'source_override_path='.escapeshellarg($this->sourceOverridePath),
            'source_environment_path='.escapeshellarg($this->sourceEnvironmentPath),
            'source_custom_compose_path='.escapeshellarg($this->sourceCustomComposePath),
            'source_postgres_upgrade_compose_path='.escapeshellarg($this->sourcePostgresUpgradeComposePath),
            'source_directory='.escapeshellarg($sourceDirectory),
            'enrollment_lock_path='.escapeshellarg($this->enrollmentLockPath),
            'rollback_journal_path='.escapeshellarg($rollbackJournalPath),
            'attestor_state_directory='.escapeshellarg($this->attestorStateDirectory),
            'expected_proxy_binding='.escapeshellarg($expectedProxyBinding),
            'legacy_proxy_binding='.escapeshellarg('0.0.0.0:'.$state->appPort),
            'public_ipv6_binding='.escapeshellarg('[::]:'.$state->appPort),
            'predecessor_proxy_base64='.escapeshellarg(base64_encode($state->staticPredecessorBytes)),
            'replacement_proxy_base64='.escapeshellarg(base64_encode($state->staticReplacementBytes)),
            'source_override_base64='.escapeshellarg(base64_encode($state->sourceOverrideBytes)),
            'predecessor_proxy_sha256='.escapeshellarg(hash('sha256', $state->staticPredecessorBytes)),
            'replacement_proxy_sha256='.escapeshellarg(hash('sha256', $state->staticReplacementBytes)),
            'source_override_sha256='.escapeshellarg(hash('sha256', $state->sourceOverrideBytes)),
            'rename_noreplace_python_base64='.escapeshellarg(base64_encode($this->renameNoReplacePython())),
            'historical_evidence_validator_python_base64='.escapeshellarg(base64_encode($this->historicalEvidenceValidatorPython())),
            'applied_output='.escapeshellarg(self::APPLIED_OUTPUT),
            '',
            ...$this->shellSafetyFunctions(),
            ...$this->portInspectionFunctions(),
            ...$this->atomicRemoveFunction(allowAbsent: true),
            ...$this->coolifyComposeArgumentFunction(),
            'recreate_enrolled_coolify() {',
            '  set --',
            '  while IFS= read -r compose_argument; do set -- "$@" "$compose_argument"; done <<EOF',
            '$(coolify_compose_arguments)',
            'EOF',
            '  docker compose "$@" -f "$source_override_path" up -d --force-recreate --no-deps coolify',
            '}',
            ...$this->legacyRecreationFunctions(),
            'prepare_attestor_state_directory() {',
            '  if [ -e "$attestor_state_directory" ] || [ -L "$attestor_state_directory" ]; then',
            '    assert_directory "$attestor_state_directory"',
            '  else',
            '    mkdir -p "$attestor_state_directory" || fail',
            '    assert_directory "$attestor_state_directory"',
            '  fi',
            '  chmod 0700 "$attestor_state_directory" || fail',
            '  if [ "$(id -u)" = 0 ]; then chown 9999:0 "$attestor_state_directory" || fail; fi',
            '}',
            'verify_port_owner() {',
            '  expected_binding=$1',
            '  expected_owner=$2',
            '  alternate_binding=${3:-}',
            '  port_owner_count=0',
            '  port_owner=',
            '  docker ps --format "{{.Names}}" > "$scratch/containers" || fail',
            '  while IFS= read -r candidate; do',
            '    test -n "$candidate" || continue',
            '    candidate_bindings=$(published_host_bindings "$candidate") || fail',
            '    if printf "%s\\n" "$candidate_bindings" | grep -Fx "$expected_binding" >/dev/null '
                .'|| { [ -n "$alternate_binding" ] && printf "%s\\n" "$candidate_bindings" | grep -Fx "$alternate_binding" >/dev/null; }; then',
            '      port_owner_count=$((port_owner_count + 1))',
            '      port_owner=$candidate',
            '    fi',
            '  done < "$scratch/containers"',
            '  test "$port_owner_count" = 1 && test "$port_owner" = "$expected_owner" || fail',
            '}',
            'verify_enrolled() {',
            '  docker inspect --type container coolify >/dev/null || fail',
            '  docker inspect --type container coolify-proxy >/dev/null || fail',
            '  coolify_bindings=$(published_bindings coolify 8080) || fail',
            '  test -z "$coolify_bindings" || fail',
            '  proxy_bindings=$(published_bindings coolify-proxy 8000) || fail',
            '  printf "%s\\n" "$proxy_bindings" | grep -Fx "$expected_proxy_binding" >/dev/null || fail',
            '  if [ "$expected_proxy_binding" = "$legacy_proxy_binding" ]; then',
            '    unexpected_proxy_bindings=$(printf "%s\\n" "$proxy_bindings" | grep -Fvx "$expected_proxy_binding" | grep -Fvx "$public_ipv6_binding" || true)',
            '  else',
            '    unexpected_proxy_bindings=$(printf "%s\\n" "$proxy_bindings" | grep -Fvx "$expected_proxy_binding" || true)',
            '  fi',
            '  test -z "$unexpected_proxy_bindings" || fail',
            '  alternate_proxy_binding=',
            '  if [ "$expected_proxy_binding" = "$legacy_proxy_binding" ]; then alternate_proxy_binding=$public_ipv6_binding; fi',
            '  verify_port_owner "$expected_proxy_binding" coolify-proxy "$alternate_proxy_binding"',
            '}',
            'verify_legacy() {',
            '  docker inspect --type container coolify >/dev/null || fail',
            '  docker inspect --type container coolify-proxy >/dev/null || fail',
            '  coolify_bindings=$(published_bindings coolify 8080) || fail',
            '  printf "%s\\n" "$coolify_bindings" | grep -Fx "$legacy_proxy_binding" >/dev/null || fail',
            '  proxy_bindings=$(published_bindings coolify-proxy 8000) || fail',
            '  test -z "$proxy_bindings" || fail',
            '  verify_port_owner "$legacy_proxy_binding" coolify "$public_ipv6_binding"',
            '}',
            'completed=0',
            'handoff_started=0',
            'rollback() {',
            '  result=$?',
            '  trap - 0 HUP INT TERM',
            '  if [ "$completed" = 1 ] || [ "$handoff_started" = 0 ]; then cleanup; exit "$result"; fi',
            '  atomic_replace "$proxy_compose_path" "$predecessor_proxy_file" "$proxy_directory" || { cleanup; exit 70; }',
            '  matches "$proxy_compose_path" "$predecessor_proxy_file" || { cleanup; exit 70; }',
            '  atomic_remove "$source_override_path" "$source_directory" || { cleanup; exit 70; }',
            '  recreate_legacy_coolify || { cleanup; exit 70; }',
            '  recreate_traefik || { cleanup; exit 70; }',
            '  verify_legacy || { cleanup; exit 70; }',
            '  cleanup',
            '  exit "$result"',
            '}',
            '',
            'assert_directory "$proxy_directory"',
            'assert_directory "$source_directory"',
            'assert_regular "$proxy_compose_path"',
            'assert_regular "$source_compose_path"',
            'assert_regular "$source_production_compose_path"',
            'assert_regular "$source_environment_path"',
            'assert_regular_or_absent "$source_custom_compose_path"',
            'assert_regular_or_absent "$source_postgres_upgrade_compose_path"',
            'assert_regular_or_absent "$source_override_path"',
            'assert_regular_or_absent "$rollback_journal_path"',
            'assert_regular_or_absent "$enrollment_lock_path"',
            'command -v flock >/dev/null 2>&1 || fail',
            'exec 9> "$enrollment_lock_path" || fail',
            'flock -x 9 || fail',
            'assert_regular "$proxy_compose_path"',
            'assert_regular "$source_compose_path"',
            'assert_regular "$source_production_compose_path"',
            'assert_regular "$source_environment_path"',
            'assert_regular_or_absent "$source_custom_compose_path"',
            'assert_regular_or_absent "$source_postgres_upgrade_compose_path"',
            'assert_regular_or_absent "$source_override_path"',
            'assert_regular_or_absent "$rollback_journal_path"',
            'assert_valid_source_override_rollback_artifacts',
            'is_absent "$rollback_journal_path" || fail',
            'prepare_attestor_state_directory',
            'scratch=$(mktemp -d "$proxy_directory/.control-plane-listener.XXXXXX") || fail',
            'predecessor_proxy_file="$scratch/proxy-predecessor"',
            'replacement_proxy_file="$scratch/proxy-replacement"',
            'source_override_file="$scratch/source-override"',
            'cleanup() { rm -f "$predecessor_proxy_file" "$replacement_proxy_file" "$source_override_file" "$scratch/containers"; rmdir "$scratch" 2>/dev/null || true; }',
            'trap rollback 0 HUP INT TERM',
            'printf %s "$predecessor_proxy_base64" | base64 -d > "$predecessor_proxy_file" || fail',
            'printf %s "$replacement_proxy_base64" | base64 -d > "$replacement_proxy_file" || fail',
            'printf %s "$source_override_base64" | base64 -d > "$source_override_file" || fail',
            'checksum_matches "$predecessor_proxy_file" "$predecessor_proxy_sha256" || fail',
            'checksum_matches "$replacement_proxy_file" "$replacement_proxy_sha256" || fail',
            'checksum_matches "$source_override_file" "$source_override_sha256" || fail',
            'if matches "$proxy_compose_path" "$replacement_proxy_file" && matches_if_present "$source_override_path" "$source_override_file"; then',
            '  handoff_started=1',
            '  durable_remote_reaffirm "$proxy_compose_path" "$proxy_directory" || fail',
            '  durable_remote_reaffirm "$source_override_path" "$source_directory" || fail',
            '  verify_enrolled',
            '  assert_valid_source_override_rollback_artifacts',
            '  completed=1',
            '  printf %s "$applied_output"',
            '  exit 0',
            'fi',
            'if ! matches "$proxy_compose_path" "$predecessor_proxy_file" && ! matches "$proxy_compose_path" "$replacement_proxy_file"; then fail; fi',
            'if ! is_absent "$source_override_path" && ! matches_if_present "$source_override_path" "$source_override_file"; then fail; fi',
            'handoff_started=1',
            'if ! matches "$proxy_compose_path" "$replacement_proxy_file"; then atomic_replace "$proxy_compose_path" "$replacement_proxy_file" "$proxy_directory"; fi',
            'if ! matches_if_present "$source_override_path" "$source_override_file"; then atomic_replace "$source_override_path" "$source_override_file" "$source_directory"; fi',
            'matches "$proxy_compose_path" "$replacement_proxy_file" || fail',
            'matches "$source_override_path" "$source_override_file" || fail',
            'if [ "${COOLIFY_CONTROL_PLANE_HANDOFF_FAIL_AFTER_STATIC:-}" = 1 ]; then fail; fi',
            'recreate_enrolled_coolify',
            'if [ "${COOLIFY_CONTROL_PLANE_HANDOFF_FAIL_AFTER_COOLIFY:-}" = 1 ]; then fail; fi',
            'recreate_traefik',
            'verify_enrolled',
            'durable_remote_reaffirm "$proxy_compose_path" "$proxy_directory" || fail',
            'durable_remote_reaffirm "$source_override_path" "$source_directory" || fail',
            'assert_valid_source_override_rollback_artifacts',
            'completed=1',
            'printf %s "$applied_output"',
        ]);
    }

    public function rollbackCommandFor(
        ControlPlaneProxyEnrollmentState $state,
        string $operationId,
        string $token,
        ?string $authorizedOrphanedSourceOverrideSha256 = null,
    ): string {
        if (! $state->isOwnedBy($operationId, $token)) {
            throw new InvalidArgumentException('The control-plane static listener rollback is owned by another operation.');
        }
        if (! in_array($state->phase, [ControlPlaneProxyEnrollmentPhase::RollingBack, ControlPlaneProxyEnrollmentPhase::RolledBack], true)) {
            throw new InvalidArgumentException('The control-plane static listener rollback requires durable rolling-back state.');
        }
        if ($authorizedOrphanedSourceOverrideSha256 !== null
            && preg_match('/\A[a-f0-9]{64}\z/D', $authorizedOrphanedSourceOverrideSha256) !== 1) {
            throw new InvalidArgumentException('The orphaned source override authorization must be an exact lowercase SHA-256.');
        }
        if ($authorizedOrphanedSourceOverrideSha256 !== null
            && $state->phase !== ControlPlaneProxyEnrollmentPhase::RollingBack) {
            throw new InvalidArgumentException('The orphaned source override authorization is invalid after static rollback.');
        }

        return $this->renderRollbackCommand($state, false, $authorizedOrphanedSourceOverrideSha256);
    }

    public function reassertAwaitingRollbackCommandFor(
        ControlPlaneProxyEnrollmentState $state,
        string $operationId,
        string $token,
    ): string {
        if (! $state->isOwnedBy($operationId, $token)) {
            throw new InvalidArgumentException('The control-plane static listener rollback is owned by another operation.');
        }
        if ($state->phase !== ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement) {
            throw new InvalidArgumentException('The control-plane static listener rollback reassertion requires durable awaiting-acknowledgement state.');
        }

        return $this->renderRollbackCommand($state, true, null);
    }

    private function renderRollbackCommand(
        ControlPlaneProxyEnrollmentState $state,
        bool $reassertAwaiting,
        ?string $authorizedOrphanedSourceOverrideSha256,
    ): string {

        $proxyDirectory = dirname($this->proxyComposePath);
        $sourceDirectory = dirname($this->sourceComposePath);
        $rollbackJournalPath = $proxyDirectory.'/.control-plane-static-listener-rollback.'.$state->operationId.'.journal';
        $sourceOverrideQuarantinePath = $sourceDirectory.'/.control-plane-source-override-rollback.'.$state->operationId.'.quarantine';
        $this->assertAbsolutePath($rollbackJournalPath, 'rollback journal path');
        $this->assertAbsolutePath($sourceOverrideQuarantinePath, 'source override quarantine path');

        return implode("\n", [
            'set -eu',
            'umask 077',
            'proxy_compose_path='.escapeshellarg($this->proxyComposePath),
            'proxy_directory='.escapeshellarg($proxyDirectory),
            'source_compose_path='.escapeshellarg($this->sourceComposePath),
            'source_production_compose_path='.escapeshellarg($this->sourceProductionComposePath),
            'source_override_path='.escapeshellarg($this->sourceOverridePath),
            'source_environment_path='.escapeshellarg($this->sourceEnvironmentPath),
            'source_custom_compose_path='.escapeshellarg($this->sourceCustomComposePath),
            'source_postgres_upgrade_compose_path='.escapeshellarg($this->sourcePostgresUpgradeComposePath),
            'source_directory='.escapeshellarg($sourceDirectory),
            'source_override_quarantine_path='.escapeshellarg($sourceOverrideQuarantinePath),
            'enrollment_lock_path='.escapeshellarg($this->enrollmentLockPath),
            'rollback_journal_path='.escapeshellarg($rollbackJournalPath),
            'state_phase='.escapeshellarg($state->phase->value),
            'reassert_awaiting='.escapeshellarg($reassertAwaiting ? '1' : '0'),
            'expected_proxy_binding='.escapeshellarg(match ($state->exposure) {
                ControlPlaneProxyExposure::Public => '0.0.0.0:'.$state->appPort,
                ControlPlaneProxyExposure::Loopback => '127.0.0.1:'.$state->appPort,
            }),
            'legacy_proxy_binding='.escapeshellarg('0.0.0.0:'.$state->appPort),
            'public_ipv6_binding='.escapeshellarg('[::]:'.$state->appPort),
            'predecessor_proxy_base64='.escapeshellarg(base64_encode($state->staticPredecessorBytes)),
            'replacement_proxy_base64='.escapeshellarg(base64_encode($state->staticReplacementBytes)),
            'source_override_base64='.escapeshellarg(base64_encode($state->sourceOverrideBytes)),
            'predecessor_proxy_sha256='.escapeshellarg(hash('sha256', $state->staticPredecessorBytes)),
            'replacement_proxy_sha256='.escapeshellarg(hash('sha256', $state->staticReplacementBytes)),
            'source_override_sha256='.escapeshellarg(hash('sha256', $state->sourceOverrideBytes)),
            'authorized_orphaned_source_override_sha256='.escapeshellarg($authorizedOrphanedSourceOverrideSha256 ?? 'absent'),
            'rename_noreplace_python_base64='.escapeshellarg(base64_encode($this->renameNoReplacePython())),
            'rollback_journal_prefix_base64='.escapeshellarg(base64_encode($this->rollbackJournalPrefix($state))),
            'rollback_started_journal_suffix_base64='.escapeshellarg(base64_encode($this->rollbackJournalSuffix('rolling-back'))),
            'rollback_completed_journal_suffix_base64='.escapeshellarg(base64_encode($this->rollbackJournalSuffix('rolled-back'))),
            'rolled_back_output='.escapeshellarg(self::ROLLED_BACK_OUTPUT),
            '',
            ...$this->shellSafetyFunctions(),
            ...$this->portInspectionFunctions(),
            ...$this->coolifyComposeArgumentFunction(),
            ...$this->legacyRecreationFunctions(),
            'has_port_owner() {',
            '  expected_binding=$1',
            '  expected_owner=$2',
            '  alternate_binding=${3:-}',
            '  port_owner_count=0',
            '  port_owner=',
            '  docker ps --format "{{.Names}}" > "$scratch/containers" 2>/dev/null || return 1',
            '  while IFS= read -r candidate; do',
            '    test -n "$candidate" || continue',
            '    candidate_bindings=$(published_host_bindings "$candidate") || return 1',
            '    if printf "%s\n" "$candidate_bindings" | grep -Fx "$expected_binding" >/dev/null '
                .'|| { [ -n "$alternate_binding" ] && printf "%s\n" "$candidate_bindings" | grep -Fx "$alternate_binding" >/dev/null; }; then',
            '      port_owner_count=$((port_owner_count + 1))',
            '      port_owner=$candidate',
            '    fi',
            '  done < "$scratch/containers"',
            '  test "$port_owner_count" = 1 && test "$port_owner" = "$expected_owner"',
            '}',
            'enrolled_is_verified() {',
            '  docker inspect --type container coolify >/dev/null 2>&1 || return 1',
            '  docker inspect --type container coolify-proxy >/dev/null 2>&1 || return 1',
            '  coolify_bindings=$(published_bindings coolify 8080) || return 1',
            '  test -z "$coolify_bindings" || return 1',
            '  proxy_bindings=$(published_bindings coolify-proxy 8000) || return 1',
            '  printf "%s\n" "$proxy_bindings" | grep -Fx "$expected_proxy_binding" >/dev/null || return 1',
            '  if [ "$expected_proxy_binding" = "$legacy_proxy_binding" ]; then',
            '    unexpected_proxy_bindings=$(printf "%s\n" "$proxy_bindings" | grep -Fvx "$expected_proxy_binding" | grep -Fvx "$public_ipv6_binding" || true)',
            '  else',
            '    unexpected_proxy_bindings=$(printf "%s\n" "$proxy_bindings" | grep -Fvx "$expected_proxy_binding" || true)',
            '  fi',
            '  test -z "$unexpected_proxy_bindings" || return 1',
            '  alternate_proxy_binding=',
            '  if [ "$expected_proxy_binding" = "$legacy_proxy_binding" ]; then alternate_proxy_binding=$public_ipv6_binding; fi',
            '  has_port_owner "$expected_proxy_binding" coolify-proxy "$alternate_proxy_binding"',
            '}',
            'legacy_is_verified() {',
            '  docker inspect --type container coolify >/dev/null 2>&1 || return 1',
            '  docker inspect --type container coolify-proxy >/dev/null 2>&1 || return 1',
            '  coolify_bindings=$(published_bindings coolify 8080) || return 1',
            '  printf "%s\n" "$coolify_bindings" | grep -Fx "$legacy_proxy_binding" >/dev/null || return 1',
            '  proxy_bindings=$(published_bindings coolify-proxy 8000) || return 1',
            '  test -z "$proxy_bindings" || return 1',
            '  has_port_owner "$legacy_proxy_binding" coolify "$public_ipv6_binding"',
            '}',
            'verify_legacy() { legacy_is_verified || fail; }',
            'ensure_rollback_journal() {',
            '  if is_absent "$rollback_journal_path"; then',
            '    [ "$reassert_awaiting" = 0 ] || fail',
            '    atomic_replace "$rollback_journal_path" "$rollback_started_journal_file" "$proxy_directory"',
            '  elif ! matches "$rollback_journal_path" "$rollback_started_journal_file" && ! matches "$rollback_journal_path" "$rollback_completed_journal_file"; then',
            '    fail',
            '  fi',
            '}',
            'reclaim_legacy_rollback_journal() {',
            '  assert_regular "$rollback_journal_path"',
            '  test "$(durable_remote_link_count "$rollback_journal_path")" = 1 || fail',
            '  journal_owner_uid=$(durable_remote_owner_uid "$rollback_journal_path") || fail',
            '  current_uid=$(id -u) || fail',
            '  current_gid=$(id -g) || fail',
            '  case "$journal_owner_uid" in "$current_uid"|9999) ;; *) fail ;; esac',
            '  chown -h "$current_uid:$current_gid" "$rollback_journal_path" || fail',
            '  assert_regular "$rollback_journal_path"',
            '  chmod 600 "$rollback_journal_path" || fail',
            '  durable_remote_assert_owned_regular "$rollback_journal_path" || fail',
            '}',
            'complete_rollback_journal() {',
            '  atomic_replace "$rollback_journal_path" "$rollback_completed_journal_file" "$proxy_directory"',
            '  matches "$rollback_journal_path" "$rollback_completed_journal_file" || fail',
            '}',
            'restart_rollback_journal() {',
            '  atomic_replace "$rollback_journal_path" "$rollback_started_journal_file" "$proxy_directory"',
            '  matches "$rollback_journal_path" "$rollback_started_journal_file" || fail',
            '}',
            '',
            'assert_directory "$proxy_directory"',
            'assert_directory "$source_directory"',
            'assert_regular "$proxy_compose_path"',
            'assert_regular "$source_compose_path"',
            'assert_regular "$source_production_compose_path"',
            'assert_regular "$source_environment_path"',
            'assert_regular_or_absent "$source_custom_compose_path"',
            'assert_regular_or_absent "$source_postgres_upgrade_compose_path"',
            'assert_regular_or_absent "$source_override_path"',
            'assert_regular_or_absent "$source_override_quarantine_path"',
            'assert_regular_or_absent "$rollback_journal_path"',
            'assert_regular_or_absent "$enrollment_lock_path"',
            'command -v flock >/dev/null 2>&1 || fail',
            'exec 9> "$enrollment_lock_path" || fail',
            'flock -x 9 || fail',
            'assert_regular "$proxy_compose_path"',
            'assert_regular "$source_compose_path"',
            'assert_regular "$source_production_compose_path"',
            'assert_regular "$source_environment_path"',
            'assert_regular_or_absent "$source_custom_compose_path"',
            'assert_regular_or_absent "$source_postgres_upgrade_compose_path"',
            'assert_regular_or_absent "$source_override_path"',
            'assert_regular_or_absent "$source_override_quarantine_path"',
            'assert_regular_or_absent "$rollback_journal_path"',
            'scratch=$(mktemp -d "$proxy_directory/.control-plane-listener.XXXXXX") || fail',
            'predecessor_proxy_file="$scratch/proxy-predecessor"',
            'replacement_proxy_file="$scratch/proxy-replacement"',
            'source_override_file="$scratch/source-override"',
            'rollback_started_journal_file="$scratch/rollback-started-journal"',
            'rollback_completed_journal_file="$scratch/rollback-completed-journal"',
            'cleanup() { rm -f "$predecessor_proxy_file" "$replacement_proxy_file" "$source_override_file" "$rollback_started_journal_file" "$rollback_completed_journal_file" "$scratch/containers"; rmdir "$scratch" 2>/dev/null || true; }',
            'trap cleanup 0',
            'trap "exit 1" HUP INT TERM',
            'printf %s "$predecessor_proxy_base64" | base64 -d > "$predecessor_proxy_file" || fail',
            'printf %s "$replacement_proxy_base64" | base64 -d > "$replacement_proxy_file" || fail',
            'printf %s "$source_override_base64" | base64 -d > "$source_override_file" || fail',
            'checksum_matches "$predecessor_proxy_file" "$predecessor_proxy_sha256" || fail',
            'checksum_matches "$replacement_proxy_file" "$replacement_proxy_sha256" || fail',
            'checksum_matches "$source_override_file" "$source_override_sha256" || fail',
            'rollback_orphaned_source_override_sha256=absent',
            'source_override_disposition=absent',
            'if matches "$proxy_compose_path" "$replacement_proxy_file"; then',
            '  is_absent "$source_override_quarantine_path" || fail',
            '  matches "$source_override_path" "$source_override_file" || fail',
            '  enrolled_is_verified || fail',
            '  [ "$authorized_orphaned_source_override_sha256" = absent ] || fail',
            '  rollback_orphaned_source_override_sha256=$source_override_sha256',
            '  source_override_disposition=canonical',
            'elif matches "$proxy_compose_path" "$predecessor_proxy_file"; then',
            '  if ! is_absent "$source_override_path"; then',
            '    is_absent "$source_override_quarantine_path" || fail',
            '    if matches "$source_override_path" "$source_override_file"; then',
            '      [ "$authorized_orphaned_source_override_sha256" = absent ] || fail',
            '      rollback_orphaned_source_override_sha256=$source_override_sha256',
            '    else',
            '      [ "$authorized_orphaned_source_override_sha256" != absent ] || fail',
            '      checksum_matches "$source_override_path" "$authorized_orphaned_source_override_sha256" || fail',
            '      rollback_orphaned_source_override_sha256=$authorized_orphaned_source_override_sha256',
            '    fi',
            '    source_override_disposition=canonical',
            '  elif ! is_absent "$source_override_quarantine_path"; then',
            '    durable_remote_assert_owned_regular "$source_override_quarantine_path" || fail',
            '    source_override_audit_checksum=$(sha256sum "$source_override_quarantine_path") || fail',
            '    rollback_orphaned_source_override_sha256=${source_override_audit_checksum%% *}',
            '    is_sha256 "$rollback_orphaned_source_override_sha256" || fail',
            '    if [ "$authorized_orphaned_source_override_sha256" != absent ]; then',
            '      [ "$authorized_orphaned_source_override_sha256" = "$rollback_orphaned_source_override_sha256" ] || fail',
            '    fi',
            '    checksum_matches "$source_override_quarantine_path" "$rollback_orphaned_source_override_sha256" || fail',
            '    source_override_disposition=quarantined',
            '  else',
            '    [ "$authorized_orphaned_source_override_sha256" = absent ] || fail',
            '  fi',
            'else',
            '  fail',
            'fi',
            'printf %s "$rollback_journal_prefix_base64" | base64 -d > "$rollback_started_journal_file" || fail',
            'printf %s "$rollback_orphaned_source_override_sha256" >> "$rollback_started_journal_file" || fail',
            'printf %s "$rollback_started_journal_suffix_base64" | base64 -d >> "$rollback_started_journal_file" || fail',
            'printf %s "$rollback_journal_prefix_base64" | base64 -d > "$rollback_completed_journal_file" || fail',
            'printf %s "$rollback_orphaned_source_override_sha256" >> "$rollback_completed_journal_file" || fail',
            'printf %s "$rollback_completed_journal_suffix_base64" | base64 -d >> "$rollback_completed_journal_file" || fail',
            'ensure_rollback_journal',
            'reclaim_legacy_rollback_journal',
            'if [ "$source_override_disposition" != absent ]; then',
            '  if [ "$source_override_disposition" = quarantined ] && is_absent "$source_override_path" && matches "$rollback_journal_path" "$rollback_completed_journal_file" && legacy_is_verified; then',
            '    durable_remote_assert_owned_regular "$source_override_quarantine_path" || fail',
            '    checksum_matches "$source_override_quarantine_path" "$rollback_orphaned_source_override_sha256" || fail',
            '    durable_remote_reaffirm "$source_override_quarantine_path" "$source_directory" || fail',
            '    checksum_matches "$source_override_quarantine_path" "$rollback_orphaned_source_override_sha256" || fail',
            '    durable_remote_reaffirm "$proxy_compose_path" "$proxy_directory" || fail',
            '    durable_remote_reaffirm "$rollback_journal_path" "$proxy_directory" || fail',
            '    printf %s "$rolled_back_output"',
            '    exit 0',
            '  fi',
            '  if matches "$rollback_journal_path" "$rollback_completed_journal_file"; then restart_rollback_journal; fi',
            '  matches "$rollback_journal_path" "$rollback_started_journal_file" || fail',
            '  durable_remote_reaffirm "$rollback_journal_path" "$proxy_directory" || fail',
            '  if matches "$proxy_compose_path" "$replacement_proxy_file"; then atomic_replace "$proxy_compose_path" "$predecessor_proxy_file" "$proxy_directory"; fi',
            '  matches "$proxy_compose_path" "$predecessor_proxy_file" || fail',
            '  if [ "$source_override_disposition" = canonical ]; then',
            '    is_absent "$source_override_quarantine_path" || fail',
            '    checksum_matches "$source_override_path" "$rollback_orphaned_source_override_sha256" || fail',
            '    durable_move_no_clobber "$source_override_path" "$source_override_quarantine_path" "$source_directory" || fail',
            '  else',
            '    is_absent "$source_override_path" || fail',
            '  fi',
            '  is_absent "$source_override_path" || fail',
            '  durable_remote_assert_owned_regular "$source_override_quarantine_path" || fail',
            '  source_override_audit_identity=$(file_identity "$source_override_quarantine_path") || fail',
            '  checksum_matches "$source_override_quarantine_path" "$rollback_orphaned_source_override_sha256" || fail',
            '  durable_remote_reaffirm "$source_override_quarantine_path" "$source_directory" || fail',
            '  if [ "${COOLIFY_CONTROL_PLANE_ROLLBACK_FAIL_AFTER_STATIC:-}" = 1 ]; then fail; fi',
            '  recreate_traefik',
            '  if [ "${COOLIFY_CONTROL_PLANE_ROLLBACK_FAIL_AFTER_TRAEFIK:-}" = 1 ]; then fail; fi',
            '  recreate_legacy_coolify',
            '  verify_legacy',
            '  durable_remote_assert_owned_regular "$source_override_quarantine_path" || fail',
            '  [ "$(file_identity "$source_override_quarantine_path")" = "$source_override_audit_identity" ] || fail',
            '  checksum_matches "$source_override_quarantine_path" "$rollback_orphaned_source_override_sha256" || fail',
            '  durable_remote_reaffirm "$source_override_quarantine_path" "$source_directory" || fail',
            '  durable_remote_assert_owned_regular "$source_override_quarantine_path" || fail',
            '  [ "$(file_identity "$source_override_quarantine_path")" = "$source_override_audit_identity" ] || fail',
            '  checksum_matches "$source_override_quarantine_path" "$rollback_orphaned_source_override_sha256" || fail',
            '  if [ "${COOLIFY_CONTROL_PLANE_ROLLBACK_FAIL_AFTER_AUDIT_COMPLETION:-}" = 1 ]; then fail; fi',
            '  complete_rollback_journal',
            '  durable_remote_reaffirm "$proxy_compose_path" "$proxy_directory" || fail',
            '  is_absent "$source_override_path" || fail',
            '  durable_remote_reaffirm "$rollback_journal_path" "$proxy_directory" || fail',
            '  printf %s "$rolled_back_output"',
            '  exit 0',
            'fi',
            'if matches "$rollback_journal_path" "$rollback_completed_journal_file" && matches "$proxy_compose_path" "$predecessor_proxy_file" && is_absent "$source_override_path" && is_absent "$source_override_quarantine_path" && legacy_is_verified; then',
            '  durable_remote_reaffirm "$proxy_compose_path" "$proxy_directory" || fail',
            '  is_absent "$source_override_path" || fail',
            '  durable_remote_reaffirm "$rollback_journal_path" "$proxy_directory" || fail',
            '  printf %s "$rolled_back_output"',
            '  exit 0',
            'fi',
            'if [ "$reassert_awaiting" = 1 ] && matches "$rollback_journal_path" "$rollback_completed_journal_file"; then',
            '  restart_rollback_journal',
            'fi',
            'if [ "$state_phase" = rolled_back ]; then',
            '  matches "$proxy_compose_path" "$predecessor_proxy_file" || fail',
            '  is_absent "$source_override_path" || fail',
            '  is_absent "$source_override_quarantine_path" || fail',
            '  verify_legacy',
            '  complete_rollback_journal',
            '  durable_remote_reaffirm "$proxy_compose_path" "$proxy_directory" || fail',
            '  is_absent "$source_override_path" || fail',
            '  durable_remote_reaffirm "$rollback_journal_path" "$proxy_directory" || fail',
            '  printf %s "$rolled_back_output"',
            '  exit 0',
            'fi',
            'if matches "$proxy_compose_path" "$predecessor_proxy_file" && is_absent "$source_override_path" && is_absent "$source_override_quarantine_path" && legacy_is_verified; then',
            '  complete_rollback_journal',
            '  durable_remote_reaffirm "$proxy_compose_path" "$proxy_directory" || fail',
            '  is_absent "$source_override_path" || fail',
            '  durable_remote_reaffirm "$rollback_journal_path" "$proxy_directory" || fail',
            '  printf %s "$rolled_back_output"',
            '  exit 0',
            'fi',
            'matches "$proxy_compose_path" "$predecessor_proxy_file" || fail',
            'is_absent "$source_override_path" || fail',
            'is_absent "$source_override_quarantine_path" || fail',
            'if [ "${COOLIFY_CONTROL_PLANE_ROLLBACK_FAIL_AFTER_STATIC:-}" = 1 ]; then fail; fi',
            'recreate_traefik',
            'if [ "${COOLIFY_CONTROL_PLANE_ROLLBACK_FAIL_AFTER_TRAEFIK:-}" = 1 ]; then fail; fi',
            'recreate_legacy_coolify',
            'verify_legacy',
            'complete_rollback_journal',
            'durable_remote_reaffirm "$proxy_compose_path" "$proxy_directory" || fail',
            'is_absent "$source_override_path" || fail',
            'durable_remote_reaffirm "$rollback_journal_path" "$proxy_directory" || fail',
            'printf %s "$rolled_back_output"',
        ]);
    }

    /** @return list<string> */
    private function shellSafetyFunctions(): array
    {
        return [
            'fail() { exit 1; }',
            ...DurableRemoteArtifact::shellFunctions(),
            'assert_directory() { test -d "$1" && test ! -L "$1" || fail; }',
            'assert_regular() { test -e "$1" && test ! -L "$1" && test -f "$1" || fail; }',
            'assert_regular_or_absent() {',
            '  if [ -e "$1" ] || [ -L "$1" ]; then assert_regular "$1"; fi',
            '}',
            'is_absent() { test ! -e "$1" && test ! -L "$1"; }',
            'is_sha256() {',
            '  [ "$#" -eq 1 ] || return 64',
            '  [ "${#1}" = 64 ] || return 1',
            '  case "$1" in *[!a-f0-9]*) return 1 ;; esac',
            '}',
            'is_operation_id() {',
            '  [ "$#" -eq 1 ] || return 64',
            '  [ "${#1}" -ge 1 ] && [ "${#1}" -le 128 ] || return 1',
            '  case "$1" in [a-z0-9]*) ;; *) return 1 ;; esac',
            '  case "$1" in *[!a-z0-9._-]*) return 1 ;; esac',
            '}',
            'assert_valid_completed_source_override_rollback_journal() {',
            '  historical_quarantine_path=$1',
            '  historical_journal_path=$2',
            '  historical_operation_id=$3',
            '  command -v python3 >/dev/null 2>&1 || fail',
            '  historical_owner_uid=$(id -u) || fail',
            '  printf %s "$historical_evidence_validator_python_base64" | base64 -d | python3 - --validate-historical "$historical_quarantine_path" "$historical_journal_path" "$historical_operation_id" "$historical_owner_uid" || fail',
            '}',
            'assert_valid_source_override_rollback_artifacts() {',
            '  for source_override_rollback_artifact in "$source_directory"/.control-plane-source-override-rollback.*; do',
            '    if [ ! -e "$source_override_rollback_artifact" ] && [ ! -L "$source_override_rollback_artifact" ]; then continue; fi',
            '    source_override_rollback_name=${source_override_rollback_artifact##*/}',
            '    case "$source_override_rollback_name" in .control-plane-source-override-rollback.*.quarantine) ;; *) fail ;; esac',
            '    source_override_rollback_operation_id=${source_override_rollback_name#.control-plane-source-override-rollback.}',
            '    source_override_rollback_operation_id=${source_override_rollback_operation_id%.quarantine}',
            '    is_operation_id "$source_override_rollback_operation_id" || fail',
            '    source_override_rollback_journal="$proxy_directory/.control-plane-static-listener-rollback.$source_override_rollback_operation_id.journal"',
            '    assert_valid_completed_source_override_rollback_journal "$source_override_rollback_artifact" "$source_override_rollback_journal" "$source_override_rollback_operation_id"',
            '  done',
            '}',
            'matches() { assert_regular "$1"; cmp -s "$1" "$2"; }',
            'matches_if_present() { is_absent "$1" && return 1; matches "$1" "$2"; }',
            'checksum_matches() {',
            '  checksum=$(sha256sum "$1")',
            '  test "${checksum%% *}" = "$2"',
            '}',
            'file_identity() {',
            '  if stat -c "%d:%i" "$1" >/dev/null 2>&1; then stat -c "%d:%i" "$1"; else stat -f "%d:%i" "$1"; fi',
            '}',
            'atomic_replace() {',
            '  target=$1',
            '  source=$2',
            '  target_directory=$3',
            '  stage=$(mktemp "$target_directory/.control-plane-listener.XXXXXX") || fail',
            '  cp "$source" "$stage" || fail',
            '  chmod 600 "$stage" || fail',
            '  durable_remote_replace "$stage" "$target" "$target_directory" || fail',
            '}',
            'durable_move_no_clobber() {',
            '  source=$1',
            '  destination=$2',
            '  target_directory=$3',
            '  durable_remote_assert_owned_directory "$target_directory" || fail',
            '  durable_remote_assert_owned_regular "$source" || fail',
            '  is_absent "$destination" || fail',
            '  sync "$source" || fail',
            '  command -v python3 >/dev/null 2>&1 || fail',
            '  printf %s "$rename_noreplace_python_base64" | base64 -d | python3 - "$source" "$destination" || fail',
            '  is_absent "$source" || fail',
            '  durable_remote_assert_owned_regular "$destination" || fail',
            '  sync "$target_directory" || fail',
            '}',
        ];
    }

    /** @return list<string> */
    private function atomicRemoveFunction(bool $allowAbsent): array
    {
        return [
            'atomic_remove() {',
            '  target=$1',
            '  target_directory=$2',
            $allowAbsent ? '  assert_regular_or_absent "$target"' : '  assert_regular "$target"',
            '  durable_remote_remove "$target" "$target_directory" || fail',
            '}',
        ];
    }

    /** @return list<string> */
    private function coolifyComposeArgumentFunction(): array
    {
        return [
            'coolify_compose_arguments() {',
            '  printf "%s\n" --project-directory "$source_directory" --env-file "$source_environment_path" -f "$source_compose_path" -f "$source_production_compose_path"',
            '  if [ -e "$source_custom_compose_path" ] || [ -L "$source_custom_compose_path" ]; then assert_regular "$source_custom_compose_path"; printf "%s\n" -f "$source_custom_compose_path"; fi',
            '  if [ -e "$source_postgres_upgrade_compose_path" ] || [ -L "$source_postgres_upgrade_compose_path" ]; then assert_regular "$source_postgres_upgrade_compose_path"; printf "%s\n" -f "$source_postgres_upgrade_compose_path"; fi',
            '}',
        ];
    }

    /** @return list<string> */
    private function legacyRecreationFunctions(): array
    {
        return [
            'recreate_legacy_coolify() {',
            '  set --',
            '  while IFS= read -r compose_argument; do set -- "$@" "$compose_argument"; done <<EOF',
            '$(coolify_compose_arguments)',
            'EOF',
            '  docker compose "$@" up -d --force-recreate --no-deps coolify',
            '}',
            'recreate_traefik() {',
            '  docker compose --project-directory "$proxy_directory" -f "$proxy_compose_path" up -d --force-recreate --no-deps traefik',
            '}',
        ];
    }

    private function assertAbsolutePath(string $path, string $label): void
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new InvalidArgumentException("The control-plane {$label} must be an absolute NUL-free path.");
        }
    }

    /** @return list<string> */
    private function portInspectionFunctions(): array
    {
        return [
            'published_host_bindings() {',
            '  container=$1',
            '  port_output=$(docker port "$container" 2>/dev/null) || return 1',
            '  printf "%s\n" "$port_output" | sed -n '.escapeshellarg('s/^[^ ]* -> //p'),
            '}',
            'published_bindings() {',
            '  container=$1',
            '  private_port=$2',
            '  port_output=$(docker port "$container" 2>/dev/null) || return 1',
            '  printf "%s\n" "$port_output" | sed -n "s#^${private_port}/tcp -> ##p"',
            '}',
        ];
    }

    private function rollbackJournalPrefix(ControlPlaneProxyEnrollmentState $state): string
    {
        return implode("\n", [
            'version=2',
            'operation_id='.$state->operationId,
            'token_sha256='.$state->tokenSha256,
            'app_port='.$state->appPort,
            'exposure='.$state->exposure->value,
            'static_predecessor_sha256='.hash('sha256', $state->staticPredecessorBytes),
            'static_replacement_sha256='.hash('sha256', $state->staticReplacementBytes),
            'source_override_sha256='.hash('sha256', $state->sourceOverrideBytes),
            'orphaned_source_override_audit_sha256=',
        ]);
    }

    private function rollbackJournalSuffix(string $phase): string
    {
        return "\nphase={$phase}\n";
    }

    private function historicalEvidenceValidatorPython(): string
    {
        return <<<'PYTHON'
import hashlib
import os
import re
import stat
import sys

if len(sys.argv) != 6 or sys.argv[1] != "--validate-historical":
    raise SystemExit(64)
if not hasattr(os, "O_NOFOLLOW") or not hasattr(os, "O_NONBLOCK"):
    raise SystemExit(95)

quarantine_path = sys.argv[2]
journal_path = sys.argv[3]
operation_id = sys.argv[4]
expected_uid = int(sys.argv[5])
operation_id_bytes = operation_id.encode("ascii", "strict")
if re.fullmatch(rb"[a-z0-9][a-z0-9._-]{0,127}", operation_id_bytes) is None:
    raise SystemExit(1)

open_flags = (
    os.O_RDONLY
    | os.O_NOFOLLOW
    | getattr(os, "O_CLOEXEC", 0)
    | getattr(os, "O_NONBLOCK", 0)
)
quarantine_fd = os.open(quarantine_path, open_flags)
try:
    journal_fd = os.open(journal_path, open_flags)
    try:
        quarantine_before = os.fstat(quarantine_fd)
        journal_before = os.fstat(journal_fd)

        def validate_stat(value, *, exact_mode=None):
            if not stat.S_ISREG(value.st_mode):
                raise SystemExit(1)
            if value.st_uid != expected_uid or value.st_nlink != 1:
                raise SystemExit(1)
            permissions = stat.S_IMODE(value.st_mode)
            if exact_mode is not None:
                if permissions != exact_mode:
                    raise SystemExit(1)
            elif permissions & 0o022 or permissions & 0o600 != 0o600:
                raise SystemExit(1)

        validate_stat(quarantine_before)
        validate_stat(journal_before, exact_mode=0o600)
        if (quarantine_before.st_dev, quarantine_before.st_ino) == (
            journal_before.st_dev,
            journal_before.st_ino,
        ):
            raise SystemExit(1)

        quarantine_hash = hashlib.sha256()
        while True:
            chunk = os.read(quarantine_fd, 1024 * 1024)
            if not chunk:
                break
            quarantine_hash.update(chunk)
        quarantine_sha256 = quarantine_hash.hexdigest().encode("ascii")

        journal_bytes = bytearray()
        while True:
            chunk = os.read(journal_fd, 4096)
            if not chunk:
                break
            journal_bytes.extend(chunk)
            if len(journal_bytes) > 4096:
                raise SystemExit(1)

        pattern = (
            rb"version=2\n"
            rb"operation_id=" + re.escape(operation_id_bytes) + rb"\n"
            rb"token_sha256=[a-f0-9]{64}\n"
            rb"app_port=(?P<app_port>[1-9][0-9]{0,4})\n"
            rb"exposure=(?:public|loopback)\n"
            rb"static_predecessor_sha256=[a-f0-9]{64}\n"
            rb"static_replacement_sha256=[a-f0-9]{64}\n"
            rb"source_override_sha256=[a-f0-9]{64}\n"
            rb"orphaned_source_override_audit_sha256="
            + quarantine_sha256
            + rb"\nphase=rolled-back\n"
        )
        match = re.fullmatch(pattern, bytes(journal_bytes))
        if match is None or int(match.group("app_port")) > 65535:
            raise SystemExit(1)

        quarantine_after = os.fstat(quarantine_fd)
        journal_after = os.fstat(journal_fd)

        def stable_fingerprint(value):
            return (
                value.st_dev,
                value.st_ino,
                value.st_mode,
                value.st_uid,
                value.st_gid,
                value.st_nlink,
                value.st_size,
                value.st_mtime_ns,
                value.st_ctime_ns,
            )

        if stable_fingerprint(quarantine_before) != stable_fingerprint(quarantine_after):
            raise SystemExit(1)
        if stable_fingerprint(journal_before) != stable_fingerprint(journal_after):
            raise SystemExit(1)

        quarantine_path_stat = os.stat(quarantine_path, follow_symlinks=False)
        journal_path_stat = os.stat(journal_path, follow_symlinks=False)
        if (quarantine_path_stat.st_dev, quarantine_path_stat.st_ino) != (
            quarantine_before.st_dev,
            quarantine_before.st_ino,
        ):
            raise SystemExit(1)
        if (journal_path_stat.st_dev, journal_path_stat.st_ino) != (
            journal_before.st_dev,
            journal_before.st_ino,
        ):
            raise SystemExit(1)
    finally:
        os.close(journal_fd)
finally:
    os.close(quarantine_fd)
PYTHON;
    }

    private function renameNoReplacePython(): string
    {
        return <<<'PYTHON'
import ctypes
import errno
import os
import sys

libc = ctypes.CDLL(None, use_errno=True)
try:
    renameat2 = libc.renameat2
except AttributeError:
    raise SystemExit(95)

renameat2.argtypes = [
    ctypes.c_int,
    ctypes.c_char_p,
    ctypes.c_int,
    ctypes.c_char_p,
    ctypes.c_uint,
]
renameat2.restype = ctypes.c_int

result = renameat2(
    -100,
    os.fsencode(sys.argv[1]),
    -100,
    os.fsencode(sys.argv[2]),
    1,
)
if result == 0:
    raise SystemExit(0)

error = ctypes.get_errno()
if error == errno.EEXIST:
    raise SystemExit(17)
if error in {errno.ENOSYS, errno.EINVAL, errno.ENOTSUP, errno.EOPNOTSUPP}:
    raise SystemExit(95)
raise SystemExit(96)
PYTHON;
    }
}
