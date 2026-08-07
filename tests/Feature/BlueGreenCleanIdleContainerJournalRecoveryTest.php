<?php

use App\Actions\Application\BlueGreen\AttestBlueGreenDestinationState;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\BlueGreenManagedRouteMetadataForOperationResult;
use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\RecoverCleanIdleBlueGreenContainerMutationJournal;
use App\Actions\Application\BlueGreen\RepairBlueGreenSteadyStates;
use App\Actions\Application\BlueGreen\ResolveBlueGreenExpectedProxyState;
use App\Actions\Application\BlueGreen\VerifyBlueGreenCandidateReleaseProof;
use App\Actions\Proxy\BlueGreenActiveReplica;
use App\Actions\Proxy\BlueGreenActiveReplicaSet;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
});

function cleanIdleJournalStateWithOperation(BlueGreenProxyState $state, string $operationId): BlueGreenProxyState
{
    return new BlueGreenProxyState(
        managedFilename: $state->managedFilename,
        applicationUuid: $state->applicationUuid,
        destinationId: $state->destinationId,
        operationId: $operationId,
        mutationSequence: 1,
        destinationFenceEpoch: $state->destinationFenceEpoch,
        routingRevision: $state->routingRevision,
        managedSha256: $state->managedSha256,
        activeColor: $state->activeColor,
        activeDeploymentUuid: $state->activeDeploymentUuid,
        activeContainerName: $state->activeContainerName,
        activeContainerId: $state->activeContainerId,
        applicationRoutingConfigDigest: $state->applicationRoutingConfigDigest,
        destinationTopologyDigest: $state->destinationTopologyDigest,
        activeContainerSet: $state->activeContainerSet,
        activeReplicaSetDigest: $state->activeReplicaSetDigest,
        activeReplicaSet: $state->activeReplicaSet,
    );
}

function isCleanIdleBootIdentityRead(string $payload): bool
{
    return str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id");
}

it('archives an exact committed clean idle journal after a reboot and reproving the active runtime identity', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ...ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
        'legacy_container_name' => null,
        'phase' => BlueGreenDeploymentPhase::IDLE,
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
    ]);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
    ) ?? throw new RuntimeException('The clean IDLE fixture requires an exact managed route.');
    $journalExpectedState = cleanIdleJournalStateWithOperation($expectedState, 'clean-idle-predecessor-operation');
    $journalSha256 = hash('sha256', 'clean-idle-committed-journal');
    $journalBootId = (string) $scenario->deployment->blue_green_server_boot_id;
    $currentBootId = 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff';
    $journalPresent = true;
    $payloads = [];

    Process::fake(function (PendingProcess $process) use (
        $currentBootId,
        $expectedState,
        $journalExpectedState,
        $journalBootId,
        $journalSha256,
        &$journalPresent,
        &$payloads,
    ) {
        $payload = (string) $process->command."\n".(string) $process->input;
        $payloads[] = $payload;
        if (isCleanIdleBootIdentityRead($payload)) {
            return Process::result(output: $currentBootId);
        }
        if (str_contains($payload, 'committed_container_manifest_stage=')) {
            $journalPresent = false;

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::COMMITTED_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX,
                'archived',
                $journalSha256,
                (new WriteBlueGreenProxyConfiguration)->committedContainerMutationJournalArchiveFilename(
                    $expectedState->managedFilename,
                    $journalSha256,
                ),
            ]));
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            if (! $journalPresent) {
                return Process::result(output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent');
            }

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
                $journalSha256,
                $journalBootId,
                BlueGreenProxyRollbackArtifact::PRESENT_STATE,
                $expectedState->managedSha256,
                hash('sha256', 'clean-idle-mutation-script'),
                hash('sha256', 'clean-idle-completion-script'),
            ])."\n".base64_encode($journalExpectedState->serialize())."\n".base64_encode($expectedState->serialize()));
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($expectedState->serialize())."\n".$expectedState->managedSha256);
        }

        return Process::result(errorOutput: 'Unexpected clean-IDLE journal recovery command.', exitCode: 1);
    });
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->withArgs(fn (Server $server, BlueGreenContainerExpectation $expectation): bool => $server->is($scenario->server)
            && $expectation->name === $expectedState->activeContainerName
            && $expectation->dockerId === $expectedState->activeContainerId
            && $expectation->deploymentUuid === $expectedState->activeDeploymentUuid
            && $expectation->routingRevision === $expectedState->routingRevision)
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectedState->activeContainerId,
            status: 'running',
            health: 'healthy',
        ));

    $recovered = RecoverCleanIdleBlueGreenContainerMutationJournal::run(
        $scenario->server,
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
    );
    $allPayloads = implode("\n", $payloads);

    expect($recovered)->toBeInstanceOf(BlueGreenProxyState::class)
        ->and($recovered?->serialize())->toBe($expectedState->serialize())
        ->and($journalPresent)->toBeFalse()
        ->and($allPayloads)->toContain("tr -d '\\n' < /proc/sys/kernel/random/boot_id")
        ->not->toContain(
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        );
});

