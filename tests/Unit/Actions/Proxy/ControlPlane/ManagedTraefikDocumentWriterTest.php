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
): ManagedTraefikDocumentMutation {
    return new ManagedTraefikDocumentMutation(
        dynamicDirectory: $root.'/dynamic',
        stateDirectory: $root.'/state',
        filename: 'coolify-control-plane.yaml',
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
    $process->setTimeout(10);
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

        expect(runManagedTraefikDocumentCommand($writer->writeCommandFor($enrollment))->isSuccessful())->toBeTrue();

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
