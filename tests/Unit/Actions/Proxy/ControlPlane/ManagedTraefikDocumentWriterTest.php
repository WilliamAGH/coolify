<?php

use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentMutation;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriter;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriterAuthority;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function managedTraefikDocumentMutation(
    string $root,
    string $operationId,
    int $revision,
    string $replacementBytes,
    ?ManagedTraefikDocumentMutation $predecessor = null,
    string $filename = 'coolify-control-plane.yaml',
): ManagedTraefikDocumentMutation {
    return new ManagedTraefikDocumentMutation(
        dynamicDirectory: $root.'/dynamic',
        stateDirectory: $root.'/state',
        filename: $filename,
        operationId: $operationId,
        revision: $revision,
        expectedSha256: $predecessor?->replacementSha256(),
        expectedOperationId: $predecessor?->operationId,
        expectedRevision: $predecessor?->revision,
        replacementBytes: $replacementBytes,
    );
}

function runManagedTraefikDocumentCommand(string $command, array $environment = []): Process
{
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(30);
    if ($environment !== []) {
        $process->setEnv($environment);
    }
    $process->run();

    return $process;
}

function managedTraefikDocumentRoot(): string
{
    return sys_get_temp_dir().'/coolify-managed-traefik-document-'.bin2hex(random_bytes(8));
}

function managedTraefikDocumentWriterAuthority(
    ManagedTraefikDocumentMutation $mutation,
    int $epoch,
    string $member = 'green',
    string $identity = 'a',
): ManagedTraefikDocumentWriterAuthority {
    return new ManagedTraefikDocumentWriterAuthority(
        epoch: $epoch,
        operationId: $mutation->operationId,
        member: $member,
        containerId: str_repeat($identity, 64),
        containerName: 'coolify-writer-'.$member,
        imageId: 'sha256:'.str_repeat($identity, 64),
        dynamicRevision: $mutation->revision,
        dynamicSha256: $mutation->replacementSha256(),
    );
}

function managedTraefikDocumentRollbackAuthority(
    ManagedTraefikDocumentMutation $predecessor,
    ManagedTraefikDocumentMutation $rolledBackMutation,
    ManagedTraefikDocumentWriterAuthority $predecessorAuthority,
): ManagedTraefikDocumentWriterAuthority {
    return new ManagedTraefikDocumentWriterAuthority(
        epoch: $predecessorAuthority->epoch + 1,
        operationId: $rolledBackMutation->operationId,
        member: $predecessorAuthority->member,
        containerId: $predecessorAuthority->containerId,
        containerName: $predecessorAuthority->containerName,
        imageId: $predecessorAuthority->imageId,
        dynamicRevision: $predecessor->revision,
        dynamicSha256: $predecessor->replacementSha256(),
    );
}

function managedTraefikDocumentEnrollmentRollbackAuthority(
    ManagedTraefikDocumentMutation $mutation,
    ManagedTraefikDocumentWriterAuthority $replacementAuthority,
): ManagedTraefikDocumentWriterAuthority {
    return new ManagedTraefikDocumentWriterAuthority(
        epoch: $replacementAuthority->epoch + 1,
        operationId: $mutation->operationId,
        member: $replacementAuthority->member,
        containerId: $replacementAuthority->containerId,
        containerName: $replacementAuthority->containerName,
        imageId: $replacementAuthority->imageId,
        dynamicRevision: $mutation->revision,
        dynamicSha256: $mutation->expectedSha256 ?? hash('sha256', ''),
    );
}

/** @return array<string, string> */
function managedTraefikDocumentDockerEnvironment(
    string $root,
    ManagedTraefikDocumentWriterAuthority $targetAuthority,
    string $visibleContainerIds,
    bool $targetRunning = true,
    ?string $targetImageId = null,
): array {
    $binDirectory = $root.'/bin';
    mkdir($binDirectory, 0700, true);
    $dockerPath = $binDirectory.'/docker';
    file_put_contents($dockerPath, <<<'SH'
#!/bin/sh
set -eu

if [ "$1" = container ] && [ "$2" = ls ]; then
    if [ -n "$COOLIFY_TEST_DOCKER_IDS" ]; then
        printf '%s\n' "$COOLIFY_TEST_DOCKER_IDS"
    fi
    exit 0
fi

if [ "$1" = inspect ]; then
    inspected=
    for argument in "$@"; do
        inspected=$argument
    done
    [ "$inspected" = "$COOLIFY_TEST_TARGET_CONTAINER_ID" ] || exit 1
    printf '%s|/%s|%s|%s\n' \
        "$COOLIFY_TEST_TARGET_CONTAINER_ID" \
        "$COOLIFY_TEST_TARGET_CONTAINER_NAME" \
        "$COOLIFY_TEST_TARGET_IMAGE_ID" \
        "$COOLIFY_TEST_TARGET_RUNNING"
    exit 0
fi

exit 1
SH);
    chmod($dockerPath, 0700);

    return [
        'PATH' => $binDirectory.PATH_SEPARATOR.(getenv('PATH') ?: '/usr/bin:/bin'),
        'COOLIFY_TEST_DOCKER_IDS' => $visibleContainerIds,
        'COOLIFY_TEST_TARGET_CONTAINER_ID' => $targetAuthority->containerId,
        'COOLIFY_TEST_TARGET_CONTAINER_NAME' => $targetAuthority->containerName,
        'COOLIFY_TEST_TARGET_IMAGE_ID' => $targetImageId ?? $targetAuthority->imageId,
        'COOLIFY_TEST_TARGET_RUNNING' => $targetRunning ? 'true' : 'false',
    ];
}

/**
 * @return array{
 *     writer: ManagedTraefikDocumentWriter,
 *     orphaned: ManagedTraefikDocumentMutation,
 *     mutation: ManagedTraefikDocumentMutation,
 *     correctedMutation: ManagedTraefikDocumentMutation,
 *     orphanedAuthority: ManagedTraefikDocumentWriterAuthority,
 *     targetAuthority: ManagedTraefikDocumentWriterAuthority,
 *     correctedPredecessorBytes: string,
 *     command: string,
 *     staleCommand: string,
 *     environment: array<string, string>,
 *     orphanedArtifactBytes: string
 * }
 */
function managedTraefikDocumentOrphanSupersessionFixture(string $root): array
{
    $writer = new ManagedTraefikDocumentWriter;
    $orphaned = managedTraefikDocumentMutation(
        root: $root,
        operationId: 'orphaned-initial-enrollment',
        revision: 1,
        replacementBytes: "http:\n  routers:\n    orphaned: {}\n",
    );
    $orphanedWrite = runManagedTraefikDocumentCommand($writer->writeCommandFor($orphaned));
    if (! $orphanedWrite->isSuccessful()) {
        throw new RuntimeException($orphanedWrite->getErrorOutput());
    }
    $orphanedArtifactBytes = file_get_contents($orphaned->rollbackArtifactPath());
    if (! is_string($orphanedArtifactBytes)) {
        throw new RuntimeException('The orphaned rollback artifact fixture is missing.');
    }

    $orphanedAuthority = managedTraefikDocumentWriterAuthority(
        $orphaned,
        epoch: 1,
        member: 'orphaned',
        identity: 'c',
    );
    file_put_contents($orphaned->writerAuthorityPath(), $orphanedAuthority->toJson());
    chmod($orphaned->writerAuthorityPath(), 0640);

    $correctedPredecessorBytes = "http:\n  routers:\n    generic-overwrite: {}\n";
    file_put_contents($orphaned->documentPath(), $correctedPredecessorBytes);
    $mutation = new ManagedTraefikDocumentMutation(
        dynamicDirectory: $orphaned->dynamicDirectory,
        stateDirectory: $orphaned->stateDirectory,
        filename: $orphaned->filename,
        operationId: 'replacement-enrollment',
        revision: 1,
        expectedSha256: hash('sha256', $correctedPredecessorBytes),
        expectedOperationId: null,
        expectedRevision: null,
        replacementBytes: "http:\n  routers:\n    replacement: {}\n",
    );
    $correctedMutation = $mutation;
    $targetAuthority = managedTraefikDocumentWriterAuthority(
        $mutation,
        epoch: 1,
        member: 'replacement',
        identity: 'd',
    );
    $command = $writer->supersedeOrphanedInitialEnrollmentAuthorityCommandFor(
        mutation: $mutation,
        correctedPredecessorBytes: $correctedPredecessorBytes,
        orphanedAuthority: $orphanedAuthority,
        targetAuthority: $targetAuthority,
        transportLfRepairProvenance: true,
    );
    $staleSuccessor = managedTraefikDocumentMutation(
        root: $root,
        operationId: 'captured-orphaned-successor',
        revision: 2,
        replacementBytes: "http:\n  routers:\n    stale: {}\n",
        predecessor: $orphaned,
    );

    return [
        'writer' => $writer,
        'orphaned' => $orphaned,
        'mutation' => $mutation,
        'correctedMutation' => $correctedMutation,
        'orphanedAuthority' => $orphanedAuthority,
        'targetAuthority' => $targetAuthority,
        'correctedPredecessorBytes' => $correctedPredecessorBytes,
        'command' => $command,
        'staleCommand' => $writer->writeCommandForRequiringAuthority(
            $staleSuccessor,
            $orphanedAuthority,
            allowBootstrap: false,
        ),
        'environment' => managedTraefikDocumentDockerEnvironment(
            root: $root,
            targetAuthority: $targetAuthority,
            visibleContainerIds: $targetAuthority->containerId,
        ),
        'orphanedArtifactBytes' => $orphanedArtifactBytes,
    ];
}

it('atomically writes one managed file-provider document and replays its owner idempotently', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $mutation = managedTraefikDocumentMutation(
            root: $root,
            operationId: 'control-plane-enrollment',
            revision: 1,
            replacementBytes: "http:\n  routers: {}\n",
        );

        $first = runManagedTraefikDocumentCommand($writer->writeCommandFor($mutation));
        $second = runManagedTraefikDocumentCommand($writer->writeCommandFor($mutation));

        expect($first->isSuccessful())->toBeTrue()
            ->and(trim($first->getOutput()))->toBe(ManagedTraefikDocumentWriter::APPLIED_OUTPUT)
            ->and($second->isSuccessful())->toBeTrue()
            ->and(trim($second->getOutput()))->toBe(ManagedTraefikDocumentWriter::APPLIED_OUTPUT)
            ->and(file_get_contents($mutation->documentPath()))->toBe($mutation->replacementBytes)
            ->and(file_get_contents($mutation->sidecarPath()))->toBe($mutation->replacementSidecar())
            ->and(file_get_contents($mutation->rollbackArtifactPath()))->toContain(
                'coolify-managed-traefik-document-rollback-v1',
                'control-plane-enrollment',
                $mutation->replacementSha256(),
            )
            ->and(file_exists($mutation->journalPath()))->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
});

it('does not reap owned temporary files for a different managed filename', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $filesystem->mkdir([$root.'/dynamic', $root.'/state'], 0700);
        $writer = new ManagedTraefikDocumentWriter;
        $mutation = managedTraefikDocumentMutation(
            root: $root,
            operationId: 'control-plane-scoped-cleanup',
            revision: 1,
            replacementBytes: "http:\n  routers: {}\n",
        );
        $otherFilename = 'secondary-control-plane.yaml';
        $otherTemporaryFiles = [
            $root.'/dynamic/.managed-traefik-document.'.$otherFilename.'.active',
            $root.'/state/.managed-traefik-journal.'.$otherFilename.'.active',
            $root.'/state/.managed-traefik-artifact.'.$otherFilename.'.active',
        ];
        foreach ($otherTemporaryFiles as $temporaryFile) {
            file_put_contents($temporaryFile, 'active-other-writer');
            chmod($temporaryFile, 0600);
        }

        $result = runManagedTraefikDocumentCommand($writer->writeCommandFor($mutation));

        expect($result->isSuccessful())->toBeTrue($result->getErrorOutput());
        foreach ($otherTemporaryFiles as $temporaryFile) {
            expect(file_get_contents($temporaryFile))->toBe('active-other-writer');
        }
    } finally {
        $filesystem->remove($root);
    }
});

