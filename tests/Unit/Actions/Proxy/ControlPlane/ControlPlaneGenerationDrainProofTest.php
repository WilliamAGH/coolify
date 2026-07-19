<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationDrainProof;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionState;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationRuntime;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function controlPlaneGenerationDrainProofShellState(): ControlPlaneGenerationPromotionState
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
        dynamicWritten: controlPlaneGenerationDrainProofShellSuccessorObservation('2026-07-19T12:08:00Z'),
        dualRoute: controlPlaneGenerationDrainProofShellSuccessorObservation('2026-07-19T12:09:00Z'),
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
function controlPlaneGenerationDrainProofShellSuccessorObservation(string $observedAt): array
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

/** @return array{root: string, bin: string, proc: string, state: string} */
function controlPlaneGenerationDrainProofShellFixture(ControlPlaneGenerationPromotionState $state): array
{
    $root = sys_get_temp_dir().'/coolify-control-plane-generation-drain-proof-'.bin2hex(random_bytes(8));
    $bin = $root.'/bin';
    $proc = $root.'/proc';
    $statePath = $root.'/docker-state';
    $filesystem = new Filesystem;
    $filesystem->mkdir([$bin, $proc], 0700);

    $members = [];
    foreach ($state->runtime->predecessorRuntime as $index => $identity) {
        $pid = $index === 'coolify-web-a' ? 4242 : 4343;
        $filesystem->mkdir($proc.'/'.$pid.'/net', 0700);
        file_put_contents($proc.'/'.$pid.'/net/tcp', "  sl  local_address rem_address   st\n   0: 0100007F:1F40 00000000:0000 0A\n");
        file_put_contents($proc.'/'.$pid.'/net/tcp6', "  sl  local_address rem_address   st\n   0: 00000000000000000000000000000000:1F40 00000000000000000000000000000000:0000 0A\n");
        $members[] = implode('|', [$identity['container_id'], $index, $identity['image_id'], 'true', (string) $pid]);
    }
    file_put_contents($statePath, implode("\n", [...$members, '']));
    file_put_contents($bin.'/docker', <<<'SH'
#!/bin/sh
set -eu

state=$FAKE_DRAIN_PROOF_DOCKER_STATE
if [ "$1" != inspect ]; then exit 1; fi
for argument in "$@"; do selector=$argument; done
if [ "${FAKE_DRAIN_PROOF_INSPECTION+x}" = x ]; then
    printf '%s\n' "$FAKE_DRAIN_PROOF_INSPECTION"
    exit 0
fi
awk -F '|' -v selector="$selector" '
    $1 == selector {
        print $1 "|/" $2 "|" $3 "|" $4 "|" $5
        found = 1
        exit
    }
    END { exit(found ? 0 : 1) }
' "$state"
SH
    );
    file_put_contents($bin.'/date', <<<'SH'
#!/bin/sh
set -eu

printf '%s\n' "${FAKE_DRAIN_PROOF_TIMESTAMP:-2026-07-19T12:10:00Z}"
SH
    );
    chmod($bin.'/docker', 0700);
    chmod($bin.'/date', 0700);

    return ['root' => $root, 'bin' => $bin, 'proc' => $proc, 'state' => $statePath];
}

/** @param array{bin: string, state: string} $fixture */
function runControlPlaneGenerationDrainProofShell(string $command, array $fixture, array $environment = []): Process
{
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(5);
    $process->setEnv(array_replace([
        'PATH' => $fixture['bin'].':'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'FAKE_DRAIN_PROOF_DOCKER_STATE' => $fixture['state'],
    ], $environment));
    $process->run();

    return $process;
}

