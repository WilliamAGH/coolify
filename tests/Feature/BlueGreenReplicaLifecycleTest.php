<?php

use App\Actions\Application\BlueGreen\BindBlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerRemovalPlan;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\InspectBlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\ReconstructBlueGreenDeploymentRecovery;
use App\Actions\Application\BlueGreen\RemoveBlueGreenApplicationContainers;
use App\Actions\Application\BlueGreen\RemoveBlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\ReserveBlueGreenReplicaSet;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Exceptions\DeploymentException;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
});

/**
 * @return array{
 *     application: Application,
 *     claim: BlueGreenDeploymentClaim,
 *     state: ApplicationBlueGreenDeployment
 * }
 */
function exactBlueGreenReplicaBindingFixture(int $replicaCount = 3): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $deploymentUuid = 'replica-binding-release';
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'pending_color' => BlueGreenDeploymentColor::BLUE,
        'pending_deployment_uuid' => $deploymentUuid,
        'operation_deployment_uuid' => $deploymentUuid,
        'operation_candidate_container_name' => $application->uuid.'-blue',
        'operation_rollback_managed_filename' => 'replica-binding-rollback.yaml',
        'operation_destination_fence_epoch' => 1,
        'operation_server_boot_id' => '11111111-1111-1111-1111-111111111111',
        'operation_topology_digest' => hash('sha256', 'replica-binding-topology'),
        'operation_routing_config_digest' => hash('sha256', 'replica-binding-routing'),
        'supersession_generation' => 1,
        'phase' => BlueGreenDeploymentPhase::PREPARING,
        'routing_revision' => 1,
    ]);
    $claim = new BlueGreenDeploymentClaim(
        stateId: $state->id,
        applicationId: $application->id,
        standaloneDockerId: $destination->id,
        pendingColor: BlueGreenDeploymentColor::BLUE,
        previousActiveColor: null,
        deploymentUuid: $deploymentUuid,
        expectedRoutingRevision: 1,
        destinationFenceEpoch: 1,
        serverBootId: '11111111-1111-1111-1111-111111111111',
        topologyDigest: hash('sha256', 'replica-binding-topology'),
        routingConfigDigest: hash('sha256', 'replica-binding-routing'),
        backendPortInventory: BlueGreenBackendPortInventory::fromPorts([3000]),
        drainBackendPortInventory: null,
        supersessionGeneration: 1,
        legacyContainerName: null,
        replicaCount: $replicaCount,
        candidateContainerName: $application->uuid.'-blue',
        rollbackManagedFilename: 'replica-binding-rollback.yaml',
    );
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'destination_id' => $destination->id,
        'server_id' => $server->id,
        'deployment_uuid' => $claim->deploymentUuid,
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'blue_green_color' => $claim->pendingColor,
        'blue_green_phase' => BlueGreenDeploymentPhase::PREPARING,
        'blue_green_routing_revision' => $claim->expectedRoutingRevision,
        'blue_green_destination_fence_epoch' => $claim->destinationFenceEpoch,
        'blue_green_server_boot_id' => $claim->serverBootId,
        'blue_green_topology_digest' => $claim->topologyDigest,
        'blue_green_routing_config_digest' => $claim->routingConfigDigest,
        'blue_green_backend_port_inventory' => $claim->backendPortInventory->serialized,
        'blue_green_drain_backend_port_inventory' => null,
        'blue_green_supersession_generation' => $claim->supersessionGeneration,
    ]);
    (new ReserveBlueGreenReplicaSet)->handle(
        application: $application,
        state: $state,
        color: $claim->pendingColor,
        deploymentUuid: $claim->deploymentUuid,
        routingRevision: $claim->expectedRoutingRevision,
        members: [[
            'composeServiceBase' => $application->uuid.'-blue',
            'containerName' => $claim->candidateContainerName,
        ]],
        replicaCount: $claim->replicaCount,
    );

    return compact('application', 'claim', 'state');
}

/** @return list<BlueGreenReplicaInspection> */
function exactBlueGreenReplicaInspections(
    Application $application,
    int $replicaCount,
): array {
    return array_map(
        static fn (int $index): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $index,
            composeService: $application->uuid."-blue-replica-{$index}",
            containerName: $application->uuid."-blue-replica-{$index}-1",
            dockerId: str_repeat((string) $index, 64),
            status: 'running',
            health: 'healthy',
        ),
        range(1, $replicaCount),
    );
}

/** @param array{commands: non-empty-list<string>, completionAssertions: non-empty-list<string>} $plan */
function runReplicaRemovalPlanAgainstFakeDocker(
    array $plan,
    string $stateFile,
    string $binaryDirectory,
): SymfonyProcess {
    $script = implode("\n", [
        'set -eu',
        ...$plan['commands'],
        ...$plan['completionAssertions'],
    ]);
    $process = SymfonyProcess::fromShellCommandline('bash -c '.escapeshellarg($script), null, [
        'COOLIFY_FAKE_DOCKER_STATE' => $stateFile,
        'PATH' => $binaryDirectory.':'.getenv('PATH'),
    ]);
    $process->run();

    return $process;
}

