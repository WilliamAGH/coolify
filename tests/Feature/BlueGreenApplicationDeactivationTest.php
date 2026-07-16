<?php

use App\Actions\Application\BlueGreen\BlueGreenDeactivationException;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationInProgressException;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteOutcome;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteResult;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationTransportException;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\DeactivateBlueGreenApplicationDestination;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDeactivationRemoteCommand;
use App\Actions\Application\BlueGreen\FindBlueGreenDeactivationFence;
use App\Actions\Application\BlueGreen\ResumeBlueGreenDeactivations;
use App\Actions\Application\StopApplication;
use App\Actions\Application\StopApplicationOneServer;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\User\DeleteUserResources;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Events\ServiceStatusChanged;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

function blueGreenDeactivationInnerCommand(string $command): string
{
    if (preg_match_all('/[A-Za-z0-9+\/=]{40,}/', $command, $matches) < 1) {
        return $command;
    }

    foreach ($matches[0] as $candidate) {
        $decoded = base64_decode($candidate, true);
        if (is_string($decoded) && str_starts_with($decoded, 'set -eu')) {
            return $decoded;
        }
    }

    return $command;
}

it('proves the exact tombstone, drains sockets, and rechecks it before exact container cleanup', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::idleState($application, $destination, $application->uuid.'-legacy');
    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$application, $destination]]);

    $deactivated = DeactivateBlueGreenApplicationDestination::run($application, $destination->id);

    expect($deactivated)->toBeTrue()
        ->and(ApplicationBlueGreenDeployment::query()->whereKey($state->id)->exists())->toBeFalse()
        ->and(ApplicationBlueGreenDeactivation::query()->sole()->phase)->toBe(BlueGreenDeactivationPhase::COMPLETED)
        ->and(ApplicationBlueGreenDeactivation::query()->sole()->completed_at)->not->toBeNull();
    Process::assertRan(function ($process): bool {
        $command = blueGreenDeactivationInnerCommand($process->command);

        return str_contains($command, 'expectedCurrentSha256') === false
            && str_contains($command, 'current_checksum=$(sha256sum')
            && str_contains($command, 'tombstone-present');
    });
    Process::assertRan(function ($process): bool {
        $command = blueGreenDeactivationInnerCommand($process->command);

        return str_contains($command, '| tr -d')
            && str_contains($command, 'date +%s')
            && strpos($command, 'flock -x 9') < strpos($command, 'checksum=$(sha256sum');
    });
    Process::assertRan(function ($process): bool {
        $command = blueGreenDeactivationInnerCommand($process->command);

        return str_contains($command, '"$status" != 418')
            && str_contains($command, strtolower(BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER))
            && ! str_contains($command, '127.0.0.1:8080')
            && ! str_contains($command, 'docker stop');
    });
    Process::assertRan(function ($process) use ($application): bool {
        $command = blueGreenDeactivationInnerCommand($process->command);

        return str_contains($command, "{$application->uuid}-blue")
            && str_contains($command, "{$application->uuid}-green")
            && str_contains($command, "{$application->uuid}-legacy")
            && str_contains($command, '/proc/$pid/net/tcp6')
            && str_contains($command, 'stable_zero_observations')
            && substr_count($command, 'tombstone_checksum=$(sha256sum') >= 4
            && strpos($command, 'flock -x 9') < strpos($command, 'tombstone_checksum=$(sha256sum')
            && str_contains($command, 'docker stop --time=')
            && ! str_contains($command, 'docker ps -a --filter');
    });
});

it('retains the durable row and marks intervention when the state is not safe to deactivate', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = ApplicationBlueGreenDeployment::create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'pending_color' => BlueGreenDeploymentColor::BLUE,
        'pending_deployment_uuid' => 'unsafe-pending-deployment',
        'phase' => BlueGreenDeploymentPhase::PREPARING,
        'routing_revision' => 1,
    ]);
    Process::fake();

    expect(fn () => DeactivateBlueGreenApplicationDestination::run($application, $destination->id))
        ->toThrow(BlueGreenDeactivationException::class, 'not idle');

    $deactivation = ApplicationBlueGreenDeactivation::query()->sole();
    expect($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($state->fresh()->deactivation_operation_id)->toBe($deactivation->operation_id)
        ->and($state->fresh()->deactivation_started_at?->equalTo($deactivation->started_at))->toBeTrue();
    Process::assertNothingRan();
});

