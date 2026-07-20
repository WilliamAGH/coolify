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
        private readonly ExtractControlPlaneDynamicFragments $dynamicFragmentExtractor,
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
        $healthCheckProof = hash_hmac('sha256', ControlPlaneDynamicConfiguration::HEALTH_PROOF_DERIVATION_CONTEXT, $token);
        $extractedFragments = $existingDynamicYaml === null
            ? [
                'realtimeRouterFragments' => [],
                'terminalRouterFragments' => [],
                'preservedServices' => [],
                'preservedMiddlewares' => [],
            ]
            : $this->dynamicFragmentExtractor->handle($existingDynamicYaml);
        $realtimeRouterFragments = $this->mergePreservedDefinitions(
            $extractedFragments['realtimeRouterFragments'],
            $realtimeRouterFragments,
            'realtime router',
        );
        $terminalRouterFragments = $this->mergePreservedDefinitions(
            $extractedFragments['terminalRouterFragments'],
            $terminalRouterFragments,
            'terminal router',
        );
        $preservedServices = $this->mergePreservedDefinitions(
            $extractedFragments['preservedServices'],
            $preservedServices,
            'service',
        );
        $preservedMiddlewares = $this->mergePreservedDefinitions(
            $extractedFragments['preservedMiddlewares'],
            $preservedMiddlewares,
            'middleware',
        );
        $dynamicConfiguration = $this->dynamicConfigurationCompiler->handle(
            host: $host,
            appPortEntrypoint: 'coolify',
            activeBackendDnsNames: $activeBackendDnsNames,
            expectedRevision: $expectedRevision,
            expectedMember: $expectedMember,
            configurationAcknowledgement: $configurationAcknowledgement,
            healthCheckProof: $healthCheckProof,
            publicScheme: $publicScheme,
            realtimeRouterFragments: $realtimeRouterFragments,
            terminalRouterFragments: $terminalRouterFragments,
            preservedServices: $preservedServices,
            preservedMiddlewares: $preservedMiddlewares,
        );
        $staticConfiguration = $this->staticConfigurationCompiler->withHealthProofIdentity(
            configuration: $staticConfiguration,
            healthProofTokenSha256: hash('sha256', $healthCheckProof),
            authenticationProxyProofSha256: hash(
                'sha256',
                ControlPlaneDynamicConfiguration::deriveAuthenticationProxyProof($healthCheckProof),
            ),
            dynamicSha256: $dynamicConfiguration->sha256,
            configurationAcknowledgement: $configurationAcknowledgement,
            expectedMember: $expectedMember,
            expectedRevision: $expectedRevision,
            serverId: (int) $server->getKey(),
            canonicalHost: $host,
        );
        $desiredState = ControlPlaneProxyEnrollmentState::reserve(
            operationId: $operationId,
            token: $token,
            serverId: (int) $server->getKey(),
            appPort: $appPort,
            exposure: $exposure,
            dynamicRevision: 1,
            canonicalHost: $host,
            publicScheme: $publicScheme,
            expectedMember: $expectedMember,
            expectedRevision: $expectedRevision,
            configurationAcknowledgement: $configurationAcknowledgement,
            activeBackendDnsNames: $activeBackendDnsNames,
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

    /**
     * @param  array<string, array<string, mixed>>  $extracted
     * @param  array<string, array<string, mixed>>  $additional
     * @return array<string, array<string, mixed>>
     */
    private function mergePreservedDefinitions(array $extracted, array $additional, string $role): array
    {
        if (array_intersect_key($extracted, $additional) !== []) {
            throw new InvalidArgumentException("An explicitly supplied control-plane {$role} duplicates the preserved snapshot owner.");
        }
        $merged = [...$extracted, ...$additional];
        ksort($merged, SORT_STRING);

        return $merged;
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
            $currentState->canonicalHost,
            $currentState->publicScheme,
            $currentState->expectedMember,
            $currentState->expectedRevision,
            $currentState->configurationAcknowledgement,
            $currentState->activeBackendDnsNames,
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
            $desiredState->canonicalHost,
            $desiredState->publicScheme,
            $desiredState->expectedMember,
            $desiredState->expectedRevision,
            $desiredState->configurationAcknowledgement,
            $desiredState->activeBackendDnsNames,
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