function writeReplicaRemovalFakeDocker(string $binaryDirectory): void
{
    mkdir($binaryDirectory, 0700, true);
    $dockerPath = $binaryDirectory.'/docker';
    file_put_contents($dockerPath, <<<'SH'
#!/bin/sh
set -eu
state=''
find_container() {
    identifier=$1
    while IFS='|' read -r container_id container_name application_id deployment_uuid color routing_revision replica_index replica_count compose_project compose_service; do
        test -n "$container_id" || continue
        if test "$identifier" = "$container_id" || test "$identifier" = "$container_name"; then
            printf '%s|%s|%s|%s|%s|%s|%s|%s|%s|%s\n' "$container_id" "$container_name" "$application_id" "$deployment_uuid" "$color" "$routing_revision" "$replica_index" "$replica_count" "$compose_project" "$compose_service"
            return 0
        fi
    done < "$COOLIFY_FAKE_DOCKER_STATE"

    return 1
}
case "$1" in
    container)
        test "$2" = inspect
        find_container "$3" >/dev/null
        ;;
    inspect)
        format=${2#--format=}
        identifier=$3
        state=$(find_container "$identifier")
        IFS='|' read -r container_id container_name application_id deployment_uuid color routing_revision replica_index replica_count compose_project compose_service <<EOF
$state
EOF
        case "$format" in
            '{{.Id}}') printf '%s\n' "$container_id" ;;
            '{{.Name}}') printf '/%s\n' "$container_name" ;;
            *coolify.applicationId*) printf '%s\n' "$application_id" ;;
            *coolify.pullRequestId*) printf '0\n' ;;
            *coolify.blueGreen.managed*) printf 'true\n' ;;
            *coolify.blueGreen.deploymentUuid*) printf '%s\n' "$deployment_uuid" ;;
            *coolify.blueGreen.color*) printf '%s\n' "$color" ;;
            *coolify.blueGreen.routingRevision*) printf '%s\n' "$routing_revision" ;;
            *coolify.blueGreen.replicaIndex*) printf '%s\n' "$replica_index" ;;
            *coolify.blueGreen.replicaCount*) printf '%s\n' "$replica_count" ;;
            *com.docker.compose.project*) printf '%s\n' "$compose_project" ;;
            *com.docker.compose.service*) printf '%s\n' "$compose_service" ;;
            *) exit 94 ;;
        esac
        ;;
    ps)
        no_trunc=false
        for argument in "$@"; do
            if test "$argument" = --no-trunc; then
                no_trunc=true
                break
            fi
        done
        test "$no_trunc" = true
        shift
        while IFS='|' read -r container_id container_name application_id deployment_uuid color routing_revision replica_index replica_count compose_project compose_service; do
            test -n "$container_id" || continue
            matches=true
            for argument in "$@"; do
                case "$argument" in
                    label=coolify.applicationId=*) test "$argument" = "label=coolify.applicationId=$application_id" || matches=false ;;
                    label=coolify.pullRequestId=*) test "$argument" = 'label=coolify.pullRequestId=0' || matches=false ;;
                    label=coolify.blueGreen.managed=*) test "$argument" = 'label=coolify.blueGreen.managed=true' || matches=false ;;
                    label=coolify.blueGreen.deploymentUuid=*) test "$argument" = "label=coolify.blueGreen.deploymentUuid=$deployment_uuid" || matches=false ;;
                    label=coolify.blueGreen.color=*) test "$argument" = "label=coolify.blueGreen.color=$color" || matches=false ;;
                    label=coolify.blueGreen.routingRevision=*) test "$argument" = "label=coolify.blueGreen.routingRevision=$routing_revision" || matches=false ;;
                    label=coolify.blueGreen.replicaIndex=*) test "$argument" = "label=coolify.blueGreen.replicaIndex=$replica_index" || matches=false ;;
                    label=coolify.blueGreen.replicaCount=*) test "$argument" = "label=coolify.blueGreen.replicaCount=$replica_count" || matches=false ;;
                    label=com.docker.compose.project=*) test "$argument" = "label=com.docker.compose.project=$compose_project" || matches=false ;;
                    label=com.docker.compose.service=*) test "$argument" = "label=com.docker.compose.service=$compose_service" || matches=false ;;
                esac
            done
            test "$matches" = true && printf '%s\n' "$container_id"
        done < "$COOLIFY_FAKE_DOCKER_STATE"
        ;;
    rm)
        test "$2" = -f
        removal_id=$3
        find_container "$removal_id" >/dev/null
        temporary_state="$COOLIFY_FAKE_DOCKER_STATE.tmp.$$"
        : > "$temporary_state"
        while IFS= read -r container; do
            test "${container%%|*}" = "$removal_id" || printf '%s\n' "$container" >> "$temporary_state"
        done < "$COOLIFY_FAKE_DOCKER_STATE"
        mv "$temporary_state" "$COOLIFY_FAKE_DOCKER_STATE"
        ;;
    *) exit 95 ;;
esac
SH);
    chmod($dockerPath, 0700);
}

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

it('rejects incomplete or duplicate durable replica ledger indexes', function (array $indexes): void {
    $replicas = collect($indexes)->map(static function (int $index): ApplicationBlueGreenReplica {
        $replica = new ApplicationBlueGreenReplica;
        $replica->forceFill(['replica_index' => $index]);

        return $replica;
    });

    expect(fn () => BlueGreenReplicaSet::fromReplicas($replicas))
        ->toThrow(InvalidArgumentException::class, 'every contiguous replica index exactly once');
})->with([
    'missing initial index' => [[2, 3, 4]],
    'duplicate index' => [[1, 1, 3]],
    'non-contiguous index' => [[1, 2, 4]],
]);

it('groups the durable replica ledger by co-rolled member rather than by index alone', function (): void {
    // Two co-rolled services at one replica each: the flat ledger reads [1, 1],
    // which the single-member reading rejects outright.
    $rows = collect([
        ['compose_service' => 'llm_gateway-blue', 'replica_index' => 1],
        ['compose_service' => 'queue-blue', 'replica_index' => 1],
    ])->map(static function (array $attributes): ApplicationBlueGreenReplica {
        $replica = new ApplicationBlueGreenReplica;
        $replica->forceFill($attributes);

        return $replica;
    });

    expect(fn () => BlueGreenReplicaSet::fromReplicas($rows))
        ->toThrow(InvalidArgumentException::class, 'every contiguous replica index exactly once');

    $replicaSet = BlueGreenReplicaSet::fromReplicas($rows, ['llm_gateway-blue', 'queue-blue']);

    expect($replicaSet->count)->toBe(1)
        ->and($replicaSet->members)->toBe(['llm_gateway-blue', 'queue-blue'])
        ->and($replicaSet->promotionThreshold())->toBe(2)
        // One replica per member is still two containers, so the scalar
        // candidate identity can no longer stand in for the color.
        ->and($replicaSet->usesScalarCompatibilityPath())->toBeFalse();
});

