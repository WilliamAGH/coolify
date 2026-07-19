<?php

namespace App\Actions\Server;

use App\Jobs\DeleteResourceJob;
use App\Models\Server;
use Illuminate\Support\Facades\Bus;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class QueueServerDeletion
{
    use AsAction;

    public function handle(
        Server $server,
        bool $forceDeleteResources,
        bool $deleteFromHetzner = false,
    ): int {
        $resources = $server->definedResources()
            ->unique(fn (object $resource): string => $resource->getMorphClass().':'.$resource->getKey())
            ->values();
        if ($resources->isNotEmpty() && ! $forceDeleteResources) {
            throw new RuntimeException('Server deletion requires every defined resource to be deleted first.');
        }

        $deleteServer = DeleteServer::makeJob(
            $server->id,
            $deleteFromHetzner,
            $server->hetzner_server_id,
            $server->cloud_provider_token_id,
            $server->team_id,
        );
        if ($resources->isEmpty()) {
            Bus::dispatch($deleteServer);

            return 0;
        }

        $resourceDeletionJobs = $resources
            ->map(fn (object $resource): DeleteResourceJob => new DeleteResourceJob($resource))
            ->all();
        Bus::chain([
            ...$resourceDeletionJobs,
            $deleteServer,
        ])->onQueue('high')->dispatch();

        return count($resourceDeletionJobs);
    }
}
