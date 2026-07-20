<?php

namespace App\Actions\Proxy;

enum BlueGreenRoutingMode
{
    case Steady;
    case Failover;
    case LegacyAdoption;
    case LegacyRecoveryBridge;
    case ProbeOnly;
}
