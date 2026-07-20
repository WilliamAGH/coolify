<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\CoolifyTask;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\ScheduledTaskJob;
use App\Support\ProxyMutationQueue;

it('CoolifyTask has correct retry properties defined', function () {
    $reflection = new ReflectionClass(CoolifyTask::class);

    // Check public properties exist
    expect($reflection->hasProperty('tries'))->toBeTrue()
        ->and($reflection->hasProperty('maxExceptions'))->toBeTrue()
        ->and($reflection->hasProperty('timeout'))->toBeTrue()
        ->and($reflection->hasMethod('backoff'))->toBeTrue();

    // Get default values from class definition
    $defaultProperties = $reflection->getDefaultProperties();

    expect($defaultProperties['tries'])->toBe(3)
        ->and($defaultProperties['maxExceptions'])->toBe(1)
        ->and($defaultProperties['timeout'])->toBe(600);
});

it('ScheduledTaskJob has correct retry properties defined', function () {
    $reflection = new ReflectionClass(ScheduledTaskJob::class);

    // Check public properties exist
    expect($reflection->hasProperty('tries'))->toBeTrue()
        ->and($reflection->hasProperty('maxExceptions'))->toBeTrue()
        ->and($reflection->hasProperty('timeout'))->toBeTrue()
        ->and($reflection->hasMethod('backoff'))->toBeTrue()
        ->and($reflection->hasMethod('failed'))->toBeTrue();

    // Get default values from class definition
    $defaultProperties = $reflection->getDefaultProperties();

    expect($defaultProperties['tries'])->toBe(3)
        ->and($defaultProperties['maxExceptions'])->toBe(1)
        ->and($defaultProperties['timeout'])->toBe(300);
});

it('DatabaseBackupJob keeps one exception and a bounded default timeout', function () {
    $reflection = new ReflectionClass(DatabaseBackupJob::class);
    $defaultProperties = $reflection->getDefaultProperties();

    expect($defaultProperties['maxExceptions'])->toBe(1)
        ->and($defaultProperties['timeout'])->toBe(3600);
});

it('isolates proxy repairs and application builds with independently bounded capacity', function () {
    $originalQueues = getenv('HORIZON_QUEUES');
    $originalProxyMutationProcesses = getenv('HORIZON_PROXY_MUTATION_MAX_PROCESSES');
    putenv('HORIZON_QUEUES=high,application-deployments,proxy-mutations,default,application-deployments,proxy-mutations');
    putenv('HORIZON_PROXY_MUTATION_MAX_PROCESSES=99');

    try {
        $horizon = require dirname(__DIR__, 2).'/config/horizon.php';
    } finally {
        $originalQueues === false
            ? putenv('HORIZON_QUEUES')
            : putenv("HORIZON_QUEUES={$originalQueues}");
        $originalProxyMutationProcesses === false
            ? putenv('HORIZON_PROXY_MUTATION_MAX_PROCESSES')
            : putenv("HORIZON_PROXY_MUTATION_MAX_PROCESSES={$originalProxyMutationProcesses}");
    }

    $sharedQueues = explode(',', $horizon['defaults']['s6']['queue']);
    $proxySupervisor = $horizon['defaults']['proxy-mutations'];
    $deploymentSupervisor = $horizon['defaults']['application-deployments'];
    $queue = require dirname(__DIR__, 2).'/config/queue.php';

    expect($sharedQueues)->toBe(['high', 'default'])
        ->and($proxySupervisor['connection'])->toBe(ProxyMutationQueue::CONNECTION)
        ->and($proxySupervisor['queue'])->toBe(ProxyMutationQueue::NAME)
        ->and($proxySupervisor['balance'])->toBeFalse()
        ->and($proxySupervisor['minProcesses'])->toBe(1)
        ->and($proxySupervisor['maxProcesses'])->toBe(1)
        ->and($horizon['waits']['redis:proxy-mutations'])->toBe(60)
        ->and($deploymentSupervisor['connection'])->toBe('redis')
        ->and($deploymentSupervisor['queue'])->toBe(ApplicationDeploymentJob::QUEUE)
        ->and($deploymentSupervisor['balance'])->toBeFalse()
        ->and($deploymentSupervisor['minProcesses'])->toBe(1)
        ->and($deploymentSupervisor['maxProcesses'])->toBe(4)
        ->and($horizon['waits']['redis:application-deployments'])->toBe(60)
        ->and($queue['connections']['redis']['retry_after'])->toBeGreaterThan($proxySupervisor['timeout']);
});
