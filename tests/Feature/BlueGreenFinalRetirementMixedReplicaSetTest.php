<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\RetireBlueGreenInactiveContainer;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ContainerStatusTypes;
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
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $statuses
 * @return array{application: Application, owner: ApplicationDeploymentQueue, state: ApplicationBlueGreenDeployment, inspections: list<BlueGreenReplicaInspection>}
 */
function makeFinalRetirementMixedReplicaScenario(array $statuses): array
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
        'fqdn' => 'https://final-mixed-retirement.example.test',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'ports_mappings' => null,
        'custom_docker_run_options' => null,
        'health_check_enabled' => true,
    ]);
    $application->settings->update([
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
        'custom_internal_name' => null,
        'is_blue_green_deployment_enabled' => true,
    ]);
    $application = $application->fresh();

    $ownerUuid = 'final-mixed-retirement-owner';
    $inactiveUuid = 'final-mixed-retirement-inactive';
    $activeContainerId = str_repeat('c', 64);
    $inactiveContainerId = str_repeat('a', 64);
    $fingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::GREEN,
        2,
        2,
        $ownerUuid,
    );
    $runtimeState = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        new BlueGreenRoutingTarget(
            destinationId: $destination->id,
            activeColor: BlueGreenDeploymentColor::GREEN,
            blueContainerName: $application->uuid.'-blue',
            greenContainerName: $application->uuid.'-green',
            port: 3000,
            ports: null,
            routingRevision: 2,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($ownerUuid),
            destinationFenceEpoch: 2,
            operationId: $ownerUuid,
            mutationSequence: 2,
            activeDeploymentUuid: $ownerUuid,
            activeContainerId: $activeContainerId,
            destinationTopologyDigest: $fingerprint->operationTopologyDigest,
        ),
    )->state;
    $inventory = BlueGreenBackendPortInventory::forApplication($application, $application->settings);
    $owner = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $ownerUuid,
        'pull_request_id' => 0,
        'commit' => 'final-mixed-retirement-owner-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 2,
        'blue_green_destination_fence_epoch' => 2,
        'blue_green_topology_digest' => $fingerprint->operationTopologyDigest,
        'blue_green_routing_config_digest' => $fingerprint->routingConfigDigest,
        'blue_green_candidate_container_id' => $activeContainerId,
        'blue_green_backend_port_inventory' => $inventory->serialized,
        'blue_green_drain_backend_port_inventory' => $inventory->serialized,
    ]);
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $inactiveUuid,
        'pull_request_id' => 0,
        'commit' => 'final-mixed-retirement-inactive-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_topology_digest' => $fingerprint->operationTopologyDigest,
        'blue_green_routing_config_digest' => $fingerprint->routingConfigDigest,
        'blue_green_candidate_container_id' => $inactiveContainerId,
        'blue_green_backend_port_inventory' => $inventory->serialized,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'blue_deployment_uuid' => $inactiveUuid,
        'green_deployment_uuid' => $ownerUuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
        'supersession_generation' => 2,
        'destination_fence_epoch' => $runtimeState->destinationFenceEpoch,
        'destination_fence_operation_id' => $runtimeState->operationId,
        'destination_fence_mutation_sequence' => $runtimeState->mutationSequence,
        'managed_file_sha256' => $runtimeState->managedSha256,
        'destination_topology_digest' => $runtimeState->destinationTopologyDigest,
        'destination_routing_topology_digest' => $fingerprint->routingTopologyDigest,
        'application_routing_config_digest' => $runtimeState->applicationRoutingConfigDigest,
        'inactive_retirement_owner_deployment_uuid' => $ownerUuid,
        'inactive_retirement_color' => BlueGreenDeploymentColor::BLUE,
        'inactive_retirement_deployment_uuid' => $inactiveUuid,
        'inactive_retirement_container_id' => $inactiveContainerId,
        'inactive_retirement_container_routing_revision' => 1,
        'inactive_retirement_owner_routing_revision' => 2,
        'inactive_retirement_supersession_generation' => 2,
        'inactive_retirement_destination_fence_epoch' => 2,
        'inactive_retirement_server_boot_id' => '11111111-2222-3333-4444-555555555555',
        'inactive_retirement_topology_digest' => $runtimeState->destinationTopologyDigest,
        'inactive_retirement_routing_config_digest' => $runtimeState->applicationRoutingConfigDigest,
        'inactive_retirement_not_before_at' => now()->subMinute(),
        'inactive_retirement_drain_deadline_at' => now()->addMinute(),
        'inactive_retirement_stop_grace_seconds' => 30,
        'inactive_retirement_lease_seconds' => 4_000,
        'inactive_retirement_attempts' => RetireBlueGreenInactiveContainer::MAX_ATTEMPTS - 1,
    ]);

    config(['constants.ssh.mux_enabled' => false]);
    $privateKeyId = (int) $server->private_key_id;
    $privateKey = PrivateKey::query()->find($privateKeyId)
        ?? PrivateKey::factory()->create([
            'id' => $privateKeyId,
            'team_id' => $server->team_id,
        ]);
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);

    $inspections = array_map(function (string $status, int $offset) use ($application): BlueGreenReplicaInspection {
        $replicaIndex = $offset + 1;
        $composeService = $application->uuid."-blue-replica-{$replicaIndex}";

        return BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $replicaIndex,
            composeService: $composeService,
            containerName: "{$composeService}-1",
            dockerId: str_repeat((string) $replicaIndex, 64),
            status: $status,
            health: 'healthy',
        );
    }, array_values($statuses), array_keys($statuses));
    $identity = BlueGreenReplicaSet::identityDigest($inspections);
    $state->update(['inactive_retirement_container_id' => $identity]);
    ApplicationDeploymentQueue::query()
        ->where('application_id', $application->id)
        ->where('deployment_uuid', $inactiveUuid)
        ->update(['blue_green_candidate_container_id' => $identity]);
    foreach ($inspections as $inspection) {
        ApplicationBlueGreenReplica::query()->create([
            'application_blue_green_deployment_id' => $state->id,
            'application_id' => $application->id,
            'standalone_docker_id' => $destination->id,
            'color' => $state->inactive_retirement_color,
            'replica_index' => $inspection->replicaIndex,
            'deployment_uuid' => $inactiveUuid,
            'routing_revision' => $state->inactive_retirement_container_routing_revision,
            'compose_project' => $application->uuid,
            'compose_service' => $inspection->composeService,
            'container_name' => $inspection->containerName,
            'container_id' => $inspection->dockerId,
            'health_status' => 'healthy',
            'last_observed_at' => now()->subMinute(),
        ]);
    }

    return [
        'application' => $application,
        'owner' => $owner,
        'state' => $state->fresh(),
        'inspections' => $inspections,
    ];
}

