<?php

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::forget('instance_settings_fqdn_host');
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], ['fqdn' => null]));
    // Ensure session.secure starts unconfigured for each test
    config(['session.secure' => null]);
});

it('sets session.secure to true when request arrives over HTTPS via proxy', function () {
    $this->get('/login', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-For' => '1.2.3.4',
    ]);

    expect(config('session.secure'))->toBeTrue();
});

it('does not set session.secure for plain HTTP requests', function () {
    $this->get('/login');

    expect(config('session.secure'))->toBeNull();
});

it('does not override explicit SESSION_SECURE_COOKIE=false for HTTPS requests', function () {
    config(['session.secure' => false]);

    $this->get('/login', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-For' => '1.2.3.4',
    ]);

    // Explicit false must not be overridden — our check is `=== null` only
    expect(config('session.secure'))->toBeFalse();
});

it('does not override explicit SESSION_SECURE_COOKIE=true', function () {
    config(['session.secure' => true]);

    $this->get('/login');

    expect(config('session.secure'))->toBeTrue();
});

it('marks session cookie with Secure flag when accessed over HTTPS proxy', function () {
    $response = $this->get('/login', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-For' => '1.2.3.4',
    ]);

    // /login redirects to onboarding when no user exists yet; the session
    // cookie is set either way
    expect($response->status())->toBeIn([200, 302]);

    $cookieName = config('session.cookie');
    $sessionCookie = collect($response->headers->all('set-cookie'))
        ->first(fn ($c) => str_contains($c, $cookieName));

    expect($sessionCookie)->not->toBeNull()
        ->and(strtolower($sessionCookie))->toContain('; secure');
});
