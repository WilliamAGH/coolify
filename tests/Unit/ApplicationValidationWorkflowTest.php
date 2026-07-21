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

function controlPlaneTraefikRuntimeScript(): string
{
    return file_get_contents(dirname(__DIR__).'/Integration/ControlPlaneTraefik/run.sh');
}

/**
 * @return list<string>
 */
function controlPlaneTraefikTransportLifecycleViolations(string $script): array
{
    preg_match_all(
        '/^\s*publish_transport_release [^\n]+ "\$forward_transport_release"$/m',
        $script,
        $forwardTransportReleases,
    );
    if (count($forwardTransportReleases[0]) !== 1) {
        return ['native Traefik runtime must retain its original transport streams through proved rollback'];
    }

    $milestones = [
        '    start_transport_observer forward backend-blue "$BACKEND_BLUE_STATE_DIR"',
        '    forward_applied_at_ms=$(now_ms)',
        '    start_transport_observer rollback backend-green "$BACKEND_GREEN_STATE_DIR"',
        '    rollback_applied_at_ms=$(now_ms)',
        '    publish_transport_release rollback "$rollback_applied_at_ms" "$rollback_transport_release"',
        '    assert_transport_continuity "$rollback_transport_report" rollback',
        '    assert_transition_log "$rollback_observer_log"',
        '    assert_exact_dynamic_snapshot "$blue_final_snapshot"',
        '    publish_transport_release full-cycle "$rollback_applied_at_ms" "$forward_transport_release"',
        '    wait_for_transport_observer full-cycle "$forward_transport_pid" "$forward_transport_log" "$forward_transport_report"',
        '    assert_full_cycle_transport_continuity "$forward_transport_report"',
    ];
    $previousPosition = -1;

    foreach ($milestones as $milestone) {
        $position = strpos($script, $milestone);
        if ($position === false || $position <= $previousPosition) {
            return ['native Traefik runtime must retain its original transport streams through proved rollback'];
        }

        $previousPosition = $position;
    }

    foreach ([
        'any(.sse.events[]; .receivedAt >= $forward_applied_at and .receivedAt < $rollback_started_at)',
        'any(.websocket.events[]; .receivedAt >= $forward_applied_at and .receivedAt < $rollback_started_at)',
    ] as $requiredAssertion) {
        if (! str_contains($script, $requiredAssertion)) {
            return ['native Traefik runtime must retain its original transport streams through proved rollback'];
        }
    }

    return [];
}

/**
 * @return list<string>
 */