it('keeps transport-ambiguous remote failures resumable under the exact durable owner', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::idleState($application, $destination);
    Process::fake(fn () => Process::result(exitCode: 255, errorOutput: 'ssh transport disconnected'));

    expect(fn () => DeactivateBlueGreenApplicationDestination::run($application, $destination->id))
        ->toThrow(BlueGreenDeactivationTransportException::class, 'remains resumable');

    $deactivation = ApplicationBlueGreenDeactivation::query()->sole();
    expect($deactivation->phase)->toBe(BlueGreenDeactivationPhase::DEACTIVATING)
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::DEACTIVATING)
        ->and($state->fresh()->deactivation_operation_id)->toBe($deactivation->operation_id)
        ->and($state->fresh()->deactivation_started_at?->equalTo($deactivation->started_at))->toBeTrue();
});

it('keeps a malformed remote outcome resumable without marking intervention', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::idleState($application, $destination);
    Process::fake(fn () => Process::result(output: 'not-a-deactivation-envelope'));

    expect(fn () => DeactivateBlueGreenApplicationDestination::run($application, $destination->id))
        ->toThrow(BlueGreenDeactivationTransportException::class, 'remains resumable');

    $deactivation = ApplicationBlueGreenDeactivation::query()->sole();
    expect($deactivation->phase)->toBe(BlueGreenDeactivationPhase::DEACTIVATING)
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::DEACTIVATING)
        ->and($state->fresh()->deactivation_operation_id)->toBe($deactivation->operation_id);
});

it('marks a proven remote invariant for intervention under the exact prepared owner', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::idleState($application, $destination);
    $outcome = (new ExecuteBlueGreenDeactivationRemoteCommand)->encode(new BlueGreenDeactivationRemoteResult(
        BlueGreenDeactivationRemoteOutcome::InvariantViolation,
        19,
        "destination deadline expired\n",
    ));
    Process::fake(fn () => Process::result(output: $outcome));

    expect(fn () => DeactivateBlueGreenApplicationDestination::run($application, $destination->id))
        ->toThrow(BlueGreenDeactivationException::class, 'exit status 19');

    $deactivation = ApplicationBlueGreenDeactivation::query()->sole();
    expect($deactivation->phase)->toBe(BlueGreenDeactivationPhase::INTERVENTION_REQUIRED)
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($state->fresh()->deactivation_operation_id)->toBe($deactivation->operation_id);
});

it('never adopts a newer deactivation owner while marking a failed prepared operation', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::idleState($application, $destination);
    $newOperationId = str_repeat('f', 64);
    $newStartedAt = now()->addMinute()->startOfSecond();
    $outcome = (new ExecuteBlueGreenDeactivationRemoteCommand)->encode(new BlueGreenDeactivationRemoteResult(
        BlueGreenDeactivationRemoteOutcome::InvariantViolation,
        20,
        "route ownership changed\n",
    ));
    Process::fake(function () use ($application, $destination, $state, $newOperationId, $newStartedAt, $outcome) {
        ApplicationBlueGreenDeactivation::query()
            ->where('application_id', $application->id)
            ->where('standalone_docker_id', $destination->id)
            ->update([
                'operation_id' => $newOperationId,
                'started_at' => $newStartedAt,
            ]);
        ApplicationBlueGreenDeployment::query()->whereKey($state->id)->update([
            'deactivation_operation_id' => $newOperationId,
            'deactivation_started_at' => $newStartedAt,
        ]);

        return Process::result(output: $outcome);
    });

    expect(fn () => DeactivateBlueGreenApplicationDestination::run($application, $destination->id))
        ->toThrow(BlueGreenDeactivationException::class, 'owner changed while intervention was being recorded');

    $deactivation = ApplicationBlueGreenDeactivation::query()->sole();
    expect($deactivation->operation_id)->toBe($newOperationId)
        ->and($deactivation->started_at?->equalTo($newStartedAt))->toBeTrue()
        ->and($deactivation->phase)->toBe(BlueGreenDeactivationPhase::DEACTIVATING)
        ->and($state->fresh()->deactivation_operation_id)->toBe($newOperationId)
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::DEACTIVATING);
});

