<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\ApplicationDeploymentQueue;
use Lorisleiva\Actions\Concerns\AsAction;

class BlueGreenDeploymentQueueActivity
{
    use AsAction;

    public function handle(
        ApplicationDeploymentQueue $deployment,
        int $staleAfterSeconds,
    ): bool {
        if ($staleAfterSeconds < 1) {
            throw new \InvalidArgumentException('The reconciliation stale window must be positive.');
        }

        return $deployment->updated_at !== null
            && $deployment->updated_at->gte(now()->subSeconds($staleAfterSeconds));
    }
}
