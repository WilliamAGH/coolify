<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;

final readonly class BlueGreenDeploymentRecoveryOperation
{
    public function __construct(
        public BlueGreenDeploymentClaim $claim,
        public Application $application,
        public StandaloneDocker $destination,
        public Server $server,
        public ApplicationDeploymentQueue $deployment,
        public ?BlueGreenContainerExpectation $previousContainer,
        public ?BlueGreenLegacyRoutingSnapshot $legacyRoutingSnapshot,
        public BlueGreenContainerExpectation $candidateContainer,
        public BlueGreenProxyRollbackKey $rollbackKey,
        public ?BlueGreenProxyState $currentDestinationState,
        public BlueGreenDeploymentPhase $recoveredPhase,
        public bool $routingMutationRecorded,
        public bool $wasFinalized,
    ) {}

    public function restoredRoutingRevision(): int
    {
        if ($this->claim->previousActiveColor === null) {
            return $this->claim->expectedRoutingRevision - 1;
        }

        return $this->previousContainer?->routingRevision
            ?? throw new \LogicException('A fixed-color rollback operation has no previous routing revision.');
    }

    public function restoredDestinationState(): BlueGreenProxyState
    {
        $currentState = $this->currentDestinationState
            ?? throw new \LogicException('A routed recovery operation has no current destination state.');
        $destinationFenceEpoch = $currentState->destinationFenceEpoch + 1;
        $mutationSequence = $currentState->mutationSequence + 1;

        return $this->rollbackKey->expectedState?->withDestinationFenceEpoch(
            $destinationFenceEpoch,
            $this->claim->deploymentUuid,
            $mutationSequence,
        ) ?? $currentState->withoutManagedRoute(
            $destinationFenceEpoch,
            $this->claim->deploymentUuid,
            $mutationSequence,
        );
    }

    public function withDiscoveredRoutingMutation(BlueGreenProxyRollbackKey $rollbackKey): self
    {
        return new self(
            claim: $this->claim,
            application: $this->application,
            destination: $this->destination,
            server: $this->server,
            deployment: $this->deployment,
            previousContainer: $this->previousContainer,
            legacyRoutingSnapshot: $this->legacyRoutingSnapshot,
            candidateContainer: $this->candidateContainer,
            rollbackKey: $rollbackKey,
            currentDestinationState: $rollbackKey->replacementState,
            recoveredPhase: $this->recoveredPhase,
            routingMutationRecorded: true,
            wasFinalized: $this->wasFinalized,
        );
    }
}
