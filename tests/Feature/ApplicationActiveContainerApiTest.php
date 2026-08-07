<?php

use App\Actions\Application\BlueGreen\ActiveApplicationContainerState;
use App\Actions\Application\BlueGreen\BlueGreenManagedRouteMetadataForOperationResult;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\RepairBlueGreenSteadyStates;
use App\Actions\Application\BlueGreen\ResolveActiveApplicationContainerState;
use App\Actions\Application\BlueGreen\ResolveBlueGreenExpectedProxyState;
use App\Actions\Application\BlueGreen\ResolveOrdinaryApplicationDeploymentUuids;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

function ordinaryApplicationContainer(
    int $applicationId,
    string $containerId,
    string $deploymentUuid,
    string $image,
    string $health = 'healthy',
    ?string $imageId = null,
): array {
    return [
        'Id' => $containerId,
        'Image' => $imageId ?? 'sha256:'.hash('sha256', $image),
        'Config' => [
            'Image' => $image,
            'Labels' => [
                'coolify.applicationId' => (string) $applicationId,
                'coolify.pullRequestId' => '0',
                'coolify.deploymentId' => $deploymentUuid,
            ],
        ],
        'State' => ['Status' => 'running', 'Health' => ['Status' => $health]],
    ];
}

function activeContainerApiJournalStateWithOperation(
    BlueGreenProxyState $state,
    string $operationId,
): BlueGreenProxyState {
    return new BlueGreenProxyState(
        managedFilename: $state->managedFilename,
        applicationUuid: $state->applicationUuid,
        destinationId: $state->destinationId,
        operationId: $operationId,
        mutationSequence: 1,
        destinationFenceEpoch: $state->destinationFenceEpoch,
        routingRevision: $state->routingRevision,
        managedSha256: $state->managedSha256,
        activeColor: $state->activeColor,
        activeDeploymentUuid: $state->activeDeploymentUuid,
        activeContainerName: $state->activeContainerName,
        activeContainerId: $state->activeContainerId,
        applicationRoutingConfigDigest: $state->applicationRoutingConfigDigest,
        destinationTopologyDigest: $state->destinationTopologyDigest,
        activeContainerSet: $state->activeContainerSet,
    );
}

/** @return array{scenario: BlueGreenRecoveryScenario, expected_state: BlueGreenProxyState, token: string} */
function activeContainerApiCleanIdleScenario(User $user): array
{
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ...ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
        'legacy_container_name' => null,
        'phase' => BlueGreenDeploymentPhase::IDLE,
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
    ]);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
    ) ?? throw new RuntimeException('The active-container API fixture requires an exact managed route.');
    $team = $scenario->application->team();
    if (! $team instanceof Team) {
        throw new RuntimeException('The active-container API fixture requires one application team.');
    }
    $team->members()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);
    session(['currentTeam' => $team]);

    return [
        'scenario' => $scenario,
        'expected_state' => $expectedState,
        'token' => $user->createToken('active-container-journal-api-test', ['read'])->plainTextToken,
    ];
}

/**
 * @param  list<string>  $payloads
 */
