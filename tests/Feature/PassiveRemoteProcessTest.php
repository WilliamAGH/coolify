<?php

use App\Exceptions\ControlPlaneMutationLockedException;
use App\Models\Server;
use App\Support\ControlPlaneMode;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ControlPlaneStateFixture;

$originalControlPlaneEnvironment = [
    'CONTROL_PLANE_MODE' => getenv('CONTROL_PLANE_MODE'),
    'CONTROL_PLANE_MUTATION_FREEZE_EPOCH' => getenv('CONTROL_PLANE_MUTATION_FREEZE_EPOCH'),
    'CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH' => getenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH'),
];

it('rejects every remote execution helper before touching a server in passive mode even when freeze state is malformed', function (string $operation) {
    putenv('CONTROL_PLANE_MODE=passive');
    putenv('CONTROL_PLANE_MUTATION_FREEZE_EPOCH=malformed freeze epoch');
    putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH=/tmp/mutation-freeze-epoch');
    config()->set('control-plane.mode', ControlPlaneMode::Passive->value);

    $server = Mockery::mock(Server::class);
    $invoke = match ($operation) {
        'remote-process' => fn () => remote_process(['true'], $server),
        'scp' => fn () => instant_scp('/tmp/source', '/tmp/destination', $server),
        'remote-process-with-timeout' => fn () => instant_remote_process_with_timeout(['true'], $server),
        'instant-remote-process' => fn () => instant_remote_process(['true'], $server),
    };

    expect($invoke)
        ->toThrow(LogicException::class, 'Remote execution is unavailable in passive control plane mode.');
})->with([
    'queued remote process' => 'remote-process',
    'scp' => 'scp',
    'remote process with timeout' => 'remote-process-with-timeout',
    'instant remote process' => 'instant-remote-process',
]);

it('rejects every remote execution helper before activity, queue, or process side effects during a durable mutation freeze', function (string $operation) {
    if (($unsupportedReason = ControlPlaneStateFixture::unsupportedReason()) !== null) {
        $this->markTestSkipped($unsupportedReason);
    }

    $fixture = ControlPlaneStateFixture::create('coolify-remote-process-mutation-freeze');
    $freezeEpoch = 'operation-0123456789.fwd.mutation-freeze';

    try {
        putenv('CONTROL_PLANE_MODE=active');
        putenv("CONTROL_PLANE_MUTATION_FREEZE_EPOCH={$freezeEpoch}");
        putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH='.$fixture->path('mutation-freeze-epoch'));
        config()->set('control-plane.mode', ControlPlaneMode::Active->value);
        $fixture->writeMarker('mutation-freeze-epoch', $freezeEpoch);
        $fixture->writeMutationLease();
        Process::fake();
        Queue::fake();

        $server = Mockery::mock(Server::class);
        $server->shouldNotReceive('isNonRoot');
        $invoke = match ($operation) {
            'remote-process' => fn () => remote_process(['true'], $server),
            'scp' => fn () => instant_scp('/tmp/source', '/tmp/destination', $server),
            'remote-process-with-timeout' => fn () => instant_remote_process_with_timeout(['true'], $server),
            'instant-remote-process' => fn () => instant_remote_process(['true'], $server),
        };

        expect($invoke)
            ->toThrow(ControlPlaneMutationLockedException::class, 'Control-plane mutations are temporarily locked.');

        Process::assertNothingRan();
        Queue::assertNothingPushed();
    } finally {
        $fixture->cleanup();
    }
})->with([
    'queued remote process' => 'remote-process',
    'scp' => 'scp',
    'remote process with timeout' => 'remote-process-with-timeout',
    'instant remote process' => 'instant-remote-process',
]);

it('does not require mutation state before entering remote execution when no freeze is configured', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_MUTATION_FREEZE_EPOCH');
    putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH');
    config()->set('control-plane.mode', ControlPlaneMode::Active->value);

    $server = Mockery::mock(Server::class);
    $server->shouldReceive('isNonRoot')
        ->once()
        ->andThrow(new RuntimeException('remote execution entered'));

    expect(fn () => instant_remote_process(['true'], $server))
        ->toThrow(RuntimeException::class, 'remote execution entered');
});

afterEach(function () use ($originalControlPlaneEnvironment) {
    foreach ($originalControlPlaneEnvironment as $name => $value) {
        putenv($value === false ? $name : "{$name}={$value}");
    }
});
