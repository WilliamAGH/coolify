<?php

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * @return array<string, array{artifact_name: string, candidate_repository: string, expected_publish_result: string, failure_mode: string, publish_latest: string, release_kind: string, semantic_version: string, staging_alias: string, target_repository: string}>
 */
function releaseOperationalAcceptanceScenarios(): array
{
    return [
        'issue7-staging-success' => [
            'artifact_name' => 'coolify-operational-issue7',
            'candidate_repository' => 'williamagh/coolify-remediation-issue7-candidates',
            'expected_publish_result' => 'success',
            'failure_mode' => 'none',
            'publish_latest' => 'false',
            'release_kind' => 'staging',
            'semantic_version' => '',
            'staging_alias' => 'v4.x',
            'target_repository' => 'williamagh/coolify-remediation-issue7',
        ],
        'issue7-staging-failed-build' => [
            'artifact_name' => 'coolify-operational-issue7',
            'candidate_repository' => 'williamagh/coolify-remediation-issue7-candidates',
            'expected_publish_result' => 'failure',
            'failure_mode' => 'build-after-gates',
            'publish_latest' => 'false',
            'release_kind' => 'staging',
            'semantic_version' => '',
            'staging_alias' => 'v4.x',
            'target_repository' => 'williamagh/coolify-remediation-issue7',
        ],
        'issue9-latest-bootstrap' => [
            'artifact_name' => 'coolify-operational-issue9',
            'candidate_repository' => 'williamagh/coolify-remediation-issue9-candidates',
            'expected_publish_result' => 'success',
            'failure_mode' => 'none',
            'publish_latest' => 'true',
            'release_kind' => 'production',
            'semantic_version' => '0.0.0-operational.101',
            'staging_alias' => '',
            'target_repository' => 'williamagh/coolify-remediation-issue9',
        ],
        'issue9-latest-mixed' => [
            'artifact_name' => 'coolify-operational-issue9',
            'candidate_repository' => 'williamagh/coolify-remediation-issue9-candidates',
            'expected_publish_result' => 'failure',
            'failure_mode' => 'none',
            'publish_latest' => 'true',
            'release_kind' => 'production',
            'semantic_version' => '0.0.0-operational.101',
            'staging_alias' => '',
            'target_repository' => 'williamagh/coolify-remediation-issue9',
        ],
        'issue9-latest-malformed' => [
            'artifact_name' => 'coolify-operational-issue9',
            'candidate_repository' => 'williamagh/coolify-remediation-issue9-candidates',
            'expected_publish_result' => 'failure',
            'failure_mode' => 'none',
            'publish_latest' => 'true',
            'release_kind' => 'production',
            'semantic_version' => '0.0.0-operational.101',
            'staging_alias' => '',
            'target_repository' => 'williamagh/coolify-remediation-issue9',
        ],
        'issue9-latest-split' => [
            'artifact_name' => 'coolify-operational-issue9',
            'candidate_repository' => 'williamagh/coolify-remediation-issue9-candidates',
            'expected_publish_result' => 'failure',
            'failure_mode' => 'none',
            'publish_latest' => 'true',
            'release_kind' => 'production',
            'semantic_version' => '0.0.0-operational.101',
            'staging_alias' => '',
            'target_repository' => 'williamagh/coolify-remediation-issue9',
        ],
        'issue9-latest-compensation' => [
            'artifact_name' => 'coolify-operational-issue9',
            'candidate_repository' => 'williamagh/coolify-remediation-issue9-candidates',
            'expected_publish_result' => 'failure',
            'failure_mode' => 'docker-alias-before-copy',
            'publish_latest' => 'true',
            'release_kind' => 'production',
            'semantic_version' => '0.0.0-operational.101',
            'staging_alias' => '',
            'target_repository' => 'williamagh/coolify-remediation-issue9',
        ],
        'issue9-latest-forward-repair' => [
            'artifact_name' => 'coolify-operational-issue9',
            'candidate_repository' => 'williamagh/coolify-remediation-issue9-candidates',
            'expected_publish_result' => 'success',
            'failure_mode' => 'none',
            'publish_latest' => 'true',
            'release_kind' => 'production',
            'semantic_version' => '0.0.0-operational.101',
            'staging_alias' => '',
            'target_repository' => 'williamagh/coolify-remediation-issue9',
        ],
        'issue18-serialization' => [
            'artifact_name' => 'coolify-operational-issue18',
            'candidate_repository' => 'williamagh/coolify-remediation-issue18-candidates',
            'expected_publish_result' => 'success',
            'failure_mode' => 'none',
            'publish_latest' => 'false',
            'release_kind' => 'staging',
            'semantic_version' => '',
            'staging_alias' => 'v4.x',
            'target_repository' => 'williamagh/coolify-remediation-issue18',
        ],
    ];
}

/**
 * @return array<string, array{0: string, 1: array{artifact_name: string, candidate_repository: string, expected_publish_result: string, failure_mode: string, publish_latest: string, release_kind: string, semantic_version: string, staging_alias: string, target_repository: string}}>
 */
function releaseOperationalAcceptanceScenarioDataset(): array
{
    $dataset = [];

    foreach (releaseOperationalAcceptanceScenarios() as $scenario => $expected) {
        $dataset[$scenario] = [$scenario, $expected];
    }

    return $dataset;
}

function releaseOperationalAcceptanceWorkflowRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @return array<string, mixed>
 */
