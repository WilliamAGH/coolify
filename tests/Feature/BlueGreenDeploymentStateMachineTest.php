<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\BlueGreenLegacyRouter;
use App\Actions\Application\BlueGreen\BlueGreenLegacyRoutingSnapshot;
use App\Actions\Application\BlueGreen\BlueGreenLegacyService;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\CompleteBlueGreenDeploymentOperation;
use App\Actions\Application\BlueGreen\RecordBlueGreenCandidateIdentity;
use App\Actions\Application\BlueGreen\RecordBlueGreenLegacyRoutingSnapshot;
use App\Actions\Application\BlueGreen\RecordBlueGreenRoutingMutation;
use App\Actions\Application\BlueGreen\TransitionsBlueGreenDeployment;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{application: Application, destination: StandaloneDocker, server: Server, team: Team}
 */
function createBlueGreenStateMachineContext(bool $enableBlueGreen = true): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://blue-green-state-machine.example.com',
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

    return compact('application', 'destination', 'server', 'team');
}

function createBlueGreenStateMachineDeployment(
    Application $application,
    StandaloneDocker $destination,
    string $deploymentUuid,
): ApplicationDeploymentQueue {
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => $deploymentUuid,
        'destination_id' => $destination->id,
        'server_id' => $destination->server_id,
    ]);
}

function legacyBlueGreenStateMachineContainer(Application $application, string $name): BlueGreenContainerExpectation
{
    return new BlueGreenContainerExpectation(
        name: $name,
        dockerId: str_repeat('a', 64),
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: false,
    );
}

function activeBlueGreenStateMachineContainer(
    Application $application,
    BlueGreenDeploymentClaim $claim,
): BlueGreenContainerExpectation {
    return new BlueGreenContainerExpectation(
        name: $application->uuid.'-'.$claim->pendingColor->value,
        dockerId: str_repeat('b', 64),
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: $claim->deploymentUuid,
        color: $claim->pendingColor,
        routingRevision: $claim->expectedRoutingRevision,
    );
}

function recordFirstBlueGreenStateMachineOperation(
    Application $application,
    BlueGreenDeploymentClaim $claim,
): void {
    if ($claim->legacyContainerName !== null) {
        $rule = 'Host(`state-machine.example.test`) && PathPrefix(`/`)';
        RecordBlueGreenLegacyRoutingSnapshot::run($claim, new BlueGreenLegacyRoutingSnapshot(
            containerName: $claim->legacyContainerName,
            dockerId: str_repeat('a', 64),
            port: 3000,
            containerAddresses: ['10.0.0.2'],
            routers: [new BlueGreenLegacyRouter(
                name: 'state-machine',
                rule: $rule,
                entryPoints: ['https'],
                serviceName: 'state-machine',
                middlewares: [],
                priority: strlen($rule),
                tls: true,
                certificateResolver: 'letsencrypt',
            )],
            services: [new BlueGreenLegacyService(
                name: 'state-machine',
                port: 3000,
                routerNames: ['state-machine'],
            )],
            labelsSha256: str_repeat('c', 64),
        ));
    }
    RecordBlueGreenCandidateIdentity::run($claim, new BlueGreenContainerInspection(
        exists: true,
        dockerId: str_repeat('b', 64),
        status: 'running',
        health: 'healthy',
    ));
    RecordBlueGreenRoutingMutation::run($claim);
}

