<?php

use App\Actions\Application\BlueGreen\AttestBlueGreenDestinationState;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\BlueGreenInterventionRecoveryResult;
use App\Actions\Application\BlueGreen\BlueGreenManagedRouteMetadataForOperationResult;
use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
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
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
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

it('archives a pending journal whose owner retirement is terminal and whose live route already equals the replacement', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $journalBootId = (string) $scenario->deployment->blue_green_server_boot_id;
    $ownerDeploymentUuid = BlueGreenRecoveryScenario::OPERATION_UUID;
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
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $scenario->application->id,
        'deployment_uuid' => 'predecessor-retired-deployment',
        'pull_request_id' => 0,
        'destination_id' => $scenario->destination->id,
        'server_id' => $scenario->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinutes(2),
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 0,
        'blue_green_candidate_container_id' => BlueGreenRecoveryScenario::LEGACY_ID,
        'blue_green_topology_digest' => $scenario->state->destination_topology_digest,
        'blue_green_routing_config_digest' => $scenario->state->application_routing_config_digest,
    ]);
    // Pre-retirement managed route (fence before the drain mutation lands).
    $journalExpectedState = ResolveBlueGreenExpectedProxyState::run(
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
    ) ?? throw new RuntimeException('The terminal-retirement pending fixture requires a pre-mutation managed route.');
    $replacementState = $journalExpectedState->withMutationOwner($ownerDeploymentUuid);
    // markStopped advances the durable fence to the replacement while leaving the
    // pending journal behind when the drain crash-orphaned it.
    $scenario->state->update([
        'destination_fence_operation_id' => $replacementState->operationId,
        'destination_fence_mutation_sequence' => $replacementState->mutationSequence,
        'green_deployment_uuid' => 'predecessor-retired-deployment',
        'inactive_retirement_owner_deployment_uuid' => $ownerDeploymentUuid,
        'inactive_retirement_color' => BlueGreenDeploymentColor::GREEN->value,
        'inactive_retirement_deployment_uuid' => 'predecessor-retired-deployment',
        'inactive_retirement_container_id' => BlueGreenRecoveryScenario::LEGACY_ID,
        'inactive_retirement_container_routing_revision' => 0,
        'inactive_retirement_owner_routing_revision' => 1,
        'inactive_retirement_supersession_generation' => 1,
        'inactive_retirement_destination_fence_epoch' => 1,
        'inactive_retirement_server_boot_id' => $journalBootId,
        'inactive_retirement_topology_digest' => $scenario->state->destination_topology_digest,
        'inactive_retirement_routing_config_digest' => $scenario->state->application_routing_config_digest,
        'inactive_retirement_not_before_at' => now()->subMinutes(5),
        'inactive_retirement_drain_deadline_at' => now()->subMinutes(3),
        'inactive_retirement_stop_grace_seconds' => 30,
        'inactive_retirement_lease_seconds' => 120,
        'inactive_retirement_last_observed_connections' => 0,
        'inactive_retirement_observed_at' => now()->subMinute(),
        'inactive_retirement_attempts' => 1,
        'inactive_retirement_stopped_at' => now()->subMinute(),
        'inactive_retirement_intervention_required_at' => null,
        'inactive_retirement_dispatch_reserved_until_at' => null,
    ]);
    $durableState = ResolveBlueGreenExpectedProxyState::run(
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
    ) ?? throw new RuntimeException('The terminal-retirement pending fixture requires an exact post-retirement managed route.');
    expect(BlueGreenProxyState::matches($durableState, $replacementState))->toBeTrue()
        ->and($replacementState->isMutationSuccessorOf($journalExpectedState, $ownerDeploymentUuid))->toBeTrue();
    $journalSha256 = hash('sha256', 'terminal-retirement-pending-leftover');
    $journalPresent = true;
    $payloads = [];
    $archiveAttempted = false;
    $mutationScriptInvoked = false;
    $replacementSidecarFinalized = false;

    Process::fake(function (PendingProcess $process) use (
        $journalBootId,
        $journalExpectedState,
        $journalSha256,
        $replacementState,
        &$archiveAttempted,
        &$journalPresent,
        &$mutationScriptInvoked,
        &$payloads,
        &$replacementSidecarFinalized,
    ) {
        $payload = (string) $process->command."\n".(string) $process->input;
        $payloads[] = $payload;
        if (isCleanIdleBootIdentityRead($payload)) {
            return Process::result(output: $journalBootId);
        }
        if (str_contains($payload, 'operation_container_manifest_stage=')) {
            $archiveAttempted = true;
            $mutationScriptInvoked = $mutationScriptInvoked
                || str_contains($payload, 'sh "$operation_container_mutation_decoded"')
                || str_contains($payload, 'sh "$operation_container_completion_decoded"');
            $replacementSidecarFinalized = str_contains($payload, 'operation_container_state_stage=')
                && str_contains($payload, 'durable_remote_replace "$operation_container_state_stage"');
            $journalPresent = false;

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
                $journalSha256,
                (new WriteBlueGreenProxyConfiguration)->containerMutationJournalArchiveFilename(
                    $replacementState->managedFilename,
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
                BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
                $journalSha256,
                $journalBootId,
                BlueGreenProxyRollbackArtifact::PRESENT_STATE,
                $replacementState->managedSha256,
                hash('sha256', 'terminal-retirement-mutation-script'),
                hash('sha256', 'terminal-retirement-completion-script'),
            ])."\n".base64_encode($journalExpectedState->serialize())."\n".base64_encode($replacementState->serialize()));
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            if ($journalPresent) {
                return Process::result(output: WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT);
            }

            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($replacementState->serialize())."\n".$replacementState->managedSha256);
        }

        return Process::result(errorOutput: 'Unexpected terminal-retirement pending journal recovery command.', exitCode: 1);
    });
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $replacementState->activeContainerId,
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
        ->and($recovered?->serialize())->toBe($replacementState->serialize())
        ->and($archiveAttempted)->toBeTrue()
        ->and($journalPresent)->toBeFalse()
        ->and($mutationScriptInvoked)->toBeFalse()
        ->and($replacementSidecarFinalized)->toBeTrue()
        ->and($allPayloads)->toContain('operation_container_manifest_stage=')
        ->not->toContain(
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        );
});

