<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\RemoveBlueGreenInactiveContainer;
use App\Actions\Application\BlueGreen\RemoveExactBlueGreenCandidate;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

/** @return array{application: Application, destination: StandaloneDocker, server: Server} */
function inactiveBlueGreenContainerContext(): array
{
    $context = BlueGreenDeactivationScenario::context();
    $application = $context['application'];
    $destination = $context['destination'];
    $server = $context['server'];
    $server->privateKey->storeInFileSystem();

    return compact('application', 'destination', 'server');
}

/** @return array{ApplicationBlueGreenDeployment, BlueGreenDeploymentClaim} */
function inactiveBlueGreenClaim(
    Application $application,
    StandaloneDocker $destination,
    ?string $inactiveDeploymentUuid,
): array {
    $operationUuid = 'current-green-operation';
    $state = ApplicationBlueGreenDeployment::create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'pending_color' => BlueGreenDeploymentColor::GREEN,
        'pending_deployment_uuid' => $operationUuid,
        'green_deployment_uuid' => $inactiveDeploymentUuid,
        'operation_deployment_uuid' => $operationUuid,
        'phase' => BlueGreenDeploymentPhase::PREPARING,
        'routing_revision' => 3,
    ]);

    return [$state, new BlueGreenDeploymentClaim(
        stateId: $state->id,
        applicationId: $application->id,
        standaloneDockerId: $destination->id,
        pendingColor: BlueGreenDeploymentColor::GREEN,
        previousActiveColor: BlueGreenDeploymentColor::BLUE,
        deploymentUuid: $operationUuid,
        expectedRoutingRevision: 3,
        legacyContainerName: null,
    )];
}

it('retires only the exact persisted inactive slot identity and labels', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = inactiveBlueGreenContainerContext();
    $inactiveDeploymentUuid = 'proven-inactive-green';
    $inactiveDockerId = str_repeat('a', 64);
    ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => $inactiveDeploymentUuid,
        'destination_id' => $destination->id,
        'server_id' => $server->id,
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now(),
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 2,
        'blue_green_candidate_container_id' => $inactiveDockerId,
    ]);
    [, $claim] = inactiveBlueGreenClaim($application, $destination, $inactiveDeploymentUuid);
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->withArgs(fn ($actualServer, BlueGreenContainerExpectation $expectation): bool => $actualServer->is($server)
            && $expectation->name === $application->uuid.'-green'
            && $expectation->dockerId === $inactiveDockerId
            && $expectation->deploymentUuid === $inactiveDeploymentUuid
            && $expectation->color === BlueGreenDeploymentColor::GREEN
            && $expectation->routingRevision === 2)
        ->andReturn(BlueGreenContainerInspection::missing());
    RemoveExactBlueGreenCandidate::shouldRun()
        ->once()
        ->withArgs(fn ($actualServer, $actualApplication, BlueGreenContainerExpectation $expectation): bool => $actualServer->is($server)
            && $actualApplication->is($application)
            && $expectation->dockerId === $inactiveDockerId);

    RemoveBlueGreenInactiveContainer::run($server, $application, $destination, $claim);
});

it('fails closed when an unclaimed inactive slot name is occupied', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = inactiveBlueGreenContainerContext();
    [, $claim] = inactiveBlueGreenClaim($application, $destination, null);
    Process::fake(['*' => Process::result(output: 'occupied')]);
    InspectBlueGreenContainer::shouldNotRun();
    RemoveExactBlueGreenCandidate::shouldNotRun();

    expect(fn () => RemoveBlueGreenInactiveContainer::run($server, $application, $destination, $claim))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'without durable ownership provenance');
});

it('fails closed when a persisted inactive name was reused by another Docker identity', function () {
    ['application' => $application, 'server' => $server] = inactiveBlueGreenContainerContext();
    $expectation = new BlueGreenContainerExpectation(
        name: $application->uuid.'-green',
        dockerId: str_repeat('b', 64),
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: 'persisted-green-owner',
        color: BlueGreenDeploymentColor::GREEN,
        routingRevision: 2,
    );
    Process::fake([
        "*{$expectation->dockerId}*" => Process::result(output: 'coolify-blue-green-container:missing'),
        "*{$expectation->name}*" => Process::result(output: 'foreign-name-owner'),
        '*' => Process::result(),
    ]);

    expect(fn () => InspectBlueGreenContainer::run($server, $expectation))
        ->toThrow(RuntimeException::class, 'was reused by another Docker identity');
});
