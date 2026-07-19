<?php

namespace App\Actions\Application\BlueGreen;

enum BlueGreenIdleRouteReconciliationOutcome: string
{
    case Busy = 'busy';
    case Unchanged = 'unchanged';
    case Repaired = 'repaired';
}
