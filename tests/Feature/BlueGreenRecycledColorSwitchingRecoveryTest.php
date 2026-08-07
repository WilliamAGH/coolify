<?php

use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\PlanBlueGreenSteadyState;
use App\Actions\Application\BlueGreen\ResolveBlueGreenActiveReplicaSet;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const RECYCLED_BLUE_FIRST_RELEASE = 'recycled-blue-first-release';
const RECYCLED_GREEN_INTERIM_RELEASE = 'recycled-green-interim-release';
const RECYCLED_BLUE_PENDING_RELEASE = 'recycled-blue-pending-release';

/**
 * A destination whose BLUE color already carries a finalized earlier release
 * while a later promotion BACK to BLUE crashed mid-SWITCHING: the durable
 * finalized `blue_deployment_uuid` still names the first release, and only
 * `pending_deployment_uuid` names the release the proxy actually routes.
 *
 * @return array{
 *     application: Application,
 *     state: ApplicationBlueGreenDeployment,
 *     pendingInspections: non-empty-list<BlueGreenReplicaInspection>,
 * }
 */
function makeBlueGreenRecycledColorSwitchingFixture(): array
{
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://recycled-color.example.test',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
    ]);

    $releases = [
        [RECYCLED_BLUE_FIRST_RELEASE, BlueGreenDeploymentColor::BLUE, 1, ApplicationDeploymentStatus::FINISHED, BlueGreenDeploymentPhase::IDLE],
        [RECYCLED_GREEN_INTERIM_RELEASE, BlueGreenDeploymentColor::GREEN, 2, ApplicationDeploymentStatus::FINISHED, BlueGreenDeploymentPhase::IDLE],
        [RECYCLED_BLUE_PENDING_RELEASE, BlueGreenDeploymentColor::BLUE, 3, ApplicationDeploymentStatus::IN_PROGRESS, BlueGreenDeploymentPhase::SWITCHING],
    ];
    foreach ($releases as [$deploymentUuid, $color, $routingRevision, $status, $phase]) {
        ApplicationDeploymentQueue::query()->create([
            'application_id' => $application->id,
            'application_name' => $application->name,
            'server_id' => $server->id,
            'server_name' => $server->name,
            'destination_id' => $destination->id,
            'deployment_uuid' => $deploymentUuid,
            'pull_request_id' => 0,
            'commit' => "commit-{$deploymentUuid}",
            'status' => $status->value,
            'blue_green_color' => $color,
            'blue_green_phase' => $phase,
            'blue_green_routing_revision' => $routingRevision,
            'blue_green_destination_fence_epoch' => $routingRevision,
        ]);
    }

    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'pending_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => RECYCLED_BLUE_FIRST_RELEASE,
        'green_deployment_uuid' => RECYCLED_GREEN_INTERIM_RELEASE,
        'pending_deployment_uuid' => RECYCLED_BLUE_PENDING_RELEASE,
        'operation_deployment_uuid' => RECYCLED_BLUE_PENDING_RELEASE,
        'phase' => BlueGreenDeploymentPhase::SWITCHING,
        'routing_revision' => 3,
        'supersession_generation' => 3,
        'destination_fence_epoch' => 3,
        'destination_fence_operation_id' => RECYCLED_BLUE_PENDING_RELEASE,
        'destination_fence_mutation_sequence' => 5,
        'managed_file_sha256' => str_repeat('e', 64),
        'destination_topology_digest' => hash('sha256', 'recycled-color-topology'),
        'application_routing_config_digest' => hash('sha256', 'recycled-color-routing-config'),
    ]);

    $ledger = [
        [RECYCLED_BLUE_FIRST_RELEASE, 1, 'c'],
        [RECYCLED_BLUE_PENDING_RELEASE, 3, 'd'],
    ];
    $pendingInspections = [];
    foreach ($ledger as [$deploymentUuid, $routingRevision, $idNibble]) {
        foreach ((new BlueGreenReplicaSet(2))->indexes() as $replicaIndex) {
            $composeService = $application->uuid."-blue-replica-{$replicaIndex}";
            $containerName = "{$composeService}-rev{$routingRevision}";
            $dockerId = str_repeat($idNibble, 63).$replicaIndex;
            ApplicationBlueGreenReplica::query()->create([
                'application_blue_green_deployment_id' => $state->id,
                'application_id' => $application->id,
                'standalone_docker_id' => $destination->id,
                'color' => BlueGreenDeploymentColor::BLUE,
                'replica_index' => $replicaIndex,
                'deployment_uuid' => $deploymentUuid,
                'routing_revision' => $routingRevision,
                'compose_project' => $application->uuid,
                'compose_service' => $composeService,
                'container_name' => $containerName,
                'container_id' => $dockerId,
                'health_status' => 'healthy',
                'last_observed_at' => now()->subMinute(),
            ]);
            if ($deploymentUuid === RECYCLED_BLUE_PENDING_RELEASE) {
                $pendingInspections[] = BlueGreenReplicaInspection::fromRuntime(
                    replicaIndex: $replicaIndex,
                    composeService: $composeService,
                    containerName: $containerName,
                    dockerId: $dockerId,
                    status: 'running',
                    health: 'healthy',
                );
            }
        }
    }

    return [
        'application' => $application,
        'state' => $state,
        'pendingInspections' => $pendingInspections,
    ];
}

