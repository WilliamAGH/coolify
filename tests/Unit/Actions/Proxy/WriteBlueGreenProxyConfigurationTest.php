<?php

use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactCommitter;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactReader;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactRestorer;
use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Exception\ParseException;

function validBlueGreenProxyConfiguration(): BlueGreenProxyConfiguration
{
    $yaml = <<<'YAML'
http:
  routers:
    app:
      rule: 'Host(`example.test`)'
      service: app
  services:
    app:
      loadBalancer:
        servers:
          - url: 'http://app-fixed-blue:8080'
YAML;
    $yaml .= "\n";

    return new BlueGreenProxyConfiguration(
        managedFilename: 'coolify-blue-green-a5adf99dc3d81b09.yaml',
        yaml: $yaml,
        sha256: hash('sha256', $yaml),
    );
}

function validBlueGreenRollbackKey(): BlueGreenProxyRollbackKey
{
    return new BlueGreenProxyRollbackKey(
        managedFilename: 'coolify-blue-green-a5adf99dc3d81b09.yaml',
        operationId: 'deploy-1234',
        routingRevision: 7,
    );
}

function runBlueGreenShellCommand(string $command): string
{
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(10);
    $process->mustRun();

    return $process->getOutput();
}

it('stages on the same filesystem, checks bytes, and moves over the active file', function () {
    $writer = new WriteBlueGreenProxyConfiguration;
    $command = $writer->commandFor(
        '/data/coolify/proxy/',
        validBlueGreenProxyConfiguration(),
        validBlueGreenRollbackKey(),
    );

    $stagePosition = strpos($command, 'stage=$(mktemp');
    $checksumPosition = strpos($command, 'actual_checksum=$(sha256sum');
    $movePosition = strpos($command, 'mv -fT --');
    $artifactPosition = strpos($command, '.coolify-rollback');
    expect($command)
        ->toContain("mktemp '/data/coolify/proxy/dynamic/.coolify-blue-green-a5adf99dc3d81b09.yaml.XXXXXX'")
        ->toContain('base64 -d > "$stage"')
        ->toContain('test "${actual_checksum%% *}" =')
        ->toContain('flock -x 9')
        ->toContain('.coolify-blue-green-a5adf99dc3d81b09.yaml.coolify.lock')
        ->toContain('sync -f "$stage"')
        ->toContain("sync -f '/data/coolify/proxy/dynamic'")
        ->toContain('test ! -d \'/data/coolify/proxy/dynamic/coolify-blue-green-a5adf99dc3d81b09.yaml\'')
        ->toContain('mv -fT -- "$stage" \'/data/coolify/proxy/dynamic/coolify-blue-green-a5adf99dc3d81b09.yaml\'')
        ->not->toContain('tee')
        ->not->toContain('> \'/data/coolify/proxy/dynamic/coolify-blue-green-a5adf99dc3d81b09.yaml\'')
        ->and($artifactPosition)->toBeLessThan($stagePosition)
        ->and(strpos($command, 'flock -x 9'))->toBeLessThan($artifactPosition)
        ->and($stagePosition)->toBeLessThan($checksumPosition)
        ->and($checksumPosition)->toBeLessThan($movePosition);
});

it('shell-quotes the proxy path without changing the managed filename', function () {
    $command = (new WriteBlueGreenProxyConfiguration)->commandFor(
        "/data/proxy'; touch /tmp/owned; #",
        validBlueGreenProxyConfiguration(),
        validBlueGreenRollbackKey(),
    );

    expect($command)->toContain("'/data/proxy'\\''; touch /tmp/owned; #/dynamic'")
        ->and(substr_count($command, 'touch /tmp/owned'))->toBeGreaterThan(0)
        ->and($command)->not->toContain("mkdir -p -- /data/proxy';");
});

