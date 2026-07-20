<?php

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

it('requires native OCI runtime acceptance before a production or staging index can be released', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/publish-linux-image.yml');
    $jobs = $workflow['jobs'] ?? [];
    $buildAndScan = $jobs['build-and-scan'] ?? [];

    $platformsByArchitecture = [];
    foreach ($buildAndScan['strategy']['matrix']['include'] ?? [] as $platform) {
        $platformsByArchitecture[$platform['arch']] = [
            'platform' => $platform['platform'],
            'runner' => $platform['runner'],
        ];
    }

    $runtimeGate = null;
    foreach ($buildAndScan['steps'] ?? [] as $step) {
        if (($step['name'] ?? null) === 'Verify the exact production or staging OCI archive and runtime') {
            $runtimeGate = $step;
            break;
        }
    }

    expect($platformsByArchitecture)->toBe([
        'amd64' => ['platform' => 'linux/amd64', 'runner' => 'ubuntu-24.04'],
        'arm64' => ['platform' => 'linux/arm64', 'runner' => 'ubuntu-24.04-arm'],
    ])->and($buildAndScan['continue-on-error'] ?? false)->toBeFalse()
        ->and($runtimeGate)->toBeArray()
        ->and($runtimeGate['if'] ?? null)->toBe("inputs.release_kind == 'production' || inputs.release_kind == 'staging'")
        ->and($runtimeGate['continue-on-error'] ?? false)->toBeFalse()
        ->and($runtimeGate['run'] ?? '')->toContain('OCI_ARCHIVE="$archive"')
        ->toContain('OCI_PLATFORM="$PLATFORM"')
        ->toContain('tests/Integration/VerifyOciArchiveImage.sh')
        ->not->toContain('PassiveControlPlaneImageTest.sh')
        ->and($jobs['stage-candidates']['needs'] ?? null)->toBe(['validate-inputs', 'build-and-scan'])
        ->and($jobs['release']['needs'] ?? null)->toBe(['stage-candidates', 'attest-and-verify']);
});

