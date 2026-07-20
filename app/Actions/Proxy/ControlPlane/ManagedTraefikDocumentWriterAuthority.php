<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;

final readonly class ManagedTraefikDocumentWriterAuthority
{
    public const MAXIMUM_SERIALIZED_BYTES = 1024;

    public function __construct(
        public int $epoch,
        public string $operationId,
        public string $member,
        public string $containerId,
        public string $containerName,
        public string $imageId,
        public int $dynamicRevision,
        public string $dynamicSha256,
    ) {
        if ($epoch < 1) {
            throw new InvalidArgumentException('A managed Traefik document writer authority epoch must be positive.');
        }
        $this->assertOperationId($operationId);
        $this->assertIdentifier($member, 'member');
        $this->assertDockerId($containerId, 'container ID');
        $this->assertContainerName($containerName);
        $this->assertImageId($imageId);
        if ($dynamicRevision < 1) {
            throw new InvalidArgumentException('A managed Traefik document writer authority dynamic revision must be positive.');
        }
        $this->assertSha256($dynamicSha256, 'dynamic checksum');

        if (strlen($this->toJson()) > self::MAXIMUM_SERIALIZED_BYTES) {
            throw new InvalidArgumentException('The managed Traefik document writer authority exceeds its bounded size.');
        }
    }

    public function toJson(): string
    {
        return json_encode([
            'epoch' => $this->epoch,
            'operation_id' => $this->operationId,
            'member' => $this->member,
            'container_id' => $this->containerId,
            'container_name' => $this->containerName,
            'image_id' => $this->imageId,
            'dynamic_revision' => $this->dynamicRevision,
            'dynamic_sha256' => $this->dynamicSha256,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    public function matchesReplacement(ManagedTraefikDocumentMutation $mutation): bool
    {
        return $this->operationId === $mutation->operationId
            && $this->dynamicRevision === $mutation->revision
            && hash_equals($this->dynamicSha256, $mutation->replacementSha256());
    }

    public function matchesPredecessor(ManagedTraefikDocumentMutation $mutation): bool
    {
        return $mutation->expectedSha256 !== null
            && $mutation->expectedWriterOperationId() !== null
            && $mutation->expectedRevision !== null
            && hash_equals($this->operationId, $mutation->expectedWriterOperationId())
            && $this->dynamicRevision === $mutation->expectedRevision
            && hash_equals($this->dynamicSha256, $mutation->expectedSha256);
    }

    public function matchesPredecessorDocument(ManagedTraefikDocumentMutation $mutation): bool
    {
        return $mutation->expectedSha256 !== null
            && $mutation->expectedRevision !== null
            && $this->dynamicRevision === $mutation->expectedRevision
            && hash_equals($this->dynamicSha256, $mutation->expectedSha256);
    }

    public function hasSameWriterIdentityAs(self $other): bool
    {
        return hash_equals($this->member, $other->member)
            && hash_equals($this->containerId, $other->containerId)
            && hash_equals($this->containerName, $other->containerName)
            && hash_equals($this->imageId, $other->imageId);
    }

    private function assertOperationId(string $operationId): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,127}\z/D', $operationId) !== 1) {
            throw new InvalidArgumentException('The managed Traefik document writer authority operation ID is invalid.');
        }
    }

    private function assertIdentifier(string $value, string $label): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The managed Traefik document writer authority {$label} is invalid.");
        }
    }

    private function assertDockerId(string $value, string $label): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The managed Traefik document writer authority {$label} must be a full Docker ID.");
        }
    }

    private function assertContainerName(string $value): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('The managed Traefik document writer authority container name is invalid.');
        }
    }

    private function assertImageId(string $value): void
    {
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('The managed Traefik document writer authority image ID must be a full sha256 image ID.');
        }
    }

    private function assertSha256(string $value, string $label): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The managed Traefik document writer authority {$label} is invalid.");
        }
    }
}
