<?php

use App\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('enables static mode without overwriting newer application attributes', function (): void {
    $application = Application::factory()->create([
        'name' => 'Application before update',
        'ports_exposes' => '3000',
    ]);
    $setting = $application->settings()->firstOrFail();
    $setting->setRelation('application', $application);

    Application::query()
        ->whereKey($application->id)
        ->update(['name' => 'Application after update']);

    $setting->is_static = true;
    $setting->save();

    $persistedApplication = $application->fresh();

    expect($persistedApplication->name)->toBe('Application after update')
        ->and($persistedApplication->ports_exposes)->toBe('80')
        ->and($setting->fresh()->is_static)->toBeTrue();
});
