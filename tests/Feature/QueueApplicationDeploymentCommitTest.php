<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\CompleteBlueGreenDeploymentOperation;
use App\Actions\Application\BlueGreen\FindBlueGreenDeactivationFence;
use App\Actions\Application\BlueGreen\RecordBlueGreenCandidateIdentity;
use App\Actions\Application\BlueGreen\RecordBlueGreenDestinationState;
use App\Actions\Application\BlueGreen\RecordBlueGreenRoutingMutation;
use App\Actions\Application\BlueGreen\TransitionsBlueGreenDeployment;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\BlueGreenFleetStatus;
use App\Enums\DeploymentDispatchClaimResult;
use App\Events\ApplicationConfigurationChanged;
use App\Jobs\ActivateApplicationDeploymentJob;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\ConvergeBlueGreenDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\BlueGreenDeploymentLifecycle;
use App\Support\ProxyMutationQueue;
use App\Support\ProxyMutationQueueFrozenException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ActivateApplicationDeploymentJob::class, ApplicationDeploymentJob::class]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'test-network-'.fake()->unique()->word(),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function makeApplication(int $environmentId, int $destinationId, ?string $gitCommitSha): Application
{
    $attributes = [
        'environment_id' => $environmentId,
        'destination_id' => $destinationId,
        'destination_type' => StandaloneDocker::class,
    ];

    if ($gitCommitSha !== null) {
        $attributes['git_commit_sha'] = $gitCommitSha;
    }

    return Application::factory()->create($attributes);
}

function makeQueueAdmissionDeployment(
    Application $application,
    Server $server,
    string $deploymentUuid,
    ?StandaloneDocker $destination = null,
): ApplicationDeploymentQueue {
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination?->id ?? $application->destination_id,
        'deployment_uuid' => $deploymentUuid,
        'commit' => "commit-{$deploymentUuid}",
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);
}

function createPostCutoffQueueDeactivationFence(
    Application $application,
    StandaloneDocker $destination,
    int $queueCutoffId,
    BlueGreenDeactivationPhase $phase,
): ApplicationBlueGreenDeactivation {
    return ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'operation_id' => str_repeat('a', 64),
        'started_at' => now()->subHour(),
        'queue_cutoff_id' => $queueCutoffId,
        'supersession_generation' => 1,
        'phase' => $phase,
        'completed_at' => in_array($phase, [
            BlueGreenDeactivationPhase::COMPLETED,
            BlueGreenDeactivationPhase::STOPPED,
            BlueGreenDeactivationPhase::REMOVED,
        ], true) ? now()->subMinutes(30) : null,
    ]);
}

describe('deployment queue draining across destinations', function () {
    test('dispatches a third destination after the second destination finishes', function () {
        $serverB = Server::factory()->create(['team_id' => $this->team->id]);
        $serverC = Server::factory()->create(['team_id' => $this->team->id]);
        $destinationB = StandaloneDocker::factory()->create([
            'server_id' => $serverB->id,
            'network' => 'queue-destination-b',
        ]);
        $destinationC = StandaloneDocker::factory()->create([
            'server_id' => $serverC->id,
            'network' => 'queue-destination-c',
        ]);
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $secondDestination = makeQueueAdmissionDeployment(
            $application,
            $serverB,
            'queue-destination-b-deployment',
            $destinationB,
        );
        $thirdDestination = makeQueueAdmissionDeployment(
            $application,
            $serverC,
            'queue-destination-c-deployment',
            $destinationC,
        );

        expect($secondDestination->claimForDispatch(bypassServerCapacity: true))->toBeTrue()
            ->and($thirdDestination->claimForDispatch())->toBeFalse();

        $secondDestination->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);
        queue_next_deployment($secondDestination);

        expect($thirdDestination->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($thirdDestination->fresh()->horizon_job_id)->not->toBeNull();
        Bus::assertDispatched(
            ApplicationDeploymentJob::class,
            fn (ApplicationDeploymentJob $job): bool => $job->application_deployment_queue_id === $thirdDestination->id,
        );
    });

    test('dispatches the next destination after cancellation mid fan-out', function () {
        $serverB = Server::factory()->create(['team_id' => $this->team->id]);
        $serverC = Server::factory()->create(['team_id' => $this->team->id]);
        $destinationB = StandaloneDocker::factory()->create([
            'server_id' => $serverB->id,
            'network' => 'queue-cancel-destination-b',
        ]);
        $destinationC = StandaloneDocker::factory()->create([
            'server_id' => $serverC->id,
            'network' => 'queue-cancel-destination-c',
        ]);
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $cancelledDestination = makeQueueAdmissionDeployment(
            $application,
            $serverB,
            'queue-cancel-destination-b-deployment',
            $destinationB,
        );
        $nextDestination = makeQueueAdmissionDeployment(
            $application,
            $serverC,
            'queue-cancel-destination-c-deployment',
            $destinationC,
        );

        expect($cancelledDestination->claimForDispatch(bypassServerCapacity: true))->toBeTrue()
            ->and($nextDestination->claimForDispatch())->toBeFalse();

        $cancelledDestination->update(['status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value]);
        next_after_cancel($cancelledDestination);

        expect($nextDestination->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($nextDestination->fresh()->horizon_job_id)->not->toBeNull();
        Bus::assertDispatched(
            ApplicationDeploymentJob::class,
            fn (ApplicationDeploymentJob $job): bool => $job->application_deployment_queue_id === $nextDestination->id,
        );
    });
});

describe('proxy mutation freeze recovery', function () {
    test('keeps a rejected physical publication durably recoverable', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-freeze-durable-dispatch',
        );
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $deployment->update(['execution_phase' => ApplicationDeploymentExecutionPhase::Activate]);
        $deployment->refresh();
        $dispatchAttemptUuid = $deployment->horizon_job_id;
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new ProxyMutationQueueFrozenException('test-freeze-owner'));
        app()->instance(Dispatcher::class, $dispatcher);

        expect(dispatch_claimed_application_deployment($deployment))->toBeTrue()
            ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($deployment->fresh()->horizon_job_id)->toBe($dispatchAttemptUuid)
            ->and($deployment->fresh()->horizon_job_worker)->toBeNull();
    });

    test('keeps a post-handoff publication failure durably recoverable', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-handoff-publication-failure',
        );
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $deployment->update(['execution_phase' => ApplicationDeploymentExecutionPhase::Activate]);
        $deployment->refresh();
        $dispatchAttemptUuid = $deployment->horizon_job_id;
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('Transient activation queue outage.'));
        app()->instance(Dispatcher::class, $dispatcher);

        expect(dispatch_claimed_application_deployment(
            $deployment,
            preserveActivationForRecoveryOnFailure: true,
        ))->toBeTrue()
            ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($deployment->fresh()->execution_phase)->toBe(ApplicationDeploymentExecutionPhase::Activate)
            ->and($deployment->fresh()->horizon_job_id)->toBe($dispatchAttemptUuid)
            ->and($deployment->fresh()->horizon_job_worker)->toBeNull()
            ->and($deployment->fresh()->finished_at)->toBeNull();
    });

    test('recovers preparation without publishing activation while proxy mutations are frozen', function () {
        $prepareApplication = makeApplication($this->environment->id, $this->destination->id, null);
        $prepareDeployment = makeQueueAdmissionDeployment(
            $prepareApplication,
            $this->server,
            'queue-freeze-prepare-recovery',
        );
        expect($prepareDeployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $activationApplication = makeApplication($this->environment->id, $this->destination->id, null);
        $activationDeployment = makeQueueAdmissionDeployment(
            $activationApplication,
            $this->server,
            'queue-freeze-activation-recovery',
        );
        expect($activationDeployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $activationDeployment->update(['execution_phase' => ApplicationDeploymentExecutionPhase::Activate]);
        ApplicationDeploymentQueue::query()
            ->whereKey([$prepareDeployment->id, $activationDeployment->id])
            ->update(['updated_at' => now()->subMinutes(10)]);
        $operationId = 'test-recovery-freeze-'.Str::uuid();
        $jobRepository = $this->mock(JobRepository::class);
        $jobRepository
            ->shouldReceive('getJobs')
            ->once()
            ->with([$prepareDeployment->horizon_job_id])
            ->andReturn(collect());
        $jobRepository
            ->shouldReceive('getJobs')
            ->once()
            ->with([$activationDeployment->horizon_job_id])
            ->andReturn(collect());

        try {
            ProxyMutationQueue::freeze($operationId);

            expect(recover_stale_application_deployment_dispatches(limit: 2))->toBe(1);
            Bus::assertDispatched(ApplicationDeploymentJob::class, fn (ApplicationDeploymentJob $job): bool => $job->application_deployment_queue_id === $prepareDeployment->id);
            Bus::assertNotDispatched(ActivateApplicationDeploymentJob::class);
            expect($activationDeployment->fresh()->updated_at->lt(now()->subMinutes(5)))->toBeTrue();
        } finally {
            $snapshot = ProxyMutationQueue::snapshot();
            if ($snapshot->freezeOperationId === $operationId) {
                ProxyMutationQueue::unfreeze(
                    $operationId,
                    expectedFence: $snapshot->freezeFence ?? throw new RuntimeException('The test freeze did not issue a fence.'),
                );
            }
        }

        expect(recover_stale_application_deployment_dispatches(limit: 2))->toBe(1);
        Bus::assertDispatched(ActivateApplicationDeploymentJob::class, function (ActivateApplicationDeploymentJob $job) use ($activationDeployment): bool {
            return $job->application_deployment_queue_id === $activationDeployment->id
                && $job->dispatch_attempt_uuid === $activationDeployment->horizon_job_id
                && $job->connection === ProxyMutationQueue::CONNECTION
                && $job->queue === ProxyMutationQueue::NAME;
        });
    });
});

