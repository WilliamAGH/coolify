<?php

use App\Actions\Application\BlueGreen\ExecuteBlueGreenDestinationMutation;
use App\Actions\Application\BlueGreen\ResolveBlueGreenExpectedProxyState;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
    Notification::fake();
});

/** @return array{application: Application, destination: StandaloneDocker, server: Server} */
function blueGreenFirstAdoptionRollbackContext(): array
{
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $application->update([
        'health_check_enabled' => true,
        'ports_mappings' => null,
    ]);
    $application->settings()->update([
        'is_blue_green_deployment_enabled' => true,
        'is_container_label_readonly_enabled' => true,
    ]);

    return [
        'application' => $application->fresh(['settings']),
        'destination' => $destination,
        'server' => $server,
    ];
}

function blueGreenFirstAdoptionDeployment(
    Application $application,
    StandaloneDocker $destination,
    Server $server,
    string $deploymentUuid,
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
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'only_this_server' => true,
    ]);
}

/**
 * Extracts the highest-sequence durable fence state embedded in a remote
 * script, so the fake can mirror what the destination would persist.
 */
function blueGreenFirstAdoptionHighestFenceBlob(string $command): ?string
{
    preg_match_all('#[A-Za-z0-9+/]{24,}={0,2}#', $command, $matches);
    $best = null;
    $bestSequence = -1;
    foreach (array_unique($matches[0]) as $candidate) {
        $decoded = base64_decode($candidate, true);
        if ($decoded === false) {
            continue;
        }
        $record = json_decode($decoded, true);
        if (! is_array($record) || ($record['magic'] ?? null) !== 'coolify-blue-green-destination-fence-v2') {
            continue;
        }
        $sequence = (int) ($record['mutation_sequence'] ?? 0);
        if ($sequence > $bestSequence) {
            $bestSequence = $sequence;
            $best = $candidate;
        }
    }

    return $best;
}

/**
 * The journal embeds the fenced mutation script base64-encoded; this is the
 * exact encoded form of a start-candidate mutation whose only command is the
 * recognizable marker.
 */
function blueGreenFirstAdoptionEncodedStartMarker(): string
{
    return base64_encode("set -eu\nblue-green-start-candidate-marker\n");
}

/**
 * Simulates the destination fence state machine: fenced mutation scripts
 * commit their replacement state file before container commands run, and
 * attestations compare the exact expected state against that file.
 */
function blueGreenFirstAdoptionRemoteFake(?string &$remoteFenceState): void
{
    Process::fake(function ($process) use (&$remoteFenceState) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        if (str_contains($command, 'coolify-blue-green-destination-state-attested')) {
            $expected = blueGreenFirstAdoptionHighestFenceBlob($command);

            return $expected === $remoteFenceState
                ? Process::result(output: 'coolify-blue-green-destination-state-attested')
                : Process::result(output: '', exitCode: 1);
        }
        $mutationBlob = blueGreenFirstAdoptionHighestFenceBlob($command);
        if ($mutationBlob !== null) {
            $remoteFenceState = $mutationBlob;
            if (str_contains($command, blueGreenFirstAdoptionEncodedStartMarker())) {
                // The fenced journal committed its state file, then the exact
                // container command failed: the remote fence advanced while the
                // deployment saw only a mutation error.
                return Process::result(output: '', exitCode: 1);
            }

            return Process::result(output: '');
        }
        if (str_contains($command, 'coolify-blue-green-container:missing')) {
            return Process::result(output: 'coolify-blue-green-container:missing');
        }
        if (str_contains($command, 'printf occupied; else printf missing')) {
            return Process::result(output: 'missing');
        }
        if (str_contains($command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: BlueGreenDeactivationScenario::BOOT_ID);
        }

        return Process::result(output: '[]');
    });
}

