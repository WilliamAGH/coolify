<?php

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;

afterEach(function () {
    Mockery::close();
});

it('generates GitLab source commands without reading an HTML URL', function () {
    $deploymentUuid = 'test-deployment-uuid';

    $privateKey = Mockery::mock(PrivateKey::class)->makePartial();
    $privateKey->shouldReceive('getAttribute')->with('private_key')->andReturn('fake-private-key');

    $gitlabSource = Mockery::mock(GitlabApp::class)->makePartial();
    $gitlabSource->shouldReceive('getMorphClass')->andReturn(GitlabApp::class);
    $gitlabSource->shouldReceive('getAttribute')->with('privateKey')->andReturn($privateKey);
    $gitlabSource->shouldReceive('getAttribute')->with('private_key_id')->andReturn(1);
    $gitlabSource->shouldReceive('getAttribute')->with('custom_port')->andReturn(22);
    $gitlabSource->shouldNotReceive('getAttribute')->with('html_url');

    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->shouldReceive('deploymentType')->andReturn('source');
    $application->shouldReceive('customRepository')->andReturn([
        'repository' => 'git@gitlab.com:user/repo.git',
        'port' => 22,
    ]);
    $application->shouldReceive('getAttribute')->with('source')->andReturn($gitlabSource);
    $application->source = $gitlabSource;

    $result = $application->generateGitLsRemoteCommands($deploymentUuid, false);

    expect($result)->toBeArray();
    expect($result)->toHaveKey('commands');
    expect($result['commands'])->toContain('git ls-remote');
    expect($result['commands'])->toContain('id_rsa');
    expect($result['commands'])->toContain('mkdir -p /root/.ssh');
});

it('rejects private GitHub sources without a complete HTML URL', function () {
    $githubSource = Mockery::mock(GithubApp::class)->makePartial();
    $githubSource->shouldReceive('getMorphClass')->andReturn(GithubApp::class);
    $githubSource->shouldReceive('getAttribute')->with('is_public')->andReturn(false);
    $githubSource->shouldReceive('getAttribute')->with('html_url')->andReturn('github.example.test');

    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->shouldReceive('deploymentType')->andReturn('source');
    $application->shouldReceive('customRepository')->andReturn([
        'repository' => 'owner/repository',
        'port' => 22,
    ]);
    $application->shouldReceive('getAttribute')->with('source')->andReturn($githubSource);
    $application->source = $githubSource;

    expect(fn () => $application->generateGitLsRemoteCommands('test-deployment-uuid', false))
        ->toThrow(RuntimeException::class, 'The GitHub App URL must include a scheme and host.');
});

it('generates ls-remote commands for GitLab source without private key', function () {
    $deploymentUuid = 'test-deployment-uuid';

    $gitlabSource = Mockery::mock(GitlabApp::class)->makePartial();
    $gitlabSource->shouldReceive('getMorphClass')->andReturn(GitlabApp::class);
    $gitlabSource->shouldReceive('getAttribute')->with('privateKey')->andReturn(null);
    $gitlabSource->shouldReceive('getAttribute')->with('private_key_id')->andReturn(null);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->shouldReceive('deploymentType')->andReturn('source');
    $application->shouldReceive('customRepository')->andReturn([
        'repository' => 'https://gitlab.com/user/repo.git',
        'port' => 22,
    ]);
    $application->shouldReceive('getAttribute')->with('source')->andReturn($gitlabSource);
    $application->source = $gitlabSource;

    $result = $application->generateGitLsRemoteCommands($deploymentUuid, false);

    expect($result)->toBeArray();
    expect($result)->toHaveKey('commands');
    expect($result['commands'])->toContain('git ls-remote');
    expect($result['commands'])->toContain('https://gitlab.com/user/repo.git');
    // Should NOT contain SSH key setup
    expect($result['commands'])->not->toContain('id_rsa');
});

it('does not return null for GitLab source type', function () {
    $deploymentUuid = 'test-deployment-uuid';

    $gitlabSource = Mockery::mock(GitlabApp::class)->makePartial();
    $gitlabSource->shouldReceive('getMorphClass')->andReturn(GitlabApp::class);
    $gitlabSource->shouldReceive('getAttribute')->with('privateKey')->andReturn(null);
    $gitlabSource->shouldReceive('getAttribute')->with('private_key_id')->andReturn(null);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->shouldReceive('deploymentType')->andReturn('source');
    $application->shouldReceive('customRepository')->andReturn([
        'repository' => 'https://gitlab.com/user/repo.git',
        'port' => 22,
    ]);
    $application->shouldReceive('getAttribute')->with('source')->andReturn($gitlabSource);
    $application->source = $gitlabSource;

    $lsRemoteResult = $application->generateGitLsRemoteCommands($deploymentUuid, false);
    expect($lsRemoteResult)->not->toBeNull();
    expect($lsRemoteResult)->toHaveKeys(['commands', 'branch', 'fullRepoUrl']);
});
