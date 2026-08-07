<?php

use App\Actions\Application\BlueGreen\AttestBlueGreenLegacyRouteNetworkIdentity;
use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\CompleteBlueGreenDeploymentOperation;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDestinationMutation;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\PlanBlueGreenSteadyState;
use App\Actions\Application\BlueGreen\RecordBlueGreenCandidateIdentity;
use App\Actions\Application\BlueGreen\RecordBlueGreenDestinationState;
use App\Actions\Application\BlueGreen\RecordBlueGreenRoutingMutation;
use App\Actions\Application\BlueGreen\RehydrateBlueGreenDestinationRoutingTopologyDigest;
use App\Actions\Application\BlueGreen\ResolveBlueGreenActiveReplicaSet;
use App\Actions\Application\BlueGreen\TransitionsBlueGreenDeployment;
use App\Actions\Proxy\BlueGreenActiveReplica;
use App\Actions\Proxy\BlueGreenActiveReplicaSet;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process as SymfonyProcess;

beforeEach(function (): void {
    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
});

afterEach(function (): void {
    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
});

/** @return array{application: Application, deployment: ApplicationDeploymentQueue, destination: mixed, server: Server} */
function makeTopologyDigestFixture(string $deploymentUuid): array
{
    Process::fake();
    Storage::fake('ssh-keys');
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $privateKey->storeInFileSystem();
    Process::assertNothingRan();
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'ports_exposes' => '3000',
        'fqdn' => 'https://topology-digest.example.com',
        'health_check_enabled' => true,
    ]);
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $deploymentUuid,
        'pull_request_id' => 0,
        'commit' => 'topology-digest-commit',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'only_this_server' => true,
    ]);

    return compact('application', 'deployment', 'destination', 'server');
}

/** @return array{state: ApplicationBlueGreenDeployment, previous: BlueGreenContainerExpectation, expected: BlueGreenProxyState, legacyDigest: string, managedYaml: string} */
function seedManagedTopologyState(array $fixture): array
{
    $application = $fixture['application']->fresh(['settings']);
    $destination = $fixture['destination']->fresh();
    $previousUuid = 'topology-previous-deployment';
    $previousContainerId = str_repeat('a', 64);
    $legacyDigest = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::BLUE,
        1,
        1,
        $previousUuid,
    )->operationTopologyDigest;
    $configuration = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        new BlueGreenRoutingTarget(
            destinationId: (int) $destination->id,
            activeColor: BlueGreenDeploymentColor::BLUE,
            blueContainerName: $application->uuid.'-blue',
            greenContainerName: $application->uuid.'-green',
            port: 3000,
            ports: [3000],
            routingRevision: 1,
            mode: BlueGreenRoutingMode::Steady,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($previousUuid),
            destinationFenceEpoch: 1,
            operationId: $previousUuid,
            mutationSequence: 2,
            activeDeploymentUuid: $previousUuid,
            activeContainerId: $previousContainerId,
            destinationTopologyDigest: $legacyDigest,
        ),
    );
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $fixture['server']->id,
        'server_name' => $fixture['server']->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $previousUuid,
        'pull_request_id' => 0,
        'commit' => 'previous-topology-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_topology_digest' => $legacyDigest,
        'blue_green_routing_config_digest' => $configuration->routingConfigDigest,
        'blue_green_backend_port_inventory' => BlueGreenBackendPortInventory::fromPorts([3000])->serialized,
        'blue_green_candidate_container_id' => $previousContainerId,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => $previousUuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
        'supersession_generation' => 1,
        'destination_fence_epoch' => 1,
        'destination_fence_operation_id' => $previousUuid,
        'destination_fence_mutation_sequence' => 2,
        'managed_file_sha256' => $configuration->sha256,
        'destination_topology_digest' => $legacyDigest,
        'application_routing_config_digest' => $configuration->routingConfigDigest,
    ]);

    return [
        'state' => $state,
        'previous' => new BlueGreenContainerExpectation(
            name: $application->uuid.'-blue',
            dockerId: $previousContainerId,
            applicationId: (int) $application->id,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $previousUuid,
            color: BlueGreenDeploymentColor::BLUE,
            routingRevision: 1,
        ),
        'expected' => $configuration->state,
        'legacyDigest' => $legacyDigest,
        'managedYaml' => $configuration->yaml,
    ];
}

function rotateTopologyServerConnection(Server $server): void
{
    $replacementKey = PrivateKey::factory()->create(['team_id' => $server->team_id]);
    $server->fresh()->update([
        'private_key_id' => $replacementKey->id,
        'ip' => '10.255.255.99',
        'user' => 'rotated-operator',
        'port' => 2222,
    ]);
}

function fakeTopologyDigestRouteNetworkProof(BlueGreenProxyState $state, string $network): void
{
    $members = $state->activeContainerSet?->members ?? [(object) [
        'id' => $state->activeContainerId,
    ]];
    $output = collect($members)
        ->map(static fn (object $member): string => 'coolify-blue-green-route-network-proof:'
            .$member->id."\t".json_encode([$network => []], JSON_THROW_ON_ERROR))
        ->implode("\n");
    Process::fake(static function (PendingProcess $process) use ($output, $state) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $payload = $command."\n".(string) $process->input;

        return match (true) {
            str_contains($payload, 'coolify-blue-green-route-network-proof:') => Process::result(output: $output),
            str_contains($payload, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($state->serialize())."\n"
                    .$state->managedSha256,
            ),
            str_contains($payload, '/proc/sys/kernel/random/boot_id') => Process::result(
                output: '11111111-2222-3333-4444-555555555555',
            ),
            default => throw new RuntimeException('Unexpected lazy routing topology network proof process.'),
        };
    });
}