it('reconciles a committed fence advance during first-adoption rollback so the retry adopts refreshably', function (): void {
    $context = blueGreenFirstAdoptionRollbackContext();
    $application = $context['application'];
    $destination = $context['destination'];
    $server = $context['server'];
    $first = blueGreenFirstAdoptionDeployment($application, $destination, $server, 'first-adoption-fails');
    $remoteFenceState = null;
    blueGreenFirstAdoptionRemoteFake($remoteFenceState);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $first,
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    $lifecycle->initialize();
    $claim = $lifecycle->claim();

    $failure = null;
    try {
        $lifecycle->promote(
            static function (): void {},
            static fn (): array => ['blue-green-start-candidate-marker'],
        );
    } catch (Throwable $exception) {
        $failure = $exception;
    }
    expect($failure)->not->toBeNull()
        ->and($remoteFenceState)->not->toBeNull();

    $lifecycle->rollback($failure);
    $lifecycle->release();

    $state = ApplicationBlueGreenDeployment::query()
        ->where('application_id', $application->id)
        ->where('standalone_docker_id', $destination->id)
        ->sole();
    expect($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE, (string) $first->fresh()?->logs)
        ->and($state->operation_deployment_uuid)->toBeNull()
        ->and($state->active_color)->toBeNull()
        ->and($state->managed_file_sha256)->toBeNull()
        ->and($first->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($state->destination_fence_operation_id)->toBe($claim->deploymentUuid)
        ->and($state->destination_fence_mutation_sequence)->toBe(1);

    $durableExpectedState = ResolveBlueGreenExpectedProxyState::run($application->fresh(['settings']), $destination, $state);
    expect($durableExpectedState)->not->toBeNull()
        ->and(base64_encode($durableExpectedState->serialize()))->toBe($remoteFenceState);

    $retry = blueGreenFirstAdoptionDeployment($application, $destination, $server, 'first-adoption-retry');
    $retryLifecycle = new BlueGreenDeploymentLifecycle(
        application: $application->fresh(['settings']),
        deployment: $retry,
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );

    try {
        $retryLifecycle->initialize();
        $retryClaim = $retryLifecycle->claim();

        expect($retryClaim)->not->toBeNull()
            ->and($retryClaim->pendingColor)->toBe(BlueGreenDeploymentColor::BLUE)
            ->and($retryClaim->expectedRoutingRevision)->toBe(1)
            ->and($retryClaim->previousActiveColor)->toBeNull();

        $adoption = (new ExecuteBlueGreenDestinationMutation)->replacementStateFor(
            $application->fresh(['settings']),
            $retryClaim,
            $durableExpectedState,
        );
        expect($adoption->operationId)->toBe($retry->deployment_uuid)
            ->and($adoption->managedSha256)->toBeNull()
            ->and($adoption->mutationSequence)->toBe(1)
            ->and($adoption->destinationFenceEpoch)->toBe($durableExpectedState->destinationFenceEpoch);
    } finally {
        $retryLifecycle->release();
    }
});

it('leaves a pristine destination untouched when the failed mutation never committed its fence state', function (): void {
    $context = blueGreenFirstAdoptionRollbackContext();
    $application = $context['application'];
    $destination = $context['destination'];
    $server = $context['server'];
    $first = blueGreenFirstAdoptionDeployment($application, $destination, $server, 'first-adoption-uncommitted');
    $remoteFenceState = null;
    Process::fake(function ($process) use (&$remoteFenceState) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        if (str_contains($command, 'coolify-blue-green-destination-state-attested')) {
            $expected = blueGreenFirstAdoptionHighestFenceBlob($command);

            return $expected === $remoteFenceState
                ? Process::result(output: 'coolify-blue-green-destination-state-attested')
                : Process::result(output: '', exitCode: 1);
        }
        if (str_contains($command, blueGreenFirstAdoptionEncodedStartMarker())) {
            // The mutation failed before its journal committed anything.
            return Process::result(output: '', exitCode: 1);
        }
        if (str_contains($command, 'coolify-blue-green-container:missing')) {
            return Process::result(output: 'coolify-blue-green-container:missing');
        }
        if (str_contains($command, 'printf occupied; else printf missing')) {
            return Process::result(output: 'missing');
        }
        if (str_contains($command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: BlueGreenDeactivationScenario::BOOT_ID);
        }

        return Process::result(output: '[]');
    });
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $first,
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    $lifecycle->initialize();
    $lifecycle->claim();

    $failure = null;
    try {
        $lifecycle->promote(
            static function (): void {},
            static fn (): array => ['blue-green-start-candidate-marker'],
        );
    } catch (Throwable $exception) {
        $failure = $exception;
    }
    expect($failure)->not->toBeNull();

    $lifecycle->rollback($failure);
    $lifecycle->release();

    $state = ApplicationBlueGreenDeployment::query()
        ->where('application_id', $application->id)
        ->where('standalone_docker_id', $destination->id)
        ->sole();
    expect($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE, (string) $first->fresh()?->logs)
        ->and($state->destination_fence_operation_id)->toBeNull()
        ->and((int) $state->destination_fence_mutation_sequence)->toBe(0)
        ->and($first->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and(ResolveBlueGreenExpectedProxyState::run($application->fresh(['settings']), $destination, $state))->toBeNull();
});