it('inspects every co-rolled member through its own durable Compose identity', function (): void {
    $rows = collect([
        ['compose_service' => 'llm_gateway-blue', 'replica_index' => 1],
        ['compose_service' => 'queue-blue', 'replica_index' => 1],
    ])->map(static function (array $attributes): ApplicationBlueGreenReplica {
        $replica = new ApplicationBlueGreenReplica;
        $replica->forceFill([
            ...$attributes,
            'application_id' => 41,
            'color' => BlueGreenDeploymentColor::BLUE,
            'deployment_uuid' => 'co-rolled-release',
            'routing_revision' => 12,
            'compose_project' => 'application-project',
        ]);

        return $replica;
    });
    $replicaSet = BlueGreenReplicaSet::fromReplicas($rows, ['llm_gateway-blue', 'queue-blue']);
    $inspector = new InspectBlueGreenReplicaSet;

    $command = $inspector->commandFor($rows, $replicaSet);

    // One replica per member emits no replica fan-out labels at all, so a
    // filter naming a replicaCount would match nothing that was ever started.
    expect($command)->toContain(
        'label=com.docker.compose.service=llm_gateway-blue',
        'label=com.docker.compose.service=queue-blue',
    )
        ->and($command)->not->toContain('coolify.blueGreen.replicaCount')
        ->and($command)->not->toContain('coolify.blueGreen.replicaIndex');

    // Both members sit at replica index 1, so a shell variable keyed by that
    // index would have the second member overwrite the first.
    expect(substr_count($command, 'coolify_replica_'))->toBeGreaterThanOrEqual(4)
        ->and(preg_match_all('/(coolify_replica_[A-Za-z0-9_]+)=\$\(/', $command, $assigned))->toBe(2)
        ->and(array_unique($assigned[1]))->toHaveCount(2);

    $output = $rows->map(static function (ApplicationBlueGreenReplica $replica): string {
        return json_encode([
            'Id' => hash('sha256', $replica->compose_service),
            'Name' => '/'.$replica->compose_service,
            'State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']],
            'Config' => ['Labels' => [
                'coolify.applicationId' => '41',
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => 'co-rolled-release',
                'coolify.blueGreen.color' => 'blue',
                'coolify.blueGreen.routingRevision' => '12',
                'com.docker.compose.project' => 'application-project',
                'com.docker.compose.service' => $replica->compose_service,
            ]],
        ], JSON_THROW_ON_ERROR);
    })->implode("\n");

    $inspections = $inspector->parse($output, $rows, $replicaSet);

    expect($inspections)->toHaveCount(2)
        ->and(array_column($inspections, 'composeService'))->toBe(['llm_gateway-blue', 'queue-blue'])
        ->and(array_column($inspections, 'containerName'))->toBe(['llm_gateway-blue', 'queue-blue']);

    // Both members are reported, so the available parse must key by the member
    // rather than by the replica index the two of them share.
    $availableOutput = $rows->map(static function (ApplicationBlueGreenReplica $replica) use ($rows): string {
        $offset = $rows->values()->search(
            static fn (ApplicationBlueGreenReplica $row): bool => $row->compose_service === $replica->compose_service,
        );

        return $offset."\t".json_encode([
            'Id' => hash('sha256', $replica->compose_service),
            'Name' => '/'.$replica->compose_service,
            'State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']],
            'Config' => ['Labels' => [
                'coolify.applicationId' => '41',
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => 'co-rolled-release',
                'coolify.blueGreen.color' => 'blue',
                'coolify.blueGreen.routingRevision' => '12',
                'com.docker.compose.project' => 'application-project',
                'com.docker.compose.service' => $replica->compose_service,
            ]],
        ], JSON_THROW_ON_ERROR);
    })->implode("\n");

    expect($inspector->parseAvailable($availableOutput, $rows, $replicaSet))->toHaveCount(2);
});

it('proves replica contiguity inside every co-rolled member group', function (): void {
    $rows = collect([
        ['compose_service' => 'llm_gateway-blue-replica-1', 'replica_index' => 1],
        ['compose_service' => 'llm_gateway-blue-replica-2', 'replica_index' => 2],
        ['compose_service' => 'queue-blue-replica-1', 'replica_index' => 1],
        ['compose_service' => 'queue-blue-replica-2', 'replica_index' => 2],
    ])->map(static function (array $attributes): ApplicationBlueGreenReplica {
        $replica = new ApplicationBlueGreenReplica;
        $replica->forceFill($attributes);

        return $replica;
    });
    $members = ['llm_gateway-blue', 'queue-blue'];

    expect(BlueGreenReplicaSet::fromReplicas($rows, $members)->count)->toBe(2)
        ->and(BlueGreenReplicaSet::fromReplicas($rows, $members)->promotionThreshold())->toBe(4);

    // One member complete, the other doubled: the total still divides evenly, so
    // only per-group contiguity catches it.
    $skewed = collect([
        ['compose_service' => 'llm_gateway-blue-replica-1', 'replica_index' => 1],
        ['compose_service' => 'llm_gateway-blue-replica-2', 'replica_index' => 2],
        ['compose_service' => 'llm_gateway-blue-replica-1', 'replica_index' => 1],
        ['compose_service' => 'llm_gateway-blue-replica-2', 'replica_index' => 2],
    ])->map(static function (array $attributes): ApplicationBlueGreenReplica {
        $replica = new ApplicationBlueGreenReplica;
        $replica->forceFill($attributes);

        return $replica;
    });

    expect(fn () => BlueGreenReplicaSet::fromReplicas($skewed, $members))
        ->toThrow(InvalidArgumentException::class, 'for every co-rolled member');
});

it('refuses promotion when any co-rolled member is unhealthy', function (): void {
    $replicaSet = new BlueGreenReplicaSet(1, ['llm_gateway-blue', 'queue-blue']);
    $healthy = static fn (string $composeService, string $health): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
        replicaIndex: 1,
        composeService: $composeService,
        containerName: $composeService,
        dockerId: str_repeat('a', 64),
        status: 'running',
        health: $health,
    );

    $replicaSet->assertPromotionThreshold([
        $healthy('llm_gateway-blue', 'healthy'),
        $healthy('queue-blue', 'healthy'),
    ]);

    expect(fn () => $replicaSet->assertPromotionThreshold([
        $healthy('llm_gateway-blue', 'healthy'),
        $healthy('queue-blue', 'starting'),
    ]))->toThrow(InvalidArgumentException::class, 'running, and healthy before promotion')
        // A color that only brought one member up must never satisfy the
        // threshold by presenting that member twice.
        ->and(fn () => $replicaSet->assertPromotionThreshold([
            $healthy('llm_gateway-blue', 'healthy'),
            $healthy('llm_gateway-blue', 'healthy'),
        ]))->toThrow(InvalidArgumentException::class, 'running, and healthy before promotion');
});

