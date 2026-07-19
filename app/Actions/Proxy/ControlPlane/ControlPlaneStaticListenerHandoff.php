<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class ControlPlaneStaticListenerHandoff
{
    use AsAction;

    public const APPLIED_OUTPUT = 'coolify-control-plane-static-listener:active';

    public function __construct(
        private string $proxyComposePath = '/data/coolify/proxy/docker-compose.yml',
        private string $sourceComposePath = '/data/coolify/source/docker-compose.yml',
        private string $sourceProductionComposePath = '/data/coolify/source/docker-compose.prod.yml',
        private string $sourceOverridePath = '/data/coolify/source/docker-compose.control-plane-listener.yml',
        private string $sourceEnvironmentPath = '/data/coolify/source/.env',
        ?string $sourceCustomComposePath = null,
        ?string $sourcePostgresUpgradeComposePath = null,
        ?string $enrollmentLockPath = null,
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
    }

    private string $enrollmentLockPath;

    private string $sourceCustomComposePath;

    private string $sourcePostgresUpgradeComposePath;

    public function commandFor(ControlPlaneProxyEnrollmentState $state): string
    {
        $expectedProxyBinding = match ($state->exposure) {
            ControlPlaneProxyExposure::Public => '0.0.0.0:'.$state->appPort,
            ControlPlaneProxyExposure::Loopback => '127.0.0.1:'.$state->appPort,
        };

        return implode("\n", [
            'set -eu',
            'umask 077',
            'proxy_compose_path='.escapeshellarg($this->proxyComposePath),
            'proxy_directory='.escapeshellarg(dirname($this->proxyComposePath)),
            'source_compose_path='.escapeshellarg($this->sourceComposePath),
            'source_production_compose_path='.escapeshellarg($this->sourceProductionComposePath),
            'source_override_path='.escapeshellarg($this->sourceOverridePath),
            'source_environment_path='.escapeshellarg($this->sourceEnvironmentPath),
            'source_custom_compose_path='.escapeshellarg($this->sourceCustomComposePath),
            'source_postgres_upgrade_compose_path='.escapeshellarg($this->sourcePostgresUpgradeComposePath),
            'source_directory='.escapeshellarg(dirname($this->sourceComposePath)),
            'enrollment_lock_path='.escapeshellarg($this->enrollmentLockPath),
            'expected_proxy_binding='.escapeshellarg($expectedProxyBinding),
            'legacy_proxy_binding='.escapeshellarg('0.0.0.0:'.$state->appPort),
            'public_ipv6_binding='.escapeshellarg('[::]:'.$state->appPort),
            'predecessor_proxy_base64='.escapeshellarg(base64_encode($state->staticPredecessorBytes)),
            'replacement_proxy_base64='.escapeshellarg(base64_encode($state->staticReplacementBytes)),
            'source_override_base64='.escapeshellarg(base64_encode($state->sourceOverrideBytes)),
            'predecessor_proxy_sha256='.escapeshellarg(hash('sha256', $state->staticPredecessorBytes)),
            'replacement_proxy_sha256='.escapeshellarg(hash('sha256', $state->staticReplacementBytes)),
            'source_override_sha256='.escapeshellarg(hash('sha256', $state->sourceOverrideBytes)),
            'applied_output='.escapeshellarg(self::APPLIED_OUTPUT),
            '',
            'fail() { exit 1; }',
            'assert_directory() { test -d "$1" && test ! -L "$1" || fail; }',
            'assert_regular() { test -e "$1" && test ! -L "$1" && test -f "$1" || fail; }',
            'assert_regular_or_absent() {',
            '  if [ -e "$1" ] || [ -L "$1" ]; then assert_regular "$1"; fi',
            '}',
            'is_absent() { test ! -e "$1" && test ! -L "$1"; }',
            'matches() { assert_regular "$1"; cmp -s "$1" "$2"; }',
            'matches_if_present() { is_absent "$1" && return 1; matches "$1" "$2"; }',
            'checksum_matches() {',
            '  checksum=$(sha256sum "$1")',
            '  test "${checksum%% *}" = "$2"',
            '}',
            'atomic_replace() {',
            '  target=$1',
            '  source=$2',
            '  target_directory=$3',
            '  stage=$(mktemp "$target_directory/.control-plane-listener.XXXXXX") || fail',
            '  cp "$source" "$stage" || fail',
            '  chmod 600 "$stage" || fail',
            '  sync -f "$stage" || fail',
            '  mv -f "$stage" "$target" || fail',
            '  sync -f "$target_directory" || fail',
            '}',
            'atomic_remove() {',
            '  target=$1',
            '  target_directory=$2',
            '  assert_regular_or_absent "$target"',
            '  rm -f "$target" || fail',
            '  sync -f "$target_directory" || fail',
            '}',
            'coolify_compose_arguments() {',
            '  printf "%s\n" --project-directory "$source_directory" --env-file "$source_environment_path" -f "$source_compose_path" -f "$source_production_compose_path"',
            '  if [ -e "$source_custom_compose_path" ] || [ -L "$source_custom_compose_path" ]; then assert_regular "$source_custom_compose_path"; printf "%s\n" -f "$source_custom_compose_path"; fi',
            '  if [ -e "$source_postgres_upgrade_compose_path" ] || [ -L "$source_postgres_upgrade_compose_path" ]; then assert_regular "$source_postgres_upgrade_compose_path"; printf "%s\n" -f "$source_postgres_upgrade_compose_path"; fi',
            '}',
            'recreate_enrolled_coolify() {',
            '  set --',
            '  while IFS= read -r compose_argument; do set -- "$@" "$compose_argument"; done <<EOF',
            '$(coolify_compose_arguments)',
            'EOF',
            '  docker compose "$@" -f "$source_override_path" up -d --force-recreate --no-deps coolify',
            '}',
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
            'verify_port_owner() {',
            '  expected_binding=$1',
            '  expected_owner=$2',
            '  port_owner_count=0',
            '  port_owner=',
            '  docker ps --format "{{.Names}}" > "$scratch/containers" || fail',
            '  while IFS= read -r candidate; do',
            '    test -n "$candidate" || continue',
            '    candidate_bindings=$(docker port "$candidate" 2>/dev/null || true)',
            '    if printf "%s\\n" "$candidate_bindings" | grep -Fx "$expected_binding" >/dev/null; then',
            '      port_owner_count=$((port_owner_count + 1))',
            '      port_owner=$candidate',
            '    fi',
            '  done < "$scratch/containers"',
            '  test "$port_owner_count" = 1 && test "$port_owner" = "$expected_owner" || fail',
            '}',
            'verify_enrolled() {',
            '  docker inspect --type container coolify >/dev/null || fail',
            '  docker inspect --type container coolify-proxy >/dev/null || fail',
            '  coolify_bindings=$(docker port coolify 8080/tcp 2>/dev/null || true)',
            '  test -z "$coolify_bindings" || fail',
            '  proxy_bindings=$(docker port coolify-proxy 8000/tcp) || fail',
            '  printf "%s\\n" "$proxy_bindings" | grep -Fx "$expected_proxy_binding" >/dev/null || fail',
            '  if [ "$expected_proxy_binding" = "$legacy_proxy_binding" ]; then',
            '    unexpected_proxy_bindings=$(printf "%s\\n" "$proxy_bindings" | grep -Fvx "$expected_proxy_binding" | grep -Fvx "$public_ipv6_binding" || true)',
            '  else',
            '    unexpected_proxy_bindings=$(printf "%s\\n" "$proxy_bindings" | grep -Fvx "$expected_proxy_binding" || true)',
            '  fi',
            '  test -z "$unexpected_proxy_bindings" || fail',
            '  verify_port_owner "$expected_proxy_binding" coolify-proxy',
            '}',
            'verify_legacy() {',
            '  docker inspect --type container coolify >/dev/null || fail',
            '  docker inspect --type container coolify-proxy >/dev/null || fail',
            '  coolify_bindings=$(docker port coolify 8080/tcp) || fail',
            '  printf "%s\\n" "$coolify_bindings" | grep -Fx "$legacy_proxy_binding" >/dev/null || fail',
            '  proxy_bindings=$(docker port coolify-proxy 8000/tcp 2>/dev/null || true)',
            '  test -z "$proxy_bindings" || fail',
            '  verify_port_owner "$legacy_proxy_binding" coolify',
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
            '  verify_enrolled',
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
            'completed=1',
            'printf %s "$applied_output"',
        ]);
    }

    private function assertAbsolutePath(string $path, string $label): void
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new InvalidArgumentException("The control-plane {$label} must be an absolute NUL-free path.");
        }
    }
}
