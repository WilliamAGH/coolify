<?php

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * @return array<string, mixed>
 */
function applicationValidationWorkflow(): array
{
    return Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/application-validation.yml');
}

/**
 * @param  array<string, mixed>  $workflow
 * @return array<string, mixed>
 */
function applicationValidationRequiredAggregateStep(array $workflow): array
{
    $step = collect($workflow['jobs']['required']['steps'] ?? [])
        ->firstWhere('name', 'Require every generic validation job');

    if (! is_array($step)) {
        throw new RuntimeException('The required application validation aggregate step is missing.');
    }

    return $step;
}

/**
 * @return array<string, string|false>
 */
function applicationValidationRequiredEnvironment(string $eventName): array
{
    return [
        'BLUE_GREEN_LIFECYCLE_RESULT' => 'success',
        'BROWSER_RESULT' => 'success',
        'EVENT_NAME' => $eventName,
        'FORMATTING_RESULT' => 'success',
        'FORK_DEPLOY_RESULT' => 'success',
        'NODE_RESULT' => 'success',
        'PHP_RESULT' => 'success',
        'REALTIME_RUNTIME_RESULT' => $eventName === 'pull_request' ? 'success' : 'skipped',
        'TESTING_HOST_RUNTIME_RESULT' => $eventName === 'pull_request' ? 'success' : 'skipped',
        'WORKFLOW_RESULT' => 'success',
    ];
}

/**
 * @param  array<string, string|false>  $environment
 */
function runApplicationValidationRequiredAggregate(array $environment): Process
{
    $step = applicationValidationRequiredAggregateStep(applicationValidationWorkflow());
    $process = new Process(
        ['bash', '-c', (string) ($step['run'] ?? '')],
        dirname(__DIR__, 2),
        $environment,
    );
    $process->run();

    return $process;
}

/**
 * @return array<string, array{string, string}>
 */
function applicationValidationGenericResultFailures(): array
{
    $cases = [];

    foreach ([
        'BLUE_GREEN_LIFECYCLE_RESULT',
        'BROWSER_RESULT',
        'FORMATTING_RESULT',
        'FORK_DEPLOY_RESULT',
        'NODE_RESULT',
        'PHP_RESULT',
        'WORKFLOW_RESULT',
    ] as $variable) {
        foreach (['failure', 'skipped', 'cancelled'] as $result) {
            $cases["{$variable} is {$result}"] = [$variable, $result];
        }
    }

    return $cases;
}

/**
 * @return array<string, array{string, string}>
 */
function applicationValidationMissingOrEmptyEnvironmentCases(): array
{
    $cases = [];

    foreach ([
        'BLUE_GREEN_LIFECYCLE_RESULT',
        'BROWSER_RESULT',
        'EVENT_NAME',
        'FORMATTING_RESULT',
        'FORK_DEPLOY_RESULT',
        'NODE_RESULT',
        'PHP_RESULT',
        'REALTIME_RUNTIME_RESULT',
        'TESTING_HOST_RUNTIME_RESULT',
        'WORKFLOW_RESULT',
    ] as $variable) {
        foreach (['missing', 'empty'] as $state) {
            $cases["{$variable} is {$state}"] = [$variable, $state];
        }
    }

    return $cases;
}

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
        'tests/Feature/DatabaseMigrationReadinessTest.php',
        'tests/Feature/BlueGreenSupersessionGenerationTest.php',
        'tests/Feature/LegacyProxyMutationPayloadAdoptionTest.php',
        'tests/Feature/PostgresUserDeletionConcurrencyTest.php',
        'tests/Feature/ProxyMutationQueueGateTest.php',
        'tests/Feature/QueueApplicationDeploymentCommitTest.php',
        'tests/Unit/ApplicationDeploymentActivationOrderTest.php',
        'tests/Unit/ProxyMutationQueueTest.php',
        'tests/Unit/ScheduledJobsRetryConfigTest.php',
    ] as $requiredTest) {
        if (! str_contains((string) $blueGreenScript, $requiredTest)) {
            $violations[] = 'blue-green lifecycle validation must execute every ownership and migration gate';

            break;
        }
    }
    $activationConfigurationStep = collect($blueGreen['steps'] ?? [])
        ->firstWhere('name', 'Validate deployment activation configuration fence')['run'] ?? '';
    if (! str_contains(
        (string) $activationConfigurationStep,
        "tests/Unit/DeploymentConfiguration/ApplicationConfigurationSnapshotTest.php --filter='fences deployment command'",
    )) {
        $violations[] = 'blue-green lifecycle validation must fail closed when activation-time commands change after preparation';
    }

    $phpApplication = is_array($jobs) ? ($jobs['php'] ?? []) : [];
    $controlPlaneScript = collect($phpApplication['steps'] ?? [])
        ->firstWhere('name', 'Run native Traefik control-plane tests')['run'] ?? '';
    foreach ([
        'tests/Feature/InspectProxyMutationQueueTest.php',
        'tests/Feature/Proxy/ControlPlane',
        'tests/Unit/Actions/Proxy/ControlPlane',
    ] as $requiredPath) {
        if (! str_contains((string) $controlPlaneScript, $requiredPath)) {
            $violations[] = 'application validation must execute every native Traefik control-plane test';

            break;
        }
    }

    $workflowAndShell = is_array($jobs) ? ($jobs['workflow-and-shell'] ?? []) : [];
    $databaseMigrationScript = collect($workflowAndShell['steps'] ?? [])
        ->firstWhere('name', 'Verify database migration S6 exit propagation')['run'] ?? '';
    if (! str_contains((string) $databaseMigrationScript, 'tests/Integration/DatabaseMigrationS6/run.sh')) {
        $violations[] = 'application validation must execute the database migration S6 exit propagation integration';
    }
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
    $workflow = applicationValidationWorkflow();

    expect(applicationValidationWorkflowViolations($workflow))->toBe([]);
});

