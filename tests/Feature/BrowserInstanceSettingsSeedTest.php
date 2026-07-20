<?php

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the browser instance settings singleton at id zero', function () {
    $settings = seedBrowserInstanceSettings();

    expect($settings->getKey())->toBe(0)
        ->and(InstanceSettings::query()->whereKey(0)->exists())->toBeTrue();
});
