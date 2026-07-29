<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Actions\Proxy\SaveProxyConfiguration;
use App\Models\Server;
use Closure;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class ExecuteControlPlaneProxyEnrollmentRollback
{
    use AsAction;

    public function __construct(
        private readonly StoreControlPlaneProxyEnrollmentState $stateStore,
        private readonly NormalizeControlPlaneEnrollmentFilesystem $filesystemNormalizer,
        private readonly ControlPlaneStaticListenerHandoff $staticHandoff,
        private readonly ManagedTraefikDocumentWriter $dynamicWriter,
        private readonly InspectControlPlaneEnrollmentWriterAuthority $writerAuthorityInspector,
        private readonly InspectControlPlaneEnrollmentWriter $writerInspector,
        private readonly BootstrapControlPlaneEnrollmentWriterAuthority $writerAuthorityBootstrap,
        private readonly InstallControlPlaneCandidateHealthMarkers $candidateMarkerInstaller,
        private readonly VerifyControlPlaneRestoredRoutes $restoredRoutesVerifier,
    ) {}

    /** @param null|Closure(string): ?string $remoteExecutor */
    public function handle(
        Server $server,
        string $operationId,
        string $token,
        ?Closure $remoteExecutor = null,
        ?string $authorizedOrphanedSourceOverrideSha256 = null,
    ): ControlPlaneProxyEnrollmentState {
        if ($authorizedOrphanedSourceOverrideSha256 !== null
            && preg_match('/\A[a-f0-9]{64}\z/D', $authorizedOrphanedSourceOverrideSha256) !== 1) {
            throw new InvalidArgumentException('The orphaned source override authorization must be an exact lowercase SHA-256.');
        }

        return $this->stateStore->serializeOperation(
            $server,
            fn (Server $lockedServer): ControlPlaneProxyEnrollmentState => $this->handleLocked(
                $lockedServer,
                $operationId,
                $token,
                $remoteExecutor,
                $authorizedOrphanedSourceOverrideSha256,
            ),
        );
    }

    /** @param null|Closure(string): ?string $remoteExecutor */
    private function handleLocked(
        Server $server,
        string $operationId,
        string $token,
        ?Closure $remoteExecutor,
        ?string $authorizedOrphanedSourceOverrideSha256,
    ): ControlPlaneProxyEnrollmentState {
        $state = $this->ownedState($server, $operationId, $token);
        if ($authorizedOrphanedSourceOverrideSha256 !== null && in_array($state->phase, [
            ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement,
            ControlPlaneProxyEnrollmentPhase::RolledBack,
        ], true)) {
            throw new InvalidArgumentException('The orphaned source override authorization is invalid after static rollback.');
        }
        if ($state->phase === ControlPlaneProxyEnrollmentPhase::RolledBack) {
            return $state;
        }
        $this->stateStore->assertRollbackAvailable($server);

        $execute = $remoteExecutor ?? static fn (string $command): ?string => instant_remote_process(
            [$command],
            $server,
            timeout: 120,
            disableMultiplexing: true,
            retry: false,
        );
        if ($state->phase === ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement) {
            return $this->acknowledgeRestoredRoutes($server, $state, $operationId, $token, $execute);
        }

        $wasAlreadyRollingBack = $state->phase === ControlPlaneProxyEnrollmentPhase::RollingBack;
        $proxyPath = rtrim((string) $server->proxyPath(), '/');
        $dynamicMutation = $this->rollbackMutation($server, $state);
        [$replacementAuthority, $rolledBackAuthority] = $this->rollbackAuthorities(
            $state,
            $dynamicMutation,
            $execute,
        );
        $this->assertExactOutput(
            $execute($this->filesystemNormalizer->commandFor($proxyPath)),
            NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
            'control-plane enrollment filesystem normalization',
        );
        if (! $wasAlreadyRollingBack) {
            $state = $this->stateStore->transition(
                $server,
                $operationId,
                $token,
                $state->phase,
                ControlPlaneProxyEnrollmentPhase::RollingBack,
                now()->toIso8601String(),
            );
        }
        $this->assertExactOutput(
            $execute($this->staticHandoff->rollbackCommandFor(
                $state,
                $operationId,
                $token,
                $authorizedOrphanedSourceOverrideSha256,
            )),
            ControlPlaneStaticListenerHandoff::ROLLED_BACK_OUTPUT,
            'static listener rollback',
        );
        $server->refresh();
        (new SaveProxyConfiguration)->persistDatabaseState($server, $state->staticPredecessorBytes);

        if (! $wasAlreadyRollingBack) {
            return $state;
        }

        $this->assertExactOutput(
            $execute($this->dynamicWriter->rollbackEnrollmentCommandFor(
                $dynamicMutation,
                $replacementAuthority,
                $rolledBackAuthority,
            )),
            ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT,
            'dynamic Traefik document rollback',
        );

        return $this->stateStore->transition(
            $server,
            $operationId,
            $token,
            ControlPlaneProxyEnrollmentPhase::RollingBack,
            ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement,
            now()->toIso8601String(),
        );
    }

    /** @param Closure(string): ?string $execute */
    private function acknowledgeRestoredRoutes(
        Server $server,
        ControlPlaneProxyEnrollmentState $state,
        string $operationId,
        string $token,
        Closure $execute,
    ): ControlPlaneProxyEnrollmentState {
        $this->assertExactOutput(
            $execute($this->staticHandoff->reassertAwaitingRollbackCommandFor($state, $operationId, $token)),
            ControlPlaneStaticListenerHandoff::ROLLED_BACK_OUTPUT,
            'static listener rollback reassertion',
        );

        $expectedMember = 'coolify';
        $expectedRevision = 'rollback-'.$state->dynamicRevision;
        $expectedDynamicSha256 = hash('sha256', $state->dynamicPredecessorBytes ?? '');
        $derivedHealthProof = hash_hmac(
            'sha256',
            ControlPlaneDynamicConfiguration::HEALTH_PROOF_DERIVATION_CONTEXT,
            $token,
        );
        $marker = ControlPlaneCandidateHealthMarker::fromDerivedHealthProof(
            operationId: $operationId,
            expectedMember: $expectedMember,
            expectedRevision: $expectedRevision,
            dynamicSha256: $expectedDynamicSha256,
            derivedHealthProof: $derivedHealthProof,
        );
        $execute($this->candidateMarkerInstaller->handle($marker, ['coolify']));

        $proof = new ControlPlaneRestoredRoutesProof(
            canonicalHost: $state->canonicalHost,
            publicScheme: $state->publicScheme,
            appPort: $state->appPort,
            expectedBackendMember: $expectedMember,
            expectedBackendRevision: $expectedRevision,
            expectedDynamicPredecessorSha256: $expectedDynamicSha256,
            publicRouteExpected: $state->dynamicPredecessorBytes !== null,
        );
        $transcript = $execute($proof->shellCommand());
        if (! is_string($transcript)) {
            throw new RuntimeException('The restored control-plane route proof returned no transcript.');
        }
        $this->restoredRoutesVerifier->handle($proof, $transcript);

        $dynamicMutation = $this->rollbackMutation($server, $state);
        $finalizationStatus = $execute(
            $this->dynamicWriter->inspectEnrollmentRollbackFinalizationCommandFor($dynamicMutation),
        );
        if (is_string($finalizationStatus)
            && hash_equals(ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_CLEANUP_PENDING_OUTPUT, trim($finalizationStatus))) {
            $this->assertExactOutput(
                $execute($this->dynamicWriter->finalizePartialEnrollmentRollbackCommandFor($dynamicMutation)),
                ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT,
                'partial dynamic Traefik document rollback finalization',
            );
            $finalizationStatus = ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT;
        }
        if (! is_string($finalizationStatus)
            || ! hash_equals(ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT, trim($finalizationStatus))) {
            $this->assertExactOutput(
                $finalizationStatus,
                ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_PENDING_OUTPUT,
                'dynamic Traefik document rollback finalization inspection',
            );
            [, $rolledBackAuthority] = $this->rollbackAuthorities($state, $dynamicMutation, $execute);
            $this->assertExactOutput(
                $execute($this->dynamicWriter->finalizeEnrollmentRollbackCommandFor(
                    $dynamicMutation,
                    $rolledBackAuthority,
                )),
                ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT,
                'dynamic Traefik document rollback finalization',
            );
        }

        return $this->stateStore->transition(
            $server,
            $operationId,
            $token,
            ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement,
            ControlPlaneProxyEnrollmentPhase::RolledBack,
            now()->toIso8601String(),
        );
    }

    /**
     * @param  Closure(string): ?string  $execute
     * @return array{ManagedTraefikDocumentWriterAuthority, ManagedTraefikDocumentWriterAuthority}
     */
    private function rollbackAuthorities(
        ControlPlaneProxyEnrollmentState $state,
        ManagedTraefikDocumentMutation $mutation,
        Closure $execute,
    ): array {
        $authorityTranscript = $execute($this->writerAuthorityInspector->commandFor($mutation));
        if (! is_string($authorityTranscript)) {
            throw new RuntimeException('The control-plane enrollment writer authority inspection returned no transcript.');
        }
        $existingAuthority = $this->writerAuthorityInspector->handle($authorityTranscript);
        if ($existingAuthority === null) {
            $writerContainerName = $state->activeBackendDnsNames[0];
            $identityTranscript = $execute($this->writerInspector->commandFor($writerContainerName));
            if (! is_string($identityTranscript)) {
                throw new RuntimeException('The control-plane enrollment writer inspection returned no transcript.');
            }
            $writerIdentity = $this->writerInspector->handle($identityTranscript, $writerContainerName);
        } else {
            $writerIdentity = new ControlPlaneEnrollmentWriterIdentity(
                containerId: $existingAuthority->containerId,
                containerName: $existingAuthority->containerName,
                imageId: $existingAuthority->imageId,
            );
        }
        $replacementAuthority = $this->writerAuthorityBootstrap->authorityFor($state, $writerIdentity);
        $rolledBackAuthority = $this->writerAuthorityBootstrap->rolledBackAuthorityFor($state, $writerIdentity);
        if ($existingAuthority !== null
            && ! hash_equals($existingAuthority->toJson(), $replacementAuthority->toJson())
            && ! hash_equals($existingAuthority->toJson(), $rolledBackAuthority->toJson())) {
            throw new RuntimeException('The control-plane enrollment writer authority is not owned by this exact rollback.');
        }

        return [$replacementAuthority, $rolledBackAuthority];
    }

    private function rollbackMutation(
        Server $server,
        ControlPlaneProxyEnrollmentState $state,
    ): ManagedTraefikDocumentMutation {
        $proxyPath = rtrim((string) $server->proxyPath(), '/');

        return new ManagedTraefikDocumentMutation(
            dynamicDirectory: $proxyPath.'/dynamic',
            stateDirectory: $proxyPath.'/.control-plane-managed-traefik',
            filename: $state->managedFilename,
            operationId: $state->operationId,
            revision: $state->dynamicRevision,
            expectedSha256: $state->dynamicPredecessorBytes === null
                ? null
                : hash('sha256', $state->dynamicPredecessorBytes),
            expectedOperationId: null,
            expectedRevision: null,
            replacementBytes: $state->dynamicReplacementBytes,
        );
    }

    private function ownedState(Server $server, string $operationId, string $token): ControlPlaneProxyEnrollmentState
    {
        $state = $this->stateStore->read($server)
            ?? throw new RuntimeException('The durable control-plane enrollment state is missing.');
        if ($state->serverId !== (int) $server->getKey() || ! $state->isOwnedBy($operationId, $token)) {
            throw new RuntimeException('The durable control-plane enrollment state is owned by another operation.');
        }

        return $state;
    }

    private function assertExactOutput(?string $output, string $expected, string $operation): void
    {
        if (! is_string($output) || ! hash_equals($expected, trim($output))) {
            throw new RuntimeException("The control-plane {$operation} did not return its exact completion proof.");
        }
    }
}
