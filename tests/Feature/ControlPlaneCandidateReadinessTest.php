<?php

use App\Http\Controllers\Api\PreflightController;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

$originalCandidateReadinessEnvironment = [
    'CONTROL_PLANE_MODE' => getenv('CONTROL_PLANE_MODE'),
    'CONTROL_PLANE_STARTUP_MODE' => getenv('CONTROL_PLANE_STARTUP_MODE'),
    'CONTROL_PLANE_WRITER_EPOCH' => getenv('CONTROL_PLANE_WRITER_EPOCH'),
    'CONTROL_PLANE_WRITER_MARKER_PATH' => getenv('CONTROL_PLANE_WRITER_MARKER_PATH'),
];

beforeEach(function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
    putenv('CONTROL_PLANE_WRITER_EPOCH=candidate-readiness-writer-0123456789');
    putenv('CONTROL_PLANE_WRITER_MARKER_PATH=/var/lib/coolify-control-plane/absent-candidate-readiness-writer');
    config()->set([
        'app.maintenance.driver' => 'file',
        'app.maintenance.store' => null,
        'cache.default' => 'array',
        'debugbar.enabled' => false,
        'ray.enable' => false,
        'telescope.enabled' => false,
    ]);

    $this->candidateProbeDirectory = sys_get_temp_dir().'/coolify-candidate-readiness-'.bin2hex(random_bytes(8));
    $this->candidateProbeToken = 'candidate-readiness-token-0123456789';
    $this->candidateProbeAcknowledgement = 'candidate-readiness-ack-0123456789';
    mkdir($this->candidateProbeDirectory, 0700);

    $tokenPath = $this->candidateProbeDirectory.'/token';
    $acknowledgementPath = $this->candidateProbeDirectory.'/acknowledgement';
    file_put_contents($tokenPath, $this->candidateProbeToken."\n");
    file_put_contents($acknowledgementPath, $this->candidateProbeAcknowledgement."\n");
    chmod($tokenPath, 0400);
    chmod($acknowledgementPath, 0400);

    config()->set([
        'control-plane.direct_probe_token_path' => $tokenPath,
        'control-plane.applied_ack_path' => $acknowledgementPath,
    ]);
});

afterEach(function () use ($originalCandidateReadinessEnvironment) {
    foreach (['token', 'acknowledgement'] as $secretFile) {
        $secretPath = $this->candidateProbeDirectory.'/'.$secretFile;
        if (is_file($secretPath)) {
            unlink($secretPath);
        }
    }
    if (is_dir($this->candidateProbeDirectory)) {
        rmdir($this->candidateProbeDirectory);
    }

    foreach ($originalCandidateReadinessEnvironment as $name => $configuredValue) {
        putenv($configuredValue === false ? $name : "{$name}={$configuredValue}");
    }
});

it('acknowledges a candidate only after the application database and Redis are ready', function () {
    $database = Mockery::mock(Connection::class);
    $database->expects('scalar')
        ->with('select 1', [], false)
        ->andReturn(1);
    DB::shouldReceive('connection')->once()->withNoArgs()->andReturn($database);

    $redis = Mockery::mock();
    $redis->expects('command')->with('ping')->andReturn(true);
    Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);

    $this->get('/api/control-plane/probe', [
        'X-Control-Plane-Probe' => $this->candidateProbeToken,
    ])
        ->assertNoContent()
        ->assertHeader('X-Control-Plane-Applied-Config', $this->candidateProbeAcknowledgement);
});

it('withholds the candidate acknowledgement when the application database is unavailable', function () {
    DB::shouldReceive('connection')->once()->withNoArgs()->andThrow(new RuntimeException('database unavailable'));
    Redis::shouldReceive('connection')->never();

    try {
        app(PreflightController::class)->directProbe(Request::create(
            '/api/control-plane/probe',
            'GET',
            server: ['HTTP_X_CONTROL_PLANE_PROBE' => $this->candidateProbeToken],
        ));
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);

        return;
    }

    $this->fail('The candidate probe acknowledged an unavailable application database.');
});

it('withholds the candidate acknowledgement when application Redis is unavailable', function () {
    $database = Mockery::mock(Connection::class);
    $database->expects('scalar')
        ->with('select 1', [], false)
        ->andReturn('1');
    DB::shouldReceive('connection')->once()->withNoArgs()->andReturn($database);

    $redis = Mockery::mock();
    $redis->expects('command')->with('ping')->andThrow(new RuntimeException('Redis unavailable'));
    Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);

    try {
        app(PreflightController::class)->directProbe(Request::create(
            '/api/control-plane/probe',
            'GET',
            server: ['HTTP_X_CONTROL_PLANE_PROBE' => $this->candidateProbeToken],
        ));
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);

        return;
    }

    $this->fail('The candidate probe acknowledged unavailable application Redis.');
});

it('does not disclose dependency readiness to an unauthenticated probe', function () {
    DB::shouldReceive('connection')->never();
    Redis::shouldReceive('connection')->never();

    try {
        app(PreflightController::class)->directProbe(Request::create(
            '/api/control-plane/probe',
            'GET',
            server: ['HTTP_X_CONTROL_PLANE_PROBE' => 'incorrect-candidate-readiness-token'],
        ));
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);

        return;
    }

    $this->fail('The candidate probe disclosed dependency readiness without authentication.');
});
