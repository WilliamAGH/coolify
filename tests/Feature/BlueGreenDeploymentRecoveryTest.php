<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentQueueActivity;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\BlueGreenFinalizedRecoveryPlan;
use App\Actions\Application\BlueGreen\BlueGreenLegacyBridgePlan;
use App\Actions\Application\BlueGreen\BlueGreenLegacyRoutingSnapshot;
use App\Actions\Application\BlueGreen\CaptureBlueGreenLegacyRouting;
use App\Actions\Application\BlueGreen\CompleteBlueGreenDeploymentOperation;
use App\Actions\Application\BlueGreen\EnsureBlueGreenPreviousContainerRunning;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\PlanBlueGreenFinalizedRecovery;
use App\Actions\Application\BlueGreen\PlanBlueGreenLegacyBridgeRecovery;
use App\Actions\Application\BlueGreen\PlanBlueGreenLegacyCandidateFenceRecovery;
use App\Actions\Application\BlueGreen\PlanBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\ReadBlueGreenRecoveryArtifact;
use App\Actions\Application\BlueGreen\RebindBlueGreenLegacyRoutingSnapshot;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ReconstructBlueGreenDeploymentRecovery;
use App\Actions\Application\BlueGreen\RemoveExactBlueGreenCandidate;
use App\Actions\Application\BlueGreen\VerifyBlueGreenLegacyProviderRecovery;
use App\Actions\Application\BlueGreen\VerifyBlueGreenManagedConfiguration;
use App\Actions\Application\BlueGreen\VerifyBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\WaitForBlueGreenLegacyDockerRouting;
use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactCommitter;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactRestorer;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

/** @return list<array{router: string, url: string}> */
function blueGreenRecoveryRoutes(): array
{
    return [['router' => 'recovery-public', 'url' => 'https://recovery.example.test/']];
}

function fakeInactiveBlueGreenRecoveryQueue(): void
{
    BlueGreenDeploymentQueueActivity::shouldRun()->once()->andReturnFalse();
}

function fakeBlueGreenRollbackArtifact(): void
{
    $bytes = "http:\n".
        "  routers:\n".
        "    recovery-public:\n".
        "      rule: Host(`recovery.example.test`) && PathPrefix(`/`)\n".
        "      entryPoints: [https]\n".
        "      service: recovery-service\n".
        "      middlewares: [recovery-proof]\n".
        "  middlewares:\n".
        "    recovery-proof:\n".
        "      headers:\n".
        "        customResponseHeaders:\n".
        '          X-Coolify-Probe-Ack: '.str_repeat('a', 64)."\n".
        "  services:\n".
        "    recovery-service:\n".
        "      loadBalancer:\n".
        "        servers: [{url: 'http://recovery:8080'}]\n";
    ReadBlueGreenRecoveryArtifact::shouldRun()
        ->once()
        ->andReturnUsing(fn ($server, $rollbackKey) => new BlueGreenProxyRollbackArtifact(
            key: $rollbackKey,
            existed: true,
            bytes: $bytes,
        ));
}

function fakeBlueGreenMissingRollbackArtifact(): void
{
    ReadBlueGreenRecoveryArtifact::shouldRun()
        ->once()
        ->andReturnUsing(fn ($server, $rollbackKey) => new BlueGreenProxyRollbackArtifact(
            key: $rollbackKey,
            existed: false,
            bytes: '',
        ));
}

function blueGreenLegacyRecoveryPlan(string $acknowledgement): BlueGreenLegacyBridgePlan
{
    $yaml = "http:\n  routers: {}\n";

    return new BlueGreenLegacyBridgePlan(
        configuration: new BlueGreenProxyConfiguration(
            managedFilename: BlueGreenRecoveryScenario::MANAGED_FILENAME,
            yaml: $yaml,
            sha256: hash('sha256', $yaml),
        ),
        publicRoutes: blueGreenRecoveryRoutes(),
        publicAcknowledgement: $acknowledgement,
    );
}

function fakeBlueGreenLegacyRebind(BlueGreenRecoveryScenario $scenario): BlueGreenLegacyRoutingSnapshot
{
    $snapshot = BlueGreenRecoveryScenario::legacyRoutingSnapshot($scenario->application, '10.0.0.99');
    CaptureBlueGreenLegacyRouting::shouldNotRun();
    RebindBlueGreenLegacyRoutingSnapshot::shouldRun()
        ->once()
        ->ordered()
        ->withArgs(fn ($server, $application, $destination, $expectation, $persistedSnapshot): bool => $persistedSnapshot->containerAddresses === ['10.0.0.2'])
        ->andReturn($snapshot);

    return $snapshot;
}

