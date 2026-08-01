<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationException;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteOutcome;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteResult;
use App\Actions\Application\BlueGreen\DeactivateBlueGreenApplication;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDeactivationRemoteCommand;
use App\Actions\Application\BlueGreen\ProveBlueGreenDestinationEmpty;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

/**
 * The durable blue-green state claims an active route, but every trace of
 * the application was removed from the destination out-of-band (lab
 * teardown, manual docker rm). The fence cannot match live bytes to durable
 * state, so without a proven-empty escape this traps the application:
 * deactivation records an intervention, recovery reports manual-only, and
 * deletion becomes impossible. These tests lock in the proven-empty
 * completion path and the fail-closed behavior for ambiguous state.
 */
function provenEmptyActiveRouteFixture(): array
{
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $application->update([
        'fqdn' => 'https://proven-empty.example.test',
        'ports_exposes' => '3000',
    ]);
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    $application = $application->fresh(['settings']);

    $activeDeploymentUuid = 'proven-empty-blue-deployment';
    $activeContainerId = str_repeat('e', 64);
    $target = new BlueGreenRoutingTarget(
        destinationId: $destination->id,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: $application->uuid.'-blue',
        greenContainerName: $application->uuid.'-green',
        port: 3000,
        ports: [3000],
        routingRevision: 1,
        publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($activeDeploymentUuid),
        destinationFenceEpoch: 1,
        operationId: $activeDeploymentUuid,
        mutationSequence: 1,
        activeDeploymentUuid: $activeDeploymentUuid,
        activeContainerId: $activeContainerId,
        destinationTopologyDigest: hash('sha256', 'proven-empty-destination'),
    );
    $configuration = CompileBlueGreenProxyConfiguration::run($application, $destination, $target);

    ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $destination->server_id,
        'server_name' => $destination->server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $activeDeploymentUuid,
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_server_boot_id' => BlueGreenDeactivationScenario::BOOT_ID,
        'blue_green_topology_digest' => $configuration->state->destinationTopologyDigest,
        'blue_green_routing_config_digest' => $configuration->state->applicationRoutingConfigDigest,
        'blue_green_backend_port_inventory' => BlueGreenBackendPortInventory::fromPorts([3000])->serialized,
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

    $application->delete();
    $application->newQuery()
        ->withTrashed()
        ->whereKey($application->id)
        ->update(['deleted_at' => now()->subMinutes(20)->startOfSecond()]);

    return [$application->fresh(), $destination, $state];
}

function fakeRemoteForProvenEmpty(bool $destinationEmpty): void
{
    Process::fake(function (PendingProcess $process) use ($destinationEmpty) {
        if (str_contains($process->command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: BlueGreenDeactivationScenario::BOOT_ID, exitCode: 0);
        }
        if (str_contains($process->command, 'coolify-blue-green-destination-proven-empty')) {
            return Process::result(
                output: $destinationEmpty
                    ? 'coolify-blue-green-destination-proven-empty'
                    : 'coolify-blue-green-destination-not-empty',
                exitCode: 0,
            );
        }

        // Fenced deactivation commands base64-wrap their inner script; decode
        // it to tell the managed-bytes capture apart from the mutations.
        $innerCommand = $process->command;
        if (preg_match("/printf %s '([A-Za-z0-9+\/=]+)' \| base64 -d/", $process->command, $matches) === 1) {
            $decoded = base64_decode($matches[1], true);
            if (is_string($decoded)) {
                $innerCommand = $decoded;
            }
        }
        if (preg_match('/^checksum=\$\(sha256sum/m', $innerCommand) === 1) {
            // The managed proxy bytes are gone: readSource sees the remote
            // invariant failure exactly as the fenced wrapper reports it.
            return Process::result(
                output: (new ExecuteBlueGreenDeactivationRemoteCommand)->encode(
                    new BlueGreenDeactivationRemoteResult(
                        outcome: BlueGreenDeactivationRemoteOutcome::InvariantViolation,
                        exitStatus: 1,
                        output: '',
                    ),
                ),
                exitCode: 0,
            );
        }

        return Process::result(
            output: (new ExecuteBlueGreenDeactivationRemoteCommand)->encode(
                new BlueGreenDeactivationRemoteResult(
                    outcome: BlueGreenDeactivationRemoteOutcome::Success,
                    exitStatus: 0,
                    output: '',
                ),
            ),
            exitCode: 0,
        );
    });
}

it('completes deactivation on a proven-empty destination instead of recording an intervention', function (): void {
    [$application, $destination, $state] = provenEmptyActiveRouteFixture();
    fakeRemoteForProvenEmpty(destinationEmpty: true);

    DeactivateBlueGreenApplication::run($application);

    $deactivation = ApplicationBlueGreenDeactivation::query()
        ->where('application_id', $application->id)
        ->latest('id')
        ->firstOrFail();

    expect($deactivation->phase)->toBe(BlueGreenDeactivationPhase::COMPLETED)
        ->and($deactivation->completed_at)->not->toBeNull()
        ->and(
            ApplicationBlueGreenDeactivation::query()
                ->where('application_id', $application->id)
                ->where('phase', BlueGreenDeactivationPhase::INTERVENTION_REQUIRED->value)
                ->exists()
        )->toBeFalse()
        // Completion through the proven-empty route-less path retires the
        // durable deployment state row for a deletion deactivation.
        ->and($state->fresh())->toBeNull();
});

it('stays fail-closed with an intervention when the destination is not proven empty', function (): void {
    [$application, $destination] = provenEmptyActiveRouteFixture();
    fakeRemoteForProvenEmpty(destinationEmpty: false);

    try {
        DeactivateBlueGreenApplication::run($application);
        $this->fail('Expected the deactivation to keep its invariant failure.');
    } catch (BlueGreenDeactivationException) {
        // Expected: the exact invariant failure survives an ambiguous destination.
    }

    expect(
        ApplicationBlueGreenDeactivation::query()
            ->where('application_id', $application->id)
            ->where('phase', BlueGreenDeactivationPhase::INTERVENTION_REQUIRED->value)
            ->exists()
    )->toBeTrue();
});

it('force deletes a trashed application whose fqdn is still set after its blue-green state cleared', function (): void {
    ['application' => $application] = BlueGreenDeactivationScenario::context();

    $application->delete();
    $application->forceDelete();

    expect($application->newQueryWithoutScopes()->whereKey($application->id)->exists())->toBeFalse();
});

it('proves destination emptiness only through its exact remote attestation', function (): void {
    $probe = (new ProveBlueGreenDestinationEmpty)->probeCommandFor('app-uuid-123', '/data/coolify/proxy', 7);

    expect($probe)
        ->toContain("probe_uuid='app-uuid-123'")
        ->toContain('docker ps -a --format')
        ->toContain('grep -F "$probe_uuid"')
        ->toContain('/data/coolify/proxy/dynamic')
        ->toContain('coolify-blue-green-destination-proven-empty')
        ->toContain('coolify-blue-green-destination-not-empty');
});
