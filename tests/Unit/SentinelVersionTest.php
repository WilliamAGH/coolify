<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('uses bundled sentinel metadata without network access for guarded fork releases', function (string $version) {
    config(['constants.coolify.version' => $version]);
    Cache::put('coolify:versions:all', [
        'coolify' => ['sentinel' => ['version' => 'stale-upstream-version']],
        'traefik' => ['v3.6' => 'stale-upstream-version'],
    ], 3600);
    Http::preventStrayRequests();

    $versions = json_decode((string) file_get_contents(base_path('versions.json')), true);

    expect(get_latest_sentinel_version())
        ->toBe(data_get($versions, 'coolify.sentinel.version'))
        ->and(get_traefik_versions())
        ->toBe(data_get($versions, 'traefik'));
    Http::assertNothingSent();
})->with([
    'canonical fork release' => '4.13.1-fork',
    'numbered fork release' => '4.13.1-fork.2',
]);

it('keeps live sentinel metadata refresh for upstream releases', function () {
    config([
        'constants.coolify.version' => '4.2.10',
        'constants.coolify.versions_url' => 'https://versions.example.test/versions.json',
    ]);
    Http::fake([
        'versions.example.test/*' => Http::response([
            'coolify' => ['sentinel' => ['version' => '0.0.22']],
        ]),
    ]);

    expect(get_latest_sentinel_version())->toBe('0.0.22');
    Http::assertSentCount(1);
});

it('falls back when guarded fork bundled metadata cannot be read', function () {
    config(['constants.coolify.version' => '4.13.1-fork']);
    File::shouldReceive('exists')
        ->once()
        ->andThrow(new RuntimeException('Bundled metadata is unavailable.'));
    Http::preventStrayRequests();

    expect(get_latest_sentinel_version())->toBe('0.0.0');
    Http::assertNothingSent();
});
