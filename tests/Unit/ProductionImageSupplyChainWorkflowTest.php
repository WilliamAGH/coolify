<?php

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

function releaseWorkflowRepositoryRoot(): string
{
    return dirname(__DIR__, 2);
}

function releaseWorkflowTestDigest(string $character): string
{
    return 'sha256:'.str_repeat($character, 64);
}

function releaseWorkflowRegistryReferencePath(string $stateDirectory, string $reference): string
{
    return $stateDirectory.'/refs/'.hash('sha256', $reference);
}

function releaseWorkflowWriteRegistryReference(string $stateDirectory, string $reference, string $digest): void
{
    file_put_contents(releaseWorkflowRegistryReferencePath($stateDirectory, $reference), $digest."\n");
}

function releaseWorkflowReadRegistryReference(string $stateDirectory, string $reference): ?string
{
    $path = releaseWorkflowRegistryReferencePath($stateDirectory, $reference);

    return is_file($path) ? trim((string) file_get_contents($path)) : null;
}

/** @return list<string> */
function releaseWorkflowNeeds(array $job): array
{
    $needs = $job['needs'] ?? [];

    return is_array($needs) ? array_values($needs) : [$needs];
}

/** @return list<array<string, mixed>> */
function releaseWorkflowSteps(array $job): array
{
    return array_values(array_filter($job['steps'] ?? [], is_array(...)));
}

/** @return array<string, mixed> */
function releaseWorkflowStep(array $job, string $name): array
{
    foreach (releaseWorkflowSteps($job) as $step) {
        if (($step['name'] ?? null) === $name) {
            return $step;
        }
    }

    return [];
}

/** @return array<string, mixed> */
function releaseWorkflowStepById(array $job, string $id): array
{
    foreach (releaseWorkflowSteps($job) as $step) {
        if (($step['id'] ?? null) === $id) {
            return $step;
        }
    }

    return [];
}

function releaseWorkflowSelectionCaseBody(string $selectionScript, string $sourcePath): string
{
    $matched = preg_match(
        '/^[\\t ]*[^\\r\\n]*'.preg_quote($sourcePath, '/').'[^\\r\\n]*\\)[\\t ]*\\R(?<body>.*?)(?=^[\\t ]*;;)/ms',
        $selectionScript,
        $matches,
    );

    return $matched === 1 ? $matches['body'] : '';
}

/** @return list<string> */
function releaseWorkflowActionReferences(array $workflow): array
{
    $references = [];

    foreach ($workflow['jobs'] ?? [] as $job) {
        if (! is_array($job)) {
            continue;
        }

        if (is_string($job['uses'] ?? null)) {
            $references[] = $job['uses'];
        }

        foreach (releaseWorkflowSteps($job) as $step) {
            if (is_string($step['uses'] ?? null)) {
                $references[] = $step['uses'];
            }
        }
    }

    return $references;
}

/** @return list<string> */
function releaseWorkflowContainerReferences(array $workflow): array
{
    $references = [];

    foreach ($workflow['jobs'] ?? [] as $job) {
        if (! is_array($job)) {
            continue;
        }

        $container = $job['container'] ?? null;
        if (is_string($container)) {
            $references[] = $container;
        } elseif (is_string($container['image'] ?? null)) {
            $references[] = $container['image'];
        }

        foreach ($job['services'] ?? [] as $service) {
            if (is_string($service['image'] ?? null)) {
                $references[] = $service['image'];
            }
        }
    }

    return $references;
}

/** @return list<string> */
function releaseWorkflowScalarValues(mixed $value): array
{
    if (is_string($value)) {
        return [$value];
    }

    if (! is_array($value)) {
        return [];
    }

    $values = [];
    foreach ($value as $nestedValue) {
        $values = [...$values, ...releaseWorkflowScalarValues($nestedValue)];
    }

    return $values;
}

/** @return list<array{mutation: string, expected: string}> */
function releaseWorkflowNegativeCases(): array
{
    $fixture = Yaml::parseFile(
        releaseWorkflowRepositoryRoot().'/tests/Fixtures/supply-chain-workflow-negative-cases.yml',
    );
    $cases = $fixture['cases'] ?? [];

    return array_map(
        static fn (array $case): array => [
            'mutation' => (string) $case['mutation'],
            'expected' => (string) $case['expected'],
        ],
        $cases,
    );
}