it('rejects hard-linked managed state and preserves the stable mutation lock inode', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $first = managedTraefikDocumentMutation(
            root: $root,
            operationId: 'control-plane-hard-link-first',
            revision: 1,
            replacementBytes: "http:\n  routers:\n    first: {}\n",
        );
        $second = managedTraefikDocumentMutation(
            root: $root,
            operationId: 'control-plane-hard-link-second',
            revision: 2,
            replacementBytes: "http:\n  routers:\n    second: {}\n",
            predecessor: $first,
        );

        $firstWrite = runManagedTraefikDocumentCommand($writer->writeCommandFor($first));
        $lockInode = fileinode($first->lockPath());
        $hardLink = $root.'/state/sidecar-hardlink';
        link($first->sidecarPath(), $hardLink);
        $rejected = runManagedTraefikDocumentCommand($writer->writeCommandFor($second));
        $documentAfterRejection = file_get_contents($first->documentPath());
        $sidecarAfterRejection = file_get_contents($first->sidecarPath());
        unlink($hardLink);
        $recovered = runManagedTraefikDocumentCommand($writer->writeCommandFor($second));
        clearstatcache(true, $first->lockPath());

        expect($firstWrite->isSuccessful())->toBeTrue()
            ->and($rejected->isSuccessful())->toBeFalse()
            ->and($documentAfterRejection)->toBe($first->replacementBytes)
            ->and($sidecarAfterRejection)->toBe($first->replacementSidecar())
            ->and($recovered->isSuccessful())->toBeTrue()
            ->and(file_get_contents($first->documentPath()))->toBe($second->replacementBytes)
            ->and(fileinode($first->lockPath()))->toBe($lockInode)
            ->and(fileperms($first->lockPath()) & 0777)->toBe(0600);
    } finally {
        $filesystem->remove($root);
    }
});

it('recovers an owned candidate fsync crash without publishing partial state or retaining its temp file', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $mutation = managedTraefikDocumentMutation(
            root: $root,
            operationId: 'control-plane-candidate-fsync-crash',
            revision: 1,
            replacementBytes: "http:\n  routers:\n    candidate: {}\n",
        );
        $command = $writer->writeCommandFor($mutation);

        $crashed = runManagedTraefikDocumentCommand(
            $command,
            ['COOLIFY_DURABLE_REMOTE_ARTIFACT_CRASH_AFTER_CANDIDATE_FSYNC' => '1'],
        );
        $crashTemps = glob($mutation->stateDirectory.'/.managed-traefik-artifact.*') ?: [];
        $documentExistsAfterCrash = file_exists($mutation->documentPath());
        $replayed = runManagedTraefikDocumentCommand($command);
        $recoveredTemps = glob($mutation->stateDirectory.'/.managed-traefik-artifact.*') ?: [];

        expect($crashed->isSuccessful())->toBeFalse()
            ->and($crashed->getExitCode())->toBe(75)
            ->and($documentExistsAfterCrash)->toBeFalse()
            ->and($crashTemps)->toHaveCount(1)
            ->and($replayed->isSuccessful())->toBeTrue()
            ->and($recoveredTemps)->toBe([])
            ->and(file_get_contents($mutation->documentPath()))->toBe($mutation->replacementBytes)
            ->and(file_get_contents($mutation->sidecarPath()))->toBe($mutation->replacementSidecar());
    } finally {
        $filesystem->remove($root);
    }
});

it('replays after a journal unlink crash with the exact routed document already durable', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $first = managedTraefikDocumentMutation(
            root: $root,
            operationId: 'control-plane-unlink-first',
            revision: 1,
            replacementBytes: "http:\n  routers:\n    first: {}\n",
        );
        $second = managedTraefikDocumentMutation(
            root: $root,
            operationId: 'control-plane-unlink-second',
            revision: 2,
            replacementBytes: "http:\n  routers:\n    second: {}\n",
            predecessor: $first,
        );

        $firstWrite = runManagedTraefikDocumentCommand($writer->writeCommandFor($first));
        $command = $writer->writeCommandFor($second);
        $crashed = runManagedTraefikDocumentCommand(
            $command,
            ['COOLIFY_DURABLE_REMOTE_ARTIFACT_CRASH_AFTER_UNLINK' => '1'],
        );
        $replayed = runManagedTraefikDocumentCommand($command);

        expect($firstWrite->isSuccessful())->toBeTrue()
            ->and($crashed->isSuccessful())->toBeFalse()
            ->and($crashed->getExitCode())->toBe(75)
            ->and(file_exists($second->journalPath()))->toBeFalse()
            ->and(file_get_contents($second->documentPath()))->toBe($second->replacementBytes)
            ->and(file_get_contents($second->sidecarPath()))->toBe($second->replacementSidecar())
            ->and($replayed->isSuccessful())->toBeTrue()
            ->and(trim($replayed->getOutput()))->toBe(ManagedTraefikDocumentWriter::APPLIED_OUTPUT);
    } finally {
        $filesystem->remove($root);
    }
});

it('adopts one exact unmanaged predecessor checksum before installing its first sidecar identity', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $filesystem->mkdir($root.'/dynamic', 0700);
        $legacyBytes = "http:\n  routers:\n    legacy: {}\n";
        $documentPath = $root.'/dynamic/coolify-control-plane.yaml';
        file_put_contents($documentPath, $legacyBytes);
        $mutation = new ManagedTraefikDocumentMutation(
            dynamicDirectory: $root.'/dynamic',
            stateDirectory: $root.'/state',
            filename: 'coolify-control-plane.yaml',
            operationId: 'control-plane-adoption',
            revision: 1,
            expectedSha256: hash('sha256', $legacyBytes),
            expectedOperationId: null,
            expectedRevision: null,
            replacementBytes: "http:\n  routers:\n    managed: {}\n",
        );

        $result = runManagedTraefikDocumentCommand((new ManagedTraefikDocumentWriter)->writeCommandFor($mutation));

        expect($result->isSuccessful())->toBeTrue()
            ->and(file_get_contents($mutation->documentPath()))->toBe($mutation->replacementBytes)
            ->and(file_get_contents($mutation->sidecarPath()))->toBe($mutation->replacementSidecar())
            ->and(file_get_contents($mutation->rollbackArtifactPath()))->toContain(base64_encode($legacyBytes));
    } finally {
        $filesystem->remove($root);
    }
});

it('supersedes one exact orphaned initial-enrollment authority without an authority or route absence window', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $fixture = managedTraefikDocumentOrphanSupersessionFixture($root);
        expect(fn () => $fixture['writer']->supersedeOrphanedInitialEnrollmentAuthorityCommandFor(
            mutation: $fixture['mutation'],
            correctedPredecessorBytes: $fixture['correctedPredecessorBytes'],
            orphanedAuthority: $fixture['orphanedAuthority'],
            targetAuthority: $fixture['targetAuthority'],
            transportLfRepairProvenance: false,
        ))->toThrow(InvalidArgumentException::class, 'transport-LF');
        $result = runManagedTraefikDocumentCommand($fixture['command'], $fixture['environment']);
        $newRollbackArtifact = file_get_contents($fixture['mutation']->rollbackArtifactPath());

        expect($result->isSuccessful())->toBeTrue($result->getErrorOutput())
            ->and(trim($result->getOutput()))->toBe(ManagedTraefikDocumentWriter::ORPHANED_ENROLLMENT_AUTHORITY_SUPERSEDED_OUTPUT)
            ->and(file_get_contents($fixture['mutation']->documentPath()))->toBe($fixture['mutation']->replacementBytes)
            ->and(file_get_contents($fixture['mutation']->sidecarPath()))->toBe($fixture['mutation']->replacementSidecar())
            ->and(file_get_contents($fixture['mutation']->writerAuthorityPath()))->toBe($fixture['targetAuthority']->toJson())
            ->and(file_exists($fixture['orphaned']->rollbackArtifactPath()))->toBeFalse()
            ->and($newRollbackArtifact)->toBe($fixture['writer']->rollbackArtifactFor(
                $fixture['correctedMutation'],
                $fixture['correctedPredecessorBytes'],
            ))
            ->and($newRollbackArtifact)->toContain("\nabsent\n".base64_encode($fixture['correctedPredecessorBytes'])."\n")
            ->and(file_exists($fixture['mutation']->journalPath()))->toBeFalse();

        $staleWriter = runManagedTraefikDocumentCommand($fixture['staleCommand']);
        expect($staleWriter->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['mutation']->documentPath()))->toBe($fixture['mutation']->replacementBytes)
            ->and(file_get_contents($fixture['mutation']->writerAuthorityPath()))->toBe($fixture['targetAuthority']->toJson());

        $rolledBackAuthority = managedTraefikDocumentEnrollmentRollbackAuthority(
            $fixture['correctedMutation'],
            $fixture['targetAuthority'],
        );
        $rolledBack = runManagedTraefikDocumentCommand($fixture['writer']->rollbackEnrollmentCommandFor(
            $fixture['correctedMutation'],
            $fixture['targetAuthority'],
            $rolledBackAuthority,
        ));

        expect($rolledBack->isSuccessful())->toBeTrue($rolledBack->getErrorOutput())
            ->and(file_get_contents($fixture['mutation']->documentPath()))->toBe($fixture['correctedPredecessorBytes'])
            ->and(file_exists($fixture['mutation']->sidecarPath()))->toBeFalse()
            ->and(file_get_contents($fixture['mutation']->writerAuthorityPath()))->toBe($rolledBackAuthority->toJson());

        $finalized = runManagedTraefikDocumentCommand(
            $fixture['writer']->finalizeEnrollmentRollbackCommandFor(
                $fixture['correctedMutation'],
                $rolledBackAuthority,
            ),
        );
        expect($finalized->isSuccessful())->toBeTrue($finalized->getErrorOutput())
            ->and(trim($finalized->getOutput()))->toBe(ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT)
            ->and(file_get_contents($fixture['mutation']->documentPath()))->toBe($fixture['correctedPredecessorBytes'])
            ->and(file_exists($fixture['mutation']->stateDirectory))->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
});

it('replays orphan-authority supersession after every durable mutation boundary', function (string $crashVariable): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $fixture = managedTraefikDocumentOrphanSupersessionFixture($root);
        $crashed = runManagedTraefikDocumentCommand(
            $fixture['command'],
            [...$fixture['environment'], $crashVariable => '1'],
        );
        $documentCandidates = glob(
            $fixture['mutation']->dynamicDirectory.'/.managed-traefik-document.'.$fixture['mutation']->filename.'.*',
        );
        if ($crashVariable === 'COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_DOCUMENT_CANDIDATE_FSYNC') {
            expect($documentCandidates)->toBeArray()->toHaveCount(1)
                ->and(file_get_contents($documentCandidates[0]))->toBe($fixture['mutation']->replacementBytes)
                ->and(file_exists($fixture['mutation']->journalPath()))->toBeTrue()
                ->and(file_get_contents($fixture['mutation']->documentPath()))->toBe($fixture['correctedPredecessorBytes'])
                ->and(file_get_contents($fixture['mutation']->sidecarPath()))->toBe($fixture['orphaned']->replacementSidecar());
        }
        $replayed = runManagedTraefikDocumentCommand($fixture['command'], $fixture['environment']);

        expect($crashed->isSuccessful())->toBeFalse()
            ->and($crashed->getExitCode())->toBe(75)
            ->and($replayed->isSuccessful())->toBeTrue($replayed->getErrorOutput())
            ->and(trim($replayed->getOutput()))->toBe(ManagedTraefikDocumentWriter::ORPHANED_ENROLLMENT_AUTHORITY_SUPERSEDED_OUTPUT)
            ->and(file_get_contents($fixture['mutation']->documentPath()))->toBe($fixture['mutation']->replacementBytes)
            ->and(file_get_contents($fixture['mutation']->sidecarPath()))->toBe($fixture['mutation']->replacementSidecar())
            ->and(file_get_contents($fixture['mutation']->writerAuthorityPath()))->toBe($fixture['targetAuthority']->toJson())
            ->and(file_exists($fixture['orphaned']->rollbackArtifactPath()))->toBeFalse()
            ->and(file_exists($fixture['mutation']->journalPath()))->toBeFalse()
            ->and(glob(
                $fixture['mutation']->dynamicDirectory.'/.managed-traefik-document.'.$fixture['mutation']->filename.'.*',
            ))->toBe([]);
    } finally {
        $filesystem->remove($root);
    }
})->with([
    'authority candidate fsync' => 'COOLIFY_DURABLE_REMOTE_ARTIFACT_CRASH_AFTER_CANDIDATE_FSYNC',
    'authority fence' => 'COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_AUTHORITY',
    'forward journal' => 'COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_JOURNAL',
    'document candidate fsync after journal' => 'COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_DOCUMENT_CANDIDATE_FSYNC',
    'document replacement' => 'COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_DOCUMENT',
    'sidecar replacement' => 'COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_SIDECAR',
    'journal unlink' => 'COOLIFY_DURABLE_REMOTE_ARTIFACT_CRASH_AFTER_UNLINK',
    'orphan artifact unlink' => 'COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_ORPHAN_ARTIFACT_UNLINK',
]);