it('claims the first inactive color with the detected legacy identity and queue provenance', function () {
    ['application' => $application, 'destination' => $destination] = createBlueGreenStateMachineContext();
    $deployment = createBlueGreenStateMachineDeployment($application, $destination, 'first-blue-deployment');

    $claim = ClaimBlueGreenDeployment::run(
        $application,
        $destination,
        $deployment,
        'legacy-application-container',
        legacyBlueGreenStateMachineContainer($application, 'legacy-application-container'),
    );
    $state = ApplicationBlueGreenDeployment::query()->sole();

    expect($claim->pendingColor)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($claim->previousActiveColor)->toBeNull()
        ->and($claim->expectedRoutingRevision)->toBe(1)
        ->and($claim->legacyContainerName)->toBe('legacy-application-container')
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($state->active_color)->toBeNull()
        ->and($state->pending_color)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($state->pending_deployment_uuid)->toBe($deployment->deployment_uuid)
        ->and($state->legacy_container_name)->toBe('legacy-application-container')
        ->and($state->operation_deployment_uuid)->toBe($deployment->deployment_uuid)
        ->and($state->operation_previous_container_name)->toBe('legacy-application-container')
        ->and($state->operation_previous_container_id)->toBe(str_repeat('a', 64))
        ->and($state->operation_candidate_container_name)->toBe($application->uuid.'-blue')
        ->and($state->operation_candidate_container_id)->toBeNull()
        ->and($state->operation_rollback_managed_filename)->toBe($claim->rollbackManagedFilename)
        ->and($state->operation_routing_mutated_at)->toBeNull()
        ->and($state->routing_revision)->toBe(1)
        ->and($deployment->fresh()->blue_green_color)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($deployment->fresh()->blue_green_routing_revision)->toBe(1)
        ->and($deployment->fresh()->blue_green_previous_container_id)->toBe(str_repeat('a', 64))
        ->and($deployment->fresh()->blue_green_candidate_container_id)->toBeNull()
        ->and($deployment->fresh()->blue_green_rollback_managed_filename)->toBe($claim->rollbackManagedFilename)
        ->and($deployment->fresh()->blue_green_routing_mutated_at)->toBeNull();
});

it('serializes competing claims without overwriting the winner', function () {
    ['application' => $application, 'destination' => $destination] = createBlueGreenStateMachineContext();
    $winner = createBlueGreenStateMachineDeployment($application, $destination, 'winning-deployment');
    $contender = createBlueGreenStateMachineDeployment($application, $destination, 'contending-deployment');

    $claim = ClaimBlueGreenDeployment::run($application, $destination, $winner);

    expect(fn () => ClaimBlueGreenDeployment::run($application, $destination, $contender))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'already pending');

    $state = ApplicationBlueGreenDeployment::query()->sole();
    expect(ApplicationBlueGreenDeployment::query()->count())->toBe(1)
        ->and($state->pending_deployment_uuid)->toBe($claim->deploymentUuid)
        ->and($state->routing_revision)->toBe($claim->expectedRoutingRevision)
        ->and($contender->fresh()->blue_green_color)->toBeNull()
        ->and($contender->fresh()->blue_green_phase)->toBeNull()
        ->and($contender->fresh()->blue_green_routing_revision)->toBeNull();
});

it('rejects an application configured on more than one standalone Docker destination', function () {
    ['application' => $application, 'team' => $team] = createBlueGreenStateMachineContext(enableBlueGreen: false);
    $additionalServer = Server::factory()->create(['team_id' => $team->id]);
    $additionalDestination = $additionalServer->standaloneDockers()->firstOrFail();
    $application->additional_servers()->attach($additionalServer->id, [
        'standalone_docker_id' => $additionalDestination->id,
        'status' => 'exited',
    ]);
    expect($application->fresh()->blueGreenDeploymentIneligibilityReason())
        ->toBe('Blue-green deployments support exactly one standalone Docker destination.');
});

it('rejects a queue whose destination does not match the claimed standalone Docker destination', function () {
    ['application' => $application, 'destination' => $destination, 'team' => $team] = createBlueGreenStateMachineContext();
    $otherServer = Server::factory()->create(['team_id' => $team->id]);
    $otherDestination = $otherServer->standaloneDockers()->firstOrFail();
    $deployment = createBlueGreenStateMachineDeployment($application, $destination, 'wrong-destination-deployment');

    expect(fn () => ClaimBlueGreenDeployment::run($application, $otherDestination, $deployment))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'destination does not match');

    expect(ApplicationBlueGreenDeployment::query()->count())->toBe(0);
});

it('rejects a queue whose server does not own its destination', function () {
    ['application' => $application, 'destination' => $destination, 'team' => $team] = createBlueGreenStateMachineContext();
    $otherServer = Server::factory()->create(['team_id' => $team->id]);
    $deployment = createBlueGreenStateMachineDeployment($application, $destination, 'wrong-server-deployment');
    $deployment->update(['server_id' => $otherServer->id]);

    expect(fn () => ClaimBlueGreenDeployment::run($application, $destination, $deployment->fresh()))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'server does not own');

    expect(ApplicationBlueGreenDeployment::query()->count())->toBe(0);
});

