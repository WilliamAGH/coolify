<?php

namespace App\Jobs;

use App\Contracts\ProxyMutation;
use App\Support\ProxyMutationQueue;
use App\Support\UsesProxyMutationQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Spatie\Activitylog\Models\Activity;

class ProxyMutationTask extends CoolifyTask implements ProxyMutation
{
    use Dispatchable;
    use UsesProxyMutationQueue;

    public function __construct(
        Activity $activity,
        bool $ignore_errors,
        mixed $call_event_on_finish,
        mixed $call_event_data,
    ) {
        parent::__construct(
            activity: $activity,
            ignore_errors: $ignore_errors,
            call_event_on_finish: $call_event_on_finish,
            call_event_data: $call_event_data,
        );

        $this->onQueue(ProxyMutationQueue::NAME);
        ProxyMutationQueue::assign($this);
    }

    public function handle(): void
    {
        ProxyMutationQueue::execute(parent::handle(...));
    }
}