it('keeps terminal-retirement pending journals fenced unless ownership live route and terminal flags all match', function (string $failure): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $journalBootId = (string) $scenario->deployment->blue_green_server_boot_id;
    $ownerDeploymentUuid = BlueGreenRecoveryScenario::OPERATION_UUID;
    $retirementAttributes = [
        'inactive_retirement_owner_deployment_uuid' => $ownerDeploymentUuid,
        'inactive_retirement_color' => BlueGreenDeploymentColor::GREEN->value,
        'inactive_retirement_deployment_uuid' => 'predecessor-retired-deployment',
        'inactive_retirement_container_id' => BlueGreenRecoveryScenario::LEGACY_ID,
        'inactive_retirement_container_routing_revision' => 0,
        'inactive_retirement_owner_routing_revision' => 1,
        'inactive_retirement_supersession_generation' => 1,
        'inactive_retirement_destination_fence_epoch' => 1,
        'inactive_retirement_server_boot_id' => $journalBootId,
        'inactive_retirement_topology_digest' => $scenario->state->destination_topology_digest,
        'inactive_retirement_routing_config_digest' => $scenario->state->application_routing_config_digest,
        'inactive_retirement_not_before_at' => now()->subMinutes(5),
        'inactive_retirement_drain_deadline_at' => now()->subMinutes(3),
        'inactive_retirement_stop_grace_seconds' => 30,
        'inactive_retirement_lease_seconds' => 120,
        'inactive_retirement_last_observed_connections' => 0,
        'inactive_retirement_observed_at' => now()->subMinute(),
        'inactive_retirement_attempts' => 1,
        'inactive_retirement_stopped_at' => now()->subMinute(),
        'inactive_retirement_intervention_required_at' => null,
        'inactive_retirement_dispatch_reserved_until_at' => null,
    ];
    if ($failure === 'cleared') {
        $retirementAttributes = ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes();
    }
    if ($failure === 'wrong-owner') {
        $retirementAttributes['inactive_retirement_owner_deployment_uuid'] = 'foreign-retirement-owner';
    }
    if ($failure === 'wrong-boot') {
        $retirementAttributes['inactive_retirement_server_boot_id'] = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    }
    if ($failure === 'intervention') {
        $retirementAttributes['inactive_retirement_intervention_required_at'] = now()->subMinute();
    }
    if ($failure === 'reserved') {
        $retirementAttributes['inactive_retirement_dispatch_reserved_until_at'] = now()->addMinute();
    }
    if ($failure === 'not-stopped') {
        $retirementAttributes['inactive_retirement_stopped_at'] = null;
    }
    if ($failure === 'wrong-generation') {
        $retirementAttributes['inactive_retirement_supersession_generation'] = 2;
    }
    if ($failure === 'wrong-topology') {
        $retirementAttributes['inactive_retirement_topology_digest'] = str_repeat('a', 64);
    }
    if ($failure === 'active-target') {
        $retirementAttributes['inactive_retirement_container_id'] = BlueGreenRecoveryScenario::CANDIDATE_ID;
    }
    $scenario->state->update([
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ...ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
        'legacy_container_name' => null,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'green_deployment_uuid' => $failure === 'wrong-inactive-slot'
            ? 'foreign-inactive-deployment'
            : 'predecessor-retired-deployment',
        ...$retirementAttributes,
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
        'blue_green_destination_fence_epoch' => $failure === 'wrong-owner-epoch' ? 2 : 1,
    ]);
    if ($failure !== 'missing-inactive-queue') {
        ApplicationDeploymentQueue::query()->create([
            'application_id' => $scenario->application->id,
            'deployment_uuid' => 'predecessor-retired-deployment',
            'pull_request_id' => 0,
            'destination_id' => $scenario->destination->id,
            'server_id' => $scenario->server->id,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
            'finished_at' => now()->subMinutes(2),
            'blue_green_color' => BlueGreenDeploymentColor::GREEN,
            'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
            'blue_green_routing_revision' => 0,
            'blue_green_candidate_container_id' => match ($failure) {
                'wrong-target' => str_repeat('f', 64),
                'active-target' => BlueGreenRecoveryScenario::CANDIDATE_ID,
                default => BlueGreenRecoveryScenario::LEGACY_ID,
            },
            'blue_green_topology_digest' => $failure === 'missing-inactive-digests'
                ? null
                : $scenario->state->destination_topology_digest,
            'blue_green_routing_config_digest' => $failure === 'missing-inactive-digests'
                ? null
                : $scenario->state->application_routing_config_digest,
        ]);
    }
    if ($failure === 'owner-fenced-deactivation') {
        ApplicationBlueGreenDeactivation::query()->create([
            'application_id' => $scenario->application->id,
            'standalone_docker_id' => $scenario->destination->id,
            'operation_id' => str_repeat('d', 64),
            'started_at' => now()->subMinutes(2),
            'queue_cutoff_id' => $scenario->deployment->id,
            'supersession_generation' => 1,
            'phase' => BlueGreenDeactivationPhase::COMPLETED,
            'completed_at' => now()->subMinute(),
        ]);
    }
    $replacementState = ResolveBlueGreenExpectedProxyState::run(
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
    ) ?? throw new RuntimeException('The terminal-retirement negative fixture requires an exact managed route.');
    $journalExpectedState = cleanIdleJournalStateWithOperation($replacementState, 'terminal-retirement-negative-predecessor');
    $journalSha256 = hash('sha256', "terminal-retirement-negative-{$failure}");
    $casAttempted = false;
    $liveState = $replacementState;

    Process::fake(function (PendingProcess $process) use (
        &$casAttempted,
        $failure,
        $journalBootId,
        $journalExpectedState,
        $journalSha256,
        $liveState,
        $replacementState,
    ) {
        $payload = (string) $process->command."\n".(string) $process->input;
        if (isCleanIdleBootIdentityRead($payload)) {
            return Process::result(output: $journalBootId);
        }
        if (str_contains($payload, 'operation_container_manifest_stage=')) {
            $casAttempted = true;

            return Process::result(errorOutput: 'An ambiguous terminal-retirement journal must not reach CAS.', exitCode: 1);
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
                $journalSha256,
                $journalBootId,
                BlueGreenProxyRollbackArtifact::PRESENT_STATE,
                $failure === 'live-mismatch'
                    ? hash('sha256', 'live-route-diverged')
                    : $replacementState->managedSha256,
                hash('sha256', 'terminal-retirement-negative-mutation'),
                hash('sha256', 'terminal-retirement-negative-completion'),
            ])."\n".base64_encode($journalExpectedState->serialize())."\n".base64_encode($replacementState->serialize()));
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($liveState->serialize())."\n".$liveState->managedSha256);
        }

        return Process::result(errorOutput: 'Unexpected terminal-retirement negative recovery command.', exitCode: 1);
    });
    InspectBlueGreenContainer::shouldNotRun();

    expect(fn () => RecoverCleanIdleBlueGreenContainerMutationJournal::run(
        $scenario->server,
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
    ))->toThrow(BlueGreenDeploymentTransitionException::class)
        ->and($casAttempted)->toBeFalse();
})->with([
    'cleared retirement provenance remains ambiguous' => ['cleared'],
    'wrong retirement owner remains fenced' => ['wrong-owner'],
    'wrong retirement boot remains fenced' => ['wrong-boot'],
    'intervention-marked retirement remains fenced' => ['intervention'],
    'dispatch-reserved retirement remains fenced' => ['reserved'],
    'unstopped retirement remains fenced' => ['not-stopped'],
    'wrong retirement generation remains fenced' => ['wrong-generation'],
    'wrong retirement topology remains fenced' => ['wrong-topology'],
    'wrong inactive color slot remains fenced' => ['wrong-inactive-slot'],
    'wrong owner fence epoch remains fenced' => ['wrong-owner-epoch'],
    'owner-fencing completed deactivation remains fenced' => ['owner-fenced-deactivation'],
    'missing inactive queue remains fenced' => ['missing-inactive-queue'],
    'missing inactive queue digests remain fenced' => ['missing-inactive-digests'],
    'wrong inactive target remains fenced' => ['wrong-target'],
    'active target reused as inactive remains fenced' => ['active-target'],
    'live route mismatch remains fenced' => ['live-mismatch'],
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

/**
 * One routed, cleanly claimable IDLE destination whose last container mutation
 * committed everywhere and left only its journal behind — the shape that fences
 * every managed-route read until an operator archives the journal.
 */
function completedContainerMutationJournalScenario(): BlueGreenRecoveryScenario
{
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

    return $scenario;
}

/**
 * The reported production shape on its decisive axis: a routed IDLE row that
 * still names an inactive-retirement owner, terminal, so the mature profile is
 * reached first and refuses it. Here it refuses in its durable context builder
 * because the rest of the retirement provenance is already cleared; on the
 * production row it refuses later, at the remote inspection, because the
 * journal on disk is the deployment's route mutation and not its own drain.
 * Either way the completed-mutation profile is the only remaining owner.
 */
function completedContainerMutationJournalScenarioWithTerminalRetirement(): BlueGreenRecoveryScenario
{
    $scenario = completedContainerMutationJournalScenario();
    $scenario->state->update([
        'inactive_retirement_owner_deployment_uuid' => $scenario->deployment->deployment_uuid,
        'inactive_retirement_stopped_at' => now()->subMinute(),
        'inactive_retirement_intervention_required_at' => null,
        'inactive_retirement_dispatch_reserved_until_at' => null,
    ]);

    return $scenario;
}

/**
 * @param  list<string>  $payloads
 */
function fakeCompletedContainerMutationJournalRemote(
    BlueGreenProxyState $journalExpectedState,
    BlueGreenProxyState $journalReplacementState,
    BlueGreenProxyState $liveState,
    string $journalSha256,
    string $journalBootId,
    array &$payloads,
    bool &$journalPresent,
): void {
    $payloads = [];
    Process::fake(function (PendingProcess $process) use (
        $journalExpectedState,
        $journalReplacementState,
        $liveState,
        $journalSha256,
        $journalBootId,
        &$payloads,
        &$journalPresent,
    ) {
        $payload = (string) $process->command."\n".(string) $process->input;
        $payloads[] = $payload;
        if (isCleanIdleBootIdentityRead($payload)) {
            return Process::result(output: 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff');
        }
        if (str_contains($payload, 'committed_container_manifest_stage=')) {
            $journalPresent = false;

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::COMMITTED_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX,
                'archived',
                $journalSha256,
                (new WriteBlueGreenProxyConfiguration)->committedContainerMutationJournalArchiveFilename(
                    $journalReplacementState->managedFilename,
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
                $journalReplacementState->managedSha256,
                hash('sha256', 'completed-mutation-script'),
                hash('sha256', 'completed-completion-script'),
            ])."\n".base64_encode($journalExpectedState->serialize())."\n".base64_encode($journalReplacementState->serialize()));
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($liveState->serialize())."\n".$liveState->managedSha256);
        }

        return Process::result();
    });
}

