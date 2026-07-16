<?php

use App\Livewire\Project\Application\General;
use App\Models\Application;
use App\Models\ApplicationSetting;

function composeEditorApplication(?string $composeContent): Application
{
    $application = new Application([
        'name' => 'Compose application',
        'git_repository' => 'coollabsio/example',
        'git_branch' => 'main',
        'build_pack' => 'dockercompose',
        'static_image' => 'nginx:latest',
        'base_directory' => '/',
        'docker_compose_location' => '/docker-compose.yml',
        'docker_compose_raw' => $composeContent,
        'is_http_basic_auth_enabled' => false,
        'redirect' => 'both',
    ]);
    $settings = new ApplicationSetting;
    $settings->setRawAttributes([
        'is_static' => false,
        'is_spa' => false,
        'is_build_server_enabled' => false,
        'is_preserve_repository_enabled' => false,
        'is_container_label_escape_enabled' => true,
        'is_container_label_readonly_enabled' => false,
    ]);
    $application->setRelation('settings', $settings);

    return $application;
}

function composeEditorComponent(Application $application): General
{
    $component = new General;
    $component->application = $application;

    return $component;
}

it('syncs docker_compose_raw to component property after loading compose file', function () {
    $composeContent = 'version: "3"\nservices:\n  web:\n    image: nginx';
    $component = composeEditorComponent(composeEditorApplication($composeContent));

    $component->syncData();

    expect($component->dockerComposeRaw)
        ->toBe($composeContent)
        ->not->toBeEmpty();
});

it('ensures General component syncs docker_compose_raw property after loading', function () {
    $application = composeEditorApplication(null);
    $component = composeEditorComponent($application);
    $component->syncData();

    expect($component->dockerComposeRaw)->toBeNull();

    $application->docker_compose_raw = 'services:\n  web:\n    image: nginx';
    $component->syncData();

    expect($component->dockerComposeRaw)->toBe($application->docker_compose_raw);
});
