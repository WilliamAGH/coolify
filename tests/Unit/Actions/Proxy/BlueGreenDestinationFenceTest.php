<?php

use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Proxy\BlueGreenActiveContainer;
use App\Actions\Proxy\BlueGreenActiveContainerSet;
use App\Actions\Proxy\BlueGreenActiveReplica;
use App\Actions\Proxy\BlueGreenActiveReplicaSet;
use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\ContainerStatusTypes;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function destinationFenceBootId(): string
{
    return '11111111-2222-3333-4444-555555555555';
}

function destinationFenceWriter(?string $currentBootId = null): WriteBlueGreenProxyConfiguration
{
    return new class($currentBootId) extends WriteBlueGreenProxyConfiguration
    {
        public function __construct(private readonly ?string $currentBootId) {}

        protected function bootIdentityAssertionCommand(string $expectedBootIdShellValue): string
        {
            return $this->currentBootId === null
                ? 'true'
                : 'test '.escapeshellarg($this->currentBootId).' = '.$expectedBootIdShellValue;
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

it('migrates only the exact released v3 scalar and routed member without replaying pending journals', function (): void {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-released-v3-migration-'.bin2hex(random_bytes(8));
    $bin = $proxyPath.'/bin';
    $filesystem->mkdir([$proxyPath.'/dynamic', $proxyPath.'/.coolify-blue-green', $bin], 0700);

    try {
        $writer = destinationFenceWriter();
        $managedFilename = BlueGreenRoutingTarget::managedFilename('app-fenced', 42);
        $managedBytes = "http:\n  routers: {}\n";
        $managedSha256 = hash('sha256', $managedBytes);
        $canonicalRoutedId = str_repeat('a', 64);
        $secondaryId = str_repeat('b', 64);
        $releasedAggregateId = str_repeat('c', 64);
        $state = static function (string $routedId, string $secondaryMemberId) use (
            $managedFilename,
            $managedSha256,
        ): BlueGreenProxyState {
            return new BlueGreenProxyState(
                managedFilename: $managedFilename,
                applicationUuid: 'app-fenced',
                destinationId: 42,
                operationId: 'released-v3-owner',
                mutationSequence: 3,
                destinationFenceEpoch: 2,
                routingRevision: 2,
                managedSha256: $managedSha256,
                activeColor: BlueGreenDeploymentColor::BLUE,
                activeDeploymentUuid: 'released-v3-active',
                activeContainerName: 'app-fenced-blue',
                activeContainerId: $routedId,
                applicationRoutingConfigDigest: hash('sha256', 'released-v3-routing'),
                destinationTopologyDigest: hash('sha256', 'released-v3-topology'),
                activeContainerSet: BlueGreenActiveContainerSet::fromMembers([
                    new BlueGreenActiveContainer(8080, 'app-fenced-blue', $routedId),
                    new BlueGreenActiveContainer(9090, 'app-fenced-worker-blue', $secondaryMemberId),
                ]),
            );
        };
        $released = $state($releasedAggregateId, $secondaryId);
        $canonical = $state($canonicalRoutedId, $secondaryId);
        $managedPath = $writer->managedPath($proxyPath, $managedFilename);
        $statePath = $writer->statePath($proxyPath, $managedFilename);
        file_put_contents($managedPath, $managedBytes);
        file_put_contents($statePath, $released->serialize());
        chmod($managedPath, 0600);
        chmod($statePath, 0600);
        $liveReplicas = [
            [
                'application_id' => 17,
                'deployment_uuid' => 'released-v3-active',
                'color' => BlueGreenDeploymentColor::BLUE->value,
                'routing_revision' => 2,
                'compose_project' => 'app-fenced',
                'compose_service' => 'web-blue',
                'replica_index' => 1,
                'replica_count' => 1,
                'container_name' => 'app-fenced-blue',
                'container_id' => $canonicalRoutedId,
            ],
            [
                'application_id' => 17,
                'deployment_uuid' => 'released-v3-active',
                'color' => BlueGreenDeploymentColor::BLUE->value,
                'routing_revision' => 2,
                'compose_project' => 'app-fenced',
                'compose_service' => 'worker-blue',
                'replica_index' => 1,
                'replica_count' => 1,
                'container_name' => 'app-fenced-worker-blue',
                'container_id' => $secondaryId,
            ],
        ];
        file_put_contents($bin.'/docker', <<<'SH'
#!/bin/sh
set -eu

if [ "$1" = inspect ]; then
    case "$2" in
        --format=*) format=${2#--format=} ;;
        *) exit 64 ;;
    esac
    container_id=$3
    if [ "${FAKE_DOCKER_RESTARTED_ID:-}" = "$container_id" ]; then
        exit 1
    fi
    case "$container_id" in
        "$FAKE_DOCKER_PRIMARY_ID")
            container_name=$FAKE_DOCKER_PRIMARY_NAME
            compose_service=$FAKE_DOCKER_PRIMARY_SERVICE
            ;;
        "$FAKE_DOCKER_SECONDARY_ID")
            container_name=$FAKE_DOCKER_SECONDARY_NAME
            compose_service=$FAKE_DOCKER_SECONDARY_SERVICE
            ;;
        *) exit 1 ;;
    esac
    case "$format" in
        '{{.Id}}') printf '%s\n' "$container_id" ;;
        '{{.Name}}') printf '/%s\n' "$container_name" ;;
        '{{.State.Status}}') printf 'running\n' ;;
        '{{.State.Health.Status}}') printf 'healthy\n' ;;
        *coolify.applicationId*) printf '%s\n' "$FAKE_DOCKER_APPLICATION_ID" ;;
        *coolify.pullRequestId*) printf '0\n' ;;
        *coolify.blueGreen.managed*) printf 'true\n' ;;
        *coolify.blueGreen.deploymentUuid*) printf '%s\n' "$FAKE_DOCKER_DEPLOYMENT_UUID" ;;
        *coolify.blueGreen.color*) printf '%s\n' "$FAKE_DOCKER_COLOR" ;;
        *coolify.blueGreen.routingRevision*) printf '%s\n' "$FAKE_DOCKER_ROUTING_REVISION" ;;
        *com.docker.compose.project*) printf '%s\n' "$FAKE_DOCKER_COMPOSE_PROJECT" ;;
        *com.docker.compose.service*) printf '%s\n' "$compose_service" ;;
        *) exit 64 ;;
    esac
    exit 0
