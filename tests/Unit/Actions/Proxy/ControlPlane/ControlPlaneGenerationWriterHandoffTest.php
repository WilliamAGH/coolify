<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionState;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationRuntime;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationWriterHandoff;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function writerHandoffEnrollment(): ControlPlaneProxyEnrollmentState
{
    return new ControlPlaneProxyEnrollmentState(
        phase: ControlPlaneProxyEnrollmentPhase::Enrolled,
        operationId: 'writer-handoff-enrollment',
        tokenSha256: hash('sha256', 'writer-handoff-enrollment-token'),
        serverId: 1,
        appPort: 8000,
        exposure: ControlPlaneProxyExposure::Public,
        managedFilename: ControlPlaneDynamicConfiguration::MANAGED_FILENAME,
        dynamicRevision: 1,
        canonicalHost: 'dashboard.example.test',
        publicScheme: 'https',
        expectedMember: 'blue',
        expectedRevision: 'release-1',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        activeBackendDnsNames: ['coolify-predecessor'],
        staticPredecessorBytes: "services:\n  traefik: {}\n",
        staticReplacementBytes: "services:\n  traefik:\n    ports: ['80:80']\n",
        sourceOverrideBytes: "services:\n  coolify:\n    ports: !reset []\n",
        dynamicPredecessorBytes: null,
        dynamicReplacementBytes: "http:\n  routers:\n    dashboard: {}\n",
        createdAt: '2026-07-19T12:00:00Z',
        updatedAt: '2026-07-19T12:00:00Z',
    );
}

function writerHandoffReservedState(): ControlPlaneGenerationPromotionState
{
    $enrollment = writerHandoffEnrollment();

    return ControlPlaneGenerationPromotionState::reserve(
        operationId: 'writer-handoff-promotion',
        token: 'writer-handoff-secret-token',
        serverId: 1,
        predecessor: $enrollment,
        successorDynamicRevision: 2,
        successorDynamicSha256: hash('sha256', "http:\n  routers:\n    dashboard-successor: {}\n"),
        successorMember: 'green',
        successorReleaseRevision: 'release-2',
        successorBackends: ['coolify-writer'],
        successorConfigurationAcknowledgement: 'ack:'.str_repeat('b', 64),
        runtime: new ControlPlaneGenerationRuntime(
            predecessorRuntime: [
                'coolify-predecessor' => [
                    'container_id' => str_repeat('c', 64),
                    'image_id' => 'sha256:'.str_repeat('d', 64),
                ],
            ],
            successorRuntime: [
                'coolify-writer' => [
                    'container_id' => str_repeat('a', 64),
                    'image_id' => 'sha256:'.str_repeat('b', 64),
                ],
            ],
            writerContainerName: 'coolify-writer',
            writerContainerId: str_repeat('a', 64),
        ),
        writerMember: 'green',
        writerEpoch: 2,
        timestamp: '2026-07-19T12:00:00Z',
    );
}

function writerHandoffSuccessorObservation(ControlPlaneGenerationPromotionState $state, string $observedAt): array
{
    return [
        'operation_id' => $state->operationId,
        'dynamic_revision' => $state->successor['dynamic_revision'],
        'dynamic_sha256' => $state->successor['dynamic_sha256'],
        'configuration_acknowledgement' => $state->successor['configuration_acknowledgement'],
        'member' => $state->successor['member'],
        'release_revision' => $state->successor['release_revision'],
        'observed_at' => $observedAt,
    ];
}

function writerHandoffZeroObservation(ControlPlaneGenerationPromotionState $state, string $observedAt): array
{
    return [
        'observed_at' => $observedAt,
        'pending' => 0,
        'reserved' => 0,
        'delayed' => 0,
        'tcp_connection_count' => 0,
        'predecessor_runtime_sha256' => $state->predecessorRuntimeSha256(),
    ];
}