/** @param Closure(BlueGreenOperationFence): mixed $callback */
function withTopologyDigestOperationFence(
    Application $application,
    StandaloneDocker $destination,
    Closure $callback,
): mixed {
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key((int) $application->id, (int) $destination->id),
        BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
    );
    if (! $lock->get()) {
        throw new RuntimeException('Unable to acquire the topology digest test operation fence.');
    }
    $fence = new BlueGreenOperationFence($lock, BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS);

    try {
        return $callback($fence);
    } finally {
        $fence->releaseIfOwned();
    }
}

it('requires every claim caller to supply the routing topology digest explicitly', function (): void {
    $parameter = collect((new ReflectionMethod(BlueGreenDeploymentClaim::class, '__construct'))->getParameters())
        ->first(static fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'routingTopologyDigest');

    expect($parameter)->toBeInstanceOf(ReflectionParameter::class)
        ->and($parameter->isOptional())->toBeFalse()
        ->and($parameter->isDefaultValueAvailable())->toBeFalse();
});

it('keeps the routing topology digest stable while legacy operation provenance follows connection rotation', function (): void {
    $fixture = makeTopologyDigestFixture('credential-rotation-fingerprint');
    $before = ComputeBlueGreenDeploymentFingerprint::run(
        $fixture['application'],
        $fixture['destination'],
        BlueGreenDeploymentColor::BLUE,
        1,
        1,
        $fixture['deployment']->deployment_uuid,
    );

    rotateTopologyServerConnection($fixture['server']);
    $after = ComputeBlueGreenDeploymentFingerprint::run(
        $fixture['application']->fresh(['settings']),
        $fixture['destination']->fresh(),
        BlueGreenDeploymentColor::BLUE,
        1,
        1,
        $fixture['deployment']->deployment_uuid,
    );

    expect($after->routingTopologyDigest)->toBe($before->routingTopologyDigest)
        ->and($after->operationTopologyDigest)->not->toBe($before->operationTopologyDigest);
});

