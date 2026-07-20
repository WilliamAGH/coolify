<?php

use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteOutcome;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteResult;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDeactivationRemoteCommand;
use App\Actions\Application\StopApplicationOneServer;
use App\Actions\Docker\GetContainersStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Livewire\Project\Shared\Destination;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->mainServer = Server::factory()->create(['team_id' => $this->team->id]);
    $this->mainServer->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $this->mainServer->save();
    $this->mainDestination = StandaloneDocker::factory()->create([
        'server_id' => $this->mainServer->id,
        'name' => 'main-destination',
        'network' => 'main-network',
    ]);

    $this->additionalServer = Server::factory()->create(['team_id' => $this->team->id]);
    $this->additionalServer->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $this->additionalServer->save();
    $this->additionalDestination = StandaloneDocker::factory()->create([
        'server_id' => $this->additionalServer->id,
        'name' => 'additional-destination',
        'network' => 'additional-network',
    ]);

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $this->mainDestination->id,
        'destination_type' => StandaloneDocker::class,
        'fqdn' => 'https://destination-topology.example.test',
        'health_check_enabled' => true,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'ports_mappings' => null,
        'custom_docker_run_options' => null,
    ]);
    $this->application->settings()->firstOrFail()->update([
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
        'custom_internal_name' => null,
        'is_blue_green_deployment_enabled' => false,
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

afterEach(function (): void {
    GetContainersStatus::clearFake();
    StopApplicationOneServer::clearFake();
});

function addDestinationTopologyLifecycleOwner(Application $application, StandaloneDocker $destination, bool $withDeactivation, bool $softDeleted): void
{
    if ($withDeactivation) {
        ApplicationBlueGreenDeactivation::query()->create([
            'application_id' => $application->id,
            'standalone_docker_id' => $destination->id,
            'operation_id' => str_repeat('e', 64),
            'started_at' => now(),
            'queue_cutoff_id' => 0,
            'supersession_generation' => 1,
        ]);
    }
    if ($softDeleted) {
        DB::table('applications')
            ->where('id', $application->id)
            ->update(['deleted_at' => now()]);
    }
}

function fakeDestinationRemovalRemoteSuccess(): void
{
    Process::fake(function (PendingProcess $process) {
        if (str_contains($process->command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: '34480cd3-5fb4-4f9d-a118-794f67aa0649', exitCode: 0);
        }

        return Process::result(
            output: (new ExecuteBlueGreenDeactivationRemoteCommand)->encode(
                new BlueGreenDeactivationRemoteResult(
                    outcome: BlueGreenDeactivationRemoteOutcome::Success,
                    exitStatus: 0,
                    output: '',
                ),
            ),
            exitCode: 0,
        );
    });
}

dataset('destination topology lifecycle owners', [
    'active deactivation only' => [true, false],
    'soft-deleted application only' => [false, true],
]);

it('reloads stale topology before preserving the current main destination during promotion', function (): void {
    $thirdServer = Server::factory()->create(['team_id' => $this->team->id]);
    $thirdDestination = StandaloneDocker::factory()->create([
        'server_id' => $thirdServer->id,
        'name' => 'third-destination',
        'network' => 'third-network',
    ]);
    $this->application->additional_networks()->attach($this->additionalDestination->id, [
        'server_id' => $this->additionalServer->id,
    ]);
    $this->application->additional_networks()->attach($thirdDestination->id, [
        'server_id' => $thirdServer->id,
    ]);
    $staleComponent = Livewire::test(Destination::class, ['resource' => $this->application->fresh()]);
    GetContainersStatus::shouldRun()->twice()->andReturnNull();

    Livewire::test(Destination::class, ['resource' => $this->application->fresh()])
        ->call('promote', $this->additionalDestination->id, $this->additionalServer->id)
        ->assertNotDispatched('error');
    $staleComponent->instance()->promote($thirdDestination->id, $thirdServer->id);

    $application = $this->application->fresh();
    $additionalDestinationIds = $application->additional_networks
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($application->destination_id)->toBe($thirdDestination->id)
        ->and($additionalDestinationIds)->toBe(collect([
            $this->mainDestination->id,
            $this->additionalDestination->id,
        ])->sort()->values()->all());
});

it('rejects promotion of a destination removed after a stale component loaded it', function (): void {
    $this->application->additional_networks()->attach($this->additionalDestination->id, [
        'server_id' => $this->additionalServer->id,
    ]);
    $staleComponent = Livewire::test(Destination::class, ['resource' => $this->application->fresh()]);
    StopApplicationOneServer::shouldRun()->once()->andReturnNull();

    Livewire::test(Destination::class, ['resource' => $this->application->fresh()])
        ->call('removeServer', $this->additionalDestination->id, $this->additionalServer->id, 'password')
        ->assertNotDispatched('error');
    $staleComponent->instance()->promote($this->additionalDestination->id, $this->additionalServer->id);

    $application = $this->application->fresh();

    expect($application->destination_id)->toBe($this->mainDestination->id)
        ->and($application->additional_networks)->toHaveCount(0);
});

it('repeats the main destination fence after reloading stale topology', function (): void {
    $this->application->additional_networks()->attach($this->additionalDestination->id, [
        'server_id' => $this->additionalServer->id,
    ]);
    $staleComponent = Livewire::test(Destination::class, ['resource' => $this->application->fresh()]);
    GetContainersStatus::shouldRun()->once()->andReturnNull();
    StopApplicationOneServer::shouldRun()->never();

    Livewire::test(Destination::class, ['resource' => $this->application->fresh()])
        ->call('promote', $this->additionalDestination->id, $this->additionalServer->id)
        ->assertNotDispatched('error');
    $result = $staleComponent->instance()->removeServer(
        $this->additionalDestination->id,
        $this->additionalServer->id,
        'password',
    );

    $application = $this->application->fresh();

    expect($result)->toBeNull()
        ->and($application->destination_id)->toBe($this->additionalDestination->id)
        ->and($application->additional_networks)->toHaveCount(1)
        ->and($application->additional_networks->first()->id)->toBe($this->mainDestination->id);
});

it('allows an additional destination on a different server while blue-green deployment is opted in', function (): void {
    $this->application->settings()->firstOrFail()->update([
        'is_blue_green_deployment_enabled' => true,
    ]);

    Livewire::test(Destination::class, ['resource' => $this->application->fresh()])
        ->call('addServer', $this->additionalDestination->id, $this->additionalServer->id)
        ->assertNotDispatched('error');

    $additionalDestinations = $this->application->fresh()->additional_networks;

    expect($additionalDestinations)
        ->toHaveCount(1)
        ->and($additionalDestinations->first()->id)->toBe($this->additionalDestination->id);
});

it('proves deactivation before detaching an opted-in destination with no prior durable state', function (): void {
    $this->application->settings()->firstOrFail()->update([
        'is_blue_green_deployment_enabled' => true,
    ]);
    $this->application->additional_networks()->attach($this->additionalDestination->id, [
        'server_id' => $this->additionalServer->id,
    ]);
    $this->additionalServer->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $privateKey = PrivateKey::query()->create([
        'name' => 'Destination removal test key',
        'private_key' => generateSSHKey('ed25519')['private'],
        'team_id' => $this->team->id,
    ]);
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
    $this->additionalServer->update(['private_key_id' => $privateKey->id]);
    $this->additionalServer->refresh();
    fakeDestinationRemovalRemoteSuccess();

    Livewire::test(Destination::class, ['resource' => $this->application->fresh()])
        ->call('removeServer', $this->additionalDestination->id, $this->additionalServer->id, 'password')
        ->assertNotDispatched('error');

    expect($this->application->fresh()->additional_networks)->toHaveCount(0)
        ->and(ApplicationBlueGreenDeployment::query()
            ->where('application_id', $this->application->id)
            ->where('standalone_docker_id', $this->additionalDestination->id)
            ->doesntExist())->toBeTrue()
        ->and(ApplicationBlueGreenDeactivation::query()
            ->where('application_id', $this->application->id)
            ->where('standalone_docker_id', $this->additionalDestination->id)
            ->doesntExist())->toBeTrue();
    Process::assertRan(fn (PendingProcess $process): bool => $process->command !== '');
});

it('shows durable blue-green phase and active color for each configured destination', function (): void {
    $this->application->additional_networks()->attach($this->additionalDestination->id, [
        'server_id' => $this->additionalServer->id,
    ]);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $this->application->id,
        'standalone_docker_id' => $this->mainDestination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'primary-blue-green-ui-state',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
    ]);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $this->application->id,
        'standalone_docker_id' => $this->additionalDestination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'green_deployment_uuid' => 'additional-blue-green-ui-state',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
    ]);

    Livewire::test(Destination::class, ['resource' => $this->application->fresh()])
        ->assertSee('Blue-green: Idle')
        ->assertSee('(blue)')
        ->assertSee('(green)');
});

