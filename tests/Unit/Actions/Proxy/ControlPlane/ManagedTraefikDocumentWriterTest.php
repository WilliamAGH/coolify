<?php

use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentMutation;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriter;
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
