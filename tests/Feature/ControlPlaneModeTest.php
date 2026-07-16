<?php

use App\Console\Commands\Seeder as SeederCommand;
use App\Exceptions\ControlPlaneMutationLockedException;
use App\Http\Controllers\Api\PreflightController;
use App\Http\Middleware\EnforceControlPlaneMode;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Models\InstanceSettings;
use App\Providers\AppServiceProvider;
use App\Support\ControlPlaneMode;
use App\Support\ControlPlaneReadOnlyRoutePolicy;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\Process;
use Tests\Support\ControlPlaneStateFixture;

uses(RefreshDatabase::class);

$originalControlPlaneEnvironment = [
    'CONTROL_PLANE_MODE' => getenv('CONTROL_PLANE_MODE'),
    'CONTROL_PLANE_STARTUP_MODE' => getenv('CONTROL_PLANE_STARTUP_MODE'),
    'CONTROL_PLANE_PROMOTION_IN_PROGRESS' => getenv('CONTROL_PLANE_PROMOTION_IN_PROGRESS'),
    'CONTROL_PLANE_WRITER_EPOCH' => getenv('CONTROL_PLANE_WRITER_EPOCH'),
    'CONTROL_PLANE_WRITER_MARKER_PATH' => getenv('CONTROL_PLANE_WRITER_MARKER_PATH'),
    'CONTROL_PLANE_WEB_EPOCH' => getenv('CONTROL_PLANE_WEB_EPOCH'),
    'CONTROL_PLANE_WEB_MARKER_PATH' => getenv('CONTROL_PLANE_WEB_MARKER_PATH'),
    'CONTROL_PLANE_ROUTE_DRAIN_EPOCH' => getenv('CONTROL_PLANE_ROUTE_DRAIN_EPOCH'),
    'CONTROL_PLANE_ROUTE_DRAIN_MARKER_PATH' => getenv('CONTROL_PLANE_ROUTE_DRAIN_MARKER_PATH'),
    'CONTROL_PLANE_MUTATION_FREEZE_EPOCH' => getenv('CONTROL_PLANE_MUTATION_FREEZE_EPOCH'),
    'CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH' => getenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH'),
];

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    config()->set([
        'app.maintenance.driver' => 'file',
        'app.maintenance.store' => null,
    ]);
});

afterEach(function () use ($originalControlPlaneEnvironment) {
    foreach ($originalControlPlaneEnvironment as $name => $value) {
        putenv($value === false ? $name : "{$name}={$value}");
    }
});

it('defaults the control plane to active mode', function () {
    putenv('CONTROL_PLANE_MODE');

    expect(ControlPlaneMode::fromEnvironment())->toBe(ControlPlaneMode::Active);
});

it('rejects unknown control plane modes', function () {
    putenv('CONTROL_PLANE_MODE=standby');

    ControlPlaneMode::fromEnvironment();
})->throws(InvalidArgumentException::class, 'CONTROL_PLANE_MODE must be one of: active, passive.');

it('rejects unknown control plane startup modes', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=standby');

    ControlPlaneMode::backgroundServicesAllowed();
})->throws(InvalidArgumentException::class, 'CONTROL_PLANE_STARTUP_MODE must be one of: full, web-only.');

it('requires a deployment writer epoch in active web only mode', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
    putenv('CONTROL_PLANE_WRITER_EPOCH');
    putenv('CONTROL_PLANE_WRITER_MARKER_PATH=/var/lib/coolify-control-plane/writer-epoch');

    ControlPlaneMode::backgroundServicesAllowed();
})->throws(
    InvalidArgumentException::class,
    'CONTROL_PLANE_WRITER_EPOCH must contain 16 to 128 safe token characters.'
);

it('keeps a web-only peer without writer authority background-disabled', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
    putenv('CONTROL_PLANE_WRITER_EPOCH');
    putenv('CONTROL_PLANE_WRITER_MARKER_PATH');
    config()->set([
        'control-plane.writer_epoch' => null,
        'control-plane.writer_marker_path' => null,
    ]);

    expect(ControlPlaneMode::writerOwnershipProven())->toBeFalse()
        ->and(ControlPlaneMode::backgroundServicesAllowed())->toBeFalse();
});

it('rejects ephemeral writer marker paths in active web only mode', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
    putenv('CONTROL_PLANE_WRITER_EPOCH=deployment-epoch-0001');
    putenv('CONTROL_PLANE_WRITER_MARKER_PATH=/tmp/writer-epoch');

    ControlPlaneMode::backgroundServicesAllowed();
})->throws(
    InvalidArgumentException::class,
    'CONTROL_PLANE_WRITER_MARKER_PATH must be an absolute persistent path without dot segments.'
);

it('fences every application route when web ownership is not proven', function () {
    $directory = storage_path('framework/testing/coolify-control-plane-web-fence-'.bin2hex(random_bytes(8)));
    $webMarkerPath = $directory.'/web-epoch';

    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
    putenv('CONTROL_PLANE_WEB_EPOCH=green-web-epoch-0123456789');
    putenv("CONTROL_PLANE_WEB_MARKER_PATH={$webMarkerPath}");
    putenv('CONTROL_PLANE_WRITER_EPOCH=green-writer-epoch-0123456789');
    putenv("CONTROL_PLANE_WRITER_MARKER_PATH={$directory}/writer-epoch");

    $this->get('/')->assertServiceUnavailable();
    $this->getJson('/api/v1/enable')->assertServiceUnavailable();
    $this->getJson('/api/v1/servers/server-uuid/validate')->assertServiceUnavailable();
    $this->postJson('/api/health')->assertServiceUnavailable();
    $this->get('/api/health')->assertSuccessful();
});

