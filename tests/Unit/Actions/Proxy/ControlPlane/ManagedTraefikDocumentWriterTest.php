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

it('allows rollback only before promotion with the predecessor authority or explicit absent reconciliation', function () {
    $filesystem = new Filesystem;
    $root = managedTraefikDocumentRoot();

    try {
        $writer = new ManagedTraefikDocumentWriter;
        $first = managedTraefikDocumentMutation($root, 'control-plane-authority-first', 1, "http:\n  routers:\n    first: {}\n");
        $firstAuthority = managedTraefikDocumentWriterAuthority($first, epoch: 1, identity: 'a');
        $second = managedTraefikDocumentMutation($root, 'control-plane-authority-second', 2, "http:\n  routers:\n    second: {}\n", $first);
        $secondAuthority = managedTraefikDocumentWriterAuthority($second, epoch: 2, identity: 'b');

        $legacyFirst = runManagedTraefikDocumentCommand($writer->writeCommandFor($first));
        $absentPreWriteRollback = runManagedTraefikDocumentCommand(
            $writer->rollbackCommandForRequiringPredecessorAuthority(
                $second,
                $firstAuthority,
                allowInitialOrPreWriteReconciliation: true,
                allowMissingArtifactNoop: true,
            ),
        );
        $authorityAbsentAfterPreWriteRollback = file_exists($second->writerAuthorityPath());
        $strictPreWriteRollback = runManagedTraefikDocumentCommand(
            $writer->rollbackCommandForRequiringPredecessorAuthority($second, $firstAuthority),
        );
        $bootstrapped = runManagedTraefikDocumentCommand(
            $writer->writeCommandForRequiringAuthority($second, $firstAuthority, allowBootstrap: true),
        );
        $rolledBackBeforePromotion = runManagedTraefikDocumentCommand(
            $writer->rollbackCommandForRequiringPredecessorAuthority($second, $firstAuthority),
        );
        $documentAfterRollbackBeforePromotion = file_get_contents($second->documentPath());
        $authorityAfterRollbackBeforePromotion = file_get_contents($second->writerAuthorityPath());
        $switched = runManagedTraefikDocumentCommand(
            $writer->writeCommandForRequiringAuthority($second, $firstAuthority, allowBootstrap: false),
        );
        $promoted = runManagedTraefikDocumentCommand(
            $writer->promoteWriterAuthorityCommandFor(
                $second->stateDirectory,
                $second->filename,
                $firstAuthority,
                $secondAuthority,
            ),
        );
        $rollbackAfterPromotion = runManagedTraefikDocumentCommand(
            $writer->rollbackCommandForRequiringPredecessorAuthority($second, $firstAuthority),
        );

        expect($legacyFirst->isSuccessful())->toBeTrue()
            ->and($absentPreWriteRollback->isSuccessful())->toBeTrue()
            ->and($authorityAbsentAfterPreWriteRollback)->toBeFalse()
            ->and($strictPreWriteRollback->isSuccessful())->toBeFalse()
            ->and($bootstrapped->isSuccessful())->toBeTrue()
            ->and($rolledBackBeforePromotion->isSuccessful())->toBeTrue()
            ->and($documentAfterRollbackBeforePromotion)->toBe($first->replacementBytes)
            ->and($authorityAfterRollbackBeforePromotion)->toBe($firstAuthority->toJson())
            ->and($switched->isSuccessful())->toBeTrue()
            ->and($promoted->isSuccessful())->toBeTrue()
            ->and($rollbackAfterPromotion->isSuccessful())->toBeFalse()
            ->and(file_get_contents($second->documentPath()))->toBe($second->replacementBytes)
            ->and(file_get_contents($second->writerAuthorityPath()))->toBe($secondAuthority->toJson())
            ->and(fn () => $writer->rollbackCommandForRequiringPredecessorAuthority($second, $secondAuthority))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        $filesystem->remove($root);
    }
});
