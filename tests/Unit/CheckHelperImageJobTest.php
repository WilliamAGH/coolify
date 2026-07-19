<?php

use App\Jobs\CheckHelperImageJob;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Once::flush();
});

it('does not poll helper metadata for a guarded fork release', function (string $version) {
    config(['constants.coolify.version' => $version]);
    Http::preventStrayRequests();

    (new CheckHelperImageJob)->handle();

    Http::assertNothingSent();
})->with([
    'canonical fork release' => '4.13.1-fork',
    'numbered fork release' => '4.13.1-fork.2',
]);

it('keeps helper metadata checks enabled for stable releases', function () {
    InstanceSettings::forceCreate([
        'id' => 0,
        'helper_version' => '1.0.0',
    ]);
    config(['constants.coolify.version' => '4.13.1']);
    Http::fake([
        '*' => Http::response([
            'coolify' => [
                'helper' => ['version' => '1.0.1'],
            ],
        ]),
    ]);

    (new CheckHelperImageJob)->handle();

    expect(InstanceSettings::findOrFail(0)->helper_version)->toBe('1.0.1');
    Http::assertSentCount(1);
});
