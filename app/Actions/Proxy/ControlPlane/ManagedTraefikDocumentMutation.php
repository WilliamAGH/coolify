<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;

final readonly class ManagedTraefikDocumentMutation
{
    public function __construct(
        public string $dynamicDirectory,
        public string $stateDirectory,
        public string $filename,
        public string $operationId,
        public int $revision,
        public ?string $expectedSha256,
        public ?string $expectedOperationId,
        public ?int $expectedRevision,
        public string $replacementBytes,
    ) {
        $this->assertDirectory($dynamicDirectory, 'dynamic directory');
        $this->assertDirectory($stateDirectory, 'state directory');
        $this->assertFilename($filename);
        $this->assertOperationId($operationId);

        if ($revision < 1) {
            throw new InvalidArgumentException('A managed Traefik document revision must be positive.');
        }

        if ($expectedSha256 === null) {
            if ($expectedOperationId !== null || $expectedRevision !== null) {
                throw new InvalidArgumentException('An absent managed Traefik document cannot have a predecessor identity.');
            }
        } else {
            $this->assertSha256($expectedSha256, 'expected managed Traefik document checksum');

            if (($expectedOperationId === null) !== ($expectedRevision === null)) {
                throw new InvalidArgumentException('A managed Traefik document predecessor identity must be complete.');
            }

            if ($expectedOperationId !== null && $expectedRevision !== null) {
                $this->assertOperationId($expectedOperationId);
                if ($expectedRevision < 1 || $revision <= $expectedRevision) {
                    throw new InvalidArgumentException('A managed Traefik document revision must strictly advance its predecessor.');
                }
            }
        }
    }

    public function expectedSidecar(): ?string
    {
        if ($this->expectedSha256 === null || $this->expectedOperationId === null || $this->expectedRevision === null) {
            return null;
        }

        return $this->sidecar(
            operationId: $this->expectedOperationId,
            revision: $this->expectedRevision,
            sha256: $this->expectedSha256,
        );
    }

    public function replacementSha256(): string
    {
        return hash('sha256', $this->replacementBytes);
    }

    public function replacementSidecar(): string
    {
        return $this->sidecar(
            operationId: $this->operationId,
            revision: $this->revision,
            sha256: $this->replacementSha256(),
        );
    }

    public function documentPath(): string
    {
        return rtrim($this->dynamicDirectory, '/').'/'.$this->filename;
    }

    public function sidecarPath(): string
    {
        return rtrim($this->stateDirectory, '/').'/.'.$this->filename.'.state.json';
    }

    public function lockPath(): string
    {
        return rtrim($this->stateDirectory, '/').'/.'.$this->filename.'.lock';
    }

    public function journalPath(): string
    {
        return rtrim($this->stateDirectory, '/').'/.'.$this->filename.'.pending-mutation';
    }

    public function rollbackArtifactPath(): string
    {
        return rtrim($this->stateDirectory, '/').'/.'.$this->filename.'.'.$this->operationId.'.r'.$this->revision.'.rollback';
    }

    private function sidecar(string $operationId, int $revision, string $sha256): string
    {
        return json_encode([
            'version' => 1,
            'filename' => $this->filename,
            'operation_id' => $operationId,
            'revision' => $revision,
            'sha256' => $sha256,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    private function assertDirectory(string $directory, string $label): void
    {
        if (! str_starts_with($directory, '/') || str_contains($directory, "\0")) {
            throw new InvalidArgumentException("The managed Traefik {$label} must be an absolute NUL-free path.");
        }
    }

    private function assertFilename(string $filename): void
    {
        if (basename($filename) !== $filename
            || preg_match('/\A[a-z0-9][a-z0-9.-]{0,127}\.ya?ml\z/D', $filename) !== 1) {
            throw new InvalidArgumentException('The managed Traefik document filename is invalid.');
        }
    }

    private function assertOperationId(string $operationId): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,127}\z/D', $operationId) !== 1) {
            throw new InvalidArgumentException('The managed Traefik document operation ID is invalid.');
        }
    }

    private function assertSha256(string $sha256, string $label): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
            throw new InvalidArgumentException("The {$label} is invalid.");
        }
    }
}
