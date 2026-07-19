<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationDrainProof;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionState;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationRuntime;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneGenerationDrainProof;
use App\Support\ProxyMutationQueueSnapshot;

function controlPlaneGenerationDrainProofVerifierState(): ControlPlaneGenerationPromotionState
{
    $runtime = new ControlPlaneGenerationRuntime(
        predecessorRuntime: [
            'coolify-web-a' => ['container_id' => str_repeat('a', 64), 'image_id' => 'sha256:'.str_repeat('b', 64)],
            'coolify-web-b' => ['container_id' => str_repeat('c', 64), 'image_id' => 'sha256:'.str_repeat('d', 64)],
        ],
        successorRuntime: [
            'coolify-web-c' => ['container_id' => str_repeat('e', 64), 'image_id' => 'sha256:'.str_repeat('f', 64)],
        ],
        writerContainerName: 'coolify-web-c',
        writerContainerId: str_repeat('e', 64),
    );

    return new ControlPlaneGenerationPromotionState(
        phase: ControlPlaneGenerationPromotionPhase::Draining,
        operationId: 'promotion-one',
        tokenSha256: hash('sha256', 'promotion-token'),
        serverId: 1,
        managedFilename: ControlPlaneDynamicConfiguration::MANAGED_FILENAME,
        predecessor: [
            'operation_id' => 'enrollment-one',
            'dynamic_revision' => 1,
            'dynamic_sha256' => hash('sha256', 'predecessor-dynamic'),
            'member' => 'blue',
            'release_revision' => 'release-one',
            'backends' => ['coolify-web-a', 'coolify-web-b'],
            'configuration_acknowledgement' => 'ack:'.str_repeat('a', 64),
        ],
        successor: [
            'dynamic_revision' => 2,
            'dynamic_sha256' => hash('sha256', 'successor-dynamic'),
            'member' => 'green',
            'release_revision' => 'release-two',
            'backends' => ['coolify-web-c'],
            'configuration_acknowledgement' => 'ack:'.str_repeat('b', 64),
        ],
        runtimeFence: ['epoch' => 2, 'observed_at' => '2026-07-19T12:04:00Z'],
        mutationFreeze: ['operation_id' => 'promotion-one', 'observed_at' => '2026-07-19T12:04:00Z'],
        queueInventory: ['pending' => 0, 'reserved' => 0, 'delayed' => 0, 'observed_at' => '2026-07-19T12:06:00Z'],
        dynamicWritten: controlPlaneGenerationDrainProofVerifierSuccessorObservation('2026-07-19T12:08:00Z'),
        dualRoute: controlPlaneGenerationDrainProofVerifierSuccessorObservation('2026-07-19T12:09:00Z'),
        draining: ['deadline_at' => '2026-07-19T12:20:00Z', 'stable_zero_observations' => []],
        runtime: $runtime,
        predecessorWriterOperationId: 'enrollment-one',
        writerMember: 'green',
        writerEpoch: 2,
        rollbackWriterAuthority: null,
        legacyWriterAuthorityReconciliationRequired: false,
        retiredAt: null,
        fenceReleasedAt: null,
        writerPromotedAt: null,
        unfrozenAt: null,
        rollbackStartedAt: null,
        rollbackAcknowledgedAt: null,
        rolledBackAt: null,
        lastError: null,
        lastErrorAt: null,
        interventionRequiredAt: null,
        createdAt: '2026-07-19T12:00:00Z',
        updatedAt: '2026-07-19T12:09:00Z',
    );
}

/** @return array{operation_id: string, dynamic_revision: int, dynamic_sha256: string, configuration_acknowledgement: string, member: string, release_revision: string, observed_at: string} */
function controlPlaneGenerationDrainProofVerifierSuccessorObservation(string $observedAt): array
{
    return [
        'operation_id' => 'promotion-one',
        'dynamic_revision' => 2,
        'dynamic_sha256' => hash('sha256', 'successor-dynamic'),
        'configuration_acknowledgement' => 'ack:'.str_repeat('b', 64),
        'member' => 'green',
        'release_revision' => 'release-two',
        'observed_at' => $observedAt,
    ];
}

