<?php

use App\Actions\Application\WaitForSwarmStackConvergence;
use App\Models\Server;

function swarmConvergenceServices(): string
{
    return <<<'JSON'
{"ID":"service-web","Name":"application_web","Replicas":"1/1"}
{"ID":"service-worker","Name":"application_worker","Replicas":"1/1"}
JSON;
}

function swarmConvergenceRunningTask(): string
{
    return '{"DesiredState":"Running","CurrentState":"Running 2 seconds ago","Error":""}';
}

it('cannot report convergence before the stack deploy command completes', function () {
    $events = [];

    (new WaitForSwarmStackConvergence)->deployAndWait(
        new Server,
        'application',
        healthCheckStartPeriod: 0,
        healthCheckInterval: 1,
        healthCheckRetries: 1,
        deploy: function () use (&$events): void {
            $events[] = 'deploy';
        },
        remoteExecutor: function (string $command, int $timeout) use (&$events): string {
            $events[] = $command;

            return match (true) {
                str_starts_with($command, 'docker service ls') => '{"ID":"service-web","Name":"application_web","Replicas":"1/1"}',
                str_starts_with($command, 'docker service inspect') => '{"State":"completed","Message":"update completed"}',
                default => swarmConvergenceRunningTask(),
            };
        },
        sleeper: static function (int $seconds): void {},
    );

    expect($events[0])->toBe('deploy')
        ->and($events[1])->toStartWith('docker service ls')
        ->and($events[2])->toStartWith('docker service inspect');
});

it('waits for every service in a stack to complete', function () {
    $commands = [];
    $updateStatuses = [
        '{"State":"updating","Message":"web is starting"}',
        '{"State":"completed","Message":"worker completed"}',
        '{"State":"completed","Message":"web completed"}',
        '{"State":"completed","Message":"worker completed"}',
    ];
    $sleeps = [];

    (new WaitForSwarmStackConvergence)->handle(
        new Server,
        'application',
        healthCheckStartPeriod: 0,
        healthCheckInterval: 2,
        healthCheckRetries: 2,
        remoteExecutor: function (string $command) use (&$commands, &$updateStatuses): string {
            $commands[] = $command;

            return match (true) {
                str_starts_with($command, 'docker service ls') => swarmConvergenceServices(),
                str_starts_with($command, 'docker service inspect') => array_shift($updateStatuses),
                str_starts_with($command, 'docker service ps') => swarmConvergenceRunningTask(),
            };
        },
        sleeper: function (int $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        },
    );

    expect($sleeps)->toBe([2])
        ->and(array_values(array_filter($commands, fn (string $command): bool => str_starts_with($command, 'docker service inspect'))))->toHaveCount(4)
        ->and($commands[0])->toContain("--filter 'label=com.docker.stack.namespace=application'");
});

it('attests a newly created service through its desired running tasks when UpdateStatus is null', function () {
    $commands = [];

    (new WaitForSwarmStackConvergence)->handle(
        new Server,
        'application',
        healthCheckStartPeriod: 0,
        healthCheckInterval: 2,
        healthCheckRetries: 1,
        remoteExecutor: function (string $command) use (&$commands): string {
            $commands[] = $command;

            return match (true) {
                str_starts_with($command, 'docker service ls') => swarmConvergenceServices(),
                str_starts_with($command, 'docker service inspect') => 'null',
                str_starts_with($command, 'docker service ps') => swarmConvergenceRunningTask(),
            };
        },
        sleeper: static function (int $seconds): void {},
    );

    expect(array_values(array_filter($commands, fn (string $command): bool => str_starts_with($command, 'docker service inspect'))))->toHaveCount(2)
        ->and(array_values(array_filter($commands, fn (string $command): bool => str_starts_with($command, 'docker service ps'))))->toHaveCount(2);
});

it('does not trust a completed update while actual replicas remain below desired replicas', function () {
    expect(fn () => (new WaitForSwarmStackConvergence)->handle(
        new Server,
        'application',
        healthCheckStartPeriod: 0,
        healthCheckInterval: 1,
        healthCheckRetries: 1,
        remoteExecutor: function (string $command): string {
            return match (true) {
                str_starts_with($command, 'docker service ls') => '{"ID":"service-web","Name":"application_web","Replicas":"0/1"}',
                str_starts_with($command, 'docker service inspect') => '{"State":"completed","Message":"update completed"}',
                default => swarmConvergenceRunningTask(),
            };
        },
        sleeper: static function (int $seconds): void {},
    ))->toThrow(RuntimeException::class, 'update completed but replicas or tasks are still pending');
});

