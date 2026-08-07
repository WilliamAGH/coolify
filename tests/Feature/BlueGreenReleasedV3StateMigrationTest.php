<?php

use App\Actions\Application\BlueGreen\AttestBlueGreenDestinationState;
use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\BlueGreenSteadyStateRepairResult;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\MigrateBlueGreenReleasedV3ProxyState;
use App\Actions\Application\BlueGreen\PlanBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\PlanBlueGreenSteadyState;
use App\Actions\Application\BlueGreen\ReconstructBlueGreenDeploymentRecovery;
use App\Actions\Application\BlueGreen\RepairBlueGreenSteadyState;
use App\Actions\Application\BlueGreen\ResolveBlueGreenExpectedProxyState;
use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    config(['constants.ssh.mux_enabled' => false]);
});

/**
 * @return array{
 *     application: Application,
 *     server: Server,
 *     destination: StandaloneDocker,
 *     state: ApplicationBlueGreenDeployment,
 *     deployment: ApplicationDeploymentQueue,
 *     configuration: BlueGreenProxyConfiguration,
 *     canonical: BlueGreenProxyState,
 *     released: BlueGreenProxyState,
 *     aggregate_id: string,
 *     routed_id: string,
 *     secondary_id: string,
 *     alphabetical_sibling_id: string
 * }
 */
