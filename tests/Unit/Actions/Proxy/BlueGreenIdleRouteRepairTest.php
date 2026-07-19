<?php

use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function idleRouteRepairBootId(): string
{
    return '11111111-2222-3333-4444-555555555555';
}

function idleRouteRepairWriter(): WriteBlueGreenProxyConfiguration
{
    return new class extends WriteBlueGreenProxyConfiguration
    {
        protected function bootIdentityAssertionCommand(string $expectedBootIdShellValue): string
        {
            return 'true';
        }
    };
}

function interruptedIdleRouteRepairWriter(): WriteBlueGreenProxyConfiguration
{
    return new class extends WriteBlueGreenProxyConfiguration
    {
        protected function bootIdentityAssertionCommand(string $expectedBootIdShellValue): string
        {
            return 'true';
        }

        protected function afterManagedMutationCommands(): array
        {
            return ['exit 93'];
        }
    };
}

function compileIdleRouteRepairConfiguration(
    int $epoch,
    BlueGreenDeploymentColor $activeColor,
    string $operationId,
): BlueGreenProxyConfiguration {
    return (new CompileBlueGreenProxyConfiguration)->compileGeneratedLabels(
        applicationUuid: 'idle-route-app',
        generatedLabels: [
            'traefik.enable=true',
            'traefik.http.routers.app.rule=Host(`idle-route.example.test`)',
            'traefik.http.routers.app.entryPoints=https',
            'traefik.http.routers.app.service=app',
            'traefik.http.routers.app.tls=true',
            'traefik.http.services.app.loadbalancer.server.port=8080',
        ],
        target: new BlueGreenRoutingTarget(
            destinationId: 71,
            activeColor: $activeColor,
            blueContainerName: 'idle-route-app-blue',
            greenContainerName: 'idle-route-app-green',
            port: 8080,
            routingRevision: $epoch,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($operationId),
            destinationFenceEpoch: $epoch,
            operationId: $operationId,
            mutationSequence: 1,
            activeDeploymentUuid: 'idle-route-deployment-'.$epoch,
            activeContainerId: str_repeat($activeColor === BlueGreenDeploymentColor::BLUE ? 'a' : 'b', 64),
            destinationTopologyDigest: hash('sha256', 'idle-route-topology-'.$epoch),
        ),
    );
}

function runIdleRouteRepairCommand(string $command): string
{
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(10);
    $process->mustRun();

    return $process->getOutput();
}

function failedIdleRouteRepairCommand(string $command): Process
{
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(10);
    $process->run();

    return $process;
}

/** @return array{WriteBlueGreenProxyConfiguration, BlueGreenProxyConfiguration, string, string, string} */
function prepareIdleRouteRepairFixture(): array
{
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-idle-route-'.bin2hex(random_bytes(8));
    $filesystem->mkdir($proxyPath.'/dynamic', 0700);
    $writer = idleRouteRepairWriter();
    $configuration = compileIdleRouteRepairConfiguration(1, BlueGreenDeploymentColor::BLUE, 'idle-route-owner');
    runIdleRouteRepairCommand($writer->commandFor(
        $proxyPath,
        $configuration,
        new BlueGreenProxyRollbackKey('idle-route-owner', null, $configuration->state),
        idleRouteRepairBootId(),
    ));

    return [
        $writer,
        $configuration,
        $proxyPath,
        $writer->managedPath($proxyPath, $configuration->managedFilename),
        $writer->statePath($proxyPath, $configuration->managedFilename),
    ];
}

