<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Models\Application;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

class ReconcileBlueGreenIdleRoutes
{
    use AsAction;

    public function handle(
        Application $application,
        StandaloneDocker $destination,
    ): BlueGreenIdleRouteReconciliationOutcome {
        $application = Application::withTrashed()->with('settings')->find($application->id);
        $destination = StandaloneDocker::query()->with('server')->find($destination->id);
        if ($application === null
            || $application->trashed()
            || $destination === null
            || $destination->server === null) {
            throw new BlueGreenDeploymentTransitionException('The idle blue-green route no longer has a live application destination and server.');
        }
        $server = $destination->server;
        $leaseSeconds = BlueGreenDeploymentLock::deactivationLeaseSeconds();
        $lifecycleLock = Cache::lock(
            BlueGreenDeploymentLock::key((int) $application->id, (int) $destination->id),
            $leaseSeconds,
        );
        if (! $lifecycleLock->get()) {
            return BlueGreenIdleRouteReconciliationOutcome::Busy;
        }

        $operationFence = new BlueGreenOperationFence($lifecycleLock, $leaseSeconds);
        try {
            $expectedState = $this->captureExpectedState($application, $destination);
            $application = Application::withTrashed()->with('settings')->find($application->id);
            $destination = StandaloneDocker::query()->with('server')->find($destination->id);
            if ($application === null
                || $application->trashed()
                || $destination === null
                || $destination->server === null) {
                throw new BlueGreenDeploymentTransitionException('The idle blue-green route topology changed before remote repair.');
            }
            $server = $destination->server;
            $configuration = $this->configurationFor($application, $destination, $expectedState);
            $operationFence->assertLockOwnership();
            $this->assertHealthyActiveContainer($server, $application, $expectedState);
            $operationFence->assertLockOwnership();
            $expectedBootId = $this->readServerBootIdentity($server);
            $operationFence->assertLockOwnership();
            (new RecordBlueGreenIdleRouteReconciliation)->assertExpectedState(
                $application,
                $destination,
                $expectedState,
            );
            $operationFence->assertLockOwnership();
            $output = $this->repairRemoteRoute($server, $configuration, $expectedState, $expectedBootId);
            $outcome = match (trim($output)) {
                WriteBlueGreenProxyConfiguration::IDLE_ROUTE_UNCHANGED_OUTPUT => BlueGreenIdleRouteReconciliationOutcome::Unchanged,
                WriteBlueGreenProxyConfiguration::IDLE_ROUTE_REPAIRED_OUTPUT => BlueGreenIdleRouteReconciliationOutcome::Repaired,
                default => throw new BlueGreenDeploymentTransitionException('The idle route repair did not attest its exact completion.'),
            };
            $operationFence->assertLockOwnership();
            $this->recordReconciliation($application, $destination, $expectedState);

            if ($outcome === BlueGreenIdleRouteReconciliationOutcome::Repaired) {
                Log::info('Repaired exact idle blue-green route.', [
                    'application_id' => (int) $application->id,
                    'destination_id' => (int) $destination->id,
                    'server_id' => (int) $server->id,
                ]);
            }

            return $outcome;
        } finally {
            try {
                $operationFence->releaseIfOwned();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    protected function captureExpectedState(
        Application $application,
        StandaloneDocker $destination,
    ): BlueGreenProxyState {
        return (new RecordBlueGreenIdleRouteReconciliation)->captureExpectedState($application, $destination);
    }

    protected function configurationFor(
        Application $application,
        StandaloneDocker $destination,
        BlueGreenProxyState $expectedState,
    ): BlueGreenProxyConfiguration {
        $activeColor = $expectedState->activeColor;
        $activeDeploymentUuid = $expectedState->activeDeploymentUuid;
        $activeContainerId = $expectedState->activeContainerId;
        $port = $application->blueGreenDeploymentBackendPort($application->settings);
        if ($activeColor === null
            || $activeDeploymentUuid === null
            || $activeContainerId === null
            || $expectedState->managedSha256 === null
            || $port === null) {
            throw new BlueGreenDeploymentTransitionException('The idle blue-green route does not have complete canonical configuration provenance.');
        }

        $configuration = CompileBlueGreenProxyConfiguration::run(
            $application,
            $destination,
            new BlueGreenRoutingTarget(
                destinationId: (int) $destination->id,
                activeColor: $activeColor,
                blueContainerName: $application->uuid.'-'.BlueGreenDeploymentColor::BLUE->value,
                greenContainerName: $application->uuid.'-'.BlueGreenDeploymentColor::GREEN->value,
                port: $port,
                routingRevision: $expectedState->routingRevision,
                publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($expectedState->operationId),
                destinationFenceEpoch: $expectedState->destinationFenceEpoch,
                operationId: $expectedState->operationId,
                mutationSequence: $expectedState->mutationSequence,
                activeDeploymentUuid: $activeDeploymentUuid,
                activeContainerId: $activeContainerId,
                destinationTopologyDigest: $expectedState->destinationTopologyDigest,
            ),
        );
        if ($configuration->state->serialize() !== $expectedState->serialize()
            || ! hash_equals($configuration->sha256, $expectedState->managedSha256)
            || ! hash_equals($configuration->routingConfigDigest, $expectedState->applicationRoutingConfigDigest)) {
            throw new BlueGreenDeploymentTransitionException('The canonical idle blue-green route does not match durable route provenance.');
        }

        return $configuration;
    }

    protected function inspectActiveContainer(
        Server $server,
        Application $application,
        BlueGreenProxyState $expectedState,
    ): BlueGreenContainerInspection {
        $expectation = new BlueGreenContainerExpectation(
            name: $expectedState->activeContainerName
                ?? throw new BlueGreenDeploymentTransitionException('The idle blue-green route has no active container name.'),
            dockerId: $expectedState->activeContainerId,
            applicationId: (int) $application->id,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $expectedState->activeDeploymentUuid,
            color: $expectedState->activeColor,
            routingRevision: $expectedState->routingRevision,
        );
        $inspection = new InspectBlueGreenContainer;

        return $inspection->parse(
            trim((string) instant_remote_process([
                $inspection->commandFor($expectation->dockerId ?? $expectation->name),
            ], $server, timeout: BlueGreenDeploymentLock::deactivationRemoteTimeoutSeconds(), retry: false)),
            $expectation,
        );
    }

    protected function readServerBootIdentity(Server $server): string
    {
        $bootIdentity = new ReadBlueGreenServerBootIdentity;

        return $bootIdentity->fromRemoteOutput((string) instant_remote_process([
            $bootIdentity->commandFor(),
        ], $server, timeout: BlueGreenDeploymentLock::deactivationRemoteTimeoutSeconds(), retry: false));
    }

    protected function repairRemoteRoute(
        Server $server,
        BlueGreenProxyConfiguration $configuration,
        BlueGreenProxyState $expectedState,
        string $expectedBootId,
    ): string {
        return (string) instant_remote_process([
            (new WriteBlueGreenProxyConfiguration)->repairExactStateCommandFor(
                $server->proxyPath(),
                $configuration,
                $expectedState,
                $expectedBootId,
            ),
        ], $server, timeout: BlueGreenDeploymentLock::deactivationRemoteTimeoutSeconds(), retry: false);
    }

    protected function recordReconciliation(
        Application $application,
        StandaloneDocker $destination,
        BlueGreenProxyState $expectedState,
    ): void {
        RecordBlueGreenIdleRouteReconciliation::run($application, $destination, $expectedState);
    }

    private function assertHealthyActiveContainer(
        Server $server,
        Application $application,
        BlueGreenProxyState $expectedState,
    ): void {
        $inspection = $this->inspectActiveContainer($server, $application, $expectedState);
        if (! $inspection->exists
            || $inspection->dockerId !== $expectedState->activeContainerId
            || $inspection->status !== 'running'
            || $inspection->health !== 'healthy') {
            throw new BlueGreenDeploymentTransitionException('The idle blue-green route active container is not the exact healthy durable target.');
        }
    }
}