function fakeActiveContainerApiJournalRemote(
    BlueGreenRecoveryScenario $scenario,
    BlueGreenProxyState $expectedState,
    string $journalStatus,
    bool &$journalPresent,
    array &$payloads,
    ?string $strictReadFault = null,
    ?Closure $bootMismatchAt = null,
): void {
    $journalExpectedState = activeContainerApiJournalStateWithOperation(
        $expectedState,
        'active-container-api-predecessor',
    );
    $journalSha256 = hash('sha256', 'active-container-api-'.$journalStatus);
    $journalBootId = (string) $scenario->deployment->blue_green_server_boot_id;
    $imageReference = 'registry.example/active-container:exact';
    $containerOutput = json_encode([
        'Id' => $expectedState->activeContainerId,
        'Image' => 'sha256:'.hash('sha256', $imageReference),
        'Config' => [
            'Image' => $imageReference,
            'Labels' => [
                'coolify.applicationId' => (string) $scenario->application->id,
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => $expectedState->activeDeploymentUuid,
                'coolify.blueGreen.color' => $expectedState->activeColor?->value,
                'coolify.blueGreen.routingRevision' => (string) $expectedState->routingRevision,
            ],
        ],
        'State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']],
    ], JSON_THROW_ON_ERROR);

    Process::fake(function (PendingProcess $process) use (
        $containerOutput,
        $expectedState,
        $journalBootId,
        $journalExpectedState,
        &$journalPresent,
        $journalSha256,
        $journalStatus,
        &$payloads,
        $strictReadFault,
        $bootMismatchAt,
    ) {
        $payload = (string) $process->command."\n".(string) $process->input;
        $payloads[] = $payload;
        if ($bootMismatchAt !== null && $bootMismatchAt($payload)) {
            if (! str_contains(
                $payload,
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_BOOT_IDENTITY_MISMATCH_OUTPUT,
            )) {
                return Process::result(
                    errorOutput: 'Expected-current-boot inspection did not emit its typed mismatch marker.',
                    exitCode: 1,
                );
            }

            return Process::result(output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_BOOT_IDENTITY_MISMATCH_OUTPUT);
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            if ($strictReadFault !== null) {
                return Process::result(errorOutput: $strictReadFault, exitCode: 70);
            }
            if ($journalPresent) {
                return Process::result(output: WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT);
            }

            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($expectedState->serialize())."\n".$expectedState->managedSha256);
        }
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: $journalBootId);
        }
        if (str_contains($payload, 'committed_container_manifest_stage=')) {
            $journalPresent = false;

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::COMMITTED_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX,
                'archived',
                $journalSha256,
                (new WriteBlueGreenProxyConfiguration)->committedContainerMutationJournalArchiveFilename(
                    $expectedState->managedFilename,
                    $journalSha256,
                ),
            ]));
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                $journalStatus,
                $journalSha256,
                $journalBootId,
                BlueGreenProxyRollbackArtifact::PRESENT_STATE,
                $expectedState->managedSha256,
                hash('sha256', 'active-container-api-mutation-script'),
                hash('sha256', 'active-container-api-completion-script'),
            ])."\n".base64_encode($journalExpectedState->serialize())."\n".base64_encode($expectedState->serialize()));
        }
        if (str_contains($payload, 'docker container ls -aq --no-trunc')) {
            return Process::result(output: $containerOutput);
        }

        return Process::result(errorOutput: 'Unexpected active-container journal API command.', exitCode: 1);
    });
}

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $this->user = $user;
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $this->token = $user->createToken('active-container-api-test', ['read'])->plainTextToken;
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'registry.example/app',
        'docker_registry_image_tag' => '815c045',
        'status' => 'running:healthy',
    ]);
});

it('fails closed before remote inspection when persisted server proxy JSON is corrupt', function (): void {
    [
        'scenario' => $scenario,
        'expected_state' => $expectedState,
        'token' => $token,
    ] = activeContainerApiCleanIdleScenario($this->user);
    DB::table('servers')
        ->where('id', $scenario->server->id)
        ->update(['proxy' => '[]']);
    Server::flushIdentityMap();
    $journalPresent = false;
    $payloads = [];
    fakeActiveContainerApiJournalRemote(
        $scenario,
        $expectedState,
        BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
        $journalPresent,
        $payloads,
    );

    $this->withToken($token)
        ->getJson("/api/v1/applications/{$scenario->application->uuid}/active-container")
        ->assertInternalServerError()
        ->assertJsonMissingExact([
            'message' => 'Active application container state is not observable.',
        ]);

    Process::assertNothingRan();
});

it('returns the active container runtime image ID and configured image reference', function () {
    $resolver = Mockery::mock(new ResolveActiveApplicationContainerState)->makePartial();
    $resolver->shouldReceive('handle')
        ->once()
        ->andReturn(new ActiveApplicationContainerState(
            image: 'sha256:'.hash('sha256', 'registry.example/app:ccb9a3b'),
            imageReference: 'registry.example/app:ccb9a3b',
            status: 'running:unhealthy',
            destination: [[
                'destination_id' => (int) $this->application->destination_id,
                'deployment_uuid' => 'deployment-ccb9a3b',
                'color' => 'green',
                'routing_revision' => 7,
                'container_ids' => [str_repeat('a', 64)],
            ]],
        ));
    $this->app->instance(ResolveActiveApplicationContainerState::class, $resolver);

    $this->withToken($this->token)
        ->getJson("/api/v1/applications/{$this->application->uuid}/active-container")
        ->assertSuccessful()
        ->assertJsonPath('image', 'sha256:'.hash('sha256', 'registry.example/app:ccb9a3b'))
        ->assertJsonPath('image_reference', 'registry.example/app:ccb9a3b')
        ->assertJsonPath('source', 'dockerimage')
        ->assertJsonPath('status', 'running:unhealthy')
        ->assertJsonPath('provenance.kind', 'live-container-inspection')
        ->assertJsonPath('provenance.destination.0.deployment_uuid', 'deployment-ccb9a3b');
});

