<?php

use Symfony\Component\Yaml\Yaml;

it('updates the protected changelog through a validated pull request', function () {
    $workflowPath = dirname(__DIR__, 2).'/.github/workflows/generate-changelog.yml';
    $workflowSource = file_get_contents($workflowPath);
    $workflow = Yaml::parseFile($workflowPath);
    $publishStep = collect($workflow['jobs']['changelog']['steps'])
        ->firstWhere('name', 'Publish protected changelog pull request');

    expect($workflow['permissions'])
        ->toMatchArray([
            'actions' => 'write',
            'contents' => 'write',
            'pull-requests' => 'write',
            'statuses' => 'write',
        ])
        ->and($workflow['concurrency'])
        ->toMatchArray([
            'group' => 'generate-changelog',
            'cancel-in-progress' => true,
        ])
        ->and($workflowSource)
        ->toContain('actions/checkout@93cb6efe18208431cddfb8368fd83d5badbf9bfd')
        ->toContain('orhun/git-cliff-action@f50e11560dce63f7c33227798f90b924471a88b5')
        ->and($publishStep['run'])
        ->toContain("bot_branch='automation/changelog'")
        ->toContain('git push --force-with-lease=')
        ->toContain('gh pr create')
        ->toContain('gh workflow run application-validation.yml')
        ->toContain('-f source_sha="${head_sha}"')
        ->toContain('gh run watch "${validation_run_id}" --exit-status')
        ->toContain('statuses/${head_sha}')
        ->toContain("context='Application validation required'")
        ->toContain('--json state,autoMergeRequest')
        ->toContain('if [ "${pr_merge_state}" = \'unarmed\' ]')
        ->toContain('gh pr merge --auto --squash --delete-branch')
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