it('keeps the single-member replica ledger reading byte-identical', function (): void {
    $rows = collect([1, 2, 3])->map(static function (int $index): ApplicationBlueGreenReplica {
        $replica = new ApplicationBlueGreenReplica;
        $replica->forceFill([
            'compose_service' => "application-blue-replica-{$index}",
            'replica_index' => $index,
        ]);

        return $replica;
    });

    $historic = BlueGreenReplicaSet::fromReplicas($rows);
    $explicit = BlueGreenReplicaSet::fromReplicas($rows, ['application-blue']);

    expect($historic->count)->toBe(3)
        ->and($historic->members)->toBe([])
        ->and($historic->promotionThreshold())->toBe(3)
        ->and($historic->usesScalarCompatibilityPath())->toBeFalse()
        ->and($explicit->count)->toBe($historic->count)
        ->and($explicit->promotionThreshold())->toBe($historic->promotionThreshold())
        ->and($explicit->usesScalarCompatibilityPath())->toBe($historic->usesScalarCompatibilityPath());
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

it('binds the durable N=3 identities before refusing partial health after a restart and settings drift', function (): void {
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'ports_exposes' => '3000',
        'fqdn' => 'https://replica-drift.example.test',
        'health_check_enabled' => true,
        'health_check_retries' => 1,
        'health_check_start_period' => 0,
    ]);
    $application->settings()->firstOrFail()->update([
        'is_blue_green_deployment_enabled' => true,
        'blue_green_replica_count' => 3,
    ]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'durable-replica-quorum',
        'pull_request_id' => 0,
        'commit' => 'durable-replica-quorum',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'only_this_server' => true,
    ]);
    $bootId = '11111111-1111-1111-1111-111111111111';
    $claim = ClaimBlueGreenDeployment::run($application, $destination, $deployment, $bootId);
    $claimedRows = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $claim->stateId)
        ->orderBy('replica_index')
        ->get();
    DB::table('application_settings')
        ->where('application_id', $application->id)
        ->update(['blue_green_replica_count' => 1]);
    $application = $application->fresh(['settings']);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment->fresh(),
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    $lock = Cache::lock(BlueGreenDeploymentLock::key($application->id, $destination->id), 300);
    expect($lock->get())->toBeTrue();
    (new ReflectionProperty($lifecycle, 'enabled'))->setValue($lifecycle, true);
    (new ReflectionProperty($lifecycle, 'claim'))->setValue($lifecycle, $claim);
    (new ReflectionProperty($lifecycle, 'operationFence'))->setValue(
        $lifecycle,
        new BlueGreenOperationFence($lock, 300),
    );
    (new ReflectionProperty($lifecycle, 'candidateContainerExpectation'))->setValue(
        $lifecycle,
        new BlueGreenContainerExpectation(
            name: $claim->candidateContainerName ?? $application->uuid.'-'.$claim->pendingColor->value,
            dockerId: null,
            applicationId: $application->id,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $claim->deploymentUuid,
            color: $claim->pendingColor,
            routingRevision: $claim->expectedRoutingRevision,
        ),
    );
    $inspections = $claimedRows->map(
        static fn (ApplicationBlueGreenReplica $replica): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $replica->replica_index,
            composeService: $replica->compose_service,
            containerName: $replica->compose_service.'-1',
            dockerId: str_repeat((string) $replica->replica_index, 64),
            status: 'running',
            health: $replica->replica_index === 2 ? 'unhealthy' : 'healthy',
        ),
    )->all();
    $inspectionOutput = $claimedRows->map(static function (ApplicationBlueGreenReplica $replica): string {
        $inspection = json_encode([
            'Id' => str_repeat((string) $replica->replica_index, 64),
            'Name' => '/'.$replica->compose_service.'-1',
            'State' => [
                'Status' => 'running',
                'Health' => ['Status' => $replica->replica_index === 2 ? 'unhealthy' : 'healthy'],
            ],
            'Config' => ['Labels' => [
                'coolify.applicationId' => (string) $replica->application_id,
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => $replica->deployment_uuid,
                'coolify.blueGreen.color' => $replica->color->value,
                'coolify.blueGreen.routingRevision' => (string) $replica->routing_revision,
                'coolify.blueGreen.replicaIndex' => (string) $replica->replica_index,
                'coolify.blueGreen.replicaCount' => '3',
                'com.docker.compose.project' => $replica->compose_project,
                'com.docker.compose.service' => $replica->compose_service,
            ]],
        ], JSON_THROW_ON_ERROR);

        return $replica->replica_index."\t".$inspection;
    })->implode("\n");
    Process::fake(function ($process) use ($bootId, $inspectionOutput) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($command, '/proc/sys/kernel/random/boot_id')
            ? Process::result(output: $bootId)
            : Process::result(output: $inspectionOutput);
    });

    try {
        expect(fn () => (new ReflectionMethod($lifecycle, 'waitForExactCandidateHealth'))->invoke($lifecycle))
            ->toThrow(DeploymentException::class, 'only 2 of 3 healthy');
    } finally {
        $lock->release();
    }
    $boundRows = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $claim->stateId)
        ->orderBy('replica_index')
        ->get();
    [$rollbackCommands, $rollbackCompletionAssertions] = (new RemoveBlueGreenReplicaSet)->commandsFor(
        $claim,
        $boundRows,
    );
    $rollbackPlan = implode("\n", [...$rollbackCommands, ...$rollbackCompletionAssertions]);

    expect($application->settings->blueGreenReplicaCount())->toBe(1)
        ->and($boundRows)->toHaveCount(3)
        ->and($boundRows->pluck('container_id')->all())->toBe([
            str_repeat('1', 64),
            str_repeat('2', 64),
            str_repeat('3', 64),
        ])
        ->and($boundRows->pluck('health_status')->all())->toBe(['healthy', 'unhealthy', 'healthy'])
        ->and($rollbackPlan)->toContain(
            'docker ps -aq --no-trunc',
            'label=coolify.blueGreen.replicaIndex=1',
            'label=coolify.blueGreen.replicaIndex=2',
            'label=coolify.blueGreen.replicaIndex=3',
        )
        ->and(collect($rollbackCommands)->filter(
            static fn (string $command): bool => str_starts_with($command, 'docker rm -f '),
        ))->toHaveCount(3)
        ->and($deployment->fresh()->blue_green_candidate_container_id)->toBeNull();
});

it('keeps the claimed scalar health and rollback identity when settings drift from one replica to three', function (): void {
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'ports_exposes' => '3000',
        'fqdn' => 'https://scalar-drift.example.test',
        'health_check_enabled' => true,
        'health_check_retries' => 1,
        'health_check_start_period' => 0,
    ]);
    $application->settings()->firstOrFail()->update([
        'is_blue_green_deployment_enabled' => true,
        'blue_green_replica_count' => 1,
    ]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'durable-scalar-quorum',
        'pull_request_id' => 0,
        'commit' => 'durable-scalar-quorum',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'only_this_server' => true,
    ]);
    $bootId = '22222222-2222-2222-2222-222222222222';
    $claim = ClaimBlueGreenDeployment::run($application, $destination, $deployment, $bootId);
    DB::table('application_settings')
        ->where('application_id', $application->id)
        ->update(['blue_green_replica_count' => 3]);
    $application = $application->fresh(['settings']);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment->fresh(),
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    $lock = Cache::lock(BlueGreenDeploymentLock::key($application->id, $destination->id), 300);
    expect($lock->get())->toBeTrue();
    $expectation = new BlueGreenContainerExpectation(
        name: $claim->candidateContainerName ?? $application->uuid.'-'.$claim->pendingColor->value,
        dockerId: null,
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: $claim->deploymentUuid,
        color: $claim->pendingColor,
        routingRevision: $claim->expectedRoutingRevision,
    );
    (new ReflectionProperty($lifecycle, 'enabled'))->setValue($lifecycle, true);
    (new ReflectionProperty($lifecycle, 'claim'))->setValue($lifecycle, $claim);
    (new ReflectionProperty($lifecycle, 'operationFence'))->setValue(
        $lifecycle,
        new BlueGreenOperationFence($lock, 300),
    );
    (new ReflectionProperty($lifecycle, 'candidateContainerExpectation'))->setValue($lifecycle, $expectation);
    $containerId = str_repeat('a', 64);
    $inspectionOutput = json_encode([
        'Id' => $containerId,
        'Name' => '/'.$expectation->name,
        'State' => ['Status' => 'running', 'Health' => ['Status' => 'unhealthy']],
        'Config' => ['Labels' => [
            'coolify.applicationId' => (string) $application->id,
            'coolify.pullRequestId' => '0',
            'coolify.blueGreen.managed' => 'true',
            'coolify.blueGreen.deploymentUuid' => $claim->deploymentUuid,
            'coolify.blueGreen.color' => $claim->pendingColor->value,
            'coolify.blueGreen.routingRevision' => (string) $claim->expectedRoutingRevision,
        ]],
    ], JSON_THROW_ON_ERROR);
    Process::fake(function ($process) use ($bootId, $inspectionOutput) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($command, '/proc/sys/kernel/random/boot_id')
            ? Process::result(output: $bootId)
            : Process::result(output: $inspectionOutput);
    });

    try {
        expect(fn () => (new ReflectionMethod($lifecycle, 'waitForExactCandidateHealth'))->invoke($lifecycle))
            ->toThrow(DeploymentException::class, 'running/unhealthy');
    } finally {
        $lock->release();
    }
    $boundExpectation = (new ReflectionProperty($lifecycle, 'candidateContainerExpectation'))->getValue($lifecycle);
    $rows = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $claim->stateId)
        ->get();

    expect($application->settings->blueGreenReplicaCount())->toBe(3)
        ->and(BlueGreenReplicaSet::fromReplicas($rows)->usesScalarCompatibilityPath())->toBeTrue()
        ->and($boundExpectation->dockerId)->toBe($containerId)
        ->and(ApplicationBlueGreenDeployment::query()->findOrFail($claim->stateId)->operation_candidate_container_id)
        ->toBe($containerId)
        ->and($deployment->fresh()->blue_green_candidate_container_id)->toBe($containerId);
});

