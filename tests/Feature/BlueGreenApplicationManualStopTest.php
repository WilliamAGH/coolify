<?php

use App\Actions\Application\BlueGreen\BlueGreenDeactivationException;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationFailure;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationInProgressException;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteOutcome;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteResult;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationTransportException;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\DeactivateBlueGreenApplication;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDeactivationRemoteCommand;
use App\Actions\Application\BlueGreen\ResumeBlueGreenDeactivations;
use App\Actions\Application\StopApplication;
use App\Actions\Application\StopApplicationOneServer;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Events\ServiceStatusChanged;
use App\Livewire\Project\Application\Heading as ApplicationHeading;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\User;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

function fakeManualBlueGreenStopRemoteSuccess(?Closure $onFencedMutation = null): void
{
    Process::fake(function (PendingProcess $process) use ($onFencedMutation) {
        if (str_contains($process->command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: BlueGreenDeactivationScenario::BOOT_ID, exitCode: 0);
        }

        $replacementState = manualBlueGreenRemoteReplacementState($process);
        if ($replacementState !== null && $onFencedMutation !== null) {
            $onFencedMutation($process, $replacementState);
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

/** @return list<string> */
function manualBlueGreenDecodedRemotePayloads(PendingProcess $process): array
{
    $payloads = [];
    $pendingPayloads = [$process->command];
    $seenPayloads = [];

    for ($depth = 0; $depth < 3; $depth++) {
        $nextPayloads = [];
        foreach ($pendingPayloads as $pendingPayload) {
            preg_match_all("/'([A-Za-z0-9+\\/=]{16,})'/", $pendingPayload, $matches);
            foreach ($matches[1] ?? [] as $encodedPayload) {
                $decodedPayload = base64_decode($encodedPayload, true);
                if (! is_string($decodedPayload) || $decodedPayload === '' || isset($seenPayloads[$decodedPayload])) {
                    continue;
                }

                $seenPayloads[$decodedPayload] = true;
                $payloads[] = $decodedPayload;
                $nextPayloads[] = $decodedPayload;
            }
        }
        $pendingPayloads = $nextPayloads;
    }

    return $payloads;
}

function manualBlueGreenRemoteReplacementState(PendingProcess $process): ?BlueGreenProxyState
{
    foreach (manualBlueGreenDecodedRemotePayloads($process) as $payload) {
        try {
            $state = BlueGreenProxyState::parse($payload);
        } catch (Throwable) {
            continue;
        }

        if ($state->managedSha256 === null) {
            return $state;
        }
    }

    return null;
}

function manualBlueGreenLegacyMutationPayload(PendingProcess $process, string $containerName): ?string
{
    foreach (manualBlueGreenDecodedRemotePayloads($process) as $payload) {
        if (str_contains($payload, 'docker container inspect '.escapeshellarg($containerName))) {
            return $payload;
        }
    }

    return null;
}

/**
 * @param  array{server: Server}  $context
 * @return array{server: Server, destination: StandaloneDocker}
 */
function manualBlueGreenAdditionalDestination(array $context): array
{
    $additionalServer = Server::factory()->create([
        'team_id' => $context['server']->team_id,
        'private_key_id' => $context['server']->private_key_id,
    ]);
    $additionalServer->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $additionalServer->refresh();
    $additionalServer->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $additionalServer->save();

    return [
        'server' => $additionalServer,
        'destination' => $additionalServer->standaloneDockers()->firstOrFail(),
    ];
}

it('adopts and removes the exact legacy container through a fenced first manual stop', function () {
    $context = BlueGreenDeactivationScenario::context();
    $application = $context['application'];
    $destination = $context['destination'];
    $observedState = null;
    $observedDeactivation = null;
    $replacementState = null;
    $legacyMutationPayload = null;

    $application->settings()->update([
        'is_blue_green_deployment_enabled' => true,
        'is_container_label_readonly_enabled' => true,
    ]);
    expect(ApplicationBlueGreenDeployment::query()->doesntExist())->toBeTrue();

    fakeManualBlueGreenStopRemoteSuccess(function (
        PendingProcess $process,
        BlueGreenProxyState $fencedReplacementState,
    ) use (
        $application,
        &$legacyMutationPayload,
        &$observedDeactivation,
        &$observedState,
        &$replacementState,
    ): void {
        $observedState = ApplicationBlueGreenDeployment::query()->sole();
        $observedDeactivation = ApplicationBlueGreenDeactivation::query()->sole();
        $replacementState = $fencedReplacementState;
        $legacyMutationPayload = manualBlueGreenLegacyMutationPayload($process, (string) $application->uuid);
    });
    Event::fake([ServiceStatusChanged::class]);

    $result = StopApplication::run($application, dockerCleanup: false);
    $stoppedState = ApplicationBlueGreenDeployment::query()->sole();
    $deactivation = ApplicationBlueGreenDeactivation::query()->sole();

    expect($result)->toBeNull()
        ->and($observedState)->toBeInstanceOf(ApplicationBlueGreenDeployment::class)
        ->and($observedState->phase)->toBe(BlueGreenDeploymentPhase::DEACTIVATING)
        ->and($observedState->legacy_container_name)->toBe($application->uuid)
        ->and($observedDeactivation)->toBeInstanceOf(ApplicationBlueGreenDeactivation::class)
        ->and($observedDeactivation->phase)->toBe(BlueGreenDeactivationPhase::STOPPING)
        ->and($replacementState)->toBeInstanceOf(BlueGreenProxyState::class)
        ->and($replacementState->operationId)->toBe($deactivation->operation_id)
        ->and($replacementState->managedSha256)->toBeNull()
        ->and($legacyMutationPayload)->toBeString()
        ->and($legacyMutationPayload)->toContain('docker container inspect '.escapeshellarg((string) $application->uuid))
        ->and($legacyMutationPayload)->toContain('docker rm -f "$container_id"')
        ->and($stoppedState->phase)->toBe(BlueGreenDeploymentPhase::STOPPED)
        ->and($stoppedState->legacy_container_name)->toBeNull()
        ->and($stoppedState->destination_fence_operation_id)->toBe($replacementState->operationId)
        ->and($stoppedState->destination_fence_mutation_sequence)->toBe($replacementState->mutationSequence)
        ->and($deactivation->phase)->toBe(BlueGreenDeactivationPhase::STOPPED);
});

it('consumes the exact fenced manual-stop proof when blue-green is disabled', function (): void {
    $context = BlueGreenDeactivationScenario::context();
    $application = $context['application'];
    $destination = $context['destination'];
    BlueGreenDeactivationScenario::routeLessState($application, $destination);
    fakeManualBlueGreenStopRemoteSuccess();
    Event::fake([ServiceStatusChanged::class]);

    StopApplication::run($application, dockerCleanup: false);
    $stoppedState = ApplicationBlueGreenDeployment::query()->sole();
    $stoppedDeactivation = ApplicationBlueGreenDeactivation::query()->sole();

    expect($stoppedState->phase)->toBe(BlueGreenDeploymentPhase::STOPPED)
        ->and($stoppedState->destination_fence_operation_id)->toBe($stoppedDeactivation->operation_id)
        ->and($stoppedState->destination_topology_digest)->not->toBeNull()
        ->and($stoppedState->application_routing_config_digest)->not->toBeNull()
        ->and($stoppedDeactivation->phase)->toBe(BlueGreenDeactivationPhase::STOPPED);

    $setting = $application->settings()->firstOrFail();
    $setting->is_blue_green_deployment_enabled = false;

    expect($setting->save())->toBeTrue()
        ->and($setting->fresh()->is_blue_green_deployment_enabled)->toBeFalse()
        ->and(ApplicationBlueGreenDeployment::query()->doesntExist())->toBeTrue()
        ->and(ApplicationBlueGreenDeactivation::query()->doesntExist())->toBeTrue();
});

it('records a typed remote invariant failure through manual stop and renders its durable intervention', function (): void {
    $context = BlueGreenDeactivationScenario::context();
    $application = $context['application'];
    $destination = $context['destination'];
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    $rawRemoteDetail = str_repeat('credential=not-for-public-display ', 40);
    $publicReason = 'The destination proved a blue-green deactivation invariant failure.';

    Process::fake(function (PendingProcess $process) use ($rawRemoteDetail) {
        if (str_contains($process->command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: BlueGreenDeactivationScenario::BOOT_ID, exitCode: 0);
        }

        return Process::result(
            output: (new ExecuteBlueGreenDeactivationRemoteCommand)->encode(
                new BlueGreenDeactivationRemoteResult(
                    outcome: BlueGreenDeactivationRemoteOutcome::InvariantViolation,
                    exitStatus: 19,
                    output: $rawRemoteDetail,
                ),
            ),
            exitCode: 0,
        );
    });

    $exception = null;
    try {
        StopApplication::run($application, dockerCleanup: false);
    } catch (BlueGreenDeactivationException $caught) {
        $exception = $caught;
    }

    $deactivation = ApplicationBlueGreenDeactivation::query()->sole();
    $interventionState = $state->fresh();

    expect($exception)->toBeInstanceOf(BlueGreenDeactivationException::class)
        ->and($exception?->getMessage())->toBe($publicReason)
        ->and(mb_strlen($publicReason))->toBeLessThanOrEqual(BlueGreenDeactivationFailure::MAXIMUM_PUBLIC_REASON_LENGTH)
        ->and($deactivation->phase)->toBe(BlueGreenDeactivationPhase::INTERVENTION_REQUIRED)
        ->and($deactivation->intervention_phase)->toBe(BlueGreenDeactivationPhase::STOPPING->value)
        ->and($deactivation->intervention_reason)->toBe($publicReason)
        ->and($deactivation->intervention_reason)->not->toContain('credential=not-for-public-display')
        ->and($interventionState?->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($interventionState?->intervention_phase)->toBe(BlueGreenDeploymentPhase::DEACTIVATING->value)
        ->and($interventionState?->intervention_reason)->toBe($publicReason);

    $this->withoutVite();
    $admin = User::factory()->create();
    $admin->teams()->attach($context['team'], ['role' => 'admin']);
    $this->actingAs($admin);
    session(['currentTeam' => $context['team']]);

    Livewire::test(ApplicationHeading::class, ['application' => $application->fresh()])
        ->assertSet('blueGreenIntervention.phase', 'deactivation')
        ->assertSet('blueGreenIntervention.sourcePhase', BlueGreenDeactivationPhase::STOPPING->value)
        ->assertSet('blueGreenIntervention.destinationId', $destination->id)
        ->assertSet('blueGreenIntervention.reason', $publicReason)
        ->assertSee('Blue-green deactivation (stopping)')
        ->assertSee('destination '.$destination->id)
        ->assertSee($publicReason)
        ->assertDontSee($rawRemoteDetail);
});

it('resumes an immutable route-less replacement after its durable record fails and application configuration drifts', function () {
    $context = BlueGreenDeactivationScenario::context();
    $application = $context['application'];
    $destination = $context['destination'];
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    $recordFailureArmed = false;
    $recordFailureInjected = false;
    $replacementStates = [];

    DB::connection()->beforeExecuting(function (string $query) use (&$recordFailureArmed, &$recordFailureInjected): void {
        $normalizedQuery = strtolower(ltrim($query));
        if (! $recordFailureArmed
            || $recordFailureInjected
            || ! str_starts_with($normalizedQuery, 'update')
            || ! str_contains($normalizedQuery, 'application_blue_green_deployments')) {
            return;
        }

        $recordFailureArmed = false;
        $recordFailureInjected = true;

        throw new RuntimeException('manual-stop route-less durable record failure');
    });
    fakeManualBlueGreenStopRemoteSuccess(function (
        PendingProcess $process,
        BlueGreenProxyState $replacementState,
    ) use (&$recordFailureArmed, &$replacementStates): void {
        $replacementStates[] = $replacementState;
        $recordFailureArmed = true;
    });

    expect(fn () => StopApplication::run($application, dockerCleanup: false))
        ->toThrow(BlueGreenDeactivationTransportException::class, 'Blue-green deactivation transport did not prove completion.');

    $failedState = $state->fresh();
    $failedDeactivation = ApplicationBlueGreenDeactivation::query()->sole();
    $staleStartedAt = now()->subMinutes(10)->startOfSecond();
    $driftedFqdn = 'https://manual-stop-resume-drift.example.test';

    ApplicationBlueGreenDeployment::query()
        ->whereKey($failedState->id)
        ->update(['deactivation_started_at' => $staleStartedAt]);
    ApplicationBlueGreenDeactivation::query()
        ->whereKey($failedDeactivation->id)
        ->update(['started_at' => $staleStartedAt]);
    DB::table('applications')
        ->where('id', $application->id)
        ->update(['fqdn' => $driftedFqdn]);

    $resumption = ResumeBlueGreenDeactivations::run(
        $application->id,
        $destination->id,
        staleAfterSeconds: 1,
    );
    $resumedState = $state->fresh();
    $resumedDeactivation = $failedDeactivation->fresh();

    expect($recordFailureInjected)->toBeTrue()
        ->and($failedState->phase)->toBe(BlueGreenDeploymentPhase::DEACTIVATING)
        ->and($failedState->destination_fence_operation_id)->toBeNull()
        ->and($failedDeactivation->phase)->toBe(BlueGreenDeactivationPhase::STOPPING)
        ->and($resumption)->toHaveCount(1)
        ->and($resumption[0]->outcome)->toBe('resumed')
        ->and($application->fresh()->fqdn)->toBe($driftedFqdn)
        ->and($replacementStates)->toHaveCount(2)
        ->and($replacementStates[1]->serialize())->toBe($replacementStates[0]->serialize())
        ->and($resumedState->phase)->toBe(BlueGreenDeploymentPhase::STOPPED)
        ->and($resumedState->destination_fence_operation_id)->toBe($replacementStates[0]->operationId)
        ->and($resumedState->application_routing_config_digest)->toBe($replacementStates[0]->applicationRoutingConfigDigest)
        ->and($resumedState->destination_topology_digest)->toBe($replacementStates[0]->destinationTopologyDigest)
        ->and($resumedDeactivation->phase)->toBe(BlueGreenDeactivationPhase::STOPPED);
});

it('keeps a detached stopped destination available for strict deletion after later all-destination stop', function () {
    $context = BlueGreenDeactivationScenario::context();
    $application = $context['application'];
    $destination = $context['destination'];
    $additional = manualBlueGreenAdditionalDestination($context);
    $additionalServer = $additional['server'];
    $historicalDestination = $additional['destination'];

    $application->additional_networks()->attach($historicalDestination->id, [
        'server_id' => $additionalServer->id,
    ]);
    $primaryState = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    $historicalState = BlueGreenDeactivationScenario::routeLessState($application, $historicalDestination);
    fakeManualBlueGreenStopRemoteSuccess();

    $oneDestinationResult = StopApplicationOneServer::run($application, $additionalServer);
    $historicalStop = ApplicationBlueGreenDeactivation::query()
        ->where('standalone_docker_id', $historicalDestination->id)
        ->sole();
    $historicalOperationId = $historicalStop->operation_id;
    $historicalGeneration = $historicalStop->supersession_generation;

    expect($oneDestinationResult)->toBeNull()
        ->and($historicalState->fresh()->phase)->toBe(BlueGreenDeploymentPhase::STOPPED)
        ->and($historicalStop->fresh()->phase)->toBe(BlueGreenDeactivationPhase::STOPPED);

    $application->additional_networks()->detach($historicalDestination->id);
    expect($application->fresh()->blueGreenConfiguredStandaloneDockerDestinationIds()->all())->toBe([$destination->id]);
    Event::fake([ServiceStatusChanged::class]);

    $allDestinationResult = StopApplication::run($application, dockerCleanup: false);
    $historicalStopAfterAllDestinations = $historicalStop->fresh();

    expect($allDestinationResult)->toBeNull()
        ->and($primaryState->fresh()->phase)->toBe(BlueGreenDeploymentPhase::STOPPED)
        ->and($historicalStopAfterAllDestinations->phase)->toBe(BlueGreenDeactivationPhase::STOPPED)
        ->and($historicalStopAfterAllDestinations->operation_id)->toBe($historicalOperationId)
        ->and($historicalStopAfterAllDestinations->supersession_generation)->toBe($historicalGeneration);

    $strictDeactivations = DeactivateBlueGreenApplication::run($application);
    $strictHistoricalDeactivation = ApplicationBlueGreenDeactivation::query()
        ->where('standalone_docker_id', $historicalDestination->id)
        ->sole();
    $tombstonedApplication = $application->newQuery()
        ->withTrashed()
        ->findOrFail($application->id);

    expect($strictDeactivations)->toHaveCount(2)
        ->and($tombstonedApplication->trashed())->toBeTrue()
        ->and($strictHistoricalDeactivation->phase)->toBe(BlueGreenDeactivationPhase::COMPLETED)
        ->and($strictHistoricalDeactivation->operation_id)->not->toBe($historicalOperationId)
        ->and($strictHistoricalDeactivation->supersession_generation)->toBeGreaterThan($historicalGeneration)
        ->and(ApplicationBlueGreenDeployment::query()
            ->where('application_id', $application->id)
            ->doesntExist())->toBeTrue();
});

it('rejects a detached stopped destination whose durable generations do not match', function () {
    $context = BlueGreenDeactivationScenario::context();
    $application = $context['application'];
    $additional = manualBlueGreenAdditionalDestination($context);
    $historicalDestination = $additional['destination'];
    $startedAt = now()->subMinute()->startOfSecond();

    $application->additional_networks()->attach($historicalDestination->id, [
        'server_id' => $additional['server']->id,
    ]);
    $historicalState = BlueGreenDeactivationScenario::routeLessState($application, $historicalDestination);
    $historicalState->update([
        'phase' => BlueGreenDeploymentPhase::STOPPED,
        'supersession_generation' => 4,
    ]);
    $historicalDeactivation = ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $historicalDestination->id,
        'operation_id' => str_repeat('b', 64),
        'started_at' => $startedAt,
        'queue_cutoff_id' => 0,
        'supersession_generation' => 3,
        'phase' => BlueGreenDeactivationPhase::STOPPED,
        'completed_at' => $startedAt->copy()->addSecond(),
    ]);
    $application->additional_networks()->detach($historicalDestination->id);
    Process::fake();

    expect(fn () => DeactivateBlueGreenApplication::run($application))
        ->toThrow(BlueGreenDeactivationException::class, 'no longer configured');

    expect($application->fresh()->trashed())->toBeFalse()
        ->and($historicalState->fresh()->phase)->toBe(BlueGreenDeploymentPhase::STOPPED)
        ->and($historicalDeactivation->fresh()->phase)->toBe(BlueGreenDeactivationPhase::STOPPED);
    Process::assertNothingRan();
});

it('stops every configured blue-green destination through the application stop bridge', function () {
    $context = BlueGreenDeactivationScenario::context();
    $application = $context['application'];
    $destination = $context['destination'];
    $additional = manualBlueGreenAdditionalDestination($context);
    $additionalServer = $additional['server'];
    $additionalDestination = $additional['destination'];

    $application->additional_networks()->attach($additionalDestination->id, [
        'server_id' => $additionalServer->id,
    ]);
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    $additionalState = BlueGreenDeactivationScenario::routeLessState($application, $additionalDestination);
    fakeManualBlueGreenStopRemoteSuccess();
    Event::fake([ServiceStatusChanged::class]);

    $result = StopApplication::run($application, dockerCleanup: false);
    $deactivations = ApplicationBlueGreenDeactivation::query()
        ->where('application_id', $application->id)
        ->get();

    expect($result)->toBeNull()
        ->and($application->fresh()->trashed())->toBeFalse()
        ->and($deactivations)->toHaveCount(2)
        ->and($deactivations->pluck('standalone_docker_id')->sort()->values()->all())
        ->toBe(collect([$destination->id, $additionalDestination->id])->sort()->values()->all())
        ->and($deactivations->every(fn (ApplicationBlueGreenDeactivation $deactivation): bool => $deactivation->phase === BlueGreenDeactivationPhase::STOPPED))
        ->toBeTrue()
        ->and($deactivations->every(fn (ApplicationBlueGreenDeactivation $deactivation): bool => $deactivation->completed_at !== null))
        ->toBeTrue()
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::STOPPED)
        ->and($additionalState->fresh()->phase)->toBe(BlueGreenDeploymentPhase::STOPPED);
});

it('renews every pending fence throughout a slow all-destination stop', function (): void {
    $context = BlueGreenDeactivationScenario::context();
    $application = $context['application'];
    $destination = $context['destination'];
    $second = manualBlueGreenAdditionalDestination($context);
    $third = manualBlueGreenAdditionalDestination($context);

    $application->additional_networks()->attach($second['destination']->id, [
        'server_id' => $second['server']->id,
    ]);
    $application->additional_networks()->attach($third['destination']->id, [
        'server_id' => $third['server']->id,
    ]);
    $states = [
        $destination->id => BlueGreenDeactivationScenario::routeLessState($application, $destination),
        $second['destination']->id => BlueGreenDeactivationScenario::routeLessState($application, $second['destination']),
        $third['destination']->id => BlueGreenDeactivationScenario::routeLessState($application, $third['destination']),
    ];
    $destinationIds = $application->fresh()
        ->blueGreenConfiguredStandaloneDockerDestinationIds()
        ->map(fn (mixed $destinationId): int => (int) $destinationId)
        ->values()
        ->all();
    $leaseSeconds = BlueGreenDeploymentLock::deactivationLeaseSeconds();
    $simulatedRemoteLatencySeconds = 160;
    $mutationIndex = 0;
    $renewalSnapshots = [];
    Carbon::setTestNow(now()->startOfSecond());
    fakeManualBlueGreenStopRemoteSuccess(function (
        PendingProcess $process,
        BlueGreenProxyState $replacementState,
    ) use (
        $application,
        $destinationIds,
        $simulatedRemoteLatencySeconds,
        &$mutationIndex,
        &$renewalSnapshots,
    ): void {
        $cacheStore = Cache::getStore();
        if (! $cacheStore instanceof ArrayStore) {
            throw new RuntimeException('The slow-fleet fence regression requires the test array cache store.');
        }

        $pendingDestinationIds = array_slice($destinationIds, $mutationIndex);
        $remainingLeaseSeconds = [];
        foreach ($pendingDestinationIds as $pendingDestinationId) {
            $expiresAt = $cacheStore->locks[BlueGreenDeploymentLock::key(
                $application->id,
                $pendingDestinationId,
            )]['expiresAt'] ?? null;
            $remainingLeaseSeconds[$pendingDestinationId] = $expiresAt instanceof Carbon
                ? $expiresAt->getTimestamp() - Carbon::now()->getTimestamp()
                : null;
        }
        $renewalSnapshots[] = [
            'destination_id' => $replacementState->destinationId,
            'remaining_lease_seconds' => $remainingLeaseSeconds,
        ];
        $mutationIndex++;
        Carbon::setTestNow(Carbon::now()->addSeconds($simulatedRemoteLatencySeconds));
    });
    Event::fake([ServiceStatusChanged::class]);

    try {
        $result = StopApplication::run($application, dockerCleanup: false);
    } finally {
        Carbon::setTestNow();
    }

    expect($result)->toBeNull()
        ->and($mutationIndex)->toBe(3)
        ->and($mutationIndex * $simulatedRemoteLatencySeconds)->toBeGreaterThan($leaseSeconds)
        ->and($renewalSnapshots)->toHaveCount(3)
        ->and(ApplicationBlueGreenDeactivation::query()
            ->where('application_id', $application->id)
            ->where('phase', BlueGreenDeactivationPhase::STOPPED->value)
            ->count())->toBe(3)
        ->and(collect($states)->every(
            fn (ApplicationBlueGreenDeployment $state): bool => $state->fresh()->phase === BlueGreenDeploymentPhase::STOPPED,
        ))->toBeTrue();
    foreach ($renewalSnapshots as $index => $renewalSnapshot) {
        expect($renewalSnapshot['destination_id'])->toBe($destinationIds[$index])
            ->and(array_keys($renewalSnapshot['remaining_lease_seconds']))->toBe(array_slice($destinationIds, $index));
        expect($renewalSnapshot['remaining_lease_seconds'])->each->toBe($leaseSeconds);
    }
});

it('stops exactly the destination owned by the selected server', function () {
    $context = BlueGreenDeactivationScenario::context();
    $application = $context['application'];
    $destination = $context['destination'];
    $additional = manualBlueGreenAdditionalDestination($context);
    $additionalServer = $additional['server'];
    $additionalDestination = $additional['destination'];

    $application->additional_networks()->attach($additionalDestination->id, [
        'server_id' => $additionalServer->id,
    ]);
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    $additionalState = BlueGreenDeactivationScenario::routeLessState($application, $additionalDestination);
    fakeManualBlueGreenStopRemoteSuccess();

    $result = StopApplicationOneServer::run($application, $additionalServer);
    $deactivation = ApplicationBlueGreenDeactivation::query()->sole();

    expect($result)->toBeNull()
        ->and($deactivation->standalone_docker_id)->toBe($additionalDestination->id)
        ->and($deactivation->phase)->toBe(BlueGreenDeactivationPhase::STOPPED)
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($additionalState->fresh()->phase)->toBe(BlueGreenDeploymentPhase::STOPPED);
});

it('does not run Docker work when a manual stop loses a destination fence', function () {
    $context = BlueGreenDeactivationScenario::context();
    $application = $context['application'];
    $destination = $context['destination'];
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($application->id, $destination->id),
        BlueGreenDeploymentLock::deactivationLeaseSeconds(),
    );

    expect($lock->get())->toBeTrue();
    Process::fake();

    try {
        expect(fn () => StopApplication::run($application, dockerCleanup: false))
            ->toThrow(BlueGreenDeactivationInProgressException::class, 'Another blue-green lifecycle operation owns one of the application destinations required for deletion.');
    } finally {
        $released = $lock->release();
    }

    expect($released)->toBeTrue()
        ->and(ApplicationBlueGreenDeactivation::query()->doesntExist())->toBeTrue()
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE);
    Process::assertNothingRan();
});

