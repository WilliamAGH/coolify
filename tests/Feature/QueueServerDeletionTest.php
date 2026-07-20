<?php

use App\Actions\Server\DeleteServer;
use App\Actions\Server\QueueServerDeletion;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

it('chains blue-green resource deletion before the exact server deletion action job', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    Bus::fake();
    Queue::fake();

    $queuedResources = QueueServerDeletion::run($server, true);

    expect($queuedResources)->toBe(1)
        ->and($application->fresh()->requiresBlueGreenDeactivation())->toBeTrue()
        ->and(Application::query()->find($application->id))->not->toBeNull()
        ->and(Server::query()->find($server->id))->not->toBeNull()
        ->and(Server::withTrashed()->findOrFail($server->id)->trashed())->toBeFalse()
        ->and(StandaloneDocker::query()->find($destination->id))->not->toBeNull();

    Bus::assertChained([
        static fn (DeleteResourceJob $job): bool => $job->resource->is($application),
        DeleteServer::makeJob(
            $server->id,
            false,
            $server->hetzner_server_id,
            $server->cloud_provider_token_id,
            $server->team_id,
            false,
            $server->vultr_instance_id,
            false,
            $server->digitalocean_droplet_id,
        ),
    ]);
    Bus::assertNotDispatched(
        static fn (JobDecorator $job): bool => $job->decorates(DeleteServer::class),
    );
    Queue::assertNothingPushed();
});

it('refuses resource-bearing server deletion without force and leaves every model untouched', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    Bus::fake();
    Queue::fake();

    expect(fn () => QueueServerDeletion::run($server, false))->toThrow(RuntimeException::class);

    expect($application->fresh()->requiresBlueGreenDeactivation())->toBeTrue()
        ->and(Application::query()->find($application->id))->not->toBeNull()
        ->and(Server::query()->find($server->id))->not->toBeNull()
        ->and(Server::withTrashed()->findOrFail($server->id)->trashed())->toBeFalse()
        ->and(StandaloneDocker::query()->find($destination->id))->not->toBeNull();
    Bus::assertNothingDispatched();
    Queue::assertNothingPushed();
});

it('directly dispatches the decorated server deletion job when the server is empty', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->sole();
    Bus::fake();
    Queue::fake();

    $queuedResources = QueueServerDeletion::run($server, false);

    expect($server->definedResources())->toBeEmpty()
        ->and($queuedResources)->toBe(0)
        ->and(StandaloneDocker::query()->find($destination->id))->not->toBeNull();

    Bus::assertDispatchedWithoutChain(
        static fn (JobDecorator $job): bool => $job->decorates(DeleteServer::class)
            && $job->getParameters() === [
                $server->id,
                false,
                $server->hetzner_server_id,
                $server->cloud_provider_token_id,
                $server->team_id,
                false,
                $server->vultr_instance_id,
                false,
                $server->digitalocean_droplet_id,
            ],
    );
    Queue::assertNothingPushed();
});

it('forwards every selected cloud provider deletion through the canonical server deletion action', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'hetzner_server_id' => 123,
        'vultr_instance_id' => 'instance-123',
        'digitalocean_droplet_id' => 456,
    ]);
    Bus::fake();

    QueueServerDeletion::run($server, false, true, true, true);

    Bus::assertDispatchedWithoutChain(
        static fn (JobDecorator $job): bool => $job->decorates(DeleteServer::class)
            && $job->getParameters() === [
                $server->id,
                true,
                $server->hetzner_server_id,
                $server->cloud_provider_token_id,
                $server->team_id,
                true,
                $server->vultr_instance_id,
                true,
                $server->digitalocean_droplet_id,
            ],
    );
});