it('executes replica rollback only for the immutable bound identity and preserves replacements', function (): void {
    $fixture = exactBlueGreenReplicaBindingFixture();
    $bound = (new BindBlueGreenReplicaSet)->handle(
        $fixture['claim'],
        exactBlueGreenReplicaInspections($fixture['application'], $fixture['claim']->replicaCount),
    );
    [$commands, $completionAssertions] = (new RemoveBlueGreenReplicaSet)->commandsFor(
        $fixture['claim'],
        collect($bound),
    );
    $firstReplicaId = $bound[0]->container_id;
    $command = implode("\n", $commands);
    $identityGuard = 'test "$(docker ps -aq --no-trunc';
    $removal = 'docker rm -f '.escapeshellarg($firstReplicaId);

    expect($firstReplicaId)->toMatch('/^[a-f0-9]{64}$/')
        ->and($command)->toContain(
            $identityGuard,
            '= '.escapeshellarg($firstReplicaId),
            'label=coolify.blueGreen.replicaIndex=1',
            'label=coolify.blueGreen.replicaCount=3',
            'label=com.docker.compose.project='.$bound[0]->compose_project,
            'label=com.docker.compose.service='.$bound[0]->compose_service,
            $removal,
        )
        ->and(strpos($command, $identityGuard))->toBeInt()->toBeLessThan(strpos($command, $removal))
        ->and(implode("\n", $completionAssertions))->toContain('docker ps -aq --no-trunc');

    $filesystem = new Filesystem;
    $fixtureDirectory = sys_get_temp_dir().'/coolify-replica-removal-'.bin2hex(random_bytes(8));
    $binaryDirectory = $fixtureDirectory.'/bin';
    $stateFile = $fixtureDirectory.'/state';
    $filesystem->mkdir($fixtureDirectory, 0700);
    writeReplicaRemovalFakeDocker($binaryDirectory);
    $stateLines = array_map(
        static fn (ApplicationBlueGreenReplica $replica): string => implode('|', [
            $replica->container_id,
            $replica->container_name,
            $replica->application_id,
            $replica->deployment_uuid,
            $replica->color->value,
            $replica->routing_revision,
            $replica->replica_index,
            3,
            $replica->compose_project,
            $replica->compose_service,
        ]),
        $bound,
    );
    $plan = compact('commands', 'completionAssertions');

    try {
        file_put_contents($stateFile, implode("\n", $stateLines)."\n");
        $exactRemoval = runReplicaRemovalPlanAgainstFakeDocker($plan, $stateFile, $binaryDirectory);

        expect($exactRemoval->isSuccessful())->toBeTrue($exactRemoval->getErrorOutput())
            ->and(trim((string) file_get_contents($stateFile)))->toBe('');

        $replacementId = str_repeat('f', 64);
        $replacementState = preg_replace('/^[^|]+/', $replacementId, $stateLines[0], 1);
        expect($replacementState)->toBeString();
        $replacementStateLines = [$replacementState, ...array_slice($stateLines, 1)];
        file_put_contents($stateFile, implode("\n", $replacementStateLines)."\n");
        $replacementFence = runReplicaRemovalPlanAgainstFakeDocker($plan, $stateFile, $binaryDirectory);

        expect($replacementFence->isSuccessful())->toBeFalse()
            ->and(file($stateFile, FILE_IGNORE_NEW_LINES))->toBe($replacementStateLines);
    } finally {
        $filesystem->remove($fixtureDirectory);
    }

    $truncated = clone $bound[0];
    $truncated->forceFill(['container_id' => str_repeat('a', 12)]);
    expect(fn (): array => (new RemoveBlueGreenReplicaSet)->commandsFor(
        $fixture['claim'],
        collect([$truncated, ...array_slice($bound, 1)]),
    ))->toThrow(RuntimeException::class, 'exact pending release');
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
    $inspections = $inspector->parse($output, $replicas, new BlueGreenReplicaSet(3));

    expect($inspections)->toHaveCount(3)
        ->and(array_column($inspections, 'replicaIndex'))->toBe([1, 2, 3])
        ->and(array_column($inspections, 'containerName'))->toBe([
            'application-blue-replica-1-1',
            'application-blue-replica-2-1',
            'application-blue-replica-3-1',
        ])
        ->and($inspector->commandFor($replicas, new BlueGreenReplicaSet(3)))->toContain(
            'docker ps -aq --no-trunc',
            'label=coolify.blueGreen.replicaIndex=1',
            'label=com.docker.compose.service=application-blue-replica-3',
        )
        ->and($inspector->availableCommandFor($replicas, new BlueGreenReplicaSet(3)))->toContain('docker ps -aq --no-trunc');

    expect(fn () => $inspector->parse(str_replace(
        '"coolify.blueGreen.replicaCount":"3"',
        '"coolify.blueGreen.replicaCount":"2"',
        $output,
    ), $replicas, new BlueGreenReplicaSet(3)))->toThrow(RuntimeException::class, 'replicaCount');
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

    expect(fn () => DB::transaction(
        static fn (): ApplicationBlueGreenReplica => ApplicationBlueGreenReplica::query()->create($attributes),
    ))
        ->toThrow(QueryException::class)
        ->and(ApplicationBlueGreenReplica::query()->count())->toBe(2);
});

