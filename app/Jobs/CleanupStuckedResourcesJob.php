<?php

namespace App\Jobs;

use App\Contracts\ProxyMutation;
use App\Support\ProxyMutationQueue;
use App\Support\UsesProxyMutationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanupStuckedResourcesJob implements ProxyMutation, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use UsesProxyMutationQueue;

    public function __construct()
    {
        ProxyMutationQueue::assign($this);
    }

    public function handle(ConsoleKernel $kernel): void
    {
        ProxyMutationQueue::ensureExecutionAllowed();

        $kernel->call('cleanup:stucked-resources');
    }
}