it('resolves an ordinary image application directly from its live container without blue-green state', function () {
    $containerId = str_repeat('b', 64);
    $image = 'registry.example/app:ordinary-live';
    $state = (new ResolveActiveApplicationContainerState)->resolveOrdinaryFromContainers(
        (int) $this->application->id,
        collect([(int) $this->application->destination_id => 'deployment-ordinary']),
        collect([
            (int) $this->application->destination_id => collect([ordinaryApplicationContainer(
                (int) $this->application->id,
                $containerId,
                'deployment-ordinary',
                $image,
            )]),
        ]),
    );

    expect($this->application->blueGreenDeployments()->exists())->toBeFalse()
        ->and($state?->image)->toBe('sha256:'.hash('sha256', $image))
        ->and($state?->imageReference)->toBe($image)
        ->and($state?->status)->toBe('running:healthy')
        ->and($state?->destination[0]['deployment_uuid'])->toBe('deployment-ordinary')
        ->and($state?->destination[0]['container_ids'])->toBe([$containerId]);
});

it('ignores a stale ordinary container with a different image', function () {
    $destinationId = (int) $this->application->destination_id;
    $state = (new ResolveActiveApplicationContainerState)->resolveOrdinaryFromContainers(
        (int) $this->application->id,
        collect([$destinationId => 'deployment-current']),
        collect([$destinationId => collect([
            ordinaryApplicationContainer(
                (int) $this->application->id,
                str_repeat('c', 64),
                'deployment-stale',
                'registry.example/app:stale',
            ),
            ordinaryApplicationContainer(
                (int) $this->application->id,
                str_repeat('d', 64),
                'deployment-current',
                'registry.example/app:current',
            ),
        ])]),
    );

    expect($state?->image)->toBe('sha256:'.hash('sha256', 'registry.example/app:current'))
        ->and($state?->destination[0]['container_ids'])->toBe([str_repeat('d', 64)]);
});

it('derives ordinary status only from the exact current deployment', function () {
    $destinationId = (int) $this->application->destination_id;
    $state = (new ResolveActiveApplicationContainerState)->resolveOrdinaryFromContainers(
        (int) $this->application->id,
        collect([$destinationId => 'deployment-current']),
        collect([$destinationId => collect([
            ordinaryApplicationContainer(
                (int) $this->application->id,
                str_repeat('e', 64),
                'deployment-stale',
                'registry.example/app:same',
                'unhealthy',
            ),
            ordinaryApplicationContainer(
                (int) $this->application->id,
                str_repeat('f', 64),
                'deployment-current',
                'registry.example/app:same',
            ),
        ])]),
    );

    expect($state?->image)->toBe('sha256:'.hash('sha256', 'registry.example/app:same'))
        ->and($state?->status)->toBe('running:healthy')
        ->and($state?->destination[0]['container_ids'])->toBe([str_repeat('f', 64)]);
});

it('fails closed when only a stale ordinary deployment is observable', function () {
    $destinationId = (int) $this->application->destination_id;

    expect((new ResolveActiveApplicationContainerState)->resolveOrdinaryFromContainers(
        (int) $this->application->id,
        collect([$destinationId => 'deployment-current']),
        collect([$destinationId => collect([ordinaryApplicationContainer(
            (int) $this->application->id,
            str_repeat('1', 64),
            'deployment-stale',
            'registry.example/app:stale',
        )])]),
    ))->toBeNull();
});

it('fails closed when an ordinary deployment has more than one matching container', function () {
    $destinationId = (int) $this->application->destination_id;
    $matchingContainers = collect([
        ordinaryApplicationContainer(
            (int) $this->application->id,
            str_repeat('3', 64),
            'deployment-duplicate',
            'registry.example/app:duplicate',
        ),
        ordinaryApplicationContainer(
            (int) $this->application->id,
            str_repeat('4', 64),
            'deployment-duplicate',
            'registry.example/app:duplicate',
        ),
    ]);

    expect((new ResolveActiveApplicationContainerState)->resolveOrdinaryFromContainers(
        (int) $this->application->id,
        collect([$destinationId => 'deployment-duplicate']),
        collect([$destinationId => $matchingContainers]),
    ))->toBeNull();
});

