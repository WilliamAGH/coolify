<?php

namespace App\Enums;

enum BlueGreenDeploymentPhase: string
{
    case IDLE = 'idle';
    case PREPARING = 'preparing';
    case SWITCHING = 'switching';
    case ROLLING_BACK = 'rolling_back';
    case DEACTIVATING = 'deactivating';
    case INTERVENTION_REQUIRED = 'intervention_required';
}