function writerHandoffState(
    ControlPlaneGenerationPromotionPhase $phase = ControlPlaneGenerationPromotionPhase::WriterPromoting,
): ControlPlaneGenerationPromotionState {
    $state = writerHandoffReservedState();
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::CandidateProving, '2026-07-19T12:01:00Z');
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::CandidateProven, '2026-07-19T12:02:00Z');
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::Freezing, '2026-07-19T12:03:00Z');
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::Frozen, '2026-07-19T12:04:00Z', [
        'runtime_fence' => ['epoch' => $state->writerEpoch, 'observed_at' => '2026-07-19T12:04:00Z'],
        'mutation_freeze' => ['operation_id' => $state->operationId, 'observed_at' => '2026-07-19T12:04:00Z'],
    ]);
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::Quiescing, '2026-07-19T12:05:00Z');
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::Quiesced, '2026-07-19T12:06:00Z', [
        'queue_inventory' => [
            'pending' => 0,
            'reserved' => 0,
            'delayed' => 0,
            'observed_at' => '2026-07-19T12:06:00Z',
        ],
    ]);
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::Switching, '2026-07-19T12:07:00Z');
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement, '2026-07-19T12:08:00Z', [
        'dynamic_written' => writerHandoffSuccessorObservation($state, '2026-07-19T12:08:00Z'),
    ]);
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::Draining, '2026-07-19T12:09:00Z', [
        'dual_route' => writerHandoffSuccessorObservation($state, '2026-07-19T12:09:00Z'),
        'draining' => ['deadline_at' => '2026-07-19T12:20:00Z', 'stable_zero_observations' => []],
    ]);
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::Retiring, '2026-07-19T12:10:00Z', [
        'draining' => [
            'deadline_at' => '2026-07-19T12:20:00Z',
            'stable_zero_observations' => [
                writerHandoffZeroObservation($state, '2026-07-19T12:10:00Z'),
                writerHandoffZeroObservation($state, '2026-07-19T12:11:00Z'),
            ],
        ],
    ]);
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::WriterPromoting, '2026-07-19T12:12:00Z', [
        'retired_at' => '2026-07-19T12:12:00Z',
    ]);

    if ($phase === ControlPlaneGenerationPromotionPhase::WriterPromoting) {
        return $state;
    }

    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::FenceReleasing, '2026-07-19T12:13:00Z', [
        'writer_promoted_at' => '2026-07-19T12:13:00Z',
    ]);
    if ($phase === ControlPlaneGenerationPromotionPhase::FenceReleasing) {
        return $state;
    }

    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::Unfreezing, '2026-07-19T12:14:00Z', [
        'fence_released_at' => '2026-07-19T12:14:00Z',
    ]);
    if ($phase === ControlPlaneGenerationPromotionPhase::Unfreezing) {
        return $state;
    }

    throw new InvalidArgumentException('The writer handoff fixture only supports promotion and replay phases.');
}

/** @return array{root: string, bin: string, container_root: string, marker: string, state: string, log: string, docker_id: string, container_name: string, image_id: string} */
function writerHandoffFixture(): array
{
    $root = sys_get_temp_dir().'/coolify-control-plane-writer-handoff-'.bin2hex(random_bytes(8));
    $bin = $root.'/bin';
    $containerRoot = $root.'/container';
    $marker = $containerRoot.ControlPlaneGenerationWriterHandoff::CONTAINER_MARKER_PATH;
    $state = $root.'/docker-state';
    $log = $root.'/docker.log';
    (new Filesystem)->mkdir([$bin, dirname($marker)], 0700);
    file_put_contents($state, implode("\n", [
        'docker_id='.str_repeat('a', 64),
        'container_name=coolify-writer',
        'image_id=sha256:'.str_repeat('b', 64),
        'running=true',
        '',
    ]));
    file_put_contents($log, '');
    file_put_contents($bin.'/docker', <<<'SH'
#!/bin/sh
set -eu

state=$FAKE_DOCKER_STATE
log=$FAKE_DOCKER_LOG
container_root=$FAKE_CONTAINER_ROOT
command=$1
shift

case "$command" in
  inspect)
    selector=
    for argument in "$@"; do selector=$argument; done
    printf 'inspect %s\n' "$selector" >> "$log"
    [ "${FAKE_DOCKER_MISSING:-}" != 1 ] || exit 1
    if [ "${FAKE_DOCKER_INSPECTION+x}" = x ]; then
      printf '%s\n' "$FAKE_DOCKER_INSPECTION"
      exit 0
    fi
    . "$state"
    printf '%s|/%s|%s|%s\n' "$docker_id" "$container_name" "$image_id" "$running"
    ;;
  exec)
    selector=$1
    shift
    printf 'exec %s\n' "$selector" >> "$log"
    . "$state"
    [ "$selector" = "$docker_id" ] || exit 1
    [ "${FAKE_DOCKER_EXEC_FAIL:-}" != 1 ] || exit 1
    shell_command=$1
    shell_flags=$2
    shell_script=$3
    shift 3
    argument_zero=$1
    marker_path=$2
    shift 2
    case "$marker_path" in
      /var/www/html/storage/framework/cache/*) ;;
      *) exit 1 ;;
    esac
    exec "$shell_command" "$shell_flags" "$shell_script" "$argument_zero" "$container_root$marker_path" "$@"
    ;;
  *) exit 1 ;;
esac
SH
    );
    chmod($bin.'/docker', 0700);
    file_put_contents($bin.'/sync', <<<'SH'
#!/bin/sh
set -eu
[ "$#" = 1 ]
case "$1" in -*) exit 64 ;; esac
printf 'sync %s\n' "$1" >> "$FAKE_DOCKER_LOG"
SH
    );
    chmod($bin.'/sync', 0700);

    return [
        'root' => $root,
        'bin' => $bin,
        'container_root' => $containerRoot,
        'marker' => $marker,
        'state' => $state,
        'log' => $log,
        'docker_id' => str_repeat('a', 64),
        'container_name' => 'coolify-writer',
        'image_id' => 'sha256:'.str_repeat('b', 64),
    ];
}

/** @param array{bin: string, container_root: string, state: string, log: string} $fixture */
function runWriterHandoffCommand(string $command, array $fixture, array $environment = []): Process
{
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(5);
    $process->setEnv(array_replace([
        'PATH' => $fixture['bin'].':'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'FAKE_CONTAINER_ROOT' => $fixture['container_root'],
        'FAKE_DOCKER_STATE' => $fixture['state'],
        'FAKE_DOCKER_LOG' => $fixture['log'],
    ], $environment));
    $process->run();

    return $process;
}

