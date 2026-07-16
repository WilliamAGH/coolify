<?php

namespace App\Actions\Database;

use App\Actions\Server\CleanupDocker;
use App\Contracts\ProxyMutation;
use App\Events\ServiceStatusChanged;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Support\ProxyMutationQueue;
use App\Support\UsesProxyMutationQueue;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;

class StopDatabase implements ProxyMutation
{
    use AsAction;
    use UsesProxyMutationQueue;

    public function configureJob(JobDecorator $job): void
    {
        ProxyMutationQueue::assign($job);
    }

    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database, bool $dockerCleanup = true)
    {
        ProxyMutationQueue::ensureExecutionAllowed();

        try {
            $server = $database->destination->server;
            if (! $server->isFunctional()) {
                return 'Server is not functional';
            }

            $this->stopContainer($database, $database->uuid, 30);

            // Reset restart tracking when database is manually stopped
            $database->update([
                'restart_count' => 0,
                'last_restart_at' => null,
                'last_restart_type' => null,
            ]);

            if ($dockerCleanup) {
                CleanupDocker::dispatch($server, false, false);
            }

            if ($database->is_public) {
                StopDatabaseProxy::run($database);
            }

            return 'Database stopped successfully';
        } catch (\Exception $e) {
            return 'Database stop failed: '.$e->getMessage();
        } finally {
            ServiceStatusChanged::dispatch($database->environment->project->team->id);
        }

    }

    private function stopContainer($database, string $containerName, int $timeout = 30): void
    {
        $server = $database->destination->server;
        instant_remote_process(command: [
            "docker stop -t $timeout $containerName",
            "docker rm -f $containerName",
        ], server: $server, throwError: false);
    }
}
