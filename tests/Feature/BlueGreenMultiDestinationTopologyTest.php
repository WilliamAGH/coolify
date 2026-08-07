<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenInterventionRecoveryResult;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\PlanBlueGreenSteadyState;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
use App\Actions\Application\BlueGreen\RetireBlueGreenInactiveContainer;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Shared\ComplexStatusCheck;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\BlueGreenFleetStatus;
use App\Enums\ContainerStatusTypes;
use App\Enums\ProxyTypes;
use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\ResumeBlueGreenDrainingDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Notifications\Application\DeploymentFailed;
use App\Notifications\Application\DeploymentSuccess;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
});

/** @return array{application: Application, destination: StandaloneDocker, server: Server, team: Team} */
function blueGreenMultiDestinationFixture(): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://blue-green-multi-destination.example.test',
        'health_check_enabled' => true,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'ports_mappings' => null,
        'custom_docker_run_options' => null,
    ]);
    $application->settings()->firstOrFail()->update([
        'is_blue_green_deployment_enabled' => true,
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
        'custom_internal_name' => null,
    ]);

    return compact('application', 'destination', 'server', 'team');
}

/** @return array{destination: StandaloneDocker, server: Server} */
function blueGreenMultiDestinationAdditional(Team $team, string $suffix): array
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = StandaloneDocker::factory()->create([
        'server_id' => $server->id,
        'name' => "blue-green-{$suffix}",
        'network' => "blue-green-{$suffix}",
    ]);

    return compact('destination', 'server');
}

function blueGreenMultiDestinationSetPrivate(object $target, string $property, mixed $value): void
{
    $reflection = new ReflectionProperty($target, $property);
    $reflection->setValue($target, $value);
}

function blueGreenMultiDestinationInvoke(object $target, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($target, $method))->invoke($target, ...$arguments);
}

function blueGreenMultiDestinationGetPrivate(object $target, string $property): mixed
{
    return (new ReflectionProperty($target, $property))->getValue($target);
}

function blueGreenMultiDestinationLifecycle(
    Application $application,
    ApplicationDeploymentQueue $deployment,
    StandaloneDocker $destination,
    Server $server,
): BlueGreenDeploymentLifecycle {
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment,
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    blueGreenMultiDestinationSetPrivate($lifecycle, 'enabled', true);

    return $lifecycle;
}

function blueGreenMultiDestinationJob(
    Application $application,
    ApplicationDeploymentQueue $deployment,
    StandaloneDocker $destination,
    Server $server,
): ApplicationDeploymentJob {
    $job = new ApplicationDeploymentJob($deployment->id);
    blueGreenMultiDestinationSetPrivate($job, 'application', $application->fresh(['destination.server', 'settings']));
    blueGreenMultiDestinationSetPrivate($job, 'application_deployment_queue', $deployment);
    blueGreenMultiDestinationSetPrivate($job, 'destination', $destination);
    blueGreenMultiDestinationSetPrivate($job, 'server', $server);
    blueGreenMultiDestinationSetPrivate($job, 'mainServer', $server);
    blueGreenMultiDestinationSetPrivate($job, 'pull_request_id', 0);
    blueGreenMultiDestinationSetPrivate($job, 'deployment_uuid', $deployment->deployment_uuid);
    blueGreenMultiDestinationSetPrivate($job, 'only_this_server', false);
    blueGreenMultiDestinationSetPrivate(
        $job,
        'blueGreenLifecycle',
        blueGreenMultiDestinationLifecycle($application, $deployment, $destination, $server),
    );

    return $job;
}

function blueGreenMultiDestinationQueue(
    Application $application,
    StandaloneDocker $destination,
    Server $server,
    string $deploymentUuid,
    string $status = ApplicationDeploymentStatus::IN_PROGRESS->value,
): ApplicationDeploymentQueue {
    return ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $deploymentUuid,
        'pull_request_id' => 0,
        'commit' => $deploymentUuid,
        'status' => $status,
        'only_this_server' => false,
    ]);
}

it('accepts every blue-green destination on a distinct server and rejects a duplicate server in the model boundary', function (): void {
    $fixture = blueGreenMultiDestinationFixture();
    $second = blueGreenMultiDestinationAdditional($fixture['team'], 'second');
    $third = blueGreenMultiDestinationAdditional($fixture['team'], 'third');
    $fixture['application']->additional_networks()->attach($second['destination']->id, ['server_id' => $second['server']->id]);
    $fixture['application']->additional_networks()->attach($third['destination']->id, ['server_id' => $third['server']->id]);

    expect($fixture['application']->fresh()->blueGreenDeploymentIneligibilityReason())->toBeNull();

    $sameServerDestination = StandaloneDocker::factory()->create([
        'server_id' => $second['server']->id,
        'name' => 'blue-green-same-server',
        'network' => 'blue-green-same-server',
    ]);

    expect(fn () => $fixture['application']->fresh()
        ->assertAdditionalStandaloneDockerDestinationCanBeAttached($sameServerDestination))
        ->toThrow(RuntimeException::class, 'one destination per server');
});

