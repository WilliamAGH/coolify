<?php

use App\Actions\Application\BlueGreen\AttestBlueGreenLegacyRouteNetworkIdentity;
use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\BlueGreenSteadyStatePlan;
use App\Actions\Application\BlueGreen\BlueGreenTopologyLock;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\DeactivateBlueGreenApplication;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\PlanBlueGreenSteadyState;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
use App\Actions\Application\BlueGreen\RehydrateBlueGreenDestinationRoutingTopologyDigest;
use App\Actions\Application\BlueGreen\RetireBlueGreenInactiveContainer;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\BlueGreenFleetStatus;
use App\Enums\ContainerStatusTypes;
use App\Enums\ProxyTypes;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BlueGreenRecoveryScenario;

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL is required for this feature file.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
});

it('live-attests a pre-migration passive retirement on PostgreSQL without replacing its durable provenance', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $application = $scenario->application->fresh(['settings']);
    $destination = $scenario->destination->fresh();
    $owner = $scenario->deployment;
    $inactiveContainerId = BlueGreenRecoveryScenario::LEGACY_ID;
    $backendPortInventory = BlueGreenBackendPortInventory::fromPorts([3000]);
    $topologyDigest = (string) $scenario->state->destination_topology_digest;
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
            routingRevision: 2,
            mode: BlueGreenRoutingMode::Steady,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($owner->deployment_uuid),
            destinationFenceEpoch: 2,
            operationId: $owner->deployment_uuid,
            mutationSequence: 1,
            activeDeploymentUuid: $owner->deployment_uuid,
            activeContainerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
            destinationTopologyDigest: $topologyDigest,
        ),
    );
    $owner->update([
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 2,
        'blue_green_destination_fence_epoch' => 2,
        'blue_green_topology_digest' => $topologyDigest,
        'blue_green_routing_config_digest' => $configuration->routingConfigDigest,
        'blue_green_backend_port_inventory' => $backendPortInventory->serialized,
        'blue_green_drain_backend_port_inventory' => $backendPortInventory->serialized,
    ]);
    $inactive = postgresBlueGreenMultiDestinationQueue(
        $application,
        $destination,
        $scenario->server,
        'pre-migration-passive-retirement-inactive',
        ApplicationDeploymentStatus::FINISHED->value,
    );
    $inactive->update([
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_topology_digest' => $topologyDigest,
        'blue_green_routing_config_digest' => $configuration->routingConfigDigest,
        'blue_green_backend_port_inventory' => $backendPortInventory->serialized,
        'blue_green_candidate_container_id' => $inactiveContainerId,
    ]);
    $bootId = '11111111-2222-3333-4444-555555555555';
    $scenario->state->update([
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => $owner->deployment_uuid,
        'green_deployment_uuid' => $inactive->deployment_uuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
        'supersession_generation' => 1,
        'destination_fence_epoch' => 2,
        'destination_fence_operation_id' => $owner->deployment_uuid,
        'destination_fence_mutation_sequence' => 1,
        'managed_file_sha256' => $configuration->sha256,
        'destination_topology_digest' => $topologyDigest,
        'destination_routing_topology_digest' => null,
        'application_routing_config_digest' => $configuration->routingConfigDigest,
        'inactive_retirement_owner_deployment_uuid' => $owner->deployment_uuid,
        'inactive_retirement_color' => BlueGreenDeploymentColor::GREEN,
        'inactive_retirement_deployment_uuid' => $inactive->deployment_uuid,
        'inactive_retirement_container_id' => $inactiveContainerId,
        'inactive_retirement_container_routing_revision' => 1,
        'inactive_retirement_owner_routing_revision' => 2,
        'inactive_retirement_supersession_generation' => 1,
        'inactive_retirement_destination_fence_epoch' => 2,
        'inactive_retirement_server_boot_id' => $bootId,
        'inactive_retirement_topology_digest' => $topologyDigest,
        'inactive_retirement_routing_config_digest' => $configuration->routingConfigDigest,
        'inactive_retirement_not_before_at' => now()->subSecond(),
        'inactive_retirement_drain_deadline_at' => now()->addMinute(),
        'inactive_retirement_stop_grace_seconds' => 30,
        'inactive_retirement_lease_seconds' => 4_000,
        'inactive_retirement_dispatch_reserved_until_at' => now()->addSeconds(30),
    ]);
    $state = $scenario->state->fresh();
    $plan = PlanBlueGreenSteadyState::run($application, $destination, $state);
    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken($owner->deployment_uuid);
    $preservedAttributes = array_values(array_diff(
        array_keys(ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes()),
        ['inactive_retirement_last_observed_connections', 'inactive_retirement_observed_at', 'inactive_retirement_stopped_at'],
    ));
    $before = collect([...$preservedAttributes, 'supersession_generation'])
        ->mapWithKeys(static fn (string $attribute): array => [$attribute => $state->getRawOriginal($attribute)])
        ->all();
    $activeInspection = new BlueGreenContainerInspection(
        exists: true,
        dockerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
        status: ContainerStatusTypes::RUNNING->value,
        health: 'healthy',
    );
    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturn(
            $activeInspection,
            new BlueGreenContainerInspection(
                exists: true,
                dockerId: $inactiveContainerId,
                status: ContainerStatusTypes::EXITED->value,
                health: 'healthy',
            ),
        );
    Process::fake(static function (PendingProcess $process) use (
        $bootId,
        $configuration,
        $destination,
        $plan,
        $releaseProof,
    ) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $payload = $command."\n".(string) $process->input;

        return match (true) {
            str_contains($command, 'coolify-blue-green-destination-state-attested') => Process::result(
                output: 'coolify-blue-green-destination-state-attested',
            ),
            str_contains($command, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($configuration->state->serialize())."\n"
                    .$configuration->state->managedSha256,
            ),
            str_contains($payload, 'coolify-blue-green-route-network-proof:') => Process::result(
                output: 'coolify-blue-green-route-network-proof:'
                    .BlueGreenRecoveryScenario::CANDIDATE_ID."\t"
                    .json_encode([$destination->network => []], JSON_THROW_ON_ERROR),
            ),
            str_contains($command, '/proc/sys/kernel/random/boot_id') => Process::result(output: $bootId),
            str_contains($command, '{{json .Config.Env}}') => Process::result(
                output: json_encode(['COOLIFY_DEPLOYMENT_RELEASE_PROOF='.$releaseProof], JSON_THROW_ON_ERROR),
            ),
            str_contains($command, 'curl --config -') => Process::result(output: "HTTP/1.1 200 OK\r\n"
                .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$plan->publicAcknowledgement}\r\n"
                .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n"),
            default => throw new RuntimeException('Unexpected passive retirement rehydration process.'),
        };
    });

    expect(RetireBlueGreenInactiveContainer::run($state->id, 'foreign-retirement-owner', 1))
        ->toBe(RetireBlueGreenInactiveContainer::STALE)
        ->and($state->fresh()->destination_routing_topology_digest)->toBeNull();
    $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 1);
    $state = $state->fresh();
    $after = collect(array_keys($before))
        ->mapWithKeys(static fn (string $attribute): array => [$attribute => $state->getRawOriginal($attribute)])
        ->all();

    expect($result)->toBe(RetireBlueGreenInactiveContainer::COMPLETED)
        ->and($state->destination_routing_topology_digest)
        ->toBe((new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor($application, $destination))
        ->and($after)->toBe($before)
        ->and($state->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull();
});