it('does not trust a completed update while a desired task is pending', function () {
    expect(fn () => (new WaitForSwarmStackConvergence)->handle(
        new Server,
        'application',
        healthCheckStartPeriod: 0,
        healthCheckInterval: 1,
        healthCheckRetries: 1,
        remoteExecutor: function (string $command): string {
            return match (true) {
                str_starts_with($command, 'docker service ls') => '{"ID":"service-web","Name":"application_web","Replicas":"1/1"}',
                str_starts_with($command, 'docker service inspect') => '{"State":"completed","Message":"update completed"}',
                default => '{"DesiredState":"Running","CurrentState":"Pending 2 seconds ago","Error":""}',
            };
        },
        sleeper: static function (int $seconds): void {},
    ))->toThrow(RuntimeException::class, 'update completed but replicas or tasks are still pending');
});

it('ignores rejected historical tasks after their replacement is healthy', function () {
    (new WaitForSwarmStackConvergence)->handle(
        new Server,
        'application',
        healthCheckStartPeriod: 0,
        healthCheckInterval: 1,
        healthCheckRetries: 1,
        remoteExecutor: function (string $command): string {
            return match (true) {
                str_starts_with($command, 'docker service ls') => '{"ID":"service-web","Name":"application_web","Replicas":"1/1"}',
                str_starts_with($command, 'docker service inspect') => '{"State":"completed","Message":"update completed"}',
                default => implode("\n", [
                    '{"DesiredState":"Shutdown","CurrentState":"Rejected 10 seconds ago","Error":"old image was unavailable"}',
                    swarmConvergenceRunningTask(),
                ]),
            };
        },
        sleeper: static function (int $seconds): void {},
    );

    expect(true)->toBeTrue();
});

it('fails when a Swarm stack remains updating past its bounded healthcheck window', function () {
    $state = (object) [
        'inspectionCount' => 0,
        'sleeps' => [],
    ];

    expect(fn () => (new WaitForSwarmStackConvergence)->handle(
        new Server,
        'application',
        healthCheckStartPeriod: 3,
        healthCheckInterval: 2,
        healthCheckRetries: 2,
        remoteExecutor: function (string $command) use ($state): string {
            if (str_starts_with($command, 'docker service ls')) {
                return swarmConvergenceServices();
            }

            if (str_starts_with($command, 'docker service inspect')) {
                $state->inspectionCount++;

                return '{"State":"updating","Message":"still updating"}';
            }

            return '';
        },
        sleeper: function (int $seconds) use ($state): void {
            $state->sleeps[] = $seconds;
        },
    ))->toThrow(RuntimeException::class, 'did not converge after 2 checks within 7 seconds');

    expect($state->inspectionCount)->toBe(4)
        ->and($state->sleeps)->toBe([3, 2]);
});

it('normalizes zero healthcheck interval and retries to one immediate check', function () {
    $state = (object) [
        'inspectionCount' => 0,
        'sleeps' => [],
    ];

    expect(fn () => (new WaitForSwarmStackConvergence)->handle(
        new Server,
        'application',
        healthCheckStartPeriod: 0,
        healthCheckInterval: 0,
        healthCheckRetries: 0,
        remoteExecutor: function (string $command) use ($state): string {
            if (str_starts_with($command, 'docker service ls')) {
                return swarmConvergenceServices();
            }

            if (str_starts_with($command, 'docker service inspect')) {
                $state->inspectionCount++;

                return '{"State":"updating","Message":"still updating"}';
            }

            return '';
        },
        sleeper: function (int $seconds) use ($state): void {
            $state->sleeps[] = $seconds;
        },
    ))->toThrow(RuntimeException::class, 'did not converge after 1 checks within 1 seconds');

    expect($state->inspectionCount)->toBe(2)
        ->and($state->sleeps)->toBe([]);
});