it('rejects invalid YAML, checksum drift, and non-reserved filenames before remote commands', function () {
    $writer = new WriteBlueGreenProxyConfiguration;
    $invalidYaml = "http:\n  routers: [\n";
    expect(fn () => $writer->commandFor('/data/coolify/proxy', new BlueGreenProxyConfiguration(
        'coolify-blue-green-a5adf99dc3d81b09.yaml',
        $invalidYaml,
        hash('sha256', $invalidYaml),
    ), validBlueGreenRollbackKey()))->toThrow(ParseException::class);

    $valid = validBlueGreenProxyConfiguration();
    $invalidMiddlewareYaml = str_replace("  routers:\n", "  middlewares: invalid\n  routers:\n", $valid->yaml);
    expect(fn () => $writer->commandFor('/data/coolify/proxy', new BlueGreenProxyConfiguration(
        $valid->managedFilename,
        $invalidMiddlewareYaml,
        hash('sha256', $invalidMiddlewareYaml),
    ), validBlueGreenRollbackKey()))->toThrow(InvalidArgumentException::class, 'routers and services');

    expect(fn () => $writer->commandFor('/data/coolify/proxy', new BlueGreenProxyConfiguration(
        $valid->managedFilename,
        $valid->yaml,
        str_repeat('0', 64),
    ), validBlueGreenRollbackKey()))->toThrow(InvalidArgumentException::class, 'checksum')
        ->and(fn () => $writer->commandFor('/data/coolify/proxy', new BlueGreenProxyConfiguration(
            '../custom.yaml',
            $valid->yaml,
            $valid->sha256,
        ), validBlueGreenRollbackKey()))->toThrow(InvalidArgumentException::class, 'managed reserved filename');

    expect(fn () => $writer->commandFor('relative/proxy', $valid, validBlueGreenRollbackKey()))
        ->toThrow(InvalidArgumentException::class, 'must be absolute');
});

it('serializes and validates durable rollback ownership, missing state, and checksums', function () {
    $key = validBlueGreenRollbackKey();
    $present = new BlueGreenProxyRollbackArtifact($key, true, "\0prior\r\nbytes");
    $missing = new BlueGreenProxyRollbackArtifact($key, false, '');

    expect(BlueGreenProxyRollbackArtifact::parse($key, $present->serialize())->bytes)->toBe("\0prior\r\nbytes")
        ->and(BlueGreenProxyRollbackArtifact::parse($key, $missing->serialize())->existed)->toBeFalse()
        ->and(fn () => BlueGreenProxyRollbackArtifact::parse(
            new BlueGreenProxyRollbackKey($key->managedFilename, 'another-operation', 7),
            $present->serialize(),
        ))->toThrow(InvalidArgumentException::class, 'owned by another operation')
        ->and(fn () => BlueGreenProxyRollbackArtifact::parse(
            $key,
            str_replace($present->sha256, str_repeat('0', 64), $present->serialize()),
        ))->toThrow(InvalidArgumentException::class, 'checksum');
});

it('creates the durable rollback artifact once before replacement and never uses a Traefik extension', function () {
    $key = validBlueGreenRollbackKey();
    $command = (new WriteBlueGreenProxyConfiguration)->commandFor(
        '/data/coolify/proxy',
        validBlueGreenProxyConfiguration(),
        $key,
    );
    $artifactFilename = $key->artifactFilename();

    expect($artifactFilename)->toEndWith('.coolify-rollback')
        ->not->toEndWith('.yaml')
        ->not->toEndWith('.yml')
        ->not->toEndWith('.toml')
        ->and($command)->toContain(
            'if [ ! -e',
            'ln -- "$rollback_stage"',
            'sync -f "$rollback_stage"',
            'test "$(stat -c %a --',
            ' = 600',
            'test "$rollback_operation" = \'deploy-1234\'',
            'test "$rollback_revision" = \'7\'',
            BlueGreenProxyRollbackArtifact::OUTPUT_PREFIX,
        )
        ->not->toContain('mv -fT -- "$rollback_stage"');
});

it('provides validated read restore and idempotent commit commands for durable rollback', function () {
    $key = validBlueGreenRollbackKey();
    $reader = (new BlueGreenProxyRollbackArtifactReader)->commandFor('/data/coolify/proxy', $key);
    $restorer = (new BlueGreenProxyRollbackArtifactRestorer)->commandFor('/data/coolify/proxy', $key);
    $committer = (new BlueGreenProxyRollbackArtifactCommitter)->commandFor('/data/coolify/proxy', $key);

    expect($reader)->toContain(BlueGreenProxyRollbackArtifact::OUTPUT_PREFIX, 'base64 -d <&3')
        ->and($reader)->toContain(
            'flock -x 9',
            '.coolify-blue-green-a5adf99dc3d81b09.yaml.coolify.lock',
            'if [ ! -e',
            BlueGreenProxyRollbackArtifactReader::ABSENT_OUTPUT,
        )
        ->and($restorer)->toContain(
            'base64 -d <&3',
            'mv -fT -- "$rollback_decoded"',
            'rm -f -- "$rollback_decoded"',
        )
        ->and($committer)->toContain('flock -x 9', '.coolify-blue-green-a5adf99dc3d81b09.yaml.coolify.lock', 'if [ -e', 'rm -f --')
        ->and($reader.$restorer.$committer)->not->toContain('.yaml.rollback');
});

