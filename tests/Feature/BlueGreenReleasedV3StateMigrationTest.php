<?php

use App\Actions\Application\BlueGreen\AttestBlueGreenDestinationState;
use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentRecoveryOperation;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\BlueGreenSteadyStateRepairResult;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\MigrateBlueGreenReleasedV3ProxyState;
use App\Actions\Application\BlueGreen\PlanBlueGreenForwardRecovery;
use App\Actions\Application\BlueGreen\PlanBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\PlanBlueGreenSteadyState;
use App\Actions\Application\BlueGreen\ReconstructBlueGreenDeploymentRecovery;
use App\Actions\Application\BlueGreen\RehydrateBlueGreenDestinationRoutingTopologyDigest;
use App\Actions\Application\BlueGreen\RepairBlueGreenSteadyState;
use App\Actions\Application\BlueGreen\ResolveBlueGreenExpectedProxyState;
use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyRollbackKey;
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
use Illuminate\Foundation\Testing\DatabaseTruncation as LaravelDatabaseTruncation;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

trait TruncatesReleasedV3StateDatabase
{
    use LaravelDatabaseTruncation {
        truncateDatabaseTables as private truncatePersistentDatabaseTables;
    }

    protected function truncateDatabaseTables(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Artisan::call('migrate:fresh', ['--no-interaction' => true]);

            return;
        }

        $this->truncatePersistentDatabaseTables();
    }
}

// Not RefreshDatabase: its per-test wrapping transaction would keep
// DB::transactionLevel() at 1 on the PostgreSQL lane, and the legacy
// route network attestation these tests execute for real refuses to run
// inside a transaction. DatabaseTruncation preserves transaction level 0
// without rebuilding the entire persistent schema around every test. The
// file-local trait retains migrate:fresh for in-memory SQLite because that
// schema is discarded whenever Laravel rebuilds the test application.
uses(TruncatesReleasedV3StateDatabase::class);

beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    config(['constants.ssh.mux_enabled' => false]);
});