fi

if [ "$1" = ps ]; then
    shift
    compose_service=
    while [ "$#" -gt 0 ]; do
        if [ "$1" = --filter ]; then
            case "$2" in
                label=com.docker.compose.service=*)
                    compose_service=${2#label=com.docker.compose.service=}
                    ;;
            esac
            shift 2
            continue
        fi
        shift
    done
    secondary_id=$FAKE_DOCKER_SECONDARY_ID
    if [ "${FAKE_DOCKER_RESTARTED_ID:-}" = "$secondary_id" ]; then
        secondary_id=$FAKE_DOCKER_REPLACEMENT_ID
    fi
    case "$compose_service" in
        "$FAKE_DOCKER_PRIMARY_SERVICE") printf '%s\n' "$FAKE_DOCKER_PRIMARY_ID" ;;
        "$FAKE_DOCKER_SECONDARY_SERVICE") printf '%s\n' "$secondary_id" ;;
        '') printf '%s\n%s\n' "$FAKE_DOCKER_PRIMARY_ID" "$secondary_id" ;;
        *) exit 65 ;;
    esac
    exit 0
fi

exit 64
SH
        );
        chmod($bin.'/docker', 0700);
        $dockerEnvironment = [
            'PATH' => $bin.PATH_SEPARATOR.(getenv('PATH') ?: '/usr/bin:/bin'),
            'FAKE_DOCKER_APPLICATION_ID' => '17',
            'FAKE_DOCKER_DEPLOYMENT_UUID' => 'released-v3-active',
            'FAKE_DOCKER_COLOR' => BlueGreenDeploymentColor::BLUE->value,
            'FAKE_DOCKER_ROUTING_REVISION' => '2',
            'FAKE_DOCKER_COMPOSE_PROJECT' => 'app-fenced',
            'FAKE_DOCKER_PRIMARY_ID' => $canonicalRoutedId,
            'FAKE_DOCKER_PRIMARY_NAME' => 'app-fenced-blue',
            'FAKE_DOCKER_PRIMARY_SERVICE' => 'web-blue',
            'FAKE_DOCKER_SECONDARY_ID' => $secondaryId,
            'FAKE_DOCKER_SECONDARY_NAME' => 'app-fenced-worker-blue',
            'FAKE_DOCKER_SECONDARY_SERVICE' => 'worker-blue',
            'FAKE_DOCKER_REPLACEMENT_ID' => str_repeat('e', 64),
        ];
        $command = $writer->migrateReleasedV3StateCommandFor(
            $proxyPath,
            $released,
            $canonical,
            destinationFenceBootId(),
            $liveReplicas,
        );

        expect(trim(runDestinationFenceCommand($command, $dockerEnvironment)))
            ->toBe(WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT)
            ->and(file_get_contents($managedPath))->toBe($managedBytes)
            ->and(file_get_contents($statePath))->toBe($canonical->serialize())
            ->and(trim(runDestinationFenceCommand($command, $dockerEnvironment)))
            ->toBe(WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT);

        $restartedEnvironment = [
            ...$dockerEnvironment,
            'FAKE_DOCKER_RESTARTED_ID' => $secondaryId,
        ];
        expect(failedDestinationFenceCommand($command, $restartedEnvironment)->isSuccessful())
            ->toBeFalse()
            ->and(file_get_contents($statePath))->toBe($canonical->serialize());

        file_put_contents($statePath, $released->serialize());
        expect(failedDestinationFenceCommand($command, $restartedEnvironment)->isSuccessful())
            ->toBeFalse()
            ->and(file_get_contents($managedPath))->toBe($managedBytes)
            ->and(file_get_contents($statePath))->toBe($released->serialize());

        $foreign = $state($releasedAggregateId, str_repeat('d', 64));
        file_put_contents($statePath, $foreign->serialize());
        expect(failedDestinationFenceCommand($command, $dockerEnvironment)->isSuccessful())->toBeFalse()
            ->and(file_get_contents($statePath))->toBe($foreign->serialize());

        foreach ([
            $writer->mutationJournalPath($proxyPath, $managedFilename),
            $writer->containerMutationJournalPath($proxyPath, $managedFilename),
        ] as $journalPath) {
            file_put_contents($statePath, $released->serialize());
            file_put_contents($journalPath, 'unrelated pending operation');
            chmod($journalPath, 0600);

            expect(failedDestinationFenceCommand($command, $dockerEnvironment)->isSuccessful())->toBeFalse()
                ->and(file_get_contents($journalPath))->toBe('unrelated pending operation')
                ->and(file_get_contents($statePath))->toBe($released->serialize());

            unlink($journalPath);
        }
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('exactly migrates released v2 fan-out state and refuses live replica or sidecar mismatches', function (): void {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-released-v2-fanout-migration-'.bin2hex(random_bytes(8));
    $bin = $proxyPath.'/bin';
    $filesystem->mkdir([$proxyPath.'/dynamic', $proxyPath.'/.coolify-blue-green', $bin], 0700);

    try {
        $writer = destinationFenceWriter();
        $managedFilename = BlueGreenRoutingTarget::managedFilename('app-fenced', 42);
        $managedBytes = "http:\n  routers: {}\n";
        $managedSha256 = hash('sha256', $managedBytes);
        $firstId = str_repeat('a', 64);
        $secondId = str_repeat('b', 64);
        $inspections = [
            BlueGreenReplicaInspection::fromRuntime(1, 'app-blue-replica-1', 'app-fenced-blue-replica-1', $firstId, 'running', 'healthy'),
            BlueGreenReplicaInspection::fromRuntime(2, 'app-blue-replica-2', 'app-fenced-blue-replica-2', $secondId, 'running', 'healthy'),
        ];
        $legacyDigest = BlueGreenReplicaSet::identityDigest($inspections);
        $activeReplicaSet = BlueGreenActiveReplicaSet::fromMembers([
            new BlueGreenActiveReplica('app-blue-replica-1', 1, [8080], 'app-fenced-blue-replica-1', $firstId),
            new BlueGreenActiveReplica('app-blue-replica-2', 2, [8080], 'app-fenced-blue-replica-2', $secondId),
        ]);
        $released = new BlueGreenProxyState(
            managedFilename: $managedFilename,
            applicationUuid: 'app-fenced',
            destinationId: 42,
            operationId: 'released-v2-fanout-owner',
            mutationSequence: 3,
            destinationFenceEpoch: 2,
            routingRevision: 2,
            managedSha256: $managedSha256,
            activeColor: BlueGreenDeploymentColor::BLUE,
            activeDeploymentUuid: 'released-v2-fanout-active',
            activeContainerName: 'app-fenced-blue',
            activeContainerId: $legacyDigest,
            applicationRoutingConfigDigest: hash('sha256', 'released-v2-fanout-routing'),
            destinationTopologyDigest: hash('sha256', 'released-v2-fanout-topology'),
        );
        $canonical = new BlueGreenProxyState(
            managedFilename: $managedFilename,
            applicationUuid: 'app-fenced',
            destinationId: 42,
            operationId: 'released-v2-fanout-owner',
            mutationSequence: 3,
            destinationFenceEpoch: 2,
            routingRevision: 2,
            managedSha256: $managedSha256,
            activeColor: BlueGreenDeploymentColor::BLUE,
            activeDeploymentUuid: 'released-v2-fanout-active',
            activeContainerName: 'app-fenced-blue-replica-1',
            activeContainerId: $firstId,
            applicationRoutingConfigDigest: hash('sha256', 'released-v2-fanout-routing'),
            destinationTopologyDigest: hash('sha256', 'released-v2-fanout-topology'),
            activeReplicaSetDigest: $activeReplicaSet->identityDigest(),
            activeReplicaSet: $activeReplicaSet,
        );
        $liveReplicas = [
            [
                'application_id' => 17,
                'deployment_uuid' => 'released-v2-fanout-active',
                'color' => BlueGreenDeploymentColor::BLUE->value,
                'routing_revision' => 2,
                'compose_project' => 'app-fenced',
                'compose_service' => 'app-blue-replica-1',
                'replica_index' => 1,
                'replica_count' => 2,
                'container_name' => 'app-fenced-blue-replica-1',
                'container_id' => $firstId,
            ],
            [
                'application_id' => 17,
                'deployment_uuid' => 'released-v2-fanout-active',
                'color' => BlueGreenDeploymentColor::BLUE->value,
                'routing_revision' => 2,
                'compose_project' => 'app-fenced',
                'compose_service' => 'app-blue-replica-2',
                'replica_index' => 2,
                'replica_count' => 2,
                'container_name' => 'app-fenced-blue-replica-2',
                'container_id' => $secondId,
            ],
        ];
        $managedPath = $writer->managedPath($proxyPath, $managedFilename);
        $statePath = $writer->statePath($proxyPath, $managedFilename);
        file_put_contents($managedPath, $managedBytes);
        file_put_contents($statePath, $released->serialize());
        chmod($managedPath, 0600);
        chmod($statePath, 0600);
        file_put_contents($bin.'/docker', <<<'SH'
#!/bin/sh
set -eu

if [ "$1" = inspect ]; then
    case "$2" in --format=*) format=${2#--format=} ;; *) exit 64 ;; esac
    container_id=$3
    case "$container_id" in
        "$FAKE_DOCKER_FIRST_ID")
            container_name=$FAKE_DOCKER_FIRST_NAME
            compose_service=$FAKE_DOCKER_FIRST_SERVICE
            replica_index=1
            ;;
        "$FAKE_DOCKER_SECOND_ID")
            container_name=$FAKE_DOCKER_SECOND_NAME
            compose_service=$FAKE_DOCKER_SECOND_SERVICE
            replica_index=${FAKE_DOCKER_SECOND_REPLICA_INDEX:-2}
            ;;
        *) exit 1 ;;
    esac
    case "$format" in
        '{{.Id}}') printf '%s\n' "$container_id" ;;
        '{{.Name}}') printf '/%s\n' "$container_name" ;;
        '{{.State.Status}}') printf 'running\n' ;;
        '{{.State.Health.Status}}') printf 'healthy\n' ;;
        *coolify.applicationId*) printf '17\n' ;;
        *coolify.pullRequestId*) printf '0\n' ;;
        *coolify.blueGreen.managed*) printf 'true\n' ;;
        *coolify.blueGreen.deploymentUuid*) printf 'released-v2-fanout-active\n' ;;
        *coolify.blueGreen.color*) printf 'blue\n' ;;
        *coolify.blueGreen.routingRevision*) printf '2\n' ;;
        *coolify.blueGreen.replicaIndex*) printf '%s\n' "$replica_index" ;;
        *coolify.blueGreen.replicaCount*) printf '2\n' ;;
        *com.docker.compose.project*) printf 'app-fenced\n' ;;
        *com.docker.compose.service*) printf '%s\n' "$compose_service" ;;
        *) exit 64 ;;
    esac
    exit 0