function fakeSuccessfulBlueGreenLegacyRollbackMutations(
    BlueGreenRecoveryScenario $scenario,
    bool $routingMayHaveChanged,
    bool $candidateExists,
    bool $artifactExists,
): void {
    $hasDurableSnapshot = $scenario->state->operation_legacy_routing_snapshot !== null;
    EnsureBlueGreenPreviousContainerRunning::shouldRun()->once()->ordered();
    if ($hasDurableSnapshot) {
        fakeBlueGreenLegacyRebind($scenario);
    } else {
        CaptureBlueGreenLegacyRouting::shouldNotRun();
        RebindBlueGreenLegacyRoutingSnapshot::shouldNotRun();
    }
    if ($routingMayHaveChanged) {
        PlanBlueGreenLegacyCandidateFenceRecovery::shouldRun()
            ->once()
            ->andReturn(blueGreenLegacyRecoveryPlan(str_repeat('b', 64)));
        PlanBlueGreenLegacyBridgeRecovery::shouldRun()
            ->once()
            ->andReturn(blueGreenLegacyRecoveryPlan(str_repeat('c', 64)));
        WriteBlueGreenProxyConfiguration::shouldRun()
            ->twice()
            ->andReturnUsing(fn ($server, $configuration, $rollbackKey) => new BlueGreenProxyRollbackArtifact(
                key: $rollbackKey,
                existed: false,
                bytes: '',
            ));
        VerifyBlueGreenManagedConfiguration::shouldRun()->twice();
        VerifyBlueGreenPublicRecovery::shouldRun()->twice();
        WaitForBlueGreenLegacyDockerRouting::shouldRun()->once();
        BlueGreenProxyRollbackArtifactRestorer::shouldRun()->once();
    }
    $hasDurableSnapshot
        ? VerifyBlueGreenLegacyProviderRecovery::shouldRun()->once()
        : VerifyBlueGreenLegacyProviderRecovery::shouldNotRun();
    $candidateExists
        ? RemoveExactBlueGreenCandidate::shouldRun()->once()
        : RemoveExactBlueGreenCandidate::shouldNotRun();
    $artifactExists
        ? BlueGreenProxyRollbackArtifactCommitter::shouldRun()->once()
        : BlueGreenProxyRollbackArtifactCommitter::shouldNotRun();
}

function fakeSuccessfulBlueGreenRollbackMutations(): void
{
    EnsureBlueGreenPreviousContainerRunning::shouldRun()->once();
    BlueGreenProxyRollbackArtifactRestorer::shouldRun()->once();
    VerifyBlueGreenPublicRecovery::shouldRun()->once();
    RemoveExactBlueGreenCandidate::shouldRun()->once();
    BlueGreenProxyRollbackArtifactCommitter::shouldRun()->once();
}