function releaseOperationalAcceptanceWorkflow(): array
{
    $workflow = Yaml::parseFile(releaseOperationalAcceptanceWorkflowRoot().'/.github/workflows/release-operational-acceptance.yml');

    expect($workflow)->toBeArray();

    return $workflow;
}

/**
 * @param  string|list<string>|null  $needs
 * @return list<string>
 */
function releaseOperationalAcceptanceNeeds(string|array|null $needs): array
{
    if ($needs === null) {
        return [];
    }

    $normalized = is_array($needs) ? $needs : [$needs];
    sort($normalized);

    return $normalized;
}

/**
 * @param  list<string>  $requiredPrerequisites
 */
function releaseOperationalAcceptanceRunsAlways(mixed $condition, array $requiredPrerequisites = []): bool
{
    if (! is_string($condition) || ! str_contains($condition, 'always()') || str_contains($condition, '||')) {
        return false;
    }

    foreach ($requiredPrerequisites as $requiredPrerequisite) {
        if (! str_contains($condition, $requiredPrerequisite)) {
            return false;
        }
    }

    return true;
}

/**
 * @return list<string>
 */
function releaseOperationalAcceptanceSecretNames(mixed $value): array
{
    if (is_string($value)) {
        preg_match_all('/secrets\.([A-Z0-9_]+)/', $value, $matches);

        return $matches[1];
    }

    if (! is_array($value)) {
        return [];
    }

    $names = [];
    foreach ($value as $nestedValue) {
        $names = [...$names, ...releaseOperationalAcceptanceSecretNames($nestedValue)];
    }
    sort($names);

    return array_values(array_unique($names));
}

/**
 * @param  array<string, mixed>  $job
 */
function releaseOperationalAcceptanceUsesCheckout(array $job): bool
{
    foreach ($job['steps'] ?? [] as $step) {
        if (str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@')) {
            return true;
        }
    }

    return false;
}

/**
 * @param  array<string, mixed>  $job
 */
function releaseOperationalAcceptanceHasDockerHubLogin(array $job): bool
{
    foreach ($job['steps'] ?? [] as $step) {
        if (str_starts_with((string) ($step['uses'] ?? ''), 'docker/login-action@') &&
            ($step['with']['registry'] ?? null) === 'docker.io' &&
            ($step['with']['username'] ?? null) === '${{ secrets.DOCKERHUB_USERNAME }}' &&
            ($step['with']['password'] ?? null) === '${{ secrets.DOCKERHUB_TOKEN }}') {
            return true;
        }
    }

    return false;
}

/**
 * @param  array<string, mixed>  $workflow
 * @return array<string, mixed>
 */
function releaseOperationalAcceptanceWorkflowStep(array $workflow, string $jobName, string $stepName): array
{
    foreach ($workflow['jobs'][$jobName]['steps'] ?? [] as $step) {
        if (is_array($step) && ($step['name'] ?? null) === $stepName) {
            return $step;
        }
    }

    throw new RuntimeException("Missing {$jobName} workflow step: {$stepName}");
}

/**
 * @param  array<string, mixed>  $workflow
 * @param  array<string, string>  $environment
 * @return array{arguments: list<list<string>>, process: Process}
 */