fi

if [ "$1" = ps ]; then
    shift
    compose_service=
    while [ "$#" -gt 0 ]; do
        if [ "$1" = --filter ]; then
            case "$2" in label=com.docker.compose.service=*) compose_service=${2#label=com.docker.compose.service=} ;; esac
            shift 2
            continue
        fi
        shift
    done
    case "$compose_service" in
        "$FAKE_DOCKER_FIRST_SERVICE") printf '%s\n' "$FAKE_DOCKER_FIRST_ID" ;;
        "$FAKE_DOCKER_SECOND_SERVICE") printf '%s\n' "$FAKE_DOCKER_SECOND_ID" ;;
        '') printf '%s\n%s\n' "$FAKE_DOCKER_FIRST_ID" "$FAKE_DOCKER_SECOND_ID" ;;
        *) exit 65 ;;
    esac
    exit 0
fi

exit 64
SH
        );
        chmod($bin.'/docker', 0700);
        $dockerEnvironment = [
            'PATH' => $bin.PATH_SEPARATOR.(getenv('PATH') ?: '/usr/bin:/bin'),
            'FAKE_DOCKER_FIRST_ID' => $firstId,
            'FAKE_DOCKER_FIRST_NAME' => 'app-fenced-blue-replica-1',
            'FAKE_DOCKER_FIRST_SERVICE' => 'app-blue-replica-1',
            'FAKE_DOCKER_SECOND_ID' => $secondId,
            'FAKE_DOCKER_SECOND_NAME' => 'app-fenced-blue-replica-2',
            'FAKE_DOCKER_SECOND_SERVICE' => 'app-blue-replica-2',
        ];
        $command = $writer->migrateReleasedV3StateCommandFor(
            $proxyPath,
            $released,
            $canonical,
            destinationFenceBootId(),
            $liveReplicas,
        );

        expect(trim(runDestinationFenceCommand($command, $dockerEnvironment)))
            ->toBe(WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT)
            ->and(file_get_contents($managedPath))->toBe($managedBytes)
            ->and(file_get_contents($statePath))->toBe($canonical->serialize())
            ->and(trim(runDestinationFenceCommand($command, $dockerEnvironment)))
            ->toBe(WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT);

        file_put_contents($statePath, $released->serialize());
        $mismatchedEnvironment = [...$dockerEnvironment, 'FAKE_DOCKER_SECOND_REPLICA_INDEX' => '1'];
        expect(failedDestinationFenceCommand($command, $mismatchedEnvironment)->isSuccessful())
            ->toBeFalse()
            ->and(file_get_contents($statePath))->toBe($released->serialize());

        $foreign = new BlueGreenProxyState(
            managedFilename: $released->managedFilename,
            applicationUuid: $released->applicationUuid,
            destinationId: $released->destinationId,
            operationId: $released->operationId,
            mutationSequence: $released->mutationSequence,
            destinationFenceEpoch: $released->destinationFenceEpoch,
            routingRevision: $released->routingRevision,
            managedSha256: $released->managedSha256,
            activeColor: $released->activeColor,
            activeDeploymentUuid: $released->activeDeploymentUuid,
            activeContainerName: $released->activeContainerName,
            activeContainerId: str_repeat('f', 64),
            applicationRoutingConfigDigest: $released->applicationRoutingConfigDigest,
            destinationTopologyDigest: $released->destinationTopologyDigest,
        );
        file_put_contents($statePath, $foreign->serialize());
        expect(failedDestinationFenceCommand($command, $dockerEnvironment)->isSuccessful())
            ->toBeFalse()
            ->and(file_get_contents($statePath))->toBe($foreign->serialize());
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('generates exact released v2 migration assertions for co-rolled fan-out replica slots', function (): void {
    $writer = destinationFenceWriter();
    $managedFilename = BlueGreenRoutingTarget::managedFilename('app-fenced', 42);
    $managedSha256 = hash('sha256', 'co-rolled-fan-out-route');
    $replicas = [
        ['gateway-blue-replica-1', 1, [8080], 'app-fenced-gateway-blue-replica-1', str_repeat('1', 64)],
        ['worker-blue-replica-1', 1, [], 'app-fenced-worker-blue-replica-1', str_repeat('2', 64)],
        ['gateway-blue-replica-2', 2, [8080], 'app-fenced-gateway-blue-replica-2', str_repeat('3', 64)],
        ['worker-blue-replica-2', 2, [], 'app-fenced-worker-blue-replica-2', str_repeat('4', 64)],
    ];
    $inspections = array_map(
        static fn (array $replica): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $replica[1],
            composeService: $replica[0],
            containerName: $replica[3],
            dockerId: $replica[4],
            status: 'running',
            health: 'healthy',
        ),
        $replicas,
    );
    $activeReplicaSet = BlueGreenActiveReplicaSet::fromMembers(array_map(
        static fn (array $replica): BlueGreenActiveReplica => new BlueGreenActiveReplica(
            composeService: $replica[0],
            replicaIndex: $replica[1],
            ports: $replica[2],
            name: $replica[3],
            id: $replica[4],
        ),
        $replicas,
    ));
    $legacyDigest = BlueGreenReplicaSet::identityDigest($inspections);
    $released = new BlueGreenProxyState(
        managedFilename: $managedFilename,
        applicationUuid: 'app-fenced',
        destinationId: 42,
        operationId: 'released-v2-co-rolled-owner',
        mutationSequence: 4,
        destinationFenceEpoch: 3,
        routingRevision: 7,
        managedSha256: $managedSha256,
        activeColor: BlueGreenDeploymentColor::BLUE,
        activeDeploymentUuid: 'released-v2-co-rolled-active',
        activeContainerName: 'app-fenced-blue',
        activeContainerId: $legacyDigest,
        applicationRoutingConfigDigest: hash('sha256', 'released-v2-co-rolled-routing'),
        destinationTopologyDigest: hash('sha256', 'released-v2-co-rolled-topology'),
    );
    $representative = $activeReplicaSet->representative();
    $canonical = new BlueGreenProxyState(
        managedFilename: $managedFilename,
        applicationUuid: 'app-fenced',
        destinationId: 42,
        operationId: 'released-v2-co-rolled-owner',
        mutationSequence: 4,
        destinationFenceEpoch: 3,
        routingRevision: 7,
        managedSha256: $managedSha256,
        activeColor: BlueGreenDeploymentColor::BLUE,
        activeDeploymentUuid: 'released-v2-co-rolled-active',
        activeContainerName: $representative->name,
        activeContainerId: $representative->id,
        applicationRoutingConfigDigest: hash('sha256', 'released-v2-co-rolled-routing'),
        destinationTopologyDigest: hash('sha256', 'released-v2-co-rolled-topology'),
        activeReplicaSetDigest: $activeReplicaSet->identityDigest(),
        activeReplicaSet: $activeReplicaSet,
    );
    $liveReplicas = array_map(
        static fn (array $replica): array => [
            'application_id' => 17,
            'deployment_uuid' => 'released-v2-co-rolled-active',
            'color' => BlueGreenDeploymentColor::BLUE->value,
            'routing_revision' => 7,
            'compose_project' => 'app-fenced',
            'compose_service' => $replica[0],
            'replica_index' => $replica[1],
            'replica_count' => 2,
            'container_name' => $replica[3],
            'container_id' => $replica[4],
        ],
        $replicas,
    );

    $command = $writer->migrateReleasedV3StateCommandFor(
        '/data/coolify/proxy',
        $released,
        $canonical,
        destinationFenceBootId(),
        $liveReplicas,
    );

    expect($legacyDigest)->not->toBe($activeReplicaSet->identityDigest())
        ->and($canonical->activeSetFenceIdentity())->toBe($activeReplicaSet->identityDigest())
        ->and($command)->toContain(
            base64_encode($released->serialize()),
            base64_encode($canonical->serialize()),
        )
        ->and(substr_count($command, 'label=coolify.blueGreen.replicaIndex=1'))->toBe(4)
        ->and(substr_count($command, 'label=coolify.blueGreen.replicaIndex=2'))->toBe(4)
        ->and(substr_count($command, 'label=coolify.blueGreen.replicaCount=2'))->toBe(8);
    foreach ($replicas as $replica) {
        expect($command)->toContain($replica[0], $replica[3], $replica[4]);
    }
});

