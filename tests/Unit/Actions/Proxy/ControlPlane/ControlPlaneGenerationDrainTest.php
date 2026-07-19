<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationDrain;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @return array{root: string, bin: string, proc: string, state: string, log: string, docker_id: string, container_name: string} */
function controlPlaneGenerationDrainFixture(
    string $dockerId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    string $containerName = 'coolify-predecessor',
    string $running = 'true',
    int $pid = 4242,
): array {
    $root = sys_get_temp_dir().'/coolify-control-plane-generation-drain-'.bin2hex(random_bytes(8));
    $bin = $root.'/bin';
    $proc = $root.'/proc';
    $state = $root.'/docker-state';
    (new Filesystem)->mkdir([$bin, $proc.'/'.$pid.'/net'], 0700);

    file_put_contents($proc.'/'.$pid.'/net/tcp', "  sl  local_address rem_address   st\n   0: 0100007F:1F40 00000000:0000 0A\n");
    file_put_contents($proc.'/'.$pid.'/net/tcp6', "  sl  local_address rem_address   st\n   0: 00000000000000000000000000000000:1F40 00000000000000000000000000000000:0000 0A\n");
    file_put_contents($state, implode("\n", [
        'docker_id='.$dockerId,
        'container_name='.$containerName,
        'running='.$running,
        'pid='.$pid,
        '',
    ]));
    file_put_contents($root.'/docker.log', '');
    file_put_contents($bin.'/docker', <<<'SH'
#!/bin/sh
set -eu

state=$FAKE_DOCKER_STATE
log=$FAKE_DOCKER_LOG
command=$1
shift

case "$command" in
  inspect)
    selector=
    for argument in "$@"; do selector=$argument; done
    printf 'inspect %s\n' "$selector" >> "$log"
    if [ "${FAKE_DOCKER_MISSING:-}" = 1 ]; then exit 1; fi
    if [ "${FAKE_DOCKER_INSPECTION+x}" = x ]; then
      printf '%s\n' "$FAKE_DOCKER_INSPECTION"
      exit 0
    fi
    . "$state"
    printf '%s|/%s|%s|%s\n' "$docker_id" "$container_name" "$running" "$pid"
    ;;
  stop)
    selector=
    for argument in "$@"; do selector=$argument; done
    printf 'stop %s\n' "$*" >> "$log"
    . "$state"
    printf 'docker_id=%s\ncontainer_name=%s\nrunning=false\npid=0\n' "$docker_id" "$container_name" > "$state"
    ;;
  *) exit 1 ;;
esac
SH
    );
    file_put_contents($bin.'/date', <<<'SH'
#!/bin/sh
set -eu

printf '%s\n' "$FAKE_DATE_EPOCH"
SH
    );
    file_put_contents($bin.'/sleep', <<<'SH'
#!/bin/sh
exit 0
SH
    );
    chmod($bin.'/docker', 0700);
    chmod($bin.'/date', 0700);
    chmod($bin.'/sleep', 0700);

    return [
        'root' => $root,
        'bin' => $bin,
        'proc' => $proc,
        'state' => $state,
        'log' => $root.'/docker.log',
        'docker_id' => $dockerId,
        'container_name' => $containerName,
    ];
}

/** @param array{bin: string, state: string, log: string} $fixture */
function runControlPlaneGenerationDrainCommand(string $command, array $fixture, array $environment = []): Process
{
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(5);
    $process->setEnv(array_replace([
        'PATH' => $fixture['bin'].':'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'FAKE_DOCKER_STATE' => $fixture['state'],
        'FAKE_DOCKER_LOG' => $fixture['log'],
        'FAKE_DATE_EPOCH' => '100',
    ], $environment));
    $process->run();

    return $process;
}

/** @param array{proc: string, docker_id: string, container_name: string} $fixture */
function controlPlaneGenerationDrainCommand(array $fixture, int $deadline = 200): string
{
    return (new ControlPlaneGenerationDrain($fixture['proc']))->commandFor(
        predecessorDockerId: $fixture['docker_id'],
        containerName: $fixture['container_name'],
        backendPort: 8000,
        drainDeadlineEpoch: $deadline,
        stopTimeoutSeconds: 5,
    );
}

