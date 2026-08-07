<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenManagedRouteMetadataForOperationResult;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\DrainBlueGreenPreviousContainer;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\ResolveBlueGreenExpectedProxyState;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ContainerStatusTypes;
use App\Enums\ProxyTypes;
use App\Http\Middleware\ApiAbility;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\RetireBlueGreenInactiveContainerJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ApplicationDeploymentJob::class]);
    Notification::fake();
    Process::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('emergency-recovery-test', ['deploy'])->plainTextToken;
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

function emergencyRecoveryHeaders(string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'Content-Type' => 'application/json',
    ];
}

function makeEmergencyRecoveryDeployment(
    Environment $environment,
    Server $server,
    StandaloneDocker $destination,
    string $status = ApplicationDeploymentStatus::IN_PROGRESS->value,
): ApplicationDeploymentQueue {
    $privateKey = PrivateKey::factory()->create(['team_id' => $environment->project->team_id]);
    $application = Application::query()->create([
        'name' => 'emergency-recovery-app',
        'uuid' => (string) str()->uuid(),
        'git_repository' => 'coollabsio/coolify-examples',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'private_key_id' => $privateKey->id,
    ]);

    return ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => (string) str()->uuid(),
        'pull_request_id' => 0,
        'commit' => 'emergency-recovery',
        'status' => $status,
    ]);
}

/**
 * @return array{application: Application, deployment: ApplicationDeploymentQueue, owner: ApplicationDeploymentQueue, state: ApplicationBlueGreenDeployment}
 */
function makeEmergencyFailedFirstAdoptionJournalScenario(
    Environment $environment,
    Server $server,
    StandaloneDocker $destination,
): array {
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $deployment = makeEmergencyRecoveryDeployment($environment, $server, $destination);
    $application = Application::query()->findOrFail($deployment->application_id);
    $application->update([
        'fqdn' => 'https://emergency-failed-first-adoption.example.test',
        'health_check_enabled' => true,
        'ports_mappings' => null,
    ]);
    $application->settings->update(['is_blue_green_deployment_enabled' => true]);
    $ownerUuid = 'emergency-failed-first-adoption-owner';
    $inventory = BlueGreenBackendPortInventory::forApplication($application, $application->settings);
    $fingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::BLUE,
        1,
        1,
        $ownerUuid,
    );
    $managedFilename = BlueGreenRoutingTarget::managedFilename(
        (string) $application->uuid,
        (int) $destination->id,
    );
    $owner = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $ownerUuid,
        'pull_request_id' => 0,
        'commit' => 'emergency-failed-first-adoption-owner-commit',
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'finished_at' => now(),
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_supersession_generation' => 1,
        'blue_green_candidate_container_id' => null,
        'blue_green_previous_container_id' => str_repeat('d', 64),
        'blue_green_rollback_managed_filename' => $managedFilename,
        'blue_green_routing_mutated_at' => null,
        'blue_green_server_boot_id' => '22222222-3333-4444-5555-666666666666',
        'blue_green_topology_digest' => $fingerprint->operationTopologyDigest,
        'blue_green_routing_config_digest' => $fingerprint->routingConfigDigest,
        'blue_green_backend_port_inventory' => $inventory->serialized,
        'blue_green_drain_backend_port_inventory' => $inventory->serialized,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create(array_merge(
        [
            'application_id' => $application->id,
            'standalone_docker_id' => $destination->id,
            'phase' => BlueGreenDeploymentPhase::IDLE,
            'active_color' => null,
            'pending_color' => null,
            'blue_deployment_uuid' => null,
            'green_deployment_uuid' => null,
            'pending_deployment_uuid' => null,
            'legacy_container_name' => $application->uuid.'-legacy',
            'supersession_generation' => 1,
            'routing_revision' => 0,
            'destination_fence_epoch' => 0,
            'destination_fence_operation_id' => null,
            'destination_fence_mutation_sequence' => 0,
            'managed_file_sha256' => null,
            'destination_topology_digest' => null,
            'application_routing_config_digest' => null,
            'intervention_phase' => null,
            'intervention_reason' => null,
        ],
        ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
    ));
    ApplicationBlueGreenReplica::query()->create([
        'application_blue_green_deployment_id' => $state->id,
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'color' => BlueGreenDeploymentColor::BLUE,
        'replica_index' => 1,
        'deployment_uuid' => $ownerUuid,
        'routing_revision' => 1,
        'compose_project' => $application->uuid,
        'compose_service' => $application->uuid.'-blue',
        'container_name' => $application->uuid.'-blue',
        'container_id' => null,
        'health_status' => 'pending',
        'last_observed_at' => null,
    ]);

    return compact('application', 'deployment', 'owner', 'state');
}

/**
 * @return array{application: Application, deployment: ApplicationDeploymentQueue, owner: ApplicationDeploymentQueue, state: ApplicationBlueGreenDeployment}
 */
function makeEmergencyCommittedIdleRetirementScenario(
    Environment $environment,
    Server $server,
    StandaloneDocker $destination,
): array {
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $deployment = makeEmergencyRecoveryDeployment($environment, $server, $destination);
    $application = Application::query()->findOrFail($deployment->application_id);
    $application->update([
        'fqdn' => 'https://emergency-idle-retirement.example.test',
        'health_check_enabled' => true,
    ]);
    $application->settings->update([
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
        'custom_internal_name' => null,
        'is_blue_green_deployment_enabled' => true,
    ]);

    $ownerUuid = 'emergency-idle-retirement-owner';
    $inactiveUuid = 'emergency-idle-retirement-inactive';
    $activeContainerId = str_repeat('c', 64);
    $inactiveContainerId = str_repeat('a', 64);
    $fingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::GREEN,
        2,
        2,
        $ownerUuid,
    );
    $runtimeState = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        new BlueGreenRoutingTarget(
            destinationId: $destination->id,
            activeColor: BlueGreenDeploymentColor::GREEN,
            blueContainerName: $application->uuid.'-blue',
            greenContainerName: $application->uuid.'-green',
            port: 3000,
            routingRevision: 2,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($ownerUuid),
            destinationFenceEpoch: 2,
            operationId: $ownerUuid,
            mutationSequence: 2,
            activeDeploymentUuid: $ownerUuid,
            activeContainerId: $activeContainerId,
            destinationTopologyDigest: $fingerprint->operationTopologyDigest,
        ),
    )->state;
    $inventory = BlueGreenBackendPortInventory::forApplication($application, $application->settings);
    $owner = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $ownerUuid,
        'pull_request_id' => 0,
        'commit' => 'emergency-idle-retirement-owner-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 2,
        'blue_green_destination_fence_epoch' => 2,
        'blue_green_topology_digest' => $fingerprint->operationTopologyDigest,
        'blue_green_routing_config_digest' => $fingerprint->routingConfigDigest,
        'blue_green_candidate_container_id' => $activeContainerId,
        'blue_green_backend_port_inventory' => $inventory->serialized,
        'blue_green_drain_backend_port_inventory' => $inventory->serialized,
    ]);
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $inactiveUuid,
        'pull_request_id' => 0,
        'commit' => 'emergency-idle-retirement-inactive-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_topology_digest' => $fingerprint->operationTopologyDigest,
        'blue_green_routing_config_digest' => $fingerprint->routingConfigDigest,
        'blue_green_candidate_container_id' => $inactiveContainerId,
        'blue_green_backend_port_inventory' => $inventory->serialized,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'blue_deployment_uuid' => $inactiveUuid,
        'green_deployment_uuid' => $ownerUuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
        'supersession_generation' => 2,
        'destination_fence_epoch' => $runtimeState->destinationFenceEpoch,
        'destination_fence_operation_id' => $runtimeState->operationId,
        'destination_fence_mutation_sequence' => $runtimeState->mutationSequence,
        'managed_file_sha256' => $runtimeState->managedSha256,
        'destination_topology_digest' => $runtimeState->destinationTopologyDigest,
        'destination_routing_topology_digest' => $fingerprint->routingTopologyDigest,
        'application_routing_config_digest' => $runtimeState->applicationRoutingConfigDigest,
        'inactive_retirement_owner_deployment_uuid' => $ownerUuid,
        'inactive_retirement_color' => BlueGreenDeploymentColor::BLUE,
        'inactive_retirement_deployment_uuid' => $inactiveUuid,
        'inactive_retirement_container_id' => $inactiveContainerId,
        'inactive_retirement_container_routing_revision' => 1,
        'inactive_retirement_owner_routing_revision' => 2,
        'inactive_retirement_supersession_generation' => 2,
        'inactive_retirement_destination_fence_epoch' => 2,
        'inactive_retirement_server_boot_id' => '11111111-2222-3333-4444-555555555555',
        'inactive_retirement_topology_digest' => $runtimeState->destinationTopologyDigest,
        'inactive_retirement_routing_config_digest' => $runtimeState->applicationRoutingConfigDigest,
        'inactive_retirement_not_before_at' => now()->subMinute(),
        'inactive_retirement_drain_deadline_at' => now(),
        'inactive_retirement_stop_grace_seconds' => 30,
        'inactive_retirement_lease_seconds' => 4_000,
        'inactive_retirement_intervention_required_at' => now(),
    ]);

    return compact('application', 'deployment', 'owner', 'state');
}

