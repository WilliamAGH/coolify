<?php

use App\Jobs\PullTemplatesFromCDN;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Once::flush();
});

it('does not pull service templates for a guarded fork release', function (string $version) {
    config(['constants.coolify.version' => $version]);
    Http::preventStrayRequests();

    (new PullTemplatesFromCDN)->handle();

    Http::assertNothingSent();
})->with([
    'canonical fork release' => '4.13.1-fork',
    'numbered fork release' => '4.13.1-fork.2',
]);

it('leaves the shipped template catalog untouched on a guarded fork release', function () {
    // The job writes straight into the image's own templates/ directory, so an
    // unfenced pull replaced the catalog this fork ships with upstream's copy.
    $catalog = base_path('templates/'.config('constants.services.file_name'));
    $before = File::exists($catalog) ? File::get($catalog) : null;
    config(['constants.coolify.version' => '4.13.1-fork']);
    Http::preventStrayRequests();

    (new PullTemplatesFromCDN)->handle();

    $after = File::exists($catalog) ? File::get($catalog) : null;
    expect($after)->toBe($before);
});

it('keeps pulling service templates for stable releases', function () {
    // Redirected to a throwaway filename: the real catalog is a tracked ~1MB
    // repo file and a test must never rewrite it.
    config([
        'constants.coolify.version' => '4.13.1',
        'constants.services.file_name' => 'pull-templates-from-cdn-test.json',
    ]);
    $written = base_path('templates/pull-templates-from-cdn-test.json');
    File::delete($written);
    Http::fake(['*' => Http::response(['some-service' => ['name' => 'some-service']])]);

    try {
        (new PullTemplatesFromCDN)->handle();

        Http::assertSentCount(1);
        expect(File::exists($written))->toBeTrue()
            ->and(json_decode(File::get($written), true))->toBe(['some-service' => ['name' => 'some-service']]);
    } finally {
        File::delete($written);
    }
});

it('allows the template source to be pointed away from the upstream CDN', function () {
    expect(config('constants.services.official'))
        ->toBe(env('SERVICE_TEMPLATES_URL', 'https://cdn.coollabs.io/coolify/service-templates-latest.json'));
});