it('keeps the aggregate contract structurally connected to every selected result', function () {
    $workflow = applicationValidationWorkflow();
    $required = $workflow['jobs']['required'] ?? [];
    $step = applicationValidationRequiredAggregateStep($workflow);
    $phpStep = collect($workflow['jobs']['php']['steps'] ?? [])
        ->firstWhere('name', 'Run release and version-consumer tests');

    expect($required['if'] ?? null)->toBe('always()')
        ->and($required['runs-on'] ?? null)->toBe('ubuntu-24.04')
        ->and($required['needs'] ?? null)->toBe([
            'blue-green-lifecycle',
            'php',
            'browser',
            'formatting',
            'fork-deploy',
            'node',
            'realtime-runtime',
            'testing-host-runtime',
            'workflow-and-shell',
        ])
        ->and($step['shell'] ?? null)->toBe('bash')
        ->and($step['env'] ?? null)->toBe([
            'BLUE_GREEN_LIFECYCLE_RESULT' => '${{ needs.blue-green-lifecycle.result }}',
            'BROWSER_RESULT' => '${{ needs.browser.result }}',
            'EVENT_NAME' => '${{ github.event_name }}',
            'FORMATTING_RESULT' => '${{ needs.formatting.result }}',
            'FORK_DEPLOY_RESULT' => '${{ needs.fork-deploy.result }}',
            'NODE_RESULT' => '${{ needs.node.result }}',
            'PHP_RESULT' => '${{ needs.php.result }}',
            'REALTIME_RUNTIME_RESULT' => '${{ needs.realtime-runtime.result }}',
            'TESTING_HOST_RUNTIME_RESULT' => '${{ needs.testing-host-runtime.result }}',
            'WORKFLOW_RESULT' => '${{ needs.workflow-and-shell.result }}',
        ])
        ->and((string) ($phpStep['run'] ?? ''))
        ->toContain('tests/Unit/ApplicationValidationWorkflowTest.php');
});

it('executes the actual aggregate script for successful pull requests and reusable calls', function (
    string $eventName,
): void {
    $process = runApplicationValidationRequiredAggregate(
        applicationValidationRequiredEnvironment($eventName),
    );

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
})->with([
    'pull request' => ['pull_request'],
    'workflow call' => ['workflow_call'],
]);

it('fails the actual aggregate script for every non-successful generic result', function (
    string $variable,
    string $result,
): void {
    $environment = applicationValidationRequiredEnvironment('pull_request');
    $environment[$variable] = $result;

    $process = runApplicationValidationRequiredAggregate($environment);

    expect($process->isSuccessful())->toBeFalse();
})->with(applicationValidationGenericResultFailures());

it('requires testing-host success for pull requests', function (string $result): void {
    $environment = applicationValidationRequiredEnvironment('pull_request');
    $environment['TESTING_HOST_RUNTIME_RESULT'] = $result;

    $process = runApplicationValidationRequiredAggregate($environment);

    expect($process->isSuccessful())->toBeFalse();
})->with([
    'failure' => ['failure'],
    'skipped' => ['skipped'],
    'cancelled' => ['cancelled'],
]);

it('requires realtime runtime success for pull requests', function (string $result): void {
    $environment = applicationValidationRequiredEnvironment('pull_request');
    $environment['REALTIME_RUNTIME_RESULT'] = $result;

    $process = runApplicationValidationRequiredAggregate($environment);

    expect($process->isSuccessful())->toBeFalse();
})->with([
    'failure' => ['failure'],
    'skipped' => ['skipped'],
    'cancelled' => ['cancelled'],
]);

