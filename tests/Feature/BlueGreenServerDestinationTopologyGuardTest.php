<?php

use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Jobs\ConnectProxyToNetworksJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

/**
 * @return array{application: Application, destination: StandaloneDocker, server: Server}
 */
function makeServerDestinationTopologyFixture(bool $enableBlueGreen = true): array
{
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://topology-guard.example.test',
        'health_check_enabled' => true,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'ports_mappings' => null,
        'custom_docker_run_options' => null,
    ]);
    $application->settings()->firstOrFail()->update([
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
        'custom_internal_name' => null,
        'is_blue_green_deployment_enabled' => $enableBlueGreen,
    ]);

    return compact('application', 'destination', 'server');
}

it('rejects proxy changes for a protected blue-green server', function () {
    ['server' => $server] = makeServerDestinationTopologyFixture();
    $server->proxy->set('type', ProxyTypes::CADDY->value);

    expect(fn () => $server->save())
        ->toThrow(RuntimeException::class, 'require Traefik');

    expect($server->fresh()->proxyType())->toBe(ProxyTypes::TRAEFIK->value);
});

it('allows state-only proxy document writes without treating them as topology transitions', function (bool $quietly): void {
    ['server' => $server] = makeServerDestinationTopologyFixture();
    $server->proxy->set('control_plane_test_state', ['phase' => 'prepared']);

    expect(fn (): bool => $quietly ? $server->saveQuietly() : $server->save())
        ->not->toThrow(RuntimeException::class);

    $persistedServer = $server->fresh();
    expect($persistedServer->proxyType())->toBe(ProxyTypes::TRAEFIK->value)
        ->and($persistedServer->proxy->get('control_plane_test_state'))->toBe(['phase' => 'prepared']);
})->with([
    'ordinary save' => false,
    'quiet save' => true,
]);

it('rejects Docker Swarm transitions for a protected blue-green server', function () {
    ['server' => $server] = makeServerDestinationTopologyFixture();
    $setting = $server->settings()->firstOrFail();
    $setting->is_swarm_manager = '1';

    expect(fn () => $setting->save())
        ->toThrow(RuntimeException::class, 'converted to Docker Swarm');

    expect($setting->fresh()->is_swarm_manager)->toBeFalse();
});

it('rejects destination topology changes and removals while blue-green is protected', function () {
    ['destination' => $destination] = makeServerDestinationTopologyFixture();
    $destination->network = 'changed-blue-green-network';

    expect(fn () => $destination->save())
        ->toThrow(RuntimeException::class, 'cannot change topology or be removed');
    expect($destination->fresh()->network)->not->toBe('changed-blue-green-network');

    $destination = $destination->fresh();
    expect(fn () => $destination->delete())
        ->toThrow(RuntimeException::class, 'cannot change topology or be removed');
    expect(StandaloneDocker::query()->find($destination->id))->not->toBeNull();
});

it('rejects server removal while it hosts protected blue-green topology', function () {
    ['server' => $server] = makeServerDestinationTopologyFixture();

    expect(fn () => $server->delete())
        ->toThrow(RuntimeException::class, 'cannot be removed');

    expect(Server::query()->find($server->id))->not->toBeNull();
});

it('rejects quiet topology writes inside the lock transaction', function () {
    ['destination' => $destination, 'server' => $server] = makeServerDestinationTopologyFixture();
    $initialTransactionLevel = DB::transactionLevel();
    $server->proxy->set('type', ProxyTypes::CADDY->value);

    expect(fn () => $server->saveQuietly())
        ->toThrow(RuntimeException::class, 'require Traefik');
    expect(DB::transactionLevel())->toBe($initialTransactionLevel)
        ->and($server->fresh()->proxyType())->toBe(ProxyTypes::TRAEFIK->value);

    $setting = $server->settings()->firstOrFail();
    $setting->is_swarm_manager = true;
    expect(fn () => $setting->saveQuietly())
        ->toThrow(RuntimeException::class, 'converted to Docker Swarm');
    expect(DB::transactionLevel())->toBe($initialTransactionLevel)
        ->and($setting->fresh()->is_swarm_manager)->toBeFalse();

    $destination->network = 'quietly-changed-network';
    expect(fn () => $destination->saveQuietly())
        ->toThrow(RuntimeException::class, 'cannot change topology or be removed');
    expect(DB::transactionLevel())->toBe($initialTransactionLevel)
        ->and($destination->fresh()->network)->not->toBe('quietly-changed-network');
});