it('keeps an existing destination routing digest stable when an eligible destination is attached', function (): void {
    $fixture = makeTopologyDigestFixture('additional-destination-routing-digest');
    $application = $fixture['application']->fresh(['settings']);
    $destination = $fixture['destination']->fresh(['server']);
    $before = (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor($application, $destination);
    $application->blueGreenDeployments()->create([
        'standalone_docker_id' => $destination->id,
        'destination_routing_topology_digest' => $before,
    ]);
    $additionalServer = Server::factory()->create(['team_id' => $fixture['server']->team_id]);
    $additionalServer->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $additionalServer->save();
    $additionalDestination = $additionalServer->standaloneDockers()->firstOrFail();

    $application->prepareBlueGreenAdditionalDestinationAddition($additionalDestination);
    $application->additional_networks()->attach($additionalDestination->id, ['server_id' => $additionalServer->id]);

    $after = (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor(
        $application->fresh(['settings']),
        $destination,
    );
    $claim = ClaimBlueGreenDeployment::run(
        application: $application->fresh(['settings']),
        standaloneDocker: $destination,
        deployment: $fixture['deployment']->fresh(),
        serverBootId: '11111111-2222-3333-4444-555555555555',
    );

    expect($after)->toBe($before)
        ->and($claim->routingTopologyDigest)->toBe($before);
});

it('rehydrates one remotely attested legacy route and admits a claim after credential rotation', function (): void {
    $fixture = makeTopologyDigestFixture('credential-rotation-claim');
    $managed = seedManagedTopologyState($fixture);
    rotateTopologyServerConnection($fixture['server']);
    fakeTopologyDigestRouteNetworkProof($managed['expected'], $fixture['destination']->network);

    $claim = withTopologyDigestOperationFence(
        $fixture['application'],
        $fixture['destination'],
        static function (BlueGreenOperationFence $fence) use ($fixture, $managed): BlueGreenDeploymentClaim {
            $application = $fixture['application']->fresh(['settings']);
            $destination = $fixture['destination']->fresh(['server']);
            $attestation = AttestBlueGreenLegacyRouteNetworkIdentity::run(
                server: $destination->server,
                application: $application,
                destination: $destination,
                routeState: $managed['expected'],
                expectedServerBootId: '11111111-2222-3333-4444-555555555555',
                operationFence: $fence,
            );

            return ClaimBlueGreenDeployment::run(
                application: $application,
                standaloneDocker: $destination,
                deployment: $fixture['deployment']->fresh(),
                serverBootId: '11111111-2222-3333-4444-555555555555',
                previousContainer: $managed['previous'],
                legacyRouteNetworkAttestation: $attestation,
            );
        },
    );

    $state = $managed['state']->fresh();
    expect($claim->operationTopologyDigest)->not->toBe($managed['legacyDigest'])
        ->and($state->destination_topology_digest)->toBe($managed['legacyDigest'])
        ->and($state->operation_topology_digest)->toBe($claim->operationTopologyDigest)
        ->and($state->destination_routing_topology_digest)->toBe($claim->routingTopologyDigest)
        ->and($fixture['deployment']->fresh()->blue_green_topology_digest)->toBe($claim->operationTopologyDigest);
});

it('attests every exact Docker replica behind a replica-aware legacy route', function (): void {
    $fixture = makeTopologyDigestFixture('replica-route-network-attestation');
    $application = $fixture['application']->fresh(['settings']);
    $destination = $fixture['destination']->fresh(['server']);
    $members = BlueGreenActiveReplicaSet::fromMembers([
        new BlueGreenActiveReplica(
            composeService: 'app-blue-replica-1',
            replicaIndex: 1,
            ports: [3000],
            name: $application->uuid.'-blue-replica-1',
            id: str_repeat('a', 64),
        ),
        new BlueGreenActiveReplica(
            composeService: 'app-blue-replica-2',
            replicaIndex: 2,
            ports: [3000],
            name: $application->uuid.'-blue-replica-2',
            id: str_repeat('b', 64),
        ),
    ]);
    $state = new BlueGreenProxyState(
        managedFilename: BlueGreenRoutingTarget::managedFilename((string) $application->uuid, (int) $destination->id),
        applicationUuid: (string) $application->uuid,
        destinationId: (int) $destination->id,
        operationId: 'replica-route-network-attestation',
        mutationSequence: 1,
        destinationFenceEpoch: 1,
        routingRevision: 1,
        managedSha256: str_repeat('c', 64),
        activeColor: BlueGreenDeploymentColor::BLUE,
        activeDeploymentUuid: 'replica-route-network-attestation',
        activeContainerName: $members->representative()->name,
        activeContainerId: $members->representative()->id,
        applicationRoutingConfigDigest: str_repeat('d', 64),
        destinationTopologyDigest: str_repeat('e', 64),
        activeReplicaSetDigest: $members->identityDigest(),
        activeReplicaSet: $members,
    );
    $networkPayloads = [];
    Process::fake(static function (PendingProcess $process) use (&$networkPayloads, $destination, $state, $members) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $payload = $command."\n".(string) $process->input;

        return match (true) {
            str_contains($payload, 'coolify-blue-green-route-network-proof:') => (function () use (&$networkPayloads, $payload, $destination, $members): mixed {
                $networkPayloads[] = $payload;

                return Process::result(output: collect($members->members)
                    ->map(static fn (BlueGreenActiveReplica $member): string => 'coolify-blue-green-route-network-proof:'
                        .$member->id."\t".json_encode([$destination->network => []], JSON_THROW_ON_ERROR))
                    ->implode("\n"));
            })(),
            str_contains($payload, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($state->serialize())."\n"
                    .$state->managedSha256,
            ),
            str_contains($payload, '/proc/sys/kernel/random/boot_id') => Process::result(
                output: '11111111-2222-3333-4444-555555555555',
            ),
            default => throw new RuntimeException('Unexpected replica route network attestation process.'),
        };
    });

    withTopologyDigestOperationFence(
        $application,
        $destination,
        static function (BlueGreenOperationFence $fence) use ($application, $destination, $fixture, $state): void {
            AttestBlueGreenLegacyRouteNetworkIdentity::run(
                server: $fixture['server']->fresh(),
                application: $application,
                destination: $destination,
                routeState: $state,
                expectedServerBootId: '11111111-2222-3333-4444-555555555555',
                operationFence: $fence,
            );
        },
    );

    expect($networkPayloads)->toHaveCount(1)
        ->and($networkPayloads[0])->toContain(str_repeat('a', 64))
        ->and($networkPayloads[0])->toContain(str_repeat('b', 64))
        ->and($networkPayloads[0])->not->toContain($members->identityDigest());
});

it('fans every non-Compose replica out across every immutable backend port', function (): void {
    $inspections = [
        BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: 1,
            composeService: 'app-blue-replica-1',
            containerName: 'app-blue-replica-1',
            dockerId: str_repeat('a', 64),
            status: 'running',
            health: 'healthy',
        ),
        BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: 2,
            composeService: 'app-blue-replica-2',
            containerName: 'app-blue-replica-2',
            dockerId: str_repeat('b', 64),
            status: 'running',
            health: 'healthy',
        ),
    ];
    $replicaSet = ResolveBlueGreenActiveReplicaSet::run(
        topology: null,
        color: BlueGreenDeploymentColor::BLUE,
        replicaSet: new BlueGreenReplicaSet(2),
        inspections: $inspections,
        scalarBackendPorts: [3000, 4000],
    );
    $backends = ['app-blue-replica-1', 'app-blue-replica-2'];
    $target = new BlueGreenRoutingTarget(
        destinationId: 1,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: $backends[0],
        greenContainerName: 'app-green-replica-1',
        port: 3000,
        routingRevision: 1,
        ports: [3000, 4000],
        blueReplicaBackends: $backends,
        greenReplicaBackends: ['app-green-replica-1', 'app-green-replica-2'],
        blueReplicaSet: $replicaSet,
    );

    expect($replicaSet)->not->toBeNull()
        ->and($replicaSet->members[0]->ports)->toBe([3000, 4000])
        ->and($replicaSet->members[1]->ports)->toBe([3000, 4000])
        ->and($target->replicaBackendsForPort(BlueGreenDeploymentColor::BLUE, 3000))->toBe($backends)
        ->and($target->replicaBackendsForPort(BlueGreenDeploymentColor::BLUE, 4000))->toBe($backends);
});

it('rejects destination network drift after live proof at the locked artifact binding', function (): void {
    $fixture = makeTopologyDigestFixture('post-proof-network-drift');
    $managed = seedManagedTopologyState($fixture);
    fakeTopologyDigestRouteNetworkProof($managed['expected'], $fixture['destination']->network);

    expect(fn () => withTopologyDigestOperationFence(
        $fixture['application'],
        $fixture['destination'],
        static function (BlueGreenOperationFence $fence) use ($fixture, $managed): void {
            $application = $fixture['application']->fresh(['settings']);
            $destination = $fixture['destination']->fresh(['server']);
            $attestation = AttestBlueGreenLegacyRouteNetworkIdentity::run(
                server: $destination->server,
                application: $application,
                destination: $destination,
                routeState: $managed['expected'],
                expectedServerBootId: '11111111-2222-3333-4444-555555555555',
                operationFence: $fence,
            );
            $destination->newQuery()->whereKey($destination->id)->update(['network' => 'post-proof-network']);

            ClaimBlueGreenDeployment::run(
                application: $application,
                standaloneDocker: $destination,
                deployment: $fixture['deployment']->fresh(),
                serverBootId: '11111111-2222-3333-4444-555555555555',
                previousContainer: $managed['previous'],
                legacyRouteNetworkAttestation: $attestation,
            );
        },
    ))->toThrow(BlueGreenDeploymentTransitionException::class, 'attestation no longer matches the exact locked destination snapshot')
        ->and($managed['state']->fresh()->destination_routing_topology_digest)->toBeNull()
        ->and($fixture['deployment']->fresh()->blue_green_phase)->toBeNull();
});