it('enforces the one-destination-per-server invariant at the database boundary', function (): void {
    $fixture = blueGreenMultiDestinationFixture();
    $sameServerDestination = StandaloneDocker::factory()->create([
        'server_id' => $fixture['server']->id,
        'name' => 'blue-green-primary-server-duplicate',
        'network' => 'blue-green-primary-server-duplicate',
    ]);

    expect(fn () => DB::table('additional_destinations')->insert([
        'application_id' => $fixture['application']->id,
        'server_id' => $fixture['server']->id,
        'standalone_docker_id' => $sameServerDestination->id,
    ]))->toThrow(QueryException::class, 'one destination per server');
});

it('writes an additional blue-green status only to its exact destination pivot', function (): void {
    $fixture = blueGreenMultiDestinationFixture();
    $additional = blueGreenMultiDestinationAdditional($fixture['team'], 'status');
    $fixture['application']->additional_networks()->attach($additional['destination']->id, [
        'server_id' => $additional['server']->id,
    ]);
    $primaryRawStatus = $fixture['application']->fresh()->realStatus();

    $updated = (new ComplexStatusCheck)->updateApplicationDestinationStatus(
        $fixture['application']->fresh(),
        $additional['destination']->id,
        $additional['server']->id,
        'degraded:unknown',
    );

    expect($updated)->toBeTrue()
        ->and($fixture['application']->fresh()->realStatus())->toBe($primaryRawStatus)
        ->and($fixture['application']->fresh()->additional_networks->first()->pivot->status)->toBe('degraded:unknown');
});

it('fans a blue-green deployment out sequentially with one durable fleet owner', function (): void {
    Queue::fake();
    $fixture = blueGreenMultiDestinationFixture();
    $second = blueGreenMultiDestinationAdditional($fixture['team'], 'fanout-second');
    $third = blueGreenMultiDestinationAdditional($fixture['team'], 'fanout-third');
    $fixture['application']->additional_networks()->attach($second['destination']->id, ['server_id' => $second['server']->id]);
    $fixture['application']->additional_networks()->attach($third['destination']->id, ['server_id' => $third['server']->id]);
    $rootDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $fixture['destination'],
        $fixture['server'],
        'blue-green-multi-fleet-root',
        ApplicationDeploymentStatus::FINISHED->value,
    );
    $job = blueGreenMultiDestinationJob(
        $fixture['application'],
        $rootDeployment,
        $fixture['destination'],
        $fixture['server'],
    );

    blueGreenMultiDestinationInvoke($job, 'deploy_to_additional_destinations');

    $children = ApplicationDeploymentQueue::query()
        ->where('application_id', $fixture['application']->id)
        ->where('blue_green_fleet_deployment_uuid', $rootDeployment->deployment_uuid)
        ->where('id', '!=', $rootDeployment->id)
        ->orderBy('id')
        ->get();

    expect($children)->toHaveCount(2)
        ->and($children->pluck('destination_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all())
        ->toBe(collect([$second['destination']->id, $third['destination']->id])->sort()->values()->all())
        ->and($children->every(fn (ApplicationDeploymentQueue $deployment): bool => $deployment->only_this_server))->toBeTrue()
        ->and($children->pluck('status')->all())->toContain(
            ApplicationDeploymentStatus::IN_PROGRESS->value,
            ApplicationDeploymentStatus::QUEUED->value,
        );
});

it('copies the immutable root release identity when the branch moves before fleet fan-out', function (): void {
    Queue::fake();
    $fixture = blueGreenMultiDestinationFixture();
    $additional = blueGreenMultiDestinationAdditional($fixture['team'], 'immutable-release');
    $fixture['application']->additional_networks()->attach($additional['destination']->id, ['server_id' => $additional['server']->id]);
    $rootDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $fixture['destination'],
        $fixture['server'],
        'immutable-root',
        ApplicationDeploymentStatus::FINISHED->value,
    );
    $rootDeployment->update([
        'commit' => '0123456789abcdef0123456789abcdef01234567',
        'git_type' => 'github',
        'force_rebuild' => true,
        'is_webhook' => true,
        'is_api' => true,
        'restart_only' => false,
        'rollback' => false,
        'docker_registry_image_tag' => 'release-immutable',
    ]);
    $fixture['application']->update([
        'git_branch' => 'main',
        'git_commit_sha' => 'fedcba9876543210fedcba9876543210fedcba98',
    ]);

    blueGreenMultiDestinationInvoke(
        blueGreenMultiDestinationJob($fixture['application'], $rootDeployment->fresh(), $fixture['destination'], $fixture['server']),
        'deploy_to_additional_destinations',
    );

    $child = ApplicationDeploymentQueue::query()
        ->where('blue_green_fleet_deployment_uuid', $rootDeployment->deployment_uuid)
        ->where('id', '!=', $rootDeployment->id)
        ->sole();
    expect($child->only([
        'commit',
        'git_type',
        'force_rebuild',
        'is_webhook',
        'is_api',
        'restart_only',
        'rollback',
        'docker_registry_image_tag',
    ]))->toBe($rootDeployment->fresh()->only([
        'commit',
        'git_type',
        'force_rebuild',
        'is_webhook',
        'is_api',
        'restart_only',
        'rollback',
        'docker_registry_image_tag',
    ]));
});

