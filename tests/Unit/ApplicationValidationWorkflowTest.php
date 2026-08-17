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

function blueGreenPostgresqlRunner(): string
{
    return file_get_contents(dirname(__DIR__, 2).'/scripts/dev/test-blue-green-postgresql.sh');
}

function blueGreenPostgresqlMakeTarget(): string
{
    return file_get_contents(dirname(__DIR__, 2).'/make/test.mk');
}

/**
 * @return list<string>
 */
function blueGreenPostgresqlTestManifest(?string $runner = null): array
{
    preg_match(
        '/readonly -a BLUE_GREEN_POSTGRESQL_TESTS=\(\n(?<tests>.*?)\n\)/s',
        $runner ?? blueGreenPostgresqlRunner(),
        $matches,
    );

    if (! isset($matches['tests'])) {
        throw new RuntimeException('The canonical PostgreSQL test manifest is missing.');
    }

    preg_match_all('/^\s*(tests\/[^\s]+\.php)$/m', $matches['tests'], $testPaths);

    return $testPaths[1];
}

/**
 * @return list<string>
 */
function readNullDelimitedFixtureFile(string $path): array
{
    if (! is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);

    if ($contents === false || $contents === '') {
        return [];
    }

    return explode("\0", rtrim($contents, "\0"));
}

/**
 * @param  array<string, string|false>  $environment
 * @return array{
 *     artisan_arguments: list<string>,
 *     docker_calls: string,
 *     pest_arguments: list<string>,
 *     pest_environment: string,
 *     process: Process
 * }
 */
function runBlueGreenPostgresqlLocalRunner(array $environment = []): array
{
    $fixture = sys_get_temp_dir().'/coolify-blue-green-runner-'.bin2hex(random_bytes(8));
    $bin = $fixture.'/bin';

    if (! mkdir($bin, 0700, true) && ! is_dir($bin)) {
        throw new RuntimeException('Could not create the blue-green runner fixture directory.');
    }

    $dockerCalls = $fixture.'/docker.calls';
    $pestArguments = $fixture.'/pest.arguments';
    $pestEnvironment = $fixture.'/pest.environment';
    $artisanArguments = $fixture.'/artisan.arguments';

    file_put_contents($bin.'/docker', <<<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail

printf '%s\n' "$*" >> "$FAKE_DOCKER_CALLS"

case "${1:-}" in
    context)
        case "${2:-}" in
            show)
                printf '%s\n' "${FAKE_DOCKER_CONTEXT:-default}"
                ;;
            inspect)
                [[ "${FAKE_DOCKER_CONTEXT_INSPECT_FAILURE:-false}" != true ]] || exit 1
                printf '%s\n' "${FAKE_DOCKER_ENDPOINT:-unix:///var/run/docker.sock}"
                ;;
            *)
                exit 64
                ;;
        esac
        ;;
    create | start)
        ;;
    exec)
        if [[ "$*" == *'redis-cli ping' ]]; then
            printf 'PONG\n'
        fi
        ;;
    port)
        case "${3:-}" in
            5432/tcp)
                printf '127.0.0.1:55432\n'
                ;;
            6379/tcp)
                printf '127.0.0.1:56379\n'
                ;;
            *)
                exit 64
                ;;
        esac
        ;;
    rm)
        if [[ "$*" == *cpbg-redis-tests-* && "${FAKE_REDIS_CLEANUP_FAILURE:-false}" == true ]]; then
            exit 1
        fi
        if [[ "$*" == *cpbg-postgresql-tests-* && "${FAKE_POSTGRESQL_CLEANUP_FAILURE:-false}" == true ]]; then
            exit 1
        fi
        ;;
    *)
        exit 64
        ;;
esac
BASH);
    file_put_contents($bin.'/php', <<<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "${1:-}" == -r ]]; then
    [[ "${FAKE_PCNTL_FORK_AVAILABLE:-true}" == true ]] || exit 1
    exit 0
fi

if [[ "${1:-}" == -d ]]; then
    printf '%s\0' "$@" > "$FAKE_PEST_ARGUMENTS"
    {
        for variable in DATABASE_URL DB_READ_HOST DB_READ_PORT DB_READ_USERNAME DB_READ_PASSWORD DB_WRITE_HOST DB_WRITE_PORT DB_WRITE_USERNAME DB_WRITE_PASSWORD; do
            if printenv "$variable" >/dev/null; then
                if [[ -z "${!variable}" ]]; then
                    printf '%s=empty\n' "$variable"
                else
                    printf '%s=non-empty\n' "$variable"
                fi
            else
                printf '%s=absent\n' "$variable"
            fi
        done
    } > "$FAKE_PEST_ENVIRONMENT"
    if [[ -n "${FAKE_LARAVEL_BOOTSTRAP_PROBE:-}" ]]; then
        "$FAKE_LARAVEL_BOOTSTRAP_PHP" \
            "$FAKE_LARAVEL_BOOTSTRAP_PROBE" \
            "$FAKE_LARAVEL_BOOTSTRAP_REPOSITORY_ROOT" \
            "$FAKE_LARAVEL_BOOTSTRAP_DOTENV_PATH" \
            > "$FAKE_LARAVEL_BOOTSTRAP_OUTPUT"
    fi
    if [[ -n "${FAKE_CONFIG_CACHE_CONTENT:-}" ]]; then
        printf '%s' "$FAKE_CONFIG_CACHE_CONTENT" > "$APP_CONFIG_CACHE"
    fi
    exit "${FAKE_PEST_EXIT_CODE:-0}"
fi

printf '%s\0' "$@" > "$FAKE_ARTISAN_ARGUMENTS"
exit "${FAKE_ARTISAN_EXIT_CODE:-0}"
BASH);
    chmod($bin.'/docker', 0700);
    chmod($bin.'/php', 0700);

    try {
        $process = new Process(
            ['bash', 'scripts/dev/test-blue-green-postgresql.sh', 'local'],
            dirname(__DIR__, 2),
            array_merge([
                'BASH_ENV' => '/dev/null',
                'DATABASE_URL' => false,
                'DB_READ_HOST' => false,
                'DB_READ_PORT' => false,
                'DB_READ_USERNAME' => false,
                'DB_READ_PASSWORD' => false,
                'DB_WRITE_HOST' => false,
                'DB_WRITE_PORT' => false,
                'DB_WRITE_USERNAME' => false,
                'DB_WRITE_PASSWORD' => false,
                'DOCKER_CONTEXT' => false,
                'DOCKER_HOST' => false,
                'FAKE_ARTISAN_ARGUMENTS' => $artisanArguments,
                'FAKE_DOCKER_CALLS' => $dockerCalls,
                'FAKE_PEST_ARGUMENTS' => $pestArguments,
                'FAKE_PEST_ENVIRONMENT' => $pestEnvironment,
                'PATH' => $bin.PATH_SEPARATOR.(string) getenv('PATH'),
                'REDIS_URL' => false,
            ], $environment),
        );
        $process->run();

        return [
            'artisan_arguments' => readNullDelimitedFixtureFile($artisanArguments),
            'docker_calls' => is_file($dockerCalls) ? (string) file_get_contents($dockerCalls) : '',
            'pest_arguments' => readNullDelimitedFixtureFile($pestArguments),
            'pest_environment' => is_file($pestEnvironment) ? (string) file_get_contents($pestEnvironment) : '',
            'process' => $process,
        ];
    } finally {
        foreach ([$bin.'/docker', $bin.'/php', $dockerCalls, $pestArguments, $pestEnvironment, $artisanArguments] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($bin);
        rmdir($fixture);
    }
}