/** @return array{application: Application, destination: StandaloneDocker, server: Server, team: Team} */
function postgresBlueGreenMultiDestinationFixture(): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://postgres-blue-green-fleet.example.test',
        'health_check_enabled' => true,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'ports_mappings' => null,
        'custom_docker_run_options' => null,
    ]);
    $application->settings()->firstOrFail()->update([
        'is_blue_green_deployment_enabled' => true,
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
        'custom_internal_name' => null,
    ]);

    return compact('application', 'destination', 'server', 'team');
}

/** @return array{destination: StandaloneDocker, server: Server} */
function postgresBlueGreenMultiDestinationAdditional(Team $team, string $suffix): array
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = StandaloneDocker::factory()->create([
        'server_id' => $server->id,
        'name' => "postgres-blue-green-{$suffix}",
        'network' => "postgres-blue-green-{$suffix}",
    ]);

    return compact('destination', 'server');
}

function postgresBlueGreenMultiDestinationQueue(
    Application $application,
    StandaloneDocker $destination,
    Server $server,
    string $deploymentUuid,
    string $status,
): ApplicationDeploymentQueue {
    return ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $deploymentUuid,
        'pull_request_id' => 0,
        'commit' => $deploymentUuid,
        'status' => $status,
        'only_this_server' => true,
    ]);
}

/**
 * @return array{application: Application, destination: StandaloneDocker, expectedState: BlueGreenProxyState, state: ApplicationBlueGreenDeployment}
 */
function postgresBlueGreenManagedRoutingTopologyFixture(): array
{
    Process::fake();
    Storage::fake('ssh-keys');
    $fixture = postgresBlueGreenMultiDestinationFixture();
    $fixture['server']->privateKey->storeInFileSystem();
    $application = $fixture['application']->fresh(['settings']);
    $destination = $fixture['destination']->fresh();
    $previousDeploymentUuid = 'postgres-routing-topology-previous';
    $previousContainerId = str_repeat('a', 64);
    $legacyDigest = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::BLUE,
        1,
        1,
        $previousDeploymentUuid,
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
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($previousDeploymentUuid),
            destinationFenceEpoch: 1,
            operationId: $previousDeploymentUuid,
            mutationSequence: 2,
            activeDeploymentUuid: $previousDeploymentUuid,
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
        'deployment_uuid' => $previousDeploymentUuid,
        'pull_request_id' => 0,
        'commit' => 'postgres-routing-topology-previous',
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
        'blue_deployment_uuid' => $previousDeploymentUuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
        'supersession_generation' => 1,
        'destination_fence_epoch' => 1,
        'destination_fence_operation_id' => $previousDeploymentUuid,
        'destination_fence_mutation_sequence' => 2,
        'managed_file_sha256' => $configuration->sha256,
        'destination_topology_digest' => $legacyDigest,
        'application_routing_config_digest' => $configuration->routingConfigDigest,
    ]);

    return [
        'application' => $application,
        'destination' => $destination,
        'expectedState' => $configuration->state,
        'state' => $state,
    ];
}