it('does not select a clean idle recovery row outside the exact application destination scope', function (): void {
    $applicationScenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $foreignServer = Server::factory()->create();
    $foreignDestination = $foreignServer->standaloneDockers()->firstOrFail();
    Process::fake();

    expect(fn () => RepairBlueGreenSteadyStates::make()->recoverCleanIdleContainerMutationJournal(
        $applicationScenario->application,
        $foreignDestination,
    ))->toThrow(
        BlueGreenDeploymentTransitionException::class,
        'has no durable state for this application destination',
    );

    Process::assertNothingRan();
});

it('fails closed when a committed clean idle journal becomes pending before archival', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ...ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
        'legacy_container_name' => null,
        'phase' => BlueGreenDeploymentPhase::IDLE,
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
    ]);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
    ) ?? throw new RuntimeException('The clean IDLE race fixture requires an exact managed route.');
    $journalExpectedState = cleanIdleJournalStateWithOperation($expectedState, 'clean-idle-race-predecessor');
    $journalSha256 = hash('sha256', 'clean-idle-committed-to-pending-race');
    $journalBootId = (string) $scenario->deployment->blue_green_server_boot_id;
    $committedJournalInspected = false;
    $committedOnlyArchiveAttempted = false;
    $replacementWriteAttempted = false;
    $payloads = [];

    Process::fake(function (PendingProcess $process) use (
        $expectedState,
        $journalExpectedState,
        $journalBootId,
        $journalSha256,
        &$committedJournalInspected,
        &$committedOnlyArchiveAttempted,
        &$replacementWriteAttempted,
        &$payloads,
    ) {
        $payload = (string) $process->command."\n".(string) $process->input;
        $payloads[] = $payload;
        if (isCleanIdleBootIdentityRead($payload)) {
            return Process::result(output: $journalBootId);
        }
        if (str_contains($payload, 'operation_container_manifest_stage=')) {
            $replacementWriteAttempted = str_contains($payload, 'operation_container_state_stage=')
                || str_contains($payload, 'durable_remote_replace "$operation_container_state_stage"');

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
                $journalSha256,
                (new WriteBlueGreenProxyConfiguration)->containerMutationJournalArchiveFilename(
                    $expectedState->managedFilename,
                    $journalSha256,
                ),
            ]));
        }
        if (str_contains($payload, 'committed_container_manifest_stage=')) {
            $committedOnlyArchiveAttempted = true;
            $replacementWriteAttempted = $replacementWriteAttempted
                || str_contains($payload, 'committed_container_state_stage=')
                || str_contains($payload, 'durable_remote_replace "$committed_container_state_stage"');

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::COMMITTED_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
                $journalSha256,
                (new WriteBlueGreenProxyConfiguration)->committedContainerMutationJournalArchiveFilename(
                    $expectedState->managedFilename,
                    $journalSha256,
                ),
            ]));
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            $committedJournalInspected = true;

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
                $journalSha256,
                $journalBootId,
                BlueGreenProxyRollbackArtifact::PRESENT_STATE,
                $expectedState->managedSha256,
                hash('sha256', 'clean-idle-race-mutation-script'),
                hash('sha256', 'clean-idle-race-completion-script'),
            ])."\n".base64_encode($journalExpectedState->serialize())."\n".base64_encode($expectedState->serialize()));
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($expectedState->serialize())."\n".$expectedState->managedSha256);
        }

        return Process::result(errorOutput: 'Unexpected clean-IDLE committed-to-pending race command.', exitCode: 1);
    });
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectedState->activeContainerId,
            status: 'running',
            health: 'healthy',
        ));

    expect(fn () => RecoverCleanIdleBlueGreenContainerMutationJournal::run(
        $scenario->server,
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
    ))->toThrow(BlueGreenDeploymentTransitionException::class)
        ->and($committedJournalInspected)->toBeTrue()
        ->and($committedOnlyArchiveAttempted)->toBeTrue()
        ->and($replacementWriteAttempted)->toBeFalse()
        ->and(implode("\n", $payloads))
        ->not->toContain(
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
            'sh "$committed_container_mutation_decoded"',
            'sh "$committed_container_completion_decoded"',
        );
});