describe('application deployment execution phase handoff', function () {
    test('atomically transfers preparation ownership to an encrypted activation attempt', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-phase-handoff',
        );
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $deployment = $deployment->fresh();
        $prepareAttemptUuid = $deployment->horizon_job_id;
        $prepareWorker = 'prepare-worker-a';
        expect($deployment->acquireDispatchExecution($prepareAttemptUuid, $prepareWorker))->toBeTrue();

        $payload = $deployment->makePreparedActivationPayload([
            'kind' => 'container-image',
            'reference' => 'registry.example.test/coolify/application@sha256:'.str_repeat('a', 64),
            'runtime_secret' => 'phase-handoff-secret',
        ]);

        $activationAttemptUuid = $deployment->handoffToActivation(
            $prepareAttemptUuid,
            $prepareWorker,
            $payload,
        );
        $persisted = $deployment->fresh();
        $rawPayload = DB::table('application_deployment_queues')
            ->where('id', $deployment->id)
            ->value('prepared_activation_payload');

        expect($activationAttemptUuid)->toBeString()
            ->and(Str::isUuid($activationAttemptUuid))->toBeTrue()
            ->and($activationAttemptUuid)->not->toBe($prepareAttemptUuid)
            ->and($persisted->execution_phase)->toBe(ApplicationDeploymentExecutionPhase::Activate)
            ->and($persisted->horizon_job_id)->toBe($activationAttemptUuid)
            ->and($persisted->horizon_job_worker)->toBeNull()
            ->and($persisted->prepared_activation_payload)->toBe($payload)
            ->and($persisted->toArray())->not->toHaveKey('prepared_activation_payload')
            ->and($rawPayload)->toBeString()
            ->and($rawPayload)->not->toContain('phase-handoff-secret');

        expect($persisted->validatedPreparedActivationPayload())->toBe($payload);

        dispatch_claimed_application_deployment($persisted);
        Bus::assertDispatched(ActivateApplicationDeploymentJob::class, function (ActivateApplicationDeploymentJob $job) use ($persisted): bool {
            return $job->application_deployment_queue_id === $persisted->id
                && $job->dispatch_attempt_uuid === $persisted->horizon_job_id
                && $job->connection === ProxyMutationQueue::CONNECTION
                && $job->queue === ProxyMutationQueue::NAME;
        });
    });

    test('rejects stale preparation owners and malformed activation identities', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-phase-stale-owner',
        );
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $deployment = $deployment->fresh();
        $prepareAttemptUuid = $deployment->horizon_job_id;
        expect($deployment->acquireDispatchExecution($prepareAttemptUuid, 'prepare-worker-a'))->toBeTrue();
        $payload = $deployment->makePreparedActivationPayload([]);

        expect($deployment->handoffToActivation($prepareAttemptUuid, 'foreign-worker', $payload))->toBeNull()
            ->and(fn () => $deployment->handoffToActivation(
                $prepareAttemptUuid,
                'prepare-worker-a',
                [...$payload, 'destination_id' => (int) $deployment->destination_id + 1],
            ))->toThrow(InvalidArgumentException::class, 'invalid destination_id');

        $tamperedPayload = $payload;
        $tamperedPayload['artifact']['image'] = 'sha256:tampered';
        expect(fn () => $deployment->handoffToActivation(
            $prepareAttemptUuid,
            'prepare-worker-a',
            $tamperedPayload,
        ))->toThrow(InvalidArgumentException::class, 'fingerprint is invalid');

        $activationAttemptUuid = $deployment->handoffToActivation(
            $prepareAttemptUuid,
            'prepare-worker-a',
            $payload,
        );

        expect($activationAttemptUuid)->toBeString()
            ->and($deployment->handoffToActivation($prepareAttemptUuid, 'prepare-worker-a', $payload))->toBeNull();
    });

    test('revalidates prepared input against the locked deployment row', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-phase-locked-identity',
        );
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $deployment = $deployment->fresh();
        $prepareAttemptUuid = $deployment->horizon_job_id;
        expect($deployment->acquireDispatchExecution($prepareAttemptUuid, 'prepare-worker-a'))->toBeTrue();
        $payload = $deployment->makePreparedActivationPayload([]);
        ApplicationDeploymentQueue::query()
            ->whereKey($deployment->id)
            ->update(['commit' => 'newer-commit-after-preparation']);

        expect(fn () => $deployment->handoffToActivation(
            $prepareAttemptUuid,
            'prepare-worker-a',
            $payload,
        ))->toThrow(InvalidArgumentException::class, 'prepared activation payload is malformed')
            ->and($deployment->fresh()->execution_phase)->toBe(ApplicationDeploymentExecutionPhase::Prepare)
            ->and($deployment->fresh()->horizon_job_id)->toBe($prepareAttemptUuid);
    });

    test('only the owning attempt can clear its completed remote process', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-process-owner-cas',
        );
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $deployment = $deployment->fresh();
        $prepareAttemptUuid = $deployment->horizon_job_id;
        expect($deployment->acquireDispatchExecution($prepareAttemptUuid, 'prepare-worker-a'))->toBeTrue();
        expect($deployment->claimCurrentProcessOwnership(
            (string) Str::uuid(),
            'process-123',
        ))->toBeFalse()
            ->and($deployment->fresh()->current_process_id)->toBeNull()
            ->and($deployment->claimCurrentProcessOwnership(
                $prepareAttemptUuid,
                'process-123',
            ))->toBeTrue()
            ->and($deployment->fresh()->current_process_id)->toBe('process-123')
            ->and($deployment->claimCurrentProcessOwnership(
                $prepareAttemptUuid,
                'process-456',
            ))->toBeFalse();

        expect($deployment->releaseCurrentProcessOwnership(
            (string) Str::uuid(),
            'process-123',
        ))->toBeFalse()
            ->and($deployment->fresh()->current_process_id)->toBe('process-123')
            ->and($deployment->releaseCurrentProcessOwnership(
                $prepareAttemptUuid,
                'stale-process',
            ))->toBeFalse()
            ->and($deployment->fresh()->current_process_id)->toBe('process-123')
            ->and($deployment->releaseCurrentProcessOwnership(
                $prepareAttemptUuid,
                'process-123',
            ))->toBeTrue()
            ->and($deployment->fresh()->current_process_id)->toBeNull();
    });

    test('the owning attempt keeps process ownership for terminal-status cleanup commands', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-process-owner-terminal-cleanup',
        );
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $deployment = $deployment->fresh();
        $attemptUuid = $deployment->horizon_job_id;
        expect($deployment->acquireDispatchExecution($attemptUuid, 'prepare-worker-a'))->toBeTrue();

        foreach ([ApplicationDeploymentStatus::FINISHED, ApplicationDeploymentStatus::FAILED] as $terminalStatus) {
            ApplicationDeploymentQueue::query()
                ->whereKey($deployment->id)
                ->update(['status' => $terminalStatus->value]);

            expect($deployment->claimCurrentProcessOwnership((string) Str::uuid(), 'foreign-cleanup'))->toBeFalse()
                ->and($deployment->fresh()->current_process_id)->toBeNull()
                ->and($deployment->claimCurrentProcessOwnership($attemptUuid, "cleanup-{$terminalStatus->value}"))->toBeTrue()
                ->and($deployment->fresh()->current_process_id)->toBe("cleanup-{$terminalStatus->value}")
                ->and($deployment->releaseCurrentProcessOwnership($attemptUuid, "cleanup-{$terminalStatus->value}"))->toBeTrue()
                ->and($deployment->fresh()->current_process_id)->toBeNull();
        }

        foreach ([ApplicationDeploymentStatus::CANCELLED_BY_USER, ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET] as $cancelledStatus) {
            ApplicationDeploymentQueue::query()
                ->whereKey($deployment->id)
                ->update(['status' => $cancelledStatus->value]);

            expect($deployment->claimCurrentProcessOwnership($attemptUuid, 'cleanup-after-cancel'))->toBeFalse()
                ->and($deployment->fresh()->current_process_id)->toBeNull();
        }
    });

    test('only the owning preparation attempt can persist its build server', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $buildServer = Server::factory()->create(['team_id' => $this->team->id]);
        $deployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-build-server-owner-cas',
        );
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $deployment = $deployment->fresh();
        $prepareAttemptUuid = $deployment->horizon_job_id;

        expect($deployment->recordPreparationBuildServer($prepareAttemptUuid, $buildServer->id))->toBeFalse()
            ->and($deployment->acquireDispatchExecution($prepareAttemptUuid, 'prepare-worker-a'))->toBeTrue()
            ->and($deployment->recordPreparationBuildServer((string) Str::uuid(), $buildServer->id))->toBeFalse()
            ->and($deployment->recordPreparationBuildServer($prepareAttemptUuid, $buildServer->id))->toBeTrue()
            ->and($deployment->fresh()->build_server_id)->toBe($buildServer->id);

        $deployment->update(['execution_phase' => ApplicationDeploymentExecutionPhase::Activate]);
        expect($deployment->recordPreparationBuildServer($prepareAttemptUuid, $this->server->id))->toBeFalse()
            ->and($deployment->fresh()->build_server_id)->toBe($buildServer->id);
    });
});

