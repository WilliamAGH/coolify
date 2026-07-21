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
    $resolver = collect($authorize['steps'] ?? [])->firstWhere('name', 'Resolve immutable candidate ref');
    $resolverScript = (string) ($resolver['with']['script'] ?? '');

    expect(array_keys($inputs))->toBe(['candidate_sha', 'candidate_ref', 'base_sha'])
        ->and($authorize['runs-on'] ?? null)->toBe(williamCallahanTrustedRunner())
        ->and($authorize['permissions'] ?? null)->toBe(['contents' => 'read'])
        ->and((string) $script)
        ->toContain('^ship/v4x/$FROZEN_SHA/[0-9a-f]{40}$')
        ->toContain('GITHUB_SHA')
        ->toContain('FROZEN_BASE_SHA')
        ->and($resolverScript)
        ->toContain('compareCommitsWithBasehead')
        ->toContain("['ahead', 'identical'].includes(comparison.data.status)")
        ->toContain('protectedPolicyPaths')
        ->toContain("'.github/workflows/gate-v4x-candidate.yml'")
        ->toContain("'scripts/dev/ship.sh'")
        ->toContain('changedFiles.length >= 300')
        ->toContain('Candidate changes protected release policy');

    foreach ($authorize['steps'] ?? [] as $step) {
        expect((string) ($step['uses'] ?? ''))->not->toStartWith('actions/checkout@');
    }
});

it('runs hosted reusable validation against the exact authorized candidate sha', function () {
    $candidate = v4xWorkflow('gate-v4x-candidate.yml');
    $validation = $candidate['jobs']['application-validation'] ?? [];
    $attestation = $candidate['jobs']['attest-candidate'] ?? [];
    $applicationValidation = v4xWorkflow('application-validation.yml');
    $baseInput = $applicationValidation['on']['workflow_call']['inputs']['base_sha'] ?? [];
    $workflowCallInput = $applicationValidation['on']['workflow_call']['inputs']['source_sha'] ?? [];
    $runtime = $applicationValidation['jobs']['testing-host-runtime'] ?? [];
    $runtimeRequirement = collect($applicationValidation['jobs']['required']['steps'] ?? [])
        ->firstWhere('name', 'Require every generic validation job');

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
        ])
        ->and($attestation['needs'] ?? null)->toBe('application-validation')
        ->and($attestation['runs-on'] ?? null)->toBe('ubuntu-24.04')
        ->and($attestation['permissions'] ?? null)->toBe([
            'checks' => 'write',
            'contents' => 'read',
        ])
        ->and($runtime['if'] ?? null)
        ->toBe('${{ github.event_name == \'pull_request\' || inputs.source_sha != \'\' }}')
        ->and($runtimeRequirement['env']['VALIDATION_SOURCE_SHA'] ?? null)
        ->toBe('${{ inputs.source_sha }}')
        ->and((string) ($runtimeRequirement['run'] ?? ''))
        ->toContain('[[ "$EVENT_NAME" == pull_request || -n "$VALIDATION_SOURCE_SHA" ]]')
        ->toContain('Testing-host runtime validation did not succeed for the exact source');

    $attestationScript = collect($attestation['steps'] ?? [])
        ->firstWhere('name', 'Attest exact validated candidate')['with']['script'] ?? '';

    expect((string) $attestationScript)
        ->toContain('github.rest.checks.create')
        ->toContain("name: 'Application validation required'")
        ->toContain('head_sha: process.env.FROZEN_SHA');

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