/** @param array{container_id: string, image_id: string} $identity */
function controlPlaneGenerationDrainProofVerifierRecord(
    string $candidateName,
    array $identity,
    string $timestamp,
    string $tcpConnectionCount = '0',
    string $containerName = '',
): string {
    return implode(' ', [
        ControlPlaneGenerationDrainProof::TRANSCRIPT_RECORD,
        $candidateName,
        $identity['container_id'],
        $containerName === '' ? '/'.$candidateName : $containerName,
        $identity['image_id'],
        '4242',
        $tcpConnectionCount,
        $timestamp,
    ]);
}

function successfulControlPlaneGenerationDrainProofTranscript(ControlPlaneGenerationPromotionState $state): string
{
    $records = [];
    $timestamp = 10;
    foreach ($state->runtime->predecessorRuntime as $candidateName => $identity) {
        $records[] = controlPlaneGenerationDrainProofVerifierRecord(
            candidateName: $candidateName,
            identity: $identity,
            timestamp: sprintf('2026-07-19T12:10:%02dZ', $timestamp),
        );
        $timestamp++;
    }

    return implode("\n", [
        ControlPlaneGenerationDrainProof::TRANSCRIPT_BEGIN,
        ...$records,
        ControlPlaneGenerationDrainProof::TRANSCRIPT_END,
    ]);
}

function controlPlaneGenerationDrainProofQueueSnapshot(
    ?string $operationId = 'promotion-one',
    int $pending = 0,
    int $reserved = 0,
    int $delayed = 0,
): Closure {
    return static fn (): ProxyMutationQueueSnapshot => new ProxyMutationQueueSnapshot(
        $operationId,
        $pending,
        $reserved,
        $delayed,
    );
}

it('returns the canonical durable zero observation with a state-derived predecessor checksum', function (): void {
    $state = controlPlaneGenerationDrainProofVerifierState();
    $observation = VerifyControlPlaneGenerationDrainProof::run(
        state: $state,
        transcript: successfulControlPlaneGenerationDrainProofTranscript($state),
        queueSnapshotProvider: controlPlaneGenerationDrainProofQueueSnapshot(),
    );

    expect($observation)->toBe([
        'observed_at' => '2026-07-19T12:10:11Z',
        'pending' => 0,
        'reserved' => 0,
        'delayed' => 0,
        'tcp_connection_count' => 0,
        'predecessor_runtime_sha256' => $state->predecessorRuntimeSha256(),
    ]);
});

it('rejects forged Docker ID, name, and immutable image records', function (string $search, string $replace): void {
    $state = controlPlaneGenerationDrainProofVerifierState();
    $transcript = str_replace($search, $replace, successfulControlPlaneGenerationDrainProofTranscript($state));

    expect(fn (): array => VerifyControlPlaneGenerationDrainProof::run($state, $transcript, controlPlaneGenerationDrainProofQueueSnapshot()))
        ->toThrow(InvalidArgumentException::class, 'stale predecessor runtime identity');
})->with([
    'Docker ID' => [str_repeat('a', 64), str_repeat('1', 64)],
    'Docker name' => ['/coolify-web-a', '/foreign-web'],
    'immutable image' => ['sha256:'.str_repeat('b', 64), 'sha256:'.str_repeat('1', 64)],
]);