function releasedV3StateFixture(): array
{
    Storage::fake('ssh-keys');
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $document = [
        'services' => [
            'web' => [
                'container_name' => 'web-compose-application',
                'image' => 'example/web:latest',
                'healthcheck' => ['test' => ['CMD', 'true'], 'interval' => '5s'],
                'labels' => [
                    'coolify.applicationId=1',
                    'coolify.managed=true',
                    'coolify.pullRequestId=0',
                    'coolify.type=application',
                    'traefik.enable=true',
                    'traefik.http.routers.web.rule=Host(`released-v3-web.example.test`) && PathPrefix(`/`)',
                    'traefik.http.routers.web.entryPoints=https',
                    'traefik.http.routers.web.service=web',
                    'traefik.http.routers.web.tls=true',
                    'traefik.http.services.web.loadbalancer.server.port=3000',
                ],
            ],
            'worker' => [
                'container_name' => 'worker-compose-application',
                'image' => 'example/worker:latest',
                'healthcheck' => ['test' => ['CMD', 'true'], 'interval' => '5s'],
                'labels' => [
                    'coolify.applicationId=1',
                    'coolify.managed=true',
                    'coolify.pullRequestId=0',
                    'coolify.type=application',
                    'traefik.enable=true',
                    'traefik.http.routers.worker.rule=Host(`released-v3-worker.example.test`) && PathPrefix(`/`)',
                    'traefik.http.routers.worker.entryPoints=https',
                    'traefik.http.routers.worker.service=worker',
                    'traefik.http.routers.worker.tls=true',
                    'traefik.http.services.worker.loadbalancer.server.port=4000',
                ],
            ],
            'aaa_sidecar' => [
                'container_name' => 'aaa-sidecar-compose-application',
                'image' => 'example/sidecar:latest',
                'healthcheck' => ['test' => ['CMD', 'true'], 'interval' => '5s'],
                'environment' => ['WEB_URL' => 'http://web:3000'],
                'labels' => [
                    'coolify.applicationId=1',
                    'coolify.managed=true',
                    'coolify.pullRequestId=0',
                    'coolify.type=application',
                ],
            ],
        ],
    ];
    $rawDocument = $document;
    foreach ($rawDocument['services'] as &$service) {
        unset($service['container_name']);
        $service['labels'] = array_values(array_filter(
            $service['labels'],
            static fn (string $label): bool => ! str_starts_with($label, 'coolify.'),
        ));
    }
    unset($service);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => '3',
        'docker_compose' => Yaml::dump($document, 10),
        'docker_compose_raw' => Yaml::dump($rawDocument, 10),
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://released-v3-web.example.test'],
            'worker' => ['domain' => 'https://released-v3-worker.example.test:4000'],
        ], JSON_THROW_ON_ERROR),
        'docker_compose_location' => '/infra/docker-compose.yml',
        'base_directory' => '/gateway/edge-and-queue',
        'docker_compose_custom_build_command' => null,
        'docker_compose_custom_start_command' => null,
        'fqdn' => null,
        'health_check_enabled' => true,
    ]);
    $application->settings()->update([
        'is_blue_green_deployment_enabled' => true,
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
        'blue_green_replica_count' => 1,
    ]);
    $application = $application->fresh(['settings']);
    $topology = $application->blueGreenComposeTopology()
        ?? throw new RuntimeException('The released-v3 fixture has no Compose topology.');
    $deploymentUuid = 'released-v3-active-blue';
    $fingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::BLUE,
        1,
        1,
        $deploymentUuid,
    );
    $backendPorts = BlueGreenBackendPortInventory::forApplication($application)
        ?? throw new RuntimeException('The released-v3 fixture has no backend inventory.');
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => $deploymentUuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
        'supersession_generation' => 1,
        'destination_fence_epoch' => 1,
        'destination_fence_operation_id' => $deploymentUuid,
        'destination_fence_mutation_sequence' => 1,
        'managed_file_sha256' => str_repeat('c', 64),
        'destination_topology_digest' => $fingerprint->operationTopologyDigest,
        'destination_routing_topology_digest' => $fingerprint->routingTopologyDigest,
        'application_routing_config_digest' => $fingerprint->routingConfigDigest,
    ]);
    $candidateNames = $topology->candidateContainerNames($application, BlueGreenDeploymentColor::BLUE);
    $candidateServices = $topology->candidateComposeServices(BlueGreenDeploymentColor::BLUE);
    $routedId = str_repeat('a', 64);
    $secondaryId = str_repeat('b', 64);
    $alphabeticalSiblingId = str_repeat('d', 64);
    $idsByService = [
        'web-blue' => $routedId,
        'worker-blue' => $secondaryId,
        'aaa_sidecar-blue' => $alphabeticalSiblingId,
    ];
    $inspections = [];
    foreach ($candidateServices as $composeService) {
        $baseService = str_replace('-blue', '', $composeService);
        $containerId = $idsByService[$composeService]
            ?? throw new RuntimeException('The released-v3 fixture has an unexpected candidate service.');
        $containerName = $candidateNames[$baseService]
            ?? throw new RuntimeException('The released-v3 fixture has no candidate container name.');
        ApplicationBlueGreenReplica::query()->create([
            'application_blue_green_deployment_id' => $state->id,
            'application_id' => $application->id,
            'standalone_docker_id' => $destination->id,
            'color' => BlueGreenDeploymentColor::BLUE,
            'replica_index' => 1,
            'deployment_uuid' => $deploymentUuid,
            'routing_revision' => 1,
            'compose_project' => $application->uuid,
            'compose_service' => $composeService,
            'container_name' => $containerName,
            'container_id' => $containerId,
            'health_status' => 'healthy',
            'last_observed_at' => now(),
        ]);
        $inspections[] = BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: 1,
            composeService: $composeService,
            containerName: $containerName,
            dockerId: $containerId,
            status: 'running',
            health: 'healthy',
        );
    }
    $aggregateId = BlueGreenReplicaSet::identityDigest($inspections);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $deploymentUuid,
        'pull_request_id' => 0,
        'commit' => 'released-v3-active-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_server_boot_id' => '11111111-2222-3333-4444-555555555555',
        'blue_green_topology_digest' => $fingerprint->operationTopologyDigest,
        'blue_green_routing_config_digest' => $fingerprint->routingConfigDigest,
        'blue_green_backend_port_inventory' => $backendPorts->serialized,
        'blue_green_supersession_generation' => 1,
        'blue_green_candidate_container_id' => $aggregateId,
    ]);

    $resolver = new ResolveBlueGreenExpectedProxyState;
    $provisional = $resolver->handle($application, $destination, $state)
        ?? throw new RuntimeException('The released-v3 fixture has no provisional state.');
    $target = (new PlanBlueGreenSteadyState)->routingTargetForState(
        $application,
        $destination,
        $state,
        $provisional,
    );
    $configuration = CompileBlueGreenProxyConfiguration::run($application, $destination, $target);
    $state->update([
        'managed_file_sha256' => $configuration->sha256,
        'application_routing_config_digest' => $configuration->routingConfigDigest,
    ]);
    $deployment->update(['blue_green_routing_config_digest' => $configuration->routingConfigDigest]);
    $state = $state->fresh();
    $deployment = $deployment->fresh();
    $canonical = $resolver->handle($application, $destination, $state)
        ?? throw new RuntimeException('The released-v3 fixture has no canonical state.');
    $released = $resolver->releasedV3State($application, $destination, $state)
        ?? throw new RuntimeException('The released-v3 fixture has no released projection.');
    $configuration = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        (new PlanBlueGreenSteadyState)->routingTargetForState(
            $application,
            $destination,
            $state,
            $canonical,
        ),
    );
    if (! BlueGreenProxyState::matches($configuration->state, $canonical)) {
        throw new RuntimeException('The released-v3 fixture canonical state does not compile exactly.');
    }

    return compact(
        'application',
        'server',
        'destination',
        'state',
        'deployment',
        'configuration',
        'canonical',
        'released',
        'aggregateId',
        'routedId',
        'secondaryId',
    ) + [
        'aggregate_id' => $aggregateId,
        'routed_id' => $routedId,
        'secondary_id' => $secondaryId,
        'alphabetical_sibling_id' => $alphabeticalSiblingId,
    ];
}

