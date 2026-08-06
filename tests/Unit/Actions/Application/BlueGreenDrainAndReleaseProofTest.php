<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\DrainBlueGreenPreviousContainer;
use App\Actions\Application\BlueGreen\VerifyBlueGreenCandidateReleaseProof;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\BlueGreenDeploymentColor;
use App\Services\BlueGreenDeploymentLifecycle;

function blueGreenDrainExpectation(): BlueGreenContainerExpectation
{
    return new BlueGreenContainerExpectation(
        name: 'app-drain-blue',
        dockerId: str_repeat('a', 64),
        applicationId: 42,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: 'deployment-drain',
        color: BlueGreenDeploymentColor::BLUE,
        routingRevision: 7,
    );
}

it('requires two observed zero-connection samples before exact previous-container stop', function () {
    $commands = (new DrainBlueGreenPreviousContainer)->commandsFor(
        blueGreenDrainExpectation(),
        backendPort: 8080,
        drainDeadlineEpoch: 1_700_000_030,
        stopTimeoutSeconds: 15,
        hasInitialZeroObservation: true,
    );
    $script = $commands[array_key_last($commands)];

    expect($commands)->not->toBeEmpty()
        ->and($script)
        ->toContain(
            'drain_deadline=1700000030',
            'drain_zero_observations=1',
            '/proc/$drain_pid/net/tcp',
            '/proc/$drain_pid/net/tcp6',
            '$4 == "01"',
            'target_port=\'1F90\'',
            'drain_zero_observations" -ge 2',
            'timed out with $drain_connections active backend connection(s)',
            'docker stop --time=15',
            str_repeat('a', 64),
        );
});

it('never fails a drain whose current observation has no backend connections left', function () {
    $commands = (new DrainBlueGreenPreviousContainer)->commandsFor(
        blueGreenDrainExpectation(),
        backendPort: 8080,
        drainDeadlineEpoch: 1_700_000_030,
        stopTimeoutSeconds: 15,
    );
    $script = $commands[array_key_last($commands)];

    // Observed in production: a predecessor with zero connections still failed
    // the release because the immutable deadline passed before the second
    // confirming sample. Nothing was connected, so there was nothing to wait
    // for — the deadline must not turn that into a deployment failure.
    // The deadline branch runs from the clock test to the timeout exit; a
    // zero-connection escape must live inside it, before that exit.
    $deadlineStart = strpos($script, 'date +%s');
    $timeoutExit = strpos($script, 'timed out with');
    $deadlineBranch = substr($script, $deadlineStart, $timeoutExit - $deadlineStart);

    expect($deadlineBranch)->toContain('-eq 0');
    expect($deadlineBranch)->toContain('break');
});

it('retires the exact predecessor without re-observing connections once the drain budget is spent', function () {
    $commands = (new DrainBlueGreenPreviousContainer)->forcedStopCommandsFor(
        blueGreenDrainExpectation(),
        stopTimeoutSeconds: 15,
    );
    $script = $commands[array_key_last($commands)];

    expect($commands)->not->toBeEmpty()
        ->and($script)
        ->toContain('docker stop --time=15', str_repeat('a', 64))
        ->and($script)
        ->not->toContain(
            'drain_deadline',
            'drain_zero_observations',
            '/proc/$drain_pid/net/tcp',
            DrainBlueGreenPreviousContainer::TIMEOUT_MARKER,
        );
});

it('refuses a forced retirement without an exact Docker identity or a positive stop grace', function () {
    $drain = new DrainBlueGreenPreviousContainer;

    expect(fn () => $drain->forcedStopCommandsFor(
        new BlueGreenContainerExpectation(
            name: 'app-drain-blue',
            dockerId: null,
            applicationId: 42,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: 'deployment-drain',
            color: BlueGreenDeploymentColor::BLUE,
            routingRevision: 7,
        ),
        stopTimeoutSeconds: 15,
    ))->toThrow(InvalidArgumentException::class, 'exact Docker ID is required')
        ->and(fn () => $drain->forcedStopCommandsFor(blueGreenDrainExpectation(), stopTimeoutSeconds: 0))
        ->toThrow(InvalidArgumentException::class, 'must be positive');
});

it('counts established connections across every exposed backend port before retirement', function () {
    $drain = new DrainBlueGreenPreviousContainer;
    $commands = $drain->commandsFor(
        blueGreenDrainExpectation(),
        backendPort: [8080, 3000],
        drainDeadlineEpoch: 1_700_000_030,
        stopTimeoutSeconds: 15,
    );
    $script = $commands[array_key_last($commands)];
    $observation = $drain->observationCommandFor(
        blueGreenDrainExpectation(),
        backendPort: [8080, 3000],
    );

    expect($script)->toContain(
        "target_ports='0BB8 1F90'",
        'expected_ports[ports[index]] = 1',
        'in expected_ports',
    )->and($observation)->toContain(
        "target_ports='0BB8 1F90'",
        'expected_ports[ports[index]] = 1',
        'in expected_ports',
    );
});

it('binds the release proof to the exact managed candidate label and runtime environment', function () {
    $expectation = blueGreenDrainExpectation();
    $token = BlueGreenRoutingTarget::durableReleaseProofToken('deployment-drain');
    $command = (new VerifyBlueGreenCandidateReleaseProof)->commandFor($expectation, $token);

    expect($command)
        ->toContain(
            VerifyBlueGreenCandidateReleaseProof::LABEL,
            $token,
            str_repeat('a', 64),
            '{{json .Config.Env}}',
        )
        ->not->toBeEmpty();
});

it('inspects the exact previous backend configuration before accepting a distinct release proof', function () {
    $command = (new VerifyBlueGreenCandidateReleaseProof)->metadataCommandFor(blueGreenDrainExpectation());

    expect($command)
        ->toContain('docker inspect', '{{json .Config}}', str_repeat('a', 64))
        ->not->toBeEmpty();
});

it('caps public handoff observation independently of the Docker stop grace period', function () {
    $method = new ReflectionMethod(BlueGreenDeploymentLifecycle::class, 'boundedPublicHandoffGraceSeconds');

    expect($method->invoke(null, 5, 5))->toBe(15)
        ->and($method->invoke(null, 3_600, 3_600))->toBe(30);
});
