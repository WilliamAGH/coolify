<?php

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

it('DatabaseBackupJob has correct retry properties defined', function () {
    $reflection = new ReflectionClass(DatabaseBackupJob::class);

    // Check public properties exist
    expect($reflection->hasProperty('tries'))->toBeTrue()
        ->and($reflection->hasProperty('maxExceptions'))->toBeTrue()
        ->and($reflection->hasProperty('timeout'))->toBeTrue()
        ->and($reflection->hasMethod('backoff'))->toBeTrue()
        ->and($reflection->hasMethod('failed'))->toBeTrue();

    // Get default values from class definition
    $defaultProperties = $reflection->getDefaultProperties();

    expect($defaultProperties['tries'])->toBe(2)
        ->and($defaultProperties['maxExceptions'])->toBe(1)
        ->and($defaultProperties['timeout'])->toBe(3600);
});

it('DatabaseBackupJob enforces minimum timeout of 60 seconds', function () {
    // Read the constructor to verify minimum timeout enforcement
    $reflection = new ReflectionClass(DatabaseBackupJob::class);
    $constructor = $reflection->getMethod('__construct');

    // Get the constructor source
    $filename = $reflection->getFileName();
    $startLine = $constructor->getStartLine();
    $endLine = $constructor->getEndLine();

    $source = file($filename);
    $constructorSource = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));

    // Verify the implementation enforces minimum of 60 seconds
    expect($constructorSource)
        ->toContain('max(')
        ->toContain('60');
});

it('isolates proxy mutations from build workers with bounded dedicated capacity', function () {
    $originalQueues = getenv('HORIZON_QUEUES');
    putenv('HORIZON_QUEUES=high,proxy-mutations,default,proxy-mutations');

    try {
        $horizon = require dirname(__DIR__, 2).'/config/horizon.php';
    } finally {
        $originalQueues === false
            ? putenv('HORIZON_QUEUES')
            : putenv("HORIZON_QUEUES={$originalQueues}");
    }

    $sharedQueues = explode(',', $horizon['defaults']['s6']['queue']);
    $proxySupervisor = $horizon['defaults']['proxy-mutations'];
    $queue = require dirname(__DIR__, 2).'/config/queue.php';

    expect($sharedQueues)->toBe(['high', 'default'])
        ->and($proxySupervisor['connection'])->toBe(ProxyMutationQueue::CONNECTION)
        ->and($proxySupervisor['queue'])->toBe(ProxyMutationQueue::NAME)
        ->and($proxySupervisor['balance'])->toBeFalse()
        ->and($proxySupervisor['minProcesses'])->toBe(1)
        ->and($proxySupervisor['maxProcesses'])->toBe(1)
        ->and($horizon['waits']['redis:proxy-mutations'])->toBe(60)
        ->and($queue['connections']['redis']['retry_after'])->toBeGreaterThan($proxySupervisor['timeout']);
});
