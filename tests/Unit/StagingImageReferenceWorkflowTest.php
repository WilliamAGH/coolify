<?php

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

function stagingImageReferenceAlias(string $branchName): string
{
    return substr(str_replace('/', '-', $branchName), 0, 63).'-'.hash('sha256', $branchName);
}

it('publishes a validated branch alias only after staging validation succeeds', function () {
    $root = dirname(__DIR__, 2);
    $publisher = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $workflow = Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml');
    $jobs = $workflow['jobs'];
    $resolver = $jobs['resolve-staging-alias'] ?? [];
    $publish = $jobs['publish'] ?? [];
    $reference = $jobs['export-staging-reference'] ?? [];
    $aliasStep = $resolver['steps'][0] ?? [];
    $referenceStep = $reference['steps'][0] ?? [];

    expect($publisher['on']['workflow_call']['inputs']['staging_alias']['type'] ?? null)->toBe('string')
        ->and($publisher['on']['workflow_call']['outputs'] ?? [])->toHaveKeys(['staging_digest', 'staging_image'])
        ->and($resolver['needs'] ?? null)->toBe('authorize')
        ->and($resolver['permissions'] ?? null)->toBe([])
        ->and($resolver['outputs']['staging_alias'] ?? null)->toBe('${{ steps.alias.outputs.staging_alias }}')
        ->and($aliasStep['id'] ?? null)->toBe('alias')
        ->and($aliasStep['shell'] ?? null)->toBe('bash')
        ->and($aliasStep['env']['BRANCH_NAME'] ?? null)->toBe('${{ github.ref_name }}')
        ->and($publish['uses'] ?? null)->toBe('./.github/workflows/publish-linux-image.yml')
        ->and($publish['needs'] ?? null)->toBe(['application-validation', 'resolve-staging-alias'])
        ->and($publish['if'] ?? null)->toBeNull()
        ->and($publish['concurrency']['group'] ?? null)->toBe('linux-image-release-coollabsio/coolify-staging')
        ->and($publish['concurrency']['cancel-in-progress'] ?? null)->toBeFalse()
        ->and($publish['concurrency']['queue'] ?? null)->toBeNull()
        ->and($publish['with']['staging_alias'] ?? null)->toBe('${{ needs.resolve-staging-alias.outputs.staging_alias }}')
        ->and($publish['with']['semantic_version'] ?? null)->toBe('')
        ->and($publish['with']['publish_latest'] ?? null)->toBeFalse()
        ->and($publish)->not->toHaveKey('runs-on')
        ->and($reference['needs'] ?? null)->toBe('publish')
        ->and($reference['if'] ?? null)->toBeNull()
        ->and($reference['permissions'] ?? null)->toBe([])
        ->and($referenceStep['env']['STAGING_DIGEST'] ?? null)->toBe('${{ needs.publish.outputs.staging_digest }}')
        ->and($referenceStep['env']['STAGING_IMAGE'] ?? null)->toBe('${{ needs.publish.outputs.staging_image }}')
        ->and((string) ($referenceStep['run'] ?? ''))->toContain('$GITHUB_STEP_SUMMARY');
});

it('derives OCI aliases from staging branch names', function (string $branchName, ?string $expectedAlias, bool $shouldSucceed) {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml');
    $script = (string) ($workflow['jobs']['resolve-staging-alias']['steps'][0]['run'] ?? '');
    $output = tempnam(sys_get_temp_dir(), 'coolify-staging-alias-');

    expect($output)->toBeString();

    try {
        $process = new Process(['bash', '-c', $script], $root, [
            'BRANCH_NAME' => $branchName,
            'GITHUB_OUTPUT' => $output,
        ]);
        $process->run();

        expect($process->isSuccessful())->toBe($shouldSucceed);

        if ($shouldSucceed) {
            parse_str(str_replace("\n", '&', trim((string) file_get_contents($output))), $result);

            expect($result['staging_alias'] ?? null)->toBe($expectedAlias);
        }
    } finally {
        unlink($output);
    }
})->with([
    'slash-delimited branch' => ['feature/blue-green', stagingImageReferenceAlias('feature/blue-green'), true],
    'hyphenated branch' => ['feature-blue-green', stagingImageReferenceAlias('feature-blue-green'), true],
    'long branch' => [str_repeat('a', 128), stagingImageReferenceAlias(str_repeat('a', 128)), true],
    'Docker-invalid branch character' => ['feature@blue', null, false],
]);

it('makes aliases distinct when branch sanitization would otherwise collide', function () {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml');
    $script = (string) ($workflow['jobs']['resolve-staging-alias']['steps'][0]['run'] ?? '');
    $aliases = [];

    foreach (['feature/blue-green', 'feature-blue-green'] as $branchName) {
        $output = tempnam(sys_get_temp_dir(), 'coolify-staging-alias-');
        expect($output)->toBeString();

        try {
            $process = new Process(['bash', '-c', $script], $root, [
                'BRANCH_NAME' => $branchName,
                'GITHUB_OUTPUT' => $output,
            ]);
            $process->mustRun();
            parse_str(str_replace("\n", '&', trim((string) file_get_contents($output))), $result);
            $aliases[] = $result['staging_alias'] ?? null;
        } finally {
            unlink($output);
        }
    }

    expect($aliases)->toBe([
        stagingImageReferenceAlias('feature/blue-green'),
        stagingImageReferenceAlias('feature-blue-green'),
    ])->not->toHaveCount(1)
        ->and($aliases[0])->not->toBe($aliases[1]);
});

it('renders the staged image outputs as a consumable workflow reference', function () {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/coolify-staging-build.yml');
    $script = (string) ($workflow['jobs']['export-staging-reference']['steps'][0]['run'] ?? '');
    $summary = tempnam(sys_get_temp_dir(), 'coolify-staging-summary-');
    $digest = 'sha256:'.str_repeat('a', 64);
    $image = 'docker.io/coollabsio/coolify-staging:feature-blue-green';

    expect($summary)->toBeString();

    try {
        $process = new Process(['bash', '-c', $script], $root, [
            'GITHUB_STEP_SUMMARY' => $summary,
            'STAGING_DIGEST' => $digest,
            'STAGING_IMAGE' => $image,
        ]);
        $process->run();

        expect($process->isSuccessful())->toBeTrue()
            ->and(file_get_contents($summary))->toBe("## Staged image\n\n- Immutable digest: `{$digest}`\n- Consumer image: `{$image}`\n");
    } finally {
        unlink($summary);
    }
});