it('archives a committed clean idle replica journal only after proving every durable replica slot', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ...ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
        'legacy_container_name' => null,
        'phase' => BlueGreenDeploymentPhase::IDLE,
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
    ]);
    $inspections = collect([1, 2])->map(function (int $replicaIndex) use ($scenario): BlueGreenReplicaInspection {
        $composeService = "{$scenario->application->uuid}-blue-replica-{$replicaIndex}";

        return BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $replicaIndex,
            composeService: $composeService,
            containerName: $composeService,
            dockerId: str_repeat((string) $replicaIndex, 64),
            status: 'running',
            health: 'healthy',
        );
    })->all();
    $durableActiveReplicaSet = BlueGreenActiveReplicaSet::fromMembers(array_map(
        static fn (BlueGreenReplicaInspection $inspection): BlueGreenActiveReplica => new BlueGreenActiveReplica(
            composeService: $inspection->composeService,
            replicaIndex: $inspection->replicaIndex,
            ports: [3000],
            name: $inspection->containerName,
            id: $inspection->dockerId,
        ),
        $inspections,
    ));
    $scenario->deployment->update([
        'blue_green_candidate_container_id' => $durableActiveReplicaSet->identityDigest(),
    ]);
    foreach ($inspections as $inspection) {
        ApplicationBlueGreenReplica::query()->create([
            'application_blue_green_deployment_id' => $scenario->state->id,
            'application_id' => $scenario->application->id,
            'standalone_docker_id' => $scenario->destination->id,
            'color' => BlueGreenDeploymentColor::BLUE,
            'replica_index' => $inspection->replicaIndex,
            'deployment_uuid' => $scenario->deployment->deployment_uuid,
            'routing_revision' => 1,
            'compose_project' => $scenario->application->uuid,
            'compose_service' => $inspection->composeService,
            'container_name' => $inspection->containerName,
            'container_id' => $inspection->dockerId,
            'health_status' => 'healthy',
            'last_observed_at' => now()->subMinute(),
        ]);
    }
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
    ) ?? throw new RuntimeException('The replica clean IDLE fixture requires an exact managed route.');
    $journalExpectedState = cleanIdleJournalStateWithOperation($expectedState, 'replica-clean-idle-predecessor');
    $journalSha256 = hash('sha256', 'replica-clean-idle-committed-journal');
    $journalBootId = (string) $scenario->deployment->blue_green_server_boot_id;
    $journalPresent = true;
    $replicaInspectionRequested = false;
    $payloads = [];
    $runtimeById = collect($inspections)->mapWithKeys(fn (BlueGreenReplicaInspection $inspection): array => [
        $inspection->dockerId => json_encode([
            'Id' => $inspection->dockerId,
            'Name' => '/'.$inspection->containerName,
            'State' => [
                'Status' => $inspection->status,
                'Health' => ['Status' => $inspection->health],
            ],
            'Config' => ['Labels' => [
                'coolify.applicationId' => (string) $scenario->application->id,
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => $scenario->deployment->deployment_uuid,
                'coolify.blueGreen.color' => BlueGreenDeploymentColor::BLUE->value,
                'coolify.blueGreen.routingRevision' => '1',
                'coolify.blueGreen.replicaIndex' => (string) $inspection->replicaIndex,
                'coolify.blueGreen.replicaCount' => '2',
                'com.docker.compose.project' => $scenario->application->uuid,
                'com.docker.compose.service' => $inspection->composeService,
            ]],
        ], JSON_THROW_ON_ERROR),
    ]);
    $runtimeOutput = $runtimeById->implode("\n");
    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken($scenario->deployment->deployment_uuid);

    Process::fake(function (PendingProcess $process) use (
        $expectedState,
        $journalExpectedState,
        $journalBootId,
        $journalSha256,
        &$journalPresent,
        &$payloads,
        &$replicaInspectionRequested,
        $releaseProof,
        $runtimeById,
        $runtimeOutput,
    ) {
        $payload = (string) $process->command."\n".(string) $process->input;
        $payloads[] = $payload;
        if (isCleanIdleBootIdentityRead($payload)) {
            return Process::result(output: $journalBootId);
        }
        if (str_contains($payload, 'committed_container_manifest_stage=')) {
            $journalPresent = false;

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::COMMITTED_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX,
                'archived',
                $journalSha256,
                (new WriteBlueGreenProxyConfiguration)->committedContainerMutationJournalArchiveFilename(
                    $expectedState->managedFilename,
                    $journalSha256,
                ),
            ]));
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            if (! $journalPresent) {
                return Process::result(output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent');
            }

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
                $journalSha256,
                $journalBootId,
                BlueGreenProxyRollbackArtifact::PRESENT_STATE,
                $expectedState->managedSha256,
                hash('sha256', 'replica-clean-idle-mutation-script'),
                hash('sha256', 'replica-clean-idle-completion-script'),
            ])."\n".base64_encode($journalExpectedState->serialize())."\n".base64_encode($expectedState->serialize()));
        }
        if (str_contains($payload, 'coolify_replica_')) {
            $replicaInspectionRequested = true;

            return Process::result(output: $runtimeOutput);
        }
        if (str_contains($payload, VerifyBlueGreenCandidateReleaseProof::LABEL)) {
            return Process::result(output: json_encode([
                VerifyBlueGreenCandidateReleaseProof::ENVIRONMENT_VARIABLE.'='.$releaseProof,
            ], JSON_THROW_ON_ERROR));
        }
        foreach ($runtimeById as $dockerId => $runtime) {
            if (str_contains($payload, "docker container inspect '{$dockerId}'")) {
                return Process::result(output: $runtime);
            }
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($expectedState->serialize())."\n".$expectedState->managedSha256);
        }

        return Process::result(errorOutput: 'Unexpected replica clean-IDLE journal recovery command.', exitCode: 1);
    });
    $recovered = RecoverCleanIdleBlueGreenContainerMutationJournal::run(
        $scenario->server,
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
    );

    $allPayloads = implode("\n", $payloads);
    expect($recovered?->serialize())->toBe($expectedState->serialize())
        ->and($expectedState->activeContainerId)->toBe($durableActiveReplicaSet->representative()->id)
        ->and($expectedState->activeReplicaSetDigest)->toBe($durableActiveReplicaSet->identityDigest())
        ->and($replicaInspectionRequested)->toBeTrue()
        ->and($journalPresent)->toBeFalse()
        ->and($allPayloads)->toContain(
            'coolify.blueGreen.replicaIndex=1',
            'coolify.blueGreen.replicaIndex=2',
            $inspections[0]->composeService,
            $inspections[1]->composeService,
        )
        ->not->toContain(
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        );
});