function postgresBlueGreenFakeRoutingTopologyRehydration(
    BlueGreenProxyState $expectedState,
    BlueGreenSteadyStatePlan $plan,
    string $destinationNetwork,
    ?Closure $beforeFinalManagedRouteRead = null,
    ?Closure $duringNetworkProof = null,
): void {
    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken(
        $plan->activeDeployment->deployment_uuid,
    );
    $managedRouteReads = 0;
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $plan->representativeActiveContainer()->dockerId,
            status: 'running',
            health: 'healthy',
        ));
    Process::fake(static function (PendingProcess $process) use (
        &$managedRouteReads,
        $beforeFinalManagedRouteRead,
        $destinationNetwork,
        $duringNetworkProof,
        $expectedState,
        $plan,
        $releaseProof,
    ) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $payload = $command."\n".(string) $process->input;

        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            $managedRouteReads++;
            if ($managedRouteReads === 3 && $beforeFinalManagedRouteRead !== null) {
                $beforeFinalManagedRouteRead();
            }

            return Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($expectedState->serialize())."\n"
                    .$expectedState->managedSha256,
            );
        }

        return match (true) {
            str_contains($payload, '{{json .Config.Env}}') => Process::result(
                output: json_encode(['COOLIFY_DEPLOYMENT_RELEASE_PROOF='.$releaseProof], JSON_THROW_ON_ERROR),
            ),
            str_contains($payload, 'coolify-blue-green-route-network-proof:') => (static function () use (
                $destinationNetwork,
                $duringNetworkProof,
                $plan,
            ) {
                $duringNetworkProof?->__invoke();

                return Process::result(
                    output: 'coolify-blue-green-route-network-proof:'
                        .$plan->representativeActiveContainer()->dockerId."\t"
                        .json_encode([$destinationNetwork => []], JSON_THROW_ON_ERROR),
                );
            })(),
            str_contains($payload, '/proc/sys/kernel/random/boot_id') => Process::result(
                output: '11111111-2222-3333-4444-555555555555',
            ),
            str_contains($payload, 'curl --config -') => Process::result(output: "HTTP/1.1 200 OK\r\n"
                .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$plan->publicAcknowledgement}\r\n"
                .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n"),
            default => throw new RuntimeException('Unexpected routing topology rehydration process.'),
        };
    });
}

function postgresBlueGreenWaitForLock(string $applicationName): bool
{
    $deadline = microtime(true) + 5;
    do {
        $activity = DB::selectOne(
            'select wait_event_type from pg_stat_activity where application_name = ?',
            [$applicationName],
        );
        if (($activity->wait_event_type ?? null) === 'Lock') {
            return true;
        }
        usleep(25_000);
    } while (microtime(true) < $deadline);

    return false;
}

it('fences query-style historical destination network drift before lazy routing digest establishment', function (): void {
    $managed = postgresBlueGreenManagedRoutingTopologyFixture();
    $application = $managed['application'];
    $destination = $managed['destination'];
    $previousNetwork = (string) $destination->network;
    $changedNetwork = 'postgres-routing-topology-drifted';
    $updated = $destination->newQuery()
        ->whereKey($destination->id)
        ->update(['network' => $changedNetwork]);
    $pending = postgresBlueGreenMultiDestinationQueue(
        $application,
        $destination,
        $destination->server,
        'postgres-routing-topology-drift-claim',
        ApplicationDeploymentStatus::IN_PROGRESS->value,
    );
    $previous = new BlueGreenContainerExpectation(
        name: $application->uuid.'-blue',
        dockerId: $managed['expectedState']->activeContainerId,
        applicationId: (int) $application->id,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: (string) $managed['expectedState']->activeDeploymentUuid,
        color: BlueGreenDeploymentColor::BLUE,
        routingRevision: 1,
    );
    Process::fake(static function (PendingProcess $process) use ($managed, $previousNetwork) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $payload = $command."\n".(string) $process->input;

        return match (true) {
            str_contains($payload, 'coolify-blue-green-route-network-proof:') => Process::result(
                output: 'coolify-blue-green-route-network-proof:'
                    .$managed['expectedState']->activeContainerId."\t"
                    .json_encode([$previousNetwork => []], JSON_THROW_ON_ERROR),
            ),
            str_contains($payload, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($managed['expectedState']->serialize())."\n"
                    .$managed['expectedState']->managedSha256,
            ),
            str_contains($payload, '/proc/sys/kernel/random/boot_id') => Process::result(
                output: '11111111-2222-3333-4444-555555555555',
            ),
            default => throw new RuntimeException('Unexpected historical routing topology drift process.'),
        };
    });
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key((int) $application->id, (int) $destination->id),
        BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
    );
    expect($updated)->toBe(1)
        ->and($destination->fresh()->network)->toBe($changedNetwork)
        ->and($lock->get())->toBeTrue();
    $fence = new BlueGreenOperationFence($lock, BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS);

    try {
        expect(function () use ($application, $destination, $fence, $managed, $pending, $previous): void {
            $lockedApplication = $application->fresh(['settings']);
            $lockedDestination = $destination->fresh(['server']);
            $attestation = AttestBlueGreenLegacyRouteNetworkIdentity::run(
                server: $lockedDestination->server,
                application: $lockedApplication,
                destination: $lockedDestination,
                routeState: $managed['expectedState'],
                expectedServerBootId: '11111111-2222-3333-4444-555555555555',
                operationFence: $fence,
            );
            ClaimBlueGreenDeployment::run(
                application: $lockedApplication,
                standaloneDocker: $lockedDestination,
                deployment: $pending->fresh(),
                serverBootId: '11111111-2222-3333-4444-555555555555',
                previousContainer: $previous,
                legacyRouteNetworkAttestation: $attestation,
            );
        })->toThrow(
            BlueGreenDeploymentTransitionException::class,
            'historical destination network drift must be reconciled',
        );
    } finally {
        $fence->releaseIfOwned();
    }

    expect($managed['state']->fresh()->destination_routing_topology_digest)->toBeNull()
        ->and($managed['state']->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($pending->fresh()->blue_green_phase)->toBeNull();
});

