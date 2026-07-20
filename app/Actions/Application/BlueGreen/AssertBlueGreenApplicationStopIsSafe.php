<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeactivationPhase;
use App\Models\Application;
use Lorisleiva\Actions\Concerns\AsAction;

final class AssertBlueGreenApplicationStopIsSafe
{
    use AsAction;

    public function handle(Application $application): void
    {
        if (! $application->requiresBlueGreenDeactivation()) {
            return;
        }

        $completedDeletion = $application->trashed()
            && ! $application->blueGreenDeployments()->exists()
            && $application->blueGreenDeactivations()->exists()
            && ! $application->blueGreenDeactivations()
                ->where('phase', '!=', BlueGreenDeactivationPhase::COMPLETED->value)
                ->exists();
        if ($completedDeletion) {
            return;
        }

        throw new BlueGreenDeactivationInProgressException(
            'Stopping a blue-green application requires a dedicated lifecycle owner; no containers were changed.',
        );
    }
}