it('keeps pending foreign boot-mismatched deactivation-only and unhealthy clean idle journals fenced', function (string $failure): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ...ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
        'legacy_container_name' => null,
        'phase' => BlueGreenDeploymentPhase::IDLE,
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
    ]);
    if ($failure === 'deactivation') {
        $deactivationOperationId = str_repeat('d', 64);
        $scenario->state->update(['destination_fence_operation_id' => $deactivationOperationId]);
        ApplicationBlueGreenDeactivation::query()->create([
            'application_id' => $scenario->application->id,
            'standalone_docker_id' => $scenario->destination->id,
            'operation_id' => $deactivationOperationId,
            'started_at' => now()->subMinutes(2),
            'queue_cutoff_id' => $scenario->deployment->id,
            'supersession_generation' => 1,
            'phase' => BlueGreenDeactivationPhase::COMPLETED,
            'completed_at' => now()->subMinute(),
        ]);
    }
    $durableState = ResolveBlueGreenExpectedProxyState::run(
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
    ) ?? throw new RuntimeException('The fenced clean IDLE fixture requires an exact managed route.');
    $journalExpectedState = cleanIdleJournalStateWithOperation($durableState, 'clean-idle-fenced-predecessor');
    $replacementState = $failure === 'foreign'
        ? cleanIdleJournalStateWithOperation($durableState, 'foreign-clean-idle-operation')
        : $durableState;
    $journalStatus = $failure === 'pending'
        ? BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR
        : BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR;
    $currentBootId = (string) $scenario->deployment->blue_green_server_boot_id;
    $journalBootId = $failure === 'boot'
        ? 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'
        : $currentBootId;
    $journalSha256 = hash('sha256', "clean-idle-fenced-{$failure}");
    $casAttempted = false;

    Process::fake(function (PendingProcess $process) use (
        &$casAttempted,
        $currentBootId,
        $journalBootId,
        $journalExpectedState,
        $journalSha256,
        $journalStatus,
        $replacementState,
    ) {
        $payload = (string) $process->command."\n".(string) $process->input;
        if (isCleanIdleBootIdentityRead($payload)) {
            return Process::result(output: $currentBootId);
        }
        if (str_contains($payload, 'operation_container_manifest_stage=')) {
            $casAttempted = true;

            return Process::result(errorOutput: 'An ambiguous journal must not reach CAS.', exitCode: 1);
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                $journalStatus,
                $journalSha256,
                $journalBootId,
                BlueGreenProxyRollbackArtifact::PRESENT_STATE,
                $replacementState->managedSha256,
                hash('sha256', "clean-idle-fenced-{$journalStatus}-mutation"),
                hash('sha256', "clean-idle-fenced-{$journalStatus}-completion"),
            ])."\n".base64_encode($journalExpectedState->serialize())."\n".base64_encode($replacementState->serialize()));
        }

        return Process::result(errorOutput: 'Unexpected fenced clean-IDLE recovery command.', exitCode: 1);
    });
    if ($failure === 'runtime') {
        InspectBlueGreenContainer::shouldRun()
            ->once()
            ->andReturn(new BlueGreenContainerInspection(
                exists: true,
                dockerId: $durableState->activeContainerId,
                status: 'running',
                health: 'unhealthy',
            ));
    } else {
        InspectBlueGreenContainer::shouldNotRun();
    }

    expect(fn () => RecoverCleanIdleBlueGreenContainerMutationJournal::run(
        $scenario->server,
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
    ))->toThrow(BlueGreenDeploymentTransitionException::class)
        ->and($casAttempted)->toBeFalse();
})->with([
    'pending expected sidecar remains ambiguous' => ['pending'],
    'foreign operation has no durable owner' => ['foreign'],
    'journal boot does not bind to its terminal queue' => ['boot'],
    'completed deactivation has no journal boot provenance' => ['deactivation'],
    'exact active runtime is not healthy' => ['runtime'],
]);