it('fails closed before fencing an orphan authority when any exact compare-and-set or runtime proof drifts', function (string $drift): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $fixture = managedTraefikDocumentOrphanSupersessionFixture($root);
        $environment = $fixture['environment'];

        match ($drift) {
            'document' => file_put_contents($fixture['mutation']->documentPath(), "http:\n  routers:\n    foreign-document: {}\n"),
            'sidecar' => file_put_contents($fixture['mutation']->sidecarPath(), "{}\n"),
            'authority' => file_put_contents($fixture['mutation']->writerAuthorityPath(), "{}\n"),
            'orphan-lineage-artifact' => file_put_contents($fixture['orphaned']->rollbackArtifactPath(), "foreign\n"),
            'extra-rollback-artifact' => file_put_contents(
                $fixture['mutation']->stateDirectory.'/.'.$fixture['mutation']->filename.'.extra-enrollment.r1.rollback',
                "foreign\n",
            ),
            'unknown-state-file' => file_put_contents($fixture['mutation']->stateDirectory.'/foreign-state', "foreign\n"),
            'symlink-state-file' => symlink(
                $fixture['mutation']->documentPath(),
                $fixture['mutation']->stateDirectory.'/foreign-link',
            ),
            'dynamic-candidate-wrong-content' => file_put_contents(
                $fixture['mutation']->dynamicDirectory.'/.managed-traefik-document.'.$fixture['mutation']->filename.'.ABC123',
                "foreign\n",
            ),
            'dynamic-candidate-without-journal' => file_put_contents(
                $fixture['mutation']->dynamicDirectory.'/.managed-traefik-document.'.$fixture['mutation']->filename.'.ABC123',
                $fixture['mutation']->replacementBytes,
            ),
            'dynamic-candidate-symlink' => symlink(
                $fixture['mutation']->documentPath(),
                $fixture['mutation']->dynamicDirectory.'/.managed-traefik-document.'.$fixture['mutation']->filename.'.ABC123',
            ),
            'dynamic-candidate-hardlink' => link(
                $fixture['mutation']->documentPath(),
                $fixture['mutation']->dynamicDirectory.'/.managed-traefik-document.'.$fixture['mutation']->filename.'.ABC123',
            ),
            'dynamic-candidate-unknown-name' => file_put_contents(
                $fixture['mutation']->dynamicDirectory.'/.managed-traefik-document.'.$fixture['mutation']->filename.'.TOO-LONG',
                $fixture['mutation']->replacementBytes,
            ),
            'dynamic-candidate-multiple' => [
                file_put_contents(
                    $fixture['mutation']->dynamicDirectory.'/.managed-traefik-document.'.$fixture['mutation']->filename.'.ABC123',
                    $fixture['mutation']->replacementBytes,
                ),
                file_put_contents(
                    $fixture['mutation']->dynamicDirectory.'/.managed-traefik-document.'.$fixture['mutation']->filename.'.DEF456',
                    $fixture['mutation']->replacementBytes,
                ),
            ],
            'orphan-container-present' => $environment['COOLIFY_TEST_DOCKER_IDS'] = implode("\n", [
                $fixture['targetAuthority']->containerId,
                $fixture['orphanedAuthority']->containerId,
            ]),
            'target-container-stopped' => $environment['COOLIFY_TEST_TARGET_RUNNING'] = 'false',
            'target-image-changed' => $environment['COOLIFY_TEST_TARGET_IMAGE_ID'] = 'sha256:'.str_repeat('e', 64),
        };

        $result = runManagedTraefikDocumentCommand($fixture['command'], $environment);

        expect($result->isSuccessful())->toBeFalse()
            ->and(file_exists($fixture['mutation']->rollbackArtifactPath()))->toBeFalse()
            ->and(file_exists($fixture['mutation']->journalPath()))->toBeFalse();
        if ($drift !== 'authority') {
            expect(file_get_contents($fixture['mutation']->writerAuthorityPath()))->toBe($fixture['orphanedAuthority']->toJson());
        }
        if (in_array($drift, [
            'orphan-lineage-artifact',
            'extra-rollback-artifact',
            'unknown-state-file',
            'symlink-state-file',
            'dynamic-candidate-wrong-content',
            'dynamic-candidate-without-journal',
            'dynamic-candidate-symlink',
            'dynamic-candidate-hardlink',
            'dynamic-candidate-unknown-name',
            'dynamic-candidate-multiple',
        ], true)) {
            expect(file_get_contents($fixture['mutation']->documentPath()))->toBe($fixture['correctedPredecessorBytes'])
                ->and(file_get_contents($fixture['mutation']->sidecarPath()))->toBe($fixture['orphaned']->replacementSidecar());
        }
    } finally {
        $filesystem->remove($root);
    }
})->with([
    'document',
    'sidecar',
    'authority',
    'orphan-lineage-artifact',
    'extra-rollback-artifact',
    'unknown-state-file',
    'symlink-state-file',
    'dynamic-candidate-wrong-content',
    'dynamic-candidate-without-journal',
    'dynamic-candidate-symlink',
    'dynamic-candidate-hardlink',
    'dynamic-candidate-unknown-name',
    'dynamic-candidate-multiple',
    'orphan-container-present',
    'target-container-stopped',
    'target-image-changed',
]);

it('rejects stale document writers after a successor owns the sidecar revision', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $first = managedTraefikDocumentMutation($root, 'control-plane-first', 1, "http:\n  routers: {}\n");
        $second = managedTraefikDocumentMutation($root, 'control-plane-second', 2, "http:\n  routers:\n    api: {}\n", $first);

        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($first))->isSuccessful())->toBeTrue()
            ->and(runManagedTraefikDocumentCommand($writer->writeCommandFor($second))->isSuccessful())->toBeTrue();

        $stale = runManagedTraefikDocumentCommand($writer->writeCommandFor($first));

        expect($stale->isSuccessful())->toBeFalse()
            ->and(file_get_contents($second->documentPath()))->toBe($second->replacementBytes)
            ->and(file_get_contents($second->sidecarPath()))->toBe($second->replacementSidecar());
    } finally {
        $filesystem->remove($root);
    }
});

it('replays a crash after the document rename before its strict sidecar replacement', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $mutation = managedTraefikDocumentMutation(
            root: $root,
            operationId: 'control-plane-crash-replay',
            revision: 1,
            replacementBytes: "http:\n  services: {}\n",
        );

        $crashed = runManagedTraefikDocumentCommand(
            $writer->writeCommandFor($mutation),
            ['COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_DOCUMENT' => '1'],
        );

        expect($crashed->isSuccessful())->toBeFalse()
            ->and(file_get_contents($mutation->documentPath()))->toBe($mutation->replacementBytes)
            ->and(file_exists($mutation->sidecarPath()))->toBeFalse()
            ->and(file_exists($mutation->journalPath()))->toBeTrue();

        $replayed = runManagedTraefikDocumentCommand($writer->writeCommandFor($mutation));

        expect($replayed->isSuccessful())->toBeTrue()
            ->and(trim($replayed->getOutput()))->toBe(ManagedTraefikDocumentWriter::APPLIED_OUTPUT)
            ->and(file_get_contents($mutation->sidecarPath()))->toBe($mutation->replacementSidecar())
            ->and(file_exists($mutation->journalPath()))->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
});

it('rolls back only the replacement owner to its exact predecessor bytes and rejects a stale rollback', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $first = managedTraefikDocumentMutation($root, 'control-plane-predecessor', 1, "http:\n  middlewares: {}\n");
        $second = managedTraefikDocumentMutation($root, 'control-plane-replacement', 2, "http:\n  middlewares:\n    auth: {}\n", $first);

        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($first))->isSuccessful())->toBeTrue()
            ->and(runManagedTraefikDocumentCommand($writer->writeCommandFor($second))->isSuccessful())->toBeTrue();

        $rolledBack = runManagedTraefikDocumentCommand($writer->rollbackCommandFor($second));
        $replayedRollback = runManagedTraefikDocumentCommand($writer->rollbackCommandFor($second));

        expect($rolledBack->isSuccessful())->toBeTrue()
            ->and(trim($rolledBack->getOutput()))->toBe(ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT)
            ->and($replayedRollback->isSuccessful())->toBeTrue()
            ->and(file_get_contents($second->documentPath()))->toBe($first->replacementBytes)
            ->and(file_get_contents($second->sidecarPath()))->toBe($first->replacementSidecar());

        $third = managedTraefikDocumentMutation($root, 'control-plane-successor', 3, "http:\n  middlewares:\n    ratelimit: {}\n", $first);
        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($third))->isSuccessful())->toBeTrue();

        $staleRollback = runManagedTraefikDocumentCommand($writer->rollbackCommandFor($second));

        expect($staleRollback->isSuccessful())->toBeFalse()
            ->and(file_get_contents($third->documentPath()))->toBe($third->replacementBytes)
            ->and(file_get_contents($third->sidecarPath()))->toBe($third->replacementSidecar());
    } finally {
        $filesystem->remove($root);
    }
});

it('fails closed for a missing rollback artifact unless pre-write reconciliation explicitly opts in', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $predecessor = managedTraefikDocumentMutation($root, 'control-plane-predecessor', 1, "http:\n  middlewares: {}\n");
        $successor = managedTraefikDocumentMutation($root, 'control-plane-successor', 2, "http:\n  middlewares:\n    auth: {}\n", $predecessor);

        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($predecessor))->isSuccessful())->toBeTrue();
        $strict = runManagedTraefikDocumentCommand($writer->rollbackCommandFor($successor));
        $result = runManagedTraefikDocumentCommand($writer->rollbackCommandFor(
            $successor,
            allowMissingArtifactNoop: true,
        ));

        expect($strict->isSuccessful())->toBeFalse()
            ->and(file_get_contents($successor->documentPath()))->toBe($predecessor->replacementBytes)
            ->and(file_get_contents($successor->sidecarPath()))->toBe($predecessor->replacementSidecar())
            ->and(file_exists($successor->rollbackArtifactPath()))->toBeFalse()
            ->and($result->isSuccessful())->toBeTrue()
            ->and(trim($result->getOutput()))->toBe(ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT)
            ->and(file_get_contents($successor->documentPath()))->toBe($predecessor->replacementBytes)
            ->and(file_get_contents($successor->sidecarPath()))->toBe($predecessor->replacementSidecar())
            ->and(file_exists($successor->rollbackArtifactPath()))->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
});

it('validates missing-artifact rollback bytes before installing writer authority', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $filesystem->mkdir([$root.'/dynamic', $root.'/state'], 0700);
        $writer = new ManagedTraefikDocumentWriter;
        $enrollment = managedTraefikDocumentMutation(
            $root,
            'control-plane-enrollment-precondition',
            1,
            "http:\n  routers:\n    enrollment: {}\n",
        );
        $replacementAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1);
        $rolledBackAuthority = managedTraefikDocumentEnrollmentRollbackAuthority($enrollment, $replacementAuthority);
        $foreignDocument = "http:\n  routers:\n    foreign: {}\n";
        file_put_contents($enrollment->documentPath(), $foreignDocument);

        $result = runManagedTraefikDocumentCommand($writer->rollbackEnrollmentCommandFor(
            $enrollment,
            $replacementAuthority,
            $rolledBackAuthority,
        ));

        expect($result->isSuccessful())->toBeFalse()
            ->and(file_get_contents($enrollment->documentPath()))->toBe($foreignDocument)
            ->and(file_exists($enrollment->writerAuthorityPath()))->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
});

it('fails closed when a managed document path is a symlink', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $filesystem->mkdir($root.'/dynamic', 0700);
        $outside = $root.'/outside.yaml';
        file_put_contents($outside, "http:\n  routers:\n    outside: {}\n");
        $mutation = managedTraefikDocumentMutation($root, 'control-plane-symlink', 1, "http:\n  routers: {}\n");
        symlink($outside, $mutation->documentPath());

        $result = runManagedTraefikDocumentCommand((new ManagedTraefikDocumentWriter)->writeCommandFor($mutation));

        expect($result->isSuccessful())->toBeFalse()
            ->and(file_get_contents($outside))->toBe("http:\n  routers:\n    outside: {}\n")
            ->and(file_exists($mutation->sidecarPath()))->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
});