it('unfences application routes only after secure web ownership is proven', function () {
    if (($unsupportedReason = ControlPlaneStateFixture::unsupportedReason()) !== null) {
        $this->markTestSkipped($unsupportedReason);
    }

    $fixture = ControlPlaneStateFixture::create('coolify-control-plane-web-owned');
    $webMarkerPath = $fixture->path('web-epoch');

    try {
        putenv('CONTROL_PLANE_MODE=active');
        putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
        putenv('CONTROL_PLANE_WEB_EPOCH=green-web-epoch-0123456789');
        putenv("CONTROL_PLANE_WEB_MARKER_PATH={$webMarkerPath}");
        putenv('CONTROL_PLANE_WRITER_EPOCH=green-writer-epoch-0123456789');
        putenv('CONTROL_PLANE_WRITER_MARKER_PATH='.$fixture->path('writer-epoch'));

        $fixture->writeMarker('web-epoch', 'green-web-epoch-0123456789');

        $this->postJson('/api/health')->assertNotFound();
        expect($this->get('/')->getStatusCode())->not->toBe(Response::HTTP_SERVICE_UNAVAILABLE);
        expect(ControlPlaneMode::backgroundServicesAllowed())->toBeFalse();
    } finally {
        $fixture->cleanup();
    }
});

it('keeps web ownership separate from background writer ownership across process restarts', function () {
    if (($unsupportedReason = ControlPlaneStateFixture::unsupportedReason()) !== null) {
        $this->markTestSkipped($unsupportedReason);
    }

    $fixture = ControlPlaneStateFixture::create('coolify-control-plane-split-fence');
    $writerMarkerPath = $fixture->path('writer-epoch');
    $webMarkerPath = $fixture->path('web-epoch');

    try {
        putenv('CONTROL_PLANE_MODE=active');
        putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
        putenv('CONTROL_PLANE_WRITER_EPOCH=green-writer-epoch-0123456789');
        putenv("CONTROL_PLANE_WRITER_MARKER_PATH={$writerMarkerPath}");
        putenv('CONTROL_PLANE_WEB_EPOCH=green-web-epoch-0123456789');
        putenv("CONTROL_PLANE_WEB_MARKER_PATH={$webMarkerPath}");
        $fixture->writeMarker('web-epoch', 'green-web-epoch-0123456789');

        expect(ControlPlaneMode::webOwnershipProven())->toBeTrue()
            ->and(ControlPlaneMode::writerOwnershipProven())->toBeFalse()
            ->and(ControlPlaneMode::backgroundServicesAllowed())->toBeFalse();
        expect($this->get('/')->getStatusCode())->not->toBe(Response::HTTP_SERVICE_UNAVAILABLE);
        expect($this->getJson('/api/v1/enable')->getStatusCode())
            ->not->toBe(Response::HTTP_SERVICE_UNAVAILABLE);
    } finally {
        $fixture->cleanup();
    }
});

it('returns an explicit lock response for every dynamic request during a durable freeze', function () {
    if (($unsupportedReason = ControlPlaneStateFixture::unsupportedReason()) !== null) {
        $this->markTestSkipped($unsupportedReason);
    }

    $fixture = ControlPlaneStateFixture::create('coolify-control-plane-mutation-freeze');
    $webMarkerPath = $fixture->path('web-epoch');
    $freezeMarkerPath = $fixture->path('mutation-freeze-epoch');
    $webEpoch = 'green-web-epoch-0123456789';
    $freezeEpoch = 'operation-0123456789.fwd.mutation-freeze';

    try {
        putenv('CONTROL_PLANE_MODE=active');
        putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
        putenv("CONTROL_PLANE_WEB_EPOCH={$webEpoch}");
        putenv("CONTROL_PLANE_WEB_MARKER_PATH={$webMarkerPath}");
        putenv('CONTROL_PLANE_WRITER_EPOCH=green-writer-epoch-0123456789');
        putenv('CONTROL_PLANE_WRITER_MARKER_PATH='.$fixture->path('writer-epoch'));
        putenv("CONTROL_PLANE_MUTATION_FREEZE_EPOCH={$freezeEpoch}");
        putenv("CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH={$freezeMarkerPath}");
        $fixture->writeMarker('web-epoch', $webEpoch);
        $fixture->writeMarker('mutation-freeze-epoch', $freezeEpoch);
        $fixture->writeMutationLease();

        $this->get('/api/health')->assertSuccessful();
        foreach ([
            '/api/v1/version',
            '/api/v1/enable/',
            '/auth/github/redirect/',
            '/auth/github/callback/',
            '/webhooks/source/github/redirect/',
            '/settings/backup',
            '/project/test/environment/test/new',
        ] as $dynamicPath) {
            $this->get($dynamicPath)
                ->assertStatus(Response::HTTP_LOCKED)
                ->assertExactJson(['message' => 'Control-plane mutations are temporarily locked']);
            $this->call('HEAD', $dynamicPath)
                ->assertStatus(Response::HTTP_LOCKED);
        }
        foreach (['/api/v1/enable/', '/api/health'] as $dynamicPath) {
            $this->call('OPTIONS', $dynamicPath)
                ->assertStatus(Response::HTTP_LOCKED)
                ->assertExactJson(['message' => 'Control-plane mutations are temporarily locked']);
        }
        $this->postJson('/api/health')
            ->assertStatus(Response::HTTP_LOCKED)
            ->assertExactJson(['message' => 'Control-plane mutations are temporarily locked']);

        $fixture->remove('mutation-freeze-epoch');
        $this->postJson('/api/health')->assertNotFound();
    } finally {
        $fixture->cleanup();
    }
});

it('suppresses startup and scheduled work during a matching durable freeze', function () {
    if (($unsupportedReason = ControlPlaneStateFixture::unsupportedReason()) !== null) {
        $this->markTestSkipped($unsupportedReason);
    }

    $fixture = ControlPlaneStateFixture::create('coolify-control-plane-background-freeze');
    $freezeEpoch = 'operation-0123456789.fwd.mutation-freeze';

    try {
        putenv('CONTROL_PLANE_MODE=active');
        putenv('CONTROL_PLANE_STARTUP_MODE=full');
        putenv("CONTROL_PLANE_MUTATION_FREEZE_EPOCH={$freezeEpoch}");
        putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH='.$fixture->path('mutation-freeze-epoch'));
        $fixture->writeMarker('mutation-freeze-epoch', $freezeEpoch);
        $fixture->writeMutationLease();

        expect(ControlPlaneMode::writerOwnershipProven())->toBeTrue()
            ->and(ControlPlaneMode::backgroundServicesAllowed())->toBeFalse()
            ->and(ControlPlaneMode::startupWorkAllowed())->toBeFalse();

        $this->artisan('start:seeder')
            ->expectsOutputToContain('Control plane startup work is disabled: seeding skipped.')
            ->assertSuccessful();

        Artisan::call('schedule:list');

        expect(Artisan::output())
            ->not->toContain('cleanup:redis')
            ->not->toContain('horizon:snapshot')
            ->not->toContain('ServerManagerJob');
    } finally {
        $fixture->cleanup();
    }
});