it('refuses a stale additional-destination attachment after lifecycle ownership changes', function (bool $withDeactivation, bool $softDeleted): void {
    $component = Livewire::test(Destination::class, ['resource' => $this->application->fresh()]);
    addDestinationTopologyLifecycleOwner($this->application, $this->mainDestination, $withDeactivation, $softDeleted);

    $component
        ->call('addServer', $this->additionalDestination->id, $this->additionalServer->id)
        ->assertDispatched('error');

    expect(DB::table('additional_destinations')
        ->where('application_id', $this->application->id)
        ->exists())->toBeFalse()
        ->and(Application::withTrashed()->findOrFail($this->application->id)->trashed())->toBe($softDeleted)
        ->and(ApplicationBlueGreenDeactivation::query()->where('application_id', $this->application->id)->exists())->toBe($withDeactivation);
})->with('destination topology lifecycle owners');

it('refuses a stale additional-destination removal after lifecycle ownership changes', function (bool $withDeactivation, bool $softDeleted): void {
    $this->application->additional_networks()->attach($this->additionalDestination->id, [
        'server_id' => $this->additionalServer->id,
    ]);
    $component = Livewire::test(Destination::class, ['resource' => $this->application->fresh()]);
    addDestinationTopologyLifecycleOwner($this->application, $this->additionalDestination, $withDeactivation, $softDeleted);
    StopApplicationOneServer::shouldRun()->never();

    $component
        ->call('removeServer', $this->additionalDestination->id, $this->additionalServer->id, 'password')
        ->assertDispatched('error');

    expect(DB::table('additional_destinations')
        ->where('application_id', $this->application->id)
        ->where('standalone_docker_id', $this->additionalDestination->id)
        ->exists())->toBeTrue()
        ->and(Application::withTrashed()->findOrFail($this->application->id)->trashed())->toBe($softDeleted)
        ->and(ApplicationBlueGreenDeactivation::query()->where('application_id', $this->application->id)->exists())->toBe($withDeactivation);
})->with('destination topology lifecycle owners');