it('rolls interrupted first-adoption and later-color switches back to the exact previous revision', function (
    bool $fixedPrevious,
    BlueGreenDeploymentPhase $phase,
    string $previousStatus,
) {
    $scenario = BlueGreenRecoveryScenario::create(
        phase: $phase,
        fixedPrevious: $fixedPrevious,
    );
    fakeInactiveBlueGreenRecoveryQueue();
    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturnUsing(function ($server, BlueGreenContainerExpectation $expectation) use ($previousStatus) {
            $status = $expectation->dockerId === BlueGreenRecoveryScenario::CANDIDATE_ID
                ? 'running'
                : $previousStatus;

            return new BlueGreenContainerInspection(
                exists: true,
                dockerId: $expectation->dockerId,
                status: $status,
                health: $status === 'running' ? 'healthy' : 'exited',
            );
        });
    $fixedPrevious ? fakeBlueGreenRollbackArtifact() : fakeBlueGreenMissingRollbackArtifact();
    PlanBlueGreenPublicRecovery::shouldRun()->once()->andReturn(blueGreenRecoveryRoutes());
    $fixedPrevious
        ? fakeSuccessfulBlueGreenRollbackMutations()
        : fakeSuccessfulBlueGreenLegacyRollbackMutations($scenario, true, true, true);

    $result = ReconcileBlueGreenDeployment::run($scenario->state);
    $state = $scenario->state->fresh();
    $deployment = $scenario->deployment->fresh();

    expect($result->outcome)->toBe('reconciled', $result->message)
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->active_color)->toBe($fixedPrevious ? BlueGreenDeploymentColor::BLUE : null)
        ->and($state->pending_color)->toBeNull()
        ->and($state->operation_deployment_uuid)->toBeNull()
        ->and($state->routing_revision)->toBe($fixedPrevious ? 7 : 0)
        ->and($deployment->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($deployment->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($deployment->finished_at)->not->toBeNull();
})->with([
    'first legacy preparing' => [false, BlueGreenDeploymentPhase::PREPARING, 'running'],
    'first legacy stopped after switch' => [false, BlueGreenDeploymentPhase::SWITCHING, 'exited'],
    'interrupted rollback replay' => [false, BlueGreenDeploymentPhase::ROLLING_BACK, 'running'],
    'later fixed-color switch' => [true, BlueGreenDeploymentPhase::SWITCHING, 'running'],
]);

it('keeps rollback terminal after best-effort artifact garbage collection fails', function () {
    $scenario = BlueGreenRecoveryScenario::create(
        phase: BlueGreenDeploymentPhase::SWITCHING,
        fixedPrevious: true,
    );
    fakeInactiveBlueGreenRecoveryQueue();
    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturnUsing(fn ($server, BlueGreenContainerExpectation $expectation) => new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectation->dockerId,
            status: 'running',
            health: 'healthy',
        ));
    fakeBlueGreenRollbackArtifact();
    PlanBlueGreenPublicRecovery::shouldRun()->once()->andReturn(blueGreenRecoveryRoutes());
    EnsureBlueGreenPreviousContainerRunning::shouldRun()->once();
    BlueGreenProxyRollbackArtifactRestorer::shouldRun()->once();
    VerifyBlueGreenPublicRecovery::shouldRun()->once();
    RemoveExactBlueGreenCandidate::shouldRun()->once();
    BlueGreenProxyRollbackArtifactCommitter::shouldRun()
        ->once()
        ->andThrow(new RuntimeException('simulated artifact garbage-collection crash'));

    $result = ReconcileBlueGreenDeployment::run($scenario->state);

    expect($result->outcome)->toBe('reconciled', $result->message)
        ->and($scenario->state->fresh()->operation_deployment_uuid)->toBeNull()
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($scenario->deployment->fresh()->finished_at)->not->toBeNull();
});

it('recovers both sides of the atomic proxy rename boundary from the owned artifact', function (bool $mutationRecorded) {
    $scenario = BlueGreenRecoveryScenario::create(
        routingMutationRecorded: $mutationRecorded,
        legacySnapshotRecorded: true,
    );
    fakeInactiveBlueGreenRecoveryQueue();
    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturnUsing(fn ($server, BlueGreenContainerExpectation $expectation) => new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectation->dockerId,
            status: 'running',
            health: 'healthy',
        ));
    fakeBlueGreenMissingRollbackArtifact();
    PlanBlueGreenPublicRecovery::shouldRun()->once()->andReturn(blueGreenRecoveryRoutes());
    fakeSuccessfulBlueGreenLegacyRollbackMutations($scenario, true, true, true);

    $result = ReconcileBlueGreenDeployment::run($scenario->state);

    expect($result->outcome)->toBe('reconciled', $result->message)
        ->and($scenario->state->fresh()->routing_revision)->toBe(0);
})->with([
    'artifact created before durable mutation marker' => false,
    'atomic rename completed and durable mutation marker recorded' => true,
]);

it('clears a pre-candidate pre-proxy crash without requiring an artifact that never existed', function () {
    $scenario = BlueGreenRecoveryScenario::create(
        candidateId: null,
        routingMutationRecorded: false,
    );
    fakeInactiveBlueGreenRecoveryQueue();
    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturnUsing(fn ($server, BlueGreenContainerExpectation $expectation) => $expectation->blueGreenManaged
            ? BlueGreenContainerInspection::missing()
            : new BlueGreenContainerInspection(
                exists: true,
                dockerId: $expectation->dockerId,
                status: 'running',
                health: 'healthy',
            ));
    ReadBlueGreenRecoveryArtifact::shouldRun()->once()->andReturnNull();
    PlanBlueGreenPublicRecovery::shouldNotRun();
    fakeSuccessfulBlueGreenLegacyRollbackMutations($scenario, false, false, false);
    VerifyBlueGreenPublicRecovery::shouldNotRun();
    BlueGreenProxyRollbackArtifactRestorer::shouldNotRun();

    $result = ReconcileBlueGreenDeployment::run($scenario->state);

    expect($result->outcome)->toBe('reconciled', $result->message)
        ->and($scenario->state->fresh()->routing_revision)->toBe(0)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
});

