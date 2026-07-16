<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\Server;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

function nixpacksNullEnvVariable(Application $application, string $key, ?string $value): EnvironmentVariable
{
    $environmentVariable = new EnvironmentVariable;
    $environmentVariable->setRawAttributes([
        'key' => $key,
        'value' => is_null($value) ? null : encrypt($value),
        'is_literal' => false,
        'is_multiline' => false,
    ]);
    $environmentVariable->setRelation('resourceable', $application);

    return $environmentVariable;
}

/**
 * @param  array<string, string|null>  $productionVariables
 * @param  array<string, string|null>  $previewVariables
 */
function nixpacksNullEnvApplication(array $productionVariables = [], array $previewVariables = []): Application
{
    $application = new Application(['build_pack' => 'nixpacks']);
    $application->setRelation('environment', null);
    $application->setRelation('destination', null);
    $application->setRelation(
        'nixpacks_environment_variables',
        collect($productionVariables)
            ->map(fn (?string $value, string $key) => nixpacksNullEnvVariable($application, $key, $value))
            ->values(),
    );
    $application->setRelation(
        'nixpacks_environment_variables_preview',
        collect($previewVariables)
            ->map(fn (?string $value, string $key) => nixpacksNullEnvVariable($application, $key, $value))
            ->values(),
    );

    return $application;
}

/**
 * @param  Collection<string, string|null>  $coolifyVariables
 */
function nixpacksNullEnvJob(
    Application $application,
    int $pullRequestId,
    Collection $coolifyVariables,
): ApplicationDeploymentJob {
    $job = new class($coolifyVariables) extends ApplicationDeploymentJob
    {
        /**
         * @param  Collection<string, string|null>  $coolifyVariables
         */
        public function __construct(private readonly Collection $coolifyVariables) {}

        protected function generate_coolify_env_variables(bool $forBuildTime = false): Collection
        {
            return $this->coolifyVariables;
        }
    };

    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $reflection->getProperty('application')->setValue($job, $application);
    $reflection->getProperty('pull_request_id')->setValue($job, $pullRequestId);
    $reflection->getProperty('mainServer')->setValue($job, new Server);

    return $job;
}

function nixpacksNullEnvArguments(ApplicationDeploymentJob $job): string
{
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $reflection->getMethod('generate_nixpacks_env_variables')->invoke($job);

    return $reflection->getProperty('env_nixpacks_args')->getValue($job);
}

it('filters out null environment variables from nixpacks build command', function () {
    $application = nixpacksNullEnvApplication([
        'VALID_VAR' => 'valid_value',
        'NULL_VAR' => null,
        'EMPTY_VAR' => '',
        'ANOTHER_VALID_VAR' => 'another_value',
    ]);
    $job = nixpacksNullEnvJob($application, 0, collect([
        'COOLIFY_FQDN' => 'example.com',
        'COOLIFY_URL' => null,
        'COOLIFY_BRANCH' => '',
        'SOURCE_COMMIT' => 'abc123',
    ]));

    $environmentArguments = nixpacksNullEnvArguments($job);

    expect($environmentArguments)
        ->toContain("--env 'VALID_VAR=valid_value'")
        ->toContain("--env 'ANOTHER_VALID_VAR=another_value'")
        ->toContain("--env 'COOLIFY_FQDN=example.com'")
        ->toContain("--env 'SOURCE_COMMIT=abc123'")
        ->not->toContain('NULL_VAR')
        ->not->toContain('EMPTY_VAR')
        ->not->toContain('COOLIFY_URL')
        ->not->toContain('COOLIFY_BRANCH')
        ->not->toMatch('/--env [A-Z_]+=$/')
        ->not->toMatch("/--env '[A-Z_]+='$/");
});

it('filters out null environment variables from nixpacks preview deployments', function () {
    $application = nixpacksNullEnvApplication(previewVariables: [
        'PREVIEW_VAR' => 'preview_value',
        'NULL_PREVIEW_VAR' => null,
    ]);
    $job = nixpacksNullEnvJob($application, 123, collect([
        'COOLIFY_FQDN' => 'preview.example.com',
    ]));

    $environmentArguments = nixpacksNullEnvArguments($job);

    expect($environmentArguments)
        ->toContain("--env 'PREVIEW_VAR=preview_value'")
        ->toContain("--env 'COOLIFY_FQDN=preview.example.com'")
        ->not->toContain('NULL_PREVIEW_VAR');
});

it('handles all environment variables being null or empty', function () {
    $application = nixpacksNullEnvApplication([
        'NULL_VAR' => null,
        'EMPTY_VAR' => '',
    ]);
    $job = nixpacksNullEnvJob($application, 0, collect([
        'COOLIFY_URL' => null,
        'COOLIFY_BRANCH' => '',
    ]));

    expect(nixpacksNullEnvArguments($job))->toBe('');
});

it('filters out null coolify env variables from env_args used in nixpacks plan JSON', function () {
    $coolifyVariables = collect([
        'COOLIFY_URL' => null,
        'COOLIFY_FQDN' => null,
        'COOLIFY_BRANCH' => 'main',
        'COOLIFY_RESOURCE_UUID' => 'abc123',
        'COOLIFY_CONTAINER_NAME' => '',
    ]);

    $environmentArguments = collect([]);
    $coolifyVariables->each(function ($value, $key) use ($environmentArguments) {
        if (! is_null($value) && $value !== '') {
            $environmentArguments->put($key, $value);
        }
    });

    expect($environmentArguments->has('COOLIFY_URL'))->toBeFalse()
        ->and($environmentArguments->has('COOLIFY_FQDN'))->toBeFalse()
        ->and($environmentArguments->has('COOLIFY_CONTAINER_NAME'))->toBeFalse()
        ->and($environmentArguments->get('COOLIFY_BRANCH'))->toBe('main')
        ->and($environmentArguments->get('COOLIFY_RESOURCE_UUID'))->toBe('abc123');

    $json = json_encode(['variables' => $environmentArguments->toArray()], JSON_PRETTY_PRINT);
    $parsed = json_decode($json, true);

    foreach ($parsed['variables'] as $value) {
        expect($value)->toBeString();
    }
});

it('preserves environment variables with zero values', function () {
    $application = nixpacksNullEnvApplication([
        'ZERO_VALUE' => '0',
        'FALSE_VALUE' => 'false',
    ]);
    $job = nixpacksNullEnvJob($application, 0, collect());

    expect(nixpacksNullEnvArguments($job))
        ->toContain("--env 'ZERO_VALUE=0'")
        ->toContain("--env 'FALSE_VALUE=false'");
});
