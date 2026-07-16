<?php

use App\Livewire\Source\Github\Create;
use App\Models\GithubApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

trait InteractsWithGithubSourceCreation
{
    public Team $team;

    public User $user;
}

uses(RefreshDatabase::class, InteractsWithGithubSourceCreation::class);

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->user->load('teams');

    $this->actingAs($this->user);
    refreshSession($this->team);
});

describe('GitHub Source Create Component', function () {
    test('creates github app with default values', function () {
        Livewire::test(Create::class)
            ->assertSuccessful()
            ->set('name', 'my-test-app')
            ->call('createGitHubApp')
            ->assertRedirect();

        $githubApp = GithubApp::query()->where('name', 'my-test-app')->sole();

        expect($githubApp->name)->toBe('my-test-app');
        expect($githubApp->api_url)->toBe('https://api.github.com');
        expect($githubApp->html_url)->toBe('https://github.com');
        expect($githubApp->custom_user)->toBe('git');
        expect($githubApp->custom_port)->toBe(22);
        expect($githubApp->is_system_wide)->toBeFalse();
        expect($githubApp->team_id)->toBe($this->team->id);
    });

    test('creates github app with system wide enabled', function () {
        Livewire::test(Create::class)
            ->assertSuccessful()
            ->set('name', 'system-wide-app')
            ->set('is_system_wide', true)
            ->call('createGitHubApp')
            ->assertRedirect();

        $githubApp = GithubApp::query()->where('name', 'system-wide-app')->sole();

        expect($githubApp->is_system_wide)->toBeTrue();
    });

    test('creates github app with custom organization', function () {
        Livewire::test(Create::class)
            ->assertSuccessful()
            ->set('name', 'org-app')
            ->set('organization', 'my-org')
            ->call('createGitHubApp')
            ->assertRedirect();

        $githubApp = GithubApp::query()->where('name', 'org-app')->sole();

        expect($githubApp->organization)->toBe('my-org');
    });

    test('creates GitHub Enterprise source with custom git settings', function () {
        Livewire::test(Create::class)
            ->assertSuccessful()
            ->set('name', 'enterprise-app')
            ->set('api_url', 'https://enterprise.github.com/api/v3')
            ->set('html_url', 'https://enterprise.github.com')
            ->set('custom_user', 'git-custom')
            ->set('custom_port', 2222)
            ->call('createGitHubApp')
            ->assertRedirect();

        $githubApp = GithubApp::query()->where('name', 'enterprise-app')->sole();

        expect($githubApp->api_url)->toBe('https://enterprise.github.com/api/v3');
        expect($githubApp->html_url)->toBe('https://enterprise.github.com');
        expect($githubApp->custom_user)->toBe('git-custom');
        expect($githubApp->custom_port)->toBe(2222);
    });

    test('validates required fields', function () {
        Livewire::test(Create::class)
            ->assertSuccessful()
            ->set('name', '')
            ->call('createGitHubApp')
            ->assertHasErrors(['name']);
    });

    test('redirects to github app show page after creation', function () {
        $component = Livewire::test(Create::class)
            ->set('name', 'redirect-test')
            ->call('createGitHubApp');

        $githubApp = GithubApp::query()->where('name', 'redirect-test')->sole();

        $component->assertRedirect(route('source.github.show', ['github_app_uuid' => $githubApp->uuid]));
    });
});