it('rejects server connection drift after live proof before the locked claim', function (): void {
    $fixture = makeTopologyDigestFixture('post-proof-connection-drift');
    $managed = seedManagedTopologyState($fixture);
    $replacementKey = PrivateKey::factory()->create(['team_id' => $fixture['server']->team_id]);
    fakeTopologyDigestRouteNetworkProof($managed['expected'], $fixture['destination']->network);

    expect(fn () => withTopologyDigestOperationFence(
        $fixture['application'],
        $fixture['destination'],
        static function (BlueGreenOperationFence $fence) use ($fixture, $managed, $replacementKey): void {
            $application = $fixture['application']->fresh(['settings']);
            $destination = $fixture['destination']->fresh(['server']);
            $attestation = AttestBlueGreenLegacyRouteNetworkIdentity::run(
                server: $destination->server,
                application: $application,
                destination: $destination,
                routeState: $managed['expected'],
                expectedServerBootId: '11111111-2222-3333-4444-555555555555',
                operationFence: $fence,
            );
            Server::query()->whereKey($destination->server->id)->update([
                'private_key_id' => $replacementKey->id,
                'ip' => '10.255.255.97',
                'user' => 'post-proof-claim-operator',
                'port' => 2224,
            ]);

            ClaimBlueGreenDeployment::run(
                application: $application,
                standaloneDocker: $destination,
                deployment: $fixture['deployment']->fresh(),
                serverBootId: '11111111-2222-3333-4444-555555555555',
                previousContainer: $managed['previous'],
                legacyRouteNetworkAttestation: $attestation,
            );
        },
    ))->toThrow(BlueGreenDeploymentTransitionException::class, 'attestation no longer matches the exact locked destination snapshot')
        ->and($managed['state']->fresh()->destination_routing_topology_digest)->toBeNull()
        ->and($fixture['deployment']->fresh()->blue_green_phase)->toBeNull();
});

it('keeps a legacy routing digest null when active-container network evidence is missing', function (): void {
    $fixture = makeTopologyDigestFixture('missing-network-proof-claim');
    $managed = seedManagedTopologyState($fixture);
    Process::fake(static function (PendingProcess $process) use ($managed) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $payload = $command."\n".(string) $process->input;

        return match (true) {
            str_contains($payload, 'coolify-blue-green-route-network-proof:') => Process::result(),
            str_contains($payload, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($managed['expected']->serialize())."\n"
                    .$managed['expected']->managedSha256,
            ),
            str_contains($payload, '/proc/sys/kernel/random/boot_id') => Process::result(
                output: '11111111-2222-3333-4444-555555555555',
            ),
            default => throw new RuntimeException('Unexpected missing routing topology network proof process.'),
        };
    });

    expect(fn () => withTopologyDigestOperationFence(
        $fixture['application'],
        $fixture['destination'],
        static function (BlueGreenOperationFence $fence) use ($fixture, $managed): BlueGreenDeploymentClaim {
            $application = $fixture['application']->fresh(['settings']);
            $destination = $fixture['destination']->fresh(['server']);
            $attestation = AttestBlueGreenLegacyRouteNetworkIdentity::run(
                server: $destination->server,
                application: $application,
                destination: $destination,
                routeState: $managed['expected'],
                expectedServerBootId: '11111111-2222-3333-4444-555555555555',
                operationFence: $fence,
            );

            return ClaimBlueGreenDeployment::run(
                application: $application,
                standaloneDocker: $destination,
                deployment: $fixture['deployment']->fresh(),
                serverBootId: '11111111-2222-3333-4444-555555555555',
                previousContainer: $managed['previous'],
                legacyRouteNetworkAttestation: $attestation,
            );
        },
    ))->toThrow(BlueGreenDeploymentTransitionException::class, 'network proof was missing or ambiguous')
        ->and($managed['state']->fresh()->destination_routing_topology_digest)->toBeNull()
        ->and($fixture['deployment']->fresh()->blue_green_phase)->toBeNull();
});

it('refuses legacy routing-topology rehydration without exact remote attestation', function (): void {
    $fixture = makeTopologyDigestFixture('missing-attestation-claim');
    $managed = seedManagedTopologyState($fixture);

    expect(fn () => ClaimBlueGreenDeployment::run(
        application: $fixture['application']->fresh(['settings']),
        standaloneDocker: $fixture['destination']->fresh(),
        deployment: $fixture['deployment']->fresh(),
        serverBootId: '11111111-2222-3333-4444-555555555555',
        previousContainer: $managed['previous'],
    ))->toThrow(BlueGreenDeploymentTransitionException::class, 'must be remotely attested')
        ->and($managed['state']->fresh()->destination_routing_topology_digest)->toBeNull();
});

it('does not establish a routing digest when pristine remote attestation rejects residue', function (): void {
    $fixture = makeTopologyDigestFixture('pristine-attestation-residue');
    Process::fake(static function (PendingProcess $process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return match (true) {
            str_contains($command, 'coolify-blue-green-destination-state-attested') => Process::result(
                errorOutput: 'A managed blue/green route file exists without durable control-plane state; reconciliation is required before first adoption.',
                exitCode: 1,
            ),
            str_contains($command, '/proc/sys/kernel/random/boot_id') => Process::result(
                output: '11111111-2222-3333-4444-555555555555',
            ),
            default => throw new RuntimeException('Unexpected pristine routing topology attestation process.'),
        };
    });
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $fixture['application']->fresh(['settings']),
        deployment: $fixture['deployment']->fresh(),
        destination: $fixture['destination']->fresh(),
        server: $fixture['server']->fresh(),
        timeout: 30,
        checkForCancellation: static function (): void {},
    );

    expect(fn () => $lifecycle->initialize())
        ->toThrow(RuntimeException::class, 'managed blue/green route file exists without durable control-plane state')
        ->and(ApplicationBlueGreenDeployment::query()
            ->where('application_id', $fixture['application']->id)
            ->where('standalone_docker_id', $fixture['destination']->id)
            ->value('destination_routing_topology_digest'))->toBeNull()
        ->and(ApplicationBlueGreenDeployment::query()
            ->where('application_id', $fixture['application']->id)
            ->where('standalone_docker_id', $fixture['destination']->id)
            ->exists())->toBeFalse();
});

