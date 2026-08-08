<?php

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0, 'is_api_enabled' => true]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first();
});

function serviceContainerLabelAuthHeaders($bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function assertSafeMalformedComposeResponse($response, string $marker): void
{
    $response->assertUnprocessable()
        ->assertExactJson([
            'message' => 'Validation failed.',
            'errors' => ['docker_compose_raw' => 'Invalid YAML format.'],
        ]);
    expect($response->getContent())->not->toContain($marker, 'unrelated-service');
}

describe('POST /api/v1/services compose validation', function () {
    test('sanitizes malformed one-click compose YAML', function () {
        $marker = 'ONE-CLICK-COMPOSE-SECRET-MARKER';
        $path = base_path('templates/'.config('constants.services.file_name'));
        Cache::put('service-templates:'.(filemtime($path) ?: 0), collect([
            'malformed-fixture' => (object) ['compose' => base64_encode("services:\n  app: [{$marker}\n  unrelated-service: secret")],
        ]));

        $response = $this->withHeaders(serviceContainerLabelAuthHeaders($this->bearerToken))
            ->postJson('/api/v1/services', [
                'type' => 'malformed-fixture', 'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid, 'server_uuid' => $this->server->uuid,
            ]);

        assertSafeMalformedComposeResponse($response, $marker);
    });

    test('sanitizes malformed custom compose YAML', function () {
        $marker = 'CUSTOM-COMPOSE-SECRET-MARKER';
        $response = $this->withHeaders(serviceContainerLabelAuthHeaders($this->bearerToken))
            ->postJson('/api/v1/services', [
                'docker_compose_raw' => base64_encode("services:\n  app: [{$marker}\n  unrelated-service: secret"),
                'project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
            ]);

        assertSafeMalformedComposeResponse($response, $marker);
    });
});

describe('PATCH /api/v1/services/{uuid}', function () {
    test('sanitizes malformed changed compose YAML', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id, 'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(), 'environment_id' => $this->environment->id,
        ]);
        $marker = 'UPDATE-COMPOSE-SECRET-MARKER';

        $response = $this->withHeaders(serviceContainerLabelAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/services/{$service->uuid}", [
                'docker_compose_raw' => base64_encode("services:\n  app: [{$marker}\n  unrelated-service: secret"),
            ]);

        assertSafeMalformedComposeResponse($response, $marker);
    });

    test('accepts is_container_label_escape_enabled field', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        $response = $this->withHeaders(serviceContainerLabelAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/services/{$service->uuid}", [
                'is_container_label_escape_enabled' => false,
            ]);

        $response->assertStatus(200);

        $service->refresh();
        expect($service->is_container_label_escape_enabled)->toBeFalsy();
    });

    test('rejects invalid is_container_label_escape_enabled value', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        $response = $this->withHeaders(serviceContainerLabelAuthHeaders($this->bearerToken))
            ->patchJson("/api/v1/services/{$service->uuid}", [
                'is_container_label_escape_enabled' => 'not-a-boolean',
            ]);

        $response->assertStatus(422);
    });
});