it('bootstraps the exact enrollment predecessor during the initial generation switch and rejects a legacy bypass', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $enrollment = managedTraefikDocumentMutation(
            root: $root,
            operationId: 'control-plane-authority-enrollment',
            revision: 1,
            replacementBytes: "http:\n  routers:\n    enrollment: {}\n",
        );
        $mutation = managedTraefikDocumentMutation(
            $root,
            'control-plane-authority-bootstrap',
            2,
            "http:\n  routers:\n    bootstrap: {}\n",
            $enrollment,
        );
        $authority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1);

        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($enrollment))->isSuccessful())->toBeTrue()
            ->and(file_get_contents($enrollment->rollbackArtifactPath()))
            ->toBe($writer->rollbackArtifactFor($enrollment, null));

        $written = runManagedTraefikDocumentCommand(
            $writer->writeCommandForRequiringAuthority($mutation, $authority, allowBootstrap: true),
        );
        $legacyBypass = runManagedTraefikDocumentCommand($writer->writeCommandFor($mutation));
        $authorityBytes = $authority->toJson();

        expect($written->isSuccessful())->toBeTrue()
            ->and(trim($written->getOutput()))->toBe(ManagedTraefikDocumentWriter::APPLIED_OUTPUT)
            ->and(file_get_contents($mutation->documentPath()))->toBe($mutation->replacementBytes)
            ->and(file_get_contents($mutation->sidecarPath()))->toBe($mutation->replacementSidecar())
            ->and(file_get_contents($mutation->writerAuthorityPath()))->toBe($authorityBytes)
            ->and(json_decode($authorityBytes, true, 512, JSON_THROW_ON_ERROR))->toBe([
                'epoch' => 1,
                'operation_id' => $enrollment->operationId,
                'member' => 'green',
                'container_id' => str_repeat('a', 64),
                'container_name' => 'coolify-writer-green',
                'image_id' => 'sha256:'.str_repeat('a', 64),
                'dynamic_revision' => 1,
                'dynamic_sha256' => $enrollment->replacementSha256(),
            ])
            ->and($legacyBypass->isSuccessful())->toBeFalse()
            ->and(file_get_contents($mutation->documentPath()))->toBe($mutation->replacementBytes)
            ->and(file_get_contents($mutation->writerAuthorityPath()))->toBe($authorityBytes);
    } finally {
        $filesystem->remove($root);
    }
});

it('preserves the active epoch during the route switch and promotes it only afterward with crash replay', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $first = managedTraefikDocumentMutation($root, 'control-plane-authority-first', 1, "http:\n  routers:\n    first: {}\n");
        $firstAuthority = managedTraefikDocumentWriterAuthority($first, epoch: 1, identity: 'a');
        $second = managedTraefikDocumentMutation($root, 'control-plane-authority-second', 2, "http:\n  routers:\n    second: {}\n", $first);
        $secondAuthority = managedTraefikDocumentWriterAuthority($second, epoch: 2, identity: 'b');

        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($first))->isSuccessful())->toBeTrue();

        $switched = runManagedTraefikDocumentCommand(
            $writer->writeCommandForRequiringAuthority($second, $firstAuthority, allowBootstrap: true),
        );
        $authorityAfterSwitch = file_get_contents($second->writerAuthorityPath());
        $legacyBypass = runManagedTraefikDocumentCommand($writer->writeCommandFor($second));
        $crashedPromotion = runManagedTraefikDocumentCommand(
            $writer->promoteWriterAuthorityCommandFor(
                $second->stateDirectory,
                $second->filename,
                $firstAuthority,
                $secondAuthority,
            ),
            ['COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_AUTHORITY' => '1'],
        );
        $authorityAfterPromotionCrash = file_get_contents($second->writerAuthorityPath());
        $documentAfterPromotionCrash = file_get_contents($second->documentPath());
        $replayedPromotion = runManagedTraefikDocumentCommand(
            $writer->promoteWriterAuthorityCommandFor(
                $second->stateDirectory,
                $second->filename,
                $firstAuthority,
                $secondAuthority,
            ),
        );
        $staleWrite = runManagedTraefikDocumentCommand(
            $writer->writeCommandForRequiringAuthority($second, $firstAuthority, allowBootstrap: false),
        );

        expect($switched->isSuccessful())->toBeTrue()
            ->and(trim($switched->getOutput()))->toBe(ManagedTraefikDocumentWriter::APPLIED_OUTPUT)
            ->and(file_get_contents($second->documentPath()))->toBe($second->replacementBytes)
            ->and(file_get_contents($second->sidecarPath()))->toBe($second->replacementSidecar())
            ->and($authorityAfterSwitch)->toBe($firstAuthority->toJson())
            ->and($legacyBypass->isSuccessful())->toBeFalse()
            ->and($crashedPromotion->isSuccessful())->toBeFalse()
            ->and($authorityAfterPromotionCrash)->toBe($secondAuthority->toJson())
            ->and($documentAfterPromotionCrash)->toBe($second->replacementBytes)
            ->and($replayedPromotion->isSuccessful())->toBeTrue()
            ->and(trim($replayedPromotion->getOutput()))->toBe(ManagedTraefikDocumentWriter::WRITER_AUTHORITY_PROMOTED_OUTPUT)
            ->and($staleWrite->isSuccessful())->toBeFalse()
            ->and(file_get_contents($second->documentPath()))->toBe($second->replacementBytes)
            ->and(file_get_contents($second->sidecarPath()))->toBe($second->replacementSidecar())
            ->and(file_get_contents($second->writerAuthorityPath()))->toBe($secondAuthority->toJson());
    } finally {
        $filesystem->remove($root);
    }
});

it('rejects malformed authority promotion contexts, skipped epochs, and foreign compare-and-set state', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $first = managedTraefikDocumentMutation($root, 'control-plane-authority-first', 1, "http:\n  routers:\n    first: {}\n");
        $firstAuthority = managedTraefikDocumentWriterAuthority($first, epoch: 1, identity: 'a');
        $second = managedTraefikDocumentMutation($root, 'control-plane-authority-second', 2, "http:\n  routers:\n    second: {}\n", $first);
        $secondAuthority = managedTraefikDocumentWriterAuthority($second, epoch: 2, identity: 'b');
        $skippedAuthority = managedTraefikDocumentWriterAuthority($second, epoch: 3, identity: 'c');
        $foreignAuthority = new ManagedTraefikDocumentWriterAuthority(
            epoch: 1,
            operationId: 'foreign-control-plane-authority',
            member: 'purple',
            containerId: str_repeat('d', 64),
            containerName: 'coolify-writer-purple',
            imageId: 'sha256:'.str_repeat('d', 64),
            dynamicRevision: 41,
            dynamicSha256: hash('sha256', 'foreign authority'),
        );

        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($first))->isSuccessful())->toBeTrue()
            ->and(runManagedTraefikDocumentCommand(
                $writer->writeCommandForRequiringAuthority($second, $firstAuthority, allowBootstrap: true),
            )->isSuccessful())->toBeTrue();

        $foreignCompareAndSet = runManagedTraefikDocumentCommand(
            $writer->promoteWriterAuthorityCommandFor(
                $second->stateDirectory,
                $second->filename,
                $foreignAuthority,
                $secondAuthority,
            ),
        );

        expect($foreignCompareAndSet->isSuccessful())->toBeFalse()
            ->and(file_get_contents($first->writerAuthorityPath()))->toBe($firstAuthority->toJson())
            ->and(fn () => $writer->promoteWriterAuthorityCommandFor(
                $second->stateDirectory,
                $second->filename,
                $firstAuthority,
                $skippedAuthority,
            ))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $writer->promoteWriterAuthorityCommandFor(
                'relative/state',
                $second->filename,
                $firstAuthority,
                $secondAuthority,
            ))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $writer->promoteWriterAuthorityCommandFor(
                $second->stateDirectory,
                '../outside.yaml',
                $firstAuthority,
                $secondAuthority,
            ))->toThrow(InvalidArgumentException::class);
    } finally {
        $filesystem->remove($root);
    }
});

it('tombstones a rolled-back generation so a captured stale forward shell command cannot resurrect it', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $first = managedTraefikDocumentMutation($root, 'control-plane-authority-first', 1, "http:\n  routers:\n    first: {}\n");
        $firstAuthority = managedTraefikDocumentWriterAuthority($first, epoch: 1, identity: 'a');
        $second = managedTraefikDocumentMutation($root, 'control-plane-authority-second', 2, "http:\n  routers:\n    second: {}\n", $first);
        $rolledBackAuthority = managedTraefikDocumentRollbackAuthority($first, $second, $firstAuthority);

        $legacyFirst = runManagedTraefikDocumentCommand($writer->writeCommandFor($first));
        $capturedForwardCommand = $writer->writeCommandForRequiringAuthority(
            $second,
            $firstAuthority,
            allowBootstrap: true,
        );
        $forward = runManagedTraefikDocumentCommand($capturedForwardCommand);
        $rollbackCommand = $writer->rollbackCommandForRequiringPredecessorAuthority(
            $second,
            $firstAuthority,
            $rolledBackAuthority,
        );
        $rolledBack = runManagedTraefikDocumentCommand($rollbackCommand);
        $replayedRollback = runManagedTraefikDocumentCommand($rollbackCommand);
        $staleForward = runManagedTraefikDocumentCommand($capturedForwardCommand);

        expect($legacyFirst->isSuccessful())->toBeTrue()
            ->and($forward->isSuccessful())->toBeTrue()
            ->and(trim($forward->getOutput()))->toBe(ManagedTraefikDocumentWriter::APPLIED_OUTPUT)
            ->and($rolledBack->isSuccessful())->toBeTrue()
            ->and(trim($rolledBack->getOutput()))->toBe(ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT)
            ->and($replayedRollback->isSuccessful())->toBeTrue()
            ->and($staleForward->isSuccessful())->toBeFalse()
            ->and(file_get_contents($second->documentPath()))->toBe($first->replacementBytes)
            ->and(file_get_contents($second->sidecarPath()))->toBe($first->replacementSidecar())
            ->and(file_get_contents($second->writerAuthorityPath()))->toBe($rolledBackAuthority->toJson());
    } finally {
        $filesystem->remove($root);
    }
});

it('rolls back initial enrollment with an exact replacement authority and replays its tombstone', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $enrollment = managedTraefikDocumentMutation(
            $root,
            'control-plane-enrollment-authority-rollback',
            1,
            "http:\n  routers:\n    enrollment: {}\n",
        );
        $replacementAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1);
        $rolledBackAuthority = new ManagedTraefikDocumentWriterAuthority(
            epoch: 2,
            operationId: $enrollment->operationId,
            member: $replacementAuthority->member,
            containerId: $replacementAuthority->containerId,
            containerName: $replacementAuthority->containerName,
            imageId: $replacementAuthority->imageId,
            dynamicRevision: $enrollment->revision,
            dynamicSha256: hash('sha256', ''),
        );

        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($enrollment))->isSuccessful())->toBeTrue();
        file_put_contents($enrollment->writerAuthorityPath(), $replacementAuthority->toJson());
        $command = $writer->rollbackEnrollmentCommandFor(
            $enrollment,
            $replacementAuthority,
            $rolledBackAuthority,
        );
        $rolledBack = runManagedTraefikDocumentCommand($command);
        $replayed = runManagedTraefikDocumentCommand($command);

        expect($rolledBack->isSuccessful())->toBeTrue($rolledBack->getErrorOutput())
            ->and(trim($rolledBack->getOutput()))->toBe(ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT)
            ->and($replayed->isSuccessful())->toBeTrue($replayed->getErrorOutput())
            ->and(file_exists($enrollment->documentPath()))->toBeFalse()
            ->and(file_exists($enrollment->sidecarPath()))->toBeFalse()
            ->and(file_get_contents($enrollment->writerAuthorityPath()))->toBe($rolledBackAuthority->toJson());
    } finally {
        $filesystem->remove($root);
    }
});

it('rolls back only an exact authority-less initial enrollment and preserves its rollback tombstone', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $enrollment = managedTraefikDocumentMutation(
            $root,
            'control-plane-abandoned-enrollment',
            1,
            "http:\n  routers:\n    enrollment: {}\n",
        );
        $replacementAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1);
        $rolledBackAuthority = managedTraefikDocumentEnrollmentRollbackAuthority($enrollment, $replacementAuthority);
        $forwardCommand = $writer->writeCommandFor($enrollment);

        expect(runManagedTraefikDocumentCommand($forwardCommand)->isSuccessful())->toBeTrue();
        $rollbackCommand = $writer->rollbackAbandonedInitialEnrollmentCommandFor(
            $enrollment,
            $replacementAuthority,
            $rolledBackAuthority,
        );
        $rolledBack = runManagedTraefikDocumentCommand($rollbackCommand);
        $replayed = runManagedTraefikDocumentCommand($rollbackCommand);
        $staleForward = runManagedTraefikDocumentCommand($forwardCommand);

        expect($rolledBack->isSuccessful())->toBeTrue($rolledBack->getErrorOutput())
            ->and(trim($rolledBack->getOutput()))->toBe(ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT)
            ->and($replayed->isSuccessful())->toBeTrue($replayed->getErrorOutput())
            ->and($staleForward->isSuccessful())->toBeFalse()
            ->and(file_exists($enrollment->documentPath()))->toBeFalse()
            ->and(file_exists($enrollment->sidecarPath()))->toBeFalse()
            ->and(file_exists($enrollment->rollbackArtifactPath()))->toBeTrue()
            ->and(file_exists($enrollment->lockPath()))->toBeTrue()
            ->and(file_get_contents($enrollment->writerAuthorityPath()))->toBe($rolledBackAuthority->toJson());
    } finally {
        $filesystem->remove($root);
    }
});

