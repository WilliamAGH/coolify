<?php

use App\Actions\Application\BlueGreen\ActiveApplicationContainerResolution;
use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\ResolveActiveApplicationContainer;
use App\Actions\Application\BlueGreen\ResolveBlueGreenActiveReplicaSet;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array{application: Application, server: Server, destination: mixed, topology: string, routing: string} */
function activeContainerResolverFixture(string $host): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => "https://{$host}.example.test",
    ]);

    return [
        'application' => $application,
        'server' => $server,
        'destination' => $destination,
        'topology' => hash('sha256', $host.'-topology'),
        'routing' => hash('sha256', $host.'-routing'),
    ];
}

/** @param  array{application: Application, server: Server, destination: mixed, topology: string, routing: string}  $fixture */
function activeContainerQueue(
    array $fixture,
    string $uuid,
    string $containerId,
    BlueGreenDeploymentColor $color,
    int $revision,
    BlueGreenDeploymentPhase $phase = BlueGreenDeploymentPhase::IDLE,
): ApplicationDeploymentQueue {
    return ApplicationDeploymentQueue::query()->create([
        'application_id' => $fixture['application']->id,
        'deployment_uuid' => $uuid,
        'pull_request_id' => 0,
        'server_id' => $fixture['server']->id,
        'destination_id' => $fixture['destination']->id,
        'status' => $phase === BlueGreenDeploymentPhase::DRAINING
            ? ApplicationDeploymentStatus::IN_PROGRESS->value
            : ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => $color,
        'blue_green_phase' => $phase,
        'blue_green_routing_revision' => $revision,
        'blue_green_topology_digest' => $fixture['topology'],
        'blue_green_routing_config_digest' => $fixture['routing'],
        'blue_green_candidate_container_id' => $containerId,
    ]);
}

function activeContainerPreparedReplicaCount(
    ApplicationDeploymentQueue $deployment,
    int $replicaCount,
): ApplicationDeploymentQueue {
    $deployment = $deployment->fresh();
    $deployment->update([
        'prepared_activation_payload' => $deployment->makePreparedActivationPayload([
            'blue_green_claim' => ['replica_count' => $replicaCount],
        ]),
    ]);

    return $deployment->fresh();
}

it('uses the exact active fixed-color provenance while idle', function () {
    $fixture = activeContainerResolverFixture('resolver-idle');
    $containerId = str_repeat('a', 64);
    $actualRoutingDigest = hash('sha256', 'resolver-idle-actual-routing');
    $deployment = activeContainerPreparedReplicaCount(
        activeContainerQueue($fixture, 'resolver-idle-blue', $containerId, BlueGreenDeploymentColor::BLUE, 3),
        1,
    );
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'resolver-idle-blue',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 3,
        'destination_topology_digest' => $fixture['topology'],
        'application_routing_config_digest' => $actualRoutingDigest,
    ]);
    $fixture['application']->settings->update(['blue_green_replica_count' => 2]);

    expect(data_get(
        $deployment->validatedPreparedActivationPayload(),
        'artifact.blue_green_claim.replica_count',
    ))->toBe(1);
    $resolution = ResolveActiveApplicationContainer::run(collect([$fixture['application']]))->first();

    expect($resolution)->toBeInstanceOf(ActiveApplicationContainerResolution::class)
        ->and($resolution->observable)->toBeTrue()
        ->and($resolution->containerId)->toBe($containerId)
        ->and($resolution->deploymentUuid)->toBe('resolver-idle-blue')
        ->and($resolution->matches($containerId, 'resolver-idle-blue'))->toBeTrue()
        ->and($resolution->matches(substr($containerId, 0, 12), 'resolver-idle-blue'))->toBeTrue()
        ->and($resolution->matches(null, 'resolver-idle-blue'))->toBeFalse()
        ->and($resolution->matches(str_repeat('b', 64), 'resolver-idle-blue'))->toBeFalse();
});

