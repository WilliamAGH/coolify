<?php

namespace App\Actions\Application\BlueGreen;

enum BlueGreenDeactivationRemoteOutcome: string
{
    case Success = 'success';
    case Deferred = 'deferred';
    case InvariantViolation = 'invariant';
}