function completedContainerMutationExpectedState(BlueGreenRecoveryScenario $scenario): BlueGreenProxyState
{
    return ResolveBlueGreenExpectedProxyState::run(
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
    ) ?? throw new RuntimeException('The completed-mutation fixture requires an exact managed route.');
}

/** @param array<string, list<array<string, mixed>>> $auditEvents */
function captureCompletedContainerMutationAudit(array &$auditEvents): void
{
    $auditEvents = [];
    $auditChannel = Mockery::mock();
    // One event name can legitimately be emitted more than once in a single
    // invocation -- two independent refusals both stand when nothing resolved
    // the destination -- so every record is kept rather than overwritten.
    $capture = function (string $event, array $context) use (&$auditEvents): void {
        $auditEvents[$event][] = $context;
    };
    $auditChannel->shouldReceive('warning')->andReturnUsing($capture);
    $auditChannel->shouldReceive('error')->andReturnUsing($capture);
    $auditChannel->shouldReceive('info')->andReturnUsing($capture);
    Log::shouldReceive('channel')->andReturn($auditChannel);
    Log::shouldReceive('warning')->andReturnNull();
    Log::shouldReceive('error')->andReturnNull();
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('debug')->andReturnNull();
}

it('classifies a completed container-mutation journal as recoverable without changing it', function (): void {
    $scenario = completedContainerMutationJournalScenario();
    $expectedState = completedContainerMutationExpectedState($scenario);
    $journalPresent = true;
    $payloads = [];
    fakeCompletedContainerMutationJournalRemote(
        cleanIdleJournalStateWithOperation($expectedState, 'completed-mutation-predecessor'),
        $expectedState,
        $expectedState,
        hash('sha256', 'completed-mutation-journal'),
        (string) $scenario->deployment->blue_green_server_boot_id,
        $payloads,
        $journalPresent,
    );
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectedState->activeContainerId,
            status: 'running',
            health: 'healthy',
        ));
    $stateBefore = $scenario->state->fresh()->getAttributes();
    $auditEvents = [];
    captureCompletedContainerMutationAudit($auditEvents);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::INSPECTED)
        ->and($auditEvents)->toHaveKey('blue_green.stale_container_journal.completed_mutation_inspected')
        ->and($auditEvents['blue_green.stale_container_journal.completed_mutation_inspected'][0]['phase'] ?? null)
        ->toBe('completed_container_mutation')
        ->and($journalPresent)->toBeTrue()
        ->and($scenario->state->fresh()->getAttributes())->toBe($stateBefore)
        ->and(implode("\n", $payloads))->not->toContain(
            'committed_container_manifest_stage=',
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        );
});