it('keeps the predecessor observable until the candidate is routed', function (BlueGreenDeploymentPhase $phase) {
    $fixture = activeContainerResolverFixture('resolver-'.$phase->value);
    $previousId = str_repeat('b', 64);
    activeContainerQueue($fixture, 'resolver-previous-green', $previousId, BlueGreenDeploymentColor::GREEN, 4);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'pending_color' => BlueGreenDeploymentColor::BLUE,
        'green_deployment_uuid' => 'resolver-previous-green',
        'operation_previous_active_color' => BlueGreenDeploymentColor::GREEN,
        'operation_previous_deployment_uuid' => 'resolver-previous-green',
        'operation_previous_routing_revision' => 4,
        'operation_previous_container_id' => $previousId,
        'operation_deployment_uuid' => 'resolver-candidate-blue',
        'operation_candidate_container_id' => str_repeat('c', 64),
        'phase' => $phase,
        'routing_revision' => 5,
        'destination_topology_digest' => $fixture['topology'],
        'application_routing_config_digest' => $fixture['routing'],
    ]);

    $resolution = ResolveActiveApplicationContainer::run(collect([$fixture['application']]))->first();

    expect($resolution->observable)->toBeTrue()
        ->and($resolution->containerId)->toBe($previousId)
        ->and($resolution->deploymentUuid)->toBe('resolver-previous-green');
})->with([
    BlueGreenDeploymentPhase::PREPARING,
    BlueGreenDeploymentPhase::SWITCHING,
    BlueGreenDeploymentPhase::ROLLING_BACK,
]);

it('uses managed-route mutation ordering instead of lifecycle phase for cutover and rollback', function () {
    $fixture = activeContainerResolverFixture('resolver-route-ordering');
    $previousId = str_repeat('4', 64);
    $candidateId = str_repeat('5', 64);
    $previousManagedSha = hash('sha256', 'previous-managed-route');
    $candidateManagedSha = hash('sha256', 'candidate-managed-route');
    $previous = activeContainerQueue(
        $fixture,
        'resolver-route-ordering-green',
        $previousId,
        BlueGreenDeploymentColor::GREEN,
        6,
    );
    $candidate = activeContainerQueue(
        $fixture,
        'resolver-route-ordering-blue',
        $candidateId,
        BlueGreenDeploymentColor::BLUE,
        7,
        BlueGreenDeploymentPhase::ROLLING_BACK,
    );
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'pending_color' => BlueGreenDeploymentColor::BLUE,
        'green_deployment_uuid' => $previous->deployment_uuid,
        'operation_previous_active_color' => BlueGreenDeploymentColor::GREEN,
        'operation_previous_deployment_uuid' => $previous->deployment_uuid,
        'operation_previous_routing_revision' => 6,
        'operation_previous_container_id' => $previousId,
        'operation_previous_managed_file_sha256' => $previousManagedSha,
        'operation_deployment_uuid' => $candidate->deployment_uuid,
        'operation_candidate_container_id' => $candidateId,
        'operation_routing_config_digest' => $fixture['routing'],
        'phase' => BlueGreenDeploymentPhase::ROLLING_BACK,
        'routing_revision' => 7,
        'managed_file_sha256' => $candidateManagedSha,
        'operation_routing_mutated_at' => now(),
        'destination_topology_digest' => $fixture['topology'],
        'application_routing_config_digest' => $fixture['routing'],
    ]);
    $deployments = collect([
        $previous->deployment_uuid => $previous,
        $candidate->deployment_uuid => $candidate,
    ]);
    $resolver = new ResolveActiveApplicationContainer;

    $beforeProxyRestoration = $resolver->resolveRoutedState($fixture['application'], $state, $deployments);
    $state->managed_file_sha256 = $previousManagedSha;
    $afterProxyRestoration = $resolver->resolveRoutedState($fixture['application'], $state, $deployments);

    expect($beforeProxyRestoration?->containerId)->toBe($candidateId)
        ->and($beforeProxyRestoration?->deploymentUuid)->toBe($candidate->deployment_uuid)
        ->and($afterProxyRestoration?->containerId)->toBe($previousId)
        ->and($afterProxyRestoration?->deploymentUuid)->toBe($previous->deployment_uuid);
});