it('fails closed when configured and observed destination inventories differ', function () {
    $action = new ResolveActiveApplicationContainerState;

    expect($action->destinationIdsMatch(collect([11, 12]), collect([11])))->toBeFalse()
        ->and($action->destinationIdsMatch(collect([11, 12]), collect([11, 13])))->toBeFalse()
        ->and($action->destinationIdsMatch(collect([12, 11]), collect([11, 12])))->toBeTrue();
});

it('uses the latest completed ordinary deployment as the current identity fence', function () {
    $destinationId = (int) $this->application->destination_id;
    foreach (['deployment-old', 'deployment-current'] as $deploymentUuid) {
        ApplicationDeploymentQueue::query()->create([
            'application_id' => $this->application->id,
            'deployment_uuid' => $deploymentUuid,
            'destination_id' => $destinationId,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
        ]);
    }

    $resolved = ResolveOrdinaryApplicationDeploymentUuids::run(
        $this->application,
        collect([$destinationId]),
    );

    expect($resolved?->all())->toBe([$destinationId => 'deployment-current']);
});

it('fails closed while an ordinary deployment can change the current container', function () {
    $destinationId = (int) $this->application->destination_id;
    foreach ([
        'deployment-current' => ApplicationDeploymentStatus::FINISHED,
        'deployment-mutating' => ApplicationDeploymentStatus::IN_PROGRESS,
    ] as $deploymentUuid => $status) {
        ApplicationDeploymentQueue::query()->create([
            'application_id' => $this->application->id,
            'deployment_uuid' => $deploymentUuid,
            'destination_id' => $destinationId,
            'status' => $status->value,
        ]);
    }

    expect(ResolveOrdinaryApplicationDeploymentUuids::run(
        $this->application,
        collect([$destinationId]),
    ))->toBeNull();
});

it('excludes only the exact current restart when resolving the ordinary predecessor', function () {
    $destinationId = (int) $this->application->destination_id;
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deployment-current',
        'destination_id' => $destinationId,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    $restart = ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deployment-restart',
        'destination_id' => $destinationId,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'restart_only' => true,
    ]);

    expect(ResolveOrdinaryApplicationDeploymentUuids::run(
        $this->application,
        collect([$destinationId]),
    ))->toBeNull()
        ->and(ResolveOrdinaryApplicationDeploymentUuids::run(
            $this->application,
            collect([$destinationId]),
            $restart->id,
        )?->all())->toBe([$destinationId => 'deployment-current'])
        ->and((new ResolveOrdinaryApplicationDeploymentUuids)->currentRestartDeploymentUuids(
            $this->application,
            collect([$destinationId]),
            $restart->id,
        )?->all())->toBe([$destinationId => 'deployment-restart']);

    $restart->update(['restart_only' => false]);

    expect(ResolveOrdinaryApplicationDeploymentUuids::run(
        $this->application,
        collect([$destinationId]),
        $restart->id,
    ))->toBeNull()
        ->and((new ResolveOrdinaryApplicationDeploymentUuids)->currentRestartDeploymentUuids(
            $this->application,
            collect([$destinationId]),
            $restart->id,
        ))->toBeNull();
});

it('fails current restart recovery when a competing deployment appears during container observation', function () {
    $destinationId = (int) $this->application->destination_id;
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deployment-current',
        'destination_id' => $destinationId,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    $restart = ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deployment-restart',
        'destination_id' => $destinationId,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'restart_only' => true,
    ]);
    $competingDeploymentCreated = false;
    $observedContainer = ordinaryApplicationContainer(
        (int) $this->application->id,
        str_repeat('2', 64),
        $restart->deployment_uuid,
        "{$this->application->uuid}:restart-current",
    );
    $resolver = new class($destinationId, (int) $this->application->id, $observedContainer, function () use (&$competingDeploymentCreated): void {
        $competingDeploymentCreated = true;
    },
    ) extends ResolveActiveApplicationContainerState {
        public function __construct(
            private readonly int $destinationId,
            private readonly int $applicationId,
            private readonly array $observedContainer,
            private readonly Closure $onContainerObservation,
        ) {}

        protected function containersByDestination(
            Collection $destinationIds,
            Collection $destinations,
            int $applicationId,
        ): ?Collection {
            ($this->onContainerObservation)();
            ApplicationDeploymentQueue::query()->create([
                'application_id' => $this->applicationId,
                'deployment_uuid' => 'deployment-competing',
                'destination_id' => $this->destinationId,
                'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
            ]);

            return collect([
                $this->destinationId => collect([$this->observedContainer]),
            ]);
        }
    };

    expect($resolver->handleCurrentOrdinaryRestart(
        $this->application,
        $restart->id,
    ))->toBeNull()
        ->and($competingDeploymentCreated)->toBeTrue();
});

