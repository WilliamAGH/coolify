<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\QuarantineSpentFirstAdoptionDrainJournal;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const SPENT_DRAIN_JOURNAL_BOOT_ID = '11111111-2222-3333-4444-555555555555';

/**
 * One spent first-adoption drain parked for intervention, with every durable
 * owner assertion already satisfied, so the only thing left to decide is
 * whether the destination's deactivation history fences the journal archival.
 *
 * @return array{application: Application, deployment: ApplicationDeploymentQueue, state: ApplicationBlueGreenDeployment}
 */
function makeSpentFirstAdoptionDrainJournalFixture(): array
{
    config(['constants.ssh.mux_enabled' => false]);
    $team = Team::factory()->create();
    $privateKey = PrivateKey::create([
        'name' => 'spent-drain-journal-fence-key',
        'private_key' => <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY,
        'team_id' => $team->id,
    ]);
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://spent-drain-journal-fence.example.test',
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'ports_exposes' => '3000',
        'health_check_enabled' => true,
    ]);
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    $application = $application->fresh(['settings']);

    $operationUuid = 'spent-first-adoption-drain-owner';
    $candidateContainerId = str_repeat('a', 64);
    $previousContainerId = str_repeat('b', 64);
    $topologyDigest = hash('sha256', 'spent-drain-journal-topology');
    $routingConfigDigest = hash('sha256', 'spent-drain-journal-routing');
    $inventory = BlueGreenBackendPortInventory::fromPorts([3000, 8080]);

    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $operationUuid,
        'pull_request_id' => 0,
        'commit' => 'spent-drain-journal-commit',
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'finished_at' => now()->subMinute(),
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_topology_digest' => $topologyDigest,
        'blue_green_routing_config_digest' => $routingConfigDigest,
        'blue_green_candidate_container_id' => $candidateContainerId,
        'blue_green_previous_container_id' => $previousContainerId,
        'blue_green_server_boot_id' => SPENT_DRAIN_JOURNAL_BOOT_ID,
        'blue_green_supersession_generation' => 1,
        'blue_green_backend_port_inventory' => BlueGreenBackendPortInventory::fromPorts([3000])->serialized,
        'blue_green_drain_backend_port_inventory' => $inventory->serialized,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => $operationUuid,
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeploymentPhase::DRAINING->value,
        'intervention_reason' => RecoverBlueGreenIntervention::UNRECONSTRUCTABLE_DRAIN_REASON,
        'routing_revision' => 1,
        'supersession_generation' => 1,
        'destination_fence_epoch' => 1,
        'destination_fence_operation_id' => $operationUuid,
        'destination_fence_mutation_sequence' => 2,
        'managed_file_sha256' => hash('sha256', 'spent-drain-journal-managed'),
        'destination_topology_digest' => $topologyDigest,
        'application_routing_config_digest' => $routingConfigDigest,
        'legacy_container_name' => 'spent-drain-legacy-container',
        'operation_deployment_uuid' => $operationUuid,
        'operation_candidate_container_id' => $candidateContainerId,
        'operation_previous_container_name' => 'spent-drain-legacy-container',
        'operation_previous_container_id' => $previousContainerId,
        'operation_routing_config_digest' => $routingConfigDigest,
        'operation_server_boot_id' => SPENT_DRAIN_JOURNAL_BOOT_ID,
        'operation_drain_deadline_at' => now()->subMinutes(5),
        'operation_drain_last_observed_connections' => 1,
        'operation_drain_observed_at' => now()->subMinutes(4),
    ]);

    return [
        'application' => $application,
        'deployment' => $deployment,
        'state' => $state,
    ];
}

function spentFirstAdoptionDrainJournalFence(Application $application): BlueGreenOperationFence
{
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($application->id, $application->destination->id),
        300,
    );
    expect($lock->get())->toBeTrue();

    return new BlueGreenOperationFence($lock, 300);
}

