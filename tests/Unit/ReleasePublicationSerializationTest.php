<?php

use Symfony\Component\Yaml\Yaml;

it('serializes caller release publication and permits shared release writes by target', function (string $workflowPath, string $targetRepository): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/'.$workflowPath);
    $publishJob = $workflow['jobs']['publish'] ?? [];

    expect($publishJob['concurrency'] ?? null)->toBe([
        'group' => "linux-image-release-{$targetRepository}",
        'cancel-in-progress' => false,
    ])->and($publishJob['permissions']['contents'] ?? null)->toBe('write');
})->with([
    'production' => ['.github/workflows/coolify-production-build.yml', 'coollabsio/coolify'],
    'staging' => ['.github/workflows/coolify-staging-build.yml', 'coollabsio/coolify-staging'],
    'testing host' => ['.github/workflows/coolify-testing-host.yml', 'coollabsio/coolify-testing-host'],
]);
