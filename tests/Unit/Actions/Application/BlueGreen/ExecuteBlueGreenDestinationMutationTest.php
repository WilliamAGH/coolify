<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDestinationMutation;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Models\Application;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function runApplicationDestinationMutation(string $command): Process
{
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(10);
    $process->run();

    return $process;
}

it('requires reconciliation before a distinct successor mutation can run', function () {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-application-destination-mutation-'.bin2hex(random_bytes(8));
    $filesystem->mkdir($proxyPath.'/dynamic', 0700);

    try {
        $application = new Application;
        $application->uuid = 'app-destination-fenced';
        $claim = new BlueGreenDeploymentClaim(
            stateId: 1,
            applicationId: 2,
            standaloneDockerId: 3,
            pendingColor: BlueGreenDeploymentColor::BLUE,
            previousActiveColor: null,
            deploymentUuid: 'deployment-fenced',
            expectedRoutingRevision: 1,
            destinationFenceEpoch: 1,
            serverBootId: '11111111-2222-3333-4444-555555555555',
            operationTopologyDigest: hash('sha256', 'topology'),
            routingTopologyDigest: hash('sha256', 'routing-topology'),
            routingConfigDigest: hash('sha256', 'routing'),
            backendPortInventory: BlueGreenBackendPortInventory::fromPorts([3000]),
            drainBackendPortInventory: null,
            supersessionGeneration: 1,
            legacyContainerName: null,
        );
        $writer = new class extends WriteBlueGreenProxyConfiguration
        {
            protected function bootIdentityAssertionCommand(string $expectedBootIdShellValue): string
            {
                return 'true';
            }
        };
        $action = new ExecuteBlueGreenDestinationMutation($writer);
        $candidateStarted = $action->replacementStateFor($application, $claim, null);
        $markerPath = $proxyPath.'/container-state';
        $startCommand = $action->commandFor(
            $proxyPath,
            null,
            $candidateStarted,
            ['printf %s '.escapeshellarg('running').' > '.escapeshellarg($markerPath)],
            ['test "$(cat '.escapeshellarg($markerPath).')" = running'],
            $claim->serverBootId,
        );
        $bootFencedStartCommand = (new ExecuteBlueGreenDestinationMutation)->commandFor(
            $proxyPath,
            null,
            $candidateStarted,
            ['printf %s '.escapeshellarg('running').' > '.escapeshellarg($markerPath)],
            ['test "$(cat '.escapeshellarg($markerPath).')" = running'],
            $claim->serverBootId,
        );
        expect($bootFencedStartCommand)
            ->toContain('flock -x 9')
            ->toContain("test \"$(cat /proc/sys/kernel/random/boot_id)\" = '11111111-2222-3333-4444-555555555555'")
            ->and(strpos($bootFencedStartCommand, 'flock -x 9'))
            ->toBeLessThan(strpos($bootFencedStartCommand, '/proc/sys/kernel/random/boot_id'))
            ->and(strpos($bootFencedStartCommand, '/proc/sys/kernel/random/boot_id'))
            ->toBeLessThan(strpos($bootFencedStartCommand, 'container_journal_stage=$(mktemp'));
        expect(runApplicationDestinationMutation($startCommand)->isSuccessful())->toBeTrue()
            ->and(file_get_contents($markerPath))->toBe('running');

        $unreconciledRemoval = $action->commandFor(
            $proxyPath,
            null,
            $candidateStarted,
            ['printf %s '.escapeshellarg('removed').' > '.escapeshellarg($markerPath)],
            ['test "$(cat '.escapeshellarg($markerPath).')" = removed'],
            $claim->serverBootId,
        );
        expect(runApplicationDestinationMutation($unreconciledRemoval)->isSuccessful())->toBeTrue()
            ->and(file_get_contents($markerPath))->toBe('running');

        $candidateRemoved = $action->replacementStateFor($application, $claim, $candidateStarted);
        $reconciledRemoval = $action->commandFor(
            $proxyPath,
            $candidateStarted,
            $candidateRemoved,
            ['printf %s '.escapeshellarg('removed').' > '.escapeshellarg($markerPath)],
            ['test "$(cat '.escapeshellarg($markerPath).')" = removed'],
            $claim->serverBootId,
        );
        expect(runApplicationDestinationMutation($reconciledRemoval)->isSuccessful())->toBeTrue()
            ->and(file_get_contents($markerPath))->toBe('removed')
            ->and($candidateRemoved->mutationSequence)->toBe(2);

        expect(runApplicationDestinationMutation($startCommand)->isSuccessful())->toBeFalse()
            ->and(file_get_contents($markerPath))->toBe('removed');
    } finally {
        $filesystem->remove($proxyPath);
    }
});