/** @return list<string> */
function releaseWorkflowViolations(array $sharedWorkflow, array $applicationValidationWorkflow, array $callers): array
{
    $violations = [];
    $workflowCall = $sharedWorkflow['on']['workflow_call'] ?? null;
    $jobs = $sharedWorkflow['jobs'] ?? [];
    $applicationValidationEvents = $applicationValidationWorkflow['on'] ?? [];
    $applicationValidationJobs = $applicationValidationWorkflow['jobs'] ?? [];

    if (! is_array($applicationValidationEvents) || ! array_key_exists('workflow_call', $applicationValidationEvents)) {
        $violations[] = 'application validation workflow must be callable';
    }

    if (($applicationValidationWorkflow['permissions'] ?? null) !== ['contents' => 'read']) {
        $violations[] = 'application validation must use read-only repository permissions';
    }

    if (($applicationValidationWorkflow['concurrency']['group'] ?? null) !== 'application-validation-${{ github.event.pull_request.number || github.ref }}' ||
        ($applicationValidationWorkflow['concurrency']['cancel-in-progress'] ?? null) !== "\${{ github.event_name == 'pull_request' }}") {
        $violations[] = 'application validation must serialize pushes without cancellation while superseding stale pull requests';
    }

    $requiredApplicationValidationJobs = [
        'php',
        'browser',
        'formatting',
        'node',
        'workflow-and-shell',
        'postgres-redis',
        'integration-changes',
        'control-plane-migration',
        'control-plane-runtime-fence',
        'control-plane-backup-restore',
        'control-plane-blue-green-simulation',
        'application-deployment-blue-green',
        'production-application-blue-green',
    ];
    foreach ($requiredApplicationValidationJobs as $jobName) {
        if (! isset($applicationValidationJobs[$jobName])) {
            $violations[] = "missing application validation job: {$jobName}";
        }
    }

    $phpValidationEnvironment = $applicationValidationJobs['php']['env'] ?? [];
    foreach ([
        'APP_ENV' => 'testing',
        'CACHE_STORE' => 'array',
        'DB_CONNECTION' => 'testing',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
    ] as $name => $expectedValue) {
        if (($phpValidationEnvironment[$name] ?? null) !== $expectedValue) {
            $violations[] = "PHP application validation must set {$name}={$expectedValue}";
        }
    }

    $phpTestScript = (string) (releaseWorkflowStep(
        $applicationValidationJobs['php'] ?? [],
        'Run application tests',
    )['run'] ?? '');
    foreach (['--testsuite=Unit', '--testsuite=Feature', 'tests/v4/Feature'] as $backendSuite) {
        if (! str_contains($phpTestScript, $backendSuite)) {
            $violations[] = "PHP application validation is missing backend suite: {$backendSuite}";
        }
    }

    $redisFeatureTests = [
        'tests/Feature/AcceptedProxyMutationRemoteProcessTest.php',
        'tests/Feature/ProxyMutationQueueGateTest.php',
    ];
    if (! str_contains($phpTestScript, '--exclude-group=requires-redis')) {
        $violations[] = 'PHP application validation must exclude Redis-bound feature tests';
    }
    foreach ($redisFeatureTests as $redisFeatureTest) {
        $contents = file_get_contents(releaseWorkflowRepositoryRoot().'/'.$redisFeatureTest);
        if (! is_string($contents) || ! str_contains($contents, "pest()->group('requires-redis');")) {
            $violations[] = "Redis-bound feature test must declare the requires-redis group: {$redisFeatureTest}";
        }
    }

    if (str_contains($phpTestScript, 'tests/v4/Browser') ||
        trim($phpTestScript) === 'php artisan test --compact') {
        $violations[] = 'PHP application validation must not run browser tests in the composer-only job';
    }

    $browserSteps = collect(releaseWorkflowSteps($applicationValidationJobs['browser'] ?? []));
    foreach (['npm ci', 'npx playwright install --with-deps chromium', 'npm run build', 'php artisan test --compact tests/v4/Browser'] as $browserCommand) {
        if (! $browserSteps->contains(fn (array $step): bool => str_contains(
            (string) ($step['run'] ?? ''),
            $browserCommand,
        ))) {
            $violations[] = "browser validation is missing required setup or execution: {$browserCommand}";
        }
    }

    $requiredValidationNeeds = releaseWorkflowNeeds($applicationValidationJobs['required'] ?? []);
    sort($requiredValidationNeeds);
    sort($requiredApplicationValidationJobs);
    if ($requiredValidationNeeds !== $requiredApplicationValidationJobs ||
        ($applicationValidationJobs['required']['if'] ?? null) !== 'always()') {
        $violations[] = 'application validation must require every validation job';
    }

    $workflowAndShell = $applicationValidationJobs['workflow-and-shell'] ?? [];
    if (releaseWorkflowStep($workflowAndShell, 'Validate workflows') === [] ||
        releaseWorkflowStep($workflowAndShell, 'Verify pinned source provenance') === []) {
        $violations[] = 'application validation must verify workflow syntax and source provenance';
    }
    $immutableReferencesStep = releaseWorkflowStep(
        $workflowAndShell,
        'Require immutable external action and container references',
    );
    $immutableReferencesScript = (string) ($immutableReferencesStep['run'] ?? '');
    if (! str_contains($immutableReferencesScript, 'uses:') ||
        ! str_contains($immutableReferencesScript, 'image:') ||
        ! str_contains($immutableReferencesScript, '@sha256:')) {
        $violations[] = 'application validation must enforce immutable action and container references';
    }

    $postgresRedisJob = $applicationValidationJobs['postgres-redis'] ?? [];
    $postgresRedisEnvironment = $postgresRedisJob['env'] ?? [];
    foreach ([
        'DB_CONNECTION' => 'pgsql',
        'CACHE_STORE' => 'redis',
        'COOLIFY_EXTERNAL_TEST_SERVICES' => true,
        'REDIS_HOST' => '127.0.0.1',
        'REDIS_PORT' => 6379,
    ] as $name => $expectedValue) {
        if (($postgresRedisEnvironment[$name] ?? null) !== $expectedValue) {
            $violations[] = "PostgreSQL and Redis validation must set {$name} for its service-backed tests";
        }
    }
    $postgresRedisTestScript = (string) (releaseWorkflowStep(
        $postgresRedisJob,
        'Run PostgreSQL, Redis, concurrency, migration, lock, and queue tests',
    )['run'] ?? '');
    foreach ([
        ...$redisFeatureTests,
        'tests/Feature/PostgresBlueGreenTopologyConcurrencyTest.php',
    ] as $serviceBoundTest) {
        if (! str_contains($postgresRedisTestScript, $serviceBoundTest)) {
            $violations[] = "PostgreSQL and Redis validation must run service-bound test: {$serviceBoundTest}";
        }
    }

    $validationServices = $postgresRedisJob['services'] ?? [];
    foreach ([
        'postgres' => 'postgres:15-alpine@sha256:3d0f7584ed7d04e27fa050d6683a74746608faf21f202be78460d679cc56461f',
        'redis' => 'redis:7-alpine@sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99',
    ] as $service => $expectedImage) {
        if (($validationServices[$service]['image'] ?? null) !== $expectedImage) {
            $violations[] = "application validation service image must use its reviewed immutable digest: {$service}";
        }
    }
    $shellCheckScript = (string) (releaseWorkflowStep($workflowAndShell, 'ShellCheck changed shell scripts')['run'] ?? '');
    if (! str_contains($shellCheckScript, 'git cat-file -e') ||
        ! str_contains($shellCheckScript, '4b825dc642cb6eb9a060e54bf8d69288fbee4904')) {
        $violations[] = 'manual validation dispatches must resolve a valid ShellCheck comparison base';
    }
    foreach (['git diff --name-only -z', 'mapfile -d', '[[ -x "$path"', 'first_line', 'shellcheck -- "${scripts[@]}"'] as $shellCheckContract) {
        if (! str_contains($shellCheckScript, $shellCheckContract)) {
            $violations[] = "ShellCheck selection is missing its safe executable-script contract: {$shellCheckContract}";
        }
    }

    $integrationSelection = $applicationValidationJobs['integration-changes'] ?? [];
    $selectionScript = (string) (releaseWorkflowStep(
        $integrationSelection,
        'Select integration gates from changed paths',
    )['run'] ?? '');
    foreach ([
        'migration',
        'runtime-fence',
        'backup-restore',
        'blue-green-simulation',
        'application-deployment-blue-green',
        'production-application-blue-green',
    ] as $output) {
        if (! isset($integrationSelection['outputs'][$output]) ||
            ! str_contains($selectionScript, "echo \"{$output}=")) {
            $violations[] = "application validation must select the {$output} integration gate from changed paths";
        }
    }
    foreach ([
        'app/Contracts/ProxyMutation.php',
        'app/Jobs/ProxyMutationTask.php',
        'app/Providers/AppServiceProvider.php',
        'app/Support/ControlPlaneMode.php',
        'app/Support/ProxyMutation*',
        'config/control-plane.php',
        'config/horizon.php',
    ] as $runtimeFencePath) {
        if (! str_contains(
            releaseWorkflowSelectionCaseBody($selectionScript, $runtimeFencePath),
            'runtime_fence=true',
        )) {
            $violations[] = "runtime-fence selection is missing a control-plane boundary: {$runtimeFencePath}";
        }
    }
    foreach ([
        'tests/Integration/ApplicationDeploymentJobBlueGreen/*' => 'application_deployment_blue_green=true',
        'tests/Integration/ProductionApplicationBlueGreen/*' => 'production_application_blue_green=true',
    ] as $acceptancePath => $selectionAssignment) {
        if (! str_contains($selectionScript, $acceptancePath) ||
            ! str_contains($selectionScript, $selectionAssignment)) {
            $violations[] = "real blue/green acceptance selection is incomplete: {$acceptancePath}";
        }
    }

    foreach ([
        'control-plane-migration' => [
            'output' => 'migration',
            'entrypoint' => 'tests/Integration/ControlPlaneMigration/run.sh',
        ],
        'control-plane-runtime-fence' => [
            'output' => 'runtime-fence',
            'entrypoint' => 'tests/Integration/ControlPlaneRuntimeFence/run.sh',
        ],
        'control-plane-backup-restore' => [
            'output' => 'backup-restore',
            'entrypoint' => 'tests/Integration/ControlPlaneBackupRestore/run.sh',
        ],
        'control-plane-blue-green-simulation' => [
            'output' => 'blue-green-simulation',
            'entrypoint' => 'tests/Integration/ControlPlaneBlueGreen/run.sh',
        ],
        'application-deployment-blue-green' => [
            'output' => 'application-deployment-blue-green',
            'entrypoint' => 'tests/Integration/ApplicationDeploymentJobBlueGreen/run.sh',
        ],
        'production-application-blue-green' => [
            'output' => 'production-application-blue-green',
            'entrypoint' => 'tests/Integration/ProductionApplicationBlueGreen/run.sh',
        ],
    ] as $jobName => $contract) {
        $job = $applicationValidationJobs[$jobName] ?? [];
        $expectedCondition = "needs.integration-changes.outputs.{$contract['output']} == 'true'";
        $runsEntrypoint = collect(releaseWorkflowSteps($job))
            ->contains(fn (array $step): bool => str_contains(
                (string) ($step['run'] ?? ''),
                $contract['entrypoint'],
            ));

        if (releaseWorkflowNeeds($job) !== ['integration-changes'] ||
            ($job['if'] ?? null) !== $expectedCondition ||
            ! $runsEntrypoint) {
            $violations[] = "application validation integration gate is not mandatory when selected: {$jobName}";
        }
    }

    $blueGreenScenarios = $applicationValidationJobs['control-plane-blue-green-simulation']['strategy']['matrix']['scenario'] ?? [];
    sort($blueGreenScenarios);
    if ($blueGreenScenarios !== ['continuous-availability', 'queue-gate', 'routed-candidate-restart', 'router-reload-failure']) {
        $violations[] = 'control-plane blue/green simulation must cover queueing, rollback, routed restart, and continuous availability';
    }
    if (! str_contains(
        (string) ($applicationValidationJobs['control-plane-blue-green-simulation']['name'] ?? ''),
        'simulation',
    )) {
        $violations[] = 'mock control-plane blue/green validation must be labeled as a simulation';
    }
    foreach ([
        'application-deployment-blue-green' => 120,
        'production-application-blue-green' => 90,
    ] as $acceptanceJobName => $expectedTimeout) {
        $acceptanceJob = $applicationValidationJobs[$acceptanceJobName] ?? [];
        if (($acceptanceJob['timeout-minutes'] ?? null) !== $expectedTimeout) {
            $violations[] = "real blue/green acceptance gate must use its bounded timeout: {$acceptanceJobName}";
        }
    }

    $requiredValidationScript = (string) (releaseWorkflowStep(
        $applicationValidationJobs['required'] ?? [],
        'Require every validation job',
    )['run'] ?? '');
    foreach ([
        'control-plane-migration',
        'control-plane-runtime-fence',
        'control-plane-backup-restore',
        'control-plane-blue-green-simulation',
        'application-deployment-blue-green',
        'production-application-blue-green',
    ] as $jobName) {
        if (! str_contains($requiredValidationScript, "require_selected {$jobName}")) {
            $violations[] = "aggregate validation may ignore a selected integration gate: {$jobName}";
        }
    }

    if (! is_array($workflowCall)) {
        $violations[] = 'publish workflow must be callable';
    }

    foreach ([
        'release_kind',
        'dockerfile',
        'artifact_name',
        'candidate_repository',
        'target_repository',
        'semantic_version',
        'publish_latest',
        'validate_only',
    ] as $input) {
        if (! isset($workflowCall['inputs'][$input])) {
            $violations[] = "missing reusable input: {$input}";
        }
    }

    if (($workflowCall['inputs']['validate_only'] ?? null) !== [
        'default' => false,
        'required' => false,
        'type' => 'boolean',
    ]) {
        $violations[] = 'validate-only must be an explicit default-false reusable input';
    }

    foreach (['DOCKERHUB_USERNAME', 'DOCKERHUB_TOKEN'] as $secret) {
        if (($workflowCall['secrets'][$secret]['required'] ?? null) !== true) {
            $violations[] = "missing required reusable secret: {$secret}";
        }
    }

    foreach (['digest', 'docker_image', 'ghcr_image'] as $output) {
        if (! isset($workflowCall['outputs'][$output])) {
            $violations[] = "missing reusable output: {$output}";
        }
    }
    foreach ([
        'digest' => '${{ jobs.release.outputs.index_digest || jobs.repair-latest.outputs.index_digest || jobs.verify-authoritative-noop.outputs.index_digest }}',
        'docker_image' => '${{ jobs.release.outputs.docker_image || jobs.repair-latest.outputs.docker_image || jobs.verify-authoritative-noop.outputs.docker_image }}',
        'ghcr_image' => '${{ jobs.release.outputs.ghcr_image || jobs.repair-latest.outputs.ghcr_image || jobs.verify-authoritative-noop.outputs.ghcr_image }}',
    ] as $output => $expectedValue) {
        if (($workflowCall['outputs'][$output]['value'] ?? null) !== $expectedValue) {
            $violations[] = "reusable output must retain authoritative no-op fallback: {$output}";
        }
    }

    if (($sharedWorkflow['permissions'] ?? null) !== []) {
        $violations[] = 'shared publication must not grant workflow-wide permissions';
    }

    if (($sharedWorkflow['concurrency']['group'] ?? null) !== 'linux-image-${{ inputs.target_repository }}' ||
        ($sharedWorkflow['concurrency']['cancel-in-progress'] ?? null) !== false) {
        $violations[] = 'shared publication must serialize each target without cancellation';
    }

    foreach (['release', 'repair-latest'] as $aliasMutationJob) {
        if (($jobs[$aliasMutationJob]['concurrency']['group'] ?? null) !== 'linux-image-alias-mutation-${{ inputs.target_repository }}' ||
            ($jobs[$aliasMutationJob]['concurrency']['cancel-in-progress'] ?? null) !== false) {
            $violations[] = 'alias mutation jobs must serialize per target at the job level because workflow_call ignores workflow-level concurrency';
        }
    }

    $runTagStep = releaseWorkflowStepById($jobs['validate-inputs'] ?? [], 'target');
    $runTagScript = (string) ($runTagStep['run'] ?? '');
    $publicationStateStep = releaseWorkflowStepById($jobs['validate-inputs'] ?? [], 'publication');
    $publicationStateScript = (string) ($publicationStateStep['run'] ?? '');
    if (($jobs['validate-inputs']['outputs']['publish_required'] ?? null) !== '${{ steps.publication.outputs.publish_required }}' ||
        ($jobs['validate-inputs']['outputs']['docker_image'] ?? null) !== '${{ steps.publication.outputs.docker_image }}' ||
        ($jobs['validate-inputs']['outputs']['ghcr_image'] ?? null) !== '${{ steps.publication.outputs.ghcr_image }}' ||
        ! str_contains($publicationStateScript, 'ghcr_status=') ||
        ! str_contains($publicationStateScript, 'docker_status=') ||
        ! str_contains($publicationStateScript, '[Dd]ocker-[Cc]ontent-[Dd]igest') ||
        ! str_contains($publicationStateScript, '^200 sha256:') ||
        ! str_contains($publicationStateScript, 'Semantic tag presence differs across registries')) {
        $violations[] = 'serialized production publication must use matching authoritative registry tag state';
    }
    if (! str_contains($runTagScript, 'run_tag="sha-$GITHUB_SHA-run-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"') ||
        ! str_contains($runTagScript, "'^sha-[0-9a-f]{40}-run-[1-9][0-9]*-[1-9][0-9]*$'")) {
        $violations[] = 'immutable run tags must begin with the source commit and include the run provenance';
    }
    foreach (['validate_tag_length', '"${#tag}" -le 128', '"${run_tag}-amd64"', '"${run_tag}-arm64"', '"quarantine-${run_tag}"', '"$SEMANTIC_VERSION"'] as $tagLimitContract) {
        if (! str_contains($runTagScript, $tagLimitContract)) {
            $violations[] = 'all release tags must enforce the OCI 128-character limit before builds start';
        }
    }
    foreach ([
        "[ \"\$REPOSITORY\" = 'coollabsio/coolify' ]",
        '[ "$VALIDATE_ONLY" = false ]',
        '[ "$VALIDATE_ONLY" = true ]',
    ] as $validationModeContract) {
        if (! str_contains($runTagScript, $validationModeContract)) {
            $violations[] = 'production validation mode must fail closed for canonical and fork repositories';
        }
    }

    foreach ([
        'production' => ['coolify', 'docker/production/Dockerfile'],
        'staging' => ['coolify-staging', 'docker/production/Dockerfile'],
        'testing-host' => ['coolify-testing-host', 'docker/testing-host/Dockerfile'],
    ] as $releaseKind => [$artifactName, $dockerfile]) {
        foreach (["[ \"\$ARTIFACT_NAME\" = '{$artifactName}' ]", "[ \"\$DOCKERFILE\" = '{$dockerfile}' ]"] as $contract) {
            if (! str_contains($runTagScript, $contract)) {
                $violations[] = "{$releaseKind} must validate its exact artifact name and Dockerfile";
            }
        }
    }

    $requiredGraph = [
        'validate-inputs' => [],
        'repair-latest' => ['validate-inputs'],
        'verify-authoritative-noop' => ['validate-inputs'],
        'build-and-scan' => ['validate-inputs'],
        'stage-candidates' => ['validate-inputs', 'build-and-scan'],
        'attest-and-verify' => ['stage-candidates'],
        'release' => ['stage-candidates', 'attest-and-verify'],
        'cleanup' => ['validate-inputs', 'stage-candidates', 'attest-and-verify', 'release'],
    ];
    foreach ($requiredGraph as $jobName => $expectedNeeds) {
        if (! isset($jobs[$jobName])) {
            $violations[] = "missing shared lifecycle job: {$jobName}";

            continue;
        }

        $actualNeeds = releaseWorkflowNeeds($jobs[$jobName]);
        sort($actualNeeds);
        sort($expectedNeeds);
        if ($actualNeeds !== $expectedNeeds) {
            $violations[] = "shared lifecycle dependency is invalid: {$jobName}";
        }
    }

    foreach ([
        'validate-inputs' => [],
        'repair-latest' => ['contents' => 'read', 'packages' => 'write'],
        'verify-authoritative-noop' => ['packages' => 'read'],
        'build-and-scan' => ['contents' => 'read'],
        'stage-candidates' => ['contents' => 'read', 'packages' => 'write'],
        'attest-and-verify' => [
            'artifact-metadata' => 'write',
            'attestations' => 'write',
            'contents' => 'read',
            'id-token' => 'write',
            'packages' => 'write',
        ],
        'release' => ['contents' => 'read', 'packages' => 'write'],
        'cleanup' => ['contents' => 'read', 'packages' => 'write'],
    ] as $jobName => $expectedPermissions) {
        if (isset($jobs[$jobName]) && ($jobs[$jobName]['permissions'] ?? null) !== $expectedPermissions) {
            $violations[] = "shared lifecycle permissions are invalid: {$jobName}";
        }
    }

    $platforms = array_column($jobs['build-and-scan']['strategy']['matrix']['include'] ?? [], 'platform');
    sort($platforms);
    if ($platforms !== ['linux/amd64', 'linux/arm64']) {
        $violations[] = 'shared build must publish only linux amd64 and arm64';
    }

    $validateOnlyConditions = [
        'repair-latest' => "\${{ ! inputs.validate_only && needs.validate-inputs.outputs.repair_required == 'true' }}",
        'verify-authoritative-noop' => "\${{ ! inputs.validate_only && needs.validate-inputs.outputs.publish_required == 'false' && needs.validate-inputs.outputs.repair_required == 'false' }}",
        'stage-candidates' => "\${{ ! inputs.validate_only && needs.validate-inputs.outputs.publish_required == 'true' }}",
        'attest-and-verify' => "\${{ ! inputs.validate_only && needs.stage-candidates.result == 'success' }}",
        'release' => "\${{ ! inputs.validate_only && needs.stage-candidates.result == 'success' }}",
        'cleanup' => "\${{ always() && ! inputs.validate_only && needs.validate-inputs.outputs.publish_required == 'true' }}",
    ];
    foreach ($validateOnlyConditions as $jobName => $expectedCondition) {
        if (($jobs[$jobName]['if'] ?? null) !== $expectedCondition) {
            $violations[] = "validate-only may reach external publication job: {$jobName}";
        }
    }
    if (($jobs['build-and-scan']['if'] ?? null) !== "\${{ inputs.validate_only || needs.validate-inputs.outputs.publish_required == 'true' }}") {
        $violations[] = 'validate-only must retain the complete build-and-scan job';
    }
    $requiredValidateOnlyProductionSteps = [
        'Run native systemd host gate against exact OCI child' => "inputs.release_kind == 'production' && matrix.arch == 'amd64'",
        'Package sanitized production host-gate evidence' => "always() && inputs.release_kind == 'production' && matrix.arch == 'amd64'",
        'Upload sanitized production host-gate evidence' => "always() && inputs.release_kind == 'production' && matrix.arch == 'amd64'",
        'Require native production host-gate success' => "always() && inputs.release_kind == 'production' && matrix.arch == 'amd64'",
    ];
    foreach ($requiredValidateOnlyProductionSteps as $stepName => $expectedCondition) {
        $step = releaseWorkflowStep($jobs['build-and-scan'] ?? [], $stepName);
        if ($step === [] || ($step['if'] ?? null) !== $expectedCondition ||
            str_contains((string) ($step['if'] ?? ''), 'validate_only')) {
            $violations[] = "validate-only must retain the exact production host-gate evidence step: {$stepName}";
        }
    }
    $nativeHostGateRun = (string) (releaseWorkflowStep(
        $jobs['build-and-scan'] ?? [],
        'Run native systemd host gate against exact OCI child',
    )['run'] ?? '');
    if (! str_contains($nativeHostGateRun, '[[ $GITHUB_EVENT_NAME == push && $GITHUB_REF == refs/heads/v4.x ]]') ||
        ! str_contains($nativeHostGateRun, "fail 'the privileged production host gate requires a push to refs/heads/v4.x'")) {
        $violations[] = 'validate-only native production host gate must retain the trusted push and v4.x runtime guard';
    }
    $evidencePackageStep = releaseWorkflowStep(
        $jobs['build-and-scan'] ?? [],
        'Package sanitized production host-gate evidence',
    );
    $evidenceUploadStep = releaseWorkflowStep(
        $jobs['build-and-scan'] ?? [],
        'Upload sanitized production host-gate evidence',
    );
    $evidenceRequireStep = releaseWorkflowStep(
        $jobs['build-and-scan'] ?? [],
        'Require native production host-gate success',
    );
    if (($evidencePackageStep['id'] ?? null) !== 'package-production-host-gate-evidence' ||
        ($evidenceUploadStep['id'] ?? null) !== 'upload-production-host-gate-evidence' ||
        ($evidenceRequireStep['env']['HOST_GATE_OUTCOME'] ?? null) !== '${{ steps.production-host-gate.outcome }}' ||
        ($evidenceRequireStep['env']['PACKAGE_OUTCOME'] ?? null) !== '${{ steps.package-production-host-gate-evidence.outcome }}' ||
        ($evidenceRequireStep['env']['EVIDENCE_ARTIFACT_ID'] ?? null) !== '${{ steps.upload-production-host-gate-evidence.outputs.artifact-id }}') {
        $violations[] = 'validate-only production host-gate evidence chain is weakened or disconnected';
    }
    foreach ($jobs as $jobName => $job) {
        $hasRegistryLogin = collect(releaseWorkflowSteps($job))
            ->contains(fn (array $step): bool => str_starts_with(
                (string) ($step['uses'] ?? ''),
                'docker/login-action@',
            ));
        if ($hasRegistryLogin && ! isset($validateOnlyConditions[$jobName])) {
            $violations[] = "validate-only may reach an external registry login: {$jobName}";
        }
    }

    foreach ($jobs as $job) {
        foreach (releaseWorkflowSteps($job) as $step) {
            if (str_contains((string) ($step['run'] ?? ''), '${{ inputs.')) {
                $violations[] = 'reusable workflow inputs may not be interpolated directly into shell scripts';
            }
        }
    }

    foreach ([
        'Stage candidates by verified digest',
        'Create or verify immutable run tags',
        'Publish semantic and latest aliases atomically',
        'Delete candidate and quarantine tags only',
    ] as $stepName) {
        $jobName = match ($stepName) {
            'Stage candidates by verified digest' => 'stage-candidates',
            'Delete candidate and quarantine tags only' => 'cleanup',
            default => 'release',
        };
        if (releaseWorkflowStep($jobs[$jobName] ?? [], $stepName) === []) {
            $violations[] = "missing lifecycle step: {$stepName}";
        }
    }

    $runtimeGateStep = releaseWorkflowStep($jobs['build-and-scan'] ?? [], 'Run passive control-plane runtime gate');
    if ($runtimeGateStep === []) {
        $violations[] = 'missing passive control-plane runtime gate in build-and-scan';
    } else {
        if (($runtimeGateStep['if'] ?? null) !== "inputs.release_kind == 'production' && matrix.arch == 'amd64'") {
            $violations[] = 'passive runtime gate must run for production amd64 builds';
        }
        $runtimeGateRun = (string) ($runtimeGateStep['run'] ?? '');
        if (! str_contains($runtimeGateRun, 'tests/Integration/VerifyOciArchiveImage.sh') ||
            ! str_contains($runtimeGateRun, 'PREFLIGHT_IMAGE="$image" tests/Integration/PassiveControlPlaneImageTest.sh') ||
            str_contains($runtimeGateRun, 'docker load --input')) {
            $violations[] = 'passive runtime gate must verify and load the exact OCI archive before testing';
        }
    }

    $buildSteps = releaseWorkflowSteps($jobs['build-and-scan'] ?? []);
    $testingHostGateIndex = array_search('Verify the exact testing-host OCI archive and runtime', array_column($buildSteps, 'name'), true);
    $evidenceUploadIndex = array_search('Retain gated OCI evidence', array_column($buildSteps, 'name'), true);
    $testingHostGateRun = (string) (releaseWorkflowStep(
        $jobs['build-and-scan'] ?? [],
        'Verify the exact testing-host OCI archive and runtime',
    )['run'] ?? '');
    if (! is_int($testingHostGateIndex) || ! is_int($evidenceUploadIndex) ||
        $testingHostGateIndex >= $evidenceUploadIndex ||
        ! str_contains($testingHostGateRun, 'tests/Integration/VerifyOciArchiveImage.sh') ||
        ! str_contains($testingHostGateRun, 'tests/Integration/TestingHostImageTest.sh') ||
        str_contains($testingHostGateRun, '. "$candidate"')) {
        $violations[] = 'testing-host must verify the exact OCI archive and runtime before evidence upload';
    }

    $aliasPublicationStep = releaseWorkflowStep(
        $jobs['release'] ?? [],
        'Publish semantic and latest aliases atomically',
    );
    $aliasPublicationRun = (string) ($aliasPublicationStep['run'] ?? '');
    if (! str_contains($aliasPublicationRun, 'ensure-pair')) {
        $violations[] = 'semantic tags must use a preflighted cross-registry publisher';
    }
    foreach ([
        'verify-index "$GHCR_TARGET"',
        'verify-index "$DOCKER_TARGET"',
        'ghcr_semantic_before=',
        'docker_semantic_before=',
        'ghcr_latest_before=',
        'docker_latest_before=',
        'restore_alias_if_owned',
        'trap compensate_aliases EXIT',
        'preflight-latest',
        'promote-latest',
    ] as $atomicAliasContract) {
        if (! str_contains($aliasPublicationRun, $atomicAliasContract)) {
            $violations[] = 'semantic and latest aliases must publish as one preflighted compensation-aware operation';
        }
    }
    if (releaseWorkflowStep($jobs['release'] ?? [], 'Create or verify semantic version tags') !== [] ||
        releaseWorkflowStep($jobs['release'] ?? [], 'Promote latest monotonically across registries') !== []) {
        $violations[] = 'semantic and latest aliases must publish as one preflighted compensation-aware operation';
    }
    $latestPreflightIndex = strpos($aliasPublicationRun, 'preflight-latest');
    $mutationStartedIndex = strpos($aliasPublicationRun, 'mutation_started=true');
    $semanticPublishIndex = strpos($aliasPublicationRun, 'ensure-pair');
    if (! is_int($latestPreflightIndex) || ! is_int($mutationStartedIndex) || ! is_int($semanticPublishIndex) ||
        $latestPreflightIndex >= $mutationStartedIndex || $latestPreflightIndex >= $semanticPublishIndex) {
        $violations[] = 'latest monotonicity must be preflighted before any semantic alias mutation';
    }

    $runTagStep = releaseWorkflowStep($jobs['release'] ?? [], 'Create or verify immutable run tags');
    if (! str_contains((string) ($runTagStep['run'] ?? ''), 'ensure-pair')) {
        $violations[] = 'immutable run tags must use a preflighted cross-registry publisher';
    }

    $attestationSteps = array_filter(
        releaseWorkflowSteps($jobs['attest-and-verify'] ?? []),
        fn (array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), 'actions/attest@'),
    );
    if (count($attestationSteps) !== 6) {
        $violations[] = 'both registries must receive child and index attestations';
    }

    $attestationJobSteps = releaseWorkflowSteps($jobs['attest-and-verify'] ?? []);
    $attestationNames = array_column($attestationJobSteps, 'name');
    $ghcrLoginIndex = array_search('Login to GHCR', $attestationNames, true);
    $dockerLoginIndex = array_search('Login to Docker Hub', $attestationNames, true);
    $firstAttestationIndex = null;
    foreach ($attestationJobSteps as $index => $step) {
        if (str_starts_with((string) ($step['uses'] ?? ''), 'actions/attest@')) {
            $firstAttestationIndex = $index;
            break;
        }
    }
    if (! is_int($ghcrLoginIndex) || ! is_int($dockerLoginIndex) || ! is_int($firstAttestationIndex) ||
        $ghcrLoginIndex >= $firstAttestationIndex || $dockerLoginIndex >= $firstAttestationIndex) {
        $violations[] = 'attestation jobs must authenticate to both registries before attesting';
    }

    $cleanupSteps = $jobs['cleanup'] ?? [];
    $cleanupChecksum = (string) (releaseWorkflowStep($cleanupSteps, 'Verify registry client bytes')['run'] ?? '');
    $cleanupRun = (string) (releaseWorkflowStep($cleanupSteps, 'Delete candidate and quarantine tags only')['run'] ?? '');
    if (! str_contains($cleanupChecksum, 'sha256sum --check --strict') ||
        ! str_contains($cleanupRun, "'^sha-[0-9a-f]{40}-run-[1-9][0-9]*-[1-9][0-9]*$'")) {
        $violations[] = 'cleanup must verify regctl bytes and reject an invalid run tag';
    }

    $attestationVerificationStep = releaseWorkflowStep(
        $jobs['attest-and-verify'] ?? [],
        'Verify both registry attestations',
    );
    $attestationVerificationRun = (string) ($attestationVerificationStep['run'] ?? '');
    $reusableWorkflowSigner = '--signer-workflow "${GITHUB_REPOSITORY}/.github/workflows/publish-linux-image.yml"';
    if (! str_contains($attestationVerificationRun, $reusableWorkflowSigner) ||
        str_contains($attestationVerificationRun, '--signer-workflow "$GITHUB_WORKFLOW_REF"')) {
        $violations[] = 'attestation verification must require the reusable workflow signer';
    }

    foreach (['--repo "$GITHUB_REPOSITORY"', '--source-digest "$GITHUB_SHA"', '--source-ref "$GITHUB_REF"'] as $requiredVerificationArgument) {
        if (! str_contains($attestationVerificationRun, $requiredVerificationArgument)) {
            $violations[] = 'attestation verification must retain caller repository and source provenance checks';
        }
    }

    $publicationPermissions = [
        'artifact-metadata' => 'write',
        'attestations' => 'write',
        'contents' => 'read',
        'id-token' => 'write',
        'packages' => 'write',
    ];

    foreach ([
        'production' => [
            'inputs' => [
                'artifact_name' => 'coolify',
                'target_repository' => 'coollabsio/coolify',
                'candidate_repository' => 'coollabsio/coolify-production-staging',
                'dockerfile' => 'docker/production/Dockerfile',
                'release_kind' => 'production',
                'semantic_version' => '${{ needs.resolve-version.outputs.version }}',
                'publish_latest' => true,
                'validate_only' => "\${{ github.repository != 'coollabsio/coolify' }}",
            ],
            'publish_if' => "\${{ needs.resolve-version.outputs.should_publish == 'true' }}",
            'publish_needs' => ['resolve-version'],
            'jobs' => ['application-validation', 'resolve-version', 'publish'],
        ],
        'testing-host' => [
            'inputs' => [
                'artifact_name' => 'coolify-testing-host',
                'target_repository' => 'coollabsio/coolify-testing-host',
                'candidate_repository' => 'coollabsio/coolify-testing-host-staging',
                'dockerfile' => 'docker/testing-host/Dockerfile',
                'release_kind' => 'testing-host',
                'semantic_version' => '',
                'publish_latest' => true,
            ],
            'publish_if' => null,
            'publish_needs' => ['application-validation'],
            'jobs' => ['application-validation', 'authorize', 'publish'],
        ],
        'staging' => [
            'inputs' => [
                'artifact_name' => 'coolify-staging',
                'target_repository' => 'coollabsio/coolify-staging',
                'candidate_repository' => 'coollabsio/coolify-staging-candidates',
                'dockerfile' => 'docker/production/Dockerfile',
                'release_kind' => 'staging',
                'semantic_version' => '',
                'publish_latest' => false,
            ],
            'publish_if' => null,
            'publish_needs' => ['application-validation'],
            'jobs' => ['application-validation', 'authorize', 'publish'],
        ],
    ] as $callerName => $expectedContract) {
        $caller = $callers[$callerName] ?? [];
        $publish = $caller['jobs']['publish'] ?? [];
        $expectedInputs = $expectedContract['inputs'];
        $expectedJobs = $expectedContract['jobs'];
        $actualJobs = array_keys($caller['jobs'] ?? []);
        sort($actualJobs);
        sort($expectedJobs);

        if ($actualJobs !== $expectedJobs) {
            $violations[] = "{$callerName} must keep lifecycle jobs in the shared publication workflow";
        }

        if (($caller['permissions'] ?? null) !== []) {
            $violations[] = "{$callerName} must not grant workflow-wide permissions";
        }

        if (array_key_exists('concurrency', $caller)) {
            $violations[] = "{$callerName} must leave target serialization to the shared publication workflow";
        }

        if (($publish['uses'] ?? null) !== './.github/workflows/publish-linux-image.yml') {
            $violations[] = "{$callerName} must use the shared publish workflow";

            continue;
        }

        foreach ($expectedInputs as $input => $expectedValue) {
            if (($publish['with'][$input] ?? null) !== $expectedValue) {
                $violations[] = "{$callerName} has an invalid {$input}";
            }
        }

        if (releaseWorkflowNeeds($publish) !== $expectedContract['publish_needs']) {
            $violations[] = "{$callerName} publish dependency is invalid";
        }

        if (($publish['if'] ?? null) !== $expectedContract['publish_if']) {
            $violations[] = "{$callerName} has an invalid publication condition";
        }

        $expectedSecrets = [
            'DOCKERHUB_TOKEN' => '${{ secrets.DOCKERHUB_TOKEN }}',
            'DOCKERHUB_USERNAME' => '${{ secrets.DOCKERHUB_USERNAME }}',
        ];
        if (($publish['permissions'] ?? null) !== $publicationPermissions ||
            ($publish['secrets'] ?? null) !== $expectedSecrets) {
            $violations[] = "{$callerName} must pass only the required publication permissions and Docker Hub secrets";
        }

    }

    $productionJobs = $callers['production']['jobs'] ?? [];
    if (($productionJobs['application-validation']['uses'] ?? null) !== './.github/workflows/application-validation.yml' ||
        releaseWorkflowNeeds($productionJobs['application-validation'] ?? []) !== [] ||
        ($productionJobs['application-validation']['permissions'] ?? null) !== ['contents' => 'read'] ||
        releaseWorkflowNeeds($productionJobs['resolve-version'] ?? []) !== ['application-validation'] ||
        releaseWorkflowNeeds($productionJobs['publish'] ?? []) !== ['resolve-version']) {
        $violations[] = 'production publication must wait for application validation';
    }

    $versionStep = releaseWorkflowStep($productionJobs['resolve-version'] ?? [], 'Read semantic version from bootstrap');
    if (($productionJobs['resolve-version']['permissions'] ?? null) !== ['contents' => 'read'] ||
        ($productionJobs['resolve-version']['outputs']['should_publish'] ?? null) !== '${{ steps.version.outputs.should_publish }}' ||
        ! str_contains((string) ($versionStep['run'] ?? ''), 'php bootstrap/getVersion.php') ||
        ($versionStep['env']['BEFORE_SHA'] ?? null) !== '${{ github.event.before }}' ||
        ! str_contains((string) ($versionStep['run'] ?? ''), 'version_compare') ||
        ! str_contains((string) ($versionStep['run'] ?? ''), 'should_publish=false')) {
        $violations[] = 'production semantic publication must require an explicit increasing version bump';
    }

    $testingHostJobs = $callers['testing-host']['jobs'] ?? [];
    if (($testingHostJobs['application-validation']['uses'] ?? null) !== './.github/workflows/application-validation.yml' ||
        releaseWorkflowNeeds($testingHostJobs['application-validation'] ?? []) !== ['authorize'] ||
        ($testingHostJobs['application-validation']['permissions'] ?? null) !== ['contents' => 'read'] ||
        releaseWorkflowNeeds($testingHostJobs['publish'] ?? []) !== ['application-validation']) {
        $violations[] = 'testing-host publication must wait for application validation';
    }

    $stagingJobs = $callers['staging']['jobs'] ?? [];
    if (($stagingJobs['application-validation']['uses'] ?? null) !== './.github/workflows/application-validation.yml' ||
        releaseWorkflowNeeds($stagingJobs['application-validation'] ?? []) !== ['authorize'] ||
        ($stagingJobs['application-validation']['permissions'] ?? null) !== ['contents' => 'read'] ||
        releaseWorkflowNeeds($stagingJobs['publish'] ?? []) !== ['application-validation']) {
        $violations[] = 'staging publication must wait for canonical repository authorization and application validation';
    }

    $stagingAuthorizeJob = $stagingJobs['authorize'] ?? [];
    $stagingAuthorizeStep = releaseWorkflowStep($stagingAuthorizeJob, 'Require the canonical repository');
    $stagingAuthorizeRun = (string) ($stagingAuthorizeStep['run'] ?? '');
    if (releaseWorkflowNeeds($stagingAuthorizeJob) !== [] ||
        ($stagingAuthorizeJob['permissions'] ?? null) !== [] ||
        ($stagingAuthorizeStep['env']['REPOSITORY'] ?? null) !== '${{ github.repository }}' ||
        ! str_contains($stagingAuthorizeRun, "[ \"\$REPOSITORY\" = 'coollabsio/coolify' ]")) {
        $violations[] = 'staging publication must authorize the canonical repository before privileged publication';
    }

    $authorizeJob = $testingHostJobs['authorize'] ?? [];
    $authorizeStep = releaseWorkflowStep($authorizeJob, 'Require the canonical repository and branch');
    $authorizeRun = (string) ($authorizeStep['run'] ?? '');
    if (releaseWorkflowNeeds($authorizeJob) !== [] ||
        ($authorizeJob['permissions'] ?? null) !== [] ||
        ! str_contains($authorizeRun, "[ \"\$REPOSITORY\" = 'coollabsio/coolify' ]") ||
        ! str_contains($authorizeRun, "[ \"\$REF\" = 'refs/heads/next' ]")) {
        $violations[] = 'testing-host publication must authorize the canonical repository and next branch';
    }

    foreach ([$sharedWorkflow, $applicationValidationWorkflow, ...array_values($callers)] as $workflow) {
        foreach (releaseWorkflowActionReferences($workflow) as $reference) {
            if (str_starts_with($reference, './')) {
                continue;
            }

            if (preg_match('/^[^\/@\s]+(?:\/[^@\s]+)+@[a-f0-9]{40}$/', $reference) !== 1) {
                $violations[] = 'every external action must use a full commit SHA';
            }
        }

        foreach (releaseWorkflowContainerReferences($workflow) as $reference) {
            if (preg_match('/^[^@\s]+@sha256:[a-f0-9]{64}$/', $reference) !== 1) {
                $violations[] = 'every workflow service and job container image must use an immutable digest';
            }
        }

        foreach (releaseWorkflowScalarValues($workflow) as $scalar) {
            if (str_contains(strtolower($scalar), 'windows') || str_contains(strtolower($scalar), 'template')) {
                $violations[] = 'Linux publication workflows may not couple to unrelated platform or template artifacts';
            }
        }
    }

    return array_values(array_unique($violations));
}

