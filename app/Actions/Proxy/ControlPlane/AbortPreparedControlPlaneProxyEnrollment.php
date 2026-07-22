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

    private const PRESENT_ARTIFACT = '__COOLIFY_CONTROL_PLANE_ARTIFACT_PRESENT__';

    public string $commandSignature = 'control-plane:proxy-enrollment:abort-prepared
        {server_id : Local Coolify server ID}
        {operation_id : Exact prepared enrollment operation ID}
        {canonical_host : Exact prepared dashboard host}
        {expected_revision : Exact prepared release revision}';

    public string $commandDescription = 'Abort an unchanged local prepared enrollment whose token is unavailable.';

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
        if (! $server->isLocalhost()) {
            throw new InvalidArgumentException('A prepared control-plane enrollment can only be aborted on the local Coolify server.');
        }

        $state = $this->stateStore->read($server)
            ?? throw new RuntimeException('The durable control-plane enrollment state is missing.');
        if ($state->phase !== ControlPlaneProxyEnrollmentPhase::Prepared
            || ! hash_equals($state->operationId, $operationId)
            || ! hash_equals($state->canonicalHost, $canonicalHost)
            || ! hash_equals($state->expectedRevision, $expectedRevision)) {
            throw new RuntimeException('The prepared control-plane enrollment abort fence does not match the durable state.');
        }

        $this->assertUnchangedPreparedFilesystem($server, $state, $remoteExecutor);

        return $this->stateStore->abortPrepared(
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
        $this->assertAbsent($execute, $proxyPath.'/.control-plane-managed-traefik');

        $managedDocument = $proxyPath.'/dynamic/'.$state->managedFilename;
        if ($state->dynamicPredecessorBytes === null) {
            $this->assertAbsent($execute, $managedDocument);
        } else {
            $this->assertExactRegularFile($execute, $managedDocument, $state->dynamicPredecessorBytes);
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
