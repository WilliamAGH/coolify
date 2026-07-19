<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerRemovalPlan;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationException;
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
use App\Actions\Application\BlueGreen\ResumeBlueGreenDeactivations;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
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

it('keeps transport-ambiguous remote failures resumable under the exact generation', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    $application->delete();
    fakeBlueGreenRemoteProcessSequence(
        BlueGreenDeactivationScenario::BOOT_ID,
        'not-a-typed-blue-green-remote-outcome',
    );

    expect(fn () => DeactivateBlueGreenApplicationDestination::run($application, $destination->id))
        ->toThrow(BlueGreenDeactivationTransportException::class);

    $deactivation = ApplicationBlueGreenDeactivation::query()->sole();
    expect($deactivation->phase)->toBe(BlueGreenDeactivationPhase::DEACTIVATING)
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