it('fails closed when a durable replica ledger omits a contiguous slot', function (): void {
    $replicas = collect([1, 3])->map(function (int $index): ApplicationBlueGreenReplica {
        $replica = new ApplicationBlueGreenReplica;
        $replica->forceFill(['replica_index' => $index]);

        return $replica;
    });

    expect(fn (): BlueGreenReplicaSet => BlueGreenReplicaSet::fromReplicas($replicas))
        ->toThrow(InvalidArgumentException::class, 'contiguous replica index exactly once')
        ->and(fn (): string => (new InspectBlueGreenReplicaSet)->availableCommandFor($replicas, new BlueGreenReplicaSet(2)))
        ->toThrow(RuntimeException::class, 'contiguous claimed quorum');
});

it('requires every claimed replica to be running before a multi-replica start mutation completes', function (): void {
    $fixture = exactBlueGreenReplicaBindingFixture();
    $fixture['application']->update(['build_pack' => 'nixpacks']);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $fixture['application']->fresh(),
        deployment: ApplicationDeploymentQueue::query()
            ->where('deployment_uuid', $fixture['claim']->deploymentUuid)
            ->sole(),
        destination: $fixture['application']->destination,
        server: $fixture['application']->destination->server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    $expectation = new BlueGreenContainerExpectation(
        name: $fixture['claim']->candidateContainerName ?? throw new RuntimeException('The test claim has no candidate name.'),
        dockerId: null,
        applicationId: $fixture['application']->id,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: $fixture['claim']->deploymentUuid,
        color: $fixture['claim']->pendingColor,
        routingRevision: $fixture['claim']->expectedRoutingRevision,
    );

    $assertions = (new ReflectionMethod(
        BlueGreenDeploymentLifecycle::class,
        'candidateStartCompletionAssertionsFor',
    ))->invoke($lifecycle, $fixture['claim'], $expectation);

    expect($assertions)->not->toBeEmpty()
        ->and(implode("\n", $assertions))->toContain(
            'docker ps -aq --no-trunc',
            'coolify.blueGreen.replicaIndex=1',
            'coolify.blueGreen.replicaIndex=2',
            'coolify.blueGreen.replicaIndex=3',
        );
});

it('freezes the configured replica count into durable claim provenance before settings change', function (): void {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $application->update([
        'health_check_enabled' => true,
        'ports_mappings' => null,
    ]);
    $application->settings()->update([
        'is_blue_green_deployment_enabled' => true,
        'is_container_label_readonly_enabled' => true,
        'blue_green_replica_count' => 3,
    ]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'claim-replica-count-provenance',
        'pull_request_id' => 0,
        'commit' => 'claim-replica-count-provenance',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'only_this_server' => true,
    ]);
    Process::fake(['*' => Process::sequence([
        BlueGreenDeactivationScenario::BOOT_ID,
        'coolify-blue-green-destination-state-attested',
        '',
        BlueGreenDeactivationScenario::BOOT_ID,
    ])]);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment,
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );

    try {
        $lifecycle->initialize();
        $claim = $lifecycle->claim();
        DB::table('application_settings')
            ->where('application_id', $application->id)
            ->update(['blue_green_replica_count' => 1]);
        $replicas = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $claim->stateId)
            ->orderBy('replica_index')
            ->get();

        expect($claim->replicaCount)->toBe(3)
            ->and(BlueGreenReplicaSet::fromReplicas($replicas)->count)->toBe(3)
            ->and($replicas->pluck('replica_index')->all())->toBe([1, 2, 3]);
    } finally {
        $lifecycle->release();
    }
});

it('reconstructs the exact prepared claim after the activation queue handoff', function (): void {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $application->update([
        'health_check_enabled' => true,
        'ports_mappings' => null,
    ]);
    $application->settings()->update([
        'is_blue_green_deployment_enabled' => true,
        'is_container_label_readonly_enabled' => true,
    ]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'prepared-activation-claim',
        'pull_request_id' => 0,
        'commit' => 'prepared-activation-claim',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'execution_phase' => ApplicationDeploymentExecutionPhase::Prepare,
        'only_this_server' => true,
    ]);
    Process::fake(function (PendingProcess $process) {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;

        if (str_contains($command, 'coolify-blue-green-destination-state-attested')) {
            return Process::result(output: 'coolify-blue-green-destination-state-attested');
        }

        if (str_contains($command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: BlueGreenDeactivationScenario::BOOT_ID);
        }

        return Process::result(output: '');
    });
    $preparation = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment,
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    $preparation->initialize();
    $preparedClaim = $preparation->claim();
    $preparation->release();
    $deployment->update(['execution_phase' => ApplicationDeploymentExecutionPhase::Activate]);
    $activation = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment->fresh(),
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );

    try {
        $activation->initializePreparedActivation();
        $activationClaim = $activation->claim();

        expect($activationClaim)->not->toBeNull()
            ->and($activationClaim->stateId)->toBe($preparedClaim->stateId)
            ->and($activationClaim->deploymentUuid)->toBe($preparedClaim->deploymentUuid)
            ->and($activationClaim->candidateContainerName)->toBe($preparedClaim->candidateContainerName)
            ->and($activationClaim->destinationFenceEpoch)->toBe($preparedClaim->destinationFenceEpoch)
            ->and($activationClaim->topologyDigest)->toBe($preparedClaim->topologyDigest)
            ->and($activationClaim->routingConfigDigest)->toBe($preparedClaim->routingConfigDigest);
    } finally {
        $activation->release();
    }

    Process::assertRanTimes(function (PendingProcess $process): bool {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;

        return str_contains($command, 'coolify-blue-green-destination-state-attested');
    }, 2);
});