/** @param list<BlueGreenReplicaInspection> $inspections */
function finalRetirementMixedReplicaInspectionOutput(
    Application $application,
    ApplicationBlueGreenDeployment $state,
    array $inspections,
): string {
    return collect($inspections)->map(
        static fn (BlueGreenReplicaInspection $inspection): string => json_encode([
            'Id' => $inspection->dockerId,
            'Name' => '/'.$inspection->containerName,
            'State' => [
                'Status' => $inspection->status,
                'Health' => ['Status' => $inspection->health],
            ],
            'Config' => ['Labels' => [
                'coolify.applicationId' => (string) $application->id,
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => $state->inactive_retirement_deployment_uuid,
                'coolify.blueGreen.color' => $state->inactive_retirement_color->value,
                'coolify.blueGreen.routingRevision' => (string) $state->inactive_retirement_container_routing_revision,
                'coolify.blueGreen.replicaIndex' => (string) $inspection->replicaIndex,
                'coolify.blueGreen.replicaCount' => (string) count($inspections),
                'com.docker.compose.project' => $application->uuid,
                'com.docker.compose.service' => $inspection->composeService,
            ]],
        ], JSON_THROW_ON_ERROR),
    )->implode("\n");
}

it('includes exited members in the final-attempt removal commands and absence assertions before recording removal', function (string $terminalStatus): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state, 'inspections' => $inspections] = makeFinalRetirementMixedReplicaScenario([
        ContainerStatusTypes::RUNNING->value,
        $terminalStatus,
    ]);
    $replicas = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $state->id)
        ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
        ->orderBy('replica_index')
        ->orderBy('compose_service')
        ->get();
    $replicaSet = BlueGreenReplicaSet::fromReplicas(
        $replicas,
        $state->candidateComposeServicesFor(
            $state->inactive_retirement_color,
            $state->inactive_retirement_deployment_uuid,
            $application,
        ),
    );
    $inspectionOutput = finalRetirementMixedReplicaInspectionOutput($application, $state, $inspections);
    $mutationPayloads = [];
    $bootId = (string) $state->inactive_retirement_server_boot_id;
    Process::fake(function (PendingProcess $process) use (&$mutationPayloads, $bootId, $inspectionOutput): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (str_contains($payload, 'container_journal_stage=')) {
            $mutationPayloads[] = $payload;

            return Process::result();
        }
        if (str_contains($payload, 'coolify_replica_')) {
            return Process::result(output: $inspectionOutput);
        }

        return Process::result(output: $bootId);
    });
    InspectBlueGreenContainer::shouldRun()->never();

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::COMPLETED);

    $expectedCommands = [];
    $expectedCompletionAssertions = [];
    foreach ($inspections as $inspection) {
        $replica = $replicas->firstWhere('compose_service', $inspection->composeService);
        if (! $replica instanceof ApplicationBlueGreenReplica) {
            throw new RuntimeException('The mixed final-retirement fixture lost an exact Compose slot.');
        }
        $expectation = new BlueGreenContainerExpectation(
            name: $inspection->containerName,
            dockerId: $inspection->dockerId,
            applicationId: $application->id,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $state->inactive_retirement_deployment_uuid,
            color: $state->inactive_retirement_color,
            routingRevision: $state->inactive_retirement_container_routing_revision,
        );
        $containerId = escapeshellarg($inspection->dockerId);
        $inspector = new InspectBlueGreenContainer;
        array_push($expectedCommands, ...$inspector->exactReplicaMutationAssertionsFor(
            expectation: $expectation,
            replicaIndex: $replica->replica_index,
            replicaSet: $replicaSet,
            composeProject: $replica->compose_project,
            composeService: $replica->compose_service,
        ));
        $expectedCommands[] = "docker rm -f {$containerId} >/dev/null 2>&1 || ! docker container inspect {$containerId} >/dev/null 2>&1";
        array_push(
            $expectedCompletionAssertions,
            ...$inspector->absentMutationCompletionAssertionsFor($expectation),
        );
        $filters = [
            'label=coolify.applicationId='.$replica->application_id,
            'label=coolify.pullRequestId=0',
            'label=coolify.blueGreen.managed=true',
            'label=coolify.blueGreen.deploymentUuid='.$replica->deployment_uuid,
            'label=coolify.blueGreen.color='.$replica->color->value,
            'label=coolify.blueGreen.routingRevision='.$replica->routing_revision,
        ];
        foreach ($replicaSet->labelMap($replica->replica_index) as $label => $value) {
            $filters[] = "label={$label}={$value}";
        }
        $filters[] = 'label=com.docker.compose.project='.$replica->compose_project;
        $filters[] = 'label=com.docker.compose.service='.$replica->compose_service;
        $filterArguments = implode(' ', array_map(
            static fn (string $filter): string => '--filter '.escapeshellarg($filter),
            $filters,
        ));
        $expectedCompletionAssertions[] = 'test -z "$(docker ps -aq --no-trunc '.$filterArguments.')"';
    }
    $expectedMutationScript = implode("\n", ['set -eu', ...$expectedCommands])."\n";
    $expectedCompletionScript = implode("\n", ['set -eu', ...$expectedCompletionAssertions])."\n";
    expect($mutationPayloads)->toHaveCount(1)
        ->and($mutationPayloads[0])->toContain(
            base64_encode($expectedMutationScript),
            base64_encode($expectedCompletionScript),
        )
        ->and($state->fresh()->inactive_retirement_stopped_at)->not->toBeNull()
        ->and(ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->id)
            ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
            ->get()
            ->every(static fn (ApplicationBlueGreenReplica $replica): bool => $replica->health_status === 'stopped'))->toBeTrue()
        ->and((string) $owner->fresh()->logs)->toContain('was removed after the bounded retirement limit');
})->with([
    'exited member' => ContainerStatusTypes::EXITED->value,
    'dead member' => ContainerStatusTypes::DEAD->value,
]);