it('observes the candidate immediately after proxy cutover while phase is still preparing', function () {
    $fixture = activeContainerResolverFixture('resolver-preparing-cutover');
    $previousId = str_repeat('8', 64);
    $candidateId = str_repeat('9', 64);
    $previous = activeContainerQueue(
        $fixture,
        'resolver-preparing-green',
        $previousId,
        BlueGreenDeploymentColor::GREEN,
        10,
    );
    $candidate = activeContainerQueue(
        $fixture,
        'resolver-preparing-blue',
        $candidateId,
        BlueGreenDeploymentColor::BLUE,
        11,
        BlueGreenDeploymentPhase::PREPARING,
    );
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'pending_color' => BlueGreenDeploymentColor::BLUE,
        'green_deployment_uuid' => $previous->deployment_uuid,
        'operation_previous_active_color' => BlueGreenDeploymentColor::GREEN,
        'operation_previous_deployment_uuid' => $previous->deployment_uuid,
        'operation_previous_routing_revision' => 10,
        'operation_previous_container_id' => $previousId,
        'operation_previous_managed_file_sha256' => hash('sha256', 'preparing-previous-route'),
        'operation_deployment_uuid' => $candidate->deployment_uuid,
        'operation_candidate_container_id' => $candidateId,
        'operation_routing_config_digest' => $fixture['routing'],
        'phase' => BlueGreenDeploymentPhase::PREPARING,
        'routing_revision' => 11,
        'managed_file_sha256' => hash('sha256', 'preparing-candidate-route'),
        'operation_routing_mutated_at' => now(),
        'destination_topology_digest' => $fixture['topology'],
        'application_routing_config_digest' => $fixture['routing'],
    ]);

    $resolution = (new ResolveActiveApplicationContainer)->resolveRoutedState(
        $fixture['application'],
        $state,
        collect([
            $previous->deployment_uuid => $previous,
            $candidate->deployment_uuid => $candidate,
        ]),
    );

    expect($resolution?->containerId)->toBe($candidateId)
        ->and($resolution?->deploymentUuid)->toBe($candidate->deployment_uuid);
});

it('fails closed while a preparing route mutation lacks durable public-cutover attestation', function () {
    $fixture = activeContainerResolverFixture('resolver-probe-only');
    $candidateId = str_repeat('a', 64);
    $candidate = activeContainerQueue(
        $fixture,
        'resolver-probe-only-blue',
        $candidateId,
        BlueGreenDeploymentColor::BLUE,
        12,
        BlueGreenDeploymentPhase::PREPARING,
    );
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'pending_color' => BlueGreenDeploymentColor::BLUE,
        'operation_deployment_uuid' => $candidate->deployment_uuid,
        'operation_candidate_container_id' => $candidateId,
        'operation_routing_config_digest' => $fixture['routing'],
        'phase' => BlueGreenDeploymentPhase::PREPARING,
        'routing_revision' => 12,
        'managed_file_sha256' => hash('sha256', 'private-probe-route'),
        'destination_topology_digest' => $fixture['topology'],
        'application_routing_config_digest' => $fixture['routing'],
    ]);

    $resolution = (new ResolveActiveApplicationContainer)->resolveRoutedState(
        $fixture['application'],
        $state,
        collect([$candidate->deployment_uuid => $candidate]),
    );

    expect($resolution)->toBeNull();
});

