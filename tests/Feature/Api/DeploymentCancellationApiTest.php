<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'is_api_enabled' => true,
    ]));

    // Create a team with owner
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    // Create an API token for the user
    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    // Create a server for the team
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $project->environments()->where('name', 'production')->firstOrFail();
    $this->destination = $this->server->standaloneDockers()->firstOrFail();
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

describe('POST /api/v1/deployments/{uuid}/cancel', function () {
    test('returns 401 when not authenticated', function () {
        $response = $this->postJson('/api/v1/deployments/fake-uuid/cancel');

        $response->assertStatus(401);
    });

    test('returns 404 when deployment not found', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/deployments/non-existent-uuid/cancel');

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Deployment not found.']);
    });

    test('returns 403 when user does not own the deployment', function () {
        // Create another team and server
        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);

        $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
        $otherEnvironment = $otherProject->environments()->where('name', 'production')->firstOrFail();
        $otherDestination = $otherServer->standaloneDockers()->firstOrFail();
        $otherApplication = Application::factory()->create([
            'environment_id' => $otherEnvironment->id,
            'destination_id' => $otherDestination->id,
            'destination_type' => $otherDestination->getMorphClass(),
        ]);

        // Create a deployment owned by the other team.
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'test-deployment-uuid',
            'application_id' => $otherApplication->id,
            'server_id' => $otherServer->id,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel");

        $response->assertStatus(403);
        $response->assertJson(['message' => 'You do not have permission to cancel this deployment.']);
    });

    test('returns 400 when deployment is already finished', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'finished-deployment-uuid',
            'application_id' => $this->application->id,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel");

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'Deployment cannot be cancelled. Current status: finished');
    });

    test('returns 400 when deployment is already failed', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'failed-deployment-uuid',
            'application_id' => $this->application->id,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::FAILED->value,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel");

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'Deployment cannot be cancelled. Current status: failed');
    });

    test('returns 400 when deployment is already cancelled', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'cancelled-deployment-uuid',
            'application_id' => $this->application->id,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel");

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'Deployment cannot be cancelled. Current status: cancelled-by-user');
    });

    test('cancels queued deployment and updates status in database', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'queued-deployment-uuid',
            'application_id' => $this->application->id,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::QUEUED->value,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel");

        // The controller updates status before SSH calls, so DB state is always correct
        $deployment->refresh();
        expect($deployment->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value);
    });

    test('cancels in-progress deployment and updates status in database', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'in-progress-deployment-uuid',
            'application_id' => $this->application->id,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel");

        // The controller updates status before SSH calls, so DB state is always correct
        $deployment->refresh();
        expect($deployment->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value);
    });
    test('returns 200 when the deployment container no longer exists during post-cancel cleanup', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'missing-container-uuid',
            'application_id' => $this->application->id,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        Process::fake([
            '*docker rm -f*' => Process::result(
                errorOutput: "Error response from daemon: No such container: {$deployment->deployment_uuid}",
                exitCode: 1,
            ),
            '*docker ps -a*' => Process::result(output: $deployment->deployment_uuid, exitCode: 0),
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel");

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Deployment cancelled successfully.',
            'deployment_uuid' => $deployment->deployment_uuid,
            'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
        ]);
        expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value);
    });

    test('drains the serialized application lane when remote cleanup fails', function () {
        Bus::fake([ApplicationDeploymentJob::class]);
        Process::fake([
            '*' => Process::result(errorOutput: 'remote cleanup failed', exitCode: 1),
        ]);
        $project = Project::factory()->create(['team_id' => $this->team->id]);
        $environment = $project->environments()->where('name', 'production')->firstOrFail();
        $destination = $this->server->standaloneDockers()->firstOrFail();
        $application = Application::factory()->create([
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);
        $nextServer = Server::factory()->create(['team_id' => $this->team->id]);
        $nextDestination = $nextServer->standaloneDockers()->firstOrFail();
        $cancelledDeployment = ApplicationDeploymentQueue::query()->create([
            'application_id' => $application->id,
            'application_name' => $application->name,
            'server_id' => $this->server->id,
            'server_name' => $this->server->name,
            'destination_id' => $destination->id,
            'deployment_uuid' => 'cancel-with-remote-failure',
            'status' => ApplicationDeploymentStatus::QUEUED->value,
        ]);
        $nextDeployment = ApplicationDeploymentQueue::query()->create([
            'application_id' => $application->id,
            'application_name' => $application->name,
            'server_id' => $nextServer->id,
            'server_name' => $nextServer->name,
            'destination_id' => $nextDestination->id,
            'deployment_uuid' => 'next-after-remote-failure',
            'status' => ApplicationDeploymentStatus::QUEUED->value,
        ]);

        expect($cancelledDeployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue()
            ->and($nextDeployment->claimForDispatch())->toBeFalse();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$cancelledDeployment->deployment_uuid}/cancel");

        $response->assertStatus(200);
        expect($cancelledDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
            ->and($nextDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
        Bus::assertDispatched(
            ApplicationDeploymentJob::class,
            fn (ApplicationDeploymentJob $job): bool => $job->application_deployment_queue_id === $nextDeployment->id,
        );
    });

    test('returns 200 for a blue-green activate-phase deployment when the helper container is missing', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'blue-green-activate-uuid',
            'application_id' => $this->application->id,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
            'execution_phase' => 'activate',
        ]);

        Process::fake([
            '*docker rm -f*' => Process::result(
                errorOutput: "Error response from daemon: No such container: {$deployment->deployment_uuid}",
                exitCode: 1,
            ),
            '*docker ps -a*' => Process::result(output: $deployment->deployment_uuid, exitCode: 0),
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel");

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Deployment cancelled successfully.',
            'deployment_uuid' => $deployment->deployment_uuid,
            'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
        ]);
        expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value);
    });

    test('records genuine cleanup failures to the deployment log without failing the cancellation', function () {
        $privateKey = PrivateKey::query()->create([
            'name' => 'Cancel cleanup failure test key',
            'private_key' => generateSSHKey('ed25519')['private'],
            'team_id' => $this->team->id,
        ]);
        Storage::fake('ssh-keys');
        Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
        $this->server->update(['private_key_id' => $privateKey->id]);

        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'ssh-failure-uuid',
            'application_id' => $this->application->id,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        Process::fake([
            '*' => Process::result(
                errorOutput: 'bash: line 1: docker: command not found',
                exitCode: 127,
            ),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel");

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Deployment cancelled successfully.',
            'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
        ]);
        expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value);

        $logEntries = collect(json_decode($deployment->fresh()->logs, true));
        $stderrEntries = $logEntries->where('type', 'stderr');
        expect($stderrEntries->contains(
            fn (array $entry): bool => str($entry['output'])->contains('docker: command not found')
        ))->toBeTrue();
    });

    test('returns correct response structure on success', function () {
        Process::fake([
            '*' => Process::result(output: '', exitCode: 0),
        ]);
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => 'success-deployment-uuid',
            'application_id' => $this->application->id,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'deployment_uuid',
            'status',
        ]);
        $response->assertJson([
            'deployment_uuid' => $deployment->deployment_uuid,
            'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
        ]);
    });
});