describe('queue_application_deployment commit resolution', function () {
    test('uses application git_commit_sha when commit parameter omitted', function () {
        $pinnedSha = 'abc123def456abc123def456abc123def456abc1';
        $application = makeApplication($this->environment->id, $this->destination->id, $pinnedSha);

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: 'test-deploy-uuid-1',
        );

        expect($result['status'])->toBe('queued');

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', 'test-deploy-uuid-1')->first();
        expect($deployment)->not->toBeNull();
        expect($deployment->commit)->toBe($pinnedSha);
    });

    test('falls back to HEAD when both commit parameter and git_commit_sha are unset', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, 'HEAD');

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: 'test-deploy-uuid-2',
        );

        expect($result['status'])->toBe('queued');

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', 'test-deploy-uuid-2')->first();
        expect($deployment->commit)->toBe('HEAD');
    });

    test('explicit commit parameter overrides application git_commit_sha', function () {
        $pinnedSha = 'abc123def456abc123def456abc123def456abc1';
        $webhookSha = '111222333444555666777888999000aaabbbccc1';
        $application = makeApplication($this->environment->id, $this->destination->id, $pinnedSha);

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: 'test-deploy-uuid-3',
            commit: $webhookSha,
        );

        expect($result['status'])->toBe('queued');

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', 'test-deploy-uuid-3')->first();
        expect($deployment->commit)->toBe($webhookSha);
    });

    test('treats empty string commit parameter as unset and uses git_commit_sha', function () {
        $pinnedSha = 'abc123def456abc123def456abc123def456abc1';
        $application = makeApplication($this->environment->id, $this->destination->id, $pinnedSha);

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: 'test-deploy-uuid-4',
            commit: '',
        );

        expect($result['status'])->toBe('queued');

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', 'test-deploy-uuid-4')->first();
        expect($deployment->commit)->toBe($pinnedSha);
    });
});

