<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Models\Server;
use Closure;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class ReconcileRolledBackControlPlaneProxyEnrollment
{
    use AsAction;

    public function __construct(
        private readonly StoreControlPlaneProxyEnrollmentState $stateStore,
        private readonly NormalizeControlPlaneEnrollmentFilesystem $filesystemNormalizer,
        private readonly ManagedTraefikDocumentWriter $dynamicWriter,
        private readonly InspectControlPlaneEnrollmentWriterAuthority $writerAuthorityInspector,
        private readonly InspectControlPlaneEnrollmentWriter $writerInspector,
        private readonly BootstrapControlPlaneEnrollmentWriterAuthority $writerAuthorityBootstrap,
    ) {}

    /** @param null|Closure(string): ?string $remoteExecutor */
    public function handle(
        Server $server,
        ControlPlaneProxyEnrollmentState $state,
        ?Closure $remoteExecutor = null,
    ): void {
        $this->assertExactRolledBackState($server, $state);
        $this->stateStore->assertRollbackAvailable($server);

        $execute = $remoteExecutor ?? static fn (string $command): ?string => instant_remote_process(
            [$command],
            $server,
            timeout: 120,
            disableMultiplexing: true,
            retry: false,
        );
        $proxyPath = rtrim((string) $server->proxyPath(), '/');
        $mutation = $this->rollbackMutation($server, $state);
        $finalizationStatus = $execute($this->dynamicWriter->inspectEnrollmentRollbackFinalizationCommandFor($mutation));
        if (is_string($finalizationStatus)
            && hash_equals(ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT, trim($finalizationStatus))) {
            return;
        }
        if (is_string($finalizationStatus)
            && hash_equals(ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_CLEANUP_PENDING_OUTPUT, trim($finalizationStatus))) {
            $this->assertExactOutput(
                $execute($this->dynamicWriter->finalizePartialEnrollmentRollbackCommandFor($mutation)),
                ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT,
                'partial dynamic Traefik document rollback finalization',
            );

            return;
        }
        $this->assertExactOutput(
            $finalizationStatus,
            ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_PENDING_OUTPUT,
            'dynamic Traefik document rollback finalization inspection',
        );

        $authorityTranscript = $execute($this->writerAuthorityInspector->commandFor($mutation));
        if (! is_string($authorityTranscript)) {
            throw new RuntimeException('The control-plane enrollment writer authority inspection returned no transcript.');
        }
        $existingAuthority = $this->writerAuthorityInspector->handle($authorityTranscript);
        $writerIdentity = $existingAuthority === null
            ? $this->inspectFirstBackendWriterIdentity($state, $execute)
            : new ControlPlaneEnrollmentWriterIdentity(
                containerId: $existingAuthority->containerId,
                containerName: $existingAuthority->containerName,
                imageId: $existingAuthority->imageId,
            );
        $replacementAuthority = $this->writerAuthorityBootstrap->authorityFor($state, $writerIdentity);
        $rolledBackAuthority = $this->writerAuthorityBootstrap->rolledBackAuthorityFor($state, $writerIdentity);

        $requiresRollback = $existingAuthority === null
            || hash_equals($existingAuthority->toJson(), $replacementAuthority->toJson());
        if (! $requiresRollback
            && ! hash_equals($existingAuthority->toJson(), $rolledBackAuthority->toJson())) {
            throw new RuntimeException('The control-plane enrollment writer authority is not owned by this exact rollback.');
        }

        $this->assertExactOutput(
            $execute($this->filesystemNormalizer->commandFor($proxyPath)),
            NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
            'control-plane enrollment filesystem normalization',
        );
        if ($requiresRollback) {
            $this->assertExactOutput(
                $execute($this->dynamicWriter->rollbackEnrollmentCommandFor(
                    $mutation,
                    $replacementAuthority,
                    $rolledBackAuthority,
                )),
                ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT,
                'dynamic Traefik document rollback',
            );
        }

        $this->assertExactOutput(
            $execute($this->dynamicWriter->finalizeEnrollmentRollbackCommandFor(
                $mutation,
                $rolledBackAuthority,
            )),
            ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT,
            'dynamic Traefik document rollback finalization',
        );
    }

    /** @param Closure(string): ?string $execute */
    private function inspectFirstBackendWriterIdentity(
        ControlPlaneProxyEnrollmentState $state,
        Closure $execute,
    ): ControlPlaneEnrollmentWriterIdentity {
        $writerContainerName = $state->activeBackendDnsNames[0]
            ?? throw new RuntimeException('The rolled-back control-plane enrollment has no active backend writer.');
        $identityTranscript = $execute($this->writerInspector->commandFor($writerContainerName));
        if (! is_string($identityTranscript)) {
            throw new RuntimeException('The control-plane enrollment writer inspection returned no transcript.');
        }

        return $this->writerInspector->handle($identityTranscript, $writerContainerName);
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

    private function assertExactRolledBackState(
        Server $server,
        ControlPlaneProxyEnrollmentState $state,
    ): void {
        if ($state->serverId !== (int) $server->getKey()
            || $state->phase !== ControlPlaneProxyEnrollmentPhase::RolledBack) {
            throw new RuntimeException('The control-plane enrollment state is not an exact rolled-back state for this server.');
        }
        $durableState = $this->stateStore->read($server);
        if ($durableState === null
            || $durableState->phase !== ControlPlaneProxyEnrollmentPhase::RolledBack
            || $durableState->toArray() !== $state->toArray()) {
            throw new RuntimeException('The durable rolled-back control-plane enrollment state changed before reconciliation.');
        }
    }

    private function assertExactOutput(?string $output, string $expected, string $operation): void
    {
        if (! is_string($output) || ! hash_equals($expected, trim($output))) {
            throw new RuntimeException("The control-plane {$operation} did not return its exact completion proof.");
        }
    }
}