/**
 * @return array{configuration: array<string, mixed>, fixture: string, malicious_configuration_cache: string, runner: array<string, mixed>}
 */
function runBlueGreenPostgresqlLocalRunnerWithDotenvBootstrapProbe(): array
{
    $fixture = sys_get_temp_dir().'/coolify-blue-green-bootstrap-probe-'.bin2hex(random_bytes(8));
    $dotenvPath = $fixture.'/dotenv';
    $dotenvFile = $dotenvPath.'/.env.testing';
    $bootstrapProbe = $fixture.'/bootstrap.php';
    $bootstrapOutput = $fixture.'/configuration.json';
    $maliciousConfigurationCache = $fixture.'/malicious-configuration.php';

    if (! mkdir($dotenvPath, 0700, true) && ! is_dir($dotenvPath)) {
        throw new RuntimeException('Could not create the blue-green Laravel bootstrap fixture directory.');
    }

    try {
        if (file_put_contents($dotenvFile, <<<'ENV'
BLUE_GREEN_DOTENV_PROBE=loaded
DATABASE_URL=postgresql://dotenv-user:dotenv-password@database.internal:5432/unsafe
DB_HOST=database.internal
DB_PORT=5433
DB_DATABASE=unsafe
DB_USERNAME=dotenv-user
DB_PASSWORD=dotenv-password
DB_READ_HOST=read.internal
DB_READ_PORT=5433
DB_READ_USERNAME=replica
DB_READ_PASSWORD=dotenv-read-password
DB_WRITE_HOST=write.internal
DB_WRITE_PORT=5434
DB_WRITE_USERNAME=writer
DB_WRITE_PASSWORD=dotenv-write-password
ENV
        ) === false) {
            throw new RuntimeException('Could not write the blue-green Laravel dotenv fixture.');
        }

        if (file_put_contents($maliciousConfigurationCache, <<<'PHP'
<?php

return [
    'database' => [
        'default' => 'pgsql',
        'connections' => [
            'pgsql' => [
                'database' => 'cached_unsafe',
                'host' => 'cached.database.internal',
                'read' => ['host' => 'cached.read.internal'],
                'url' => 'postgresql://cached-user:cached-password@cached.database.internal:5432/cached_unsafe',
                'write' => ['host' => 'cached.write.internal'],
            ],
        ],
    ],
];
PHP
        ) === false) {
            throw new RuntimeException('Could not write the blue-green Laravel malicious config cache fixture.');
        }

        if (file_put_contents($bootstrapProbe, <<<'PHP'
<?php

$repositoryRoot = $argv[1];
$environmentPath = $argv[2];

require $repositoryRoot.'/vendor/autoload.php';

$app = require $repositoryRoot.'/bootstrap/app.php';
$app->useEnvironmentPath($environmentPath);

(new Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables)->bootstrap($app);
(new Illuminate\Foundation\Bootstrap\LoadConfiguration)->bootstrap($app);

$database = $app->make('config')->get('database');
$pgsql = $database['connections']['pgsql'] ?? throw new RuntimeException('The pgsql connection was not configured.');

echo json_encode([
    'config_cache_path' => $app->getCachedConfigPath(),
    'default' => $database['default'] ?? null,
    'dotenv_probe' => Illuminate\Support\Env::get('BLUE_GREEN_DOTENV_PROBE'),
    'pgsql' => [
        'database' => $pgsql['database'] ?? null,
        'has_read' => array_key_exists('read', $pgsql),
        'has_write' => array_key_exists('write', $pgsql),
        'host' => $pgsql['host'] ?? null,
        'password' => $pgsql['password'] ?? null,
        'port' => $pgsql['port'] ?? null,
        'url' => $pgsql['url'] ?? null,
        'username' => $pgsql['username'] ?? null,
    ],
], JSON_THROW_ON_ERROR);
PHP
        ) === false) {
            throw new RuntimeException('Could not write the blue-green Laravel bootstrap probe.');
        }

        $runner = runBlueGreenPostgresqlLocalRunner([
            'APP_BASE_PATH' => false,
            'APP_CONFIG_CACHE' => $maliciousConfigurationCache,
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:8nWXkyPyP3g2bK8nWXkyPyP3g2bK8nWXkyPyP3g2bK8=',
            'FAKE_CONFIG_CACHE_CONTENT' => '<?php return [];',
            'FAKE_LARAVEL_BOOTSTRAP_DOTENV_PATH' => $dotenvPath,
            'FAKE_LARAVEL_BOOTSTRAP_OUTPUT' => $bootstrapOutput,
            'FAKE_LARAVEL_BOOTSTRAP_PHP' => PHP_BINARY,
            'FAKE_LARAVEL_BOOTSTRAP_PROBE' => $bootstrapProbe,
            'FAKE_LARAVEL_BOOTSTRAP_REPOSITORY_ROOT' => dirname(__DIR__, 2),
            'TMPDIR' => $fixture,
        ]);

        $configuration = [];
        if (is_file($bootstrapOutput)) {
            $contents = file_get_contents($bootstrapOutput);
            if ($contents === false) {
                throw new RuntimeException('Could not read the blue-green Laravel bootstrap configuration.');
            }

            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new RuntimeException('The blue-green Laravel bootstrap configuration was not an object.');
            }
            $configuration = $decoded;
        }

        return [
            'configuration' => $configuration,
            'fixture' => $fixture,
            'malicious_configuration_cache' => $maliciousConfigurationCache,
            'runner' => $runner,
        ];
    } finally {
        foreach ([$dotenvFile, $bootstrapProbe, $bootstrapOutput, $maliciousConfigurationCache] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($dotenvPath);
        rmdir($fixture);
    }
}

/**
 * @param  array<string, string|false>  $overrides
 * @return array<string, string|false>
 */