afterEach(function (): void {
    if (DB::getDriverName() !== 'sqlite') {
        $this->truncateDatabaseTables();
    }
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
    $released = $resolver->releasedV3State($application, $destination, $state, $canonical)
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
    $released = $resolver->releasedV2FanOutState($application, $destination, $state, $canonical)
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
    if (! BlueGreenProxyState::matches($configuration->state, $released)) {
        throw new RuntimeException('The released-v2 fixture rollback-readable state does not compile exactly.');
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

it('keeps managed-route writes rollback-readable while retaining canonical v4 internally', function (): void {
    $fanOut = releasedV2FanOutStateFixture();
    $coRolled = releasedV3StateFixture();

    expect($fanOut['canonical']->toArray()['magic'])->toBe(BlueGreenProxyState::MAGIC_REPLICA_SET)
        ->and($fanOut['configuration']->state->toArray()['magic'])->toBe(BlueGreenProxyState::MAGIC)
        ->and($fanOut['configuration']->state->serialize())->toBe($fanOut['released']->serialize())
        ->and($coRolled['configuration']->state->toArray()['magic'])->toBe(BlueGreenProxyState::MAGIC_SET)
        ->and($coRolled['configuration']->state->serialize())->not->toContain(BlueGreenProxyState::MAGIC_REPLICA_SET);
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

it('attests released v2 fan-out bytes without rewriting the sidecar and returns the exact released state', function (): void {
    $fixture = releasedV2FanOutStateFixture();
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
            str_contains($invocation, '/proc/sys/kernel/random/boot_id') => Process::result(output: $bootId),
            default => throw new RuntimeException('Unexpected released-v2 fan-out attestation command.'),
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

    expect($first?->serialize())->toBe($fixture['released']->serialize())
        ->and($second?->serialize())->toBe($fixture['released']->serialize())
        ->and(collect($invocations)->contains(
            static fn (string $invocation): bool => str_contains($invocation, WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT),
        ))->toBeFalse()
        ->and(collect($invocations)->contains(
            static fn (string $invocation): bool => str_contains($invocation, base64_encode($fixture['canonical']->serialize())),
        ))->toBeFalse();
});

it('derives the released fan-out projection from one immutable canonical resolution', function (): void {
    $fixture = releasedV2FanOutStateFixture();
    ApplicationDeploymentQueue::query()->delete();
    $resolver = new ResolveBlueGreenExpectedProxyState;

    expect(fn () => $resolver->handle($fixture['application'], $fixture['destination'], $fixture['state']))
        ->toThrow(BlueGreenDeploymentTransitionException::class);
    expect($resolver->releasedV2FanOutState(
        $fixture['application'],
        $fixture['destination'],
        $fixture['state'],
        $fixture['canonical'],
    )?->serialize())->toBe($fixture['released']->serialize());
});

it('derives the released v3 projection from one immutable canonical resolution', function (): void {
    $fixture = releasedV3StateFixture();
    ApplicationBlueGreenReplica::query()->delete();
    $resolver = new ResolveBlueGreenExpectedProxyState;

    expect(fn () => $resolver->handle($fixture['application'], $fixture['destination'], $fixture['state']))
        ->toThrow(BlueGreenDeploymentTransitionException::class);
    expect($resolver->releasedV3State(
        $fixture['application'],
        $fixture['destination'],
        $fixture['state'],
        $fixture['canonical'],
    )?->serialize())->toBe($fixture['released']->serialize());
});

it('plans forward recovery against the exact released v3 projection', function (): void {
    $fixture = releasedV3StateFixture();
    $topology = $fixture['application']->blueGreenComposeTopology()
        ?? throw new RuntimeException('The released-v3 forward-recovery fixture has no Compose topology.');
    $state = $fixture['state'];
    $deployment = $fixture['deployment'];
    $canonical = $fixture['canonical'];
    $compatible = $fixture['released'];

    expect($compatible->managedSha256)->toBe($canonical->managedSha256)
        ->and($compatible->activeContainerId)->not->toBe($canonical->activeContainerId);

    $candidateReplicas = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $state->id)
        ->where('deployment_uuid', $deployment->deployment_uuid)
        ->orderBy('compose_service')
        ->get()
        ->map(static fn (ApplicationBlueGreenReplica $replica): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: (int) $replica->replica_index,
            composeService: (string) $replica->compose_service,
            containerName: (string) $replica->container_name,
            dockerId: (string) $replica->container_id,
            status: 'running',
            health: 'healthy',
        ))
        ->all();
    $backendPortInventory = BlueGreenBackendPortInventory::fromSerialized(
        $deployment->blue_green_backend_port_inventory,
    );
    $claim = new BlueGreenDeploymentClaim(
        stateId: $state->id,
        applicationId: $fixture['application']->id,
        standaloneDockerId: $fixture['destination']->id,
        pendingColor: BlueGreenDeploymentColor::BLUE,
        previousActiveColor: BlueGreenDeploymentColor::GREEN,
        deploymentUuid: $deployment->deployment_uuid,
        expectedRoutingRevision: 1,
        destinationFenceEpoch: 1,
        serverBootId: '11111111-2222-3333-4444-555555555555',
        operationTopologyDigest: $deployment->blue_green_topology_digest,
        routingTopologyDigest: $state->destination_routing_topology_digest,
        routingConfigDigest: $state->application_routing_config_digest,
        backendPortInventory: $backendPortInventory,
        drainBackendPortInventory: $backendPortInventory,
        supersessionGeneration: 1,
        legacyContainerName: null,
        replicaCount: 1,
        candidateContainerName: $canonical->activeContainerName,
        rollbackManagedFilename: $compatible->managedFilename,
        candidateContainerNames: $topology->candidateContainerNames(
            $fixture['application'],
            BlueGreenDeploymentColor::BLUE,
        ),
    );
    $operation = new BlueGreenDeploymentRecoveryOperation(
        claim: $claim,
        application: $fixture['application'],
        destination: $fixture['destination'],
        server: $fixture['server'],
        deployment: $deployment,
        previousContainer: new BlueGreenContainerExpectation(
            name: $fixture['application']->uuid.'-green',
            dockerId: str_repeat('e', 64),
            applicationId: $fixture['application']->id,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: 'released-v3-previous-green',
            color: BlueGreenDeploymentColor::GREEN,
            routingRevision: 1,
        ),
        legacyRoutingSnapshot: null,
        candidateContainer: new BlueGreenContainerExpectation(
            name: $canonical->activeContainerName,
            dockerId: $canonical->activeContainerId,
            applicationId: $fixture['application']->id,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $deployment->deployment_uuid,
            color: BlueGreenDeploymentColor::BLUE,
            routingRevision: 1,
        ),
        rollbackKey: new BlueGreenProxyRollbackKey(
            operationId: $deployment->deployment_uuid,
            expectedState: null,
            replacementState: $compatible,
        ),
        currentDestinationState: $compatible,
        recoveredPhase: BlueGreenDeploymentPhase::SWITCHING,
        routingMutationRecorded: true,
        wasFinalized: false,
        candidateSetFenceIdentity: $canonical->activeContainerId,
    );

    $plan = PlanBlueGreenForwardRecovery::run($operation, $candidateReplicas);

    expect($plan->configuration->state->serialize())->toBe($compatible->serialize())
        ->and($plan->configuration->state->serialize())->not->toBe($canonical->serialize());
});

it('repairs a released v2 fan-out destination against the exact released sidecar bytes', function (): void {
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
    $repairIndex = collect($invocations)->search(
        static fn (string $invocation): bool => str_contains($invocation, 'repair_outcome='),
    );

    expect($result->outcome)->toBe(BlueGreenSteadyStateRepairResult::HEALTHY, $result->message)
        ->and($repairIndex)->toBeInt()
        ->and($invocations[$repairIndex])->toContain(base64_encode($fixture['released']->serialize()))
        ->and(collect($invocations)->contains(
            static fn (string $invocation): bool => str_contains($invocation, WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT),
        ))->toBeFalse()
        ->and(collect($invocations)->contains(
            static fn (string $invocation): bool => str_contains($invocation, base64_encode($fixture['canonical']->serialize())),
        ))->toBeFalse();
});

it('rehydrates a released v2 fan-out routing-topology digest without touching sidecar bytes', function (): void {
    $fixture = releasedV2FanOutStateFixture();
    $fixture['state']->update(['destination_routing_topology_digest' => null]);
    $state = $fixture['state']->fresh();
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
    $networkProof = collect($fixture['canonical']->activeContainerIdentities())
        ->map(static fn (array $identity): string => 'coolify-blue-green-route-network-proof:'
            .$identity['id']."\t".json_encode([$fixture['destination']->network => []], JSON_THROW_ON_ERROR))
        ->implode("\n");
    $invocations = [];
    Process::fake(function (PendingProcess $process) use (
        $bootId,
        $fixture,
        $networkProof,
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
            str_contains($invocation, 'coolify-blue-green-route-network-proof:') => Process::result(output: $networkProof),
            str_contains($invocation, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($fixture['released']->serialize())
                    ."\n".$fixture['released']->managedSha256,
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
            default => throw new RuntimeException('Unexpected released-v2 rehydrating steady-repair command.'),
        };
    });

    $rehydrated = RehydrateBlueGreenDestinationRoutingTopologyDigest::run($state);
    $expectedDigest = (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor(
        $fixture['application']->fresh(['settings']),
        $fixture['destination'],
    );
    $networkProofInvocation = collect($invocations)->first(
        static fn (string $invocation): bool => str_contains($invocation, 'coolify-blue-green-route-network-proof:'),
    );

    expect($rehydrated->destination_routing_topology_digest)->toBe($expectedDigest)
        ->and($fixture['state']->fresh()->destination_routing_topology_digest)->toBe($expectedDigest)
        ->and($networkProofInvocation)->toBeString()
        ->and($networkProofInvocation)->toContain($fixture['first_id'], $fixture['second_id'])
        ->and(collect($invocations)->contains(
            static fn (string $invocation): bool => str_contains($invocation, WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT),
        ))->toBeFalse()
        ->and(collect($invocations)->contains(
            static fn (string $invocation): bool => str_contains($invocation, base64_encode($fixture['canonical']->serialize())),
        ))->toBeFalse();
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

it('attests the ledger-proven released v3 state without rewriting sidecar bytes while holding the lifecycle fence', function (): void {
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
            str_contains($invocation, '/proc/sys/kernel/random/boot_id') => Process::result(output: $bootId),
            default => throw new RuntimeException('Unexpected released-v3 attestation command.'),
        };
    });
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($fixture['application']->id, $fixture['destination']->id),
        300,
    );
    expect($lock->get())->toBeTrue();
    $fence = new BlueGreenOperationFence($lock, 300);

    try {
        $attested = MigrateBlueGreenReleasedV3ProxyState::run(
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

    expect($attested?->serialize())->toBe($fixture['released']->serialize())
        ->and($invocations)->toHaveCount(2)
        ->and($invocations[1])->toContain('coolify-blue-green-managed-route:present:')
        ->and(collect($invocations)->contains(
            static fn (string $invocation): bool => str_contains($invocation, WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT),
        ))->toBeFalse()
        ->and(collect($invocations)->contains(
            static fn (string $invocation): bool => str_contains($invocation, base64_encode($fixture['canonical']->serialize())),
        ))->toBeFalse();
});

it('returns an already canonical released v3 destination only after live sidecar attestation', function (): void {
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
            str_contains($invocation, '/proc/sys/kernel/random/boot_id') => Process::result(output: $bootId),
            default => throw new RuntimeException('Unexpected canonical released-v3 attestation command.'),
        };
    });
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($fixture['application']->id, $fixture['destination']->id),
        300,
    );
    expect($lock->get())->toBeTrue();
    $fence = new BlueGreenOperationFence($lock, 300);

    try {
        $attested = MigrateBlueGreenReleasedV3ProxyState::run(
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

    expect($attested?->serialize())->toBe($fixture['canonical']->serialize())
        ->and(collect($invocations)->contains(
            static fn (string $invocation): bool => str_contains($invocation, 'coolify-blue-green-managed-route:present:'),
        ))->toBeTrue()
        ->and(collect($invocations)->contains(
            static fn (string $invocation): bool => str_contains($invocation, WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT),
        ))->toBeFalse();
});

it('refuses an already-canonical verdict when the live sidecar is neither released nor canonical', function (): void {
    $fixture = releasedV3StateFixture();
    $bootId = '11111111-2222-3333-4444-555555555555';
    $foreign = $fixture['canonical']->withDestinationFenceEpoch(
        $fixture['canonical']->destinationFenceEpoch + 1,
    );
    Process::fake(function (PendingProcess $process) use ($bootId, $foreign) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $input = is_string($process->input) ? $process->input : '';
        $invocation = $command."\n".$input;

        return match (true) {
            str_contains($invocation, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($foreign->serialize())
                    ."\n".$foreign->managedSha256,
            ),
            str_contains($invocation, '/proc/sys/kernel/random/boot_id') => Process::result(output: $bootId),
            default => throw new RuntimeException('Unexpected foreign released-v3 attestation command.'),
        };
    });
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($fixture['application']->id, $fixture['destination']->id),
        300,
    );
    expect($lock->get())->toBeTrue();
    $fence = new BlueGreenOperationFence($lock, 300);

    try {
        expect(fn () => MigrateBlueGreenReleasedV3ProxyState::run(
            $fixture['server'],
            $fixture['application'],
            $fixture['destination'],
            $fixture['state'],
            $bootId,
            $fence,
        ))->toThrow(BlueGreenDeploymentTransitionException::class);
    } finally {
        $fence->releaseIfOwned();
    }
});

