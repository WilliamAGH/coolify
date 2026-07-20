<?php

use Symfony\Component\Yaml\Yaml;

/**
 * @return array<string, mixed>
 */
function v4xWorkflow(string $name): array
{
    return Yaml::parseFile(dirname(__DIR__, 2)."/.github/workflows/{$name}");
}

/**
 * @return array{group: string, labels: list<string>}
 */
function williamCallahanTrustedRunner(): array
{
    return [
        'group' => 'coolify-trusted',
        'labels' => ['self-hosted', 'Linux', 'X64', 'williamacallahan'],
    ];
}

it('authorizes an immutable v4.x candidate on the William Callahan runner', function () {
    $workflow = v4xWorkflow('gate-v4x-candidate.yml');
    $inputs = $workflow['on']['workflow_dispatch']['inputs'] ?? [];
    $authorize = $workflow['jobs']['authorize'] ?? [];
    $script = collect($authorize['steps'] ?? [])->firstWhere('name', 'Authorize immutable candidate')['run'] ?? '';

    expect(array_keys($inputs))->toBe(['candidate_sha', 'candidate_ref', 'base_sha'])
        ->and($authorize['runs-on'] ?? null)->toBe(williamCallahanTrustedRunner())
        ->and($authorize['permissions'] ?? null)->toBe(['contents' => 'read'])
        ->and((string) $script)
        ->toContain('^ship/v4x/$FROZEN_SHA/[0-9a-f]{40}$')
        ->toContain('GITHUB_SHA')
        ->toContain('FROZEN_BASE_SHA');

    foreach ($authorize['steps'] ?? [] as $step) {
        expect((string) ($step['uses'] ?? ''))->not->toStartWith('actions/checkout@');
    }
});

it('runs hosted reusable validation against the exact authorized candidate sha', function () {
    $candidate = v4xWorkflow('gate-v4x-candidate.yml');
    $validation = $candidate['jobs']['application-validation'] ?? [];
    $applicationValidation = v4xWorkflow('application-validation.yml');
    $baseInput = $applicationValidation['on']['workflow_call']['inputs']['base_sha'] ?? [];
    $workflowCallInput = $applicationValidation['on']['workflow_call']['inputs']['source_sha'] ?? [];

    expect($validation['needs'] ?? null)->toBe('authorize')
        ->and($validation['uses'] ?? null)->toBe('./.github/workflows/application-validation.yml')
        ->and($validation['with']['base_sha'] ?? null)->toBe('${{ inputs.base_sha }}')
        ->and($validation['with']['source_sha'] ?? null)->toBe('${{ inputs.candidate_sha }}')
        ->and($validation['permissions'] ?? null)->toBe(['contents' => 'read'])
        ->and($baseInput)->toBe([
            'description' => 'Exact base commit SHA for changed-file validation',
            'required' => false,
            'type' => 'string',
            'default' => '',
        ])
        ->and($workflowCallInput)->toBe([
            'description' => 'Exact commit SHA to validate',
            'required' => false,
            'type' => 'string',
            'default' => '',
        ]);

    foreach ($applicationValidation['jobs'] ?? [] as $jobName => $job) {
        foreach ($job['steps'] ?? [] as $step) {
            if (! str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@')) {
                continue;
            }

            expect($step['with']['ref'] ?? null)->toBe(
                '${{ inputs.source_sha || github.sha }}',
                "validation checkout in {$jobName} must bind the requested source SHA",
            );
        }
    }

    $serialized = Yaml::dump($applicationValidation, 12, 2);

    expect($serialized)
        ->toContain('gate-v4x-candidate.yml')
        ->toContain('VALIDATION_SOURCE_SHA')
        ->not->toContain('application-validation-${{ github.sha }}');
});

it('reuses a green self-hosted candidate gate before production validation', function () {
    $workflow = v4xWorkflow('coolify-production-build.yml');
    $jobs = $workflow['jobs'] ?? [];
    $preflight = $jobs['candidate-preflight'] ?? [];
    $validation = $jobs['application-validation'] ?? [];
    $required = $jobs['validation-required'] ?? [];
    $resolveVersion = $jobs['resolve-version'] ?? [];
    $verify = collect($preflight['steps'] ?? [])->firstWhere('id', 'verify');

    expect($preflight['runs-on'] ?? null)->toBe(williamCallahanTrustedRunner())
        ->and($preflight['permissions'] ?? null)->toBe([
            'actions' => 'read',
            'contents' => 'read',
        ])
        ->and($preflight['outputs']['verified'] ?? null)->toBe('${{ steps.verify.outputs.verified }}')
        ->and($verify)->toBeArray()
        ->and((string) ($verify['run'] ?? ''))->toContain('verify-v4x-candidate-gate.sh')
        ->and($validation['needs'] ?? null)->toBe('candidate-preflight')
        ->and($validation['if'] ?? null)->toBe('${{ always() && needs.candidate-preflight.outputs.verified != \'true\' }}')
        ->and($validation['with']['base_sha'] ?? null)->toBe('${{ github.event.before }}')
        ->and($validation['with']['source_sha'] ?? null)->toBe('${{ github.sha }}')
        ->and($required['needs'] ?? null)->toBe(['candidate-preflight', 'application-validation'])
        ->and($required['if'] ?? null)->toBe('always()')
        ->and($required['runs-on'] ?? null)->toBe('ubuntu-24.04')
        ->and($resolveVersion['needs'] ?? null)->toBe('validation-required');
});

it('uses the organization label for the privileged fork promotion', function () {
    $workflow = v4xWorkflow('publish-linux-image.yml');
    $forkRelease = $workflow['jobs']['fork-release'] ?? [];

    expect($forkRelease['runs-on'] ?? null)
        ->toBe(williamCallahanTrustedRunner())
        ->and($forkRelease['permissions'] ?? null)->toBe(['contents' => 'write']);

    foreach ($forkRelease['steps'] ?? [] as $step) {
        expect((string) ($step['uses'] ?? ''))->not->toStartWith('actions/checkout@');
    }
});