it('reuses the held mutation lease for nested mutation callbacks', function () {
    if (($unsupportedReason = ControlPlaneStateFixture::unsupportedReason()) !== null) {
        $this->markTestSkipped($unsupportedReason);
    }

    $fixture = ControlPlaneStateFixture::create('coolify-control-plane-nested-mutation-lease');

    try {
        putenv('CONTROL_PLANE_MUTATION_FREEZE_EPOCH=operation-0123456789.fwd.mutation-freeze');
        putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH='.$fixture->path('mutation-freeze-epoch'));
        $fixture->writeMutationLease();

        expect(ControlPlaneMode::withMutationLease(
            fn (): string => ControlPlaneMode::withMutationLease(fn (): string => 'nested-mutation-completed'),
        ))->toBe('nested-mutation-completed');
    } finally {
        $fixture->cleanup();
    }
});

it('treats an entirely absent mutation-freeze configuration as unfrozen', function () {
    $configuredEpoch = config('control-plane.mutation_freeze_epoch');
    $configuredMarkerPath = config('control-plane.mutation_freeze_marker_path');

    try {
        putenv('CONTROL_PLANE_MODE=active');
        putenv('CONTROL_PLANE_MUTATION_FREEZE_EPOCH');
        putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH');
        config()->set([
            'control-plane.mutation_freeze_epoch' => null,
            'control-plane.mutation_freeze_marker_path' => null,
        ]);

        expect(ControlPlaneMode::mutationFreezeActive())->toBeFalse()
            ->and(ControlPlaneMode::withMutationLease(static fn (): string => 'unfrozen'))
            ->toBe('unfrozen');
    } finally {
        config()->set([
            'control-plane.mutation_freeze_epoch' => $configuredEpoch,
            'control-plane.mutation_freeze_marker_path' => $configuredMarkerPath,
        ]);
    }
});

it('rejects incomplete mutation-freeze configuration', function (?string $epoch, ?string $markerPath) {
    $configuredEpoch = config('control-plane.mutation_freeze_epoch');
    $configuredMarkerPath = config('control-plane.mutation_freeze_marker_path');

    try {
        putenv('CONTROL_PLANE_MODE=active');
        putenv('CONTROL_PLANE_MUTATION_FREEZE_EPOCH');
        putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH');
        config()->set([
            'control-plane.mutation_freeze_epoch' => $epoch,
            'control-plane.mutation_freeze_marker_path' => $markerPath,
        ]);

        expect(fn (): bool => ControlPlaneMode::mutationFreezeActive())
            ->toThrow(InvalidArgumentException::class)
            ->and(fn (): null => ControlPlaneMode::withMutationLease(static fn (): null => null))
            ->toThrow(ControlPlaneMutationLockedException::class);
    } finally {
        config()->set([
            'control-plane.mutation_freeze_epoch' => $configuredEpoch,
            'control-plane.mutation_freeze_marker_path' => $configuredMarkerPath,
        ]);
    }
})->with([
    'epoch only' => ['operation-0123456789.fwd.mutation-freeze', null],
    'marker path only' => [null, '/var/lib/coolify-control-plane/mutation-freeze-epoch'],
]);

it('fails closed for a mismatched durable mutation-freeze marker', function () {
    if (($unsupportedReason = ControlPlaneStateFixture::unsupportedReason()) !== null) {
        $this->markTestSkipped($unsupportedReason);
    }

    $fixture = ControlPlaneStateFixture::create('coolify-control-plane-mismatched-mutation-freeze');
    $freezeEpoch = 'operation-0123456789.fwd.mutation-freeze';

    try {
        putenv('CONTROL_PLANE_MODE=active');
        putenv("CONTROL_PLANE_MUTATION_FREEZE_EPOCH={$freezeEpoch}");
        putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH='.$fixture->path('mutation-freeze-epoch'));
        $fixture->writeMutationLease();
        $fixture->writeMarker('mutation-freeze-epoch', 'operation-0123456789.rev.mutation-freeze');

        expect(fn (): bool => ControlPlaneMode::mutationFreezeActive())
            ->toThrow(InvalidArgumentException::class)
            ->and(fn (): null => ControlPlaneMode::withMutationLease(static fn (): null => null))
            ->toThrow(ControlPlaneMutationLockedException::class);
    } finally {
        $fixture->cleanup();
    }
});

it('holds a shared mutation lease until the guarded operation completes', function () {
    if (($unsupportedReason = ControlPlaneStateFixture::unsupportedReason()) !== null) {
        $this->markTestSkipped($unsupportedReason);
    }

    $fixture = ControlPlaneStateFixture::create('coolify-control-plane-shared-mutation-lease');
    $freezeEpoch = 'operation-0123456789.fwd.mutation-freeze';

    try {
        putenv('CONTROL_PLANE_MODE=active');
        putenv("CONTROL_PLANE_MUTATION_FREEZE_EPOCH={$freezeEpoch}");
        putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH='.$fixture->path('mutation-freeze-epoch'));
        $fixture->writeMutationLease();

        $exclusiveProbe = ControlPlaneMode::withMutationLease(function () use ($fixture) {
            $probe = new Process([
                PHP_BINARY,
                '-r',
                '$lease = fopen($argv[1], "rb");'
                .'if ($lease === false) { exit(2); }'
                .'echo flock($lease, LOCK_EX | LOCK_NB) ? "acquired" : "blocked";',
                $fixture->path('mutation-inflight.lock'),
            ]);
            $probe->run();

            return $probe;
        });

        expect($exclusiveProbe->getExitCode())->toBe(0)
            ->and($exclusiveProbe->getOutput())->toBe('blocked');
    } finally {
        $fixture->cleanup();
    }
});