describe('ApplicationDeploymentQueue stale dispatch recovery', function () {
    test('reclaims an abandoned exact worker attempt with one new dispatch identity', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment($application, $this->server, 'queue-abandoned-worker');
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $originalAttemptUuid = $deployment->horizon_job_id;
        $abandonedWorkerUuid = (string) Str::uuid();
        expect($deployment->acquireDispatchExecution($originalAttemptUuid, $abandonedWorkerUuid))->toBeTrue();
        ApplicationDeploymentQueue::query()
            ->whereKey($deployment->id)
            ->update(['updated_at' => now()->subMinutes(10)]);
        $lookups = [];
        $recoveredIds = [];

        $recovered = ApplicationDeploymentQueue::recoverStaleDispatchAttempts(
            staleAfterSeconds: 60,
            findLiveDispatchAttemptUuids: function (array $uuids) use (&$lookups): array {
                $lookups = $uuids;

                return [];
            },
            onRecovered: function (ApplicationDeploymentQueue $recoveredDeployment) use (&$recoveredIds): void {
                $recoveredIds[] = $recoveredDeployment->id;
            },
        );
        $persisted = $deployment->fresh();

        expect($recovered)->toBe(1)
            ->and($lookups)->toBe([$abandonedWorkerUuid])
            ->and($recoveredIds)->toBe([$deployment->id])
            ->and($persisted->horizon_job_worker)->toBeNull()
            ->and(Str::isUuid($persisted->horizon_job_id))->toBeTrue()
            ->and($persisted->horizon_job_id)->not->toBe($originalAttemptUuid)
            ->and($deployment->reserveStaleDispatchRepublish(now(), $abandonedWorkerUuid))->toBeFalse();
    });

    test('does not reclaim a worker attempt still present in Horizon', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment($application, $this->server, 'queue-live-worker');
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $attemptUuid = $deployment->horizon_job_id;
        $workerUuid = (string) Str::uuid();
        expect($deployment->acquireDispatchExecution($attemptUuid, $workerUuid))->toBeTrue();
        ApplicationDeploymentQueue::query()
            ->whereKey($deployment->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        $recovered = ApplicationDeploymentQueue::recoverStaleDispatchAttempts(
            staleAfterSeconds: 60,
            findLiveDispatchAttemptUuids: fn (array $uuids): array => $uuids,
        );

        expect($recovered)->toBe(0)
            ->and($deployment->fresh()->horizon_job_id)->toBe($attemptUuid)
            ->and($deployment->fresh()->horizon_job_worker)->toBe($workerUuid)
            ->and($deployment->fresh()->updated_at->gt(now()->subMinute()))->toBeTrue();
    });

    test('caps stale recovery candidates in id order and preserves retry UUIDs across runs', function () {
        $staleDeployments = collect(range(1, 5))->map(function (int $index): ApplicationDeploymentQueue {
            $application = makeApplication($this->environment->id, $this->destination->id, null);
            $deployment = makeQueueAdmissionDeployment($application, $this->server, "queue-bounded-recovery-{$index}");
            expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();

            return $deployment;
        });
        ApplicationDeploymentQueue::query()
            ->whereKey($staleDeployments->pluck('id'))
            ->update(['updated_at' => now()->subMinutes(10)]);

        $retryUuidsByDeploymentId = $staleDeployments
            ->mapWithKeys(static fn (ApplicationDeploymentQueue $deployment): array => [
                $deployment->id => $deployment->horizon_job_id,
            ])
            ->all();
        $recoveredBatches = [];
        $recoveredRetryUuids = [];
        $recoveredCounts = [];

        foreach (range(1, 3) as $run) {
            $recoveredIds = [];
            $recoveredCounts[] = ApplicationDeploymentQueue::recoverStaleDispatchAttempts(
                findLiveDispatchAttemptUuids: static fn (array $dispatchAttemptUuids): array => [],
                onRecovered: function (ApplicationDeploymentQueue $deployment) use (&$recoveredIds, &$recoveredRetryUuids, $retryUuidsByDeploymentId): void {
                    $recoveredIds[] = $deployment->id;
                    $recoveredRetryUuids[] = $deployment->horizon_job_id;

                    expect($deployment->horizon_job_id)->toBe($retryUuidsByDeploymentId[$deployment->id]);
                },
                limit: 2,
            );
            $recoveredBatches[] = $recoveredIds;
        }

        expect($recoveredCounts)->toBe([2, 2, 1])
            ->and($recoveredBatches)->toBe([
                [$staleDeployments[0]->id, $staleDeployments[1]->id],
                [$staleDeployments[2]->id, $staleDeployments[3]->id],
                [$staleDeployments[4]->id],
            ])
            ->and($recoveredRetryUuids)->toBe(array_values($retryUuidsByDeploymentId));

        foreach ($staleDeployments as $deployment) {
            expect($deployment->fresh()->horizon_job_id)->toBe($retryUuidsByDeploymentId[$deployment->id]);
        }
    });

    test('consumes the candidate cap for a live first candidate without backfilling', function () {
        $staleDeployments = collect(range(1, 3))->map(function (int $index): ApplicationDeploymentQueue {
            $application = makeApplication($this->environment->id, $this->destination->id, null);
            $deployment = makeQueueAdmissionDeployment($application, $this->server, "queue-live-candidate-{$index}");
            expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();

            return $deployment;
        });
        ApplicationDeploymentQueue::query()
            ->whereKey($staleDeployments->pluck('id'))
            ->update(['updated_at' => now()->subMinutes(10)]);

        [$liveDeployment, $recoveredDeployment, $unscannedDeployment] = $staleDeployments->all();
        $candidateAttemptBatches = [];
        $recoveredIds = [];

        $recoveredCount = ApplicationDeploymentQueue::recoverStaleDispatchAttempts(
            findLiveDispatchAttemptUuids: function (array $dispatchAttemptUuids) use (&$candidateAttemptBatches, $liveDeployment): array {
                $candidateAttemptBatches[] = $dispatchAttemptUuids;

                return [$liveDeployment->horizon_job_id];
            },
            onRecovered: function (ApplicationDeploymentQueue $deployment) use (&$recoveredIds): void {
                $recoveredIds[] = $deployment->id;
            },
            limit: 2,
        );

        expect($recoveredCount)->toBe(1)
            ->and($candidateAttemptBatches)->toBe([[
                $liveDeployment->horizon_job_id,
                $recoveredDeployment->horizon_job_id,
            ]])
            ->and($recoveredIds)->toBe([$recoveredDeployment->id])
            ->and($liveDeployment->fresh()->updated_at->gt(now()->subMinute()))->toBeTrue()
            ->and($recoveredDeployment->fresh()->updated_at->gt(now()->subMinute()))->toBeTrue()
            ->and($unscannedDeployment->fresh()->updated_at->lt(now()->subMinutes(5)))->toBeTrue();

        $secondRunCount = ApplicationDeploymentQueue::recoverStaleDispatchAttempts(
            findLiveDispatchAttemptUuids: function (array $dispatchAttemptUuids) use (&$candidateAttemptBatches): array {
                $candidateAttemptBatches[] = $dispatchAttemptUuids;

                return [];
            },
            onRecovered: function (ApplicationDeploymentQueue $deployment) use (&$recoveredIds): void {
                $recoveredIds[] = $deployment->id;
            },
            limit: 2,
        );

        expect($secondRunCount)->toBe(1)
            ->and($candidateAttemptBatches)->toBe([
                [$liveDeployment->horizon_job_id, $recoveredDeployment->horizon_job_id],
                [$unscannedDeployment->horizon_job_id],
            ])
            ->and($recoveredIds)->toBe([$recoveredDeployment->id, $unscannedDeployment->id]);
    });

    test('consumes cancelled candidates without backfilling the recovery run', function () {
        $staleDeployments = collect(range(1, 3))->map(function (int $index): ApplicationDeploymentQueue {
            $application = makeApplication($this->environment->id, $this->destination->id, null);
            $deployment = makeQueueAdmissionDeployment($application, $this->server, "queue-cancelled-candidate-{$index}");
            expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();

            return $deployment;
        });
        ApplicationDeploymentQueue::query()
            ->whereKey($staleDeployments->pluck('id'))
            ->update(['updated_at' => now()->subMinutes(10)]);

        [$cancelledDeployment, $recoveredDeployment, $unscannedDeployment] = $staleDeployments->all();
        $candidateAttemptBatches = [];
        $recoveredIds = [];

        $recoveredCount = ApplicationDeploymentQueue::recoverStaleDispatchAttempts(
            findLiveDispatchAttemptUuids: function (array $dispatchAttemptUuids) use (&$candidateAttemptBatches, $cancelledDeployment): array {
                $candidateAttemptBatches[] = $dispatchAttemptUuids;
                ApplicationDeploymentQueue::query()
                    ->whereKey($cancelledDeployment->id)
                    ->update(['status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value]);

                return [];
            },
            onRecovered: function (ApplicationDeploymentQueue $deployment) use (&$recoveredIds): void {
                $recoveredIds[] = $deployment->id;
            },
            limit: 2,
        );

        expect($recoveredCount)->toBe(1)
            ->and($candidateAttemptBatches)->toBe([[
                $cancelledDeployment->horizon_job_id,
                $recoveredDeployment->horizon_job_id,
            ]])
            ->and($cancelledDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
            ->and($recoveredIds)->toBe([$recoveredDeployment->id])
            ->and($unscannedDeployment->fresh()->updated_at->lt(now()->subMinutes(5)))->toBeTrue();
    });

    test('consumes candidates that lose their dispatch CAS without backfilling the recovery run', function () {
        $staleDeployments = collect(range(1, 3))->map(function (int $index): ApplicationDeploymentQueue {
            $application = makeApplication($this->environment->id, $this->destination->id, null);
            $deployment = makeQueueAdmissionDeployment($application, $this->server, "queue-cas-loss-candidate-{$index}");
            expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();

            return $deployment;
        });
        ApplicationDeploymentQueue::query()
            ->whereKey($staleDeployments->pluck('id'))
            ->update(['updated_at' => now()->subMinutes(10)]);

        [$contestedDeployment, $recoveredDeployment, $unscannedDeployment] = $staleDeployments->all();
        $candidateAttemptBatches = [];
        $recoveredIds = [];

        $recoveredCount = ApplicationDeploymentQueue::recoverStaleDispatchAttempts(
            findLiveDispatchAttemptUuids: function (array $dispatchAttemptUuids) use (&$candidateAttemptBatches, $contestedDeployment): array {
                $candidateAttemptBatches[] = $dispatchAttemptUuids;
                ApplicationDeploymentQueue::query()
                    ->whereKey($contestedDeployment->id)
                    ->update(['horizon_job_worker' => 'concurrent-worker']);

                return [];
            },
            onRecovered: function (ApplicationDeploymentQueue $deployment) use (&$recoveredIds): void {
                $recoveredIds[] = $deployment->id;
            },
            limit: 2,
        );

        expect($recoveredCount)->toBe(1)
            ->and($candidateAttemptBatches)->toBe([[
                $contestedDeployment->horizon_job_id,
                $recoveredDeployment->horizon_job_id,
            ]])
            ->and($contestedDeployment->fresh()->horizon_job_worker)->toBe('concurrent-worker')
            ->and($recoveredIds)->toBe([$recoveredDeployment->id])
            ->and($unscannedDeployment->fresh()->updated_at->lt(now()->subMinutes(5)))->toBeTrue();
    });

    test('publishes each recovered candidate immediately and stops when dispatch throws', function () {
        $staleDeployments = collect(range(1, 3))->map(function (int $index): ApplicationDeploymentQueue {
            $application = makeApplication($this->environment->id, $this->destination->id, null);
            $deployment = makeQueueAdmissionDeployment($application, $this->server, "queue-immediate-publication-{$index}");
            expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();

            return $deployment;
        });
        ApplicationDeploymentQueue::query()
            ->whereKey($staleDeployments->pluck('id'))
            ->update(['updated_at' => now()->subMinutes(10)]);

        [$firstDeployment, $secondDeployment, $thirdDeployment] = $staleDeployments->all();
        $firstRetryUuid = $firstDeployment->horizon_job_id;
        $secondRetryUuid = $secondDeployment->horizon_job_id;
        $publishedJob = null;
        $this->mock(JobRepository::class)
            ->shouldReceive('getJobs')
            ->once()
            ->with([$firstRetryUuid, $secondRetryUuid])
            ->andReturn(collect());
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function (ApplicationDeploymentJob $job) use (&$publishedJob): never {
            $publishedJob = $job;

            throw new RuntimeException('Queue publication failed mid-recovery.');
        });
        app()->instance(Dispatcher::class, $dispatcher);

        expect(fn (): int => recover_stale_application_deployment_dispatches(limit: 2))
            ->toThrow(RuntimeException::class, 'Queue publication failed mid-recovery.');

        expect($publishedJob)->toBeInstanceOf(ApplicationDeploymentJob::class)
            ->and($publishedJob->application_deployment_queue_id)->toBe($firstDeployment->id)
            ->and($publishedJob->dispatch_attempt_uuid)->toBe($firstRetryUuid)
            ->and(Str::isUuid($publishedJob->dispatch_attempt_uuid))->toBeTrue()
            ->and($firstDeployment->fresh()->horizon_job_id)->toBe($firstRetryUuid)
            ->and($firstDeployment->fresh()->updated_at->gt(now()->subMinute()))->toBeTrue()
            ->and($secondDeployment->fresh()->updated_at->lt(now()->subMinutes(5)))->toBeTrue()
            ->and($thirdDeployment->fresh()->updated_at->lt(now()->subMinutes(5)))->toBeTrue();
    });
});

describe('activation job queue restore', function () {
    test('rehydrates parent private deployment context after SerializesModels restore', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment($application, $this->server, 'activate-queue-restore');
        $deployment->update([
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
            'execution_phase' => ApplicationDeploymentExecutionPhase::Activate,
            'horizon_job_id' => $attempt = (string) Str::uuid(),
        ]);

        $job = new ActivateApplicationDeploymentJob($deployment->id, $attempt);
        $payload = $job->__serialize();

        // SerializesModels only reflects the concrete class, so parent private state is absent.
        expect(array_key_exists("\0".ActivateApplicationDeploymentJob::class."\0application_deployment_queue", $payload))->toBeFalse()
            ->and(array_key_exists("\0".ApplicationDeploymentJob::class."\0application_deployment_queue", $payload))->toBeFalse();

        $restored = (new ReflectionClass(ActivateApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
        $restored->__unserialize($payload);

        $queueProperty = new ReflectionProperty(ApplicationDeploymentJob::class, 'application_deployment_queue');
        expect($queueProperty->isInitialized($restored))->toBeTrue()
            ->and($queueProperty->getValue($restored)->id)->toBe($deployment->id)
            ->and($restored->application_deployment_queue_id)->toBe($deployment->id);
    });

    test('failed rehydrates before preserving a newer dispatch owner', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment($application, $this->server, 'activate-failed-rehydrate');
        $deployment->update([
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
            'execution_phase' => ApplicationDeploymentExecutionPhase::Activate,
            'horizon_job_id' => $attempt = (string) Str::uuid(),
        ]);

        $job = new ActivateApplicationDeploymentJob($deployment->id, $attempt);
        $payload = $job->__serialize();
        $restored = (new ReflectionClass(ActivateApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
        $restored->__unserialize($payload);
        $newerAttempt = (string) Str::uuid();
        $deployment->update(['horizon_job_id' => $newerAttempt]);

        $queueProperty = new ReflectionProperty(ApplicationDeploymentJob::class, 'application_deployment_queue');
        expect($queueProperty->isInitialized($restored))->toBeTrue();

        $restored->failed(new RuntimeException('stale activation failure must not win'));

        expect($queueProperty->isInitialized($restored))->toBeTrue()
            ->and($queueProperty->getValue($restored)->id)->toBe($deployment->id)
            ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($deployment->fresh()->execution_phase)->toBe(ApplicationDeploymentExecutionPhase::Activate)
            ->and($deployment->fresh()->horizon_job_id)->toBe($newerAttempt);
    });
});

describe('transactional admission and reattach', function () {
    test('reattaches an identical nonterminal deployment and returns its real uuid', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, 'abc1234');

        $first = queue_application_deployment(
            application: $application,
            deployment_uuid: 'admission-first',
        );
        $second = queue_application_deployment(
            application: $application,
            deployment_uuid: 'admission-second',
        );

        expect($first['status'])->toBe('queued')
            ->and($second['status'])->toBe('reattached')
            ->and($second['deployment_uuid'])->toBe('admission-first')
            ->and(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->count())->toBe(1);
    });

    test('promotes force_rebuild on a queued reattach but never mutates a running row', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, 'abc1234');
        ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'application_name' => $application->name,
            'server_id' => $this->server->id,
            'server_name' => $this->server->name,
            'destination_id' => $this->destination->id,
            'deployment_uuid' => 'admission-running-blocker',
            'commit' => 'unrelated-running-commit',
            'pull_request_id' => 0,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        $first = queue_application_deployment(
            application: $application,
            deployment_uuid: 'admission-queued',
        );
        $queuedRow = ApplicationDeploymentQueue::query()->where('deployment_uuid', 'admission-queued')->firstOrFail();
        expect($first['status'])->toBe('queued')
            ->and($queuedRow->status)->toBe(ApplicationDeploymentStatus::QUEUED->value)
            ->and((bool) $queuedRow->force_rebuild)->toBeFalse();

        $second = queue_application_deployment(
            application: $application,
            deployment_uuid: 'admission-queued-retry',
            force_rebuild: true,
        );

        expect($second['status'])->toBe('reattached')
            ->and($second['deployment_uuid'])->toBe('admission-queued')
            ->and((bool) $queuedRow->fresh()->force_rebuild)->toBeTrue();

        $runningRetry = queue_application_deployment(
            application: $application,
            deployment_uuid: 'admission-running-retry',
            commit: 'unrelated-running-commit',
            force_rebuild: true,
        );

        expect($runningRetry['status'])->toBe('reattached')
            ->and($runningRetry['deployment_uuid'])->toBe('admission-running-blocker')
            ->and((bool) ApplicationDeploymentQueue::query()->where('deployment_uuid', 'admission-running-blocker')->firstOrFail()->force_rebuild)->toBeFalse();
    });

    test('terminal rows never dedup a retry', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, 'abc1234');
        ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'application_name' => $application->name,
            'server_id' => $this->server->id,
            'server_name' => $this->server->name,
            'destination_id' => $this->destination->id,
            'deployment_uuid' => 'admission-failed',
            'commit' => 'abc1234',
            'pull_request_id' => 0,
            'status' => ApplicationDeploymentStatus::FAILED->value,
        ]);

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: 'admission-after-failure',
        );

        expect($result['status'])->toBe('queued')
            ->and($result['deployment_uuid'])->toBe('admission-after-failure');
    });

    test('distinct logical identity always creates a new row', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, 'abc1234');
        ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'application_name' => $application->name,
            'server_id' => $this->server->id,
            'server_name' => $this->server->name,
            'destination_id' => $this->destination->id,
            'deployment_uuid' => 'admission-running-blocker',
            'commit' => 'unrelated-running-commit',
            'pull_request_id' => 0,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        $plain = queue_application_deployment(
            application: $application,
            deployment_uuid: 'admission-plain',
        );
        $rollback = queue_application_deployment(
            application: $application,
            deployment_uuid: 'admission-rollback',
            rollback: true,
        );
        $tagged = queue_application_deployment(
            application: $application,
            deployment_uuid: 'admission-tagged',
            docker_registry_image_tag: 'sha-abc',
        );

        expect($plain['status'])->toBe('queued')
            ->and($rollback['status'])->toBe('queued')
            ->and($tagged['status'])->toBe('queued')
            ->and(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->count())->toBe(4);
    });

    test('reattach happens before the capacity check so retries never see queue_full', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, 'abc1234');
        $this->server->settings()->update(['deployment_queue_limit' => 1]);
        ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'application_name' => $application->name,
            'server_id' => $this->server->id,
            'server_name' => $this->server->name,
            'destination_id' => $this->destination->id,
            'deployment_uuid' => 'admission-running-blocker',
            'commit' => 'unrelated-running-commit',
            'pull_request_id' => 0,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);
        $first = queue_application_deployment(
            application: $application,
            deployment_uuid: 'admission-fills-queue',
        );
        expect($first['status'])->toBe('queued');

        $retry = queue_application_deployment(
            application: $application,
            deployment_uuid: 'admission-retry-at-capacity',
        );
        $overflow = queue_application_deployment(
            application: $application,
            deployment_uuid: 'admission-overflow',
            rollback: true,
        );

        expect($retry['status'])->toBe('reattached')
            ->and($retry['deployment_uuid'])->toBe('admission-fills-queue')
            ->and($overflow['status'])->toBe('queue_full');
    });
});

