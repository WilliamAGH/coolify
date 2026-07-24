<?php

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

/**
 * Swap the current request so getRealtime() sees a known scheme/host/port,
 * mirroring how pusher-js dials `<wsHost>:<wsPort|wssPort>/app/<key>`.
 */
function swapRealtimeRequest(string $uri): void
{
    app()->instance('request', Request::create($uri));
}

it('returns the public proxy port for the browser when an instance FQDN is configured on https', function () {
    // fork-deploy normalizes the internal PUSHER_PORT to 6001; the browser must
    // never dial that container-only port when served behind Traefik.
    config()->set('constants.pusher.port', '6001');

    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0, 'fqdn' => 'https://coolify.example.test']));

    swapRealtimeRequest('https://coolify.example.test/');

    // pusher-js will build wss://coolify.example.test:443/app/<key> which the
    // Traefik Host(<fqdn>) && PathPrefix(/app) router forwards to Reverb.
    expect(getRealtime())->toBe('443');
});

it('ignores PUSHER_PORT behind a proxy so the fix survives fork-deploy normalization', function () {
    config()->set('constants.pusher.port', '6001');

    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0, 'fqdn' => 'https://coolify.example.test']));

    swapRealtimeRequest('https://coolify.example.test/');

    expect(getRealtime())->not->toBe('6001');
});

it('uses the http proxy port when the dashboard FQDN is served over http', function () {
    config()->set('constants.pusher.port', '6001');

    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0, 'fqdn' => 'http://coolify.example.test']));

    swapRealtimeRequest('http://coolify.example.test/');

    expect(getRealtime())->toBe('80');
});

it('returns the direct Reverb port for un-proxied SERVER_IP:PORT access without an FQDN', function () {
    config()->set('constants.pusher.port', '6001');

    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));

    swapRealtimeRequest('http://1.2.3.4:8000/');

    // No proxy in front, so the browser connects straight to the exposed
    // Reverb port on the standalone install (upstream behavior preserved).
    expect(getRealtime())->toBe('6001');
});

it('falls back to the direct Reverb port when no FQDN is set even on a bare host', function () {
    config()->set('constants.pusher.port', null);

    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));

    swapRealtimeRequest('http://1.2.3.4:8000/');

    expect(getRealtime())->toBe('6001');
});

it('keeps the Echo client port and TLS decision driven by getRealtime and the request scheme', function () {
    $echoClient = file_get_contents(resource_path('views/layouts/base.blade.php'));

    // The browser-facing port comes from getRealtime() (public port when proxied),
    // never a hard-coded internal port, and forceTLS follows the page scheme so
    // the wss transport targets wssPort on https.
    expect($echoClient)
        ->toContain('wsPort: "{{ getRealtime() }}"')
        ->toContain('wssPort: "{{ getRealtime() }}"')
        ->toContain("forceTLS: {{ request()->isSecure() ? 'true' : 'false' }}")
        ->not->toContain('forceTLS: false,');
});