function controlPlaneTraefikTransportSafetyViolations(string $script): array
{
    $violations = [];

    foreach ([
        'readonly TRANSPORT_OBSERVER_RELOAD_PHASES=6',
        'readonly TRANSPORT_OBSERVER_TIMEOUT_MARGIN_MS=15000',
        '(2 * MAX_TRANSITION_OBSERVATION_MS)',
        '(TRANSPORT_OBSERVER_RELOAD_PHASES * MAX_RELOAD_DELAY_MS)',
        '+ TRANSPORT_OBSERVER_TIMEOUT_MARGIN_MS',
    ] as $timeoutContract) {
        if (! str_contains($script, $timeoutContract)) {
            $violations[] = 'native Traefik transport timeout must cover both bounded transitions and reload phases';
            break;
        }
    }

    $cleanupStart = strpos($script, "cleanup() {\n");
    $cleanupEnd = $cleanupStart === false ? false : strpos($script, "\n}\n\ntrap cleanup EXIT", $cleanupStart);
    $cleanup = $cleanupStart === false || $cleanupEnd === false
        ? ''
        : substr($script, $cleanupStart, $cleanupEnd - $cleanupStart);
    $terminatePosition = strpos($cleanup, 'terminate_registered_background_pids');
    $composeDownPosition = strpos($cleanup, 'compose down --volumes --remove-orphans');
    $reapPosition = strpos($cleanup, 'reap_registered_background_pids');
    if ($terminatePosition === false
        || $composeDownPosition === false
        || $reapPosition === false
        || ! ($terminatePosition < $composeDownPosition && $composeDownPosition < $reapPosition)
        || ! str_contains($script, 'readonly BACKGROUND_PID_EXIT_TIMEOUT_MS=5000')
        || ! str_contains($script, 'kill -KILL "$pid" 2>/dev/null || true')) {
        $violations[] = 'native Traefik cleanup must tear Compose down before bounded child reaping';
    }

    foreach ([
        'wrong-backend',
        'wrong-color',
        'wrong-generation',
        'wrong-dynamic-sha',
        'changed-connection-id',
        'missing-green-interval',
        'missing-post-rollback',
        'assert_transport_report_validation',
    ] as $reportContract) {
        if (! str_contains($script, $reportContract)) {
            $violations[] = 'native Traefik self-tests must reject malformed full-cycle transport reports';
            break;
        }
    }

    return array_values(array_unique($violations));
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
 * @param  array<string, mixed>  $workflow
 * @return array<string, mixed>
 */
function applicationValidationSourceIdentityStep(array $workflow): array
{
    $step = collect($workflow['jobs']['source-identity']['steps'] ?? [])
        ->firstWhere('name', 'Bind requested source to check-run revision');

    if (! is_array($step)) {
        throw new RuntimeException('The exact validation source identity step is missing.');
    }

    return $step;
}

/**
 * @return array<string, string|false>
 */
function applicationValidationRequiredEnvironment(string $eventName, string $sourceSha = ''): array
{
    return [
        'BLUE_GREEN_LIFECYCLE_RESULT' => 'success',
        'BROWSER_RESULT' => 'success',
        'EVENT_NAME' => $eventName,
        'FORMATTING_RESULT' => 'success',
        'FORK_DEPLOY_RESULT' => 'success',
        'NODE_RESULT' => 'success',
        'PHP_RESULT' => 'success',
        'SOURCE_IDENTITY_RESULT' => 'success',
        'VALIDATION_SOURCE_SHA' => $sourceSha,
        'TESTING_HOST_RUNTIME_RESULT' => $eventName === 'pull_request' || $sourceSha !== '' ? 'success' : 'skipped',
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

function runApplicationValidationSourceIdentity(string $sourceSha, string $checkRunSha): Process
{
    $step = applicationValidationSourceIdentityStep(applicationValidationWorkflow());
    $process = new Process(
        ['bash', '-c', (string) ($step['run'] ?? '')],
        dirname(__DIR__, 2),
        [
            'CHECK_RUN_SHA' => $checkRunSha,
            'VALIDATION_SOURCE_SHA' => $sourceSha,
        ],
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
        'SOURCE_IDENTITY_RESULT',
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
        'SOURCE_IDENTITY_RESULT',
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

    $sourceIdentity = is_array($jobs) ? ($jobs['source-identity'] ?? []) : [];
    $sourceIdentityStep = collect($sourceIdentity['steps'] ?? [])
        ->firstWhere('name', 'Bind requested source to check-run revision');
    if (($sourceIdentity['name'] ?? null) !== 'Exact validation source identity'
        || ! is_array($sourceIdentityStep)
        || ($sourceIdentityStep['shell'] ?? null) !== 'bash'
        || ($sourceIdentityStep['env'] ?? null) !== [
            'CHECK_RUN_SHA' => '${{ github.sha }}',
            'VALIDATION_SOURCE_SHA' => '${{ inputs.source_sha }}',
        ]
        || ! str_contains((string) ($sourceIdentityStep['run'] ?? ''), '[[ "$VALIDATION_SOURCE_SHA" == "$CHECK_RUN_SHA" ]]')) {
        $violations[] = 'application validation must bind an exact requested source to the check-run revision';
    }
    foreach (is_array($jobs) ? $jobs : [] as $jobName => $job) {
        if (in_array($jobName, ['required', 'source-identity'], true)) {
            continue;
        }
        if (($job['needs'] ?? null) !== 'source-identity') {
            $violations[] = 'every validation job must wait for exact source identity';

            break;
        }
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
        'tests/Feature/BlueGreenApplicationManualStopTest.php',
        'tests/Feature/BlueGreenCancellationCompensationTest.php',
        'tests/Feature/BlueGreenContinuousAvailabilityAcceptanceTest.php',
        'tests/Feature/BlueGreenCrashBoundaryAcceptanceTest.php',
        'tests/Feature/BlueGreenDeploymentReconciliationTest.php',
        'tests/Feature/BlueGreenFinalizedDrainingRecoveryTest.php',
        'tests/Feature/BlueGreenInactiveRetirementTest.php',
        'tests/Feature/BlueGreenLifecyclePublicRecoveryTest.php',
        'tests/Feature/BlueGreenMigrationReplayTest.php',
        'tests/Feature/BlueGreenMultiPortPromotionAcceptanceTest.php',
        'tests/Feature/BlueGreenReplicaLifecycleTest.php',
        'tests/Feature/BlueGreenStoppedLegacyContainerCleanupTest.php',
        'tests/Feature/DatabaseMigrationReadinessTest.php',
        'tests/Feature/BlueGreenSupersessionGenerationTest.php',
        'tests/Feature/LegacyProxyMutationPayloadAdoptionTest.php',
        'tests/Feature/PostgresUserDeletionConcurrencyTest.php',
        'tests/Feature/ProxyMutationQueueGateTest.php',
        'tests/Feature/QueueApplicationDeploymentCommitTest.php',
        'tests/Unit/ApplicationDeploymentActivationOrderTest.php',
        'tests/Unit/Actions/Application/BlueGreen/BlueGreenNonRootRemoteExecutionTest.php',
        'tests/Unit/Actions/Proxy/BlueGreenNonRootRemoteExecutionTest.php',
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
    $releaseTestsScript = collect($phpApplication['steps'] ?? [])
        ->firstWhere('name', 'Run release and version-consumer tests')['run'] ?? '';
    if (! str_contains((string) $releaseTestsScript, 'tests/Unit/V4xCandidateWorkflowTest.php')) {
        $violations[] = 'application validation must execute the v4.x candidate workflow regression owner';
    }
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
    $backupQuiesceOwner = 'tests/Feature/Proxy/ControlPlane/ProveAndFreezeControlPlaneGenerationTest.php';
    $backupQuiesceScript = collect($phpApplication['steps'] ?? [])
        ->firstWhere('name', 'Run control-plane backup-quiesce owner')['run'] ?? '';
    $phpScripts = collect($phpApplication['steps'] ?? [])
        ->map(fn (array $step): string => (string) ($step['run'] ?? ''))
        ->implode("\n");
    if ((string) $backupQuiesceScript !== "php artisan test --compact {$backupQuiesceOwner}"
        || substr_count($phpScripts, $backupQuiesceOwner) !== 1) {
        $violations[] = 'application validation must execute the canonical control-plane backup-quiesce owner';
    }

    $workflowAndShell = is_array($jobs) ? ($jobs['workflow-and-shell'] ?? []) : [];
    $candidateShipScript = collect($workflowAndShell['steps'] ?? [])
        ->firstWhere('name', 'Run v4.x candidate ship contract')['run'] ?? '';
    if ((string) $candidateShipScript !== 'scripts/dev/ship.test.sh') {
        $violations[] = 'application validation must execute the v4.x candidate ship contract';
    }
    $dockerDaemonConfigurationScript = collect($workflowAndShell['steps'] ?? [])
        ->firstWhere('name', 'Verify Docker daemon configuration ownership')['run'] ?? '';
    if (! str_contains((string) $dockerDaemonConfigurationScript, 'tests/Integration/DockerDaemonConfigurationTest.sh')) {
        $violations[] = 'application validation must execute the Docker daemon configuration integration';
    }
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
    $workflowAndShellSteps = collect($workflowAndShell['steps'] ?? []);
    $phpSetupIndex = $workflowAndShellSteps
        ->search(fn (array $step): bool => ($step['name'] ?? null) === 'Set up PHP');
    $composerDependenciesIndex = $workflowAndShellSteps
        ->search(fn (array $step): bool => ($step['name'] ?? null) === 'Install Composer dependencies');
    $traefikRuntimeIndex = $workflowAndShellSteps
        ->search(fn (array $step): bool => ($step['name'] ?? null) === 'Run native Traefik runtime integration');
    $phpSetup = $phpSetupIndex === false ? null : $workflowAndShellSteps->get($phpSetupIndex);
    $composerDependencies = $composerDependenciesIndex === false ? null : $workflowAndShellSteps->get($composerDependenciesIndex);
    if (! is_array($phpSetup)
        || ($phpSetup['uses'] ?? null) !== 'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240'
        || ($phpSetup['with']['php-version'] ?? null) !== '8.5'
        || ($phpSetup['with']['coverage'] ?? null) !== 'none'
        || ($phpSetup['with']['extensions'] ?? null) !== 'mbstring, pdo_sqlite, redis') {
        $violations[] = 'native Traefik runtime integration must configure pinned PHP 8.5';
    }
    if (! is_array($composerDependencies)
        || ($composerDependencies['run'] ?? null) !== 'composer install --no-interaction --prefer-dist --optimize-autoloader') {
        $violations[] = 'native Traefik runtime integration must install Composer dependencies';
    }
    if ($phpSetupIndex === false
        || $composerDependenciesIndex === false
        || $traefikRuntimeIndex === false
        || ! ($phpSetupIndex < $composerDependenciesIndex && $composerDependenciesIndex < $traefikRuntimeIndex)) {
        $violations[] = 'native Traefik runtime integration must prepare PHP and Composer before it runs';
    }

    $testingHostRuntime = is_array($jobs) ? ($jobs['testing-host-runtime'] ?? []) : [];
    $bundledRuntime = collect($testingHostRuntime['steps'] ?? [])
        ->firstWhere('name', 'Run exact bundled Reverb and terminal runtime contract');
    if (! is_array($bundledRuntime)
        || ($bundledRuntime['env']['PRODUCTION_IMAGE'] ?? null) !== 'coolify:application-validation-${{ inputs.source_sha || github.sha }}'
        || ($bundledRuntime['run'] ?? null) !== 'tests/Integration/RealtimeImageTest.sh') {
        $violations[] = 'application validation must execute the bundled Reverb and terminal contract against the exact production image';
    }
    $productionBlueGreen = collect($testingHostRuntime['steps'] ?? [])
        ->firstWhere('name', 'Run production application blue-green deployment contract');
    if (! is_array($productionBlueGreen)
        || ($productionBlueGreen['env'] ?? null) !== [
            'EVIDENCE_PARENT' => '${{ runner.temp }}/production-application-blue-green-evidence',
            'PRODUCTION_APPLICATION_BLUE_GREEN_EVIDENCE_DIRECTORY' => '${{ runner.temp }}/production-application-blue-green-evidence',
            'PRODUCTION_IMAGE' => 'coolify:application-validation-${{ inputs.source_sha || github.sha }}',
            'TESTING_HOST_IMAGE' => 'coolify-testing-host:application-validation-${{ inputs.source_sha || github.sha }}',
        ]
        || ! str_contains((string) ($productionBlueGreen['run'] ?? ''), 'install -d -m 0700 "$EVIDENCE_PARENT"')
        || ! str_contains((string) ($productionBlueGreen['run'] ?? ''), 'tests/Integration/ProductionApplicationBlueGreen/run.sh')) {
        $violations[] = 'application validation must execute the production application blue-green contract against both exact source images';
    }
    $productionBlueGreenEvidenceSanitizer = collect($testingHostRuntime['steps'] ?? [])
        ->firstWhere('name', 'Sanitize production application blue-green evidence');
    $sanitizerScript = (string) ($productionBlueGreenEvidenceSanitizer['run'] ?? '');
    $expectedSanitizerScript = <<<'SH'
set -Eeuo pipefail
python3 tests/Integration/ProductionApplicationBlueGreen/sanitize-evidence.py \
  "$RAW_EVIDENCE_PARENT" "$SANITIZED_EVIDENCE_PARENT"
SH;
    if (! is_array($productionBlueGreenEvidenceSanitizer)
        || ($productionBlueGreenEvidenceSanitizer['if'] ?? null) !== 'always()'
        || ($productionBlueGreenEvidenceSanitizer['shell'] ?? null) !== 'bash'
        || ($productionBlueGreenEvidenceSanitizer['env'] ?? null) !== [
            'RAW_EVIDENCE_PARENT' => '${{ runner.temp }}/production-application-blue-green-evidence',
            'SANITIZED_EVIDENCE_PARENT' => '${{ runner.temp }}/production-application-blue-green-sanitized',
        ]
        || trim($sanitizerScript) !== $expectedSanitizerScript) {
        $violations[] = 'application validation must sanitize every retained production application blue-green text artifact';
    }
    $productionBlueGreenArtifact = collect($testingHostRuntime['steps'] ?? [])
        ->firstWhere('name', 'Retain sanitized production application blue-green evidence');
    if (! is_array($productionBlueGreenArtifact)
        || ($productionBlueGreenArtifact['if'] ?? null) !== 'always()'
        || ($productionBlueGreenArtifact['uses'] ?? null) !== 'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a'
        || ($productionBlueGreenArtifact['with']['if-no-files-found'] ?? null) !== 'ignore'
        || ($productionBlueGreenArtifact['with']['path'] ?? null) !== '${{ runner.temp }}/production-application-blue-green-sanitized/**') {
        $violations[] = 'application validation must retain sanitized production application blue-green evidence';
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

it('accepts only an empty source or the exact check-run revision', function (string $sourceSha, string $checkRunSha): void {
    $process = runApplicationValidationSourceIdentity($sourceSha, $checkRunSha);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
})->with([
    'implicit check-run source' => ['', str_repeat('a', 40)],
    'matching exact source' => [str_repeat('b', 40), str_repeat('b', 40)],
]);

it('rejects malformed or mismatched exact validation sources', function (string $sourceSha, string $checkRunSha): void {
    $process = runApplicationValidationSourceIdentity($sourceSha, $checkRunSha);

    expect($process->isSuccessful())->toBeFalse();
})->with([
    'short source' => [str_repeat('a', 39), str_repeat('a', 40)],
    'uppercase source' => [str_repeat('A', 40), str_repeat('a', 40)],
    'different revision' => [str_repeat('a', 40), str_repeat('b', 40)],
]);

it('rejects removing the exact validation source binding', function (): void {
    $workflow = applicationValidationWorkflow();
    $step = collect($workflow['jobs']['source-identity']['steps'] ?? [])
        ->search(fn (array $candidate): bool => ($candidate['name'] ?? null) === 'Bind requested source to check-run revision');
    expect($step)->not->toBeFalse();
    $workflow['jobs']['source-identity']['steps'][$step]['run'] = 'true';

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('application validation must bind an exact requested source to the check-run revision');
});

it('rejects running a validation job before exact source identity succeeds', function (): void {
    $workflow = applicationValidationWorkflow();
    unset($workflow['jobs']['php']['needs']);

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('every validation job must wait for exact source identity');
});

it('fails when Docker daemon configuration ownership is removed from required validation', function () {
    $workflow = applicationValidationWorkflow();
    $step = collect($workflow['jobs']['workflow-and-shell']['steps'] ?? [])
        ->search(fn (array $candidate): bool => ($candidate['name'] ?? null) === 'Verify Docker daemon configuration ownership');
    expect($step)->not->toBeFalse();

    unset($workflow['jobs']['workflow-and-shell']['steps'][$step]);

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('application validation must execute the Docker daemon configuration integration');
});

it('fails when the production application blue-green runtime owner is removed', function (): void {
    $workflow = applicationValidationWorkflow();
    $step = collect($workflow['jobs']['testing-host-runtime']['steps'] ?? [])
        ->search(fn (array $candidate): bool => ($candidate['name'] ?? null) === 'Run production application blue-green deployment contract');
    expect($step)->not->toBeFalse();
    unset($workflow['jobs']['testing-host-runtime']['steps'][$step]);

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('application validation must execute the production application blue-green contract against both exact source images');
});

it('fails when an exact blue-green regression owner is removed from required validation', function (string $requiredTest): void {
    $workflow = applicationValidationWorkflow();
    $step = collect($workflow['jobs']['blue-green-lifecycle']['steps'] ?? [])
        ->search(fn (array $candidate): bool => ($candidate['name'] ?? null) === 'Run blue-green lifecycle tests');
    expect($step)->not->toBeFalse();
    $workflow['jobs']['blue-green-lifecycle']['steps'][$step]['run'] = str_replace(
        $requiredTest,
        'tests/Feature/RemovedRequiredRegressionOwnerTest.php',
        (string) $workflow['jobs']['blue-green-lifecycle']['steps'][$step]['run'],
    );

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('blue-green lifecycle validation must execute every ownership and migration gate');
})->with([
    'replica identity' => 'tests/Feature/BlueGreenReplicaLifecycleTest.php',
    'application privileged transport' => 'tests/Unit/Actions/Application/BlueGreen/BlueGreenNonRootRemoteExecutionTest.php',
    'proxy privileged transport' => 'tests/Unit/Actions/Proxy/BlueGreenNonRootRemoteExecutionTest.php',
]);

it('fails when production application blue-green evidence bypasses the sanitized export tree', function (): void {
    $workflow = applicationValidationWorkflow();
    $step = collect($workflow['jobs']['testing-host-runtime']['steps'] ?? [])
        ->search(fn (array $candidate): bool => ($candidate['name'] ?? null) === 'Retain sanitized production application blue-green evidence');
    expect($step)->not->toBeFalse();
    $workflow['jobs']['testing-host-runtime']['steps'][$step]['with']['path'] =
        '${{ runner.temp }}/production-application-blue-green-evidence/**';

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('application validation must retain sanitized production application blue-green evidence');
});

it('fails when production application blue-green evidence is uploaded without sanitizing every text artifact', function (): void {
    $workflow = applicationValidationWorkflow();
    $step = collect($workflow['jobs']['testing-host-runtime']['steps'] ?? [])
        ->search(fn (array $candidate): bool => ($candidate['name'] ?? null) === 'Sanitize production application blue-green evidence');
    expect($step)->not->toBeFalse();
    unset($workflow['jobs']['testing-host-runtime']['steps'][$step]);

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('application validation must sanitize every retained production application blue-green text artifact');
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
            'source-identity',
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
            'SOURCE_IDENTITY_RESULT' => '${{ needs.source-identity.result }}',
            'VALIDATION_SOURCE_SHA' => '${{ inputs.source_sha }}',
            'TESTING_HOST_RUNTIME_RESULT' => '${{ needs.testing-host-runtime.result }}',
            'WORKFLOW_RESULT' => '${{ needs.workflow-and-shell.result }}',
        ])
        ->and((string) ($phpStep['run'] ?? ''))
        ->toContain('tests/Unit/ApplicationValidationWorkflowTest.php');
});

it('executes the actual aggregate script for successful pull requests and reusable calls', function (
    string $eventName,
    string $sourceSha,
): void {
    $process = runApplicationValidationRequiredAggregate(
        applicationValidationRequiredEnvironment($eventName, $sourceSha),
    );

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
})->with([
    'pull request' => ['pull_request', ''],
    'exact-source workflow call' => ['workflow_call', str_repeat('a', 40)],
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

it('only allows a skipped testing-host result without an exact source', function (string $result): void {
    $environment = applicationValidationRequiredEnvironment('workflow_call');
    $environment['TESTING_HOST_RUNTIME_RESULT'] = $result;

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

it('rejects omitting nullable backend inventory compatibility coverage from PostgreSQL validation', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/application-validation.yml');
    $step = collect($workflow['jobs']['blue-green-lifecycle']['steps'])
        ->search(fn (array $step): bool => ($step['name'] ?? null) === 'Run blue-green lifecycle tests');
    $workflow['jobs']['blue-green-lifecycle']['steps'][$step]['run'] = str_replace(
        'tests/Feature/BlueGreenMultiPortPromotionAcceptanceTest.php',
        'tests/Feature/BlueGreenLifecyclePublicRecoveryTest.php',
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

it('rejects omitting the canonical control-plane backup-quiesce owner', function () {
    $workflow = applicationValidationWorkflow();
    $step = collect($workflow['jobs']['php']['steps'] ?? [])
        ->search(fn (array $candidate): bool => ($candidate['name'] ?? null) === 'Run control-plane backup-quiesce owner');
    expect($step)->not->toBeFalse();

    unset($workflow['jobs']['php']['steps'][$step]);

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('application validation must execute the canonical control-plane backup-quiesce owner');
});

it('rejects executing the control-plane backup-quiesce owner twice', function () {
    $workflow = applicationValidationWorkflow();
    $step = collect($workflow['jobs']['php']['steps'] ?? [])
        ->search(fn (array $candidate): bool => ($candidate['name'] ?? null) === 'Run native Traefik control-plane tests');
    expect($step)->not->toBeFalse();

    $workflow['jobs']['php']['steps'][$step]['run'] .= "\n"
        .'php artisan test --compact tests/Feature/Proxy/ControlPlane/ProveAndFreezeControlPlaneGenerationTest.php';

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('application validation must execute the canonical control-plane backup-quiesce owner');
});

it('rejects omitting the native Traefik runtime integration', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/application-validation.yml');
    $step = collect($workflow['jobs']['workflow-and-shell']['steps'])
        ->search(fn (array $step): bool => ($step['name'] ?? null) === 'Run native Traefik runtime integration');
    $workflow['jobs']['workflow-and-shell']['steps'][$step]['run'] = 'true';

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('application validation must execute the native Traefik runtime integration');
});

it('rejects omitting bundled Reverb and terminal runtime acceptance', function () {
    $workflow = applicationValidationWorkflow();
    $step = collect($workflow['jobs']['testing-host-runtime']['steps'] ?? [])
        ->search(fn (array $candidate): bool => ($candidate['name'] ?? null) === 'Run exact bundled Reverb and terminal runtime contract');
    expect($step)->not->toBeFalse();

    unset($workflow['jobs']['testing-host-runtime']['steps'][$step]);

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('application validation must execute the bundled Reverb and terminal contract against the exact production image');
});

it('retains the original transport streams through forward promotion and proved rollback', function () {
    expect(controlPlaneTraefikTransportLifecycleViolations(controlPlaneTraefikRuntimeScript()))->toBe([]);
});

it('rejects releasing the original transport streams before rollback proof', function () {
    $script = controlPlaneTraefikRuntimeScript();
    $rollbackStart = '    start_transport_observer rollback backend-green "$BACKEND_GREEN_STATE_DIR"';
    $prematureRelease = '    publish_transport_release forward "$forward_applied_at_ms" "$forward_transport_release"';
    $mutated = str_replace($rollbackStart, $prematureRelease."\n".$rollbackStart, $script);

    expect(controlPlaneTraefikTransportLifecycleViolations($mutated))
        ->toContain('native Traefik runtime must retain its original transport streams through proved rollback');
});

it('keeps full-cycle transport deadlines and cleanup ordering internally bounded', function () {
    expect(controlPlaneTraefikTransportSafetyViolations(controlPlaneTraefikRuntimeScript()))->toBe([]);
});

it('rejects shortening the full-cycle observer below its derived phase bounds', function () {
    $mutated = str_replace(
        '+ TRANSPORT_OBSERVER_TIMEOUT_MARGIN_MS',
        '+ 0',
        controlPlaneTraefikRuntimeScript(),
    );

    expect(controlPlaneTraefikTransportSafetyViolations($mutated))
        ->toContain('native Traefik transport timeout must cover both bounded transitions and reload phases');
});

it('rejects waiting on observer children before exact-project Compose teardown', function () {
    $script = controlPlaneTraefikRuntimeScript();
    $mutated = str_replace(
        "    if [ \"\$COMPOSE_STARTED\" -eq 1 ]; then\n        compose down --volumes --remove-orphans >/dev/null 2>&1\n    fi\n    reap_registered_background_pids",
        "    reap_registered_background_pids\n    if [ \"\$COMPOSE_STARTED\" -eq 1 ]; then\n        compose down --volumes --remove-orphans >/dev/null 2>&1\n    fi",
        $script,
    );

    expect($mutated)->not->toBe($script)
        ->and(controlPlaneTraefikTransportSafetyViolations($mutated))
        ->toContain('native Traefik cleanup must tear Compose down before bounded child reaping');
});

it('executes negative full-cycle transport report fixtures', function () {
    $process = new Process([
        'bash',
        dirname(__DIR__).'/Integration/ControlPlaneTraefik/run.sh',
        '--self-test',
    ], dirname(__DIR__, 2));
    $process->setTimeout(30);
    $process->mustRun();

    expect($process->getOutput())
        ->toContain('PASS: transition-log and transport-report validation self-tests completed.');
});

it('rejects omitting database migration S6 exit propagation coverage', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/application-validation.yml');
    $step = collect($workflow['jobs']['workflow-and-shell']['steps'])
        ->search(fn (array $step): bool => ($step['name'] ?? null) === 'Verify database migration S6 exit propagation');
    $workflow['jobs']['workflow-and-shell']['steps'][$step]['run'] = 'true';

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('application validation must execute the database migration S6 exit propagation integration');
});

it('pins Node 24 action implementations throughout release validation and publication', function () {
    $expectedPins = [
        'actions/download-artifact' => '3e5f45b2cfb9172054b4087a40e8e0b5a5461e7c',
        'actions/setup-node' => '820762786026740c76f36085b0efc47a31fe5020',
        'actions/upload-artifact' => '043fb46d1a93c77aae656e7c1c64a875d1fc6a0a',
        'aquasecurity/trivy-action' => 'ed142fd0673e97e23eac54620cfb913e5ce36c25',
        'docker/build-push-action' => '53b7df96c91f9c12dcc8a07bcb9ccacbed38856a',
        'docker/login-action' => 'af1e73f918a031802d376d3c8bbc3fe56130a9b0',
        'docker/setup-buildx-action' => 'bb05f3f5519dd87d3ba754cc423b652a5edd6d2c',
        'shivammathur/setup-php' => 'f3e473d116dcccaddc5834248c87452386958240',
    ];
    $observedActions = array_fill_keys(array_keys($expectedPins), 0);
    $workflowSources = '';

    foreach ([
        'application-validation.yml',
        'publish-linux-image.yml',
        'release-operational-acceptance.yml',
    ] as $workflowFile) {
        $workflowPath = dirname(__DIR__, 2).'/.github/workflows/'.$workflowFile;
        $workflowSources .= file_get_contents($workflowPath);
        $workflow = Yaml::parseFile($workflowPath);

        foreach ($workflow['jobs'] ?? [] as $job) {
            foreach ($job['steps'] ?? [] as $step) {
                $uses = (string) ($step['uses'] ?? '');

                foreach ($expectedPins as $action => $sha) {
                    if (! str_starts_with($uses, $action.'@')) {
                        continue;
                    }

                    expect($uses)->toBe($action.'@'.$sha);
                    $observedActions[$action]++;
                }
            }
        }
    }

    expect($observedActions)->each->toBeGreaterThan(0);

    foreach ([
        '10e90e3645eae34f1e60eeb005ba3a3d33f178e8',
        '44454db4f0199b8b9685a5d763dc37cbf79108e1',
        '49933ea5288caeca8642d1e84afbd3f7d6820020',
        '57a97c7e7821a5776cebc9bb87c984fa69cba8f1',
        '634f93cb2916e3fdff6788551b99b062d0335ce0',
        'c7c53464625b32c7a7e944ae62b3e17d2b600130',
        'c94ce9fb468520275223c153574b00df6fe4bcc9',
        'e468171a9de216ec08956ac3ada2f0791b6bd435',
        'ea165f8d65b6e75b540449e92b4886f43607fa02',
    ] as $deprecatedPin) {
        expect($workflowSources)->not->toContain($deprecatedPin);
    }
});
