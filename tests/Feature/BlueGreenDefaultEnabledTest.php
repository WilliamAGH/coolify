<?php

use App\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('enables blue-green deployment by default for new application settings', function () {
    $application = Application::factory()->create();

    expect($application->settings->is_blue_green_deployment_enabled)->toBeTrue();
});