function prepareEmergencyRemoteServer(Server $server, PrivateKey $privateKey): void
{
    config(['constants.ssh.mux_enabled' => false]);
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
    $server->update(['private_key_id' => $privateKey->id]);
}

/** @param list<string> $payloads */
function fakeEmergencyFailedFirstAdoptionJournalRemote(
    Application $application,
    StandaloneDocker $destination,
    ApplicationDeploymentQueue $owner,
    ApplicationBlueGreenDeployment $state,
    array &$payloads,
    bool &$archived,
    ?Closure $afterFirstBootRead = null,
): void {
    $managedFilename = BlueGreenRoutingTarget::managedFilename(
        (string) $application->uuid,
        (int) $destination->id,
    );
    $writer = new WriteBlueGreenProxyConfiguration;
    $journalSha256 = hash('sha256', 'emergency-failed-first-adoption-journal');
    $archiveFilename = $writer->staleContainerMutationJournalArchiveFilename(
        $managedFilename,
        (int) $state->id,
    );
    $provenance = $writer->staleContainerMutationJournalProvenanceSha256For(
        $managedFilename,
        (string) $application->uuid,
        (int) $destination->id,
        (string) $owner->deployment_uuid,
        (string) $owner->blue_green_server_boot_id,
        (string) $owner->blue_green_routing_config_digest,
        (string) $owner->blue_green_topology_digest,
    );
    $legacyRuntime = json_encode([
        'Id' => str_repeat('d', 64),
        'Name' => '/'.$application->uuid.'-legacy',
        'State' => [
            'Status' => 'running',
            'Health' => ['Status' => 'healthy'],
        ],
        'Config' => [
            'Labels' => [
                'coolify.applicationId' => (string) $application->id,
                'coolify.pullRequestId' => '0',
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $payloads = [];
    $archived = false;
    $bootRead = false;
    Process::fake(function (PendingProcess $process) use (
        $afterFirstBootRead,
        &$archived,
        $archiveFilename,
        &$bootRead,
        $journalSha256,
        $legacyRuntime,
        &$payloads,
        $provenance,
    ): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        $payloads[] = $payload;
        if (str_contains($payload, 'coolify-blue-green-stale-first-adoption-runtime:v1')) {
            return Process::result(output: "coolify-blue-green-stale-first-adoption-runtime:v1\n{$legacyRuntime}");
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX)) {
            $status = 'pending';
            if (str_contains($payload, 'durable_remote_replace "$container_journal_path" "$container_journal_archive_path"')) {
                $archived = true;
                $status = 'archived';
            }

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX,
                $status,
                $journalSha256,
                $archiveFilename,
                $provenance,
            ]));
        }
        if (str_contains($payload, '/proc/sys/kernel/random/boot_id')) {
            if (! $bootRead) {
                $bootRead = true;
                $afterFirstBootRead?->__invoke();
            }

            return Process::result(output: '11111111-2222-3333-4444-555555555555');
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:absent')) {
            return Process::result(output: 'coolify-blue-green-managed-route:absent');
        }

        return Process::result();
    });
}

/**
 * @param  list<string>  $payloads
 */
function fakeEmergencyCommittedIdleRetirementJournalRemote(
    array &$payloads,
    bool &$archived,
    bool &$strictManagedRouteRead,
    BlueGreenProxyState $expectedState,
    BlueGreenProxyState $replacementState,
    string $bootId,
    ?BlueGreenProxyState $reportedReplacementState = null,
): void {
    $payloads = [];
    $journalSha256 = hash('sha256', 'emergency-committed-inactive-retirement-journal');
    $archiveFilename = (new WriteBlueGreenProxyConfiguration)->committedContainerMutationJournalArchiveFilename(
        $expectedState->managedFilename,
        $journalSha256,
    );
    $reportedReplacementState ??= $replacementState;
    $replacementManagedSha256 = $replacementState->managedSha256
        ?? throw new RuntimeException('The emergency committed retirement fixture requires a present replacement route.');
    $reportedReplacementManagedSha256 = $reportedReplacementState->managedSha256
        ?? throw new RuntimeException('The reported emergency retirement fixture requires a present replacement route.');
    Process::fake(function (PendingProcess $process) use (
        &$payloads,
        &$archived,
        &$strictManagedRouteRead,
        $archiveFilename,
        $bootId,
        $journalSha256,
        $expectedState,
        $replacementState,
        $replacementManagedSha256,
        $reportedReplacementManagedSha256,
        $reportedReplacementState,
    ): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        $payloads[] = $payload;
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: $bootId);
        }
        if (str_contains($payload, 'committed_container_manifest_stage=')) {
            $archived = true;

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::COMMITTED_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX,
                'archived',
                $journalSha256,
                $archiveFilename,
            ]));
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            if (! $archived) {
                return Process::result();
            }
            $strictManagedRouteRead = true;

            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($replacementState->serialize())
                ."\n".$replacementManagedSha256);
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX)) {
            $archived = true;

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
                $journalSha256,
                $archiveFilename,
            ]));
        }
        if (! str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            return Process::result();
        }

        return Process::result(output: implode('|', [
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
            BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
            $journalSha256,
            $bootId,
            'present',
            $reportedReplacementManagedSha256,
            hash('sha256', 'emergency-committed-mutation-script'),
            hash('sha256', 'emergency-committed-completion-script'),
        ])."\n".base64_encode($expectedState->serialize())."\n".base64_encode($reportedReplacementState->serialize()));
    });
}

/**
 * @param  list<string>  $payloads
 */
function fakeEmergencyAbsentIdleRetirementJournalRemote(
    array &$payloads,
    bool &$strictManagedRouteRead,
    BlueGreenProxyState $expectedState,
    string $bootId,
): void {
    $payloads = [];
    $strictManagedRouteRead = false;
    $managedSha256 = $expectedState->managedSha256
        ?? throw new RuntimeException('The journal-free emergency fixture requires a present expected route.');
    Process::fake(function (PendingProcess $process) use (
        &$payloads,
        &$strictManagedRouteRead,
        $bootId,
        $expectedState,
        $managedSha256,
    ): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        $payloads[] = $payload;
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: $bootId);
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            return Process::result(
                output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent',
            );
        }
        if (str_contains($payload, 'coolify-blue-green-destination-state-attested')) {
            return Process::result(output: 'coolify-blue-green-destination-state-attested');
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            $strictManagedRouteRead = true;

            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($expectedState->serialize())
                ."\n".$managedSha256);
        }

        return Process::result();
    });
}

/**
 * @param  list<string>  $payloads
 */