it('keeps a surviving mixed replica set unrecorded and sanitizes the ambiguous final-retirement log', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state, 'inspections' => $inspections] = makeFinalRetirementMixedReplicaScenario([
        ContainerStatusTypes::RUNNING->value,
        ContainerStatusTypes::EXITED->value,
    ]);
    $inspectionOutput = finalRetirementMixedReplicaInspectionOutput($application, $state, $inspections);
    $bootId = (string) $state->inactive_retirement_server_boot_id;
    $remoteStderr = 'Error response from daemon: exited member '.str_repeat('2', 64).' survived forced removal';
    Process::fake(function (PendingProcess $process) use ($bootId, $inspectionOutput, $remoteStderr): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (str_contains($payload, 'container_journal_stage=')) {
            return Process::result(errorOutput: $remoteStderr, exitCode: 1);
        }
        if (str_contains($payload, 'coolify_replica_')) {
            return Process::result(output: $inspectionOutput);
        }

        return Process::result(output: $bootId);
    });
    InspectBlueGreenContainer::shouldRun()->never();

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::INTERVENTION);

    $state = $state->fresh();
    $logs = (string) $owner->fresh()->logs;
    expect($state->inactive_retirement_stopped_at)->toBeNull()
        ->and($state->inactive_retirement_attempts)->toBe(RetireBlueGreenInactiveContainer::MAX_ATTEMPTS)
        ->and($state->inactive_retirement_intervention_required_at)->not->toBeNull()
        ->and(ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->id)
            ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
            ->get()
            ->every(static fn (ApplicationBlueGreenReplica $replica): bool => $replica->health_status === 'healthy'))->toBeTrue()
        ->and($logs)->toMatch('/reason=ambiguous_mutation correlation_id=[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/')
        ->and($logs)->not->toContain('Error response from daemon')
        ->and($logs)->not->toContain('survived forced removal');
});