it('claims a fresh blue-green deployment from a stopped state', function () {
    $context = BlueGreenDeactivationScenario::context();
    $application = $context['application'];
    $destination = $context['destination'];
    $startedAt = now()->subMinute()->startOfSecond();
    $stopOperationId = str_repeat('a', 64);

    $application->update([
        'fqdn' => 'https://manual-stop-restart.example.test',
        'health_check_enabled' => true,
        'ports_exposes' => '3000',
        'ports_mappings' => null,
    ]);
    $application->settings()->update([
        'is_blue_green_deployment_enabled' => true,
        'is_container_label_readonly_enabled' => true,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'phase' => BlueGreenDeploymentPhase::STOPPED,
        'supersession_generation' => 4,
        'destination_fence_operation_id' => $stopOperationId,
        'destination_fence_mutation_sequence' => 1,
        'destination_topology_digest' => str_repeat('b', 64),
        'application_routing_config_digest' => str_repeat('c', 64),
    ]);
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'operation_id' => $stopOperationId,
        'started_at' => $startedAt,
        'queue_cutoff_id' => 0,
        'supersession_generation' => 4,
        'phase' => BlueGreenDeactivationPhase::STOPPED,
        'completed_at' => $startedAt->copy()->addSecond(),
    ]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'manual-stop-restart-claim',
        'pull_request_id' => 0,
        'destination_id' => $destination->id,
        'server_id' => $destination->server_id,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);

    $bootId = BlueGreenDeactivationScenario::BOOT_ID;
    $legacyRouteAttestationAttempted = false;
    Process::fake(function (PendingProcess $process) use ($bootId, &$legacyRouteAttestationAttempted) {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;
        $payload = $command."\n".(string) $process->input;

        if (str_contains($payload, 'coolify-blue-green-route-network-proof:')) {
            $legacyRouteAttestationAttempted = true;
        }
        if (str_contains($payload, 'coolify-blue-green-destination-state-attested')) {

            return Process::result(output: 'coolify-blue-green-destination-state-attested');
        }
        if (str_contains($payload, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: $bootId);
        }

        return Process::result(output: '');
    });
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment,
        destination: $destination,
        server: $context['server'],
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    $lifecycle->initialize();
    $claim = $lifecycle->claim();

    expect($claim)->not->toBeNull()
        ->and($legacyRouteAttestationAttempted)->toBeFalse()
        ->and($claim?->supersessionGeneration)->toBeGreaterThan(4)
        ->and($claim?->legacyContainerName)->toBeNull()
        ->and($lifecycle->previousContainerName())->toBeNull()
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::PREPARING);
});

