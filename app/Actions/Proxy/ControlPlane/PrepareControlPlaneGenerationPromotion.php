<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Models\Server;
use Closure;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class PrepareControlPlaneGenerationPromotion
{
    use AsAction;

    public function __construct(
        private readonly CompileControlPlaneDynamicConfiguration $dynamicConfigurationCompiler,
        private readonly ExtractControlPlaneDynamicFragments $dynamicFragmentExtractor,
        private readonly StoreControlPlaneProxyEnrollmentState $enrollmentStateStore,
        private readonly StoreControlPlaneGenerationPromotionState $promotionStateStore,
        private readonly ManagedTraefikDocumentWriter $dynamicWriter,
        private readonly ControlPlaneGenerationWriterAuthority $writerAuthority,
        private readonly VerifyControlPlaneRestoredRoutes $restoredRoutesVerifier,
    ) {}

    /**
     * @param  list<string>  $successorBackendDnsNames
     * @param  array<string, array<string, mixed>>  $realtimeRouterFragments
     * @param  array<string, array<string, mixed>>  $terminalRouterFragments
     * @param  array<string, array<string, mixed>>  $preservedServices
     * @param  array<string, array<string, mixed>>  $preservedMiddlewares
     */
    public function handle(
        Server $server,
        string $operationId,
        string $token,
        string $successorMember,
        string $successorReleaseRevision,
        array $successorBackendDnsNames,
        ControlPlaneGenerationRuntime $runtime,
        string $writerMember,
        int $writerEpoch,
        string $predecessorDynamicYaml,
        array $realtimeRouterFragments = [],
        array $terminalRouterFragments = [],
        array $preservedServices = [],
        array $preservedMiddlewares = [],
        ?Closure $remoteExecutor = null,
    ): PreparedControlPlaneGenerationPromotion {
        $enrollment = $this->enrolledState($server);
        $currentPromotion = $this->promotionStateStore->read($server);
        if ($currentPromotion?->legacyWriterAuthorityReconciliationRequired) {
            if ($currentPromotion->phase !== ControlPlaneGenerationPromotionPhase::RolledBack) {
                throw new RuntimeException('Legacy control-plane writer authority must be reconciled through generation resume before preparation can continue.');
            }
            $currentPromotion = $this->reconcileLegacyTerminalRollback(
                $server,
                $currentPromotion,
                $enrollment,
                $remoteExecutor,
            );
        }
        $isCompletedPredecessor = $currentPromotion?->phase === ControlPlaneGenerationPromotionPhase::Completed;
        $isRolledBackPredecessor = $currentPromotion?->phase === ControlPlaneGenerationPromotionPhase::RolledBack;
        $isChainedReplay = $currentPromotion !== null
            && ! $isCompletedPredecessor
            && ! $isRolledBackPredecessor
            && $currentPromotion->isOwnedBy($operationId, $token)
            && ! $currentPromotion->matchesEnrolledWriterPredecessor($enrollment);

        if ($isCompletedPredecessor) {
            $predecessorDynamicRevision = $currentPromotion->successor['dynamic_revision'];
            $predecessorDynamicSha256 = $currentPromotion->successor['dynamic_sha256'];
            $predecessorBackends = $currentPromotion->successor['backends'];
        } elseif ($isRolledBackPredecessor || $isChainedReplay) {
            $predecessorDynamicRevision = $currentPromotion->predecessor['dynamic_revision'];
            $predecessorDynamicSha256 = $currentPromotion->predecessor['dynamic_sha256'];
            $predecessorBackends = $currentPromotion->predecessor['backends'];
        } else {
            $predecessorDynamicRevision = $enrollment->dynamicRevision;
            $predecessorDynamicSha256 = hash('sha256', $enrollment->dynamicReplacementBytes);
            $predecessorBackends = $enrollment->activeBackendDnsNames;
        }

        $this->assertExactPredecessorBytes(
            $predecessorDynamicYaml,
            $predecessorDynamicSha256,
            $isCompletedPredecessor || $isRolledBackPredecessor || $isChainedReplay ? null : $enrollment->dynamicReplacementBytes,
        );
        $successorBackendDnsNames = $this->normalizeSuccessorBackends($successorBackendDnsNames);
        $this->assertExactRuntime(
            $runtime,
            $predecessorBackends,
            $successorBackendDnsNames,
            $successorMember,
            $writerMember,
        );

        $extractedFragments = $this->dynamicFragmentExtractor->handle($predecessorDynamicYaml);
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
        $configurationAcknowledgement = $this->configurationAcknowledgement(
            server: $server,
            operationId: $operationId,
            token: $token,
            predecessorDynamicRevision: $predecessorDynamicRevision,
            predecessorDynamicSha256: $predecessorDynamicSha256,
            enrollment: $enrollment,
            successorMember: $successorMember,
            successorReleaseRevision: $successorReleaseRevision,
            successorBackendDnsNames: $successorBackendDnsNames,
            runtime: $runtime,
            writerMember: $writerMember,
            writerEpoch: $writerEpoch,
        );
        $successorConfiguration = $this->dynamicConfigurationCompiler->handle(
            host: $enrollment->canonicalHost,
            appPortEntrypoint: 'coolify',
            activeBackendDnsNames: $successorBackendDnsNames,
            expectedRevision: $successorReleaseRevision,
            expectedMember: $successorMember,
            configurationAcknowledgement: $configurationAcknowledgement,
            healthCheckProof: hash_hmac(
                'sha256',
                ControlPlaneDynamicConfiguration::HEALTH_PROOF_DERIVATION_CONTEXT,
                $token,
            ),
            publicScheme: $enrollment->publicScheme,
            realtimeRouterFragments: $realtimeRouterFragments,
            terminalRouterFragments: $terminalRouterFragments,
            preservedServices: $preservedServices,
            preservedMiddlewares: $preservedMiddlewares,
        );
        $successorDynamicRevision = $predecessorDynamicRevision + 1;

        if ($isChainedReplay) {
            $this->assertSamePreparedArtifacts(
                $currentPromotion,
                $server,
                $predecessorDynamicSha256,
                $successorDynamicRevision,
                $successorConfiguration,
                $successorMember,
                $successorReleaseRevision,
                $successorBackendDnsNames,
                $configurationAcknowledgement,
                $runtime,
                $writerMember,
                $writerEpoch,
            );
            $state = $this->promotionStateStore->reserve($server, $currentPromotion, $token);

            return new PreparedControlPlaneGenerationPromotion($state, $successorConfiguration);
        }

        if ($isCompletedPredecessor) {
            $desiredState = ControlPlaneGenerationPromotionState::reserveAfterCompleted(
                operationId: $operationId,
                token: $token,
                serverId: (int) $server->getKey(),
                completedPromotion: $currentPromotion,
                successorDynamicRevision: $successorDynamicRevision,
                successorDynamicSha256: $successorConfiguration->sha256,
                successorMember: $successorMember,
                successorReleaseRevision: $successorReleaseRevision,
                successorBackends: $successorBackendDnsNames,
                successorConfigurationAcknowledgement: $configurationAcknowledgement,
                runtime: $runtime,
                writerMember: $writerMember,
                writerEpoch: $writerEpoch,
                timestamp: now()->toIso8601String(),
            );
        } elseif ($isRolledBackPredecessor) {
            $desiredState = ControlPlaneGenerationPromotionState::reserveAfterRolledBack(
                operationId: $operationId,
                token: $token,
                serverId: (int) $server->getKey(),
                rolledBackPromotion: $currentPromotion,
                successorDynamicRevision: $successorDynamicRevision,
                successorDynamicSha256: $successorConfiguration->sha256,
                successorMember: $successorMember,
                successorReleaseRevision: $successorReleaseRevision,
                successorBackends: $successorBackendDnsNames,
                successorConfigurationAcknowledgement: $configurationAcknowledgement,
                runtime: $runtime,
                writerMember: $writerMember,
                writerEpoch: $writerEpoch,
                timestamp: now()->toIso8601String(),
            );
        } else {
            $desiredState = ControlPlaneGenerationPromotionState::reserve(
                operationId: $operationId,
                token: $token,
                serverId: (int) $server->getKey(),
                predecessor: $enrollment,
                successorDynamicRevision: $successorDynamicRevision,
                successorDynamicSha256: $successorConfiguration->sha256,
                successorMember: $successorMember,
                successorReleaseRevision: $successorReleaseRevision,
                successorBackends: $successorBackendDnsNames,
                successorConfigurationAcknowledgement: $configurationAcknowledgement,
                runtime: $runtime,
                writerMember: $writerMember,
                writerEpoch: $writerEpoch,
                timestamp: now()->toIso8601String(),
            );
        }
        $state = $this->promotionStateStore->reserve($server, $desiredState, $token);

        return new PreparedControlPlaneGenerationPromotion($state, $successorConfiguration);
    }

    /** @param null|Closure(string): ?string $remoteExecutor */
    private function reconcileLegacyTerminalRollback(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
        ControlPlaneProxyEnrollmentState $enrollment,
        ?Closure $remoteExecutor,
    ): ControlPlaneGenerationPromotionState {
        $execute = $remoteExecutor ?? static fn (string $command): ?string => instant_remote_process(
            [$command],
            $server,
            timeout: 120,
            disableMultiplexing: true,
            retry: false,
        );
        $proxyPath = rtrim((string) $server->proxyPath(), '/');
        $mutation = new ManagedTraefikDocumentMutation(
            dynamicDirectory: $proxyPath.'/dynamic',
            stateDirectory: $proxyPath.'/.control-plane-managed-traefik',
            filename: $state->managedFilename,
            operationId: $state->operationId,
            revision: $state->successor['dynamic_revision'],
            expectedSha256: $state->predecessor['dynamic_sha256'],
            expectedOperationId: $state->predecessor['operation_id'],
            expectedRevision: $state->predecessor['dynamic_revision'],
            replacementBytes: '',
            expectedWriterOperationId: $state->predecessorWriterOperationId,
        );
        $output = $execute($this->dynamicWriter->reconcileRolledBackWriterAuthorityCommandFor(
            mutation: $mutation,
            predecessorAuthority: $this->writerAuthority->predecessor($state),
            rolledBackAuthority: $this->writerAuthority->rolledBack($state),
        ));
        if (! is_string($output) || ! hash_equals(ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT, $output)) {
            throw new RuntimeException('The terminal legacy rollback writer authority reconciliation did not return its exact completion proof.');
        }

        $proof = new ControlPlaneRestoredRoutesProof(
            canonicalHost: $enrollment->canonicalHost,
            publicScheme: $enrollment->publicScheme,
            appPort: $enrollment->appPort,
            expectedBackendMember: $state->predecessor['member'],
            expectedBackendRevision: $state->predecessor['release_revision'],
            expectedDynamicPredecessorSha256: $state->predecessor['dynamic_sha256'],
        );
        $transcript = $execute($proof->shellCommand());
        if (! is_string($transcript)) {
            throw new RuntimeException('The terminal legacy rollback restored-route proof returned no transcript.');
        }
        $this->restoredRoutesVerifier->handle($proof, $transcript);

        return $this->promotionStateStore->reconcileLegacyTerminalRollbackWriterAuthority(
            $server,
            $state,
            now()->toIso8601String(),
        );
    }

    private function enrolledState(Server $server): ControlPlaneProxyEnrollmentState
    {
        $enrollment = $this->enrollmentStateStore->read($server)
            ?? throw new RuntimeException('The permanent control-plane enrollment state is missing.');
        if ($enrollment->phase !== ControlPlaneProxyEnrollmentPhase::Enrolled) {
            throw new RuntimeException('The permanent control-plane enrollment is not enrolled.');
        }

        return $enrollment;
    }

    private function assertExactPredecessorBytes(
        string $predecessorDynamicYaml,
        string $predecessorDynamicSha256,
        ?string $expectedPredecessorDynamicYaml,
    ): void {
        $matchesExpectedBytes = $expectedPredecessorDynamicYaml === null
            ? hash_equals($predecessorDynamicSha256, hash('sha256', $predecessorDynamicYaml))
            : hash_equals($expectedPredecessorDynamicYaml, $predecessorDynamicYaml);
        if (! $matchesExpectedBytes) {
            throw new RuntimeException('The control-plane generation promotion predecessor dynamic bytes are stale.');
        }
    }

    /**
     * @param  list<string>  $successorBackendDnsNames
     * @return list<string>
     */
    private function normalizeSuccessorBackends(array $successorBackendDnsNames): array
    {
        if ($successorBackendDnsNames === [] || ! array_is_list($successorBackendDnsNames)) {
            throw new InvalidArgumentException('The successor control-plane backend set must be a non-empty list.');
        }
        foreach ($successorBackendDnsNames as $backendDnsName) {
            if (! is_string($backendDnsName)) {
                throw new InvalidArgumentException('The successor control-plane backend set is invalid.');
            }
        }
        if (count(array_unique($successorBackendDnsNames, SORT_STRING)) !== count($successorBackendDnsNames)) {
            throw new InvalidArgumentException('The successor control-plane backend set must not contain duplicates.');
        }
        sort($successorBackendDnsNames, SORT_STRING);

        return array_values($successorBackendDnsNames);
    }

    /**
     * @param  list<string>  $predecessorBackends
     * @param  list<string>  $successorBackends
     */
    private function assertExactRuntime(
        ControlPlaneGenerationRuntime $runtime,
        array $predecessorBackends,
        array $successorBackends,
        string $successorMember,
        string $writerMember,
    ): void {
        if (! hash_equals($successorMember, $writerMember)) {
            throw new InvalidArgumentException('The control-plane writer member must match the successor member.');
        }
        if (array_keys($runtime->predecessorRuntime) !== $predecessorBackends
            || array_keys($runtime->successorRuntime) !== $successorBackends) {
            throw new InvalidArgumentException('The control-plane generation runtime does not match the exact routed backend sets.');
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
            throw new InvalidArgumentException("An explicitly supplied control-plane {$role} duplicates the preserved predecessor owner.");
        }
        $merged = [...$extracted, ...$additional];
        ksort($merged, SORT_STRING);

        return $merged;
    }

    /** @param list<string> $successorBackendDnsNames */
    private function configurationAcknowledgement(
        Server $server,
        string $operationId,
        string $token,
        int $predecessorDynamicRevision,
        string $predecessorDynamicSha256,
        ControlPlaneProxyEnrollmentState $enrollment,
        string $successorMember,
        string $successorReleaseRevision,
        array $successorBackendDnsNames,
        ControlPlaneGenerationRuntime $runtime,
        string $writerMember,
        int $writerEpoch,
    ): string {
        return 'ack:'.hash('sha256', json_encode([
            'operation_id' => $operationId,
            'token_sha256' => hash('sha256', $token),
            'server_id' => (int) $server->getKey(),
            'host' => $enrollment->canonicalHost,
            'public_scheme' => $enrollment->publicScheme,
            'predecessor_dynamic_revision' => $predecessorDynamicRevision,
            'predecessor_dynamic_sha256' => $predecessorDynamicSha256,
            'successor_member' => $successorMember,
            'successor_release_revision' => $successorReleaseRevision,
            'successor_backends' => $successorBackendDnsNames,
            'runtime' => $runtime->toArray(),
            'writer_member' => $writerMember,
            'writer_epoch' => $writerEpoch,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param list<string> $successorBackendDnsNames */
    private function assertSamePreparedArtifacts(
        ControlPlaneGenerationPromotionState $currentPromotion,
        Server $server,
        string $predecessorDynamicSha256,
        int $successorDynamicRevision,
        ControlPlaneDynamicConfiguration $successorConfiguration,
        string $successorMember,
        string $successorReleaseRevision,
        array $successorBackendDnsNames,
        string $configurationAcknowledgement,
        ControlPlaneGenerationRuntime $runtime,
        string $writerMember,
        int $writerEpoch,
    ): void {
        if ($currentPromotion->serverId !== (int) $server->getKey()
            || ! hash_equals($currentPromotion->predecessor['dynamic_sha256'], $predecessorDynamicSha256)
            || $currentPromotion->successor['dynamic_revision'] !== $successorDynamicRevision
            || ! hash_equals($currentPromotion->successor['dynamic_sha256'], $successorConfiguration->sha256)
            || ! hash_equals($currentPromotion->successor['member'], $successorMember)
            || ! hash_equals($currentPromotion->successor['release_revision'], $successorReleaseRevision)
            || $currentPromotion->successor['backends'] !== $successorBackendDnsNames
            || ! hash_equals($currentPromotion->successor['configuration_acknowledgement'], $configurationAcknowledgement)
            || $currentPromotion->runtime->toArray() !== $runtime->toArray()
            || ! hash_equals($currentPromotion->writerMember, $writerMember)
            || $currentPromotion->writerEpoch !== $writerEpoch) {
            throw new RuntimeException('The same control-plane generation promotion owner supplied different immutable preparation artifacts.');
        }
    }
}
