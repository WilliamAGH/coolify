<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerRemovalPlan;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationException;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationFailure;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationInProgressException;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationPreparation;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteOutcome;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteResult;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationTransportException;
use App\Actions\Application\BlueGreen\DeactivateBlueGreenApplication;
use App\Actions\Application\BlueGreen\DeactivateBlueGreenApplicationDestination;
use App\Actions\Application\BlueGreen\DrainAndRemoveBlueGreenApplicationContainers;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDeactivationRemoteCommand;
use App\Actions\Application\BlueGreen\PrepareBlueGreenDeactivation;
use App\Actions\Application\BlueGreen\PrepareBlueGreenProxyDeactivation;
use App\Actions\Application\BlueGreen\RemoveBlueGreenApplicationContainers;
use App\Actions\Application\BlueGreen\ResolveBlueGreenExpectedProxyState;
use App\Actions\Application\BlueGreen\ResumeBlueGreenDeactivations;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Notifications\Application\BlueGreenInterventionRequired;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

function blueGreenDeactivationRemoteOutput(
    BlueGreenDeactivationRemoteOutcome $outcome,
    int $exitStatus,
    string $output = '',
): string {
    return (new ExecuteBlueGreenDeactivationRemoteCommand)->encode(
        new BlueGreenDeactivationRemoteResult($outcome, $exitStatus, $output),
    );
}

function fakeBlueGreenRemoteProcessSequence(string ...$outputs): void
{
    Process::fake(['*' => Process::sequence($outputs)]);
}

it('truncates public and internal intervention evidence on UTF-8 character boundaries', function (): void {
    $failure = new BlueGreenDeactivationFailure(
        str_repeat('é', 513),
        str_repeat('🧪', 4097),
    );

    expect($failure->publicReason)->toEndWith('...')
        ->and(mb_check_encoding($failure->publicReason, 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($failure->publicReason))->toBe(BlueGreenDeactivationFailure::MAXIMUM_PUBLIC_REASON_LENGTH)
        ->and($failure->internalEvidence)->not->toBeNull()
        ->and(mb_check_encoding($failure->internalEvidence, 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($failure->internalEvidence))->toBe(4096);
});

it('allocates one exact supersession generation for state, deactivation, and cancelled queue provenance', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination, supersessionGeneration: 3);
    $deployment = BlueGreenDeactivationScenario::queuedDeployment($application, $destination);
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'operation_id' => str_repeat('a', 64),
        'started_at' => now()->subMinute(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 7,
        'phase' => BlueGreenDeactivationPhase::COMPLETED,
        'completed_at' => now()->subSecond(),
    ]);
    $application->delete();

    $preparation = PrepareBlueGreenDeactivation::run($application, $destination->id);
    $deactivation = $preparation->deactivation->fresh();

    expect($deactivation->phase)->toBe(BlueGreenDeactivationPhase::DEACTIVATING)
        ->and($deactivation->supersession_generation)->toBe(8)
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::DEACTIVATING)
        ->and($state->fresh()->supersession_generation)->toBe(8)
        ->and($state->fresh()->deactivation_operation_id)->toBe($deactivation->operation_id)
        ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
        ->and($deployment->fresh()->blue_green_supersession_generation)->toBe(8);
});

it('defers a typed 240-second drain attempt without marking intervention', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    $application->delete();
    $preparation = PrepareBlueGreenDeactivation::run($application, $destination->id);
    $snapshot = BlueGreenDeactivationScenario::proxySnapshot($application, $destination);
    $plan = new BlueGreenContainerRemovalPlan(
        applicationId: $application->id,
        blueContainerName: $application->uuid.'-blue',
        blueRoutingRevision: 1,
        greenContainerName: $application->uuid.'-green',
        greenRoutingRevision: 2,
        legacyContainerName: null,
        stopGracePeriodSeconds: 1,
    );
    fakeBlueGreenRemoteProcessSequence(blueGreenDeactivationRemoteOutput(
        BlueGreenDeactivationRemoteOutcome::Deferred,
        75,
        'The bounded 240-second drain attempt ended before safe removal.',
    ));

    expect(fn () => DrainAndRemoveBlueGreenApplicationContainers::run(
        $server,
        $snapshot,
        $plan,
        BlueGreenDeactivationScenario::BOOT_ID,
    ))->toThrow(BlueGreenDeactivationInProgressException::class);

    $deactivation = $preparation->deactivation->fresh();
    expect($deactivation->phase)->toBe(BlueGreenDeactivationPhase::DEACTIVATING)
        ->and($deactivation->supersession_generation)->toBe(1)
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::DEACTIVATING)
        ->and($state->fresh()->supersession_generation)->toBe($deactivation->supersession_generation)
        ->and($state->fresh()->deactivation_operation_id)->toBe($deactivation->operation_id);
    Process::assertRanTimes(fn () => true, 1);
});