it('archives a completed container-mutation journal so the destination route is observable again', function (): void {
    $scenario = completedContainerMutationJournalScenario();
    $expectedState = completedContainerMutationExpectedState($scenario);
    $journalPresent = true;
    $payloads = [];
    fakeCompletedContainerMutationJournalRemote(
        cleanIdleJournalStateWithOperation($expectedState, 'completed-mutation-predecessor'),
        $expectedState,
        $expectedState,
        hash('sha256', 'completed-mutation-journal'),
        (string) $scenario->deployment->blue_green_server_boot_id,
        $payloads,
        $journalPresent,
    );
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectedState->activeContainerId,
            status: 'running',
            health: 'healthy',
        ));
    $stateBefore = $scenario->state->fresh()->getAttributes();

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Archive the completed container-mutation journal that fences every managed-route read.',
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($journalPresent)->toBeFalse()
        ->and($scenario->state->fresh()->getAttributes())->toBe($stateBefore)
        ->and(implode("\n", $payloads))->not->toContain(
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        );
});

it('recovers the reported completed-mutation shape that still names a terminal inactive retirement', function (bool $apply): void {
    $scenario = completedContainerMutationJournalScenarioWithTerminalRetirement();
    $expectedState = completedContainerMutationExpectedState($scenario);
    $journalPresent = true;
    $payloads = [];
    fakeCompletedContainerMutationJournalRemote(
        cleanIdleJournalStateWithOperation($expectedState, 'completed-mutation-predecessor'),
        $expectedState,
        $expectedState,
        hash('sha256', 'completed-mutation-journal'),
        (string) $scenario->deployment->blue_green_server_boot_id,
        $payloads,
        $journalPresent,
    );
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectedState->activeContainerId,
            status: 'running',
            health: 'healthy',
        ));
    $stateBefore = $scenario->state->fresh()->getAttributes();

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: $apply,
        reason: $apply ? 'Archive the completed container-mutation journal on the reported production shape.' : null,
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe($apply
            ? BlueGreenInterventionRecoveryResult::RECOVERED
            : BlueGreenInterventionRecoveryResult::INSPECTED)
        ->and($journalPresent)->toBe(! $apply)
        ->and($scenario->state->fresh()->getAttributes())->toBe($stateBefore);
})->with([
    'inspection' => false,
    'archival' => true,
]);