it('leaves a legacy routing digest null when remote attestation mismatches', function (): void {
    $fixture = makeTopologyDigestFixture('legacy-attestation-mismatch');
    $managed = seedManagedTopologyState($fixture);
    Process::fake(static function (PendingProcess $process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return match (true) {
            str_contains($command, 'coolify-blue-green-destination-state-attested') => Process::result(
                output: 'coolify-blue-green-destination-state-mismatch',
            ),
            str_contains($command, '/proc/sys/kernel/random/boot_id') => Process::result(
                output: '11111111-2222-3333-4444-555555555555',
            ),
            default => throw new RuntimeException('Unexpected legacy routing topology attestation process.'),
        };
    });
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $fixture['application']->fresh(['settings']),
        deployment: $fixture['deployment']->fresh(),
        destination: $fixture['destination']->fresh(),
        server: $fixture['server']->fresh(),
        timeout: 30,
        checkForCancellation: static function (): void {},
    );

    expect(fn () => $lifecycle->initialize())
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'did not return its exact attestation')
        ->and($managed['state']->fresh()->destination_routing_topology_digest)->toBeNull()
        ->and($managed['state']->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($fixture['deployment']->fresh()->blue_green_phase)->toBeNull();
});

it('fences a genuine routing topology change and names the routing-affecting field class', function (): void {
    $fixture = makeTopologyDigestFixture('routing-topology-drift');
    $routingDigest = ComputeBlueGreenDeploymentFingerprint::run(
        $fixture['application'],
        $fixture['destination'],
        BlueGreenDeploymentColor::BLUE,
        1,
        1,
        $fixture['deployment']->deployment_uuid,
    )->routingTopologyDigest;
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'destination_routing_topology_digest' => $routingDigest,
    ]);
    $fixture['destination']->newQuery()->whereKey($fixture['destination']->id)->update(['network' => 'changed-network']);

    ClaimBlueGreenDeployment::run(
        application: $fixture['application']->fresh(['settings']),
        standaloneDocker: $fixture['destination']->fresh(),
        deployment: $fixture['deployment']->fresh(),
        serverBootId: '11111111-2222-3333-4444-555555555555',
    );
})->throws(
    BlueGreenDeploymentTransitionException::class,
    'destination network, server proxy type/path, or Compose routed topology',
);