function fakeEmergencyPendingExpectedSidecarJournalRemote(
    array &$payloads,
    bool &$archiveRequested,
    bool &$journalPresent,
    bool &$absenceProven,
    bool &$journalScriptsReplayed,
    BlueGreenProxyState $expectedState,
    BlueGreenProxyState $replacementState,
    string $bootId,
    bool $journalReappearsAfterArchive = false,
    bool $timeoutAfterPendingArchive = false,
): void {
    $payloads = [];
    $archiveRequested = false;
    $journalPresent = true;
    $absenceProven = false;
    $journalScriptsReplayed = false;
    $casCalls = 0;
    $journalSha256 = hash('sha256', 'emergency-pending-expected-sidecar-journal');
    $archiveFilename = (new WriteBlueGreenProxyConfiguration)->committedContainerMutationJournalArchiveFilename(
        $expectedState->managedFilename,
        $journalSha256,
    );
    $replacementManagedSha256 = $replacementState->managedSha256
        ?? throw new RuntimeException('The pending emergency recovery fixture requires a present replacement route.');
    Process::fake(function (PendingProcess $process) use (
        &$absenceProven,
        &$archiveRequested,
        $archiveFilename,
        $bootId,
        &$casCalls,
        $expectedState,
        $journalReappearsAfterArchive,
        &$journalPresent,
        $journalSha256,
        &$journalScriptsReplayed,
        &$payloads,
        $replacementManagedSha256,
        $replacementState,
        $timeoutAfterPendingArchive,
    ): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        $payloads[] = $payload;
        if (str_contains($payload, 'sh "$container_journal_mutation_decoded"')
            || str_contains($payload, 'sh "$container_journal_completion_decoded"')
            || str_contains($payload, 'sh "$operation_container_mutation_decoded"')
            || str_contains($payload, 'sh "$operation_container_completion_decoded"')) {
            $journalScriptsReplayed = true;
        }
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: $bootId);
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX)) {
            $casCalls++;
            $archiveRequested = true;
            $casStatus = str_contains($payload, 'operation_container_state_stage=')
                || str_contains($payload, 'coolify-blue-green-finalized-container-journal-archive-v1')
                    ? BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR
                    : BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR;
            if ($journalReappearsAfterArchive && $casCalls > 1) {
                $journalPresent = true;

                return Process::result(
                    errorOutput: 'The pending container journal reappeared before finalization could prove absence.',
                    exitCode: 1,
                );
            }
            $journalPresent = $journalReappearsAfterArchive;
            if ($casStatus === BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR) {
                $absenceProven = true;
                $journalPresent = false;
            }

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
                $casStatus,
                $journalSha256,
                $archiveFilename,
            ]));
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            if (! $journalPresent) {
                $absenceProven = true;

                return Process::result(
                    output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent',
                );
            }

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
                $journalSha256,
                $bootId,
                'present',
                $replacementManagedSha256,
                hash('sha256', 'emergency-pending-mutation-script'),
                hash('sha256', 'emergency-pending-completion-script'),
            ])."\n".base64_encode($expectedState->serialize())
                ."\n".base64_encode($replacementState->serialize()));
        }
        if ($timeoutAfterPendingArchive && str_contains($payload, 'container_journal_stage=')) {
            $journalPresent = true;

            return Process::result(
                errorOutput: DrainBlueGreenPreviousContainer::TIMEOUT_MARKER.' with 1 active backend connection(s)',
                exitCode: 1,
            );
        }
        if (str_contains($payload, 'drain_pid=')) {
            return Process::result(output: "1\n");
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:') && $archiveRequested) {
            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($replacementState->serialize())
                ."\n".$replacementManagedSha256);
        }

        return Process::result();
    });
}

it('archives an exact failed first-adoption stale journal before releasing its emergency queue handle', function (): void {
    ['application' => $application, 'deployment' => $deployment, 'owner' => $owner, 'state' => $state] = makeEmergencyFailedFirstAdoptionJournalScenario(
        $this->environment,
        $this->server,
        $this->destination,
    );
    prepareEmergencyRemoteServer(
        $this->server,
        PrivateKey::query()->findOrFail($application->private_key_id),
    );
    $payloads = [];
    $archived = false;
    fakeEmergencyFailedFirstAdoptionJournalRemote(
        $application,
        $this->destination,
        $owner,
        $state,
        $payloads,
        $archived,
    );

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk()
        ->assertJsonPath('outcome', 'clean')
        ->assertJsonPath('claimable', true)
        ->assertJsonPath('cancelled', true)
        ->assertJsonPath('recovery_owner_active', false);

    expect($archived)->toBeTrue()
        ->and($deployment->horizon_job_id)->toBeNull()
        ->and($deployment->fresh()->status)->not->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and(implode("\n", $payloads))->toContain(
            'durable_remote_replace "$container_journal_path" "$container_journal_archive_path"',
        )->not->toContain(
            'sh "$container_journal_mutation_decoded"',
            'sh "$container_journal_completion_decoded"',
        );
});

it('returns a stable inspection failure code without remote diagnostics', function (): void {
    ['application' => $application, 'deployment' => $deployment] = makeEmergencyFailedFirstAdoptionJournalScenario(
        $this->environment,
        $this->server,
        $this->destination,
    );
    prepareEmergencyRemoteServer(
        $this->server,
        PrivateKey::query()->findOrFail($application->private_key_id),
    );
    $remoteDiagnostic = 'ssh: connect to host 10.0.0.9 port 22: Connection refused; docker parser state=invalid';
    Process::fake(function (PendingProcess $process) use ($remoteDiagnostic): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (str_contains($payload, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: '11111111-2222-3333-4444-555555555555');
        }
        if (str_contains($payload, 'coolify-blue-green-stale-first-adoption-runtime:v1')) {
            return Process::result(errorOutput: $remoteDiagnostic, exitCode: 255);
        }

        return Process::result();
    });

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk()
        ->assertJsonPath('outcome', 'manual_only')
        ->assertJsonPath('reason_code', 'inspection_failed')
        ->assertJsonPath('message', 'Deployment recovery could not be completed safely; no unproven recovery action was taken.')
        ->assertJsonMissing(['message' => $remoteDiagnostic]);
    expect($response->json('correlation_id'))->toBeUuid();
});

it('returns a correlated stable response when emergency recovery throws', function (): void {
    ['deployment' => $deployment] = makeEmergencyFailedFirstAdoptionJournalScenario(
        $this->environment,
        $this->server,
        $this->destination,
    );
    $remoteDiagnostic = 'docker daemon replied with database password: not-for-public-response';
    $throwOnStateLookup = true;
    Event::listen(
        'eloquent.retrieved: '.ApplicationBlueGreenDeployment::class,
        static function () use (&$throwOnStateLookup, $remoteDiagnostic): void {
            if ($throwOnStateLookup) {
                throw new RuntimeException($remoteDiagnostic);
            }
        },
    );

    try {
        $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
            ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");
    } finally {
        $throwOnStateLookup = false;
    }

    $response->assertStatus(500)
        ->assertJsonPath('reason_code', 'recovery_failed')
        ->assertJsonPath('message', 'Deployment recovery could not be completed safely.')
        ->assertJsonMissing(['message' => $remoteDiagnostic]);
    expect($response->json('correlation_id'))->toBeUuid();
});

it('does not cancel an exact failed first-adoption successor while its lifecycle owner is active', function (): void {
    ['application' => $application, 'deployment' => $deployment, 'owner' => $owner, 'state' => $state] = makeEmergencyFailedFirstAdoptionJournalScenario(
        $this->environment,
        $this->server,
        $this->destination,
    );
    $dispatchAttemptUuid = (string) str()->uuid();
    $deployment->update(['horizon_job_id' => $dispatchAttemptUuid]);
    prepareEmergencyRemoteServer(
        $this->server,
        PrivateKey::query()->findOrFail($application->private_key_id),
    );
    $payloads = [];
    $archived = false;
    fakeEmergencyFailedFirstAdoptionJournalRemote(
        $application,
        $this->destination,
        $owner,
        $state,
        $payloads,
        $archived,
    );
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($application->id, $this->destination->id),
        BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
    );
    expect($lock->get())->toBeTrue();

    try {
        $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
            ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");
    } finally {
        $lock->release();
    }

    $response->assertOk()
        ->assertJsonPath('outcome', 'deferred')
        ->assertJsonPath('claimable', false)
        ->assertJsonPath('cancelled', false)
        ->assertJsonPath('recovery_owner_active', true)
        ->assertJsonPath('reason_code', 'recovery_deferred')
        ->assertJsonPath('correlation_id', null);

    $preservedDeployment = $deployment->fresh();
    expect($archived)->toBeFalse()
        ->and($preservedDeployment->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($preservedDeployment->horizon_job_id)->toBe($dispatchAttemptUuid)
        ->and($preservedDeployment->finished_at)->toBeNull()
        ->and(implode("\n", $payloads))->not->toContain(
            'durable_remote_replace "$container_journal_path" "$container_journal_archive_path"',
        );
});