/** @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>} */
function mutateReleaseWorkflow(array $sharedWorkflow, array $callers, string $mutation): array
{
    return match ($mutation) {
        'remove-production-validation' => (function () use ($sharedWorkflow, $callers): array {
            $callers['production']['jobs']['resolve-version']['needs'] = [];

            return [$sharedWorkflow, $callers];
        })(),
        'testing-host-targets-production' => (function () use ($sharedWorkflow, $callers): array {
            $callers['testing-host']['jobs']['publish']['with']['target_repository'] = 'coollabsio/coolify';

            return [$sharedWorkflow, $callers];
        })(),
        'staging-publishes-latest' => (function () use ($sharedWorkflow, $callers): array {
            $callers['staging']['jobs']['publish']['with']['publish_latest'] = true;

            return [$sharedWorkflow, $callers];
        })(),
        'staging-targets-production' => (function () use ($sharedWorkflow, $callers): array {
            $callers['staging']['jobs']['publish']['with']['target_repository'] = 'coollabsio/coolify';

            return [$sharedWorkflow, $callers];
        })(),
        'cancel-target-publication' => (function () use ($sharedWorkflow, $callers): array {
            $sharedWorkflow['concurrency']['cancel-in-progress'] = true;

            return [$sharedWorkflow, $callers];
        })(),
        'add-caller-target-concurrency' => (function () use ($sharedWorkflow, $callers): array {
            $callers['production']['concurrency'] = [
                'group' => 'linux-image-coollabsio-coolify',
                'cancel-in-progress' => false,
            ];

            return [$sharedWorkflow, $callers];
        })(),
        'drop-alias-mutation-serialization' => (function () use ($sharedWorkflow, $callers): array {
            unset($sharedWorkflow['jobs']['release']['concurrency']);

            return [$sharedWorkflow, $callers];
        })(),
        'remove-immutable-run-tags' => (function () use ($sharedWorkflow, $callers): array {
            foreach ($sharedWorkflow['jobs']['release']['steps'] as $index => $step) {
                if (($step['name'] ?? null) === 'Create or verify immutable run tags') {
                    unset($sharedWorkflow['jobs']['release']['steps'][$index]);
                }
            }

            return [$sharedWorkflow, $callers];
        })(),
        'unprovenanced-run-tags' => (function () use ($sharedWorkflow, $callers): array {
            foreach ($sharedWorkflow['jobs']['validate-inputs']['steps'] as $index => $step) {
                if (($step['id'] ?? null) === 'target') {
                    $sharedWorkflow['jobs']['validate-inputs']['steps'][$index]['run'] = str_replace(
                        'run_tag="sha-$GITHUB_SHA-run-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"',
                        'run_tag="run-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"',
                        (string) ($step['run'] ?? ''),
                    );
                }
            }

            return [$sharedWorkflow, $callers];
        })(),
        'caller-workflow-signer' => (function () use ($sharedWorkflow, $callers): array {
            foreach ($sharedWorkflow['jobs']['attest-and-verify']['steps'] as $index => $step) {
                if (($step['name'] ?? null) === 'Verify both registry attestations') {
                    $sharedWorkflow['jobs']['attest-and-verify']['steps'][$index]['run'] = str_replace(
                        '--signer-workflow "${GITHUB_REPOSITORY}/.github/workflows/publish-linux-image.yml"',
                        '--signer-workflow "$GITHUB_WORKFLOW_REF"',
                        (string) ($step['run'] ?? ''),
                    );
                }
            }

            return [$sharedWorkflow, $callers];
        })(),
        'semantic-tags-sequential' => (function () use ($sharedWorkflow, $callers): array {
            foreach ($sharedWorkflow['jobs']['release']['steps'] as $index => $step) {
                if (($step['name'] ?? null) === 'Publish semantic and latest aliases atomically') {
                    $sharedWorkflow['jobs']['release']['steps'][$index]['run'] = str_replace(
                        'ensure-pair',
                        'ensure-tag',
                        (string) ($step['run'] ?? ''),
                    );
                }
            }

            return [$sharedWorkflow, $callers];
        })(),
        'make-checkout-mutable' => (function () use ($sharedWorkflow, $callers): array {
            foreach ($sharedWorkflow['jobs']['build-and-scan']['steps'] as &$step) {
                if (($step['uses'] ?? null) === 'actions/checkout@93cb6efe18208431cddfb8368fd83d5badbf9bfd') {
                    $step['uses'] = 'actions/checkout@v5';
                    break;
                }
            }
            unset($step);

            return [$sharedWorkflow, $callers];
        })(),
        default => throw new InvalidArgumentException("Unknown release workflow mutation: {$mutation}"),
    };
}