it('refuses a direct mutation lease in passive mode', function () {
    putenv('CONTROL_PLANE_MODE=passive');
    config()->set('control-plane.mode', ControlPlaneMode::Passive->value);

    expect(fn () => ControlPlaneMode::withMutationLease(static fn (): null => null))
        ->toThrow(
            ControlPlaneMutationLockedException::class,
            'Control-plane mutations are unavailable in passive control plane mode.',
        );
});

it('returns the child seeder exit status', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=full');
    config()->set('constants.seeder.is_seeder_enabled', true);

    $command = new class extends SeederCommand
    {
        public function info($string, $verbosity = null) {}

        public function call($command, array $arguments = [])
        {
            return self::FAILURE;
        }
    };

    expect($command->handle())->toBe(Command::FAILURE);
});

it('treats a temporarily locked child seeder as an intentional startup skip', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=full');
    config()->set('constants.seeder.is_seeder_enabled', true);

    $command = new class extends SeederCommand
    {
        /** @var list<string> */
        public array $messages = [];

        public function info($string, $verbosity = null)
        {
            $this->messages[] = $string;
        }

        public function call($command, array $arguments = [])
        {
            throw new ControlPlaneMutationLockedException;
        }
    };

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($command->messages)->toContain('Control plane startup work is disabled: seeding skipped.');
});

it('fails the request fence closed when its configured operation identity is malformed', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_MUTATION_FREEZE_EPOCH=unsafe value');
    putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH=/var/lib/coolify-control-plane/mutation-freeze-epoch');

    $this->postJson('/api/health')->assertStatus(Response::HTTP_LOCKED);
});

it('fails closed when a configured web epoch is malformed', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
    putenv('CONTROL_PLANE_WEB_EPOCH=unsafe value');
    putenv('CONTROL_PLANE_WEB_MARKER_PATH=/var/lib/coolify-control-plane/web-epoch');

    ControlPlaneMode::webOwnershipProven();
})->throws(
    InvalidArgumentException::class,
    'CONTROL_PLANE_WEB_EPOCH must contain 16 to 128 safe token characters.'
);

it('rejects a malformed route-drain epoch', function () {
    putenv('CONTROL_PLANE_ROUTE_DRAIN_EPOCH=unsafe route drain epoch');
    putenv('CONTROL_PLANE_ROUTE_DRAIN_MARKER_PATH=/var/lib/coolify-control-plane/route-drain-epoch');

    ControlPlaneMode::routeDrainActive();
})->throws(
    InvalidArgumentException::class,
    'CONTROL_PLANE_ROUTE_DRAIN_EPOCH must contain 16 to 128 safe token characters.'
);

it('rejects an ephemeral route-drain marker path', function () {
    putenv('CONTROL_PLANE_ROUTE_DRAIN_EPOCH=green-a-route-drain-epoch-0123456789');
    putenv('CONTROL_PLANE_ROUTE_DRAIN_MARKER_PATH=/tmp/route-drain-epoch');

    ControlPlaneMode::routeDrainActive();
})->throws(
    InvalidArgumentException::class,
    'CONTROL_PLANE_ROUTE_DRAIN_MARKER_PATH must be an absolute persistent path without dot segments.'
);

it('treats the explicit process mode as authoritative over cached configuration', function () {
    putenv('CONTROL_PLANE_MODE=passive');
    config()->set('control-plane.mode', ControlPlaneMode::Active->value);

    expect(ControlPlaneMode::configured())->toBe(ControlPlaneMode::Passive);
});

it('forces passive-safe framework and telemetry configuration', function () {
    putenv('CONTROL_PLANE_MODE=passive');
    config()->set([
        'control-plane.mode' => ControlPlaneMode::Passive->value,
        'app.debug' => true,
        'queue.default' => 'redis',
        'cache.default' => 'redis',
        'session.driver' => 'database',
        'broadcasting.default' => 'pusher',
        'mail.default' => 'smtp',
        'logging.default' => 'stack',
        'nightwatch.enabled' => true,
        'sentry.dsn' => 'https://public@example.test/1',
        'sentry.enable_tracing' => true,
        'sentry.enable_logs' => true,
        'telescope.enabled' => true,
        'debugbar.enabled' => true,
        'ray.enable' => true,
        'constants.migration.is_migration_enabled' => true,
        'constants.seeder.is_seeder_enabled' => true,
        'constants.horizon.is_horizon_enabled' => true,
        'constants.horizon.is_scheduler_enabled' => true,
        'constants.nightwatch.is_nightwatch_enabled' => true,
    ]);

    (new AppServiceProvider(app()))->register();

    expect(config('app.debug'))->toBeFalse()
        ->and(config('queue.default'))->toBe('null')
        ->and(config('queue.failed.driver'))->toBe('null')
        ->and(config('cache.default'))->toBe('array')
        ->and(config('session.driver'))->toBe('array')
        ->and(config('broadcasting.default'))->toBe('null')
        ->and(config('mail.default'))->toBe('array')
        ->and(config('logging.default'))->toBe('stderr')
        ->and(config('nightwatch.enabled'))->toBeFalse()
        ->and(config('sentry.dsn'))->toBeNull()
        ->and(config('sentry.enable_tracing'))->toBeFalse()
        ->and(config('sentry.enable_logs'))->toBeFalse()
        ->and(config('telescope.enabled'))->toBeFalse()
        ->and(config('debugbar.enabled'))->toBeFalse()
        ->and(config('ray.enable'))->toBeFalse()
        ->and(config('constants.migration.is_migration_enabled'))->toBeFalse()
        ->and(config('constants.seeder.is_seeder_enabled'))->toBeFalse()
        ->and(config('constants.horizon.is_horizon_enabled'))->toBeFalse()
        ->and(config('constants.horizon.is_scheduler_enabled'))->toBeFalse()
        ->and(config('constants.nightwatch.is_nightwatch_enabled'))->toBeFalse();
});

it('keeps the existing health endpoint available in passive mode', function () {
    putenv('CONTROL_PLANE_MODE=passive');
    config()->set('control-plane.mode', ControlPlaneMode::Passive->value);

    $this->get('/api/health')
        ->assertSuccessful()
        ->assertSeeText('OK');
});