it('drains every persisted backend port before deactivation removes application containers', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $snapshot = BlueGreenDeactivationScenario::proxySnapshot($application, $destination, [8080, 3000]);
    $plan = new BlueGreenContainerRemovalPlan(
        applicationId: $application->id,
        blueContainerName: $application->uuid.'-blue',
        blueRoutingRevision: 1,
        greenContainerName: $application->uuid.'-green',
        greenRoutingRevision: 2,
        legacyContainerName: null,
        stopGracePeriodSeconds: 1,
        replicaContainers: [[
            'name' => $application->uuid.'-blue-2',
            'id' => str_repeat('a', 64),
            'color' => BlueGreenDeploymentColor::BLUE,
            'routingRevision' => 1,
            'deploymentUuid' => 'replica-deployment',
            'index' => 2,
            'count' => 2,
            'composeProject' => 'coolify-project',
            'composeService' => 'web-blue-replica-2',
            'ordinal' => 0,
        ], [
            'name' => $application->uuid.'-blue-worker-2',
            'id' => str_repeat('b', 64),
            'color' => BlueGreenDeploymentColor::BLUE,
            'routingRevision' => 1,
            'deploymentUuid' => 'replica-deployment',
            'index' => 2,
            'count' => 2,
            'composeProject' => 'coolify-project',
            'composeService' => 'worker-blue-replica-2',
            'ordinal' => 1,
        ], [
            'name' => $application->uuid.'-green',
            'id' => str_repeat('c', 64),
            'color' => BlueGreenDeploymentColor::GREEN,
            'routingRevision' => 2,
            'deploymentUuid' => 'scalar-replica-deployment',
            'index' => 1,
            'count' => 1,
            'composeProject' => 'coolify-project',
            'composeService' => 'worker-green',
            'ordinal' => 2,
        ]],
    );

    $command = (new DrainAndRemoveBlueGreenApplicationContainers)->commandFor(
        $server->proxyPath(),
        $snapshot,
        $plan,
    );

    expect($command)->toContain(
        "backend_ports='0BB8 1F90'",
        'expected_ports[ports[index]] = 1',
        'toupper(endpoint[2]) in expected_ports',
        '{{index .Config.Labels "coolify.blueGreen.deploymentUuid"}}',
        'test "$container_id" = '.escapeshellarg(str_repeat('a', 64)),
        'test "$container_id" = '.escapeshellarg(str_repeat('b', 64)),
        'test "$container_id" = '.escapeshellarg(str_repeat('c', 64)),
        '${replica_0_container_id:-}',
        '${replica_1_container_id:-}',
        '${replica_2_container_id:-}',
        '! docker container inspect '.escapeshellarg($application->uuid.'-blue-2'),
        '! docker container inspect '.escapeshellarg($application->uuid.'-blue-worker-2'),
    );
});