it('returns the existing conflict response when remote active-container inspection is not observable', function (
    string $buildPack,
    string $output,
    string $errorOutput,
    int $exitCode,
) {
    $this->application->update(['build_pack' => $buildPack]);
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deployment-current',
        'destination_id' => $this->application->destination_id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    Process::fake(fn (PendingProcess $process) => Process::result(
        output: $output,
        errorOutput: $errorOutput,
        exitCode: $exitCode,
    ));

    $this->withToken($this->token)
        ->getJson("/api/v1/applications/{$this->application->uuid}/active-container")
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Active application container state is not observable.',
        ]);

    Process::assertRan(function (PendingProcess $process): bool {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;

        return str_contains($command, 'docker container ls -aq --no-trunc')
            && str_contains($command, 'label=coolify.applicationId='.$this->application->id)
            && str_contains($command, 'label=coolify.pullRequestId=0');
    });
})->with([
    'blank successful inspection' => ['dockerimage', '', '', 0],
    'failed inspection' => ['dockercompose', '', 'remote container inspection failed', 23],
]);

it('keeps an exact committed clean idle journal fenced during a read-scoped active-container request', function (): void {
    [
        'scenario' => $scenario,
        'expected_state' => $expectedState,
        'token' => $token,
    ] = activeContainerApiCleanIdleScenario($this->user);
    $journalPresent = true;
    $payloads = [];
    fakeActiveContainerApiJournalRemote(
        $scenario,
        $expectedState,
        BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
        $journalPresent,
        $payloads,
    );
    InspectBlueGreenContainer::shouldNotRun();

    $response = $this->withToken($token)
        ->getJson("/api/v1/applications/{$scenario->application->uuid}/active-container");

    $response->assertConflict()
        ->assertExactJson([
            'message' => 'Active application container state is not observable.',
        ]);
    $remotePayload = implode("\n", $payloads);
    $lockPath = (new WriteBlueGreenProxyConfiguration)->managedLockPath(
        $scenario->server->proxyPath(),
        $expectedState->managedFilename,
    );
    $sharedLockCommand = 'exec 9<'.escapeshellarg($lockPath);

    expect($journalPresent)->toBeTrue()
        ->and($remotePayload)
        ->toContain(WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT)
        ->toContain($sharedLockCommand)
        ->toContain('flock -s 9')
        ->not->toContain(
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
            'committed_container_manifest_stage=',
            'operation_container_manifest_stage=',
            'mutation_journal_stage=',
            'durable_remote_replace ',
            'durable_remote_remove ',
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
            'sh "$committed_container_mutation_decoded"',
            'sh "$committed_container_completion_decoded"',
        )
        ->not->toMatch('/(?:^|\\n)\\s*(?:mkdir|chmod|setfacl)\\b/')
        ->not->toMatch('/(?:^|\\n)\\s*exec\\s+9>/')
        ->not->toMatch('/>\\s*'.preg_quote(escapeshellarg($lockPath), '/').'(?=\\s|$)/');
});

it('returns a structured conflict for a genuinely pending blue-green journal', function (): void {
    [
        'scenario' => $scenario,
        'expected_state' => $expectedState,
        'token' => $token,
    ] = activeContainerApiCleanIdleScenario($this->user);
    $journalPresent = true;
    $payloads = [];
    fakeActiveContainerApiJournalRemote(
        $scenario,
        $expectedState,
        BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
        $journalPresent,
        $payloads,
    );
    InspectBlueGreenContainer::shouldNotRun();

    $this->withToken($token)
        ->getJson("/api/v1/applications/{$scenario->application->uuid}/active-container")
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Active application container state is not observable.',
        ]);

    expect($journalPresent)->toBeTrue()
        ->and(implode("\n", $payloads))
        ->not->toContain(
            'committed_container_manifest_stage=',
            'operation_container_manifest_stage=',
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        );
});

