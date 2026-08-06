<?php

use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function destinationFenceBootId(): string
{
    return '11111111-2222-3333-4444-555555555555';
}

function destinationFenceWriter(): WriteBlueGreenProxyConfiguration
{
    return new class extends WriteBlueGreenProxyConfiguration
    {
        protected function bootIdentityAssertionCommand(string $expectedBootIdShellValue): string
        {
            return 'true';
        }
    };
}

/** @return non-empty-list<string> */
function destinationFenceFileAttestation(string $path, string $contents): array
{
    return ['test "$(cat '.escapeshellarg($path).' 2>/dev/null || true)" = '.escapeshellarg($contents)];
}

function compileDestinationFencedBlueGreenConfiguration(
    int $epoch,
    BlueGreenDeploymentColor $activeColor,
    string $deploymentUuid,
    string $containerId,
    string $operationId,
    int $mutationSequence = 1,
): BlueGreenProxyConfiguration {
    return (new CompileBlueGreenProxyConfiguration)->compileGeneratedLabels(
        applicationUuid: 'app-fenced',
        generatedLabels: [
            'traefik.enable=true',
            'traefik.http.routers.app.rule=Host(`example.test`)',
            'traefik.http.routers.app.entryPoints=https',
            'traefik.http.routers.app.service=app',
            'traefik.http.routers.app.tls=true',
            'traefik.http.services.app.loadbalancer.server.port=8080',
        ],
        target: new BlueGreenRoutingTarget(
            destinationId: 42,
            activeColor: $activeColor,
            blueContainerName: 'app-fenced-blue',
            greenContainerName: 'app-fenced-green',
            port: 8080,
            routingRevision: $epoch,
            destinationFenceEpoch: $epoch,
            operationId: $operationId,
            mutationSequence: $mutationSequence,
            activeDeploymentUuid: $deploymentUuid,
            activeContainerId: $containerId,
            destinationTopologyDigest: hash('sha256', 'server:7|destination:42'),
        ),
    );
}

/** @param array<string, string> $environment */
function runDestinationFenceCommand(string $command, array $environment = []): string
{
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(10);
    if ($environment !== []) {
        $process->setEnv($environment);
    }
    $process->mustRun();

    return $process->getOutput();
}

/** @param array<string, string> $environment */
function failedDestinationFenceCommand(string $command, array $environment = []): Process
{
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(10);
    if ($environment !== []) {
        $process->setEnv($environment);
    }
    $process->run();

    return $process;
}