it('leaves a live inactive retirement to the mature profile instead of the completed-mutation profile', function (): void {
    $scenario = completedContainerMutationJournalScenarioWithTerminalRetirement();
    $scenario->state->update([
        'inactive_retirement_stopped_at' => null,
        'inactive_retirement_dispatch_reserved_until_at' => now()->addMinutes(5),
    ]);
    $expectedState = completedContainerMutationExpectedState($scenario);
    $journalPresent = true;
    $payloads = [];
    fakeCompletedContainerMutationJournalRemote(
        cleanIdleJournalStateWithOperation($expectedState, 'completed-mutation-predecessor'),
        $expectedState,
        $expectedState,
        hash('sha256', 'completed-mutation-journal'),
        (string) $scenario->deployment->blue_green_server_boot_id,
        $payloads,
        $journalPresent,
    );
    $stateBefore = $scenario->state->fresh()->getAttributes();

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($journalPresent)->toBeTrue()
        ->and($scenario->state->fresh()->getAttributes())->toBe($stateBefore)
        // A durable refusal still costs the destination nothing: the completed
        // mutation profile never probes a row the mature profile owns.
        ->and($payloads)->toBeEmpty();
});

it('refuses a completed container-mutation journal whose payload does not equal the durable destination state', function (
    bool $apply,
    string $expectedOutcome,
    string $expectedReasonCode,
): void {
    $scenario = completedContainerMutationJournalScenario();
    $expectedState = completedContainerMutationExpectedState($scenario);
    $impostorState = new BlueGreenProxyState(
        managedFilename: $expectedState->managedFilename,
        applicationUuid: $expectedState->applicationUuid,
        destinationId: $expectedState->destinationId,
        operationId: $expectedState->operationId,
        mutationSequence: $expectedState->mutationSequence,
        destinationFenceEpoch: $expectedState->destinationFenceEpoch,
        routingRevision: $expectedState->routingRevision,
        managedSha256: $expectedState->managedSha256,
        activeColor: $expectedState->activeColor,
        activeDeploymentUuid: $expectedState->activeDeploymentUuid,
        activeContainerName: $expectedState->activeContainerName,
        activeContainerId: str_repeat('c', 64),
        applicationRoutingConfigDigest: $expectedState->applicationRoutingConfigDigest,
        destinationTopologyDigest: $expectedState->destinationTopologyDigest,
    );
    $journalPresent = true;
    $payloads = [];
    fakeCompletedContainerMutationJournalRemote(
        cleanIdleJournalStateWithOperation($impostorState, 'completed-mutation-predecessor'),
        $impostorState,
        $expectedState,
        hash('sha256', 'completed-mutation-journal'),
        (string) $scenario->deployment->blue_green_server_boot_id,
        $payloads,
        $journalPresent,
    );
    $stateBefore = $scenario->state->fresh()->getAttributes();

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: $apply,
        reason: $apply ? 'Prove the completed-mutation profile refuses a journal that does not equal the durable state.' : null,
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe($expectedOutcome)
        ->and($result->reasonCode)->toBe($expectedReasonCode)
        ->and($journalPresent)->toBeTrue()
        ->and($scenario->state->fresh()->getAttributes())->toBe($stateBefore)
        ->and(implode("\n", $payloads))->not->toContain('committed_container_manifest_stage=');
})->with([
    'inspection refuses and proves nothing changed' => [
        false,
        BlueGreenInterventionRecoveryResult::MANUAL_ONLY,
        'inspection_failed',
    ],
    'archival refuses before dispatching any CAS' => [
        true,
        BlueGreenInterventionRecoveryResult::MANUAL_ONLY,
        'inspection_failed',
    ],
]);