it('removes drift-named production containers through the immutable application label scope', function (): void {
    ['application' => $application] = BlueGreenDeactivationScenario::context();
    $plan = new BlueGreenContainerRemovalPlan(
        applicationId: $application->id,
        blueContainerName: $application->uuid.'-blue',
        blueRoutingRevision: null,
        greenContainerName: $application->uuid.'-green',
        greenRoutingRevision: null,
        legacyContainerName: null,
        stopGracePeriodSeconds: 10,
    );
    $remover = new RemoveBlueGreenApplicationContainers;

    $command = $remover->commandFor($plan);
    $absence = $remover->assertAbsentCommandFor($plan);
    $commandSyntax = new Symfony\Component\Process\Process(['bash', '-n']);
    $commandSyntax->setInput($command)->run();
    $absenceSyntax = new Symfony\Component\Process\Process(['bash', '-n']);
    $absenceSyntax->setInput($absence)->run();

    expect($commandSyntax->isSuccessful())->toBeTrue()
        ->and($absenceSyntax->isSuccessful())->toBeTrue();
    expect($command)
        ->toContain("--filter 'label=coolify.applicationId={$application->id}'")
        ->toContain('for container_id in $application_container_ids; do')
        ->toContain('{{index .Config.Labels "coolify.applicationId"}} {{index .Config.Labels "coolify.managed"}} {{index .Config.Labels "coolify.pullRequestId"}} {{index .Config.Labels "coolify.type"}}')
        ->toContain('[ "$metadata" = '.escapeshellarg("{$application->id} true 0 application").' ]')
        ->toContain('test "$pull_request_id" -gt 0')
        ->toContain('docker rm -f "$container_id" >/dev/null');
    expect($absence)
        ->toContain("--filter 'label=coolify.applicationId={$application->id}'")
        ->toContain('test "$pull_request_id" -gt 0')
        ->toContain('test "$metadata" != '.escapeshellarg("{$application->id} true 0 application"));
});

it('drains drift-named production containers before label-scoped removal', function (): void {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $snapshot = BlueGreenDeactivationScenario::proxySnapshot($application, $destination, [3000]);
    $plan = new BlueGreenContainerRemovalPlan(
        applicationId: $application->id,
        blueContainerName: $application->uuid.'-blue',
        blueRoutingRevision: null,
        greenContainerName: $application->uuid.'-green',
        greenRoutingRevision: null,
        legacyContainerName: null,
        stopGracePeriodSeconds: 10,
    );

    $command = (new DrainAndRemoveBlueGreenApplicationContainers)->commandFor(
        $server->proxyPath(),
        $snapshot,
        $plan,
    );
    $syntax = new Symfony\Component\Process\Process(['bash', '-n']);
    $syntax->setInput($command)->run();

    expect($syntax->isSuccessful())->toBeTrue();
    expect($command)
        ->toContain("application_container_filter='label=coolify.applicationId={$application->id}'")
        ->toContain('{{.State.Pid}}|{{.State.Running}}')
        ->toContain('test "$metadata" = '.escapeshellarg("{$application->id}|true|0|application"))
        ->toContain('test "$pull_request_id" -gt 0')
        ->toContain('test -r "/proc/$pid/net/tcp"')
        ->toContain('mutation_active_connections=0')
        ->toContain('[ "$mutation_production_container_ids" != "$stable_zero_container_ids" ]')
        ->toContain('docker rm -f "$container_id" >/dev/null')
        ->toContain('test "$metadata" != '.escapeshellarg("{$application->id}|true|0|application"));
});

it('uses the first canonical persisted backend port as the deactivation primary', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();

    $snapshot = BlueGreenDeactivationScenario::proxySnapshot($application, $destination, [8080]);

    expect($snapshot->backendPort)->toBe(8080)
        ->and($snapshot->backendPorts)->toBe([8080]);
});