it('keeps rollback artifact reads and commits serialized by the managed file lock', function () {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-lock-'.bin2hex(random_bytes(8));
    $dynamicPath = $proxyPath.'/dynamic';
    $filesystem->mkdir($dynamicPath, 0700);

    try {
        $writer = new WriteBlueGreenProxyConfiguration;
        $key = validBlueGreenRollbackKey();
        $configuration = validBlueGreenProxyConfiguration();
        $activePath = $dynamicPath.'/'.$configuration->managedFilename;
        $artifactPath = $dynamicPath.'/'.$key->artifactFilename();
        $previousBytes = "\0rollback\r\nbytes\n";
        file_put_contents($activePath, $previousBytes);
        runBlueGreenShellCommand($writer->commandFor($proxyPath, $configuration, $key));

        $base64 = (new ExecutableFinder)->find('base64');
        if (! is_string($base64)) {
            throw new RuntimeException('The rollback lock test requires base64.');
        }

        $binaryPath = $proxyPath.'/bin';
        $readerReadyPath = $proxyPath.'/reader-ready';
        $readerReleasePath = $proxyPath.'/reader-release';
        $wrapperPath = $binaryPath.'/base64';
        $filesystem->mkdir($binaryPath, 0700);
        file_put_contents($wrapperPath, <<<'SH'
#!/usr/bin/env sh
set -eu

if [ "$#" -eq 1 ] && [ "$1" = "$COOLIFY_TEST_PAUSE_BASE64_PATH" ]; then
    : > "$COOLIFY_TEST_READER_READY"
    while [ ! -e "$COOLIFY_TEST_READER_RELEASE" ]; do
        sleep 0.01
    done
fi

exec "$COOLIFY_TEST_REAL_BASE64" "$@"
SH);
        chmod($wrapperPath, 0700);

        $path = getenv('PATH');
        if (! is_string($path)) {
            throw new RuntimeException('The rollback lock test requires PATH.');
        }
        $readerEnvironment = [
            'COOLIFY_TEST_PAUSE_BASE64_PATH' => $artifactPath,
            'COOLIFY_TEST_READER_READY' => $readerReadyPath,
            'COOLIFY_TEST_READER_RELEASE' => $readerReleasePath,
            'COOLIFY_TEST_REAL_BASE64' => $base64,
            'PATH' => $binaryPath.PATH_SEPARATOR.$path,
        ];
        $reader = Process::fromShellCommandline(
            (new BlueGreenProxyRollbackArtifactReader)->commandFor($proxyPath, $key),
        );
        $reader->setEnv($readerEnvironment);
        $reader->setTimeout(10);
        $reader->start();

        for ($attempt = 0; $attempt < 100 && ! is_file($readerReadyPath); $attempt++) {
            usleep(10_000);
        }

        expect(is_file($readerReadyPath))->toBeTrue();

        $committer = Process::fromShellCommandline($writer->rollbackArtifactCommitCommandFor($proxyPath, $key));
        $committer->setTimeout(10);
        $committer->start();
        usleep(100_000);

        expect($committer->isRunning())->toBeTrue()
            ->and(is_file($artifactPath))->toBeTrue();

        touch($readerReleasePath);
        $reader->wait();
        $committer->wait();

        $readArtifact = BlueGreenProxyRollbackArtifact::fromRemoteOutput($key, $reader->getOutput());
        expect($reader->isSuccessful())->toBeTrue()
            ->and($committer->isSuccessful())->toBeTrue()
            ->and($readArtifact->bytes)->toBe($previousBytes)
            ->and(file_exists($artifactPath))->toBeFalse();
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('executes durable write read restore and commit without overwriting the first rollback bytes', function () {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-'.bin2hex(random_bytes(8));
    $dynamicPath = $proxyPath.'/dynamic';
    $filesystem->mkdir($dynamicPath, 0700);

    try {
        $writer = new WriteBlueGreenProxyConfiguration;
        $key = validBlueGreenRollbackKey();
        $firstConfiguration = validBlueGreenProxyConfiguration();
        $secondYaml = str_replace('app-fixed-blue', 'app-fixed-green', $firstConfiguration->yaml);
        $secondConfiguration = new BlueGreenProxyConfiguration(
            $firstConfiguration->managedFilename,
            $secondYaml,
            hash('sha256', $secondYaml),
        );
        $activePath = $dynamicPath.'/'.$firstConfiguration->managedFilename;
        $artifactPath = $dynamicPath.'/'.$key->artifactFilename();
        $previousBytes = "\0legacy\r\nnot: valid: yaml\n";
        file_put_contents($activePath, $previousBytes);

        $firstArtifact = BlueGreenProxyRollbackArtifact::fromRemoteOutput(
            $key,
            runBlueGreenShellCommand($writer->commandFor($proxyPath, $firstConfiguration, $key)),
        );
        chmod($artifactPath, 0644);
        $wrongModeWrite = Process::fromShellCommandline(
            $writer->commandFor($proxyPath, $secondConfiguration, $key),
        );
        $wrongModeWrite->run();
        expect($wrongModeWrite->isSuccessful())->toBeFalse()
            ->and(file_get_contents($activePath))->toBe($firstConfiguration->yaml);
        chmod($artifactPath, 0600);

        $serializedArtifact = file_get_contents($artifactPath);
        file_put_contents($artifactPath, str_replace($firstArtifact->sha256, str_repeat('0', 64), $serializedArtifact));
        $corruptArtifactWrite = Process::fromShellCommandline(
            $writer->commandFor($proxyPath, $secondConfiguration, $key),
        );
        $corruptArtifactWrite->run();
        expect($corruptArtifactWrite->isSuccessful())->toBeFalse()
            ->and(file_get_contents($activePath))->toBe($firstConfiguration->yaml);
        file_put_contents($artifactPath, $serializedArtifact);

        $secondArtifact = BlueGreenProxyRollbackArtifact::fromRemoteOutput(
            $key,
            runBlueGreenShellCommand($writer->commandFor($proxyPath, $secondConfiguration, $key)),
        );
        $readArtifact = BlueGreenProxyRollbackArtifact::fromRemoteOutput(
            $key,
            runBlueGreenShellCommand((new BlueGreenProxyRollbackArtifactReader)->commandFor($proxyPath, $key)),
        );

        expect($firstArtifact->bytes)->toBe($previousBytes)
            ->and($secondArtifact->bytes)->toBe($previousBytes)
            ->and($readArtifact->bytes)->toBe($previousBytes)
            ->and(file_get_contents($activePath))->toBe($secondConfiguration->yaml)
            ->and(substr(sprintf('%o', fileperms($artifactPath)), -3))->toBe('600');

        runBlueGreenShellCommand($writer->rollbackArtifactRestoreCommandFor($proxyPath, $key));
        expect(file_get_contents($activePath))->toBe($previousBytes)
            ->and(is_file($artifactPath))->toBeTrue();

        runBlueGreenShellCommand($writer->rollbackArtifactCommitCommandFor($proxyPath, $key));
        runBlueGreenShellCommand($writer->rollbackArtifactCommitCommandFor($proxyPath, $key));
        expect(file_exists($artifactPath))->toBeFalse();

        unlink($activePath);
        $missingKey = new BlueGreenProxyRollbackKey(
            $firstConfiguration->managedFilename,
            'deploy-missing',
            8,
        );
        $missingArtifact = BlueGreenProxyRollbackArtifact::fromRemoteOutput(
            $missingKey,
            runBlueGreenShellCommand($writer->commandFor($proxyPath, $firstConfiguration, $missingKey)),
        );
        expect($missingArtifact->existed)->toBeFalse()
            ->and(is_file($activePath))->toBeTrue();
        runBlueGreenShellCommand($writer->rollbackArtifactRestoreCommandFor($proxyPath, $missingKey));
        expect(file_exists($activePath))->toBeFalse();
        runBlueGreenShellCommand($writer->rollbackArtifactCommitCommandFor($proxyPath, $missingKey));

        $symlinkTarget = $proxyPath.'/outside-managed-file';
        file_put_contents($symlinkTarget, 'outside');
        symlink($symlinkTarget, $activePath);
        $symlinkWrite = Process::fromShellCommandline($writer->commandFor(
            $proxyPath,
            $firstConfiguration,
            new BlueGreenProxyRollbackKey($firstConfiguration->managedFilename, 'deploy-symlink', 9),
        ));
        $symlinkWrite->run();
        expect($symlinkWrite->isSuccessful())->toBeFalse()
            ->and(file_get_contents($symlinkTarget))->toBe('outside')
            ->and(is_link($activePath))->toBeTrue();
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('decides rollback artifact absence while holding the managed file lock', function () {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-absent-'.bin2hex(random_bytes(8));
    $filesystem->mkdir($proxyPath.'/dynamic', 0700);

    try {
        $output = runBlueGreenShellCommand(
            (new BlueGreenProxyRollbackArtifactReader)->commandFor($proxyPath, validBlueGreenRollbackKey()),
        );

        expect($output)->toBe(BlueGreenProxyRollbackArtifactReader::ABSENT_OUTPUT);
    } finally {
        $filesystem->remove($proxyPath);
    }
});
