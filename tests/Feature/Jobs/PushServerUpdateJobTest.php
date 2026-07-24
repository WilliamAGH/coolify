<?php

use App\Jobs\PushServerUpdateJob;
use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

test('containers with empty service subId are skipped', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $service = Service::factory()->create([
        'server_id' => $server->id,
    ]);
    $serviceApp = ServiceApplication::create([
        'service_id' => $service->id,
        'uuid' => (string) str()->uuid(),
        'name' => 'app-'.str()->random(8),
    ]);

    $data = [
        'containers' => [
            [
                'name' => 'test-container',
                'state' => 'running',
                'health_status' => 'healthy',
                'labels' => [
                    'coolify.managed' => true,
                    'coolify.serviceId' => (string) $service->id,
                    'coolify.service.subType' => 'application',
                    'coolify.service.subId' => '',
                ],
            ],
        ],
    ];

    $job = new PushServerUpdateJob($server, $data);

    // Run handle - should not throw a PDOException about empty bigint
    $job->handle();

    // The empty subId container should have been skipped
    expect($job->foundServiceApplicationIds)->not->toContain('');
    expect($job->serviceContainerStatuses)->toBeEmpty();
});

test('containers with valid service subId are processed', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $service = Service::factory()->create([
        'server_id' => $server->id,
    ]);
    $serviceApp = ServiceApplication::create([
        'service_id' => $service->id,
        'uuid' => (string) str()->uuid(),
        'name' => 'app-'.str()->random(8),
    ]);

    $data = [
        'containers' => [
            [
                'name' => 'test-container',
                'state' => 'running',
                'health_status' => 'healthy',
                'labels' => [
                    'coolify.managed' => true,
                    'coolify.serviceId' => (string) $service->id,
                    'coolify.service.subType' => 'application',
                    'coolify.service.subId' => (string) $serviceApp->id,
                    'com.docker.compose.service' => 'myapp',
                ],
            ],
        ],
    ];

    $job = new PushServerUpdateJob($server, $data);
    $job->handle();

    expect($job->foundServiceApplicationIds)->toContain((string) $serviceApp->id);
});

test('a null container-status entry from a stale job snapshot is replaced instead of crashing', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $job = new PushServerUpdateJob($server, [
        'containers' => [
            [
                'name' => 'web-container',
                'state' => 'running',
                'health_status' => 'healthy',
                'labels' => [
                    'coolify.managed' => true,
                    'coolify.applicationId' => (string) $application->id,
                    'coolify.pullRequestId' => '0',
                    'com.docker.compose.service' => 'web',
                ],
            ],
        ],
    ]);
    // Simulate the production failure mode (2026-07-24 02:49-03:23 UTC): a job
    // snapshot deserialized with a null status entry, so has() passes while
    // get() returns null.
    $job->applicationContainerStatuses = collect([$application->id => null]);

    $job->handle();

    $statuses = $job->applicationContainerStatuses->get($application->id);
    expect($statuses)->toBeInstanceOf(Collection::class)
        ->and($statuses->get('web'))->toBe('running:healthy');
});
