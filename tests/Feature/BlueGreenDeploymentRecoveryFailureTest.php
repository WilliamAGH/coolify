<?php

use App\Actions\Application\BlueGreen\BeginBlueGreenDeploymentRecovery;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentQueueActivity;
use App\Actions\Application\BlueGreen\EnsureBlueGreenPreviousContainerRunning;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\PlanBlueGreenFinalizedRecovery;
use App\Actions\Application\BlueGreen\PlanBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\ReadBlueGreenRecoveryArtifact;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenDeployment;
use App\Actions\Application\BlueGreen\RemoveExactBlueGreenCandidate;
use App\Actions\Application\BlueGreen\VerifyBlueGreenManagedConfiguration;
use App\Actions\Application\BlueGreen\VerifyBlueGreenPublicRecovery;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactCommitter;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactRestorer;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeactivation;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

function assertBlueGreenRecoveryRemoteMutationsDoNotRun(): void
{
    BeginBlueGreenDeploymentRecovery::shouldNotRun();
    EnsureBlueGreenPreviousContainerRunning::shouldNotRun();
    BlueGreenProxyRollbackArtifactRestorer::shouldNotRun();
    VerifyBlueGreenPublicRecovery::shouldNotRun();
    RemoveExactBlueGreenCandidate::shouldNotRun();
    BlueGreenProxyRollbackArtifactCommitter::shouldNotRun();
}

it('fails closed with terminal queue provenance when a post-mutation artifact is missing or corrupt', function (bool $corrupt) {
    $scenario = BlueGreenRecoveryScenario::create(routingMutationRecorded: true);
    BlueGreenDeploymentQueueActivity::shouldRun()->once()->andReturnFalse();
    InspectBlueGreenContainer::shouldRun()->once()->andReturn(new BlueGreenContainerInspection(
        exists: true,
        dockerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
        status: 'running',
        health: 'healthy',
    ));
    $artifactExpectation = ReadBlueGreenRecoveryArtifact::shouldRun()->once();
    $corrupt
        ? $artifactExpectation->andThrow(new RuntimeException('corrupt rollback artifact'))
        : $artifactExpectation->andReturnNull();
    PlanBlueGreenPublicRecovery::shouldNotRun();
    assertBlueGreenRecoveryRemoteMutationsDoNotRun();

    $result = ReconcileBlueGreenDeployment::run($scenario->state);
    $state = $scenario->state->fresh();
    $deployment = $scenario->deployment->fresh();

    expect($result->outcome)->toBe('intervention_required')
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($state->operation_deployment_uuid)->toBe($deployment->deployment_uuid)
        ->and($state->operation_candidate_container_id)->toBe(BlueGreenRecoveryScenario::CANDIDATE_ID)
        ->and($deployment->blue_green_phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($deployment->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($deployment->finished_at)->not->toBeNull();
})->with([
    'missing after recorded routing mutation' => false,
    'corrupt exact-key artifact' => true,
]);

it('leaves every container and artifact intact when the candidate name was reused', function () {
    $scenario = BlueGreenRecoveryScenario::create();
    BlueGreenDeploymentQueueActivity::shouldRun()->once()->andReturnFalse();
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andThrow(new RuntimeException('persisted candidate name was reused'));
    ReadBlueGreenRecoveryArtifact::shouldNotRun();
    PlanBlueGreenPublicRecovery::shouldNotRun();
    assertBlueGreenRecoveryRemoteMutationsDoNotRun();

    $result = ReconcileBlueGreenDeployment::run($scenario->state);

    expect($result->outcome)->toBe('intervention_required')
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($scenario->state->fresh()->operation_rollback_managed_filename)
        ->toBe(BlueGreenRecoveryScenario::MANAGED_FILENAME);
});

it('fails closed before mutation when the exact previous immutable container is missing', function () {
    $scenario = BlueGreenRecoveryScenario::create();
    BlueGreenDeploymentQueueActivity::shouldRun()->once()->andReturnFalse();
    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturnUsing(fn ($server, BlueGreenContainerExpectation $expectation) => $expectation->dockerId === BlueGreenRecoveryScenario::CANDIDATE_ID
            ? new BlueGreenContainerInspection(
                exists: true,
                dockerId: $expectation->dockerId,
                status: 'running',
                health: 'healthy',
            )
            : BlueGreenContainerInspection::missing());
    ReadBlueGreenRecoveryArtifact::shouldRun()
        ->once()
        ->andReturnUsing(fn ($server, $key) => new BlueGreenProxyRollbackArtifact($key, true, 'prior'));
    PlanBlueGreenPublicRecovery::shouldNotRun();
    assertBlueGreenRecoveryRemoteMutationsDoNotRun();

    $result = ReconcileBlueGreenDeployment::run($scenario->state);

    expect($result->outcome)->toBe('intervention_required')
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED);
});

it('defers while the owning queue is active or still recent without inspecting remote state', function () {
    $scenario = BlueGreenRecoveryScenario::create();
    BlueGreenDeploymentQueueActivity::shouldRun()->once()->andReturnTrue();
    InspectBlueGreenContainer::shouldNotRun();
    ReadBlueGreenRecoveryArtifact::shouldNotRun();

    $result = ReconcileBlueGreenDeployment::run($scenario->state);

    expect($result->outcome)->toBe('deferred')
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($scenario->deployment->fresh()->finished_at)->toBeNull();
});

it('does not steal a live lifecycle owner at the five-minute queue safety boundary', function () {
    Carbon::setTestNow('2026-07-13 12:00:00');
    $scenario = BlueGreenRecoveryScenario::create();
    $scenario->deployment->newQuery()->whereKey($scenario->deployment->id)->update([
        'updated_at' => now()->subSeconds(300),
    ]);
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($scenario->application->id, $scenario->destination->id),
        60,
    );
    expect($lock->get())->toBeTrue();
    InspectBlueGreenContainer::shouldNotRun();

    try {
        $result = ReconcileBlueGreenDeployment::run($scenario->state);
    } finally {
        $liveOwnerReleased = $lock->release();
        Carbon::setTestNow();
    }

    expect($result->outcome)->toBe('deferred')
        ->and($liveOwnerReleased)->toBeTrue()
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING);
});

