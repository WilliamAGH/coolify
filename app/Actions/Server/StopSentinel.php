<?php

namespace App\Actions\Server;

use App\Contracts\ProxyMutation;
use App\Models\Server;
use App\Support\ProxyMutationQueue;
use App\Support\UsesProxyMutationQueue;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;

class StopSentinel implements ProxyMutation
{
    use AsAction;
    use UsesProxyMutationQueue;

    public function configureJob(JobDecorator $job): void
    {
        ProxyMutationQueue::assign($job);
    }

    public function handle(Server $server)
    {
        ProxyMutationQueue::ensureExecutionAllowed();

        instant_remote_process(['docker rm -f coolify-sentinel'], $server, false);
        $server->sentinelHeartbeat(isReset: true);
    }
}