it('does not enter lifecycle recovery while returning a structured conflict for a committed journal', function (): void {
    [
        'scenario' => $scenario,
        'expected_state' => $expectedState,
        'token' => $token,
    ] = activeContainerApiCleanIdleScenario($this->user);
    $journalPresent = true;
    $payloads = [];
    fakeActiveContainerApiJournalRemote(
        $scenario,
        $expectedState,
        BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
        $journalPresent,
        $payloads,
    );
    $repair = Mockery::mock(new RepairBlueGreenSteadyStates);
    $repair
        ->shouldReceive('recoverCleanIdleContainerMutationJournal')
        ->never();
    $this->app->instance(RepairBlueGreenSteadyStates::class, $repair);
    InspectBlueGreenContainer::shouldNotRun();

    $this->withToken($token)
        ->getJson("/api/v1/applications/{$scenario->application->uuid}/active-container")
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Active application container state is not observable.',
        ]);

    expect($journalPresent)->toBeTrue()
        ->and(implode("\n", $payloads))
        ->toContain(WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT)
        ->not->toContain(
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
            'committed_container_manifest_stage=',
        );
});

it('returns a structured conflict before read scope can inspect journal boot identity', function (): void {
    [
        'scenario' => $scenario,
        'expected_state' => $expectedState,
        'token' => $token,
    ] = activeContainerApiCleanIdleScenario($this->user);
    $journalPresent = true;
    $payloads = [];
    fakeActiveContainerApiJournalRemote(
        $scenario,
        $expectedState,
        BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
        $journalPresent,
        $payloads,
        bootMismatchAt: static fn (string $payload): bool => str_contains(
            $payload,
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
        ),
    );
    InspectBlueGreenContainer::shouldNotRun();

    $this->withToken($token)
        ->getJson("/api/v1/applications/{$scenario->application->uuid}/active-container")
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Active application container state is not observable.',
        ]);

    expect($journalPresent)->toBeTrue()
        ->and(implode("\n", $payloads))
        ->toContain(WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT)
        ->not->toContain('committed_container_manifest_stage=');
});

it('returns a structured conflict before read scope can archive a committed journal', function (): void {
    [
        'scenario' => $scenario,
        'expected_state' => $expectedState,
        'token' => $token,
    ] = activeContainerApiCleanIdleScenario($this->user);
    $journalPresent = true;
    $payloads = [];
    fakeActiveContainerApiJournalRemote(
        $scenario,
        $expectedState,
        BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
        $journalPresent,
        $payloads,
        bootMismatchAt: static fn (string $payload): bool => str_contains(
            $payload,
            'committed_container_manifest_stage=',
        ),
    );
    InspectBlueGreenContainer::shouldNotRun();

    $this->withToken($token)
        ->getJson("/api/v1/applications/{$scenario->application->uuid}/active-container")
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Active application container state is not observable.',
        ]);

    expect($journalPresent)->toBeTrue()
        ->and(implode("\n", $payloads))
        ->toContain(WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT)
        ->not->toContain(
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
            'committed_container_manifest_stage=',
        );
});

it('keeps an unrelated active-container route read fault as an internal server error', function (): void {
    [
        'scenario' => $scenario,
        'expected_state' => $expectedState,
        'token' => $token,
    ] = activeContainerApiCleanIdleScenario($this->user);
    $journalPresent = false;
    $payloads = [];
    fakeActiveContainerApiJournalRemote(
        $scenario,
        $expectedState,
        BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
        $journalPresent,
        $payloads,
        strictReadFault: 'unexpected active-container managed-route fault',
    );
    InspectBlueGreenContainer::shouldNotRun();

    $this->withToken($token)
        ->getJson("/api/v1/applications/{$scenario->application->uuid}/active-container")
        ->assertInternalServerError()
        ->assertJsonMissingExact([
            'message' => 'Active application container state is not observable.',
        ]);

    expect(implode("\n", $payloads))
        ->toContain('coolify-blue-green-managed-route:present:')
        ->not->toContain(
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
            'committed_container_manifest_stage=',
        );
});