it('shares the exact read-only policy across passive, API, and maintenance boundaries', function () {
    putenv('CONTROL_PLANE_MODE=passive');
    config()->set('control-plane.mode', ControlPlaneMode::Passive->value);

    $middleware = new EnforceControlPlaneMode;
    $maintenanceMiddleware = app(PreventRequestsDuringMaintenance::class);
    $maintenanceBoundary = new ReflectionMethod($maintenanceMiddleware, 'inExceptArray');
    $maintenanceBoundary->setAccessible(true);

    foreach (ControlPlaneReadOnlyRoutePolicy::apiRoutes() as $uri => $action) {
        $path = '/api/'.$uri;
        $response = $middleware->handle(
            Request::create($path, 'GET'),
            fn () => response('allowed'),
        );
        $route = app('router')->getRoutes()->match(Request::create($path, 'GET'));

        expect($response->getStatusCode())->toBe(Response::HTTP_OK)
            ->and($route->getAction('uses'))->toBe($action[0].'@'.$action[1])
            ->and($maintenanceBoundary->invoke(
                $maintenanceMiddleware,
                Request::create($path, 'GET'),
            ))->toBeTrue()
            ->and($maintenanceBoundary->invoke(
                $maintenanceMiddleware,
                Request::create($path.'?probe=1', 'GET'),
            ))->toBeFalse();
    }

    expect($maintenanceBoundary->invoke(
        $maintenanceMiddleware,
        Request::create('/api/control-plane/route-health', 'GET'),
    ))->toBeTrue()
        ->and($maintenanceBoundary->invoke(
            $maintenanceMiddleware,
            Request::create('/api/control-plane/route-health?probe=1', 'GET'),
        ))->toBeFalse()
        ->and(ControlPlaneReadOnlyRoutePolicy::allowsPassiveRequest(
            Request::create('/api/control-plane/route-health', 'GET'),
        ))->toBeFalse();
});

it('acknowledges only the exact active web-only direct-origin probe secret', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');

    $directory = sys_get_temp_dir().'/coolify-control-plane-probe-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $tokenPath = $directory.'/token';
    $acknowledgementPath = $directory.'/acknowledgement';
    $token = 'direct-probe-token-0123456789';
    $acknowledgement = 'applied-config-0123456789';
    file_put_contents($tokenPath, $token."\n");
    file_put_contents($acknowledgementPath, $acknowledgement."\n");
    chmod($tokenPath, 0400);
    chmod($acknowledgementPath, 0400);
    config()->set([
        'control-plane.direct_probe_token_path' => $tokenPath,
        'control-plane.applied_ack_path' => $acknowledgementPath,
    ]);

    $connection = Mockery::mock(Connection::class);
    $connection->expects('scalar')
        ->twice()
        ->with('select 1', [], false)
        ->andReturn(1);
    DB::shouldReceive('connection')->twice()->withNoArgs()->andReturn($connection);

    $redis = Mockery::mock();
    $redis->expects('command')->twice()->with('ping')->andReturn(true);
    Redis::shouldReceive('connection')->twice()->with('default')->andReturn($redis);

    try {
        $this->get('/api/control-plane/probe', ['X-Control-Plane-Probe' => $token])
            ->assertNoContent()
            ->assertHeader('X-Control-Plane-Applied-Config', $acknowledgement);
        $this->get('/api/control-plane/probe?probe=1', ['X-Control-Plane-Probe' => $token])
            ->assertServiceUnavailable();

        $controller = app(PreflightController::class);
        $response = $controller->directProbe(Request::create(
            '/api/control-plane/probe',
            'GET',
            server: ['HTTP_X_CONTROL_PLANE_PROBE' => $token],
        ));
        expect($response->getStatusCode())->toBe(Response::HTTP_NO_CONTENT)
            ->and($response->headers->get('X-Control-Plane-Applied-Config'))->toBe($acknowledgement)
            ->and(fn () => $controller->directProbe(Request::create(
                '/api/control-plane/probe',
                'GET',
                server: ['HTTP_X_CONTROL_PLANE_PROBE' => 'wrong-direct-probe-token'],
            )))->toThrow(NotFoundHttpException::class);
    } finally {
        unlink($tokenPath);
        unlink($acknowledgementPath);
        rmdir($directory);
    }
});

it('keeps route health as an unowned web-only boundary before ownership is proven', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');

    $response = (new EnforceControlPlaneMode)->handle(
        Request::create('/api/control-plane/route-health', 'GET'),
        fn () => response('route-health-boundary'),
    );
    $queryResponse = (new EnforceControlPlaneMode)->handle(
        Request::create('/api/control-plane/route-health?probe=1', 'GET'),
        fn () => response('query-boundary'),
    );

    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($response->getContent())->toBe('route-health-boundary')
        ->and($queryResponse->getStatusCode())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);
});

