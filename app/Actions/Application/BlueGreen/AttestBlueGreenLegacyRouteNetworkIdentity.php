<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenActiveContainer;
use App\Actions\Proxy\BlueGreenActiveReplica;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Models\Application;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Support\ValidationPatterns;
use Closure;
use Illuminate\Support\Facades\DB;
use JsonException;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Proves that every container named by an attested legacy route is attached to
 * the destination network whose routing-only digest is about to be established.
 */
final class AttestBlueGreenLegacyRouteNetworkIdentity
{
    use AsAction;

    private const PROOF_PREFIX = 'coolify-blue-green-route-network-proof:';

    public function handle(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        BlueGreenProxyState $routeState,
        string $expectedServerBootId,
        BlueGreenOperationFence $operationFence,
        ?BlueGreenProxyState $containerIdentityState = null,
    ): BlueGreenLegacyRouteNetworkAttestation {
        return $this->attest(
            $server,
            $application,
            $destination,
            $routeState,
            $expectedServerBootId,
            $operationFence,
            $containerIdentityState,
            function () use ($server, $application, $destination, $routeState): void {
                $liveState = ReadBlueGreenManagedRouteMetadata::run($server, $application, $destination);
                $this->assertExactRouteState($routeState, $liveState);
            },
        );
    }

    /**
     * Reuses the canonical network proof while the exact retirement owner has
     * a pending journal that intentionally fences the strict sidecar reader.
     */
    public function handlePendingInactiveRetirementJournal(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        BlueGreenProxyState $routeState,
        string $expectedServerBootId,
        BlueGreenOperationFence $operationFence,
        string $ownerDeploymentUuid,
        ?BlueGreenProxyState $containerIdentityState = null,
    ): BlueGreenLegacyRouteNetworkAttestation {
        return $this->attest(
            $server,
            $application,
            $destination,
            $routeState,
            $expectedServerBootId,
            $operationFence,
            $containerIdentityState,
            function () use (
                $server,
                $application,
                $destination,
                $expectedServerBootId,
                $ownerDeploymentUuid,
                $routeState,
            ): void {
                $this->assertExactPendingInactiveRetirementJournalState(
                    $server,
                    $application,
                    $destination,
                    $routeState,
                    $expectedServerBootId,
                    $ownerDeploymentUuid,
                );
            },
        );
    }

    /** @param Closure(): void $assertRouteState */
    private function attest(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        BlueGreenProxyState $routeState,
        string $expectedServerBootId,
        BlueGreenOperationFence $operationFence,
        ?BlueGreenProxyState $containerIdentityState,
        Closure $assertRouteState,
    ): BlueGreenLegacyRouteNetworkAttestation {
        if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() !== 0) {
            throw new BlueGreenDeploymentTransitionException(
                'Legacy route network proof must run outside PostgreSQL transactions and topology advisory locks.',
            );
        }
        if ((int) $routeState->destinationId !== (int) $destination->id
            || (int) $application->id < 1
            || (string) $routeState->applicationUuid !== (string) $application->uuid
            || (int) $destination->server_id !== (int) $server->id
            || ! is_string($destination->network)
            || ! ValidationPatterns::isValidDockerNetwork($destination->network)) {
            throw new BlueGreenDeploymentTransitionException('The legacy route has no exact valid destination network identity.');
        }
        $containerIdentityState ??= $routeState;
        $this->assertSameManagedRoute($routeState, $containerIdentityState);
        $containers = $this->activeContainers($containerIdentityState);
        $bootAssertion = (new ReadBlueGreenServerBootIdentity)->assertionCommandFor($expectedServerBootId);
        $script = ['set -eu', $bootAssertion];
        foreach ($containers as $container) {
            $containerId = escapeshellarg($container['id']);
            $script[] = 'test "$(docker inspect --format='.escapeshellarg('{{.Id}}').' '.$containerId.')" = '.$containerId;
            $script[] = 'test "$(docker inspect --format='.escapeshellarg('{{.Name}}').' '.$containerId.')" = '.escapeshellarg('/'.$container['name']);
            $script[] = "printf '%s\\t' ".escapeshellarg(self::PROOF_PREFIX.$container['id']);
            $script[] = 'docker inspect --format='.escapeshellarg('{{json .NetworkSettings.Networks}}').' '.$containerId;
        }
        $script[] = $bootAssertion;