it('does not cancel or advance the queue when a newer attempt adopts the exact failed first-adoption row during inspection', function (): void {
    ['application' => $application, 'deployment' => $deployment, 'owner' => $owner, 'state' => $state] = makeEmergencyFailedFirstAdoptionJournalScenario(
        $this->environment,
        $this->server,
        $this->destination,
    );
    $originalDispatchAttemptUuid = (string) str()->uuid();
    $replacementDispatchAttemptUuid = (string) str()->uuid();
    $deployment->update(['horizon_job_id' => $originalDispatchAttemptUuid]);
    $queuedFollower = $deployment->replicate();
    $queuedFollower->deployment_uuid = (string) str()->uuid();
    $queuedFollower->pull_request_id = 1;
    $queuedFollower->commit = 'emergency-binding-drift-queued-follower';
    $queuedFollower->status = ApplicationDeploymentStatus::QUEUED->value;
    $queuedFollower->horizon_job_id = null;
    $queuedFollower->save();
    prepareEmergencyRemoteServer(
        $this->server,
        PrivateKey::query()->findOrFail($application->private_key_id),
    );
    $payloads = [];
    $archived = false;
    fakeEmergencyFailedFirstAdoptionJournalRemote(
        $application,
        $this->destination,
        $owner,
        $state,
        $payloads,
        $archived,
        afterFirstBootRead: static function () use ($deployment, $replacementDispatchAttemptUuid): void {
            ApplicationDeploymentQueue::query()
                ->whereKey($deployment->getKey())
                ->update(['horizon_job_id' => $replacementDispatchAttemptUuid]);
        },
    );

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk()
        ->assertJsonPath('outcome', 'manual_only')
        ->assertJsonPath('claimable', false)
        ->assertJsonPath('cancelled', false)
        ->assertJsonPath('recovery_owner_active', false);

    $preservedDeployment = $deployment->fresh();
    $untouchedFollower = $queuedFollower->fresh();
    expect($archived)->toBeFalse()
        ->and($preservedDeployment->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($preservedDeployment->horizon_job_id)->toBe($replacementDispatchAttemptUuid)
        ->and($preservedDeployment->finished_at)->toBeNull()
        ->and($untouchedFollower->status)->toBe(ApplicationDeploymentStatus::QUEUED->value)
        ->and($untouchedFollower->horizon_job_id)->toBeNull()
        ->and(implode("\n", $payloads))->not->toContain(
            'durable_remote_replace "$container_journal_path" "$container_journal_archive_path"',
            "docker ps -a --filter name={$deployment->deployment_uuid}",
        );
    Bus::assertNotDispatched(ApplicationDeploymentJob::class);
});

it('does not cancel or advance the queue when a worker acquires the same recovery attempt during inspection', function (): void {
    ['application' => $application, 'deployment' => $deployment, 'owner' => $owner, 'state' => $state] = makeEmergencyFailedFirstAdoptionJournalScenario(
        $this->environment,
        $this->server,
        $this->destination,
    );
    $dispatchAttemptUuid = (string) str()->uuid();
    $deployment->update([
        'horizon_job_id' => $dispatchAttemptUuid,
        'horizon_job_worker' => null,
    ]);
    $queuedFollower = $deployment->replicate();
    $queuedFollower->deployment_uuid = (string) str()->uuid();
    $queuedFollower->pull_request_id = 1;
    $queuedFollower->commit = 'emergency-worker-binding-drift-queued-follower';
    $queuedFollower->status = ApplicationDeploymentStatus::QUEUED->value;
    $queuedFollower->horizon_job_id = null;
    $queuedFollower->horizon_job_worker = null;
    $queuedFollower->save();
    prepareEmergencyRemoteServer(
        $this->server,
        PrivateKey::query()->findOrFail($application->private_key_id),
    );
    $payloads = [];
    $archived = false;
    fakeEmergencyFailedFirstAdoptionJournalRemote(
        $application,
        $this->destination,
        $owner,
        $state,
        $payloads,
        $archived,
        afterFirstBootRead: static function () use ($deployment): void {
            ApplicationDeploymentQueue::query()
                ->whereKey($deployment->getKey())
                ->update(['horizon_job_worker' => 'newly-active-worker']);
        },
    );

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk()
        ->assertJsonPath('outcome', 'clean')
        ->assertJsonPath('claimable', true)
        ->assertJsonPath('cancelled', false)
        ->assertJsonPath('recovery_owner_active', false);

    $preservedDeployment = $deployment->fresh();
    $untouchedFollower = $queuedFollower->fresh();
    expect($archived)->toBeTrue()
        ->and($preservedDeployment->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($preservedDeployment->horizon_job_id)->toBe($dispatchAttemptUuid)
        ->and($preservedDeployment->horizon_job_worker)->toBe('newly-active-worker')
        ->and($preservedDeployment->finished_at)->toBeNull()
        ->and($untouchedFollower->status)->toBe(ApplicationDeploymentStatus::QUEUED->value)
        ->and($untouchedFollower->horizon_job_id)->toBeNull()
        ->and(implode("\n", $payloads))->not->toContain(
            "docker ps -a --filter name={$deployment->deployment_uuid}",
        );
    Bus::assertNotDispatched(ApplicationDeploymentJob::class);
});

it('reports a destination as claimable when no durable blue-green state fences it', function () {
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $this->server, $this->destination);
    $dispatchAttemptUuid = (string) str()->uuid();
    $deployment->update(['horizon_job_id' => $dispatchAttemptUuid]);
    $application = Application::query()->findOrFail($deployment->application_id);
    prepareEmergencyRemoteServer(
        $this->server,
        PrivateKey::query()->findOrFail($application->private_key_id),
    );
    Process::fake(function (PendingProcess $process): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;

        return Process::result(output: str_contains($payload, 'coolify-blue-green-managed-route:absent')
            ? 'coolify-blue-green-managed-route:absent'
            : '');
    });

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk()
        ->assertJsonPath('deployment_uuid', $deployment->deployment_uuid)
        ->assertJsonPath('outcome', 'clean')
        ->assertJsonPath('claimable', true)
        ->assertJsonPath('cancelled', true);

    $cancelledDeployment = $deployment->fresh();
    expect($cancelledDeployment->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
        ->and($cancelledDeployment->horizon_job_id)->toBe($dispatchAttemptUuid);
});

it('keeps remote diagnostics out of deployment logs when post-cancellation cleanup fails', function () {
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $this->server, $this->destination);
    $application = Application::query()->findOrFail($deployment->application_id);
    prepareEmergencyRemoteServer(
        $this->server,
        PrivateKey::query()->findOrFail($application->private_key_id),
    );
    $remoteDiagnostic = 'ssh: connect to host 10.0.0.9 port 22: Connection timed out; docker daemon unreachable';
    Process::fake(function (PendingProcess $process) use ($deployment, $remoteDiagnostic): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (str_contains($payload, "docker ps -a --filter name={$deployment->deployment_uuid}")) {
            return Process::result(errorOutput: $remoteDiagnostic, exitCode: 255);
        }

        return Process::result(output: str_contains($payload, 'coolify-blue-green-managed-route:absent')
            ? 'coolify-blue-green-managed-route:absent'
            : '');
    });

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk()
        ->assertJsonPath('cancelled', true);

    $logs = (string) $deployment->fresh()->logs;
    expect($logs)->toContain('Post-cancellation cleanup failed: reason=cleanup_failed correlation_id=')
        ->not->toContain($remoteDiagnostic)
        ->not->toContain('10.0.0.9');
});

it('does not cancel a deployment that already reached a terminal status', function () {
    $deployment = makeEmergencyRecoveryDeployment(
        $this->environment,
        $this->server,
        $this->destination,
        ApplicationDeploymentStatus::FAILED->value,
    );

    $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover")
        ->assertOk()
        ->assertJsonPath('cancelled', false)
        ->assertJsonPath('status', ApplicationDeploymentStatus::FAILED->value);
});

it('routes a parked intervention through recovery instead of leaving it fenced', function () {
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $this->server, $this->destination);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $deployment->application_id,
        'standalone_docker_id' => $this->destination->id,
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeploymentPhase::DRAINING->value,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'supersession_generation' => 1,
    ]);

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    // The recovery owner classifies fail-closed, so the durable outcome may be
    // clean, deferred, or manual-only; what must never happen is the emergency
    // endpoint reporting success while the destination stays fenced.
    $response->assertOk()
        ->assertJsonStructure(['deployment_uuid', 'status', 'cancelled', 'outcome', 'message', 'claimable']);

    expect($response->json('outcome'))->toBeIn(['clean', 'deferred', 'manual_only'])
        ->and($response->json('claimable'))->toBe(
            $response->json('outcome') === 'clean' && $response->json('claimable'),
        );
});

