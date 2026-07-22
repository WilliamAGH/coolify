<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Models\Server;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class AbortPreparedControlPlaneProxyEnrollment
{
    use AsAction;

    public string $commandSignature = 'control-plane:proxy-enrollment:abort-prepared
        {server_id : Local Coolify server ID}
        {operation_id : Exact prepared enrollment operation ID}
        {canonical_host : Exact prepared dashboard host}
        {expected_revision : Exact prepared release revision}';

    public string $commandDescription = 'Abort an unchanged local prepared enrollment whose token is unavailable.';

    public function __construct(
        private readonly StoreControlPlaneProxyEnrollmentState $stateStore,
        private readonly string $proxyPath = '/data/coolify/proxy',
        private readonly string $sourceDirectory = '/data/coolify/source',
    ) {}

    public function handle(
        Server $server,
        string $operationId,
        string $canonicalHost,
        string $expectedRevision,
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

        $this->assertUnchangedPreparedFilesystem($state);

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

    private function assertUnchangedPreparedFilesystem(ControlPlaneProxyEnrollmentState $state): void
    {
        $proxyPath = rtrim($this->proxyPath, '/');
        $sourceDirectory = rtrim($this->sourceDirectory, '/');
        $this->assertExactRegularFile($proxyPath.'/docker-compose.yml', $state->staticPredecessorBytes);
        $this->assertAbsent($sourceDirectory.'/docker-compose.control-plane-listener.yml');
        $this->assertAbsent($proxyPath.'/.control-plane-managed-traefik');

        $managedDocument = $proxyPath.'/dynamic/'.$state->managedFilename;
        if ($state->dynamicPredecessorBytes === null) {
            $this->assertAbsent($managedDocument);
        } else {
            $this->assertExactRegularFile($managedDocument, $state->dynamicPredecessorBytes);
        }
    }

    private function assertExactRegularFile(string $path, string $expectedBytes): void
    {
        if (! is_file($path) || is_link($path) || file_get_contents($path) !== $expectedBytes) {
            throw new RuntimeException("Prepared control-plane enrollment artifact changed: {$path}");
        }
    }

    private function assertAbsent(string $path): void
    {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException("Prepared control-plane enrollment artifact is no longer absent: {$path}");
        }
    }
}