it('reports only an owned undrained web-only member as route healthy', function () {
    if (($unsupportedReason = ControlPlaneStateFixture::unsupportedReason()) !== null) {
        $this->markTestSkipped($unsupportedReason);
    }

    $fixture = ControlPlaneStateFixture::create('coolify-control-plane-route-health');
    $secretDirectory = sys_get_temp_dir().'/coolify-control-plane-route-health-'.bin2hex(random_bytes(8));
    mkdir($secretDirectory, 0700);
    $tokenPath = $secretDirectory.'/token';
    $acknowledgementPath = $secretDirectory.'/acknowledgement';
    $poolAcknowledgementPath = $secretDirectory.'/pool-acknowledgement';
    $webEpoch = 'green-web-a-epoch-0123456789';
    $routeDrainEpoch = 'green-web-a-route-drain-0123456789';
    $freezeEpoch = 'operation-0123456789.fwd.mutation-freeze';
    $token = 'green-web-a-route-health-token-0123456789';
    $acknowledgement = 'green-web-a-applied-ack-0123456789';
    $poolAcknowledgement = 'green-web-a-pool-ack-0123456789';
    $memberId = 'green-web-a-member-0123456789';

    file_put_contents($tokenPath, $token."\n");
    file_put_contents($acknowledgementPath, $acknowledgement."\n");
    file_put_contents($poolAcknowledgementPath, $poolAcknowledgement."\n");
    chmod($tokenPath, 0400);
    chmod($acknowledgementPath, 0400);
    chmod($poolAcknowledgementPath, 0400);

    try {
        putenv('CONTROL_PLANE_MODE=active');
        putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
        putenv("CONTROL_PLANE_WEB_EPOCH={$webEpoch}");
        putenv('CONTROL_PLANE_WEB_MARKER_PATH='.$fixture->path('web-epoch'));
        putenv('CONTROL_PLANE_WRITER_EPOCH=green-writer-epoch-0123456789');
        putenv('CONTROL_PLANE_WRITER_MARKER_PATH='.$fixture->path('writer-epoch'));
        putenv("CONTROL_PLANE_ROUTE_DRAIN_EPOCH={$routeDrainEpoch}");
        putenv('CONTROL_PLANE_ROUTE_DRAIN_MARKER_PATH='.$fixture->path('route-drain-epoch'));
        putenv("CONTROL_PLANE_MUTATION_FREEZE_EPOCH={$freezeEpoch}");
        putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH='.$fixture->path('mutation-freeze-epoch'));
        config()->set([
            'control-plane.route_health_token_path' => $tokenPath,
            'control-plane.applied_ack_path' => $acknowledgementPath,
            'control-plane.pool_ack_path' => null,
            'control-plane.member_id' => null,
        ]);
        $fixture->writeMarker('mutation-freeze-epoch', $freezeEpoch);
        $fixture->writeMutationLease();

        $controller = app(PreflightController::class);
        $routeHealthRequest = fn (string $providedToken): Request => Request::create(
            '/api/control-plane/route-health',
            'GET',
            server: ['HTTP_X_CONTROL_PLANE_ROUTE_HEALTH' => $providedToken],
        );
        $routeHealthStatus = function () use ($controller, $routeHealthRequest, $token): int {
            try {
                $controller->routeHealth($routeHealthRequest($token));
            } catch (HttpException $exception) {
                return $exception->getStatusCode();
            }

            $this->fail('Expected route health to reject the member.');
        };

        expect(fn () => $controller->routeHealth($routeHealthRequest('wrong-route-health-token')))
            ->toThrow(NotFoundHttpException::class)
            ->and($routeHealthStatus())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);

        $fixture->writeMarker('web-epoch', $webEpoch);

        $connection = Mockery::mock(Connection::class);
        $connection->expects('scalar')->once()->with('select 1', [], false)->andReturn(1);
        DB::shouldReceive('connection')->once()->withNoArgs()->andReturn($connection);

        $redis = Mockery::mock();
        $redis->expects('command')->once()->with('ping')->andReturn(true);
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);

        $response = $controller->routeHealth($routeHealthRequest($token));
        expect($response->getStatusCode())->toBe(Response::HTTP_NO_CONTENT)
            ->and($response->headers->get('X-Control-Plane-Applied-Config'))->toBe($acknowledgement)
            ->and($response->headers->has('X-Control-Plane-Pool-Ack'))->toBeFalse()
            ->and($response->headers->has('X-Control-Plane-Member'))->toBeFalse();

        config()->set([
            'control-plane.pool_ack_path' => $poolAcknowledgementPath,
            'control-plane.member_id' => $memberId,
        ]);

        $connection = Mockery::mock(Connection::class);
        $connection->expects('scalar')->once()->with('select 1', [], false)->andReturn(1);
        DB::shouldReceive('connection')->once()->withNoArgs()->andReturn($connection);

        $redis = Mockery::mock();
        $redis->expects('command')->once()->with('ping')->andReturn(true);
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);

        $poolResponse = $controller->routeHealth($routeHealthRequest($token));
        expect($poolResponse->getStatusCode())->toBe(Response::HTTP_NO_CONTENT)
            ->and($poolResponse->headers->get('X-Control-Plane-Applied-Config'))->toBe($acknowledgement)
            ->and($poolResponse->headers->get('X-Control-Plane-Pool-Ack'))->toBe($poolAcknowledgement)
            ->and($poolResponse->headers->get('X-Control-Plane-Member'))->toBe($memberId);

        $fixture->writeMarker('route-drain-epoch', $routeDrainEpoch);
        $drainResponse = $controller->routeHealth($routeHealthRequest($token));
        expect($drainResponse->getStatusCode())->toBe(Response::HTTP_SERVICE_UNAVAILABLE)
            ->and($drainResponse->headers->get('X-Control-Plane-Applied-Config'))->toBe($acknowledgement)
            ->and($drainResponse->headers->get('X-Control-Plane-Pool-Ack'))->toBe($poolAcknowledgement)
            ->and($drainResponse->headers->get('X-Control-Plane-Member'))->toBe($memberId)
            ->and($drainResponse->headers->get('X-Control-Plane-Route-State'))->toBe('draining')
            ->and($drainResponse->headers->get('X-Control-Plane-Route-Drain-Epoch'))->toBe($routeDrainEpoch);

        $fixture->writeMarker('route-drain-epoch', 'stale-route-drain-epoch-0123456789');
        expect($routeHealthStatus())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);

        chmod($fixture->path('route-drain-epoch'), 0640);
        expect($routeHealthStatus())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);
    } finally {
        unlink($tokenPath);
        unlink($acknowledgementPath);
        unlink($poolAcknowledgementPath);
        rmdir($secretDirectory);
        $fixture->cleanup();
    }
});

it('fails closed when only one route health pool identity setting is configured', function (
    bool $configureAcknowledgement,
    bool $configureMember,
) {
    $secretDirectory = sys_get_temp_dir().'/coolify-control-plane-route-health-pool-'.bin2hex(random_bytes(8));
    mkdir($secretDirectory, 0700);
    $tokenPath = $secretDirectory.'/token';
    $poolAcknowledgementPath = $secretDirectory.'/pool-acknowledgement';
    $token = 'route-health-pool-token-0123456789';

    file_put_contents($tokenPath, $token."\n");
    file_put_contents($poolAcknowledgementPath, 'route-health-pool-ack-0123456789'."\n");
    chmod($tokenPath, 0400);
    chmod($poolAcknowledgementPath, 0400);

    try {
        putenv('CONTROL_PLANE_MODE=active');
        putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
        config()->set([
            'control-plane.route_health_token_path' => $tokenPath,
            'control-plane.pool_ack_path' => $configureAcknowledgement ? $poolAcknowledgementPath : null,
            'control-plane.member_id' => $configureMember ? 'route-health-member-0123456789' : null,
        ]);

        try {
            app(PreflightController::class)->routeHealth(Request::create(
                '/api/control-plane/route-health',
                'GET',
                server: ['HTTP_X_CONTROL_PLANE_ROUTE_HEALTH' => $token],
            ));
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);

            return;
        }

        $this->fail('Route health accepted an incomplete pool identity.');
    } finally {
        unlink($tokenPath);
        unlink($poolAcknowledgementPath);
        rmdir($secretDirectory);
    }
})->with([
    'acknowledgement without member' => [true, false],
    'member without acknowledgement' => [false, true],
]);

