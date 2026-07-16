<?php

use App\Actions\Proxy\GetProxyConfiguration;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Log::spy();
    Cache::spy();
});

function serverWithDbProxyConfig(?string $savedConfig, string $proxyType = 'TRAEFIK'): Server
{
    return (new Server)->forceFill([
        'id' => 1,
        'name' => 'Test Server',
        'proxy' => [
            'type' => $proxyType,
            'last_saved_proxy_configuration' => $savedConfig,
        ],
    ]);
}

it('returns OK for NONE proxy type without reading config', function () {
    $server = serverWithDbProxyConfig(null, 'NONE');

    $result = GetProxyConfiguration::run($server);

    expect($result)->toBe('OK');
});

it('reads proxy configuration from database', function () {
    $savedConfig = "services:\n  traefik:\n    image: traefik:v3.5\n";
    $server = serverWithDbProxyConfig($savedConfig);

    $result = GetProxyConfiguration::run($server);

    expect($result)->toBe($savedConfig);
});

it('preserves full custom config including labels, env vars, and custom commands', function () {
    $customConfig = <<<'YAML'
services:
  traefik:
    image: traefik:v3.5
    command:
      - '--entrypoints.http.address=:80'
      - '--metrics.prometheus=true'
    labels:
      - 'traefik.enable=true'
      - 'waf.custom.middleware=true'
    environment:
      CF_API_EMAIL: user@example.com
      CF_API_KEY: secret-key
YAML;

    $server = serverWithDbProxyConfig($customConfig);

    $result = GetProxyConfiguration::run($server);

    expect($result)->toBe($customConfig)
        ->and($result)->toContain('waf.custom.middleware=true')
        ->and($result)->toContain('CF_API_EMAIL')
        ->and($result)->toContain('metrics.prometheus=true');
});

it('logs warning when regenerating defaults', function () {
    $server = serverWithDbProxyConfig(null);

    // backfillFromDisk will be called — we need instant_remote_process to return empty
    // Since it's a global function we can't easily mock it, so test the logging via
    // the force regenerate path instead
    try {
        GetProxyConfiguration::run($server, forceRegenerate: true);
    } catch (Throwable) {
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'regenerated to defaults'))
        ->once();
});

it('does not read from disk when DB config exists', function () {
    $savedConfig = "services:\n  traefik:\n    image: traefik:v3.5\n";
    $server = serverWithDbProxyConfig($savedConfig);

    $result = GetProxyConfiguration::run($server);

    expect($result)->toBe($savedConfig);
});

it('rejects stored Traefik config when proxy type is CADDY', function () {
    $traefikConfig = "services:\n  traefik:\n    image: traefik:v3.6\n";
    $server = serverWithDbProxyConfig($traefikConfig, 'CADDY');

    // Config type mismatch should trigger regeneration, which will try
    // backfillFromDisk (instant_remote_process) then generateDefault.
    // Both will fail in test env, but the warning log proves mismatch was detected.
    try {
        GetProxyConfiguration::run($server);
    } catch (Throwable) {
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'does not match current proxy type'))
        ->once();
});

it('rejects stored Caddy config when proxy type is TRAEFIK', function () {
    $caddyConfig = "services:\n  caddy:\n    image: lucaslorentz/caddy-docker-proxy:2.8-alpine\n";
    $server = serverWithDbProxyConfig($caddyConfig, 'TRAEFIK');

    try {
        GetProxyConfiguration::run($server);
    } catch (Throwable) {
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'does not match current proxy type'))
        ->once();
});

it('accepts stored Caddy config when proxy type is CADDY', function () {
    $caddyConfig = "services:\n  caddy:\n    image: lucaslorentz/caddy-docker-proxy:2.8-alpine\n";
    $server = serverWithDbProxyConfig($caddyConfig, 'CADDY');

    $result = GetProxyConfiguration::run($server);

    expect($result)->toBe($caddyConfig);
});

it('accepts stored config when YAML parsing fails', function () {
    $invalidYaml = 'this: is: not: [valid yaml: {{{}}}';
    $server = serverWithDbProxyConfig($invalidYaml, 'TRAEFIK');

    $result = GetProxyConfiguration::run($server);

    expect($result)->toBe($invalidYaml);
});