it('attests every predecessor runtime member and aggregates both kernel TCP tables', function (): void {
    $state = controlPlaneGenerationDrainProofShellState();
    $fixture = controlPlaneGenerationDrainProofShellFixture($state);
    $filesystem = new Filesystem;

    try {
        $command = (new ControlPlaneGenerationDrainProof($fixture['proc']))->commandFor(
            state: $state,
            backendPort: 8000,
            freezeOperationId: $state->operationId,
        );
        $result = runControlPlaneGenerationDrainProofShell($command, $fixture);

        expect($result->isSuccessful())->toBeTrue()
            ->and($result->getOutput())->toContain(ControlPlaneGenerationDrainProof::TRANSCRIPT_BEGIN)
            ->and($result->getOutput())->toContain(ControlPlaneGenerationDrainProof::TRANSCRIPT_END)
            ->and(substr_count($result->getOutput(), ControlPlaneGenerationDrainProof::TRANSCRIPT_RECORD))->toBe(2)
            ->and($result->getOutput())->toContain('coolify-web-a '.str_repeat('a', 64).' /coolify-web-a sha256:'.str_repeat('b', 64).' 4242 0 2026-07-19T12:10:00Z')
            ->and($result->getOutput())->toContain('coolify-web-b '.str_repeat('c', 64).' /coolify-web-b sha256:'.str_repeat('d', 64).' 4343 0 2026-07-19T12:10:00Z')
            ->and(substr_count($command, 'docker inspect --type container'))->toBe(2)
            ->and($command)->toContain('.Image')
            ->and($command)->toContain('/net/tcp6')
            ->and($command)->toContain('FNR == 1')
            ->and($command)->not->toContain($state->predecessorRuntimeSha256());
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('fails closed before emitting a proof record when Docker identity or kernel TCP evidence is wrong', function (array $environment, ?string $tcp6): void {
    $state = controlPlaneGenerationDrainProofShellState();
    $fixture = controlPlaneGenerationDrainProofShellFixture($state);
    $filesystem = new Filesystem;
    if ($tcp6 !== null) {
        file_put_contents($fixture['proc'].'/4242/net/tcp6', $tcp6);
    }

    try {
        $command = (new ControlPlaneGenerationDrainProof($fixture['proc']))->commandFor($state, 8000, $state->operationId);
        $result = runControlPlaneGenerationDrainProofShell($command, $fixture, $environment);

        expect($result->isSuccessful())->toBeFalse()
            ->and($result->getOutput())->not->toContain(ControlPlaneGenerationDrainProof::TRANSCRIPT_RECORD);
    } finally {
        $filesystem->remove($fixture['root']);
    }
})->with([
    'wrong Docker ID' => [['FAKE_DRAIN_PROOF_INSPECTION' => str_repeat('1', 64).'|/coolify-web-a|sha256:'.str_repeat('b', 64).'|true|4242'], null],
    'wrong Docker name' => [['FAKE_DRAIN_PROOF_INSPECTION' => str_repeat('a', 64).'|/foreign-web|sha256:'.str_repeat('b', 64).'|true|4242'], null],
    'wrong immutable image' => [['FAKE_DRAIN_PROOF_INSPECTION' => str_repeat('a', 64).'|/coolify-web-a|sha256:'.str_repeat('1', 64).'|true|4242'], null],
    'malformed TCP6 table' => [[], "not a TCP table\n"],
]);

it('reports a nonzero backend TCP count for verifier refusal without converting it to zero', function (): void {
    $state = controlPlaneGenerationDrainProofShellState();
    $fixture = controlPlaneGenerationDrainProofShellFixture($state);
    $filesystem = new Filesystem;
    file_put_contents(
        $fixture['proc'].'/4242/net/tcp',
        "  sl  local_address rem_address   st\n   0: 0100007F:1F40 0100007F:C001 01\n",
    );

    try {
        $command = (new ControlPlaneGenerationDrainProof($fixture['proc']))->commandFor($state, 8000, $state->operationId);
        $result = runControlPlaneGenerationDrainProofShell($command, $fixture);

        expect($result->isSuccessful())->toBeTrue()
            ->and($result->getOutput())->toContain('coolify-web-a '.str_repeat('a', 64).' /coolify-web-a sha256:'.str_repeat('b', 64).' 4242 1 ');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('requires the exact freeze owner operation ID and a valid backend port', function (): void {
    $state = controlPlaneGenerationDrainProofShellState();
    $proof = new ControlPlaneGenerationDrainProof;

    expect(fn (): string => $proof->commandFor($state, 8000, 'another-operation'))
        ->toThrow(InvalidArgumentException::class, 'exact mutation freeze owner')
        ->and(fn (): string => $proof->commandFor($state, 0, $state->operationId))
        ->toThrow(InvalidArgumentException::class, 'backend port');
});
