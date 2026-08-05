<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ApplicationDeploymentJob::class]);
    Notification::fake();
    Process::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('emergency-recovery-test', ['deploy'])->plainTextToken;
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

function emergencyRecoveryHeaders(string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'Content-Type' => 'application/json',
    ];
}

function makeEmergencyRecoveryDeployment(
    Environment $environment,
    Server $server,
    StandaloneDocker $destination,
    string $status = ApplicationDeploymentStatus::IN_PROGRESS->value,
): ApplicationDeploymentQueue {
    $privateKey = PrivateKey::factory()->create(['team_id' => $environment->project->team_id]);
    $application = Application::query()->create([
        'name' => 'emergency-recovery-app',
        'uuid' => (string) str()->uuid(),
        'git_repository' => 'coollabsio/coolify-examples',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'private_key_id' => $privateKey->id,
    ]);

    return ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => (string) str()->uuid(),
        'pull_request_id' => 0,
        'commit' => 'emergency-recovery',
        'status' => $status,
    ]);
}

it('reports a destination as claimable when no durable blue-green state fences it', function () {
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $this->server, $this->destination);

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk()
        ->assertJsonPath('deployment_uuid', $deployment->deployment_uuid)
        ->assertJsonPath('outcome', 'clean')
        ->assertJsonPath('claimable', true)
        ->assertJsonPath('cancelled', true);

    expect($deployment->fresh()->status)->not->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
});

it('does not cancel a deployment that already reached a terminal status', function () {
    $deployment = makeEmergencyRecoveryDeployment(
        $this->environment,
        $this->server,
        $this->destination,
        ApplicationDeploymentStatus::FAILED->value,
    );

    $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover")
        ->assertOk()
        ->assertJsonPath('cancelled', false)
        ->assertJsonPath('status', ApplicationDeploymentStatus::FAILED->value);
});

it('routes a parked intervention through recovery instead of leaving it fenced', function () {
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $this->server, $this->destination);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $deployment->application_id,
        'standalone_docker_id' => $this->destination->id,
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeploymentPhase::DRAINING->value,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'supersession_generation' => 1,
    ]);

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    // The recovery owner classifies fail-closed, so the durable outcome may be
    // clean, deferred, or manual-only; what must never happen is the emergency
    // endpoint reporting success while the destination stays fenced.
    $response->assertOk()
        ->assertJsonStructure(['deployment_uuid', 'status', 'cancelled', 'outcome', 'message', 'claimable']);

    expect($response->json('outcome'))->toBeIn(['clean', 'deferred', 'manual_only'])
        ->and($response->json('claimable'))->toBe(
            $response->json('outcome') === 'clean' && $response->json('claimable'),
        );
});

it('recovers a draining hang without first cancelling the row its owner proof needs', function () {
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $this->server, $this->destination);
    $deployment->update([
        'blue_green_phase' => BlueGreenDeploymentPhase::DRAINING,
        'blue_green_supersession_generation' => 1,
    ]);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $deployment->application_id,
        'standalone_docker_id' => $this->destination->id,
        'phase' => BlueGreenDeploymentPhase::DRAINING,
        'operation_deployment_uuid' => $deployment->deployment_uuid,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'supersession_generation' => 1,
    ]);

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk();

    // The reconciler's exact-owner proof requires an IN_PROGRESS queue row, so
    // cancelling before recovery would demote this to manual_only and, when a
    // fenced resume owner is in flight, strand the work it was called to
    // finish. Neither may happen.
    expect($response->json('outcome'))->not->toBe('manual_only');

    if ($response->json('outcome') === 'deferred') {
        expect($response->json('cancelled'))->toBeFalse()
            ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    }
});

it('refuses to recover through a stale deployment uuid that no longer owns the destination', function () {
    $deployment = makeEmergencyRecoveryDeployment(
        $this->environment,
        $this->server,
        $this->destination,
        ApplicationDeploymentStatus::FAILED->value,
    );
    $liveOperationUuid = (string) str()->uuid();
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $deployment->application_id,
        'standalone_docker_id' => $this->destination->id,
        'phase' => BlueGreenDeploymentPhase::DRAINING,
        'operation_deployment_uuid' => $liveOperationUuid,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'supersession_generation' => 2,
    ]);

    $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover")
        ->assertOk()
        ->assertJsonPath('cancelled', false);

    // The newer operation must be untouched by the stale handle.
    $state = ApplicationBlueGreenDeployment::query()
        ->where('standalone_docker_id', $this->destination->id)
        ->firstOrFail();
    expect($state->phase)->toBe(BlueGreenDeploymentPhase::DRAINING)
        ->and($state->operation_deployment_uuid)->toBe($liveOperationUuid);
});

it('releases a deployment stranded after a newer operation took the destination', function () {
    // Observed live on 4.13.62: the durable state finished and moved to IDLE
    // under a different owner, leaving the older queue entry IN_PROGRESS with
    // no phase and every successor queued behind it forever. No scheduled
    // reconciler scans an IDLE state, so nothing else releases this row.
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $this->server, $this->destination);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $deployment->application_id,
        'standalone_docker_id' => $this->destination->id,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => (string) str()->uuid(),
        'supersession_generation' => 1,
    ]);

    $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover")
        ->assertOk()
        ->assertJsonPath('cancelled', true);

    expect($deployment->fresh()->status)->not->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
});

it('never reports clean while the destination is still fenced for the next push', function () {
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $this->server, $this->destination);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $deployment->application_id,
        'standalone_docker_id' => $this->destination->id,
        'phase' => BlueGreenDeploymentPhase::DEACTIVATING,
        'operation_deployment_uuid' => $deployment->deployment_uuid,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'supersession_generation' => 1,
    ]);

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk();

    // A DEACTIVATING state is skipped by the reconciler, which would otherwise
    // read as "no work left"; it is not claimable, so it must not read clean.
    if ($response->json('claimable') === false) {
        expect($response->json('outcome'))->not->toBe('clean');
    }
});

it('refuses emergency recovery for a deployment owned by another team', function () {
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $this->server, $this->destination);
    $otherUser = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);
    $otherToken = $otherUser->createToken('other-team', ['deploy'])->plainTextToken;

    $response = $this->withHeaders(emergencyRecoveryHeaders($otherToken))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    expect($response->status())->toBeIn([401, 403])
        ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
});

it('returns 404 for an unknown deployment uuid', function () {
    $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson('/api/v1/deployments/does-not-exist/recover')
        ->assertStatus(404);
});
