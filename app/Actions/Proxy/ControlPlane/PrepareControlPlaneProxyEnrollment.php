<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Enums\ProxyTypes;
use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class PrepareControlPlaneProxyEnrollment
{
    use AsAction;

    public function __construct(
        private readonly CompileControlPlaneStaticProxyConfiguration $staticConfigurationCompiler,
        private readonly CompileControlPlaneDynamicConfiguration $dynamicConfigurationCompiler,
        private readonly StoreControlPlaneProxyEnrollmentState $stateStore,
    ) {}

    /**
     * @param  list<string>  $activeBackendDnsNames
     * @param  array<string, array<string, mixed>>  $realtimeRouterFragments
     * @param  array<string, array<string, mixed>>  $terminalRouterFragments
     * @param  array<string, array<string, mixed>>  $preservedServices
     * @param  array<string, array<string, mixed>>  $preservedMiddlewares
     */
    public function handle(
        Server $server,
        string $operationId,
        string $token,
        int $appPort,
        ControlPlaneProxyExposure $exposure,
        string $sourceComposeYaml,
        ?string $existingDynamicYaml,
        array $activeBackendDnsNames,
        string $host,
        string $expectedRevision,
        string $expectedMember,
        string $configurationAcknowledgement,
        string $publicScheme = 'https',
        array $realtimeRouterFragments = [],
        array $terminalRouterFragments = [],
        array $preservedServices = [],
        array $preservedMiddlewares = [],
        bool $hasProvenAlternateRoute = false,
    ): ControlPlaneProxyEnrollmentState {
        $this->assertEligibleServer($server);
        if ($exposure === ControlPlaneProxyExposure::Loopback && ! $hasProvenAlternateRoute) {
            throw new InvalidArgumentException('Loopback control-plane APP_PORT exposure requires a proven alternate route.');
        }

        $staticConfiguration = $this->staticConfigurationCompiler->handle(
            server: $server,
            sourceComposeYaml: $sourceComposeYaml,
            appPort: $appPort,
            exposure: $exposure,
        );
        $dynamicConfiguration = $this->dynamicConfigurationCompiler->handle(
            host: $host,
            appPortEntrypoint: 'coolify',
            activeBackendDnsNames: $activeBackendDnsNames,
            expectedRevision: $expectedRevision,
            expectedMember: $expectedMember,
            configurationAcknowledgement: $configurationAcknowledgement,
            publicScheme: $publicScheme,
            realtimeRouterFragments: $realtimeRouterFragments,
            terminalRouterFragments: $terminalRouterFragments,
            preservedServices: $preservedServices,
            preservedMiddlewares: $preservedMiddlewares,
        );
        $desiredState = ControlPlaneProxyEnrollmentState::reserve(
            operationId: $operationId,
            token: $token,
            serverId: (int) $server->getKey(),
            appPort: $appPort,
            exposure: $exposure,
            dynamicRevision: 1,
            staticConfiguration: $staticConfiguration,
            dynamicConfiguration: $dynamicConfiguration,
            dynamicPredecessorBytes: $existingDynamicYaml,
            timestamp: now()->toIso8601String(),
        );

        $currentState = $this->stateStore->read($server);
        if ($currentState !== null && $currentState->isOwnedBy($operationId, $token)) {
            $this->assertSamePreparedArtifacts($currentState, $desiredState);

            return $currentState;
        }

        try {
            return $this->stateStore->reserve($server, $desiredState, $token);
        } catch (RuntimeException $exception) {
            $currentState = $this->stateStore->read($server);
            if ($currentState !== null && $currentState->isOwnedBy($operationId, $token)) {
                $this->assertSamePreparedArtifacts($currentState, $desiredState);

                return $currentState;
            }

            throw $exception;
        }
    }

    private function assertEligibleServer(Server $server): void
    {
        if ($server->isSwarm()) {
            throw new InvalidArgumentException('Control-plane proxy enrollment does not alter Swarm installations.');
        }
        if (! $server->isLocalhost()) {
            throw new InvalidArgumentException('Control-plane proxy enrollment requires the local Coolify server.');
        }
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new InvalidArgumentException('Control-plane proxy enrollment requires the existing Traefik proxy.');
        }
    }

    private function assertSamePreparedArtifacts(
        ControlPlaneProxyEnrollmentState $currentState,
        ControlPlaneProxyEnrollmentState $desiredState,
    ): void {
        $currentArtifacts = [
            $currentState->serverId,
            $currentState->appPort,
            $currentState->exposure,
            $currentState->managedFilename,
            $currentState->dynamicRevision,
            $currentState->staticPredecessorBytes,
            $currentState->staticReplacementBytes,
            $currentState->sourceOverrideBytes,
            $currentState->dynamicPredecessorBytes,
            $currentState->dynamicReplacementBytes,
        ];
        $desiredArtifacts = [
            $desiredState->serverId,
            $desiredState->appPort,
            $desiredState->exposure,
            $desiredState->managedFilename,
            $desiredState->dynamicRevision,
            $desiredState->staticPredecessorBytes,
            $desiredState->staticReplacementBytes,
            $desiredState->sourceOverrideBytes,
            $desiredState->dynamicPredecessorBytes,
            $desiredState->dynamicReplacementBytes,
        ];

        if ($currentArtifacts !== $desiredArtifacts) {
            throw new RuntimeException('The same control-plane enrollment owner supplied different immutable preparation artifacts.');
        }
    }
}
