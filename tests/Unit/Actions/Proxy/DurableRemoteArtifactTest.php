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

it('rejects a group-writable artifact directory before opening durable state', function (): void {
    $fixture = durableRemoteArtifactFixture();

    try {
        chmod($fixture['directory'], 0770);
        $rejected = runDurableRemoteArtifactCommand(
            'durable_remote_assert_owned_directory '.escapeshellarg($fixture['directory']),
            $fixture['environment'],
        );
        chmod($fixture['directory'], 0700);
        $accepted = runDurableRemoteArtifactCommand(
            'durable_remote_assert_owned_directory '.escapeshellarg($fixture['directory']),
            $fixture['environment'],
        );

        expect($rejected->isSuccessful())->toBeFalse()
            ->and($accepted->isSuccessful())->toBeTrue($accepted->getErrorOutput());
    } finally {
        (new Filesystem)->remove($fixture['root']);
    }
});

it('names the refused path and reason on stderr when an ownership assert fails', function (): void {
    $fixture = durableRemoteArtifactFixture();

    try {
        chmod($fixture['directory'], 0770);
        $rejectedDirectory = runDurableRemoteArtifactCommand(
            'durable_remote_assert_owned_directory '.escapeshellarg($fixture['directory']),
            $fixture['environment'],
        );
        chmod($fixture['directory'], 0700);
        $missing = $fixture['directory'].'/missing-artifact';
        $rejectedRegular = runDurableRemoteArtifactCommand(
            'durable_remote_assert_owned_regular '.escapeshellarg($missing),
            $fixture['environment'],
        );

        expect($rejectedDirectory->isSuccessful())->toBeFalse()
            ->and($rejectedDirectory->getErrorOutput())->toContain($fixture['directory'])
            ->and($rejectedRegular->isSuccessful())->toBeFalse()
            ->and($rejectedRegular->getErrorOutput())->toContain($missing);
    } finally {
        (new Filesystem)->remove($fixture['root']);
    }
});

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

it('removes owned regular artifacts matching a prefix and syncs their directory once', function (): void {
    $fixture = durableRemoteArtifactFixture();
    $prefix = '.durable-artifact.';
    $first = $fixture['directory'].'/'.$prefix.'first';
    $second = $fixture['directory'].'/'.$prefix.'second';
    $unrelated = $fixture['directory'].'/.unrelated';
    file_put_contents($first, 'first');
    file_put_contents($second, 'second');
    file_put_contents($unrelated, 'keep');

    try {
        $result = runDurableRemoteArtifactCommand(
            'durable_remote_cleanup_owned_regular_prefix '.escapeshellarg($fixture['directory']).' '.escapeshellarg($prefix),
            $fixture['environment'],
        );

        expect($result->isSuccessful())->toBeTrue($result->getErrorOutput())
            ->and(file_exists($first))->toBeFalse()
            ->and(file_exists($second))->toBeFalse()
            ->and(file_get_contents($unrelated))->toBe('keep')
            ->and(file_get_contents($fixture['log']))->toBe($fixture['directory']."\n");
    } finally {
        (new Filesystem)->remove($fixture['root']);
    }
});

it('does not sync when no owned artifact matches the prefix', function (): void {
    $fixture = durableRemoteArtifactFixture();

    try {
        $result = runDurableRemoteArtifactCommand(
            'durable_remote_cleanup_owned_regular_prefix '.escapeshellarg($fixture['directory']).' '.escapeshellarg('.durable-artifact.'),
            $fixture['environment'],
        );

        expect($result->isSuccessful())->toBeTrue($result->getErrorOutput())
            ->and(file_get_contents($fixture['log']))->toBe('');
    } finally {
        (new Filesystem)->remove($fixture['root']);
    }
});

it('rejects invalid cleanup prefixes', function (string $prefix): void {
    $fixture = durableRemoteArtifactFixture();

    try {
        $result = runDurableRemoteArtifactCommand(
            'durable_remote_cleanup_owned_regular_prefix '.escapeshellarg($fixture['directory']).' '.escapeshellarg($prefix),
            $fixture['environment'],
        );

        expect($result->getExitCode())->toBe(64)
            ->and(file_get_contents($fixture['log']))->toBe('');
    } finally {
        (new Filesystem)->remove($fixture['root']);
    }
})->with([
    'when empty' => '',
    'without a dot prefix' => 'durable-artifact.',
    'with only a dot' => '.',
    'with uppercase characters' => '.Durable-artifact.',
    'with an underscore' => '.durable_artifact.',
]);

it('prevalidates sorted matching artifacts before retaining them on unsafe entries', function (string $unsafeKind): void {
    $fixture = durableRemoteArtifactFixture();
    $prefix = '.durable-artifact.';
    $first = $fixture['directory'].'/'.$prefix.'a-safe';
    $second = $fixture['directory'].'/'.$prefix.'b-safe';
    $unsafe = $fixture['directory'].'/'.$prefix.'z-unsafe';
    $outside = $fixture['root'].'/outside';
    file_put_contents($first, 'first');
    file_put_contents($second, 'second');

    if ($unsafeKind === 'symlink') {
        file_put_contents($outside, 'outside');
        symlink($outside, $unsafe);
    } else {
        file_put_contents($unsafe, 'unsafe');
        link($unsafe, $outside);
    }

    try {
        $result = runDurableRemoteArtifactCommand(
            'durable_remote_cleanup_owned_regular_prefix '.escapeshellarg($fixture['directory']).' '.escapeshellarg($prefix),
            $fixture['environment'],
        );

        expect($result->getExitCode())->toBe(1)
            ->and(file_exists($first))->toBeTrue()
            ->and(file_exists($second))->toBeTrue()
            ->and(file_exists($unsafe))->toBeTrue()
            ->and(file_get_contents($fixture['log']))->toBe('');

        if ($unsafeKind === 'symlink') {
            expect(is_link($unsafe))->toBeTrue();
        } else {
            expect(fileinode($unsafe))->toBe(fileinode($outside));
        }
    } finally {
        (new Filesystem)->remove($fixture['root']);
    }
})->with([
    'symbolic link' => 'symlink',
    'hard link' => 'hardlink',
]);
