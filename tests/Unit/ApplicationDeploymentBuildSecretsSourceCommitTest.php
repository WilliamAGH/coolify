<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationSetting;

function sourceCommitBuildSecretPartition(bool $includeSourceCommit): array
{
    $job = new ReflectionClass(ApplicationDeploymentJob::class);
    $instance = $job->newInstanceWithoutConstructor();

    $application = new Application;
    $settings = new ApplicationSetting;
    $settings->include_source_commit_in_build = $includeSourceCommit;
    $application->setRelation('settings', $settings);

    $applicationProperty = $job->getProperty('application');
    $applicationProperty->setValue($instance, $application);
    $commitProperty = $job->getProperty('commit');
    $commitProperty->setValue($instance, '2059234c16e832002b621b120d9166b21ad419f2');

    $partitionMethod = $job->getMethod('partitionPublicBuildArgsFromSecrets');
    [$secrets, $publicBuildArgs] = $partitionMethod->invoke($instance, collect([
        'DATABASE_URL' => 'secret-database-url',
        'SOURCE_COMMIT' => 'user-defined-value-must-not-be-published',
    ]));
    $buildArgsMethod = $job->getMethod('generatePublicDockerBuildArgs');

    return [$secrets, $publicBuildArgs, $buildArgsMethod->invoke($instance, $publicBuildArgs)];
}

it('keeps source commit as a public build argument when build secrets are enabled', function () {
    [$secrets, $publicBuildArgs, $buildArgs] = sourceCommitBuildSecretPartition(true);

    expect($secrets->all())
        ->toBe(['DATABASE_URL' => 'secret-database-url'])
        ->and($publicBuildArgs->all())
        ->toBe(['SOURCE_COMMIT' => '2059234c16e832002b621b120d9166b21ad419f2'])
        ->and($buildArgs->all())
        ->toBe(['SOURCE_COMMIT' => "--build-arg 'SOURCE_COMMIT=2059234c16e832002b621b120d9166b21ad419f2'"]);
});

it('does not expose source commit when build provenance injection is disabled', function () {
    [$secrets, $publicBuildArgs, $buildArgs] = sourceCommitBuildSecretPartition(false);

    expect($secrets->all())
        ->toBe([
            'DATABASE_URL' => 'secret-database-url',
            'SOURCE_COMMIT' => 'user-defined-value-must-not-be-published',
        ])
        ->and($publicBuildArgs->isEmpty())
        ->toBeTrue()
        ->and($buildArgs->isEmpty())
        ->toBeTrue();
});
