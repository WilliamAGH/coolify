<?php

use Symfony\Component\Yaml\Yaml;

/**
 * @param  array<string, mixed>  $workflow
 * @return list<string>
 */
function applicationValidationWorkflowViolations(array $workflow): array
{
    $violations = [];
    $jobs = $workflow['jobs'] ?? [];

    if (! is_array($workflow['on'] ?? null) || ! array_key_exists('workflow_call', $workflow['on'])) {
        $violations[] = 'application validation must remain reusable';
    }

    if (($workflow['permissions'] ?? null) !== ['contents' => 'read']) {
        $violations[] = 'application validation must use read-only repository permissions';
    }

    $required = is_array($jobs) ? ($jobs['required'] ?? []) : [];
    if (($required['name'] ?? null) !== 'Application validation required') {
        $violations[] = 'application validation must preserve the protected branch status context';
    }

    $requiredNeeds = $required['needs'] ?? [];
    $validationJobs = array_values(array_filter(
        array_keys(is_array($jobs) ? $jobs : []),
        fn (string $job): bool => $job !== 'required',
    ));
    sort($requiredNeeds);
    sort($validationJobs);
    if ($requiredNeeds !== $validationJobs || ($required['if'] ?? null) !== 'always()') {
        $violations[] = 'application validation must aggregate every validation job';
    }

    $blueGreen = is_array($jobs) ? ($jobs['blue-green-lifecycle'] ?? []) : [];
    $postgres = $blueGreen['services']['postgres'] ?? [];
    if (($postgres['image'] ?? null) !== 'postgres:16-alpine@sha256:57c72fd2a128e416c7fcc499958864df5301e940bca0a56f58fddf30ffc07777'
        || ($blueGreen['env']['DB_CONNECTION'] ?? null) !== 'pgsql'
        || ($blueGreen['env']['DB_HOST'] ?? null) !== '127.0.0.1') {
        $violations[] = 'blue-green lifecycle validation must use its pinned PostgreSQL service';
    }
    $blueGreenScript = collect($blueGreen['steps'] ?? [])->firstWhere('name', 'Run blue-green lifecycle tests')['run'] ?? '';
    if (! str_contains(
        (string) $blueGreenScript,
        'php -d memory_limit=1G vendor/bin/pest --compact --do-not-cache-result',
    )) {
        $violations[] = 'blue-green lifecycle validation must run Pest with its bounded explicit memory contract';
    }
    foreach ([
        'tests/Feature/ApplicationDeploymentBlueGreenDestinationFenceTest.php',
        'tests/Feature/BlueGreenApplicationDeactivationTest.php',
        'tests/Feature/BlueGreenCancellationCompensationTest.php',
        'tests/Feature/BlueGreenContinuousAvailabilityAcceptanceTest.php',
        'tests/Feature/BlueGreenCrashBoundaryAcceptanceTest.php',
        'tests/Feature/BlueGreenMigrationReplayTest.php',
        'tests/Feature/BlueGreenSupersessionGenerationTest.php',
        'tests/Feature/ProxyMutationQueueGateTest.php',
        'tests/Unit/ScheduledJobsRetryConfigTest.php',
    ] as $requiredTest) {
        if (! str_contains((string) $blueGreenScript, $requiredTest)) {
            $violations[] = 'blue-green lifecycle validation must execute every ownership and migration gate';

            break;
        }
    }

    $phpApplication = is_array($jobs) ? ($jobs['php'] ?? []) : [];
    $controlPlaneScript = collect($phpApplication['steps'] ?? [])
        ->firstWhere('name', 'Run native Traefik control-plane tests')['run'] ?? '';
    foreach ([
        'tests/Feature/Proxy/ControlPlane',
        'tests/Unit/Actions/Proxy/ControlPlane',
    ] as $requiredPath) {
        if (! str_contains((string) $controlPlaneScript, $requiredPath)) {
            $violations[] = 'application validation must execute every native Traefik control-plane test';

            break;
        }
    }

    $workflowAndShell = is_array($jobs) ? ($jobs['workflow-and-shell'] ?? []) : [];
    $traefikRuntimeScript = collect($workflowAndShell['steps'] ?? [])
        ->firstWhere('name', 'Run native Traefik runtime integration')['run'] ?? '';
    if (! str_contains((string) $traefikRuntimeScript, 'tests/Integration/ControlPlaneTraefik/run.sh')) {
        $violations[] = 'application validation must execute the native Traefik runtime integration';
    }

    foreach (is_array($jobs) ? $jobs : [] as $job) {
        foreach ($job['steps'] ?? [] as $step) {
            $uses = (string) ($step['uses'] ?? '');
            if ($uses !== '' && ! str_starts_with($uses, './') && preg_match('/@[0-9a-f]{40}\z/', $uses) !== 1) {
                $violations[] = 'external actions must use immutable commit references';
            }
        }
    }

    return array_values(array_unique($violations));
}

it('defines the required application validation contract', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/application-validation.yml');

    expect(applicationValidationWorkflowViolations($workflow))->toBe([]);
});

it('rejects renaming the protected branch validation context', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/application-validation.yml');
    $workflow['jobs']['required']['name'] = 'Renamed application validation';

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('application validation must preserve the protected branch status context');
});

it('rejects omitting a blue-green ownership gate from PostgreSQL validation', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/application-validation.yml');
    $step = collect($workflow['jobs']['blue-green-lifecycle']['steps'])
        ->search(fn (array $step): bool => ($step['name'] ?? null) === 'Run blue-green lifecycle tests');
    $workflow['jobs']['blue-green-lifecycle']['steps'][$step]['run'] = str_replace(
        'tests/Feature/BlueGreenCancellationCompensationTest.php',
        'tests/Feature/BlueGreenApplicationDeactivationTest.php',
        $workflow['jobs']['blue-green-lifecycle']['steps'][$step]['run'],
    );

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('blue-green lifecycle validation must execute every ownership and migration gate');
});

it('rejects omitting the native Traefik control-plane suite', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/application-validation.yml');
    $step = collect($workflow['jobs']['php']['steps'])
        ->search(fn (array $step): bool => ($step['name'] ?? null) === 'Run native Traefik control-plane tests');
    $workflow['jobs']['php']['steps'][$step]['run'] = 'php artisan test --compact tests/Feature/Proxy/ControlPlane';

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('application validation must execute every native Traefik control-plane test');
});

it('rejects omitting the native Traefik runtime integration', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/application-validation.yml');
    $step = collect($workflow['jobs']['workflow-and-shell']['steps'])
        ->search(fn (array $step): bool => ($step['name'] ?? null) === 'Run native Traefik runtime integration');
    $workflow['jobs']['workflow-and-shell']['steps'][$step]['run'] = 'true';

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('application validation must execute the native Traefik runtime integration');
});