it('prepares and resumes a multi-port deactivation from its active deployment inventory after application ports change', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $application->update([
        'fqdn' => 'https://blue-green-web.example.test:3000,https://blue-green-metrics.example.test:8080',
        'health_check_enabled' => true,
        'health_check_path' => '/health',
        'ports_exposes' => '3000,8080',
    ]);
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    $application = $application->fresh(['settings']);
    $activeDeploymentUuid = 'persisted-multi-port-blue';
    $activeContainerId = str_repeat('c', 64);
    $target = new BlueGreenRoutingTarget(
        destinationId: $destination->id,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: $application->uuid.'-blue',
        greenContainerName: $application->uuid.'-green',
        port: 3000,
        ports: [3000, 8080],
        routingRevision: 1,
        publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($activeDeploymentUuid),
        destinationFenceEpoch: 1,
        operationId: $activeDeploymentUuid,
        mutationSequence: 1,
        activeDeploymentUuid: $activeDeploymentUuid,
        activeContainerId: $activeContainerId,
        destinationTopologyDigest: hash('sha256', 'persisted-multi-port-destination'),
    );
    $configuration = CompileBlueGreenProxyConfiguration::run($application, $destination, $target);
    $inventory = BlueGreenBackendPortInventory::fromPorts([3000, 8080]);
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $destination->server_id,
        'server_name' => $destination->server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $activeDeploymentUuid,
        'pull_request_id' => 0,
        'commit' => 'persisted-multi-port-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_server_boot_id' => BlueGreenDeactivationScenario::BOOT_ID,
        'blue_green_topology_digest' => $configuration->state->destinationTopologyDigest,
        'blue_green_routing_config_digest' => $configuration->state->applicationRoutingConfigDigest,
        'blue_green_backend_port_inventory' => $inventory->serialized,
        'blue_green_drain_backend_port_inventory' => null,
        'blue_green_supersession_generation' => 1,
        'blue_green_candidate_container_id' => $activeContainerId,
        'blue_green_rollback_managed_filename' => $configuration->managedFilename,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => $activeDeploymentUuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
        'destination_fence_epoch' => $configuration->state->destinationFenceEpoch,
        'destination_fence_operation_id' => $configuration->state->operationId,
        'destination_fence_mutation_sequence' => $configuration->state->mutationSequence,
        'managed_file_sha256' => $configuration->state->managedSha256,
        'destination_topology_digest' => $configuration->state->destinationTopologyDigest,
        'application_routing_config_digest' => $configuration->state->applicationRoutingConfigDigest,
        'supersession_generation' => 1,
    ]);

    $application->update([
        'fqdn' => 'https://blue-green-web.example.test:3000',
        'ports_exposes' => '3000',
    ]);
    $application->delete();
    $application->newQuery()
        ->withTrashed()
        ->whereKey($application->id)
        ->update(['deleted_at' => now()->subMinutes(20)->startOfSecond()]);
    $preparation = PrepareBlueGreenDeactivation::run($application, $destination->id);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $destination,
        $preparation->state,
    );
    $sourceYaml = $configuration->yaml;
    $sourceSha256 = hash('sha256', $sourceYaml);
    fakeBlueGreenRemoteProcessSequence(blueGreenDeactivationRemoteOutput(
        BlueGreenDeactivationRemoteOutcome::Success,
        0,
        implode("\n", [
            '1700000000',
            $sourceSha256,
            base64_encode($sourceYaml),
        ]),
    ));

    $snapshot = PrepareBlueGreenProxyDeactivation::run(
        $application,
        $preparation,
        $expectedState,
        BlueGreenDeactivationScenario::BOOT_ID,
    );
    $staleStartedAt = now()->subMinutes(10)->startOfSecond();
    ApplicationBlueGreenDeactivation::query()
        ->whereKey($preparation->deactivation->id)
        ->update(['started_at' => $staleStartedAt]);
    $tombstoneState = new BlueGreenProxyState(
        managedFilename: $expectedState->managedFilename,
        applicationUuid: $expectedState->applicationUuid,
        destinationId: $expectedState->destinationId,
        operationId: $preparation->deactivation->operation_id,
        mutationSequence: 1,
        destinationFenceEpoch: $expectedState->destinationFenceEpoch + 1,
        routingRevision: $expectedState->routingRevision,
        managedSha256: $snapshot->tombstoneSha256,
        activeColor: $expectedState->activeColor,
        activeDeploymentUuid: $expectedState->activeDeploymentUuid,
        activeContainerName: $expectedState->activeContainerName,
        activeContainerId: $expectedState->activeContainerId,
        applicationRoutingConfigDigest: $expectedState->applicationRoutingConfigDigest,
        destinationTopologyDigest: $expectedState->destinationTopologyDigest,
    );
    ApplicationBlueGreenDeployment::query()
        ->whereKey($state->id)
        ->update([
            'deactivation_started_at' => $staleStartedAt,
            'destination_fence_epoch' => $tombstoneState->destinationFenceEpoch,
            'destination_fence_operation_id' => $tombstoneState->operationId,
            'destination_fence_mutation_sequence' => $tombstoneState->mutationSequence,
            'managed_file_sha256' => $tombstoneState->managedSha256,
        ]);
    $absentState = $tombstoneState->withoutManagedRoute(
        $tombstoneState->destinationFenceEpoch + 1,
        $preparation->deactivation->operation_id,
        $tombstoneState->mutationSequence + 1,
    );
    $rollbackArtifact = new BlueGreenProxyRollbackArtifact(
        new BlueGreenProxyRollbackKey(
            $preparation->deactivation->operation_id,
            $tombstoneState,
            $absentState,
        ),
        true,
        $snapshot->tombstoneYaml,
    );
    $tombstoneResponse = "HTTP/1.1 418 I'm a teapot\r\n"
        .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$snapshot->tombstoneAcknowledgement}\r\n\r\n";
    $remoteSuccess = blueGreenDeactivationRemoteOutput(BlueGreenDeactivationRemoteOutcome::Success, 0);
    $remoteTimestamp = blueGreenDeactivationRemoteOutput(
        BlueGreenDeactivationRemoteOutcome::Success,
        0,
        '1700000000',
    );
    $remoteOutputs = [
        blueGreenDeactivationRemoteOutput(BlueGreenDeactivationRemoteOutcome::Success, 0, 'tombstone'),
        $remoteSuccess,
        $remoteTimestamp,
    ];
    foreach ($snapshot->routes as $route) {
        $remoteOutputs[] = $remoteTimestamp;
        $remoteOutputs[] = $remoteTimestamp;
    }
    $remoteOutputs[] = $remoteSuccess;
    $remoteOutputs[] = $remoteSuccess;
    $remoteOutputs[] = blueGreenDeactivationRemoteOutput(
        BlueGreenDeactivationRemoteOutcome::Success,
        0,
        BlueGreenProxyRollbackArtifact::OUTPUT_PREFIX.base64_encode($rollbackArtifact->serialize()),
    );
    $remoteOutputs[] = $remoteSuccess;
    $remoteOutputs[] = $remoteSuccess;
    $remoteOutputs[] = $remoteTimestamp;
    foreach ($snapshot->routes as $route) {
        $remoteOutputs[] = $remoteTimestamp;
        $remoteOutputs[] = $remoteTimestamp;
    }
    $remoteOutputs[] = $remoteSuccess;
    $publicResponses = [
        ...array_fill(0, count($snapshot->routes), $tombstoneResponse),
        ...array_fill(0, count($snapshot->routes), "HTTP/1.1 404 Not Found\r\n\r\n"),
    ];
    Process::fake(function (PendingProcess $process) use (&$publicResponses, &$remoteOutputs) {
        if (str_ends_with($process->command, " 'curl --config -'")) {
            return Process::result(output: array_shift($publicResponses));
        }
        if (str_contains($process->command, 'coolify-blue-green-deactivation-remote-v1')) {
            return Process::result(output: array_shift($remoteOutputs));
        }
        if (str_contains($process->command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: BlueGreenDeactivationScenario::BOOT_ID);
        }

        throw new RuntimeException('Unexpected remote process during blue-green deactivation resume.');
    });

    $results = ResumeBlueGreenDeactivations::run(staleAfterSeconds: 300);

    Process::assertRanTimes(fn (): bool => true, 36);
    expect($snapshot->backendPorts)->toBe([3000, 8080])
        ->and($results)->toHaveCount(1)
        ->and($results[0]->outcome)->toBe('resumed')
        ->and(ApplicationBlueGreenDeactivation::query()->findOrFail($preparation->deactivation->id)->phase)
        ->toBe(BlueGreenDeactivationPhase::COMPLETED)
        ->and(ApplicationBlueGreenDeployment::query()->whereKey($state->id)->doesntExist())->toBeTrue();
});