it('idempotently resumes a proven deactivation after an interrupted stop attempt', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::idleState(
        $application,
        $destination,
        phase: BlueGreenDeploymentPhase::DEACTIVATING,
    );
    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$application, $destination]]);

    expect(DeactivateBlueGreenApplicationDestination::run($application, $destination->id))->toBeTrue()
        ->and(ApplicationBlueGreenDeployment::query()->whereKey($state->id)->exists())->toBeFalse();
    Process::assertRan(fn ($process) => str_contains(blueGreenDeactivationInnerCommand($process->command), 'coolify-blue-green-'));
});

it('fails closed instead of resuming an unproven deactivating state', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::idleState($application, $destination);
    $state->update(['phase' => BlueGreenDeploymentPhase::DEACTIVATING]);
    Process::fake();

    expect(fn () => DeactivateBlueGreenApplicationDestination::run($application, $destination->id))
        ->toThrow(BlueGreenDeactivationException::class, 'no durable deactivation fence');

    expect($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::DEACTIVATING)
        ->and(ApplicationBlueGreenDeactivation::query()->doesntExist())->toBeTrue();
    Process::assertNothingRan();
});

it('uses a global queue cutoff to fence preexisting matching queues only', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $firstFencedDeployment = BlueGreenDeactivationScenario::queuedDeployment(
        $application,
        $destination,
        'first-queued-before-no-state-deactivation',
    );
    $unrelatedApplication = Application::factory()->create([
        'environment_id' => $application->environment_id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $unrelatedBetweenFencedDeployments = BlueGreenDeactivationScenario::queuedDeployment(
        $unrelatedApplication,
        $destination,
        'unrelated-between-fenced-deployments',
    );
    $secondFencedDeployment = BlueGreenDeactivationScenario::queuedDeployment(
        $application,
        $destination,
        'second-queued-before-no-state-deactivation',
    );
    $unrelatedHighIdPreviewDeployment = BlueGreenDeactivationScenario::queuedDeployment(
        $application,
        $destination,
        'unrelated-high-id-preview-deployment',
        pullRequestId: 42,
    );
    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$application, $destination]]);

    expect(DeactivateBlueGreenApplicationDestination::run($application, $destination->id))->toBeTrue();

    $deactivation = ApplicationBlueGreenDeactivation::query()->sole();
    expect($firstFencedDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
        ->and($secondFencedDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
        ->and(FindBlueGreenDeactivationFence::run($firstFencedDeployment->fresh()))->not->toBeNull()
        ->and(FindBlueGreenDeactivationFence::run($secondFencedDeployment->fresh()))->not->toBeNull()
        ->and($deactivation->queue_cutoff_id)->toBe($unrelatedHighIdPreviewDeployment->id)
        ->and($unrelatedBetweenFencedDeployments->fresh()->status)->toBe(ApplicationDeploymentStatus::QUEUED->value)
        ->and(FindBlueGreenDeactivationFence::run($unrelatedBetweenFencedDeployments->fresh()))->toBeNull()
        ->and($unrelatedHighIdPreviewDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::QUEUED->value)
        ->and(FindBlueGreenDeactivationFence::run($unrelatedHighIdPreviewDeployment->fresh()))->toBeNull();

    $laterDeployment = BlueGreenDeactivationScenario::queuedDeployment(
        $application,
        $destination,
        'queued-after-no-state-deactivation',
    );
    expect($laterDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::QUEUED->value)
        ->and(FindBlueGreenDeactivationFence::run($laterDeployment))->toBeNull();
});

it('deactivates a rolled-back state without requiring its retained routing revision to be active', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = ApplicationBlueGreenDeployment::create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 7,
    ]);
    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$application, $destination]]);

    expect(DeactivateBlueGreenApplicationDestination::run($application, $destination->id))->toBeTrue()
        ->and(ApplicationBlueGreenDeployment::query()->whereKey($state->id)->exists())->toBeFalse();
});