it('refuses an unattested canonical verdict when no released projection exists', function (): void {
    $fixture = releasedV3StateFixture();
    $fixture['deployment']->update([
        'blue_green_candidate_container_id' => $fixture['routed_id'],
    ]);
    $canonical = ResolveBlueGreenExpectedProxyState::run(
        $fixture['application'],
        $fixture['destination'],
        $fixture['state'],
    ) ?? throw new RuntimeException('The canonical-only fixture has no expected state.');
    expect((new ResolveBlueGreenExpectedProxyState)->releasedV3State(
        $fixture['application'],
        $fixture['destination'],
        $fixture['state'],
        $canonical,
    ))->toBeNull();

    $bootId = '11111111-2222-3333-4444-555555555555';
    $foreign = $canonical->withDestinationFenceEpoch($canonical->destinationFenceEpoch + 1);
    Process::fake(function (PendingProcess $process) use ($bootId, $foreign) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $input = is_string($process->input) ? $process->input : '';
        $invocation = $command."\n".$input;

        return match (true) {
            str_contains($invocation, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($foreign->serialize())
                    ."\n".$foreign->managedSha256,
            ),
            str_contains($invocation, '/proc/sys/kernel/random/boot_id') => Process::result(output: $bootId),
            default => throw new RuntimeException('Unexpected canonical-only attestation command.'),
        };
    });
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($fixture['application']->id, $fixture['destination']->id),
        300,
    );
    expect($lock->get())->toBeTrue();
    $fence = new BlueGreenOperationFence($lock, 300);

    try {
        expect(fn () => MigrateBlueGreenReleasedV3ProxyState::run(
            $fixture['server'],
            $fixture['application'],
            $fixture['destination'],
            $fixture['state'],
            $bootId,
            $fence,
        ))->toThrow(BlueGreenDeploymentTransitionException::class);
    } finally {
        $fence->releaseIfOwned();
    }
});