it('fails closed before tombstone persistence when any public router has no entry point', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $application->delete();
    $operationId = str_repeat('b', 64);
    $deactivation = ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'operation_id' => $operationId,
        'started_at' => now(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeactivationPhase::DEACTIVATING,
    ]);
    $activeService = BlueGreenRoutingTarget::activeServiceName((string) $application->uuid, (int) $destination->id);
    $sourceYaml = Yaml::dump([
        'http' => [
            'routers' => [
                'managed-valid-public' => [
                    'rule' => 'Host(`blue-green-deactivation.example.test`) && PathPrefix(`/`)',
                    'entryPoints' => ['https'],
                    'service' => $activeService,
                ],
                'managed-empty-public' => [
                    'rule' => 'Host(`blue-green-deactivation.example.test`) && PathPrefix(`/`)',
                    'entryPoints' => [],
                    'service' => $activeService,
                ],
            ],
            'services' => [
                $activeService => [
                    'weighted' => [
                        'services' => [[
                            'name' => BlueGreenRoutingTarget::memberServiceReference(
                                (string) $application->uuid,
                                (int) $destination->id,
                                BlueGreenDeploymentColor::BLUE,
                            ),
                            'weight' => 1,
                        ]],
                    ],
                ],
            ],
        ],
    ]);
    $sourceSha256 = hash('sha256', $sourceYaml);
    $state = new ApplicationBlueGreenDeployment([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'routing_revision' => 1,
    ]);
    $preparation = new BlueGreenDeactivationPreparation(
        state: $state,
        destination: $destination,
        containerRemovalPlan: new BlueGreenContainerRemovalPlan(
            applicationId: $application->id,
            blueContainerName: $application->uuid.'-blue',
            blueRoutingRevision: 1,
            greenContainerName: $application->uuid.'-green',
            greenRoutingRevision: null,
            legacyContainerName: null,
            stopGracePeriodSeconds: 1,
        ),
        deactivation: $deactivation,
    );
    $managedFilename = BlueGreenRoutingTarget::managedFilename((string) $application->uuid, (int) $destination->id);
    $expectedState = new BlueGreenProxyState(
        managedFilename: $managedFilename,
        applicationUuid: (string) $application->uuid,
        destinationId: $destination->id,
        operationId: $operationId,
        mutationSequence: 1,
        destinationFenceEpoch: 1,
        routingRevision: 1,
        managedSha256: $sourceSha256,
        activeColor: BlueGreenDeploymentColor::BLUE,
        activeDeploymentUuid: 'deactivation-active-blue',
        activeContainerName: $application->uuid.'-blue',
        activeContainerId: str_repeat('a', 64),
        applicationRoutingConfigDigest: hash('sha256', 'routing'),
        destinationTopologyDigest: hash('sha256', 'topology'),
    );
    fakeBlueGreenRemoteProcessSequence(blueGreenDeactivationRemoteOutput(
        BlueGreenDeactivationRemoteOutcome::Success,
        0,
        implode("\n", [
            '1700000000',
            $sourceSha256,
            base64_encode($sourceYaml),
        ]),
    ));

    $exception = null;
    try {
        PrepareBlueGreenProxyDeactivation::run(
            $application,
            $preparation,
            $expectedState,
            BlueGreenDeactivationScenario::BOOT_ID,
        );
    } catch (BlueGreenDeactivationException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(BlueGreenDeactivationException::class)
        ->and($exception->getPrevious()?->getMessage())
        ->toContain('managed-empty-public has no entry point to verify')
        ->and($deactivation->fresh()->proxy_snapshot)->toBeNull();
    Process::assertRanTimes(fn (): bool => true, 1);
});