it('archives a spent first-adoption drain journal on a destination carrying only terminal deactivation history', function (BlueGreenDeactivationPhase $phase): void {
    ['application' => $application, 'deployment' => $deployment, 'state' => $state] = makeSpentFirstAdoptionDrainJournalFixture();
    // A deactivation row is permanent history and nothing ever deletes one. An
    // application stopped even once would otherwise fail every later deployment
    // whose first-adoption drain went spent, and leave the pending mutation
    // journal on the host with no owner able to quarantine it.
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => $phase,
        'operation_id' => hash('sha256', 'terminal-spent-drain-history'),
        'started_at' => now()->subHour(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'completed_at' => now()->subMinutes(59),
    ]);
    expect($deployment->created_at->gt(now()->subHour()))->toBeTrue();

    // The archival reaches the host boot-identity proof it binds to and then
    // dispatches its authenticated inspection, both of which are only reachable
    // once terminal history stops fencing it.
    $remoteCommands = [];
    Process::fake(function ($process) use (&$remoteCommands) {
        $remoteCommands[] = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;

        return Process::result(output: SPENT_DRAIN_JOURNAL_BOOT_ID);
    });

    expect(fn () => QuarantineSpentFirstAdoptionDrainJournal::run(
        $state->id,
        spentFirstAdoptionDrainJournalFence($application),
    ))->toThrow(
        BlueGreenDeploymentTransitionException::class,
        'The spent first-adoption drain journal did not return its exact authenticated result.',
    );
    expect($remoteCommands)->toHaveCount(2)
        ->and($remoteCommands[0])->toContain('boot_id');
})->with([
    'stopped' => BlueGreenDeactivationPhase::STOPPED,
    'completed' => BlueGreenDeactivationPhase::COMPLETED,
]);

it('refuses to archive a spent first-adoption drain journal while the deactivation is a live fence', function (
    BlueGreenDeactivationPhase $phase,
    bool $completed,
): void {
    ['application' => $application, 'state' => $state] = makeSpentFirstAdoptionDrainJournalFixture();
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => $phase,
        'operation_id' => hash('sha256', 'live-spent-drain-fence'),
        'started_at' => now()->subMinute(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'completed_at' => $completed ? now() : null,
    ]);
    Process::fake();

    expect(fn () => QuarantineSpentFirstAdoptionDrainJournal::run(
        $state->id,
        spentFirstAdoptionDrainJournalFence($application),
    ))->toThrow(
        BlueGreenDeploymentTransitionException::class,
        'The spent first-adoption drain no longer has one exact live destination owner.',
    );
    Process::assertNothingRan();
})->with([
    'deactivating' => [BlueGreenDeactivationPhase::DEACTIVATING, false],
    'stopping' => [BlueGreenDeactivationPhase::STOPPING, false],
    'removing' => [BlueGreenDeactivationPhase::REMOVING, false],
    'intervention required' => [BlueGreenDeactivationPhase::INTERVENTION_REQUIRED, false],
    'removed' => [BlueGreenDeactivationPhase::REMOVED, true],
]);

it('refuses to archive a spent first-adoption drain journal whose owner terminal stop history cut off', function (): void {
    ['application' => $application, 'deployment' => $deployment, 'state' => $state] = makeSpentFirstAdoptionDrainJournalFixture();
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => BlueGreenDeactivationPhase::STOPPED,
        'operation_id' => hash('sha256', 'cut-off-spent-drain-owner'),
        'started_at' => now(),
        'queue_cutoff_id' => $deployment->getKey(),
        'supersession_generation' => 1,
        'completed_at' => now(),
    ]);
    Process::fake();

    expect(fn () => QuarantineSpentFirstAdoptionDrainJournal::run(
        $state->id,
        spentFirstAdoptionDrainJournalFence($application),
    ))->toThrow(
        BlueGreenDeploymentTransitionException::class,
        'The spent first-adoption drain no longer has one exact live destination owner.',
    );
    Process::assertNothingRan();
});
