<?php

namespace App\Jobs;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanupHelperContainersJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public function __construct(public Server $server) {}

    public function uniqueId(): string
    {
        return $this->server->uuid;
    }

    public function handle(): void
    {
        $containers = instant_remote_process_with_timeout([
            "docker container ps --format '{{json .}}' | jq -s '.'",
        ], $this->server);
        $helperContainers = collect(json_decode($containers))
            ->filter(fn (mixed $container): bool => $this->isConfiguredHelperImage(data_get($container, 'Image')))
            ->values();

        if ($helperContainers->count() > 0) {
            $deploymentStatuses = ApplicationDeploymentQueue::query()
                ->where(function ($query): void {
                    $query->where('server_id', $this->server->id)
                        ->orWhere('build_server_id', $this->server->id);
                })
                ->whereIn('deployment_uuid', $helperContainers->pluck('Names')->filter()->all())
                ->pluck('status', 'deployment_uuid');

            foreach ($helperContainers as $container) {
                $containerId = data_get($container, 'ID');
                $containerName = data_get($container, 'Names');
                $deploymentStatus = $deploymentStatuses->get($containerName);

                if (in_array($deploymentStatus, [
                    ApplicationDeploymentStatus::IN_PROGRESS->value,
                    ApplicationDeploymentStatus::QUEUED->value,
                ], true)) {
                    \Log::info('CleanupHelperContainersJob - Skipping active deployment container', [
                        'container' => $containerName,
                        'id' => $containerId,
                    ]);

                    continue;
                }

                if ($deploymentStatus === null && ! $this->isDeploymentHelperName($containerName)) {
                    \Log::info('CleanupHelperContainersJob - Skipping helper without deployment ownership', [
                        'container' => $containerName,
                        'id' => $containerId,
                    ]);

                    continue;
                }

                \Log::info('CleanupHelperContainersJob - Removing orphaned helper container', [
                    'container' => $containerName,
                    'id' => $containerId,
                ]);

                instant_remote_process_with_timeout(['docker container rm -f '.$containerId], $this->server);
            }
        }
    }

    public function failed(?\Throwable $exception): void
    {
        send_internal_notification('CleanupHelperContainersJob failed with error: '.($exception?->getMessage() ?? 'unknown error'));
    }

    private function isConfiguredHelperImage(mixed $image): bool
    {
        if (! is_string($image) || $image === '') {
            return false;
        }

        $configuredImage = coolifyHelperImage();
        $acceptedRepositories = collect([$configuredImage]);
        if (str_starts_with($configuredImage, 'docker.io/')) {
            $acceptedRepositories->push(substr($configuredImage, strlen('docker.io/')));
        }

        return $acceptedRepositories->contains(
            fn (string $repository): bool => $image === $repository
                || str_starts_with($image, "{$repository}:")
                || str_starts_with($image, "{$repository}@"),
        );
    }

    private function isDeploymentHelperName(mixed $containerName): bool
    {
        return is_string($containerName)
            && preg_match('/^[a-z0-9]{24}$/', $containerName) === 1;
    }
}