it('rejects drift from the exact authority-less initial enrollment without changing its document', function (string $drift): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $enrollment = managedTraefikDocumentMutation(
            $root,
            'control-plane-abandoned-enrollment-drift',
            1,
            "http:\n  routers:\n    enrollment: {}\n",
        );
        $replacementAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1);
        $rolledBackAuthority = managedTraefikDocumentEnrollmentRollbackAuthority($enrollment, $replacementAuthority);
        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($enrollment))->isSuccessful())->toBeTrue();

        match ($drift) {
            'authority' => file_put_contents($enrollment->writerAuthorityPath(), $replacementAuthority->toJson()),
            'artifact' => file_put_contents($enrollment->rollbackArtifactPath(), "foreign\n"),
            'document' => file_put_contents($enrollment->documentPath(), "http:\n  routers:\n    foreign: {}\n"),
            'sidecar' => file_put_contents($enrollment->sidecarPath(), "{}\n"),
            'lock' => unlink($enrollment->lockPath()),
            'extra' => file_put_contents($enrollment->stateDirectory.'/foreign-state', "foreign\n"),
        };

        $result = runManagedTraefikDocumentCommand(
            $writer->rollbackAbandonedInitialEnrollmentCommandFor(
                $enrollment,
                $replacementAuthority,
                $rolledBackAuthority,
            ),
        );

        expect($result->isSuccessful())->toBeFalse()
            ->and(file_exists($enrollment->writerAuthorityPath()))->toBe($drift === 'authority')
            ->and(file_exists($enrollment->rollbackArtifactPath()))->toBeTrue();
    } finally {
        $filesystem->remove($root);
    }
})->with(['authority', 'artifact', 'document', 'sidecar', 'lock', 'extra']);

it('replays an exact abandoned initial enrollment rollback after each durable mutation boundary', function (string $crashVariable): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $enrollment = managedTraefikDocumentMutation(
            $root,
            'control-plane-abandoned-enrollment-crash',
            1,
            "http:\n  routers:\n    enrollment: {}\n",
        );
        $replacementAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1);
        $rolledBackAuthority = managedTraefikDocumentEnrollmentRollbackAuthority($enrollment, $replacementAuthority);
        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($enrollment))->isSuccessful())->toBeTrue();
        $rollbackCommand = $writer->rollbackAbandonedInitialEnrollmentCommandFor(
            $enrollment,
            $replacementAuthority,
            $rolledBackAuthority,
        );

        $crashed = runManagedTraefikDocumentCommand($rollbackCommand, [$crashVariable => '1']);
        $replayed = runManagedTraefikDocumentCommand($rollbackCommand);

        expect($crashed->isSuccessful())->toBeFalse()
            ->and(file_get_contents($enrollment->writerAuthorityPath()))->toBe($rolledBackAuthority->toJson())
            ->and($replayed->isSuccessful())->toBeTrue($replayed->getErrorOutput())
            ->and(file_exists($enrollment->documentPath()))->toBeFalse()
            ->and(file_exists($enrollment->sidecarPath()))->toBeFalse()
            ->and(file_exists($enrollment->journalPath()))->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
})->with([
    'after the epoch-two rollback tombstone' => 'COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_AUTHORITY',
    'after restoring the predecessor document' => 'COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_DOCUMENT',
]);

it('recovers an exact no-journal predecessor document with the replacement sidecar', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $enrollment = managedTraefikDocumentMutation(
            $root,
            'control-plane-abandoned-no-journal',
            1,
            "http:\n  routers:\n    enrollment: {}\n",
        );
        $replacementAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1);
        $rolledBackAuthority = managedTraefikDocumentEnrollmentRollbackAuthority($enrollment, $replacementAuthority);
        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($enrollment))->isSuccessful())->toBeTrue();
        $rollbackCommand = $writer->rollbackAbandonedInitialEnrollmentCommandFor(
            $enrollment,
            $replacementAuthority,
            $rolledBackAuthority,
        );
        $afterAuthority = runManagedTraefikDocumentCommand(
            $rollbackCommand,
            ['COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_AUTHORITY' => '1'],
        );
        unlink($enrollment->documentPath());

        expect($afterAuthority->isSuccessful())->toBeFalse()
            ->and(file_exists($enrollment->journalPath()))->toBeFalse()
            ->and(file_get_contents($enrollment->sidecarPath()))->toBe($enrollment->replacementSidecar());

        $replayed = runManagedTraefikDocumentCommand($rollbackCommand);
        expect($replayed->isSuccessful())->toBeTrue($replayed->getErrorOutput())
            ->and(trim($replayed->getOutput()))->toBe(ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT)
            ->and(file_exists($enrollment->documentPath()))->toBeFalse()
            ->and(file_exists($enrollment->sidecarPath()))->toBeFalse()
            ->and(file_exists($enrollment->journalPath()))->toBeFalse()
            ->and(file_get_contents($enrollment->writerAuthorityPath()))->toBe($rolledBackAuthority->toJson());
    } finally {
        $filesystem->remove($root);
    }
});

it('replays an exact abandoned rollback after restoring a present predecessor document and sidecar', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $predecessor = managedTraefikDocumentMutation($root, 'predecessor', 1, "http:\n  routers:\n    predecessor: {}\n");
        $enrollment = managedTraefikDocumentMutation(
            $root,
            'control-plane-abandoned-present-predecessor',
            2,
            "http:\n  routers:\n    enrollment: {}\n",
            $predecessor,
        );
        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($predecessor))->isSuccessful())->toBeTrue()
            ->and(runManagedTraefikDocumentCommand($writer->writeCommandFor($enrollment))->isSuccessful())->toBeTrue();
        unlink($predecessor->rollbackArtifactPath());
        $replacementAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1);
        $rolledBackAuthority = managedTraefikDocumentEnrollmentRollbackAuthority($enrollment, $replacementAuthority);
        $rollbackCommand = $writer->rollbackAbandonedInitialEnrollmentCommandFor(
            $enrollment,
            $replacementAuthority,
            $rolledBackAuthority,
        );

        $crashed = runManagedTraefikDocumentCommand(
            $rollbackCommand,
            ['COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_SIDECAR' => '1'],
        );
        expect($crashed->isSuccessful())->toBeFalse()
            ->and(file_get_contents($enrollment->documentPath()))->toBe($predecessor->replacementBytes)
            ->and(file_get_contents($enrollment->sidecarPath()))->toBe($predecessor->replacementSidecar())
            ->and(file_exists($enrollment->journalPath()))->toBeTrue();

        $replayed = runManagedTraefikDocumentCommand($rollbackCommand);
        expect($replayed->isSuccessful())->toBeTrue($replayed->getErrorOutput())
            ->and(file_get_contents($enrollment->documentPath()))->toBe($predecessor->replacementBytes)
            ->and(file_get_contents($enrollment->sidecarPath()))->toBe($predecessor->replacementSidecar())
            ->and(file_exists($enrollment->journalPath()))->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
});

it('replays an exact abandoned rollback after the epoch-two authority candidate was fsynced', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $enrollment = managedTraefikDocumentMutation(
            $root,
            'control-plane-abandoned-authority-candidate',
            1,
            "http:\n  routers:\n    enrollment: {}\n",
        );
        $replacementAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1);
        $rolledBackAuthority = managedTraefikDocumentEnrollmentRollbackAuthority($enrollment, $replacementAuthority);
        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($enrollment))->isSuccessful())->toBeTrue();
        $rollbackCommand = $writer->rollbackAbandonedInitialEnrollmentCommandFor(
            $enrollment,
            $replacementAuthority,
            $rolledBackAuthority,
        );

        $crashed = runManagedTraefikDocumentCommand(
            $rollbackCommand,
            ['COOLIFY_DURABLE_REMOTE_ARTIFACT_CRASH_AFTER_CANDIDATE_FSYNC' => '1'],
        );
        expect($crashed->isSuccessful())->toBeFalse()
            ->and(file_exists($enrollment->writerAuthorityPath()))->toBeFalse();

        $replayed = runManagedTraefikDocumentCommand($rollbackCommand);
        expect($replayed->isSuccessful())->toBeTrue($replayed->getErrorOutput())
            ->and(file_get_contents($enrollment->writerAuthorityPath()))->toBe($rolledBackAuthority->toJson());
    } finally {
        $filesystem->remove($root);
    }
});

it('replays an exact abandoned rollback after the rollback journal candidate was fsynced', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $enrollment = managedTraefikDocumentMutation(
            $root,
            'control-plane-abandoned-journal-candidate',
            1,
            "http:\n  routers:\n    enrollment: {}\n",
        );
        $replacementAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1);
        $rolledBackAuthority = managedTraefikDocumentEnrollmentRollbackAuthority($enrollment, $replacementAuthority);
        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($enrollment))->isSuccessful())->toBeTrue();
        $rollbackCommand = $writer->rollbackAbandonedInitialEnrollmentCommandFor(
            $enrollment,
            $replacementAuthority,
            $rolledBackAuthority,
        );
        $afterAuthority = runManagedTraefikDocumentCommand(
            $rollbackCommand,
            ['COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_AUTHORITY' => '1'],
        );
        expect($afterAuthority->isSuccessful())->toBeFalse()
            ->and(file_get_contents($enrollment->writerAuthorityPath()))->toBe($rolledBackAuthority->toJson())
            ->and(file_exists($enrollment->journalPath()))->toBeFalse();

        $afterJournalCandidate = runManagedTraefikDocumentCommand(
            $rollbackCommand,
            ['COOLIFY_DURABLE_REMOTE_ARTIFACT_CRASH_AFTER_CANDIDATE_FSYNC' => '1'],
        );
        expect($afterJournalCandidate->isSuccessful())->toBeFalse()
            ->and(file_exists($enrollment->journalPath()))->toBeFalse();

        $replayed = runManagedTraefikDocumentCommand($rollbackCommand);
        expect($replayed->isSuccessful())->toBeTrue($replayed->getErrorOutput())
            ->and(file_exists($enrollment->documentPath()))->toBeFalse()
            ->and(file_exists($enrollment->sidecarPath()))->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
});

it('reconciles an empty abandoned legacy scratch directory before exact rollback replay', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $enrollment = managedTraefikDocumentMutation(
            $root,
            'control-plane-abandoned-empty-scratch',
            1,
            "http:\n  routers:\n    enrollment: {}\n",
        );
        $replacementAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1);
        $rolledBackAuthority = managedTraefikDocumentEnrollmentRollbackAuthority($enrollment, $replacementAuthority);
        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($enrollment))->isSuccessful())->toBeTrue();
        $abandonedScratch = $enrollment->stateDirectory.'/.managed-traefik-document.Ab12Cd';
        mkdir($abandonedScratch, 0700);

        $replayed = runManagedTraefikDocumentCommand(
            $writer->rollbackAbandonedInitialEnrollmentCommandFor(
                $enrollment,
                $replacementAuthority,
                $rolledBackAuthority,
            ),
        );

        expect($replayed->isSuccessful())->toBeTrue($replayed->getErrorOutput())
            ->and(file_exists($abandonedScratch))->toBeFalse()
            ->and(file_exists($enrollment->documentPath()))->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
});

it('reconciles a populated abandoned legacy scratch directory before exact rollback replay', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $enrollment = managedTraefikDocumentMutation(
            $root,
            'control-plane-abandoned-populated-scratch',
            1,
            "http:\n  routers:\n    enrollment: {}\n",
        );
        $replacementAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1);
        $rolledBackAuthority = managedTraefikDocumentEnrollmentRollbackAuthority($enrollment, $replacementAuthority);
        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($enrollment))->isSuccessful())->toBeTrue();
        $abandonedScratch = $enrollment->stateDirectory.'/.managed-traefik-document.Ab12Cd';
        mkdir($abandonedScratch, 0700);
        foreach ([
            'expected-sidecar',
            'replacement-sidecar',
            'replacement-payload',
            'expected-journal',
            'forward-journal',
            'authority-required',
            'authority-next',
            'predecessor-document',
        ] as $scratchFile) {
            file_put_contents($abandonedScratch.'/'.$scratchFile, $scratchFile);
        }

        $replayed = runManagedTraefikDocumentCommand(
            $writer->rollbackAbandonedInitialEnrollmentCommandFor(
                $enrollment,
                $replacementAuthority,
                $rolledBackAuthority,
            ),
        );

        expect($replayed->isSuccessful())->toBeTrue($replayed->getErrorOutput())
            ->and(file_exists($abandonedScratch))->toBeFalse()
            ->and(file_exists($enrollment->documentPath()))->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
});