it('keeps rollback fleet children pinned to the root rollback artifact identity', function (): void {
    Queue::fake();
    $fixture = blueGreenMultiDestinationFixture();
    $additional = blueGreenMultiDestinationAdditional($fixture['team'], 'rollback-release');
    $fixture['application']->additional_networks()->attach($additional['destination']->id, ['server_id' => $additional['server']->id]);
    $rootDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $fixture['destination'],
        $fixture['server'],
        'rollback-root',
        ApplicationDeploymentStatus::FINISHED->value,
    );
    $rootDeployment->update([
        'commit' => 'rollback-commit',
        'rollback' => true,
        'force_rebuild' => true,
        'docker_registry_image_tag' => 'rollback-image-tag',
        'git_type' => 'gitlab',
    ]);

    blueGreenMultiDestinationInvoke(
        blueGreenMultiDestinationJob($fixture['application'], $rootDeployment->fresh(), $fixture['destination'], $fixture['server']),
        'deploy_to_additional_destinations',
    );

    $child = ApplicationDeploymentQueue::query()
        ->where('blue_green_fleet_deployment_uuid', $rootDeployment->deployment_uuid)
        ->where('id', '!=', $rootDeployment->id)
        ->sole();
    expect((bool) $child->rollback)->toBeTrue()
        ->and((bool) $child->force_rebuild)->toBeTrue()
        ->and($child->commit)->toBe('rollback-commit')
        ->and($child->docker_registry_image_tag)->toBe('rollback-image-tag')
        ->and($child->git_type)->toBe('gitlab');
});

it('accepts reattached fleet children instead of exploding when fanout fires twice', function (): void {
    Queue::fake();
    $fixture = blueGreenMultiDestinationFixture();
    $additional = blueGreenMultiDestinationAdditional($fixture['team'], 'double-fire');
    $fixture['application']->additional_networks()->attach($additional['destination']->id, ['server_id' => $additional['server']->id]);
    $rootDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $fixture['destination'],
        $fixture['server'],
        'double-fire-root',
        ApplicationDeploymentStatus::FINISHED->value,
    );

    blueGreenMultiDestinationInvoke(
        blueGreenMultiDestinationJob($fixture['application'], $rootDeployment->fresh(), $fixture['destination'], $fixture['server']),
        'deploy_to_additional_destinations',
    );
    $child = ApplicationDeploymentQueue::query()
        ->where('blue_green_fleet_deployment_uuid', $rootDeployment->deployment_uuid)
        ->where('id', '!=', $rootDeployment->id)
        ->sole();

    $rootDeployment->fresh()->update([
        'blue_green_fleet_deployment_uuid' => null,
        'blue_green_fleet_status' => null,
    ]);

    blueGreenMultiDestinationInvoke(
        blueGreenMultiDestinationJob($fixture['application'], $rootDeployment->fresh(), $fixture['destination'], $fixture['server']),
        'deploy_to_additional_destinations',
    );

    expect(ApplicationDeploymentQueue::query()
        ->where('blue_green_fleet_deployment_uuid', $rootDeployment->deployment_uuid)
        ->where('id', '!=', $rootDeployment->id)
        ->count())->toBe(1)
        ->and(ApplicationDeploymentQueue::query()
            ->where('blue_green_fleet_deployment_uuid', $rootDeployment->deployment_uuid)
            ->where('id', '!=', $rootDeployment->id)
            ->sole()->id)->toBe($child->id)
        ->and($rootDeployment->fresh()->blue_green_fleet_deployment_uuid)->toBe($rootDeployment->deployment_uuid)
        ->and((string) $rootDeployment->fresh()->logs)->not->toContain('could not enqueue every destination');
});

it('surfaces a degraded fleet when scheduling rejects the locked topology', function (): void {
    Notification::fake();
    $fixture = blueGreenMultiDestinationFixture();
    $fixture['team']->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'deployment_failure_email_notifications' => true,
        'deployment_success_email_notifications' => true,
    ]);
    $foreignTeam = Team::factory()->create();
    $foreignDestination = blueGreenMultiDestinationAdditional($foreignTeam, 'foreign');
    Event::fake();
    $fixture['application']->additional_networks()->attach($foreignDestination['destination']->id, [
        'server_id' => $foreignDestination['server']->id,
    ]);
    $rootDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $fixture['destination'],
        $fixture['server'],
        'blue-green-fleet-scheduling-rejected',
        ApplicationDeploymentStatus::FINISHED->value,
    );
    $job = blueGreenMultiDestinationJob(
        $fixture['application'],
        $rootDeployment,
        $fixture['destination'],
        $fixture['server'],
    );

    blueGreenMultiDestinationInvoke($job, 'handleSuccessfulDeployment');

    expect($rootDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($rootDeployment->fresh()->blue_green_fleet_deployment_uuid)->toBe($rootDeployment->deployment_uuid)
        ->and($rootDeployment->fresh()->blue_green_fleet_status)->toBe(BlueGreenFleetStatus::PAUSED)
        ->and((string) $rootDeployment->fresh()->logs)
        ->toContain('Blue-green fleet scheduling failed before any additional destination was dispatched')
        ->and($fixture['application']->fresh()->additional_networks()->firstOrFail()->pivot->status)
        ->toBe('degraded:unknown')
        ->and(ApplicationDeploymentQueue::query()
            ->where('application_id', $fixture['application']->id)
            ->where('id', '!=', $rootDeployment->id)
            ->count())->toBe(0)
        ->and(Notification::sent($fixture['team'], DeploymentFailed::class))->toHaveCount(1)
        ->and(Notification::sent($fixture['team'], DeploymentSuccess::class))->toHaveCount(0);
});

