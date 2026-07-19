<?php

use App\Actions\Application\BlueGreen\BlueGreenIdleRouteReconciliationOutcome;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenIdleRouteDrift;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenIdleRoutes;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\StandaloneDocker;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

afterEach(function (): void {
    ReconcileBlueGreenIdleRoutes::clearFake();
});

/**
 * @return list<array{application: Application, destination: StandaloneDocker, state: ApplicationBlueGreenDeployment}>
 */
function idleRouteReconciliationCommandStates(int $count): array
{
    ['application' => $template, 'destination' => $destination] = BlueGreenDeactivationScenario::context();

    return collect(range(1, $count))
        ->map(function (int $index) use ($template, $destination): array {
            $application = $index === 1
                ? $template
                : Application::factory()->create([
                    'environment_id' => $template->environment_id,
                    'destination_id' => $destination->id,
                    'destination_type' => $destination->getMorphClass(),
                ]);

            return [
                'application' => $application,
                'destination' => $destination,
                'state' => BlueGreenDeactivationScenario::routeLessState($application, $destination),
            ];
        })
        ->sortBy(fn (array $state): int => $state['state']->id)
        ->values()
        ->all();
}

it('reconciles only the lowest-ID exact idle route by default', function (): void {
    $states = idleRouteReconciliationCommandStates(3);

    ReconcileBlueGreenIdleRoutes::mock()
        ->shouldReceive('handle')
        ->once()
        ->withArgs(function (Application $application, StandaloneDocker $destination) use ($states): bool {
            return $application->is($states[0]['application'])
                && $destination->is($states[0]['destination']);
        })
        ->andReturn(BlueGreenIdleRouteReconciliationOutcome::Repaired);

    $this->artisan('blue-green:reconcile-idle-routes')
        ->expectsOutput("state={$states[0]['state']->id} outcome=repaired The missing or drifted idle blue-green route was regenerated.")
        ->expectsOutput('repaired=1 unchanged=0 busy=0 failed=0')
        ->assertSuccessful();
});

it('isolates a failed route and continues in deterministic ID order within the limit', function (): void {
    $states = idleRouteReconciliationCommandStates(3);
    $handledApplicationIds = [];

    ReconcileBlueGreenIdleRoutes::mock()
        ->shouldReceive('handle')
        ->twice()
        ->andReturnUsing(function (Application $application, StandaloneDocker $destination) use (&$handledApplicationIds, $states): BlueGreenIdleRouteReconciliationOutcome {
            $handledApplicationIds[] = $application->id;
            expect($destination->is($states[0]['destination']))->toBeTrue();

            if ($application->is($states[0]['application'])) {
                throw new RuntimeException('The first selected route failed closed.');
            }

            return BlueGreenIdleRouteReconciliationOutcome::Busy;
        });

    $this->artisan('blue-green:reconcile-idle-routes', ['--limit' => 2])
        ->expectsOutput("state={$states[0]['state']->id} outcome=failed The first selected route failed closed.")
        ->expectsOutput("state={$states[1]['state']->id} outcome=busy A concurrent lifecycle operation owns the destination.")
        ->expectsOutput('repaired=0 unchanged=0 busy=1 failed=1')
        ->assertExitCode(Command::FAILURE);

    expect($handledApplicationIds)->toBe([
        $states[0]['application']->id,
        $states[1]['application']->id,
    ]);
});

it('advances the default-limit cursor after a failed state', function (): void {
    $states = idleRouteReconciliationCommandStates(2);

    ReconcileBlueGreenIdleRoutes::mock()
        ->shouldReceive('handle')
        ->once()
        ->withArgs(function (Application $application, StandaloneDocker $destination) use ($states): bool {
            return $application->is($states[0]['application'])
                && $destination->is($states[0]['destination']);
        })
        ->andThrow(new RuntimeException('The first default-limit route failed closed.'));

    $this->artisan('blue-green:reconcile-idle-routes')
        ->expectsOutput("state={$states[0]['state']->id} outcome=failed The first default-limit route failed closed.")
        ->expectsOutput('repaired=0 unchanged=0 busy=0 failed=1')
        ->assertExitCode(Command::FAILURE);

    ReconcileBlueGreenIdleRoutes::clearFake();
    ReconcileBlueGreenIdleRoutes::mock()
        ->shouldReceive('handle')
        ->once()
        ->withArgs(function (Application $application, StandaloneDocker $destination) use ($states): bool {
            return $application->is($states[1]['application'])
                && $destination->is($states[1]['destination']);
        })
        ->andReturn(BlueGreenIdleRouteReconciliationOutcome::Unchanged);

    $this->artisan('blue-green:reconcile-idle-routes')
        ->expectsOutput("state={$states[1]['state']->id} outcome=unchanged The exact idle blue-green route was already current.")
        ->expectsOutput('repaired=0 unchanged=1 busy=0 failed=0')
        ->assertSuccessful();
});