function blueGreenPostgresqlExternalEnvironment(array $overrides = []): array
{
    return array_merge([
        'COOLIFY_EXTERNAL_TEST_SERVICES' => 'true',
        'DATABASE_URL' => false,
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => 'coolify_testing',
        'DB_HOST' => '127.0.0.1',
        'DB_PASSWORD' => 'secret',
        'DB_PORT' => '5432',
        'DB_READ_HOST' => false,
        'DB_READ_PORT' => false,
        'DB_READ_USERNAME' => false,
        'DB_READ_PASSWORD' => false,
        'DB_USERNAME' => 'postgres',
        'DB_WRITE_HOST' => false,
        'DB_WRITE_PORT' => false,
        'DB_WRITE_USERNAME' => false,
        'DB_WRITE_PASSWORD' => false,
        'REDIS_CACHE_DB' => '1',
        'REDIS_DB' => '0',
        'REDIS_HOST' => '127.0.0.1',
        'REDIS_PASSWORD' => '',
        'REDIS_PORT' => '6379',
        'REDIS_URL' => '',
        'REDIS_USERNAME' => '',
    ], $overrides);
}

/**
 * @param  array<string, string|false>  $environment
 */
function runBlueGreenPostgresqlExternalRunner(array $environment): Process
{
    $process = new Process(
        ['bash', 'scripts/dev/test-blue-green-postgresql.sh', 'external'],
        dirname(__DIR__, 2),
        $environment,
    );
    $process->run();

    return $process;
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
        'UNIT_SUITE_RESULT' => 'success',
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

/** @param  array<string, string>  $context */
function runApplicationValidationSourceIdentity(
    string $sourceSha,
    string $checkRunSha,
    string $baseSha = '',
    array $context = [],
): Process {
    $step = applicationValidationSourceIdentityStep(applicationValidationWorkflow());
    $process = new Process(
        ['bash', '-c', (string) ($step['run'] ?? '')],
        dirname(__DIR__, 2),
        array_merge([
            'BASE_REF' => '',
            'CHECK_RUN_SHA' => $checkRunSha,
            'EVENT_NAME' => 'workflow_call',
            'HEAD_REF' => '',
            'HEAD_REPOSITORY' => '',
            'REPOSITORY' => 'williamacallahan/coolify',
            'VALIDATION_SOURCE_SHA' => $sourceSha,
            'VALIDATION_BASE_SHA' => $baseSha,
        ], $context),
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
        'UNIT_SUITE_RESULT',
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
        'UNIT_SUITE_RESULT',
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
function applicationValidationWorkflowViolations(array $workflow, ?string $blueGreenRunner = null): array
{
    $violations = [];
    $jobs = $workflow['jobs'] ?? [];
    $blueGreenRunner ??= blueGreenPostgresqlRunner();
    $manifestCounts = array_count_values(blueGreenPostgresqlTestManifest($blueGreenRunner));

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
    $sourceIdentityRun = (string) ($sourceIdentityStep['run'] ?? '');
    if (($sourceIdentity['name'] ?? null) !== 'Exact validation source identity'
        || ! is_array($sourceIdentityStep)
        || ($sourceIdentityStep['shell'] ?? null) !== 'bash'
        || ($sourceIdentityStep['env'] ?? null) !== [
            'BASE_REF' => '${{ github.base_ref }}',
            'CHECK_RUN_SHA' => '${{ github.sha }}',
            'EVENT_NAME' => '${{ github.event_name }}',
            'HEAD_REF' => '${{ github.head_ref }}',
            'HEAD_REPOSITORY' => '${{ github.event.pull_request.head.repo.full_name }}',
            'REPOSITORY' => '${{ github.repository }}',
            'VALIDATION_SOURCE_SHA' => '${{ inputs.source_sha }}',
            'VALIDATION_BASE_SHA' => '${{ inputs.base_sha }}',
        ]
        || ! str_contains($sourceIdentityRun, '[[ "$VALIDATION_SOURCE_SHA" == "$CHECK_RUN_SHA" ]]')
        || ! str_contains($sourceIdentityRun, '"$CHECK_RUN_SHA" == "$VALIDATION_BASE_SHA"')) {
        $violations[] = 'application validation must bind an exact requested source to the check-run revision';
    }
    if (! str_contains($sourceIdentityRun, '"$BASE_REF" == \'main\'')
        || ! str_contains($sourceIdentityRun, '"$HEAD_REF" == \'dev\'')
        || ! str_contains($sourceIdentityRun, '"$HEAD_REPOSITORY" == "$REPOSITORY"')) {
        $violations[] = 'application validation must enforce same-repository dev-to-main promotion identity';
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
    $redis = $blueGreen['services']['redis'] ?? [];
    $redisEnvironment = $blueGreen['env'] ?? [];
    if (($redis['image'] ?? null) !== 'redis:7-alpine@sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99'
        || ($redisEnvironment['REDIS_HOST'] ?? null) !== '127.0.0.1'
        || ($redisEnvironment['REDIS_PORT'] ?? null) !== 6379
        || ($redisEnvironment['REDIS_DB'] ?? null) !== 0
        || ($redisEnvironment['REDIS_CACHE_DB'] ?? null) !== 1
        || ($redisEnvironment['REDIS_DB'] ?? null) === ($redisEnvironment['REDIS_CACHE_DB'] ?? null)
        || ! array_key_exists('REDIS_USERNAME', $redisEnvironment)
        || $redisEnvironment['REDIS_USERNAME'] !== ''
        || ! array_key_exists('REDIS_PASSWORD', $redisEnvironment)
        || $redisEnvironment['REDIS_PASSWORD'] !== ''
        || ! array_key_exists('REDIS_URL', $redisEnvironment)
        || $redisEnvironment['REDIS_URL'] !== '') {
        $violations[] = 'blue-green lifecycle validation must use its pinned Redis service with explicit isolated loopback settings';
    }
    $blueGreenScript = collect($blueGreen['steps'] ?? [])
        ->firstWhere('name', 'Run canonical blue-green PostgreSQL tests')['run'] ?? '';
    if ($blueGreenScript !== 'bash scripts/dev/test-blue-green-postgresql.sh external') {
        $violations[] = 'blue-green lifecycle validation must invoke the canonical PostgreSQL runner';
    }
    $blueGreenPhpSetup = collect($blueGreen['steps'] ?? [])
        ->firstWhere('name', 'Set up PHP');
    $blueGreenExtensions = is_array($blueGreenPhpSetup)
        ? array_map('trim', explode(',', (string) ($blueGreenPhpSetup['with']['extensions'] ?? '')))
        : [];
    $pcntlForkPreflight = 'php -r \'exit(function_exists("pcntl_fork") ? 0 : 1);\'';
    $pcntlForkPreflightPosition = strpos($blueGreenRunner, $pcntlForkPreflight);
    $pestCommandPosition = strpos($blueGreenRunner, 'vendor/bin/pest');
    if (! in_array('pcntl', $blueGreenExtensions, true)
        || $pcntlForkPreflightPosition === false
        || $pestCommandPosition === false
        || $pcntlForkPreflightPosition > $pestCommandPosition) {
        $violations[] = 'blue-green lifecycle validation must install pcntl and preflight pcntl_fork before Pest';
    }
    if (! str_contains($blueGreenRunner, 'php -d memory_limit=1G vendor/bin/pest "${pest_arguments[@]}"')
        || ! str_contains($blueGreenRunner, 'pest_arguments=(--compact --do-not-cache-result)')) {
        $violations[] = 'blue-green lifecycle validation must run Pest with its bounded explicit memory contract';
    }
    foreach ([
        'tests/Feature/Api/DeploymentCancellationApiTest.php',
        'tests/Feature/ApplicationActiveContainerApiTest.php',
        'tests/Feature/ApplicationDeploymentBlueGreenDestinationFenceTest.php',
        'tests/Feature/BlueGreenApplicationDeactivationTest.php',
        'tests/Feature/BlueGreenApplicationManualStopTest.php',
        'tests/Feature/BlueGreenAutomaticRecoveryAcceptanceTest.php',
        'tests/Feature/BlueGreenCancellationCompensationTest.php',
        'tests/Feature/BlueGreenCandidateContainerSetMigrationTest.php',
        'tests/Feature/BlueGreenCleanIdleContainerJournalRecoveryTest.php',
        'tests/Feature/BlueGreenContinuousAvailabilityAcceptanceTest.php',
        'tests/Feature/BlueGreenConvergenceTest.php',
        'tests/Feature/BlueGreenCrashBoundaryAcceptanceTest.php',
        'tests/Feature/BlueGreenDeploymentReconciliationTest.php',
        'tests/Feature/BlueGreenFinalizedDrainingRecoveryTest.php',
        'tests/Feature/BlueGreenInactiveRetirementTest.php',
        'tests/Feature/BlueGreenInterventionRecoveryTest.php',
        'tests/Feature/BlueGreenLifecyclePublicRecoveryTest.php',
        'tests/Feature/BlueGreenMigrationReplayTest.php',
        'tests/Feature/BlueGreenMultiPortPromotionAcceptanceTest.php',
        'tests/Feature/BlueGreenOperationAwareManagedRouteReadTest.php',
        'tests/Feature/BlueGreenReleasedV3StateMigrationTest.php',
        'tests/Feature/BlueGreenReplicaLifecycleTest.php',
        'tests/Feature/BlueGreenServerDestinationTopologyGuardTest.php',
        'tests/Feature/BlueGreenSteadyStateRepairFenceTest.php',
        'tests/Feature/BlueGreenStoppedLegacyContainerCleanupTest.php',
        'tests/Feature/DatabaseMigrationReadinessTest.php',
        'tests/Feature/FactoryIntegrityTest.php',
        'tests/Feature/BlueGreenSupersessionGenerationTest.php',
        'tests/Feature/BlueGreenTopologyDigestCommandsTest.php',
        'tests/Feature/BlueGreenTopologyDigestConnectionSettingsTest.php',
        'tests/Feature/LegacyProxyMutationPayloadAdoptionTest.php',
        'tests/Feature/PostgresUserDeletionConcurrencyTest.php',
        'tests/Feature/Proxy/ControlPlane/PrepareControlPlaneProxyEnrollmentFromHostTest.php',
        'tests/Feature/ProxyMutationQueueGateTest.php',
        'tests/Feature/QueueApplicationDeploymentCommitTest.php',
        'tests/Unit/ApplicationDeploymentActivationOrderTest.php',
        'tests/Unit/Actions/Application/BlueGreenDrainAndReleaseProofTest.php',
        'tests/Unit/Actions/Application/BlueGreen/BlueGreenNonRootRemoteExecutionTest.php',
        'tests/Unit/Actions/Application/BlueGreen/ResolveActiveApplicationContainerStateTest.php',
        'tests/Unit/Actions/Proxy/BlueGreenCommittedContainerMutationJournalTest.php',
        'tests/Unit/Actions/Proxy/BlueGreenNonRootRemoteExecutionTest.php',
        'tests/Unit/Actions/Proxy/BlueGreenProxyStateRouteProofTest.php',
        'tests/Unit/ApplicationOpenApiTest.php',
        'tests/Unit/ProxyMutationQueueTest.php',
        'tests/Unit/ScheduledJobsRetryConfigTest.php',
    ] as $requiredTest) {
        if (($manifestCounts[$requiredTest] ?? 0) !== 1) {
            $violations[] = 'blue-green lifecycle validation must execute every ownership and migration gate';

            break;
        }
    }
    if (! str_contains($blueGreenRunner, 'tests/Unit/DeploymentConfiguration/ApplicationConfigurationSnapshotTest.php')
        || ! str_contains($blueGreenRunner, "--filter='fences deployment command'")) {
        $violations[] = 'blue-green lifecycle validation must fail closed when activation-time commands change after preparation';
    }

    $phpApplication = is_array($jobs) ? ($jobs['php'] ?? []) : [];
    $releaseTestsScript = collect($phpApplication['steps'] ?? [])
        ->firstWhere('name', 'Run release and version-consumer tests')['run'] ?? '';
    if (! str_contains((string) $releaseTestsScript, 'tests/Unit/StagingImageReferenceWorkflowTest.php')) {
        $violations[] = 'application validation must execute the staging image reference workflow regression owner';
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

it('runs release entrypoint contract tests in application validation', function (): void {
    $step = collect(applicationValidationWorkflow()['jobs']['workflow-and-shell']['steps'] ?? [])
        ->firstWhere('name', 'Run release entrypoint contract tests');

    expect($step)->toBeArray()
        ->and($step['run'] ?? null)->toBe('scripts/dev/ship.test.sh');
});

it('tracks every release and PostgreSQL validation executable with its checkout mode', function (): void {
    $expectedModes = [
        'make/test.mk' => '100644',
        'scripts/dev/assert-branch-current.sh' => '100755',
        'scripts/dev/ship.sh' => '100755',
        'scripts/dev/ship.test.sh' => '100755',
        'scripts/dev/test-blue-green-postgresql.sh' => '100755',
    ];
    $process = new Process([
        'git',
        'ls-files',
        '--stage',
        '--',
        ...array_keys($expectedModes),
    ], dirname(__DIR__, 2));
    $process->mustRun();
    $trackedModes = [];

    foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $entry) {
        if (preg_match('/^(?<mode>[0-9]{6}) [0-9a-f]{40} 0\t(?<path>.+)$/D', $entry, $matches) === 1) {
            $trackedModes[$matches['path']] = $matches['mode'];
        }
    }

    expect($trackedModes)->toBe($expectedModes);
});

it('routes CI and the local PostgreSQL target through one canonical executable manifest', function (): void {
    $workflow = applicationValidationWorkflow();
    $workflowRun = collect($workflow['jobs']['blue-green-lifecycle']['steps'] ?? [])
        ->firstWhere('name', 'Run canonical blue-green PostgreSQL tests')['run'] ?? null;
    $blueGreenCommands = collect($workflow['jobs']['blue-green-lifecycle']['steps'] ?? [])
        ->map(fn (array $step): string => (string) ($step['run'] ?? ''))
        ->implode("\n");
    $makeTarget = blueGreenPostgresqlMakeTarget();

    expect($workflowRun)->toBe('bash scripts/dev/test-blue-green-postgresql.sh external')
        ->and($blueGreenCommands)->not->toContain('vendor/bin/pest')
        ->not->toContain('php artisan test')
        ->and(file_get_contents(dirname(__DIR__, 2).'/Makefile'))->toContain('include make/test.mk')
        ->and($makeTarget)->toContain('bash scripts/dev/test-blue-green-postgresql.sh local')
        ->and($makeTarget)->not->toContain('tests/Feature/')
        ->and(file_get_contents(dirname(__DIR__, 2).'/.github/workflows/application-validation.yml'))
        ->not->toContain('tests/Feature/BlueGreenCancellationCompensationTest.php');
});

it('rejects missing or unsafe Redis configuration in blue-green lifecycle CI', function (string $variable, mixed $value, bool $omit): void {
    $workflow = applicationValidationWorkflow();

    if ($omit) {
        unset($workflow['jobs']['blue-green-lifecycle']['env'][$variable]);
    } else {
        $workflow['jobs']['blue-green-lifecycle']['env'][$variable] = $value;
    }

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('blue-green lifecycle validation must use its pinned Redis service with explicit isolated loopback settings');
})->with([
    'missing URL override' => ['REDIS_URL', null, true],
    'inherited URL' => ['REDIS_URL', 'redis://redis.internal:6379', false],
    'missing host' => ['REDIS_HOST', null, true],
    'non-loopback host' => ['REDIS_HOST', 'redis.internal', false],
    'missing port' => ['REDIS_PORT', null, true],
    'wrong port' => ['REDIS_PORT', 6380, false],
    'missing database' => ['REDIS_DB', null, true],
    'wrong database' => ['REDIS_DB', 2, false],
    'missing cache database' => ['REDIS_CACHE_DB', null, true],
    'wrong cache database' => ['REDIS_CACHE_DB', 2, false],
    'shared queue and cache database' => ['REDIS_CACHE_DB', 0, false],
    'missing username' => ['REDIS_USERNAME', null, true],
    'non-empty username' => ['REDIS_USERNAME', 'default', false],
    'missing password' => ['REDIS_PASSWORD', null, true],
    'non-empty password' => ['REDIS_PASSWORD', 'secret', false],
]);

it('rejects a non-digest-pinned Redis lifecycle service', function (): void {
    $workflow = applicationValidationWorkflow();
    $workflow['jobs']['blue-green-lifecycle']['services']['redis']['image'] = 'redis:7-alpine';

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('blue-green lifecycle validation must use its pinned Redis service with explicit isolated loopback settings');
});

it('keeps the issue 194 and 195 regression owners exactly once in the canonical PostgreSQL manifest', function (string $testPath): void {
    $manifest = blueGreenPostgresqlTestManifest();

    expect($manifest)->toContain($testPath)
        ->and(array_count_values($manifest)[$testPath] ?? 0)->toBe(1);
})->with([
    'factory integrity' => 'tests/Feature/FactoryIntegrityTest.php',
    'active container API' => 'tests/Feature/ApplicationActiveContainerApiTest.php',
    'server destination topology' => 'tests/Feature/BlueGreenServerDestinationTopologyGuardTest.php',
    'active state resolution' => 'tests/Unit/Actions/Application/BlueGreen/ResolveActiveApplicationContainerStateTest.php',
    'OpenAPI contract' => 'tests/Unit/ApplicationOpenApiTest.php',
    'candidate container durability' => 'tests/Feature/BlueGreenCandidateContainerSetDurabilityTest.php',
    'candidate container schema' => 'tests/Feature/BlueGreenCandidateContainerSetMigrationTest.php',
    'Docker log owner label' => 'tests/Feature/DockerLogOwnerLabelTest.php',
]);

it('fails closed before Pest when pcntl_fork is unavailable', function (): void {
    $result = runBlueGreenPostgresqlLocalRunner([
        'FAKE_PCNTL_FORK_AVAILABLE' => 'false',
    ]);

    expect($result['process']->isSuccessful())->toBeFalse()
        ->and($result['process']->getErrorOutput())->toContain('pcntl_fork is required for blue-green PostgreSQL concurrency tests.')
        ->and($result['docker_calls'])->toBe('')
        ->and($result['pest_arguments'])->toBe([])
        ->and($result['artisan_arguments'])->toBe([]);
});

it('requires pcntl in canonical PostgreSQL validation', function (): void {
    $workflow = applicationValidationWorkflow();
    $step = collect($workflow['jobs']['blue-green-lifecycle']['steps'] ?? [])
        ->search(fn (array $candidate): bool => ($candidate['name'] ?? null) === 'Set up PHP');
    expect($step)->not->toBeFalse();
    $workflow['jobs']['blue-green-lifecycle']['steps'][$step]['with']['extensions'] = 'mbstring, pdo_pgsql, redis';

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('blue-green lifecycle validation must install pcntl and preflight pcntl_fork before Pest');
});

it('rejects canonical PostgreSQL validation without a pcntl_fork preflight', function (): void {
    $runner = str_replace(
        'php -r \'exit(function_exists("pcntl_fork") ? 0 : 1);\'',
        'true',
        blueGreenPostgresqlRunner(),
    );

    expect(applicationValidationWorkflowViolations(applicationValidationWorkflow(), $runner))
        ->toContain('blue-green lifecycle validation must install pcntl and preflight pcntl_fork before Pest');
});

it('fails closed when external PostgreSQL is not explicitly configured', function (): void {
    $process = runBlueGreenPostgresqlExternalRunner(blueGreenPostgresqlExternalEnvironment([
        'COOLIFY_EXTERNAL_TEST_SERVICES' => false,
        'DB_CONNECTION' => false,
        'DB_HOST' => false,
        'DB_PORT' => false,
        'DB_DATABASE' => false,
        'DB_USERNAME' => false,
        'DB_PASSWORD' => false,
    ]));

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('COOLIFY_EXTERNAL_TEST_SERVICES=true is required.');
});

it('rejects unsafe external PostgreSQL targets', function (array $environment, string $expectedError): void {
    $process = runBlueGreenPostgresqlExternalRunner(blueGreenPostgresqlExternalEnvironment($environment));

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain($expectedError);
})->with([
    'non-loopback host' => [
        ['DB_HOST' => 'database.internal'],
        'DB_HOST must be loopback.',
    ],
    'non-testing database' => [
        ['DB_DATABASE' => 'coolify'],
        'DB_DATABASE must end in _testing.',
    ],
]);

it('rejects inherited PostgreSQL route overrides in external mode', function (string $variable, string $value): void {
    $process = runBlueGreenPostgresqlExternalRunner(blueGreenPostgresqlExternalEnvironment([
        $variable => $value,
    ]));

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain("$variable must be unset to prevent external database routes.");
})->with([
    'database URL' => ['DATABASE_URL', 'postgresql://postgres:secret@database.internal/coolify_testing'],
    'read host' => ['DB_READ_HOST', 'database.internal'],
    'read port' => ['DB_READ_PORT', '5433'],
    'read username' => ['DB_READ_USERNAME', 'replica'],
    'read password' => ['DB_READ_PASSWORD', 'secret'],
    'write host' => ['DB_WRITE_HOST', 'database.internal'],
    'write port' => ['DB_WRITE_PORT', '5433'],
    'write username' => ['DB_WRITE_USERNAME', 'writer'],
    'write password' => ['DB_WRITE_PASSWORD', 'secret'],
]);

it('rejects unsafe external Redis targets', function (array $environment, string $expectedError): void {
    $process = runBlueGreenPostgresqlExternalRunner(blueGreenPostgresqlExternalEnvironment($environment));

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain($expectedError);
})->with([
    'URL override' => [
        ['REDIS_URL' => 'redis://redis.internal:6379'],
        'REDIS_URL must be unset; configure Redis with explicit REDIS_* values.',
    ],
    'non-loopback host' => [
        ['REDIS_HOST' => 'redis.internal'],
        'REDIS_HOST must be loopback.',
    ],
    'missing port' => [
        ['REDIS_PORT' => false],
        'REDIS_PORT must be explicitly configured.',
    ],
    'invalid queue database' => [
        ['REDIS_DB' => 'queue'],
        'REDIS_DB must be explicitly configured.',
    ],
    'shared queue and cache database' => [
        ['REDIS_CACHE_DB' => '0'],
        'REDIS_DB and REDIS_CACHE_DB must be distinct.',
    ],
    'partial credentials' => [
        ['REDIS_USERNAME' => 'default'],
        'REDIS_USERNAME and REDIS_PASSWORD must both be empty or both be non-empty.',
    ],
]);

it('empties database route overrides before local Pest execution', function (): void {
    $result = runBlueGreenPostgresqlLocalRunner([
        'DATABASE_URL' => 'postgresql://postgres:secret@database.internal/coolify_testing',
        'DB_READ_HOST' => 'database.internal',
        'DB_READ_PORT' => '5433',
        'DB_READ_USERNAME' => 'replica',
        'DB_READ_PASSWORD' => 'secret',
        'DB_WRITE_HOST' => 'database.internal',
        'DB_WRITE_PORT' => '5433',
        'DB_WRITE_USERNAME' => 'writer',
        'DB_WRITE_PASSWORD' => 'secret',
    ]);

    expect($result['process']->isSuccessful())->toBeTrue($result['process']->getErrorOutput())
        ->and($result['pest_arguments'])->toBe(array_merge([
            '-d',
            'memory_limit=1G',
            'vendor/bin/pest',
            '--compact',
            '--do-not-cache-result',
        ], blueGreenPostgresqlTestManifest()))
        ->and($result['artisan_arguments'])->toBe([
            'artisan',
            'test',
            '--compact',
            'tests/Unit/DeploymentConfiguration/ApplicationConfigurationSnapshotTest.php',
            '--filter=fences deployment command',
        ]);

    $pestArgumentCounts = array_count_values($result['pest_arguments']);

    foreach ([
        'tests/Feature/BlueGreenCandidateContainerSetDurabilityTest.php',
        'tests/Feature/BlueGreenCandidateContainerSetMigrationTest.php',
        'tests/Feature/DockerLogOwnerLabelTest.php',
    ] as $testPath) {
        expect($pestArgumentCounts[$testPath] ?? 0)->toBe(1);
    }

    foreach ([
        'DATABASE_URL',
        'DB_READ_HOST',
        'DB_READ_PORT',
        'DB_READ_USERNAME',
        'DB_READ_PASSWORD',
        'DB_WRITE_HOST',
        'DB_WRITE_PORT',
        'DB_WRITE_USERNAME',
        'DB_WRITE_PASSWORD',
    ] as $variable) {
        expect($result['pest_environment'])->toContain("$variable=empty");
    }
});

it('keeps local Laravel pgsql configuration isolated from dotenv and cached config', function (): void {
    $probe = runBlueGreenPostgresqlLocalRunnerWithDotenvBootstrapProbe();
    $runner = $probe['runner'];

    expect($runner['process']->isSuccessful())->toBeTrue($runner['process']->getErrorOutput());

    foreach ([
        'DATABASE_URL',
        'DB_READ_HOST',
        'DB_READ_PORT',
        'DB_READ_USERNAME',
        'DB_READ_PASSWORD',
        'DB_WRITE_HOST',
        'DB_WRITE_PORT',
        'DB_WRITE_USERNAME',
        'DB_WRITE_PASSWORD',
    ] as $variable) {
        expect($runner['pest_environment'])->toContain("$variable=empty");
    }

    $configuration = $probe['configuration'];
    expect($configuration['default'] ?? null)->toBe('pgsql')
        ->and($configuration['dotenv_probe'] ?? null)->toBe('loaded')
        ->and($configuration['config_cache_path'] ?? null)->toBeString();

    $configurationCachePath = $configuration['config_cache_path'];
    expect($configurationCachePath)->not->toBe($probe['malicious_configuration_cache'])
        ->and(str_starts_with($configurationCachePath, $probe['fixture'].'/coolify-blue-green-config-cache.'))->toBeTrue()
        ->and(is_file($configurationCachePath))->toBeFalse()
        ->and(is_dir(dirname($configurationCachePath)))->toBeFalse();

    expect($configuration['pgsql'] ?? null)->toBeArray();
    $pgsql = $configuration['pgsql'];

    expect($pgsql['url'] ?? null)->toBe('')
        ->and($pgsql['host'] ?? null)->toBe('127.0.0.1')
        ->and($pgsql['port'] ?? null)->toBe('55432')
        ->and($pgsql['database'] ?? null)->toBe('coolify_blue_green_testing')
        ->and($pgsql['username'] ?? null)->toBe('postgres')
        ->and($pgsql['password'] ?? null)->toBe('coolify-blue-green-tests')
        ->and($pgsql['has_read'] ?? null)->toBeFalse()
        ->and($pgsql['has_write'] ?? null)->toBeFalse();
});

it('allows named local Docker contexts backed by a socket endpoint', function (array $environment, string $expectedContext): void {
    $result = runBlueGreenPostgresqlLocalRunner($environment);

    expect($result['process']->isSuccessful())->toBeTrue($result['process']->getErrorOutput())
        ->and($result['docker_calls'])->toContain("context inspect $expectedContext")
        ->toContain('create --name');
})->with([
    'OrbStack Unix socket' => [
        [
            'FAKE_DOCKER_CONTEXT' => 'orbstack',
            'FAKE_DOCKER_ENDPOINT' => 'unix:///Users/coolify/.orbstack/run/docker.sock',
        ],
        'orbstack',
    ],
    'named npipe socket' => [
        [
            'FAKE_DOCKER_CONTEXT' => 'desktop-windows',
            'FAKE_DOCKER_ENDPOINT' => 'npipe:////./pipe/docker_engine',
        ],
        'desktop-windows',
    ],
]);

it('rejects remote Docker selectors and endpoints before local containers are created', function (array $environment, string $expectedError): void {
    $result = runBlueGreenPostgresqlLocalRunner($environment);

    expect($result['process']->isSuccessful())->toBeFalse()
        ->and($result['process']->getErrorOutput())->toContain($expectedError)
        ->and($result['docker_calls'])->not->toContain('create --name');
})->with([
    'DOCKER_HOST' => [
        ['DOCKER_HOST' => 'ssh://docker.example.test'],
        'DOCKER_HOST must be unset for the disposable local PostgreSQL lane.',
    ],
    'DOCKER_CONTEXT' => [
        ['DOCKER_CONTEXT' => 'remote'],
        'DOCKER_CONTEXT must be unset for the disposable local PostgreSQL lane.',
    ],
    'failed context inspection' => [
        ['FAKE_DOCKER_CONTEXT_INSPECT_FAILURE' => 'true'],
        'Could not determine the Docker endpoint for the disposable local PostgreSQL lane.',
    ],
    'tcp active context endpoint' => [
        [
            'FAKE_DOCKER_CONTEXT' => 'orbstack-remote',
            'FAKE_DOCKER_ENDPOINT' => 'tcp://docker.example.test:2376',
        ],
        'Docker context orbstack-remote must use a local Unix or npipe socket.',
    ],
    'ssh active context endpoint' => [
        [
            'FAKE_DOCKER_CONTEXT' => 'orbstack-remote',
            'FAKE_DOCKER_ENDPOINT' => 'ssh://docker.example.test',
        ],
        'Docker context orbstack-remote must use a local Unix or npipe socket.',
    ],
]);

it('fails local execution when cleanup of an owned disposable container fails', function (string $failureVariable, string $containerName): void {
    $result = runBlueGreenPostgresqlLocalRunner([
        $failureVariable => 'true',
    ]);

    expect($result['process']->isSuccessful())->toBeFalse()
        ->and($result['process']->getExitCode())->toBe(1)
        ->and($result['process']->getErrorOutput())->toContain("Failed to remove $containerName")
        ->and($result['docker_calls'])->toContain("rm --force --volumes $containerName");
})->with([
    'Redis container' => ['FAKE_REDIS_CLEANUP_FAILURE', 'cpbg-redis-tests-'],
    'PostgreSQL container' => ['FAKE_POSTGRESQL_CLEANUP_FAILURE', 'cpbg-postgresql-tests-'],
]);

it('preserves the original Pest failure when local cleanup also fails', function (): void {
    $result = runBlueGreenPostgresqlLocalRunner([
        'FAKE_PEST_EXIT_CODE' => '73',
        'FAKE_REDIS_CLEANUP_FAILURE' => 'true',
    ]);

    expect($result['process']->isSuccessful())->toBeFalse()
        ->and($result['process']->getExitCode())->toBe(73)
        ->and($result['process']->getErrorOutput())->toContain('Failed to remove cpbg-redis-tests-');
});

it('owns and cleans only its named disposable PostgreSQL container', function (): void {
    $runner = blueGreenPostgresqlRunner();

    expect($runner)->toContain('LOCAL_CONTAINER="cpbg-postgresql-tests-$$"')
        ->toContain('--label coolify.integration.ephemeral=true')
        ->toContain('trap cleanup EXIT')
        ->toContain('docker rm --force --volumes "$LOCAL_CONTAINER"')
        ->toContain('postgres:16-alpine@sha256:57c72fd2a128e416c7fcc499958864df5301e940bca0a56f58fddf30ffc07777');
});

it('rejects bypassing the canonical PostgreSQL runner in CI', function (): void {
    $workflow = applicationValidationWorkflow();
    $step = collect($workflow['jobs']['blue-green-lifecycle']['steps'] ?? [])
        ->search(fn (array $candidate): bool => ($candidate['name'] ?? null) === 'Run canonical blue-green PostgreSQL tests');
    expect($step)->not->toBeFalse();
    $workflow['jobs']['blue-green-lifecycle']['steps'][$step]['run'] = 'php artisan test --compact tests/Feature/ExampleTest.php';

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('blue-green lifecycle validation must invoke the canonical PostgreSQL runner');
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

it('accepts a gate-shaped source when the run executes on the exact frozen base', function (): void {
    $base = str_repeat('c', 40);
    $process = runApplicationValidationSourceIdentity(str_repeat('d', 40), $base, $base);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('rejects a differing source when the run does not execute on the frozen base', function (): void {
    $process = runApplicationValidationSourceIdentity(str_repeat('d', 40), str_repeat('c', 40), str_repeat('e', 40));

    expect($process->isSuccessful())->toBeFalse();
});

it('allows only same-repository dev promotions into fork main', function (array $context, bool $successful): void {
    $process = runApplicationValidationSourceIdentity('', str_repeat('a', 40), '', $context);

    expect($process->isSuccessful())->toBe($successful, $process->getErrorOutput());
})->with([
    'same-repository dev promotion' => [[
        'BASE_REF' => 'main',
        'EVENT_NAME' => 'pull_request',
        'HEAD_REF' => 'dev',
        'HEAD_REPOSITORY' => 'williamacallahan/coolify',
    ], true],
    'topic branch cannot bypass dev' => [[
        'BASE_REF' => 'main',
        'EVENT_NAME' => 'pull_request',
        'HEAD_REF' => 'topic',
        'HEAD_REPOSITORY' => 'williamacallahan/coolify',
    ], false],
    'external dev branch cannot promote' => [[
        'BASE_REF' => 'main',
        'EVENT_NAME' => 'pull_request',
        'HEAD_REF' => 'dev',
        'HEAD_REPOSITORY' => 'someone-else/coolify',
    ], false],
    'topic branches remain valid for dev' => [[
        'BASE_REF' => 'dev',
        'EVENT_NAME' => 'pull_request',
        'HEAD_REF' => 'topic',
        'HEAD_REPOSITORY' => 'williamacallahan/coolify',
    ], true],
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

it('rejects removing the fork promotion identity guard', function (): void {
    $workflow = applicationValidationWorkflow();
    $step = collect($workflow['jobs']['source-identity']['steps'] ?? [])
        ->search(fn (array $candidate): bool => ($candidate['name'] ?? null) === 'Bind requested source to check-run revision');
    expect($step)->not->toBeFalse();
    $workflow['jobs']['source-identity']['steps'][$step]['run'] = str_replace(
        '"$HEAD_REF" == \'dev\'',
        'true',
        (string) $workflow['jobs']['source-identity']['steps'][$step]['run'],
    );

    expect(applicationValidationWorkflowViolations($workflow))
        ->toContain('application validation must enforce same-repository dev-to-main promotion identity');
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
    $runner = str_replace(
        $requiredTest,
        'tests/Feature/RemovedRequiredRegressionOwnerTest.php',
        blueGreenPostgresqlRunner(),
    );

    expect(applicationValidationWorkflowViolations($workflow, $runner))
        ->toContain('blue-green lifecycle validation must execute every ownership and migration gate');
})->with([
    'released v3 state migration' => 'tests/Feature/BlueGreenReleasedV3StateMigrationTest.php',
    'replica identity' => 'tests/Feature/BlueGreenReplicaLifecycleTest.php',
    'topology digest commands' => 'tests/Feature/BlueGreenTopologyDigestCommandsTest.php',
    'application privileged transport' => 'tests/Unit/Actions/Application/BlueGreen/BlueGreenNonRootRemoteExecutionTest.php',
    'proxy privileged transport' => 'tests/Unit/Actions/Proxy/BlueGreenNonRootRemoteExecutionTest.php',
    'control-plane enrollment serialization' => 'tests/Feature/Proxy/ControlPlane/PrepareControlPlaneProxyEnrollmentFromHostTest.php',
]);

it('does not accept a comment as a PostgreSQL manifest entry', function (): void {
    $requiredTest = 'tests/Feature/BlueGreenReplicaLifecycleTest.php';
    $runner = str_replace(
        '    '.$requiredTest,
        "    tests/Feature/RemovedRequiredRegressionOwnerTest.php\n    # ".$requiredTest,
        blueGreenPostgresqlRunner(),
    );

    expect(applicationValidationWorkflowViolations(applicationValidationWorkflow(), $runner))
        ->toContain('blue-green lifecycle validation must execute every ownership and migration gate');
});

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
            'unit-suite',
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
            'UNIT_SUITE_RESULT' => '${{ needs.unit-suite.result }}',
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
    $runner = str_replace(
        'tests/Feature/BlueGreenCancellationCompensationTest.php',
        'tests/Feature/BlueGreenApplicationDeactivationTest.php',
        blueGreenPostgresqlRunner(),
    );

    expect(applicationValidationWorkflowViolations(applicationValidationWorkflow(), $runner))
        ->toContain('blue-green lifecycle validation must execute every ownership and migration gate');
});

it('rejects omitting nullable backend inventory compatibility coverage from PostgreSQL validation', function (): void {
    $runner = str_replace(
        'tests/Feature/BlueGreenMultiPortPromotionAcceptanceTest.php',
        'tests/Feature/BlueGreenLifecyclePublicRecoveryTest.php',
        blueGreenPostgresqlRunner(),
    );

    expect(applicationValidationWorkflowViolations(applicationValidationWorkflow(), $runner))
        ->toContain('blue-green lifecycle validation must execute every ownership and migration gate');
});

it('rejects omitting delayed database-startup coverage from PostgreSQL validation', function () {
    $runner = str_replace(
        'tests/Feature/DatabaseMigrationReadinessTest.php',
        '',
        blueGreenPostgresqlRunner(),
    );

    expect(applicationValidationWorkflowViolations(applicationValidationWorkflow(), $runner))
        ->toContain('blue-green lifecycle validation must execute every ownership and migration gate');
});

it('rejects omitting PostgreSQL user-deletion concurrency coverage', function () {
    $runner = str_replace(
        'tests/Feature/PostgresUserDeletionConcurrencyTest.php',
        '',
        blueGreenPostgresqlRunner(),
    );

    expect(applicationValidationWorkflowViolations(applicationValidationWorkflow(), $runner))
        ->toContain('blue-green lifecycle validation must execute every ownership and migration gate');
});

it('rejects omitting the activation configuration fence from PostgreSQL validation', function () {
    $runner = str_replace(
        'tests/Unit/DeploymentConfiguration/ApplicationConfigurationSnapshotTest.php',
        'tests/Unit/RemovedActivationConfigurationFenceTest.php',
        blueGreenPostgresqlRunner(),
    );

    expect(applicationValidationWorkflowViolations(applicationValidationWorkflow(), $runner))
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
        "    if [ \"\$COMPOSE_STARTED\" -eq 1 ]; then\n        if compose down --volumes --remove-orphans >/dev/null 2>&1; then\n            :\n        else\n            cleanup_status=\$?\n            cleanup_failures+=(\"owned Compose project cleanup failed with status \$cleanup_status\")\n        fi\n    fi\n    reap_registered_background_pids",
        "    reap_registered_background_pids\n    if [ \"\$COMPOSE_STARTED\" -eq 1 ]; then\n        if compose down --volumes --remove-orphans >/dev/null 2>&1; then\n            :\n        else\n            cleanup_status=\$?\n            cleanup_failures+=(\"owned Compose project cleanup failed with status \$cleanup_status\")\n        fi\n    fi",
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
        ->toContain('PASS: transition-log, transport-report, and cleanup validation self-tests completed.');
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
