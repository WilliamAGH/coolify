<?php

use App\Actions\Proxy\RemoveBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function blueGreenProxyRemovalShellCommand(string $command): Process
{
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(10);
    $process->run();

    return $process;
}

function blueGreenProxyRemovalFile(
    string $applicationUuid,
    int $destinationId,
    int $routingRevision = 1,
    string $activeColor = 'blue',
): string {
    return implode("\n", [
        '# This file is generated and managed by Coolify.',
        '# coolify.blue-green.managed: "true"',
        '# coolify.application: "'.$applicationUuid.'"',
        '# coolify.destination: '.$destinationId,
        '# coolify.routing-revision: '.$routingRevision,
        '# coolify.active-color: "'.$activeColor.'"',
        '',
        'http:',
        '  routers: {}',
    ]);
}

it('atomically removes only the managed blue-green proxy file after proving its ownership metadata', function () {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-remove-'.bin2hex(random_bytes(8));
    $dynamicPath = $proxyPath.'/dynamic';
    $applicationUuid = 'fixed-app';
    $destinationId = 5;
    $removal = new RemoveBlueGreenProxyConfiguration;
    $managedFilename = $removal->managedFilenameFor($applicationUuid, $destinationId);
    $managedPath = $dynamicPath.'/'.$managedFilename;

    $filesystem->mkdir($dynamicPath, 0700);
    try {
        file_put_contents($managedPath, blueGreenProxyRemovalFile($applicationUuid, $destinationId));

        $process = blueGreenProxyRemovalShellCommand(
            $removal->commandFor(
                $proxyPath,
                $applicationUuid,
                $destinationId,
                1,
                BlueGreenDeploymentColor::BLUE,
            ),
        );

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and(file_exists($managedPath))->toBeFalse()
            ->and($removal->commandFor(
                $proxyPath,
                $applicationUuid,
                $destinationId,
                1,
                BlueGreenDeploymentColor::BLUE,
            ))->toContain('flock -x 9');
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('retains files whose ownership metadata is ambiguous or whose managed path is a symlink', function () {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-remove-'.bin2hex(random_bytes(8));
    $dynamicPath = $proxyPath.'/dynamic';
    $applicationUuid = 'fixed-app';
    $destinationId = 5;
    $removal = new RemoveBlueGreenProxyConfiguration;
    $managedFilename = $removal->managedFilenameFor($applicationUuid, $destinationId);
    $managedPath = $dynamicPath.'/'.$managedFilename;

    $filesystem->mkdir($dynamicPath, 0700);
    try {
        file_put_contents($managedPath, blueGreenProxyRemovalFile(
            $applicationUuid,
            $destinationId,
            routingRevision: 2,
            activeColor: 'green',
        ));
        $metadataMismatch = blueGreenProxyRemovalShellCommand(
            $removal->commandFor(
                $proxyPath,
                $applicationUuid,
                $destinationId,
                1,
                BlueGreenDeploymentColor::BLUE,
            ),
        );

        expect($metadataMismatch->isSuccessful())->toBeFalse()
            ->and(file_exists($managedPath))->toBeTrue();

        unlink($managedPath);
        $outsidePath = $proxyPath.'/outside.yaml';
        file_put_contents($outsidePath, blueGreenProxyRemovalFile($applicationUuid, $destinationId));
        symlink($outsidePath, $managedPath);

        $symlink = blueGreenProxyRemovalShellCommand(
            $removal->commandFor(
                $proxyPath,
                $applicationUuid,
                $destinationId,
                1,
                BlueGreenDeploymentColor::BLUE,
            ),
        );

        expect($symlink->isSuccessful())->toBeFalse()
            ->and(is_link($managedPath))->toBeTrue()
            ->and(file_exists($outsidePath))->toBeTrue();
    } finally {
        $filesystem->remove($proxyPath);
    }
});
