<?php

use App\Actions\Proxy\DurableRemoteArtifact;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @return array{root: string, directory: string, log: string, environment: array<string, string>} */
function durableRemoteArtifactFixture(): array
{
    $root = sys_get_temp_dir().'/coolify-durable-remote-artifact-'.bin2hex(random_bytes(8));
    $directory = $root.'/artifacts';
    $bin = $root.'/bin';
    $log = $root.'/sync.log';
    (new Filesystem)->mkdir([$directory, $bin], 0700);
    file_put_contents($log, '');
    file_put_contents($bin.'/sync', <<<'SH'
#!/bin/sh
set -eu
[ "$#" -eq 1 ]
case "$1" in -*) exit 64 ;; esac
printf '%s\n' "$1" >> "$DURABLE_SYNC_LOG"
[ "${DURABLE_SYNC_FAIL_PATH:-}" != "$1" ]
SH
    );
    chmod($bin.'/sync', 0700);

    return [
        'root' => $root,
        'directory' => $directory,
        'log' => $log,
        'environment' => [
            'PATH' => $bin.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'DURABLE_SYNC_LOG' => $log,
        ],
    ];
}

/** @param array<string, string> $environment */
function runDurableRemoteArtifactCommand(string $command, array $environment): Process
{
    $process = Process::fromShellCommandline(implode("\n", [
        'set -eu',
        ...DurableRemoteArtifact::shellFunctions(),
        $command,
    ]));
    $process->setTimeout(5);
    $process->setEnv($environment);
    $process->run();

    return $process;
}

it('publishes a stage only after its file barrier and then syncs the containing directory', function (): void {
    $fixture = durableRemoteArtifactFixture();
    $stage = $fixture['directory'].'/.stage';
    $target = $fixture['directory'].'/state.json';
    file_put_contents($stage, 'replacement');

    try {
        $result = runDurableRemoteArtifactCommand(
            'durable_remote_replace '.escapeshellarg($stage).' '.escapeshellarg($target).' '.escapeshellarg($fixture['directory']),
            $fixture['environment'],
        );

        expect($result->isSuccessful())->toBeTrue($result->getErrorOutput())
            ->and(file_exists($stage))->toBeFalse()
            ->and(file_get_contents($target))->toBe('replacement')
            ->and(file_get_contents($fixture['log']))->toBe($stage."\n".$fixture['directory']."\n");
    } finally {
        (new Filesystem)->remove($fixture['root']);
    }
});

it('syncs the containing directory after removing an artifact', function (): void {
    $fixture = durableRemoteArtifactFixture();
    $target = $fixture['directory'].'/state.json';
    file_put_contents($target, 'retired');

    try {
        $result = runDurableRemoteArtifactCommand(
            'durable_remote_remove '.escapeshellarg($target).' '.escapeshellarg($fixture['directory']),
            $fixture['environment'],
        );

        expect($result->isSuccessful())->toBeTrue($result->getErrorOutput())
            ->and(file_exists($target))->toBeFalse()
            ->and(file_get_contents($fixture['log']))->toBe($fixture['directory']."\n");
    } finally {
        (new Filesystem)->remove($fixture['root']);
    }
});

it('retries the stage when its file barrier fails before rename', function (): void {
    $fixture = durableRemoteArtifactFixture();
    $stage = $fixture['directory'].'/.stage';
    $target = $fixture['directory'].'/state.json';
    file_put_contents($stage, 'replacement');
    $command = 'durable_remote_replace '.escapeshellarg($stage).' '.escapeshellarg($target).' '.escapeshellarg($fixture['directory']);

    try {
        $failed = runDurableRemoteArtifactCommand($command, [
            ...$fixture['environment'],
            'DURABLE_SYNC_FAIL_PATH' => $stage,
        ]);
        $retried = runDurableRemoteArtifactCommand($command, $fixture['environment']);

        expect($failed->isSuccessful())->toBeFalse()
            ->and($retried->isSuccessful())->toBeTrue($retried->getErrorOutput())
            ->and(file_exists($stage))->toBeFalse()
            ->and(file_get_contents($target))->toBe('replacement')
            ->and(file_get_contents($fixture['log']))->toBe($stage."\n".$stage."\n".$fixture['directory']."\n");
    } finally {
        (new Filesystem)->remove($fixture['root']);
    }
});

it('reaffirms the published target when retrying a failed directory barrier', function (): void {
    $fixture = durableRemoteArtifactFixture();
    $stage = $fixture['directory'].'/.stage';
    $target = $fixture['directory'].'/state.json';
    file_put_contents($stage, 'replacement');

    try {
        $command = 'durable_remote_replace '.escapeshellarg($stage).' '.escapeshellarg($target).' '.escapeshellarg($fixture['directory']);
        $failed = runDurableRemoteArtifactCommand(
            $command,
            [
                ...$fixture['environment'],
                'DURABLE_SYNC_FAIL_PATH' => $fixture['directory'],
            ],
        );
        $retried = runDurableRemoteArtifactCommand($command, $fixture['environment']);

        expect($failed->isSuccessful())->toBeFalse()
            ->and($retried->isSuccessful())->toBeTrue($retried->getErrorOutput())
            ->and(file_exists($stage))->toBeFalse()
            ->and(file_get_contents($target))->toBe('replacement')
            ->and(file_get_contents($fixture['log']))->toBe(
                $stage."\n".$fixture['directory']."\n".$target."\n".$fixture['directory']."\n",
            );
    } finally {
        (new Filesystem)->remove($fixture['root']);
    }
});
