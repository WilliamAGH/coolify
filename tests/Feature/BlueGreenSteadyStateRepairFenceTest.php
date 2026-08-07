<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenLifecycleDatabaseLocks;
use App\Actions\Application\BlueGreen\BlueGreenManagedRouteMetadataForOperationResult;
use App\Actions\Application\BlueGreen\BlueGreenSteadyStateRepairResult;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\PlanBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\PlanBlueGreenSteadyState;
use App\Actions\Application\BlueGreen\RepairBlueGreenSteadyState;
use App\Actions\Application\BlueGreen\RepairBlueGreenSteadyStates;
use App\Actions\Application\BlueGreen\ResolveBlueGreenExpectedProxyState;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::forget('blue-green:steady-repair-cursor');
});

afterEach(function (): void {
    Cache::forget('blue-green:steady-repair-cursor');
});

it('refuses lifecycle row locks without an active database transaction', function (): void {
    DB::partialMock()
        ->shouldReceive('transactionLevel')
        ->once()
        ->andReturn(0);

    expect(fn () => BlueGreenLifecycleDatabaseLocks::forDestination(
        1,
        1,
    ))->toThrow(RuntimeException::class, 'active database transaction');
});

/**
 * One unowned IDLE destination whose durable steady route is exactly the route
 * the repair owner would compile for it, so the only thing left to decide is
 * whether the destination's deactivation history fences the repair.
 *
 * @return array{application: Application, deployment: ApplicationDeploymentQueue, state: ApplicationBlueGreenDeployment}
 */
function makeBlueGreenSteadyRepairFixture(): array
{
    $team = Team::factory()->create();
    Storage::fake('ssh-keys');
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
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
        'fqdn' => 'https://steady-repair-fence.example.test',
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'ports_exposes' => '3000',
        'health_check_enabled' => true,
    ]);
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    $application = $application->fresh(['settings']);

    $activeUuid = 'steady-repair-active-owner';
    $activeContainerId = str_repeat('a', 64);
    $topologyDigest = hash('sha256', 'steady-repair-topology');
    $routingTopologyDigest = (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor(
        $application,
        $destination,
    );
    $configuration = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        new BlueGreenRoutingTarget(
            destinationId: (int) $destination->id,
            activeColor: BlueGreenDeploymentColor::GREEN,
            blueContainerName: $application->uuid.'-'.BlueGreenDeploymentColor::BLUE->value,
            greenContainerName: $application->uuid.'-'.BlueGreenDeploymentColor::GREEN->value,
            port: 3000,
            ports: [3000],
            routingRevision: 2,
            mode: BlueGreenRoutingMode::Steady,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($activeUuid),
            destinationFenceEpoch: 2,
            operationId: $activeUuid,
            mutationSequence: 3,
            activeDeploymentUuid: $activeUuid,
            activeContainerId: $activeContainerId,
            destinationTopologyDigest: $topologyDigest,
        ),
    );

    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $activeUuid,
        'pull_request_id' => 0,
        'commit' => 'steady-repair-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 2,
        'blue_green_destination_fence_epoch' => 2,
        'blue_green_topology_digest' => $topologyDigest,
        'blue_green_routing_config_digest' => $configuration->routingConfigDigest,
        'blue_green_candidate_container_id' => $activeContainerId,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'green_deployment_uuid' => $activeUuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
        'supersession_generation' => 2,
        'destination_fence_epoch' => 2,
        'destination_fence_operation_id' => $activeUuid,
        'destination_fence_mutation_sequence' => 3,
        'managed_file_sha256' => $configuration->sha256,
        'destination_topology_digest' => $topologyDigest,
        'destination_routing_topology_digest' => $routingTopologyDigest,
        'application_routing_config_digest' => $configuration->routingConfigDigest,
    ]);

    return [
        'application' => $application,
        'deployment' => $deployment,
        'state' => $state,
    ];
}

/**
 * Prove the exact active container, managed route, and public traffic for one
 * canonical steady state. The remote responses are deliberately tied to the
 * fixture's deployment-bound release proof and generated acknowledgement.
 */