function expectedWriterHandoffMarker(ControlPlaneGenerationPromotionState $state): string
{
    $writer = $state->runtime->writerIdentity();

    return json_encode([
        'operation_id' => $state->operationId,
        'writer_epoch' => $state->writerEpoch,
        'writer_member' => $state->writerMember,
        'writer_container_id' => $writer['container_id'],
        'writer_image_id' => $writer['image_id'],
        'successor_dynamic_sha256' => $state->successor['dynamic_sha256'],
        'successor_release_revision' => $state->successor['release_revision'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

it('renders one deterministic, secret-free, full-ID writer handoff command', function (): void {
    $state = writerHandoffState();
    $action = new ControlPlaneGenerationWriterHandoff;
    $command = ControlPlaneGenerationWriterHandoff::run($state);
    $writer = $state->runtime->writerIdentity();

    expect($command)->toBe($action->commandFor($state))
        ->and($command)->toContain("expected_docker_id='".$writer['container_id']."'")
        ->and($command)->toContain("expected_container_name='".$writer['name']."'")
        ->and($command)->toContain("expected_image_id='".$writer['image_id']."'")
        ->and($command)->toContain(escapeshellarg(ControlPlaneGenerationWriterHandoff::CONTAINER_MARKER_PATH))
        ->and($command)->toContain(escapeshellarg(expectedWriterHandoffMarker($state)))
        ->and($command)->toContain("allow_marker_create='true'")
        ->and($command)->toContain('docker inspect --type container')
        ->and($command)->toContain('docker exec "$expected_docker_id" sh -ceu')
        ->and($command)->toContain('mktemp')
        ->and($command)->toContain('chmod 0600')
        ->and($command)->toContain('durable_remote_replace "$temporary_path" "$marker_path" "$marker_directory"')
        ->and($command)->not->toContain('sync -f')
        ->and($command)->toContain('cmp -s')
        ->and($command)->not->toContain('writer-handoff-secret-token')
        ->and($command)->not->toContain('token_sha256')
        ->and($command)->not->toContain('docker exec "$expected_container_name"');
});

it('attests the exact successor writer, writes its authority marker atomically, and replays it', function (): void {
    $filesystem = new Filesystem;
    $fixture = writerHandoffFixture();
    $state = writerHandoffState();
    $command = ControlPlaneGenerationWriterHandoff::run($state);

    try {
        $first = runWriterHandoffCommand($command, $fixture);
        $second = runWriterHandoffCommand($command, $fixture);
        $log = file_get_contents($fixture['log']);

        expect($first->isSuccessful())->toBeTrue()
            ->and($first->getOutput())->toBe(ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n")
            ->and($second->isSuccessful())->toBeTrue()
            ->and($second->getOutput())->toBe(ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n")
            ->and(file_get_contents($fixture['marker']))->toBe(expectedWriterHandoffMarker($state))
            ->and(fileperms($fixture['marker']) & 0777)->toBe(0600)
            ->and($log)->toContain('inspect '.$fixture['docker_id'])
            ->and($log)->toContain('exec '.$fixture['docker_id'])
            ->and($log)->toContain('sync '.$fixture['marker'])
            ->and($log)->toContain('sync '.dirname($fixture['marker']))
            ->and($log)->not->toContain('sync -f')
            ->and($log)->not->toContain('exec '.$fixture['container_name'])
            ->and($command)->not->toContain('writer-handoff-secret-token');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('fails before Docker exec when any exact writer identity field differs', function (string $inspection): void {
    $filesystem = new Filesystem;
    $fixture = writerHandoffFixture();

    try {
        $result = runWriterHandoffCommand(
            ControlPlaneGenerationWriterHandoff::run(writerHandoffState()),
            $fixture,
            ['FAKE_DOCKER_INSPECTION' => $inspection],
        );

        expect($result->isSuccessful())->toBeFalse()
            ->and(file_exists($fixture['marker']))->toBeFalse()
            ->and(file_get_contents($fixture['log']))->toBe('inspect '.$fixture['docker_id']."\n");
    } finally {
        $filesystem->remove($fixture['root']);
    }
})->with([
    'container ID mismatch' => [str_repeat('0', 64).'|/coolify-writer|sha256:'.str_repeat('b', 64).'|true'],
    'container name mismatch' => [str_repeat('a', 64).'|/foreign-writer|sha256:'.str_repeat('b', 64).'|true'],
    'immutable image mismatch' => [str_repeat('a', 64).'|/coolify-writer|sha256:'.str_repeat('0', 64).'|true'],
    'stopped writer' => [str_repeat('a', 64).'|/coolify-writer|sha256:'.str_repeat('b', 64).'|false'],
]);

it('fails closed without overwriting a non-identical authority marker', function (): void {
    $filesystem = new Filesystem;
    $fixture = writerHandoffFixture();
    $state = writerHandoffState();

    try {
        $nonIdenticalMarker = expectedWriterHandoffMarker($state)."\n";
        file_put_contents($fixture['marker'], $nonIdenticalMarker);
        $result = runWriterHandoffCommand(ControlPlaneGenerationWriterHandoff::run($state), $fixture);

        expect($result->isSuccessful())->toBeFalse()
            ->and(file_get_contents($fixture['marker']))->toBe($nonIdenticalMarker)
            ->and(file_get_contents($fixture['log']))->toContain('exec '.$fixture['docker_id']);
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('rejects symlinked and non-regular writer authority targets', function (string $target): void {
    $filesystem = new Filesystem;
    $fixture = writerHandoffFixture();
    $outside = $fixture['root'].'/outside-marker';

    try {
        if ($target === 'symlink') {
            file_put_contents($outside, 'outside marker');
            symlink($outside, $fixture['marker']);
        } else {
            mkdir($fixture['marker'], 0700);
        }

        $result = runWriterHandoffCommand(ControlPlaneGenerationWriterHandoff::run(writerHandoffState()), $fixture);

        expect($result->isSuccessful())->toBeFalse()
            ->and($target === 'symlink' ? file_get_contents($outside) : is_dir($fixture['marker']))
            ->toBe($target === 'symlink' ? 'outside marker' : true);
    } finally {
        $filesystem->remove($fixture['root']);
    }
})->with([
    'symlinked marker' => ['symlink'],
    'directory marker' => ['directory'],
]);

it('allows only WriterPromoting to create and later phases to replay the exact marker', function (): void {
    $filesystem = new Filesystem;
    $fixture = writerHandoffFixture();
    $action = new ControlPlaneGenerationWriterHandoff;
    $fenceReleasing = writerHandoffState(ControlPlaneGenerationPromotionPhase::FenceReleasing);
    $unfreezing = writerHandoffState(ControlPlaneGenerationPromotionPhase::Unfreezing);

    try {
        $replayWithoutMarker = runWriterHandoffCommand($action->commandFor($fenceReleasing), $fixture);
        $writerPromoting = runWriterHandoffCommand($action->commandFor(writerHandoffState()), $fixture);
        $fenceReplay = runWriterHandoffCommand($action->commandFor($fenceReleasing), $fixture);
        $unfreezeReplay = runWriterHandoffCommand($action->commandFor($unfreezing), $fixture);

        expect($replayWithoutMarker->isSuccessful())->toBeFalse()
            ->and($writerPromoting->isSuccessful())->toBeTrue()
            ->and($fenceReplay->getOutput())->toBe(ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n")
            ->and($unfreezeReplay->getOutput())->toBe(ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n")
            ->and($action->commandFor($fenceReleasing))->toContain("allow_marker_create='false'")
            ->and($action->commandFor($unfreezing))->toContain("allow_marker_create='false'")
            ->and(fn (): string => $action->commandFor(writerHandoffReservedState()))
            ->toThrow(InvalidArgumentException::class, 'WriterPromoting');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('is valid POSIX shell under dash and bash and has no shellcheck findings', function (): void {
    $filesystem = new Filesystem;
    $root = sys_get_temp_dir().'/coolify-control-plane-writer-handoff-shell-'.bin2hex(random_bytes(8));
    $commandPath = $root.'/writer-handoff.sh';
    $filesystem->mkdir($root, 0700);
    file_put_contents($commandPath, ControlPlaneGenerationWriterHandoff::run(writerHandoffState()));

    try {
        foreach ([['dash', '-n', $commandPath], ['bash', '-n', $commandPath], ['shellcheck', '-s', 'sh', $commandPath]] as $arguments) {
            $process = new Process($arguments);
            $process->run();

            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        }
    } finally {
        $filesystem->remove($root);
    }
});
