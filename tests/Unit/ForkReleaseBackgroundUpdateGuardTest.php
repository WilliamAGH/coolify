<?php

use App\Actions\Server\StartSentinel;
use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\CheckHelperImageJob;
use App\Models\Server;
use App\Support\ControlPlaneMode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['constants.coolify.version' => '4.13.0-fork.1']);
    Http::preventStrayRequests();
});

it('skips upstream helper metadata for a fork release', function () {
    (new CheckHelperImageJob)->handle();

    Http::assertNothingSent();
});

it('does not start or update sentinel from upstream metadata for a fork release', function () {
    StartSentinel::shouldNotRun();
    $server = (new Server)->forceFill([
        'id' => 0,
        'uuid' => 'coolify-server',
        'name' => 'Coolify',
        'ip' => '127.0.0.1',
    ]);

    (new CheckAndStartSentinelJob($server))->handle();

    Http::assertNothingSent();
});

it('returns before the direct sentinel action acquires a queue lease or dispatches remote work for a fork release', function () {
    $originalControlPlaneMode = getenv('CONTROL_PLANE_MODE');
    $configuredControlPlaneMode = config('control-plane.mode');

    try {
        putenv('CONTROL_PLANE_MODE=passive');
        config(['control-plane.mode' => ControlPlaneMode::Passive->value]);
        Http::preventStrayRequests();
        Process::fake();
        Queue::fake();

        (new StartSentinel)->handle(Mockery::mock(Server::class), restart: true);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Process::assertNothingRan();
    } finally {
        putenv($originalControlPlaneMode === false
            ? 'CONTROL_PLANE_MODE'
            : "CONTROL_PLANE_MODE={$originalControlPlaneMode}");
        config(['control-plane.mode' => $configuredControlPlaneMode]);
    }
});