it('runs legacy route network proof without an open transaction or advisory lock', function (): void {
    $managed = postgresBlueGreenManagedRoutingTopologyFixture();
    $plan = PlanBlueGreenSteadyState::run(
        $managed['application'],
        $managed['destination'],
        $managed['state'],
    );
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key((int) $managed['application']->id, (int) $managed['destination']->id),
        BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
    );
    expect($lock->get())->toBeTrue();
    $fence = new BlueGreenOperationFence($lock, BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS);
    DB::beginTransaction();

    try {
        expect(fn () => AttestBlueGreenLegacyRouteNetworkIdentity::run(
            server: $managed['destination']->server,
            application: $managed['application'],
            destination: $managed['destination'],
            routeState: $managed['expectedState'],
            expectedServerBootId: '11111111-2222-3333-4444-555555555555',
            operationFence: $fence,
        ))->toThrow(
            BlueGreenDeploymentTransitionException::class,
            'Legacy route network proof must run outside PostgreSQL transactions and topology advisory locks.',
        );
        Process::assertNothingRan();
    } finally {
        DB::rollBack();
        $fence->releaseIfOwned();
    }

    $transactionLevels = [];
    $advisoryLockCounts = [];
    postgresBlueGreenFakeRoutingTopologyRehydration(
        expectedState: $managed['expectedState'],
        plan: $plan,
        destinationNetwork: $managed['destination']->network,
        duringNetworkProof: static function () use (&$advisoryLockCounts, &$transactionLevels): void {
            $transactionLevels[] = DB::transactionLevel();
            $advisoryLockCounts[] = (int) DB::scalar(<<<'SQL'
                select count(*)
                from pg_locks
                where pid = pg_backend_pid()
                  and locktype = 'advisory'
                  and granted
                SQL);
        },
    );

    $rehydrated = RehydrateBlueGreenDestinationRoutingTopologyDigest::run($managed['state']);

    expect($transactionLevels)->toBe([0])
        ->and($advisoryLockCounts)->toBe([0])
        ->and($rehydrated->destination_routing_topology_digest)->toBe(
            (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor(
                $managed['application'],
                $managed['destination'],
            ),
        );
});

it('serializes direct cross-table topology writes through the destination reservation relation', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('The pcntl extension is required for this concurrency test.');
    }
    $fixture = postgresBlueGreenMultiDestinationFixture();
    $additional = postgresBlueGreenMultiDestinationAdditional($fixture['team'], 'reservation');
    $competingPrimary = StandaloneDocker::factory()->create([
        'server_id' => $additional['server']->id,
        'name' => 'postgres-blue-green-competing-primary',
        'network' => 'postgres-blue-green-competing-primary',
    ]);
    $resultPath = tempnam(sys_get_temp_dir(), 'coolify-topology-race-');
    if ($resultPath === false) {
        throw new RuntimeException('Unable to allocate a topology concurrency result file.');
    }
    $applicationName = 'coolify-topology-race-'.bin2hex(random_bytes(8));
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($sockets === false) {
        unlink($resultPath);
        throw new RuntimeException('Unable to allocate topology synchronization sockets.');
    }
    $processId = null;
    $transactionStarted = false;
    DB::disconnect();

    try {
        $processId = pcntl_fork();
        if ($processId === -1) {
            throw new RuntimeException('Unable to fork the topology contender.');
        }
        if ($processId === 0) {
            fclose($sockets[0]);
            try {
                DB::purge();
                DB::selectOne("select set_config('application_name', ?, false)", [$applicationName]);
                fwrite($sockets[1], 'R');
                if (fread($sockets[1], 1) !== '1') {
                    throw new RuntimeException('The topology contender was not released.');
                }
                DB::table('applications')
                    ->where('id', $fixture['application']->id)
                    ->update([
                        'destination_id' => $competingPrimary->id,
                        'destination_type' => $competingPrimary->getMorphClass(),
                    ]);
                $payload = ['updated' => true];
            } catch (Throwable $throwable) {
                $payload = [
                    'updated' => false,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ];
            }
            file_put_contents($resultPath, json_encode($payload, JSON_THROW_ON_ERROR));
            fclose($sockets[1]);
            exit(0);
        }

        fclose($sockets[1]);
        if (fread($sockets[0], 1) !== 'R') {
            throw new RuntimeException('The topology contender did not become ready.');
        }
        DB::purge();
        DB::beginTransaction();
        $transactionStarted = true;
        DB::table('additional_destinations')->insert([
            'application_id' => $fixture['application']->id,
            'server_id' => $additional['server']->id,
            'standalone_docker_id' => $additional['destination']->id,
        ]);
        fwrite($sockets[0], '1');

        $blocked = postgresBlueGreenWaitForLock($applicationName);
        DB::commit();
        $transactionStarted = false;
        pcntl_waitpid($processId, $status);
        $processId = null;
        $payload = json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);

        expect($blocked)->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0)
            ->and($payload['updated'])->toBeFalse()
            ->and($payload['message'])->toContain('one destination per server')
            ->and(DB::table('application_destination_reservations')
                ->where('application_id', $fixture['application']->id)
                ->count())->toBe(2)
            ->and((int) $fixture['application']->fresh()->destination_id)->toBe($fixture['destination']->id);
    } finally {
        if ($transactionStarted && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($processId !== null && $processId > 0) {
            pcntl_waitpid($processId, $status);
        }
        if (is_resource($sockets[0])) {
            fclose($sockets[0]);
        }
        if (is_resource($sockets[1])) {
            fclose($sockets[1]);
        }
        DB::reconnect();
        unlink($resultPath);
    }
});