function fakeHealthyBlueGreenSteadyRepairProofs(
    Application $application,
    ApplicationDeploymentQueue $deployment,
    ApplicationBlueGreenDeployment $state,
    ?Closure $beforePublicProofResponse = null,
): void {
    config(['constants.ssh.mux_enabled' => false]);
    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken($deployment->deployment_uuid);
    $configuration = CompileBlueGreenProxyConfiguration::run(
        $application,
        $application->destination,
        new BlueGreenRoutingTarget(
            destinationId: (int) $application->destination_id,
            activeColor: BlueGreenDeploymentColor::GREEN,
            blueContainerName: $application->uuid.'-'.BlueGreenDeploymentColor::BLUE->value,
            greenContainerName: $application->uuid.'-'.BlueGreenDeploymentColor::GREEN->value,
            port: 3000,
            ports: [3000],
            routingRevision: $state->routing_revision,
            mode: BlueGreenRoutingMode::Steady,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($deployment->deployment_uuid),
            destinationFenceEpoch: $state->destination_fence_epoch,
            operationId: (string) $state->destination_fence_operation_id,
            mutationSequence: $state->destination_fence_mutation_sequence,
            activeDeploymentUuid: $deployment->deployment_uuid,
            activeContainerId: str_repeat('a', 64),
            destinationTopologyDigest: (string) $state->destination_topology_digest,
        ),
    );
    $publicAcknowledgement = (new PlanBlueGreenPublicRecovery)
        ->publicAcknowledgementForYaml($configuration->yaml);
    $expectedState = ResolveBlueGreenExpectedProxyState::run($application, $application->destination, $state)
        ?? throw new RuntimeException('The healthy steady repair fixture requires one exact managed route.');
    $bootId = '11111111-2222-3333-4444-555555555555';

    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: str_repeat('a', 64),
            status: 'running',
            health: 'healthy',
        ));
    Process::fake(static function (PendingProcess $process) use (
        $bootId,
        $beforePublicProofResponse,
        $expectedState,
        $publicAcknowledgement,
        $releaseProof,
    ): FakeProcessResult {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;
        $input = is_string($process->input) ? $process->input : '';
        $invocation = $command."\n".$input;

        if (str_contains($invocation, '__coolify_blue_green_probe')) {
            if ($beforePublicProofResponse !== null) {
                $beforePublicProofResponse();
            }

            return Process::result(
                output: "HTTP/1.1 200 OK\r\n"
                    .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$publicAcknowledgement}\r\n"
                    .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n",
            );
        }

        return match (true) {
            str_contains($invocation, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($expectedState->serialize())."\n".$expectedState->managedSha256,
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
            default => throw new RuntimeException('Unexpected steady-state repair remote proof.'),
        };
    });
}

it('defers before remote proof when the routing-topology digest is missing', function (): void {
    ['state' => $state] = makeBlueGreenSteadyRepairFixture();
    $state->update(['destination_routing_topology_digest' => null]);
    InspectBlueGreenContainer::shouldRun()->never();
    Process::fake();

    $result = RepairBlueGreenSteadyState::run($state->fresh());

    expect($result->outcome)->toBe(BlueGreenSteadyStateRepairResult::PENDING_ROUTING_TOPOLOGY_DIGEST)
        ->and($result->message)->toContain('run blue-green:rehydrate-routing-topology');
    Process::assertNothingRan();
});

it('distinguishes a missing routing-topology digest from ordinary deferral', function (): void {
    ['state' => $state] = makeBlueGreenSteadyRepairFixture();
    $state->update(['destination_routing_topology_digest' => null]);
    InspectBlueGreenContainer::shouldRun()->never();
    Process::fake();

    $result = RepairBlueGreenSteadyState::run($state->fresh());

    expect($result->outcome)->not->toBe(BlueGreenSteadyStateRepairResult::DEFERRED);
});

it('defers before remote proof when the routing-topology digest no longer matches current topology', function (): void {
    ['state' => $state] = makeBlueGreenSteadyRepairFixture();
    $state->update(['destination_routing_topology_digest' => hash('sha256', 'stale-routing-topology')]);
    InspectBlueGreenContainer::shouldRun()->never();
    Process::fake();

    $result = RepairBlueGreenSteadyState::run($state->fresh());

    expect($result->outcome)->toBe(BlueGreenSteadyStateRepairResult::DEFERRED)
        ->and($result->message)->toBe('The destination routing topology digest no longer matches current topology.');
    Process::assertNothingRan();
});