it('recovers a draining hang without first cancelling the row its owner proof needs', function () {
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $this->server, $this->destination);
    $deployment->update([
        'blue_green_phase' => BlueGreenDeploymentPhase::DRAINING,
        'blue_green_supersession_generation' => 1,
    ]);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $deployment->application_id,
        'standalone_docker_id' => $this->destination->id,
        'phase' => BlueGreenDeploymentPhase::DRAINING,
        'operation_deployment_uuid' => $deployment->deployment_uuid,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'supersession_generation' => 1,
    ]);

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk();

    // The reconciler's exact-owner proof requires an IN_PROGRESS queue row, so
    // cancelling before recovery would demote this to manual_only and, when a
    // fenced resume owner is in flight, strand the work it was called to
    // finish. Neither may happen.
    expect($response->json('outcome'))->not->toBe('manual_only');

    // A deferral is only a reason to leave the row running when something is
    // actually still running it. When recovery dispatched a fenced owner that
    // owner needs this exact IN_PROGRESS entry; when it dispatched nothing the
    // entry is a strand that would block every successor forever, and releasing
    // it is precisely what break-glass was called to do.
    if ($response->json('outcome') === 'deferred') {
        if ($response->json('recovery_owner_active') === true) {
            expect($response->json('cancelled'))->toBeFalse()
                ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
        } else {
            expect($response->json('cancelled'))->toBeTrue()
                ->and($deployment->fresh()->status)->not->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
        }
    }
});

it('refuses to recover through a stale deployment uuid that no longer owns the destination', function () {
    $deployment = makeEmergencyRecoveryDeployment(
        $this->environment,
        $this->server,
        $this->destination,
        ApplicationDeploymentStatus::FAILED->value,
    );
    $liveOperationUuid = (string) str()->uuid();
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $deployment->application_id,
        'standalone_docker_id' => $this->destination->id,
        'phase' => BlueGreenDeploymentPhase::DRAINING,
        'operation_deployment_uuid' => $liveOperationUuid,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'supersession_generation' => 2,
    ]);

    $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover")
        ->assertOk()
        ->assertJsonPath('cancelled', false);

    // The newer operation must be untouched by the stale handle.
    $state = ApplicationBlueGreenDeployment::query()
        ->where('standalone_docker_id', $this->destination->id)
        ->firstOrFail();
    expect($state->phase)->toBe(BlueGreenDeploymentPhase::DRAINING)
        ->and($state->operation_deployment_uuid)->toBe($liveOperationUuid);
});

it('recovers the old durable inactive-retirement owner without an intervention marker before releasing a stale emergency handle', function (): void {
    ['application' => $application, 'deployment' => $deployment, 'owner' => $owner, 'state' => $state] = makeEmergencyCommittedIdleRetirementScenario(
        $this->environment,
        $this->server,
        $this->destination,
    );
    prepareEmergencyRemoteServer(
        $application->destination->server,
        PrivateKey::query()->findOrFail($application->private_key_id),
    );
    $state->update(['inactive_retirement_intervention_required_at' => null]);
    $state = $state->fresh();
    expect($state->inactive_retirement_intervention_required_at)->toBeNull();
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The emergency recovery fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $payloads = [];
    $archived = false;
    $strictManagedRouteRead = false;
    fakeEmergencyCommittedIdleRetirementJournalRemote(
        $payloads,
        $archived,
        $strictManagedRouteRead,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
    );
    InspectBlueGreenContainer::shouldRun()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $state->inactive_retirement_container_id,
            status: ContainerStatusTypes::EXITED->value,
            health: 'healthy',
        ));

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk()
        ->assertJsonPath('outcome', 'clean')
        ->assertJsonPath('claimable', true)
        ->assertJsonPath('cancelled', true);

    $recoveredState = $state->fresh();
    expect($archived)->toBeTrue()
        ->and($strictManagedRouteRead)->toBeTrue()
        ->and($deployment->fresh()->status)->not->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($recoveredState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($recoveredState->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($recoveredState->destination_fence_operation_id)->toBe($owner->deployment_uuid)
        ->and($recoveredState->destination_fence_operation_id)->not->toBe($deployment->deployment_uuid)
        ->and($recoveredState->destination_fence_mutation_sequence)->toBe($replacementState->mutationSequence)
        ->and(implode("\n", $payloads))->not->toContain(
            'sh "$committed_container_mutation_decoded"',
            'sh "$committed_container_completion_decoded"',
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
            base64_encode($expectedState->withMutationOwner($deployment->deployment_uuid)->serialize()),
        );
});

it('keeps a legacy null routing topology digest manual-only during inactive-retirement recovery', function (): void {
    ['deployment' => $deployment, 'state' => $state] = makeEmergencyCommittedIdleRetirementScenario(
        $this->environment,
        $this->server,
        $this->destination,
    );
    $state->update(['destination_routing_topology_digest' => null]);

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk()
        ->assertJsonPath('outcome', 'manual_only')
        ->assertJsonPath('claimable', false);

    expect($state->fresh()->destination_routing_topology_digest)->toBeNull()
        ->and($state->fresh()->inactive_retirement_stopped_at)->toBeNull();
});

it('releases only the stale emergency queue row when a foreign destination lock makes inactive-retirement recovery retry', function (): void {
    ['application' => $application, 'deployment' => $deployment, 'owner' => $owner, 'state' => $state] = makeEmergencyCommittedIdleRetirementScenario(
        $this->environment,
        $this->server,
        $this->destination,
    );
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($application->id, $this->destination->id),
        60,
    );
    expect($lock->get())->toBeTrue();

    try {
        $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
            ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");
    } finally {
        $lock->release();
    }

    $response->assertOk()
        ->assertJsonPath('outcome', 'deferred')
        ->assertJsonPath('claimable', false)
        ->assertJsonPath('cancelled', true)
        ->assertJsonPath('recovery_owner_active', false);

    expect($deployment->fresh()->status)->not->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($owner->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($state->fresh()->inactive_retirement_stopped_at)->toBeNull()
        ->and($state->fresh()->inactive_retirement_dispatch_reserved_until_at)->toBeNull();
});

it('releases the exact stale emergency queue row while a reserved inactive-retirement owner continues retrying', function (): void {
    ['application' => $application, 'deployment' => $deployment, 'owner' => $owner, 'state' => $state] = makeEmergencyCommittedIdleRetirementScenario(
        $this->environment,
        $this->server,
        $this->destination,
    );
    $state->update(['inactive_retirement_dispatch_reserved_until_at' => now()->addMinute()]);
    $state = $state->fresh();
    $reservedUntil = $state->inactive_retirement_dispatch_reserved_until_at;
    expect($reservedUntil)->not->toBeNull();
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($application->id, $this->destination->id),
        60,
    );
    expect($lock->get())->toBeTrue();

    try {
        $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
            ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");
    } finally {
        $lock->release();
    }

    $response->assertOk()
        ->assertJsonPath('outcome', 'deferred')
        ->assertJsonPath('claimable', false)
        ->assertJsonPath('cancelled', true)
        ->assertJsonPath('recovery_owner_active', true)
        ->assertJsonPath('reason_code', 'recovery_deferred')
        ->assertJsonPath('correlation_id', null);

    $recoveredState = $state->fresh();
    expect($deployment->fresh()->status)->not->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($owner->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($recoveredState->inactive_retirement_owner_deployment_uuid)->toBe($owner->deployment_uuid)
        ->and($recoveredState->supersession_generation)->toBe(2)
        ->and($recoveredState->inactive_retirement_supersession_generation)->toBe(2)
        ->and($recoveredState->inactive_retirement_stopped_at)->toBeNull()
        ->and($recoveredState->inactive_retirement_dispatch_reserved_until_at?->equalTo($reservedUntil))->toBeTrue();
});

