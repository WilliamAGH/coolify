<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Models\Server;
use App\Support\ProxyMutationQueue;
use App\Support\ProxyMutationQueueSnapshot;
use Closure;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class RollbackControlPlaneGenerationPromotion
{
    use AsAction;

    public function __construct(
        private readonly StoreControlPlaneGenerationPromotionState $promotionStore,
        private readonly StoreControlPlaneProxyEnrollmentState $enrollmentStore,
        private readonly ManagedTraefikDocumentWriter $dynamicWriter,
        private readonly ControlPlaneGenerationWriterAuthority $writerAuthority,
        private readonly VerifyControlPlaneRestoredRoutes $restoredRoutesVerifier,
    ) {}

    /** @param null|Closure(string): ?string $remoteExecutor */
    public function handle(
        Server $server,
        string $operationId,
        string $token,
        ?string $successorYaml = null,
        ?Closure $remoteExecutor = null,
    ): ControlPlaneGenerationPromotionState {
        $state = $this->ownedState($server, $operationId, $token);
        $execute = $remoteExecutor ?? static fn (string $command): ?string => instant_remote_process(
            [$command],
            $server,
            timeout: 120,
            disableMultiplexing: true,
            retry: false,
        );
        if ($state->legacyWriterAuthorityReconciliationRequired
            && in_array($state->phase, [
                ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement,
                ControlPlaneGenerationPromotionPhase::RollbackUnfreezing,
                ControlPlaneGenerationPromotionPhase::RolledBack,
            ], true)) {
            $mutation = $this->legacyReconciliationMutation($server, $state);
            $this->assertExactOutput(
                $execute($this->dynamicWriter->reconcileRolledBackWriterAuthorityCommandFor(
                    mutation: $mutation,
                    predecessorAuthority: $this->writerAuthority->predecessor($state),
                    rolledBackAuthority: $this->writerAuthority->rolledBack($state),
                )),
                ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT,
                'legacy dynamic Traefik document rollback reconciliation',
            );
            $this->proveRestoredRoutes($server, $state, $execute);
            $state = $this->promotionStore->reconcileLegacyRollbackWriterAuthority(
                $server,
                $operationId,
                $token,
                now()->toIso8601String(),
            );
        }
        if ($state->phase === ControlPlaneGenerationPromotionPhase::RolledBack) {
            return $state;
        }
        if ($state->phase === ControlPlaneGenerationPromotionPhase::Completed) {
            throw new RuntimeException('A completed control-plane generation promotion cannot be rolled back.');
        }
        if (in_array($state->phase, [
            ControlPlaneGenerationPromotionPhase::Retiring,
            ControlPlaneGenerationPromotionPhase::WriterPromoting,
            ControlPlaneGenerationPromotionPhase::FenceReleasing,
            ControlPlaneGenerationPromotionPhase::Unfreezing,
        ], true)) {
            throw new RuntimeException('A control-plane generation cannot be rolled back after predecessor retirement has started.');
        }

        $mustRestoreDynamicDocument = $this->mustRestoreDynamicDocument($state);
        if ($mustRestoreDynamicDocument && ! $state->legacyWriterAuthorityReconciliationRequired) {
            $this->assertSuccessorYaml($state, $successorYaml);
        }

        if (! in_array($state->phase, [
            ControlPlaneGenerationPromotionPhase::RollingBack,
            ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement,
            ControlPlaneGenerationPromotionPhase::RollbackUnfreezing,
        ], true)) {
            $startedAt = now()->toIso8601String();
            $state = $this->promotionStore->transition(
                $server,
                $operationId,
                $token,
                $state->phase,
                ControlPlaneGenerationPromotionPhase::RollingBack,
                $startedAt,
                ['rollback_started_at' => $startedAt],
            );
        }

        if ($state->phase === ControlPlaneGenerationPromotionPhase::RollingBack) {
            if ($mustRestoreDynamicDocument) {
                $this->assertRecordedFreeze($state, ProxyMutationQueue::snapshot());
                if ($state->legacyWriterAuthorityReconciliationRequired) {
                    $mutation = $this->legacyReconciliationMutation($server, $state);
                    $command = $this->dynamicWriter->reconcileRolledBackWriterAuthorityCommandFor(
                        mutation: $mutation,
                        predecessorAuthority: $this->writerAuthority->predecessor($state),
                        rolledBackAuthority: $this->writerAuthority->rolledBack($state),
                    );
                } else {
                    $mutation = $this->dynamicMutation($server, $state, $successorYaml);
                    $command = $this->dynamicWriter->rollbackCommandForRequiringPredecessorAuthority(
                        mutation: $mutation,
                        predecessorAuthority: $this->writerAuthority->predecessor($state),
                        rolledBackAuthority: $this->writerAuthority->rolledBack($state),
                        allowInitialOrPreWriteReconciliation: $state->dynamicWritten === null
                            && $this->isInitialGeneration($server, $state),
                        allowMissingArtifactNoop: $state->dynamicWritten === null,
                    );
                }
                $this->assertExactOutput(
                    $execute($command),
                    ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT,
                    'dynamic Traefik document rollback',
                );
            }

            $this->assertRecordedFreeze($state, ProxyMutationQueue::snapshot());

            $state = $this->promotionStore->transition(
                $server,
                $operationId,
                $token,
                ControlPlaneGenerationPromotionPhase::RollingBack,
                ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement,
                now()->toIso8601String(),
                ['rollback_writer_authority' => [
                    'operation_id' => $state->operationId,
                    'epoch' => $state->writerEpoch,
                    'fenced' => true,
                ], 'legacy_writer_authority_reconciliation_required' => false],
            );
        }

        if ($state->phase === ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement) {
            $state = $this->acknowledgeRestoredRoutes($server, $state, $operationId, $token, $execute);
        }

        return $this->completeRollbackUnfreeze($server, $state, $operationId, $token);
    }

    /** @param Closure(string): ?string $execute */
    private function acknowledgeRestoredRoutes(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
        string $operationId,
        string $token,
        Closure $execute,
    ): ControlPlaneGenerationPromotionState {
        if ($state->phase !== ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement) {
            throw new RuntimeException("Control-plane generation rollback cannot acknowledge from {$state->phase->value}.");
        }

        $this->assertRecordedFreeze($state, ProxyMutationQueue::snapshot());
        $this->proveRestoredRoutes($server, $state, $execute);
        $this->assertRecordedFreeze($state, ProxyMutationQueue::snapshot());

        $acknowledgedAt = now()->toIso8601String();

        return $this->promotionStore->transition(
            $server,
            $operationId,
            $token,
            ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement,
            ControlPlaneGenerationPromotionPhase::RollbackUnfreezing,
            $acknowledgedAt,
            ['rollback_acknowledged_at' => $acknowledgedAt],
        );
    }

    /** @param Closure(string): ?string $execute */
    private function proveRestoredRoutes(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
        Closure $execute,
    ): void {
        $enrollment = $this->enrolledRouteAnchor($server);
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
            throw new RuntimeException('The restored control-plane generation route proof returned no transcript.');
        }
        $this->restoredRoutesVerifier->handle($proof, $transcript);
    }

    private function completeRollbackUnfreeze(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
        string $operationId,
        string $token,
    ): ControlPlaneGenerationPromotionState {
        if ($state->phase !== ControlPlaneGenerationPromotionPhase::RollbackUnfreezing) {
            throw new RuntimeException("Control-plane generation rollback cannot unfreeze from {$state->phase->value}.");
        }

        $this->releaseMutationFreeze($state);
        $rolledBackAt = now()->toIso8601String();

        return $this->promotionStore->transition(
            $server,
            $operationId,
            $token,
            ControlPlaneGenerationPromotionPhase::RollbackUnfreezing,
            ControlPlaneGenerationPromotionPhase::RolledBack,
            $rolledBackAt,
            ['rolled_back_at' => $rolledBackAt],
        );
    }

    private function mustRestoreDynamicDocument(
        ControlPlaneGenerationPromotionState $state,
    ): bool {
        if (in_array($state->phase, [
            ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement,
            ControlPlaneGenerationPromotionPhase::RollbackUnfreezing,
        ], true)) {
            return false;
        }

        return true;
    }

    private function assertSuccessorYaml(ControlPlaneGenerationPromotionState $state, ?string $successorYaml): void
    {
        if ($successorYaml === null
            || ! hash_equals($state->successor['dynamic_sha256'], hash('sha256', $successorYaml))) {
            throw new RuntimeException('The successor control-plane YAML checksum does not match its durable promotion state.');
        }
    }

    private function dynamicMutation(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
        ?string $successorYaml,
    ): ManagedTraefikDocumentMutation {
        if ($successorYaml === null) {
            throw new RuntimeException('The exact successor control-plane YAML is required to roll back its managed document.');
        }

        $proxyPath = rtrim((string) $server->proxyPath(), '/');

        return new ManagedTraefikDocumentMutation(
            dynamicDirectory: $proxyPath.'/dynamic',
            stateDirectory: $proxyPath.'/.control-plane-managed-traefik',
            filename: $state->managedFilename,
            operationId: $state->operationId,
            revision: $state->successor['dynamic_revision'],
            expectedSha256: $state->predecessor['dynamic_sha256'],
            expectedOperationId: $state->predecessor['operation_id'],
            expectedRevision: $state->predecessor['dynamic_revision'],
            replacementBytes: $successorYaml,
            expectedWriterOperationId: $state->predecessorWriterOperationId,
        );
    }

    private function legacyReconciliationMutation(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
    ): ManagedTraefikDocumentMutation {
        $proxyPath = rtrim((string) $server->proxyPath(), '/');

        return new ManagedTraefikDocumentMutation(
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
    }

    private function enrolledRouteAnchor(Server $server): ControlPlaneProxyEnrollmentState
    {
        $enrollment = $this->enrollmentStore->read($server)
            ?? throw new RuntimeException('The permanent control-plane enrollment state is missing.');
        if ($enrollment->phase !== ControlPlaneProxyEnrollmentPhase::Enrolled) {
            throw new RuntimeException('The permanent control-plane enrollment route anchor is stale.');
        }

        return $enrollment;
    }

    private function isInitialGeneration(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
    ): bool {
        $enrollment = $this->enrolledRouteAnchor($server);

        return $state->matchesEnrolledWriterPredecessor($enrollment);
    }

    private function assertRecordedFreeze(
        ControlPlaneGenerationPromotionState $state,
        ProxyMutationQueueSnapshot $snapshot,
    ): void {
        if ($snapshot->freezeOperationId !== null
            && ! hash_equals($state->operationId, $snapshot->freezeOperationId)) {
            throw new RuntimeException('The control-plane generation mutation freeze is owned by another operation.');
        }
        if ($state->mutationFreeze === null) {
            if ($snapshot->freezeOperationId !== null) {
                throw new RuntimeException('The control-plane generation mutation freeze was never durably recorded.');
            }

            return;
        }
        if ($snapshot->freezeOperationId === null) {
            throw new RuntimeException('The recorded control-plane generation mutation freeze is missing.');
        }
    }

    private function releaseMutationFreeze(ControlPlaneGenerationPromotionState $state): void
    {
        $snapshot = ProxyMutationQueue::snapshot();
        if ($snapshot->freezeOperationId !== null
            && ! hash_equals($state->operationId, $snapshot->freezeOperationId)) {
            throw new RuntimeException('The control-plane generation mutation freeze is owned by another operation.');
        }
        if ($snapshot->freezeOperationId === null) {
            return;
        }
        if ($state->mutationFreeze === null) {
            throw new RuntimeException('The control-plane generation mutation freeze was never durably recorded.');
        }
        if (! $snapshot->isEmpty()) {
            throw new RuntimeException('The control-plane generation mutation queue must be empty before rollback completion.');
        }

        $released = ProxyMutationQueue::unfreeze($state->operationId);
        if ($released->freezeOperationId !== null || ! $released->isEmpty()) {
            throw new RuntimeException('The control-plane generation mutation queue did not release cleanly.');
        }
    }

    private function ownedState(
        Server $server,
        string $operationId,
        string $token,
    ): ControlPlaneGenerationPromotionState {
        $state = $this->promotionStore->read($server)
            ?? throw new RuntimeException('The durable control-plane generation promotion state is missing.');
        if ($state->serverId !== (int) $server->getKey() || ! $state->isOwnedBy($operationId, $token)) {
            throw new RuntimeException('The durable control-plane generation promotion state is owned by another operation.');
        }

        return $state;
    }

    private function assertExactOutput(?string $output, string $expected, string $operation): void
    {
        if (! is_string($output) || ! hash_equals($expected, $output)) {
            throw new RuntimeException("The control-plane {$operation} did not return its exact completion proof.");
        }
    }
}
