<?php

namespace App\Actions\Service;

use App\Contracts\ProxyMutation;
use App\Models\Service;
use App\Support\ProxyMutationQueue;
use App\Support\UsesProxyMutationQueue;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;

class RestartService implements ProxyMutation
{
    use AsAction;
    use UsesProxyMutationQueue;

    public function configureJob(JobDecorator $job): void
    {
        ProxyMutationQueue::assign($job);
    }

    public function handle(Service $service, bool $pullLatestImages)
    {
        ProxyMutationQueue::ensureExecutionAllowed();

        return StartService::run(
            service: $service,
            pullLatestImages: $pullLatestImages,
            stopBeforeStart: true,
        );
    }
}