it('releases a stale emergency queue row when direct pending-journal recovery times out without reserving a retry worker', function (): void {
    Queue::fake();
    ['application' => $application, 'deployment' => $deployment, 'owner' => $owner, 'state' => $state] = makeEmergencyCommittedIdleRetirementScenario(
        $this->environment,
        $this->server,
        $this->destination,
    );
    prepareEmergencyRemoteServer(
        $application->destination->server,
        PrivateKey::query()->findOrFail($application->private_key_id),
    );
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The timed-out pending-journal fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $payloads = [];
    $archiveRequested = false;
    $journalPresent = true;
    $absenceProven = false;
    $journalScriptsReplayed = false;
    fakeEmergencyPendingExpectedSidecarJournalRemote(
        $payloads,
        $archiveRequested,
        $journalPresent,
        $absenceProven,
        $journalScriptsReplayed,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
        timeoutAfterPendingArchive: true,
    );
    InspectBlueGreenContainer::shouldRun()
        ->atLeast()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $state->inactive_retirement_container_id,
            status: ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ));

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk()
        ->assertJsonPath('outcome', 'deferred')
        ->assertJsonPath('claimable', false)
        ->assertJsonPath('cancelled', true)
        ->assertJsonPath('recovery_owner_active', false);

    $recoveredState = $state->fresh();
    expect($archiveRequested)->toBeTrue()
        ->and($journalPresent)->toBeTrue()
        ->and($absenceProven)->toBeFalse()
        ->and($journalScriptsReplayed)->toBeFalse()
        ->and($deployment->fresh()->status)->not->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($owner->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($recoveredState->inactive_retirement_attempts)->toBe(1)
        ->and($recoveredState->inactive_retirement_stopped_at)->toBeNull()
        ->and($recoveredState->inactive_retirement_dispatch_reserved_until_at)->toBeNull()
        ->and(implode("\n", $payloads))->toContain('container_journal_stage=');
    Queue::assertNotPushed(RetireBlueGreenInactiveContainerJob::class);
});

it('reconciles a committed inactive-retirement journal before evaluating stale idle diagnostics', function (
    ?string $interventionReason,
    string $expectedOutcome,
    bool $expectedClaimable,
): void {
    ['application' => $application, 'deployment' => $deployment, 'owner' => $owner, 'state' => $state] = makeEmergencyCommittedIdleRetirementScenario(
        $this->environment,
        $this->server,
        $this->destination,
    );
    prepareEmergencyRemoteServer(
        $application->destination->server,
        PrivateKey::query()->findOrFail($application->private_key_id),
    );
    $state->update([
        'intervention_phase' => BlueGreenDeploymentPhase::ROLLING_BACK->value,
        'intervention_reason' => $interventionReason,
    ]);
    $state = $state->fresh();
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The combined recovery fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $payloads = [];
    $archived = false;
    $strictManagedRouteRead = false;
    fakeEmergencyCommittedIdleRetirementJournalRemote(
        $payloads,
        $archived,
        $strictManagedRouteRead,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
    );
    InspectBlueGreenContainer::shouldRun()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $state->inactive_retirement_container_id,
            status: ContainerStatusTypes::EXITED->value,
            health: 'healthy',
        ));

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk()
        ->assertJsonPath('outcome', $expectedOutcome)
        ->assertJsonPath('claimable', $expectedClaimable);
    $recoveredState = $state->fresh();
    expect($archived)->toBeTrue()
        ->and($recoveredState->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($recoveredState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($recoveredState->inactive_retirement_dispatch_reserved_until_at)->toBeNull()
        ->and($recoveredState->destination_fence_operation_id)->toBe($replacementState->operationId)
        ->and(implode("\n", $payloads))->not->toContain(
            'sh "$committed_container_mutation_decoded"',
            'sh "$committed_container_completion_decoded"',
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        );
    if ($expectedClaimable) {
        expect($strictManagedRouteRead)->toBeTrue()
            ->and($response->json('cancelled'))->toBeTrue()
            ->and($recoveredState->intervention_phase)->toBeNull()
            ->and($recoveredState->intervention_reason)->toBeNull();

        return;
    }

    expect($response->json('cancelled'))->toBeTrue()
        ->and($response->json('recovery_owner_active'))->toBeFalse()
        ->and($deployment->fresh()->status)->not->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($recoveredState->intervention_phase)->toBe(BlueGreenDeploymentPhase::ROLLING_BACK->value)
        ->and($recoveredState->intervention_reason)->toBeNull();
})->with([
    'exact diagnostic pair is cleaned after journal recovery' => [
        'A committed retirement and completed rollback left stale diagnostics behind.',
        'clean',
        true,
    ],
    'partial diagnostic pair remains fail closed after journal recovery' => [
        null,
        'manual_only',
        false,
    ],
]);

it('reports pending expected-sidecar recovery clean only after proving the remote journal is absent', function (
    bool $journalReappearsAfterArchive,
): void {
    ['application' => $application, 'deployment' => $deployment, 'owner' => $owner, 'state' => $state] = makeEmergencyCommittedIdleRetirementScenario(
        $this->environment,
        $this->server,
        $this->destination,
    );
    prepareEmergencyRemoteServer(
        $application->destination->server,
        PrivateKey::query()->findOrFail($application->private_key_id),
    );
    $state->update(['inactive_retirement_intervention_required_at' => null]);
    $state = $state->fresh();
    expect($state->inactive_retirement_intervention_required_at)->toBeNull();
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The pending emergency recovery fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    InspectBlueGreenContainer::shouldRun()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $state->inactive_retirement_container_id,
            status: ContainerStatusTypes::EXITED->value,
            health: 'healthy',
        ));
    $payloads = [];
    $archiveRequested = false;
    $journalPresent = true;
    $absenceProven = false;
    $journalScriptsReplayed = false;
    fakeEmergencyPendingExpectedSidecarJournalRemote(
        $payloads,
        $archiveRequested,
        $journalPresent,
        $absenceProven,
        $journalScriptsReplayed,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
        $journalReappearsAfterArchive,
    );

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover")
        ->assertOk()
        ->assertJsonPath('cancelled', true);

    expect($archiveRequested)->toBeTrue()
        ->and($journalScriptsReplayed)->toBeFalse()
        ->and(implode("\n", $payloads))->not->toContain(
            'sh "$container_journal_mutation_decoded"',
            'sh "$container_journal_completion_decoded"',
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        );
    if ($journalReappearsAfterArchive) {
        expect($response->json('outcome'))->not->toBe('clean')
            ->and($response->json('claimable'))->toBeFalse()
            ->and($absenceProven)->toBeFalse()
            ->and($journalPresent)->toBeTrue()
            ->and($state->fresh()->inactive_retirement_intervention_required_at)->not->toBeNull();

        return;
    }

    $response->assertJsonPath('outcome', 'clean')
        ->assertJsonPath('claimable', true);
    expect($absenceProven)->toBeTrue()
        ->and($journalPresent)->toBeFalse()
        ->and($state->fresh()->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($state->fresh()->inactive_retirement_stopped_at)->not->toBeNull();
})->with([
    'journal is durably absent' => false,
    'journal reappears after archive acknowledgement' => true,
]);

it('keeps an ordinary delayed retirement untouched when emergency recovery proves no journal exists', function (): void {
    ['application' => $application, 'deployment' => $deployment, 'state' => $state] = makeEmergencyCommittedIdleRetirementScenario(
        $this->environment,
        $this->server,
        $this->destination,
    );
    prepareEmergencyRemoteServer(
        $application->destination->server,
        PrivateKey::query()->findOrFail($application->private_key_id),
    );
    $state->update([
        'inactive_retirement_intervention_required_at' => null,
        'inactive_retirement_not_before_at' => now()->addMinutes(10),
        'inactive_retirement_attempts' => 3,
    ]);
    $state = $state->fresh();
    $notBefore = $state->getRawOriginal('inactive_retirement_not_before_at');
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The journal-free emergency fixture requires an exact expected route state.');
    $payloads = [];
    $strictManagedRouteRead = false;
    fakeEmergencyAbsentIdleRetirementJournalRemote(
        $payloads,
        $strictManagedRouteRead,
        $expectedState,
        $state->inactive_retirement_server_boot_id,
    );
    InspectBlueGreenContainer::shouldRun()->never();

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk()
        ->assertJsonPath('outcome', 'clean')
        ->assertJsonPath('claimable', true)
        ->assertJsonPath('cancelled', true)
        ->assertJsonPath('recovery_owner_active', false);
    $state = $state->fresh();
    expect($strictManagedRouteRead)->toBeTrue()
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($state->inactive_retirement_stopped_at)->toBeNull()
        ->and($state->inactive_retirement_attempts)->toBe(3)
        ->and($state->getRawOriginal('inactive_retirement_not_before_at'))->toBe($notBefore)
        ->and($state->destination_fence_mutation_sequence)->toBe($expectedState->mutationSequence)
        ->and(implode("\n", $payloads))->not->toContain(
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
            'container_journal_stage=',
            'operation_container_state_stage=',
        );
});

