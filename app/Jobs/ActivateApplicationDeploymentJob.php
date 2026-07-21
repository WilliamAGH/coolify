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

    /**
     * Laravel restores queued subclasses without the private state declared on
     * ApplicationDeploymentJob. Rebuild that state from the durable queue row
     * before a worker can run either handle() or failed().
     *
     * @param  array<string, mixed>  $values
     */
    public function __unserialize(array $values): void
    {
        parent::__unserialize($values);

        parent::__construct($this->application_deployment_queue_id, $this->dispatch_attempt_uuid);
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
