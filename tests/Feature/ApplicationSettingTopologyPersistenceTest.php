<?php

use App\Enums\BlueGreenIneligibilityReason;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationSetting;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

function applicationSettingTopologyApplication(): Application
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
        'fqdn' => 'https://application-setting-topology.example.test',
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
    ]);
    $setting->save();

    return $application;
}

function applicationSettingMutationOwner(bool $withDeactivation, bool $softDeleted): Application
{
    $application = applicationSettingTopologyApplication();
    if ($withDeactivation) {
        ApplicationBlueGreenDeactivation::query()->create([
            'application_id' => $application->id,
            'standalone_docker_id' => $application->destination_id,
            'operation_id' => str_repeat('a', 64),
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

it('admits direct Eloquent setting inserts for never-deployed assembly-incomplete applications', function (): void {
    $application = applicationSettingTopologyApplication();
    $application->update(['health_check_enabled' => false]);
    $application->settings()->firstOrFail()->delete();

    $setting = ApplicationSetting::query()->create([
        'application_id' => $application->id,
        'is_blue_green_deployment_enabled' => true,
    ]);

    expect($setting->fresh()->is_blue_green_deployment_enabled)->toBeTrue()
        ->and($application->fresh()->blueGreenIneligibilityReason())->toBe(BlueGreenIneligibilityReason::HealthcheckRequired)
        ->and($application->fresh()->isBlueGreenDeploymentEnabled())->toBeFalse();
});

it('admits quiet setting inserts for never-deployed assembly-incomplete applications', function (): void {
    $application = applicationSettingTopologyApplication();
    $application->update(['health_check_enabled' => false]);
    $application->settings()->firstOrFail()->delete();
    $setting = new ApplicationSetting([
        'application_id' => $application->id,
        'is_blue_green_deployment_enabled' => true,
    ]);

    expect($setting->saveQuietly())->toBeTrue();

    expect($setting->fresh()->is_blue_green_deployment_enabled)->toBeTrue()
        ->and($application->fresh()->blueGreenIneligibilityReason())->toBe(BlueGreenIneligibilityReason::HealthcheckRequired)
        ->and($application->fresh()->isBlueGreenDeploymentEnabled())->toBeFalse();
});

it('rebases locked non-dirty attributes before saving a lifecycle setting mutation', function (): void {
    $application = applicationSettingTopologyApplication();
    $staleSetting = $application->settings()->firstOrFail();
    $currentSetting = $staleSetting->fresh();
    $currentSetting->is_debug_enabled = true;
    $currentSetting->save();
    $staleSetting->blue_green_inactive_retention_seconds = 60;

    $staleSetting->save();

    expect($staleSetting->is_debug_enabled)->toBeTrue()
        ->and($staleSetting->blue_green_inactive_retention_seconds)->toBe(60)
        ->and($staleSetting->fresh()->is_debug_enabled)->toBeTrue()
        ->and($staleSetting->fresh()->blue_green_inactive_retention_seconds)->toBe(60);
});

it('rejects quiet inactive-retention changes while a durable state is non-idle', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false);
    $setting = $scenario->application->settings()->firstOrFail();
    $originalRetention = $setting->blue_green_inactive_retention_seconds;
    $setting->blue_green_inactive_retention_seconds = 60;

    expect(fn (): bool => $setting->saveQuietly())
        ->toThrow(RuntimeException::class, 'in progress');

    expect($setting->fresh()->blue_green_inactive_retention_seconds)->toBe($originalRetention);
});

it('rejects direct and quiet setting mutation after lifecycle ownership changes', function (bool $withDeactivation, bool $softDeleted, string $message, bool $quietly): void {
    $application = applicationSettingMutationOwner($withDeactivation, $softDeleted);
    $setting = $application->settings()->firstOrFail();
    $setting->blue_green_inactive_retention_seconds = 60;

    expect(fn (): bool => $quietly ? $setting->saveQuietly() : $setting->save())
        ->toThrow(RuntimeException::class, $message);

    $persistedApplication = Application::withTrashed()->findOrFail($application->id);
    expect($setting->fresh()->blue_green_inactive_retention_seconds)->not->toBe(60)
        ->and($persistedApplication->trashed())->toBe($softDeleted)
        ->and($persistedApplication->blueGreenDeactivations()->exists())->toBe($withDeactivation);
})->with([
    'active deactivation only' => [true, false, 'durable deactivation state'],
    'soft-deleted application only' => [false, true, 'soft-deleted'],
])->with([
    'ordinary save' => false,
    'quiet save' => true,
]);

it('rejects quiet blue-green opt-out while durable state exists', function (): void {
    $application = applicationSettingTopologyApplication();
    $setting = $application->settings()->firstOrFail();
    $setting->is_blue_green_deployment_enabled = true;
    $setting->save();
    $application->blueGreenDeployments()->create([
        'standalone_docker_id' => $application->destination_id,
    ]);
    $setting->is_blue_green_deployment_enabled = false;

    expect(fn (): bool => $setting->saveQuietly())
        ->toThrow(RuntimeException::class, 'cannot be disabled while durable state exists');

    expect($setting->fresh()->is_blue_green_deployment_enabled)->toBeTrue();
});

it('rejects quiet configuration changes that make durable blue-green state ineligible', function (): void {
    $application = applicationSettingTopologyApplication();
    $setting = $application->settings()->firstOrFail();
    $setting->is_blue_green_deployment_enabled = true;
    $setting->save();
    $application->blueGreenDeployments()->create([
        'standalone_docker_id' => $application->destination_id,
    ]);
    $setting->is_container_label_readonly_enabled = false;

    expect(fn (): bool => $setting->saveQuietly())
        ->toThrow(RuntimeException::class, 'configuration cannot become ineligible');

    expect($setting->fresh()->is_container_label_readonly_enabled)->toBeTrue();
});

it('rejects quiet application setting reassignment', function (): void {
    $application = applicationSettingTopologyApplication();
    $otherApplication = applicationSettingTopologyApplication();
    $setting = $application->settings()->firstOrFail();
    $setting->application_id = $otherApplication->id;

    expect(fn (): bool => $setting->saveQuietly())
        ->toThrow(RuntimeException::class, 'cannot be reassigned');

    expect($setting->fresh()->application_id)->toBe($application->id);
});