it('persists a label-proven candidate Docker ID before exact recovery removal', function () {
    $scenario = BlueGreenRecoveryScenario::create(
        candidateId: null,
        routingMutationRecorded: false,
    );
    fakeInactiveBlueGreenRecoveryQueue();
    $candidateInspectionCount = 0;
    InspectBlueGreenContainer::shouldRun()
        ->times(3)
        ->andReturnUsing(function ($server, BlueGreenContainerExpectation $expectation) use (&$candidateInspectionCount) {
            if ($expectation->blueGreenManaged) {
                $candidateInspectionCount++;

                return new BlueGreenContainerInspection(
                    exists: true,
                    dockerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
                    status: 'running',
                    health: 'healthy',
                );
            }

            return new BlueGreenContainerInspection(
                exists: true,
                dockerId: $expectation->dockerId,
                status: 'running',
                health: 'healthy',
            );
        });
    ReadBlueGreenRecoveryArtifact::shouldRun()->once()->andReturnNull();
    PlanBlueGreenPublicRecovery::shouldNotRun();
    CaptureBlueGreenLegacyRouting::shouldNotRun();
    RebindBlueGreenLegacyRoutingSnapshot::shouldNotRun();
    EnsureBlueGreenPreviousContainerRunning::shouldRun()->once();
    VerifyBlueGreenLegacyProviderRecovery::shouldNotRun();
    VerifyBlueGreenPublicRecovery::shouldNotRun();
    RemoveExactBlueGreenCandidate::shouldRun()
        ->once()
        ->withArgs(fn ($server, $application, BlueGreenContainerExpectation $expectation) => $expectation->dockerId === BlueGreenRecoveryScenario::CANDIDATE_ID);
    BlueGreenProxyRollbackArtifactRestorer::shouldNotRun();
    BlueGreenProxyRollbackArtifactCommitter::shouldNotRun();

    $result = ReconcileBlueGreenDeployment::run($scenario->state);

    expect($result->outcome)->toBe('reconciled', $result->message)
        ->and($candidateInspectionCount)->toBe(2)
        ->and($scenario->deployment->fresh()->blue_green_candidate_container_id)
        ->toBe(BlueGreenRecoveryScenario::CANDIDATE_ID);
});

it('recovers every finalized promotion at canonical steady precedence', function (bool $fixedPrevious) {
    $scenario = BlueGreenRecoveryScenario::create(fixedPrevious: $fixedPrevious, finalized: true);
    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state);
    $yaml = "http:\n  routers:\n    recovery-public:\n      rule: Host(`recovery.example.test`) && PathPrefix(`/`)\n      entryPoints:\n        - https\n";
    CompileBlueGreenProxyConfiguration::shouldRun()
        ->once()
        ->withArgs(fn ($application, $destination, BlueGreenRoutingTarget $target): bool => $target->mode === BlueGreenRoutingMode::Steady
            && $target->publicAcknowledgement() !== null)
        ->andReturn(new BlueGreenProxyConfiguration(
            managedFilename: BlueGreenRecoveryScenario::MANAGED_FILENAME,
            yaml: $yaml,
            sha256: hash('sha256', $yaml),
        ));

    $plan = PlanBlueGreenFinalizedRecovery::run($operation);

    expect($plan->configuration->managedFilename)->toBe(BlueGreenRecoveryScenario::MANAGED_FILENAME);
})->with([
    'first adoption' => [false],
    'later fixed-color promotion' => [true],
]);