it('automatically resumes stale deactivations using the exact durable claim', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::idleState(
        $application,
        $destination,
        phase: BlueGreenDeploymentPhase::DEACTIVATING,
    );
    $startedAt = now()->subMinutes(10);
    $state->update(['deactivation_started_at' => $startedAt]);
    ApplicationBlueGreenDeactivation::query()
        ->where('application_id', $application->id)
        ->where('standalone_docker_id', $destination->id)
        ->update(['started_at' => $startedAt]);
    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$application, $destination]]);

    $results = ResumeBlueGreenDeactivations::run(staleAfterSeconds: 300);

    expect($results)->toHaveCount(1)
        ->and($results[0]->outcome)->toBe('resumed')
        ->and(ApplicationBlueGreenDeployment::query()->whereKey($state->id)->exists())->toBeFalse()
        ->and(ApplicationBlueGreenDeactivation::query()->sole()->phase)->toBe(BlueGreenDeactivationPhase::COMPLETED);
});

it('automatically resumes a stale state-less deactivation from its single durable owner', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $deactivation = ApplicationBlueGreenDeactivation::create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'operation_id' => bin2hex(random_bytes(32)),
        'started_at' => now()->subMinutes(10),
        'queue_cutoff_id' => 0,
        'phase' => BlueGreenDeactivationPhase::DEACTIVATING,
    ]);
    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$application, $destination]]);

    $results = ResumeBlueGreenDeactivations::run(staleAfterSeconds: 300);

    expect($results)->toHaveCount(1)
        ->and($results[0]->stateId)->toBe($deactivation->id)
        ->and($results[0]->outcome)->toBe('resumed')
        ->and($deactivation->fresh()->phase)->toBe(BlueGreenDeactivationPhase::COMPLETED)
        ->and($deactivation->fresh()->completed_at)->not->toBeNull();
});

it('retains a proven deactivation claim when container provenance becomes ambiguous', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::idleState(
        $application,
        $destination,
        phase: BlueGreenDeploymentPhase::DEACTIVATING,
    );
    $operationId = $state->deactivation_operation_id;
    ApplicationDeploymentQueue::query()
        ->where('deployment_uuid', $state->blue_deployment_uuid)
        ->update(['blue_green_phase' => BlueGreenDeploymentPhase::PREPARING->value]);
    Process::fake();

    expect(fn () => DeactivateBlueGreenApplicationDestination::run($application, $destination->id))
        ->toThrow(BlueGreenDeactivationException::class, 'provenance is incomplete');

    expect($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($state->fresh()->deactivation_operation_id)->toBe($operationId);
    Process::assertNothingRan();
});

it('defers a concurrent deactivation without changing durable state', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::idleState($application, $destination);
    $lock = Cache::lock(BlueGreenDeploymentLock::key($application->id, $destination->id), 60);
    expect($lock->get())->toBeTrue();
    Process::fake();

    try {
        expect(fn () => DeactivateBlueGreenApplicationDestination::run($application, $destination->id))
            ->toThrow(BlueGreenDeactivationInProgressException::class, 'already in progress');

        expect($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE);
        Process::assertNothingRan();
    } finally {
        $lock->release();
    }
});

it('uses the strict lifecycle for every durable destination when stopping an application', function () {
    ['application' => $application, 'destination' => $primaryDestination, 'server' => $primaryServer, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $additionalServer = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $primaryServer->private_key_id,
    ]);
    $additionalServer->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $additionalServer->refresh();
    $additionalServer->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $additionalServer->save();
    $additionalDestination = $additionalServer->standaloneDockers()->firstOrFail();
    $application->additional_servers()->attach($additionalServer->id, [
        'standalone_docker_id' => $additionalDestination->id,
        'status' => 'exited',
    ]);
    BlueGreenDeactivationScenario::idleState($application, $primaryDestination);
    BlueGreenDeactivationScenario::idleState($application, $additionalDestination);
    Event::fake([ServiceStatusChanged::class]);
    BlueGreenDeactivationScenario::fakeLifecycleProcesses([
        [$application, $primaryDestination],
        [$application, $additionalDestination],
    ]);

    StopApplication::run($application, dockerCleanup: false);

    expect($application->blueGreenDeployments()->doesntExist())->toBeTrue();
    Process::assertRan(fn ($process) => str_contains(blueGreenDeactivationInnerCommand($process->command), 'coolify-blue-green-'));
});