/**
 * @return array{
 *     application: Application,
 *     server: Server,
 *     destination: StandaloneDocker,
 *     state: ApplicationBlueGreenDeployment,
 *     deployment: ApplicationDeploymentQueue,
 *     canonical: BlueGreenProxyState,
 *     released: BlueGreenProxyState,
 *     legacy_digest: string,
 *     canonical_digest: string,
 *     first_id: string,
 *     second_id: string
 * }
 */
function releasedV2FanOutStateFixture(): array
{
    Storage::fake('ssh-keys');
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
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
        'fqdn' => 'https://released-v2-fanout.example.test',
        'health_check_enabled' => true,
    ]);
    $application->settings()->update([
        'is_blue_green_deployment_enabled' => true,
        'is_container_label_readonly_enabled' => true,
        'blue_green_replica_count' => 2,
    ]);
    $application = $application->fresh(['settings']);
    $deploymentUuid = 'released-v2-fanout-blue';
    $fingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::BLUE,
        1,
        1,
        $deploymentUuid,
    );
    $backendPorts = BlueGreenBackendPortInventory::forApplication($application)
        ?? throw new RuntimeException('The released-v2 fixture has no backend inventory.');
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => $deploymentUuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
        'supersession_generation' => 1,
        'destination_fence_epoch' => 1,
        'destination_fence_operation_id' => $deploymentUuid,
        'destination_fence_mutation_sequence' => 1,
        'managed_file_sha256' => hash('sha256', 'released-v2-fanout-managed-route'),
        'destination_topology_digest' => $fingerprint->operationTopologyDigest,
        'destination_routing_topology_digest' => $fingerprint->routingTopologyDigest,
        'application_routing_config_digest' => $fingerprint->routingConfigDigest,
    ]);
    $replicaSet = new BlueGreenReplicaSet(2);
    $firstId = str_repeat('a', 64);
    $secondId = str_repeat('b', 64);
    $ids = [1 => $firstId, 2 => $secondId];
    $inspections = [];
    foreach ($replicaSet->indexes() as $replicaIndex) {
        $composeService = $replicaSet->serviceName($application->uuid.'-blue', $replicaIndex);
        $containerName = $application->uuid.'-blue-replica-'.$replicaIndex;
        ApplicationBlueGreenReplica::query()->create([
            'application_blue_green_deployment_id' => $state->id,
            'application_id' => $application->id,
            'standalone_docker_id' => $destination->id,
            'color' => BlueGreenDeploymentColor::BLUE,
            'replica_index' => $replicaIndex,
            'deployment_uuid' => $deploymentUuid,
            'routing_revision' => 1,
            'compose_project' => $application->uuid,
            'compose_service' => $composeService,
            'container_name' => $containerName,
            'container_id' => $ids[$replicaIndex],
            'health_status' => 'healthy',
            'last_observed_at' => now(),
        ]);
        $inspections[] = BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $replicaIndex,
            composeService: $composeService,
            containerName: $containerName,
            dockerId: $ids[$replicaIndex],
            status: 'running',
            health: 'healthy',
        );
    }
    $legacyDigest = BlueGreenReplicaSet::identityDigest($inspections);
    $canonicalDigest = $replicaSet->fenceIdentity($inspections);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $deploymentUuid,
        'pull_request_id' => 0,
        'commit' => 'released-v2-fanout-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_server_boot_id' => '11111111-2222-3333-4444-555555555555',
        'blue_green_topology_digest' => $fingerprint->operationTopologyDigest,
        'blue_green_routing_config_digest' => $fingerprint->routingConfigDigest,
        'blue_green_backend_port_inventory' => $backendPorts->serialized,
        'blue_green_supersession_generation' => 1,
        'blue_green_candidate_container_id' => $legacyDigest,
    ]);
    $resolver = new ResolveBlueGreenExpectedProxyState;
    $provisional = $resolver->handle($application, $destination, $state)
        ?? throw new RuntimeException('The released-v2 fixture has no provisional projection.');
    $target = (new PlanBlueGreenSteadyState)->routingTargetForState(
        $application,
        $destination,
        $state,
        $provisional,
    );
    $configuration = CompileBlueGreenProxyConfiguration::run($application, $destination, $target);
    $state->update([
        'managed_file_sha256' => $configuration->sha256,
        'application_routing_config_digest' => $configuration->routingConfigDigest,
    ]);
    $deployment->update(['blue_green_routing_config_digest' => $configuration->routingConfigDigest]);
    $state = $state->fresh();
    $deployment = $deployment->fresh();
    $canonical = $resolver->handle($application, $destination, $state)
        ?? throw new RuntimeException('The released-v2 fixture has no canonical projection.');
    $released = $resolver->releasedV2FanOutState($application, $destination, $state)
        ?? throw new RuntimeException('The released-v2 fixture has no released projection.');
    $configuration = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        (new PlanBlueGreenSteadyState)->routingTargetForState(
            $application,
            $destination,
            $state,
            $canonical,
        ),
    );
    if (! BlueGreenProxyState::matches($configuration->state, $canonical)) {
        throw new RuntimeException('The released-v2 fixture canonical state does not compile exactly.');
    }

    return compact('application', 'server', 'destination', 'state', 'deployment', 'configuration', 'canonical', 'released') + [
        'legacy_digest' => $legacyDigest,
        'canonical_digest' => $canonicalDigest,
        'first_id' => $firstId,
        'second_id' => $secondId,
    ];
}

