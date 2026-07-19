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

        $mustRestoreDynamicDocument = $this->mustRestoreDynamicDocument($state, $successorYaml);
        if ($mustRestoreDynamicDocument) {
            $this->assertSuccessorYaml($state, $successorYaml);
        }

        if (! in_array($state->phase, [
            ControlPlaneGenerationPromotionPhase::RollingBack,
            ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement,
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

        $execute = $remoteExecutor ?? static fn (string $command): ?string => instant_remote_process(
            [$command],
            $server,
            timeout: 120,
            disableMultiplexing: true,
            retry: false,
        );

        if ($state->phase === ControlPlaneGenerationPromotionPhase::RollingBack) {
            if ($mustRestoreDynamicDocument) {
                $this->assertExactOutput(
                    $execute($this->dynamicWriter->rollbackCommandFor($this->dynamicMutation($server, $state, $successorYaml))),
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
            );
        }

        return $this->acknowledgeRestoredRoutes($server, $state, $operationId, $token, $execute);
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

        $enrollment = $this->enrolledRouteAnchor($server);
        $this->assertRecordedFreeze($state, ProxyMutationQueue::snapshot(), allowReleased: true);

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

        $this->releaseMutationFreeze($state);
        $acknowledgedAt = now()->toIso8601String();

        return $this->promotionStore->transition(
            $server,
            $operationId,
            $token,
            ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement,
            ControlPlaneGenerationPromotionPhase::RolledBack,
            $acknowledgedAt,
            [
                'rollback_acknowledged_at' => $acknowledgedAt,
                'rolled_back_at' => $acknowledgedAt,
            ],
        );
    }

    private function mustRestoreDynamicDocument(
        ControlPlaneGenerationPromotionState $state,
        ?string $successorYaml,
    ): bool {
        if ($state->phase === ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement) {
            return false;
        }

        if ($state->dynamicWritten !== null) {
            return true;
        }

        if (in_array($state->phase, [
            ControlPlaneGenerationPromotionPhase::Switching,
            ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement,
            ControlPlaneGenerationPromotionPhase::Draining,
            ControlPlaneGenerationPromotionPhase::Retiring,
            ControlPlaneGenerationPromotionPhase::WriterPromoting,
            ControlPlaneGenerationPromotionPhase::FenceReleasing,
            ControlPlaneGenerationPromotionPhase::Unfreezing,
        ], true)) {
            return true;
        }

        return in_array($state->phase, [
            ControlPlaneGenerationPromotionPhase::RollingBack,
            ControlPlaneGenerationPromotionPhase::InterventionRequired,
        ], true) && $successorYaml !== null;
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

    private function assertRecordedFreeze(
        ControlPlaneGenerationPromotionState $state,
        ProxyMutationQueueSnapshot $snapshot,
        bool $allowReleased = false,
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
            if ($allowReleased) {
                return;
            }
            throw new RuntimeException('The recorded control-plane generation mutation freeze is missing.');
        }
    }

    private function releaseMutationFreeze(ControlPlaneGenerationPromotionState $state): void
    {
        $snapshot = ProxyMutationQueue::snapshot();
        $this->assertRecordedFreeze($state, $snapshot, allowReleased: true);
        if (! $snapshot->isEmpty()) {
            throw new RuntimeException('The control-plane generation mutation queue must be empty before rollback completion.');
        }
        if ($state->mutationFreeze === null || $snapshot->freezeOperationId === null) {
            return;
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