it('retries the rollback-artifact directory barrier after deletion', function (): void {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-artifact-removal-'.bin2hex(random_bytes(8));
    $bin = $proxyPath.'/bin';
    $log = $proxyPath.'/sync.log';
    $filesystem->mkdir([$proxyPath.'/dynamic', $bin], 0700);
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

    try {
        $writer = destinationFenceWriter();
        $configuration = compileDestinationFencedBlueGreenConfiguration(
            epoch: 1,
            activeColor: BlueGreenDeploymentColor::BLUE,
            deploymentUuid: 'deployment-artifact-removal',
            containerId: '0123456789abcdef',
            operationId: 'artifact-removal',
        );
        $rollbackKey = new BlueGreenProxyRollbackKey('artifact-removal', null, $configuration->state);
        runDestinationFenceCommand($writer->commandFor(
            $proxyPath,
            $configuration,
            $rollbackKey,
            destinationFenceBootId(),
        ));
        $artifactPath = $writer->rollbackArtifactPath($proxyPath, $rollbackKey);
        $artifactDirectory = dirname($artifactPath);
        $commitCommand = $writer->rollbackArtifactCommitCommandFor($proxyPath, $rollbackKey);
        $environment = [
            'PATH' => $bin.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'DURABLE_SYNC_LOG' => $log,
        ];

        $failed = failedDestinationFenceCommand($commitCommand, [
            ...$environment,
            'DURABLE_SYNC_FAIL_PATH' => $artifactDirectory,
        ]);
        $retried = failedDestinationFenceCommand($commitCommand, $environment);

        expect($failed->isSuccessful())->toBeFalse()
            ->and($retried->isSuccessful())->toBeTrue($retried->getErrorOutput())
            ->and(file_exists($artifactPath))->toBeFalse()
            ->and(file_get_contents($log))->toBe($artifactDirectory."\n".$artifactDirectory."\n");
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('derives the exact routing digest and durable fence state from canonical Traefik inputs', function () {
    $configuration = compileDestinationFencedBlueGreenConfiguration(
        epoch: 1,
        activeColor: BlueGreenDeploymentColor::BLUE,
        deploymentUuid: 'deployment-blue-1',
        containerId: '0123456789abcdef',
        operationId: 'compile-blue',
    );
    [, $yamlBody] = explode("\n\n", $configuration->yaml, 2);

    expect($configuration->routingConfigDigest)->toBe(hash('sha256', $yamlBody))
        ->and($configuration->state->applicationRoutingConfigDigest)->toBe($configuration->routingConfigDigest)
        ->and($configuration->state->destinationFenceEpoch)->toBe(1)
        ->and($configuration->state->operationId)->toBe('compile-blue')
        ->and($configuration->state->mutationSequence)->toBe(1)
        ->and($configuration->state->activeColor)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($configuration->state->activeDeploymentUuid)->toBe('deployment-blue-1')
        ->and($configuration->state->activeContainerName)->toBe('app-fenced-blue')
        ->and($configuration->state->activeContainerId)->toBe('0123456789abcdef')
        ->and($configuration->yaml)->toContain(
            '# coolify.destination-fence-epoch: 1',
            '# coolify.routing-config-digest: "'.$configuration->routingConfigDigest.'"',
        );

    $roundTrip = BlueGreenProxyState::parse($configuration->state->serialize());
    expect($roundTrip->serialize())->toBe($configuration->state->serialize());
});

it('makes first adoption and stale-owner rejection one flocked destination transaction', function () {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-fence-adoption-'.bin2hex(random_bytes(8));
    $filesystem->mkdir($proxyPath.'/dynamic', 0700);

    try {
        $writer = destinationFenceWriter();
        $configuration = compileDestinationFencedBlueGreenConfiguration(
            epoch: 1,
            activeColor: BlueGreenDeploymentColor::BLUE,
            deploymentUuid: 'deployment-blue-1',
            containerId: '0123456789abcdef',
            operationId: 'first-adoption',
        );
        $key = new BlueGreenProxyRollbackKey('first-adoption', null, $configuration->state);
        $managedPath = $writer->managedPath($proxyPath, $configuration->managedFilename);
        $statePath = $writer->statePath($proxyPath, $configuration->managedFilename);

        file_put_contents($managedPath, 'manual-owner');
        $occupied = failedDestinationFenceCommand($writer->commandFor($proxyPath, $configuration, $key, destinationFenceBootId()));
        expect($occupied->isSuccessful())->toBeFalse()
            ->and(file_get_contents($managedPath))->toBe('manual-owner')
            ->and(file_exists($statePath))->toBeFalse();

        unlink($managedPath);
        $artifact = BlueGreenProxyRollbackArtifact::fromRemoteOutput(
            $key,
            runDestinationFenceCommand($writer->commandFor($proxyPath, $configuration, $key, destinationFenceBootId())),
        );
        expect($artifact->existed)->toBeFalse()
            ->and(file_get_contents($managedPath))->toBe($configuration->yaml)
            ->and(BlueGreenProxyState::parse(file_get_contents($statePath))->serialize())
            ->toBe($configuration->state->serialize())
            ->and(dirname($statePath))->not->toBe($proxyPath.'/dynamic')
            ->and(array_map('basename', glob($proxyPath.'/dynamic/*') ?: []))
            ->toBe([$configuration->managedFilename]);

        $replayed = BlueGreenProxyRollbackArtifact::fromRemoteOutput(
            $key,
            runDestinationFenceCommand($writer->commandFor($proxyPath, $configuration, $key, destinationFenceBootId())),
        );
        expect($replayed->serialize())->toBe($artifact->serialize());

        $staleConfiguration = compileDestinationFencedBlueGreenConfiguration(
            epoch: 1,
            activeColor: BlueGreenDeploymentColor::BLUE,
            deploymentUuid: 'deployment-blue-1',
            containerId: '0123456789abcdef',
            operationId: 'stale-first-adoption',
        );
        $stale = new BlueGreenProxyRollbackKey('stale-first-adoption', null, $staleConfiguration->state);
        expect(failedDestinationFenceCommand($writer->commandFor($proxyPath, $staleConfiguration, $stale, destinationFenceBootId()))->isSuccessful())
            ->toBeFalse()
            ->and(file_get_contents($managedPath))->toBe($configuration->yaml);
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('fences every destination mutation by operation and strictly increasing sequence', function () {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-fence-sequence-'.bin2hex(random_bytes(8));
    $filesystem->mkdir($proxyPath.'/dynamic', 0700);

    try {
        $writer = destinationFenceWriter();
        $managedFilename = BlueGreenRoutingTarget::managedFilename('app-fenced', 42);
        $markerPath = $proxyPath.'/mutation-marker';
        $claim = new BlueGreenProxyState(
            managedFilename: $managedFilename,
            applicationUuid: 'app-fenced',
            destinationId: 42,
            operationId: 'sequenced-deployment',
            mutationSequence: 1,
            destinationFenceEpoch: 0,
            routingRevision: 0,
            managedSha256: null,
            activeColor: null,
            activeDeploymentUuid: null,
            activeContainerName: null,
            activeContainerId: null,
            applicationRoutingConfigDigest: hash('sha256', 'app-routing-inputs'),
            destinationTopologyDigest: hash('sha256', 'server:7|destination:42'),
        );
        $claimCommand = $writer->fencedDestinationCommandFor(
            proxyPath: $proxyPath,
            managedFilename: $managedFilename,
            expectedState: null,
            replacementState: $claim,
            expectedBootId: destinationFenceBootId(),
            commands: ['printf %s '.escapeshellarg('candidate-started').' > '.escapeshellarg($markerPath)],
            completionCommands: destinationFenceFileAttestation($markerPath, 'candidate-started'),
        );
        runDestinationFenceCommand($claimCommand);
        expect(file_get_contents($markerPath))->toBe('candidate-started')
            ->and(runDestinationFenceCommand($writer->attestStateCommandFor($proxyPath, $managedFilename, $claim)))
            ->toBe('coolify-blue-green-destination-state-attested');

        file_put_contents($markerPath, 'replay-sentinel');
        runDestinationFenceCommand($claimCommand);
        expect(file_get_contents($markerPath))->toBe('replay-sentinel');

        $configuration = compileDestinationFencedBlueGreenConfiguration(
            epoch: 1,
            activeColor: BlueGreenDeploymentColor::BLUE,
            deploymentUuid: 'deployment-blue-sequenced',
            containerId: '0123456789abcdef',
            operationId: 'sequenced-deployment',
            mutationSequence: 2,
        );
        $routeKey = new BlueGreenProxyRollbackKey('sequenced-deployment', $claim, $configuration->state);
        runDestinationFenceCommand($writer->commandFor($proxyPath, $configuration, $routeKey, destinationFenceBootId()));

        $sequenceThree = $configuration->state->withMutationOwner('sequenced-deployment');
        $sequenceThreeCommand = $writer->fencedDestinationCommandFor(
            proxyPath: $proxyPath,
            managedFilename: $managedFilename,
            expectedState: $configuration->state,
            replacementState: $sequenceThree,
            expectedBootId: destinationFenceBootId(),
            commands: ['printf %s '.escapeshellarg('sequence-three').' > '.escapeshellarg($markerPath)],
            completionCommands: destinationFenceFileAttestation($markerPath, 'sequence-three'),
        );
        runDestinationFenceCommand($sequenceThreeCommand);

        $sequenceFour = $sequenceThree->withMutationOwner('sequenced-deployment');
        $sequenceFourCommand = $writer->fencedDestinationCommandFor(
            proxyPath: $proxyPath,
            managedFilename: $managedFilename,
            expectedState: $sequenceThree,
            replacementState: $sequenceFour,
            expectedBootId: destinationFenceBootId(),
            commands: ['printf %s '.escapeshellarg('sequence-four').' > '.escapeshellarg($markerPath)],
            completionCommands: destinationFenceFileAttestation($markerPath, 'sequence-four'),
        );
        runDestinationFenceCommand($sequenceFourCommand);
        expect(BlueGreenProxyState::parse(file_get_contents($writer->statePath($proxyPath, $managedFilename)))->mutationSequence)
            ->toBe(4);

        $delayedSequenceThree = failedDestinationFenceCommand($sequenceThreeCommand);
        expect($delayedSequenceThree->isSuccessful())->toBeFalse()
            ->and(file_get_contents($markerPath))->toBe('sequence-four');

        file_put_contents($markerPath, 'exact-replay-sentinel');
        runDestinationFenceCommand($sequenceFourCommand);
        expect(file_get_contents($markerPath))->toBe('exact-replay-sentinel');

        $replacementOwner = $sequenceFour->withMutationOwner('replacement-deployment');
        runDestinationFenceCommand($writer->fencedDestinationCommandFor(
            proxyPath: $proxyPath,
            managedFilename: $managedFilename,
            expectedState: $sequenceFour,
            replacementState: $replacementOwner,
            expectedBootId: destinationFenceBootId(),
            commands: ['printf %s '.escapeshellarg('replacement-owner').' > '.escapeshellarg($markerPath)],
            completionCommands: destinationFenceFileAttestation($markerPath, 'replacement-owner'),
        ));
        expect($replacementOwner->mutationSequence)->toBe(1)
            ->and(file_get_contents($markerPath))->toBe('replacement-owner')
            ->and(failedDestinationFenceCommand(
                $writer->attestStateCommandFor($proxyPath, $managedFilename, $sequenceFour),
            )->isSuccessful())->toBeFalse();

        $staleOldOwner = $sequenceFour->withMutationOwner('sequenced-deployment');
        expect(failedDestinationFenceCommand($writer->fencedDestinationCommandFor(
            proxyPath: $proxyPath,
            managedFilename: $managedFilename,
            expectedState: $sequenceFour,
            replacementState: $staleOldOwner,
            expectedBootId: destinationFenceBootId(),
            commands: ['printf %s '.escapeshellarg('stale-owner').' > '.escapeshellarg($markerPath)],
            completionCommands: destinationFenceFileAttestation($markerPath, 'stale-owner'),
        ))->isSuccessful())->toBeFalse()
            ->and(file_get_contents($markerPath))->toBe('replacement-owner');

        $contenderA = $replacementOwner->withMutationOwner('concurrent-a');
        $contenderB = $replacementOwner->withMutationOwner('concurrent-b');
        $concurrentA = Process::fromShellCommandline($writer->fencedDestinationCommandFor(
            proxyPath: $proxyPath,
            managedFilename: $managedFilename,
            expectedState: $replacementOwner,
            replacementState: $contenderA,
            expectedBootId: destinationFenceBootId(),
            commands: [
                'sleep 0.2',
                'printf %s '.escapeshellarg('concurrent-a').' > '.escapeshellarg($markerPath),
            ],
            completionCommands: destinationFenceFileAttestation($markerPath, 'concurrent-a'),
        ));
        $concurrentB = Process::fromShellCommandline($writer->fencedDestinationCommandFor(
            proxyPath: $proxyPath,
            managedFilename: $managedFilename,
            expectedState: $replacementOwner,
            replacementState: $contenderB,
            expectedBootId: destinationFenceBootId(),
            commands: [
                'sleep 0.2',
                'printf %s '.escapeshellarg('concurrent-b').' > '.escapeshellarg($markerPath),
            ],
            completionCommands: destinationFenceFileAttestation($markerPath, 'concurrent-b'),
        ));
        $concurrentA->setTimeout(10);
        $concurrentB->setTimeout(10);
        $concurrentA->start();
        $concurrentB->start();
        $concurrentA->wait();
        $concurrentB->wait();
        $concurrentResults = [$concurrentA->isSuccessful(), $concurrentB->isSuccessful()];
        sort($concurrentResults);
        $concurrentState = BlueGreenProxyState::parse(
            file_get_contents($writer->statePath($proxyPath, $managedFilename)),
        );
        expect($concurrentResults)->toBe([false, true])
            ->and($concurrentState->operationId)->toBeIn(['concurrent-a', 'concurrent-b'])
            ->and($concurrentState->mutationSequence)->toBe(1)
            ->and(file_get_contents($markerPath))->toBe($concurrentState->operationId);

        runDestinationFenceCommand($writer->rollbackArtifactCommitCommandFor($proxyPath, $routeKey));
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('repairs a post-managed pre-sidecar crash before a different rollback invocation', function () {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-journal-repair-'.bin2hex(random_bytes(8));
    $filesystem->mkdir($proxyPath.'/dynamic', 0700);

    try {
        $writer = destinationFenceWriter();
        $blue = compileDestinationFencedBlueGreenConfiguration(
            epoch: 1,
            activeColor: BlueGreenDeploymentColor::BLUE,
            deploymentUuid: 'deployment-blue-journal',
            containerId: '0123456789abcdef',
            operationId: 'journal-blue',
        );
        $blueKey = new BlueGreenProxyRollbackKey('journal-blue', null, $blue->state);
        runDestinationFenceCommand($writer->commandFor($proxyPath, $blue, $blueKey, destinationFenceBootId()));
        runDestinationFenceCommand($writer->rollbackArtifactCommitCommandFor($proxyPath, $blueKey));

        $green = compileDestinationFencedBlueGreenConfiguration(
            epoch: 2,
            activeColor: BlueGreenDeploymentColor::GREEN,
            deploymentUuid: 'deployment-green-journal',
            containerId: 'fedcba9876543210',
            operationId: 'journal-green',
        );
        $greenKey = new BlueGreenProxyRollbackKey('journal-green', $blue->state, $green->state);
        $crashingWriter = new class extends WriteBlueGreenProxyConfiguration
        {
            protected function bootIdentityAssertionCommand(string $expectedBootIdShellValue): string
            {
                return 'true';
            }

            /** @return list<string> */
            protected function afterManagedMutationCommands(): array
            {
                return ['exit 86'];
            }
        };

        $interrupted = failedDestinationFenceCommand(
            $crashingWriter->commandFor($proxyPath, $green, $greenKey, destinationFenceBootId()),
        );
        $managedPath = $writer->managedPath($proxyPath, $green->managedFilename);
        $statePath = $writer->statePath($proxyPath, $green->managedFilename);
        $journalPath = $writer->mutationJournalPath($proxyPath, $green->managedFilename);
        expect($interrupted->getExitCode())->toBe(86)
            ->and(file_get_contents($managedPath))->toBe($green->yaml)
            ->and(BlueGreenProxyState::parse(file_get_contents($statePath))->serialize())
            ->toBe($blue->state->serialize())
            ->and(file_exists($journalPath))->toBeTrue();

        runDestinationFenceCommand($writer->rollbackArtifactRestoreCommandFor($proxyPath, $greenKey, destinationFenceBootId()));
        $recoveredState = BlueGreenProxyState::parse(file_get_contents($statePath));
        expect(file_get_contents($managedPath))->toBe($blue->yaml)
            ->and($recoveredState->serialize())->toBe($greenKey->rollbackState()->serialize())
            ->and($recoveredState->destinationFenceEpoch)->toBe(3)
            ->and($recoveredState->mutationSequence)->toBe(2)
            ->and(file_exists($journalPath))->toBeFalse();
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('validates prior route identity and advances the destination epoch through rollback and removal', function () {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-fence-rollback-'.bin2hex(random_bytes(8));
    $filesystem->mkdir($proxyPath.'/dynamic', 0700);

    try {
        $writer = destinationFenceWriter();
        $blue = compileDestinationFencedBlueGreenConfiguration(
            epoch: 1,
            activeColor: BlueGreenDeploymentColor::BLUE,
            deploymentUuid: 'deployment-blue-1',
            containerId: '0123456789abcdef',
            operationId: 'deploy-blue',
        );
        $blueKey = new BlueGreenProxyRollbackKey('deploy-blue', null, $blue->state);
        runDestinationFenceCommand($writer->commandFor($proxyPath, $blue, $blueKey, destinationFenceBootId()));
        runDestinationFenceCommand($writer->rollbackArtifactCommitCommandFor($proxyPath, $blueKey));

        $green = compileDestinationFencedBlueGreenConfiguration(
            epoch: 2,
            activeColor: BlueGreenDeploymentColor::GREEN,
            deploymentUuid: 'deployment-green-2',
            containerId: 'fedcba9876543210',
            operationId: 'deploy-green',
        );
        $greenKey = new BlueGreenProxyRollbackKey('deploy-green', $blue->state, $green->state);
        $greenArtifact = BlueGreenProxyRollbackArtifact::fromRemoteOutput(
            $greenKey,
            runDestinationFenceCommand($writer->commandFor($proxyPath, $green, $greenKey, destinationFenceBootId())),
        );
        expect($greenArtifact->existed)->toBeTrue()
            ->and($greenArtifact->bytes)->toBe($blue->yaml);

        $wrongPrior = new BlueGreenProxyState(
            managedFilename: $blue->state->managedFilename,
            applicationUuid: $blue->state->applicationUuid,
            destinationId: $blue->state->destinationId,
            operationId: $blue->state->operationId,
            mutationSequence: $blue->state->mutationSequence,
            destinationFenceEpoch: $blue->state->destinationFenceEpoch,
            routingRevision: $blue->state->routingRevision,
            managedSha256: $blue->state->managedSha256,
            activeColor: $blue->state->activeColor,
            activeDeploymentUuid: 'different-deployment',
            activeContainerName: $blue->state->activeContainerName,
            activeContainerId: 'aaaaaaaaaaaaaaaa',
            applicationRoutingConfigDigest: $blue->state->applicationRoutingConfigDigest,
            destinationTopologyDigest: $blue->state->destinationTopologyDigest,
        );
        $wrongKey = new BlueGreenProxyRollbackKey('deploy-green', $wrongPrior, $green->state);
        expect(fn () => BlueGreenProxyRollbackArtifact::parse($wrongKey, $greenArtifact->serialize()))
            ->toThrow(InvalidArgumentException::class, 'route identity');

        $managedPath = $writer->managedPath($proxyPath, $green->managedFilename);
        $statePath = $writer->statePath($proxyPath, $green->managedFilename);
        runDestinationFenceCommand($writer->rollbackArtifactRestoreCommandFor($proxyPath, $greenKey, destinationFenceBootId()));
        $rolledBackState = BlueGreenProxyState::parse(file_get_contents($statePath));
        expect(file_get_contents($managedPath))->toBe($blue->yaml)
            ->and($rolledBackState->serialize())->toBe($greenKey->rollbackState()->serialize())
            ->and($rolledBackState->destinationFenceEpoch)->toBe(3)
            ->and($rolledBackState->operationId)->toBe('deploy-green')
            ->and($rolledBackState->mutationSequence)->toBe(2)
            ->and($rolledBackState->activeDeploymentUuid)->toBe('deployment-blue-1')
            ->and($rolledBackState->activeContainerId)->toBe('0123456789abcdef');

        expect(failedDestinationFenceCommand($writer->commandFor($proxyPath, $green, $greenKey, destinationFenceBootId()))->isSuccessful())
            ->toBeFalse();
        runDestinationFenceCommand($writer->rollbackArtifactCommitCommandFor($proxyPath, $greenKey));

        $removedState = $rolledBackState->withoutManagedRoute(4, 'remove-route');
        $removeKey = new BlueGreenProxyRollbackKey('remove-route', $rolledBackState, $removedState);
        $removedArtifact = BlueGreenProxyRollbackArtifact::fromRemoteOutput(
            $removeKey,
            runDestinationFenceCommand($writer->removeCommandFor($proxyPath, $removeKey, destinationFenceBootId())),
        );
        expect($removedArtifact->bytes)->toBe($blue->yaml)
            ->and(file_exists($managedPath))->toBeFalse()
            ->and(BlueGreenProxyState::parse(file_get_contents($statePath))->serialize())
            ->toBe($removedState->serialize())
            ->and($removedState->destinationFenceEpoch)->toBe(4);

        expect(failedDestinationFenceCommand($writer->commandFor($proxyPath, $blue, $blueKey, destinationFenceBootId()))->isSuccessful())
            ->toBeFalse();
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('restores the original predecessor after later mutations owned by the same deployment', function () {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-fence-multi-step-'.bin2hex(random_bytes(8));
    $filesystem->mkdir($proxyPath.'/dynamic', 0700);

    try {
        $writer = destinationFenceWriter();
        $blue = compileDestinationFencedBlueGreenConfiguration(
            epoch: 1,
            activeColor: BlueGreenDeploymentColor::BLUE,
            deploymentUuid: 'deployment-blue-original',
            containerId: '0123456789abcdef',
            operationId: 'deploy-blue-original',
        );
        $blueKey = new BlueGreenProxyRollbackKey('deploy-blue-original', null, $blue->state);
        runDestinationFenceCommand($writer->commandFor($proxyPath, $blue, $blueKey, destinationFenceBootId()));
        runDestinationFenceCommand($writer->rollbackArtifactCommitCommandFor($proxyPath, $blueKey));

        $firstGreen = compileDestinationFencedBlueGreenConfiguration(
            epoch: 2,
            activeColor: BlueGreenDeploymentColor::GREEN,
            deploymentUuid: 'deployment-green-multi',
            containerId: 'fedcba9876543210',
            operationId: 'deploy-green-multi',
            mutationSequence: 1,
        );
        $originalRollbackKey = new BlueGreenProxyRollbackKey('deploy-green-multi', $blue->state, $firstGreen->state);
        runDestinationFenceCommand($writer->commandFor($proxyPath, $firstGreen, $originalRollbackKey, destinationFenceBootId()));

        $finalGreen = compileDestinationFencedBlueGreenConfiguration(
            epoch: 3,
            activeColor: BlueGreenDeploymentColor::GREEN,
            deploymentUuid: 'deployment-green-multi',
            containerId: 'fedcba9876543210',
            operationId: 'deploy-green-multi',
            mutationSequence: 2,
        );
        $finalMutationKey = new BlueGreenProxyRollbackKey('deploy-green-multi', $firstGreen->state, $finalGreen->state);
        runDestinationFenceCommand($writer->commandFor($proxyPath, $finalGreen, $finalMutationKey, destinationFenceBootId()));

        $restoredState = $blue->state->withDestinationFenceEpoch(4, 'deploy-green-multi', 3);
        runDestinationFenceCommand($writer->rollbackArtifactRestoreFromStateCommandFor(
            $proxyPath,
            $originalRollbackKey,
            $finalGreen->state,
            $restoredState,
            destinationFenceBootId(),
        ));

        $managedPath = $writer->managedPath($proxyPath, $blue->managedFilename);
        $statePath = $writer->statePath($proxyPath, $blue->managedFilename);
        expect(file_get_contents($managedPath))->toBe($blue->yaml)
            ->and(BlueGreenProxyState::parse(file_get_contents($statePath))->serialize())
            ->toBe($restoredState->serialize());
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('repairs only missing or drifted regular managed files under the exact sidecar', function () {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-steady-repair-'.bin2hex(random_bytes(8));
    $filesystem->mkdir($proxyPath.'/dynamic', 0700);

    try {
        $writer = destinationFenceWriter();
        $configuration = compileDestinationFencedBlueGreenConfiguration(
            epoch: 1,
            activeColor: BlueGreenDeploymentColor::BLUE,
            deploymentUuid: 'deployment-steady-repair',
            containerId: '0123456789abcdef',
            operationId: 'steady-repair-owner',
        );
        $key = new BlueGreenProxyRollbackKey('steady-repair-owner', null, $configuration->state);
        runDestinationFenceCommand($writer->commandFor($proxyPath, $configuration, $key, destinationFenceBootId()));
        $managedPath = $writer->managedPath($proxyPath, $configuration->managedFilename);
        $statePath = $writer->statePath($proxyPath, $configuration->managedFilename);

        expect(trim(runDestinationFenceCommand($writer->repairCommandFor($proxyPath, $configuration, destinationFenceBootId()))))
            ->toBe(WriteBlueGreenProxyConfiguration::REPAIR_HEALTHY_OUTPUT);

        unlink($managedPath);
        expect(trim(runDestinationFenceCommand($writer->repairCommandFor($proxyPath, $configuration, destinationFenceBootId()))))
            ->toBe(WriteBlueGreenProxyConfiguration::REPAIR_MISSING_OUTPUT)
            ->and(file_get_contents($managedPath))->toBe($configuration->yaml);

        file_put_contents($managedPath, 'manual drift');
        expect(trim(runDestinationFenceCommand($writer->repairCommandFor($proxyPath, $configuration, destinationFenceBootId()))))
            ->toBe(WriteBlueGreenProxyConfiguration::REPAIR_DRIFT_OUTPUT)
            ->and(file_get_contents($managedPath))->toBe($configuration->yaml)
            ->and(BlueGreenProxyState::parse(file_get_contents($statePath))->serialize())
            ->toBe($configuration->state->serialize());

        unlink($managedPath);
        symlink('/dev/null', $managedPath);
        expect(failedDestinationFenceCommand($writer->repairCommandFor($proxyPath, $configuration, destinationFenceBootId()))->isSuccessful())
            ->toBeFalse();
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('reconciles a completed container removal after a crash without replaying the destructive command', function () {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-container-journal-'.bin2hex(random_bytes(8));
    $filesystem->mkdir($proxyPath.'/dynamic', 0700);

    try {
        $managedFilename = BlueGreenRoutingTarget::managedFilename('app-fenced', 42);
        $containerMarkerPath = $proxyPath.'/candidate-container';
        file_put_contents($containerMarkerPath, 'running');
        $claim = new BlueGreenProxyState(
            managedFilename: $managedFilename,
            applicationUuid: 'app-fenced',
            destinationId: 42,
            operationId: 'remove-candidate',
            mutationSequence: 1,
            destinationFenceEpoch: 0,
            routingRevision: 0,
            managedSha256: null,
            activeColor: null,
            activeDeploymentUuid: null,
            activeContainerName: null,
            activeContainerId: null,
            applicationRoutingConfigDigest: hash('sha256', 'app-routing-inputs'),
            destinationTopologyDigest: hash('sha256', 'server:7|destination:42'),
        );
        $crashingWriter = new class extends WriteBlueGreenProxyConfiguration
        {
            /** @return list<string> */
            protected function afterContainerMutationCommands(): array
            {
                return ['exit 87'];
            }

            protected function bootIdentityAssertionCommand(string $expectedBootIdShellValue): string
            {
                return 'true';
            }
        };
        $interrupted = failedDestinationFenceCommand($crashingWriter->fencedDestinationCommandFor(
            proxyPath: $proxyPath,
            managedFilename: $managedFilename,
            expectedState: null,
            replacementState: $claim,
            expectedBootId: destinationFenceBootId(),
            commands: [
                'test -f '.escapeshellarg($containerMarkerPath),
                'rm -f -- '.escapeshellarg($containerMarkerPath),
            ],
            completionCommands: [
                'test ! -e '.escapeshellarg($containerMarkerPath),
                'test ! -L '.escapeshellarg($containerMarkerPath),
            ],
        ));
        $writer = destinationFenceWriter();
        $statePath = $writer->statePath($proxyPath, $managedFilename);
        $journalPath = $writer->containerMutationJournalPath($proxyPath, $managedFilename);

        expect($interrupted->getExitCode())->toBe(87)
            ->and(file_exists($containerMarkerPath))->toBeFalse()
            ->and(file_exists($statePath))->toBeFalse()
            ->and(file_exists($journalPath))->toBeTrue();

        expect(runDestinationFenceCommand($writer->attestStateCommandFor($proxyPath, $managedFilename, $claim)))
            ->toBe('coolify-blue-green-destination-state-attested')
            ->and(BlueGreenProxyState::parse(file_get_contents($statePath))->serialize())->toBe($claim->serialize())
            ->and(file_exists($journalPath))->toBeFalse();
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('asserts the expected boot inside the route lock before write rollback and removal mutations', function () {
    $writer = new WriteBlueGreenProxyConfiguration;
    $proxyPath = '/tmp/coolify-blue-green-boot-order';
    $blue = compileDestinationFencedBlueGreenConfiguration(
        epoch: 1,
        activeColor: BlueGreenDeploymentColor::BLUE,
        deploymentUuid: 'deployment-blue-boot-order',
        containerId: '0123456789abcdef',
        operationId: 'boot-order-blue',
    );
    $blueKey = new BlueGreenProxyRollbackKey('boot-order-blue', null, $blue->state);
    $green = compileDestinationFencedBlueGreenConfiguration(
        epoch: 2,
        activeColor: BlueGreenDeploymentColor::GREEN,
        deploymentUuid: 'deployment-green-boot-order',
        containerId: 'fedcba9876543210',
        operationId: 'boot-order-green',
    );
    $greenKey = new BlueGreenProxyRollbackKey('boot-order-green', $blue->state, $green->state);
    $removedState = $green->state->withoutManagedRoute(3, 'boot-order-remove');
    $removeKey = new BlueGreenProxyRollbackKey('boot-order-remove', $green->state, $removedState);
    $containerState = $blue->state->withMutationOwner('boot-order-container');

    $commands = [
        [$writer->commandFor($proxyPath, $blue, $blueKey, destinationFenceBootId()), 'mutation_journal_stage=$(mktemp'],
        [$writer->rollbackArtifactRestoreCommandFor($proxyPath, $greenKey, destinationFenceBootId()), 'mutation_journal_stage=$(mktemp'],
        [$writer->removeCommandFor($proxyPath, $removeKey, destinationFenceBootId()), 'mutation_journal_stage=$(mktemp'],
        [$writer->fencedDestinationCommandFor(
            proxyPath: $proxyPath,
            managedFilename: $blue->managedFilename,
            expectedState: $blue->state,
            replacementState: $containerState,
            expectedBootId: destinationFenceBootId(),
            commands: ['true'],
            completionCommands: ['true'],
        ), 'container_journal_stage=$(mktemp'],
    ];

    foreach ($commands as [$command, $journalMarker]) {
        $lockPosition = strpos($command, 'flock -x 9');
        $bootPosition = strpos($command, destinationFenceBootId());
        $journalPosition = strpos($command, $journalMarker);

        expect($lockPosition)->not->toBeFalse()
            ->and($bootPosition)->not->toBeFalse()
            ->and($journalPosition)->not->toBeFalse()
            ->and($lockPosition)->toBeLessThan($bootPosition)
            ->and($bootPosition)->toBeLessThan($journalPosition)
            ->and($command)->toContain('/proc/sys/kernel/random/boot_id');
    }
});

it('refreshes operation fingerprints only for absent route states', function () {
    $managedState = compileDestinationFencedBlueGreenConfiguration(
        epoch: 2,
        activeColor: BlueGreenDeploymentColor::BLUE,
        deploymentUuid: 'deployment-absent-refresh',
        containerId: '0123456789abcdef',
        operationId: 'absent-refresh-managed',
    )->state;
    $absentState = $managedState->withoutManagedRoute(2, 'absent-refresh-expected');
    $replacementState = $absentState->withAbsentRouteMutationOwner(
        'absent-refresh-replacement',
        hash('sha256', 'refreshed-routing-inputs'),
        hash('sha256', 'refreshed-topology-inputs'),
    );

    expect($replacementState->hasSameAbsentRouteScope($absentState))->toBeTrue()
        ->and($replacementState->managedFilename)->toBe($absentState->managedFilename)
        ->and($replacementState->applicationUuid)->toBe($absentState->applicationUuid)
        ->and($replacementState->destinationId)->toBe($absentState->destinationId)
        ->and($replacementState->destinationFenceEpoch)->toBe($absentState->destinationFenceEpoch)
        ->and($replacementState->routingRevision)->toBe($absentState->routingRevision)
        ->and($replacementState->operationId)->toBe('absent-refresh-replacement')
        ->and($replacementState->mutationSequence)->toBe(1)
        ->and($replacementState->managedSha256)->toBeNull()
        ->and($replacementState->activeColor)->toBeNull()
        ->and($replacementState->applicationRoutingConfigDigest)->toBe(hash('sha256', 'refreshed-routing-inputs'))
        ->and($replacementState->destinationTopologyDigest)->toBe(hash('sha256', 'refreshed-topology-inputs'))
        ->and($managedState->hasSameAbsentRouteScope($replacementState))->toBeFalse();

    expect(fn () => $managedState->withAbsentRouteMutationOwner(
        'managed-refresh-replacement',
        hash('sha256', 'refreshed-routing-inputs'),
        hash('sha256', 'refreshed-topology-inputs'),
    ))->toThrow(InvalidArgumentException::class);
});

it('allows only exact absent-route scopes to refresh operation fingerprints', function () {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-absent-refresh-'.bin2hex(random_bytes(8));
    $filesystem->mkdir($proxyPath.'/dynamic', 0700);

    try {
        $writer = destinationFenceWriter();
        $managedState = compileDestinationFencedBlueGreenConfiguration(
            epoch: 2,
            activeColor: BlueGreenDeploymentColor::BLUE,
            deploymentUuid: 'deployment-absent-refresh-writer',
            containerId: '0123456789abcdef',
            operationId: 'absent-refresh-writer-managed',
        )->state;
        $expectedState = $managedState->withoutManagedRoute(2, 'absent-refresh-writer-expected');
        $replacementState = $expectedState->withAbsentRouteMutationOwner(
            'absent-refresh-writer-replacement',
            hash('sha256', 'writer-refreshed-routing-inputs'),
            hash('sha256', 'writer-refreshed-topology-inputs'),
        );
        $statePath = $writer->statePath($proxyPath, $expectedState->managedFilename);
        $markerPath = $proxyPath.'/absent-refresh-marker';
        $filesystem->mkdir(dirname($statePath), 0700);
        file_put_contents($statePath, $expectedState->serialize());
        chmod($statePath, 0600);

        runDestinationFenceCommand($writer->fencedDestinationCommandFor(
            proxyPath: $proxyPath,
            managedFilename: $expectedState->managedFilename,
            expectedState: $expectedState,
            replacementState: $replacementState,
            expectedBootId: destinationFenceBootId(),
            commands: ['printf %s '.escapeshellarg('absent-refresh-applied').' > '.escapeshellarg($markerPath)],
            completionCommands: destinationFenceFileAttestation($markerPath, 'absent-refresh-applied'),
        ));
        expect(file_get_contents($markerPath))->toBe('absent-refresh-applied')
            ->and(BlueGreenProxyState::parse(file_get_contents($statePath))->serialize())
            ->toBe($replacementState->serialize());

        $managedReplacementState = new BlueGreenProxyState(
            managedFilename: $replacementState->managedFilename,
            applicationUuid: $replacementState->applicationUuid,
            destinationId: $replacementState->destinationId,
            operationId: $replacementState->operationId,
            mutationSequence: $replacementState->mutationSequence,
            destinationFenceEpoch: $replacementState->destinationFenceEpoch,
            routingRevision: $replacementState->routingRevision,
            managedSha256: hash('sha256', 'managed-absent-refresh-replacement'),
            activeColor: BlueGreenDeploymentColor::GREEN,
            activeDeploymentUuid: 'deployment-managed-replacement',
            activeContainerName: 'app-fenced-green',
            activeContainerId: 'abcdef0123456789',
            applicationRoutingConfigDigest: $replacementState->applicationRoutingConfigDigest,
            destinationTopologyDigest: $replacementState->destinationTopologyDigest,
        );
        $scopeChangedReplacementState = new BlueGreenProxyState(
            managedFilename: $replacementState->managedFilename,
            applicationUuid: 'different-fenced-app',
            destinationId: $replacementState->destinationId,
            operationId: $replacementState->operationId,
            mutationSequence: $replacementState->mutationSequence,
            destinationFenceEpoch: $replacementState->destinationFenceEpoch,
            routingRevision: $replacementState->routingRevision,
            managedSha256: null,
            activeColor: null,
            activeDeploymentUuid: null,
            activeContainerName: null,
            activeContainerId: null,
            applicationRoutingConfigDigest: $replacementState->applicationRoutingConfigDigest,
            destinationTopologyDigest: $replacementState->destinationTopologyDigest,
        );
        $epochChangedReplacementState = $expectedState
            ->withDestinationFenceEpoch($expectedState->destinationFenceEpoch + 1)
            ->withAbsentRouteMutationOwner(
                $replacementState->operationId,
                $replacementState->applicationRoutingConfigDigest,
                $replacementState->destinationTopologyDigest,
            );
        $routingRevisionChangedReplacementState = new BlueGreenProxyState(
            managedFilename: $replacementState->managedFilename,
            applicationUuid: $replacementState->applicationUuid,
            destinationId: $replacementState->destinationId,
            operationId: $replacementState->operationId,
            mutationSequence: $replacementState->mutationSequence,
            destinationFenceEpoch: $replacementState->destinationFenceEpoch,
            routingRevision: $replacementState->routingRevision + 1,
            managedSha256: null,
            activeColor: null,
            activeDeploymentUuid: null,
            activeContainerName: null,
            activeContainerId: null,
            applicationRoutingConfigDigest: $replacementState->applicationRoutingConfigDigest,
            destinationTopologyDigest: $replacementState->destinationTopologyDigest,
        );

        foreach ([
            [$managedState, $replacementState],
            [$expectedState, $managedReplacementState],
            [$expectedState, $scopeChangedReplacementState],
            [$expectedState, $epochChangedReplacementState],
            [$expectedState, $routingRevisionChangedReplacementState],
        ] as [$rejectedExpectedState, $rejectedReplacementState]) {
            expect(fn () => $writer->fencedDestinationCommandFor(
                proxyPath: $proxyPath,
                managedFilename: $expectedState->managedFilename,
                expectedState: $rejectedExpectedState,
                replacementState: $rejectedReplacementState,
                expectedBootId: destinationFenceBootId(),
                commands: ['true'],
                completionCommands: ['true'],
            ))->toThrow(InvalidArgumentException::class);
        }
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('normalizes an inherited group-writable state directory before fencing', function () {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-fence-acl-'.bin2hex(random_bytes(8));
    $filesystem->mkdir($proxyPath.'/dynamic', 0700);
    // Fleet hosts apply default POSIX ACLs under /data/coolify, so the fence's
    // own mkdir inherits a group-writable state directory (observed as 770 on
    // popos-sf3); the durable-artifact permission fence must not fail on it.
    $filesystem->mkdir($proxyPath.'/.coolify-blue-green');
    $filesystem->chmod($proxyPath.'/.coolify-blue-green', 0770);

    try {
        $writer = destinationFenceWriter();
        $configuration = compileDestinationFencedBlueGreenConfiguration(
            epoch: 1,
            activeColor: BlueGreenDeploymentColor::BLUE,
            deploymentUuid: 'deployment-blue-1',
            containerId: '0123456789abcdef',
            operationId: 'acl-normalized-adoption',
        );
        $key = new BlueGreenProxyRollbackKey('acl-normalized-adoption', null, $configuration->state);
        runDestinationFenceCommand($writer->commandFor($proxyPath, $configuration, $key, destinationFenceBootId()));

        expect(substr(sprintf('%o', fileperms($proxyPath.'/.coolify-blue-green')), -3))->toBe('700')
            ->and(file_get_contents($writer->managedPath($proxyPath, $configuration->managedFilename)))
            ->toBe($configuration->yaml);
    } finally {
        $filesystem->remove($proxyPath);
    }
});

function orphanedAbsentRouteFenceState(
    string $applicationUuid = 'orphanadoptionapp',
    int $destinationId = 42,
): BlueGreenProxyState {
    return new BlueGreenProxyState(
        managedFilename: BlueGreenRoutingTarget::managedFilename($applicationUuid, $destinationId),
        applicationUuid: $applicationUuid,
        destinationId: $destinationId,
        operationId: 'rolled-back-first-adoption',
        mutationSequence: 2,
        destinationFenceEpoch: 0,
        routingRevision: 0,
        managedSha256: null,
        activeColor: null,
        activeDeploymentUuid: null,
        activeContainerName: null,
        activeContainerId: null,
        applicationRoutingConfigDigest: hash('sha256', 'orphan-routing'),
        destinationTopologyDigest: hash('sha256', 'orphan-topology'),
    );
}

it('repairs an orphaned absent-route epoch-zero fence sidecar during first-adoption attestation', function () {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-orphan-repair-'.bin2hex(random_bytes(8));
    $filesystem->mkdir([$proxyPath.'/dynamic', $proxyPath.'/.coolify-blue-green'], 0700);

    try {
        $writer = destinationFenceWriter();
        $orphan = orphanedAbsentRouteFenceState();
        $statePath = $writer->statePath($proxyPath, $orphan->managedFilename);
        file_put_contents($statePath, $orphan->serialize());
        chmod($statePath, 0600);

        $attestation = runDestinationFenceCommand($writer->firstAdoptionAttestStateCommandFor(
            $proxyPath,
            $orphan->managedFilename,
            $orphan->applicationUuid,
            $orphan->destinationId,
        ));

        expect($attestation)->toBe('coolify-blue-green-destination-state-attested')
            ->and(file_exists($statePath))->toBeFalse();
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('attests a pristine destination unchanged through the first-adoption attestation', function () {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-orphan-pristine-'.bin2hex(random_bytes(8));
    $filesystem->mkdir($proxyPath.'/dynamic', 0700);

    try {
        $writer = destinationFenceWriter();
        $orphan = orphanedAbsentRouteFenceState();

        expect(runDestinationFenceCommand($writer->firstAdoptionAttestStateCommandFor(
            $proxyPath,
            $orphan->managedFilename,
            $orphan->applicationUuid,
            $orphan->destinationId,
        )))->toBe('coolify-blue-green-destination-state-attested');
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('refuses first-adoption attestation when the fence sidecar records a routed or foreign state', function () {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-orphan-refusal-'.bin2hex(random_bytes(8));
    $filesystem->mkdir([$proxyPath.'/dynamic', $proxyPath.'/.coolify-blue-green'], 0700);

    try {
        $writer = destinationFenceWriter();
        $orphan = orphanedAbsentRouteFenceState();
        $statePath = $writer->statePath($proxyPath, $orphan->managedFilename);
        $attestCommand = $writer->firstAdoptionAttestStateCommandFor(
            $proxyPath,
            $orphan->managedFilename,
            $orphan->applicationUuid,
            $orphan->destinationId,
        );

        $routed = compileDestinationFencedBlueGreenConfiguration(
            epoch: 1,
            activeColor: BlueGreenDeploymentColor::BLUE,
            deploymentUuid: 'deployment-routed',
            containerId: str_repeat('a', 64),
            operationId: 'routed-operation',
        )->state;
        file_put_contents($statePath, $routed->serialize());
        chmod($statePath, 0600);
        $routedRefusal = failedDestinationFenceCommand($attestCommand);
        expect($routedRefusal->isSuccessful())->toBeFalse()
            ->and($routedRefusal->getErrorOutput())->toContain('does not record an absent epoch-zero route')
            ->and(file_get_contents($statePath))->toBe($routed->serialize());

        $foreign = orphanedAbsentRouteFenceState(applicationUuid: 'someotherapplication');
        file_put_contents($statePath, $foreign->serialize());
        chmod($statePath, 0600);
        $foreignRefusal = failedDestinationFenceCommand($attestCommand);
        expect($foreignRefusal->isSuccessful())->toBeFalse()
            ->and($foreignRefusal->getErrorOutput())->toContain('does not record an absent epoch-zero route')
            ->and(file_get_contents($statePath))->toBe($foreign->serialize());

        file_put_contents($statePath, orphanedAbsentRouteFenceState()->serialize());
        chmod($statePath, 0600);
        file_put_contents($writer->managedPath($proxyPath, $orphan->managedFilename), 'stray-managed-route');
        $strayRouteRefusal = failedDestinationFenceCommand($attestCommand);
        expect($strayRouteRefusal->isSuccessful())->toBeFalse()
            ->and($strayRouteRefusal->getErrorOutput())->toContain('managed blue/green route file exists')
            ->and(file_exists($statePath))->toBeTrue();
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('rejects invalid first-adoption attestation identity inputs', function () {
    $writer = new WriteBlueGreenProxyConfiguration;
    $managedFilename = BlueGreenRoutingTarget::managedFilename('orphanadoptionapp', 42);

    expect(fn () => $writer->firstAdoptionAttestStateCommandFor('/tmp/proxy', $managedFilename, 'bad uuid!', 42))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $writer->firstAdoptionAttestStateCommandFor('/tmp/proxy', $managedFilename, 'orphanadoptionapp', -1))
        ->toThrow(InvalidArgumentException::class);
});

it('repairs the exact rollback residue an interrupted first adoption records', function () {
    $residue = '{"magic":"coolify-blue-green-destination-fence-v2","managed_filename":"coolify-blue-green-585088d398440262.yaml",'
        .'"application_uuid":"iq8dfnl24mnsj1cj4jf46vf0","destination_id":4,"operation_id":"7cddpkgpitaglirwr5eehtxi",'
        .'"mutation_sequence":2,"destination_fence_epoch":0,"routing_revision":0,"managed_sha256":null,"active_color":null,'
        .'"active_deployment_uuid":null,"active_container_name":null,"active_container_id":null,'
        .'"application_routing_config_digest":"ca9b23a7149c843d5602fd3388c09b3c821cdaeb2ff630e59d4eb44fd79a656f",'
        .'"destination_topology_digest":"f188bf115b3aae530ca0deb3cb2e54f74e38875f9e21eb9bf6961fc24f1c2bbe"}'."\n";
    $managedFilename = BlueGreenRoutingTarget::managedFilename('iq8dfnl24mnsj1cj4jf46vf0', 4);
    expect(BlueGreenProxyState::parse($residue)->serialize())->toBe($residue)
        ->and($managedFilename)->toBe('coolify-blue-green-585088d398440262.yaml');

    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-orphan-incident-'.bin2hex(random_bytes(8));
    $filesystem->mkdir([$proxyPath.'/dynamic', $proxyPath.'/.coolify-blue-green'], 0700);

    try {
        $writer = destinationFenceWriter();
        $statePath = $writer->statePath($proxyPath, $managedFilename);
        file_put_contents($statePath, $residue);
        chmod($statePath, 0600);

        expect(runDestinationFenceCommand($writer->firstAdoptionAttestStateCommandFor(
            $proxyPath,
            $managedFilename,
            'iq8dfnl24mnsj1cj4jf46vf0',
            4,
        )))->toBe('coolify-blue-green-destination-state-attested')
            ->and(file_exists($statePath))->toBeFalse();
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('builds a mature inactive-retirement journal inspection that cannot replay its mutation', function (): void {
    $writer = destinationFenceWriter();
    $expectedState = compileDestinationFencedBlueGreenConfiguration(
        epoch: 2,
        activeColor: BlueGreenDeploymentColor::GREEN,
        deploymentUuid: 'retirement-owner',
        containerId: str_repeat('c', 64),
        operationId: 'retirement-owner',
        mutationSequence: 4,
    )->state;
    $replacementState = $expectedState->withMutationOwner('retirement-owner');

    $command = $writer->inspectStaleInactiveRetirementContainerMutationJournalCommandFor(
        proxyPath: '/data/coolify/proxy',
        stateId: 75,
        expectedCurrentBootId: destinationFenceBootId(),
        expectedJournalBootId: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        expectedState: $expectedState,
        replacementState: $replacementState,
        expectedMutationSha256: str_repeat('d', 64),
        expectedCompletionSha256: str_repeat('e', 64),
        targetContainerName: 'app-fenced-blue',
        targetContainerId: str_repeat('a', 64),
        applicationId: 17,
        inactiveDeploymentUuid: 'retirement-inactive',
        inactiveColor: BlueGreenDeploymentColor::BLUE,
        inactiveRoutingRevision: 1,
    );

    expect($command)->toContain('test "$container_journal_expected_state" = '.escapeshellarg(base64_encode($expectedState->serialize())))
        ->and($command)->toContain('test "$container_journal_replacement_state" = '.escapeshellarg(base64_encode($replacementState->serialize())))
        ->and($command)->toContain('test "$container_journal_mutation_checksum" = '.escapeshellarg(str_repeat('d', 64)))
        ->and($command)->toContain('test "$container_journal_completion_checksum" = '.escapeshellarg(str_repeat('e', 64)))
        ->and($command)->toContain('coolify.blueGreen.deploymentUuid=retirement-inactive')
        ->and($command)->not->toContain('sh "$container_journal_mutation_decoded"')
        ->and($command)->not->toContain('sh "$container_journal_completion_decoded"');
});