it('never claims an untouched journal once the archive CAS reached the host', function (): void {
    $scenario = completedContainerMutationJournalScenario();
    $expectedState = completedContainerMutationExpectedState($scenario);
    $journalSha256 = hash('sha256', 'completed-mutation-journal');
    $journalExpectedState = cleanIdleJournalStateWithOperation($expectedState, 'completed-mutation-predecessor');
    $journalBootId = (string) $scenario->deployment->blue_green_server_boot_id;
    $journalPresent = true;

    Process::fake(function (PendingProcess $process) use (
        $expectedState,
        $journalExpectedState,
        $journalBootId,
        $journalSha256,
        &$journalPresent,
    ) {
        $payload = (string) $process->command."\n".(string) $process->input;
        if (isCleanIdleBootIdentityRead($payload)) {
            return Process::result(output: 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff');
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
                hash('sha256', 'completed-mutation-script'),
                hash('sha256', 'completed-completion-script'),
            ])."\n".base64_encode($journalExpectedState->serialize())."\n".base64_encode($expectedState->serialize()));
        }
        // The archive landed; only the proof read afterwards is lost.
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            return Process::result(errorOutput: 'transport lost after the archive CAS', exitCode: 255);
        }

        return Process::result();
    });
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectedState->activeContainerId,
            status: 'running',
            health: 'healthy',
        ));
    $auditEvents = [];
    captureCompletedContainerMutationAudit($auditEvents);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Prove a dispatched archive is never reported as an untouched journal.',
        staleContainerJournal: true,
    );

    expect($journalPresent)->toBeFalse()
        ->and($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::DEFERRED)
        ->and($result->reasonCode)->toBe('archive_failed')
        ->and($result->correlationId)->not->toBeNull()
        ->and($result->message)->toContain('may have completed')
        ->and($result->message)->not->toContain('no journal was changed')
        // The highest-severity outcome this command can produce. It must reach
        // the error channel under its own correlation id, and must never be
        // recorded as a refusal some later profile superseded.
        ->and(array_column($auditEvents['blue_green.intervention.recovery_failed'] ?? [], 'reason_code'))
        ->toContain('archive_failed')
        ->and(array_column($auditEvents['blue_green.intervention.recovery_failed'] ?? [], 'correlation_id'))
        ->toContain($result->correlationId)
        ->and($auditEvents)->not->toHaveKey('blue_green.intervention.recovery_superseded')
        ->and(array_column($auditEvents['blue_green.stale_container_journal.archive_outcome_unknown'] ?? [], 'phase'))
        ->toContain('completed_mutation_archive_outcome_unknown');
});

