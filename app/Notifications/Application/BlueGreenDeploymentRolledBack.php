<?php

namespace App\Notifications\Application;

use App\Models\Application;

class BlueGreenDeploymentRolledBack extends BlueGreenLifecycleNotification
{
    public function __construct(Application $application, string $deploymentUuid)
    {
        parent::__construct($application, $deploymentUuid);
    }

    protected function title(): string
    {
        return 'Blue/green deployment rolled back';
    }

    protected function description(): string
    {
        return "Coolify restored the previous healthy version of {$this->applicationName} after an interrupted blue/green deployment.";
    }

    protected function blueGreenEvent(): string
    {
        return 'reconciler_rollback';
    }

    protected function mailSubject(): string
    {
        return "Coolify: Blue/green deployment rolled back for {$this->applicationName}.";
    }

    protected function mailView(): string
    {
        return 'emails.application-blue-green-deployment-rolled-back';
    }
}
