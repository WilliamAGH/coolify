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