it('preserves a legacy route identity through committed container-mutation recovery and admits the next claim after connection rotation', function (): void {
    $fixture = makeTopologyDigestFixture('container-mutation-legacy-compatibility');
    $managed = seedManagedTopologyState($fixture);
    rotateTopologyServerConnection($fixture['server']);

    $application = $fixture['application']->fresh(['settings']);
    $destination = $fixture['destination']->fresh();
    fakeTopologyDigestRouteNetworkProof($managed['expected'], $destination->network);
    $claim = withTopologyDigestOperationFence(
        $application,
        $destination,
        static function (BlueGreenOperationFence $fence) use ($application, $destination, $fixture, $managed): BlueGreenDeploymentClaim {
            $attestation = AttestBlueGreenLegacyRouteNetworkIdentity::run(
                server: $destination->server,
                application: $application,
                destination: $destination,
                routeState: $managed['expected'],
                expectedServerBootId: '11111111-2222-3333-4444-555555555555',
                operationFence: $fence,
            );

            return ClaimBlueGreenDeployment::run(
                application: $application,
                standaloneDocker: $destination,
                deployment: $fixture['deployment']->fresh(),
                serverBootId: '11111111-2222-3333-4444-555555555555',
                previousContainer: $managed['previous'],
                legacyRouteNetworkAttestation: $attestation,
            );
        },
    );
    $executor = new ExecuteBlueGreenDestinationMutation;
    $replacement = $executor->replacementStateFor(
        $application,
        $claim,
        $managed['expected'],
    );
    $filesystem = new Filesystem;
    $root = sys_get_temp_dir().'/coolify-topology-digest-container-journal-'.bin2hex(random_bytes(8));
    $proxyPath = $root.'/proxy';
    $bootIdPath = $root.'/boot-id';
    $containerMarkerPath = $root.'/candidate-container';
    $replaySentinelPath = $root.'/mutation-replay-sentinel';
    $filesystem->mkdir($proxyPath.'/dynamic', 0700);
    file_put_contents($bootIdPath, $claim->serverBootId);
    file_put_contents($replaySentinelPath, 'before-mutation');

    $crashingWriter = new class($bootIdPath) extends WriteBlueGreenProxyConfiguration
    {
        public function __construct(private readonly string $bootIdPath) {}

        /** @return list<string> */
        protected function afterContainerMutationCommands(): array
        {
            return ['exit 87'];
        }

        protected function bootIdentityAssertionCommand(string $expectedBootIdShellValue): string
        {
            return 'test "$(cat '.escapeshellarg($this->bootIdPath).')" = '.$expectedBootIdShellValue;
        }
    };
    $statePath = $crashingWriter->statePath($proxyPath, $replacement->managedFilename);
    $managedPath = $crashingWriter->managedPath($proxyPath, $replacement->managedFilename);
    $journalPath = $crashingWriter->containerMutationJournalPath($proxyPath, $replacement->managedFilename);
    $filesystem->mkdir(dirname($statePath), 0700);
    file_put_contents($statePath, $managed['expected']->serialize());
    chmod($statePath, 0600);
    file_put_contents($managedPath, $managed['managedYaml']);
    chmod($managedPath, 0600);

    try {
        $interrupted = SymfonyProcess::fromShellCommandline(
            (new ExecuteBlueGreenDestinationMutation($crashingWriter))->commandFor(
                proxyPath: $proxyPath,
                expectedState: $managed['expected'],
                replacementState: $replacement,
                commands: [
                    'printf %s running > '.escapeshellarg($containerMarkerPath),
                    'printf %s mutation-applied > '.escapeshellarg($replaySentinelPath),
                ],
                completionCommands: [
                    'test "$(cat '.escapeshellarg($containerMarkerPath).')" = running',
                ],
                expectedServerBootId: $claim->serverBootId,
            ),
        );
        $interrupted->setTimeout(10);
        $interrupted->run();

        expect($interrupted->getExitCode())->toBe(87)
            ->and(file_get_contents($containerMarkerPath))->toBe('running')
            ->and(file_get_contents($replaySentinelPath))->toBe('mutation-applied')
            ->and(file_get_contents($statePath))->toBe($managed['expected']->serialize())
            ->and(is_file($journalPath))->toBeTrue();

        $journalSha256 = hash_file('sha256', $journalPath);
        file_put_contents($replaySentinelPath, 'must-not-replay');
        $writer = new class($bootIdPath) extends WriteBlueGreenProxyConfiguration
        {
            public function __construct(private readonly string $bootIdPath) {}

            protected function bootIdentityAssertionCommand(string $expectedBootIdShellValue): string
            {
                return 'test "$(cat '.escapeshellarg($this->bootIdPath).')" = '.$expectedBootIdShellValue;
            }
        };
        $finalized = SymfonyProcess::fromShellCommandline(
            $writer->finalizePendingContainerMutationJournalCommandFor(
                proxyPath: $proxyPath,
                managedFilename: $replacement->managedFilename,
                expectedJournalSha256: $journalSha256,
                expectedJournalBootId: $claim->serverBootId,
                expectedState: $managed['expected'],
                replacementState: $replacement,
                expectedCurrentBootId: $claim->serverBootId,
            ),
        );
        $finalized->setTimeout(10);
        $finalized->run();
        $archivePath = dirname($journalPath).'/'.$writer->containerMutationJournalArchiveFilename(
            $replacement->managedFilename,
            $journalSha256,
        );

        $recorded = RecordBlueGreenDestinationState::run(
            $claim,
            $managed['expected'],
            $replacement,
        );

        expect($finalized->isSuccessful())->toBeTrue($finalized->getErrorOutput())
            ->and(file_exists($journalPath))->toBeFalse()
            ->and(hash_file('sha256', $archivePath))->toBe($journalSha256)
            ->and(file_get_contents($statePath))->toBe($replacement->serialize())
            ->and(file_get_contents($containerMarkerPath))->toBe('running')
            ->and(file_get_contents($replaySentinelPath))->toBe('must-not-replay')
            ->and($claim->operationTopologyDigest)->not->toBe($managed['legacyDigest'])
            ->and($claim->routingTopologyDigest)->not->toBe($claim->operationTopologyDigest)
            ->and($replacement->destinationTopologyDigest)->toBe($managed['legacyDigest'])
            ->and($recorded->destination_topology_digest)->toBe($managed['legacyDigest'])
            ->and($recorded->destination_routing_topology_digest)->toBe($claim->routingTopologyDigest);

        $candidateContainerId = str_repeat('b', 64);
        RecordBlueGreenCandidateIdentity::run($claim, new BlueGreenContainerInspection(
            exists: true,
            dockerId: $candidateContainerId,
            status: 'running',
            health: 'healthy',
        ));
        $activeConfiguration = CompileBlueGreenProxyConfiguration::run(
            $application,
            $destination,
            new BlueGreenRoutingTarget(
                destinationId: $destination->id,
                activeColor: $claim->pendingColor,
                blueContainerName: $application->uuid.'-blue',
                greenContainerName: $application->uuid.'-green',
                port: 3000,
                ports: [3000],
                routingRevision: $claim->expectedRoutingRevision,
                publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($claim->deploymentUuid),
                destinationFenceEpoch: $claim->destinationFenceEpoch,
                operationId: $claim->deploymentUuid,
                mutationSequence: $replacement->mutationSequence + 1,
                activeDeploymentUuid: $claim->deploymentUuid,
                activeContainerId: $candidateContainerId,
                destinationTopologyDigest: $claim->operationTopologyDigest,
            ),
        );
        RecordBlueGreenDestinationState::run($claim, $replacement, $activeConfiguration->state);
        RecordBlueGreenRoutingMutation::run($claim, $activeConfiguration->state);
        TransitionsBlueGreenDeployment::markSwitching($claim);
        TransitionsBlueGreenDeployment::markDraining($claim, 1);
        $completed = CompleteBlueGreenDeploymentOperation::run($claim, 0);

        $nextDeployment = ApplicationDeploymentQueue::query()->create([
            'application_id' => $application->id,
            'application_name' => $application->name,
            'server_id' => $fixture['server']->id,
            'server_name' => $fixture['server']->name,
            'destination_id' => $destination->id,
            'deployment_uuid' => 'connection-rotation-subsequent-claim',
            'pull_request_id' => 0,
            'commit' => 'subsequent-topology-digest-commit',
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
            'only_this_server' => true,
        ]);
        $nextClaim = ClaimBlueGreenDeployment::run(
            application: $application->fresh(['settings']),
            standaloneDocker: $destination->fresh(),
            deployment: $nextDeployment,
            serverBootId: $claim->serverBootId,
            previousContainer: new BlueGreenContainerExpectation(
                name: $application->uuid.'-'.$claim->pendingColor->value,
                dockerId: $candidateContainerId,
                applicationId: (int) $application->id,
                pullRequestId: 0,
                blueGreenManaged: true,
                deploymentUuid: $claim->deploymentUuid,
                color: $claim->pendingColor,
                routingRevision: $claim->expectedRoutingRevision,
            ),
        );

        expect($completed->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
            ->and($completed->destination_topology_digest)->toBe($claim->operationTopologyDigest)
            ->and($completed->destination_routing_topology_digest)->toBe($claim->routingTopologyDigest)
            ->and($nextClaim->routingTopologyDigest)->toBe($claim->routingTopologyDigest)
            ->and($nextClaim->operationTopologyDigest)->toBe($claim->operationTopologyDigest);
    } finally {
        $filesystem->remove($root);
    }
});

it('rehydrates only the DB routing digest after strict live route and release proof', function (): void {
    $fixture = makeTopologyDigestFixture('operator-rehydration');
    $managed = seedManagedTopologyState($fixture);
    $application = $fixture['application']->fresh(['settings']);
    $destination = $fixture['destination']->fresh();
    $plan = PlanBlueGreenSteadyState::run($application, $destination, $managed['state']->fresh());
    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken(
        $plan->activeDeployment->deployment_uuid,
    );
    $before = $managed['state']->only([
        'destination_fence_epoch',
        'destination_fence_operation_id',
        'destination_fence_mutation_sequence',
        'managed_file_sha256',
        'destination_topology_digest',
        'application_routing_config_digest',
    ]);
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $plan->representativeActiveContainer()->dockerId,
            status: 'running',
            health: 'healthy',
        ));
    Process::fake(static function (PendingProcess $process) use ($destination, $managed, $plan, $releaseProof) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $payload = $command."\n".(string) $process->input;

        return match (true) {
            str_contains($payload, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($managed['expected']->serialize())."\n"
                    .$managed['expected']->managedSha256,
            ),
            str_contains($payload, 'coolify-blue-green-route-network-proof:') => Process::result(
                output: 'coolify-blue-green-route-network-proof:'
                    .$plan->representativeActiveContainer()->dockerId."\t"
                    .json_encode([$destination->network => []], JSON_THROW_ON_ERROR),
            ),
            str_contains($payload, '/proc/sys/kernel/random/boot_id') => Process::result(
                output: '11111111-2222-3333-4444-555555555555',
            ),
            str_contains($payload, '{{json .Config.Env}}') => Process::result(
                output: json_encode(['COOLIFY_DEPLOYMENT_RELEASE_PROOF='.$releaseProof], JSON_THROW_ON_ERROR),
            ),
            str_contains($payload, 'curl --config -') => Process::result(output: "HTTP/1.1 200 OK\r\n"
                .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$plan->publicAcknowledgement}\r\n"
                .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n"),
            default => throw new RuntimeException('Unexpected routing topology rehydration process.'),
        };
    });

    $rehydrated = RehydrateBlueGreenDestinationRoutingTopologyDigest::run($managed['state']);

    expect($rehydrated->destination_routing_topology_digest)
        ->toBe((new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor($application, $destination))
        ->and($rehydrated->only(array_keys($before)))->toBe($before);
});