it('reconstructs the exact active three-replica predecessor after the activation queue handoff', function (): void {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $application->update([
        'health_check_enabled' => true,
        'ports_mappings' => null,
    ]);
    $application->settings()->update([
        'is_blue_green_deployment_enabled' => true,
        'is_container_label_readonly_enabled' => true,
        'blue_green_replica_count' => 3,
    ]);
    $application = $application->fresh(['settings']);
    $previousDeploymentUuid = 'prepared-activation-previous-green';
    $candidateDeploymentUuid = 'prepared-activation-candidate-blue';
    $backendPortInventory = BlueGreenBackendPortInventory::fromPorts([3000]);
    $previousFingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::GREEN,
        1,
        1,
        $previousDeploymentUuid,
    );
    $managedFilename = BlueGreenRoutingTarget::managedFilename((string) $application->uuid, (int) $destination->id);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'green_deployment_uuid' => $previousDeploymentUuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
        'destination_fence_epoch' => 1,
        'destination_fence_operation_id' => $previousDeploymentUuid,
        'destination_fence_mutation_sequence' => 1,
        'managed_file_sha256' => str_repeat('d', 64),
        'destination_topology_digest' => $previousFingerprint->topologyDigest,
        'application_routing_config_digest' => $previousFingerprint->routingConfigDigest,
        'supersession_generation' => 1,
    ]);
    $previousReplicaRows = collect(range(1, 3))->map(function (int $index) use (
        $application,
        $destination,
        $previousDeploymentUuid,
        $state,
    ): ApplicationBlueGreenReplica {
        $composeService = $application->uuid."-green-replica-{$index}";

        return ApplicationBlueGreenReplica::query()->create([
            'application_blue_green_deployment_id' => $state->id,
            'application_id' => $application->id,
            'standalone_docker_id' => $destination->id,
            'color' => BlueGreenDeploymentColor::GREEN,
            'replica_index' => $index,
            'deployment_uuid' => $previousDeploymentUuid,
            'routing_revision' => 1,
            'compose_project' => $application->uuid,
            'compose_service' => $composeService,
            'container_name' => $composeService.'-1',
            'container_id' => str_repeat((string) $index, 64),
            'health_status' => 'healthy',
        ]);
    });
    $previousInspections = $previousReplicaRows->map(
        static fn (ApplicationBlueGreenReplica $replica): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $replica->replica_index,
            composeService: $replica->compose_service,
            containerName: $replica->container_name,
            dockerId: $replica->container_id,
            status: 'running',
            health: 'healthy',
        ),
    )->all();
    $previousReplicaDigest = BlueGreenReplicaSet::identityDigest($previousInspections);
    $previousDeployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $previousDeploymentUuid,
        'pull_request_id' => 0,
        'commit' => 'prepared-activation-previous-green-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_server_boot_id' => BlueGreenDeactivationScenario::BOOT_ID,
        'blue_green_topology_digest' => $previousFingerprint->topologyDigest,
        'blue_green_routing_config_digest' => $previousFingerprint->routingConfigDigest,
        'blue_green_backend_port_inventory' => $backendPortInventory->serialized,
        'blue_green_drain_backend_port_inventory' => null,
        'blue_green_supersession_generation' => 1,
        'blue_green_candidate_container_id' => $previousReplicaDigest,
        'blue_green_rollback_managed_filename' => $managedFilename,
    ]);
    $candidateDeployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $candidateDeploymentUuid,
        'pull_request_id' => 0,
        'commit' => 'prepared-activation-candidate-blue-commit',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'execution_phase' => ApplicationDeploymentExecutionPhase::Prepare,
        'only_this_server' => true,
    ]);
    $preparedClaim = ClaimBlueGreenDeployment::run(
        application: $application,
        standaloneDocker: $destination,
        deployment: $candidateDeployment,
        serverBootId: BlueGreenDeactivationScenario::BOOT_ID,
        previousContainer: new BlueGreenContainerExpectation(
            name: $application->uuid.'-green',
            dockerId: $previousReplicaDigest,
            applicationId: $application->id,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $previousDeployment->deployment_uuid,
            color: BlueGreenDeploymentColor::GREEN,
            routingRevision: 1,
        ),
    );
    $candidateDeployment->update(['execution_phase' => ApplicationDeploymentExecutionPhase::Activate]);
    $previousReplicaOutput = $previousReplicaRows->map(static function (ApplicationBlueGreenReplica $replica): string {
        return json_encode([
            'Id' => $replica->container_id,
            'Name' => '/'.$replica->container_name,
            'State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']],
            'Config' => ['Labels' => [
                'coolify.applicationId' => (string) $replica->application_id,
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => $replica->deployment_uuid,
                'coolify.blueGreen.color' => $replica->color->value,
                'coolify.blueGreen.routingRevision' => (string) $replica->routing_revision,
                'coolify.blueGreen.replicaIndex' => (string) $replica->replica_index,
                'coolify.blueGreen.replicaCount' => '3',
                'com.docker.compose.project' => $replica->compose_project,
                'com.docker.compose.service' => $replica->compose_service,
            ]],
        ], JSON_THROW_ON_ERROR);
    })->implode("\n");
    Process::fake(function ($process) use ($previousReplicaOutput) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        if (str_contains($command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: BlueGreenDeactivationScenario::BOOT_ID);
        }
        if (str_contains($command, 'coolify_replica_')) {
            return Process::result(output: $previousReplicaOutput);
        }

        return Process::result(output: '[]');
    });
    $activation = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $candidateDeployment->fresh(),
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );

    try {
        $activation->initializePreparedActivation();
        expect($activation->wasPreparedActivationHandled())->toBeFalse(
            (string) $candidateDeployment->fresh()?->logs,
        );
        $reconstructedPreviousReplicas = (new ReflectionProperty($activation, 'previousReplicaInspections'))->getValue($activation);
        $reconstructedPreviousExpectation = (new ReflectionProperty($activation, 'previousContainerExpectation'))->getValue($activation);
        $reconstructedPreviousBackends = (new ReflectionMethod(
            $activation,
            'replicaBackendsForColor',
        ))->invoke($activation, BlueGreenDeploymentColor::GREEN);

        expect($activation->claim()?->stateId)->toBe($preparedClaim->stateId)
            ->and($reconstructedPreviousReplicas)->toHaveCount(3)
            ->and(array_map(
                static fn (BlueGreenReplicaInspection $inspection): int => $inspection->replicaIndex,
                $reconstructedPreviousReplicas,
            ))->toBe([1, 2, 3])
            ->and(array_map(
                static fn (BlueGreenReplicaInspection $inspection): string => $inspection->dockerId,
                $reconstructedPreviousReplicas,
            ))->toBe($previousReplicaRows->pluck('container_id')->all())
            ->and(array_map(
                static fn (BlueGreenReplicaInspection $inspection): string => $inspection->status.'/'.$inspection->health,
                $reconstructedPreviousReplicas,
            ))->toBe(['running/healthy', 'running/healthy', 'running/healthy'])
            ->and($reconstructedPreviousBackends)->toBe($previousReplicaRows->pluck('container_name')->all())
            ->and(BlueGreenReplicaSet::identityDigest($reconstructedPreviousReplicas))->toBe($previousReplicaDigest)
            ->and($reconstructedPreviousExpectation->dockerId)->toBe($previousReplicaDigest);
    } finally {
        $activation->release();
    }
});

it('reconstructs the reserved replica quorum after the setting changes', function (
    int $reservedReplicaCount,
    int $changedReplicaCount,
): void {
    $fixture = exactBlueGreenReplicaBindingFixture($reservedReplicaCount);
    DB::table('application_settings')
        ->where('application_id', $fixture['application']->id)
        ->update(['blue_green_replica_count' => $changedReplicaCount]);

    $replicaCount = (new ReflectionMethod(
        ReconstructBlueGreenDeploymentRecovery::class,
        'replicaCount',
    ))->invoke(
        new ReconstructBlueGreenDeploymentRecovery,
        $fixture['state'],
        $fixture['claim']->deploymentUuid,
        $fixture['claim']->pendingColor,
        $fixture['claim']->expectedRoutingRevision,
    );

    expect($replicaCount)->toBe($reservedReplicaCount)
        ->and(ApplicationBlueGreenReplica::query()
            ->where('deployment_uuid', $fixture['claim']->deploymentUuid)
            ->count())->toBe($reservedReplicaCount);
})->with([
    'three to one' => [3, 1],
    'one to three' => [1, 3],
]);