it('never remotely deactivates a removal target promoted by a concurrent topology transaction', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('The pcntl extension is required for this concurrency test.');
    }
    $fixture = postgresBlueGreenMultiDestinationFixture();
    $additional = postgresBlueGreenMultiDestinationAdditional($fixture['team'], 'removal-promotion');
    $fixture['application']->additional_networks()->attach($additional['destination']->id, [
        'server_id' => $additional['server']->id,
    ]);
    $resultPath = tempnam(sys_get_temp_dir(), 'coolify-removal-promotion-race-');
    if ($resultPath === false) {
        throw new RuntimeException('Unable to allocate a removal-promotion concurrency result file.');
    }
    $applicationName = 'coolify-removal-promotion-race-'.bin2hex(random_bytes(8));
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($sockets === false) {
        unlink($resultPath);
        throw new RuntimeException('Unable to allocate removal-promotion synchronization sockets.');
    }
    $processId = null;
    $transactionStarted = false;
    DB::disconnect();

    try {
        $processId = pcntl_fork();
        if ($processId === -1) {
            throw new RuntimeException('Unable to fork the removal contender.');
        }
        if ($processId === 0) {
            fclose($sockets[0]);
            try {
                DB::purge();
                DB::selectOne("select set_config('application_name', ?, false)", [$applicationName]);
                Process::fake();
                fwrite($sockets[1], 'R');
                if (fread($sockets[1], 1) !== '1') {
                    throw new RuntimeException('The removal contender was not released.');
                }
                try {
                    DeactivateBlueGreenApplication::make()->removeDestination(
                        Application::query()->findOrFail($fixture['application']->id),
                        $additional['destination']->id,
                        $additional['server']->id,
                    );
                    $payload = [
                        'completed' => true,
                        'remote_mutation_ran' => null,
                    ];
                } catch (Throwable $throwable) {
                    $remoteMutationRan = false;
                    try {
                        Process::assertNothingRan();
                    } catch (Throwable) {
                        $remoteMutationRan = true;
                    }
                    $payload = [
                        'completed' => false,
                        'remote_mutation_ran' => $remoteMutationRan,
                        'exception' => $throwable::class,
                        'message' => $throwable->getMessage(),
                    ];
                }
            } catch (Throwable $throwable) {
                $payload = [
                    'completed' => false,
                    'remote_mutation_ran' => null,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ];
            }
            file_put_contents($resultPath, json_encode($payload, JSON_THROW_ON_ERROR));
            fclose($sockets[1]);
            exit(0);
        }

        fclose($sockets[1]);
        if (fread($sockets[0], 1) !== 'R') {
            throw new RuntimeException('The removal contender did not become ready.');
        }
        DB::purge();
        DB::beginTransaction();
        $transactionStarted = true;
        BlueGreenTopologyLock::acquire();
        DB::table('additional_destinations')
            ->where('application_id', $fixture['application']->id)
            ->where('standalone_docker_id', $additional['destination']->id)
            ->where('server_id', $additional['server']->id)
            ->delete();
        DB::table('applications')
            ->where('id', $fixture['application']->id)
            ->update([
                'destination_id' => $additional['destination']->id,
                'destination_type' => $additional['destination']->getMorphClass(),
            ]);
        DB::table('additional_destinations')->insert([
            'application_id' => $fixture['application']->id,
            'server_id' => $fixture['server']->id,
            'standalone_docker_id' => $fixture['destination']->id,
        ]);
        fwrite($sockets[0], '1');

        $blocked = postgresBlueGreenWaitForLock($applicationName);
        DB::commit();
        $transactionStarted = false;
        pcntl_waitpid($processId, $status);
        $processId = null;
        $payload = json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);

        expect($blocked)->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0)
            ->and($payload['completed'])->toBeFalse()
            ->and($payload['remote_mutation_ran'])->toBeFalse()
            ->and($payload['message'])->toContain('became the application primary')
            ->and((int) $fixture['application']->fresh()->destination_id)->toBe($additional['destination']->id);
    } finally {
        if ($transactionStarted && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($processId !== null && $processId > 0) {
            pcntl_waitpid($processId, $status);
        }
        if (is_resource($sockets[0])) {
            fclose($sockets[0]);
        }
        if (is_resource($sockets[1])) {
            fclose($sockets[1]);
        }
        DB::reconnect();
        unlink($resultPath);
    }
});