it('reconstructs exact released v2 fan-out bytes and canonical v4 identity from the durable ledger', function (): void {
    $fixture = releasedV2FanOutStateFixture();
    $released = $fixture['released'];
    $canonical = $fixture['canonical'];

    expect($released->toArray()['magic'])->toBe(BlueGreenProxyState::MAGIC)
        ->and($released->activeContainerName)->toBe($fixture['application']->uuid.'-blue')
        ->and($released->activeContainerId)->toBe($fixture['legacy_digest'])
        ->and($released->activeContainerSet)->toBeNull()
        ->and($released->activeReplicaSet)->toBeNull()
        ->and($canonical->toArray()['magic'])->toBe(BlueGreenProxyState::MAGIC_REPLICA_SET)
        ->and($canonical->activeContainerName)->not->toBe($released->activeContainerName)
        ->and($canonical->activeContainerId)->toBe($fixture['first_id'])
        ->and($canonical->activeReplicaSetDigest)->toBe($fixture['canonical_digest'])
        ->and($canonical->activeReplicaSet?->members)->toHaveCount(2)
        ->and($fixture['canonical_digest'])->not->toBe($fixture['legacy_digest']);

    $fixture['deployment']->update(['blue_green_candidate_container_id' => str_repeat('f', 64)]);
    expect(fn () => (new ResolveBlueGreenExpectedProxyState)->handle(
        $fixture['application'],
        $fixture['destination'],
        $fixture['state'],
    ))->toThrow(BlueGreenDeploymentTransitionException::class);
});

it('discovers only exact released v2 fan-out host bytes during destination attestation', function (): void {
    $fixture = releasedV2FanOutStateFixture();
    $attemptedScripts = [];
    Process::fake(function (PendingProcess $process) use (&$attemptedScripts) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $input = is_string($process->input) ? $process->input : '';
        $attemptedScripts[] = $command."\n".$input;

        return count($attemptedScripts) === 1
            ? Process::result(errorOutput: 'canonical v4 state mismatch', exitCode: 1)
            : Process::result(output: 'coolify-blue-green-destination-state-attested');
    });

    $attested = AttestBlueGreenDestinationState::run(
        $fixture['server'],
        $fixture['application'],
        $fixture['destination'],
        $fixture['state'],
    );

    expect($attested?->serialize())->toBe($fixture['released']->serialize())
        ->and($attemptedScripts)->toHaveCount(2)
        ->and($attemptedScripts[0])->toContain(base64_encode($fixture['canonical']->serialize()))
        ->and($attemptedScripts[1])->toContain(base64_encode($fixture['released']->serialize()));
});

it('migrates released v2 fan-out bytes to canonical v4 with exact live labels and idempotent replay', function (): void {
    $fixture = releasedV2FanOutStateFixture();
    $bootId = '11111111-2222-3333-4444-555555555555';
    $metadataReads = 0;
    $invocations = [];
    Process::fake(function (PendingProcess $process) use ($bootId, $fixture, &$metadataReads, &$invocations) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $input = is_string($process->input) ? $process->input : '';
        $invocation = $command."\n".$input;
        $invocations[] = $invocation;

        return match (true) {
            str_contains($invocation, 'coolify-blue-green-managed-route:present:') => (function () use ($fixture, &$metadataReads) {
                $state = $metadataReads++ === 0 ? $fixture['released'] : $fixture['canonical'];

                return Process::result(
                    output: 'coolify-blue-green-managed-route:present:'
                        .base64_encode($state->serialize())
                        ."\n".$state->managedSha256,
                );
            })(),
            str_contains($invocation, WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT) => Process::result(
                output: WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT,
            ),
            str_contains($invocation, '/proc/sys/kernel/random/boot_id') => Process::result(output: $bootId),
            default => throw new RuntimeException('Unexpected released-v2 fan-out migration command.'),
        };
    });
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($fixture['application']->id, $fixture['destination']->id),
        300,
    );
    expect($lock->get())->toBeTrue();
    $fence = new BlueGreenOperationFence($lock, 300);

    try {
        $first = MigrateBlueGreenReleasedV3ProxyState::run(
            $fixture['server'],
            $fixture['application'],
            $fixture['destination'],
            $fixture['state'],
            $bootId,
            $fence,
        );
        $second = MigrateBlueGreenReleasedV3ProxyState::run(
            $fixture['server'],
            $fixture['application'],
            $fixture['destination'],
            $fixture['state'],
            $bootId,
            $fence,
        );
    } finally {
        $fence->releaseIfOwned();
    }
    $migrationScripts = array_values(array_filter(
        $invocations,
        static fn (string $invocation): bool => str_contains(
            $invocation,
            WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT,
        ),
    ));

    expect($first?->serialize())->toBe($fixture['canonical']->serialize())
        ->and($second?->serialize())->toBe($fixture['canonical']->serialize())
        ->and($migrationScripts)->toHaveCount(2)
        ->and($migrationScripts[0])->toContain(
            base64_encode($fixture['released']->serialize()),
            base64_encode($fixture['canonical']->serialize()),
            'coolify.blueGreen.replicaIndex',
            'coolify.blueGreen.replicaCount',
            $fixture['first_id'],
            $fixture['second_id'],
        );
});