it('fails closed for an invalid route health pool member identity', function () {
    $secretDirectory = sys_get_temp_dir().'/coolify-control-plane-route-health-member-'.bin2hex(random_bytes(8));
    mkdir($secretDirectory, 0700);
    $tokenPath = $secretDirectory.'/token';
    $poolAcknowledgementPath = $secretDirectory.'/pool-acknowledgement';
    $token = 'route-health-member-token-0123456789';

    file_put_contents($tokenPath, $token."\n");
    file_put_contents($poolAcknowledgementPath, 'route-health-pool-ack-0123456789'."\n");
    chmod($tokenPath, 0400);
    chmod($poolAcknowledgementPath, 0400);

    try {
        putenv('CONTROL_PLANE_MODE=active');
        putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
        config()->set([
            'control-plane.route_health_token_path' => $tokenPath,
            'control-plane.pool_ack_path' => $poolAcknowledgementPath,
            'control-plane.member_id' => 'invalid/member-0123456789',
        ]);

        try {
            app(PreflightController::class)->routeHealth(Request::create(
                '/api/control-plane/route-health',
                'GET',
                server: ['HTTP_X_CONTROL_PLANE_ROUTE_HEALTH' => $token],
            ));
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);

            return;
        }

        $this->fail('Route health accepted an invalid pool member identity.');
    } finally {
        unlink($tokenPath);
        unlink($poolAcknowledgementPath);
        rmdir($secretDirectory);
    }
});

it('fails closed for unreadable or malformed route health pool acknowledgements', function (?string $acknowledgement) {
    $secretDirectory = sys_get_temp_dir().'/coolify-control-plane-route-health-ack-'.bin2hex(random_bytes(8));
    mkdir($secretDirectory, 0700);
    $tokenPath = $secretDirectory.'/token';
    $poolAcknowledgementPath = $secretDirectory.'/pool-acknowledgement';
    $token = 'route-health-ack-token-0123456789';

    file_put_contents($tokenPath, $token."\n");
    if ($acknowledgement !== null) {
        file_put_contents($poolAcknowledgementPath, $acknowledgement."\n");
    }
    chmod($tokenPath, 0400);

    try {
        putenv('CONTROL_PLANE_MODE=active');
        putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
        config()->set([
            'control-plane.route_health_token_path' => $tokenPath,
            'control-plane.pool_ack_path' => $poolAcknowledgementPath,
            'control-plane.member_id' => 'route-health-member-0123456789',
        ]);

        try {
            app(PreflightController::class)->routeHealth(Request::create(
                '/api/control-plane/route-health',
                'GET',
                server: ['HTTP_X_CONTROL_PLANE_ROUTE_HEALTH' => $token],
            ));
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);

            return;
        }

        $this->fail('Route health accepted an invalid pool acknowledgement.');
    } finally {
        unlink($tokenPath);
        if (is_file($poolAcknowledgementPath)) {
            unlink($poolAcknowledgementPath);
        }
        rmdir($secretDirectory);
    }
})->with([
    'unreadable acknowledgement' => [null],
    'malformed acknowledgement' => ['too-short'],
]);

it('does not expose route health outside active web-only mode', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=full');

    $controller = app(PreflightController::class);
    $routeHealthRequest = fn (): Request => Request::create('/api/control-plane/route-health', 'GET');
    $dispatchRouteHealth = function (Request $request) use ($controller): Response {
        try {
            return $controller->routeHealth($request);
        } catch (HttpException $exception) {
            return response('', $exception->getStatusCode());
        }
    };

    expect(fn () => $controller->routeHealth($routeHealthRequest()))
        ->toThrow(NotFoundHttpException::class);
    expect(fn () => $controller->routeHealth(Request::create(
        '/api/control-plane/route-health?probe=1',
        'GET',
    )))->toThrow(NotFoundHttpException::class);

    $middleware = new EnforceControlPlaneMode;
    $fullResponse = $middleware->handle($routeHealthRequest(), $dispatchRouteHealth);

    putenv('CONTROL_PLANE_MODE=passive');
    config()->set('control-plane.mode', ControlPlaneMode::Passive->value);

    expect(fn () => $controller->routeHealth($routeHealthRequest()))
        ->toThrow(NotFoundHttpException::class);

    $passiveResponse = $middleware->handle($routeHealthRequest(), $dispatchRouteHealth);
    $passiveQueryResponse = $middleware->handle(
        Request::create('/api/control-plane/route-health?probe=1', 'GET'),
        $dispatchRouteHealth,
    );

    expect($fullResponse->getStatusCode())->toBe(Response::HTTP_NOT_FOUND)
        ->and($passiveResponse->getStatusCode())->toBe(Response::HTTP_NOT_FOUND)
        ->and($passiveQueryResponse->getStatusCode())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);
});

it('allows only real non PHP public files through an unowned web only process', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
    putenv('CONTROL_PLANE_WRITER_EPOCH=green-writer-epoch-0123456789');
    putenv('CONTROL_PLANE_WRITER_MARKER_PATH=/var/lib/coolify-control-plane/absent-writer-epoch');

    $middleware = new EnforceControlPlaneMode;
    $response = $middleware->handle(
        Request::create('/robots.txt', 'HEAD'),
        fn () => response('static-boundary'),
    );
    $phpResponse = $middleware->handle(
        Request::create('/index.php', 'GET'),
        fn () => response('php-boundary'),
    );

    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($phpResponse->getStatusCode())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);
});