it('clears a journal-free intervention by retiring the exact stopped inactive target through emergency recovery', function (): void {
    ['application' => $application, 'deployment' => $deployment, 'owner' => $owner, 'state' => $state] = makeEmergencyCommittedIdleRetirementScenario(
        $this->environment,
        $this->server,
        $this->destination,
    );
    prepareEmergencyRemoteServer(
        $application->destination->server,
        PrivateKey::query()->findOrFail($application->private_key_id),
    );
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The journal-free intervention fixture requires an exact expected route state.');
    $payloads = [];
    $strictManagedRouteRead = false;
    fakeEmergencyAbsentIdleRetirementJournalRemote(
        $payloads,
        $strictManagedRouteRead,
        $expectedState,
        $state->inactive_retirement_server_boot_id,
    );
    InspectBlueGreenContainer::shouldRun()
        ->atLeast()
        ->once()
        ->withArgs(function (Server $server, BlueGreenContainerExpectation $expectation) use ($application, $owner, $state): bool {
            return $server->is($application->destination->server)
                && $expectation->name === $application->uuid.'-blue'
                && $expectation->dockerId === $state->inactive_retirement_container_id
                && $expectation->deploymentUuid === $state->inactive_retirement_deployment_uuid
                && $expectation->color === BlueGreenDeploymentColor::BLUE
                && $expectation->routingRevision === $state->inactive_retirement_container_routing_revision
                && $owner->deployment_uuid === $state->inactive_retirement_owner_deployment_uuid;
        })
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $state->inactive_retirement_container_id,
            status: ContainerStatusTypes::EXITED->value,
            health: 'healthy',
        ));

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk()
        ->assertJsonPath('outcome', 'clean')
        ->assertJsonPath('claimable', true)
        ->assertJsonPath('cancelled', true)
        ->assertJsonPath('recovery_owner_active', false);

    $recoveredState = $state->fresh();
    expect($strictManagedRouteRead)->toBeTrue()
        ->and($deployment->fresh()->status)->not->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($recoveredState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($recoveredState->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($recoveredState->destination_fence_operation_id)->toBe($expectedState->operationId)
        ->and($recoveredState->destination_fence_mutation_sequence)->toBe($expectedState->mutationSequence)
        ->and(implode("\n", $payloads))->toContain(
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent',
        )
        ->not->toContain(
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
            'container_journal_stage=',
            'operation_container_state_stage=',
        );
});

it('keeps a stale emergency handle manual-only when its old inactive-retirement journal is not committed by that owner', function (string $journal): void {
    ['application' => $application, 'deployment' => $deployment, 'owner' => $owner, 'state' => $state] = makeEmergencyCommittedIdleRetirementScenario(
        $this->environment,
        $this->server,
        $this->destination,
    );
    prepareEmergencyRemoteServer(
        $application->destination->server,
        PrivateKey::query()->findOrFail($application->private_key_id),
    );
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The emergency recovery fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $reportedReplacementState = match ($journal) {
        'foreign' => $expectedState->withMutationOwner('foreign-emergency-retirement-operation'),
        'uncommitted' => $expectedState,
    };
    $payloads = [];
    $archived = false;
    $strictManagedRouteRead = false;
    fakeEmergencyCommittedIdleRetirementJournalRemote(
        $payloads,
        $archived,
        $strictManagedRouteRead,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
        reportedReplacementState: $reportedReplacementState,
    );

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk()
        ->assertJsonPath('outcome', 'manual_only')
        ->assertJsonPath('claimable', false)
        ->assertJsonPath('cancelled', true);

    expect($archived)->toBeFalse()
        ->and($strictManagedRouteRead)->toBeFalse()
        ->and($deployment->fresh()->status)->not->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($state->fresh()->inactive_retirement_intervention_required_at)->not->toBeNull()
        ->and($state->fresh()->inactive_retirement_stopped_at)->toBeNull()
        ->and(implode("\n", $payloads))->not->toContain(
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
            'sh "$committed_container_mutation_decoded"',
            'sh "$committed_container_completion_decoded"',
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        );
})->with([
    'foreign operation journal' => 'foreign',
    'expected snapshot remains uncommitted' => 'uncommitted',
]);

it('releases a deployment stranded after a newer operation took the destination', function () {
    // Observed live on 4.13.62: the durable state finished and moved to IDLE
    // under a different owner, leaving the older queue entry IN_PROGRESS with
    // no phase and every successor queued behind it forever. No scheduled
    // reconciler scans an IDLE state, so nothing else releases this row.
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $this->server, $this->destination);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $deployment->application_id,
        'standalone_docker_id' => $this->destination->id,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => (string) str()->uuid(),
        'supersession_generation' => 1,
    ]);

    $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover")
        ->assertOk()
        ->assertJsonPath('cancelled', true);

    expect($deployment->fresh()->status)->not->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
});

it('archives an exact committed clean idle journal through emergency recovery', function (): void {
    ['application' => $application, 'deployment' => $deployment, 'owner' => $owner, 'state' => $state] = makeEmergencyCommittedIdleRetirementScenario(
        $this->environment,
        $this->server,
        $this->destination,
    );
    $bootId = '11111111-2222-3333-4444-555555555555';
    $owner->update([
        'blue_green_server_boot_id' => $bootId,
        'finished_at' => now()->subMinute(),
    ]);
    $state->update([
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ...ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
        'legacy_container_name' => null,
        'intervention_phase' => null,
        'intervention_reason' => null,
    ]);
    $state = $state->fresh();
    prepareEmergencyRemoteServer(
        $application->destination->server,
        PrivateKey::query()->findOrFail($application->private_key_id),
    );
    $replacementState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The clean IDLE emergency fixture requires an exact route state.');
    $expectedState = new BlueGreenProxyState(
        managedFilename: $replacementState->managedFilename,
        applicationUuid: $replacementState->applicationUuid,
        destinationId: $replacementState->destinationId,
        operationId: $replacementState->operationId,
        mutationSequence: $replacementState->mutationSequence - 1,
        destinationFenceEpoch: $replacementState->destinationFenceEpoch,
        routingRevision: $replacementState->routingRevision,
        managedSha256: $replacementState->managedSha256,
        activeColor: $replacementState->activeColor,
        activeDeploymentUuid: $replacementState->activeDeploymentUuid,
        activeContainerName: $replacementState->activeContainerName,
        activeContainerId: $replacementState->activeContainerId,
        applicationRoutingConfigDigest: $replacementState->applicationRoutingConfigDigest,
        destinationTopologyDigest: $replacementState->destinationTopologyDigest,
        activeContainerSet: $replacementState->activeContainerSet,
    );
    $payloads = [];
    $archived = false;
    $strictManagedRouteRead = false;
    fakeEmergencyCommittedIdleRetirementJournalRemote(
        $payloads,
        $archived,
        $strictManagedRouteRead,
        $expectedState,
        $replacementState,
        $bootId,
    );
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $replacementState->activeContainerId,
            status: 'running',
            health: 'healthy',
        ));

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk()
        ->assertJsonPath('outcome', 'clean')
        ->assertJsonPath('claimable', true)
        ->assertJsonPath('cancelled', true)
        ->assertJsonPath('recovery_owner_active', false);
    expect($archived)->toBeTrue()
        ->and($strictManagedRouteRead)->toBeTrue()
        ->and($deployment->fresh()->status)->not->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and(implode("\n", $payloads))->not->toContain(
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        );
});