it('enforces the shared Linux publication graph and caller boundaries', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $applicationValidationWorkflow = Yaml::parseFile($root.'/.github/workflows/application-validation.yml');
    $callers = [
        'production' => Yaml::parseFile($root.'/.github/workflows/coolify-production-build.yml'),
        'testing-host' => Yaml::parseFile($root.'/.github/workflows/coolify-testing-host.yml'),
        'staging' => Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml'),
    ];

    expect(releaseWorkflowViolations($sharedWorkflow, $applicationValidationWorkflow, $callers))->toBe([]);
});

it('fails closed across canonical publication and fork validation modes', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $targetStep = releaseWorkflowStepById($sharedWorkflow['jobs']['validate-inputs'] ?? [], 'target');
    $script = (string) ($targetStep['run'] ?? '');
    $semanticVersionPattern = (string) ($targetStep['env']['SEMANTIC_VERSION_PATTERN'] ?? '');
    $cases = [
        ['coollabsio/coolify', 'false', true],
        ['coollabsio/coolify', 'true', false],
        ['example/coolify-fork', 'true', true],
        ['example/coolify-fork', 'false', false],
    ];

    foreach ($cases as [$repository, $validateOnly, $shouldSucceed]) {
        $githubOutput = tempnam(sys_get_temp_dir(), 'coolify-release-target-');
        expect($githubOutput)->not->toBeFalse();

        try {
            $process = new Process(['bash', '-c', $script], $root, [
                'ARTIFACT_NAME' => 'coolify',
                'CANDIDATE_REPOSITORY' => 'coollabsio/coolify-production-staging',
                'DOCKERFILE' => 'docker/production/Dockerfile',
                'GITHUB_OUTPUT' => $githubOutput,
                'GITHUB_RUN_ATTEMPT' => '1',
                'GITHUB_RUN_ID' => '1',
                'GITHUB_SHA' => str_repeat('a', 40),
                'PUBLISH_LATEST' => 'true',
                'RELEASE_KIND' => 'production',
                'REPOSITORY' => $repository,
                'SEMANTIC_VERSION' => '4.0.0',
                'SEMANTIC_VERSION_PATTERN' => $semanticVersionPattern,
                'TARGET_REPOSITORY' => 'coollabsio/coolify',
                'VALIDATE_ONLY' => $validateOnly,
            ]);
            $process->run();

            expect($process->isSuccessful())->toBe(
                $shouldSucceed,
                "repository={$repository} validate_only={$validateOnly}",
            );
        } finally {
            unlink($githubOutput);
        }
    }
});