it('bounds every deactivation transport attempt inside the renewable lock lease', function () {
    ['server' => $server] = BlueGreenDeactivationScenario::context();
    $observedTimeout = null;
    Process::fake(function (PendingProcess $process) use (&$observedTimeout) {
        $observedTimeout = $process->timeout;

        return Process::result(output: blueGreenDeactivationRemoteOutput(
            BlueGreenDeactivationRemoteOutcome::Success,
            0,
        ));
    });

    expect(ExecuteBlueGreenDeactivationRemoteCommand::run($server, 'true'))->toBe('')
        ->and($observedTimeout)->toBe(270)
        ->toBeGreaterThan(240)
        ->toBeLessThan(300);
});

it('never retries a deactivation transport failure beyond its renewable lease', function () {
    ['server' => $server] = BlueGreenDeactivationScenario::context();
    $attempts = 0;
    Process::fake(function () use (&$attempts) {
        $attempts++;
        throw new RuntimeException('Connection reset by peer');
    });

    expect(fn () => ExecuteBlueGreenDeactivationRemoteCommand::run($server, 'true'))
        ->toThrow(RuntimeException::class, 'Connection reset by peer');
    expect($attempts)->toBe(1);
});

it('retries the exact stale deactivation owner to completion', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    $application->delete();
    $application->newQuery()
        ->withTrashed()
        ->whereKey($application->id)
        ->update(['deleted_at' => now()->subMinutes(20)->startOfSecond()]);
    $preparation = PrepareBlueGreenDeactivation::run($application, $destination->id);
    $startedAt = now()->subMinutes(10);
    $state->newQuery()->whereKey($state->id)->update(['deactivation_started_at' => $startedAt]);
    ApplicationBlueGreenDeactivation::query()
        ->whereKey($preparation->deactivation->id)
        ->update(['started_at' => $startedAt]);
    fakeBlueGreenRemoteProcessSequence(
        BlueGreenDeactivationScenario::BOOT_ID,
        blueGreenDeactivationRemoteOutput(BlueGreenDeactivationRemoteOutcome::Success, 0),
    );

    $results = ResumeBlueGreenDeactivations::run(staleAfterSeconds: 300);
    $deactivation = ApplicationBlueGreenDeactivation::query()->findOrFail($preparation->deactivation->id);

    expect($results)->toHaveCount(1)
        ->and($results[0]->outcome)->toBe('resumed')
        ->and($deactivation->phase)->toBe(BlueGreenDeactivationPhase::COMPLETED)
        ->and($deactivation->completed_at)->not->toBeNull()
        ->and($state->newQuery()->whereKey($state->id)->doesntExist())->toBeTrue();
    Process::assertRanTimes(fn () => true, 2);
});