it('stops only an exactly attested predecessor after two zero observations and replays its completion marker', function (): void {
    $filesystem = new Filesystem;
    $fixture = controlPlaneGenerationDrainFixture();

    try {
        $command = controlPlaneGenerationDrainCommand($fixture);
        $first = runControlPlaneGenerationDrainCommand($command, $fixture);
        $second = runControlPlaneGenerationDrainCommand($command, $fixture);

        expect($first->isSuccessful())->toBeTrue()
            ->and($first->getOutput())->toBe(ControlPlaneGenerationDrain::COMPLETION_MARKER."\n")
            ->and($second->isSuccessful())->toBeTrue()
            ->and($second->getOutput())->toBe(ControlPlaneGenerationDrain::COMPLETION_MARKER."\n")
            ->and(substr_count(file_get_contents($fixture['log']), 'stop '))->toBe(1)
            ->and(file_get_contents($fixture['log']))->toContain('stop --time=5 '.$fixture['docker_id'])
            ->and(file_get_contents($fixture['log']))->not->toContain('rm')
            ->and($command)->toContain("required_consecutive_zero_observations='2'")
            ->and($command)->toContain('FNR == 1')
            ->and($command)->toContain('docker stop --time="$stop_timeout_seconds" "$expected_docker_id"')
            ->and($command)->not->toContain('docker rm')
            ->and($command)->not->toContain('Authorization')
            ->and($command)->not->toContain('Bearer');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('refuses missing, different, unreadable, malformed, and expired predecessor state before stopping', function (array $environment, ?int $pid, int $deadline): void {
    $filesystem = new Filesystem;
    $fixture = controlPlaneGenerationDrainFixture(pid: $pid ?? 4242);
    if ($pid !== null && $pid !== 4242) {
        $filesystem->remove($fixture['proc'].'/'.$pid);
    }

    try {
        $result = runControlPlaneGenerationDrainCommand(
            controlPlaneGenerationDrainCommand($fixture, $deadline),
            $fixture,
            $environment,
        );

        expect($result->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['log']))->not->toContain('stop ');
    } finally {
        $filesystem->remove($fixture['root']);
    }
})->with([
    'missing exact Docker ID' => [['FAKE_DOCKER_MISSING' => '1'], null, 200],
    'different Docker ID' => [['FAKE_DOCKER_INSPECTION' => str_repeat('b', 64).'|/coolify-predecessor|true|4242'], null, 200],
    'different container name' => [['FAKE_DOCKER_INSPECTION' => str_repeat('a', 64).'|/foreign-predecessor|true|4242'], null, 200],
    'malformed inspection' => [['FAKE_DOCKER_INSPECTION' => 'malformed'], null, 200],
    'unreadable proc state' => [[], 9999, 200],
    'fixed deadline elapsed' => [['FAKE_DATE_EPOCH' => '200'], null, 200],
]);

it('fails closed when either kernel TCP table is malformed', function (): void {
    $filesystem = new Filesystem;
    $fixture = controlPlaneGenerationDrainFixture();
    file_put_contents($fixture['proc'].'/4242/net/tcp6', "not a TCP table\n");

    try {
        $result = runControlPlaneGenerationDrainCommand(controlPlaneGenerationDrainCommand($fixture), $fixture);

        expect($result->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['log']))->not->toContain('stop ');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('rejects non-exact identifiers and a weaker-than-two zero observation policy', function (): void {
    $drain = new ControlPlaneGenerationDrain;
    $defaultCommand = ControlPlaneGenerationDrain::run(
        predecessorDockerId: str_repeat('a', 64),
        containerName: 'coolify-predecessor',
        backendPort: 8000,
        drainDeadlineEpoch: 200,
        stopTimeoutSeconds: 5,
    );

    expect($defaultCommand)->toContain("proc_root='/proc'")
        ->and(fn (): string => $drain->commandFor(
            predecessorDockerId: str_repeat('a', 63),
            containerName: 'coolify-predecessor',
            backendPort: 8000,
            drainDeadlineEpoch: 200,
            stopTimeoutSeconds: 5,
        ))->toThrow(InvalidArgumentException::class, 'exact Docker ID')
        ->and(fn (): string => $drain->commandFor(
            predecessorDockerId: str_repeat('a', 64),
            containerName: 'contains a space',
            backendPort: 8000,
            drainDeadlineEpoch: 200,
            stopTimeoutSeconds: 5,
        ))->toThrow(InvalidArgumentException::class, 'exact container name')
        ->and(fn (): string => $drain->commandFor(
            predecessorDockerId: str_repeat('a', 64),
            containerName: 'coolify-predecessor',
            backendPort: 8000,
            drainDeadlineEpoch: 200,
            stopTimeoutSeconds: 5,
            requiredConsecutiveZeroObservations: 1,
        ))->toThrow(InvalidArgumentException::class, 'at least two consecutive zero observations');
});
