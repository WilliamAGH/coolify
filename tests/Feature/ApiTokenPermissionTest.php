<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Policies\ApiTokenPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'is_api_enabled' => true,
    ]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);
});

describe('POST /api/v1/projects', function () {
    test('read-only token cannot create a project', function () {
        $token = $this->user->createToken('read-only', ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/projects', [
            'name' => 'Test Project',
        ]);

        $response->assertForbidden();
    });

    test('write token can create a project', function () {
        $token = $this->user->createToken('write-token', ['write']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/projects', [
            'name' => 'Test Project',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['uuid']);
    });

    test('root token can create a project', function () {
        $token = $this->user->createToken('root-token', ['root']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/projects', [
            'name' => 'Test Project',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['uuid']);
    });
});

describe('POST /api/v1/servers', function () {
    test('read-only token cannot create a server', function () {
        $token = $this->user->createToken('read-only', ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/servers', [
            'name' => 'Test Server',
            'ip' => '1.2.3.4',
            'private_key_uuid' => 'fake-uuid',
        ]);

        $response->assertForbidden();
    });
});

describe('GET /api/v1/servers/{uuid}/validate', function () {
    test('read-only token cannot trigger server validation', function () {
        $token = $this->user->createToken('read-only', ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ])->getJson('/api/v1/servers/fake-uuid/validate');

        $response->assertForbidden();
    });
});

describe('POST /api/v1/cloud-tokens/{uuid}/validate', function () {
    test('read-only token cannot validate cloud provider token', function () {
        $token = $this->user->createToken('read-only', ['read']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/cloud-tokens/fake-uuid/validate');

        $response->assertForbidden();
    });
});

test('team members cannot grant write or root token permissions', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $member->load('teams');

    $this->actingAs($member);
    refreshSession($this->team);

    $policy = new ApiTokenPolicy;

    expect($policy->useWritePermissions($member))->toBeFalse()
        ->and($policy->useRootPermissions($member))->toBeFalse();
});