it('refuses to remove a destination while durable blue-green state references it', function (): void {
    $this->application->additional_networks()->attach($this->additionalDestination->id, [
        'server_id' => $this->additionalServer->id,
    ]);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $this->application->id,
        'standalone_docker_id' => $this->additionalDestination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'durable-additional-destination',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
    ]);
    StopApplicationOneServer::shouldRun()->never();

    Livewire::test(Destination::class, ['resource' => $this->application->fresh()])
        ->call('removeServer', $this->additionalDestination->id, $this->additionalServer->id, 'password')
        ->assertDispatched('error');

    expect($this->application->fresh()->additional_networks)->toHaveCount(1);
});

it('allows non-blue-green attachment and durably detaches before remote stop work', function (): void {
    Livewire::test(Destination::class, ['resource' => $this->application->fresh()])
        ->call('addServer', $this->additionalDestination->id, $this->additionalServer->id)
        ->assertNotDispatched('error');

    expect($this->application->fresh()->additional_networks)->toHaveCount(1);

    $additionalDestinationId = $this->additionalDestination->id;
    $additionalServerId = $this->additionalServer->id;
    $baselineTransactionLevel = DB::transactionLevel();
    StopApplicationOneServer::shouldRun()
        ->once()
        ->andReturnUsing(function (Application $application, Server $server) use ($additionalDestinationId, $additionalServerId, $baselineTransactionLevel): void {
            expect(DB::transactionLevel())->toBe($baselineTransactionLevel)
                ->and($server->id)->toBe($additionalServerId)
                ->and($application->additional_networks()
                    ->whereKey($additionalDestinationId)
                    ->wherePivot('server_id', $additionalServerId)
                    ->exists())->toBeFalse();
        });

    Livewire::test(Destination::class, ['resource' => $this->application->fresh()])
        ->call('removeServer', $additionalDestinationId, $additionalServerId, 'password')
        ->assertNotDispatched('error');

    expect($this->application->fresh()->additional_networks)->toHaveCount(0);
});