it('publishes exact drain recovery fleet failure with typed postgres ownership bindings', function (): void {
    Notification::fake();
    $fixture = postgresBlueGreenMultiDestinationFixture();
    $failed = postgresBlueGreenMultiDestinationAdditional($fixture['team'], 'drain-failure');
    $pending = postgresBlueGreenMultiDestinationAdditional($fixture['team'], 'drain-pending');
    $fixture['application']->additional_networks()->attach($failed['destination']->id, ['server_id' => $failed['server']->id]);
    $fixture['application']->additional_networks()->attach($pending['destination']->id, ['server_id' => $pending['server']->id]);
    $owner = postgresBlueGreenMultiDestinationQueue(
        $fixture['application'],
        $fixture['destination'],
        $fixture['server'],
        'postgres-drain-fleet-owner',
        ApplicationDeploymentStatus::FINISHED->value,
    );
    $owner->update([
        'blue_green_fleet_deployment_uuid' => $owner->deployment_uuid,
        'blue_green_fleet_status' => BlueGreenFleetStatus::ACTIVE,
    ]);
    $failedDeployment = postgresBlueGreenMultiDestinationQueue(
        $fixture['application'],
        $failed['destination'],
        $failed['server'],
        'postgres-drain-fleet-failed',
        ApplicationDeploymentStatus::IN_PROGRESS->value,
    );
    $pendingDeployment = postgresBlueGreenMultiDestinationQueue(
        $fixture['application'],
        $pending['destination'],
        $pending['server'],
        'postgres-drain-fleet-pending',
        ApplicationDeploymentStatus::QUEUED->value,
    );
    $failedDeployment->update([
        'blue_green_fleet_deployment_uuid' => $owner->deployment_uuid,
        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'blue_green_supersession_generation' => 1,
    ]);
    $pendingDeployment->update(['blue_green_fleet_deployment_uuid' => $owner->deployment_uuid]);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $failed['destination']->id,
        'operation_deployment_uuid' => $failedDeployment->deployment_uuid,
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'routing_revision' => 1,
        'supersession_generation' => 1,
    ]);
    $job = new ApplicationDeploymentJob($failedDeployment->id);
    foreach ([
        'application' => $fixture['application']->fresh(['destination.server', 'settings']),
        'application_deployment_queue' => $failedDeployment->fresh(),
        'destination' => $failed['destination'],
        'server' => $failed['server'],
    ] as $property => $value) {
        (new ReflectionProperty($job, $property))->setValue($job, $value);
    }

    $job->failBlueGreenDrainRecovery(new RuntimeException('PostgreSQL drain recovery requires intervention.'));

    expect($failedDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($failedDeployment->fresh()->finished_at)->not->toBeNull()
        ->and($pendingDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value)
        ->and($owner->fresh()->blue_green_fleet_status)->toBe(BlueGreenFleetStatus::PAUSED)
        ->and($fixture['application']->fresh()->additional_networks()
            ->whereKey($failed['destination']->id)
            ->firstOrFail()->pivot->status)->toBe('degraded:unknown')
        ->and($pendingDeployment->claimForDispatch(bypassServerCapacity: true))->toBeFalse();
});

it('serializes a fleet failure publication before a sibling dispatch claim', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('The pcntl extension is required for this concurrency test.');
    }
    $fixture = postgresBlueGreenMultiDestinationFixture();
    $failed = postgresBlueGreenMultiDestinationAdditional($fixture['team'], 'failure');
    $pending = postgresBlueGreenMultiDestinationAdditional($fixture['team'], 'pending');
    $fixture['application']->additional_networks()->attach($failed['destination']->id, ['server_id' => $failed['server']->id]);
    $fixture['application']->additional_networks()->attach($pending['destination']->id, ['server_id' => $pending['server']->id]);
    $owner = postgresBlueGreenMultiDestinationQueue(
        $fixture['application'],
        $fixture['destination'],
        $fixture['server'],
        'postgres-fleet-owner',
        ApplicationDeploymentStatus::FINISHED->value,
    );
    $owner->update([
        'blue_green_fleet_deployment_uuid' => $owner->deployment_uuid,
        'blue_green_fleet_status' => BlueGreenFleetStatus::ACTIVE,
    ]);
    $failedDeployment = postgresBlueGreenMultiDestinationQueue(
        $fixture['application'],
        $failed['destination'],
        $failed['server'],
        'postgres-fleet-failed',
        ApplicationDeploymentStatus::IN_PROGRESS->value,
    );
    $pendingDeployment = postgresBlueGreenMultiDestinationQueue(
        $fixture['application'],
        $pending['destination'],
        $pending['server'],
        'postgres-fleet-pending',
        ApplicationDeploymentStatus::QUEUED->value,
    );
    $failedDeployment->update(['blue_green_fleet_deployment_uuid' => $owner->deployment_uuid]);
    $pendingDeployment->update(['blue_green_fleet_deployment_uuid' => $owner->deployment_uuid]);

    $resultPath = tempnam(sys_get_temp_dir(), 'coolify-fleet-race-');
    if ($resultPath === false) {
        throw new RuntimeException('Unable to allocate a fleet concurrency result file.');
    }
    $applicationName = 'coolify-fleet-race-'.bin2hex(random_bytes(8));
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($sockets === false) {
        unlink($resultPath);
        throw new RuntimeException('Unable to allocate fleet synchronization sockets.');
    }
    $processId = null;
    $transactionStarted = false;
    DB::disconnect();

    try {
        $processId = pcntl_fork();
        if ($processId === -1) {
            throw new RuntimeException('Unable to fork the fleet claim contender.');
        }
        if ($processId === 0) {
            fclose($sockets[0]);
            try {
                DB::purge();
                DB::selectOne("select set_config('application_name', ?, false)", [$applicationName]);
                fwrite($sockets[1], 'R');
                if (fread($sockets[1], 1) !== '1') {
                    throw new RuntimeException('The fleet contender was not released.');
                }
                $claimed = ApplicationDeploymentQueue::query()
                    ->findOrFail($pendingDeployment->id)
                    ->claimForDispatch(bypassServerCapacity: true);
                $payload = ['claimed' => $claimed];
            } catch (Throwable $throwable) {
                $payload = [
                    'claimed' => null,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ];
            }
            file_put_contents($resultPath, json_encode($payload, JSON_THROW_ON_ERROR));
            fclose($sockets[1]);
            exit(0);
        }

        fclose($sockets[1]);
        if (fread($sockets[0], 1) !== 'R') {
            throw new RuntimeException('The fleet contender did not become ready.');
        }
        DB::purge();
        DB::beginTransaction();
        $transactionStarted = true;
        Application::query()->whereKey($fixture['application']->id)->lockForUpdate()->firstOrFail();
        fwrite($sockets[0], '1');

        $blocked = postgresBlueGreenWaitForLock($applicationName);
        $job = new ApplicationDeploymentJob($failedDeployment->id);
        foreach ([
            'application' => $fixture['application']->fresh(['destination.server', 'settings']),
            'application_deployment_queue' => $failedDeployment->fresh(),
            'destination' => $failed['destination'],
            'server' => $failed['server'],
        ] as $property => $value) {
            (new ReflectionProperty($job, $property))->setValue($job, $value);
        }
        $published = (new ReflectionMethod($job, 'publishBlueGreenFleetFailure'))->invoke($job);
        DB::commit();
        $transactionStarted = false;
        pcntl_waitpid($processId, $status);
        $processId = null;
        $payload = json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);

        expect($blocked)->toBeTrue()
            ->and($published)->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0)
            ->and($payload['claimed'])->toBeFalse()
            ->and($failedDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
            ->and($pendingDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value)
            ->and($owner->fresh()->blue_green_fleet_status)->toBe(BlueGreenFleetStatus::PAUSED);
    } finally {
        if ($transactionStarted && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($processId !== null && $processId > 0) {
            pcntl_waitpid($processId, $status);
        }
        if (is_resource($sockets[0])) {
            fclose($sockets[0]);
        }
        if (is_resource($sockets[1])) {
            fclose($sockets[1]);
        }
        DB::reconnect();
        unlink($resultPath);
    }
});

