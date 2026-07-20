<?php

namespace App\Enums;

enum BlueGreenFleetStatus: string
{
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
    case PAUSED = 'paused';
}