it('keeps transport-ambiguous remote failures resumable with a bounded public failure', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    $application->delete();
    fakeBlueGreenRemoteProcessSequence(
        BlueGreenDeactivationScenario::BOOT_ID,
        'not-a-typed-blue-green-remote-outcome',
    );

    $exception = null;
    try {
        DeactivateBlueGreenApplicationDestination::run($application, $destination->id);
    } catch (BlueGreenDeactivationTransportException $caught) {
        $exception = $caught;
    }

    $deactivation = ApplicationBlueGreenDeactivation::query()->sole();
    expect($exception)->toBeInstanceOf(BlueGreenDeactivationTransportException::class)
        ->and($exception?->getMessage())->toBe('Blue-green deactivation transport returned no valid remote outcome; the durable operation remains resumable.')
        ->and(strlen((string) $exception?->getMessage()))->toBeLessThanOrEqual(512)
        ->and($deactivation->phase)->toBe(BlueGreenDeactivationPhase::DEACTIVATING)
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::DEACTIVATING)
        ->and($state->fresh()->supersession_generation)->toBe($deactivation->supersession_generation);
    Process::assertRanTimes(fn () => true, 2);
});

it('marks a proven remote invariant failure for intervention instead of continuing deletion', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    InstanceSettings::unguarded(
        fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]),
    );
    Notification::fake();
    $application->team()->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'deployment_failure_email_notifications' => true,
    ]);
    expect($application->team()->fresh()->getEnabledChannels('deployment_failure'))->not->toBeEmpty();
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    $application->delete();
    fakeBlueGreenRemoteProcessSequence(
        BlueGreenDeactivationScenario::BOOT_ID,
        blueGreenDeactivationRemoteOutput(
            BlueGreenDeactivationRemoteOutcome::InvariantViolation,
            19,
            'The destination proved an invariant failure.',
        ),
    );

    expect(fn () => DeactivateBlueGreenApplicationDestination::run($application, $destination->id))
        ->toThrow(BlueGreenDeactivationException::class);

    $deactivation = ApplicationBlueGreenDeactivation::query()->sole();
    expect($deactivation->phase)->toBe(BlueGreenDeactivationPhase::INTERVENTION_REQUIRED)
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($state->fresh()->supersession_generation)->toBe($deactivation->supersession_generation);
    Notification::assertSentToTimes(
        $application->team(),
        BlueGreenInterventionRequired::class,
        1,
    );
    Process::assertRanTimes(fn () => true, 2);
});