        $operationFence->assertLockOwnership();
        ReadBlueGreenServerBootIdentity::run($server, $expectedServerBootId);
        $assertRouteState();
        try {
            $output = instant_privileged_remote_script(
                implode("\n", $script),
                $server,
                retry: false,
            );
        } catch (Throwable $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The legacy route active-container network identity could not be proven exactly.',
                previous: $exception,
            );
        }

        $lines = is_string($output) && $output !== ''
            ? preg_split('/\R/D', $output)
            : false;
        if (! is_array($lines) || count($lines) !== count($containers)) {
            throw new BlueGreenDeploymentTransitionException('The legacy route active-container network proof was missing or ambiguous.');
        }
        foreach ($containers as $index => $container) {
            $prefix = self::PROOF_PREFIX.$container['id']."\t";
            $line = $lines[$index] ?? null;
            if (! is_string($line) || ! str_starts_with($line, $prefix)) {
                throw new BlueGreenDeploymentTransitionException('The legacy route active-container network proof did not name its exact Docker identity.');
            }
            try {
                $networks = json_decode(substr($line, strlen($prefix)), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new BlueGreenDeploymentTransitionException(
                    'The legacy route active-container network proof was malformed.',
                    previous: $exception,
                );
            }
            if (! is_array($networks)
                || ! array_key_exists($destination->network, $networks)
                || ! is_array($networks[$destination->network])) {
                throw new BlueGreenDeploymentTransitionException(
                    'The legacy active route is not bound to the current destination network; historical destination network drift must be reconciled before routing topology rehydration.',
                );
            }
        }
        $assertRouteState();
        ReadBlueGreenServerBootIdentity::run($server, $expectedServerBootId);
        $operationFence->assertLockOwnership();

        return new BlueGreenLegacyRouteNetworkAttestation(
            applicationId: (int) $application->id,
            applicationUuid: (string) $application->uuid,
            serverId: (int) $server->id,
            serverUuid: (string) $server->uuid,
            serverConnectionDigest: BlueGreenLegacyRouteNetworkAttestation::connectionDigestFor($server),
            destinationId: (int) $destination->id,
            destinationNetwork: (string) $destination->network,
            serverBootId: $expectedServerBootId,
            routingTopologyDigest: (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor(
                $application,
                $destination,
            ),
            routeState: $routeState->serialize(),
        );
    }

    private function assertExactPendingInactiveRetirementJournalState(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        BlueGreenProxyState $routeState,
        string $expectedServerBootId,
        string $ownerDeploymentUuid,
    ): void {
        $inspection = ReadBlueGreenManagedRouteMetadataForOperation::run(
            $server,
            $application,
            $destination,
            $ownerDeploymentUuid,
        );
        if (! $inspection->hasPendingExpectedSidecar()
            || ! is_string($inspection->journalBootId)
            || ! hash_equals($expectedServerBootId, $inspection->journalBootId)
            || ! BlueGreenProxyState::matches($inspection->expectedState, $routeState)
            || $inspection->replacementState === null
            || ! BlueGreenProxyState::matches(
                $inspection->replacementState,
                $routeState->withMutationOwner($ownerDeploymentUuid),
            )) {
            throw new BlueGreenDeploymentTransitionException('The pending inactive-retirement journal changed during legacy route network attestation.');
        }
    }

    private function assertExactRouteState(BlueGreenProxyState $expected, ?BlueGreenProxyState $actual): void
    {
        if (! BlueGreenProxyState::matches($actual, $expected)) {
            throw new BlueGreenDeploymentTransitionException(
                'The live managed route changed while its legacy network identity was being attested.',
            );
        }
    }

    private function assertSameManagedRoute(
        BlueGreenProxyState $routeState,
        BlueGreenProxyState $containerIdentityState,
    ): void {
        if ($routeState->managedFilename !== $containerIdentityState->managedFilename
            || $routeState->applicationUuid !== $containerIdentityState->applicationUuid
            || $routeState->destinationId !== $containerIdentityState->destinationId
            || $routeState->operationId !== $containerIdentityState->operationId
            || $routeState->mutationSequence !== $containerIdentityState->mutationSequence
            || $routeState->destinationFenceEpoch !== $containerIdentityState->destinationFenceEpoch
            || $routeState->routingRevision !== $containerIdentityState->routingRevision
            || $routeState->managedSha256 !== $containerIdentityState->managedSha256
            || $routeState->activeColor !== $containerIdentityState->activeColor
            || $routeState->activeDeploymentUuid !== $containerIdentityState->activeDeploymentUuid
            || $routeState->applicationRoutingConfigDigest !== $containerIdentityState->applicationRoutingConfigDigest
            || $routeState->destinationTopologyDigest !== $containerIdentityState->destinationTopologyDigest) {
            throw new BlueGreenDeploymentTransitionException(
                'The legacy route container identities do not belong to the exact attested managed route.',
            );
        }
    }

    /** @return non-empty-list<array{name: string, id: string}> */
    private function activeContainers(BlueGreenProxyState $routeState): array
    {
        if ($routeState->managedSha256 === null
            || $routeState->activeContainerName === null
            || $routeState->activeContainerId === null) {
            throw new BlueGreenDeploymentTransitionException('The legacy managed route has no exact active container identity for network attestation.');
        }
        $containers = $routeState->activeReplicaSet !== null
            ? array_map(
                static fn (BlueGreenActiveReplica $member): array => ['name' => $member->name, 'id' => $member->id],
                $routeState->activeReplicaSet->members,
            )
            : ($routeState->activeContainerSet === null
                ? [[
                    'name' => $routeState->activeContainerName,
                    'id' => $routeState->activeContainerId,
                ]]
                : array_map(
                    static fn (BlueGreenActiveContainer $member): array => ['name' => $member->name, 'id' => $member->id],
                    $routeState->activeContainerSet->members,
                ));
        $names = array_column($containers, 'name');
        $ids = array_column($containers, 'id');
        if (count(array_unique($names)) !== count($names)
            || count(array_unique($ids)) !== count($ids)) {
            throw new BlueGreenDeploymentTransitionException('The legacy managed route active-container set is ambiguous.');
        }
        foreach ($containers as $container) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $container['name']) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $container['id']) !== 1) {
                throw new BlueGreenDeploymentTransitionException('The legacy managed route has no exact Docker container identity for network attestation.');
            }
        }

        return $containers;
    }
}
