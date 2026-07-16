<?php

namespace App\Actions\Service;

use App\Actions\Server\CleanupDocker;
use App\Contracts\ProxyMutation;
use App\Enums\ProcessStatus;
use App\Events\ServiceStatusChanged;
use App\Models\Server;
use App\Models\Service;
use App\Support\ProxyMutationQueue;
use App\Support\UsesProxyMutationQueue;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Spatie\Activitylog\Models\Activity;

class StopService implements ProxyMutation
{
    use AsAction;
    use UsesProxyMutationQueue;

    public function configureJob(JobDecorator $job): void
    {
        ProxyMutationQueue::assign($job);
    }

    public function handle(Service $service, bool $deleteConnectedNetworks = false, bool $dockerCleanup = true)
    {
        ProxyMutationQueue::ensureExecutionAllowed();

        try {
            // Cancel any in-progress deployment activities so status doesn't stay stuck at "starting"
            Activity::where('properties->type_uuid', $service->uuid)
                ->where(function ($q) {
                    $q->where('properties->status', ProcessStatus::IN_PROGRESS->value)
                        ->orWhere('properties->status', ProcessStatus::QUEUED->value);
                })
                ->each(function ($activity) {
                    $activity->properties = $activity->properties->put('status', ProcessStatus::CANCELLED->value);
                    $activity->save();
                });

            $server = $service->destination->server;
            if (! $server->isFunctional()) {
                return 'Server is not functional';
            }

            $containersToStop = [];
            $applications = $service->applications()->get();
            foreach ($applications as $application) {
                $containersToStop[] = "{$application->name}-{$service->uuid}";
            }
            $dbs = $service->databases()->get();
            foreach ($dbs as $db) {
                $containersToStop[] = "{$db->name}-{$service->uuid}";
            }

            if (! empty($containersToStop)) {
                $this->stopContainersInParallel($containersToStop, $server);
            }

            if ($deleteConnectedNetworks) {
                $service->deleteConnectedNetworks();
            }
            if ($dockerCleanup) {
                CleanupDocker::dispatch($server, false, false);
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        } finally {
            ServiceStatusChanged::dispatch($service->environment->project->team->id);
        }
    }

    private function stopContainersInParallel(array $containersToStop, Server $server): void
    {
        $timeout = count($containersToStop) > 5 ? 10 : 30;
        $commands = [];
        $containerList = implode(' ', $containersToStop);
        $commands[] = "docker stop -t $timeout $containerList";
        $commands[] = "docker rm -f $containerList";
        instant_remote_process(
            command: $commands,
            server: $server,
            throwError: false
        );
    }
}
