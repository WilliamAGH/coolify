<?php

use App\Actions\Application\BlueGreen\BlueGreenInterventionRecoveryResult;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
use App\Actions\Application\BlueGreen\ResolveBlueGreenExpectedProxyState;
use App\Actions\Application\EmergencyRecoverApplicationDeployment;
use App\Actions\Proxy\ResolveCanonicalApplicationRoutingLabels;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Exceptions\DeploymentException;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
    Notification::fake();
});

/**
 * The durable residue a cleanly completed first-adoption rollback leaves when
 * the candidate started healthy and activation refused: the destination fence
 * committed (operation id, mutation sequence 2, both digests), the replica row
 * stayed bound to the removed candidate, and the failed queue row retained its
 * candidate container id. GitHub issue #198.
 */
function firstAdoptionGuardRolledBackScenario(): BlueGreenRecoveryScenario
{
    $scenario = BlueGreenRecoveryScenario::create(
        finalized: false,
        routingMutationRecorded: false,
        applicationAttributes: [
            'health_check_enabled' => true,
            'ports_mappings' => null,
        ],
    );
    $scenario->application->settings()->update([
        'is_blue_green_deployment_enabled' => true,
        'is_container_label_readonly_enabled' => true,
    ]);
    $scenario->application->refresh()->load('settings');
    $scenario->server->settings()->update([
        'concurrent_builds' => 10,
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    $scenario->server->refresh();
    $topologyDigest = (string) $scenario->state->operation_topology_digest;
    $routingConfigDigest = (string) $scenario->state->operation_routing_config_digest;
    $candidateContainerName = $scenario->application->uuid.'-blue';
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'finished_at' => now(),
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_candidate_container_id' => BlueGreenRecoveryScenario::CANDIDATE_ID,
        'blue_green_routing_mutated_at' => null,
    ]);
    $scenario->state->update(array_merge(
        [
            'active_color' => null,
            'pending_color' => null,
            'blue_deployment_uuid' => null,
            'green_deployment_uuid' => null,
            'pending_deployment_uuid' => null,
            'legacy_container_name' => $scenario->application->uuid.'-legacy',
            'deactivation_operation_id' => null,
            'deactivation_started_at' => null,
            'destination_fence_epoch' => 0,
            'destination_fence_operation_id' => BlueGreenRecoveryScenario::OPERATION_UUID,
            'destination_fence_mutation_sequence' => 2,
            'managed_file_sha256' => null,
            'destination_topology_digest' => $topologyDigest,
            'application_routing_config_digest' => $routingConfigDigest,
            'intervention_phase' => null,
            'intervention_reason' => null,
            'supersession_generation' => 1,
            'phase' => BlueGreenDeploymentPhase::IDLE,
            'routing_revision' => 0,
        ],
        ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
    ));
    ApplicationBlueGreenReplica::query()->create([
        'application_blue_green_deployment_id' => $scenario->state->id,
        'application_id' => $scenario->application->id,
        'standalone_docker_id' => $scenario->destination->id,
        'color' => BlueGreenDeploymentColor::BLUE,
        'replica_index' => 1,
        'deployment_uuid' => $scenario->deployment->deployment_uuid,
        'routing_revision' => 1,
        'compose_project' => $scenario->application->uuid,
        'compose_service' => $candidateContainerName,
        'container_name' => $candidateContainerName,
        'container_id' => BlueGreenRecoveryScenario::CANDIDATE_ID,
        'health_status' => 'healthy',
        'last_observed_at' => now(),
    ]);
    $scenario->state->refresh();

    return $scenario;
}

/**
 * The fence-cleared residue of a first adoption that failed before its
 * destination mutation ever committed — the shape the stale-journal recovery
 * profile was written for, except no journal exists on the host.
 */
function firstAdoptionGuardUncommittedScenario(): BlueGreenRecoveryScenario
{
    $scenario = firstAdoptionGuardRolledBackScenario();
    $scenario->deployment->update([
        'blue_green_candidate_container_id' => null,
    ]);
    $scenario->state->update([
        'destination_fence_operation_id' => null,
        'destination_fence_mutation_sequence' => 0,
        'destination_topology_digest' => null,
        'application_routing_config_digest' => null,
    ]);
    ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $scenario->state->id)
        ->update([
            'container_id' => null,
            'health_status' => 'pending',
            'last_observed_at' => null,
        ]);
    $scenario->state->refresh();

    return $scenario;
}