it('keeps remote failure detail out of bounded public intervention reasons', function (): void {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    $application->delete();
    $rawDetail = str_repeat('credential=not-for-public-display ', 200);
    fakeBlueGreenRemoteProcessSequence(
        BlueGreenDeactivationScenario::BOOT_ID,
        blueGreenDeactivationRemoteOutput(
            BlueGreenDeactivationRemoteOutcome::InvariantViolation,
            19,
            $rawDetail,
        ),
    );

    $exception = null;
    try {
        DeactivateBlueGreenApplicationDestination::run($application, $destination->id);
    } catch (BlueGreenDeactivationException $caught) {
        $exception = $caught;
    }

    $deactivation = ApplicationBlueGreenDeactivation::query()->sole();
    expect($exception)->toBeInstanceOf(BlueGreenDeactivationException::class)
        ->and($exception?->getMessage())->toBe('The destination proved a blue-green deactivation invariant failure.')
        ->and(strlen((string) $exception?->getMessage()))->toBeLessThanOrEqual(512)
        ->and($exception?->failure()->internalEvidence)->toContain('credential=not-for-public-display')
        ->and($deactivation->intervention_reason)->toBe('The destination proved a blue-green deactivation invariant failure.')
        ->and($deactivation->intervention_reason)->not->toContain('credential=not-for-public-display')
        ->and($state->fresh()->intervention_reason)->toBe($deactivation->intervention_reason);
});

it('supersedes a completed live manual stop with a strict soft-delete deactivation', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination, supersessionGeneration: 3);
    Process::fake(function (PendingProcess $process) {
        if (str_contains($process->command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: BlueGreenDeactivationScenario::BOOT_ID, exitCode: 0);
        }

        return Process::result(output: blueGreenDeactivationRemoteOutput(
            BlueGreenDeactivationRemoteOutcome::Success,
            0,
        ));
    });

    $stops = (new DeactivateBlueGreenApplication)->stop($application, $destination->id);
    $manualStop = ApplicationBlueGreenDeactivation::query()->sole();
    $stoppedState = $state->fresh();

    expect($stops)->toHaveCount(1)
        ->and($application->fresh()->trashed())->toBeFalse()
        ->and($manualStop->phase)->toBe(BlueGreenDeactivationPhase::STOPPED)
        ->and($manualStop->completed_at)->not->toBeNull()
        ->and($stoppedState->phase)->toBe(BlueGreenDeploymentPhase::STOPPED)
        ->and($stoppedState->supersession_generation)->toBe($manualStop->supersession_generation);

    $deactivations = DeactivateBlueGreenApplication::run($application);
    $deactivation = ApplicationBlueGreenDeactivation::query()->sole();
    $tombstonedApplication = $application->newQuery()
        ->withTrashed()
        ->findOrFail($application->id);

    expect($deactivations)->toHaveCount(1)
        ->and($tombstonedApplication->trashed())->toBeTrue()
        ->and($deactivation->phase)->toBe(BlueGreenDeactivationPhase::COMPLETED)
        ->and($deactivation->completed_at)->not->toBeNull()
        ->and($deactivation->supersession_generation)->toBeGreaterThan($manualStop->supersession_generation)
        ->and($deactivation->operation_id)->not->toBe($manualStop->operation_id)
        ->and($deactivation->started_at)->toBeGreaterThan($tombstonedApplication->deleted_at)
        ->and(ApplicationBlueGreenDeployment::query()->whereKey($state->id)->doesntExist())->toBeTrue();
});

it('does not treat a completed manual stop as strict deletion authorization', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $application->delete();
    $tombstonedApplication = $application->newQuery()
        ->withTrashed()
        ->findOrFail($application->id);
    $startedAt = $tombstonedApplication->deleted_at->copy()->addSecond();
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'operation_id' => str_repeat('f', 64),
        'started_at' => $startedAt,
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeactivationPhase::STOPPED,
        'completed_at' => $startedAt->copy()->addSecond(),
    ]);

    expect(fn () => $tombstonedApplication->assertBlueGreenDeletionAuthorized())
        ->toThrow(RuntimeException::class, 'requires every durable deactivation owner to complete without intervention');
});