it('rejects partial, extra, duplicate, and malformed predecessor runtime records', function (): void {
    $state = controlPlaneGenerationDrainProofVerifierState();
    $runtime = $state->runtime->predecessorRuntime;
    $partial = implode("\n", [
        ControlPlaneGenerationDrainProof::TRANSCRIPT_BEGIN,
        controlPlaneGenerationDrainProofVerifierRecord('coolify-web-a', $runtime['coolify-web-a'], '2026-07-19T12:10:00Z'),
        ControlPlaneGenerationDrainProof::TRANSCRIPT_END,
    ]);
    $extra = str_replace(
        ControlPlaneGenerationDrainProof::TRANSCRIPT_END,
        controlPlaneGenerationDrainProofVerifierRecord('coolify-web-c', ['container_id' => str_repeat('1', 64), 'image_id' => 'sha256:'.str_repeat('2', 64)], '2026-07-19T12:10:12Z')."\n".ControlPlaneGenerationDrainProof::TRANSCRIPT_END,
        successfulControlPlaneGenerationDrainProofTranscript($state),
    );
    $duplicate = str_replace(
        ControlPlaneGenerationDrainProof::TRANSCRIPT_END,
        controlPlaneGenerationDrainProofVerifierRecord('coolify-web-a', $runtime['coolify-web-a'], '2026-07-19T12:10:12Z')."\n".ControlPlaneGenerationDrainProof::TRANSCRIPT_END,
        successfulControlPlaneGenerationDrainProofTranscript($state),
    );
    $malformed = ControlPlaneGenerationDrainProof::TRANSCRIPT_BEGIN."\nmalformed\n".ControlPlaneGenerationDrainProof::TRANSCRIPT_END;

    expect(fn (): array => VerifyControlPlaneGenerationDrainProof::run($state, $partial, controlPlaneGenerationDrainProofQueueSnapshot()))
        ->toThrow(InvalidArgumentException::class, 'partial')
        ->and(fn (): array => VerifyControlPlaneGenerationDrainProof::run($state, $extra, controlPlaneGenerationDrainProofQueueSnapshot()))
        ->toThrow(InvalidArgumentException::class, 'extra member')
        ->and(fn (): array => VerifyControlPlaneGenerationDrainProof::run($state, $duplicate, controlPlaneGenerationDrainProofQueueSnapshot()))
        ->toThrow(InvalidArgumentException::class, 'repeats')
        ->and(fn (): array => VerifyControlPlaneGenerationDrainProof::run($state, $malformed, controlPlaneGenerationDrainProofQueueSnapshot()))
        ->toThrow(InvalidArgumentException::class, 'malformed sentinel record');
});

it('refuses a nonzero TCP count instead of creating a resettable zero observation', function (): void {
    $state = controlPlaneGenerationDrainProofVerifierState();
    $transcript = str_replace(
        ' 4242 0 2026-07-19T12:10:10Z',
        ' 4242 1 2026-07-19T12:10:10Z',
        successfulControlPlaneGenerationDrainProofTranscript($state),
    );

    expect(fn (): array => VerifyControlPlaneGenerationDrainProof::run($state, $transcript, controlPlaneGenerationDrainProofQueueSnapshot()))
        ->toThrow(InvalidArgumentException::class, 'active backend TCP connections');
});

it('requires the exact freeze owner, zero queue counts, and timestamps inside the drain window', function (): void {
    $state = controlPlaneGenerationDrainProofVerifierState();
    $successful = successfulControlPlaneGenerationDrainProofTranscript($state);
    $beforeRouteAcknowledgement = str_replace('2026-07-19T12:10:10Z', '2026-07-19T12:08:59Z', $successful);
    $atDeadline = str_replace('2026-07-19T12:10:10Z', '2026-07-19T12:20:00Z', $successful);

    expect(fn (): array => VerifyControlPlaneGenerationDrainProof::run($state, $successful, controlPlaneGenerationDrainProofQueueSnapshot('another-operation')))
        ->toThrow(InvalidArgumentException::class, 'exact mutation freeze owner')
        ->and(fn (): array => VerifyControlPlaneGenerationDrainProof::run($state, $successful, controlPlaneGenerationDrainProofQueueSnapshot(pending: 1)))
        ->toThrow(InvalidArgumentException::class, 'empty canonical mutation queue')
        ->and(fn (): array => VerifyControlPlaneGenerationDrainProof::run($state, $beforeRouteAcknowledgement, controlPlaneGenerationDrainProofQueueSnapshot()))
        ->toThrow(InvalidArgumentException::class, 'acknowledged immutable drain window')
        ->and(fn (): array => VerifyControlPlaneGenerationDrainProof::run($state, $atDeadline, controlPlaneGenerationDrainProofQueueSnapshot()))
        ->toThrow(InvalidArgumentException::class, 'acknowledged immutable drain window');
});