it('leaves the DB routing digest null when the server connection changes after live proof', function (): void {
    $fixture = makeTopologyDigestFixture('operator-rehydration-connection-drift');
    $managed = seedManagedTopologyState($fixture);
    $application = $fixture['application']->fresh(['settings']);
    $destination = $fixture['destination']->fresh();
    $plan = PlanBlueGreenSteadyState::run($application, $destination, $managed['state']->fresh());
    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken(
        $plan->activeDeployment->deployment_uuid,
    );
    $replacementKey = PrivateKey::factory()->create(['team_id' => $fixture['server']->team_id]);
    $bootReads = 0;
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $plan->representativeActiveContainer()->dockerId,
            status: 'running',
            health: 'healthy',
        ));
    Process::fake(function (PendingProcess $process) use (
        &$bootReads,
        $destination,
        $fixture,
        $managed,
        $plan,
        $releaseProof,
        $replacementKey,
    ) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $payload = $command."\n".(string) $process->input;

        return match (true) {
            str_contains($payload, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($managed['expected']->serialize())."\n"
                    .$managed['expected']->managedSha256,
            ),
            str_contains($payload, 'coolify-blue-green-route-network-proof:') => Process::result(
                output: 'coolify-blue-green-route-network-proof:'
                    .$plan->representativeActiveContainer()->dockerId."\t"
                    .json_encode([$destination->network => []], JSON_THROW_ON_ERROR),
            ),
            str_contains($payload, '/proc/sys/kernel/random/boot_id') => (function () use (
                &$bootReads,
                $fixture,
                $replacementKey,
            ): mixed {
                $bootReads++;
                if ($bootReads === 3) {
                    Server::query()->whereKey($fixture['server']->id)->update([
                        'private_key_id' => $replacementKey->id,
                        'ip' => '10.255.255.98',
                        'user' => 'post-proof-operator',
                        'port' => 2223,
                    ]);
                }

                return Process::result(output: '11111111-2222-3333-4444-555555555555');
            })(),
            str_contains($payload, '{{json .Config.Env}}') => Process::result(
                output: json_encode(['COOLIFY_DEPLOYMENT_RELEASE_PROOF='.$releaseProof], JSON_THROW_ON_ERROR),
            ),
            str_contains($payload, 'curl --config -') => Process::result(output: "HTTP/1.1 200 OK\r\n"
                .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$plan->publicAcknowledgement}\r\n"
                .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n"),
            default => throw new RuntimeException('Unexpected connection-drift routing topology rehydration process.'),
        };
    });

    expect(fn () => RehydrateBlueGreenDestinationRoutingTopologyDigest::run($managed['state']))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'attestation no longer matches the exact locked destination snapshot')
        ->and($managed['state']->fresh()->destination_routing_topology_digest)->toBeNull();
});

