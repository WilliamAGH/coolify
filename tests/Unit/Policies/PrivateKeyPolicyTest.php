<?php

use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use App\Policies\PrivateKeyPolicy;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Tests\TestCase;

uses(TestCase::class);

function privateKeyPolicyUser(int $teamId, string $role): User
{
    $team = (new Team)->forceFill(['id' => $teamId]);
    $team->setRelation('pivot', (new Pivot)->forceFill(['role' => $role]));

    return (new User)->setRelation('teams', collect([$team]));
}

it('allows root team admin to view system private key', function () {
    $user = privateKeyPolicyUser(0, 'admin');
    $privateKey = new PrivateKey(['team_id' => 0]);

    $policy = new PrivateKeyPolicy;
    expect($policy->view($user, $privateKey))->toBeTrue();
});

it('allows root team owner to view system private key', function () {
    $user = privateKeyPolicyUser(0, 'owner');
    $privateKey = new PrivateKey(['team_id' => 0]);

    $policy = new PrivateKeyPolicy;
    expect($policy->view($user, $privateKey))->toBeTrue();
});

it('denies regular member of root team to view system private key', function () {
    $user = privateKeyPolicyUser(0, 'member');
    $privateKey = new PrivateKey(['team_id' => 0]);

    $policy = new PrivateKeyPolicy;
    expect($policy->view($user, $privateKey))->toBeFalse();
});

it('denies non-root team member to view system private key', function () {
    $user = privateKeyPolicyUser(1, 'owner');
    $privateKey = new PrivateKey(['team_id' => 0]);

    $policy = new PrivateKeyPolicy;
    expect($policy->view($user, $privateKey))->toBeFalse();
});

it('allows team member to view their own team private key', function () {
    $user = privateKeyPolicyUser(1, 'member');
    $privateKey = new PrivateKey(['team_id' => 1]);

    $policy = new PrivateKeyPolicy;
    expect($policy->view($user, $privateKey))->toBeTrue();
});

it('denies team member to view another team private key', function () {
    $user = privateKeyPolicyUser(1, 'owner');
    $privateKey = new PrivateKey(['team_id' => 2]);

    $policy = new PrivateKeyPolicy;
    expect($policy->view($user, $privateKey))->toBeFalse();
});

it('allows root team admin to update system private key', function () {
    $user = privateKeyPolicyUser(0, 'admin');
    $privateKey = new PrivateKey(['team_id' => 0]);

    $policy = new PrivateKeyPolicy;
    expect($policy->update($user, $privateKey))->toBeTrue();
});

it('denies root team member to update system private key', function () {
    $user = privateKeyPolicyUser(0, 'member');
    $privateKey = new PrivateKey(['team_id' => 0]);

    $policy = new PrivateKeyPolicy;
    expect($policy->update($user, $privateKey))->toBeFalse();
});

it('allows team admin to update their own team private key', function () {
    $user = privateKeyPolicyUser(1, 'admin');
    $privateKey = new PrivateKey(['team_id' => 1]);

    $policy = new PrivateKeyPolicy;
    expect($policy->update($user, $privateKey))->toBeTrue();
});

it('denies team member to update their own team private key', function () {
    $user = privateKeyPolicyUser(1, 'member');
    $privateKey = new PrivateKey(['team_id' => 1]);

    $policy = new PrivateKeyPolicy;
    expect($policy->update($user, $privateKey))->toBeFalse();
});

it('allows root team admin to delete system private key', function () {
    $user = privateKeyPolicyUser(0, 'admin');
    $privateKey = new PrivateKey(['team_id' => 0]);

    $policy = new PrivateKeyPolicy;
    expect($policy->delete($user, $privateKey))->toBeTrue();
});

it('denies root team member to delete system private key', function () {
    $user = privateKeyPolicyUser(0, 'member');
    $privateKey = new PrivateKey(['team_id' => 0]);

    $policy = new PrivateKeyPolicy;
    expect($policy->delete($user, $privateKey))->toBeFalse();
});
