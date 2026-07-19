<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerRemovalPlan;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationInProgressException;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationPreparation;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteOutcome;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteResult;
use App\Actions\Application\BlueGreen\BlueGreenProxyDeactivationSnapshot;
use App\Actions\Application\BlueGreen\BlueGreenProxyTombstoneInstallation;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDeactivationRemoteCommand;
use App\Actions\Application\BlueGreen\InstallBlueGreenProxyEvictionTombstone;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     artifact: BlueGreenProxyRollbackArtifact,
 *     expectedState: BlueGreenProxyState,
 *     preparation: BlueGreenDeactivationPreparation,
 *     replacementState: BlueGreenProxyState,
 *     server: Server,
 *     snapshot: BlueGreenProxyDeactivationSnapshot,
 *     state: ApplicationBlueGreenDeployment
 * }
 */
function manualStopDestinationFenceFixture(): array
{
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $tombstoneAcknowledgement = str_repeat('a', 64);
    $sourceConfiguration = [
        'http' => [
            'routers' => [
                'manual-stop-public' => [
                    'rule' => 'Host(`manual-stop.example.test`) && PathPrefix(`/`)',
                    'entryPoints' => ['https'],
                    'service' => 'manual-stop-service',
                ],
            ],
            'services' => [
                'manual-stop-service' => [
                    'loadBalancer' => [
                        'servers' => [['url' => 'http://127.0.0.1:3000']],
                    ],
                ],
            ],
        ],
    ];
    $tombstoneConfiguration = $sourceConfiguration;
    $tombstoneConfiguration['http']['routers']['manual-stop-public']['service'] = 'noop@internal';
    $tombstoneConfiguration['http']['routers']['manual-stop-public']['middlewares'] = ['manual-stop-eviction-ack'];
    $tombstoneConfiguration['http']['middlewares'] = [
        'manual-stop-eviction-ack' => [
            'headers' => [
                'customResponseHeaders' => [
                    BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER => $tombstoneAcknowledgement,
                ],
            ],
        ],
    ];
    $sourceYaml = Yaml::dump($sourceConfiguration);
    $tombstoneYaml = Yaml::dump($tombstoneConfiguration);
    $destinationClockObservedAt = 1_700_000_000;
    $snapshot = new BlueGreenProxyDeactivationSnapshot(
        managedFilename: BlueGreenRoutingTarget::managedFilename((string) $application->uuid, $destination->id),
        sourceYaml: $sourceYaml,
        sourceSha256: hash('sha256', $sourceYaml),
        tombstoneYaml: $tombstoneYaml,
        tombstoneSha256: hash('sha256', $tombstoneYaml),
        tombstoneAcknowledgement: $tombstoneAcknowledgement,
        routes: [[
            'router' => 'manual-stop-public',
            'url' => 'https://manual-stop.example.test/',
        ]],
        backendPort: 3000,
        destinationClockObservedAtUnixSeconds: $destinationClockObservedAt,
        drainDeadlineUnixSeconds: $destinationClockObservedAt + 840,
        deactivationDeadlineUnixSeconds: $destinationClockObservedAt + 900,
    );
    $operationId = str_repeat('b', 64);
    $startedAt = now()->subSecond();
    $routingDigest = hash('sha256', 'manual-stop-routing');
    $topologyDigest = hash('sha256', 'manual-stop-topology');
    $expectedState = new BlueGreenProxyState(
        managedFilename: $snapshot->managedFilename,
        applicationUuid: (string) $application->uuid,
        destinationId: $destination->id,
        operationId: 'live-route-owner',
        mutationSequence: 3,
        destinationFenceEpoch: 7,
        routingRevision: 4,
        managedSha256: $snapshot->sourceSha256,
        activeColor: BlueGreenDeploymentColor::BLUE,
        activeDeploymentUuid: 'active-blue-deployment',
        activeContainerName: $application->uuid.'-blue',
        activeContainerId: str_repeat('a', 64),
        applicationRoutingConfigDigest: $routingDigest,
        destinationTopologyDigest: $topologyDigest,
    );
    $deactivation = ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'operation_id' => $operationId,
        'started_at' => $startedAt,
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeactivationPhase::STOPPING,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'active-blue-deployment',
        'phase' => BlueGreenDeploymentPhase::DEACTIVATING,
        'routing_revision' => $expectedState->routingRevision,
        'deactivation_operation_id' => $operationId,
        'deactivation_started_at' => $startedAt,
        'destination_fence_epoch' => $expectedState->destinationFenceEpoch,
        'destination_fence_operation_id' => $expectedState->operationId,
        'destination_fence_mutation_sequence' => $expectedState->mutationSequence,
        'managed_file_sha256' => $expectedState->managedSha256,
        'destination_topology_digest' => $expectedState->destinationTopologyDigest,
        'application_routing_config_digest' => $expectedState->applicationRoutingConfigDigest,
        'supersession_generation' => $deactivation->supersession_generation,
    ]);
    $replacementState = new BlueGreenProxyState(
        managedFilename: $expectedState->managedFilename,
        applicationUuid: $expectedState->applicationUuid,
        destinationId: $expectedState->destinationId,
        operationId: $operationId,
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
    $rollbackKey = new BlueGreenProxyRollbackKey($operationId, $expectedState, $replacementState);
    $artifact = new BlueGreenProxyRollbackArtifact($rollbackKey, true, $snapshot->sourceYaml);
    $preparation = new BlueGreenDeactivationPreparation(
        state: $state,
        destination: $destination,
        containerRemovalPlan: new BlueGreenContainerRemovalPlan(
            applicationId: $application->id,
            blueContainerName: $application->uuid.'-blue',
            blueRoutingRevision: $expectedState->routingRevision,
            greenContainerName: $application->uuid.'-green',
            greenRoutingRevision: null,
            legacyContainerName: null,
            stopGracePeriodSeconds: 1,
        ),
        deactivation: $deactivation,
    );

    return compact('artifact', 'expectedState', 'preparation', 'replacementState', 'server', 'snapshot', 'state');
}

