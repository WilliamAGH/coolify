<?php

namespace App\Actions\Database;

use App\Contracts\ProxyMutation;
use App\Events\DatabaseProxyStopped;
use App\Models\ServiceDatabase;
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

class StopDatabaseProxy implements ProxyMutation
{
    use AsAction;
    use UsesProxyMutationQueue;

    public function configureJob(JobDecorator $job): void
    {
        ProxyMutationQueue::assign($job);
    }

    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|ServiceDatabase|StandaloneDragonfly|StandaloneClickhouse $database)
    {
        ProxyMutationQueue::ensureExecutionAllowed();

        $server = data_get($database, 'destination.server');
        $uuid = $database->uuid;
        if ($database->getMorphClass() === ServiceDatabase::class) {
            $server = data_get($database, 'service.server');
        }
        instant_remote_process(["docker rm -f {$uuid}-proxy"], $server);

        $database->save();

        DatabaseProxyStopped::dispatch();

    }
}