it('converges an exact released v2 fan-out sidecar before steady-state verification', function (): void {
    $fixture = releasedV2FanOutStateFixture();
    $bootId = '11111111-2222-3333-4444-555555555555';
    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken(
        $fixture['deployment']->deployment_uuid,
    );
    $publicAcknowledgement = (new PlanBlueGreenPublicRecovery)->publicAcknowledgementForYaml(
        $fixture['configuration']->yaml,
    );
    InspectBlueGreenContainer::shouldRun()
        ->times(2)
        ->andReturnUsing(static function (Server $_server, $expectation): BlueGreenContainerInspection {
            return new BlueGreenContainerInspection(
                exists: true,
                dockerId: $expectation->dockerId,
                status: 'running',
                health: 'healthy',
            );
        });
    $invocations = [];
    Process::fake(function (PendingProcess $process) use (
        $bootId,
        $fixture,
        $publicAcknowledgement,
        $releaseProof,
        &$invocations,
    ) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $input = is_string($process->input) ? $process->input : '';
        $invocation = $command."\n".$input;
        $invocations[] = $invocation;

        return match (true) {
            str_contains($invocation, '__coolify_blue_green_probe') => Process::result(
                output: "HTTP/1.1 200 OK\r\n"
                    .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER
                    .": {$publicAcknowledgement}\r\n"
                    .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER
                    .": {$releaseProof}\r\n\r\n",
            ),
            str_contains($invocation, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($fixture['released']->serialize())
                    ."\n".$fixture['released']->managedSha256,
            ),
            str_contains($invocation, WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT) => Process::result(
                output: WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT,
            ),
            str_contains($invocation, 'repair_outcome=') => Process::result(
                output: WriteBlueGreenProxyConfiguration::REPAIR_HEALTHY_OUTPUT,
            ),
            str_contains($invocation, 'coolify-blue-green-destination-state-attested') => Process::result(
                output: 'coolify-blue-green-destination-state-attested',
            ),
            str_contains($invocation, '{{json .Config.Env}}') => Process::result(
                output: json_encode([
                    'COOLIFY_DEPLOYMENT_RELEASE_PROOF='.$releaseProof,
                ], JSON_THROW_ON_ERROR),
            ),
            str_contains($invocation, '/proc/sys/kernel/random/boot_id') => Process::result(output: $bootId),
            default => throw new RuntimeException('Unexpected released-v2 steady-repair command.'),
        };
    });

    $result = RepairBlueGreenSteadyState::run($fixture['state']);
    $migrationIndex = collect($invocations)->search(
        static fn (string $invocation): bool => str_contains(
            $invocation,
            WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT,
        ),
    );
    $repairIndex = collect($invocations)->search(
        static fn (string $invocation): bool => str_contains($invocation, 'repair_outcome='),
    );

    expect($result->outcome)->toBe(BlueGreenSteadyStateRepairResult::HEALTHY, $result->message)
        ->and($migrationIndex)->toBeInt()
        ->and($repairIndex)->toBeInt()
        ->and($migrationIndex)->toBeLessThan($repairIndex);
});