it('rejects unsafe abandoned legacy scratch contents without changing the replacement', function (string $unsafeEntry): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $enrollment = managedTraefikDocumentMutation(
            $root,
            'control-plane-unsafe-legacy-scratch',
            1,
            "http:\n  routers:\n    enrollment: {}\n",
        );
        $replacementAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1);
        $rolledBackAuthority = managedTraefikDocumentEnrollmentRollbackAuthority($enrollment, $replacementAuthority);
        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($enrollment))->isSuccessful())->toBeTrue();
        $abandonedScratch = $enrollment->stateDirectory.'/.managed-traefik-document.Ab12Cd';
        mkdir($abandonedScratch, 0700);
        match ($unsafeEntry) {
            'unknown' => file_put_contents($abandonedScratch.'/unknown', 'unknown'),
            'symlink' => symlink($enrollment->documentPath(), $abandonedScratch.'/expected-sidecar'),
            'hardlink' => link($enrollment->documentPath(), $abandonedScratch.'/expected-sidecar'),
            'directory' => mkdir($abandonedScratch.'/expected-sidecar', 0700),
        };

        $result = runManagedTraefikDocumentCommand(
            $writer->rollbackAbandonedInitialEnrollmentCommandFor(
                $enrollment,
                $replacementAuthority,
                $rolledBackAuthority,
            ),
        );

        expect($result->isSuccessful())->toBeFalse()
            ->and(file_get_contents($enrollment->documentPath()))->toBe($enrollment->replacementBytes)
            ->and(file_exists($enrollment->writerAuthorityPath()))->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
})->with(['unknown', 'symlink', 'hardlink', 'directory']);

it('finalizes an exact initial enrollment rollback by restoring the pre-enrollment filesystem state', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $enrollment = managedTraefikDocumentMutation(
            $root,
            'control-plane-enrollment-finalize',
            1,
            "http:\n  routers:\n    enrollment: {}\n",
        );
        $replacementAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1);
        $rolledBackAuthority = managedTraefikDocumentEnrollmentRollbackAuthority($enrollment, $replacementAuthority);

        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($enrollment))->isSuccessful())->toBeTrue()
            ->and(file_get_contents($enrollment->rollbackArtifactPath()))
            ->toBe($writer->rollbackArtifactFor($enrollment, null));
        file_put_contents($enrollment->writerAuthorityPath(), $replacementAuthority->toJson());
        expect(runManagedTraefikDocumentCommand($writer->rollbackEnrollmentCommandFor(
            $enrollment,
            $replacementAuthority,
            $rolledBackAuthority,
        ))->isSuccessful())->toBeTrue();

        $finalized = runManagedTraefikDocumentCommand(
            $writer->finalizeEnrollmentRollbackCommandFor($enrollment, $rolledBackAuthority),
        );

        expect($finalized->isSuccessful())->toBeTrue($finalized->getErrorOutput())
            ->and(trim($finalized->getOutput()))->toBe(ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT)
            ->and(file_exists($enrollment->documentPath()))->toBeFalse()
            ->and(file_exists($enrollment->sidecarPath()))->toBeFalse()
            ->and(file_exists($enrollment->writerAuthorityPath()))->toBeFalse()
            ->and(file_exists($enrollment->rollbackArtifactPath()))->toBeFalse()
            ->and(file_exists($enrollment->lockPath()))->toBeFalse()
            ->and(file_exists($enrollment->stateDirectory))->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
});

it('recovers an interrupted initial enrollment with a visible sidecar and rollback artifact', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $enrollment = managedTraefikDocumentMutation(
            $root,
            'interrupted-control-plane-enrollment',
            1,
            "http:\n  routers:\n    enrollment: {}\n",
        );
        $replacementAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1);
        $rolledBackAuthority = managedTraefikDocumentEnrollmentRollbackAuthority($enrollment, $replacementAuthority);

        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($enrollment))->isSuccessful())->toBeTrue()
            ->and(file_get_contents($enrollment->rollbackArtifactPath()))
            ->toBe($writer->rollbackArtifactFor($enrollment, null));
        unlink($enrollment->documentPath());
        $scratch = $enrollment->stateDirectory.'/.managed-traefik-document.interrupted';
        $filesystem->mkdir($scratch, 0700);
        file_put_contents($scratch.'/expected-sidecar', '');

        $rolledBack = runManagedTraefikDocumentCommand($writer->rollbackEnrollmentCommandFor(
            $enrollment,
            $replacementAuthority,
            $rolledBackAuthority,
        ));
        $finalized = runManagedTraefikDocumentCommand(
            $writer->finalizeEnrollmentRollbackCommandFor($enrollment, $rolledBackAuthority),
        );

        expect($rolledBack->isSuccessful())->toBeTrue($rolledBack->getErrorOutput())
            ->and(trim($rolledBack->getOutput()))->toBe(ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT)
            ->and($finalized->isSuccessful())->toBeTrue($finalized->getErrorOutput())
            ->and(trim($finalized->getOutput()))->toBe(ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT)
            ->and(file_exists($enrollment->documentPath()))->toBeFalse()
            ->and(file_exists($enrollment->sidecarPath()))->toBeFalse()
            ->and(file_exists($enrollment->rollbackArtifactPath()))->toBeFalse()
            ->and(file_exists($enrollment->stateDirectory))->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
});

it('replays enrollment rollback finalization after its state directory has already been removed', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $filesystem->mkdir($root.'/dynamic', 0700);
        $predecessorBytes = "http:\n  routers:\n    predecessor: {}\n";
        $enrollment = new ManagedTraefikDocumentMutation(
            dynamicDirectory: $root.'/dynamic',
            stateDirectory: $root.'/state',
            filename: 'coolify-control-plane.yaml',
            operationId: 'control-plane-enrollment-finalize-replay',
            revision: 1,
            expectedSha256: hash('sha256', $predecessorBytes),
            expectedOperationId: null,
            expectedRevision: null,
            replacementBytes: "http:\n  routers:\n    enrollment: {}\n",
        );
        file_put_contents($enrollment->documentPath(), $predecessorBytes);
        $replacementAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1);
        $rolledBackAuthority = managedTraefikDocumentEnrollmentRollbackAuthority($enrollment, $replacementAuthority);
        $command = (new ManagedTraefikDocumentWriter)->finalizeEnrollmentRollbackCommandFor(
            $enrollment,
            $rolledBackAuthority,
        );
        $first = runManagedTraefikDocumentCommand($command);
        $replayed = runManagedTraefikDocumentCommand($command);

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and(trim($first->getOutput()))->toBe(ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT)
            ->and($replayed->isSuccessful())->toBeTrue($replayed->getErrorOutput())
            ->and(trim($replayed->getOutput()))->toBe(ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT)
            ->and(file_get_contents($enrollment->documentPath()))->toBe($predecessorBytes)
            ->and(file_exists($enrollment->stateDirectory))->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
});

it('inspects exact rollback finalization without recreating writer state', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $filesystem->mkdir($root.'/dynamic', 0700);
        $enrollment = managedTraefikDocumentMutation(
            $root,
            'control-plane-enrollment-finalization-inspection',
            1,
            "http:\n  routers:\n    enrollment: {}\n",
        );
        $writer = new ManagedTraefikDocumentWriter;
        $command = $writer->inspectEnrollmentRollbackFinalizationCommandFor($enrollment);

        $finalized = runManagedTraefikDocumentCommand($command);
        $filesystem->mkdir($enrollment->stateDirectory, 0700);
        $emptyCleanup = runManagedTraefikDocumentCommand($command);
        file_put_contents($enrollment->lockPath(), '');
        $lockedCleanup = runManagedTraefikDocumentCommand($command);
        $cleaned = runManagedTraefikDocumentCommand(
            $writer->finalizePartialEnrollmentRollbackCommandFor($enrollment),
        );

        expect($finalized->isSuccessful())->toBeTrue($finalized->getErrorOutput())
            ->and(trim($finalized->getOutput()))->toBe(ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT)
            ->and($emptyCleanup->isSuccessful())->toBeTrue($emptyCleanup->getErrorOutput())
            ->and(trim($emptyCleanup->getOutput()))->toBe(ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_CLEANUP_PENDING_OUTPUT)
            ->and($lockedCleanup->isSuccessful())->toBeTrue($lockedCleanup->getErrorOutput())
            ->and(trim($lockedCleanup->getOutput()))->toBe(ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_CLEANUP_PENDING_OUTPUT)
            ->and($cleaned->isSuccessful())->toBeTrue($cleaned->getErrorOutput())
            ->and(trim($cleaned->getOutput()))->toBe(ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT)
            ->and(file_exists($enrollment->stateDirectory))->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
});

it('finalizes a replayed enrollment rollback whose forward artifact is already absent', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $filesystem->mkdir([$root.'/dynamic', $root.'/state'], 0700);
        $writer = new ManagedTraefikDocumentWriter;
        $enrollment = managedTraefikDocumentMutation(
            $root,
            'control-plane-enrollment-finalize-without-artifact',
            1,
            "http:\n  routers:\n    enrollment: {}\n",
        );
        $replacementAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1);
        $rolledBackAuthority = managedTraefikDocumentEnrollmentRollbackAuthority($enrollment, $replacementAuthority);

        $rolledBack = runManagedTraefikDocumentCommand($writer->rollbackEnrollmentCommandFor(
            $enrollment,
            $replacementAuthority,
            $rolledBackAuthority,
        ));
        $finalized = runManagedTraefikDocumentCommand(
            $writer->finalizeEnrollmentRollbackCommandFor($enrollment, $rolledBackAuthority),
        );

        expect($rolledBack->isSuccessful())->toBeTrue($rolledBack->getErrorOutput())
            ->and(trim($rolledBack->getOutput()))->toBe(ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT)
            ->and(file_exists($enrollment->rollbackArtifactPath()))->toBeFalse()
            ->and($finalized->isSuccessful())->toBeTrue($finalized->getErrorOutput())
            ->and(trim($finalized->getOutput()))->toBe(ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT)
            ->and(file_exists($enrollment->stateDirectory))->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
});

it('rejects foreign enrollment rollback authority and unknown state files without cleanup', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $enrollment = managedTraefikDocumentMutation(
            $root,
            'control-plane-enrollment-finalize-reject',
            1,
            "http:\n  routers:\n    enrollment: {}\n",
        );
        $replacementAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1, identity: 'a');
        $rolledBackAuthority = managedTraefikDocumentEnrollmentRollbackAuthority($enrollment, $replacementAuthority);

        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($enrollment))->isSuccessful())->toBeTrue();
        file_put_contents($enrollment->writerAuthorityPath(), $replacementAuthority->toJson());
        expect(runManagedTraefikDocumentCommand($writer->rollbackEnrollmentCommandFor(
            $enrollment,
            $replacementAuthority,
            $rolledBackAuthority,
        ))->isSuccessful())->toBeTrue();

        $command = $writer->finalizeEnrollmentRollbackCommandFor($enrollment, $rolledBackAuthority);
        $foreignAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 3, identity: 'b');
        file_put_contents($enrollment->writerAuthorityPath(), $foreignAuthority->toJson());
        $rejectedAuthority = runManagedTraefikDocumentCommand($command);
        file_put_contents($enrollment->writerAuthorityPath(), $rolledBackAuthority->toJson());
        $foreignStatePath = $enrollment->stateDirectory.'/foreign-state';
        file_put_contents($foreignStatePath, 'foreign');
        $rejectedState = runManagedTraefikDocumentCommand($command);

        expect($rejectedAuthority->isSuccessful())->toBeFalse()
            ->and($rejectedState->isSuccessful())->toBeFalse()
            ->and(file_exists($enrollment->stateDirectory))->toBeTrue()
            ->and(file_exists($enrollment->rollbackArtifactPath()))->toBeTrue()
            ->and(file_get_contents($enrollment->writerAuthorityPath()))->toBe($rolledBackAuthority->toJson())
            ->and(file_get_contents($foreignStatePath))->toBe('foreign');
    } finally {
        $filesystem->remove($root);
    }
});