it('marks only the failed blue-green destination degraded and pauses the remaining fleet', function (): void {
    Notification::fake();
    $fixture = blueGreenMultiDestinationFixture();
    $failed = blueGreenMultiDestinationAdditional($fixture['team'], 'failed');
    $pending = blueGreenMultiDestinationAdditional($fixture['team'], 'pending');
    $fixture['application']->additional_networks()->attach($failed['destination']->id, ['server_id' => $failed['server']->id]);
    $fixture['application']->additional_networks()->attach($pending['destination']->id, ['server_id' => $pending['server']->id]);
    $rootDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $fixture['destination'],
        $fixture['server'],
        'blue-green-fleet-failure-root',
        ApplicationDeploymentStatus::FINISHED->value,
    );
    $rootDeployment->update([
        'blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid,
        'blue_green_fleet_status' => BlueGreenFleetStatus::ACTIVE,
    ]);
    $failedDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $failed['destination'],
        $failed['server'],
        'blue-green-fleet-failure-child',
        ApplicationDeploymentStatus::IN_PROGRESS->value,
    );
    $pendingDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $pending['destination'],
        $pending['server'],
        'blue-green-fleet-pending-child',
        ApplicationDeploymentStatus::QUEUED->value,
    );
    $failedDeployment->update(['blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid]);
    $pendingDeployment->update(['blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid]);
    $primaryRawStatus = $fixture['application']->fresh()->realStatus();
    $job = blueGreenMultiDestinationJob(
        $fixture['application'],
        $failedDeployment->fresh(),
        $failed['destination'],
        $failed['server'],
    );

    blueGreenMultiDestinationInvoke($job, 'transitionToStatus', ApplicationDeploymentStatus::FAILED);

    expect($fixture['application']->fresh()->realStatus())->toBe($primaryRawStatus)
        ->and($failedDeployment->fresh()->finished_at)->not->toBeNull()
        ->and($fixture['application']->fresh()->additional_networks()
            ->whereKey($failed['destination']->id)
            ->firstOrFail()->pivot->status)->toBe('degraded:unknown')
        ->and($pendingDeployment->fresh()->status)->toBe('cancelled-by-blue-green-fleet')
        ->and($rootDeployment->fresh()->blue_green_fleet_status)->toBe(BlueGreenFleetStatus::PAUSED)
        ->and((string) $failedDeployment->fresh()->logs)->toContain('Paused the remaining destination(s)');
});

it('publishes an exact drain-recovery fleet failure through the fleet failure owner', function (): void {
    Notification::fake();
    $fixture = blueGreenMultiDestinationFixture();
    $failed = blueGreenMultiDestinationAdditional($fixture['team'], 'drain-recovery-failed');
    $pending = blueGreenMultiDestinationAdditional($fixture['team'], 'drain-recovery-pending');
    $fixture['application']->additional_networks()->attach($failed['destination']->id, ['server_id' => $failed['server']->id]);
    $fixture['application']->additional_networks()->attach($pending['destination']->id, ['server_id' => $pending['server']->id]);
    $rootDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $fixture['destination'],
        $fixture['server'],
        'blue-green-drain-recovery-fleet-root',
        ApplicationDeploymentStatus::FINISHED->value,
    );
    $rootDeployment->update([
        'blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid,
        'blue_green_fleet_status' => BlueGreenFleetStatus::ACTIVE,
    ]);
    $failedDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $failed['destination'],
        $failed['server'],
        'blue-green-drain-recovery-failed-child',
    );
    $pendingDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $pending['destination'],
        $pending['server'],
        'blue-green-drain-recovery-pending-child',
        ApplicationDeploymentStatus::QUEUED->value,
    );
    $failedDeployment->update([
        'blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid,
        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'blue_green_supersession_generation' => 1,
    ]);
    $pendingDeployment->update(['blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid]);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $failed['destination']->id,
        'operation_deployment_uuid' => $failedDeployment->deployment_uuid,
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'routing_revision' => 1,
        'supersession_generation' => 1,
    ]);
    $job = blueGreenMultiDestinationJob(
        $fixture['application'],
        $failedDeployment->fresh(),
        $failed['destination'],
        $failed['server'],
    );

    $job->failBlueGreenDrainRecovery(new RuntimeException('The drain recovery requires intervention.'));

    expect($failedDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($pendingDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value)
        ->and($rootDeployment->fresh()->blue_green_fleet_status)->toBe(BlueGreenFleetStatus::PAUSED)
        ->and($fixture['application']->fresh()->additional_networks()
            ->whereKey($failed['destination']->id)
            ->firstOrFail()->pivot->status)->toBe('degraded:unknown')
        ->and($pendingDeployment->claimForDispatch(bypassServerCapacity: true))->toBeFalse();
});

