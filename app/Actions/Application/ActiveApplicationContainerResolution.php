<?php

namespace App\Actions\Application;

final readonly class ActiveApplicationContainerResolution
{
    private function __construct(
        private ?string $expectedContainerName,
        private ?string $failureReason,
        private string $mode,
        private string $applicationUuid,
    ) {}

    public static function standard(): self
    {
        return new self(null, null, 'standard', '');
    }

    public static function legacyBeforeFirstPromotion(string $applicationUuid): self
    {
        return new self(null, null, 'legacy-before-first-promotion', $applicationUuid);
    }

    public static function expected(string $containerName): self
    {
        return new self(ltrim(trim($containerName), '/'), null, 'expected', '');
    }

    public static function noPublicContainer(): self
    {
        return new self(null, null, 'no-public-container', '');
    }

    public static function failed(string $reason): self
    {
        return new self(null, $reason, 'failed', '');
    }

    public function accepts(?string $containerName): bool
    {
        if ($this->mode === 'standard') {
            return true;
        }

        $normalizedContainerName = $this->normalizeContainerName($containerName);
        if ($normalizedContainerName === null || $this->failureReason !== null || $this->mode === 'no-public-container') {
            return false;
        }

        if ($this->expectedContainerName !== null) {
            return $normalizedContainerName === $this->expectedContainerName;
        }

        if ($this->mode === 'legacy-before-first-promotion') {
            return ! in_array($normalizedContainerName, [
                $this->applicationUuid.'-blue',
                $this->applicationUuid.'-green',
            ], true);
        }

        return true;
    }

    public function expectedContainerName(): ?string
    {
        return $this->expectedContainerName;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    public function requiresExpectedContainer(): bool
    {
        return $this->expectedContainerName !== null;
    }

    public function requiresUnambiguousContainer(): bool
    {
        return $this->mode !== 'standard'
            && $this->mode !== 'no-public-container'
            && $this->failureReason === null;
    }

    public function hasNoPublicContainer(): bool
    {
        return $this->mode === 'no-public-container';
    }

    public function failsClosed(): bool
    {
        return $this->failureReason !== null;
    }

    private function normalizeContainerName(?string $containerName): ?string
    {
        $normalizedContainerName = ltrim(trim((string) $containerName), '/');

        return $normalizedContainerName === '' ? null : $normalizedContainerName;
    }
}