it('defers instead of taking over an apparently stale lifecycle cache lock', function () {
    Carbon::setTestNow('2026-07-13 12:00:00');
    $scenario = BlueGreenRecoveryScenario::create();
    $scenario->deployment->newQuery()->whereKey($scenario->deployment->id)->update([
        'updated_at' => now()->subSeconds(301),
    ]);
    $orphan = Cache::lock(
        BlueGreenDeploymentLock::key($scenario->application->id, $scenario->destination->id),
        BlueGreenDeploymentLock::leaseSeconds(3600),
    );
    expect($orphan->get())->toBeTrue();
    BlueGreenDeploymentQueueActivity::shouldNotRun();
    InspectBlueGreenContainer::shouldNotRun();
    ReadBlueGreenRecoveryArtifact::shouldNotRun();

    try {
        $result = ReconcileBlueGreenDeployment::run($scenario->state);
    } finally {
        $orphanReleased = $orphan->release();
        Carbon::setTestNow();
    }

    expect($result->outcome)->toBe('deferred')
        ->and($orphanReleased)->toBeTrue()
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING);
});

it('terminalizes a legacy partial preparing claim even when its queue is in progress, without remote mutation', function () {
    $scenario = BlueGreenRecoveryScenario::create();
    $scenario->state->update(['operation_deployment_uuid' => null]);
    BlueGreenDeploymentQueueActivity::shouldNotRun();
    InspectBlueGreenContainer::shouldNotRun();
    ReadBlueGreenRecoveryArtifact::shouldNotRun();
    assertBlueGreenRecoveryRemoteMutationsDoNotRun();

    $result = ReconcileBlueGreenDeployment::run($scenario->state);

    expect($result->outcome)->toBe('intervention_required')
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($scenario->state->fresh()->operation_deployment_uuid)->toBeNull()
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($scenario->deployment->fresh()->finished_at)->not->toBeNull();
});

it('skips reconciliation before remote mutation when deactivation owns the destination', function () {
    $scenario = BlueGreenRecoveryScenario::create();
    ApplicationBlueGreenDeactivation::create([
        'application_id' => $scenario->application->id,
        'standalone_docker_id' => $scenario->destination->id,
        'operation_id' => str_repeat('d', 64),
        'started_at' => now(),
        'queue_cutoff_id' => 0,
        'phase' => BlueGreenDeactivationPhase::DEACTIVATING,
    ]);
    BlueGreenDeploymentQueueActivity::shouldNotRun();
    InspectBlueGreenContainer::shouldNotRun();
    ReadBlueGreenRecoveryArtifact::shouldNotRun();
    assertBlueGreenRecoveryRemoteMutationsDoNotRun();

    $result = ReconcileBlueGreenDeployment::run($scenario->state);

    expect($result->outcome)->toBe('skipped')
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
});

it('defers when another operation replaces the durable owner before the lifecycle lock is acquired', function () {
    $scenario = BlueGreenRecoveryScenario::create();
    $replacementUuid = (string) Str::uuid();
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')
        ->once()
        ->andReturnUsing(function () use ($scenario, $replacementUuid): bool {
            $scenario->state->newQuery()
                ->whereKey($scenario->state->id)
                ->update(['operation_deployment_uuid' => $replacementUuid]);

            return true;
        });
    $lock->shouldReceive('refresh')->zeroOrMoreTimes()->andReturnTrue();
    $lock->shouldReceive('isOwnedByCurrentProcess')->once()->andReturnTrue();
    $lock->shouldReceive('release')->once()->andReturnTrue();
    Cache::shouldReceive('lock')->once()->andReturn($lock);
    BlueGreenDeploymentQueueActivity::shouldNotRun();
    InspectBlueGreenContainer::shouldNotRun();

    $result = ReconcileBlueGreenDeployment::run($scenario->state);

    expect($result->outcome)->toBe('deferred')
        ->and($scenario->state->fresh()->operation_deployment_uuid)->toBe($replacementUuid)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
});