it('repairs a steady route on a destination that carries only terminal deactivation history', function (BlueGreenDeactivationPhase $phase): void {
    ['application' => $application, 'deployment' => $deployment, 'state' => $state] = makeBlueGreenSteadyRepairFixture();
    // A deactivation row is permanent history and nothing ever deletes one, so
    // an application stopped even once carries it forever. Skipping the steady
    // repair on its mere existence left a missing or drifted managed route
    // unrepairable for the rest of that destination's life.
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => $phase,
        'operation_id' => hash('sha256', 'terminal-steady-repair-history'),
        'started_at' => now()->subHour(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'completed_at' => now()->subMinutes(59),
    ]);
    expect($deployment->created_at->gt(now()->subHour()))->toBeTrue();

    // The repair reaches the exact-active-container proof it exists to protect,
    // which is only reachable once the deactivation history stops fencing it.
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: str_repeat('a', 64),
            status: 'exited',
            health: 'unhealthy',
        ));

    $result = RepairBlueGreenSteadyState::run($state);

    expect($result->outcome)->toBe(BlueGreenSteadyStateRepairResult::DEFERRED)
        ->and($result->message)->toBe('The exact active container is not running and healthy; route repair refused.');
})->with([
    'stopped' => BlueGreenDeactivationPhase::STOPPED,
    'completed' => BlueGreenDeactivationPhase::COMPLETED,
]);

it('skips a steady repair while the destination deactivation is still a live fence', function (
    BlueGreenDeactivationPhase $phase,
    bool $completed,
): void {
    ['application' => $application, 'state' => $state] = makeBlueGreenSteadyRepairFixture();
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => $phase,
        'operation_id' => hash('sha256', 'live-steady-repair-fence'),
        'started_at' => now()->subMinute(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'completed_at' => $completed ? now() : null,
    ]);
    InspectBlueGreenContainer::shouldRun()->never();

    $result = RepairBlueGreenSteadyState::run($state);

    expect($result->outcome)->toBe(BlueGreenSteadyStateRepairResult::SKIPPED)
        ->and($result->message)->toBe('Deletion or deactivation owns the destination.');
})->with([
    'deactivating' => [BlueGreenDeactivationPhase::DEACTIVATING, false],
    'stopping' => [BlueGreenDeactivationPhase::STOPPING, false],
    'removing' => [BlueGreenDeactivationPhase::REMOVING, false],
    'intervention required' => [BlueGreenDeactivationPhase::INTERVENTION_REQUIRED, false],
    'removed' => [BlueGreenDeactivationPhase::REMOVED, true],
]);

it('skips a steady repair whose active route owner terminal stop history cut off', function (): void {
    ['application' => $application, 'deployment' => $deployment, 'state' => $state] = makeBlueGreenSteadyRepairFixture();
    // Terminal history still fences the exact route owner it cut off: republishing
    // that owner's route would resurrect routing the stop deliberately removed.
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => BlueGreenDeactivationPhase::STOPPED,
        'operation_id' => hash('sha256', 'cut-off-steady-repair-owner'),
        'started_at' => now(),
        'queue_cutoff_id' => $deployment->getKey(),
        'supersession_generation' => 1,
        'completed_at' => now(),
    ]);
    InspectBlueGreenContainer::shouldRun()->never();

    $result = RepairBlueGreenSteadyState::run($state);

    expect($result->outcome)->toBe(BlueGreenSteadyStateRepairResult::SKIPPED)
        ->and($result->message)->toBe('Deletion or deactivation owns the destination.');
});