it('recovers a recycled-color switching route onto the pending release instead of the stale finalized one', function (): void {
    [
        'application' => $application,
        'state' => $state,
        'pendingInspections' => $pendingInspections,
    ] = makeBlueGreenRecycledColorSwitchingFixture();
    $destination = $application->destination;
    $pendingReplicaSet = (new ResolveBlueGreenActiveReplicaSet)->fromBoundIdentities(
        $application->blueGreenComposeTopology(),
        BlueGreenDeploymentColor::BLUE,
        new BlueGreenReplicaSet(2),
        $pendingInspections,
        [3000],
    );
    // The proven mid-SWITCHING route: the proxy already targets the NEW pending
    // release on the recycled BLUE color, exactly as PlanBlueGreenForwardRecovery
    // hands it to routingTargetForState.
    $expectedState = new BlueGreenProxyState(
        managedFilename: BlueGreenRoutingTarget::managedFilename((string) $application->uuid, (int) $destination->id),
        applicationUuid: (string) $application->uuid,
        destinationId: (int) $destination->id,
        operationId: RECYCLED_BLUE_PENDING_RELEASE,
        mutationSequence: 5,
        destinationFenceEpoch: 3,
        routingRevision: 3,
        managedSha256: str_repeat('e', 64),
        activeColor: BlueGreenDeploymentColor::BLUE,
        activeDeploymentUuid: RECYCLED_BLUE_PENDING_RELEASE,
        activeContainerName: $pendingReplicaSet->representative()->name,
        activeContainerId: $pendingReplicaSet->representative()->id,
        applicationRoutingConfigDigest: hash('sha256', 'recycled-color-routing-config'),
        destinationTopologyDigest: hash('sha256', 'recycled-color-topology'),
        activeReplicaSetDigest: $pendingReplicaSet->identityDigest(),
        activeReplicaSet: $pendingReplicaSet,
    );

    $target = (new PlanBlueGreenSteadyState)->routingTargetForState(
        $application,
        $destination,
        $state->fresh(),
        $expectedState,
        BlueGreenRoutingMode::Steady,
    );

    $pendingBackends = array_map(
        static fn (BlueGreenReplicaInspection $inspection): string => $inspection->containerName,
        $pendingInspections,
    );
    expect($target->activeDeploymentUuid)->toBe(RECYCLED_BLUE_PENDING_RELEASE)
        ->and($target->blueReplicaBackends)->toBe($pendingBackends)
        ->and($target->blueReplicaSet?->identityDigest())->toBe($pendingReplicaSet->identityDigest())
        ->and($target->blueReplicaBackends)->not->toContain($application->uuid.'-blue-replica-1-rev1')
        ->and($target->blueReplicaBackends)->not->toContain($application->uuid.'-blue-replica-2-rev1');
});
