<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Models\Server;
use Closure;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class AbortPreparedControlPlaneProxyEnrollment
{
    use AsAction;

    private const ABSENT_ARTIFACT = '__COOLIFY_CONTROL_PLANE_ARTIFACT_ABSENT__';

    private const EMPTY_ARTIFACT_DIRECTORY = '__COOLIFY_CONTROL_PLANE_ARTIFACT_DIRECTORY_EMPTY__';

    private const LEGACY_RUNTIME = '__COOLIFY_CONTROL_PLANE_LEGACY_RUNTIME__';

    private const PRESENT_ARTIFACT = '__COOLIFY_CONTROL_PLANE_ARTIFACT_PRESENT__';

    private const REGULAR_ARTIFACT_DIRECTORY = '__COOLIFY_CONTROL_PLANE_ARTIFACT_DIRECTORY_REGULAR__';

    public string $commandSignature = 'control-plane:proxy-enrollment:abort-prepared
        {server_id : Local Coolify server ID}
        {operation_id : Exact prepared enrollment operation ID}
        {canonical_host : Exact prepared dashboard host}
        {expected_revision : Exact prepared release revision}';

    public string $commandDescription = 'Abort an unchanged local enrollment whose token is unavailable.';

    public function __construct(
        private readonly StoreControlPlaneProxyEnrollmentState $stateStore,
        private readonly ?string $proxyPath = null,
        private readonly string $sourceDirectory = '/data/coolify/source',
    ) {}

    public function handle(
        Server $server,
        string $operationId,
        string $canonicalHost,
        string $expectedRevision,
        ?Closure $remoteExecutor = null,
    ): ControlPlaneProxyEnrollmentState {
        return $this->stateStore->serializeOperation(
            $server,
            fn (Server $lockedServer): ControlPlaneProxyEnrollmentState => $this->handleLocked(
                $lockedServer,
                $operationId,
                $canonicalHost,
                $expectedRevision,
                $remoteExecutor,
            ),
        );
    }

    private function handleLocked(
        Server $server,
        string $operationId,
        string $canonicalHost,
        string $expectedRevision,
        ?Closure $remoteExecutor,
    ): ControlPlaneProxyEnrollmentState {
        if (! $server->isLocalhost()) {
            throw new InvalidArgumentException('A prepared control-plane enrollment can only be aborted on the local Coolify server.');
        }

        $state = $this->stateStore->read($server)
            ?? throw new RuntimeException('The durable control-plane enrollment state is missing.');
        if ($state->serverId !== (int) $server->getKey()
            || ! in_array($state->phase, [
                ControlPlaneProxyEnrollmentPhase::Prepared,
                ControlPlaneProxyEnrollmentPhase::Activating,
            ], true)
            || ! hash_equals($state->operationId, $operationId)
            || ! hash_equals($state->canonicalHost, $canonicalHost)
            || ! hash_equals($state->expectedRevision, $expectedRevision)) {
            throw new RuntimeException('The unchanged control-plane enrollment abort fence does not match the durable state.');
        }

        $this->assertUnchangedPreparedFilesystem($server, $state, $remoteExecutor);
        if ($state->phase === ControlPlaneProxyEnrollmentPhase::Activating) {
            $this->assertLegacyRuntimeOwnership($server, $state->appPort, $remoteExecutor);
        }

        return $this->stateStore->abortUnchanged(
            $server,
            $operationId,
            $canonicalHost,
            $expectedRevision,
            now()->toIso8601String(),
        );
    }

    public function asCommand(Command $command): int
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Prepared control-plane enrollment abort requires local root operator authority.');
        }
        $serverId = filter_var($command->argument('server_id'), FILTER_VALIDATE_INT);
        if ($serverId === false || $serverId < 0) {
            throw new InvalidArgumentException('The control-plane enrollment server ID must be a non-negative integer.');
        }
        $server = Server::query()->find($serverId)
            ?? throw new RuntimeException('The control-plane enrollment server does not exist.');
        $state = $this->handle(
            $server,
            (string) $command->argument('operation_id'),
            (string) $command->argument('canonical_host'),
            (string) $command->argument('expected_revision'),
        );
        $command->info("Control-plane enrollment phase: {$state->phase->value}");

        return Command::SUCCESS;
    }

    private function assertUnchangedPreparedFilesystem(
        Server $server,
        ControlPlaneProxyEnrollmentState $state,
        ?Closure $remoteExecutor,
    ): void {
        $execute = $remoteExecutor ?? static fn (string $command): ?string => instant_remote_process(
            [$command],
            $server,
            timeout: 30,
            disableMultiplexing: true,
            retry: false,
        );
        $proxyPath = rtrim($this->proxyPath ?? (string) $server->proxyPath(), '/');
        $sourceDirectory = rtrim($this->sourceDirectory, '/');
        $this->assertExactRegularFile($execute, $proxyPath.'/docker-compose.yml', $state->staticPredecessorBytes);
        $this->assertAbsent($execute, $sourceDirectory.'/docker-compose.control-plane-listener.yml');
        if ($state->phase === ControlPlaneProxyEnrollmentPhase::Activating) {
            $this->assertAbsentOrRegularDirectory($execute, $proxyPath.'/.control-plane-managed-traefik');
        } else {
            $this->assertAbsentOrEmptyDirectory($execute, $proxyPath.'/.control-plane-managed-traefik');
        }

        $managedDocument = $proxyPath.'/dynamic/'.$state->managedFilename;
        if ($state->dynamicPredecessorBytes === null) {
            $this->assertAbsent($execute, $managedDocument);
        } else {
            $this->assertExactRegularFile($execute, $managedDocument, $state->dynamicPredecessorBytes);
        }
    }

    private function assertLegacyRuntimeOwnership(
        Server $server,
        int $appPort,
        ?Closure $remoteExecutor,
    ): void {
        $execute = $remoteExecutor ?? static fn (string $command): ?string => instant_remote_process(
            [$command],
            $server,
            timeout: 30,
            disableMultiplexing: true,
            retry: false,
        );
        $expectedBinding = '0.0.0.0:'.$appPort;
        $publicIpv6Binding = '[::]:'.$appPort;
        $output = $execute(implode("\n", [
            'set -eu',
            'round=1',
            'while [ "$round" -le 2 ]; do',
            '  [ "$(docker inspect --type container --format '.escapeshellarg('{{.State.Running}}').' coolify)" = true ]',
            '  [ "$(docker inspect --type container --format '.escapeshellarg('{{.State.Running}}').' coolify-proxy)" = true ]',
            '  coolify_bindings=$(docker port coolify 8080/tcp)',
            '  printf "%s\n" "$coolify_bindings" | grep -Fx '.escapeshellarg($expectedBinding).' >/dev/null',
            '  unexpected_bindings=$(printf "%s\n" "$coolify_bindings" | grep -Fvx '.escapeshellarg($expectedBinding).' | grep -Fvx '.escapeshellarg($publicIpv6Binding).' || true)',
            '  [ -z "$unexpected_bindings" ]',
            '  proxy_ports=$(docker port coolify-proxy 2>/dev/null)',
            '  proxy_bindings=$(printf "%s\n" "$proxy_ports" | sed -n '.escapeshellarg('s#^'.$appPort.'/tcp -> ##p').')',
            '  [ -z "$proxy_bindings" ]',
            '  owner_count=0',
            '  owner=',
            '  candidates=$(docker ps --format '.escapeshellarg('{{.Names}}').')',
            '  for candidate in $candidates; do',
            '    candidate_ports=$(docker port "$candidate" 2>/dev/null)',
            '    candidate_bindings=$(printf "%s\n" "$candidate_ports" | sed -n '.escapeshellarg('s/^[^ ]* -> //p').')',
            '    if printf "%s\n" "$candidate_bindings" | grep -Fx '.escapeshellarg($expectedBinding).' >/dev/null '
                .'|| printf "%s\n" "$candidate_bindings" | grep -Fx '.escapeshellarg($publicIpv6Binding).' >/dev/null; then',
            '      owner_count=$((owner_count + 1))',
            '      owner=$candidate',
            '    fi',
            '  done',
            '  if [ "$owner_count" != 1 ] || [ "$owner" != coolify ]; then exit 1; fi',
            '  [ "$round" = 2 ] || sleep 1',
            '  round=$((round + 1))',
            'done',
            'printf '.escapeshellarg(self::LEGACY_RUNTIME."\n"),
        ]));
        if (! in_array($output, [self::LEGACY_RUNTIME, self::LEGACY_RUNTIME."\n"], true)) {
            throw new RuntimeException('The activating control-plane enrollment no longer has exact legacy runtime ownership.');
        }
    }

    private function assertExactRegularFile(Closure $execute, string $path, string $expectedBytes): void
    {
        if ($this->readArtifact($execute, $path) !== $expectedBytes) {
            throw new RuntimeException("Prepared control-plane enrollment artifact changed: {$path}");
        }
    }

    private function assertAbsent(Closure $execute, string $path): void
    {
        if ($this->readArtifact($execute, $path) !== null) {
            throw new RuntimeException("Prepared control-plane enrollment artifact is no longer absent: {$path}");
        }
    }

    private function assertAbsentOrEmptyDirectory(Closure $execute, string $path): void
    {
        $pathArgument = escapeshellarg($path);
        $output = $execute(
            'if [ ! -e '.$pathArgument.' ] && [ ! -L '.$pathArgument.' ]; then '
            .'printf '.escapeshellarg(self::ABSENT_ARTIFACT."\n").'; '
            .'elif [ -d '.$pathArgument.' ] && [ ! -L '.$pathArgument.' ]; then '
            .'entries=$(find '.$pathArgument.' -mindepth 1 -maxdepth 1 -print -quit) || exit 1; '
            .'[ -z "$entries" ] || exit 1; '
            .'printf '.escapeshellarg(self::EMPTY_ARTIFACT_DIRECTORY."\n").'; '
            .'else exit 1; fi',
        );
        if (! in_array($output, [
            self::ABSENT_ARTIFACT,
            self::ABSENT_ARTIFACT."\n",
            self::EMPTY_ARTIFACT_DIRECTORY,
            self::EMPTY_ARTIFACT_DIRECTORY."\n",
        ], true)) {
            throw new RuntimeException("Prepared control-plane enrollment artifact is not absent or empty: {$path}");
        }
    }

    private function assertAbsentOrRegularDirectory(Closure $execute, string $path): void
    {
        $pathArgument = escapeshellarg($path);
        $output = $execute(
            'if [ ! -e '.$pathArgument.' ] && [ ! -L '.$pathArgument.' ]; then '
            .'printf '.escapeshellarg(self::ABSENT_ARTIFACT."\n").'; '
            .'elif [ -d '.$pathArgument.' ] && [ ! -L '.$pathArgument.' ]; then '
            .'owner_uid=$(if stat -c "%u" '.$pathArgument.' >/dev/null 2>&1; then stat -c "%u" '.$pathArgument.'; else stat -f "%u" '.$pathArgument.'; fi) || exit 1; '
            .'[ "$owner_uid" = 0 ] || [ "$owner_uid" = 9999 ] || [ "$owner_uid" = "$(id -u)" ] || exit 1; '
            .'permissions=$(if stat -c "%a" '.$pathArgument.' >/dev/null 2>&1; then stat -c "%a" '.$pathArgument.'; else stat -f "%Lp" '.$pathArgument.'; fi) || exit 1; '
            .'case "$permissions" in ???|????) ;; *) exit 1 ;; esac; '
            .'case "$permissions" in *[!0-7]*) exit 1 ;; esac; '
            .'other_permissions=${permissions#"${permissions%?}"}; owner_group_permissions=${permissions%?}; '
            .'group_permissions=${owner_group_permissions#"${owner_group_permissions%?}"}; '
            .'case "${group_permissions}${other_permissions}" in *[2367]*) exit 1 ;; esac; '
            .'printf '.escapeshellarg(self::REGULAR_ARTIFACT_DIRECTORY."\n").'; '
            .'else exit 1; fi',
        );
        if (! in_array($output, [
            self::ABSENT_ARTIFACT,
            self::ABSENT_ARTIFACT."\n",
            self::REGULAR_ARTIFACT_DIRECTORY,
            self::REGULAR_ARTIFACT_DIRECTORY."\n",
        ], true)) {
            throw new RuntimeException("Prepared control-plane enrollment artifact is not an absent or regular directory: {$path}");
        }
    }

    private function readArtifact(Closure $execute, string $path): ?string
    {
        $pathArgument = escapeshellarg($path);
        $output = $execute(
            'if [ ! -e '.$pathArgument.' ] && [ ! -L '.$pathArgument.' ]; then '
            .'printf '.escapeshellarg(self::ABSENT_ARTIFACT."\n").'; '
            .'elif [ -f '.$pathArgument.' ] && [ ! -L '.$pathArgument.' ]; then '
            .'printf '.escapeshellarg(self::PRESENT_ARTIFACT."\n").'; base64 < '.$pathArgument.'; '
            .'else exit 1; fi',
        );
        if ($output === self::ABSENT_ARTIFACT."\n" || $output === self::ABSENT_ARTIFACT) {
            return null;
        }
        $prefix = self::PRESENT_ARTIFACT."\n";
        if (! is_string($output) || ! str_starts_with($output, $prefix)) {
            throw new RuntimeException("Prepared control-plane enrollment artifact could not be inspected: {$path}");
        }

        $bytes = base64_decode(trim(substr($output, strlen($prefix))), true);
        if ($bytes === false) {
            throw new RuntimeException("Prepared control-plane enrollment artifact could not be inspected: {$path}");
        }

        return $bytes;
    }
}