it('accepts a safe staging-only alias and publishes it through the serialized release owner', function () {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/publish-linux-image.yml');
    $workflowCall = $workflow['on']['workflow_call'] ?? [];
    $jobs = $workflow['jobs'] ?? [];
    $targetStep = null;
    $stagingAliasStep = null;
    foreach ($jobs['validate-inputs']['steps'] ?? [] as $step) {
        if (($step['id'] ?? null) === 'target') {
            $targetStep = $step;
        }
    }
    foreach ($jobs['release']['steps'] ?? [] as $step) {
        if (($step['id'] ?? null) === 'staging-alias') {
            $stagingAliasStep = $step;
        }
    }

    expect($workflowCall['inputs']['staging_alias'] ?? null)->toBe([
        'default' => '',
        'required' => false,
        'type' => 'string',
    ])->and($workflowCall['outputs']['staging_digest']['value'] ?? null)
        ->toBe('${{ jobs.release.outputs.staging_digest }}')
        ->and($workflowCall['outputs']['staging_image']['value'] ?? null)
        ->toBe('${{ jobs.release.outputs.staging_image }}')
        ->and($jobs['release']['outputs']['staging_digest'] ?? null)
        ->toBe('${{ steps.staging-alias.outputs.digest }}')
        ->and($jobs['release']['outputs']['staging_image'] ?? null)
        ->toBe('${{ steps.staging-alias.outputs.image }}')
        ->and($stagingAliasStep['if'] ?? null)->toBe('${{ inputs.release_kind == \'staging\' }}')
        ->and($stagingAliasStep['run'] ?? '')
        ->toContain('scripts/publish-linux-image.sh promote-alias')
        ->toContain('"$STAGING_ALIAS"')
        ->toContain('"$INDEX_DIGEST"')
        ->toContain('"$AMD64_DIGEST"')
        ->toContain('"$ARM64_DIGEST"')
        ->toContain('"$GITHUB_RUN_ID"');

    $targetScript = (string) ($targetStep['run'] ?? '');
    $semanticVersionPattern = (string) ($targetStep['env']['SEMANTIC_VERSION_PATTERN'] ?? '');
    $cases = [
        'staging accepts a branch alias' => [
            'artifact_name' => 'coolify-staging',
            'candidate_repository' => 'coollabsio/coolify-staging-candidates',
            'dockerfile' => 'docker/production/Dockerfile',
            'publish_latest' => 'false',
            'release_kind' => 'staging',
            'semantic_version' => '',
            'staging_alias' => 'next',
            'target_repository' => 'coollabsio/coolify-staging',
            'successful' => true,
        ],
        'staging rejects an unsafe branch alias' => [
            'artifact_name' => 'coolify-staging',
            'candidate_repository' => 'coollabsio/coolify-staging-candidates',
            'dockerfile' => 'docker/production/Dockerfile',
            'publish_latest' => 'false',
            'release_kind' => 'staging',
            'semantic_version' => '',
            'staging_alias' => 'next/unsafe',
            'target_repository' => 'coollabsio/coolify-staging',
            'successful' => false,
        ],
        'staging rejects a missing alias' => [
            'artifact_name' => 'coolify-staging',
            'candidate_repository' => 'coollabsio/coolify-staging-candidates',
            'dockerfile' => 'docker/production/Dockerfile',
            'publish_latest' => 'false',
            'release_kind' => 'staging',
            'semantic_version' => '',
            'staging_alias' => '',
            'target_repository' => 'coollabsio/coolify-staging',
            'successful' => false,
        ],
        'production rejects a staging alias' => [
            'artifact_name' => 'coolify',
            'candidate_repository' => 'coollabsio/coolify-production-staging',
            'dockerfile' => 'docker/production/Dockerfile',
            'publish_latest' => 'true',
            'release_kind' => 'production',
            'semantic_version' => '4.0.0',
            'staging_alias' => 'next',
            'target_repository' => 'coollabsio/coolify',
            'successful' => false,
        ],
        'testing host rejects a staging alias' => [
            'artifact_name' => 'coolify-testing-host',
            'candidate_repository' => 'coollabsio/coolify-testing-host-staging',
            'dockerfile' => 'docker/testing-host/Dockerfile',
            'publish_latest' => 'true',
            'release_kind' => 'testing-host',
            'semantic_version' => '',
            'staging_alias' => 'next',
            'target_repository' => 'coollabsio/coolify-testing-host',
            'successful' => false,
        ],
    ];

    foreach ($cases as $description => $case) {
        $githubOutput = tempnam(sys_get_temp_dir(), 'coolify-staging-alias-');
        expect($githubOutput)->not->toBeFalse();

        try {
            $process = new Process(['bash', '-c', $targetScript], $root, [
                'ARTIFACT_NAME' => $case['artifact_name'],
                'CANDIDATE_REPOSITORY' => $case['candidate_repository'],
                'DOCKERFILE' => $case['dockerfile'],
                'DOCKERHUB_TOKEN' => 'fixture-token',
                'DOCKERHUB_USERNAME' => 'fixture-user',
                'GITHUB_OUTPUT' => $githubOutput,
                'GITHUB_EVENT_NAME' => 'workflow_call',
                'GITHUB_REF' => 'refs/heads/v4.x',
                'GITHUB_REF_PROTECTED' => 'true',
                'GITHUB_RUN_ATTEMPT' => '1',
                'GITHUB_RUN_ID' => '1',
                'GITHUB_SHA' => str_repeat('a', 40),
                'OPERATIONAL_ACCEPTANCE' => 'false',
                'OPERATIONAL_FAILURE_MODE' => 'none',
                'OPERATIONAL_SCENARIO' => '',
                'PUBLISH_LATEST' => $case['publish_latest'],
                'RELEASE_KIND' => $case['release_kind'],
                'REPOSITORY' => 'coollabsio/coolify',
                'SEMANTIC_VERSION' => $case['semantic_version'],
                'SEMANTIC_VERSION_PATTERN' => $semanticVersionPattern,
                'STAGING_ALIAS' => $case['staging_alias'],
                'TARGET_REPOSITORY' => $case['target_repository'],
                'VALIDATE_ONLY' => 'false',
            ]);
            $process->run();

            expect($process->isSuccessful())->toBe($case['successful'], $description."\n".$process->getErrorOutput());
        } finally {
            unlink($githubOutput);
        }
    }
});