it('supersedes a parked intervention promotion owner through a fenced manual stop', function () {
    $context = BlueGreenDeactivationScenario::context();
    $application = $context['application'];
    $destination = $context['destination'];

    // Mirror the 2026-07-24 incident: a first adoption whose recovery
    // deterministically refused (inconsistent predecessor destination-fence
    // provenance) parks as intervention_required while still carrying
    // promotion ownership. Recovery re-refuses on every retry and settings
    // are fenced while non-idle, so a fenced manual stop must be able to
    // supersede the parked owner or the operator has no exit at all.
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'pending_color' => 'blue',
        'pending_deployment_uuid' => 'parked-first-adoption',
        'operation_deployment_uuid' => 'parked-first-adoption',
        'operation_previous_destination_fence_epoch' => 2,
        'intervention_phase' => BlueGreenDeploymentPhase::PREPARING->value,
        'intervention_reason' => 'The interrupted operation could not be proven safe to reconcile: The first-adoption recovery has inconsistent predecessor destination-fence provenance.',
        'routing_revision' => 0,
        'supersession_generation' => 2,
        'destination_fence_epoch' => 2,
        'destination_fence_operation_id' => 'interrupted-predecessor',
        'destination_fence_mutation_sequence' => 4,
        'destination_topology_digest' => str_repeat('a', 64),
        'application_routing_config_digest' => str_repeat('b', 64),
    ]);

    fakeManualBlueGreenStopRemoteSuccess();
    Event::fake([ServiceStatusChanged::class]);

    $result = StopApplication::run($application, dockerCleanup: false);
    $stoppedState = ApplicationBlueGreenDeployment::query()->sole();
    $deactivation = ApplicationBlueGreenDeactivation::query()->sole();

    expect($result)->toBeNull()
        ->and($stoppedState->phase)->toBe(BlueGreenDeploymentPhase::STOPPED)
        ->and($stoppedState->operation_deployment_uuid)->toBeNull()
        ->and($stoppedState->operation_previous_destination_fence_epoch)->toBeNull()
        ->and($stoppedState->pending_color)->toBeNull()
        ->and($stoppedState->pending_deployment_uuid)->toBeNull()
        ->and($stoppedState->intervention_phase)->toBeNull()
        ->and($stoppedState->intervention_reason)->toBeNull()
        ->and((int) $stoppedState->supersession_generation)->toBeGreaterThan(2)
        ->and($deactivation->phase)->toBe(BlueGreenDeactivationPhase::STOPPED);
});

it('still refuses to supersede a live promotion owner through a fenced manual stop', function () {
    $context = BlueGreenDeactivationScenario::context();
    $application = $context['application'];
    $destination = $context['destination'];

    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'phase' => BlueGreenDeploymentPhase::PREPARING,
        'pending_color' => 'blue',
        'pending_deployment_uuid' => 'live-first-adoption',
        'operation_deployment_uuid' => 'live-first-adoption',
        'routing_revision' => 0,
        'supersession_generation' => 2,
        'destination_fence_epoch' => 2,
    ]);

    fakeManualBlueGreenStopRemoteSuccess();
    Event::fake([ServiceStatusChanged::class]);

    expect(fn () => StopApplication::run($application, dockerCleanup: false))
        ->toThrow(BlueGreenDeactivationInProgressException::class, 'must recover or finish');

    $state = ApplicationBlueGreenDeployment::query()->sole();
    expect($state->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($state->operation_deployment_uuid)->toBe('live-first-adoption');
});
