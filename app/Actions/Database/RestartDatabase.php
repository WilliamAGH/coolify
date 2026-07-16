<?php

namespace App\Actions\Database;

use App\Contracts\ProxyMutation;
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

class RestartDatabase implements ProxyMutation
{
    use AsAction;
    use UsesProxyMutationQueue;

    public function configureJob(JobDecorator $job): void
    {
        ProxyMutationQueue::assign($job);
    }

    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database)
    {
        ProxyMutationQueue::ensureExecutionAllowed();

        $server = $database->destination->server;
        if (! $server->isFunctional()) {
            return 'Server is not functional';
        }
        StopDatabase::run($database, dockerCleanup: false);

        return StartDatabase::run($database);
    }
}