it('invokes the same clean idle coordinator from ordinary destination attestation', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ...ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
        'legacy_container_name' => null,
        'phase' => BlueGreenDeploymentPhase::IDLE,
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
    ]);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
    ) ?? throw new RuntimeException('The ordinary attestation fixture requires an exact managed route.');
    $journalExpectedState = cleanIdleJournalStateWithOperation($expectedState, 'ordinary-attestation-predecessor');
    $journalSha256 = hash('sha256', 'ordinary-attestation-committed-journal');
    $journalBootId = (string) $scenario->deployment->blue_green_server_boot_id;
    $journalPresent = true;
    Process::fake(function (PendingProcess $process) use (
        $expectedState,
        $journalBootId,
        $journalExpectedState,
        $journalSha256,
        &$journalPresent,
    ) {
        $payload = (string) $process->command."\n".(string) $process->input;
        if (isCleanIdleBootIdentityRead($payload)) {
            return Process::result(output: $journalBootId);
        }
        if (str_contains($payload, 'coolify-blue-green-destination-state-attested')) {
            return Process::result(
                errorOutput: WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT,
                exitCode: 75,
            );
        }
        if (str_contains($payload, 'committed_container_manifest_stage=')) {
            $journalPresent = false;

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::COMMITTED_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX,
                'archived',
                $journalSha256,
                (new WriteBlueGreenProxyConfiguration)->committedContainerMutationJournalArchiveFilename(
                    $expectedState->managedFilename,
                    $journalSha256,
                ),
            ]));
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
                $journalSha256,
                $journalBootId,
                BlueGreenProxyRollbackArtifact::PRESENT_STATE,
                $expectedState->managedSha256,
                hash('sha256', 'ordinary-attestation-mutation'),
                hash('sha256', 'ordinary-attestation-completion'),
            ])."\n".base64_encode($journalExpectedState->serialize())."\n".base64_encode($expectedState->serialize()));
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($expectedState->serialize())."\n".$expectedState->managedSha256);
        }

        return Process::result(errorOutput: 'Unexpected ordinary attestation recovery command.', exitCode: 1);
    });
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectedState->activeContainerId,
            status: 'running',
            health: 'healthy',
        ));

    $attested = AttestBlueGreenDestinationState::run(
        $scenario->server,
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
    );

    expect($attested?->serialize())->toBe($expectedState->serialize())
        ->and($journalPresent)->toBeFalse();
});