it('rejects an unconfigured destination even when queue destination and server agree', function () {
    ['application' => $application, 'team' => $team] = createBlueGreenStateMachineContext();
    $otherServer = Server::factory()->create(['team_id' => $team->id]);
    $otherDestination = $otherServer->standaloneDockers()->firstOrFail();
    $deployment = createBlueGreenStateMachineDeployment($application, $otherDestination, 'unconfigured-destination-deployment');

    expect(fn () => ClaimBlueGreenDeployment::run($application, $otherDestination, $deployment))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'not configured');

    expect(ApplicationBlueGreenDeployment::query()->count())->toBe(0);
});

it('promotes exact claims and alternates colors without losing deployment provenance', function () {
    ['application' => $application, 'destination' => $destination] = createBlueGreenStateMachineContext();
    $blueDeployment = createBlueGreenStateMachineDeployment($application, $destination, 'promoted-blue-deployment');
    $blueClaim = ClaimBlueGreenDeployment::run(
        $application,
        $destination,
        $blueDeployment,
        'legacy-container',
        legacyBlueGreenStateMachineContainer($application, 'legacy-container'),
    );

    recordFirstBlueGreenStateMachineOperation($application, $blueClaim);
    TransitionsBlueGreenDeployment::markSwitching($blueClaim);
    $blueState = TransitionsBlueGreenDeployment::finalize($blueClaim);

    expect($blueState->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($blueState->active_color)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($blueState->pending_color)->toBeNull()
        ->and($blueState->pending_deployment_uuid)->toBeNull()
        ->and($blueState->blue_deployment_uuid)->toBe($blueDeployment->deployment_uuid)
        ->and($blueState->legacy_container_name)->toBe('legacy-container')
        ->and($blueDeployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE);

    CompleteBlueGreenDeploymentOperation::run($blueClaim);
    $greenDeployment = createBlueGreenStateMachineDeployment($application, $destination, 'promoted-green-deployment');
    $greenClaim = ClaimBlueGreenDeployment::run(
        application: $application,
        standaloneDocker: $destination,
        deployment: $greenDeployment,
        previousContainer: activeBlueGreenStateMachineContainer($application, $blueClaim),
    );

    expect($greenClaim->pendingColor)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($greenClaim->previousActiveColor)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($greenClaim->expectedRoutingRevision)->toBe(2)
        ->and($greenClaim->legacyContainerName)->toBeNull();

    TransitionsBlueGreenDeployment::markSwitching($greenClaim);
    $greenState = TransitionsBlueGreenDeployment::finalize($greenClaim);

    expect($greenState->active_color)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($greenState->blue_deployment_uuid)->toBe($blueDeployment->deployment_uuid)
        ->and($greenState->green_deployment_uuid)->toBe($greenDeployment->deployment_uuid)
        ->and($greenState->routing_revision)->toBe(2)
        ->and($greenDeployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE);
});

it('rejects stale calls after a newer claim owns the state', function () {
    ['application' => $application, 'destination' => $destination] = createBlueGreenStateMachineContext();
    $firstDeployment = createBlueGreenStateMachineDeployment($application, $destination, 'stale-first-deployment');
    $firstClaim = ClaimBlueGreenDeployment::run($application, $destination, $firstDeployment);
    recordFirstBlueGreenStateMachineOperation($application, $firstClaim);
    TransitionsBlueGreenDeployment::markSwitching($firstClaim);
    TransitionsBlueGreenDeployment::finalize($firstClaim);
    CompleteBlueGreenDeploymentOperation::run($firstClaim);

    $secondDeployment = createBlueGreenStateMachineDeployment($application, $destination, 'current-second-deployment');
    $secondClaim = ClaimBlueGreenDeployment::run(
        application: $application,
        standaloneDocker: $destination,
        deployment: $secondDeployment,
        previousContainer: activeBlueGreenStateMachineContainer($application, $firstClaim),
    );

    expect(fn () => TransitionsBlueGreenDeployment::markSwitching($firstClaim))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'stale')
        ->and(fn () => TransitionsBlueGreenDeployment::finalize($firstClaim))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'stale');

    $state = ApplicationBlueGreenDeployment::query()->sole();
    expect($state->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($state->pending_deployment_uuid)->toBe($secondClaim->deploymentUuid)
        ->and($state->routing_revision)->toBe($secondClaim->expectedRoutingRevision)
        ->and($secondDeployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::PREPARING);
});