it('rejects reassigning existing server settings to another server', function () {
    ['server' => $server] = makeServerDestinationTopologyFixture(false);
    $targetServer = Server::factory()->create([
        'team_id' => $server->team_id,
        'private_key_id' => $server->private_key_id,
    ]);
    $setting = $server->settings()->firstOrFail();
    $setting->server_id = $targetServer->id;

    expect(fn () => $setting->save())
        ->toThrow(RuntimeException::class, 'cannot be reassigned');

    expect($setting->fresh()->server_id)->toBe($server->id);
});

it('rejects quietly reassigning existing server settings inside the lock transaction', function () {
    ['server' => $server] = makeServerDestinationTopologyFixture(false);
    $targetServer = Server::factory()->create([
        'team_id' => $server->team_id,
        'private_key_id' => $server->private_key_id,
    ]);
    $setting = $server->settings()->firstOrFail();
    $setting->server_id = $targetServer->id;
    $initialTransactionLevel = DB::transactionLevel();

    expect(fn () => $setting->saveQuietly())
        ->toThrow(RuntimeException::class, 'cannot be reassigned');

    expect(DB::transactionLevel())->toBe($initialTransactionLevel)
        ->and($setting->fresh()->server_id)->toBe($server->id);
});

it('rejects quiet topology removals inside the lock transaction', function () {
    ['destination' => $destination, 'server' => $server] = makeServerDestinationTopologyFixture();
    $initialTransactionLevel = DB::transactionLevel();

    expect(fn () => $destination->deleteQuietly())
        ->toThrow(RuntimeException::class, 'cannot change topology or be removed');
    expect(DB::transactionLevel())->toBe($initialTransactionLevel)
        ->and(StandaloneDocker::query()->find($destination->id))->not->toBeNull();

    expect(fn () => $server->deleteQuietly())
        ->toThrow(RuntimeException::class, 'cannot be removed');
    expect(DB::transactionLevel())->toBe($initialTransactionLevel)
        ->and(Server::withTrashed()->findOrFail($server->id)->trashed())->toBeFalse();
});

it('protects durable blue-green destination state even without a current opt-in', function () {
    ['application' => $application, 'destination' => $destination] = makeServerDestinationTopologyFixture(false);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'durable-topology-owner',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
    ]);
    $destination->network = 'changed-durable-network';

    expect(fn () => $destination->save())
        ->toThrow(RuntimeException::class, 'cannot change topology or be removed');

    expect($destination->fresh()->network)->not->toBe('changed-durable-network');
});

it('allows non-blue-green server and destination topology changes', function () {
    ['destination' => $destination, 'server' => $server] = makeServerDestinationTopologyFixture(false);
    $server->proxy->set('type', ProxyTypes::CADDY->value);
    $server->save();
    $destination->update(['network' => 'ordinary-network']);
    $setting = $server->settings()->firstOrFail();
    $setting->update([
        'is_swarm_manager' => '1',
        'is_swarm_worker' => '0',
    ]);

    expect($server->fresh()->proxyType())->toBe(ProxyTypes::CADDY->value)
        ->and($destination->fresh()->network)->toBe('ordinary-network')
        ->and($setting->fresh()->is_swarm_manager)->toBeTrue()
        ->and($setting->fresh()->is_swarm_worker)->toBeFalse();
});

it('allows unrelated destination and server removal', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();

    expect($destination->delete())->toBeTrue();
    expect(StandaloneDocker::query()->find($destination->id))->toBeNull();
    expect($server->delete())->toBeTrue();
    expect(Server::withTrashed()->findOrFail($server->id)->trashed())->toBeTrue();
});

it('dispatches proxy network reconciliation only after the destination transaction commits', function () {
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $queuedJobs = 0;
    Event::listen(JobQueueing::class, function (JobQueueing $event) use (&$queuedJobs): void {
        if ($event->job instanceof ConnectProxyToNetworksJob) {
            $queuedJobs++;
        }
    });
    Process::fake();
    $environment = app()->environment();
    app()->detectEnvironment(static fn (): string => 'production');

    try {
        DB::transaction(function () use ($server, &$queuedJobs): void {
            StandaloneDocker::factory()->create([
                'server_id' => $server->id,
                'network' => 'after-commit-network',
            ]);

            expect($queuedJobs)->toBe(0);
        });

        expect($queuedJobs)->toBe(1);
    } finally {
        app()->detectEnvironment(static fn (): string => $environment);
    }
});
