<?php

namespace App\Enums;

enum DeploymentDispatchClaimResult: string
{
    case CLAIMED = 'claimed';
    case DEFERRED_FOR_BLUE_GREEN_CONVERGENCE = 'deferred_for_blue_green_convergence';
    case NOT_CLAIMED = 'not_claimed';
}
