<?php

use App\Models\GithubApp;
use App\Models\User;
use App\Policies\GithubAppPolicy;
use Tests\TestCase;

uses(TestCase::class);

it('allows any user to view any github apps', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new GithubAppPolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows any user to view system-wide github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $githubApp = new GithubApp([
        'team_id' => 1,
        'is_system_wide' => true,
    ]);

    $policy = new GithubAppPolicy;
    expect($policy->view($user, $githubApp))->toBeTrue();
});

it('allows team member to view non-system-wide github app', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);
    $githubApp = new GithubApp([
        'team_id' => 1,
        'is_system_wide' => false,
    ]);

    $policy = new GithubAppPolicy;
    expect($policy->view($user, $githubApp))->toBeTrue();
});

it('denies non-team member to view non-system-wide github app', function () {
    $teams = collect([
        (object) ['id' => 2, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);
    $githubApp = new GithubApp([
        'team_id' => 1,
        'is_system_wide' => false,
    ]);

    $policy = new GithubAppPolicy;
    expect($policy->view($user, $githubApp))->toBeFalse();
});

it('allows admin to create github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new GithubAppPolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies non-admin to create github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new GithubAppPolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows user with system access to update system-wide github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('canAccessSystemResources')->andReturn(true);
    $githubApp = new GithubApp([
        'team_id' => 1,
        'is_system_wide' => true,
    ]);

    $policy = new GithubAppPolicy;
    expect($policy->update($user, $githubApp))->toBeTrue();
});

it('denies user without system access to update system-wide github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('canAccessSystemResources')->andReturn(false);
    $githubApp = new GithubApp([
        'team_id' => 1,
        'is_system_wide' => true,
    ]);

    $policy = new GithubAppPolicy;
    expect($policy->update($user, $githubApp))->toBeFalse();
});

it('allows team admin to update non-system-wide github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);
    $githubApp = new GithubApp([
        'team_id' => 1,
        'is_system_wide' => false,
    ]);

    $policy = new GithubAppPolicy;
    expect($policy->update($user, $githubApp))->toBeTrue();
});

it('denies team member to update non-system-wide github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);
    $githubApp = new GithubApp([
        'team_id' => 1,
        'is_system_wide' => false,
    ]);

    $policy = new GithubAppPolicy;
    expect($policy->update($user, $githubApp))->toBeFalse();
});

it('allows user with system access to delete system-wide github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('canAccessSystemResources')->andReturn(true);
    $githubApp = new GithubApp([
        'team_id' => 1,
        'is_system_wide' => true,
    ]);

    $policy = new GithubAppPolicy;
    expect($policy->delete($user, $githubApp))->toBeTrue();
});

it('denies user without system access to delete system-wide github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('canAccessSystemResources')->andReturn(false);
    $githubApp = new GithubApp([
        'team_id' => 1,
        'is_system_wide' => true,
    ]);

    $policy = new GithubAppPolicy;
    expect($policy->delete($user, $githubApp))->toBeFalse();
});

it('allows team admin to delete non-system-wide github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);
    $githubApp = new GithubApp([
        'team_id' => 1,
        'is_system_wide' => false,
    ]);

    $policy = new GithubAppPolicy;
    expect($policy->delete($user, $githubApp))->toBeTrue();
});

it('denies team member to delete non-system-wide github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);
    $githubApp = new GithubApp([
        'team_id' => 1,
        'is_system_wide' => false,
    ]);

    $policy = new GithubAppPolicy;
    expect($policy->delete($user, $githubApp))->toBeFalse();
});

it('denies restore of github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $githubApp = new GithubApp([
        'team_id' => 1,
        'is_system_wide' => false,
    ]);

    $policy = new GithubAppPolicy;
    expect($policy->restore($user, $githubApp))->toBeFalse();
});

it('denies force delete of github app', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $githubApp = new GithubApp([
        'team_id' => 1,
        'is_system_wide' => false,
    ]);

    $policy = new GithubAppPolicy;
    expect($policy->forceDelete($user, $githubApp))->toBeFalse();
});