describe('blue-green dispatch claim gate', function () {
    test('ordinary lifecycle admission clears stale idle rollback diagnostics before an exact successor claims and completes', function () {
        [
            'application' => $application,
            'destination' => $destination,
            'server' => $server,
        ] = BlueGreenDeactivationScenario::context();
        $application->update([
            'health_check_enabled' => true,
            'ports_mappings' => null,
        ]);
        $application->settings()->update([
            'is_blue_green_deployment_enabled' => true,
            'is_container_label_readonly_enabled' => true,
        ]);
        $application = $application->fresh(['settings']);
        $state = BlueGreenDeactivationScenario::routeLessState($application, $destination, supersessionGeneration: 1);
        $state->update([
            'intervention_phase' => BlueGreenDeploymentPhase::ROLLING_BACK->value,
            'intervention_reason' => 'Rollback completed before its diagnostic marker was cleared.',
        ]);
        $oldOwner = BlueGreenDeactivationScenario::queuedDeployment(
            $application,
            $destination,
            'stale-idle-rollback-owner',
        );
        $oldOwner->update([
            'status' => ApplicationDeploymentStatus::FAILED->value,
            'blue_green_phase' => BlueGreenDeploymentPhase::IDLE->value,
            'blue_green_supersession_generation' => 1,
            'finished_at' => now()->subMinute(),
        ]);
        $successor = BlueGreenDeactivationScenario::queuedDeployment(
            $application,
            $destination,
            'ordinary-admission-after-stale-idle-diagnostics',
        );
        Event::fake([ApplicationConfigurationChanged::class]);
        Notification::fake();
        Process::fake(function ($process) {
            $payload = (is_array($process->command)
                ? implode(' ', $process->command)
                : (string) $process->command)
                ."\n".(string) $process->input;
            if (str_contains($payload, 'coolify-blue-green-destination-state-attested')) {
                return Process::result(output: 'coolify-blue-green-destination-state-attested');
            }
            if (str_contains($payload, '/proc/sys/kernel/random/boot_id')) {
                return Process::result(output: BlueGreenDeactivationScenario::BOOT_ID);
            }

            return Process::result(output: '[]');
        });

        expect($successor->claimForDispatchDetailed(bypassServerCapacity: true))
            ->toBe(DeploymentDispatchClaimResult::CLAIMED);
        $lifecycle = new BlueGreenDeploymentLifecycle(
            application: $application,
            deployment: $successor->fresh(),
            destination: $destination,
            server: $server,
            timeout: 30,
            checkForCancellation: static function (): void {},
        );
        try {
            $lifecycle->initialize();
            $claim = $lifecycle->claim();

            expect($claim?->deploymentUuid)->toBe($successor->deployment_uuid)
                ->and($state->fresh()->intervention_phase)->toBeNull()
                ->and($state->fresh()->intervention_reason)->toBeNull();

            $claim ??= throw new RuntimeException('The clean successor did not produce a blue-green claim.');
            $candidateContainerId = str_repeat('b', 64);
            $startedCandidateState = new BlueGreenProxyState(
                managedFilename: $claim->rollbackManagedFilename,
                applicationUuid: (string) $application->uuid,
                destinationId: $destination->id,
                operationId: $claim->deploymentUuid,
                mutationSequence: 1,
                destinationFenceEpoch: 0,
                routingRevision: $claim->expectedRoutingRevision,
                managedSha256: null,
                activeColor: null,
                activeDeploymentUuid: null,
                activeContainerName: null,
                activeContainerId: null,
                applicationRoutingConfigDigest: $claim->routingConfigDigest,
                destinationTopologyDigest: $claim->operationTopologyDigest,
            );
            RecordBlueGreenDestinationState::run($claim, null, $startedCandidateState);
            RecordBlueGreenCandidateIdentity::run($claim, new BlueGreenContainerInspection(
                exists: true,
                dockerId: $candidateContainerId,
                status: 'running',
                health: 'healthy',
            ));
            $activeConfiguration = CompileBlueGreenProxyConfiguration::run(
                $application,
                $destination,
                new BlueGreenRoutingTarget(
                    destinationId: $destination->id,
                    activeColor: $claim->pendingColor,
                    blueContainerName: $application->uuid.'-blue',
                    greenContainerName: $application->uuid.'-green',
                    port: $claim->backendPortInventory->ports()[0],
                    ports: $claim->backendPortInventory->ports(),
                    routingRevision: $claim->expectedRoutingRevision,
                    mode: BlueGreenRoutingMode::Steady,
                    publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($claim->deploymentUuid),
                    destinationFenceEpoch: $claim->destinationFenceEpoch,
                    operationId: $claim->deploymentUuid,
                    mutationSequence: 2,
                    activeDeploymentUuid: $claim->deploymentUuid,
                    activeContainerId: $candidateContainerId,
                    destinationTopologyDigest: $claim->operationTopologyDigest,
                ),
            );
            RecordBlueGreenDestinationState::run($claim, $startedCandidateState, $activeConfiguration->state);
            RecordBlueGreenRoutingMutation::run($claim, $activeConfiguration->state);
            TransitionsBlueGreenDeployment::markSwitching($claim);
            TransitionsBlueGreenDeployment::markDraining($claim, 1);
            CompleteBlueGreenDeploymentOperation::run($claim);
        } finally {
            $lifecycle->release();
        }

        (new ApplicationDeploymentJob($successor->id))->completeBlueGreenDrainRecovery();

        expect($oldOwner->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
            ->and($oldOwner->fresh()->finished_at)->not->toBeNull()
            ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
            ->and($state->fresh()->intervention_phase)->toBeNull()
            ->and($state->fresh()->intervention_reason)->toBeNull()
            ->and(ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state->fresh()))->toBeTrue()
            ->and($successor->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
            ->and($successor->fresh()->finished_at)->not->toBeNull();
    });

    test('fences post-cutoff dispatch claims while a live deactivation phase owns the destination', function (
        BlueGreenDeactivationPhase $phase,
    ) {
        $application = makeApplication($this->environment->id, $this->destination->id, 'abc1234');
        $cutoff = makeQueueAdmissionDeployment($application, $this->server, 'live-deactivation-cutoff', $this->destination);
        $deactivation = createPostCutoffQueueDeactivationFence(
            $application,
            $this->destination,
            $cutoff->id,
            $phase,
        );
        $candidate = makeQueueAdmissionDeployment($application, $this->server, 'live-deactivation-candidate', $this->destination);

        expect($deactivation->fences($candidate))->toBeFalse()
            ->and(FindBlueGreenDeactivationFence::run($candidate)?->getKey())->toBe($deactivation->getKey())
            ->and($candidate->claimForDispatchDetailed(bypassServerCapacity: true))
            ->toBe(DeploymentDispatchClaimResult::NOT_CLAIMED)
            ->and($candidate->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value);
    })->with([
        'deactivating' => [BlueGreenDeactivationPhase::DEACTIVATING],
        'stopping' => [BlueGreenDeactivationPhase::STOPPING],
        'removing' => [BlueGreenDeactivationPhase::REMOVING],
        'intervention required' => [BlueGreenDeactivationPhase::INTERVENTION_REQUIRED],
    ]);

    test('allows post-cutoff dispatch claims past terminal deactivation history', function (
        BlueGreenDeactivationPhase $phase,
    ) {
        $application = makeApplication($this->environment->id, $this->destination->id, 'abc1234');
        $cutoff = makeQueueAdmissionDeployment($application, $this->server, 'terminal-deactivation-cutoff', $this->destination);
        createPostCutoffQueueDeactivationFence($application, $this->destination, $cutoff->id, $phase);
        $candidate = makeQueueAdmissionDeployment($application, $this->server, 'terminal-deactivation-candidate', $this->destination);

        expect(FindBlueGreenDeactivationFence::run($candidate))->toBeNull()
            ->and($candidate->claimForDispatchDetailed(bypassServerCapacity: true))
            ->toBe(DeploymentDispatchClaimResult::CLAIMED)
            ->and($candidate->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    })->with([
        'completed history' => [BlueGreenDeactivationPhase::COMPLETED],
        'stopped history' => [BlueGreenDeactivationPhase::STOPPED],
    ]);

    test('defers the claim and registers convergence while durable state is not cleanly claimable', function () {
        Queue::fake();
        $application = makeApplication($this->environment->id, $this->destination->id, 'abc1234');
        $state = ApplicationBlueGreenDeployment::query()->create([
            'application_id' => $application->id,
            'standalone_docker_id' => $this->destination->id,
            'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
            'routing_revision' => 2,
            'supersession_generation' => 2,
        ]);
        $deployment = makeQueueAdmissionDeployment($application, $this->server, 'gate-deferred', $this->destination);

        $result = $deployment->claimForDispatchDetailed();

        expect($result)->toBe(DeploymentDispatchClaimResult::DEFERRED_FOR_BLUE_GREEN_CONVERGENCE)
            ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::QUEUED->value);
        Queue::assertPushed(
            ConvergeBlueGreenDeploymentJob::class,
            fn (ConvergeBlueGreenDeploymentJob $job): bool => $job->applicationDeploymentQueueId === $deployment->id
                && $job->applicationId === $application->id
                && $job->standaloneDockerId === $this->destination->id,
        );
    });

    test('claims through absent, clean IDLE, and clean STOPPED states but defers every busy phase', function () {
        Queue::fake();
        $application = makeApplication($this->environment->id, $this->destination->id, 'abc1234');
        $deployment = makeQueueAdmissionDeployment($application, $this->server, 'gate-absent', $this->destination);
        expect($deployment->claimForDispatchDetailed())->toBe(DeploymentDispatchClaimResult::CLAIMED);
        $deployment->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);

        $state = ApplicationBlueGreenDeployment::query()->create([
            'application_id' => $application->id,
            'standalone_docker_id' => $this->destination->id,
            'phase' => BlueGreenDeploymentPhase::IDLE,
            'routing_revision' => 2,
            'supersession_generation' => 2,
        ]);
        $idleClaim = makeQueueAdmissionDeployment($application, $this->server, 'gate-idle', $this->destination);
        expect($idleClaim->claimForDispatchDetailed())->toBe(DeploymentDispatchClaimResult::CLAIMED);
        $idleClaim->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);

        $state->update(['phase' => BlueGreenDeploymentPhase::STOPPED]);
        $stoppedClaim = makeQueueAdmissionDeployment($application, $this->server, 'gate-stopped', $this->destination);
        expect($stoppedClaim->claimForDispatchDetailed())->toBe(DeploymentDispatchClaimResult::CLAIMED);
        $stoppedClaim->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);

        foreach ([
            BlueGreenDeploymentPhase::PREPARING,
            BlueGreenDeploymentPhase::SWITCHING,
            BlueGreenDeploymentPhase::DRAINING,
            BlueGreenDeploymentPhase::ROLLING_BACK,
            BlueGreenDeploymentPhase::DEACTIVATING,
            BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        ] as $busyPhase) {
            $state->update(['phase' => $busyPhase]);
            $busyClaim = makeQueueAdmissionDeployment($application, $this->server, 'gate-'.$busyPhase->value, $this->destination);
            expect($busyClaim->claimForDispatchDetailed())
                ->toBe(DeploymentDispatchClaimResult::DEFERRED_FOR_BLUE_GREEN_CONVERGENCE, "phase {$busyPhase->value} must defer");
            $busyClaim->delete();
        }

        $state->update(['phase' => BlueGreenDeploymentPhase::IDLE, 'pending_deployment_uuid' => 'residual-pending']);
        $dirtyIdleClaim = makeQueueAdmissionDeployment($application, $this->server, 'gate-dirty-idle', $this->destination);
        expect($dirtyIdleClaim->claimForDispatchDetailed())->toBe(DeploymentDispatchClaimResult::DEFERRED_FOR_BLUE_GREEN_CONVERGENCE);
    });
});

