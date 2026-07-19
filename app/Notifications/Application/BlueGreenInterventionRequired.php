<?php

namespace App\Notifications\Application;

use App\Models\Application;

class BlueGreenInterventionRequired extends BlueGreenLifecycleNotification
{
    public function __construct(Application $application, ?string $deploymentUuid = null)
    {
        parent::__construct($application, $deploymentUuid);
    }

    protected function title(): string
    {
        return 'Blue/green intervention required';
    }

    protected function description(): string
    {
        return "Automatic blue/green recovery stopped because operator intervention is required for {$this->applicationName}.";
    }

    protected function blueGreenEvent(): string
    {
        return 'intervention_required';
    }

    protected function mailSubject(): string
    {
        return "Coolify: Action required for blue/green deployment of {$this->applicationName}.";
    }

    protected function mailView(): string
    {
        return 'emails.application-blue-green-intervention-required';
    }
}
