<?php

namespace App\Enums;

enum ApplicationDeploymentExecutionPhase: string
{
    case Prepare = 'prepare';
    case Activate = 'activate';
}