it('attests repair for a missing or drifted route and unchanged only after an exact managed route verifies', function () {
    [$writer, $configuration, $proxyPath, $managedPath, $statePath] = prepareIdleRouteRepairFixture();
    $filesystem = new Filesystem;

    try {
        $sidecar = file_get_contents($statePath);
        unlink($managedPath);

        expect(runIdleRouteRepairCommand($writer->repairExactStateCommandFor(
            $proxyPath,
            $configuration,
            $configuration->state,
            idleRouteRepairBootId(),
        )))->toBe(WriteBlueGreenProxyConfiguration::IDLE_ROUTE_REPAIRED_OUTPUT)
            ->and(file_get_contents($managedPath))->toBe($configuration->yaml)
            ->and(file_get_contents($statePath))->toBe($sidecar);

        file_put_contents($managedPath, 'manual-route-drift');
        expect(runIdleRouteRepairCommand($writer->repairExactStateCommandFor(
            $proxyPath,
            $configuration,
            $configuration->state,
            idleRouteRepairBootId(),
        )))->toBe(WriteBlueGreenProxyConfiguration::IDLE_ROUTE_REPAIRED_OUTPUT)
            ->and(file_get_contents($managedPath))->toBe($configuration->yaml)
            ->and(file_get_contents($statePath))->toBe($sidecar);

        expect(runIdleRouteRepairCommand($writer->repairExactStateCommandFor(
            $proxyPath,
            $configuration,
            $configuration->state,
            idleRouteRepairBootId(),
        )))->toBe(WriteBlueGreenProxyConfiguration::IDLE_ROUTE_UNCHANGED_OUTPUT)
            ->and(file_get_contents($managedPath))->toBe($configuration->yaml)
            ->and(file_get_contents($statePath))->toBe($sidecar);
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('fails closed without overwriting a route when the sidecar is missing or malformed', function () {
    [$writer, $configuration, $proxyPath, $managedPath, $statePath] = prepareIdleRouteRepairFixture();
    $filesystem = new Filesystem;

    try {
        file_put_contents($managedPath, 'manual-route-drift');
        file_put_contents($statePath, 'malformed-sidecar');

        expect(failedIdleRouteRepairCommand($writer->repairExactStateCommandFor(
            $proxyPath,
            $configuration,
            $configuration->state,
            idleRouteRepairBootId(),
        ))->isSuccessful())->toBeFalse()
            ->and(file_get_contents($managedPath))->toBe('manual-route-drift');

        unlink($statePath);
        expect(failedIdleRouteRepairCommand($writer->repairExactStateCommandFor(
            $proxyPath,
            $configuration,
            $configuration->state,
            idleRouteRepairBootId(),
        ))->isSuccessful())->toBeFalse()
            ->and(file_get_contents($managedPath))->toBe('manual-route-drift');
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('does not let an older exact repair overwrite a newer destination state', function () {
    [$writer, $configuration, $proxyPath, $managedPath] = prepareIdleRouteRepairFixture();
    $filesystem = new Filesystem;

    try {
        $newerConfiguration = compileIdleRouteRepairConfiguration(2, BlueGreenDeploymentColor::GREEN, 'newer-idle-route-owner');
        runIdleRouteRepairCommand($writer->commandFor(
            $proxyPath,
            $newerConfiguration,
            new BlueGreenProxyRollbackKey(
                'newer-idle-route-owner',
                $configuration->state,
                $newerConfiguration->state,
            ),
            idleRouteRepairBootId(),
        ));

        expect(failedIdleRouteRepairCommand($writer->repairExactStateCommandFor(
            $proxyPath,
            $configuration,
            $configuration->state,
            idleRouteRepairBootId(),
        ))->isSuccessful())->toBeFalse()
            ->and(file_get_contents($managedPath))->toBe($newerConfiguration->yaml);
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('retries idempotently after an interrupted managed-file replacement', function () {
    [$writer, $configuration, $proxyPath, $managedPath, $statePath] = prepareIdleRouteRepairFixture();
    $filesystem = new Filesystem;

    try {
        file_put_contents($managedPath, 'manual-route-drift');
        expect(failedIdleRouteRepairCommand(interruptedIdleRouteRepairWriter()->repairExactStateCommandFor(
            $proxyPath,
            $configuration,
            $configuration->state,
            idleRouteRepairBootId(),
        ))->isSuccessful())->toBeFalse()
            ->and(file_get_contents($managedPath))->toBe($configuration->yaml)
            ->and(file_get_contents($statePath))->toBe($configuration->state->serialize());

        expect(runIdleRouteRepairCommand($writer->repairExactStateCommandFor(
            $proxyPath,
            $configuration,
            $configuration->state,
            idleRouteRepairBootId(),
        )))->toBe(WriteBlueGreenProxyConfiguration::IDLE_ROUTE_UNCHANGED_OUTPUT)
            ->and(file_get_contents($managedPath))->toBe($configuration->yaml);
    } finally {
        $filesystem->remove($proxyPath);
    }
});

test('example', function () {
    expect(true)->toBeTrue();
});
