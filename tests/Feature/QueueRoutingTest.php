<?php

use App\Actions\Database\StartDatabase;
use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Service\StartService;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\ScheduledJobManager;
use App\Models\ScheduledDatabaseBackup;
use App\Support\ProxyMutationQueue;

describe('deployment_queue helper', function () {
    test('uses the high queue on self-hosted', function () {
        config(['constants.coolify.self_hosted' => true]);

        expect(deployment_queue())->toBe('high');
    });

    test('uses the deployments queue on cloud', function () {
        config(['constants.coolify.self_hosted' => false]);

        expect(deployment_queue())->toBe('deployments');
    });
});

describe('crons_queue helper', function () {
    test('uses the high queue on self-hosted', function () {
        config(['constants.coolify.self_hosted' => true]);

        expect(crons_queue())->toBe('high');
    });

    test('uses the crons queue on cloud', function () {
        config(['constants.coolify.self_hosted' => false]);

        expect(crons_queue())->toBe('crons');
    });
});

describe('start action job routing', function () {
    test('routes database start actions to the authoritative proxy-mutations queue', function (string $actionClass) {
        expect($actionClass::makeJob()->queue)->toBe(ProxyMutationQueue::NAME);
    })->with([
        StartDatabase::class,
        StartDatabaseProxy::class,
    ]);

    test('routes service start actions to the authoritative proxy-mutations queue', function () {
        expect(StartService::makeJob()->queue)->toBe(ProxyMutationQueue::NAME);
    });
});

describe('scheduled job routing', function () {
    test('scheduled jobs use the crons queue on cloud', function () {
        config(['constants.coolify.self_hosted' => false]);

        expect((new ScheduledJobManager)->queue)->toBe('crons');
        expect((new DatabaseBackupJob(new ScheduledDatabaseBackup))->queue)->toBe('crons');
    });

    test('scheduled jobs use the high queue on self-hosted', function () {
        config(['constants.coolify.self_hosted' => true]);

        expect((new ScheduledJobManager)->queue)->toBe('high');
        expect((new DatabaseBackupJob(new ScheduledDatabaseBackup))->queue)->toBe('high');
    });
});
