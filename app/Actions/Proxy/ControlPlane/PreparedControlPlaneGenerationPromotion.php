<?php

namespace App\Actions\Proxy\ControlPlane;

final readonly class PreparedControlPlaneGenerationPromotion
{
    public function __construct(
        public ControlPlaneGenerationPromotionState $state,
        public ControlPlaneDynamicConfiguration $successorConfiguration,
    ) {}
}
