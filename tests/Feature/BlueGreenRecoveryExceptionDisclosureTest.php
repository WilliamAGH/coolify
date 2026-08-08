<?php

use App\Actions\Application\BlueGreen\BlueGreenInterventionRecoveryResult;
use App\Actions\Application\BlueGreen\BlueGreenReconciliationResult;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenDeployment;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
use App\Actions\Application\CancelApplicationDeployment;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));
    $this->auditLogPath = storage_path('logs/recovery-disclosure-audit-'.Str::uuid().'.log');
    config([
        'logging.channels.audit' => [
            'driver' => 'single',
            'path' => $this->auditLogPath,
            'level' => 'debug',
            'replace_placeholders' => true,
        ],
    ]);
    Log::forgetChannel('audit');
    $this->appLogPath = storage_path('logs/recovery-disclosure-app-'.Str::uuid().'.log');
    config([
        'logging.default' => 'single',
        'logging.channels.single.path' => $this->appLogPath,
    ]);
    Log::forgetChannel('single');
});

afterEach(function (): void {
    if (is_string($this->auditLogPath ?? null)) {
        File::delete($this->auditLogPath);
    }
    if (is_string($this->appLogPath ?? null)) {
        File::delete($this->appLogPath);
    }
});

/**
 * A distinctive stand-in for privileged remote diagnostics: SSH stderr naming
 * an internal address and a Docker permission failure. It must never appear in
 * a team-scoped API or recovery-result surface.
 */
function recoveryDisclosureMarker(): string
{
    return 'ssh: root@10.66.0.99 PRIVILEGED-RECOVERY-STDERR-MARKER-9f4c docker inspect permission denied';
}

function recoveryDisclosureScenario(): BlueGreenRecoveryScenario
{
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now(),
    ]);
    $scenario->state->update(array_merge(
        [
            'active_color' => null,
            'pending_color' => null,
            'blue_deployment_uuid' => null,
            'green_deployment_uuid' => null,
            'pending_deployment_uuid' => null,
            'legacy_container_name' => null,
            'deactivation_operation_id' => null,
            'deactivation_started_at' => null,
            'destination_fence_epoch' => 0,
            'destination_fence_operation_id' => null,
            'destination_fence_mutation_sequence' => 0,
            'managed_file_sha256' => null,
            'destination_topology_digest' => null,
            'application_routing_config_digest' => null,
            'intervention_phase' => null,
            'intervention_reason' => null,
            'supersession_generation' => 0,
            'phase' => BlueGreenDeploymentPhase::IDLE,
            'routing_revision' => 0,
        ],
        ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
    ));

    return $scenario;
}

/** @return array{token: string, team: Team, user: User} */
function recoveryDisclosureApiActor(BlueGreenRecoveryScenario $scenario): array
{
    $team = Team::query()->findOrFail($scenario->server->team_id);
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);

    return [
        'token' => $user->createToken('recovery-disclosure-test', ['deploy'])->plainTextToken,
        'team' => $team,
        'user' => $user,
    ];
}

it('keeps privileged remote failure detail out of the public stale-journal recovery result', function (): void {
    $scenario = recoveryDisclosureScenario();
    Process::fake(fn () => Process::result(errorOutput: recoveryDisclosureMarker(), exitCode: 255));

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Prove privileged inspection failures never reach public recovery results.',
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($result->message)->not->toContain('PRIVILEGED-RECOVERY-STDERR-MARKER-9f4c')
        ->and($result->message)->not->toContain('10.66.0.99')
        ->and($result->reasonCode)->toBe('inspection_failed')
        ->and($result->correlationId)->toBeUuid();

    $audit = File::get($this->auditLogPath);
    expect($audit)->toContain('inspection_failed')
        ->and($audit)->toContain($result->correlationId)
        ->and($audit)->not->toContain('PRIVILEGED-RECOVERY-STDERR-MARKER-9f4c');

    $appLog = File::get($this->appLogPath);
    expect($appLog)->toContain('PRIVILEGED-RECOVERY-STDERR-MARKER-9f4c')
        ->and($appLog)->toContain($result->correlationId);
});

