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

class StartDatabase implements ProxyMutation
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
        switch ($database->getMorphClass()) {
            case StandalonePostgresql::class:
                $activity = StartPostgresql::run($database);
                break;
            case StandaloneRedis::class:
                $activity = StartRedis::run($database);
                break;
            case StandaloneMongodb::class:
                $activity = StartMongodb::run($database);
                break;
            case StandaloneMysql::class:
                $activity = StartMysql::run($database);
                break;
            case StandaloneMariadb::class:
                $activity = StartMariadb::run($database);
                break;
            case StandaloneKeydb::class:
                $activity = StartKeydb::run($database);
                break;
            case StandaloneDragonfly::class:
                $activity = StartDragonfly::run($database);
                break;
            case StandaloneClickhouse::class:
                $activity = StartClickhouse::run($database);
                break;
        }
        if ($database->is_public && $database->public_port) {
            StartDatabaseProxy::dispatch($database);
        }

        return $activity;
    }
}