it('only allows a skipped testing-host result for reusable workflow calls', function (string $result): void {
    $environment = applicationValidationRequiredEnvironment('workflow_call');
    $environment['TESTING_HOST_RUNTIME_RESULT'] = $result;

    $process = runApplicationValidationRequiredAggregate($environment);

    expect($process->isSuccessful())->toBeFalse();
})->with([
    'success' => ['success'],
    'failure' => ['failure'],
    'cancelled' => ['cancelled'],
]);

it('only allows a skipped realtime runtime result for reusable workflow calls', function (string $result): void {
    $environment = applicationValidationRequiredEnvironment('workflow_call');
    $environment['REALTIME_RUNTIME_RESULT'] = $result;

    $process = runApplicationValidationRequiredAggregate($environment);

    expect($process->isSuccessful())->toBeFalse();
})->with([
    'success' => ['success'],
    'failure' => ['failure'],
    'cancelled' => ['cancelled'],
]);

it('fails closed when an aggregate environment value is missing or empty', function (
    string $variable,
    string $state,
): void {
    $environment = applicationValidationRequiredEnvironment('pull_request');
    $environment[$variable] = $state === 'missing' ? false : '';

    $process = runApplicationValidationRequiredAggregate($environment);

    expect($process->isSuccessful())->toBeFalse();
})->with(applicationValidationMissingOrEmptyEnvironmentCases());

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

it('rejects omitting delayed database-startup coverage from PostgreSQL validation', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/application-validation.yml');
    $step = collect($workflow['jobs']['blue-green-lifecycle']['steps'])
        ->search(fn (array $step): bool => ($step['name'] ?? null) === 'Run blue-green lifecycle tests');
    $workflow['jobs']['blue-green-lifecycle']['steps'][$step]['run'] = str_replace(
        'tests/Feature/DatabaseMigrationReadinessTest.php',
        '',
        $workflow['jobs']['blue-green-lifecycle']['steps'][$step]['run'],
    );

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('blue-green lifecycle validation must execute every ownership and migration gate');
});

it('rejects omitting PostgreSQL user-deletion concurrency coverage', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/application-validation.yml');
    $step = collect($workflow['jobs']['blue-green-lifecycle']['steps'])
        ->search(fn (array $step): bool => ($step['name'] ?? null) === 'Run blue-green lifecycle tests');
    $workflow['jobs']['blue-green-lifecycle']['steps'][$step]['run'] = str_replace(
        'tests/Feature/PostgresUserDeletionConcurrencyTest.php',
        '',
        $workflow['jobs']['blue-green-lifecycle']['steps'][$step]['run'],
    );

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('blue-green lifecycle validation must execute every ownership and migration gate');
});

it('rejects omitting the activation configuration fence from PostgreSQL validation', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/application-validation.yml');
    $step = collect($workflow['jobs']['blue-green-lifecycle']['steps'])
        ->search(fn (array $step): bool => ($step['name'] ?? null) === 'Validate deployment activation configuration fence');
    $workflow['jobs']['blue-green-lifecycle']['steps'][$step]['run'] = 'true';

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('blue-green lifecycle validation must fail closed when activation-time commands change after preparation');
});

it('rejects omitting the native Traefik control-plane suite', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/application-validation.yml');
    $step = collect($workflow['jobs']['php']['steps'])
        ->search(fn (array $step): bool => ($step['name'] ?? null) === 'Run native Traefik control-plane tests');
    $workflow['jobs']['php']['steps'][$step]['run'] = 'php artisan test --compact tests/Feature/Proxy/ControlPlane';

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('application validation must execute every native Traefik control-plane test');
});

it('rejects omitting explicit proxy-mutation payload diagnostics', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/application-validation.yml');
    $step = collect($workflow['jobs']['php']['steps'])
        ->search(fn (array $step): bool => ($step['name'] ?? null) === 'Run native Traefik control-plane tests');
    $workflow['jobs']['php']['steps'][$step]['run'] = str_replace(
        'tests/Feature/InspectProxyMutationQueueTest.php',
        '',
        $workflow['jobs']['php']['steps'][$step]['run'],
    );

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

it('rejects omitting database migration S6 exit propagation coverage', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/application-validation.yml');
    $step = collect($workflow['jobs']['workflow-and-shell']['steps'])
        ->search(fn (array $step): bool => ($step['name'] ?? null) === 'Verify database migration S6 exit propagation');
    $workflow['jobs']['workflow-and-shell']['steps'][$step]['run'] = 'true';

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('application validation must execute the database migration S6 exit propagation integration');
});