it('reports its own probe failure instead of handing back an earlier profile refusal', function (): void {
    $scenario = completedContainerMutationJournalScenario();
    Process::fake(function (PendingProcess $process) {
        $payload = (string) $process->command."\n".(string) $process->input;
        if (isCleanIdleBootIdentityRead($payload)) {
            return Process::result(output: 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff');
        }

        return Process::result(errorOutput: 'the destination could not be reached', exitCode: 255);
    });
    $stateBefore = $scenario->state->fresh()->getAttributes();
    $auditEvents = [];
    captureCompletedContainerMutationAudit($auditEvents);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($result->reasonCode)->toBe('inspection_failed')
        ->and($result->correlationId)->not->toBeNull()
        // The record behind that correlation id must be this profile's own
        // probe failure, not an earlier profile's durable refusal wearing it.
        ->and(array_column($auditEvents['blue_green.intervention.recovery_failed'] ?? [], 'reason_code'))
        ->toContain('inspection_failed')
        ->and(array_column($auditEvents['blue_green.intervention.recovery_failed'] ?? [], 'correlation_id'))
        ->toContain($result->correlationId)
        ->and(array_column($auditEvents['blue_green.stale_container_journal.manual_only'] ?? [], 'phase'))
        ->toContain('completed_mutation_probe_failed')
        ->and($scenario->state->fresh()->getAttributes())->toBe($stateBefore);
});

it('does not page an operator with an earlier profile refusal that the completed-mutation profile then resolved', function (): void {
    $scenario = completedContainerMutationJournalScenarioWithTerminalRetirement();
    $expectedState = completedContainerMutationExpectedState($scenario);
    $journalPresent = true;
    $payloads = [];
    fakeCompletedContainerMutationJournalRemote(
        cleanIdleJournalStateWithOperation($expectedState, 'completed-mutation-predecessor'),
        $expectedState,
        $expectedState,
        hash('sha256', 'completed-mutation-journal'),
        (string) $scenario->deployment->blue_green_server_boot_id,
        $payloads,
        $journalPresent,
    );
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectedState->activeContainerId,
            status: 'running',
            health: 'healthy',
        ));
    $auditEvents = [];
    captureCompletedContainerMutationAudit($auditEvents);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Prove a superseded refusal never reaches the error channel.',
        staleContainerJournal: true,
    );

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($auditEvents)->not->toHaveKey('blue_green.intervention.recovery_failed')
        ->and($auditEvents)->toHaveKey('blue_green.intervention.recovery_superseded')
        ->and($auditEvents)->toHaveKey('blue_green.stale_container_journal.completed_mutation_recovered');
});

it('still reports an earlier profile refusal when the completed-mutation profile does not resolve it', function (): void {
    $scenario = completedContainerMutationJournalScenarioWithTerminalRetirement();
    // No journal on the host at all: the earlier profile's refusal is the
    // operator's real answer and must reach the error channel intact.
    Process::fake(function (PendingProcess $process): mixed {
        $payload = (string) $process->command."\n".(string) $process->input;
        if (isCleanIdleBootIdentityRead($payload)) {
            return Process::result(output: 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff');
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            return Process::result(output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent');
        }

        return Process::result();
    });
    $auditEvents = [];
    captureCompletedContainerMutationAudit($auditEvents);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        staleContainerJournal: true,
    );

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($auditEvents)->toHaveKey('blue_green.intervention.recovery_failed')
        ->and($auditEvents)->not->toHaveKey('blue_green.intervention.recovery_superseded');
});