it('serializes host-proven null routing-topology digest establishment through the global PostgreSQL lock', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('The pcntl extension is required for this concurrency test.');
    }
    $managed = postgresBlueGreenManagedRoutingTopologyFixture();
    $stateId = (int) $managed['state']->id;
    $expectedDigest = (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor(
        $managed['application'],
        $managed['destination'],
    );
    $plan = PlanBlueGreenSteadyState::run(
        $managed['application'],
        $managed['destination'],
        $managed['state'],
    );
    expect($managed['state']->fresh()->destination_routing_topology_digest)->toBeNull();

    $resultPath = tempnam(sys_get_temp_dir(), 'coolify-routing-topology-race-');
    if ($resultPath === false) {
        throw new RuntimeException('Unable to allocate a routing topology concurrency result file.');
    }
    $applicationName = 'coolify-routing-topology-race-'.bin2hex(random_bytes(8));
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($sockets === false) {
        unlink($resultPath);
        throw new RuntimeException('Unable to allocate routing topology synchronization sockets.');
    }
    stream_set_timeout($sockets[0], 15);
    stream_set_timeout($sockets[1], 15);

    $processId = null;
    $transactionStarted = false;
    DB::disconnect();

    try {
        $processId = pcntl_fork();
        if ($processId === -1) {
            throw new RuntimeException('Unable to fork the routing topology contender.');
        }
        if ($processId === 0) {
            fclose($sockets[0]);
            try {
                DB::purge();
                DB::selectOne("select set_config('application_name', ?, false)", [$applicationName]);
                postgresBlueGreenFakeRoutingTopologyRehydration(
                    $managed['expectedState'],
                    $plan,
                    $managed['destination']->network,
                    static function () use ($sockets): void {
                        if (fwrite($sockets[1], 'R') !== 1) {
                            throw new RuntimeException('Unable to signal the final routing topology host proof.');
                        }
                        if (fread($sockets[1], 1) !== '1') {
                            throw new RuntimeException('The routing topology contender was not released into its commit.');
                        }
                    },
                );
                $rehydrated = RehydrateBlueGreenDestinationRoutingTopologyDigest::run(
                    ApplicationBlueGreenDeployment::query()->findOrFail($stateId),
                );
                $payload = [
                    'rehydrated' => true,
                    'routing_topology_digest' => $rehydrated->destination_routing_topology_digest,
                    'message' => null,
                ];
            } catch (Throwable $throwable) {
                $payload = [
                    'rehydrated' => false,
                    'routing_topology_digest' => null,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ];
            }
            $payload['row_version_after'] = DB::selectOne(
                'select xmin::text as row_xmin from application_blue_green_deployments where id = ?',
                [$stateId],
            )->row_xmin;
            file_put_contents($resultPath, json_encode($payload, JSON_THROW_ON_ERROR));
            fclose($sockets[1]);
            exit(0);
        }

        fclose($sockets[1]);
        if (fread($sockets[0], 1) !== 'R') {
            throw new RuntimeException('The routing topology contender did not complete its host proof.');
        }
        DB::purge();
        DB::beginTransaction();
        $transactionStarted = true;
        BlueGreenTopologyLock::acquire();
        $winnerUpdated = ApplicationBlueGreenDeployment::query()
            ->whereKey($stateId)
            ->whereNull('destination_routing_topology_digest')
            ->update(['destination_routing_topology_digest' => $expectedDigest]);
        $winnerDigest = $expectedDigest;
        $winnerRowVersion = DB::selectOne(
            'select xmin::text as row_xmin from application_blue_green_deployments where id = ?',
            [$stateId],
        )->row_xmin;
        fwrite($sockets[0], '1');

        $blocked = postgresBlueGreenWaitForLock($applicationName);
        DB::commit();
        $transactionStarted = false;
        pcntl_waitpid($processId, $status);
        $processId = null;
        $payload = json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);
        $state = ApplicationBlueGreenDeployment::query()->findOrFail($stateId);
        $finalRowVersion = DB::selectOne(
            'select xmin::text as row_xmin from application_blue_green_deployments where id = ?',
            [$stateId],
        )->row_xmin;
        expect($blocked)->toBeTrue()
            ->and($winnerUpdated)->toBe(1)
            ->and($winnerDigest)->toBe($expectedDigest)
            ->and(pcntl_wexitstatus($status))->toBe(0)
            ->and($payload['rehydrated'])->toBeFalse()
            ->and($payload['message'])->toContain('destination changed before routing topology rehydration could commit')
            ->and($payload['row_version_after'])->toBe($winnerRowVersion)
            ->and($state->destination_routing_topology_digest)->toBe($winnerDigest)
            ->and($finalRowVersion)->toBe($winnerRowVersion);
    } finally {
        if ($transactionStarted && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if (is_resource($sockets[0])) {
            fclose($sockets[0]);
        }
        if (is_resource($sockets[1])) {
            fclose($sockets[1]);
        }
        if ($processId !== null && $processId > 0) {
            pcntl_waitpid($processId, $status);
        }
        DB::reconnect();
        unlink($resultPath);
    }
});