it('publishes a recoverable drain-recovery fleet failure timestamp under the exact owner and fence', function (): void {
    Notification::fake();
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $pending = blueGreenMultiDestinationAdditional($scenario->application->team(), 'recoverable-drain-pending');
    $scenario->application->additional_networks()->attach($pending['destination']->id, [
        'server_id' => $pending['server']->id,
    ]);
    $scenario->state->update([
        'destination_routing_topology_digest' => (new ComputeBlueGreenDeploymentFingerprint)
            ->routingTopologyDigestFor($scenario->application, $scenario->destination),
    ]);
    $rootDeployment = blueGreenMultiDestinationQueue(
        $scenario->application,
        $scenario->destination,
        $scenario->server,
        'recoverable-drain-fleet-root',
        ApplicationDeploymentStatus::FINISHED->value,
    );
    $rootDeployment->update([
        'blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid,
        'blue_green_fleet_status' => BlueGreenFleetStatus::ACTIVE,
    ]);
    $scenario->deployment->update([
        'blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid,
        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'blue_green_supersession_generation' => 1,
    ]);
    $pendingDeployment = blueGreenMultiDestinationQueue(
        $scenario->application,
        $pending['destination'],
        $pending['server'],
        'recoverable-drain-fleet-pending',
        ApplicationDeploymentStatus::QUEUED->value,
    );
    $pendingDeployment->update(['blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid]);
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeploymentPhase::DRAINING,
        'intervention_reason' => 'Drain recovery requires exact operator retry.',
        'operation_drain_started_at' => now()->subMinutes(2),
        'operation_drain_deadline_at' => now()->subMinute(),
    ]);
    $job = blueGreenMultiDestinationJob(
        $scenario->application,
        $scenario->deployment->fresh(),
        $scenario->destination,
        $scenario->server,
    );

    $job->failBlueGreenDrainRecovery(new RuntimeException('Drain recovery requires intervention.'));

    expect($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($scenario->deployment->fresh()->finished_at)->not->toBeNull()
        ->and($pendingDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value)
        ->and($rootDeployment->fresh()->blue_green_fleet_status)->toBe(BlueGreenFleetStatus::PAUSED);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Retry the exact finalized drain owner under its lifecycle fence.',
    );

    // Reopening is a handoff, not a recovery: the destination stays DRAINING and
    // only the fenced resume job just queued can finish it.
    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::FINALIZED_UNCONFIRMED)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::DEFERRED)
        ->and($result->recoveryOwnerActive)->toBeTrue()
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::DRAINING)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($scenario->deployment->fresh()->finished_at)->toBeNull();
    Queue::assertPushed(
        ResumeBlueGreenDrainingDeploymentJob::class,
        fn (ResumeBlueGreenDrainingDeploymentJob $job): bool => $job->applicationDeploymentQueueId === $scenario->deployment->id,
    );
});