it('passes the monotonic remaining budget to remote commands and rejects deadline overrun', function () {
    $state = (object) [
        'now' => 0.0,
        'timeouts' => [],
    ];

    expect(fn () => (new WaitForSwarmStackConvergence)->handle(
        new Server,
        'application',
        healthCheckStartPeriod: 0,
        healthCheckInterval: 5,
        healthCheckRetries: 2,
        remoteExecutor: function (string $command, int $timeout) use ($state): string {
            $state->timeouts[] = $timeout;

            if (str_starts_with($command, 'docker service ls')) {
                $state->now = 3.0;

                return '{"ID":"service-web","Name":"application_web","Replicas":"1/1"}';
            }

            $state->now = 11.0;

            return '{"State":"updating","Message":"still updating"}';
        },
        sleeper: static function (int $seconds): void {},
        clock: static fn (): float => $state->now,
    ))->toThrow(RuntimeException::class, 'convergence deadline expired');

    expect($state->timeouts)->toBe([10, 7]);
});

it('fails immediately for rejected Swarm tasks', function () {
    expect(fn () => (new WaitForSwarmStackConvergence)->handle(
        new Server,
        'application',
        healthCheckStartPeriod: 0,
        healthCheckInterval: 1,
        healthCheckRetries: 1,
        remoteExecutor: function (string $command): string {
            if (str_starts_with($command, 'docker service ls')) {
                return swarmConvergenceServices();
            }

            if (str_starts_with($command, 'docker service inspect')) {
                return '{"State":"updating","Message":"waiting for replacement"}';
            }

            return '{"DesiredState":"Running","CurrentState":"Rejected 3 seconds ago","Error":"No such image"}';
        },
        sleeper: static function (int $seconds): void {},
    ))->toThrow(RuntimeException::class, 'application_web has a rejected task: No such image');
});

it('fails closed for terminal Swarm update states', function (string $state): void {
    expect(fn () => (new WaitForSwarmStackConvergence)->handle(
        new Server,
        'application',
        healthCheckStartPeriod: 0,
        healthCheckInterval: 1,
        healthCheckRetries: 1,
        remoteExecutor: function (string $command) use ($state): string {
            if (str_starts_with($command, 'docker service ls')) {
                return swarmConvergenceServices();
            }

            return json_encode(['State' => $state, 'Message' => 'update failure'], JSON_THROW_ON_ERROR);
        },
        sleeper: static function (int $seconds): void {},
    ))->toThrow(RuntimeException::class, "application_web: update failure update {$state}");
})->with([
    'paused' => 'paused',
    'rollback started' => 'rollback_started',
    'rollback paused' => 'rollback_paused',
    'rollback completed' => 'rollback_completed',
]);

it('rejects unknown Swarm update states', function () {
    expect(fn () => (new WaitForSwarmStackConvergence)->handle(
        new Server,
        'application',
        healthCheckStartPeriod: 0,
        healthCheckInterval: 1,
        healthCheckRetries: 1,
        remoteExecutor: function (string $command): string {
            if (str_starts_with($command, 'docker service ls')) {
                return swarmConvergenceServices();
            }

            return '{"State":"new-unknown-state"}';
        },
        sleeper: static function (int $seconds): void {},
    ))->toThrow(RuntimeException::class, 'unknown update state new-unknown-state');
});

it('rejects shell injection before executing a stack or service identifier', function () {
    $commands = [];
    $action = new WaitForSwarmStackConvergence;

    expect(fn () => $action->handle(
        new Server,
        'application; touch /tmp/pwned',
        healthCheckStartPeriod: 0,
        healthCheckInterval: 1,
        healthCheckRetries: 1,
        remoteExecutor: function (string $command) use (&$commands): string {
            $commands[] = $command;

            return '';
        },
        sleeper: static function (int $seconds): void {},
    ))->toThrow(Exception::class, 'forbidden character');

    expect($commands)->toBe([]);

    expect(fn () => $action->handle(
        new Server,
        'application',
        healthCheckStartPeriod: 0,
        healthCheckInterval: 1,
        healthCheckRetries: 1,
        remoteExecutor: static fn (string $command): string => '{"ID":"service; touch /tmp/pwned","Name":"application_web"}',
        sleeper: static function (int $seconds): void {},
    ))->toThrow(Exception::class, 'forbidden character');
});