it('reconstructs only the exact released v3 aggregate scalar and routed member from the durable ledger', function (): void {
    $fixture = releasedV3StateFixture();
    $canonical = $fixture['canonical'];
    $released = $fixture['released'];
    $replicaComposeServices = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $fixture['state']->id)
        ->orderBy('compose_service')
        ->pluck('compose_service')
        ->all();
    $canonicalMembers = $canonical->activeContainerSet?->toArray();
    $releasedMembers = $released->activeContainerSet?->toArray();
    $canonicalRoutedOffset = collect($canonicalMembers)->search(
        static fn (array $member): bool => $member['name'] === $canonical->activeContainerName,
    );
    $canonicalSecondaryOffset = collect($canonicalMembers)->search(
        static fn (array $member): bool => $member['name'] !== $canonical->activeContainerName,
    );

    expect($canonical->activeReplicaSet)->toBeNull()
        ->and($released->activeReplicaSet)->toBeNull()
        ->and($canonical->activeContainerId)->toBe($fixture['routed_id'])
        ->and($released->activeContainerId)->toBe($fixture['aggregate_id'])
        ->and($canonicalMembers)->toHaveCount(2)
        ->and($releasedMembers)->toHaveCount(2)
        ->and($replicaComposeServices[0])->toBe('aaa_sidecar-blue')
        ->and($canonicalRoutedOffset)->toBeInt()
        ->and($canonicalSecondaryOffset)->toBeInt()
        ->and($canonicalMembers[$canonicalRoutedOffset]['id'])->toBe($fixture['routed_id'])
        ->and($releasedMembers[$canonicalRoutedOffset]['id'])->toBe($fixture['aggregate_id'])
        ->and($canonicalMembers[$canonicalSecondaryOffset]['id'])->toBe($fixture['secondary_id'])
        ->and($releasedMembers[$canonicalSecondaryOffset]['id'])->toBe($fixture['secondary_id']);

    $releasedRecord = $released->toArray();
    $releasedRecord['active_container_id'] = $canonical->activeContainerId;
    $releasedRecord['active_container_set'][$canonicalRoutedOffset]['id'] = $canonicalMembers[$canonicalRoutedOffset]['id'];
    expect($releasedRecord)->toBe($canonical->toArray());
});

it('discovers exact released v3 host bytes before the lifecycle lock without weakening foreign-state attestation', function (): void {
    $fixture = releasedV3StateFixture();
    $attemptedScripts = [];
    Process::fake(function (PendingProcess $process) use (&$attemptedScripts) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $input = is_string($process->input) ? $process->input : '';
        $attemptedScripts[] = $command."\n".$input;

        return count($attemptedScripts) === 1
            ? Process::result(errorOutput: 'canonical state mismatch', exitCode: 1)
            : Process::result(output: 'coolify-blue-green-destination-state-attested');
    });

    $attested = AttestBlueGreenDestinationState::run(
        $fixture['server'],
        $fixture['application'],
        $fixture['destination'],
        $fixture['state'],
    );

    expect($attested?->serialize())->toBe($fixture['released']->serialize())
        ->and($attemptedScripts)->toHaveCount(2)
        ->and($attemptedScripts[0])->toContain(base64_encode($fixture['canonical']->serialize()))
        ->and($attemptedScripts[1])->toContain(base64_encode($fixture['released']->serialize()));

    Process::fake(['*' => Process::result(errorOutput: 'foreign state mismatch', exitCode: 1)]);
    expect(fn () => AttestBlueGreenDestinationState::run(
        $fixture['server'],
        $fixture['application'],
        $fixture['destination'],
        $fixture['state'],
    ))->toThrow(RuntimeException::class);
});

it('migrates the ledger-proven released v3 state only while holding the destination lifecycle fence', function (): void {
    $fixture = releasedV3StateFixture();
    $bootId = '11111111-2222-3333-4444-555555555555';
    $invocations = [];
    Process::fake(function (PendingProcess $process) use ($bootId, $fixture, &$invocations) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $input = is_string($process->input) ? $process->input : '';
        $invocation = $command."\n".$input;
        $invocations[] = $invocation;

        return match (true) {
            str_contains($invocation, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($fixture['released']->serialize())
                    ."\n".$fixture['released']->managedSha256,
            ),
            str_contains($invocation, WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT) => Process::result(
                output: WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT,
            ),
            str_contains($invocation, '/proc/sys/kernel/random/boot_id') => Process::result(output: $bootId),
            default => throw new RuntimeException('Unexpected released-v3 migration command.'),
        };
    });
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($fixture['application']->id, $fixture['destination']->id),
        300,
    );
    expect($lock->get())->toBeTrue();
    $fence = new BlueGreenOperationFence($lock, 300);

    try {
        $migrated = MigrateBlueGreenReleasedV3ProxyState::run(
            $fixture['server'],
            $fixture['application'],
            $fixture['destination'],
            $fixture['state'],
            $bootId,
            $fence,
        );
    } finally {
        $fence->releaseIfOwned();
    }

    expect($migrated?->serialize())->toBe($fixture['canonical']->serialize())
        ->and($invocations)->toHaveCount(3)
        ->and($invocations[1])->toContain('coolify-blue-green-managed-route:present:')
        ->and($invocations[2])->toContain(
            base64_encode($fixture['released']->serialize()),
            base64_encode($fixture['canonical']->serialize()),
            'aaa_sidecar-blue',
            'web-blue',
            'worker-blue',
            $fixture['alphabetical_sibling_id'],
            $fixture['routed_id'],
            $fixture['secondary_id'],
        );
});

