<?php

use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\CompleteBlueGreenDeploymentOperation;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('executes the supersession owner constraint matrix on the configured database', function (string $owner): void {
    ['application' => $application, 'destination' => $destination, 'deployment' => $deployment] = blueGreenSupersessionGenerationFixture();
    $mutation = match ($owner) {
        'deployment' => static fn () => ApplicationBlueGreenDeployment::query()->create([
            'application_id' => $application->id,
            'standalone_docker_id' => $destination->id,
            'operation_deployment_uuid' => 'unversioned-deployment-owner',
            'supersession_generation' => 0,
        ]),
        'deactivation' => static fn () => ApplicationBlueGreenDeactivation::query()->create([
            'application_id' => $application->id,
            'standalone_docker_id' => $destination->id,
            'operation_id' => 'unversioned-deactivation-owner',
            'started_at' => now(),
            'queue_cutoff_id' => 0,
            'supersession_generation' => 0,
            'phase' => BlueGreenDeactivationPhase::COMPLETED,
            'completed_at' => now(),
        ]),
        'queue' => static fn () => $deployment->update([
            'blue_green_phase' => BlueGreenDeploymentPhase::PREPARING,
            'blue_green_supersession_generation' => null,
        ]),
    };

    if (DB::getDriverName() === 'pgsql') {
        expect(fn () => DB::transaction($mutation))
            ->toThrow(QueryException::class);

        return;
    }

    expect(DB::getDriverName())->toBe('sqlite');
    $mutation();

    $persistedInvalidOwner = match ($owner) {
        'deployment' => ApplicationBlueGreenDeployment::query()
            ->where('operation_deployment_uuid', 'unversioned-deployment-owner')
            ->exists(),
        'deactivation' => ApplicationBlueGreenDeactivation::query()
            ->where('operation_id', 'unversioned-deactivation-owner')
            ->exists(),
        'queue' => $deployment->fresh()->blue_green_phase === BlueGreenDeploymentPhase::PREPARING,
    };
    expect($persistedInvalidOwner)->toBeTrue();
})->with(['deployment', 'deactivation', 'queue']);

/**
 * @return array{application: Application, destination: StandaloneDocker, deployment: ApplicationDeploymentQueue}
 */
function blueGreenSupersessionGenerationFixture(): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://supersession-generation.example.test',
        'health_check_enabled' => true,
        'ports_exposes' => '3000',
    ]);
    $application->settings()->firstOrFail()->update([
        'is_blue_green_deployment_enabled' => true,
    ]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'supersession-generation-deployment',
        'destination_id' => $destination->id,
        'server_id' => $destination->server_id,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);

    return compact('application', 'destination', 'deployment');
}

function claimBlueGreenSupersessionGeneration(
    Application $application,
    StandaloneDocker $destination,
    ApplicationDeploymentQueue $deployment,
): BlueGreenDeploymentClaim {
    return ClaimBlueGreenDeployment::run(
        $application,
        $destination,
        $deployment,
        '11111111-2222-3333-4444-555555555555',
    );
}

it('assigns generation one to the first claim and both durable owners', function () {
    ['application' => $application, 'destination' => $destination, 'deployment' => $deployment] = blueGreenSupersessionGenerationFixture();

    $claim = claimBlueGreenSupersessionGeneration($application, $destination, $deployment);
    $state = ApplicationBlueGreenDeployment::query()->sole();
    $queue = $deployment->fresh();

    expect($claim->supersessionGeneration)->toBe(1)
        ->and($state->supersession_generation)->toBe(1)
        ->and($queue->blue_green_supersession_generation)->toBe(1)
        ->and($state->operation_deployment_uuid)->toBe($claim->deploymentUuid)
        ->and($queue->blue_green_phase)->toBe(BlueGreenDeploymentPhase::PREPARING);
});

it('allocates a strictly newer claim generation after completed deactivation', function () {
    ['application' => $application, 'destination' => $destination, 'deployment' => $deployment] = blueGreenSupersessionGenerationFixture();
    $completedGeneration = 4;
    $startedAt = now()->subMinute();
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'supersession_generation' => $completedGeneration,
    ]);
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'operation_id' => str_repeat('a', 64),
        'started_at' => $startedAt,
        'queue_cutoff_id' => 0,
        'supersession_generation' => $completedGeneration,
        'phase' => BlueGreenDeactivationPhase::COMPLETED,
        'completed_at' => now(),
    ]);

    $claim = claimBlueGreenSupersessionGeneration($application, $destination, $deployment);
    $state = ApplicationBlueGreenDeployment::query()->sole();
    $queue = $deployment->fresh();

    expect($claim->supersessionGeneration)->toBeGreaterThan($completedGeneration)
        ->and($state->supersession_generation)->toBe($claim->supersessionGeneration)
        ->and($queue->blue_green_supersession_generation)->toBe($claim->supersessionGeneration);
});

it('does not overwrite a cancelled queue while completing a finalized generation', function () {
    ['application' => $application, 'destination' => $destination, 'deployment' => $deployment] = blueGreenSupersessionGenerationFixture();
    $claim = claimBlueGreenSupersessionGeneration($application, $destination, $deployment);
    $state = ApplicationBlueGreenDeployment::query()->sole();
    $candidateId = str_repeat('b', 64);
    $mutatedAt = now();
    $deploymentColumn = $claim->pendingColor === BlueGreenDeploymentColor::BLUE
        ? 'blue_deployment_uuid'
        : 'green_deployment_uuid';

    $state->update([
        'active_color' => $claim->pendingColor,
        'pending_color' => null,
        'pending_deployment_uuid' => null,
        $deploymentColumn => $claim->deploymentUuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'destination_fence_epoch' => $claim->destinationFenceEpoch,
        'destination_fence_operation_id' => $claim->deploymentUuid,
        'destination_fence_mutation_sequence' => 1,
        'managed_file_sha256' => str_repeat('e', 64),
        'destination_topology_digest' => $claim->operationTopologyDigest,
        'application_routing_config_digest' => $claim->routingConfigDigest,
        'operation_candidate_container_id' => $candidateId,
        'operation_routing_mutated_at' => $mutatedAt,
    ]);
    $deployment->update([
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_candidate_container_id' => $candidateId,
        'blue_green_routing_mutated_at' => $mutatedAt,
    ]);
    $cancelledAt = now()->startOfSecond();
    $deployment->update([
        'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER,
        'finished_at' => $cancelledAt,
    ]);

    $completedState = CompleteBlueGreenDeploymentOperation::run($claim);

    expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
        ->and($deployment->fresh()->finished_at->equalTo($cancelledAt))->toBeTrue()
        ->and($completedState->operation_deployment_uuid)->toBeNull()
        ->and($completedState->active_color)->toBe($claim->pendingColor);
});
