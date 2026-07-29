<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Actions\Proxy\SaveProxyConfiguration;
use App\Models\Server;
use Closure;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class ActivateControlPlaneProxyEnrollment
{
    use AsAction;

    public function __construct(
        private readonly StoreControlPlaneProxyEnrollmentState $stateStore,
        private readonly NormalizeControlPlaneEnrollmentFilesystem $filesystemNormalizer,
        private readonly InstallControlPlaneCandidateHealthMarkers $candidateMarkerInstaller,
        private readonly VerifyControlPlaneCandidateMembers $candidateVerifier,
        private readonly ManagedTraefikDocumentWriter $dynamicWriter,
        private readonly ControlPlaneStaticListenerHandoff $staticHandoff,
        private readonly InspectControlPlaneEnrollmentWriter $writerInspector,
        private readonly InspectControlPlaneEnrollmentWriterAuthority $writerAuthorityInspector,
        private readonly BootstrapControlPlaneEnrollmentWriterAuthority $writerAuthorityBootstrap,
    ) {}

    /** @param null|Closure(string): ?string $remoteExecutor */
    public function handle(
        Server $server,
        string $operationId,
        string $token,
        ?Closure $remoteExecutor = null,
    ): ControlPlaneProxyEnrollmentState {
        return $this->stateStore->serializeOperation(
            $server,
            fn (Server $lockedServer): ControlPlaneProxyEnrollmentState => $this->handleLocked(
                $lockedServer,
                $operationId,
                $token,
                $remoteExecutor,
            ),
        );
    }

    /** @param null|Closure(string): ?string $remoteExecutor */
    private function handleLocked(
        Server $server,
        string $operationId,
        string $token,
        ?Closure $remoteExecutor,
    ): ControlPlaneProxyEnrollmentState {
        $state = $this->ownedState($server, $operationId, $token);
        if ($state->phase === ControlPlaneProxyEnrollmentPhase::Enrolled) {
            return $state;
        }
        $execute = $remoteExecutor ?? static fn (string $command): ?string => instant_remote_process(
            [$command],
            $server,
            timeout: 120,
            disableMultiplexing: true,
            retry: false,
        );
        if (in_array($state->phase, [
            ControlPlaneProxyEnrollmentPhase::Active,
            ControlPlaneProxyEnrollmentPhase::Finalizing,
        ], true)) {
            $this->repairActivatedEnrollment($server, $state, $execute);

            return $state;
        }
        if ($state->phase === ControlPlaneProxyEnrollmentPhase::Preparing) {
            $state = $this->stateStore->transition(
                $server,
                $operationId,
                $token,
                ControlPlaneProxyEnrollmentPhase::Preparing,
                ControlPlaneProxyEnrollmentPhase::Prepared,
                now()->toIso8601String(),
            );
        }
        if (! in_array($state->phase, [
            ControlPlaneProxyEnrollmentPhase::Prepared,
            ControlPlaneProxyEnrollmentPhase::Activating,
        ], true)) {
            throw new RuntimeException("Control-plane enrollment cannot activate from {$state->phase->value}.");
        }

        $wasAlreadyActivating = $state->phase === ControlPlaneProxyEnrollmentPhase::Activating;
        if (! $wasAlreadyActivating) {
            $state = $this->stateStore->transition(
                $server,
                $operationId,
                $token,
                ControlPlaneProxyEnrollmentPhase::Prepared,
                ControlPlaneProxyEnrollmentPhase::Activating,
                now()->toIso8601String(),
            );
        }
        $proxyPath = rtrim((string) $server->proxyPath(), '/');
        $this->assertExactOutput(
            $execute($this->filesystemNormalizer->commandFor($proxyPath)),
            NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
            'control-plane enrollment filesystem normalization',
        );
        $derivedHealthProof = hash_hmac(
            'sha256',
            ControlPlaneDynamicConfiguration::HEALTH_PROOF_DERIVATION_CONTEXT,
            $token,
        );
        $candidateMarker = ControlPlaneCandidateHealthMarker::fromDerivedHealthProof(
            operationId: $state->operationId,
            expectedMember: $state->expectedMember,
            expectedRevision: $state->expectedRevision,
            dynamicSha256: hash('sha256', $state->dynamicReplacementBytes),
            derivedHealthProof: $derivedHealthProof,
        );
        $execute($this->candidateMarkerInstaller->handle($candidateMarker, $state->activeBackendDnsNames));

        $candidateProof = new ControlPlaneCandidateMembersProof(
            candidateNames: $state->activeBackendDnsNames,
            expectedMember: $state->expectedMember,
            expectedRevision: $state->expectedRevision,
            dynamicSha256: hash('sha256', $state->dynamicReplacementBytes),
            healthCheckProof: $derivedHealthProof,
        );
        $candidateTranscript = $execute($candidateProof->shellCommand());
        if (! is_string($candidateTranscript)) {
            throw new RuntimeException('The direct control-plane candidate proof returned no transcript.');
        }
        $this->candidateVerifier->handle($candidateProof, $candidateTranscript);

        $mutation = $this->dynamicMutation($server, $state);
        $writerAuthority = $this->writerAuthority($state, $execute);
        $authorityTranscript = $execute($this->writerAuthorityInspector->commandFor($mutation));
        if (! is_string($authorityTranscript)) {
            throw new RuntimeException('The control-plane enrollment writer authority inspection returned no transcript.');
        }
        $existingAuthority = $this->writerAuthorityInspector->handle($authorityTranscript);
        if ($existingAuthority === null) {
            $this->assertExactOutput(
                $execute($this->dynamicWriter->writeCommandFor($mutation)),
                ManagedTraefikDocumentWriter::APPLIED_OUTPUT,
                'dynamic Traefik document',
            );
        } elseif (! hash_equals($existingAuthority->toJson(), $writerAuthority->toJson())) {
            $transportLfRepairProvenance = $this->stateStore
                ->hasDynamicPredecessorTerminalLfRepairProvenance(
                    $server,
                    $state,
                    $operationId,
                    $token,
                );
            if (! $this->canSupersedeOrphanedInitialEnrollmentAuthority(
                $state,
                $wasAlreadyActivating,
                $existingAuthority,
                $writerAuthority,
                $transportLfRepairProvenance,
            )) {
                throw $this->writerAuthorityOwnershipException();
            }

            if (! str_ends_with($state->dynamicPredecessorBytes ?? '', "\n")) {
                $state = $this->stateStore->repairDynamicPredecessorTerminalLfIfUnchanged(
                    $server,
                    $state,
                    $operationId,
                    $token,
                );
            }
            $transportLfRepairProvenance = $this->stateStore
                ->hasDynamicPredecessorTerminalLfRepairProvenance(
                    $server,
                    $state,
                    $operationId,
                    $token,
                );
            if (! $transportLfRepairProvenance) {
                throw $this->writerAuthorityOwnershipException();
            }
            $mutation = $this->dynamicMutation($server, $state);
            $writerAuthority = $this->writerAuthorityBootstrap->authorityFor(
                $state,
                new ControlPlaneEnrollmentWriterIdentity(
                    containerId: $writerAuthority->containerId,
                    containerName: $writerAuthority->containerName,
                    imageId: $writerAuthority->imageId,
                ),
            );
            try {
                $supersessionCommand = $this->dynamicWriter->supersedeOrphanedInitialEnrollmentAuthorityCommandFor(
                    mutation: $mutation,
                    correctedPredecessorBytes: $state->dynamicPredecessorBytes ?? '',
                    orphanedAuthority: $existingAuthority,
                    targetAuthority: $writerAuthority,
                    transportLfRepairProvenance: $transportLfRepairProvenance,
                );
            } catch (InvalidArgumentException) {
                throw $this->writerAuthorityOwnershipException();
            }
            $supersessionOutput = $execute($supersessionCommand);
            if (! is_string($supersessionOutput)
                || ! hash_equals(
                    ManagedTraefikDocumentWriter::ORPHANED_ENROLLMENT_AUTHORITY_SUPERSEDED_OUTPUT,
                    trim($supersessionOutput),
                )) {
                throw $this->writerAuthorityOwnershipException();
            }
        }

        $this->assertExactOutput(
            $execute($this->staticHandoff->commandFor($state)),
            ControlPlaneStaticListenerHandoff::APPLIED_OUTPUT,
            'static listener handoff',
        );
        $server->refresh();
        (new SaveProxyConfiguration)->persistDatabaseState($server, $state->staticReplacementBytes);

        if (! $wasAlreadyActivating) {
            return $state;
        }

        $this->bootstrapActivatedWriterAuthority($mutation, $writerAuthority, $execute);

        return $this->stateStore->transition(
            $server,
            $operationId,
            $token,
            ControlPlaneProxyEnrollmentPhase::Activating,
            ControlPlaneProxyEnrollmentPhase::Active,
            now()->toIso8601String(),
        );
    }

    /** @param Closure(string): ?string $execute */
    private function repairActivatedEnrollment(
        Server $server,
        ControlPlaneProxyEnrollmentState $state,
        Closure $execute,
    ): void {
        $proxyPath = rtrim((string) $server->proxyPath(), '/');
        $this->assertExactOutput(
            $execute($this->filesystemNormalizer->commandFor($proxyPath)),
            NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
            'control-plane enrollment filesystem normalization',
        );
        $mutation = $this->dynamicMutation($server, $state);
        $authorityTranscript = $execute($this->writerAuthorityInspector->commandFor($mutation));
        if (! is_string($authorityTranscript)) {
            throw new RuntimeException('The control-plane enrollment writer authority inspection returned no transcript.');
        }
        $existingAuthority = $this->writerAuthorityInspector->handle($authorityTranscript);
        if ($existingAuthority === null) {
            $this->bootstrapActivatedWriterAuthority(
                $mutation,
                $this->writerAuthority($state, $execute),
                $execute,
            );

            return;
        }
        $expectedAuthority = $this->writerAuthorityBootstrap->authorityFor(
            $state,
            new ControlPlaneEnrollmentWriterIdentity(
                containerId: $existingAuthority->containerId,
                containerName: $existingAuthority->containerName,
                imageId: $existingAuthority->imageId,
            ),
        );
        if (! hash_equals($existingAuthority->toJson(), $expectedAuthority->toJson())) {
            throw new RuntimeException('The control-plane enrollment writer authority is not owned by this exact enrollment.');
        }
    }

    /** @param Closure(string): ?string $execute */
    private function bootstrapActivatedWriterAuthority(
        ManagedTraefikDocumentMutation $mutation,
        ManagedTraefikDocumentWriterAuthority $writerAuthority,
        Closure $execute,
    ): void {
        $this->assertExactOutput(
            $execute($this->writerAuthorityBootstrap->commandFor($mutation, $writerAuthority)),
            BootstrapControlPlaneEnrollmentWriterAuthority::APPLIED_OUTPUT,
            'control-plane enrollment writer authority bootstrap',
        );
    }

    /** @param Closure(string): ?string $execute */
    private function writerAuthority(
        ControlPlaneProxyEnrollmentState $state,
        Closure $execute,
    ): ManagedTraefikDocumentWriterAuthority {
        $writerContainerName = $state->activeBackendDnsNames[0];
        $inspectionTranscript = $execute($this->writerInspector->commandFor($writerContainerName));
        if (! is_string($inspectionTranscript)) {
            throw new RuntimeException('The control-plane enrollment writer inspection returned no transcript.');
        }
        $writerIdentity = $this->writerInspector->handle($inspectionTranscript, $writerContainerName);

        return $this->writerAuthorityBootstrap->authorityFor($state, $writerIdentity);
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

    private function dynamicMutation(Server $server, ControlPlaneProxyEnrollmentState $state): ManagedTraefikDocumentMutation
    {
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

    private function canSupersedeOrphanedInitialEnrollmentAuthority(
        ControlPlaneProxyEnrollmentState $state,
        bool $wasAlreadyActivating,
        ManagedTraefikDocumentWriterAuthority $existingAuthority,
        ManagedTraefikDocumentWriterAuthority $targetAuthority,
        bool $transportLfRepairProvenance,
    ): bool {
        $predecessorBytes = $state->dynamicPredecessorBytes;
        $predecessorEndsWithLf = is_string($predecessorBytes) && str_ends_with($predecessorBytes, "\n");
        if (! $wasAlreadyActivating
            || $state->phase !== ControlPlaneProxyEnrollmentPhase::Activating
            || $predecessorBytes === null
            || $predecessorBytes === ''
            || $state->dynamicRevision !== 1
            || $existingAuthority->epoch !== 1
            || $existingAuthority->dynamicRevision !== 1
            || $targetAuthority->epoch !== 1
            || $targetAuthority->dynamicRevision !== 1
            || $predecessorEndsWithLf !== $transportLfRepairProvenance
            || hash_equals($existingAuthority->operationId, $state->operationId)
            || hash_equals($existingAuthority->containerId, $targetAuthority->containerId)) {
            return false;
        }

        $correctedPredecessorBytes = str_ends_with($predecessorBytes, "\n")
            ? $predecessorBytes
            : $predecessorBytes."\n";
        $correctedPredecessorSha256 = hash('sha256', $correctedPredecessorBytes);
        $replacementSha256 = hash('sha256', $state->dynamicReplacementBytes);

        return ! str_ends_with($correctedPredecessorBytes, "\n\n")
            && ! hash_equals($correctedPredecessorSha256, $replacementSha256)
            && ! hash_equals($existingAuthority->dynamicSha256, $correctedPredecessorSha256)
            && ! hash_equals($existingAuthority->dynamicSha256, $replacementSha256);
    }

    private function writerAuthorityOwnershipException(): RuntimeException
    {
        return new RuntimeException('The control-plane enrollment writer authority is not owned by this exact enrollment.');
    }

    private function assertExactOutput(?string $output, string $expected, string $operation): void
    {
        if (! is_string($output) || ! hash_equals($expected, trim($output))) {
            throw new RuntimeException("The control-plane {$operation} did not return its exact completion proof.");
        }
    }
}