it('atomically pauses a fleet when a child finds its server non-functional before lifecycle initialization', function (): void {
    Notification::fake();
    Process::fake();
    $fixture = blueGreenMultiDestinationFixture();
    $failed = blueGreenMultiDestinationAdditional($fixture['team'], 'pre-lifecycle-server-failed');
    $pending = blueGreenMultiDestinationAdditional($fixture['team'], 'pre-lifecycle-server-pending');
    $fixture['application']->settings()->update(['custom_internal_name' => 'pre-lifecycle-server']);
    $fixture['application']->additional_networks()->attach($failed['destination']->id, ['server_id' => $failed['server']->id]);
    $fixture['application']->additional_networks()->attach($pending['destination']->id, ['server_id' => $pending['server']->id]);
    $failed['server']->settings()->update([
        'is_reachable' => false,
        'is_usable' => false,
        'force_disabled' => false,
    ]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $fixture['team']->id]);
    $failed['server']->update(['private_key_id' => $privateKey->id]);

    $rootDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $fixture['destination'],
        $fixture['server'],
        'pre-lifecycle-server-root',
        ApplicationDeploymentStatus::FINISHED->value,
    );
    $rootDeployment->update([
        'blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid,
        'blue_green_fleet_status' => BlueGreenFleetStatus::ACTIVE,
    ]);
    $failedDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $failed['destination'],
        $failed['server'],
        'pre-lifecycle-server-failed-child',
    );
    $pendingDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $pending['destination'],
        $pending['server'],
        'pre-lifecycle-server-pending-child',
        ApplicationDeploymentStatus::QUEUED->value,
    );
    $dispatchAttemptUuid = (string) Str::uuid();
    $failedDeployment->update([
        'blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid,
        'horizon_job_id' => $dispatchAttemptUuid,
        'horizon_job_worker' => null,
    ]);
    $pendingDeployment->update(['blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid]);
    $job = new ApplicationDeploymentJob($failedDeployment->id, $dispatchAttemptUuid);

    expect(blueGreenMultiDestinationGetPrivate($job, 'blueGreenLifecycle'))->toBeNull();
    $job->handlePreparation();

    $lateDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $pending['destination'],
        $pending['server'],
        'pre-lifecycle-server-late-child',
        ApplicationDeploymentStatus::QUEUED->value,
    );
    $lateDeployment->update(['blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid]);

    expect(blueGreenMultiDestinationGetPrivate($job, 'blueGreenLifecycle'))->toBeNull()
        ->and($failedDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($pendingDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value)
        ->and($rootDeployment->fresh()->blue_green_fleet_status)->toBe(BlueGreenFleetStatus::PAUSED)
        ->and($lateDeployment->claimForDispatch(bypassServerCapacity: true))->toBeFalse()
        ->and($lateDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value)
        ->and($fixture['application']->fresh()->additional_networks()
            ->whereKey($failed['destination']->id)
            ->firstOrFail()->pivot->status)->toBe('degraded:unknown');
});

it('atomically pauses a fleet when private-key setup fails before lifecycle initialization', function (): void {
    Notification::fake();
    Process::fake();
    $fixture = blueGreenMultiDestinationFixture();
    $failed = blueGreenMultiDestinationAdditional($fixture['team'], 'pre-lifecycle-key-failed');
    $pending = blueGreenMultiDestinationAdditional($fixture['team'], 'pre-lifecycle-key-pending');
    $fixture['application']->settings()->update(['custom_internal_name' => 'pre-lifecycle-key']);
    $fixture['application']->additional_networks()->attach($failed['destination']->id, ['server_id' => $failed['server']->id]);
    $fixture['application']->additional_networks()->attach($pending['destination']->id, ['server_id' => $pending['server']->id]);
    $failed['server']->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $fixture['team']->id]);
    $failed['server']->update(['private_key_id' => $privateKey->id]);

    $rootDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $fixture['destination'],
        $fixture['server'],
        'pre-lifecycle-key-root',
        ApplicationDeploymentStatus::FINISHED->value,
    );
    $rootDeployment->update([
        'blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid,
        'blue_green_fleet_status' => BlueGreenFleetStatus::ACTIVE,
    ]);
    $failedDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $failed['destination'],
        $failed['server'],
        'pre-lifecycle-key-failed-child',
    );
    $pendingDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $pending['destination'],
        $pending['server'],
        'pre-lifecycle-key-pending-child',
        ApplicationDeploymentStatus::QUEUED->value,
    );
    $dispatchAttemptUuid = (string) Str::uuid();
    $failedDeployment->update([
        'blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid,
        'horizon_job_id' => $dispatchAttemptUuid,
        'horizon_job_worker' => null,
    ]);
    $pendingDeployment->update(['blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid]);
    $job = new ApplicationDeploymentJob($failedDeployment->id, $dispatchAttemptUuid);
    $failingPrivateKey = Mockery::mock(PrivateKey::class)->makePartial();
    $failingPrivateKey->shouldReceive('storeInFileSystem')
        ->atLeast()
        ->once()
        ->andThrow(new RuntimeException('Private-key setup failed before lifecycle initialization.'));
    blueGreenMultiDestinationGetPrivate($job, 'server')->setRelation('privateKey', $failingPrivateKey);
    $failure = null;

    expect(blueGreenMultiDestinationGetPrivate($job, 'blueGreenLifecycle'))->toBeNull();
    try {
        $job->handlePreparation();
    } catch (Throwable $throwable) {
        $failure = $throwable;
    }

    $lateDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $pending['destination'],
        $pending['server'],
        'pre-lifecycle-key-late-child',
        ApplicationDeploymentStatus::QUEUED->value,
    );
    $lateDeployment->update(['blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid]);

    expect($failure)->toBeInstanceOf(RuntimeException::class)
        ->and($failure?->getMessage())->toBe('Private-key setup failed before lifecycle initialization.')
        ->and(blueGreenMultiDestinationGetPrivate($job, 'blueGreenLifecycle'))->toBeNull()
        ->and($failedDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($pendingDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value)
        ->and($rootDeployment->fresh()->blue_green_fleet_status)->toBe(BlueGreenFleetStatus::PAUSED)
        ->and($lateDeployment->claimForDispatch(bypassServerCapacity: true))->toBeFalse()
        ->and($lateDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value)
        ->and($fixture['application']->fresh()->additional_networks()
            ->whereKey($failed['destination']->id)
            ->firstOrFail()->pivot->status)->toBe('degraded:unknown');
});

it('keeps a pre-lifecycle non-fleet server failure on the ordinary failure path', function (): void {
    Notification::fake();
    Process::fake();
    $fixture = blueGreenMultiDestinationFixture();
    $fixture['application']->settings()->update(['custom_internal_name' => 'pre-lifecycle-non-fleet']);
    $fixture['server']->settings()->update([
        'is_reachable' => false,
        'is_usable' => false,
        'force_disabled' => false,
    ]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $fixture['team']->id]);
    $fixture['server']->update(['private_key_id' => $privateKey->id]);
    $deployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $fixture['destination'],
        $fixture['server'],
        'pre-lifecycle-non-fleet',
    );
    $dispatchAttemptUuid = (string) Str::uuid();
    $deployment->update([
        'horizon_job_id' => $dispatchAttemptUuid,
        'horizon_job_worker' => null,
    ]);
    $job = new ApplicationDeploymentJob($deployment->id, $dispatchAttemptUuid);

    expect(blueGreenMultiDestinationGetPrivate($job, 'blueGreenLifecycle'))->toBeNull();
    $job->handlePreparation();

    expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($deployment->fresh()->blue_green_fleet_deployment_uuid)->toBeNull()
        ->and(ApplicationDeploymentQueue::query()
            ->where('application_id', $fixture['application']->id)
            ->where('status', ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value)
            ->exists())->toBeFalse();
});

