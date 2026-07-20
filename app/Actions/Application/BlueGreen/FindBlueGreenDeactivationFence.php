<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationDeploymentQueue;
use Lorisleiva\Actions\Concerns\AsAction;

class FindBlueGreenDeactivationFence
{
    use AsAction;

    public function handle(ApplicationDeploymentQueue $deployment): ?ApplicationBlueGreenDeactivation
    {
        if ($deployment->destination_id === null) {
            return null;
        }

        $deactivation = ApplicationBlueGreenDeactivation::query()
            ->where('application_id', $deployment->application_id)
            ->where('standalone_docker_id', $deployment->destination_id)
            ->first();

        return $deactivation?->fences($deployment) === true
            ? $deactivation
            : null;
    }
}
