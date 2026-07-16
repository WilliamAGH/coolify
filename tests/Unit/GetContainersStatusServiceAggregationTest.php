<?php

use App\Actions\Docker\GetContainersStatus;
use App\Jobs\PushServerUpdateJob;
use App\Models\Service;
use App\Models\ServiceApplication;

it('aggregates all SSH-observed containers before updating a service application', function () {
    $subResource = Mockery::mock(ServiceApplication::class)->makePartial();
    $subResource->forceFill(['status' => 'exited']);
    $subResource->shouldReceive('update')
        ->once()
        ->with(['status' => 'running:unknown'])
        ->andReturnTrue();

    $applications = Mockery::mock();
    $applications->shouldReceive('where')->once()->with('id', '23')->andReturnSelf();
    $applications->shouldReceive('first')->once()->andReturn($subResource);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->forceFill([
        'id' => 17,
        'docker_compose_raw' => null,
    ]);
    $service->shouldReceive('applications')->once()->andReturn($applications);

    $action = new GetContainersStatus;
    $statuses = collect([
        '17:application:23' => collect([
            'web' => 'running:healthy',
            'worker' => 'running:unknown',
        ]),
    ]);
    (new ReflectionProperty(GetContainersStatus::class, 'serviceContainerStatuses'))
        ->setValue($action, $statuses);

    (new ReflectionMethod(GetContainersStatus::class, 'aggregateServiceContainerStatuses'))
        ->invoke($action, collect([$service]));
});

it('excludes opted-out containers from SSH service aggregation', function () {
    $subResource = Mockery::mock(ServiceApplication::class)->makePartial();
    $subResource->forceFill(['status' => 'exited']);
    $subResource->shouldReceive('update')
        ->once()
        ->with(['status' => 'running:healthy'])
        ->andReturnTrue();

    $applications = Mockery::mock();
    $applications->shouldReceive('where')->once()->with('id', '23')->andReturnSelf();
    $applications->shouldReceive('first')->once()->andReturn($subResource);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->forceFill([
        'id' => 17,
        'docker_compose_raw' => <<<'YAML'
services:
  web:
    image: nginx:latest
  worker:
    image: busybox:latest
    exclude_from_hc: true
YAML,
    ]);
    $service->shouldReceive('applications')->once()->andReturn($applications);

    $action = new GetContainersStatus;
    (new ReflectionProperty(GetContainersStatus::class, 'serviceContainerStatuses'))
        ->setValue($action, collect([
            '17:application:23' => collect([
                'web' => 'running:healthy',
                'worker' => 'exited',
            ]),
        ]));

    (new ReflectionMethod(GetContainersStatus::class, 'aggregateServiceContainerStatuses'))
        ->invoke($action, collect([$service]));
});

it('applies the same aggregation contract to Sentinel service updates', function () {
    $subResource = Mockery::mock(ServiceApplication::class)->makePartial();
    $subResource->forceFill(['status' => 'exited']);
    $subResource->shouldReceive('save')->once()->andReturnTrue();

    $service = new Service;
    $service->forceFill([
        'id' => 17,
        'docker_compose_raw' => null,
    ]);

    $job = (new ReflectionClass(PushServerUpdateJob::class))->newInstanceWithoutConstructor();
    $job->serviceContainerStatuses = collect([
        '17:application:23' => collect([
            'web' => 'running:healthy',
            'worker' => 'running:unknown',
        ]),
    ]);
    $job->servicesById = collect(['17' => $service]);
    $job->serviceApplicationsById = collect(['23' => $subResource]);
    $job->serviceDatabasesById = collect();

    (new ReflectionMethod(PushServerUpdateJob::class, 'aggregateServiceContainerStatuses'))
        ->invoke($job);

    expect($subResource->status)->toBe('running:unknown');
});