it('rehydrates a released v3 null routing-topology digest directly while leaving the sidecar byte-identical', function (): void {
    $fixture = releasedV3StateFixture();
    $fixture['state']->update(['destination_routing_topology_digest' => null]);
    $state = $fixture['state']->fresh();
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
    $networkProof = collect($fixture['canonical']->activeContainerIdentities())
        ->map(static fn (array $identity): string => 'coolify-blue-green-route-network-proof:'
            .$identity['id']."\t".json_encode([$fixture['destination']->network => []], JSON_THROW_ON_ERROR))
        ->implode("\n");
    $invocations = [];
    Process::fake(function (PendingProcess $process) use (
        $bootId,
        $fixture,
        $networkProof,
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
            str_contains($invocation, 'coolify-blue-green-route-network-proof:') => Process::result(output: $networkProof),
            str_contains($invocation, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($fixture['released']->serialize())
                    ."\n".$fixture['released']->managedSha256,
            ),
            str_contains($invocation, '{{json .Config.Env}}') => Process::result(
                output: json_encode([
                    'COOLIFY_DEPLOYMENT_RELEASE_PROOF='.$releaseProof,
                ], JSON_THROW_ON_ERROR),
            ),
            str_contains($invocation, '/proc/sys/kernel/random/boot_id') => Process::result(output: $bootId),
            default => throw new RuntimeException('Unexpected released-v3 rehydration command.'),
        };
    });

    $rehydrated = RehydrateBlueGreenDestinationRoutingTopologyDigest::run($state);
    $expectedDigest = (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor(
        $fixture['application']->fresh(['settings']),
        $fixture['destination'],
    );
    $networkProofInvocation = collect($invocations)->first(
        static fn (string $invocation): bool => str_contains($invocation, 'coolify-blue-green-route-network-proof:'),
    );

    expect($rehydrated->destination_routing_topology_digest)->toBe($expectedDigest)
        ->and($fixture['state']->fresh()->destination_routing_topology_digest)->toBe($expectedDigest)
        ->and($networkProofInvocation)->toBeString()
        ->and($networkProofInvocation)->toContain($fixture['routed_id'], $fixture['secondary_id'])
        ->and(collect($invocations)->contains(
            static fn (string $invocation): bool => str_contains($invocation, WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT),
        ))->toBeFalse()
        ->and(collect($invocations)->contains(
            static fn (string $invocation): bool => str_contains($invocation, base64_encode($fixture['canonical']->serialize())),
        ))->toBeFalse();
});

