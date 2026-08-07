<?php

use App\Actions\Application\BlueGreen\BlueGreenManagedRouteLockAbsentException;
use App\Actions\Application\BlueGreen\BlueGreenPendingProxyMutationJournalException;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\ReadBlueGreenManagedRouteMetadata;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
    $this->user = User::factory()->create();
});

/** @return array{scenario: BlueGreenRecoveryScenario, token: string} */
function unobservableRouteCleanIdleScenario(User $user): array
{
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ...ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
        'legacy_container_name' => null,
        'phase' => BlueGreenDeploymentPhase::IDLE,
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
    ]);
    $team = $scenario->application->team();
    if (! $team instanceof Team) {
        throw new RuntimeException('The unobservable-route fixture requires one application team.');
    }
    $team->members()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);
    session(['currentTeam' => $team]);

    return [
        'scenario' => $scenario,
        'token' => $user->createToken('unobservable-route-api-test', ['read'])->plainTextToken,
    ];
}

it('returns a structured conflict when a pending proxy-mutation journal fences the managed route read', function (): void {
    ['scenario' => $scenario, 'token' => $token] = unobservableRouteCleanIdleScenario($this->user);
    Process::fake();
    ReadBlueGreenManagedRouteMetadata::shouldRun()
        ->once()
        ->andThrow(new BlueGreenPendingProxyMutationJournalException(
            WriteBlueGreenProxyConfiguration::PENDING_PROXY_MUTATION_JOURNAL_OUTPUT,
        ));
    InspectBlueGreenContainer::shouldNotRun();

    $this->withToken($token)
        ->getJson("/api/v1/applications/{$scenario->application->uuid}/active-container")
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Active application container state is not observable.',
        ]);
});

it('returns a structured conflict when the managed route lock is absent beside durable remnants', function (): void {
    ['scenario' => $scenario, 'token' => $token] = unobservableRouteCleanIdleScenario($this->user);
    Process::fake();
    ReadBlueGreenManagedRouteMetadata::shouldRun()
        ->once()
        ->andThrow(new BlueGreenManagedRouteLockAbsentException('coolify-blue-green-managed-route:lock-absent'));
    InspectBlueGreenContainer::shouldNotRun();

    $this->withToken($token)
        ->getJson("/api/v1/applications/{$scenario->application->uuid}/active-container")
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Active application container state is not observable.',
        ]);
});