it('preserves the intervention result and reports a lifecycle lock release failure', function () {
    $scenario = BlueGreenRecoveryScenario::create();
    $releaseException = new RuntimeException('cache lock release failed');
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturnTrue();
    $lock->shouldReceive('refresh')->zeroOrMoreTimes()->andReturnTrue();
    $lock->shouldReceive('isOwnedByCurrentProcess')->once()->andReturnTrue();
    $lock->shouldReceive('release')->once()->andThrow($releaseException);
    Cache::shouldReceive('lock')->once()->andReturn($lock);
    $exceptionHandler = Mockery::mock(ExceptionHandler::class);
    $exceptionHandler->shouldReceive('report')->once()->with($releaseException);
    app()->instance(ExceptionHandler::class, $exceptionHandler);
    BlueGreenDeploymentQueueActivity::shouldRun()->once()->andReturnFalse();
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andThrow(new RuntimeException('candidate inspection failed'));
    ReadBlueGreenRecoveryArtifact::shouldNotRun();

    $result = ReconcileBlueGreenDeployment::run($scenario->state);

    expect($result->outcome)->toBe('intervention_required')
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED);
});

it('removes an exact first candidate without inventing a public recovery target', function () {
    $scenario = BlueGreenRecoveryScenario::create(
        routingMutationRecorded: false,
        withoutPrevious: true,
    );
    $releaseException = new RuntimeException('cache lock release failed after recovery');
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturnTrue();
    $lock->shouldReceive('refresh')->zeroOrMoreTimes()->andReturnTrue();
    $lock->shouldReceive('isOwnedByCurrentProcess')->once()->andReturnTrue();
    $lock->shouldReceive('release')->once()->andThrow($releaseException);
    Cache::shouldReceive('lock')->once()->andReturn($lock);
    $exceptionHandler = Mockery::mock(ExceptionHandler::class);
    $exceptionHandler->shouldReceive('report')->once()->with($releaseException);
    app()->instance(ExceptionHandler::class, $exceptionHandler);
    BlueGreenDeploymentQueueActivity::shouldRun()->once()->andReturnFalse();
    InspectBlueGreenContainer::shouldRun()->once()->andReturn(new BlueGreenContainerInspection(
        exists: true,
        dockerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
        status: 'running',
        health: 'healthy',
    ));
    ReadBlueGreenRecoveryArtifact::shouldRun()->once()->andReturnNull();
    PlanBlueGreenPublicRecovery::shouldNotRun();
    EnsureBlueGreenPreviousContainerRunning::shouldNotRun();
    BlueGreenProxyRollbackArtifactRestorer::shouldNotRun();
    VerifyBlueGreenPublicRecovery::shouldNotRun();
    RemoveExactBlueGreenCandidate::shouldRun()->once();
    BlueGreenProxyRollbackArtifactCommitter::shouldNotRun();

    $result = ReconcileBlueGreenDeployment::run($scenario->state);

    expect($result->outcome)->toBe('reconciled')
        ->and($scenario->state->fresh()->active_color)->toBeNull()
        ->and($scenario->state->fresh()->routing_revision)->toBe(0);
});

it('treats a corrupt finalized cleanup artifact as intervention without rolling back promotion', function () {
    $scenario = BlueGreenRecoveryScenario::create(
        fixedPrevious: true,
        finalized: true,
    );
    BlueGreenDeploymentQueueActivity::shouldRun()->once()->andReturnFalse();
    InspectBlueGreenContainer::shouldRun()->once()->andReturn(new BlueGreenContainerInspection(
        exists: true,
        dockerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
        status: 'running',
        health: 'healthy',
    ));
    ReadBlueGreenRecoveryArtifact::shouldRun()
        ->once()
        ->andThrow(new RuntimeException('corrupt finalized artifact'));
    PlanBlueGreenFinalizedRecovery::shouldNotRun();
    VerifyBlueGreenManagedConfiguration::shouldNotRun();
    assertBlueGreenRecoveryRemoteMutationsDoNotRun();

    $result = ReconcileBlueGreenDeployment::run($scenario->state);

    expect($result->outcome)->toBe('intervention_required')
        ->and($scenario->state->fresh()->active_color)->toBe($scenario->deployment->blue_green_color)
        ->and($scenario->state->fresh()->green_deployment_uuid)->toBe($scenario->deployment->deployment_uuid);
});
