<?php

use Symfony\Component\Yaml\Yaml;

it('commits the changelog directly to staging without branches or pull requests', function () {
    $workflowPath = dirname(__DIR__, 2).'/.github/workflows/generate-changelog.yml';
    $workflowSource = file_get_contents($workflowPath);
    $workflow = Yaml::parseFile($workflowPath);
    $commitStep = collect($workflow['jobs']['changelog']['steps'])
        ->firstWhere('name', 'Commit the changelog to staging');

    expect($workflow['permissions'])
        ->toMatchArray([
            'contents' => 'write',
        ])
        ->and($workflow['concurrency'])
        ->toMatchArray([
            'group' => 'generate-changelog',
            'cancel-in-progress' => true,
        ])
        ->and($workflow['on']['push']['branches'])->toBe(['staging'])
        ->and($workflow['on']['push']['paths-ignore'])->toContain('CHANGELOG.md')
        ->and($workflowSource)
        ->toContain('actions/checkout@93cb6efe18208431cddfb8368fd83d5badbf9bfd')
        ->toContain('orhun/git-cliff-action@f50e11560dce63f7c33227798f90b924471a88b5')
        ->and($commitStep['run'])
        ->toContain('git push origin HEAD:refs/heads/staging')
        ->not->toContain('gh pr create')
        ->not->toContain('bot_branch')
        ->not->toContain('HEAD:refs/heads/v4.x')
        ->not->toContain('git push https://');
});

it('allows changelog pull requests to dispatch required validation', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/application-validation.yml');

    expect($workflow['on'])->toHaveKey('workflow_dispatch')
        ->and($workflow['on']['workflow_dispatch']['inputs']['source_sha'] ?? null)
        ->toMatchArray([
            'description' => 'Exact commit SHA to validate',
            'required' => false,
            'type' => 'string',
            'default' => '',
        ]);
});