it('resolves a canonical v4 replica fence to every durable routed container', function () {
    $fixture = activeContainerResolverFixture('resolver-replica-set');
    $fixture['application']->settings->update(['blue_green_replica_count' => 2]);
    $firstId = str_repeat('6', 64);
    $secondId = str_repeat('7', 64);
    $inspections = [
        BlueGreenReplicaInspection::fromRuntime(1, 'app-blue-replica-1', 'app-blue-replica-1', $firstId, 'running', 'healthy'),
        BlueGreenReplicaInspection::fromRuntime(2, 'app-blue-replica-2', 'app-blue-replica-2', $secondId, 'running', 'healthy'),
    ];
    $backendPortInventory = BlueGreenBackendPortInventory::fromPorts([3000]);
    $identityDigest = ResolveBlueGreenActiveReplicaSet::run(
        $fixture['application']->blueGreenComposeTopology(),
        BlueGreenDeploymentColor::BLUE,
        new BlueGreenReplicaSet(2),
        $inspections,
        $backendPortInventory->ports(),
    )?->identityDigest() ?? throw new RuntimeException('The v4 replica fixture requires an aggregate proxy identity.');
    $queue = activeContainerQueue(
        $fixture,
        'resolver-replica-blue',
        $identityDigest,
        BlueGreenDeploymentColor::BLUE,
        8,
    );
    $queue = activeContainerPreparedReplicaCount($queue, 2);
    $queue->update(['blue_green_backend_port_inventory' => $backendPortInventory->serialized]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'resolver-replica-blue',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 8,
        'destination_topology_digest' => $fixture['topology'],
        'application_routing_config_digest' => $fixture['routing'],
    ]);
    foreach ($inspections as $inspection) {
        ApplicationBlueGreenReplica::query()->create([
            'application_blue_green_deployment_id' => $state->id,
            'application_id' => $fixture['application']->id,
            'standalone_docker_id' => $fixture['destination']->id,
            'color' => BlueGreenDeploymentColor::BLUE,
            'replica_index' => $inspection->replicaIndex,
            'deployment_uuid' => 'resolver-replica-blue',
            'routing_revision' => 8,
            'compose_project' => 'resolver-replica',
            'compose_service' => $inspection->composeService,
            'container_name' => $inspection->containerName,
            'container_id' => $inspection->dockerId,
            'health_status' => 'healthy',
        ]);
    }

    $resolution = ResolveActiveApplicationContainer::run(collect([$fixture['application']]))->first();
    $legacyDigest = BlueGreenReplicaSet::identityDigest($inspections);
    $queue->update(['blue_green_candidate_container_id' => $legacyDigest]);
    $legacyResolution = ResolveActiveApplicationContainer::run(collect([$fixture['application']]))->first();
    $state->replicas()->delete();
    $fixture['application']->settings->update(['blue_green_replica_count' => 1]);
    $missingLedgerResolution = ResolveActiveApplicationContainer::run(collect([$fixture['application']]))->first();

    expect($identityDigest)->not->toBe($legacyDigest)
        ->and($resolution->containerId)->toBe($identityDigest)
        ->and($resolution->containerIds)->toBe([$firstId, $secondId])
        ->and($resolution->matches($firstId, 'resolver-replica-blue'))->toBeTrue()
        ->and($resolution->matches($secondId, 'resolver-replica-blue'))->toBeTrue()
        ->and($resolution->matches($identityDigest, 'resolver-replica-blue'))->toBeFalse()
        ->and($legacyResolution->containerId)->toBe($legacyDigest)
        ->and($legacyResolution->containerIds)->toBe([$firstId, $secondId])
        ->and($missingLedgerResolution->observable)->toBeFalse()
        ->and($missingLedgerResolution->containerId)->toBeNull()
        ->and($missingLedgerResolution->containerIds)->toBe([]);
});

it('uses the routed candidate while draining', function () {
    $fixture = activeContainerResolverFixture('resolver-draining');
    $candidateId = str_repeat('c', 64);
    $actualRoutingDigest = hash('sha256', 'resolver-draining-actual-routing');
    activeContainerQueue(
        $fixture,
        'resolver-candidate-blue',
        $candidateId,
        BlueGreenDeploymentColor::BLUE,
        5,
        BlueGreenDeploymentPhase::DRAINING,
    );
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'resolver-candidate-blue',
        'operation_deployment_uuid' => 'resolver-candidate-blue',
        'operation_candidate_container_id' => $candidateId,
        'operation_routing_config_digest' => $fixture['routing'],
        'phase' => BlueGreenDeploymentPhase::DRAINING,
        'routing_revision' => 5,
        'destination_topology_digest' => $fixture['topology'],
        'application_routing_config_digest' => $actualRoutingDigest,
    ]);

    $resolution = ResolveActiveApplicationContainer::run(collect([$fixture['application']]))->first();

    expect($resolution->observable)->toBeTrue()
        ->and($resolution->containerId)->toBe($candidateId)
        ->and($resolution->deploymentUuid)->toBe('resolver-candidate-blue');
});

it('fails closed when a draining queue no longer owns the operation routing claim', function (): void {
    $fixture = activeContainerResolverFixture('resolver-draining-claim-mismatch');
    $candidateId = str_repeat('7', 64);
    $queue = activeContainerQueue(
        $fixture,
        'resolver-mismatched-candidate-blue',
        $candidateId,
        BlueGreenDeploymentColor::BLUE,
        6,
        BlueGreenDeploymentPhase::DRAINING,
    );
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'resolver-mismatched-candidate-blue',
        'operation_deployment_uuid' => 'resolver-mismatched-candidate-blue',
        'operation_candidate_container_id' => $candidateId,
        'operation_routing_config_digest' => hash('sha256', 'another-operation-claim'),
        'phase' => BlueGreenDeploymentPhase::DRAINING,
        'routing_revision' => 6,
        'destination_topology_digest' => $fixture['topology'],
        'application_routing_config_digest' => hash('sha256', 'resolver-mismatched-runtime-route'),
    ]);

    $resolution = ResolveActiveApplicationContainer::run(collect([$fixture['application']]))->first();
    $state->phase = BlueGreenDeploymentPhase::SWITCHING;
    $routedResolution = (new ResolveActiveApplicationContainer)->resolveRoutedState(
        $fixture['application'],
        $state,
        collect([$queue->deployment_uuid => $queue]),
    );

    expect($resolution->observable)->toBeFalse()
        ->and($resolution->preserveStatus)->toBeTrue()
        ->and($resolution->containerId)->toBeNull()
        ->and($routedResolution)->toBeNull();
});

