<?php

namespace App\Actions\Application\BlueGreen;

enum BlueGreenDeactivationRemoteOutcome: string
{
    case Success = 'success';
    case InvariantViolation = 'invariant';
}