function firstAdoptionGuardSuccessor(BlueGreenRecoveryScenario $scenario): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::query()->create([
        'application_id' => $scenario->application->id,
        'application_name' => $scenario->application->name,
        'server_id' => $scenario->server->id,
        'server_name' => $scenario->server->name,
        'destination_id' => $scenario->destination->id,
        'deployment_uuid' => 'first-adoption-guard-successor',
        'pull_request_id' => 0,
        'commit' => 'first-adoption-guard-successor-commit',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'horizon_job_id' => (string) Str::uuid(),
        'only_this_server' => true,
    ]);
}

/**
 * A `docker inspect {{json .}}` payload for the running legacy container whose
 * Traefik labels byte-match the canonical routing inventory, unless overridden.
 *
 * @param  array<string, string>  $labelOverrides
 */
function firstAdoptionGuardLegacyInspection(BlueGreenRecoveryScenario $scenario, array $labelOverrides = []): string
{
    $labels = [];
    foreach (ResolveCanonicalApplicationRoutingLabels::run($scenario->application->fresh(['settings']), $scenario->destination) as $label) {
        [$key, $value] = explode('=', (string) $label, 2);
        $labels[$key] = $value;
    }
    $labels = array_merge($labels, [
        'coolify.applicationId' => (string) $scenario->application->id,
        'coolify.pullRequestId' => '0',
    ], $labelOverrides);

    return json_encode([
        'Id' => BlueGreenRecoveryScenario::LEGACY_ID,
        'Name' => '/'.$scenario->application->uuid.'-legacy',
        'Config' => ['Labels' => $labels],
        'State' => [
            'Status' => 'running',
            'Health' => ['Status' => 'healthy'],
        ],
        'NetworkSettings' => [
            'Networks' => [
                (string) $scenario->destination->network => ['IPAddress' => '10.0.0.2'],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

/**
 * The highest-sequence destination-fence blob embedded in a remote payload, so
 * the fake can mirror what the destination would attest or persist.
 */
function firstAdoptionGuardHighestFenceBlob(string $payload): ?string
{
    preg_match_all('#[A-Za-z0-9+/]{24,}={0,2}#', $payload, $matches);
    $best = null;
    $bestSequence = -1;
    foreach (array_unique($matches[0]) as $candidate) {
        $decoded = base64_decode($candidate, true);
        if ($decoded === false) {
            continue;
        }
        $record = json_decode($decoded, true);
        if (! is_array($record) || ($record['magic'] ?? null) !== 'coolify-blue-green-destination-fence-v2') {
            continue;
        }
        $sequence = (int) ($record['mutation_sequence'] ?? 0);
        if ($sequence > $bestSequence) {
            $bestSequence = $sequence;
            $best = $candidate;
        }
    }

    return $best;
}

/** @param list<string> $payloads */
function firstAdoptionGuardRemoteFake(
    BlueGreenRecoveryScenario $scenario,
    array &$payloads,
    string $legacyInspection,
    ?string $remoteFenceState = null,
): void {
    $runtime = json_encode([
        'Id' => BlueGreenRecoveryScenario::LEGACY_ID,
        'Name' => '/'.$scenario->application->uuid.'-legacy',
        'State' => [
            'Status' => 'running',
            'Health' => ['Status' => 'healthy'],
        ],
        'Config' => [
            'Labels' => [
                'coolify.applicationId' => (string) $scenario->application->id,
                'coolify.pullRequestId' => '0',
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $payloads = [];
    Process::fake(function (PendingProcess $process) use (&$payloads, &$remoteFenceState, $runtime, $legacyInspection, $scenario): FakeProcessResult {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $payload = $command."\n".(string) $process->input;
        $payloads[] = $payload;
        if (str_contains($payload, 'coolify-blue-green-stale-first-adoption-runtime:v1')) {
            return Process::result(output: "coolify-blue-green-stale-first-adoption-runtime:v1\n{$runtime}");
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX)) {
            // No container-mutation journal exists on the host.
            return Process::result(output: '', exitCode: 1);
        }
        if (str_contains($payload, 'coolify-blue-green-destination-state-attested')) {
            return firstAdoptionGuardHighestFenceBlob($payload) === $remoteFenceState
                ? Process::result(output: 'coolify-blue-green-destination-state-attested')
                : Process::result(output: '', exitCode: 1);
        }
        if (str_contains($payload, 'printf occupied; else printf missing')) {
            return Process::result(output: 'missing');
        }
        $mutationBlob = firstAdoptionGuardHighestFenceBlob($payload);
        if ($mutationBlob !== null) {
            $remoteFenceState = $mutationBlob;

            return Process::result(output: '');
        }
        if (str_contains($payload, "docker ps -a --filter='label=coolify.applicationId=")) {
            return Process::result(output: json_encode([
                'Names' => $scenario->application->uuid.'-legacy',
                'State' => 'running',
                'Labels' => 'coolify.applicationId='.$scenario->application->id.',coolify.pullRequestId=0',
            ], JSON_THROW_ON_ERROR));
        }
        if (str_contains($payload, 'if docker container inspect')) {
            return str_contains($payload, $scenario->application->uuid.'-legacy')
                || str_contains($payload, BlueGreenRecoveryScenario::LEGACY_ID)
                    ? Process::result(output: $legacyInspection)
                    : Process::result(output: 'coolify-blue-green-container:missing');
        }
        if (str_contains($payload, 'docker inspect --format=')) {
            return Process::result(output: $legacyInspection);
        }
        if (str_contains($payload, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: '11111111-2222-3333-4444-555555555555');
        }

        return Process::result(output: '[]');
    });
}

/**
 * The serialized destination-fence state the completed rollback left on disk,
 * exactly as the durable row expects to attest it.
 */
function firstAdoptionGuardRemoteFenceState(BlueGreenRecoveryScenario $scenario): string
{
    $expected = ResolveBlueGreenExpectedProxyState::run(
        $scenario->application->fresh(['settings']),
        $scenario->destination,
        $scenario->state->fresh(),
    );

    return base64_encode((string) $expected?->serialize());
}

function firstAdoptionGuardLifecycle(
    BlueGreenRecoveryScenario $scenario,
    ApplicationDeploymentQueue $deployment,
): BlueGreenDeploymentLifecycle {
    return new BlueGreenDeploymentLifecycle(
        application: $scenario->application->fresh(['settings']),
        deployment: $deployment->fresh(),
        destination: $scenario->destination->fresh(),
        server: $scenario->server->fresh(),
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
}

it('re-claims a cleanly rolled-back first adoption without routing into stale-journal recovery', function (): void {
    $scenario = firstAdoptionGuardRolledBackScenario();
    $successor = firstAdoptionGuardSuccessor($scenario);
    $payloads = [];
    firstAdoptionGuardRemoteFake(
        $scenario,
        $payloads,
        firstAdoptionGuardLegacyInspection($scenario),
        firstAdoptionGuardRemoteFenceState($scenario),
    );
    $lifecycle = firstAdoptionGuardLifecycle($scenario, $successor);

    try {
        $lifecycle->initialize();
        $claim = $lifecycle->claim();

        expect($claim)->not->toBeNull()
            ->and($claim->pendingColor)->toBe(BlueGreenDeploymentColor::BLUE)
            ->and($claim->expectedRoutingRevision)->toBe(1)
            ->and($claim->previousActiveColor)->toBeNull()
            ->and($claim->legacyContainerName)->toBe($scenario->application->uuid.'-legacy');
    } finally {
        $lifecycle->release();
    }

    expect((string) $successor->fresh()?->logs)->not->toContain('Failed first-adoption stale-journal recovery')
        ->and(collect($payloads)->filter(
            static fn (string $payload): bool => str_contains($payload, 'coolify-blue-green-destination-state-attested'),
        )->count())->toBeGreaterThanOrEqual(2)
        ->and(implode("\n", $payloads))->not->toContain(
            WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX,
        );
});

it('reports a cleanly rolled-back first adoption claimable through emergency recovery', function (): void {
    $scenario = firstAdoptionGuardRolledBackScenario();
    $payloads = [];
    firstAdoptionGuardRemoteFake(
        $scenario,
        $payloads,
        firstAdoptionGuardLegacyInspection($scenario),
        firstAdoptionGuardRemoteFenceState($scenario),
    );

    $result = EmergencyRecoverApplicationDeployment::run(
        $scenario->deployment->fresh(),
        'Emergency recovery of a cleanly rolled-back first adoption.',
    );

    expect($result['outcome'])->toBe('clean')
        ->and($result['claimable'])->toBeTrue()
        ->and(implode("\n", $payloads))->not->toContain(
            WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX,
        );
});

it('continues a successor deploy when the stale first-adoption journal is not archivable', function (): void {
    $scenario = firstAdoptionGuardUncommittedScenario();
    $successor = firstAdoptionGuardSuccessor($scenario);
    $payloads = [];
    firstAdoptionGuardRemoteFake($scenario, $payloads, firstAdoptionGuardLegacyInspection($scenario));
    $lifecycle = firstAdoptionGuardLifecycle($scenario, $successor);

    try {
        $lifecycle->initialize();
        $claim = $lifecycle->claim();

        expect($claim)->not->toBeNull()
            ->and($claim->pendingColor)->toBe(BlueGreenDeploymentColor::BLUE);
    } finally {
        $lifecycle->release();
    }

    expect((string) $successor->fresh()?->logs)
        ->toContain('was not archivable for this successor')
        ->and(collect($payloads)->filter(
            static fn (string $payload): bool => str_contains($payload, 'coolify-blue-green-destination-state-attested'),
        )->count())->toBeGreaterThanOrEqual(2);
});

it('refuses first blue-green adoption before any destination mutation when legacy routing labels drift', function (): void {
    $scenario = firstAdoptionGuardRolledBackScenario();
    // A pristine destination: the durable residue is removed so this deploy is
    // the very first adoption attempt against a drifted legacy container.
    ApplicationBlueGreenReplica::query()->delete();
    $scenario->state->delete();
    $scenario->deployment->delete();
    $successor = firstAdoptionGuardSuccessor($scenario);
    $payloads = [];
    firstAdoptionGuardRemoteFake($scenario, $payloads, firstAdoptionGuardLegacyInspection($scenario, [
        'traefik.http.routers.removed-domain.rule' => 'Host(`removed.example.test`)',
    ]));
    $lifecycle = firstAdoptionGuardLifecycle($scenario, $successor);

    $failure = null;
    try {
        $lifecycle->initialize();
        $lifecycle->claim();
        $lifecycle->promote(
            static function (): void {},
            static fn (): array => ['blue-green-start-candidate-marker'],
        );
    } catch (Throwable $exception) {
        $failure = $exception;
    }
    try {
        expect($failure)->not->toBeNull();
        $lifecycle->rollback($failure);
    } finally {
        $lifecycle->release();
    }

    expect($failure)->toBeInstanceOf(DeploymentException::class)
        ->and($failure->getMessage())->toContain('do not exactly match')
        ->and(implode("\n", $payloads))->not->toContain('coolify-blue-green-container-mutation-v1')
        ->and(implode("\n", $payloads))->not->toContain('blue-green-start-candidate-marker');

    $state = ApplicationBlueGreenDeployment::query()
        ->where('application_id', $scenario->application->id)
        ->where('standalone_docker_id', $scenario->destination->id)
        ->sole();
    expect($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->pending_color)->toBeNull()
        ->and($state->routing_revision)->toBe(0)
        ->and(ResolveBlueGreenExpectedProxyState::hasDurableDestinationState($state))->toBeFalse();
});

it('names the stable refusal reason code publicly and keeps the precondition detail in the internal log', function (): void {
    $appLogPath = storage_path('logs/first-adoption-guard-app-'.Str::uuid().'.log');
    config([
        'logging.default' => 'single',
        'logging.channels.single.path' => $appLogPath,
    ]);
    Log::forgetChannel('single');
    $scenario = firstAdoptionGuardRolledBackScenario();
    $successor = firstAdoptionGuardSuccessor($scenario);
    Process::fake();

    try {
        $result = RecoverBlueGreenIntervention::run(
            stateId: (int) $scenario->state->getKey(),
            apply: false,
            reason: 'Inspect the rolled-back first adoption residue.',
            staleContainerJournal: true,
            successorQueueId: (int) $successor->getKey(),
            successorDeploymentUuid: (string) $successor->deployment_uuid,
            successorHorizonJobId: (string) $successor->getRawOriginal('horizon_job_id'),
        );

        expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
            ->and($result->reasonCode)->toBe('state_changed')
            ->and($result->correlationId)->toBeUuid()
            ->and($result->message)->not->toContain('not an unrouted idle blue-green state');
        $appLog = File::get($appLogPath);
        expect($appLog)->toContain('not an unrouted idle blue-green state')
            ->and($appLog)->toContain($result->correlationId);
        Process::assertNothingRan();
    } finally {
        File::delete($appLogPath);
    }
});