it('plans removal of every co-rolled member of a colour, including a partially started one', function (): void {
    $rows = collect([
        ['compose_service' => 'llm_gateway-blue', 'container_name' => 'app-blue', 'container_id' => str_repeat('a', 64)],
        ['compose_service' => 'queue-blue', 'container_name' => 'app-queue-blue', 'container_id' => str_repeat('b', 64)],
    ])->map(static function (array $attributes): ApplicationBlueGreenReplica {
        $replica = new ApplicationBlueGreenReplica;
        $replica->forceFill([
            ...$attributes,
            'application_blue_green_deployment_id' => 7,
            'application_id' => 41,
            'standalone_docker_id' => 3,
            'deployment_uuid' => 'co-rolled-release',
            'color' => BlueGreenDeploymentColor::BLUE,
            'routing_revision' => 12,
            'replica_index' => 1,
            'compose_project' => 'application-project',
        ]);

        return $replica;
    });
    $claim = new BlueGreenDeploymentClaim(
        stateId: 7,
        applicationId: 41,
        standaloneDockerId: 3,
        pendingColor: BlueGreenDeploymentColor::BLUE,
        previousActiveColor: null,
        deploymentUuid: 'co-rolled-release',
        expectedRoutingRevision: 12,
        destinationFenceEpoch: 1,
        serverBootId: '11111111-1111-1111-1111-111111111111',
        topologyDigest: hash('sha256', 'co-rolled-topology'),
        routingConfigDigest: hash('sha256', 'co-rolled-routing'),
        backendPortInventory: BlueGreenBackendPortInventory::fromPorts([8000, 8080], [8000 => 'llm_gateway', 8080 => 'queue']),
        drainBackendPortInventory: null,
        supersessionGeneration: 1,
        legacyContainerName: null,
        replicaCount: 1,
        candidateContainerName: 'app-blue',
        rollbackManagedFilename: 'co-rolled-rollback.yaml',
        candidateContainerNames: ['llm_gateway' => 'app-blue', 'queue' => 'app-queue-blue'],
    );

    [$commands] = (new RemoveBlueGreenReplicaSet)->commandsFor($claim, $rows);
    $joined = implode("\n", $commands);

    // Both members are planned for removal, each through its own durable
    // identity, and neither is asserted to carry replica fan-out labels that a
    // one-replica member never had.
    expect($joined)->toContain(str_repeat('a', 64), str_repeat('b', 64))
        ->and($joined)->toContain('label=com.docker.compose.service=llm_gateway-blue')
        ->and($joined)->toContain('label=com.docker.compose.service=queue-blue')
        ->and($joined)->not->toContain('coolify.blueGreen.replicaCount');

    // A crash can leave only one member started; the survivor is still planned.
    [$partialCommands] = (new RemoveBlueGreenReplicaSet)->commandsFor($claim, $rows->take(1));

    expect(implode("\n", $partialCommands))->toContain(str_repeat('a', 64))
        ->and(implode("\n", $partialCommands))->not->toContain(str_repeat('b', 64));
});

it('admits one inspection per co-rolled member when binding a colour', function (): void {
    $fixture = exactBlueGreenReplicaBindingFixture(1);
    $claim = $fixture['claim'];
    $coRolled = new BlueGreenDeploymentClaim(
        stateId: $claim->stateId,
        applicationId: $claim->applicationId,
        standaloneDockerId: $claim->standaloneDockerId,
        pendingColor: $claim->pendingColor,
        previousActiveColor: null,
        deploymentUuid: $claim->deploymentUuid,
        expectedRoutingRevision: $claim->expectedRoutingRevision,
        destinationFenceEpoch: $claim->destinationFenceEpoch,
        serverBootId: $claim->serverBootId,
        topologyDigest: $claim->topologyDigest,
        routingConfigDigest: $claim->routingConfigDigest,
        backendPortInventory: $claim->backendPortInventory,
        drainBackendPortInventory: null,
        supersessionGeneration: $claim->supersessionGeneration,
        legacyContainerName: null,
        replicaCount: 1,
        candidateContainerName: $claim->candidateContainerName,
        rollbackManagedFilename: $claim->rollbackManagedFilename,
        candidateContainerNames: [
            'gateway' => $claim->candidateContainerName,
            'queue' => $claim->candidateContainerName.'-queue',
        ],
    );

    // Two members at one replica each is two containers, which is the colour's
    // promotion threshold — not a breach of the claimed quorum.
    expect(fn () => (new BindBlueGreenReplicaSet)->handle($coRolled, [
        BlueGreenReplicaInspection::fromRuntime(1, 'gateway-blue', 'app-blue', str_repeat('a', 64), 'running', 'healthy'),
        BlueGreenReplicaInspection::fromRuntime(1, 'queue-blue', 'app-queue-blue', str_repeat('b', 64), 'running', 'healthy'),
    ]))->not->toThrow(RuntimeException::class, 'outside the claimed blue-green operation quorum');
});

it('binds a reserved slot whose container name was pinned before Docker gave it an id', function (): void {
    $replica = new ApplicationBlueGreenReplica;
    $replica->forceFill([
        'container_name' => 'app-queue-blue',
        'container_id' => null,
    ]);

    // A co-rolled member rendered under its own name has container_name pinned
    // by the Compose document at reserve time; only an id without a name is
    // genuinely partial.
    expect(fn () => (new ReflectionMethod(BindBlueGreenReplicaSet::class, 'bindExactReplicaInspection'))
        ->invoke(new BindBlueGreenReplicaSet, $replica, BlueGreenReplicaInspection::fromRuntime(
            1, 'queue-blue', 'app-queue-blue', str_repeat('c', 64), 'running', 'healthy',
        )))->not->toThrow(RuntimeException::class, 'partial container identity');

    $mismatched = new ApplicationBlueGreenReplica;
    $mismatched->forceFill(['container_name' => null, 'container_id' => str_repeat('d', 64)]);

    expect(fn () => (new ReflectionMethod(BindBlueGreenReplicaSet::class, 'bindExactReplicaInspection'))
        ->invoke(new BindBlueGreenReplicaSet, $mismatched, BlueGreenReplicaInspection::fromRuntime(
            1, 'queue-blue', 'app-queue-blue', str_repeat('d', 64), 'running', 'healthy',
        )))->toThrow(RuntimeException::class, 'partial container identity');
});