describe('newest-wins admission groups', function () {
    test('the newest queued standalone row supersedes older ones before dispatch', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, 'abc1234');
        $older = makeQueueAdmissionDeployment($application, $this->server, 'newest-wins-older', $this->destination);
        $newer = makeQueueAdmissionDeployment($application, $this->server, 'newest-wins-newer', $this->destination);

        expect($newer->claimForDispatchDetailed())->toBe(DeploymentDispatchClaimResult::CLAIMED);

        $older->refresh();
        expect($older->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
            ->and($older->finished_at)->not->toBeNull()
            ->and((string) $older->logs)->toContain('Superseded before dispatch by deployment newest-wins-newer')
            ->and((string) $older->logs)->toContain('no candidate, container, or route mutation occurred');
    });

    test('an older queued row is cancelled instead of claiming when a newer one waits', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, 'abc1234');
        $older = makeQueueAdmissionDeployment($application, $this->server, 'older-loses', $this->destination);
        makeQueueAdmissionDeployment($application, $this->server, 'newer-waits', $this->destination);

        expect($older->claimForDispatchDetailed())->toBe(DeploymentDispatchClaimResult::NOT_CLAIMED)
            ->and($older->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value);
    });

    test('superseded queued fleet children pause their finished fleet owner without rewriting its terminal status', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, 'abc1234');
        $owner = ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'application_name' => $application->name,
            'server_id' => $this->server->id,
            'server_name' => $this->server->name,
            'destination_id' => $this->destination->id,
            'deployment_uuid' => 'fleet-owner-root',
            'commit' => 'fleet-commit',
            'pull_request_id' => 0,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
            'blue_green_fleet_deployment_uuid' => 'fleet-owner-root',
            'blue_green_fleet_status' => BlueGreenFleetStatus::ACTIVE,
        ]);
        $child = ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'application_name' => $application->name,
            'server_id' => $this->server->id,
            'server_name' => $this->server->name,
            'destination_id' => $this->destination->id,
            'deployment_uuid' => 'fleet-child',
            'commit' => 'fleet-commit',
            'pull_request_id' => 0,
            'status' => ApplicationDeploymentStatus::QUEUED->value,
            'blue_green_fleet_deployment_uuid' => 'fleet-owner-root',
        ]);
        $newer = makeQueueAdmissionDeployment($application, $this->server, 'newer-standalone', $this->destination);

        expect($newer->claimForDispatchDetailed())->toBe(DeploymentDispatchClaimResult::CLAIMED);

        expect($child->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value)
            ->and($owner->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
            ->and($owner->fresh()->blue_green_fleet_status)->toBe(BlueGreenFleetStatus::PAUSED);
    });
});
