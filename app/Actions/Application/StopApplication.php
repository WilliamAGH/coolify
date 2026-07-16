<?php

namespace App\Actions\Application;

use App\Actions\Application\BlueGreen\DeactivateBlueGreenApplication;
use App\Actions\Server\CleanupDocker;
use App\Contracts\ProxyMutation;
use App\Events\ServiceStatusChanged;
use App\Models\Application;
use App\Support\ProxyMutationQueue;
use App\Support\UsesProxyMutationQueue;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;

class StopApplication implements ProxyMutation
{
    use AsAction;
    use UsesProxyMutationQueue;

    public function configureJob(JobDecorator $job): void
    {
        ProxyMutationQueue::assign($job);
    }

    public function handle(Application $application, bool $previewDeployments = false, bool $dockerCleanup = true, bool $resetRestartCount = true)
    {
        ProxyMutationQueue::ensureExecutionAllowed();

        $blueGreenStates = DeactivateBlueGreenApplication::run($application);
        $blueGreenContainerNames = collect([
            $application->uuid.'-blue',
            $application->uuid.'-green',
        ])->merge(
            $blueGreenStates->pluck('legacy_container_name')->filter(),
        );
        $servers = collect([$application->destination->server]);
        if ($application?->additional_servers?->count() > 0) {
            $servers = $servers->merge($application->additional_servers);
        }
        foreach ($servers as $server) {
            try {
                if (! $server->isFunctional()) {
                    return 'Server is not functional';
                }

                if ($server->isSwarm()) {
                    instant_remote_process(["docker stack rm {$application->uuid}"], $server);

                    return;
                }

                $containers = $previewDeployments
                    ? getCurrentApplicationContainerStatus($server, $application->id, includePullrequests: true)
                    : getCurrentApplicationContainerStatus($server, $application->id, 0);

                $containersToStop = $containers
                    ->reject(fn ($container): bool => $blueGreenContainerNames->contains(
                        ltrim((string) data_get($container, 'Names'), '/'),
                    ))
                    ->pluck('Names')
                    ->toArray();
                $timeout = $application->settings->stopGracePeriodSeconds();

                foreach ($containersToStop as $containerName) {
                    instant_remote_process(command: [
                        "docker stop --time=$timeout $containerName",
                        "docker rm -f $containerName",
                    ], server: $server, throwError: false);
                }

                if ($application->build_pack === 'dockercompose') {
                    $application->deleteConnectedNetworks();
                }

                if ($dockerCleanup) {
                    CleanupDocker::dispatch($server, false, false);
                }
            } catch (\Exception $e) {
                return $e->getMessage();
            }
        }

        if ($resetRestartCount) {
            $application->update([
                'restart_count' => 0,
                'last_restart_at' => null,
                'last_restart_type' => null,
            ]);
        } else {
            $application->update([
                'status' => 'exited',
            ]);
        }

        ServiceStatusChanged::dispatch($application->environment->project->team->id);
    }
}
