<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentPhase;

final readonly class BlueGreenInterventionRecoveryPlan
{
    public function __construct(
        public string $classification,
        public string $message,
        public bool $isIntervention,
        public ?int $stateId = null,
        public ?int $deactivationId = null,
        public ?BlueGreenDeploymentPhase $deploymentPhase = null,
        public ?BlueGreenDeactivationPhase $deactivationPhase = null,
    ) {}
}