it('clears stale rolling-back intervention diagnostics from an otherwise claimable idle destination', function (): void {
    ['application' => $application, 'deployment' => $deployment, 'state' => $state] = makeEmergencyCommittedIdleRetirementScenario(
        $this->environment,
        $this->server,
        $this->destination,
    );
    $state->update([
        'inactive_retirement_stopped_at' => now(),
        'inactive_retirement_intervention_required_at' => null,
        'inactive_retirement_dispatch_reserved_until_at' => null,
        'intervention_phase' => BlueGreenDeploymentPhase::ROLLING_BACK->value,
        'intervention_reason' => 'A completed rollback left stale diagnostic metadata behind.',
    ]);
    $state = $state->fresh();
    prepareEmergencyRemoteServer(
        $application->destination->server,
        PrivateKey::query()->findOrFail($application->private_key_id),
    );
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The rolling-back diagnostic fixture requires an exact expected route state.');
    $payloads = [];
    $strictManagedRouteRead = false;
    fakeEmergencyAbsentIdleRetirementJournalRemote(
        $payloads,
        $strictManagedRouteRead,
        $expectedState,
        $state->inactive_retirement_server_boot_id,
    );

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk()
        ->assertJsonPath('outcome', 'clean')
        ->assertJsonPath('claimable', true)
        ->assertJsonPath('cancelled', true)
        ->assertJsonPath('recovery_owner_active', false);

    $recoveredState = $state->fresh();
    expect($strictManagedRouteRead)->toBeTrue()
        ->and($deployment->fresh()->status)->not->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($recoveredState->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($recoveredState->pending_deployment_uuid)->toBeNull()
        ->and($recoveredState->deactivation_operation_id)->toBeNull()
        ->and($recoveredState->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($recoveredState->intervention_phase)->toBeNull()
        ->and($recoveredState->intervention_reason)->toBeNull()
        ->and($recoveredState->destination_fence_operation_id)->toBe($expectedState->operationId)
        ->and($recoveredState->destination_fence_mutation_sequence)->toBe($expectedState->mutationSequence)
        ->and(implode("\n", $payloads))->not->toContain(
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
            'container_journal_stage=',
            'operation_container_state_stage=',
        );
});

it('never reports clean while the destination is still fenced for the next push', function () {
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $this->server, $this->destination);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $deployment->application_id,
        'standalone_docker_id' => $this->destination->id,
        'phase' => BlueGreenDeploymentPhase::DEACTIVATING,
        'operation_deployment_uuid' => $deployment->deployment_uuid,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'supersession_generation' => 1,
    ]);

    $response = $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    $response->assertOk();

    // A DEACTIVATING state is skipped by the reconciler, which would otherwise
    // read as "no work left"; it is not claimable, so it must not read clean.
    if ($response->json('claimable') === false) {
        expect($response->json('outcome'))->not->toBe('clean');
    }
});

it('authorizes deployment cancellation through the application owner when deployment and build servers belong to other teams', function (): void {
    $deploymentServerTeam = Team::factory()->create();
    $deploymentServer = Server::factory()->create(['team_id' => $deploymentServerTeam->id]);
    $destination = StandaloneDocker::query()->where('server_id', $deploymentServer->id)->firstOrFail();
    $buildServerTeam = Team::factory()->create();
    $buildServer = Server::factory()->create(['team_id' => $buildServerTeam->id]);
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $deploymentServer, $destination);
    prepareEmergencyRemoteServer(
        $buildServer,
        PrivateKey::query()->findOrFail($deployment->application->private_key_id),
    );
    $deployment->update(['build_server_id' => $buildServer->id]);

    $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel")
        ->assertOk()
        ->assertJsonPath('status', ApplicationDeploymentStatus::CANCELLED_BY_USER->value);

    expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value);
    Process::assertRan(function (PendingProcess $process) use ($buildServer, $deployment): bool {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;

        return str_contains($command, $buildServer->ip)
            && str_contains($command, "docker ps -a --filter name={$deployment->deployment_uuid}");
    });
});

it('does not authorize deployment cancellation from ownership of only the deployment server', function (): void {
    $applicationTeam = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $applicationTeam->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $serverTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $serverTeam->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $deployment = makeEmergencyRecoveryDeployment($environment, $server, $destination);
    $serverOwner = User::factory()->create();
    $serverTeam->members()->attach($serverOwner->id, ['role' => 'owner']);
    session(['currentTeam' => $serverTeam]);
    $serverOwnerToken = $serverOwner->createToken('cancel-server-owner', ['deploy'])->plainTextToken;

    $this->withHeaders(emergencyRecoveryHeaders($serverOwnerToken))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel")
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have permission to cancel this deployment.');

    expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Process::assertNothingRan();
});

it("does not let a user use a token minted for one team to cancel another team's deployment", function (): void {
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $this->server, $this->destination);
    $tokenTeam = Team::factory()->create();
    $tokenTeam->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $tokenTeam]);
    $otherTeamToken = $this->user->createToken('cancel-other-team-scope', ['deploy'])->plainTextToken;

    $this->withHeaders(emergencyRecoveryHeaders($otherTeamToken))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel")
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have permission to cancel this deployment.');

    expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Process::assertNothingRan();
});

it('requires application deployment-management policy authorization after cancel token team scope matches', function (): void {
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $this->server, $this->destination);
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    session(['currentTeam' => $this->team]);
    $memberToken = $member->createToken('cancel-application-team-member', ['deploy'])->plainTextToken;
    $this->withoutMiddleware(ApiAbility::class);

    $this->withHeaders(emergencyRecoveryHeaders($memberToken))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/cancel")
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have permission to cancel this deployment.');

    expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Process::assertNothingRan();
});

it('authorizes emergency recovery through the application owner when its server belongs to another team', function () {
    $serverTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $serverTeam->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $server, $destination);

    $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover")
        ->assertOk()
        ->assertJsonPath('cancelled', true);

    expect($deployment->fresh()->status)->not->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
});

it('cleans the exact build server after authorized recovery when the build server belongs to another team', function () {
    $buildServerTeam = Team::factory()->create();
    $buildServer = Server::factory()->create(['team_id' => $buildServerTeam->id]);
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $this->server, $this->destination);
    prepareEmergencyRemoteServer(
        $buildServer,
        PrivateKey::query()->findOrFail($deployment->application->private_key_id),
    );
    $deployment->update(['build_server_id' => $buildServer->id]);

    $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover")
        ->assertOk()
        ->assertJsonPath('cancelled', true);

    Process::assertRan(function (PendingProcess $process) use ($buildServer, $deployment): bool {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;

        return str_contains($command, $buildServer->ip)
            && str_contains($command, "docker ps -a --filter name={$deployment->deployment_uuid}");
    });
});

it('does not authorize emergency recovery from ownership of only the deployment server', function () {
    $applicationTeam = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $applicationTeam->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $serverTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $serverTeam->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $deployment = makeEmergencyRecoveryDeployment($environment, $server, $destination);
    $serverOwner = User::factory()->create();
    $serverTeam->members()->attach($serverOwner->id, ['role' => 'owner']);
    session(['currentTeam' => $serverTeam]);
    $serverOwnerToken = $serverOwner->createToken('server-owner', ['deploy'])->plainTextToken;

    $this->withHeaders(emergencyRecoveryHeaders($serverOwnerToken))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover")
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have permission to recover this deployment.');

    expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Process::assertNothingRan();
});

it("does not let a user use a token minted for one team to recover another team's deployment", function () {
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $this->server, $this->destination);
    $tokenTeam = Team::factory()->create();
    $tokenTeam->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $tokenTeam]);
    $otherTeamToken = $this->user->createToken('other-team-scope', ['deploy'])->plainTextToken;

    $this->withHeaders(emergencyRecoveryHeaders($otherTeamToken))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover")
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have permission to recover this deployment.');

    expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Process::assertNothingRan();
});

it('requires application deploy policy authorization after token team scope matches', function () {
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $this->server, $this->destination);
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    session(['currentTeam' => $this->team]);
    $memberToken = $member->createToken('application-team-member', ['deploy'])->plainTextToken;
    $this->withoutMiddleware(ApiAbility::class);

    $this->withHeaders(emergencyRecoveryHeaders($memberToken))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover")
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have permission to recover this deployment.');

    expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Process::assertNothingRan();
});

it('refuses emergency recovery for a deployment owned by another team', function () {
    $deployment = makeEmergencyRecoveryDeployment($this->environment, $this->server, $this->destination);
    $otherUser = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);
    $otherToken = $otherUser->createToken('other-team', ['deploy'])->plainTextToken;

    $response = $this->withHeaders(emergencyRecoveryHeaders($otherToken))
        ->postJson("/api/v1/deployments/{$deployment->deployment_uuid}/recover");

    expect($response->status())->toBeIn([401, 403])
        ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
});

it('returns 404 for an unknown deployment uuid', function () {
    $this->withHeaders(emergencyRecoveryHeaders($this->token))
        ->postJson('/api/v1/deployments/does-not-exist/recover')
        ->assertStatus(404);
});