it('archives a committed clean idle journal from the scheduled sweep and repairs the route in the same pass', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    $fixture = makeBlueGreenSteadyRepairFixture();
    $application = $fixture['application'];
    $deployment = $fixture['deployment'];
    $state = $fixture['state'];
    $destination = $application->destination;
    $expectedState = ResolveBlueGreenExpectedProxyState::run($application, $destination, $state)
        ?? throw new RuntimeException('The sweep journal fixture requires an exact managed route.');
    $journalExpectedState = new BlueGreenProxyState(
        managedFilename: $expectedState->managedFilename,
        applicationUuid: $expectedState->applicationUuid,
        destinationId: $expectedState->destinationId,
        operationId: $expectedState->operationId,
        mutationSequence: $expectedState->mutationSequence - 1,
        destinationFenceEpoch: $expectedState->destinationFenceEpoch,
        routingRevision: $expectedState->routingRevision,
        managedSha256: $expectedState->managedSha256,
        activeColor: $expectedState->activeColor,
        activeDeploymentUuid: $expectedState->activeDeploymentUuid,
        activeContainerName: $expectedState->activeContainerName,
        activeContainerId: $expectedState->activeContainerId,
        applicationRoutingConfigDigest: $expectedState->applicationRoutingConfigDigest,
        destinationTopologyDigest: $expectedState->destinationTopologyDigest,
        activeContainerSet: $expectedState->activeContainerSet,
        activeReplicaSetDigest: $expectedState->activeReplicaSetDigest,
        activeReplicaSet: $expectedState->activeReplicaSet,
    );
    $journalSha256 = hash('sha256', 'sweep-committed-journal');
    $bootId = '11111111-2222-3333-4444-555555555555';
    $deployment->update(['blue_green_server_boot_id' => $bootId]);
    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken($deployment->deployment_uuid);
    $plan = PlanBlueGreenSteadyState::run($application, $destination, $state);
    $journalPresent = true;

    InspectBlueGreenContainer::shouldRun()
        ->times(3)
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: str_repeat('a', 64),
            status: 'running',
            health: 'healthy',
        ));
    Process::fake(function (PendingProcess $process) use (
        $expectedState,
        $journalExpectedState,
        $journalSha256,
        $bootId,
        $releaseProof,
        $plan,
        &$journalPresent,
    ): FakeProcessResult {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $payload = $command."\n".(is_string($process->input) ? $process->input : '');
        if (str_contains($payload, '__coolify_blue_green_probe')) {
            return Process::result(output: "HTTP/1.1 200 OK\r\n"
                .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$plan->publicAcknowledgement}\r\n"
                .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n");
        }
        if (str_contains($payload, 'repair_outcome=')) {
            if ($journalPresent) {
                return Process::result(
                    errorOutput: WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT,
                    exitCode: 75,
                );
            }

            return Process::result(output: WriteBlueGreenProxyConfiguration::REPAIR_HEALTHY_OUTPUT);
        }
        if (str_contains($payload, 'committed_container_manifest_stage=')) {
            $journalPresent = false;

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::COMMITTED_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX,
                'archived',
                $journalSha256,
                (new WriteBlueGreenProxyConfiguration)->committedContainerMutationJournalArchiveFilename(
                    $expectedState->managedFilename,
                    $journalSha256,
                ),
            ]));
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            if (! $journalPresent) {
                return Process::result(output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent');
            }

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
                $journalSha256,
                $bootId,
                BlueGreenProxyRollbackArtifact::PRESENT_STATE,
                $expectedState->managedSha256,
                hash('sha256', 'sweep-journal-mutation-script'),
                hash('sha256', 'sweep-journal-completion-script'),
            ])."\n".base64_encode($journalExpectedState->serialize())."\n".base64_encode($expectedState->serialize()));
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            if ($journalPresent) {
                return Process::result(output: WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT);
            }

            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($expectedState->serialize())."\n".$expectedState->managedSha256);
        }
        if (str_contains($payload, 'coolify-blue-green-destination-state-attested')) {
            return Process::result(output: 'coolify-blue-green-destination-state-attested');
        }
        if (str_contains($payload, '{{json .Config.Env}}')) {
            return Process::result(output: json_encode(['COOLIFY_DEPLOYMENT_RELEASE_PROOF='.$releaseProof], JSON_THROW_ON_ERROR));
        }
        if (str_contains($payload, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: $bootId);
        }

        return Process::result(errorOutput: 'Unexpected sweep journal repair command.', exitCode: 1);
    });

    $results = (new RepairBlueGreenSteadyStates)->handle(1, (int) $application->id, (int) $destination->id);

    expect($results)->toHaveCount(1)
        ->and($results[0]->outcome.' :: '.$results[0]->message)->toBe(BlueGreenSteadyStateRepairResult::HEALTHY.' :: The canonical steady route is present and publicly verified.')
        ->and($journalPresent)->toBeFalse();
});

it('clears stale intervention diagnostics after the scheduled steady repair proves the canonical route', function (): void {
    ['application' => $application, 'deployment' => $deployment, 'state' => $state] = makeBlueGreenSteadyRepairFixture();
    $state->update([
        'intervention_phase' => BlueGreenDeploymentPhase::DRAINING->value,
        'intervention_reason' => 'The prior fenced recovery was interrupted before it could clear its diagnostics.',
    ]);
    fakeHealthyBlueGreenSteadyRepairProofs($application, $deployment, $state->fresh());

    $results = RepairBlueGreenSteadyStates::run(1);

    expect($results)->toHaveCount(1)
        ->and($results[0]->outcome)->toBe(BlueGreenSteadyStateRepairResult::HEALTHY)
        ->and($state->fresh()->intervention_phase)->toBeNull()
        ->and($state->fresh()->intervention_reason)->toBeNull();
});