function manualStopDestinationFenceRemoteOutput(BlueGreenProxyRollbackArtifact $artifact): string
{
    return (new ExecuteBlueGreenDeactivationRemoteCommand)->encode(
        new BlueGreenDeactivationRemoteResult(
            BlueGreenDeactivationRemoteOutcome::Success,
            0,
            BlueGreenProxyRollbackArtifact::OUTPUT_PREFIX.base64_encode($artifact->serialize()),
        ),
    );
}

it('advances the destination fence when a live manual stop installs its eviction tombstone', function () {
    $fixture = manualStopDestinationFenceFixture();
    Process::fake([
        '*' => Process::result(
            output: manualStopDestinationFenceRemoteOutput($fixture['artifact']),
            exitCode: 0,
        ),
    ]);

    $installation = InstallBlueGreenProxyEvictionTombstone::run(
        $fixture['server'],
        $fixture['preparation'],
        $fixture['snapshot'],
        $fixture['expectedState'],
        BlueGreenDeactivationScenario::BOOT_ID,
    );
    $state = $fixture['state']->fresh();

    expect($installation)->toBe(BlueGreenProxyTombstoneInstallation::TombstonePresent)
        ->and($state->destination_fence_epoch)->toBe($fixture['replacementState']->destinationFenceEpoch)
        ->and($state->destination_fence_epoch)->toBeGreaterThan($fixture['expectedState']->destinationFenceEpoch)
        ->and($state->destination_fence_mutation_sequence)->toBe($fixture['replacementState']->mutationSequence)
        ->and($state->destination_fence_operation_id)->toBe($fixture['preparation']->deactivation->operation_id)
        ->and($state->managed_file_sha256)->toBe($fixture['replacementState']->managedSha256);
});

it('does not let a stale manual stop overwrite a newer destination fence', function () {
    $fixture = manualStopDestinationFenceFixture();
    $newerState = [
        'destination_fence_epoch' => 9,
        'destination_fence_operation_id' => 'newer-destination-owner',
        'destination_fence_mutation_sequence' => 4,
        'managed_file_sha256' => hash('sha256', 'newer-managed-route'),
        'destination_topology_digest' => hash('sha256', 'newer-destination-topology'),
        'application_routing_config_digest' => hash('sha256', 'newer-routing-configuration'),
    ];
    $advanced = false;
    Process::fake(function () use ($fixture, $newerState, &$advanced) {
        if (! $advanced) {
            ApplicationBlueGreenDeployment::query()
                ->whereKey($fixture['state']->id)
                ->update($newerState);
            $advanced = true;
        }

        return Process::result(
            output: manualStopDestinationFenceRemoteOutput($fixture['artifact']),
            exitCode: 0,
        );
    });

    expect(fn () => InstallBlueGreenProxyEvictionTombstone::run(
        $fixture['server'],
        $fixture['preparation'],
        $fixture['snapshot'],
        $fixture['expectedState'],
        BlueGreenDeactivationScenario::BOOT_ID,
    ))->toThrow(BlueGreenDeactivationInProgressException::class, 'exact durable proxy state changed during tombstone installation');

    $state = $fixture['state']->fresh();
    expect($advanced)->toBeTrue()
        ->and($state->destination_fence_epoch)->toBe($newerState['destination_fence_epoch'])
        ->and($state->destination_fence_operation_id)->toBe($newerState['destination_fence_operation_id'])
        ->and($state->destination_fence_mutation_sequence)->toBe($newerState['destination_fence_mutation_sequence'])
        ->and($state->managed_file_sha256)->toBe($newerState['managed_file_sha256'])
        ->and($state->destination_topology_digest)->toBe($newerState['destination_topology_digest'])
        ->and($state->application_routing_config_digest)->toBe($newerState['application_routing_config_digest']);
});