it('fences and reconciles an exact forward journal left before document apply', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $first = managedTraefikDocumentMutation($root, 'control-plane-journal-first', 1, "http:\n  routers:\n    first: {}\n");
        $firstAuthority = managedTraefikDocumentWriterAuthority($first, epoch: 1, identity: 'a');
        $second = managedTraefikDocumentMutation($root, 'control-plane-journal-second', 2, "http:\n  routers:\n    second: {}\n", $first);
        $rolledBackAuthority = managedTraefikDocumentRollbackAuthority($first, $second, $firstAuthority);

        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($first))->isSuccessful())->toBeTrue();

        $capturedForwardCommand = $writer->writeCommandForRequiringAuthority(
            $second,
            $firstAuthority,
            allowBootstrap: true,
        );
        $crashedForward = runManagedTraefikDocumentCommand(
            $capturedForwardCommand,
            ['COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_JOURNAL' => '1'],
        );
        $documentBeforeRollback = file_get_contents($second->documentPath());
        $authorityBeforeRollback = file_get_contents($second->writerAuthorityPath());
        $journalBeforeRollback = file_get_contents($second->journalPath());
        $rollbackCommand = $writer->rollbackCommandForRequiringPredecessorAuthority(
            $second,
            $firstAuthority,
            $rolledBackAuthority,
        );
        $crashedRollback = runManagedTraefikDocumentCommand(
            $rollbackCommand,
            ['COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_JOURNAL' => '1'],
        );
        $staleForward = runManagedTraefikDocumentCommand($capturedForwardCommand);
        $journalAfterRollbackCrash = file_get_contents($second->journalPath());
        $documentAfterRollbackCrash = file_get_contents($second->documentPath());
        $authorityAfterRollbackCrash = file_get_contents($second->writerAuthorityPath());
        $rolledBack = runManagedTraefikDocumentCommand($rollbackCommand);

        expect($crashedForward->isSuccessful())->toBeFalse()
            ->and($documentBeforeRollback)->toBe($first->replacementBytes)
            ->and($authorityBeforeRollback)->toBe($firstAuthority->toJson())
            ->and($journalBeforeRollback)->toContain("coolify-managed-traefik-document-journal-v1\nwrite\n")
            ->and($crashedRollback->isSuccessful())->toBeFalse()
            ->and($journalAfterRollbackCrash)->toContain("coolify-managed-traefik-document-journal-v1\nrollback\n")
            ->and($documentAfterRollbackCrash)->toBe($first->replacementBytes)
            ->and($authorityAfterRollbackCrash)->toBe($rolledBackAuthority->toJson())
            ->and($staleForward->isSuccessful())->toBeFalse()
            ->and($rolledBack->getErrorOutput())->toBe('')
            ->and($rolledBack->isSuccessful())->toBeTrue()
            ->and(trim($rolledBack->getOutput()))->toBe(ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT)
            ->and(file_exists($second->journalPath()))->toBeFalse()
            ->and(file_get_contents($second->documentPath()))->toBe($first->replacementBytes)
            ->and(file_get_contents($second->sidecarPath()))->toBe($first->replacementSidecar())
            ->and(file_get_contents($second->writerAuthorityPath()))->toBe($rolledBackAuthority->toJson());
    } finally {
        $filesystem->remove($root);
    }
});

it('requires the exact successor tombstone authority for pre-write rollback reconciliation', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $first = managedTraefikDocumentMutation($root, 'control-plane-authority-first', 1, "http:\n  routers:\n    first: {}\n");
        $firstAuthority = managedTraefikDocumentWriterAuthority($first, epoch: 1, identity: 'a');
        $second = managedTraefikDocumentMutation($root, 'control-plane-authority-second', 2, "http:\n  routers:\n    second: {}\n", $first);
        $rolledBackAuthority = managedTraefikDocumentRollbackAuthority($first, $second, $firstAuthority);
        $foreignTombstone = managedTraefikDocumentWriterAuthority($first, epoch: 2, identity: 'a');

        $legacyFirst = runManagedTraefikDocumentCommand($writer->writeCommandFor($first));
        $reconciled = runManagedTraefikDocumentCommand(
            $writer->rollbackCommandForRequiringPredecessorAuthority(
                $second,
                $firstAuthority,
                $rolledBackAuthority,
                allowInitialOrPreWriteReconciliation: true,
                allowMissingArtifactNoop: true,
            ),
        );
        $replayed = runManagedTraefikDocumentCommand(
            $writer->rollbackCommandForRequiringPredecessorAuthority(
                $second,
                $firstAuthority,
                $rolledBackAuthority,
                allowInitialOrPreWriteReconciliation: true,
                allowMissingArtifactNoop: true,
            ),
        );

        expect($legacyFirst->isSuccessful())->toBeTrue()
            ->and($reconciled->isSuccessful())->toBeTrue()
            ->and(trim($reconciled->getOutput()))->toBe(ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT)
            ->and($replayed->isSuccessful())->toBeTrue()
            ->and(file_get_contents($second->documentPath()))->toBe($first->replacementBytes)
            ->and(file_get_contents($second->sidecarPath()))->toBe($first->replacementSidecar())
            ->and(file_get_contents($second->writerAuthorityPath()))->toBe($rolledBackAuthority->toJson())
            ->and(fn () => $writer->rollbackCommandForRequiringPredecessorAuthority(
                $second,
                $firstAuthority,
                $foreignTombstone,
            ))->toThrow(InvalidArgumentException::class, 'exact generation attempt');
    } finally {
        $filesystem->remove($root);
    }
});

it('reconciles terminal legacy rollback authority from exact restored bytes without successor payload or token', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $first = managedTraefikDocumentMutation($root, 'control-plane-legacy-first', 1, "http:\n  routers:\n    first: {}\n");
        $firstAuthority = managedTraefikDocumentWriterAuthority($first, epoch: 1, identity: 'a');
        $second = managedTraefikDocumentMutation($root, 'control-plane-legacy-second', 2, 'not-retained', $first);
        $rolledBackAuthority = managedTraefikDocumentRollbackAuthority($first, $second, $firstAuthority);

        $legacyFirst = runManagedTraefikDocumentCommand($writer->writeCommandFor($first));
        $reconcileCommand = $writer->reconcileRolledBackWriterAuthorityCommandFor(
            $second,
            $firstAuthority,
            $rolledBackAuthority,
        );
        expect($reconcileCommand)->not->toContain('16777216', 'artifact_count', 'relevant_artifact_count');
        file_put_contents($second->journalPath(), "pending\n");
        $pendingJournal = runManagedTraefikDocumentCommand($reconcileCommand);
        unlink($second->journalPath());
        $crashed = runManagedTraefikDocumentCommand(
            $reconcileCommand,
            ['COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_AUTHORITY' => '1'],
        );
        $staleForward = runManagedTraefikDocumentCommand(
            $writer->writeCommandForRequiringAuthority($second, $firstAuthority, allowBootstrap: true),
        );
        $replayed = runManagedTraefikDocumentCommand($reconcileCommand);
        $restoredDocument = file_get_contents($second->documentPath());
        $restoredSidecar = file_get_contents($second->sidecarPath());
        $restoredAuthority = file_get_contents($second->writerAuthorityPath());
        $replacement = new ManagedTraefikDocumentMutation(
            dynamicDirectory: $root.'/dynamic',
            stateDirectory: $root.'/state',
            filename: 'coolify-control-plane.yaml',
            operationId: 'control-plane-legacy-replacement',
            revision: 3,
            expectedSha256: $first->replacementSha256(),
            expectedOperationId: $first->operationId,
            expectedRevision: $first->revision,
            replacementBytes: "http:\n  routers:\n    replacement: {}\n",
            expectedWriterOperationId: $rolledBackAuthority->operationId,
        );
        $replacementWrite = runManagedTraefikDocumentCommand(
            $writer->writeCommandForRequiringAuthority($replacement, $rolledBackAuthority, allowBootstrap: false),
        );
        $delayedReconciliation = runManagedTraefikDocumentCommand($reconcileCommand);

        expect($legacyFirst->isSuccessful())->toBeTrue()
            ->and($pendingJournal->isSuccessful())->toBeFalse()
            ->and($crashed->isSuccessful())->toBeFalse()
            ->and($crashed->getExitCode())->toBe(75)
            ->and($restoredDocument)->toBe($first->replacementBytes)
            ->and($restoredSidecar)->toBe($first->replacementSidecar())
            ->and($restoredAuthority)->toBe($rolledBackAuthority->toJson())
            ->and($staleForward->isSuccessful())->toBeFalse()
            ->and($replayed->isSuccessful())->toBeTrue()
            ->and(trim($replayed->getOutput()))->toBe(ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT)
            ->and($replacementWrite->isSuccessful())->toBeTrue()
            ->and($delayedReconciliation->isSuccessful())->toBeFalse()
            ->and(file_get_contents($replacement->documentPath()))->toBe($replacement->replacementBytes)
            ->and(file_get_contents($replacement->sidecarPath()))->toBe($replacement->replacementSidecar());
    } finally {
        $filesystem->remove($root);
    }
});

it('tombstones and restores an exact pending legacy forward journal without retained successor input', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $first = managedTraefikDocumentMutation($root, 'control-plane-pending-first', 1, "http:\n  routers:\n    first: {}\n");
        $firstAuthority = managedTraefikDocumentWriterAuthority($first, epoch: 1, identity: 'a');
        $second = managedTraefikDocumentMutation($root, 'control-plane-pending-second', 2, "http:\n  routers:\n    second: {}\n", $first);
        $rolledBackAuthority = managedTraefikDocumentRollbackAuthority($first, $second, $firstAuthority);

        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($first))->isSuccessful())->toBeTrue();
        $capturedForward = $writer->writeCommandForRequiringAuthority($second, $firstAuthority, allowBootstrap: true);
        $crashedForward = runManagedTraefikDocumentCommand(
            $capturedForward,
            ['COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_JOURNAL' => '1'],
        );
        $reconciled = runManagedTraefikDocumentCommand(
            $writer->reconcileRolledBackWriterAuthorityCommandFor($second, $firstAuthority, $rolledBackAuthority),
        );
        $staleForward = runManagedTraefikDocumentCommand($capturedForward);

        expect($crashedForward->isSuccessful())->toBeFalse()
            ->and(file_exists($second->journalPath()))->toBeFalse()
            ->and($reconciled->isSuccessful())->toBeTrue()
            ->and(trim($reconciled->getOutput()))->toBe(ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT)
            ->and(file_get_contents($second->documentPath()))->toBe($first->replacementBytes)
            ->and(file_get_contents($second->sidecarPath()))->toBe($first->replacementSidecar())
            ->and(file_get_contents($second->writerAuthorityPath()))->toBe($rolledBackAuthority->toJson())
            ->and($staleForward->isSuccessful())->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
});

it('restores a legacy sibling successor that won before the current terminal rollback tombstone', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $first = managedTraefikDocumentMutation($root, 'control-plane-sibling-first', 1, "http:\n  routers:\n    first: {}\n");
        $firstAuthority = managedTraefikDocumentWriterAuthority($first, epoch: 1, identity: 'a');
        $sibling = managedTraefikDocumentMutation($root, 'control-plane-sibling-a', 2, "http:\n  routers:\n    sibling: {}\n", $first);
        $current = managedTraefikDocumentMutation($root, 'control-plane-sibling-b', 2, 'not-retained', $first);
        $rolledBackAuthority = managedTraefikDocumentRollbackAuthority($first, $current, $firstAuthority);

        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($first))->isSuccessful())->toBeTrue();
        $capturedSiblingForward = $writer->writeCommandForRequiringAuthority($sibling, $firstAuthority, allowBootstrap: true);
        $siblingForward = runManagedTraefikDocumentCommand($capturedSiblingForward);
        file_put_contents(
            $root.'/state/.coolify-control-plane.yaml.000-history.r1.rollback',
            implode("\n", [
                'coolify-managed-traefik-document-rollback-v1',
                'coolify-control-plane.yaml',
                'historical-operation',
                '1',
                str_repeat('0', 64),
                str_repeat('1', 64),
                base64_encode("historical-sidecar\n"),
                base64_encode("historical-document\n"),
                '',
            ]),
        );
        $reconciled = runManagedTraefikDocumentCommand(
            $writer->reconcileRolledBackWriterAuthorityCommandFor($current, $firstAuthority, $rolledBackAuthority),
        );
        $staleSiblingForward = runManagedTraefikDocumentCommand($capturedSiblingForward);

        expect($siblingForward->isSuccessful())->toBeTrue()
            ->and($reconciled->isSuccessful())->toBeTrue()
            ->and(trim($reconciled->getOutput()))->toBe(ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT)
            ->and(file_get_contents($current->documentPath()))->toBe($first->replacementBytes)
            ->and(file_get_contents($current->sidecarPath()))->toBe($first->replacementSidecar())
            ->and(file_get_contents($current->writerAuthorityPath()))->toBe($rolledBackAuthority->toJson())
            ->and($staleSiblingForward->isSuccessful())->toBeFalse();
    } finally {
        $filesystem->remove($root);
    }
});