it('persists and returns only a stable reconciliation failure reason with a correlation id', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'finished_at' => now(),
    ]);
    Process::fake(fn () => Process::result(errorOutput: recoveryDisclosureMarker(), exitCode: 255));

    $result = ReconcileBlueGreenDeployment::run(
        $scenario->state,
        staleAfterSeconds: 1,
        ignoreQueueActivity: true,
    );

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::INTERVENTION_REQUIRED)
        ->and($result->message)->toStartWith('The interrupted operation could not be proven safe to reconcile. Reason code: reconciliation_failed. Correlation ID: ')
        ->and($result->message)->not->toContain('PRIVILEGED-RECOVERY-STDERR-MARKER-9f4c')
        ->and($result->message)->not->toContain('10.66.0.99')
        ->and(str($result->message)->afterLast('Correlation ID: ')->rtrim('.')->toString())->toBeUuid()
        ->and($scenario->state->fresh()->intervention_reason)->toBe($result->message);

    $appLog = File::get($this->appLogPath);
    expect($appLog)->toContain('PRIVILEGED-RECOVERY-STDERR-MARKER-9f4c')
        ->and($appLog)->toContain(str($result->message)->afterLast('Correlation ID: ')->rtrim('.')->toString());
});

it('keeps exception detail out of the emergency recovery API failure response', function (): void {
    $scenario = recoveryDisclosureScenario();
    $actor = recoveryDisclosureApiActor($scenario);
    $throwOnStateLookup = true;
    Event::listen(
        'eloquent.retrieved: '.ApplicationBlueGreenDeployment::class,
        static function () use (&$throwOnStateLookup): void {
            if ($throwOnStateLookup) {
                throw new RuntimeException(recoveryDisclosureMarker());
            }
        },
    );

    try {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$actor['token'],
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$scenario->deployment->deployment_uuid}/recover");
    } finally {
        $throwOnStateLookup = false;
    }

    $response->assertStatus(500)
        ->assertJsonPath('reason_code', 'recovery_failed')
        ->assertJsonPath('message', 'Deployment recovery could not be completed safely.');
    $body = json_encode($response->json(), JSON_THROW_ON_ERROR);
    expect($body)->not->toContain('PRIVILEGED-RECOVERY-STDERR-MARKER-9f4c')
        ->and($body)->not->toContain('10.66.0.99');

    $correlationId = $response->json('correlation_id');
    expect($correlationId)->toBeUuid();

    $audit = File::get($this->auditLogPath);
    expect($audit)->toContain('recovery_failed')
        ->and($audit)->toContain($correlationId)
        ->and($audit)->not->toContain('PRIVILEGED-RECOVERY-STDERR-MARKER-9f4c');

    $appLog = File::get($this->appLogPath);
    expect($appLog)->toContain('PRIVILEGED-RECOVERY-STDERR-MARKER-9f4c')
        ->and($appLog)->toContain($correlationId);
});

it('keeps exception detail out of the deployment cancellation API failure response', function (): void {
    $scenario = recoveryDisclosureScenario();
    $scenario->deployment->update(['status' => ApplicationDeploymentStatus::IN_PROGRESS->value]);
    $actor = recoveryDisclosureApiActor($scenario);
    $cancellation = Mockery::mock(new CancelApplicationDeployment);
    $cancellation->shouldReceive('handle')
        ->andThrow(new RuntimeException(recoveryDisclosureMarker()));
    app()->instance(CancelApplicationDeployment::class, $cancellation);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$actor['token'],
        'Content-Type' => 'application/json',
    ])->postJson("/api/v1/deployments/{$scenario->deployment->deployment_uuid}/cancel");

    $response->assertStatus(500)
        ->assertJsonPath('reason_code', 'cancel_failed')
        ->assertJsonPath('message', 'Deployment cancellation could not be completed safely.');
    $body = json_encode($response->json(), JSON_THROW_ON_ERROR);
    expect($body)->not->toContain('PRIVILEGED-RECOVERY-STDERR-MARKER-9f4c')
        ->and($body)->not->toContain('10.66.0.99');

    $correlationId = $response->json('correlation_id');
    expect($correlationId)->toBeUuid();

    $audit = File::get($this->auditLogPath);
    expect($audit)->toContain('cancel_failed')
        ->and($audit)->toContain($correlationId)
        ->and($audit)->not->toContain('PRIVILEGED-RECOVERY-STDERR-MARKER-9f4c');

    $appLog = File::get($this->appLogPath);
    expect($appLog)->toContain('PRIVILEGED-RECOVERY-STDERR-MARKER-9f4c')
        ->and($appLog)->toContain($correlationId);
});
