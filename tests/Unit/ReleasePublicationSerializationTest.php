<?php

use Symfony\Component\Yaml\Yaml;

it('serializes caller release publication by target', function (string $workflowPath, string $targetRepository): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/'.$workflowPath);
    $publishJob = $workflow['jobs']['publish'] ?? [];

    expect($publishJob['concurrency'] ?? null)->toBe([
        'group' => "linux-image-release-{$targetRepository}",
        'cancel-in-progress' => false,
    ]);
})->with([
    'production' => ['.github/workflows/coolify-production-build.yml', 'coollabsio/coolify'],
    'staging' => ['.github/workflows/coolify-staging-build.yml', 'coollabsio/coolify-staging'],
    'testing host' => ['.github/workflows/coolify-testing-host.yml', 'coollabsio/coolify-testing-host'],
]);