it('self-heals a malformed cursor and reconciles the lowest idle route', function (): void {
    $states = idleRouteReconciliationCommandStates(2);
    Cache::forever('blue-green:idle-route-reconciliation:last-state-id', 'malformed');

    ReconcileBlueGreenIdleRoutes::mock()
        ->shouldReceive('handle')
        ->once()
        ->withArgs(function (Application $application, StandaloneDocker $destination) use ($states): bool {
            return $application->is($states[0]['application'])
                && $destination->is($states[0]['destination']);
        })
        ->andReturn(BlueGreenIdleRouteReconciliationOutcome::Unchanged);

    $this->artisan('blue-green:reconcile-idle-routes')
        ->expectsOutput("state={$states[0]['state']->id} outcome=unchanged The exact idle blue-green route was already current.")
        ->expectsOutput('repaired=0 unchanged=1 busy=0 failed=0')
        ->assertSuccessful();

    expect(Cache::get('blue-green:idle-route-reconciliation:last-state-id'))->toBe($states[0]['state']->id);
});

it('fails explicitly when the cursor cannot be persisted and reaches later routes after cache recovery', function (): void {
    $states = idleRouteReconciliationCommandStates(2);
    Cache::forget('blue-green:idle-route-reconciliation:last-state-id');
    $coordinator = new ReconcileBlueGreenIdleRouteDrift;
    $cacheManager = Cache::getFacadeRoot();
    $unavailableCache = Mockery::mock(CacheRepository::class);
    $unavailableCache->shouldReceive('get')->once()->with(
        'blue-green:idle-route-reconciliation:last-state-id',
        0,
    )->andReturn(0);
    $unavailableCache->shouldReceive('forever')->once()->with(
        'blue-green:idle-route-reconciliation:last-state-id',
        $states[0]['state']->id,
    )->andReturnFalse();
    Cache::swap($unavailableCache);
    ReconcileBlueGreenIdleRoutes::shouldNotRun();

    try {
        $failed = $coordinator->handle();
    } finally {
        Cache::swap($cacheManager);
    }

    expect($failed['failed'])->toBe(1)
        ->and($failed['outcomes'])->toBe([[
            'state_id' => $states[0]['state']->id,
            'outcome' => 'failed',
            'message' => 'The idle route reconciliation cursor could not be persisted before the destination attempt.',
        ]]);

    ReconcileBlueGreenIdleRoutes::clearFake();
    ReconcileBlueGreenIdleRoutes::mock()
        ->shouldReceive('handle')
        ->once()
        ->withArgs(function (Application $application, StandaloneDocker $destination) use ($states): bool {
            return $application->is($states[0]['application'])
                && $destination->is($states[0]['destination']);
        })
        ->andReturn(BlueGreenIdleRouteReconciliationOutcome::Unchanged);

    $recovered = $coordinator->handle();

    expect($recovered['unchanged'])->toBe(1);

    ReconcileBlueGreenIdleRoutes::clearFake();
    ReconcileBlueGreenIdleRoutes::mock()
        ->shouldReceive('handle')
        ->once()
        ->withArgs(function (Application $application, StandaloneDocker $destination) use ($states): bool {
            return $application->is($states[1]['application'])
                && $destination->is($states[1]['destination']);
        })
        ->andReturn(BlueGreenIdleRouteReconciliationOutcome::Unchanged);

    $later = $coordinator->handle();

    expect($later['unchanged'])->toBe(1)
        ->and($later['outcomes'][0]['state_id'])->toBe($states[1]['state']->id);
});

it('selects only exact idle route states', function (): void {
    $state = idleRouteReconciliationCommandStates(1)[0];
    $state['state']->update(['phase' => BlueGreenDeploymentPhase::PREPARING]);

    ReconcileBlueGreenIdleRoutes::shouldNotRun();

    $this->artisan('blue-green:reconcile-idle-routes')
        ->expectsOutput('No exact idle blue-green routes were found.')
        ->expectsOutput('repaired=0 unchanged=0 busy=0 failed=0')
        ->assertSuccessful();
});

it('refuses non-positive reconciliation limits from both entrypoints', function (): void {
    expect(fn (): array => ReconcileBlueGreenIdleRouteDrift::run(limit: 0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->artisan('blue-green:reconcile-idle-routes', ['--limit' => 0]))
        ->toThrow(InvalidArgumentException::class);
});
