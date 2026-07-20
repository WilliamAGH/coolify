<?php

namespace App\Actions\Application\BlueGreen;

use Throwable;

final readonly class BlueGreenDeactivationFailure
{
    public const MAXIMUM_PUBLIC_REASON_LENGTH = 512;

    private const MAXIMUM_INTERNAL_EVIDENCE_LENGTH = 4096;

    public string $publicReason;

    public ?string $internalEvidence;

    public function __construct(string $publicReason, ?string $internalEvidence = null)
    {
        $this->publicReason = self::publicReason($publicReason);
        $this->internalEvidence = self::internalEvidence($internalEvidence);
    }

    public static function fromThrowable(Throwable $exception, string $publicReason): self
    {
        return new self($publicReason, $exception->getMessage());
    }

    public static function publicReason(string $reason): string
    {
        $normalized = preg_replace('/[\x00-\x1F\x7F]+/', ' ', trim($reason));
        $normalized = is_string($normalized) ? trim((string) preg_replace('/\s+/', ' ', $normalized)) : '';
        if ($normalized === '') {
            $normalized = 'Blue-green deactivation requires an operator recovery decision.';
        }

        if (mb_strlen($normalized) <= self::MAXIMUM_PUBLIC_REASON_LENGTH) {
            return $normalized;
        }

        return mb_substr($normalized, 0, self::MAXIMUM_PUBLIC_REASON_LENGTH - 3).'...';
    }

    private static function internalEvidence(?string $evidence): ?string
    {
        if ($evidence === null || $evidence === '') {
            return null;
        }

        return mb_strlen($evidence) <= self::MAXIMUM_INTERNAL_EVIDENCE_LENGTH
            ? $evidence
            : mb_substr($evidence, 0, self::MAXIMUM_INTERNAL_EVIDENCE_LENGTH - 3).'...';
    }
}
