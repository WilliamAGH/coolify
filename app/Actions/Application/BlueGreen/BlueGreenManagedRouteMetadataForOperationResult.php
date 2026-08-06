<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;

final readonly class BlueGreenManagedRouteMetadataForOperationResult
{
    public const ABSENT = 'absent';

    public const PENDING_EXPECTED_SIDECAR = 'pending_expected_sidecar';

    public const COMMITTED_REPLACEMENT_SIDECAR = 'committed_replacement_sidecar';

    private function __construct(
        public string $status,
        public ?BlueGreenProxyState $state,
        public ?BlueGreenProxyState $expectedState,
        public ?BlueGreenProxyState $replacementState,
        public ?string $journalSha256,
        public ?string $journalBootId,
        public ?string $managedFileState,
        public ?string $managedFileSha256,
        public ?string $mutationScriptSha256,
        public ?string $completionScriptSha256,
    ) {}

    public static function absent(?BlueGreenProxyState $state): self
    {
        return new self(
            status: self::ABSENT,
            state: $state,
            expectedState: null,
            replacementState: null,
            journalSha256: null,
            journalBootId: null,
            managedFileState: null,
            managedFileSha256: null,
            mutationScriptSha256: null,
            completionScriptSha256: null,
        );
    }

    public static function pendingExpectedSidecar(
        ?BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        string $journalSha256,
        string $journalBootId,
        string $managedFileState,
        string $managedFileSha256,
        string $mutationScriptSha256,
        string $completionScriptSha256,
    ): self {
        return new self(
            status: self::PENDING_EXPECTED_SIDECAR,
            state: $expectedState,
            expectedState: $expectedState,
            replacementState: $replacementState,
            journalSha256: $journalSha256,
            journalBootId: $journalBootId,
            managedFileState: $managedFileState,
            managedFileSha256: $managedFileSha256,
            mutationScriptSha256: $mutationScriptSha256,
            completionScriptSha256: $completionScriptSha256,
        );
    }

    public static function committedReplacementSidecar(
        ?BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        string $journalSha256,
        string $journalBootId,
        string $managedFileState,
        string $managedFileSha256,
        string $mutationScriptSha256,
        string $completionScriptSha256,
    ): self {
        return new self(
            status: self::COMMITTED_REPLACEMENT_SIDECAR,
            state: $replacementState,
            expectedState: $expectedState,
            replacementState: $replacementState,
            journalSha256: $journalSha256,
            journalBootId: $journalBootId,
            managedFileState: $managedFileState,
            managedFileSha256: $managedFileSha256,
            mutationScriptSha256: $mutationScriptSha256,
            completionScriptSha256: $completionScriptSha256,
        );
    }

    public function isAbsent(): bool
    {
        return $this->status === self::ABSENT;
    }

    public function hasPendingExpectedSidecar(): bool
    {
        return $this->status === self::PENDING_EXPECTED_SIDECAR;
    }

    public function hasCommittedReplacementSidecar(): bool
    {
        return $this->status === self::COMMITTED_REPLACEMENT_SIDECAR;
    }
}
