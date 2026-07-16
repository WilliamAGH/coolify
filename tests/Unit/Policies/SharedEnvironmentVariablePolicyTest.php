<?php

use App\Models\SharedEnvironmentVariable;
use App\Models\User;
use App\Policies\SharedEnvironmentVariablePolicy;
use Tests\TestCase;

uses(TestCase::class);

it('allows any user to view any shared environment variables', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view their team shared environment variable', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);
    $sharedEnvironmentVariable = new SharedEnvironmentVariable(['team_id' => 1]);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->view($user, $sharedEnvironmentVariable))->toBeTrue();
});

it('denies non-team member to view shared environment variable', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);
    $sharedEnvironmentVariable = new SharedEnvironmentVariable(['team_id' => 2]);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->view($user, $sharedEnvironmentVariable))->toBeFalse();
});

it('allows admin to create shared environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies non-admin to create shared environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows team admin to update shared environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);
    $sharedEnvironmentVariable = new SharedEnvironmentVariable(['team_id' => 1]);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->update($user, $sharedEnvironmentVariable))->toBeTrue();
});

it('denies team member to update shared environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);
    $sharedEnvironmentVariable = new SharedEnvironmentVariable(['team_id' => 1]);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->update($user, $sharedEnvironmentVariable))->toBeFalse();
});

it('allows team admin to delete shared environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);
    $sharedEnvironmentVariable = new SharedEnvironmentVariable(['team_id' => 1]);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->delete($user, $sharedEnvironmentVariable))->toBeTrue();
});

it('denies team member to delete shared environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);
    $sharedEnvironmentVariable = new SharedEnvironmentVariable(['team_id' => 1]);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->delete($user, $sharedEnvironmentVariable))->toBeFalse();
});

it('denies restore of shared environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $sharedEnvironmentVariable = new SharedEnvironmentVariable(['team_id' => 1]);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->restore($user, $sharedEnvironmentVariable))->toBeFalse();
});

it('denies force delete of shared environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $sharedEnvironmentVariable = new SharedEnvironmentVariable(['team_id' => 1]);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->forceDelete($user, $sharedEnvironmentVariable))->toBeFalse();
});

it('allows team admin to manage environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);
    $sharedEnvironmentVariable = new SharedEnvironmentVariable(['team_id' => 1]);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->manageEnvironment($user, $sharedEnvironmentVariable))->toBeTrue();
});

it('denies team member to manage environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(false);
    $sharedEnvironmentVariable = new SharedEnvironmentVariable(['team_id' => 1]);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->manageEnvironment($user, $sharedEnvironmentVariable))->toBeFalse();
});
