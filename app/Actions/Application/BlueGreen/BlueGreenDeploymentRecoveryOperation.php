<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyRollbackKey;
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
}