it('still stops ordinary and preview containers on a server that had blue-green state', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    BlueGreenDeactivationScenario::idleState($application, $destination);
    $containers = implode(PHP_EOL, [
        json_encode([
            'Names' => $application->uuid.'-blue',
            'Labels' => "coolify.applicationId={$application->id}",
            'State' => 'running',
        ], JSON_THROW_ON_ERROR),
        json_encode([
            'Names' => 'ordinary-container',
            'Labels' => "coolify.applicationId={$application->id}",
            'State' => 'running',
        ], JSON_THROW_ON_ERROR),
        json_encode([
            'Names' => 'preview-container',
            'Labels' => "coolify.applicationId={$application->id},coolify.pullRequestId=42",
            'State' => 'running',
        ], JSON_THROW_ON_ERROR),
    ]);
    Event::fake([ServiceStatusChanged::class]);
    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$application, $destination]], $containers);

    StopApplication::run($application, previewDeployments: true, dockerCleanup: false);

    Process::assertRan(function ($process): bool {
        $command = blueGreenDeactivationInnerCommand($process->command);

        return str_contains($command, 'docker stop --time=')
            && str_contains($command, 'ordinary-container');
    });
    Process::assertRan(function ($process): bool {
        $command = blueGreenDeactivationInnerCommand($process->command);

        return str_contains($command, 'docker stop --time=')
            && str_contains($command, 'preview-container');
    });
    Process::assertNotRan(fn ($process) => str_contains(
        blueGreenDeactivationInnerCommand($process->command),
        "docker stop --time={$application->settings->stopGracePeriodSeconds()} {$application->uuid}-blue",
    ));
});

it('uses the strict lifecycle for a selected application server', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    BlueGreenDeactivationScenario::idleState($application, $destination);
    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$application, $destination]]);

    StopApplicationOneServer::run($application, $server);

    expect($application->blueGreenDeployments()->doesntExist())->toBeTrue();
});

it('does not force-delete an application when blue-green deactivation requires intervention', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    BlueGreenDeactivationScenario::enableBlueGreen($application);
    $state = ApplicationBlueGreenDeployment::create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'pending_color' => BlueGreenDeploymentColor::BLUE,
        'pending_deployment_uuid' => 'unsafe-delete-pending',
        'phase' => BlueGreenDeploymentPhase::PREPARING,
        'routing_revision' => 1,
    ]);
    Process::fake();

    expect(fn () => (new DeleteResourceJob($application, dockerCleanup: false))->handle())
        ->toThrow(BlueGreenDeactivationException::class, 'not idle');

    $tombstonedApplication = Application::withTrashed()->findOrFail($application->id);
    expect($tombstonedApplication->trashed())->toBeTrue()
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED);
});

it('force-deletes an opted-in application only after strict durable deactivation completes', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    BlueGreenDeactivationScenario::enableBlueGreen($application);
    BlueGreenDeactivationScenario::idleState($application, $destination);
    Event::fake([ServiceStatusChanged::class]);
    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$application, $destination]]);

    (new DeleteResourceJob($application, dockerCleanup: false))->handle();

    expect(Application::withTrashed()->whereKey($application->id)->exists())->toBeFalse()
        ->and(ApplicationBlueGreenDeployment::query()
            ->where('application_id', $application->id)
            ->doesntExist())->toBeTrue();
});

it('rejects direct opted-in force deletion without completed deactivation authorization', function () {
    ['application' => $application] = BlueGreenDeactivationScenario::context();
    BlueGreenDeactivationScenario::enableBlueGreen($application);

    expect(fn () => $application->forceDelete())
        ->toThrow(RuntimeException::class, 'completed strict deactivation authorization');

    expect(Application::query()->whereKey($application->id)->exists())->toBeTrue();
});