it('rejects a queued child after its fleet owner is paused', function (): void {
    $fixture = blueGreenMultiDestinationFixture();
    $additional = blueGreenMultiDestinationAdditional($fixture['team'], 'paused-owner');
    $fixture['application']->additional_networks()->attach($additional['destination']->id, ['server_id' => $additional['server']->id]);
    $rootDeployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $fixture['destination'],
        $fixture['server'],
        'paused-fleet-root',
        ApplicationDeploymentStatus::FINISHED->value,
    );
    $rootDeployment->update([
        'blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid,
        'blue_green_fleet_status' => BlueGreenFleetStatus::PAUSED,
    ]);
    $child = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $additional['destination'],
        $additional['server'],
        'paused-fleet-child',
        ApplicationDeploymentStatus::QUEUED->value,
    );
    $child->update(['blue_green_fleet_deployment_uuid' => $rootDeployment->deployment_uuid]);

    expect($child->claimForDispatch(bypassServerCapacity: true))->toBeFalse()
        ->and($child->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value);
});

it('fails closed before a stale fleet child can mutate a destination removed from topology', function (): void {
    $fixture = blueGreenMultiDestinationFixture();
    $additional = blueGreenMultiDestinationAdditional($fixture['team'], 'stale');
    $fixture['application']->additional_networks()->attach($additional['destination']->id, [
        'server_id' => $additional['server']->id,
    ]);
    $deployment = blueGreenMultiDestinationQueue(
        $fixture['application'],
        $additional['destination'],
        $additional['server'],
        'blue-green-stale-fleet-child',
    );
    $lifecycle = blueGreenMultiDestinationLifecycle(
        $fixture['application']->fresh(['settings']),
        $deployment,
        $additional['destination'],
        $additional['server'],
    );
    $fixture['application']->additional_networks()->detach($additional['destination']->id);

    expect(fn () => blueGreenMultiDestinationInvoke($lifecycle, 'assertEligibility'))
        ->toThrow(DeploymentException::class, 'no longer configured');
});