function runReleaseOperationalAcceptanceHelperScript(array $workflow, string $jobName, string $stepName, array $environment): array
{
    $step = releaseOperationalAcceptanceWorkflowStep($workflow, $jobName, $stepName);
    $run = $step['run'] ?? null;

    if (! is_string($run)) {
        throw new RuntimeException("The {$jobName} workflow step must be executable");
    }

    $directory = sys_get_temp_dir().'/coolify-operational-helper-'.bin2hex(random_bytes(8));
    $scriptsDirectory = $directory.'/scripts';
    $recordPath = $directory.'/recorded-arguments';
    $helperPath = $scriptsDirectory.'/release-operational-fixtures.sh';

    if (! mkdir($scriptsDirectory, 0700, true) && ! is_dir($scriptsDirectory)) {
        throw new RuntimeException('Unable to create the operational acceptance helper sandbox');
    }

    try {
        file_put_contents($helperPath, <<<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail
printf '%s\037' "$@" >> "$RECORDED_ARGUMENTS"
printf '\n' >> "$RECORDED_ARGUMENTS"
BASH);
        chmod($helperPath, 0700);

        $process = new Process(['bash', '-c', $run], $directory, [
            ...$environment,
            'RECORDED_ARGUMENTS' => $recordPath,
        ]);
        $process->run();

        $arguments = [];
        $recordedArguments = is_file($recordPath) ? (string) file_get_contents($recordPath) : '';
        foreach (explode("\n", rtrim($recordedArguments, "\n")) as $recordedInvocation) {
            if ($recordedInvocation === '') {
                continue;
            }

            $arguments[] = explode("\x1f", rtrim($recordedInvocation, "\x1f"));
        }

        return [
            'arguments' => $arguments,
            'process' => $process,
        ];
    } finally {
        if (is_file($helperPath)) {
            unlink($helperPath);
        }
        if (is_file($recordPath)) {
            unlink($recordPath);
        }
        if (is_dir($scriptsDirectory)) {
            rmdir($scriptsDirectory);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}

/**
 * @return array{issue: string, preset: string, verification: string}
 */
function releaseOperationalAcceptanceFixtureContract(string $scenario): array
{
    return match ($scenario) {
        'issue7-staging-success' => [
            'issue' => 'issue7',
            'preset' => 'staging-predecessor',
            'verification' => 'verify-published',
        ],
        'issue7-staging-failed-build' => [
            'issue' => 'issue7',
            'preset' => 'staging-predecessor',
            'verification' => 'verify-seed',
        ],
        'issue9-latest-bootstrap' => [
            'issue' => 'issue9',
            'preset' => 'latest-bootstrap',
            'verification' => 'verify-published',
        ],
        'issue9-latest-mixed' => [
            'issue' => 'issue9',
            'preset' => 'latest-mixed',
            'verification' => 'verify-seed',
        ],
        'issue9-latest-malformed' => [
            'issue' => 'issue9',
            'preset' => 'latest-malformed',
            'verification' => 'verify-seed',
        ],
        'issue9-latest-split' => [
            'issue' => 'issue9',
            'preset' => 'latest-split',
            'verification' => 'verify-seed',
        ],
        'issue9-latest-compensation' => [
            'issue' => 'issue9',
            'preset' => 'latest-predecessor',
            'verification' => 'verify-seed',
        ],
        'issue9-latest-forward-repair' => [
            'issue' => 'issue9',
            'preset' => 'forward-repair',
            'verification' => 'verify-result',
        ],
        'issue18-serialization' => [
            'issue' => 'issue18',
            'preset' => 'staging-predecessor',
            'verification' => 'verify-published',
        ],
    };
}

/**
 * @param  array<string, mixed>  $workflow
 */
function releaseOperationalAcceptanceRemoveHelperInvocation(array &$workflow, string $jobName, string $stepName, string $helperInvocation): void
{
    foreach ($workflow['jobs'][$jobName]['steps'] as &$step) {
        if (($step['name'] ?? null) !== $stepName || ! is_string($step['run'] ?? null)) {
            continue;
        }

        $step['run'] = str_replace($helperInvocation, 'true', $step['run']);
        unset($step);

        return;
    }
    unset($step);

    throw new RuntimeException("Missing {$jobName} workflow step: {$stepName}");
}

/**
 * @param  array<string, mixed>  $workflow
 * @return list<string>
 */
function releaseOperationalAcceptanceWorkflowViolations(array $workflow): array
{
    $violations = [];
    $events = $workflow['on'] ?? null;
    $jobs = $workflow['jobs'] ?? null;

    if (! is_array($events) || array_keys($events) !== ['repository_dispatch'] ||
        ($events['repository_dispatch']['types'] ?? null) !== ['release-operational-acceptance']) {
        $violations[] = 'the operational acceptance lane must trigger only for the allowlisted repository dispatch type';
    }

    if (($workflow['permissions'] ?? null) !== [] || ($workflow['concurrency'] ?? null) !== [
        'group' => 'release-operational-acceptance',
        'cancel-in-progress' => false,
    ]) {
        $violations[] = 'the operational acceptance caller must use the fixed non-canceling full-run lane without ambient credentials';
    }

    if (! is_array($jobs) || releaseOperationalAcceptanceNeeds(array_keys($jobs)) !== [
        'authorize',
        'cleanup',
        'fixture',
        'publish',
        'verify',
    ]) {
        $violations[] = 'the operational acceptance lane must have only authorize fixture publish verify and cleanup jobs';

        return $violations;
    }

    $authorize = $jobs['authorize'];
    $fixture = $jobs['fixture'];
    $publish = $jobs['publish'];
    $verify = $jobs['verify'];
    $cleanup = $jobs['cleanup'];

    if (($authorize['permissions'] ?? null) !== ['contents' => 'read'] || array_key_exists('secrets', $authorize) ||
        releaseOperationalAcceptanceSecretNames($authorize) !== [] || releaseOperationalAcceptanceUsesCheckout($authorize)) {
        $violations[] = 'authorization must run without checkout secrets or write permissions';
    }

    foreach ([
        'artifact_name' => '${{ steps.authorize.outputs.artifact_name }}',
        'candidate_repository' => '${{ steps.authorize.outputs.candidate_repository }}',
        'expected_publish_result' => '${{ steps.authorize.outputs.expected_publish_result }}',
        'failure_mode' => '${{ steps.authorize.outputs.failure_mode }}',
        'publish_latest' => '${{ steps.authorize.outputs.publish_latest }}',
        'release_kind' => '${{ steps.authorize.outputs.release_kind }}',
        'scenario' => '${{ steps.authorize.outputs.scenario }}',
        'semantic_version' => '${{ steps.authorize.outputs.semantic_version }}',
        'source_sha' => '${{ steps.authorize.outputs.source_sha }}',
        'staging_alias' => '${{ steps.authorize.outputs.staging_alias }}',
        'target_repository' => '${{ steps.authorize.outputs.target_repository }}',
    ] as $output => $expectedExpression) {
        if (($authorize['outputs'][$output] ?? null) !== $expectedExpression) {
            $violations[] = 'authorization must publish only validated fixed operational acceptance values';
            break;
        }
    }

    $authorizeStep = null;
    foreach ($authorize['steps'] ?? [] as $step) {
        if (($step['id'] ?? null) === 'authorize') {
            $authorizeStep = $step;
            break;
        }
    }
    if (! is_array($authorizeStep) || ! is_string($authorizeStep['run'] ?? null)) {
        $violations[] = 'authorization must expose an executable fail-closed validation step';
    }

    if (releaseOperationalAcceptanceNeeds($fixture['needs'] ?? null) !== ['authorize'] ||
        ($fixture['environment'] ?? null) !== 'release-operational-acceptance' ||
        releaseOperationalAcceptanceSecretNames($fixture) !== ['DOCKERHUB_TOKEN', 'DOCKERHUB_USERNAME'] ||
        ! releaseOperationalAcceptanceHasDockerHubLogin($fixture)) {
        $violations[] = 'fixture preparation must use only protected minimal Docker credentials after authorization';
    }

    if (($publish['uses'] ?? null) !== './.github/workflows/publish-linux-image.yml' ||
        array_key_exists('runs-on', $publish) || array_key_exists('steps', $publish) ||
        releaseOperationalAcceptanceNeeds($publish['needs'] ?? null) !== ['authorize', 'fixture']) {
        $violations[] = 'publication must call only the canonical reusable publisher after the protected fixture';
    }
    if (($publish['concurrency'] ?? null) !== [
        'group' => 'release-operational-${{ needs.authorize.outputs.target_repository }}',
        'cancel-in-progress' => false,
    ]) {
        $violations[] = 'publication must serialize on the fixed validated target without cancellation';
    }
    if (($publish['secrets'] ?? null) !== [
        'DOCKERHUB_TOKEN' => '${{ secrets.DOCKERHUB_TOKEN }}',
        'DOCKERHUB_USERNAME' => '${{ secrets.DOCKERHUB_USERNAME }}',
    ]) {
        $violations[] = 'publication must pass only explicit Docker credentials to the reusable publisher';
    }
    foreach ([
        'artifact_name' => '${{ needs.authorize.outputs.artifact_name }}',
        'candidate_repository' => '${{ needs.authorize.outputs.candidate_repository }}',
        'dockerfile' => 'docker/production/Dockerfile',
        'operational_acceptance' => true,
        'operational_failure_mode' => '${{ needs.authorize.outputs.failure_mode }}',
        'operational_scenario' => '${{ needs.authorize.outputs.scenario }}',
        'publish_latest' => "\${{ needs.authorize.outputs.publish_latest == 'true' }}",
        'release_kind' => '${{ needs.authorize.outputs.release_kind }}',
        'semantic_version' => '${{ needs.authorize.outputs.semantic_version }}',
        'staging_alias' => '${{ needs.authorize.outputs.staging_alias }}',
        'target_repository' => '${{ needs.authorize.outputs.target_repository }}',
        'validate_only' => false,
    ] as $input => $expectedValue) {
        if (($publish['with'][$input] ?? null) !== $expectedValue) {
            $violations[] = 'publication inputs must come only from the fixed operational acceptance contract';
            break;
        }
    }

    if (! releaseOperationalAcceptanceRunsAlways($verify['if'] ?? null, [
        "needs.authorize.result == 'success'",
        "needs.fixture.result == 'success'",
    ]) ||
        releaseOperationalAcceptanceNeeds($verify['needs'] ?? null) !== ['authorize', 'fixture', 'publish'] ||
        ($verify['environment'] ?? null) !== 'release-operational-acceptance' ||
        releaseOperationalAcceptanceSecretNames($verify) !== ['DOCKERHUB_TOKEN', 'DOCKERHUB_USERNAME'] ||
        ! releaseOperationalAcceptanceHasDockerHubLogin($verify)) {
        $violations[] = 'verification must always run in the protected credential boundary';
    }
    $verifyEvidenceStep = releaseOperationalAcceptanceWorkflowStep(
        $workflow,
        'verify',
        'Verify expected result and immutable registry evidence',
    );
    foreach ([
        'EXPECTED_PUBLISH_RESULT' => '${{ needs.authorize.outputs.expected_publish_result }}',
        'PUBLISH_RESULT' => '${{ needs.publish.result }}',
        'INDEX_DIGEST' => '${{ needs.publish.outputs.digest }}',
        'STAGING_DIGEST' => '${{ needs.publish.outputs.staging_digest }}',
        'STAGING_IMAGE' => '${{ needs.publish.outputs.staging_image }}',
        'SCENARIO' => '${{ needs.authorize.outputs.scenario }}',
    ] as $name => $expectedValue) {
        if (($verifyEvidenceStep['env'][$name] ?? null) !== $expectedValue) {
            $violations[] = 'verification must consume the canonical scenario result and immutable staging image outputs';
            break;
        }
    }
    if (! releaseOperationalAcceptanceRunsAlways($cleanup['if'] ?? null, ["needs.authorize.result == 'success'"]) ||
        releaseOperationalAcceptanceNeeds($cleanup['needs'] ?? null) !== ['authorize', 'fixture', 'publish', 'verify'] ||
        ($cleanup['environment'] ?? null) !== 'release-operational-acceptance' ||
        releaseOperationalAcceptanceSecretNames($cleanup) !== ['DOCKERHUB_TOKEN', 'DOCKERHUB_USERNAME'] ||
        ! releaseOperationalAcceptanceHasDockerHubLogin($cleanup)) {
        $violations[] = 'cleanup must always run in the protected credential boundary';
    }

    return array_values(array_unique($violations));
}

/**
 * @param  array<string, mixed>  $workflow
 * @return list<string>
 */
function releaseOperationalAcceptancePublisherViolations(array $workflow): array
{
    $violations = [];
    $inputs = $workflow['on']['workflow_call']['inputs'] ?? [];
    $jobs = $workflow['jobs'] ?? [];

    foreach ([
        'operational_acceptance' => ['default' => false, 'required' => false, 'type' => 'boolean'],
        'operational_scenario' => ['default' => '', 'required' => false, 'type' => 'string'],
        'operational_failure_mode' => ['default' => 'none', 'required' => false, 'type' => 'string'],
    ] as $input => $expectedContract) {
        if (($inputs[$input] ?? null) !== $expectedContract) {
            $violations[] = 'the reusable publisher must define the operational acceptance inputs explicitly';
            break;
        }
    }

    foreach (['release', 'repair-latest'] as $jobName) {
        if (($jobs[$jobName]['concurrency'] ?? null) !== [
            'group' => 'linux-image-alias-mutation-${{ inputs.target_repository }}',
            'cancel-in-progress' => false,
        ]) {
            $violations[] = 'reusable alias mutation must remain serialized by target without cancellation';
            break;
        }
    }

    return $violations;
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, string>  $context
 * @return array{output: array<string, string>, process: Process}
 */
function runReleaseOperationalAcceptanceAuthorization(array $payload, array $context = []): array
{
    $workflow = releaseOperationalAcceptanceWorkflow();
    $authorizeStep = null;
    foreach ($workflow['jobs']['authorize']['steps'] ?? [] as $step) {
        if (($step['id'] ?? null) === 'authorize') {
            $authorizeStep = $step;
            break;
        }
    }
    expect($authorizeStep)->toBeArray();

    $eventPath = tempnam(sys_get_temp_dir(), 'coolify-operational-event-');
    $githubOutput = tempnam(sys_get_temp_dir(), 'coolify-operational-output-');
    expect($eventPath)->toBeString()
        ->and($githubOutput)->toBeString();
    $eventPath = (string) $eventPath;
    $githubOutput = (string) $githubOutput;

    try {
        $sourceSha = is_string($payload['source_sha'] ?? null) ? $payload['source_sha'] : str_repeat('a', 40);
        $environment = array_merge([
            'GITHUB_EVENT_ACTION' => 'release-operational-acceptance',
            'GITHUB_EVENT_NAME' => 'repository_dispatch',
            'GITHUB_EVENT_PATH' => $eventPath,
            'GITHUB_OUTPUT' => $githubOutput,
            'GITHUB_REF' => 'refs/heads/v4.x',
            'GITHUB_REF_PROTECTED' => 'true',
            'GITHUB_REPOSITORY' => 'WilliamAGH/coolify',
            'GITHUB_RUN_ID' => '101',
            'GITHUB_SHA' => $sourceSha,
        ], $context);
        $environment = array_merge($environment, [
            'CLIENT_PAYLOAD' => (string) json_encode($payload, JSON_THROW_ON_ERROR),
            'EVENT_ACTION' => $environment['GITHUB_EVENT_ACTION'],
            'REF_PROTECTED' => $environment['GITHUB_REF_PROTECTED'],
            'REQUESTED_SCENARIO' => (string) ($payload['scenario'] ?? ''),
            'REQUESTED_SOURCE_SHA' => (string) ($payload['source_sha'] ?? ''),
        ]);
        file_put_contents($eventPath, (string) json_encode(['client_payload' => $payload], JSON_THROW_ON_ERROR));

        $run = (string) ($authorizeStep['run'] ?? '');
        expect($run)->not->toBe('');
        $process = new Process(['bash', '-c', $run], releaseOperationalAcceptanceWorkflowRoot(), $environment);
        $process->run();

        $output = [];
        $rawOutput = trim((string) file_get_contents($githubOutput));
        foreach ($rawOutput === '' ? [] : explode("\n", $rawOutput) as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $output[$name] = $value;
        }

        return [
            'output' => $output,
            'process' => $process,
        ];
    } finally {
        unlink($eventPath);
        unlink($githubOutput);
    }
}

it('defines the repository-dispatch operational acceptance contract', function (): void {
    expect(releaseOperationalAcceptanceWorkflowViolations(releaseOperationalAcceptanceWorkflow()))->toBe([]);

    $publisher = Yaml::parseFile(releaseOperationalAcceptanceWorkflowRoot().'/.github/workflows/publish-linux-image.yml');
    expect($publisher)->toBeArray()
        ->and(releaseOperationalAcceptancePublisherViolations($publisher))->toBe([]);
});

it('authorizes only fixed issue-scoped disposable targets', function (string $scenario, array $expected): void {
    $sourceSha = str_repeat('a', 40);
    $result = runReleaseOperationalAcceptanceAuthorization([
        'scenario' => $scenario,
        'source_sha' => $sourceSha,
    ]);

    expect($result['process']->isSuccessful())->toBeTrue($result['process']->getErrorOutput())
        ->and($result['output'])->toMatchArray([
            ...$expected,
            'scenario' => $scenario,
            'source_sha' => $sourceSha,
        ]);
})->with(releaseOperationalAcceptanceScenarioDataset());

it('executes the scenario-owned fixture lifecycle through the canonical helper', function (): void {
    $workflow = releaseOperationalAcceptanceWorkflow();

    foreach (releaseOperationalAcceptanceScenarios() as $scenario => $_scenarioContract) {
        $fixtureContract = releaseOperationalAcceptanceFixtureContract($scenario);
        $result = runReleaseOperationalAcceptanceHelperScript(
            $workflow,
            'fixture',
            'Prepare and seed only the requested disposable acceptance fixture',
            ['SCENARIO' => $scenario],
        );

        expect($result['process']->isSuccessful())->toBeTrue($result['process']->getErrorOutput())
            ->and($result['arguments'])->toBe([
                ['prepare-sources', $fixtureContract['issue'], $fixtureContract['preset']],
                ['seed', $fixtureContract['issue'], $fixtureContract['preset']],
                ['verify-seed', $fixtureContract['issue'], $fixtureContract['preset']],
            ]);
    }
});

it('executes only the scenario-owned immutable evidence verification', function (): void {
    $workflow = releaseOperationalAcceptanceWorkflow();
    $digest = 'sha256:'.str_repeat('a', 64);

    foreach (releaseOperationalAcceptanceScenarios() as $scenario => $scenarioContract) {
        $fixtureContract = releaseOperationalAcceptanceFixtureContract($scenario);
        $isStagingScenario = in_array($scenario, ['issue7-staging-success', 'issue18-serialization'], true);
        $result = runReleaseOperationalAcceptanceHelperScript(
            $workflow,
            'verify',
            'Verify expected result and immutable registry evidence',
            [
                'EXPECTED_PUBLISH_RESULT' => $scenarioContract['expected_publish_result'],
                'INDEX_DIGEST' => $digest,
                'PUBLISH_RESULT' => $scenarioContract['expected_publish_result'],
                'SCENARIO' => $scenario,
                'STAGING_DIGEST' => $isStagingScenario ? $digest : '',
                'STAGING_IMAGE' => match ($scenario) {
                    'issue7-staging-success' => 'docker.io/williamagh/coolify-remediation-issue7:v4.x',
                    'issue18-serialization' => 'docker.io/williamagh/coolify-remediation-issue18:v4.x',
                    default => '',
                },
            ],
        );

        $expectedArguments = match ($fixtureContract['verification']) {
            'verify-published' => [['verify-published', $fixtureContract['issue'], $fixtureContract['preset'], $digest]],
            'verify-result' => [['verify-result', $fixtureContract['issue'], $fixtureContract['preset']]],
            default => [['verify-seed', $fixtureContract['issue'], $fixtureContract['preset']]],
        };

        expect($result['process']->isSuccessful())->toBeTrue($result['process']->getErrorOutput())
            ->and($result['arguments'])->toBe($expectedArguments);
    }
});

it('executes cleanup only for the scenario-owned disposable target', function (): void {
    $workflow = releaseOperationalAcceptanceWorkflow();

    foreach (releaseOperationalAcceptanceScenarios() as $scenario => $_scenarioContract) {
        $fixtureContract = releaseOperationalAcceptanceFixtureContract($scenario);
        $result = runReleaseOperationalAcceptanceHelperScript(
            $workflow,
            'cleanup',
            'Remove only scenario-owned disposable aliases',
            ['SCENARIO' => $scenario],
        );

        expect($result['process']->isSuccessful())->toBeTrue($result['process']->getErrorOutput())
            ->and($result['arguments'])->toBe([
                ['cleanup', $fixtureContract['issue']],
            ]);
    }
});

it('rejects every unauthorized repository dispatch dimension', function (array $payload, array $context): void {
    $result = runReleaseOperationalAcceptanceAuthorization($payload, $context);

    expect($result['process']->isSuccessful())->toBeFalse();
})->with([
    'unknown scenario' => [[
        'scenario' => 'issue7-staging-unsafe',
        'source_sha' => str_repeat('a', 40),
    ], []],
    'missing source revision' => [[
        'scenario' => 'issue7-staging-success',
    ], []],
    'short source revision' => [[
        'scenario' => 'issue7-staging-success',
        'source_sha' => str_repeat('a', 39),
    ], []],
    'mismatched source revision' => [[
        'scenario' => 'issue7-staging-success',
        'source_sha' => str_repeat('a', 40),
    ], ['GITHUB_SHA' => str_repeat('b', 40)]],
    'unexpected event name' => [[
        'scenario' => 'issue7-staging-success',
        'source_sha' => str_repeat('a', 40),
    ], ['GITHUB_EVENT_NAME' => 'workflow_dispatch']],
    'unexpected event action' => [[
        'scenario' => 'issue7-staging-success',
        'source_sha' => str_repeat('a', 40),
    ], ['GITHUB_EVENT_ACTION' => 'unsafe-operational-acceptance']],
    'wrong repository' => [[
        'scenario' => 'issue7-staging-success',
        'source_sha' => str_repeat('a', 40),
    ], ['GITHUB_REPOSITORY' => 'coollabsio/coolify']],
    'wrong ref' => [[
        'scenario' => 'issue7-staging-success',
        'source_sha' => str_repeat('a', 40),
    ], ['GITHUB_REF' => 'refs/heads/main']],
    'unprotected ref' => [[
        'scenario' => 'issue7-staging-success',
        'source_sha' => str_repeat('a', 40),
    ], ['GITHUB_REF_PROTECTED' => 'false']],
    'caller-controlled ref' => [[
        'ref' => 'refs/heads/main',
        'scenario' => 'issue7-staging-success',
        'source_sha' => str_repeat('a', 40),
    ], []],
    'caller-controlled repository' => [[
        'repository' => 'coollabsio/coolify',
        'scenario' => 'issue7-staging-success',
        'source_sha' => str_repeat('a', 40),
    ], []],
    'caller-controlled registry' => [[
        'registry' => 'docker.io/coollabsio/coolify',
        'scenario' => 'issue7-staging-success',
        'source_sha' => str_repeat('a', 40),
    ], []],
    'caller-controlled dockerfile' => [[
        'dockerfile' => 'docker/production/Dockerfile',
        'scenario' => 'issue7-staging-success',
        'source_sha' => str_repeat('a', 40),
    ], []],
    'caller-controlled suffix' => [[
        'repository_suffix' => 'production',
        'scenario' => 'issue7-staging-success',
        'source_sha' => str_repeat('a', 40),
    ], []],
    'caller-controlled target' => [[
        'scenario' => 'issue7-staging-success',
        'source_sha' => str_repeat('a', 40),
        'target_repository' => 'williamagh/coolify',
    ], []],
]);

it('detects removal of every release operational acceptance workflow guard', function (Closure $mutate, string $expectedViolation): void {
    $workflow = releaseOperationalAcceptanceWorkflow();
    $mutate($workflow);

    expect(releaseOperationalAcceptanceWorkflowViolations($workflow))->toContain($expectedViolation);
})->with([
    'adds an untrusted trigger' => [
        function (array &$workflow): void {
            $workflow['on']['workflow_dispatch'] = [];
        },
        'the operational acceptance lane must trigger only for the allowlisted repository dispatch type',
    ],
    'cancels a queued full run' => [
        function (array &$workflow): void {
            $workflow['concurrency']['cancel-in-progress'] = true;
        },
        'the operational acceptance caller must use the fixed non-canceling full-run lane without ambient credentials',
    ],
    'grants authorization write access' => [
        function (array &$workflow): void {
            $workflow['jobs']['authorize']['permissions']['contents'] = 'write';
        },
        'authorization must run without checkout secrets or write permissions',
    ],
    'adds checkout to authorization' => [
        function (array &$workflow): void {
            $workflow['jobs']['authorize']['steps'][] = ['uses' => 'actions/checkout@93cb6efe18208431cddfb8368fd83d5badbf9bfd'];
        },
        'authorization must run without checkout secrets or write permissions',
    ],
    'removes validated target output' => [
        function (array &$workflow): void {
            unset($workflow['jobs']['authorize']['outputs']['target_repository']);
        },
        'authorization must publish only validated fixed operational acceptance values',
    ],
    'removes fixture environment protection' => [
        function (array &$workflow): void {
            unset($workflow['jobs']['fixture']['environment']);
        },
        'fixture preparation must use only protected minimal Docker credentials after authorization',
    ],
    'allows an extra fixture secret' => [
        function (array &$workflow): void {
            $workflow['jobs']['fixture']['env']['UNSAFE'] = '${{ secrets.EXTRA_TOKEN }}';
        },
        'fixture preparation must use only protected minimal Docker credentials after authorization',
    ],
    'bypasses the reusable publisher' => [
        function (array &$workflow): void {
            $workflow['jobs']['publish']['uses'] = './.github/workflows/coolify-production-build.yml';
        },
        'publication must call only the canonical reusable publisher after the protected fixture',
    ],
    'changes caller publication target concurrency' => [
        function (array &$workflow): void {
            $workflow['jobs']['publish']['concurrency']['group'] = 'release-operational-${{ github.run_id }}';
        },
        'publication must serialize on the fixed validated target without cancellation',
    ],
    'allows caller controlled target input' => [
        function (array &$workflow): void {
            $workflow['jobs']['publish']['with']['target_repository'] = '${{ github.event.client_payload.target_repository }}';
        },
        'publication inputs must come only from the fixed operational acceptance contract',
    ],
    'inherits all caller secrets' => [
        function (array &$workflow): void {
            $workflow['jobs']['publish']['secrets'] = 'inherit';
        },
        'publication must pass only explicit Docker credentials to the reusable publisher',
    ],
    'skips verification on failed publication' => [
        function (array &$workflow): void {
            $workflow['jobs']['verify']['if'] = '${{ success() }}';
        },
        'verification must always run in the protected credential boundary',
    ],
    'drops immutable staged image evidence' => [
        function (array &$workflow): void {
            foreach ($workflow['jobs']['verify']['steps'] as &$step) {
                if (($step['name'] ?? null) === 'Verify expected result and immutable registry evidence') {
                    unset($step['env']['STAGING_IMAGE']);
                    break;
                }
            }
            unset($step);
        },
        'verification must consume the canonical scenario result and immutable staging image outputs',
    ],
    'skips cleanup on failure' => [
        function (array &$workflow): void {
            $workflow['jobs']['cleanup']['if'] = '${{ success() }}';
        },
        'cleanup must always run in the protected credential boundary',
    ],
]);

it('detects removal of every helper-backed operational behavior guard', function (
    Closure $mutate,
    string $jobName,
    string $stepName,
    array $environment,
    array $expectedArguments,
): void {
    $workflow = releaseOperationalAcceptanceWorkflow();
    $mutate($workflow);

    $result = runReleaseOperationalAcceptanceHelperScript($workflow, $jobName, $stepName, $environment);

    expect($result['process']->isSuccessful())->toBeTrue($result['process']->getErrorOutput())
        ->and($result['arguments'])->not->toBe($expectedArguments);
})->with([
    'removes disposable source preparation' => [
        function (array &$workflow): void {
            releaseOperationalAcceptanceRemoveHelperInvocation(
                $workflow,
                'fixture',
                'Prepare and seed only the requested disposable acceptance fixture',
                'scripts/release-operational-fixtures.sh prepare-sources "$issue" "$preset"',
            );
        },
        'fixture',
        'Prepare and seed only the requested disposable acceptance fixture',
        ['SCENARIO' => 'issue7-staging-success'],
        [
            ['prepare-sources', 'issue7', 'staging-predecessor'],
            ['seed', 'issue7', 'staging-predecessor'],
            ['verify-seed', 'issue7', 'staging-predecessor'],
        ],
    ],
    'removes disposable fixture seeding' => [
        function (array &$workflow): void {
            releaseOperationalAcceptanceRemoveHelperInvocation(
                $workflow,
                'fixture',
                'Prepare and seed only the requested disposable acceptance fixture',
                'scripts/release-operational-fixtures.sh seed "$issue" "$preset"',
            );
        },
        'fixture',
        'Prepare and seed only the requested disposable acceptance fixture',
        ['SCENARIO' => 'issue7-staging-success'],
        [
            ['prepare-sources', 'issue7', 'staging-predecessor'],
            ['seed', 'issue7', 'staging-predecessor'],
            ['verify-seed', 'issue7', 'staging-predecessor'],
        ],
    ],
    'removes disposable fixture seed verification' => [
        function (array &$workflow): void {
            releaseOperationalAcceptanceRemoveHelperInvocation(
                $workflow,
                'fixture',
                'Prepare and seed only the requested disposable acceptance fixture',
                'scripts/release-operational-fixtures.sh verify-seed "$issue" "$preset"',
            );
        },
        'fixture',
        'Prepare and seed only the requested disposable acceptance fixture',
        ['SCENARIO' => 'issue7-staging-success'],
        [
            ['prepare-sources', 'issue7', 'staging-predecessor'],
            ['seed', 'issue7', 'staging-predecessor'],
            ['verify-seed', 'issue7', 'staging-predecessor'],
        ],
    ],
    'removes immutable staging publication proof' => [
        function (array &$workflow): void {
            releaseOperationalAcceptanceRemoveHelperInvocation(
                $workflow,
                'verify',
                'Verify expected result and immutable registry evidence',
                'scripts/release-operational-fixtures.sh verify-published issue7 staging-predecessor "$INDEX_DIGEST"',
            );
        },
        'verify',
        'Verify expected result and immutable registry evidence',
        [
            'EXPECTED_PUBLISH_RESULT' => 'success',
            'INDEX_DIGEST' => 'sha256:'.str_repeat('a', 64),
            'PUBLISH_RESULT' => 'success',
            'SCENARIO' => 'issue7-staging-success',
            'STAGING_DIGEST' => 'sha256:'.str_repeat('a', 64),
            'STAGING_IMAGE' => 'docker.io/williamagh/coolify-remediation-issue7:v4.x',
        ],
        [['verify-published', 'issue7', 'staging-predecessor', 'sha256:'.str_repeat('a', 64)]],
    ],
    'removes failed alias transition proof' => [
        function (array &$workflow): void {
            releaseOperationalAcceptanceRemoveHelperInvocation(
                $workflow,
                'verify',
                'Verify expected result and immutable registry evidence',
                'scripts/release-operational-fixtures.sh verify-seed issue9 latest-mixed',
            );
        },
        'verify',
        'Verify expected result and immutable registry evidence',
        [
            'EXPECTED_PUBLISH_RESULT' => 'failure',
            'INDEX_DIGEST' => 'sha256:'.str_repeat('a', 64),
            'PUBLISH_RESULT' => 'failure',
            'SCENARIO' => 'issue9-latest-mixed',
            'STAGING_DIGEST' => '',
            'STAGING_IMAGE' => '',
        ],
        [['verify-seed', 'issue9', 'latest-mixed']],
    ],
    'removes forward repair proof' => [
        function (array &$workflow): void {
            releaseOperationalAcceptanceRemoveHelperInvocation(
                $workflow,
                'verify',
                'Verify expected result and immutable registry evidence',
                'scripts/release-operational-fixtures.sh verify-result issue9 forward-repair',
            );
        },
        'verify',
        'Verify expected result and immutable registry evidence',
        [
            'EXPECTED_PUBLISH_RESULT' => 'success',
            'INDEX_DIGEST' => 'sha256:'.str_repeat('a', 64),
            'PUBLISH_RESULT' => 'success',
            'SCENARIO' => 'issue9-latest-forward-repair',
            'STAGING_DIGEST' => '',
            'STAGING_IMAGE' => '',
        ],
        [['verify-result', 'issue9', 'forward-repair']],
    ],
    'removes disposable cleanup' => [
        function (array &$workflow): void {
            releaseOperationalAcceptanceRemoveHelperInvocation(
                $workflow,
                'cleanup',
                'Remove only scenario-owned disposable aliases',
                'scripts/release-operational-fixtures.sh cleanup "$issue"',
            );
        },
        'cleanup',
        'Remove only scenario-owned disposable aliases',
        ['SCENARIO' => 'issue7-staging-success'],
        [['cleanup', 'issue7']],
    ],
]);

it('detects removal of each reusable publisher operational boundary', function (Closure $mutate, string $expectedViolation): void {
    $publisher = Yaml::parseFile(releaseOperationalAcceptanceWorkflowRoot().'/.github/workflows/publish-linux-image.yml');
    $mutate($publisher);

    expect(releaseOperationalAcceptancePublisherViolations($publisher))->toContain($expectedViolation);
})->with([
    'removes the acceptance boolean input' => [
        function (array &$publisher): void {
            unset($publisher['on']['workflow_call']['inputs']['operational_acceptance']);
        },
        'the reusable publisher must define the operational acceptance inputs explicitly',
    ],
    'widens alias mutation concurrency cancellation' => [
        function (array &$publisher): void {
            $publisher['jobs']['release']['concurrency']['cancel-in-progress'] = true;
        },
        'reusable alias mutation must remain serialized by target without cancellation',
    ],
    'changes repair alias mutation target' => [
        function (array &$publisher): void {
            $publisher['jobs']['repair-latest']['concurrency']['group'] = 'linux-image-alias-mutation-${{ github.run_id }}';
        },
        'reusable alias mutation must remain serialized by target without cancellation',
    ],
]);
