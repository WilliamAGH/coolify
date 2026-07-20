<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerRemovalPlan;
use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\InspectBlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\RemoveBlueGreenApplicationContainers;
use App\Enums\BlueGreenDeploymentColor;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
});

it('keeps the one-replica service identity byte compatible', function (): void {
    $replicas = new BlueGreenReplicaSet(1);

    expect($replicas->usesScalarCompatibilityPath())->toBeTrue()
        ->and($replicas->promotionThreshold())->toBe(1)
        ->and($replicas->indexes())->toBe([1])
        ->and($replicas->serviceNames('application-blue'))->toBe(['application-blue'])
        ->and($replicas->labels(1))->toBe([]);
});

it('defines an all-healthy promotion threshold and exact replica services', function (): void {
    $replicas = new BlueGreenReplicaSet(3);

    expect($replicas->usesScalarCompatibilityPath())->toBeFalse()
        ->and($replicas->promotionThreshold())->toBe(3)
        ->and($replicas->indexes())->toBe([1, 2, 3])
        ->and($replicas->serviceNames('application-green'))->toBe([
            'application-green-replica-1',
            'application-green-replica-2',
            'application-green-replica-3',
        ])
        ->and($replicas->labels(2))->toBe([
            'coolify.blueGreen.replicaIndex=2',
            'coolify.blueGreen.replicaCount=3',
        ]);

    expect(fn () => new BlueGreenReplicaSet(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new BlueGreenReplicaSet(33))->toThrow(InvalidArgumentException::class);
});

it('refuses promotion unless every configured replica is running and healthy', function (): void {
    $replicas = new BlueGreenReplicaSet(3);
    $inspections = array_map(
        static fn (int $index): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $index,
            composeService: "application-blue-replica-{$index}",
            containerName: "application-blue-replica-{$index}-1",
            dockerId: str_repeat((string) $index, 64),
            status: 'running',
            health: $index === 2 ? 'unhealthy' : 'healthy',
        ),
        [1, 2, 3],
    );

    expect(fn () => $replicas->assertPromotionThreshold($inspections))
        ->toThrow(InvalidArgumentException::class, 'Every configured blue-green replica');

    $healthy = array_map(
        static fn (BlueGreenReplicaInspection $inspection): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $inspection->replicaIndex,
            composeService: $inspection->composeService,
            containerName: $inspection->containerName,
            dockerId: $inspection->dockerId,
            status: 'running',
            health: 'healthy',
        ),
        $inspections,
    );
    $replicas->assertPromotionThreshold($healthy);

    expect(BlueGreenReplicaSet::identityDigest($healthy))->toMatch('/^[a-f0-9]{64}$/');
});

it('parses exactly one provenance-matched Docker identity per replica slot', function (): void {
    $replicas = collect(range(1, 3))->map(function (int $index): ApplicationBlueGreenReplica {
        $replica = new ApplicationBlueGreenReplica;
        $replica->forceFill([
            'application_id' => 41,
            'standalone_docker_id' => 9,
            'color' => BlueGreenDeploymentColor::BLUE,
            'replica_index' => $index,
            'deployment_uuid' => 'replica-inspection-release',
            'routing_revision' => 12,
            'compose_project' => 'application-project',
            'compose_service' => "application-blue-replica-{$index}",
        ]);

        return $replica;
    });
    $output = $replicas->map(function (ApplicationBlueGreenReplica $replica): string {
        return json_encode([
            'Id' => str_repeat((string) $replica->replica_index, 64),
            'Name' => '/'.$replica->compose_service.'-1',
            'State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']],
            'Config' => ['Labels' => [
                'coolify.applicationId' => '41',
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => 'replica-inspection-release',
                'coolify.blueGreen.color' => 'blue',
                'coolify.blueGreen.routingRevision' => '12',
                'coolify.blueGreen.replicaIndex' => (string) $replica->replica_index,
                'coolify.blueGreen.replicaCount' => '3',
                'com.docker.compose.project' => 'application-project',
                'com.docker.compose.service' => $replica->compose_service,
            ]],
        ], JSON_THROW_ON_ERROR);
    })->implode("\n");
    $inspector = new InspectBlueGreenReplicaSet;
    $inspections = $inspector->parse($output, $replicas, 3);

    expect($inspections)->toHaveCount(3)
        ->and(array_column($inspections, 'replicaIndex'))->toBe([1, 2, 3])
        ->and(array_column($inspections, 'containerName'))->toBe([
            'application-blue-replica-1-1',
            'application-blue-replica-2-1',
            'application-blue-replica-3-1',
        ])
        ->and($inspector->commandFor($replicas, 3))->toContain(
            'label=coolify.blueGreen.replicaIndex=1',
            'label=com.docker.compose.service=application-blue-replica-3',
        );

    expect(fn () => $inspector->parse(str_replace(
        '"coolify.blueGreen.replicaCount":"3"',
        '"coolify.blueGreen.replicaCount":"2"',
        $output,
    ), $replicas, 3))->toThrow(RuntimeException::class, 'replicaCount');
});