it('fails closed for non-observable and inconsistent durable states', function (BlueGreenDeploymentPhase $phase) {
    $fixture = activeContainerResolverFixture('resolver-closed-'.$phase->value);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'phase' => $phase,
    ]);

    $resolution = ResolveActiveApplicationContainer::run(collect([$fixture['application']]))->first();

    expect($resolution->observable)->toBeFalse()
        ->and($resolution->matches(str_repeat('d', 64), 'stale-owner'))->toBeFalse();
})->with([
    BlueGreenDeploymentPhase::DEACTIVATING,
    BlueGreenDeploymentPhase::STOPPED,
    BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
    BlueGreenDeploymentPhase::IDLE,
]);

it('uses one durable-state query and one queue query as application count grows', function () {
    $fixtures = collect(range(1, 4))->map(function (int $index): array {
        $fixture = activeContainerResolverFixture('resolver-batch-'.$index);
        $uuid = 'resolver-batch-'.$index;
        $containerId = str_repeat(dechex($index), 64);
        activeContainerQueue($fixture, $uuid, $containerId, BlueGreenDeploymentColor::BLUE, $index);
        ApplicationBlueGreenDeployment::query()->create([
            'application_id' => $fixture['application']->id,
            'standalone_docker_id' => $fixture['destination']->id,
            'active_color' => BlueGreenDeploymentColor::BLUE,
            'blue_deployment_uuid' => $uuid,
            'phase' => BlueGreenDeploymentPhase::IDLE,
            'routing_revision' => $index,
            'destination_topology_digest' => $fixture['topology'],
            'application_routing_config_digest' => $fixture['routing'],
        ]);

        return $fixture;
    });
    $multiDestinationApplication = $fixtures->first()['application'];
    $additionalServer = Server::factory()->create(['team_id' => $fixtures->first()['server']->team_id]);
    $additionalDestination = $additionalServer->standaloneDockers()->firstOrFail();
    $multiDestinationApplication->additional_servers()->attach($additionalServer->id, [
        'standalone_docker_id' => $additionalDestination->id,
        'status' => 'exited',
    ]);
    $additionalTopology = hash('sha256', 'resolver-batch-additional-topology');
    $additionalRouting = hash('sha256', 'resolver-batch-additional-routing');
    $additionalContainerId = str_repeat('e', 64);
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $multiDestinationApplication->id,
        'deployment_uuid' => 'resolver-batch-additional',
        'pull_request_id' => 0,
        'server_id' => $additionalServer->id,
        'destination_id' => $additionalDestination->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 9,
        'blue_green_topology_digest' => $additionalTopology,
        'blue_green_routing_config_digest' => $additionalRouting,
        'blue_green_candidate_container_id' => $additionalContainerId,
    ]);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $multiDestinationApplication->id,
        'standalone_docker_id' => $additionalDestination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'green_deployment_uuid' => 'resolver-batch-additional',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 9,
        'destination_topology_digest' => $additionalTopology,
        'application_routing_config_digest' => $additionalRouting,
    ]);
    $queries = collect();
    DB::listen(function ($query) use ($queries): void {
        $queries->push($query->sql);
    });

    $resolutions = ResolveActiveApplicationContainer::run($fixtures->pluck('application'));

    expect($resolutions)->toHaveCount(5)
        ->and($resolutions->get(ActiveApplicationContainerResolution::key(
            (int) $multiDestinationApplication->id,
            (int) $additionalDestination->id,
        ))?->containerId)->toBe($additionalContainerId)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'application_blue_green_deployments')))->toHaveCount(1)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'application_deployment_queues')))->toHaveCount(1);
});
