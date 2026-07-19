<?php

namespace App\Jobs;

use App\Contracts\ProxyMutation;
use App\Support\ProxyMutationQueue;
use App\Support\UsesProxyMutationQueue;

class ActivateApplicationDeploymentJob extends ApplicationDeploymentJob implements ProxyMutation
{
    use UsesProxyMutationQueue;

    public function __construct(
        int $application_deployment_queue_id,
        ?string $dispatch_attempt_uuid = null,
    ) {
        parent::__construct($application_deployment_queue_id, $dispatch_attempt_uuid);
    }

    protected function assignExecutionQueue(): void
    {
        ProxyMutationQueue::assign($this);
    }

    public function handle(): void
    {
        $this->handleActivation();
    }
}