it('re-attests every durable replica before accepting an already canonical released v3 sidecar', function (): void {
    $fixture = releasedV3StateFixture();
    $bootId = '11111111-2222-3333-4444-555555555555';
    $invocations = [];
    Process::fake(function (PendingProcess $process) use ($bootId, $fixture, &$invocations) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $input = is_string($process->input) ? $process->input : '';
        $invocation = $command."\n".$input;
        $invocations[] = $invocation;

        return match (true) {
            str_contains($invocation, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($fixture['canonical']->serialize())
                    ."\n".$fixture['canonical']->managedSha256,
            ),
            str_contains($invocation, WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT) => Process::result(
                output: WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT,
            ),
            str_contains($invocation, '/proc/sys/kernel/random/boot_id') => Process::result(output: $bootId),
            default => throw new RuntimeException('Unexpected canonical released-v3 migration command.'),
        };
    });
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($fixture['application']->id, $fixture['destination']->id),
        300,
    );
    expect($lock->get())->toBeTrue();
    $fence = new BlueGreenOperationFence($lock, 300);

    try {
        $migrated = MigrateBlueGreenReleasedV3ProxyState::run(
            $fixture['server'],
            $fixture['application'],
            $fixture['destination'],
            $fixture['state'],
            $bootId,
            $fence,
        );
    } finally {
        $fence->releaseIfOwned();
    }

    expect($migrated?->serialize())->toBe($fixture['canonical']->serialize())
        ->and($invocations)->toHaveCount(3)
        ->and($invocations[2])->toContain(
            WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT,
            'aaa_sidecar-blue',
            'web-blue',
            'worker-blue',
            $fixture['alphabetical_sibling_id'],
            $fixture['routed_id'],
            $fixture['secondary_id'],
        );
});

it('converges an exact released v3 sidecar before steady-state managed-route and public verification', function (): void {
    $fixture = releasedV3StateFixture();
    $bootId = '11111111-2222-3333-4444-555555555555';
    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken(
        $fixture['deployment']->deployment_uuid,
    );
    $publicAcknowledgement = (new PlanBlueGreenPublicRecovery)->publicAcknowledgementForYaml(
        $fixture['configuration']->yaml,
    );
    InspectBlueGreenContainer::shouldRun()
        ->times(2)
        ->andReturnUsing(static function (Server $_server, $expectation): BlueGreenContainerInspection {
            return new BlueGreenContainerInspection(
                exists: true,
                dockerId: $expectation->dockerId,
                status: 'running',
                health: 'healthy',
            );
        });
    $invocations = [];
    Process::fake(function (PendingProcess $process) use (
        $bootId,
        $fixture,
        $publicAcknowledgement,
        $releaseProof,
        &$invocations,
    ) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $input = is_string($process->input) ? $process->input : '';
        $invocation = $command."\n".$input;
        $invocations[] = $invocation;

        return match (true) {
            str_contains($invocation, '__coolify_blue_green_probe') => Process::result(
                output: "HTTP/1.1 200 OK\r\n"
                    .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER
                    .": {$publicAcknowledgement}\r\n"
                    .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER
                    .": {$releaseProof}\r\n\r\n",
            ),
            str_contains($invocation, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($fixture['released']->serialize())
                    ."\n".$fixture['released']->managedSha256,
            ),
            str_contains($invocation, WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT) => Process::result(
                output: WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT,
            ),
            str_contains($invocation, 'repair_outcome=') => Process::result(
                output: WriteBlueGreenProxyConfiguration::REPAIR_HEALTHY_OUTPUT,
            ),
            str_contains($invocation, 'coolify-blue-green-destination-state-attested') => Process::result(
                output: 'coolify-blue-green-destination-state-attested',
            ),
            str_contains($invocation, '{{json .Config.Env}}') => Process::result(
                output: json_encode([
                    'COOLIFY_DEPLOYMENT_RELEASE_PROOF='.$releaseProof,
                ], JSON_THROW_ON_ERROR),
            ),
            str_contains($invocation, '/proc/sys/kernel/random/boot_id') => Process::result(output: $bootId),
            default => throw new RuntimeException('Unexpected released-v3 steady-repair command.'),
        };
    });

    $result = RepairBlueGreenSteadyState::run($fixture['state']);
    $migrationIndex = collect($invocations)->search(
        static fn (string $invocation): bool => str_contains(
            $invocation,
            WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT,
        ),
    );
    $repairIndex = collect($invocations)->search(
        static fn (string $invocation): bool => str_contains($invocation, 'repair_outcome='),
    );

    expect($result->outcome)->toBe(BlueGreenSteadyStateRepairResult::HEALTHY, $result->message)
        ->and($migrationIndex)->toBeInt()
        ->and($repairIndex)->toBeInt()
        ->and($migrationIndex)->toBeLessThan($repairIndex);
});