it('distinguishes absent semantic tags from registry disagreement and transport failures', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $publicationStep = releaseWorkflowStepById($sharedWorkflow['jobs']['validate-inputs'] ?? [], 'publication');
    $script = (string) ($publicationStep['run'] ?? '');
    $filesystem = new Filesystem;
    $fixture = sys_get_temp_dir().'/coolify-registry-state-'.bin2hex(random_bytes(8));
    $mockBin = $fixture.'/bin';
    $filesystem->mkdir([$mockBin, $fixture.'/runner']);
    $curl = <<<'SH'
#!/bin/sh
set -eu
output=
headers=
url=
arguments=" $* "
for contract in '--connect-timeout 10' '--max-time 30' '--retry 3' '--retry-all-errors' '--retry-delay 1'; do
    case "$arguments" in
        *" $contract "*) ;;
        *) printf 'bounded retry contract missing: %s\n' "$contract" >&2; exit 64 ;;
    esac
done
while [ "$#" -gt 0 ]; do
    case "$1" in
        --output) output=$2; shift 2 ;;
        --dump-header) headers=$2; shift 2 ;;
        http*) url=$1; shift ;;
        *) shift ;;
    esac
done
case "$url" in
    *'/token?'*) printf '{"token":"fixture-token"}\n' ;;
    *ghcr.io/v2/*/manifests/latest)
        [ "${GHCR_LATEST_STATUS:?}" != network ] || exit 7
        if [ "$GHCR_LATEST_STATUS" = 404 ]; then printf '%s\n' "${GHCR_404_BODY:-$DEFAULT_404_BODY}" > "${output:?}"; else : > "${output:?}"; fi
        printf 'Docker-Content-Digest: %s\r\n' "${GHCR_LATEST_DIGEST:-sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa}" > "${headers:?}"
        printf '%s' "$GHCR_LATEST_STATUS"
        ;;
    *registry-1.docker.io/v2/*/manifests/latest)
        [ "${DOCKER_LATEST_STATUS:?}" != network ] || exit 7
        if [ "$DOCKER_LATEST_STATUS" = 404 ]; then printf '%s\n' "${DOCKER_404_BODY:-$DEFAULT_404_BODY}" > "${output:?}"; else : > "${output:?}"; fi
        printf 'Docker-Content-Digest: %s\r\n' "${DOCKER_LATEST_DIGEST:-sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa}" > "${headers:?}"
        printf '%s' "$DOCKER_LATEST_STATUS"
        ;;
    *ghcr.io/v2/*)
        [ "${GHCR_STATUS:?}" != network ] || exit 7
        [ "$GHCR_STATUS" != timeout ] || exit 28
        if [ "$GHCR_STATUS" = 404 ]; then printf '%s\n' "${GHCR_404_BODY:-$DEFAULT_404_BODY}" > "${output:?}"; else : > "${output:?}"; fi
        printf 'Docker-Content-Digest: %s\r\n' "${GHCR_DIGEST:-sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa}" > "${headers:?}"
        printf '%s' "$GHCR_STATUS"
        ;;
    *registry-1.docker.io/v2/*)
        [ "${DOCKER_STATUS:?}" != network ] || exit 7
        [ "$DOCKER_STATUS" != timeout ] || exit 28
        if [ "$DOCKER_STATUS" = 404 ]; then printf '%s\n' "${DOCKER_404_BODY:-$DEFAULT_404_BODY}" > "${output:?}"; else : > "${output:?}"; fi
        printf 'Docker-Content-Digest: %s\r\n' "${DOCKER_DIGEST:-sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa}" > "${headers:?}"
        printf '%s' "$DOCKER_STATUS"
        ;;
    *) exit 64 ;;
esac
SH;
    file_put_contents($mockBin.'/curl', $curl);
    chmod($mockBin.'/curl', 0755);

    try {
        foreach ([
            'both semantic tags absent require publication' => ['404', '404', 'a', 'a', '404', '404', 'a', 'a', true, 'true', 'false'],
            'semantic and latest all match skip publication' => ['200', '200', 'a', 'a', '200', '200', 'a', 'a', true, 'false', 'false'],
            'missing latest alias requires repair' => ['200', '200', 'a', 'a', '404', '200', 'a', 'a', true, 'false', 'true'],
            'stale latest aliases require repair' => ['200', '200', 'a', 'a', '200', '200', 'b', 'b', true, 'false', 'true'],
            'cross-registry semantic presence disagreement fails' => ['200', '404', 'a', 'a', '200', '200', 'a', 'a', false, null, null],
            'cross-registry semantic digest disagreement fails' => ['200', '200', 'a', 'b', '200', '200', 'a', 'a', false, null, null],
            'registry server failure fails' => ['500', '500', 'a', 'a', '200', '200', 'a', 'a', false, null, null],
            'registry transport failure fails' => ['network', '404', 'a', 'a', '200', '200', 'a', 'a', false, null, null],
            'registry timeout fails' => ['timeout', '404', 'a', 'a', '200', '200', 'a', 'a', false, null, null],
        ] as $description => [$ghcrStatus, $dockerStatus, $ghcrDigest, $dockerDigest, $ghcrLatestStatus, $dockerLatestStatus, $ghcrLatestDigest, $dockerLatestDigest, $successful, $publishRequired, $repairRequired]) {
            $output = tempnam($fixture.'/runner', 'output-');
            expect($output)->not->toBeFalse();
            $process = new Process(['bash', '-c', $script], $root, [
                'DOCKER_STATUS' => $dockerStatus,
                'DEFAULT_404_BODY' => '{"errors":[{"code":"MANIFEST_UNKNOWN"}]}',
                'DOCKER_DIGEST' => 'sha256:'.str_repeat($dockerDigest, 64),
                'DOCKER_LATEST_DIGEST' => 'sha256:'.str_repeat($dockerLatestDigest, 64),
                'DOCKER_LATEST_STATUS' => $dockerLatestStatus,
                'GITHUB_OUTPUT' => $output,
                'GHCR_STATUS' => $ghcrStatus,
                'GHCR_DIGEST' => 'sha256:'.str_repeat($ghcrDigest, 64),
                'GHCR_LATEST_DIGEST' => 'sha256:'.str_repeat($ghcrLatestDigest, 64),
                'GHCR_LATEST_STATUS' => $ghcrLatestStatus,
                'PATH' => $mockBin.':'.getenv('PATH'),
                'RELEASE_KIND' => 'production',
                'RUNNER_TEMP' => $fixture.'/runner',
                'SEMANTIC_VERSION' => '4.1.4',
                'VALIDATE_ONLY' => 'false',
            ]);
            $process->run();

            expect($process->isSuccessful())->toBe($successful, $description."\n".$process->getErrorOutput());
            if ($publishRequired !== null) {
                $values = [];
                foreach (file($output, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
                    $values[$key] = $value;
                }
                expect($values['publish_required'] ?? null)->toBe($publishRequired)
                    ->and($values['repair_required'] ?? null)->toBe($repairRequired);
                if ($publishRequired === 'false' && $repairRequired === 'false') {
                    $digest = 'sha256:'.str_repeat('a', 64);
                    expect($values['index_digest'] ?? null)->toBe($digest)
                        ->and($values['ghcr_image'] ?? null)->toBe("ghcr.io/coollabsio/coolify@{$digest}")
                        ->and($values['docker_image'] ?? null)->toBe("docker.io/coollabsio/coolify@{$digest}");
                } else {
                    expect($values['ghcr_image'] ?? null)->toBe('')
                        ->and($values['docker_image'] ?? null)->toBe('');
                }
            }
        }

        foreach ([
            'unauthorized 404 body' => '{"errors":[{"code":"UNAUTHORIZED","message":"denied"}]}',
            'arbitrary 404 body' => '{"message":"not found"}',
        ] as $description => $body) {
            $output = tempnam($fixture.'/runner', 'output-');
            expect($output)->not->toBeFalse();
            $process = new Process(['bash', '-c', $script], $root, [
                'DOCKER_STATUS' => '404',
                'DEFAULT_404_BODY' => '{"errors":[{"code":"MANIFEST_UNKNOWN"}]}',
                'DOCKER_404_BODY' => $body,
                'DOCKER_DIGEST' => 'sha256:'.str_repeat('a', 64),
                'DOCKER_LATEST_DIGEST' => 'sha256:'.str_repeat('a', 64),
                'DOCKER_LATEST_STATUS' => '200',
                'GITHUB_OUTPUT' => $output,
                'GHCR_STATUS' => '404',
                'GHCR_DIGEST' => 'sha256:'.str_repeat('a', 64),
                'GHCR_LATEST_DIGEST' => 'sha256:'.str_repeat('a', 64),
                'GHCR_LATEST_STATUS' => '200',
                'PATH' => $mockBin.':'.getenv('PATH'),
                'RELEASE_KIND' => 'production',
                'RUNNER_TEMP' => $fixture.'/runner',
                'SEMANTIC_VERSION' => '4.1.4',
                'VALIDATE_ONLY' => 'false',
            ]);
            $process->run();
            expect($process->isSuccessful())->toBeFalse($description);
        }
    } finally {
        $filesystem->remove($fixture);
    }
});

it('verifies no-op aliases are multi-platform indexes with release provenance', function () {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $step = releaseWorkflowStep($workflow['jobs']['verify-authoritative-noop'] ?? [], 'Verify index platforms and release provenance');
    $script = (string) ($step['run'] ?? '');
    $filesystem = new Filesystem;
    $fixture = sys_get_temp_dir().'/coolify-noop-index-'.bin2hex(random_bytes(8));
    $mockBin = $fixture.'/bin';
    $filesystem->mkdir($mockBin);
    file_put_contents($mockBin.'/regctl', <<<'SH'
#!/bin/sh
set -eu
case "$1:$2" in
    manifest:get) printf '%s\n' "${REGCTL_MEDIA_TYPE:?}" ;;
    image:digest)
        case "$*" in
            *linux/amd64*) printf 'sha256:%064d\n' 1 ;;
            *linux/arm64*)
                if [ "${REGCTL_ARM64_SUFFIX:-2}" = invalid ]; then printf 'invalid\n'; else printf 'sha256:%064d\n' "$REGCTL_ARM64_SUFFIX"; fi
                ;;
            *) printf '%s\n' "${INDEX_DIGEST:?}" ;;
        esac
        ;;
    image:config)
        case "$*" in
            *linux/arm64*) run_id="${REGCTL_ARM64_RUN_ID:-42}" ;;
            *) run_id=42 ;;
        esac
        printf '{"config":{"Labels":{"io.coolify.release-run-id":"%s","io.coolify.release-run-attempt":"1","org.opencontainers.image.revision":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","org.opencontainers.image.version":"4.1.4"}}}\n' "$run_id"
        ;;
    *) exit 64 ;;
esac
SH);
    chmod($mockBin.'/regctl', 0755);
    $digest = 'sha256:'.str_repeat('a', 64);

    try {
        foreach ([
            'valid OCI index' => ['application/vnd.oci.image.index.v1+json', '2', '42', true],
            'single image manifest' => ['application/vnd.oci.image.manifest.v1+json', '2', '42', false],
            'single architecture masquerading as an index' => ['application/vnd.oci.image.index.v1+json', '1', '42', false],
            'invalid arm64 digest' => ['application/vnd.oci.image.index.v1+json', 'invalid', '42', false],
            'mismatched arm64 provenance' => ['application/vnd.oci.image.index.v1+json', '2', '43', false],
        ] as $description => [$mediaType, $arm64Suffix, $arm64RunId, $successful]) {
            $output = tempnam($fixture, 'output-');
            expect($output)->not->toBeFalse();
            $process = new Process(['bash', '-c', $script], $root, [
                'DOCKER_TARGET' => 'docker.io/coollabsio/coolify',
                'GITHUB_OUTPUT' => $output,
                'GHCR_TARGET' => 'ghcr.io/coollabsio/coolify',
                'INDEX_DIGEST' => $digest,
                'PATH' => $mockBin.PATH_SEPARATOR.(getenv('PATH') ?: ''),
                'REGCTL_ARM64_SUFFIX' => $arm64Suffix,
                'REGCTL_ARM64_RUN_ID' => $arm64RunId,
                'REGCTL_MEDIA_TYPE' => $mediaType,
                'SEMANTIC_VERSION' => '4.1.4',
            ]);
            $process->run();

            expect($process->isSuccessful())->toBe($successful, $description);
            if ($successful) {
                expect((string) file_get_contents($output))->toContain("index_digest={$digest}")
                    ->toContain("ghcr_image=ghcr.io/coollabsio/coolify@{$digest}")
                    ->toContain("docker_image=docker.io/coollabsio/coolify@{$digest}");
            }
        }
    } finally {
        $filesystem->remove($fixture);
    }
});

it('rejects OCI tags longer than 128 characters in input validation', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $targetStep = releaseWorkflowStepById($sharedWorkflow['jobs']['validate-inputs'] ?? [], 'target');
    $script = (string) ($targetStep['run'] ?? '');
    $semanticVersionPattern = (string) ($targetStep['env']['SEMANTIC_VERSION_PATTERN'] ?? '');
    $cases = [
        '128-character semantic tag is accepted' => ['1.0.0-'.str_repeat('a', 122), '1', true],
        '129-character semantic tag is rejected' => ['1.0.0-'.str_repeat('a', 123), '1', false],
        'oversized derived quarantine tag is rejected' => ['4.0.0', str_repeat('9', 70), false],
    ];

    foreach ($cases as $description => [$semanticVersion, $runId, $shouldSucceed]) {
        $githubOutput = tempnam(sys_get_temp_dir(), 'coolify-release-tag-limit-');
        expect($githubOutput)->not->toBeFalse();

        try {
            $process = new Process(['bash', '-c', $script], $root, [
                'ARTIFACT_NAME' => 'coolify',
                'CANDIDATE_REPOSITORY' => 'coollabsio/coolify-production-staging',
                'DOCKERFILE' => 'docker/production/Dockerfile',
                'GITHUB_OUTPUT' => $githubOutput,
                'GITHUB_RUN_ATTEMPT' => '1',
                'GITHUB_RUN_ID' => $runId,
                'GITHUB_SHA' => str_repeat('a', 40),
                'PUBLISH_LATEST' => 'true',
                'RELEASE_KIND' => 'production',
                'REPOSITORY' => 'coollabsio/coolify',
                'SEMANTIC_VERSION' => $semanticVersion,
                'SEMANTIC_VERSION_PATTERN' => $semanticVersionPattern,
                'TARGET_REPOSITORY' => 'coollabsio/coolify',
                'VALIDATE_ONLY' => 'false',
            ]);
            $process->run();

            expect($process->isSuccessful())->toBe($shouldSucceed, $description);
        } finally {
            unlink($githubOutput);
        }
    }
});

it('allows the PostgreSQL and Redis validation job to override local test defaults', function () {
    $configuration = simplexml_load_file(releaseWorkflowRepositoryRoot().'/phpunit.xml');

    expect($configuration)->not->toBeFalse();

    $environment = [];
    foreach ($configuration->php->env as $variable) {
        $attributes = $variable->attributes();
        $environment[(string) $attributes['name']] = [
            'value' => (string) $attributes['value'],
            'force' => (string) $attributes['force'],
        ];
    }

    expect($environment['DB_CONNECTION'])->toBe([
        'value' => 'testing',
        'force' => 'false',
    ])->and($environment['CACHE_STORE'])->toBe([
        'value' => 'array',
        'force' => 'false',
    ]);
});

it('rejects parsed workflow policy regressions', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $applicationValidationWorkflow = Yaml::parseFile($root.'/.github/workflows/application-validation.yml');
    $callers = [
        'production' => Yaml::parseFile($root.'/.github/workflows/coolify-production-build.yml'),
        'testing-host' => Yaml::parseFile($root.'/.github/workflows/coolify-testing-host.yml'),
        'staging' => Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml'),
    ];

    foreach (releaseWorkflowNegativeCases() as $negativeCase) {
        [$mutatedSharedWorkflow, $mutatedCallers] = mutateReleaseWorkflow(
            $sharedWorkflow,
            $callers,
            $negativeCase['mutation'],
        );

        expect(releaseWorkflowViolations($mutatedSharedWorkflow, $applicationValidationWorkflow, $mutatedCallers))
            ->toContain($negativeCase['expected']);
    }
});

it('compensates the whole alias transaction when latest publication fails', function (string $failedRegistry, bool $preexistingGhcrSemantic) {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $aliasStep = releaseWorkflowStep(
        $sharedWorkflow['jobs']['release'] ?? [],
        'Publish semantic and latest aliases atomically',
    );
    $script = (string) ($aliasStep['run'] ?? '');
    $filesystem = new Filesystem;
    $stateDirectory = sys_get_temp_dir().'/coolify-release-alias-'.bin2hex(random_bytes(8));
    $mockBin = $stateDirectory.'/bin';
    $log = $stateDirectory.'/regctl.log';
    $ghcrRepository = 'ghcr.io/coollabsio/coolify';
    $dockerRepository = 'docker.io/coollabsio/coolify';
    $semanticVersion = '4.2.0';
    $amd64Digest = releaseWorkflowTestDigest('a');
    $arm64Digest = releaseWorkflowTestDigest('b');
    $oldIndex = releaseWorkflowTestDigest('c');
    $newIndex = releaseWorkflowTestDigest('d');

    $filesystem->mkdir([$mockBin, $stateDirectory.'/refs', $stateDirectory.'/runs']);
    $filesystem->copy($root.'/tests/Fixtures/mock-regctl.sh', $mockBin.'/regctl');
    chmod($mockBin.'/regctl', 0755);
    file_put_contents($log, '');

    foreach ([$ghcrRepository, $dockerRepository] as $repository) {
        releaseWorkflowWriteRegistryReference($stateDirectory, "{$repository}@{$oldIndex}", $oldIndex);
        releaseWorkflowWriteRegistryReference($stateDirectory, "{$repository}@{$newIndex}", $newIndex);
        releaseWorkflowWriteRegistryReference($stateDirectory, "{$repository}:latest", $oldIndex);
    }
    if ($preexistingGhcrSemantic) {
        releaseWorkflowWriteRegistryReference($stateDirectory, "{$ghcrRepository}:{$semanticVersion}", $newIndex);
    }
    file_put_contents($stateDirectory.'/runs/'.substr($oldIndex, strlen('sha256:')), "41\n");
    file_put_contents($stateDirectory.'/runs/'.substr($newIndex, strlen('sha256:')), "42\n");

    $failedTarget = ($failedRegistry === 'ghcr' ? $ghcrRepository : $dockerRepository).':latest';
    $process = new Process(['bash', '-c', $script], $root, [
        'AMD64_DIGEST' => $amd64Digest,
        'ARM64_DIGEST' => $arm64Digest,
        'DOCKER_TARGET' => $dockerRepository,
        'GHCR_TARGET' => $ghcrRepository,
        'GITHUB_RUN_ID' => '42',
        'INDEX_DIGEST' => $newIndex,
        'PATH' => $mockBin.PATH_SEPARATOR.(getenv('PATH') ?: ''),
        'PUBLISH_LATEST' => 'true',
        'REGCTL_AMD64' => $amd64Digest,
        'REGCTL_ARM64' => $arm64Digest,
        'REGCTL_FAIL_TARGET' => $failedTarget,
        'REGCTL_LOG' => $log,
        'REGCTL_STATE' => $stateDirectory,
        'SEMANTIC_VERSION' => $semanticVersion,
    ]);

    try {
        $process->setTimeout(15);
        $process->run();
        $registryLog = (string) file_get_contents($log);

        expect($process->isSuccessful())->toBeFalse()
            ->and($registryLog)->toContain("copy:{$ghcrRepository}@{$newIndex}:{$dockerRepository}:{$semanticVersion}")
            ->and(releaseWorkflowReadRegistryReference($stateDirectory, "{$ghcrRepository}:{$semanticVersion}"))->toBe($preexistingGhcrSemantic ? $newIndex : null)
            ->and(releaseWorkflowReadRegistryReference($stateDirectory, "{$dockerRepository}:{$semanticVersion}"))->toBeNull()
            ->and(releaseWorkflowReadRegistryReference($stateDirectory, "{$ghcrRepository}:latest"))->toBe($oldIndex)
            ->and(releaseWorkflowReadRegistryReference($stateDirectory, "{$dockerRepository}:latest"))->toBe($oldIndex);

        if ($preexistingGhcrSemantic) {
            expect($registryLog)->not->toContain("copy:{$ghcrRepository}@{$newIndex}:{$ghcrRepository}:{$semanticVersion}");
        } else {
            expect($registryLog)->toContain("copy:{$ghcrRepository}@{$newIndex}:{$ghcrRepository}:{$semanticVersion}");
        }

        if ($failedRegistry === 'ghcr') {
            expect($registryLog)->not->toContain("copy:{$ghcrRepository}@{$newIndex}:{$ghcrRepository}:latest");
        } else {
            expect($registryLog)
                ->toContain("copy:{$ghcrRepository}@{$newIndex}:{$ghcrRepository}:latest")
                ->toContain("copy:{$ghcrRepository}@{$oldIndex}:{$ghcrRepository}:latest");
        }
    } finally {
        $filesystem->remove($stateDirectory);
    }
})->with([
    'failure immediately after semantic publication' => ['ghcr', false],
    'failure between latest updates preserves a preexisting semantic alias' => ['docker', true],
]);

it('fails closed when semantic snapshot or rollback registry reads fail', function (string $failureMode) {
    $root = releaseWorkflowRepositoryRoot();
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $step = releaseWorkflowStep($workflow['jobs']['release'] ?? [], 'Publish semantic and latest aliases atomically');
    $filesystem = new Filesystem;
    $state = sys_get_temp_dir().'/coolify-alias-read-'.bin2hex(random_bytes(8));
    $mockBin = $state.'/bin';
    $ghcr = 'ghcr.io/coollabsio/coolify';
    $docker = 'docker.io/coollabsio/coolify';
    $old = releaseWorkflowTestDigest('c');
    $new = releaseWorkflowTestDigest('d');
    $amd64 = releaseWorkflowTestDigest('a');
    $arm64 = releaseWorkflowTestDigest('b');
    $semantic = '4.2.0';
    $filesystem->mkdir([$mockBin, $state.'/refs', $state.'/runs']);
    $filesystem->copy($root.'/tests/Fixtures/mock-regctl.sh', $mockBin.'/regctl');
    chmod($mockBin.'/regctl', 0755);
    file_put_contents($state.'/regctl.log', '');
    foreach ([$ghcr, $docker] as $repository) {
        releaseWorkflowWriteRegistryReference($state, "{$repository}@{$old}", $old);
        releaseWorkflowWriteRegistryReference($state, "{$repository}@{$new}", $new);
        releaseWorkflowWriteRegistryReference($state, "{$repository}:{$semantic}", $old);
        releaseWorkflowWriteRegistryReference($state, "{$repository}:latest", $old);
    }
    file_put_contents($state.'/runs/'.substr($old, 7), "41\n");
    file_put_contents($state.'/runs/'.substr($new, 7), "42\n");

    $environment = [
        'AMD64_DIGEST' => $amd64,
        'ARM64_DIGEST' => $arm64,
        'DOCKER_TARGET' => $docker,
        'GHCR_TARGET' => $ghcr,
        'GITHUB_RUN_ID' => '42',
        'INDEX_DIGEST' => $new,
        'PATH' => $mockBin.PATH_SEPARATOR.(getenv('PATH') ?: ''),
        'PUBLISH_LATEST' => 'true',
        'REGCTL_AMD64' => $amd64,
        'REGCTL_ARM64' => $arm64,
        'REGCTL_LOG' => $state.'/regctl.log',
        'REGCTL_STATE' => $state,
        'SEMANTIC_VERSION' => $semantic,
    ];
    if (str_starts_with($failureMode, 'snapshot')) {
        $environment['REGCTL_FAIL_READ_REFERENCE'] = "{$ghcr}:{$semantic}";
        $environment['REGCTL_FAIL_READ_MESSAGE'] = match ($failureMode) {
            'snapshot-credential' => 'credential helper not found',
            'snapshot-auth404' => 'authentication endpoint returned HTTP 404',
            default => 'registry transport unavailable',
        };
    } else {
        $environment['REGCTL_FAIL_TARGET'] = "{$docker}:latest";
        $environment['REGCTL_FAIL_READ_AFTER_COPY_FAILURE_REFERENCE'] = "{$ghcr}:latest";
        releaseWorkflowWriteRegistryReference($state, "{$ghcr}:{$semantic}", $new);
        releaseWorkflowWriteRegistryReference($state, "{$docker}:{$semantic}", $new);
        if ($failureMode === 'rollback-absent') {
            unlink(releaseWorkflowRegistryReferencePath($state, "{$ghcr}:latest"));
        } elseif ($failureMode === 'rollback-cross-prior') {
            releaseWorkflowWriteRegistryReference($state, "{$docker}:latest", $amd64);
            releaseWorkflowWriteRegistryReference($state, "{$docker}@{$amd64}", $amd64);
            file_put_contents($state.'/runs/'.substr($amd64, 7), "41\n");
            $environment['REGCTL_CONCURRENT_REFERENCE'] = "{$ghcr}:latest";
            $environment['REGCTL_CONCURRENT_DIGEST'] = $amd64;
        }
    }

    try {
        $process = new Process(['bash', '-c', (string) ($step['run'] ?? '')], $root, $environment);
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and(releaseWorkflowReadRegistryReference($state, "{$ghcr}:{$semantic}"))->not->toBeNull()
            ->and(releaseWorkflowReadRegistryReference($state, "{$docker}:{$semantic}"))->not->toBeNull();
        if (str_starts_with($failureMode, 'rollback')) {
            expect($process->getErrorOutput())->toContain('compensation was incomplete');
            if ($failureMode === 'rollback-absent') {
                expect(releaseWorkflowReadRegistryReference($state, "{$ghcr}:latest"))->toBe($new);
            } elseif ($failureMode === 'rollback-cross-prior') {
                expect(releaseWorkflowReadRegistryReference($state, "{$ghcr}:latest"))->toBe($amd64);
            }
        }
    } finally {
        $filesystem->remove($state);
    }
})->with(['snapshot', 'snapshot-credential', 'snapshot-auth404', 'rollback', 'rollback-absent', 'rollback-cross-prior']);

it('rejects a superseded candidate before creating its absent semantic aliases', function () {
    $root = releaseWorkflowRepositoryRoot();
    $sharedWorkflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $aliasStep = releaseWorkflowStep(
        $sharedWorkflow['jobs']['release'] ?? [],
        'Publish semantic and latest aliases atomically',
    );
    $script = (string) ($aliasStep['run'] ?? '');
    $filesystem = new Filesystem;
    $stateDirectory = sys_get_temp_dir().'/coolify-superseded-alias-'.bin2hex(random_bytes(8));
    $mockBin = $stateDirectory.'/bin';
    $log = $stateDirectory.'/regctl.log';
    $ghcrRepository = 'ghcr.io/coollabsio/coolify';
    $dockerRepository = 'docker.io/coollabsio/coolify';
    $semanticVersion = '4.1.0';
    $amd64Digest = releaseWorkflowTestDigest('a');
    $arm64Digest = releaseWorkflowTestDigest('b');
    $candidateIndex = releaseWorkflowTestDigest('c');
    $newerIndex = releaseWorkflowTestDigest('d');

    $filesystem->mkdir([$mockBin, $stateDirectory.'/refs', $stateDirectory.'/runs']);
    $filesystem->copy($root.'/tests/Fixtures/mock-regctl.sh', $mockBin.'/regctl');
    chmod($mockBin.'/regctl', 0755);
    file_put_contents($log, '');

    foreach ([$ghcrRepository, $dockerRepository] as $repository) {
        releaseWorkflowWriteRegistryReference($stateDirectory, "{$repository}@{$candidateIndex}", $candidateIndex);
        releaseWorkflowWriteRegistryReference($stateDirectory, "{$repository}@{$newerIndex}", $newerIndex);
        releaseWorkflowWriteRegistryReference($stateDirectory, "{$repository}:latest", $newerIndex);
    }
    file_put_contents($stateDirectory.'/runs/'.substr($candidateIndex, strlen('sha256:')), "41\n");
    file_put_contents($stateDirectory.'/runs/'.substr($newerIndex, strlen('sha256:')), "42\n");

    $process = new Process(['bash', '-c', $script], $root, [
        'AMD64_DIGEST' => $amd64Digest,
        'ARM64_DIGEST' => $arm64Digest,
        'DOCKER_TARGET' => $dockerRepository,
        'GHCR_TARGET' => $ghcrRepository,
        'GITHUB_RUN_ID' => '41',
        'INDEX_DIGEST' => $candidateIndex,
        'PATH' => $mockBin.PATH_SEPARATOR.(getenv('PATH') ?: ''),
        'PUBLISH_LATEST' => 'true',
        'REGCTL_AMD64' => $amd64Digest,
        'REGCTL_ARM64' => $arm64Digest,
        'REGCTL_LOG' => $log,
        'REGCTL_STATE' => $stateDirectory,
        'SEMANTIC_VERSION' => $semanticVersion,
    ]);

    try {
        $process->setTimeout(15);
        $process->run();
        $registryLog = (string) file_get_contents($log);

        expect($process->getExitCode())->toBe(3)
            ->and($process->getErrorOutput())->toContain('classification=superseded')
            ->and($registryLog)->not->toContain('copy:')
            ->and($registryLog)->not->toContain('delete:')
            ->and(releaseWorkflowReadRegistryReference($stateDirectory, "{$ghcrRepository}:{$semanticVersion}"))->toBeNull()
            ->and(releaseWorkflowReadRegistryReference($stateDirectory, "{$dockerRepository}:{$semanticVersion}"))->toBeNull()
            ->and(releaseWorkflowReadRegistryReference($stateDirectory, "{$ghcrRepository}:latest"))->toBe($newerIndex)
            ->and(releaseWorkflowReadRegistryReference($stateDirectory, "{$dockerRepository}:latest"))->toBe($newerIndex);
    } finally {
        $filesystem->remove($stateDirectory);
    }
});

it('executes immutable-tag, compensation, and platform-verification behavior against a registry double', function () {
    $process = new Process([
        'bash',
        releaseWorkflowRepositoryRoot().'/tests/Integration/PublishLinuxImageScriptTest.sh',
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain('PUBLISH_LINUX_IMAGE_HELPER_PASS');
});