it('fences a pending container-mutation journal during attestation and attests once it is cleared', function (): void {
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

        // Attestation never inspects, replays, or finalizes a container-mutation
        // journal: only the operation-aware PHP reader may authenticate one and
        // archive it under the destination fence. Until it does, attestation is
        // refused and the journal is left exactly as the crash wrote it.
        $crashedAttestation = failedDestinationFenceCommand(
            $writer->attestStateCommandFor($proxyPath, $managedFilename, $claim),
        );
        expect($crashedAttestation->getExitCode())->toBe(75)
            ->and($crashedAttestation->getErrorOutput())
            ->toContain(WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT)
            ->and(file_exists($journalPath))->toBeTrue()
            ->and(file_exists($statePath))->toBeFalse();

        // Stand in for that archival -- the reader removes the journal and records
        // the replacement state -- so the rest of the attestation contract is
        // still exercised on the same destination.
        unlink($journalPath);
        file_put_contents($statePath, $claim->serialize());
        chmod($statePath, 0600);
        expect(runDestinationFenceCommand($writer->attestStateCommandFor($proxyPath, $managedFilename, $claim)))
            ->toBe('coolify-blue-green-destination-state-attested')
            ->and(BlueGreenProxyState::parse(file_get_contents($statePath))->serialize())->toBe($claim->serialize())
            ->and(file_exists($journalPath))->toBeFalse();

        $pendingState = $claim->withMutationOwner('pending-sentinel');
        $sentinelPath = $proxyPath.'/mutation-replay-sentinel';
        file_put_contents($sentinelPath, 'before-mutation');

        $interrupted = failedDestinationFenceCommand($crashingWriter->fencedDestinationCommandFor(
            proxyPath: $proxyPath,
            managedFilename: $managedFilename,
            expectedState: $claim,
            replacementState: $pendingState,
            expectedBootId: destinationFenceBootId(),
            commands: [
                'printf %s '.escapeshellarg('mutation-applied').' > '.escapeshellarg($sentinelPath),
            ],
            completionCommands: destinationFenceFileAttestation($sentinelPath, 'mutation-applied'),
        ));

        expect($interrupted->getExitCode())->toBe(87)
            ->and(file_get_contents($sentinelPath))->toBe('mutation-applied')
            ->and(file_exists($journalPath))->toBeTrue()
            ->and(BlueGreenProxyState::parse(file_get_contents($statePath))->serialize())
            ->toBe($claim->serialize());

        $journalBeforeAttestation = file_get_contents($journalPath);
        $stateBeforeAttestation = file_get_contents($statePath);
        file_put_contents($sentinelPath, 'replay-sentinel');

        $pendingAttestation = failedDestinationFenceCommand(
            $writer->attestStateCommandFor($proxyPath, $managedFilename, $claim),
        );
        expect($pendingAttestation->getExitCode())->not->toBe(0)
            ->and($pendingAttestation->getErrorOutput())
            ->toContain(WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT)
            ->and(file_get_contents($sentinelPath))->toBe('replay-sentinel')
            ->and(file_exists($journalPath))->toBeTrue()
            ->and(file_get_contents($journalPath))->toBe($journalBeforeAttestation)
            ->and(file_get_contents($statePath))->toBe($stateBeforeAttestation);

        $conditionalSentinelPath = $proxyPath.'/attestation-conditional-sentinel';
        file_put_contents($conditionalSentinelPath, 'unselected');
        $conditional = failedDestinationFenceCommand(implode("\n", [
            'if (',
            $writer->attestStateCommandFor($proxyPath, $managedFilename, $claim),
            '); then',
            '  printf %s success-branch > '.escapeshellarg($conditionalSentinelPath),
            'else',
            '  printf %s failure-branch > '.escapeshellarg($conditionalSentinelPath),
            'fi',
        ]));

        expect($conditional->isSuccessful())->toBeTrue($conditional->getErrorOutput())
            ->and($conditional->getErrorOutput())
            ->toContain(WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT)
            ->and(file_get_contents($conditionalSentinelPath))->toBe('failure-branch')
            ->and(file_get_contents($sentinelPath))->toBe('replay-sentinel')
            ->and(file_get_contents($journalPath))->toBe($journalBeforeAttestation)
            ->and(file_get_contents($statePath))->toBe($stateBeforeAttestation);

        $outerContinuationPath = $proxyPath.'/attestation-outer-continuation';
        $braceGroup = failedDestinationFenceCommand(implode("\n", [
            '{',
            $writer->attestStateCommandFor($proxyPath, $managedFilename, $claim),
            '}',
            'printf %s outer-script-continued > '.escapeshellarg($outerContinuationPath),
        ]));

        expect($braceGroup->isSuccessful())->toBeFalse()
            ->and($braceGroup->getErrorOutput())
            ->toContain(WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT)
            ->and(file_exists($outerContinuationPath))->toBeFalse()
            ->and(file_get_contents($sentinelPath))->toBe('replay-sentinel')
            ->and(file_get_contents($journalPath))->toBe($journalBeforeAttestation)
            ->and(file_get_contents($statePath))->toBe($stateBeforeAttestation);
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('reports a leftover container-mutation journal as pending when the next fenced mutation retries', function (): void {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-container-journal-retry-'.bin2hex(random_bytes(8));
    $filesystem->mkdir($proxyPath.'/dynamic', 0700);

    try {
        $configuration = compileDestinationFencedBlueGreenConfiguration(
            epoch: 1,
            activeColor: BlueGreenDeploymentColor::BLUE,
            deploymentUuid: 'deployment-journal-retry',
            containerId: '0123456789abcdef',
            operationId: 'journal-retry-owner',
        );
        $managedFilename = $configuration->managedFilename;
        $writer = destinationFenceWriter();
        $rollbackKey = new BlueGreenProxyRollbackKey('journal-retry-owner', null, $configuration->state);
        runDestinationFenceCommand($writer->commandFor(
            $proxyPath,
            $configuration,
            $rollbackKey,
            destinationFenceBootId(),
        ));

        $pendingState = $configuration->state->withMutationOwner('journal-retry-pending');
        $sentinelPath = $proxyPath.'/journal-retry-sentinel';
        file_put_contents($sentinelPath, 'before-mutation');
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
        $mutationArguments = [
            'proxyPath' => $proxyPath,
            'managedFilename' => $managedFilename,
            'expectedState' => $configuration->state,
            'replacementState' => $pendingState,
            'expectedBootId' => destinationFenceBootId(),
            'commands' => [
                'printf %s '.escapeshellarg('mutation-applied').' > '.escapeshellarg($sentinelPath),
            ],
            'completionCommands' => destinationFenceFileAttestation($sentinelPath, 'mutation-applied'),
        ];
        $interrupted = failedDestinationFenceCommand($crashingWriter->fencedDestinationCommandFor(...$mutationArguments));
        $statePath = $writer->statePath($proxyPath, $managedFilename);
        $journalPath = $writer->containerMutationJournalPath($proxyPath, $managedFilename);

        expect($interrupted->getExitCode())->toBe(87)
            ->and(file_exists($journalPath))->toBeTrue();

        $journalBeforeRetry = file_get_contents($journalPath);
        $stateBeforeRetry = file_get_contents($statePath);
        file_put_contents($sentinelPath, 'retry-sentinel');

        // A bounded retirement retry regenerates the same fenced mutation. The
        // leftover journal must surface as the canonical pending marker so the
        // caller can route the retry through journal recovery, not as an
        // anonymous failure that reads as an ambiguous mutation result.
        $retry = failedDestinationFenceCommand($writer->fencedDestinationCommandFor(...$mutationArguments));

        expect($retry->getExitCode())->toBe(75)
            ->and($retry->getErrorOutput())
            ->toContain(WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT)
            ->and(file_get_contents($sentinelPath))->toBe('retry-sentinel')
            ->and(file_get_contents($journalPath))->toBe($journalBeforeRetry)
            ->and(file_get_contents($statePath))->toBe($stateBeforeRetry);
    } finally {
        $filesystem->remove($proxyPath);
    }
});

it('reports an unfinished journal from a different boot as pending rather than a boot assertion failure', function (): void {
    $filesystem = new Filesystem;
    $proxyPath = sys_get_temp_dir().'/coolify-blue-green-container-journal-boot-'.bin2hex(random_bytes(8));
    $filesystem->mkdir($proxyPath.'/dynamic', 0700);

    try {
        $configuration = compileDestinationFencedBlueGreenConfiguration(
            epoch: 1,
            activeColor: BlueGreenDeploymentColor::BLUE,
            deploymentUuid: 'deployment-journal-boot',
            containerId: '0123456789abcdef',
            operationId: 'journal-boot-owner',
        );
        $managedFilename = $configuration->managedFilename;
        $writer = destinationFenceWriter(destinationFenceBootId());
        $rollbackKey = new BlueGreenProxyRollbackKey('journal-boot-owner', null, $configuration->state);
        runDestinationFenceCommand($writer->commandFor(
            $proxyPath,
            $configuration,
            $rollbackKey,
            destinationFenceBootId(),
        ));

        $pendingState = $configuration->state->withMutationOwner('journal-boot-pending');
        $sentinelPath = $proxyPath.'/journal-boot-sentinel';
        file_put_contents($sentinelPath, 'before-mutation');
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
        $journalBootId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $interrupted = failedDestinationFenceCommand($crashingWriter->fencedDestinationCommandFor(
            proxyPath: $proxyPath,
            managedFilename: $managedFilename,
            expectedState: $configuration->state,
            replacementState: $pendingState,
            expectedBootId: $journalBootId,
            commands: [
                'printf %s '.escapeshellarg('mutation-applied').' > '.escapeshellarg($sentinelPath),
            ],
            completionCommands: destinationFenceFileAttestation($sentinelPath, 'mutation-applied'),
        ));
        $statePath = $writer->statePath($proxyPath, $managedFilename);
        $journalPath = $writer->containerMutationJournalPath($proxyPath, $managedFilename);

        expect($interrupted->getExitCode())->toBe(87)
            ->and(file_get_contents($sentinelPath))->toBe('mutation-applied')
            ->and(file_exists($journalPath))->toBeTrue();

        $journalBeforeAttestation = file_get_contents($journalPath);
        $stateBeforeAttestation = file_get_contents($statePath);
        file_put_contents($sentinelPath, 'replay-sentinel');

        $pendingAttestation = failedDestinationFenceCommand(
            $writer->attestStateCommandFor($proxyPath, $managedFilename, $configuration->state),
        );

        expect($pendingAttestation->getExitCode())->not->toBe(0)
            ->and($pendingAttestation->getErrorOutput())
            ->toContain(WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT)
            ->and(file_get_contents($sentinelPath))->toBe('replay-sentinel')
            ->and(file_get_contents($journalPath))->toBe($journalBeforeAttestation)
            ->and(file_get_contents($statePath))->toBe($stateBeforeAttestation);
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
        expectedJournalBootId: destinationFenceBootId(),
        allowPendingSameBootJournal: true,
        expectedState: $expectedState,
        replacementState: $replacementState,
        expectedMutationSha256: [str_repeat('d', 64)],
        expectedCompletionSha256: str_repeat('e', 64),
        backendPorts: [8080],
        targetContainerName: 'app-fenced-blue',
        targetContainerId: str_repeat('a', 64),
        applicationId: 17,
        inactiveDeploymentUuid: 'retirement-inactive',
        inactiveColor: BlueGreenDeploymentColor::BLUE,
        inactiveRoutingRevision: 1,
    );

    expect($command)->toContain('test "$container_journal_expected_state" = '.escapeshellarg(base64_encode($expectedState->serialize())))
        ->and($command)->toContain('test "$container_journal_replacement_state" = '.escapeshellarg(base64_encode($replacementState->serialize())))
        // The drain has more than one legitimate preimage, so the mutation
        // checksum is matched against the reconstructed candidate set and the
        // journal's own recorded value selects among them.
        ->and($command)->toContain('case "$container_journal_mutation_checksum" in '.escapeshellarg(str_repeat('d', 64)).') ;; *) exit 1 ;; esac')
        ->and($command)->toContain('test "$container_journal_completion_checksum" = '.escapeshellarg(str_repeat('e', 64)))
        ->and($command)->toContain('test "$container_journal_expected_boot_id" = '.escapeshellarg(destinationFenceBootId()))
        ->and($command)->toContain('coolify.blueGreen.deploymentUuid=retirement-inactive')
        ->and($command)->toContain('case "$container_journal_target_runtime_status" in')
        ->and($command)->toContain(
            ContainerStatusTypes::EXITED->value.'|'.ContainerStatusTypes::DEAD->value.') container_journal_target_status=stopped',
        )
        ->and($command)->toContain('    *) exit 1 ;;')
        ->and($command)->not->toContain(
            'if [ "$container_journal_target_runtime_status" = running ]; then container_journal_target_status=running; else container_journal_target_status=stopped; fi',
        )
        ->and($command)->not->toContain('sh "$container_journal_mutation_decoded"')
        ->and($command)->not->toContain('sh "$container_journal_completion_decoded"');

    // One destination's drain has two legitimate preimages, so the assertion is
    // an alternation. Nothing else pins the separator: rendering it with
    // anything but '|' emits a syntactically invalid case statement that no
    // other test would catch, and the proxy host would reject every recovery
    // this profile exists to perform.
    $twoCandidateCommand = $writer->inspectStaleInactiveRetirementContainerMutationJournalCommandFor(
        proxyPath: '/data/coolify/proxy',
        stateId: 75,
        expectedCurrentBootId: destinationFenceBootId(),
        expectedJournalBootId: destinationFenceBootId(),
        allowPendingSameBootJournal: true,
        expectedState: $expectedState,
        replacementState: $replacementState,
        expectedMutationSha256: [str_repeat('c', 64), str_repeat('d', 64)],
        expectedCompletionSha256: str_repeat('e', 64),
        backendPorts: [8080],
        targetContainerName: 'app-fenced-blue',
        targetContainerId: str_repeat('a', 64),
        applicationId: 17,
        inactiveDeploymentUuid: 'retirement-inactive',
        inactiveColor: BlueGreenDeploymentColor::BLUE,
        inactiveRoutingRevision: 1,
    );

    expect($twoCandidateCommand)->toContain(
        'case "$container_journal_mutation_checksum" in '
            .escapeshellarg(str_repeat('c', 64)).'|'.escapeshellarg(str_repeat('d', 64))
            .') ;; *) exit 1 ;; esac',
    );

    $quarantineCommand = $writer->quarantineStaleInactiveRetirementContainerMutationJournalCommandFor(
        proxyPath: '/data/coolify/proxy',
        stateId: 75,
        expectedCurrentBootId: destinationFenceBootId(),
        expectedJournalBootId: destinationFenceBootId(),
        allowPendingSameBootJournal: true,
        expectedState: $expectedState,
        replacementState: $replacementState,
        expectedMutationSha256: [str_repeat('d', 64)],
        expectedCompletionSha256: str_repeat('e', 64),
        backendPorts: [8080],
        targetContainerName: 'app-fenced-blue',
        targetContainerId: str_repeat('a', 64),
        applicationId: 17,
        inactiveDeploymentUuid: 'retirement-inactive',
        inactiveColor: BlueGreenDeploymentColor::BLUE,
        inactiveRoutingRevision: 1,
        expectedJournalSha256: str_repeat('f', 64),
    );
    $targetStatusAttestation = 'container_journal_target_runtime_status=$(docker inspect --format=';
    $routeStatusAttestation = 'container_journal_route_state=$(base64 < "$container_journal_state_path"';
    $routeReplacement = 'durable_remote_replace "$container_journal_state_stage"';
    $manifestReplacement = 'durable_remote_replace "$container_journal_manifest_stage"';
    $journalArchive = 'durable_remote_replace "$container_journal_path" "$container_journal_archive_path"';
    $activeConnectionFence = 'test "$container_journal_active_connections" -eq 0';
    $activeConnectionArchiveFence = implode("\n", [
        '    '.$activeConnectionFence,
        '  fi',
        '  durable_remote_replace "$container_journal_path" "$container_journal_archive_path" "$container_journal_state_directory"',
    ]);
    $firstTargetStatusAttestation = strpos($quarantineCommand, $targetStatusAttestation);
    $routeReplacementPosition = strpos($quarantineCommand, $routeReplacement);
    $lastTargetStatusAttestation = strrpos($quarantineCommand, $targetStatusAttestation);
    $manifestReplacementPosition = strpos($quarantineCommand, $manifestReplacement);
    $journalArchivePosition = strpos($quarantineCommand, $journalArchive);
    $firstActiveConnectionFence = strpos($quarantineCommand, $activeConnectionFence);
    $lastActiveConnectionFence = strrpos($quarantineCommand, $activeConnectionFence);
    preg_match_all(
        '/'.preg_quote($targetStatusAttestation, '/').'/',
        $quarantineCommand,
        $targetStatusMatches,
        PREG_OFFSET_CAPTURE,
    );
    $targetStatusPositions = array_column($targetStatusMatches[0], 1);

    expect($targetStatusPositions)->toHaveCount(5)
        ->and(substr_count($quarantineCommand, $routeStatusAttestation))->toBe(2)
        ->and(substr_count($quarantineCommand, $activeConnectionFence))->toBe(2)
        ->and($quarantineCommand)->toContain($activeConnectionArchiveFence)
        ->and($firstTargetStatusAttestation)->toBeInt()->toBeLessThan($routeReplacementPosition)
        ->and($lastTargetStatusAttestation)->toBeInt()->toBeGreaterThan($routeReplacementPosition)
        ->and($targetStatusPositions[1])->toBeLessThan($firstActiveConnectionFence)
        ->and($firstActiveConnectionFence)->toBeInt()->toBeLessThan($manifestReplacementPosition)
        ->and($manifestReplacementPosition)->toBeInt()->toBeLessThan($targetStatusPositions[2])
        ->and($targetStatusPositions[2])->toBeLessThan($lastActiveConnectionFence)
        ->and($lastActiveConnectionFence)->toBeInt()->toBeLessThan($journalArchivePosition)
        ->and($journalArchivePosition)->toBeInt()->toBeLessThan($targetStatusPositions[3])
        ->and($targetStatusPositions[3])->toBeLessThan($routeReplacementPosition)
        ->and($routeReplacementPosition)->toBeInt()->toBeLessThan($targetStatusPositions[4]);
});