it('never rolls back a DB-finalized promotion and completes only its forward cleanup', function (bool $artifactExists) {
    $scenario = BlueGreenRecoveryScenario::create(
        fixedPrevious: true,
        finalized: true,
    );
    fakeInactiveBlueGreenRecoveryQueue();
    InspectBlueGreenContainer::shouldRun()->once()->andReturn(new BlueGreenContainerInspection(
        exists: true,
        dockerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
        status: 'running',
        health: 'healthy',
    ));
    if ($artifactExists) {
        fakeBlueGreenRollbackArtifact();
    } else {
        ReadBlueGreenRecoveryArtifact::shouldRun()->once()->andReturnNull();
    }
    $configuration = new BlueGreenProxyConfiguration(
        managedFilename: BlueGreenRecoveryScenario::MANAGED_FILENAME,
        yaml: "http:\n  routers: {}\n",
        sha256: hash('sha256', "http:\n  routers: {}\n"),
    );
    PlanBlueGreenFinalizedRecovery::shouldRun()->once()->andReturn(new BlueGreenFinalizedRecoveryPlan(
        configuration: $configuration,
        publicRoutes: blueGreenRecoveryRoutes(),
        publicAcknowledgement: str_repeat('a', 64),
    ));
    VerifyBlueGreenManagedConfiguration::shouldRun()->once();
    EnsureBlueGreenPreviousContainerRunning::shouldRun()->once();
    VerifyBlueGreenPublicRecovery::shouldRun()->once();
    $artifactExists
        ? BlueGreenProxyRollbackArtifactCommitter::shouldRun()->once()
        : BlueGreenProxyRollbackArtifactCommitter::shouldNotRun();
    BlueGreenProxyRollbackArtifactRestorer::shouldNotRun();
    RemoveExactBlueGreenCandidate::shouldNotRun();

    $result = ReconcileBlueGreenDeployment::run($scenario->state);
    $state = $scenario->state->fresh();
    $deployment = $scenario->deployment->fresh();

    expect($result->outcome)->toBe('reconciled')
        ->and($state->active_color)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($state->green_deployment_uuid)->toBe($deployment->deployment_uuid)
        ->and($state->blue_deployment_uuid)->toBe($scenario->previousDeployment->deployment_uuid)
        ->and($state->operation_deployment_uuid)->toBeNull()
        ->and($state->routing_revision)->toBe(8)
        ->and($deployment->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($deployment->finished_at)->not->toBeNull();

    expect(ReconcileBlueGreenDeployment::run($state)->outcome)->toBe('skipped');
})->with([
    'leftover exact artifact' => true,
    'artifact already committed before crash' => false,
]);

it('completes and idempotently retries only the exact finalized claim cycle', function () {
    $scenario = BlueGreenRecoveryScenario::create(fixedPrevious: true, finalized: true);
    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state);

    $completed = CompleteBlueGreenDeploymentOperation::run($operation->claim);
    $retried = CompleteBlueGreenDeploymentOperation::run($operation->claim);

    expect($completed->operation_deployment_uuid)->toBeNull()
        ->and($completed->operation_previous_container_name)->toBeNull()
        ->and($completed->legacy_container_name)->toBeNull()
        ->and($retried->routing_revision)->toBe($operation->claim->expectedRoutingRevision)
        ->and($retried->green_deployment_uuid)->toBe($operation->claim->deploymentUuid)
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($scenario->deployment->fresh()->finished_at)->not->toBeNull();
});

it('completes and idempotently retries exact finalized forward recovery', function () {
    $scenario = BlueGreenRecoveryScenario::create(fixedPrevious: true, finalized: true);
    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state);

    $completed = CompleteBlueGreenDeploymentOperation::run($operation);
    $retried = CompleteBlueGreenDeploymentOperation::run($operation);

    expect($completed->operation_deployment_uuid)->toBeNull()
        ->and($retried->routing_revision)->toBe($operation->claim->expectedRoutingRevision)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($scenario->deployment->fresh()->finished_at)->not->toBeNull();
});

it('rejects a completed claim from two cycles ago after the same color is promoted again', function () {
    $scenario = BlueGreenRecoveryScenario::create(fixedPrevious: true, finalized: true);
    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state);
    CompleteBlueGreenDeploymentOperation::run($operation->claim);
    $newerDeployment = ApplicationDeploymentQueue::create([
        'application_id' => $scenario->application->id,
        'deployment_uuid' => 'newer-green-cycle',
        'pull_request_id' => 0,
        'destination_id' => $scenario->destination->id,
        'server_id' => $scenario->destination->server_id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 10,
    ]);
    $scenario->state->newQuery()->whereKey($scenario->state->id)->update([
        'green_deployment_uuid' => $newerDeployment->deployment_uuid,
        'routing_revision' => 10,
    ]);

    expect(fn () => CompleteBlueGreenDeploymentOperation::run($operation->claim))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'exact finalized claim cycle');

    expect($scenario->state->fresh()->routing_revision)->toBe(10)
        ->and($scenario->state->fresh()->green_deployment_uuid)->toBe($newerDeployment->deployment_uuid);
});

it('rejects idempotent completion when the exact queue destination provenance changed', function () {
    $scenario = BlueGreenRecoveryScenario::create(fixedPrevious: true, finalized: true);
    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state);
    CompleteBlueGreenDeploymentOperation::run($operation->claim);
    $scenario->deployment->update(['destination_id' => $scenario->destination->id + 1000]);

    expect(fn () => CompleteBlueGreenDeploymentOperation::run($operation->claim))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'exact finalized claim cycle');
});

it('rejects fixed-color completion when the recorded previous container name is corrupt', function () {
    $scenario = BlueGreenRecoveryScenario::create(fixedPrevious: true, finalized: true);
    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state);
    $scenario->state->update(['operation_previous_container_name' => 'corrupt-previous-container']);

    expect(fn () => CompleteBlueGreenDeploymentOperation::run($operation->claim))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'durable cleanup');

    expect($scenario->state->fresh()->operation_deployment_uuid)->toBe($operation->claim->deploymentUuid);
});
