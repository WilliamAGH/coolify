<?php

namespace App\Actions\Proxy;

enum BlueGreenRoutingMode
{
    case Steady;
    case LegacyAdoption;
    case LegacyRecoveryBridge;
}