it('finishes an exact legacy sibling rollback journal before admitting the current replacement', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $first = managedTraefikDocumentMutation($root, 'control-plane-rollback-journal-first', 1, "http:\n  routers:\n    first: {}\n");
        $firstAuthority = managedTraefikDocumentWriterAuthority($first, epoch: 1, identity: 'a');
        $sibling = managedTraefikDocumentMutation($root, 'control-plane-rollback-journal-a', 2, "http:\n  routers:\n    sibling: {}\n", $first);
        $current = managedTraefikDocumentMutation($root, 'control-plane-rollback-journal-b', 2, 'not-retained', $first);
        $rolledBackAuthority = managedTraefikDocumentRollbackAuthority($first, $current, $firstAuthority);

        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($first))->isSuccessful())->toBeTrue()
            ->and(runManagedTraefikDocumentCommand($writer->writeCommandFor($sibling))->isSuccessful())->toBeTrue();
        $crashedSiblingRollback = runManagedTraefikDocumentCommand(
            $writer->rollbackCommandFor($sibling),
            ['COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_JOURNAL' => '1'],
        );
        $reconciled = runManagedTraefikDocumentCommand(
            $writer->reconcileRolledBackWriterAuthorityCommandFor($current, $firstAuthority, $rolledBackAuthority),
        );

        expect($crashedSiblingRollback->isSuccessful())->toBeFalse()
            ->and($crashedSiblingRollback->getExitCode())->toBe(75)
            ->and($reconciled->isSuccessful())->toBeTrue()
            ->and(trim($reconciled->getOutput()))->toBe(ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT)
            ->and(file_exists($current->journalPath()))->toBeFalse()
            ->and(file_get_contents($current->documentPath()))->toBe($first->replacementBytes)
            ->and(file_get_contents($current->sidecarPath()))->toBe($first->replacementSidecar())
            ->and(file_get_contents($current->writerAuthorityPath()))->toBe($rolledBackAuthority->toJson());
    } finally {
        $filesystem->remove($root);
    }
});

it('rejects a foreign operation authority at PHP command construction even when its writer identity and document match', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $first = managedTraefikDocumentMutation($root, 'control-plane-authority-first', 1, "http:\n  routers:\n    first: {}\n");
        $firstAuthority = managedTraefikDocumentWriterAuthority($first, epoch: 1, identity: 'a');
        $second = managedTraefikDocumentMutation($root, 'control-plane-authority-second', 2, "http:\n  routers:\n    second: {}\n", $first);
        $rolledBackAuthority = managedTraefikDocumentRollbackAuthority($first, $second, $firstAuthority);
        $foreignOperationAuthority = new ManagedTraefikDocumentWriterAuthority(
            epoch: $firstAuthority->epoch,
            operationId: 'foreign-control-plane-authority',
            member: $firstAuthority->member,
            containerId: $firstAuthority->containerId,
            containerName: $firstAuthority->containerName,
            imageId: $firstAuthority->imageId,
            dynamicRevision: $first->revision,
            dynamicSha256: $first->replacementSha256(),
        );

        expect($foreignOperationAuthority->matchesPredecessorDocument($second))->toBeTrue()
            ->and($foreignOperationAuthority->hasSameWriterIdentityAs($firstAuthority))->toBeTrue()
            ->and(fn () => $writer->writeCommandForRequiringAuthority(
                $second,
                $foreignOperationAuthority,
                allowBootstrap: false,
            ))->toThrow(InvalidArgumentException::class, 'exact active predecessor authority')
            ->and(fn () => $writer->rollbackCommandForRequiringPredecessorAuthority(
                $second,
                $foreignOperationAuthority,
                $rolledBackAuthority,
            ))->toThrow(InvalidArgumentException::class, 'exact predecessor authority');
    } finally {
        $filesystem->remove($root);
    }
});

it('writes the rollback tombstone before a crash so stale forward commands cannot resurrect the successor', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $first = managedTraefikDocumentMutation($root, 'control-plane-authority-first', 1, "http:\n  routers:\n    first: {}\n");
        $firstAuthority = managedTraefikDocumentWriterAuthority($first, epoch: 1, identity: 'a');
        $second = managedTraefikDocumentMutation($root, 'control-plane-authority-second', 2, "http:\n  routers:\n    second: {}\n", $first);
        $rolledBackAuthority = managedTraefikDocumentRollbackAuthority($first, $second, $firstAuthority);

        $legacyFirst = runManagedTraefikDocumentCommand($writer->writeCommandFor($first));
        $capturedForwardCommand = $writer->writeCommandForRequiringAuthority(
            $second,
            $firstAuthority,
            allowBootstrap: true,
        );
        $forward = runManagedTraefikDocumentCommand($capturedForwardCommand);
        $rollbackCommand = $writer->rollbackCommandForRequiringPredecessorAuthority(
            $second,
            $firstAuthority,
            $rolledBackAuthority,
        );
        $crashedRollback = runManagedTraefikDocumentCommand(
            $rollbackCommand,
            ['COOLIFY_MANAGED_TRAEFIK_DOCUMENT_CRASH_AFTER_AUTHORITY' => '1'],
        );
        $authorityAfterCrash = file_get_contents($second->writerAuthorityPath());
        $documentAfterCrash = file_get_contents($second->documentPath());
        $sidecarAfterCrash = file_get_contents($second->sidecarPath());
        $staleForward = runManagedTraefikDocumentCommand($capturedForwardCommand);
        $documentAfterStaleForward = file_get_contents($second->documentPath());
        $sidecarAfterStaleForward = file_get_contents($second->sidecarPath());
        $replayedRollback = runManagedTraefikDocumentCommand($rollbackCommand);

        expect($legacyFirst->isSuccessful())->toBeTrue()
            ->and($forward->isSuccessful())->toBeTrue()
            ->and($crashedRollback->isSuccessful())->toBeFalse()
            ->and($crashedRollback->getExitCode())->toBe(75)
            ->and($authorityAfterCrash)->toBe($rolledBackAuthority->toJson())
            ->and($documentAfterCrash)->toBe($second->replacementBytes)
            ->and($sidecarAfterCrash)->toBe($second->replacementSidecar())
            ->and($staleForward->isSuccessful())->toBeFalse()
            ->and($documentAfterStaleForward)->toBe($second->replacementBytes)
            ->and($sidecarAfterStaleForward)->toBe($second->replacementSidecar())
            ->and($replayedRollback->isSuccessful())->toBeTrue()
            ->and(trim($replayedRollback->getOutput()))->toBe(ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT)
            ->and(file_get_contents($second->documentPath()))->toBe($first->replacementBytes)
            ->and(file_get_contents($second->sidecarPath()))->toBe($first->replacementSidecar())
            ->and(file_get_contents($second->writerAuthorityPath()))->toBe($rolledBackAuthority->toJson());
    } finally {
        $filesystem->remove($root);
    }
});

it('allows a third generation to require the rollback tombstone instead of the original predecessor authority', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $first = managedTraefikDocumentMutation($root, 'control-plane-authority-first', 1, "http:\n  routers:\n    first: {}\n");
        $firstAuthority = managedTraefikDocumentWriterAuthority($first, epoch: 1, identity: 'a');
        $second = managedTraefikDocumentMutation($root, 'control-plane-authority-second', 2, "http:\n  routers:\n    second: {}\n", $first);
        $rolledBackAuthority = managedTraefikDocumentRollbackAuthority($first, $second, $firstAuthority);
        $third = new ManagedTraefikDocumentMutation(
            dynamicDirectory: $root.'/dynamic',
            stateDirectory: $root.'/state',
            filename: 'coolify-control-plane.yaml',
            operationId: 'control-plane-authority-third',
            revision: 3,
            expectedSha256: $first->replacementSha256(),
            expectedOperationId: $first->operationId,
            expectedRevision: $first->revision,
            replacementBytes: "http:\n  routers:\n    third: {}\n",
            expectedWriterOperationId: $rolledBackAuthority->operationId,
        );

        $legacyFirst = runManagedTraefikDocumentCommand($writer->writeCommandFor($first));
        $forward = runManagedTraefikDocumentCommand(
            $writer->writeCommandForRequiringAuthority($second, $firstAuthority, allowBootstrap: true),
        );
        $rolledBack = runManagedTraefikDocumentCommand(
            $writer->rollbackCommandForRequiringPredecessorAuthority(
                $second,
                $firstAuthority,
                $rolledBackAuthority,
            ),
        );
        $thirdForwardCommand = $writer->writeCommandForRequiringAuthority(
            $third,
            $rolledBackAuthority,
            allowBootstrap: false,
        );
        $thirdForward = runManagedTraefikDocumentCommand($thirdForwardCommand);

        expect($legacyFirst->isSuccessful())->toBeTrue()
            ->and($forward->isSuccessful())->toBeTrue()
            ->and($rolledBack->isSuccessful())->toBeTrue()
            ->and($third->expectedWriterOperationId())->toBe($rolledBackAuthority->operationId)
            ->and(fn () => $writer->writeCommandForRequiringAuthority(
                $third,
                $firstAuthority,
                allowBootstrap: false,
            ))->toThrow(InvalidArgumentException::class, 'exact active predecessor authority')
            ->and($thirdForward->isSuccessful())->toBeTrue()
            ->and(trim($thirdForward->getOutput()))->toBe(ManagedTraefikDocumentWriter::APPLIED_OUTPUT)
            ->and(file_get_contents($third->documentPath()))->toBe($third->replacementBytes)
            ->and(file_get_contents($third->sidecarPath()))->toBe($third->replacementSidecar())
            ->and(file_get_contents($third->writerAuthorityPath()))->toBe($rolledBackAuthority->toJson());
    } finally {
        $filesystem->remove($root);
    }
});

it('renders valid dash and bash commands without shellcheck findings', function (): void {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();
    $commandsRoot = $root.'/commands';

    try {
        $filesystem->mkdir($commandsRoot, 0700);
        $writer = new ManagedTraefikDocumentWriter;
        $first = managedTraefikDocumentMutation(
            root: $root,
            operationId: 'control-plane-shell-first',
            revision: 1,
            replacementBytes: "http:\n  routers:\n    first: {}\n",
        );
        $firstAuthority = managedTraefikDocumentWriterAuthority($first, epoch: 1, identity: 'a');
        $second = managedTraefikDocumentMutation(
            root: $root,
            operationId: 'control-plane-shell-second',
            revision: 2,
            replacementBytes: "http:\n  routers:\n    second: {}\n",
            predecessor: $first,
        );
        $secondAuthority = managedTraefikDocumentWriterAuthority($second, epoch: 2, identity: 'b');
        $rolledBackAuthority = managedTraefikDocumentRollbackAuthority($first, $second, $firstAuthority);
        $enrollment = managedTraefikDocumentMutation(
            root: $root,
            operationId: 'control-plane-shell-enrollment',
            revision: 3,
            replacementBytes: "http:\n  routers:\n    enrollment: {}\n",
        );
        $enrollmentReplacementAuthority = managedTraefikDocumentWriterAuthority($enrollment, epoch: 1, identity: 'c');
        $enrollmentRolledBackAuthority = managedTraefikDocumentEnrollmentRollbackAuthority(
            $enrollment,
            $enrollmentReplacementAuthority,
        );
        $orphanedEnrollment = managedTraefikDocumentMutation(
            root: $root,
            operationId: 'control-plane-shell-orphaned',
            revision: 1,
            replacementBytes: "http:\n  routers:\n    orphaned: {}\n",
        );
        $orphanedAuthority = managedTraefikDocumentWriterAuthority(
            $orphanedEnrollment,
            epoch: 1,
            identity: 'd',
        );
        $correctedPredecessor = "http:\n  routers:\n    generic: {}\n";
        $replacementEnrollment = new ManagedTraefikDocumentMutation(
            dynamicDirectory: $root.'/dynamic',
            stateDirectory: $root.'/state',
            filename: $orphanedEnrollment->filename,
            operationId: 'control-plane-shell-replacement',
            revision: 1,
            expectedSha256: hash('sha256', $correctedPredecessor),
            expectedOperationId: null,
            expectedRevision: null,
            replacementBytes: "http:\n  routers:\n    replacement: {}\n",
        );
        $replacementAuthority = managedTraefikDocumentWriterAuthority(
            $replacementEnrollment,
            epoch: 1,
            identity: 'e',
        );
        $commands = [
            $writer->writeCommandFor($first),
            $writer->promoteWriterAuthorityCommandFor(
                $second->stateDirectory,
                $second->filename,
                $firstAuthority,
                $secondAuthority,
            ),
            $writer->reconcileRolledBackWriterAuthorityCommandFor($second, $firstAuthority, $rolledBackAuthority),
            $writer->rollbackAbandonedInitialEnrollmentCommandFor(
                $enrollment,
                $enrollmentReplacementAuthority,
                $enrollmentRolledBackAuthority,
            ),
            $writer->finalizeEnrollmentRollbackCommandFor($enrollment, $enrollmentRolledBackAuthority),
            $writer->supersedeOrphanedInitialEnrollmentAuthorityCommandFor(
                $replacementEnrollment,
                $correctedPredecessor,
                $orphanedAuthority,
                $replacementAuthority,
                true,
            ),
        ];

        foreach ($commands as $index => $command) {
            $commandPath = $commandsRoot.'/managed-traefik-'.$index.'.sh';
            file_put_contents($commandPath, $command);
            foreach ([['dash', '-n', $commandPath], ['bash', '-n', $commandPath], ['shellcheck', '-s', 'sh', $commandPath]] as $arguments) {
                $process = new Process($arguments);
                $process->run();

                expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
            }
        }
    } finally {
        $filesystem->remove($root);
    }
});
