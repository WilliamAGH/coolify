<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Models\Application;
use App\Models\StandaloneDocker;
use JsonException;
use Lorisleiva\Actions\Concerns\AsAction;

final class ComputeBlueGreenDeploymentFingerprint
{
    use AsAction;

    public function handle(
        Application $application,
        StandaloneDocker $destination,
        BlueGreenDeploymentColor $pendingColor,
        int $routingRevision,
        int $destinationFenceEpoch,
        string $operationId,
        bool $legacyAdoption = false,
    ): BlueGreenDeploymentFingerprint {
        $topologyDigest = $this->topologyDigest($application, $destination);
        $application->loadMissing('settings');
        $ports = $application->blueGreenDeploymentBackendPorts($application->settings)
            ?? throw new BlueGreenDeploymentTransitionException('The blue-green application has no exact backend port inventory.');
        $configuration = CompileBlueGreenProxyConfiguration::run(
            $application,
            $destination,
            new BlueGreenRoutingTarget(
                destinationId: (int) $destination->id,
                activeColor: $pendingColor,
                blueContainerName: $application->uuid.'-'.BlueGreenDeploymentColor::BLUE->value,
                greenContainerName: $application->uuid.'-'.BlueGreenDeploymentColor::GREEN->value,
                port: $ports[0],
                ports: $ports,
                routingRevision: $routingRevision,
                mode: $legacyAdoption ? BlueGreenRoutingMode::LegacyAdoption : BlueGreenRoutingMode::Steady,
                publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($operationId),
                destinationFenceEpoch: $destinationFenceEpoch,
                operationId: $operationId,
                mutationSequence: 1,
                activeDeploymentUuid: $operationId,
                activeContainerId: 'pending',
                destinationTopologyDigest: $topologyDigest,
            ),
        );

        return new BlueGreenDeploymentFingerprint(
            topologyDigest: $topologyDigest,
            routingConfigDigest: $configuration->routingConfigDigest,
        );
    }

    public function assertMatchesClaim(BlueGreenDeploymentClaim $claim): void
    {
        $application = Application::withTrashed()
            ->with('settings')
            ->find($claim->applicationId);
        $destination = StandaloneDocker::query()
            ->with('server')
            ->find($claim->standaloneDockerId);
        if ($application === null || $application->trashed() || $destination === null || $destination->server === null) {
            throw new BlueGreenOperationFenceLostException('The blue-green destination topology disappeared after the operation was claimed.');
        }
        $fingerprint = $this->handle(
            $application,
            $destination,
            $claim->pendingColor,
            $claim->expectedRoutingRevision,
            $claim->destinationFenceEpoch,
            $claim->deploymentUuid,
            $claim->legacyContainerName !== null,
        );
        $backendPortInventory = BlueGreenBackendPortInventory::fromPorts(
            $application->blueGreenDeploymentBackendPorts($application->settings)
                ?? throw new BlueGreenOperationFenceLostException('The blue-green backend port inventory disappeared after the operation was claimed.'),
        );
        if (! hash_equals($claim->backendPortInventory->serialized, $backendPortInventory->serialized)
            || ! hash_equals($claim->topologyDigest, $fingerprint->topologyDigest)
            || ! hash_equals($claim->routingConfigDigest, $fingerprint->routingConfigDigest)) {
            throw new BlueGreenOperationFenceLostException('The blue-green destination topology or routing configuration changed after the operation was claimed.');
        }
    }

    private function topologyDigest(Application $application, StandaloneDocker $destination): string
    {
        $destination->loadMissing('server');
        $server = $destination->server
            ?? throw new BlueGreenDeploymentTransitionException('The blue-green destination has no server.');
        $configuredDestinationIds = $application->blueGreenConfiguredStandaloneDockerDestinationIds()
            ->map(static fn (mixed $destinationId): int => (int) $destinationId)
            ->sort()
            ->values()
            ->all();

        try {
            $serialized = json_encode([
                'version' => 1,
                'application_id' => (int) $application->id,
                'application_uuid' => (string) $application->uuid,
                'configured_destination_ids' => $configuredDestinationIds,
                'destination_id' => (int) $destination->id,
                'destination_server_id' => (int) $destination->server_id,
                'destination_network' => (string) $destination->network,
                'server_id' => (int) $server->id,
                'server_uuid' => (string) $server->uuid,
                'server_ip' => (string) $server->ip,
                'server_user' => (string) $server->user,
                'server_port' => (int) $server->port,
                'server_private_key_id' => (int) $server->private_key_id,
                'server_proxy_type' => (string) $server->proxyType(),
                'server_proxy_path' => $server->proxyPath(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The blue-green destination topology could not be serialized.',
                previous: $exception,
            );
        }

        return hash('sha256', $serialized);
    }
}