it('leaves the DB routing digest null when the strict live route proof fails', function (): void {
    $fixture = makeTopologyDigestFixture('operator-rehydration-live-mismatch');
    $managed = seedManagedTopologyState($fixture);
    InspectBlueGreenContainer::shouldRun()->never();
    Process::fake(static function (PendingProcess $process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return match (true) {
            str_contains($command, '/proc/sys/kernel/random/boot_id') => Process::result(
                output: '11111111-2222-3333-4444-555555555555',
            ),
            str_contains($command, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:absent',
            ),
            default => throw new RuntimeException('Unexpected failed routing topology rehydration process.'),
        };
    });

    expect(fn () => RehydrateBlueGreenDestinationRoutingTopologyDigest::run($managed['state']))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'live managed route does not match')
        ->and($managed['state']->fresh()->destination_routing_topology_digest)->toBeNull();
});

it('does not establish a routing digest for an absent destination', function (): void {
    $fixture = makeTopologyDigestFixture('operator-rehydration-absent');
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'phase' => BlueGreenDeploymentPhase::IDLE,
    ]);
    expect(fn () => RehydrateBlueGreenDestinationRoutingTopologyDigest::run($state))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'Stopped or absent destinations')
        ->and($state->fresh()->destination_routing_topology_digest)->toBeNull();
});

it('classifies a clean stopped absent route for claim establishment across connection rotation', function (): void {
    $fixture = makeTopologyDigestFixture('stopped-absent-claim-establishment');
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'phase' => BlueGreenDeploymentPhase::STOPPED,
    ]);
    rotateTopologyServerConnection($fixture['server']);

    $auditBefore = Artisan::call('blue-green:audit-topology-digests', [
        '--application-id' => (string) $fixture['application']->id,
    ]);
    $auditBeforeOutput = Artisan::output();
    $rehydrateBefore = Artisan::call('blue-green:rehydrate-routing-topology', [
        '--state-id' => (string) $state->id,
    ]);
    $rehydrateBeforeOutput = Artisan::output();
    $digestAfterDeferredRehydration = $state->fresh()->destination_routing_topology_digest;
    $claim = ClaimBlueGreenDeployment::run(
        application: $fixture['application']->fresh(['settings']),
        standaloneDocker: $fixture['destination']->fresh(),
        deployment: $fixture['deployment']->fresh(),
        serverBootId: '11111111-2222-3333-4444-555555555555',
    );
    rotateTopologyServerConnection($fixture['server']->fresh());
    $auditAfter = Artisan::call('blue-green:audit-topology-digests', [
        '--application-id' => (string) $fixture['application']->id,
    ]);
    $auditAfterOutput = Artisan::output();

    expect($auditBefore)->toBe(SymfonyCommand::SUCCESS)
        ->and($auditBeforeOutput)->toContain('claim_establishes=1')
        ->and($auditBeforeOutput)->toContain('claim-establishes')
        ->and($rehydrateBefore)->toBe(SymfonyCommand::SUCCESS)
        ->and($rehydrateBeforeOutput)->toContain('Deferred clean absent blue-green state')
        ->and($digestAfterDeferredRehydration)->toBeNull()
        ->and($claim->routingTopologyDigest)->toBe(
            (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor(
                $fixture['application']->fresh(['settings']),
                $fixture['destination']->fresh(),
            ),
        )
        ->and($state->fresh()->destination_routing_topology_digest)->toBe($claim->routingTopologyDigest)
        ->and($auditAfter)->toBe(SymfonyCommand::SUCCESS)
        ->and($auditAfterOutput)->toContain('current=1')
        ->and($auditAfterOutput)->toContain('claim_establishes=0');
});

it('establishes the routing digest for a fenced stopped destination without demanding an impossible attestation', function (): void {
    $fixture = makeTopologyDigestFixture('stopped-fenced-claim-establishment');
    $managed = seedManagedTopologyState($fixture);
    $managed['state']->update([
        'phase' => BlueGreenDeploymentPhase::STOPPED,
        'active_color' => null,
        'blue_deployment_uuid' => null,
        'green_deployment_uuid' => null,
        'managed_file_sha256' => null,
    ]);
    rotateTopologyServerConnection($fixture['server']);

    $claim = ClaimBlueGreenDeployment::run(
        application: $fixture['application']->fresh(['settings']),
        standaloneDocker: $fixture['destination']->fresh(),
        deployment: $fixture['deployment']->fresh(),
        serverBootId: '11111111-2222-3333-4444-555555555555',
    );

    expect($claim->routingTopologyDigest)->toBe(
        (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor(
            $fixture['application']->fresh(['settings']),
            $fixture['destination']->fresh(),
        ),
    )->and($managed['state']->fresh()->destination_routing_topology_digest)->toBe($claim->routingTopologyDigest);
});

it('rejects non-canonical topology digest command selectors before querying', function (): void {
    $rehydrateExit = Artisan::call('blue-green:rehydrate-routing-topology', ['--state-id' => '0']);
    $rehydrateOutput = Artisan::output();
    $auditExit = Artisan::call('blue-green:audit-topology-digests', ['--application-id' => '01']);
    $auditOutput = Artisan::output();

    expect($rehydrateExit)->toBe(SymfonyCommand::INVALID)
        ->and($rehydrateOutput)->toContain('canonical positive integers')
        ->and($auditExit)->toBe(SymfonyCommand::INVALID)
        ->and($auditOutput)->toContain('canonical positive integer');
});
