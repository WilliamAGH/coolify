<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Models\Server;
use App\Support\ProxyMutationQueue;
use App\Support\ProxyMutationQueueSnapshot;
use Closure;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class ProveAndFreezeControlPlaneGeneration
{
    use AsAction;

    public function __construct(
        private readonly StoreControlPlaneGenerationPromotionState $stateStore,
        private readonly InstallControlPlaneCandidateHealthMarkers $candidateMarkerInstaller,
        private readonly VerifyControlPlaneCandidateMembers $candidateVerifier,
    ) {}

    /** @param null|Closure(string): ?string $remoteExecutor */
    public function handle(
        Server $server,
        string $operationId,
        string $token,
        ?Closure $remoteExecutor = null,
    ): ControlPlaneGenerationPromotionState {
        $state = $this->ownedState($server, $operationId, $token);
        if (in_array($state->phase, [
            ControlPlaneGenerationPromotionPhase::Quiesced,
            ControlPlaneGenerationPromotionPhase::Completed,
            ControlPlaneGenerationPromotionPhase::RolledBack,
        ], true)) {
            return $state;
        }
        if (! in_array($state->phase, [
            ControlPlaneGenerationPromotionPhase::Prepared,
            ControlPlaneGenerationPromotionPhase::CandidateProving,
            ControlPlaneGenerationPromotionPhase::CandidateProven,
            ControlPlaneGenerationPromotionPhase::Freezing,
            ControlPlaneGenerationPromotionPhase::Frozen,
            ControlPlaneGenerationPromotionPhase::Quiescing,
        ], true)) {
            throw new RuntimeException("Control-plane generation cannot prove and freeze from {$state->phase->value}.");
        }

        if ($state->phase === ControlPlaneGenerationPromotionPhase::Prepared) {
            $state = $this->transition(
                server: $server,
                operationId: $operationId,
                token: $token,
                expectedPhase: ControlPlaneGenerationPromotionPhase::Prepared,
                nextPhase: ControlPlaneGenerationPromotionPhase::CandidateProving,
            );
        }
        if ($state->phase === ControlPlaneGenerationPromotionPhase::CandidateProving) {
            $this->proveCandidate(
                server: $server,
                state: $state,
                token: $token,
                remoteExecutor: $remoteExecutor,
            );
            $state = $this->transition(
                server: $server,
                operationId: $operationId,
                token: $token,
                expectedPhase: ControlPlaneGenerationPromotionPhase::CandidateProving,
                nextPhase: ControlPlaneGenerationPromotionPhase::CandidateProven,
            );
        }
        if ($state->phase === ControlPlaneGenerationPromotionPhase::CandidateProven) {
            $state = $this->transition(
                server: $server,
                operationId: $operationId,
                token: $token,
                expectedPhase: ControlPlaneGenerationPromotionPhase::CandidateProven,
                nextPhase: ControlPlaneGenerationPromotionPhase::Freezing,
            );
        }
        if ($state->phase === ControlPlaneGenerationPromotionPhase::Freezing) {
            $leaseSeconds = ProxyMutationQueue::freezeLeaseSeconds();
            $freeze = ProxyMutationQueue::freeze($state->operationId, leaseSeconds: $leaseSeconds);
            $this->assertFreezeOwner($freeze, $state);
            $freezeFence = $freeze->freezeFence
                ?? throw new RuntimeException('The control-plane generation mutation freeze did not issue a fence.');
            $observedAt = now()->toIso8601String();
            $state = $this->stateStore->transition(
                $server,
                $operationId,
                $token,
                ControlPlaneGenerationPromotionPhase::Freezing,
                ControlPlaneGenerationPromotionPhase::Frozen,
                $observedAt,
                [
                    'runtime_fence' => ['epoch' => $state->writerEpoch, 'observed_at' => $observedAt],
                    'mutation_freeze' => [
                        'operation_id' => $state->operationId,
                        'observed_at' => $observedAt,
                        'fence' => $freezeFence,
                        'heartbeat_at' => $observedAt,
                        'lease_seconds' => $leaseSeconds,
                    ],
                ],
            );
        }
        if ($state->phase === ControlPlaneGenerationPromotionPhase::Frozen) {
            $state = $this->heartbeatFreeze($server, $state, $token);
            $state = $this->transition(
                server: $server,
                operationId: $operationId,
                token: $token,
                expectedPhase: ControlPlaneGenerationPromotionPhase::Frozen,
                nextPhase: ControlPlaneGenerationPromotionPhase::Quiescing,
            );
        }
        if ($state->phase !== ControlPlaneGenerationPromotionPhase::Quiescing) {
            return $state;
        }

        $state = $this->heartbeatFreeze($server, $state, $token);
        ProxyMutationQueue::reapExpiredReservations(
            $state->operationId,
            $state->mutationFreezeFence(),
        );
        $snapshot = ProxyMutationQueue::snapshot();
        $this->assertFreezeOwner($snapshot, $state);
        if (! $snapshot->isEmpty()) {
            return $state;
        }

        $observedAt = now()->toIso8601String();

        return $this->stateStore->transition(
            $server,
            $operationId,
            $token,
            ControlPlaneGenerationPromotionPhase::Quiescing,
            ControlPlaneGenerationPromotionPhase::Quiesced,
            $observedAt,
            [
                'queue_inventory' => [
                    'pending' => $snapshot->pending,
                    'reserved' => $snapshot->reserved,
                    'delayed' => $snapshot->delayed,
                    'observed_at' => $observedAt,
                ],
            ],
        );
    }

    /** @param null|Closure(string): ?string $remoteExecutor */
    private function proveCandidate(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
        string $token,
        ?Closure $remoteExecutor,
    ): void {
        $execute = $remoteExecutor ?? static fn (string $command): ?string => instant_remote_process(
            [$command],
            $server,
            timeout: 120,
            disableMultiplexing: true,
            retry: false,
        );
        $healthProof = hash_hmac(
            'sha256',
            ControlPlaneDynamicConfiguration::HEALTH_PROOF_DERIVATION_CONTEXT,
            $token,
        );
        $marker = ControlPlaneCandidateHealthMarker::fromDerivedHealthProof(
            operationId: $state->operationId,
            expectedMember: $state->successor['member'],
            expectedRevision: $state->successor['release_revision'],
            dynamicSha256: $state->successor['dynamic_sha256'],
            derivedHealthProof: $healthProof,
        );
        $execute($this->candidateMarkerInstaller->handleExact($marker, $state->runtime->successorRuntime));

        $proof = new ControlPlaneCandidateMembersProof(
            candidateNames: array_keys($state->runtime->successorRuntime),
            expectedMember: $state->successor['member'],
            expectedRevision: $state->successor['release_revision'],
            dynamicSha256: $state->successor['dynamic_sha256'],
            healthCheckProof: $healthProof,
            candidateRuntime: $state->runtime->successorRuntime,
        );
        $transcript = $execute($proof->shellCommand());
        if (! is_string($transcript)) {
            throw new RuntimeException('The direct control-plane generation candidate proof returned no transcript.');
        }
        $this->candidateVerifier->handle($proof, $transcript);
    }

    private function transition(
        Server $server,
        string $operationId,
        string $token,
        ControlPlaneGenerationPromotionPhase $expectedPhase,
        ControlPlaneGenerationPromotionPhase $nextPhase,
    ): ControlPlaneGenerationPromotionState {
        return $this->stateStore->transition(
            $server,
            $operationId,
            $token,
            $expectedPhase,
            $nextPhase,
            now()->toIso8601String(),
        );
    }

    private function ownedState(
        Server $server,
        string $operationId,
        string $token,
    ): ControlPlaneGenerationPromotionState {
        $state = $this->stateStore->read($server)
            ?? throw new RuntimeException('The durable control-plane generation promotion state is missing.');
        if ($state->serverId !== (int) $server->getKey() || ! $state->isOwnedBy($operationId, $token)) {
            throw new RuntimeException('The durable control-plane generation promotion state is owned by another operation.');
        }

        return $state;
    }

    private function assertFreezeOwner(
        ProxyMutationQueueSnapshot $snapshot,
        ControlPlaneGenerationPromotionState $state,
    ): void {
        if ($snapshot->freezeOperationId === null
            || ! hash_equals($state->operationId, $snapshot->freezeOperationId)
            || ! $snapshot->hasFencedRenewableFreezeLease()) {
            throw new RuntimeException('The control-plane generation mutation freeze is owned by another operation.');
        }
        if ($state->mutationFreeze !== null
            && ! hash_equals($state->mutationFreezeFence(), $snapshot->freezeFence ?? '')) {
            throw new RuntimeException('The control-plane generation mutation freeze fence changed concurrently.');
        }
    }

    private function heartbeatFreeze(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
        string $token,
    ): ControlPlaneGenerationPromotionState {
        if ($state->mutationFreeze === null) {
            throw new RuntimeException('The control-plane generation mutation freeze was never durably recorded.');
        }
        $freezeFence = $state->mutationFreezeFence();
        $leaseSeconds = ProxyMutationQueue::freezeLeaseSeconds();
        $snapshot = ProxyMutationQueue::renewFreeze(
            $state->operationId,
            leaseSeconds: $leaseSeconds,
            expectedFence: $freezeFence,
        );
        $this->assertFreezeOwner($snapshot, $state);

        return $this->stateStore->heartbeatFreeze(
            $server,
            $state->operationId,
            $token,
            $state->writerEpoch,
            $leaseSeconds,
            $freezeFence,
            $freezeFence,
            now()->toIso8601String(),
        );
    }
}
