<?php

use App\Actions\Server\UpdateCoolify;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

function updateCoolifyActionForTest(Server $server, InstanceSettings $settings): UpdateCoolify
{
    $action = Mockery::mock(UpdateCoolify::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $action->server = $server;
    $action->shouldReceive('instanceSettings')->andReturn($settings);

    return $action;
}

beforeEach(function () {
    // Mock Server
    $this->mockServer = Mockery::mock(Server::class)->makePartial();
    $this->mockServer->id = 0;

    // Mock InstanceSettings
    $this->settings = Mockery::mock(InstanceSettings::class);
    $this->settings->shouldReceive('getAttribute')
        ->with('is_auto_update_enabled')
        ->byDefault()
        ->andReturn(true);
    $this->settings->shouldReceive('update')->byDefault()->andReturn(true);
});

afterEach(function () {
    Mockery::close();
});

it('has UpdateCoolify action class', function () {
    expect(class_exists(UpdateCoolify::class))->toBeTrue();
});

it('recognizes only guarded fork release versions', function () {
    expect(UpdateCoolify::isGuardedForkRelease('4.13.1-fork'))->toBeTrue()
        ->and(UpdateCoolify::isGuardedForkRelease('4.13.1-fork.2'))->toBeTrue()
        ->and(UpdateCoolify::isGuardedForkRelease('4.13.1'))->toBeFalse()
        ->and(UpdateCoolify::isGuardedForkRelease('4.13.1-fork.0'))->toBeFalse();
});

it('does not contact upstream or run the generic updater for a fork release', function () {
    config(['constants.coolify.version' => '4.13.1-fork']);
    Http::preventStrayRequests();
    $this->settings->shouldReceive('update')
        ->once()
        ->with(['new_version_available' => false]);
    Log::shouldReceive('warning')
        ->once()
        ->with('Upstream updater disabled for fork release', Mockery::type('array'));
    $action = updateCoolifyActionForTest($this->mockServer, $this->settings);

    $action->handle();

    Http::assertNothingSent();
});

it('directs manual fork updates to the guarded deployment workflow', function () {
    config(['constants.coolify.version' => '4.13.1-fork']);
    Http::preventStrayRequests();
    $this->settings->shouldReceive('update')
        ->once()
        ->with(['new_version_available' => false]);
    Log::shouldReceive('warning')
        ->once()
        ->with('Upstream updater disabled for fork release', Mockery::type('array'));
    $action = updateCoolifyActionForTest($this->mockServer, $this->settings);

    expect(fn () => $action->handle(manual_update: true))
        ->toThrow(RuntimeException::class, 'guarded fork deployment workflow');

    Http::assertNothingSent();
});

it('validates cache against running version before fallback', function () {
    // CDN fails
    Http::fake(['*' => Http::response(null, 500)]);

    // Mock cache returning older version
    Cache::shouldReceive('remember')
        ->andReturn(['coolify' => ['v4' => ['version' => '4.0.5']]]);

    config(['constants.coolify.version' => '4.0.10']);

    $action = updateCoolifyActionForTest($this->mockServer, $this->settings);

    // Should throw exception - cache is older than running
    try {
        $action->handle(manual_update: false);
        expect(false)->toBeTrue('Expected exception was not thrown');
    } catch (Exception $e) {
        expect($e->getMessage())->toContain('cache version');
        expect($e->getMessage())->toContain('4.0.5');
        expect($e->getMessage())->toContain('4.0.10');
    }
});

it('uses validated cache when CDN fails and cache is newer', function () {
    // CDN fails
    Http::fake(['*' => Http::response(null, 500)]);

    // Cache has newer version than current
    Cache::shouldReceive('remember')
        ->andReturn(['coolify' => ['v4' => ['version' => '4.0.10']]]);

    config(['constants.coolify.version' => '4.0.5']);

    // Mock the update method to prevent actual update
    $action = updateCoolifyActionForTest($this->mockServer, $this->settings);
    $action->shouldReceive('update')->once();

    Log::shouldReceive('warning')
        ->once()
        ->with('Failed to fetch fresh version from CDN, using validated cache', Mockery::type('array'));

    // Should not throw - cache (4.0.10) > running (4.0.5)
    $action->handle(manual_update: false);

    expect($action->latestVersion)->toBe('4.0.10');
});

it('prevents downgrade even with manual update', function () {
    // CDN returns older version
    Http::fake([
        '*' => Http::response([
            'coolify' => ['v4' => ['version' => '4.0.0']],
        ], 200),
    ]);

    // Current version is newer
    config(['constants.coolify.version' => '4.0.10']);

    $action = updateCoolifyActionForTest($this->mockServer, $this->settings);

    Log::shouldReceive('error')
        ->once()
        ->with('Downgrade prevented', Mockery::type('array'));

    // Should throw exception even for manual updates
    try {
        $action->handle(manual_update: true);
        expect(false)->toBeTrue('Expected exception was not thrown');
    } catch (Exception $e) {
        expect($e->getMessage())->toContain('Cannot downgrade');
        expect($e->getMessage())->toContain('4.0.10');
        expect($e->getMessage())->toContain('4.0.0');
    }
});
