<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\BlueGreenIneligibilityReason;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
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

it('consumes proven terminal manual-stop state during quiet blue-green opt-out', function (): void {
    $application = applicationSettingTopologyApplication();
    $setting = $application->settings()->firstOrFail();
    $setting->is_blue_green_deployment_enabled = true;
    $setting->save();
    $startedAt = now()->subMinute()->startOfSecond();
    $operationId = str_repeat('b', 64);
    $state = $application->blueGreenDeployments()->create([
        'standalone_docker_id' => $application->destination_id,
        'phase' => BlueGreenDeploymentPhase::STOPPED,
        'supersession_generation' => 2,
        'destination_fence_operation_id' => $operationId,
        'destination_fence_mutation_sequence' => 1,
        'destination_topology_digest' => str_repeat('1', 64),
        'application_routing_config_digest' => str_repeat('2', 64),
        'inactive_retirement_attempts' => 2,
        'inactive_retirement_stopped_at' => $startedAt,
    ]);
    $deactivation = $application->blueGreenDeactivations()->create([
        'standalone_docker_id' => $application->destination_id,
        'operation_id' => $operationId,
        'started_at' => $startedAt,
        'queue_cutoff_id' => 0,
        'supersession_generation' => 2,
        'phase' => BlueGreenDeactivationPhase::STOPPED,
        'completed_at' => $startedAt->copy()->addSecond(),
    ]);
    $setting->is_blue_green_deployment_enabled = false;

    expect($setting->saveQuietly())->toBeTrue();

    expect($setting->fresh()->is_blue_green_deployment_enabled)->toBeFalse()
        ->and(ApplicationBlueGreenDeployment::query()->whereKey($state->id)->doesntExist())->toBeTrue()
        ->and(ApplicationBlueGreenDeactivation::query()->whereKey($deactivation->id)->doesntExist())->toBeTrue();
});

it('rejects blue-green opt-out when stopped state lacks the exact remote fence proof', function (): void {
    $application = applicationSettingTopologyApplication();
    $setting = $application->settings()->firstOrFail();
    $setting->is_blue_green_deployment_enabled = true;
    $setting->save();
    $startedAt = now()->subMinute()->startOfSecond();
    $state = $application->blueGreenDeployments()->create([
        'standalone_docker_id' => $application->destination_id,
        'phase' => BlueGreenDeploymentPhase::STOPPED,
        'supersession_generation' => 1,
        'destination_fence_operation_id' => str_repeat('7', 64),
        'destination_fence_mutation_sequence' => 1,
        'destination_topology_digest' => str_repeat('8', 64),
        'application_routing_config_digest' => str_repeat('9', 64),
    ]);
    $deactivation = $application->blueGreenDeactivations()->create([
        'standalone_docker_id' => $application->destination_id,
        'operation_id' => str_repeat('a', 64),
        'started_at' => $startedAt,
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeactivationPhase::STOPPED,
        'completed_at' => $startedAt->copy()->addSecond(),
    ]);
    $setting->is_blue_green_deployment_enabled = false;

    expect(fn (): bool => $setting->saveQuietly())
        ->toThrow(RuntimeException::class, 'stopped state no longer matches');

    expect($setting->fresh()->is_blue_green_deployment_enabled)->toBeTrue()
        ->and(ApplicationBlueGreenDeployment::query()->whereKey($state->id)->exists())->toBeTrue()
        ->and(ApplicationBlueGreenDeactivation::query()->whereKey($deactivation->id)->exists())->toBeTrue();
});

it('rejects blue-green opt-out when a stopped destination has a live deployment owner', function (): void {
    $application = applicationSettingTopologyApplication();
    $setting = $application->settings()->firstOrFail();
    $setting->is_blue_green_deployment_enabled = true;
    $setting->save();
    $startedAt = now()->subMinute()->startOfSecond();
    $operationId = str_repeat('c', 64);
    $state = $application->blueGreenDeployments()->create([
        'standalone_docker_id' => $application->destination_id,
        'phase' => BlueGreenDeploymentPhase::STOPPED,
        'supersession_generation' => 1,
        'destination_fence_operation_id' => $operationId,
        'destination_fence_mutation_sequence' => 1,
        'destination_topology_digest' => str_repeat('3', 64),
        'application_routing_config_digest' => str_repeat('4', 64),
    ]);
    $deactivation = $application->blueGreenDeactivations()->create([
        'standalone_docker_id' => $application->destination_id,
        'operation_id' => $operationId,
        'started_at' => $startedAt,
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeactivationPhase::STOPPED,
        'completed_at' => $startedAt->copy()->addSecond(),
    ]);
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'manual-stop-opt-out-live-owner',
        'pull_request_id' => 0,
        'destination_id' => $application->destination_id,
        'server_id' => $application->destination->server_id,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);
    $setting->is_blue_green_deployment_enabled = false;

    expect(fn (): bool => $setting->saveQuietly())
        ->toThrow(RuntimeException::class, 'deployment owns a stopped destination');

    expect($setting->fresh()->is_blue_green_deployment_enabled)->toBeTrue()
        ->and(ApplicationBlueGreenDeployment::query()->whereKey($state->id)->exists())->toBeTrue()
        ->and(ApplicationBlueGreenDeactivation::query()->whereKey($deactivation->id)->exists())->toBeTrue();
});

it('rejects blue-green opt-out when manual-stop proof misses a configured destination', function (): void {
    $application = applicationSettingTopologyApplication();
    $setting = $application->settings()->firstOrFail();
    $setting->is_blue_green_deployment_enabled = true;
    $setting->save();
    $additionalServer = Server::factory()->create([
        'team_id' => $application->environment->project->team_id,
    ]);
    $additionalDestination = $additionalServer->standaloneDockers()->firstOrFail();
    $application->additional_networks()->attach($additionalDestination->id, [
        'server_id' => $additionalServer->id,
    ]);
    $startedAt = now()->subMinute()->startOfSecond();
    $operationId = str_repeat('e', 64);
    $application->blueGreenDeployments()->create([
        'standalone_docker_id' => $application->destination_id,
        'phase' => BlueGreenDeploymentPhase::STOPPED,
        'supersession_generation' => 1,
        'destination_fence_operation_id' => $operationId,
        'destination_fence_mutation_sequence' => 1,
        'destination_topology_digest' => str_repeat('5', 64),
        'application_routing_config_digest' => str_repeat('6', 64),
    ]);
    $application->blueGreenDeactivations()->create([
        'standalone_docker_id' => $application->destination_id,
        'operation_id' => $operationId,
        'started_at' => $startedAt,
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeactivationPhase::STOPPED,
        'completed_at' => $startedAt->copy()->addSecond(),
    ]);
    $setting->is_blue_green_deployment_enabled = false;

    expect(fn (): bool => $setting->saveQuietly())
        ->toThrow(RuntimeException::class, 'manual-stop proof is incomplete');

    expect($setting->fresh()->is_blue_green_deployment_enabled)->toBeTrue()
        ->and($application->blueGreenDeployments()->count())->toBe(1)
        ->and($application->blueGreenDeactivations()->count())->toBe(1);
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
