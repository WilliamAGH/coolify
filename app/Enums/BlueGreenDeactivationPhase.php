<?php

namespace App\Enums;

enum BlueGreenDeactivationPhase: string
{
    case DEACTIVATING = 'deactivating';
    case COMPLETED = 'completed';
    case INTERVENTION_REQUIRED = 'intervention_required';
}
