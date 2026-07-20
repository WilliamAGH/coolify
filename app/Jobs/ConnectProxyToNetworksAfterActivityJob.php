<?php

namespace App\Jobs;

use App\Enums\ProcessStatus;
use App\Models\Server;
use App\Support\ProxyMutationQueueFrozenException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Activitylog\Models\Activity;

class ConnectProxyToNetworksAfterActivityJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 0;

    public $maxExceptions = 1;

    public $timeout = 60;

    /** @param array<int, string> $requiredNetworks */
    public function __construct(
        public Activity $activity,
        public Server $server,
        public array $requiredNetworks = [],
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $this->activity->refresh();
        $status = $this->activity->getExtraProperty('status');
        if (in_array($status, [ProcessStatus::QUEUED->value, ProcessStatus::IN_PROGRESS->value], true)) {
            $this->release(5);

            return;
        }
        if (in_array($status, [
            ProcessStatus::ERROR->value,
            ProcessStatus::KILLED->value,
            ProcessStatus::CANCELLED->value,
            ProcessStatus::CLOSED->value,
        ], true)) {
            return;
        }
        if ($status !== ProcessStatus::FINISHED->value) {
            throw new \RuntimeException('Service start activity has an invalid terminal status.');
        }
        try {
            app(Dispatcher::class)->dispatch(new ConnectProxyToNetworksJob(
                $this->server,
                $this->requiredNetworks,
            ));
        } catch (ProxyMutationQueueFrozenException) {
            $this->release(5);
        }
    }
}