it('migrates before claim snapshots so a crash before routing mutation reconstructs a canonical rollback CAS', function (): void {
    $fixture = releasedV3StateFixture();
    $bootId = '11111111-2222-3333-4444-555555555555';
    $candidate = ApplicationDeploymentQueue::query()->create([
        'application_id' => $fixture['application']->id,
        'application_name' => $fixture['application']->name,
        'server_id' => $fixture['server']->id,
        'server_name' => $fixture['server']->name,
        'destination_id' => $fixture['destination']->id,
        'deployment_uuid' => 'released-v3-candidate-green',
        'pull_request_id' => 0,
        'commit' => 'released-v3-candidate-commit',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'only_this_server' => true,
    ]);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $fixture['application'],
        deployment: $candidate,
        destination: $fixture['destination'],
        server: $fixture['server'],
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    (new ReflectionProperty($lifecycle, 'enabled'))->setValue($lifecycle, true);
    (new ReflectionProperty($lifecycle, 'serverBootId'))->setValue($lifecycle, $bootId);
    (new ReflectionProperty($lifecycle, 'destinationState'))->setValue($lifecycle, $fixture['released']);
    (new ReflectionProperty($lifecycle, 'previousActiveColor'))->setValue(
        $lifecycle,
        BlueGreenDeploymentColor::BLUE,
    );
    (new ReflectionProperty($lifecycle, 'previousReplicaInspections'))->setValue($lifecycle, [
        BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: 1,
            composeService: 'web-blue',
            containerName: $fixture['canonical']->activeContainerName,
            dockerId: $fixture['routed_id'],
            status: 'running',
            health: 'healthy',
        ),
    ]);
    (new ReflectionProperty($lifecycle, 'previousContainerExpectation'))->setValue(
        $lifecycle,
        new BlueGreenContainerExpectation(
            name: $fixture['canonical']->activeContainerName,
            dockerId: $fixture['routed_id'],
            applicationId: $fixture['application']->id,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $fixture['deployment']->deployment_uuid,
            color: BlueGreenDeploymentColor::BLUE,
            routingRevision: 1,
        ),
    );
    (new ReflectionProperty($lifecycle, 'previousSetFenceIdentity'))->setValue(
        $lifecycle,
        $fixture['routed_id'],
    );
    $migrationObservedBeforeClaim = false;
    $invocations = [];
    Process::fake(function (PendingProcess $process) use (
        $bootId,
        $fixture,
        &$migrationObservedBeforeClaim,
        &$invocations,
    ) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $input = is_string($process->input) ? $process->input : '';
        $invocation = $command."\n".$input;
        $invocations[] = $invocation;

        return match (true) {
            str_contains($invocation, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($fixture['released']->serialize())
                    ."\n".$fixture['released']->managedSha256,
            ),
            str_contains($invocation, WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT) => (function () use (
                $fixture,
                &$migrationObservedBeforeClaim,
            ) {
                $stateAtMigration = ApplicationBlueGreenDeployment::query()->findOrFail($fixture['state']->id);
                $migrationObservedBeforeClaim = $stateAtMigration->phase === BlueGreenDeploymentPhase::IDLE
                    && $stateAtMigration->operation_deployment_uuid === null
                    && $stateAtMigration->operation_previous_proxy_state === null;

                return Process::result(
                    output: WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT,
                );
            })(),
            str_contains($invocation, '/proc/sys/kernel/random/boot_id') => Process::result(output: $bootId),
            default => throw new RuntimeException('Unexpected released-v3 claim command.'),
        };
    });

    try {
        $claim = $lifecycle->claim();
    } finally {
        $lifecycle->release();
    }
    $claimedState = $fixture['state']->fresh();
    $recovery = ReconstructBlueGreenDeploymentRecovery::run($claimedState);

    expect($claim)->not->toBeNull()
        ->and($migrationObservedBeforeClaim)->toBeTrue()
        ->and($claimedState->operation_routing_mutated_at)->toBeNull()
        ->and($claimedState->operation_previous_proxy_state)->toBe($fixture['canonical']->serialize())
        ->and($claimedState->operation_previous_proxy_state_sha256)
        ->toBe(hash('sha256', $fixture['canonical']->serialize()))
        ->and($recovery->routingMutationRecorded)->toBeFalse()
        ->and($recovery->rollbackKey->expectedState?->serialize())->toBe($fixture['canonical']->serialize())
        ->and($recovery->currentDestinationState?->serialize())->toBe($fixture['canonical']->serialize())
        ->and($recovery->rollbackKey->expectedState?->activeContainerId)->toBe($fixture['routed_id'])
        ->and($recovery->rollbackKey->expectedState?->serialize())
        ->not->toBe($fixture['released']->serialize());
});