it('locks a stale-journal destination before its lifecycle state', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('The pcntl extension is required for this concurrency test.');
    }
    $managed = postgresBlueGreenManagedRoutingTopologyFixture();
    $stateId = (int) $managed['state']->id;
    $destinationId = (int) $managed['destination']->id;
    $resultPath = tempnam(sys_get_temp_dir(), 'coolify-stale-journal-lock-order-');
    if ($resultPath === false) {
        throw new RuntimeException('Unable to allocate a stale-journal lock-order result file.');
    }
    $applicationName = 'coolify-stale-journal-lock-order-'.bin2hex(random_bytes(8));
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($sockets === false) {
        unlink($resultPath);
        throw new RuntimeException('Unable to allocate stale-journal lock-order synchronization sockets.');
    }
    stream_set_timeout($sockets[0], 15);
    stream_set_timeout($sockets[1], 15);

    $processId = null;
    $transactionStarted = false;
    DB::disconnect();

    try {
        $processId = pcntl_fork();
        if ($processId === -1) {
            throw new RuntimeException('Unable to fork the stale-journal lock-order contender.');
        }
        if ($processId === 0) {
            fclose($sockets[0]);
            try {
                DB::purge();
                DB::selectOne("select set_config('application_name', ?, false)", [$applicationName]);
                if (fwrite($sockets[1], 'R') !== 1) {
                    throw new RuntimeException('Unable to signal the stale-journal lock-order contender.');
                }
                if (fread($sockets[1], 1) !== '1') {
                    throw new RuntimeException('The stale-journal lock-order contender was not released.');
                }
                $result = RecoverBlueGreenIntervention::run(
                    stateId: $stateId,
                    staleContainerJournal: true,
                );
                $payload = [
                    'completed' => true,
                    'outcome' => $result->outcome,
                ];
            } catch (Throwable $throwable) {
                $payload = [
                    'completed' => false,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ];
            }
            file_put_contents($resultPath, json_encode($payload, JSON_THROW_ON_ERROR));
            fclose($sockets[1]);
            exit(0);
        }

        fclose($sockets[1]);
        if (fread($sockets[0], 1) !== 'R') {
            throw new RuntimeException('The stale-journal lock-order contender did not become ready.');
        }
        DB::purge();
        DB::beginTransaction();
        $transactionStarted = true;
        StandaloneDocker::query()->whereKey($destinationId)->lockForUpdate()->firstOrFail();
        fwrite($sockets[0], '1');

        $blocked = postgresBlueGreenWaitForLock($applicationName);
        $stateWasLockable = true;
        try {
            DB::selectOne(
                'select id from application_blue_green_deployments where id = ? for update nowait',
                [$stateId],
            );
        } catch (QueryException) {
            $stateWasLockable = false;
        }
        DB::commit();
        $transactionStarted = false;
        pcntl_waitpid($processId, $status);
        $processId = null;
        $payload = json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);

        expect($blocked)->toBeTrue()
            ->and($stateWasLockable)->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0)
            ->and($payload['completed'])->toBeTrue();
    } finally {
        if ($transactionStarted && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($processId !== null && $processId > 0) {
            pcntl_waitpid($processId, $status);
        }
        if (is_resource($sockets[0])) {
            fclose($sockets[0]);
        }
        if (is_resource($sockets[1])) {
            fclose($sockets[1]);
        }
        DB::reconnect();
        unlink($resultPath);
    }
});