it('repairs a released v3 destination without serializing v4 before public verification', function (): void {
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
    $repairIndex = collect($invocations)->search(
        static fn (string $invocation): bool => str_contains($invocation, 'repair_outcome='),
    );

    expect($result->outcome)->toBe(BlueGreenSteadyStateRepairResult::HEALTHY, $result->message)
        ->and($repairIndex)->toBeInt()
        ->and(collect($invocations)->contains(
            static fn (string $invocation): bool => str_contains($invocation, WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT),
        ))->toBeFalse();
});

it('claims a released destination without rewriting sidecar bytes and reconstructs a consistent rollback CAS', function (): void {
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
    $invocations = [];
    Process::fake(function (PendingProcess $process) use (
        $bootId,
        $fixture,
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
        ->and(collect($invocations)->contains(
            static fn (string $invocation): bool => str_contains($invocation, WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT),
        ))->toBeFalse()
        ->and($claimedState->operation_routing_mutated_at)->toBeNull()
        ->and($claimedState->operation_previous_proxy_state)->toBeString()
        ->and($claimedState->operation_previous_proxy_state_sha256)
        ->toBe(hash('sha256', (string) $claimedState->operation_previous_proxy_state))
        ->and($recovery->routingMutationRecorded)->toBeFalse()
        ->and($recovery->rollbackKey->expectedState?->serialize())
        ->toBe($claimedState->operation_previous_proxy_state);
});
