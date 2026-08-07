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

    private const int ROUTING_TOPOLOGY_VERSION = 5;

    private const int COMPOSE_ROUTING_IDENTITY_VERSION = 1;

    public function handle(
        Application $application,
        StandaloneDocker $destination,
        BlueGreenDeploymentColor $pendingColor,
        int $routingRevision,
        int $destinationFenceEpoch,
        string $operationId,
        bool $legacyAdoption = false,
    ): BlueGreenDeploymentFingerprint {
        return $this->forOperationTopologyDigest(
            $application,
            $destination,
            $pendingColor,
            $routingRevision,
            $destinationFenceEpoch,
            $operationId,
            $legacyAdoption,
            $this->operationTopologyDigest($application, $destination),
        );
    }

    public function forOperationTopologyDigest(
        Application $application,
        StandaloneDocker $destination,
        BlueGreenDeploymentColor $pendingColor,
        int $routingRevision,
        int $destinationFenceEpoch,
        string $operationId,
        bool $legacyAdoption,
        string $operationTopologyDigest,
    ): BlueGreenDeploymentFingerprint {
        $routingTopologyDigest = $this->routingTopologyDigestFor($application, $destination);
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
                destinationTopologyDigest: $operationTopologyDigest,
            ),
        );

        return new BlueGreenDeploymentFingerprint(
            operationTopologyDigest: $operationTopologyDigest,
            routingTopologyDigest: $routingTopologyDigest,
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
        $fingerprint = $this->forOperationTopologyDigest(
            $application,
            $destination,
            $claim->pendingColor,
            $claim->expectedRoutingRevision,
            $claim->destinationFenceEpoch,
            $claim->deploymentUuid,
            $claim->legacyContainerName !== null,
            $claim->operationTopologyDigest,
        );
        $backendPortInventory = BlueGreenBackendPortInventory::forApplication($application, $application->settings)
            ?? throw new BlueGreenOperationFenceLostException('The blue-green backend port inventory disappeared after the operation was claimed.');
        if (! hash_equals($claim->backendPortInventory->serialized, $backendPortInventory->serialized)
            || ! hash_equals($claim->routingTopologyDigest, $fingerprint->routingTopologyDigest)
            || ! hash_equals($claim->routingConfigDigest, $fingerprint->routingConfigDigest)) {
            throw new BlueGreenOperationFenceLostException('The blue-green destination topology or routing configuration changed after the operation was claimed.');
        }
    }

    public function routingTopologyDigestFor(Application $application, StandaloneDocker $destination): string
    {
        return $this->digestTopologyPayload($this->routingTopologyPayload($application, $destination));
    }

    private function operationTopologyDigest(Application $application, StandaloneDocker $destination): string
    {
        return $this->digestTopologyPayload($this->operationTopologyPayload($application, $destination));
    }

    /**
     * The durable fence identifies one application destination and the managed
     * route-file location. Mutable route behavior belongs exclusively to the
     * routing configuration digest, so adding a destination or editing a
     * Compose Traefik rule cannot strand an existing destination state.
     *
     * @return array<string, mixed>
     */
    private function routingTopologyPayload(
        Application $application,
        StandaloneDocker $destination,
    ): array {
        $destination->loadMissing('server');
        $server = $destination->server
            ?? throw new BlueGreenDeploymentTransitionException('The blue-green destination has no server.');

        $topology = [
            'version' => self::ROUTING_TOPOLOGY_VERSION,
            'application_id' => (int) $application->id,
            'application_uuid' => (string) $application->uuid,
            'destination_id' => (int) $destination->id,
            'destination_server_id' => (int) $destination->server_id,
            'destination_network' => (string) $destination->network,
            'server_id' => (int) $server->id,
            'server_uuid' => (string) $server->uuid,
            'server_proxy_type' => (string) $server->proxyType(),
            'server_proxy_path' => $server->proxyPath(),
        ];
        if ($application->build_pack === 'dockercompose') {
            $topology['compose_routed_identity'] = $this->composeRoutedIdentity($application);
        }

        return $topology;
    }

    /**
     * The operation digest deliberately preserves the pre-split payload and
     * key ordering. It is ephemeral claim provenance, so it continues to
     * capture the full configuration and connection context for a single
     * operation without changing persisted destination semantics.
     *
     * @return array<string, mixed>
     */
    private function operationTopologyPayload(Application $application, StandaloneDocker $destination): array
    {
        $destination->loadMissing('server');
        $server = $destination->server
            ?? throw new BlueGreenDeploymentTransitionException('The blue-green destination has no server.');
        $configuredDestinationIds = $application->blueGreenConfiguredStandaloneDockerDestinationIds()
            ->map(static fn (mixed $destinationId): int => (int) $destinationId)
            ->sort()
            ->values()
            ->all();

        $topology = [
            'version' => 1,
            'application_id' => (int) $application->id,
            'application_uuid' => (string) $application->uuid,
            'configured_destination_ids' => $configuredDestinationIds,
            'destination_id' => (int) $destination->id,
            'destination_server_id' => (int) $destination->server_id,
            'destination_network' => (string) $destination->network,
            'server_id' => (int) $server->id,
            'server_uuid' => (string) $server->uuid,
            // Key order is part of the frozen legacy hash: the connection
            // fields must serialize between server_uuid and server_proxy_type,
            // exactly where the pre-split formula had them.
            'server_ip' => (string) $server->ip,
            'server_user' => (string) $server->user,
            'server_port' => (int) $server->port,
            'server_private_key_id' => (int) $server->private_key_id,
            'server_proxy_type' => (string) $server->proxyType(),
            'server_proxy_path' => $server->proxyPath(),
        ];
        if ($application->build_pack === 'dockercompose') {
            $topology['version'] = 2;
            $topology['compose_routed_topology'] = $application->blueGreenTopologyFingerprintPayload();
        }

        return $topology;
    }

    /**
     * @return ?array<string, mixed>
     */
    private function composeRoutedIdentity(Application $application): ?array
    {
        $topology = $application->blueGreenTopologyFingerprintPayload();
        if ($topology === null) {
            return null;
        }

        $identity = [
            'version' => self::COMPOSE_ROUTING_IDENTITY_VERSION,
            'routed_service' => $topology['routed_service'],
            'legacy_container_name' => $topology['legacy_container_name'],
        ];
        if (array_key_exists('co_rolled_services', $topology)) {
            $identity['co_rolled_services'] = $topology['co_rolled_services'];
        }

        return $identity;
    }

    /** @param  array<string, mixed>  $topology */
    private function digestTopologyPayload(array $topology): string
    {
        try {
            $serialized = json_encode($topology, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The blue-green destination topology could not be serialized.',
                previous: $exception,
            );
        }

        return hash('sha256', $serialized);
    }
}
