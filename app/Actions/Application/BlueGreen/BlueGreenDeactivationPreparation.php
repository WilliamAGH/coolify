<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentColor;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\StandaloneDocker;

final readonly class BlueGreenDeactivationPreparation
{
    public function __construct(
        public ?ApplicationBlueGreenDeployment $state,
        public StandaloneDocker $destination,
        public BlueGreenContainerRemovalPlan $containerRemovalPlan,
        public ApplicationBlueGreenDeactivation $deactivation,
        public ?BlueGreenDeactivationException $invariantViolation = null,
        public ?BlueGreenComposeSidecarDeactivationPlan $composeSidecarRemovalPlan = null,
    ) {}

    public function routingRevision(): int
    {
        return $this->state?->routing_revision ?? 0;
    }

    public function activeColor(): ?BlueGreenDeploymentColor
    {
        return $this->state?->active_color;
    }
}