it('proves a released fan-out route carrying the legacy aggregate digest against its exact live replica set', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ...ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
        'legacy_container_name' => null,
        'phase' => BlueGreenDeploymentPhase::IDLE,
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
    ]);
    $inspections = collect([1, 2])->map(function (int $replicaIndex) use ($scenario): BlueGreenReplicaInspection {
        $composeService = "{$scenario->application->uuid}-blue-replica-{$replicaIndex}";

        return BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $replicaIndex,
            composeService: $composeService,
            containerName: $composeService,
            dockerId: str_repeat((string) $replicaIndex, 64),
            status: 'running',
            health: 'healthy',
        );
    })->all();
    $legacyAggregateDigest = BlueGreenReplicaSet::identityDigest($inspections);
    $scenario->deployment->update([
        'blue_green_candidate_container_id' => $legacyAggregateDigest,
    ]);
    foreach ($inspections as $inspection) {
        ApplicationBlueGreenReplica::query()->create([
            'application_blue_green_deployment_id' => $scenario->state->id,
            'application_id' => $scenario->application->id,
            'standalone_docker_id' => $scenario->destination->id,
            'color' => BlueGreenDeploymentColor::BLUE,
            'replica_index' => $inspection->replicaIndex,
            'deployment_uuid' => $scenario->deployment->deployment_uuid,
            'routing_revision' => 1,
            'compose_project' => $scenario->application->uuid,
            'compose_service' => $inspection->composeService,
            'container_name' => $inspection->containerName,
            'container_id' => $inspection->dockerId,
            'health_status' => 'healthy',
            'last_observed_at' => now()->subMinute(),
        ]);
    }
    $canonicalState = ResolveBlueGreenExpectedProxyState::run(
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
    ) ?? throw new RuntimeException('The released fan-out fixture requires an exact managed route.');
    // The exact bytes a released v2 fan-out writer persisted: the fixed colour
    // name and the legacy aggregate digest, with no v4 replica-set fields.
    $releasedState = new BlueGreenProxyState(
        managedFilename: $canonicalState->managedFilename,
        applicationUuid: $canonicalState->applicationUuid,
        destinationId: $canonicalState->destinationId,
        operationId: $canonicalState->operationId,
        mutationSequence: $canonicalState->mutationSequence,
        destinationFenceEpoch: $canonicalState->destinationFenceEpoch,
        routingRevision: $canonicalState->routingRevision,
        managedSha256: $canonicalState->managedSha256,
        activeColor: $canonicalState->activeColor,
        activeDeploymentUuid: $canonicalState->activeDeploymentUuid,
        activeContainerName: $scenario->application->uuid.'-blue',
        activeContainerId: $legacyAggregateDigest,
        applicationRoutingConfigDigest: $canonicalState->applicationRoutingConfigDigest,
        destinationTopologyDigest: $canonicalState->destinationTopologyDigest,
    );
    $impostorState = new BlueGreenProxyState(
        managedFilename: $releasedState->managedFilename,
        applicationUuid: $releasedState->applicationUuid,
        destinationId: $releasedState->destinationId,
        operationId: $releasedState->operationId,
        mutationSequence: $releasedState->mutationSequence,
        destinationFenceEpoch: $releasedState->destinationFenceEpoch,
        routingRevision: $releasedState->routingRevision,
        managedSha256: $releasedState->managedSha256,
        activeColor: $releasedState->activeColor,
        activeDeploymentUuid: $releasedState->activeDeploymentUuid,
        activeContainerName: $releasedState->activeContainerName,
        activeContainerId: hash('sha256', 'released-fan-out-impostor-identity'),
        applicationRoutingConfigDigest: $releasedState->applicationRoutingConfigDigest,
        destinationTopologyDigest: $releasedState->destinationTopologyDigest,
    );
    $runtimeById = collect($inspections)->mapWithKeys(fn (BlueGreenReplicaInspection $inspection): array => [
        $inspection->dockerId => json_encode([
            'Id' => $inspection->dockerId,
            'Name' => '/'.$inspection->containerName,
            'State' => [
                'Status' => $inspection->status,
                'Health' => ['Status' => $inspection->health],
            ],
            'Config' => ['Labels' => [
                'coolify.applicationId' => (string) $scenario->application->id,
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => $scenario->deployment->deployment_uuid,
                'coolify.blueGreen.color' => BlueGreenDeploymentColor::BLUE->value,
                'coolify.blueGreen.routingRevision' => '1',
                'coolify.blueGreen.replicaIndex' => (string) $inspection->replicaIndex,
                'coolify.blueGreen.replicaCount' => '2',
                'com.docker.compose.project' => $scenario->application->uuid,
                'com.docker.compose.service' => $inspection->composeService,
            ]],
        ], JSON_THROW_ON_ERROR),
    ]);
    $runtimeOutput = $runtimeById->implode("\n");
    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken($scenario->deployment->deployment_uuid);
    Process::fake(function (PendingProcess $process) use ($releaseProof, $runtimeById, $runtimeOutput) {
        $payload = (string) $process->command."\n".(string) $process->input;
        if (str_contains($payload, 'coolify_replica_')) {
            return Process::result(output: $runtimeOutput);
        }
        if (str_contains($payload, VerifyBlueGreenCandidateReleaseProof::LABEL)) {
            return Process::result(output: json_encode([
                VerifyBlueGreenCandidateReleaseProof::ENVIRONMENT_VARIABLE.'='.$releaseProof,
            ], JSON_THROW_ON_ERROR));
        }
        foreach ($runtimeById as $dockerId => $runtime) {
            if (str_contains($payload, "docker container inspect '{$dockerId}'")) {
                return Process::result(output: $runtime);
            }
        }

        return Process::result(errorOutput: 'Unexpected released fan-out runtime proof command.', exitCode: 1);
    });
    $proveActiveRuntime = function (BlueGreenProxyState $expectedState) use ($scenario): void {
        (new ReflectionMethod(RecoverCleanIdleBlueGreenContainerMutationJournal::class, 'assertExactActiveRuntime'))->invoke(
            new RecoverCleanIdleBlueGreenContainerMutationJournal,
            $scenario->server,
            $scenario->application,
            $scenario->destination,
            $scenario->state->fresh(),
            $expectedState,
        );
    };

    expect($releasedState->activeSetFenceIdentity())->toBe($legacyAggregateDigest)
        ->and($legacyAggregateDigest)->not->toBe($canonicalState->activeReplicaSetDigest)
        ->and(fn () => $proveActiveRuntime($releasedState))->not->toThrow(BlueGreenDeploymentTransitionException::class)
        ->and(fn () => $proveActiveRuntime($impostorState))->toThrow(
            BlueGreenDeploymentTransitionException::class,
            'no longer matches its durable route identity',
        );
});
