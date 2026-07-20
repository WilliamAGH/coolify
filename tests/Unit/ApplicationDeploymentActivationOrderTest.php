<?php

use App\Jobs\ApplicationDeploymentJob;

class ActivationOrderApplicationDeploymentJob extends ApplicationDeploymentJob
{
    /** @var array<int, string> */
    public array $activationEvents = [];

    public function activateForTest(): void
    {
        $this->activate_prepared_runtime();
    }

    protected function run_pre_deployment_command(): void
    {
        $this->activationEvents[] = 'pre-deployment';
    }

    protected function rolling_update(): void
    {
        $this->activationEvents[] = 'runtime-activation';
    }
}

it('runs the pre-deployment command immediately before runtime activation', function () {
    $job = (new ReflectionClass(ActivationOrderApplicationDeploymentJob::class))
        ->newInstanceWithoutConstructor();

    $job->activateForTest();

    expect($job->activationEvents)->toBe([
        'pre-deployment',
        'runtime-activation',
    ]);
});