it('deactivates blue-green state before the administrative direct force-delete path', function () {
    ['application' => $application, 'destination' => $destination, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    BlueGreenDeactivationScenario::enableBlueGreen($application);
    BlueGreenDeactivationScenario::idleState($application, $destination);
    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$application, $destination]]);

    $counts = (new DeleteUserResources($user))->execute();

    expect($counts['applications'])->toBe(1)
        ->and(Application::withTrashed()->whereKey($application->id)->exists())->toBeFalse()
        ->and(ApplicationBlueGreenDeployment::query()
            ->where('application_id', $application->id)
            ->doesntExist())->toBeTrue();
    Process::assertRan(fn ($process) => str_contains(blueGreenDeactivationInnerCommand($process->command), 'coolify-blue-green-'));
});

it('retries an incomplete canonical deletion through the administrative resource caller', function () {
    ['application' => $application, 'destination' => $destination, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    BlueGreenDeactivationScenario::enableBlueGreen($application);
    BlueGreenDeactivationScenario::idleState($application, $destination);
    Process::fake(fn () => Process::result(exitCode: 255, errorOutput: 'ssh transport disconnected'));

    expect(fn () => (new DeleteUserResources($user))->execute())
        ->toThrow(BlueGreenDeactivationTransportException::class, 'remains resumable');

    $tombstonedApplication = Application::withTrashed()->findOrFail($application->id);
    expect($tombstonedApplication->trashed())->toBeTrue()
        ->and(ApplicationBlueGreenDeactivation::query()->sole()->phase)
        ->toBe(BlueGreenDeactivationPhase::DEACTIVATING);

    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$tombstonedApplication, $destination]]);
    $counts = (new DeleteUserResources($user))->execute();

    expect($counts['applications'])->toBe(1)
        ->and(Application::withTrashed()->whereKey($application->id)->doesntExist())->toBeTrue();
});

it('deactivates blue-green state before the user model deletion cascade force-deletes applications', function () {
    ['application' => $application, 'destination' => $destination, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    BlueGreenDeactivationScenario::idleState($application, $destination);
    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$application, $destination]]);

    $user->delete();

    expect(Application::withTrashed()->whereKey($application->id)->exists())->toBeFalse()
        ->and(ApplicationBlueGreenDeployment::query()
            ->where('application_id', $application->id)
            ->doesntExist())->toBeTrue();
    Process::assertRan(fn ($process) => str_contains(blueGreenDeactivationInnerCommand($process->command), 'coolify-blue-green-'));
});

it('retries an incomplete canonical deletion through the user model caller', function () {
    ['application' => $application, 'destination' => $destination, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    BlueGreenDeactivationScenario::enableBlueGreen($application);
    BlueGreenDeactivationScenario::idleState($application, $destination);
    Process::fake(fn () => Process::result(exitCode: 255, errorOutput: 'ssh transport disconnected'));

    expect(fn () => $user->delete())
        ->toThrow(BlueGreenDeactivationTransportException::class, 'remains resumable');

    $tombstonedApplication = Application::withTrashed()->findOrFail($application->id);
    expect($tombstonedApplication->trashed())->toBeTrue()
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();

    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$tombstonedApplication, $destination]]);
    $user->delete();

    expect(Application::withTrashed()->whereKey($application->id)->doesntExist())->toBeTrue()
        ->and(User::query()->whereKey($user->id)->doesntExist())->toBeTrue();
});

it('does not tombstone applications before rejecting deletion of the sole root-team user', function () {
    $rootUser = User::factory()->create(['id' => 0]);
    ['application' => $application, 'destination' => $destination, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $team->members()->attach($rootUser->id, ['role' => 'owner']);
    $state = BlueGreenDeactivationScenario::idleState($application, $destination);
    Process::fake();

    expect(fn () => $rootUser->delete())
        ->toThrow(Exception::class, 'alone in the root team');

    expect(Application::withTrashed()->findOrFail($application->id)->trashed())->toBeFalse()
        ->and($state->fresh())->not->toBeNull();
    Process::assertNothingRan();
});