it('deactivation removes each replica only through its immutable identity and provenance', function (): void {
    $plan = new BlueGreenContainerRemovalPlan(
        applicationId: 41,
        blueContainerName: 'application-blue',
        blueRoutingRevision: 12,
        greenContainerName: 'application-green',
        greenRoutingRevision: 11,
        legacyContainerName: null,
        stopGracePeriodSeconds: 30,
        replicaContainers: [[
            'name' => 'application-blue-replica-1-1',
            'id' => str_repeat('a', 64),
            'color' => BlueGreenDeploymentColor::BLUE,
            'routingRevision' => 12,
            'deploymentUuid' => 'replica-deactivation-release',
            'index' => 1,
        ]],
    );
    $remover = new RemoveBlueGreenApplicationContainers;
    $command = $remover->commandFor($plan);

    expect($command)->toContain(
        'application-blue-replica-1-1',
        'replica-deactivation-release blue 12 1',
        str_repeat('a', 64),
    )->and($remover->assertAbsentCommandFor($plan))->toContain('application-blue-replica-1-1');
});

it('persists immutable destination color index and release identity', function (): void {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
    ]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'replica-ledger-release',
        'pull_request_id' => 0,
        'commit' => 'replica-ledger-release',
    ]);
    $replica = ApplicationBlueGreenReplica::query()->create([
        'application_blue_green_deployment_id' => $state->id,
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'color' => BlueGreenDeploymentColor::BLUE,
        'replica_index' => 1,
        'deployment_uuid' => $deployment->deployment_uuid,
        'routing_revision' => 7,
        'compose_project' => $application->uuid,
        'compose_service' => $application->uuid.'-blue-replica-1',
    ]);

    $replica->update([
        'container_name' => 'application-blue-replica-1-1',
        'container_id' => str_repeat('a', 64),
        'health_status' => 'healthy',
        'last_observed_at' => now(),
    ]);

    expect($replica->fresh()->health_status)->toBe('healthy')
        ->and($state->replicas()->sole()->deployment_uuid)->toBe('replica-ledger-release')
        ->and($application->blueGreenReplicas()->sole()->replica_index)->toBe(1);

    expect(fn () => $replica->update(['replica_index' => 2]))
        ->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $replica->update(['container_id' => str_repeat('b', 64)]))
        ->toThrow(RuntimeException::class, 'immutable');
});

it('preserves color slot history while enforcing one durable row per release index', function (): void {
    expect(Schema::hasColumn('application_settings', 'blue_green_replica_count'))->toBeTrue()
        ->and(Schema::hasTable('application_blue_green_replicas'))->toBeTrue();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
    ]);
    $attributes = [
        'application_blue_green_deployment_id' => $state->id,
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'color' => 'green',
        'replica_index' => 1,
        'deployment_uuid' => 'unique-replica-release',
        'routing_revision' => 1,
        'compose_project' => $application->uuid,
        'compose_service' => $application->uuid.'-green-replica-1',
    ];
    ApplicationBlueGreenReplica::query()->create($attributes);

    ApplicationBlueGreenReplica::query()->create([
        ...$attributes,
        'deployment_uuid' => 'another-release',
    ]);

    expect(fn () => ApplicationBlueGreenReplica::query()->create($attributes))
        ->toThrow(QueryException::class)
        ->and(ApplicationBlueGreenReplica::query()->count())->toBe(2);
});
