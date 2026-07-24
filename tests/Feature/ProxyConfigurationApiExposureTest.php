<?php

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
});

it('binds the Traefik API to loopback and enables it so on-host provider proofs can read rawdata', function (): void {
    ['server' => $server] = BlueGreenDeactivationScenario::context();

    $configuration = generateDefaultProxyConfiguration($server->fresh(), save: false);

    expect($configuration)->toContain('127.0.0.1:8080:8080')
        ->and($configuration)->toContain('--api.insecure=true')
        ->and($configuration)->not->toContain("'8080:8080'");
});

it('keeps the shared API port mapping on Swarm where host-IP binds are unsupported', function (): void {
    ['server' => $server] = BlueGreenDeactivationScenario::context();
    $server->settings()->update(['is_swarm_manager' => true]);

    $configuration = generateDefaultProxyConfiguration($server->fresh(), save: false);

    expect($configuration)->toContain('8080:8080')
        ->and($configuration)->not->toContain('127.0.0.1:8080:8080');
});
