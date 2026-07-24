<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenIneligibilityReason;
use App\Enums\ProxyTypes;
use App\Exceptions\BlueGreenAdmissionException;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

function applicationTopologyPersistenceFixture(bool $enableBlueGreen = true): Application
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://application-topology.example.test',
        'health_check_enabled' => true,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'ports_mappings' => null,
        'custom_docker_run_options' => null,
    ]);
    $setting = $application->settings()->firstOrFail();
    $setting->fill([
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
        'custom_internal_name' => null,
        'is_blue_green_deployment_enabled' => $enableBlueGreen,
    ])->save();

    return $application->fresh();
}

function applicationTopologyMutationOwner(bool $withDeactivation, bool $softDeleted): Application
{
    $application = applicationTopologyPersistenceFixture(enableBlueGreen: false);
    if ($withDeactivation) {
        ApplicationBlueGreenDeactivation::query()->create([
            'application_id' => $application->id,
            'standalone_docker_id' => $application->destination_id,
            'operation_id' => str_repeat('f', 64),
            'started_at' => now(),
            'queue_cutoff_id' => 0,
            'supersession_generation' => 1,
        ]);
    }
    if ($softDeleted) {
        DB::table('applications')->where('id', $application->id)->update(['deleted_at' => now()]);
    }

    return $application;
}

it('rejects direct and quiet ineligibility changes once the application has deployment history', function (bool $quietly): void {
    $application = applicationTopologyPersistenceFixture();
    ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $application->destination->server_id,
        'server_name' => $application->destination->server->name,
        'destination_id' => $application->destination_id,
        'deployment_uuid' => 'application-topology-deployed',
        'commit' => 'commit-application-topology-deployed',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    $application->health_check_enabled = false;

    expect(fn (): bool => $quietly ? $application->saveQuietly() : $application->save())
        ->toThrow(BlueGreenAdmissionException::class, 'cannot become ineligible');

    expect($application->fresh()->health_check_enabled)->toBeTruthy();
})->with([
    'ordinary save' => false,
    'quiet save' => true,
]);

it('admits direct and quiet ineligibility changes for never-deployed assembly-incomplete applications', function (bool $quietly): void {
    $application = applicationTopologyPersistenceFixture();
    $application->health_check_enabled = false;

    expect($quietly ? $application->saveQuietly() : $application->save())->toBeTrue();

    $persistedApplication = $application->fresh();
    expect($persistedApplication->health_check_enabled)->toBeFalsy()
        ->and($persistedApplication->blueGreenIneligibilityReason())->toBe(BlueGreenIneligibilityReason::HealthcheckRequired)
        ->and($persistedApplication->isBlueGreenDeploymentEnabled())->toBeFalse();
})->with([
    'ordinary save' => false,
    'quiet save' => true,
]);

it('rejects direct and quiet configuration mutation after lifecycle ownership changes', function (bool $withDeactivation, bool $softDeleted, string $message, bool $quietly): void {
    $application = applicationTopologyMutationOwner($withDeactivation, $softDeleted);
    $application->health_check_path = '/stale-writer';

    expect(fn (): bool => $quietly ? $application->saveQuietly() : $application->save())
        ->toThrow(RuntimeException::class, $message);

    $persistedApplication = Application::withTrashed()->findOrFail($application->id);
    expect($persistedApplication->health_check_path)->not->toBe('/stale-writer')
        ->and($persistedApplication->trashed())->toBe($softDeleted)
        ->and($persistedApplication->blueGreenDeactivations()->exists())->toBe($withDeactivation);
})->with([
    'active deactivation only' => [true, false, 'durable deactivation state'],
    'soft-deleted application only' => [false, true, 'soft-deleted'],
])->with([
    'ordinary save' => false,
    'quiet save' => true,
]);

it('rebases stale non-dirty application attributes before a lifecycle update', function (): void {
    $staleApplication = applicationTopologyPersistenceFixture(enableBlueGreen: false);
    $currentApplication = $staleApplication->fresh();
    $currentApplication->name = 'Current application name';
    $currentApplication->save();

    $staleApplication->health_check_path = '/ready';
    $staleApplication->saveQuietly();

    expect($staleApplication->name)->toBe('Current application name')
        ->and($staleApplication->fresh()->name)->toBe('Current application name')
        ->and($staleApplication->fresh()->health_check_path)->toBe('/ready');
});

it('rejects quiet routing mutations while a deployment operation is non-idle', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false);
    $application = $scenario->application->fresh();
    $originalFqdn = $application->fqdn;
    $application->fqdn = 'https://blocked-topology.example.test';

    expect(fn (): bool => $application->saveQuietly())
        ->toThrow(RuntimeException::class, 'in progress');

    expect($application->fresh()->fqdn)->toBe($originalFqdn);
});

it('keeps JSON config updates atomic when blue-green opt-out is blocked', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false);
    $application = $scenario->application->fresh();
    $originalBuildPack = $application->build_pack;
    $originalStatic = $application->settings()->firstOrFail()->is_static;
    $config = json_encode([
        'build_pack' => 'static',
        'base_directory' => '/',
        'publish_directory' => '/',
        'ports_exposes' => '80',
        'settings' => [
            'is_static' => true,
            'is_blue_green_deployment_enabled' => false,
        ],
    ], JSON_THROW_ON_ERROR);

    expect(fn () => $application->setConfig($config))
        ->toThrow(RuntimeException::class, 'cannot be disabled');

    expect($application->fresh()->build_pack)->toBe($originalBuildPack)
        ->and($application->settings()->firstOrFail()->is_static)->toBe($originalStatic);
});
