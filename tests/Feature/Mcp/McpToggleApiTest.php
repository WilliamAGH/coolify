<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::query()->delete();
    $settings = new InstanceSettings([
        'is_mcp_server_enabled' => false,
        'is_api_enabled' => true,
    ]);
    $settings->id = 0;
    $settings->save();
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
});

function makeRootMcpToken(User $user): string
{
    // The api.token.team middleware requires the user to be a member of the
    // token's team, so root tokens (team_id 0) need root-team membership.
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    if (! $user->teams()->where('teams.id', $rootTeam->id)->exists()) {
        $rootTeam->members()->attach($user->id, ['role' => 'owner']);
    }

    $token = $user->createToken('mcp-root', ['root']);
    DB::table('personal_access_tokens')
        ->where('id', $token->accessToken->id)
        ->update(['team_id' => '0']);

    return $token->plainTextToken;
}

function makeNonRootMcpToken(User $user, Team $team, array $abilities = ['write']): string
{
    $token = $user->createToken('mcp-write', $abilities);
    DB::table('personal_access_tokens')
        ->where('id', $token->accessToken->id)
        ->update(['team_id' => (string) $team->id]);

    return $token->plainTextToken;
}

test('POST /api/v1/mcp/enable enables MCP server with root token', function () {
    $token = makeRootMcpToken($this->user);

    $response = test()->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/api/v1/mcp/enable');

    $response->assertOk();
    $response->assertJson(['message' => 'MCP server enabled.']);
    expect(InstanceSettings::find(0)->is_mcp_server_enabled)->toBeTrue();
});

test('POST /api/v1/mcp/disable disables MCP server with root token', function () {
    InstanceSettings::query()->where('id', 0)->update(['is_mcp_server_enabled' => true]);
    $token = makeRootMcpToken($this->user);

    $response = test()->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/api/v1/mcp/disable');

    $response->assertOk();
    $response->assertJson(['message' => 'MCP server disabled.']);
    expect(InstanceSettings::find(0)->is_mcp_server_enabled)->toBeFalse();
});

test('non-root token cannot enable MCP server', function () {
    $token = makeNonRootMcpToken($this->user, $this->team);

    $response = test()->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/api/v1/mcp/enable');

    $response->assertStatus(403);
    expect(InstanceSettings::find(0)->is_mcp_server_enabled)->toBeFalse();
});

test('non-root token cannot disable MCP server', function () {
    InstanceSettings::query()->where('id', 0)->update(['is_mcp_server_enabled' => true]);
    $token = makeNonRootMcpToken($this->user, $this->team);

    $response = test()->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/api/v1/mcp/disable');

    $response->assertStatus(403);
    expect(InstanceSettings::find(0)->is_mcp_server_enabled)->toBeTrue();
});

test('unauthenticated request to /api/v1/mcp/enable returns 401', function () {
    $response = test()->postJson('/api/v1/mcp/enable');
    $response->assertStatus(401);
});

test('read-only token cannot toggle MCP server (lacks write ability)', function () {
    $token = makeNonRootMcpToken($this->user, $this->team, ['read']);

    $response = test()->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/api/v1/mcp/enable');

    $response->assertStatus(403);
});
