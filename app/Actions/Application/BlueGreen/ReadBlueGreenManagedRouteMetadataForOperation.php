<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Models\Application;
use App\Models\Server;
use App\Models\StandaloneDocker;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Resolves only the post-commit container-journal crash window for one exact
 * operation. An archived absent-route replacement is already attested by that
 * coordinator; present routes delegate their byte proof to the strict reader.
 */
final class ReadBlueGreenManagedRouteMetadataForOperation
{
    use AsAction;

    public function handle(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        string $expectedOperationId,
    ): BlueGreenManagedRouteMetadataForOperationResult {
        return $this->handleForCurrentBoot(
            $server,
            $application,
            $destination,
            $expectedOperationId,
            null,
        );
    }

    public function handleAcrossBoot(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        string $expectedOperationId,
        string $expectedCurrentBootId,
    ): BlueGreenManagedRouteMetadataForOperationResult {
        return $this->handleForCurrentBoot(
            $server,
            $application,
            $destination,
            $expectedOperationId,
            $expectedCurrentBootId,
        );
    }

    private function handleForCurrentBoot(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        string $expectedOperationId,
        ?string $expectedCurrentBootId,
    ): BlueGreenManagedRouteMetadataForOperationResult {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $expectedOperationId) !== 1) {
            throw new BlueGreenDeploymentTransitionException('The requested blue-green recovery operation ID is invalid.');
        }

        $inspection = $this->inspectRemote(
            $server,
            $application,
            $destination,
            recoverPendingArchive: true,
            expectedCurrentBootId: $expectedCurrentBootId,
        );
        if (! $inspection->isAbsent()
            && ($inspection->replacementState === null
                || ! hash_equals($expectedOperationId, $inspection->replacementState->operationId))) {
            throw new BlueGreenDeploymentTransitionException('The committed container-mutation journal does not belong to the requested recovery operation.');
        }

        return $inspection;
    }

    /**
     * Authenticates and parses the journal before its operation owner is known.
     * Callers must independently bind the returned replacement operation to
     * durable lifecycle provenance before invoking any CAS transition.
     */
    public function inspect(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        ?string $expectedCurrentBootId = null,
    ): BlueGreenManagedRouteMetadataForOperationResult {
        return $this->inspectRemote(
            $server,
            $application,
            $destination,
            expectedCurrentBootId: $expectedCurrentBootId,
        );
    }

    private function inspectRemote(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        bool $recoverPendingArchive = false,
        ?string $expectedCurrentBootId = null,
    ): BlueGreenManagedRouteMetadataForOperationResult {
        if ((int) $destination->server_id !== (int) $server->getKey()) {
            throw new BlueGreenDeploymentTransitionException('The blue-green destination does not belong to the requested server.');
        }

        $managedFilename = BlueGreenRoutingTarget::managedFilename(
            (string) $application->uuid,
            (int) $destination->getKey(),
        );
        $writer = new WriteBlueGreenProxyConfiguration;
        $inspectionCommand = $recoverPendingArchive
            ? $writer->inspectContainerMutationJournalForOperationCommandFor(
                $server->proxyPath(),
                $managedFilename,
                $expectedCurrentBootId,
            )
            : $writer->inspectContainerMutationJournalCommandFor(
                $server->proxyPath(),
                $managedFilename,
                $expectedCurrentBootId,
            );
        $inspection = trim((string) instant_privileged_remote_script(
            $inspectionCommand,
            $server,
        ));
        if ($inspection === WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent') {
            return BlueGreenManagedRouteMetadataForOperationResult::absent(
                ReadBlueGreenManagedRouteMetadata::run($server, $application, $destination),
            );
        }

        [$header, $encodedExpectedState, $encodedReplacementState] = array_pad(
            explode("\n", $inspection, 3),
            3,
            null,
        );
        $fields = is_string($header) ? explode('|', $header) : [];
        if (count($fields) !== 8
            || $fields[0] !== WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX
            || ! in_array($fields[1], [
                BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
                BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
            ], true)
            || preg_match('/^[a-f0-9]{64}$/D', $fields[2]) !== 1
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $fields[3]) !== 1
            || ! in_array($fields[4], [
                BlueGreenProxyRollbackArtifact::MISSING_STATE,
                BlueGreenProxyRollbackArtifact::PRESENT_STATE,
            ], true)
            || preg_match('/^[a-f0-9]{64}$/D', $fields[5]) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $fields[6]) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $fields[7]) !== 1
            || ! is_string($encodedExpectedState)
            || ! is_string($encodedReplacementState)) {
            throw new BlueGreenDeploymentTransitionException('The container-mutation journal inspection returned an invalid response.');
        }

        $expectedState = $this->parseEncodedState($encodedExpectedState, allowAbsent: true);
        $replacementState = $this->parseEncodedState($encodedReplacementState, allowAbsent: false)
            ?? throw new BlueGreenDeploymentTransitionException('The committed container-mutation journal has no replacement state.');
        if ($replacementState->managedFilename !== $managedFilename
            || $replacementState->applicationUuid !== (string) $application->uuid
            || $replacementState->destinationId !== (int) $destination->getKey()
            || ($expectedState !== null && ! $expectedState->hasSameScope($replacementState))) {
            throw new BlueGreenDeploymentTransitionException('The committed container-mutation journal does not belong to the requested application destination.');
        }
        if (! $replacementState->isMutationSuccessorOf($expectedState, $replacementState->operationId)) {
            throw new BlueGreenDeploymentTransitionException('The committed container-mutation journal replacement is not the exact operation successor.');
        }
        if ($expectedState === null) {
            if ($replacementState->destinationFenceEpoch !== 0 || $replacementState->managedSha256 !== null) {
                throw new BlueGreenDeploymentTransitionException('The committed first container mutation is not an absent epoch-zero route adoption.');
            }
        } elseif (! $replacementState->hasSameRouteIdentity($expectedState)
            && ! $replacementState->hasSameAbsentRouteScope($expectedState)) {
            throw new BlueGreenDeploymentTransitionException('The committed container-mutation journal attempts to change the managed route identity.');
        }
        $expectedManagedFileState = $replacementState->managedSha256 === null
            ? BlueGreenProxyRollbackArtifact::MISSING_STATE
            : BlueGreenProxyRollbackArtifact::PRESENT_STATE;
        $expectedManagedChecksum = $replacementState->managedSha256 ?? hash('sha256', '');
        if ($fields[4] !== $expectedManagedFileState
            || ! hash_equals($expectedManagedChecksum, $fields[5])) {
            throw new BlueGreenDeploymentTransitionException('The committed container-mutation journal metadata does not match its replacement state.');
        }

        $resultArguments = [
            'expectedState' => $expectedState,
            'replacementState' => $replacementState,
            'journalSha256' => $fields[2],
            'journalBootId' => $fields[3],
            'managedFileState' => $fields[4],
            'managedFileSha256' => $fields[5],
            'mutationScriptSha256' => $fields[6],
            'completionScriptSha256' => $fields[7],
        ];

        return $fields[1] === BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR
            ? BlueGreenManagedRouteMetadataForOperationResult::pendingExpectedSidecar(...$resultArguments)
            : BlueGreenManagedRouteMetadataForOperationResult::committedReplacementSidecar(...$resultArguments);
    }

    public function archivePendingExpectedSidecar(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        string $expectedOperationId,
        BlueGreenManagedRouteMetadataForOperationResult $inspection,
        ?string $expectedCurrentBootId = null,
    ): ?BlueGreenProxyState {
        $managedFilename = $this->assertJournalInspectionBinding(
            $server,
            $application,
            $destination,
            $expectedOperationId,
            $inspection,
            BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
        );
        $writer = new WriteBlueGreenProxyConfiguration;
        $output = trim((string) instant_privileged_remote_script(
            $writer->archivePendingContainerMutationJournalCommandFor(
                proxyPath: $server->proxyPath(),
                managedFilename: $managedFilename,
                expectedJournalSha256: (string) $inspection->journalSha256,
                expectedJournalBootId: (string) $inspection->journalBootId,
                expectedState: $inspection->expectedState,
                replacementState: $inspection->replacementState
                    ?? throw new BlueGreenDeploymentTransitionException('The pending container-mutation journal has no replacement state.'),
                expectedCurrentBootId: $expectedCurrentBootId,
            ),
            $server,
        ));
        $this->assertCasOutput(
            $writer,
            $managedFilename,
            $inspection,
            $output,
            BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
        );

        return $inspection->expectedState;
    }

    public function archiveCommittedReplacementSidecar(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        string $expectedOperationId,
        BlueGreenManagedRouteMetadataForOperationResult $inspection,
        ?string $expectedCurrentBootId = null,
    ): BlueGreenProxyState {
        $managedFilename = $this->assertJournalInspectionBinding(
            $server,
            $application,
            $destination,
            $expectedOperationId,
            $inspection,
            BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
        );
        $replacementState = $inspection->replacementState
            ?? throw new BlueGreenDeploymentTransitionException('The committed container-mutation journal has no replacement state.');
        $writer = new WriteBlueGreenProxyConfiguration;
        $output = trim((string) instant_privileged_remote_script(
            $writer->finalizePendingContainerMutationJournalCommandFor(
                proxyPath: $server->proxyPath(),
                managedFilename: $managedFilename,
                expectedJournalSha256: (string) $inspection->journalSha256,
                expectedJournalBootId: (string) $inspection->journalBootId,
                expectedState: $inspection->expectedState,
                replacementState: $replacementState,
                expectedCurrentBootId: $expectedCurrentBootId,
            ),
            $server,
        ));
        $this->assertCasOutput(
            $writer,
            $managedFilename,
            $inspection,
            $output,
            BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
        );

        return $replacementState;
    }

    /**
     * Archives only an already-committed sidecar. Unlike the operation-owned
     * finalization path, this refuses a journal that reverts to pending after
     * inspection and cannot write its replacement state.
     */
    public function archiveCommittedReplacementSidecarWithoutFinalization(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        string $expectedOperationId,
        BlueGreenManagedRouteMetadataForOperationResult $inspection,
        ?string $expectedCurrentBootId = null,
    ): BlueGreenProxyState {
        $managedFilename = $this->assertJournalInspectionBinding(
            $server,
            $application,
            $destination,
            $expectedOperationId,
            $inspection,
            BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
        );
        $replacementState = $inspection->replacementState
            ?? throw new BlueGreenDeploymentTransitionException('The committed container-mutation journal has no replacement state.');
        $writer = new WriteBlueGreenProxyConfiguration;
        $output = trim((string) instant_privileged_remote_script(
            $writer->archiveCommittedContainerMutationJournalCommandFor(
                proxyPath: $server->proxyPath(),
                managedFilename: $managedFilename,
                expectedJournalSha256: (string) $inspection->journalSha256,
                expectedState: $inspection->expectedState,
                replacementState: $replacementState,
                expectedJournalBootId: (string) $inspection->journalBootId,
                expectedCurrentBootId: $expectedCurrentBootId,
            ),
            $server,
        ));
        $this->assertCommittedArchiveOutput(
            $writer,
            $managedFilename,
            $inspection,
            $output,
        );

        return $replacementState;
    }

    /**
     * Call only after independently proving canonical runtime postconditions
     * against this exact operation-bound inspection.
     */
    public function finalizePendingReplacementSidecar(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        string $expectedOperationId,
        BlueGreenManagedRouteMetadataForOperationResult $inspection,
        ?string $expectedCurrentBootId = null,
    ): BlueGreenProxyState {
        $managedFilename = $this->assertJournalInspectionBinding(
            $server,
            $application,
            $destination,
            $expectedOperationId,
            $inspection,
            BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
        );
        $replacementState = $inspection->replacementState
            ?? throw new BlueGreenDeploymentTransitionException('The pending container-mutation journal has no replacement state.');
        $writer = new WriteBlueGreenProxyConfiguration;
        $output = trim((string) instant_privileged_remote_script(
            $writer->finalizePendingContainerMutationJournalCommandFor(
                proxyPath: $server->proxyPath(),
                managedFilename: $managedFilename,
                expectedJournalSha256: (string) $inspection->journalSha256,
                expectedJournalBootId: (string) $inspection->journalBootId,
                expectedState: $inspection->expectedState,
                replacementState: $replacementState,
                expectedCurrentBootId: $expectedCurrentBootId,
            ),
            $server,
        ));
        $this->assertCasOutput(
            $writer,
            $managedFilename,
            $inspection,
            $output,
            BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
        );

        return $replacementState;
    }

    private function assertJournalInspectionBinding(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        string $expectedOperationId,
        BlueGreenManagedRouteMetadataForOperationResult $inspection,
        string $expectedStatus,
    ): string {
        if ($inspection->status !== $expectedStatus
            || $inspection->journalSha256 === null
            || $inspection->journalBootId === null
            || $inspection->replacementState === null) {
            throw new BlueGreenDeploymentTransitionException('The container-mutation journal inspection cannot perform the requested transition.');
        }
        if ((int) $destination->server_id !== (int) $server->getKey()) {
            throw new BlueGreenDeploymentTransitionException('The blue-green destination does not belong to the requested server.');
        }
        $managedFilename = BlueGreenRoutingTarget::managedFilename(
            (string) $application->uuid,
            (int) $destination->getKey(),
        );
        $replacementState = $inspection->replacementState;
        if ($replacementState->managedFilename !== $managedFilename
            || $replacementState->applicationUuid !== (string) $application->uuid
            || $replacementState->destinationId !== (int) $destination->getKey()
            || ! hash_equals($expectedOperationId, $replacementState->operationId)
            || ! $replacementState->isMutationSuccessorOf($inspection->expectedState, $expectedOperationId)) {
            throw new BlueGreenDeploymentTransitionException('The container-mutation journal inspection is not bound to the requested operation.');
        }

        return $managedFilename;
    }

    private function assertCasOutput(
        WriteBlueGreenProxyConfiguration $writer,
        string $managedFilename,
        BlueGreenManagedRouteMetadataForOperationResult $inspection,
        string $output,
        string $expectedStatus,
    ): void {
        $expectedOutput = implode('|', [
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
            $expectedStatus,
            $inspection->journalSha256,
            $writer->containerMutationJournalArchiveFilename(
                $managedFilename,
                (string) $inspection->journalSha256,
            ),
        ]);
        if (! hash_equals($expectedOutput, $output)) {
            throw new BlueGreenDeploymentTransitionException('The container-mutation journal CAS returned an invalid response.');
        }
    }

    private function assertCommittedArchiveOutput(
        WriteBlueGreenProxyConfiguration $writer,
        string $managedFilename,
        BlueGreenManagedRouteMetadataForOperationResult $inspection,
        string $output,
    ): void {
        $expectedOutput = implode('|', [
            WriteBlueGreenProxyConfiguration::COMMITTED_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX,
            'archived',
            $inspection->journalSha256,
            $writer->committedContainerMutationJournalArchiveFilename(
                $managedFilename,
                (string) $inspection->journalSha256,
            ),
        ]);
        if (! hash_equals($expectedOutput, $output)) {
            throw new BlueGreenDeploymentTransitionException('The committed container-mutation journal archive returned an invalid response.');
        }
    }

    private function parseEncodedState(string $encodedState, bool $allowAbsent): ?BlueGreenProxyState
    {
        if ($allowAbsent && $encodedState === 'absent') {
            return null;
        }
        $serializedState = base64_decode($encodedState, strict: true);
        if ($serializedState === false) {
            throw new BlueGreenDeploymentTransitionException('The committed container-mutation journal state is not valid base64.');
        }

        try {
            return BlueGreenProxyState::parse($serializedState);
        } catch (InvalidArgumentException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The committed container-mutation journal state is not a valid destination fence.',
                previous: $exception,
            );
        }
    }
}