it('reuses a green candidate gate without coupling fallback to self-hosted availability', function () {
    $workflow = v4xWorkflow('coolify-production-build.yml');
    $jobs = $workflow['jobs'] ?? [];
    $preflight = $jobs['candidate-preflight'] ?? [];
    $validation = $jobs['application-validation'] ?? [];
    $required = $jobs['validation-required'] ?? [];
    $resolveVersion = $jobs['resolve-version'] ?? [];
    $checkout = collect($preflight['steps'] ?? [])->firstWhere('name', 'Check out protected base verifier');
    $verify = collect($preflight['steps'] ?? [])->firstWhere('id', 'verify');

    expect($preflight['runs-on'] ?? null)->toBe('ubuntu-24.04')
        ->and($preflight['permissions'] ?? null)->toBe([
            'actions' => 'read',
            'contents' => 'read',
        ])
        ->and($preflight['outputs']['verified'] ?? null)->toBe('${{ steps.verify.outputs.verified }}')
        ->and($checkout['id'] ?? null)->toBe('trusted_base')
        ->and($checkout['continue-on-error'] ?? null)->toBeTrue()
        ->and($checkout['with']['ref'] ?? null)->toBe('${{ github.event.before }}')
        ->and($checkout['with']['path'] ?? null)->toBe('trusted-v4x')
        ->and($verify)->toBeArray()
        ->and($verify['if'] ?? null)->toBe('always()')
        ->and($verify['env']['CHECKOUT_OUTCOME'] ?? null)->toBe('${{ steps.trusted_base.outcome }}')
        ->and((string) ($verify['run'] ?? ''))
        ->toContain('[[ "$CHECKOUT_OUTCOME" == success ]]')
        ->toContain('trusted-v4x/scripts/ci/verify-v4x-candidate-gate.sh')
        ->toContain('No trusted candidate preflight matched this v4.x push; hosted validation will run.')
        ->not->toContain('::notice::')
        ->not->toContain('if scripts/ci/verify-v4x-candidate-gate.sh')
        ->and($validation['needs'] ?? null)->toBe('candidate-preflight')
        ->and($validation['if'] ?? null)->toBe('${{ always() && needs.candidate-preflight.outputs.verified != \'true\' }}')
        ->and($validation['with']['base_sha'] ?? null)->toBe('${{ github.event.before }}')
        ->and($validation['with']['source_sha'] ?? null)->toBe('${{ github.sha }}')
        ->and($required['needs'] ?? null)->toBe(['candidate-preflight', 'application-validation'])
        ->and($required['if'] ?? null)->toBe('always()')
        ->and($required['runs-on'] ?? null)->toBe('ubuntu-24.04')
        ->and($resolveVersion['needs'] ?? null)->toBe('validation-required');
});

it('runs the v4.x candidate regression owners in application validation', function () {
    $workflow = v4xWorkflow('application-validation.yml');
    $php = collect($workflow['jobs']['php']['steps'] ?? [])
        ->firstWhere('name', 'Run release and version-consumer tests');
    $shell = collect($workflow['jobs']['workflow-and-shell']['steps'] ?? [])
        ->firstWhere('name', 'Run v4.x candidate ship contract');

    expect((string) ($php['run'] ?? ''))
        ->toContain('tests/Unit/V4xCandidateWorkflowTest.php')
        ->and($shell['run'] ?? null)->toBe('scripts/dev/ship.test.sh');
});

it('uses the organization label for the privileged fork promotion', function () {
    $workflow = v4xWorkflow('publish-linux-image.yml');
    $forkRelease = $workflow['jobs']['fork-release'] ?? [];

    expect($forkRelease['runs-on'] ?? null)
        ->toBe(williamCallahanTrustedRunner())
        ->and($forkRelease['permissions'] ?? null)->toBe(['contents' => 'write']);

    $checkoutSteps = collect($forkRelease['steps'] ?? [])
        ->filter(static fn (array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@'))
        ->values()
        ->all();
    expect($checkoutSteps)->toBe([[
        'uses' => 'actions/checkout@93cb6efe18208431cddfb8368fd83d5badbf9bfd',
        'with' => [
            'fetch-depth' => 0,
            'persist-credentials' => false,
            'ref' => '${{ github.sha }}',
        ],
    ]]);
});
