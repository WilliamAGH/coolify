<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenDeactivationScenario;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function setStoppedLegacyLifecycleProperty(
    BlueGreenDeploymentLifecycle $lifecycle,
    string $property,
    mixed $value,
): void {
    (new ReflectionProperty($lifecycle, $property))->setValue($lifecycle, $value);
}

function invokeStoppedLegacyLifecycleMethod(
    BlueGreenDeploymentLifecycle $lifecycle,
    string $method,
): void {
    (new ReflectionMethod($lifecycle, $method))->invoke($lifecycle);
}

it('removes only an exact stopped base-name legacy container before fixed-color lifecycle completion', function (): void {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    $application = $application->fresh(['settings']);
    $operationId = 'stopped-legacy-cleanup-operation';
    $bootId = BlueGreenDeactivationScenario::BOOT_ID;
    $previousContainerId = str_repeat('a', 64);
    $candidateContainerId = str_repeat('b', 64);
    $stoppedLegacyContainerId = str_repeat('c', 64);
    $fingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::GREEN,
        2,
        2,
        $operationId,
    );
    $managedFilename = BlueGreenRoutingTarget::managedFilename((string) $application->uuid, (int) $destination->id);
    $managedSha256 = str_repeat('d', 64);
    $mutatedAt = now()->subSecond()->startOfSecond();
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $operationId,
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'idle-fixed-blue',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
    ]);
    $claim = new BlueGreenDeploymentClaim(
        stateId: $state->id,
        applicationId: $application->id,
        standaloneDockerId: $destination->id,
        pendingColor: BlueGreenDeploymentColor::GREEN,
        previousActiveColor: BlueGreenDeploymentColor::BLUE,
        deploymentUuid: $operationId,
        expectedRoutingRevision: 2,
        destinationFenceEpoch: 2,
        serverBootId: $bootId,
        topologyDigest: $fingerprint->topologyDigest,
        routingConfigDigest: $fingerprint->routingConfigDigest,
        supersessionGeneration: 1,
        legacyContainerName: null,
        candidateContainerName: $application->uuid.'-green',
        rollbackManagedFilename: $managedFilename,
    );
    $destinationState = new BlueGreenProxyState(
        managedFilename: $managedFilename,
        applicationUuid: (string) $application->uuid,
        destinationId: $destination->id,
        operationId: $operationId,
        mutationSequence: 1,
        destinationFenceEpoch: 2,
        routingRevision: 2,
        managedSha256: $managedSha256,
        activeColor: BlueGreenDeploymentColor::GREEN,
        activeDeploymentUuid: $operationId,
        activeContainerName: $application->uuid.'-green',
        activeContainerId: $candidateContainerId,
        applicationRoutingConfigDigest: $fingerprint->routingConfigDigest,
        destinationTopologyDigest: $fingerprint->topologyDigest,
    );
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($application->id, $destination->id),
        300,
    );
    expect($lock->get())->toBeTrue()
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->fresh()->active_color)->toBe(BlueGreenDeploymentColor::BLUE);

    $legacyInspection = json_encode([
        'Id' => $stoppedLegacyContainerId,
        'Name' => '/'.$application->uuid,
        'State' => [
            'Status' => 'exited',
            'Health' => ['Status' => 'missing'],
        ],
        'Config' => [
            'Labels' => [
                'coolify.applicationId' => (string) $application->id,
                'coolify.pullRequestId' => '0',
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $containerInventory = implode(PHP_EOL, [
        json_encode([
            'ID' => substr($candidateContainerId, 0, 12),
            'Names' => $application->uuid.'-green',
            'State' => 'running',
            'Labels' => 'coolify.applicationId='.$application->id.',coolify.pullRequestId=0,coolify.blueGreen.managed=true',
        ], JSON_THROW_ON_ERROR),
        json_encode([
            'ID' => substr($stoppedLegacyContainerId, 0, 12),
            'Names' => $application->uuid,
            'State' => 'exited',
            'Labels' => 'coolify.applicationId='.$application->id.',coolify.pullRequestId=0',
        ], JSON_THROW_ON_ERROR),
    ]);
    $commands = [];
    Process::fake(function (PendingProcess $process) use (
        &$commands,
        $bootId,
        $containerInventory,
        $legacyInspection,
    ) {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;
        $commands[] = $command;
        if (str_contains($command, 'docker ps -a')) {
            return Process::result(output: $containerInventory);
        }
        if (str_contains($command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: $bootId);
        }
        if (str_contains($command, 'coolify-blue-green-container:missing')) {
            return Process::result(output: $legacyInspection);
        }

        return Process::result(output: '');
    });

    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment,
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    setStoppedLegacyLifecycleProperty($lifecycle, 'previousActiveColor', BlueGreenDeploymentColor::BLUE);
    setStoppedLegacyLifecycleProperty($lifecycle, 'operationFence', new BlueGreenOperationFence($lock, 300));

    try {
        invokeStoppedLegacyLifecycleMethod($lifecycle, 'captureStoppedLegacyContainerForRetirement');
        $expectation = (new ReflectionProperty($lifecycle, 'stoppedLegacyContainerExpectation'))->getValue($lifecycle);
        expect($expectation)->toBeInstanceOf(BlueGreenContainerExpectation::class)
            ->and($expectation->name)->toBe($application->uuid)
            ->and($expectation->dockerId)->toBe($stoppedLegacyContainerId)
            ->and($expectation->blueGreenManaged)->toBeFalse();

        $state->update([
            'active_color' => BlueGreenDeploymentColor::GREEN,
            'pending_color' => null,
            'pending_deployment_uuid' => null,
            'green_deployment_uuid' => $operationId,
            'operation_deployment_uuid' => $operationId,
            'operation_previous_active_color' => BlueGreenDeploymentColor::BLUE,
            'operation_previous_deployment_uuid' => 'idle-fixed-blue',
            'operation_previous_routing_revision' => 1,
            'operation_previous_container_name' => $application->uuid.'-blue',
            'operation_previous_container_id' => $previousContainerId,
            'operation_candidate_container_name' => $application->uuid.'-green',
            'operation_candidate_container_id' => $candidateContainerId,
            'operation_rollback_managed_filename' => $managedFilename,
            'operation_routing_mutated_at' => $mutatedAt,
            'operation_destination_fence_epoch' => 2,
            'operation_previous_destination_fence_epoch' => 1,
            'operation_server_boot_id' => $bootId,
            'operation_topology_digest' => $fingerprint->topologyDigest,
            'operation_routing_config_digest' => $fingerprint->routingConfigDigest,
            'operation_previous_managed_file_sha256' => str_repeat('e', 64),
            'destination_fence_epoch' => 2,
            'destination_fence_operation_id' => $operationId,
            'destination_fence_mutation_sequence' => 1,
            'managed_file_sha256' => $managedSha256,
            'destination_topology_digest' => $fingerprint->topologyDigest,
            'application_routing_config_digest' => $fingerprint->routingConfigDigest,
            'supersession_generation' => 1,
            'phase' => BlueGreenDeploymentPhase::DRAINING,
            'routing_revision' => 2,
        ]);
        $deployment->update([
            'blue_green_color' => BlueGreenDeploymentColor::GREEN,
            'blue_green_phase' => BlueGreenDeploymentPhase::DRAINING,
            'blue_green_routing_revision' => 2,
            'blue_green_destination_fence_epoch' => 2,
            'blue_green_server_boot_id' => $bootId,
            'blue_green_topology_digest' => $fingerprint->topologyDigest,
            'blue_green_routing_config_digest' => $fingerprint->routingConfigDigest,
            'blue_green_supersession_generation' => 1,
            'blue_green_previous_container_id' => $previousContainerId,
            'blue_green_candidate_container_id' => $candidateContainerId,
            'blue_green_rollback_managed_filename' => $managedFilename,
            'blue_green_routing_mutated_at' => $mutatedAt,
        ]);
        setStoppedLegacyLifecycleProperty($lifecycle, 'claim', $claim);
        setStoppedLegacyLifecycleProperty($lifecycle, 'destinationState', $destinationState);

        $lifecycle->complete();
    } finally {
        $lifecycle->release();
    }

    $mutationScript = implode("\n", [
        'set -eu',
        ...(new InspectBlueGreenContainer)->exactMutationAssertionsFor($expectation),
        'test "$(docker inspect --format='.escapeshellarg('{{.State.Status}}').' '.escapeshellarg($stoppedLegacyContainerId).')" = '.escapeshellarg('exited'),
        'docker rm '.escapeshellarg($stoppedLegacyContainerId).' >/dev/null',
        '! docker container inspect '.escapeshellarg($stoppedLegacyContainerId).' >/dev/null 2>&1',
    ])."\n";

    expect(collect($commands)->contains(
        fn (string $command): bool => str_contains($command, base64_encode($mutationScript)),
    ))->toBeTrue()
        ->and($mutationScript)->toContain('coolify.applicationId')
        ->toContain('coolify.pullRequestId')
        ->toContain('docker rm '.escapeshellarg($stoppedLegacyContainerId).' >/dev/null')
        ->not->toContain('docker rm -f')
        ->not->toContain('docker start');
    expect($state->fresh()->destination_fence_mutation_sequence)->toBe(2)
        ->and($state->fresh()->active_color)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($state->fresh()->managed_file_sha256)->toBe($managedSha256)
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value);
});