it('live-attests a pre-migration passive retirement on transaction-backed non-PostgreSQL fixtures', function (): void {
    if (DB::getDriverName() === 'pgsql') {
        $this->markTestSkipped('The transaction-free PostgreSQL fixture owns the live network-attestation contract.');
    }
    config(['constants.ssh.mux_enabled' => false]);
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $application = $scenario->application->fresh(['settings']);
    $destination = $scenario->destination->fresh();
    $owner = $scenario->deployment;
    $inactiveContainerId = BlueGreenRecoveryScenario::LEGACY_ID;
    $backendPortInventory = BlueGreenBackendPortInventory::fromPorts([3000]);
    $topologyDigest = (string) $scenario->state->destination_topology_digest;
    $configuration = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        new BlueGreenRoutingTarget(
            destinationId: (int) $destination->id,
            activeColor: BlueGreenDeploymentColor::BLUE,
            blueContainerName: $application->uuid.'-blue',
            greenContainerName: $application->uuid.'-green',
            port: 3000,
            ports: [3000],
            routingRevision: 2,
            mode: BlueGreenRoutingMode::Steady,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($owner->deployment_uuid),
            destinationFenceEpoch: 2,
            operationId: $owner->deployment_uuid,
            mutationSequence: 1,
            activeDeploymentUuid: $owner->deployment_uuid,
            activeContainerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
            destinationTopologyDigest: $topologyDigest,
        ),
    );
    $owner->update([
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 2,
        'blue_green_destination_fence_epoch' => 2,
        'blue_green_topology_digest' => $topologyDigest,
        'blue_green_routing_config_digest' => $configuration->routingConfigDigest,
        'blue_green_backend_port_inventory' => $backendPortInventory->serialized,
        'blue_green_drain_backend_port_inventory' => $backendPortInventory->serialized,
    ]);
    $inactive = blueGreenMultiDestinationQueue(
        $application,
        $destination,
        $scenario->server,
        'pre-migration-passive-retirement-inactive',
        ApplicationDeploymentStatus::FINISHED->value,
    );
    $inactive->update([
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_topology_digest' => $topologyDigest,
        'blue_green_routing_config_digest' => $configuration->routingConfigDigest,
        'blue_green_backend_port_inventory' => $backendPortInventory->serialized,
        'blue_green_candidate_container_id' => $inactiveContainerId,
    ]);
    $bootId = '11111111-2222-3333-4444-555555555555';
    $scenario->state->update([
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => $owner->deployment_uuid,
        'green_deployment_uuid' => $inactive->deployment_uuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
        'supersession_generation' => 1,
        'destination_fence_epoch' => 2,
        'destination_fence_operation_id' => $owner->deployment_uuid,
        'destination_fence_mutation_sequence' => 1,
        'managed_file_sha256' => $configuration->sha256,
        'destination_topology_digest' => $topologyDigest,
        'destination_routing_topology_digest' => null,
        'application_routing_config_digest' => $configuration->routingConfigDigest,
        'inactive_retirement_owner_deployment_uuid' => $owner->deployment_uuid,
        'inactive_retirement_color' => BlueGreenDeploymentColor::GREEN,
        'inactive_retirement_deployment_uuid' => $inactive->deployment_uuid,
        'inactive_retirement_container_id' => $inactiveContainerId,
        'inactive_retirement_container_routing_revision' => 1,
        'inactive_retirement_owner_routing_revision' => 2,
        'inactive_retirement_supersession_generation' => 1,
        'inactive_retirement_destination_fence_epoch' => 2,
        'inactive_retirement_server_boot_id' => $bootId,
        'inactive_retirement_topology_digest' => $topologyDigest,
        'inactive_retirement_routing_config_digest' => $configuration->routingConfigDigest,
        'inactive_retirement_not_before_at' => now()->subSecond(),
        'inactive_retirement_drain_deadline_at' => now()->addMinute(),
        'inactive_retirement_stop_grace_seconds' => 30,
        'inactive_retirement_lease_seconds' => 4_000,
        'inactive_retirement_dispatch_reserved_until_at' => now()->addSeconds(30),
    ]);
    $state = $scenario->state->fresh();
    $plan = PlanBlueGreenSteadyState::run($application, $destination, $state);
    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken($owner->deployment_uuid);
    $preservedAttributes = array_values(array_diff(
        array_keys(ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes()),
        ['inactive_retirement_last_observed_connections', 'inactive_retirement_observed_at', 'inactive_retirement_stopped_at'],
    ));
    $before = collect([...$preservedAttributes, 'supersession_generation'])
        ->mapWithKeys(static fn (string $attribute): array => [$attribute => $state->getRawOriginal($attribute)])
        ->all();
    $activeInspection = new BlueGreenContainerInspection(
        exists: true,
        dockerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
        status: ContainerStatusTypes::RUNNING->value,
        health: 'healthy',
    );
    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturn(
            $activeInspection,
            new BlueGreenContainerInspection(
                exists: true,
                dockerId: $inactiveContainerId,
                status: ContainerStatusTypes::EXITED->value,
                health: 'healthy',
            ),
        );
    Process::fake(static function (PendingProcess $process) use (
        $bootId,
        $configuration,
        $destination,
        $plan,
        $releaseProof,
    ) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $payload = $command."\n".(string) $process->input;

        return match (true) {
            str_contains($command, 'coolify-blue-green-destination-state-attested') => Process::result(
                output: 'coolify-blue-green-destination-state-attested',
            ),
            str_contains($command, 'coolify-blue-green-managed-route:present:') => Process::result(
                output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($configuration->state->serialize())."\n"
                    .$configuration->state->managedSha256,
            ),
            str_contains($payload, 'coolify-blue-green-route-network-proof:') => Process::result(
                output: 'coolify-blue-green-route-network-proof:'
                    .BlueGreenRecoveryScenario::CANDIDATE_ID."\t"
                    .json_encode([$destination->network => []], JSON_THROW_ON_ERROR),
            ),
            str_contains($command, '/proc/sys/kernel/random/boot_id') => Process::result(output: $bootId),
            str_contains($command, '{{json .Config.Env}}') => Process::result(
                output: json_encode(['COOLIFY_DEPLOYMENT_RELEASE_PROOF='.$releaseProof], JSON_THROW_ON_ERROR),
            ),
            str_contains($command, 'curl --config -') => Process::result(output: "HTTP/1.1 200 OK\r\n"
                .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$plan->publicAcknowledgement}\r\n"
                .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n"),
            default => throw new RuntimeException('Unexpected passive retirement rehydration process.'),
        };
    });

    expect(RetireBlueGreenInactiveContainer::run($state->id, 'foreign-retirement-owner', 1))
        ->toBe(RetireBlueGreenInactiveContainer::STALE)
        ->and($state->fresh()->destination_routing_topology_digest)->toBeNull();
    $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 1);
    $state = $state->fresh();
    $after = collect(array_keys($before))
        ->mapWithKeys(static fn (string $attribute): array => [$attribute => $state->getRawOriginal($attribute)])
        ->all();

    expect($result)->toBe(RetireBlueGreenInactiveContainer::COMPLETED)
        ->and($state->destination_routing_topology_digest)
        ->toBe((new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor($application, $destination))
        ->and($after)->toBe($before)
        ->and($state->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull();
});
