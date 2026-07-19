<?php

namespace App\Actions\Proxy;

use App\Contracts\ProxyMutation;
use App\Models\Server;
use App\Support\ProxyMutationQueue;
use App\Support\UsesProxyMutationQueue;
use App\Support\ValidationPatterns;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;

class RemoveProxyConnectedNetwork implements ProxyMutation
{
    use AsAction;
    use UsesProxyMutationQueue;

    public function configureJob(JobDecorator $job): void
    {
        ProxyMutationQueue::assign($job);
    }

    public function handle(Server $server, string $network): void
    {
        if (! ValidationPatterns::isValidDockerNetwork($network)) {
            throw new \InvalidArgumentException('Invalid Docker network name.');
        }

        $safeNetwork = escapeshellarg($network);
        instant_remote_process([
            "docker network disconnect {$safeNetwork} coolify-proxy 2>/dev/null || true",
            "docker network rm -f {$safeNetwork}",
        ], $server, false);
    }
}