it('does not expose the direct-origin probe outside active web-only mode', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=full');

    expect(fn () => app(PreflightController::class)->directProbe(Request::create(
        '/api/control-plane/probe',
        'GET',
        server: ['HTTP_X_CONTROL_PLANE_PROBE' => 'direct-probe-token-0123456789'],
    )))->toThrow(NotFoundHttpException::class);
});

it('exposes passive preflight version without authentication', function () {
    putenv('CONTROL_PLANE_MODE=passive');
    config()->set('control-plane.mode', ControlPlaneMode::Passive->value);

    $this->getJson('/api/preflight/version')
        ->assertSuccessful()
        ->assertJson([
            'mode' => ControlPlaneMode::Passive->value,
            'version' => config('constants.coolify.version'),
        ]);
});

it('fails database preflight when the connection cannot prove read only postgres', function () {
    putenv('CONTROL_PLANE_MODE=passive');
    config()->set('control-plane.mode', ControlPlaneMode::Passive->value);

    $this->getJson('/api/preflight/database')
        ->assertServiceUnavailable()
        ->assertJson([
            'status' => 'unavailable',
            'read_only' => false,
        ]);
});

it('proves read only state against the write connection', function () {
    putenv('CONTROL_PLANE_MODE=passive');
    config()->set('control-plane.mode', ControlPlaneMode::Passive->value);

    $connection = Mockery::mock(Connection::class);
    $connection->expects('getDriverName')->andReturn('pgsql');
    $connection->expects('scalar')
        ->with(Mockery::type('string'), [], false)
        ->andReturn('read-only');
    DB::shouldReceive('connection')->once()->andReturn($connection);

    $this->getJson('/api/preflight/database')
        ->assertSuccessful()
        ->assertJson([
            'status' => 'ok',
            'read_only' => true,
        ]);
});

it('denies every non-preflight request in passive mode', function (string $method, string $path) {
    putenv('CONTROL_PLANE_MODE=passive');
    config()->set('control-plane.mode', ControlPlaneMode::Passive->value);

    $this->call($method, $path)
        ->assertServiceUnavailable();
})->with([
    'web route' => ['GET', '/'],
    'legacy health alias' => ['GET', '/api/v1/health'],
    'active web-only direct probe' => ['GET', '/api/control-plane/probe'],
    'health head request' => ['HEAD', '/api/health'],
    'health post request' => ['POST', '/api/health'],
]);

it('denies path suffixes on passive preflight routes', function () {
    putenv('CONTROL_PLANE_MODE=passive');
    config()->set('control-plane.mode', ControlPlaneMode::Passive->value);

    // Laravel's HTTP test helper trims trailing slashes before creating the request.
    $kernel = app(HttpKernel::class);
    $request = Request::create('/api/preflight/version/', 'GET');
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    expect($response->getStatusCode())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);
});

it('denies query strings on passive preflight routes', function () {
    putenv('CONTROL_PLANE_MODE=passive');
    config()->set('control-plane.mode', ControlPlaneMode::Passive->value);

    $this->get('/api/preflight/version?probe=1')
        ->assertServiceUnavailable();
});

it('denies an empty query delimiter on passive preflight routes', function () {
    putenv('CONTROL_PLANE_MODE=passive');
    config()->set('control-plane.mode', ControlPlaneMode::Passive->value);

    $kernel = app(HttpKernel::class);
    $request = Request::create('/api/preflight/version', 'GET');
    $request->server->set('REQUEST_URI', '/api/preflight/version?');
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    expect($response->getStatusCode())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);
});

it('denies HTTP method overrides in passive mode', function (array $headers, array $body) {
    putenv('CONTROL_PLANE_MODE=passive');
    config()->set('control-plane.mode', ControlPlaneMode::Passive->value);

    $this->post('/api/health', $body, $headers)
        ->assertServiceUnavailable();
})->with([
    'override header' => [['X-HTTP-Method-Override' => 'GET'], []],
    'override body' => [[], ['_method' => 'GET']],
]);

it('does not expose passive-only preflight routes in active mode', function () {
    putenv('CONTROL_PLANE_MODE=active');
    config()->set('control-plane.mode', ControlPlaneMode::Active->value);

    $this->getJson('/api/preflight/version')->assertNotFound();
});

it('returns a service unavailable envelope for denied passive requests', function () {
    putenv('CONTROL_PLANE_MODE=passive');
    config()->set('control-plane.mode', ControlPlaneMode::Passive->value);

    $this->getJson('/')
        ->assertServiceUnavailable()
        ->assertExactJson(['message' => 'Service Unavailable']);
});

it('makes startup commands and the scheduler inert in passive mode', function (string $command) {
    putenv('CONTROL_PLANE_MODE=passive');
    config()->set('control-plane.mode', ControlPlaneMode::Passive->value);

    $this->artisan($command)
        ->expectsOutputToContain('Control plane startup work is disabled')
        ->assertSuccessful();
})->with([
    'application initialization' => 'app:init',
    'database migration' => 'start:migration',
    'database seeding' => 'start:seeder',
]);

it('registers no scheduled tasks in passive mode', function () {
    putenv('CONTROL_PLANE_MODE=passive');
    config()->set('control-plane.mode', ControlPlaneMode::Passive->value);

    Artisan::call('schedule:list');

    expect(Artisan::output())
        ->not->toContain('cleanup:redis')
        ->not->toContain('horizon:snapshot')
        ->not->toContain('ServerManagerJob');
});

it('keeps an active web only candidate free of startup and scheduled work', function (string $command) {
    if (($unsupportedReason = ControlPlaneStateFixture::unsupportedReason()) !== null) {
        $this->markTestSkipped($unsupportedReason);
    }

    $fixture = ControlPlaneStateFixture::create('coolify-control-plane-missing-writer');

    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
    putenv('CONTROL_PLANE_WRITER_EPOCH=deployment-epoch-0001');
    putenv('CONTROL_PLANE_WRITER_MARKER_PATH='.$fixture->path('writer-epoch'));
    config()->set('control-plane.mode', ControlPlaneMode::Active->value);

    try {
        $this->artisan($command)
            ->expectsOutputToContain('Control plane startup work is disabled')
            ->assertSuccessful();
    } finally {
        $fixture->cleanup();
    }
})->with([
    'application initialization' => 'app:init',
    'database migration' => 'start:migration',
    'database seeding' => 'start:seeder',
]);