it('keeps the pending claim through rollback and clears it only after rollback finishes', function (bool $switching) {
    ['application' => $application, 'destination' => $destination] = createBlueGreenStateMachineContext();
    $deployment = createBlueGreenStateMachineDeployment(
        $application,
        $destination,
        $switching ? 'switching-rollback-deployment' : 'preparing-rollback-deployment',
    );
    $claim = ClaimBlueGreenDeployment::run(
        $application,
        $destination,
        $deployment,
        'rollback-legacy',
        legacyBlueGreenStateMachineContainer($application, 'rollback-legacy'),
    );
    if ($switching) {
        TransitionsBlueGreenDeployment::markSwitching($claim);
    }

    $rollingBack = TransitionsBlueGreenDeployment::beginRollback($claim);

    expect($rollingBack->phase)->toBe(BlueGreenDeploymentPhase::ROLLING_BACK)
        ->and($rollingBack->pending_color)->toBe($claim->pendingColor)
        ->and($rollingBack->pending_deployment_uuid)->toBe($claim->deploymentUuid)
        ->and($deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::ROLLING_BACK);

    $rolledBack = TransitionsBlueGreenDeployment::finishRollback($claim);
    $failedDeployment = $deployment->fresh();

    expect($rolledBack->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($rolledBack->active_color)->toBeNull()
        ->and($rolledBack->pending_color)->toBeNull()
        ->and($rolledBack->pending_deployment_uuid)->toBeNull()
        ->and($rolledBack->routing_revision)->toBe($claim->expectedRoutingRevision - 1)
        ->and($rolledBack->legacy_container_name)->toBe('rollback-legacy')
        ->and($failedDeployment->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($failedDeployment->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($failedDeployment->finished_at)->not->toBeNull();

    expect(fn () => TransitionsBlueGreenDeployment::finishRollback($claim))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'stale');
})->with([
    'from preparing' => false,
    'from switching' => true,
]);

it('marks active transitions for intervention without discarding recovery ownership', function (string $phase) {
    ['application' => $application, 'destination' => $destination] = createBlueGreenStateMachineContext();
    $deployment = createBlueGreenStateMachineDeployment($application, $destination, "intervention-{$phase}-deployment");
    $claim = ClaimBlueGreenDeployment::run($application, $destination, $deployment);

    if (in_array($phase, ['switching', 'rolling_back'], true)) {
        TransitionsBlueGreenDeployment::markSwitching($claim);
    }
    if ($phase === 'rolling_back') {
        TransitionsBlueGreenDeployment::beginRollback($claim);
    }

    $intervention = TransitionsBlueGreenDeployment::markInterventionRequired($claim);

    expect($intervention->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($intervention->pending_color)->toBe($claim->pendingColor)
        ->and($intervention->pending_deployment_uuid)->toBe($claim->deploymentUuid)
        ->and($deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and(fn () => TransitionsBlueGreenDeployment::finishRollback($claim))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'stale');
})->with(['preparing', 'switching', 'rolling_back']);

it('can mark an exact finalized adoption for intervention before legacy retirement', function () {
    ['application' => $application, 'destination' => $destination] = createBlueGreenStateMachineContext();
    $deployment = createBlueGreenStateMachineDeployment($application, $destination, 'finalized-intervention-deployment');
    $claim = ClaimBlueGreenDeployment::run(
        $application,
        $destination,
        $deployment,
        'finalized-legacy',
        legacyBlueGreenStateMachineContainer($application, 'finalized-legacy'),
    );
    recordFirstBlueGreenStateMachineOperation($application, $claim);
    TransitionsBlueGreenDeployment::markSwitching($claim);
    TransitionsBlueGreenDeployment::finalize($claim);

    $intervention = TransitionsBlueGreenDeployment::markInterventionRequired($claim);

    expect($intervention->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($intervention->active_color)->toBe($claim->pendingColor)
        ->and($intervention->pending_color)->toBeNull()
        ->and($intervention->legacy_container_name)->toBe('finalized-legacy')
        ->and($deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and(fn () => CompleteBlueGreenDeploymentOperation::run($claim))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'durable cleanup');
});

it('completes only the exact finalized legacy operation and retries idempotently', function () {
    ['application' => $application, 'destination' => $destination] = createBlueGreenStateMachineContext();
    $deployment = createBlueGreenStateMachineDeployment($application, $destination, 'legacy-retirement-deployment');
    $claim = ClaimBlueGreenDeployment::run(
        $application,
        $destination,
        $deployment,
        'legacy-to-retire',
        legacyBlueGreenStateMachineContainer($application, 'legacy-to-retire'),
    );
    recordFirstBlueGreenStateMachineOperation($application, $claim);
    TransitionsBlueGreenDeployment::markSwitching($claim);
    TransitionsBlueGreenDeployment::finalize($claim);
    $finalizedState = ApplicationBlueGreenDeployment::query()->sole();
    $finalizedDeployment = $deployment->fresh();

    expect($finalizedState->operation_deployment_uuid)->toBe($claim->deploymentUuid)
        ->and($finalizedDeployment->status)->not->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($finalizedDeployment->finished_at)->toBeNull();

    $cleared = CompleteBlueGreenDeploymentOperation::run($claim);
    $retried = CompleteBlueGreenDeploymentOperation::run($claim);
    $completedDeployment = $deployment->fresh();

    expect($cleared->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($cleared->active_color)->toBe($claim->pendingColor)
        ->and($cleared->legacy_container_name)->toBeNull()
        ->and($cleared->operation_deployment_uuid)->toBeNull()
        ->and($cleared->operation_legacy_routing_snapshot)->toBeNull()
        ->and($completedDeployment->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($completedDeployment->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($completedDeployment->finished_at)->not->toBeNull()
        ->and($retried->legacy_container_name)->toBeNull();
});

it('rolls back a state transition when queue provenance fails its compare-and-swap', function () {
    ['application' => $application, 'destination' => $destination] = createBlueGreenStateMachineContext();
    $deployment = createBlueGreenStateMachineDeployment($application, $destination, 'queue-cas-mismatch-deployment');
    $claim = ClaimBlueGreenDeployment::run($application, $destination, $deployment);
    $deployment->update(['blue_green_phase' => BlueGreenDeploymentPhase::SWITCHING]);

    expect(fn () => TransitionsBlueGreenDeployment::markSwitching($claim))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'provenance');

    $state = ApplicationBlueGreenDeployment::query()->sole();
    expect($state->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($state->pending_deployment_uuid)->toBe($claim->deploymentUuid)
        ->and($state->routing_revision)->toBe($claim->expectedRoutingRevision);
});

it('rejects a queue that was already claimed by another blue-green lifecycle', function () {
    ['application' => $application, 'destination' => $destination] = createBlueGreenStateMachineContext();
    $deployment = createBlueGreenStateMachineDeployment($application, $destination, 'reused-provenance-deployment');
    $deployment->update([
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 9,
    ]);

    expect(fn () => ClaimBlueGreenDeployment::run($application, $destination, $deployment->fresh()))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'already has blue-green provenance');

    expect(ApplicationBlueGreenDeployment::query()->count())->toBe(0);
});

it('validates immutable claim invariants', function () {
    expect(fn () => new BlueGreenDeploymentClaim(
        stateId: 1,
        applicationId: 1,
        standaloneDockerId: 1,
        pendingColor: BlueGreenDeploymentColor::BLUE,
        previousActiveColor: null,
        deploymentUuid: '',
        expectedRoutingRevision: 1,
        legacyContainerName: null,
    ))->toThrow(InvalidArgumentException::class, 'must not be empty');

    expect(fn () => new BlueGreenDeploymentClaim(
        stateId: 1,
        applicationId: 1,
        standaloneDockerId: 1,
        pendingColor: BlueGreenDeploymentColor::BLUE,
        previousActiveColor: null,
        deploymentUuid: 'valid-deployment',
        expectedRoutingRevision: 0,
        legacyContainerName: null,
    ))->toThrow(InvalidArgumentException::class, 'must be positive');
});
