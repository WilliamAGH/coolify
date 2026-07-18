<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'fqdn' => null]);
    User::factory()->create();
    config(['app.name' => 'Coolify Cloud']);
});

it('does not load browser analytics by default', function () {
    $this->get('/login')
        ->assertSuccessful()
        ->assertDontSee('https://analytics.coollabs.io/js/plausible.js', false)
        ->assertSee('https://js.sentry-cdn.com/', false);
});

it('retains the opt-in browser analytics integration', function () {
    config(['app.analytics_enabled' => true]);

    $this->get('/login')
        ->assertSuccessful()
        ->assertSee('https://analytics.coollabs.io/js/plausible.js', false);
});