it('retains stale intervention diagnostics when scheduled steady repair cannot prove the active container', function (): void {
    ['state' => $state] = makeBlueGreenSteadyRepairFixture();
    $state->update([
        'intervention_phase' => BlueGreenDeploymentPhase::DRAINING->value,
        'intervention_reason' => 'The prior fenced recovery was interrupted before it could clear its diagnostics.',
    ]);
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: str_repeat('a', 64),
            status: 'exited',
            health: 'unhealthy',
        ));

    $results = RepairBlueGreenSteadyStates::run(1);

    expect($results)->toHaveCount(1)
        ->and($results[0]->outcome)->toBe(BlueGreenSteadyStateRepairResult::DEFERRED)
        ->and($state->fresh()->intervention_phase)->toBe(BlueGreenDeploymentPhase::DRAINING->value)
        ->and($state->fresh()->intervention_reason)->toBe('The prior fenced recovery was interrupted before it could clear its diagnostics.');
});

it('retains changed stale intervention diagnostics when the proven boundary loses its exact compare-and-swap snapshot', function (): void {
    ['application' => $application, 'deployment' => $deployment, 'state' => $state] = makeBlueGreenSteadyRepairFixture();
    $initialReason = 'The prior fenced recovery was interrupted before it could clear its diagnostics.';
    $replacementReason = 'A newer intervention diagnostic replaced the stale recovery message.';
    $state->update([
        'intervention_phase' => BlueGreenDeploymentPhase::DRAINING->value,
        'intervention_reason' => $initialReason,
    ]);
    fakeHealthyBlueGreenSteadyRepairProofs(
        $application,
        $deployment,
        $state->fresh(),
        static function () use ($replacementReason, $state): void {
            ApplicationBlueGreenDeployment::query()
                ->whereKey($state->id)
                ->update(['intervention_reason' => $replacementReason]);
        },
    );

    $results = RepairBlueGreenSteadyStates::run(1);

    expect($results)->toHaveCount(1)
        ->and($results[0]->outcome)->toBe(BlueGreenSteadyStateRepairResult::DEFERRED)
        ->and($state->fresh()->intervention_phase)->toBe(BlueGreenDeploymentPhase::DRAINING->value)
        ->and($state->fresh()->intervention_reason)->toBe($replacementReason);
});

it('defers when routing inputs change during public proof', function (
    Closure $mutateRoutingInput,
    Closure $assertRoutingInputChanged,
): void {
    ['application' => $application, 'deployment' => $deployment, 'state' => $state] = makeBlueGreenSteadyRepairFixture();
    $initialDigest = $state->destination_routing_topology_digest;
    expect($initialDigest)->toBeString();
    $reason = 'The prior fenced recovery was interrupted before it could clear its diagnostics.';
    $state->update([
        'destination_routing_topology_digest' => $initialDigest,
        'intervention_phase' => BlueGreenDeploymentPhase::DRAINING->value,
        'intervention_reason' => $reason,
    ]);
    fakeHealthyBlueGreenSteadyRepairProofs(
        $application,
        $deployment,
        $state->fresh(),
        static function () use ($application, $mutateRoutingInput): void {
            $mutateRoutingInput($application);
        },
    );

    $result = RepairBlueGreenSteadyState::run($state->fresh());

    expect($result->outcome)->toBe(BlueGreenSteadyStateRepairResult::DEFERRED)
        ->and($state->fresh()->destination_routing_topology_digest)->toBe($initialDigest)
        ->and($state->fresh()->intervention_reason)->toBe($reason);
    $assertRoutingInputChanged($application);
})->with([
    'destination network topology' => [
        static fn (Application $application): int => StandaloneDocker::query()
            ->whereKey($application->destination_id)
            ->update(['network' => 'steady-repair-drifted-network']),
        static fn (Application $application) => expect(
            StandaloneDocker::query()->findOrFail($application->destination_id)->network,
        )->toBe('steady-repair-drifted-network'),
    ],
    'application route configuration' => [
        static fn (Application $application): int => Application::query()
            ->whereKey($application->id)
            ->update(['fqdn' => 'https://steady-repair-drifted.example.test']),
        static fn (Application $application) => expect($application->fresh()->fqdn)
            ->toBe('https://steady-repair-drifted.example.test'),
    ],
]);
