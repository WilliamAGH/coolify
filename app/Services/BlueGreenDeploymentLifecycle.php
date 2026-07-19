<?php

namespace App\Services;

use App\Actions\Application\BlueGreen\AttestBlueGreenDestinationState;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenDestinationStateRecordingException;
use App\Actions\Application\BlueGreen\BlueGreenLegacyProviderState;
use App\Actions\Application\BlueGreen\BlueGreenLegacyRoutingSnapshot;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\BlueGreenOperationFenceLostException;
use App\Actions\Application\BlueGreen\CaptureBlueGreenLegacyRouting;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\CompleteBlueGreenDeploymentOperation;
use App\Actions\Application\BlueGreen\DrainBlueGreenPreviousContainer;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDestinationMutation;
use App\Actions\Application\BlueGreen\FindBlueGreenDeactivationFence;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\PlanBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\ReadBlueGreenServerBootIdentity;
use App\Actions\Application\BlueGreen\RecordBlueGreenCandidateIdentity;
use App\Actions\Application\BlueGreen\RecordBlueGreenDestinationState;
use App\Actions\Application\BlueGreen\RecordBlueGreenDrainObservation;
use App\Actions\Application\BlueGreen\RecordBlueGreenLegacyRoutingSnapshot;
use App\Actions\Application\BlueGreen\RecordBlueGreenRollbackKey;
use App\Actions\Application\BlueGreen\RecordBlueGreenRoutingMutation;
use App\Actions\Application\BlueGreen\RemoveBlueGreenInactiveContainer;
use App\Actions\Application\BlueGreen\RemoveExactBlueGreenCandidate;
use App\Actions\Application\BlueGreen\TransitionsBlueGreenDeployment;
use App\Actions\Application\BlueGreen\VerifyBlueGreenCandidateReleaseProof;
use App\Actions\Application\BlueGreen\VerifyBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\WaitForBlueGreenLegacyDockerRouting;
use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactCommitter;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactReader;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactRestorer;
use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Exceptions\DeploymentException;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Support\ValidationPatterns;
use Closure;
use Illuminate\Cache\Lock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Throwable;

final class BlueGreenDeploymentLifecycle
{
    private bool $enabled = false;

    private ?BlueGreenDeploymentClaim $claim = null;

    private ?BlueGreenDeploymentColor $previousActiveColor = null;

    private ?string $legacyContainerName = null;

    private ?BlueGreenProxyRollbackKey $rollbackKey = null;

    private ?BlueGreenProxyRollbackKey $latestRoutingMutationKey = null;

    private ?BlueGreenProxyState $destinationState = null;

    private ?BlueGreenProxyState $pendingDestinationState = null;

    private ?BlueGreenContainerExpectation $previousContainerExpectation = null;

    private ?BlueGreenContainerExpectation $candidateContainerExpectation = null;

    private ?BlueGreenLegacyRoutingSnapshot $legacyRoutingSnapshot = null;

    private ?Lock $lifecycleLock = null;

    private ?BlueGreenOperationFence $operationFence = null;

    private ?string $serverBootId = null;

    private bool $proxyChanged = false;

    private bool $finalized = false;

    private bool $drainingRecovery = false;

    private bool $completedDrainingRecovery = false;

    private bool $promotionCommitted = false;

    private bool $interventionRequired = false;

    private bool $rollbackCompleted = false;

    /** @param Closure(): void $checkForCancellation */
    public function __construct(
        private readonly Application $application,
        private readonly ApplicationDeploymentQueue $deployment,
        private readonly StandaloneDocker $destination,
        private readonly Server $server,
        private readonly int $timeout,
        private readonly Closure $checkForCancellation,
    ) {}

    public function initialize(): void
    {
        $this->assertNotFencedByDeactivation();
        $durableState = ApplicationBlueGreenDeployment::query()
            ->where('application_id', $this->application->id)
            ->where('standalone_docker_id', $this->destination->id)
            ->first();
        if ($durableState?->phase === BlueGreenDeploymentPhase::DEACTIVATING) {
            throw new DeploymentException('The queued deployment is fenced by a blue-green deactivation in progress.');
        }
        if (! $this->application->isBlueGreenDeploymentOptedIn() && $durableState === null) {
            return;
        }

        $this->enabled = true;
        if ($durableState?->phase === BlueGreenDeploymentPhase::DRAINING) {
            $this->acquireLifecycleLock();
            $this->assertNotFencedByDeactivation();
            $this->initializeDrainingRecovery($durableState);

            return;
        }
        if ($durableState?->phase === BlueGreenDeploymentPhase::IDLE
            && $this->isExactCompletedDrainingRecovery($durableState)) {
            $this->completedDrainingRecovery = true;

            return;
        }
        if ($durableState !== null
            && ($durableState->phase !== BlueGreenDeploymentPhase::IDLE
                || $durableState->operation_deployment_uuid !== null)) {
            throw new DeploymentException('An unfinished blue-green lifecycle must be reconciled before another deployment can mutate this destination.');
        }
        $this->assertEligibility();
        $this->serverBootId = ReadBlueGreenServerBootIdentity::run($this->server);
        $this->destinationState = AttestBlueGreenDestinationState::run(
            $this->server,
            $this->application,
            $this->destination,
            $durableState,
        );

        $containers = getCurrentApplicationContainerStatus(
            $this->server,
            $this->application->id,
            pullRequestId: 0,
        );
        $this->previousActiveColor = $durableState?->active_color;
        $this->legacyContainerName = $this->detectLegacyContainer($durableState, $containers);
        $this->captureHealthyPreviousContainer($durableState);

        $this->deployment->addLogEntry(
            $durableState === null
                ? 'Blue-green deployment enabled. Preparing first adoption without rolling fallback.'
                : 'Durable blue-green deployment state found. Preparing the inactive color without rolling fallback.'
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isFinalized(): bool
    {
        return $this->finalized;
    }

    public function isDrainingRecovery(): bool
    {
        return $this->drainingRecovery;
    }

    public function isCompletedDrainingRecovery(): bool
    {
        return $this->completedDrainingRecovery;
    }

    public function isRetryableDrainTimeout(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (str_contains($current->getMessage(), 'durable DRAINING state is retained for retry.')
                || preg_match(DrainBlueGreenPreviousContainer::TIMEOUT_CONNECTIONS_PATTERN, $current->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }

    public function previousContainerName(): ?string
    {
        if ($this->previousActiveColor !== null) {
            return $this->containerName($this->previousActiveColor);
        }

        return $this->legacyContainerName;
    }

    public function claim(): ?BlueGreenDeploymentClaim
    {
        if (! $this->enabled) {
            return null;
        }
        if ($this->claim !== null) {
            return $this->claim;
        }

        ($this->checkForCancellation)();
        $this->assertNotFencedByDeactivation();
        $this->acquireLifecycleLock();
        $this->assertNotFencedByDeactivation();
        $this->assertEligibility();
        $serverBootId = $this->serverBootId
            ?? throw new DeploymentException('Blue-green deployment has no captured server boot identity.');
        ReadBlueGreenServerBootIdentity::run($this->server, $serverBootId);

        $this->claim = ClaimBlueGreenDeployment::run(
            application: $this->application,
            standaloneDocker: $this->destination,
            deployment: $this->deployment,
            serverBootId: $serverBootId,
            detectedLegacyContainerName: $this->legacyContainerName,
            previousContainer: $this->previousContainerExpectation,
        );
        $candidateContainerName = $this->claim->candidateContainerName
            ?? throw new DeploymentException('The blue-green claim has no durable candidate container identity.');
        $this->candidateContainerExpectation = new BlueGreenContainerExpectation(
            name: $candidateContainerName,
            dockerId: null,
            applicationId: $this->application->id,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $this->claim->deploymentUuid,
            color: $this->claim->pendingColor,
            routingRevision: $this->claim->expectedRoutingRevision,
        );
        $this->deployment->refresh();
        $this->deployment->addLogEntry(
            "Claimed the {$this->claim->pendingColor->value} slot at routing revision {$this->claim->expectedRoutingRevision}."
        );

        return $this->claim;
    }

    /**
     * @param  Closure(): void  $prepareCandidateStart
     * @param  Closure(): non-empty-list<string>  $startCandidate
     */
    public function promote(Closure $prepareCandidateStart, Closure $startCandidate): void
    {
        try {
            $claim = $this->claim
                ?? throw new DeploymentException('Blue-green deployment reached container start without a durable claim.');
            $this->assertOperationOwned(BlueGreenDeploymentPhase::PREPARING);
            $prepareCandidateStart();
            $this->deployment->addLogEntry('----------------------------------------');
            $this->deployment->addLogEntry('Blue-green deployment started. Replacing only the inactive slot.');
            $this->replaceCandidateContainer();
            $this->assertOperationOwned(BlueGreenDeploymentPhase::PREPARING);
            $candidateStartCommands = $startCandidate();
            if (! is_array($candidateStartCommands) || ! array_is_list($candidateStartCommands) || $candidateStartCommands === []) {
                throw new DeploymentException('Blue-green candidate start must return its exact remote command list for destination fencing.');
            }
            $candidateExpectation = $this->candidateContainerExpectation
                ?? throw new DeploymentException('Blue-green candidate start has no durable container expectation.');
            $this->destinationState = $this->executeDestinationMutation(
                $candidateStartCommands,
                (new InspectBlueGreenContainer)->runningMutationCompletionAssertionsFor($candidateExpectation),
            );
            $this->waitForExactCandidateHealth();
            $this->promoteCandidate($claim);
            $this->deployment->addLogEntry('Blue-green deployment completed with verified routing.');
        } catch (Throwable $exception) {
            throw new DeploymentException('Blue-green update failed ('.get_class($exception).'): '.$exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    public function complete(): void
    {
        $claim = $this->claim
            ?? throw new DeploymentException('Blue-green deployment completion has no durable operation claim.');
        if ($this->application->blueGreenConfiguredStandaloneDockerDestinationIds()->count() !== 1
            || $this->application->additional_networks()->exists()) {
            throw new DeploymentException('Blue-green deployment completion requires exactly one configured destination.');
        }
        $this->assertOperationOwned(BlueGreenDeploymentPhase::DRAINING);
        CompleteBlueGreenDeploymentOperation::run($claim);
        $this->application->update(['status' => 'running:healthy']);
        $this->promotionCommitted = true;
        $this->deployment->refresh();
    }

    public function retirePreviousContainer(): void
    {
        $expectation = $this->previousContainerExpectation;
        if ($expectation === null) {
            return;
        }
        $claim = $this->claim
            ?? throw new DeploymentException('Previous-container retirement has no durable operation claim.');
        $this->assertOperationOwned(BlueGreenDeploymentPhase::DRAINING);
        $inspection = InspectBlueGreenContainer::run($this->server, $expectation);
        if (! $inspection->exists) {
            if (! $expectation->blueGreenManaged) {
                $this->normalizeLegacyRetirement($claim, $expectation, false);
            }

            return;
        }
        if ($inspection->dockerId !== $expectation->dockerId) {
            throw new DeploymentException('The previous Docker identity changed before destination-fenced retirement.');
        }
        $port = $this->application->blueGreenDeploymentBackendPort()
            ?? throw new DeploymentException('The blue-green backend port became ambiguous before previous-container drain.');
        $stopTimeout = $this->application->settings->deploymentStopGracePeriodSeconds();
        $drainer = new DrainBlueGreenPreviousContainer;
        $drainState = (new RecordBlueGreenDrainObservation)->deadlineFor($claim);
        $activeConnections = $drainer->activeConnections($this->server, $expectation, $port);
        $drainState = (new RecordBlueGreenDrainObservation)->record($claim, $activeConnections);
        $drainDeadline = $drainState->operation_drain_deadline_at
            ?? throw new DeploymentException('The blue-green drain has no durable deadline.');
        $this->deployment->addLogEntry(
            "Blue-green previous container {$expectation->name} is draining {$activeConnections} active backend connection(s) before retirement.",
        );
        try {
            $this->destinationState = $this->executeDestinationMutation(
                $drainer->commandsFor(
                    $expectation,
                    $port,
                    $drainDeadline->getTimestamp(),
                    $stopTimeout,
                    $activeConnections === 0,
                ),
                $drainer->completionAssertionsFor($expectation),
            );
            (new RecordBlueGreenDrainObservation)->record($claim, 0);
        } catch (BlueGreenDestinationStateRecordingException) {
            $this->reconcilePendingDestinationState();
            (new RecordBlueGreenDrainObservation)->record($claim, 0);
        } catch (Throwable $exception) {
            if (str_contains($exception->getMessage(), DrainBlueGreenPreviousContainer::TIMEOUT_MARKER)) {
                if (preg_match(DrainBlueGreenPreviousContainer::TIMEOUT_CONNECTIONS_PATTERN, $exception->getMessage(), $matches) === 1) {
                    (new RecordBlueGreenDrainObservation)->record($claim, (int) $matches['connections']);
                }
                $this->deployment->addLogEntry(
                    'Blue-green drain deadline elapsed; durable DRAINING state retained with the latest connection observation for a safe retry.',
                    'stderr',
                );
                throw new DeploymentException(
                    'Blue-green previous-container drain timed out; durable DRAINING state is retained for retry.',
                    previous: $exception,
                );
            }

            throw $exception;
        }

        if (! $expectation->blueGreenManaged) {
            $this->normalizeLegacyRetirement($claim, $expectation, true);
        }
    }

    private function normalizeLegacyRetirement(
        BlueGreenDeploymentClaim $claim,
        BlueGreenContainerExpectation $expectation,
        bool $removeContainer,
    ): void {
        $this->writeAndVerifyRouting(
            $this->routingTarget(
                activeColor: $claim->pendingColor,
                mode: BlueGreenRoutingMode::Steady,
                publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($claim->deploymentUuid),
            ),
            recordRoutingMutation: false,
            expectedPhase: BlueGreenDeploymentPhase::DRAINING,
        );
        if (! $removeContainer) {
            return;
        }

        $containerId = escapeshellarg($expectation->dockerId);
        $this->destinationState = $this->executeDestinationMutation(
            [
                ...(new InspectBlueGreenContainer)->exactMutationAssertionsFor($expectation),
                "docker rm -f {$containerId} >/dev/null; ! docker container inspect {$containerId} >/dev/null 2>&1",
            ],
            (new InspectBlueGreenContainer)->absentMutationCompletionAssertionsFor($expectation),
        );
        $this->deployment->addLogEntry(
            "Blue-green legacy container {$expectation->name} was removed after the canonical steady route was verified.",
        );
    }

    public function resumeDrainingOperation(): void
    {
        if (! $this->drainingRecovery) {
            throw new DeploymentException('Blue-green drain recovery was not initialized from an exact durable DRAINING operation.');
        }

        $this->assertOperationOwned(BlueGreenDeploymentPhase::DRAINING);
        $this->assertExactCandidateStillHealthy();
        $this->assertCandidateReleaseProof();
        $this->retirePreviousContainer();
        $this->complete();
    }

    public function requireDrainingRecoveryIntervention(): void
    {
        if (! $this->drainingRecovery || $this->claim === null) {
            throw new DeploymentException('Blue-green intervention requires an exact durable DRAINING recovery claim.');
        }

        $this->assertOperationOwned(BlueGreenDeploymentPhase::DRAINING);
        TransitionsBlueGreenDeployment::markInterventionRequired($this->claim);
        $this->interventionRequired = true;
    }

    public function release(): void
    {
        $this->operationFence?->releaseIfOwned();
        $this->operationFence = null;
        $this->lifecycleLock = null;
    }

    private function acquireLifecycleLock(): void
    {
        $leaseSeconds = BlueGreenDeploymentLock::deploymentLeaseSeconds(
            max($this->timeout, (int) config('constants.ssh.command_timeout')),
            $this->application->settings->deploymentStopGracePeriodSeconds(),
        );
        $this->lifecycleLock = Cache::lock(
            BlueGreenDeploymentLock::key($this->application->id, $this->destination->id),
            $leaseSeconds,
        );
        if (! $this->lifecycleLock->get()) {
            $this->lifecycleLock = null;
            throw new DeploymentException('Another deployment or reconciler owns this application destination blue-green lifecycle.');
        }
        $this->operationFence = new BlueGreenOperationFence($this->lifecycleLock, $leaseSeconds);
    }

    private function assertNotFencedByDeactivation(): void
    {
        if (FindBlueGreenDeactivationFence::run($this->deployment) === null) {
            return;
        }
        throw new DeploymentException('The queued deployment is fenced by a completed or in-progress application deactivation.');
    }

    private function assertEligibility(): void
    {
        if ((int) $this->deployment->destination_id !== $this->destination->id
            || (int) $this->deployment->server_id !== $this->destination->server_id) {
            throw new DeploymentException('The queued blue-green destination does not match the resolved standalone Docker destination and server.');
        }

        $applicationForEligibility = clone $this->application;
        $applicationForEligibility->setRelation('destination', $this->destination);
        $ineligibilityReason = $applicationForEligibility->blueGreenDeploymentIneligibilityReason();
        if ($ineligibilityReason !== null) {
            throw new DeploymentException("Blue-green deployment is no longer eligible: {$ineligibilityReason} Rolling fallback is forbidden while opt-in or durable state exists.");
        }
    }

    private function isExactCompletedDrainingRecovery(ApplicationBlueGreenDeployment $state): bool
    {
        $deploymentColumn = match ($state->active_color) {
            BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
            BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
            null => null,
        };
        if ($deploymentColumn === null
            || $this->deployment->status !== ApplicationDeploymentStatus::IN_PROGRESS->value
            || $state->phase !== BlueGreenDeploymentPhase::IDLE
            || $state->legacy_container_name !== null
            || $state->{$deploymentColumn} !== $this->deployment->deployment_uuid
            || $this->deployment->blue_green_phase !== BlueGreenDeploymentPhase::IDLE
            || $this->deployment->blue_green_color !== $state->active_color
            || $this->deployment->blue_green_routing_revision !== $state->routing_revision
            || $this->deployment->blue_green_destination_fence_epoch !== $state->destination_fence_epoch
            || $this->deployment->blue_green_topology_digest !== $state->destination_topology_digest
            || $this->deployment->blue_green_routing_config_digest !== $state->application_routing_config_digest) {
            return false;
        }
        foreach (ApplicationBlueGreenDeployment::clearedOperationAttributes() as $attribute => $_) {
            if ($state->{$attribute} !== null) {
                return false;
            }
        }

        return true;
    }

    private function initializeDrainingRecovery(ApplicationBlueGreenDeployment $state): void
    {
        if ($state->operation_deployment_uuid !== $this->deployment->deployment_uuid) {
            throw new DeploymentException('An unfinished blue-green drain belongs to a different deployment and cannot be resumed by this queue entry.');
        }
        if ((int) $this->deployment->destination_id !== $this->destination->id
            || (int) $this->deployment->server_id !== $this->server->id
            || $this->deployment->pull_request_id !== 0) {
            throw new DeploymentException('The queued drain recovery does not match the durable standalone Docker destination.');
        }
        if ($this->application->blueGreenConfiguredStandaloneDockerDestinationIds()->count() !== 1
            || $this->application->additional_networks()->exists()) {
            throw new DeploymentException('Blue-green drain recovery requires exactly one configured destination.');
        }

        $this->claim = $this->claimFromDrainingState($state);
        $this->previousActiveColor = $this->claim->previousActiveColor;
        $this->legacyContainerName = $this->claim->legacyContainerName;
        $this->serverBootId = $this->claim->serverBootId;
        $this->server->privateKey->storeInFileSystem();
        ReadBlueGreenServerBootIdentity::run($this->server, $this->claim->serverBootId);
        $this->destinationState = AttestBlueGreenDestinationState::run(
            $this->server,
            $this->application,
            $this->destination,
            $state,
        );
        $this->candidateContainerExpectation = $this->candidateExpectationFromDrainingState($state, $this->claim);
        $this->previousContainerExpectation = $this->previousExpectationFromDrainingState($state, $this->claim);
        $this->assertOperationOwned(BlueGreenDeploymentPhase::DRAINING);
        $this->assertExactCandidateStillHealthy();
        $this->assertCandidateReleaseProof();
        $this->finalized = true;
        $this->drainingRecovery = true;
        $this->deployment->addLogEntry(
            'Resuming the exact durable blue-green DRAINING operation; no candidate build or routing mutation will run.',
        );
    }

    private function claimFromDrainingState(ApplicationBlueGreenDeployment $state): BlueGreenDeploymentClaim
    {
        $pendingColor = $state->active_color;
        $deploymentUuid = $state->operation_deployment_uuid;
        $destinationFenceEpoch = $state->operation_destination_fence_epoch;
        $serverBootId = $state->operation_server_boot_id;
        $topologyDigest = $state->operation_topology_digest;
        $routingConfigDigest = $state->operation_routing_config_digest;
        $supersessionGeneration = $state->supersession_generation;
        $candidateContainerName = $state->operation_candidate_container_name;
        $rollbackManagedFilename = $state->operation_rollback_managed_filename;
        if ($state->pending_color !== null
            || $state->pending_deployment_uuid !== null
            || $pendingColor === null
            || ! is_string($deploymentUuid)
            || $deploymentUuid === ''
            || ! is_int($destinationFenceEpoch)
            || ! is_string($serverBootId)
            || ! is_string($topologyDigest)
            || ! is_string($routingConfigDigest)
            || ! is_int($supersessionGeneration)
            || $supersessionGeneration < 1
            || ! is_string($candidateContainerName)
            || ! is_string($rollbackManagedFilename)
            || $state->operation_drain_started_at === null
            || $state->operation_drain_deadline_at === null) {
            throw new DeploymentException('The durable blue-green DRAINING state has incomplete claim or deadline provenance.');
        }

        return new BlueGreenDeploymentClaim(
            stateId: $state->id,
            applicationId: $this->application->id,
            standaloneDockerId: $this->destination->id,
            pendingColor: $pendingColor,
            previousActiveColor: $state->operation_previous_active_color,
            deploymentUuid: $deploymentUuid,
            expectedRoutingRevision: $state->routing_revision,
            destinationFenceEpoch: $destinationFenceEpoch,
            serverBootId: $serverBootId,
            topologyDigest: $topologyDigest,
            routingConfigDigest: $routingConfigDigest,
            supersessionGeneration: $supersessionGeneration,
            legacyContainerName: $state->legacy_container_name,
            candidateContainerName: $candidateContainerName,
            rollbackManagedFilename: $rollbackManagedFilename,
        );
    }

    private function candidateExpectationFromDrainingState(
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentClaim $claim,
    ): BlueGreenContainerExpectation {
        $candidateContainerId = $state->operation_candidate_container_id;
        if (! is_string($candidateContainerId) || $candidateContainerId === '') {
            throw new DeploymentException('The durable blue-green DRAINING state has no exact candidate Docker identity.');
        }

        return new BlueGreenContainerExpectation(
            name: $claim->candidateContainerName
                ?? throw new DeploymentException('The durable blue-green DRAINING claim has no candidate container name.'),
            dockerId: $candidateContainerId,
            applicationId: $claim->applicationId,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $claim->deploymentUuid,
            color: $claim->pendingColor,
            routingRevision: $claim->expectedRoutingRevision,
        );
    }

    private function previousExpectationFromDrainingState(
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentClaim $claim,
    ): ?BlueGreenContainerExpectation {
        $name = $state->operation_previous_container_name;
        $dockerId = $state->operation_previous_container_id;
        if ($name === null && $dockerId === null) {
            if ($claim->previousActiveColor !== null || $claim->legacyContainerName !== null) {
                throw new DeploymentException('The durable blue-green DRAINING state lost its required previous container identity.');
            }

            return null;
        }
        if (! is_string($name) || $name === '' || ! is_string($dockerId) || $dockerId === '') {
            throw new DeploymentException('The durable blue-green DRAINING state has a partial previous container identity.');
        }

        if ($claim->previousActiveColor === null) {
            if ($claim->legacyContainerName === null
                || $name !== $claim->legacyContainerName
                || $state->operation_previous_deployment_uuid !== null
                || $state->operation_previous_routing_revision !== null) {
                throw new DeploymentException('The durable blue-green DRAINING legacy previous container provenance is invalid.');
            }

            return new BlueGreenContainerExpectation(
                name: $name,
                dockerId: $dockerId,
                applicationId: $claim->applicationId,
                pullRequestId: 0,
                blueGreenManaged: false,
            );
        }

        $previousDeploymentUuid = $state->operation_previous_deployment_uuid;
        $previousRoutingRevision = $state->operation_previous_routing_revision;
        if ($name !== $this->containerName($claim->previousActiveColor)
            || ! is_string($previousDeploymentUuid)
            || $previousDeploymentUuid === ''
            || ! is_int($previousRoutingRevision)) {
            throw new DeploymentException('The durable blue-green DRAINING fixed-color previous container provenance is invalid.');
        }

        return new BlueGreenContainerExpectation(
            name: $name,
            dockerId: $dockerId,
            applicationId: $claim->applicationId,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $previousDeploymentUuid,
            color: $claim->previousActiveColor,
            routingRevision: $previousRoutingRevision,
        );
    }

    private function detectLegacyContainer(
        ?ApplicationBlueGreenDeployment $durableState,
        Collection $containers,
    ): ?string {
        $blueName = $this->containerName(BlueGreenDeploymentColor::BLUE);
        $greenName = $this->containerName(BlueGreenDeploymentColor::GREEN);
        $fixedNames = [$blueName, $greenName];
        $fixedContainers = $containers->filter(
            fn (array $container): bool => in_array(data_get($container, 'Names'), $fixedNames, true),
        );
        $runningLegacyContainers = $containers->filter(
            fn (array $container): bool => data_get($container, 'State') === 'running'
                && ! in_array(data_get($container, 'Names'), $fixedNames, true),
        );

        if ($durableState?->active_color !== null) {
            if ($durableState->legacy_container_name !== null) {
                throw new DeploymentException('Durable blue-green state has both an active color and an uncleared legacy container. Reconciliation is required before another deployment.');
            }
            $activeName = $this->containerName($durableState->active_color);
            $activeContainer = $fixedContainers->first(
                fn (array $container): bool => data_get($container, 'Names') === $activeName,
            );
            if ($activeContainer === null || data_get($activeContainer, 'State') !== 'running') {
                throw new DeploymentException("Durable blue-green active container {$activeName} is not running. Refusing an unsafe promotion.");
            }
            if ($runningLegacyContainers->isNotEmpty()) {
                throw new DeploymentException('Unexpected running legacy application containers exist beside the durable active color. Reconciliation is required.');
            }

            return null;
        }

        if ($fixedContainers->isNotEmpty()) {
            throw new DeploymentException('Fixed blue-green containers exist without a durable active color. Refusing to infer ownership.');
        }
        if ($runningLegacyContainers->count() > 1) {
            throw new DeploymentException('More than one running legacy application container was detected. First blue-green adoption requires one unambiguous previous route.');
        }

        $detectedLegacyName = data_get($runningLegacyContainers->first(), 'Names');
        $durableLegacyName = $durableState?->legacy_container_name;
        if ($durableLegacyName !== null && $durableLegacyName !== $detectedLegacyName) {
            throw new DeploymentException('The durable legacy container does not match the only running legacy application container.');
        }
        if ($detectedLegacyName !== null) {
            $this->validateContainerName($detectedLegacyName);
        }

        return $durableLegacyName ?? $detectedLegacyName;
    }

    private function containerName(BlueGreenDeploymentColor $color): string
    {
        return $this->validateContainerName("{$this->application->uuid}-{$color->value}");
    }

    private function validateContainerName(string $value): string
    {
        if (! preg_match(ValidationPatterns::CONTAINER_NAME_PATTERN, $value)) {
            throw new \RuntimeException('Invalid container name: contains forbidden characters.');
        }

        return $value;
    }

    private function captureHealthyPreviousContainer(?ApplicationBlueGreenDeployment $state): void
    {
        $containerName = $this->previousContainerName();
        if ($containerName === null) {
            return;
        }
        if ($state?->active_color === null) {
            $expectation = new BlueGreenContainerExpectation(
                name: $containerName,
                dockerId: null,
                applicationId: $this->application->id,
                pullRequestId: 0,
                blueGreenManaged: false,
            );
        } else {
            $deploymentColumn = match ($state->active_color) {
                BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
                BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
            };
            $previousDeploymentUuid = $state->{$deploymentColumn};
            $previousDeployment = is_string($previousDeploymentUuid)
                ? ApplicationDeploymentQueue::query()
                    ->where('application_id', $this->application->id)
                    ->where('deployment_uuid', $previousDeploymentUuid)
                    ->first()
                : null;
            if ($previousDeployment === null
                || (int) $previousDeployment->destination_id !== $this->destination->id
                || (int) $previousDeployment->server_id !== $this->server->id
                || $previousDeployment->pull_request_id !== 0
                || $previousDeployment->blue_green_color !== $state->active_color
                || $previousDeployment->blue_green_routing_revision === null) {
                throw new DeploymentException('The durable previous color has no exact queue deployment provenance.');
            }
            $expectation = new BlueGreenContainerExpectation(
                name: $containerName,
                dockerId: null,
                applicationId: $this->application->id,
                pullRequestId: 0,
                blueGreenManaged: true,
                deploymentUuid: $previousDeployment->deployment_uuid,
                color: $state->active_color,
                routingRevision: $previousDeployment->blue_green_routing_revision,
            );
        }

        $inspection = InspectBlueGreenContainer::run($this->server, $expectation);
        if (! $inspection->exists
            || $inspection->dockerId === null
            || $inspection->status !== 'running'
            || $inspection->health !== 'healthy') {
            throw new DeploymentException("Previous blue-green container {$containerName} is not an exact running, healthy rollback target.");
        }
        $this->previousContainerExpectation = $expectation->withDockerId($inspection->dockerId);
    }

    private function assertExactPreviousContainerHealthy(): void
    {
        $expectation = $this->previousContainerExpectation;
        if ($expectation === null) {
            return;
        }
        $inspection = InspectBlueGreenContainer::run($this->server, $expectation);
        if (! $inspection->exists
            || $inspection->dockerId !== $expectation->dockerId
            || $inspection->status !== 'running'
            || $inspection->health !== 'healthy') {
            throw new DeploymentException("Previous blue-green container {$expectation->name} is no longer the exact running, healthy rollback target.");
        }
    }

    private function waitForExactPreviousContainerHealth(): void
    {
        $attempts = max(10, (int) $this->application->health_check_retries);
        $lastFailure = 'The previous container did not expose Docker health.';
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $this->assertOperationOwned(BlueGreenDeploymentPhase::ROLLING_BACK);
                $this->assertExactPreviousContainerHealthy();

                return;
            } catch (Throwable $exception) {
                $lastFailure = $exception->getMessage();
            }
            if ($attempt < $attempts) {
                Sleep::for(max(1, (int) $this->application->health_check_interval))->seconds();
            }
        }

        throw new DeploymentException("The previous blue-green rollback target did not recover health: {$lastFailure}");
    }

    private function replaceCandidateContainer(): void
    {
        $claim = $this->claim
            ?? throw new DeploymentException('Cannot replace a blue-green slot without a durable claim.');
        if ($claim->previousActiveColor === $claim->pendingColor) {
            throw new DeploymentException('The claimed blue-green candidate is the currently active color.');
        }

        $this->assertOperationOwned(BlueGreenDeploymentPhase::PREPARING);
        try {
            $this->destinationState = RemoveBlueGreenInactiveContainer::run(
                $this->server,
                $this->application,
                $this->destination,
                $claim,
                $this->destinationState,
            );
        } catch (BlueGreenDestinationStateRecordingException $exception) {
            $this->pendingDestinationState = $exception->replacementState;
            throw $exception;
        }
    }

    private function waitForExactCandidateHealth(): void
    {
        $expectation = $this->candidateContainerExpectation
            ?? throw new DeploymentException('The blue-green candidate has no durable label expectation.');
        $claim = $this->claim
            ?? throw new DeploymentException('The blue-green candidate has no durable deployment claim.');
        $startPeriod = max(0, (int) $this->application->health_check_start_period);
        for ($elapsed = 0; $elapsed < $startPeriod; $elapsed++) {
            ($this->checkForCancellation)();
            Sleep::for(1)->seconds();
        }

        $attempts = max(1, (int) $this->application->health_check_retries);
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            ($this->checkForCancellation)();
            $this->assertOperationOwned(BlueGreenDeploymentPhase::PREPARING);
            $inspection = InspectBlueGreenContainer::run($this->server, $expectation);
            if (! $inspection->exists || $inspection->dockerId === null) {
                throw new DeploymentException('The exact blue-green candidate is missing during its health gate.');
            }
            if ($expectation->dockerId === null) {
                $expectation = $expectation->withDockerId($inspection->dockerId);
                $this->assertOperationOwned(BlueGreenDeploymentPhase::PREPARING);
                RecordBlueGreenCandidateIdentity::run($claim, $inspection);
                $this->candidateContainerExpectation = $expectation;
            } elseif ($inspection->dockerId !== $expectation->dockerId) {
                throw new DeploymentException('The blue-green candidate Docker identity changed during its health gate.');
            }
            $health = $inspection->health;
            $this->deployment->addLogEntry("Blue-green candidate health attempt {$attempt} of {$attempts}: {$health}.");
            if ($inspection->status === 'running' && $health === 'healthy') {
                $this->assertCandidateReleaseProof();

                return;
            }
            if ($inspection->status !== 'running' || $health === 'unhealthy' || $health === 'missing') {
                throw new DeploymentException("The exact blue-green candidate Docker state is {$inspection->status}/{$health}.");
            }
            if ($attempt < $attempts) {
                Sleep::for(max(1, (int) $this->application->health_check_interval))->seconds();
            }
        }

        throw new DeploymentException('The exact blue-green candidate did not become healthy before the health gate expired.');
    }

    private function promoteCandidate(BlueGreenDeploymentClaim $claim): void
    {
        $candidateColor = $claim->pendingColor;
        $releaseProofToken = BlueGreenRoutingTarget::durableReleaseProofToken($claim->deploymentUuid);
        $this->assertOperationOwned(BlueGreenDeploymentPhase::PREPARING);
        $this->assertExactPreviousContainerHealthy();

        if ($claim->legacyContainerName !== null) {
            $this->legacyRoutingSnapshot = $this->captureAndProveLegacyRouting();
        }

        $previousContainer = $this->previousContainerExpectation;
        $this->deployment->addLogEntry(
            'Blue-green promotion is proving the exact candidate release through its private probe before public handoff.',
        );
        $this->writeAndVerifyRouting($this->routingTarget(
            activeColor: $candidateColor,
            probeHeader: 'X-Coolify-Blue-Green-Probe',
            probeToken: BlueGreenRoutingTarget::durableProbeToken($claim->deploymentUuid),
            probeColor: $candidateColor,
            releaseProofToken: $releaseProofToken,
            mode: BlueGreenRoutingMode::ProbeOnly,
            fallbackContainerName: $claim->legacyContainerName === null ? $previousContainer?->name : null,
        ), recordRoutingMutation: false, expectedPhase: BlueGreenDeploymentPhase::PREPARING);

        if ($previousContainer !== null) {
            $handoffMode = $claim->legacyContainerName === null
                ? BlueGreenRoutingMode::Failover
                : BlueGreenRoutingMode::LegacyAdoption;
            $this->assertPreviousReleaseProofDiffersFromCandidate($previousContainer, $releaseProofToken);
            $handoffTarget = $this->routingTarget(
                activeColor: $candidateColor,
                mode: $handoffMode,
                releaseProofToken: $releaseProofToken,
                publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($claim->deploymentUuid),
                fallbackContainerName: $previousContainer->name,
            );
            $handoffConfiguration = $this->writeAndVerifyRouting(
                $handoffTarget,
                expectedPhase: BlueGreenDeploymentPhase::PREPARING,
            );
            $this->monitorPublicHandoff(
                $handoffConfiguration,
                $handoffTarget,
                BlueGreenDeploymentPhase::PREPARING,
            );
        }

        $this->writeAndVerifyRouting($this->routingTarget(
            activeColor: $candidateColor,
            mode: $claim->legacyContainerName === null
                ? BlueGreenRoutingMode::Steady
                : BlueGreenRoutingMode::LegacyAdoption,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($claim->deploymentUuid),
        ), expectedPhase: BlueGreenDeploymentPhase::PREPARING);
        $this->assertOperationOwned(BlueGreenDeploymentPhase::PREPARING);
        TransitionsBlueGreenDeployment::markSwitching($claim);
        $this->assertOperationOwned(BlueGreenDeploymentPhase::SWITCHING);
        TransitionsBlueGreenDeployment::markDraining(
            $claim,
            $this->application->settings->deploymentStopGracePeriodSeconds(),
        );
        $this->finalized = true;

        try {
            $rollbackKey = $this->rollbackKey
                ?? throw new DeploymentException('Blue-green routing finalized without a durable rollback key.');
            $this->assertOperationOwned(BlueGreenDeploymentPhase::DRAINING);
            $this->assertServerBootIdentity();
            BlueGreenProxyRollbackArtifactCommitter::run($this->server, $rollbackKey);
        } catch (Throwable $exception) {
            if ($this->causedByOperationFenceLoss($exception)) {
                throw $exception;
            }
            $this->assertOperationOwned(BlueGreenDeploymentPhase::DRAINING);
            TransitionsBlueGreenDeployment::markInterventionRequired($claim);
            $this->interventionRequired = true;
            throw new DeploymentException('Blue-green routing is live, but durable finalization cleanup failed and requires intervention: '.$exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    private function routingTarget(
        BlueGreenDeploymentColor $activeColor,
        ?string $probeHeader = null,
        ?string $probeToken = null,
        ?BlueGreenDeploymentColor $probeColor = null,
        ?string $releaseProofToken = null,
        ?string $publicProofToken = null,
        BlueGreenRoutingMode $mode = BlueGreenRoutingMode::Steady,
        ?string $legacyContainerName = null,
        ?string $fallbackContainerName = null,
    ): BlueGreenRoutingTarget {
        $claim = $this->claim
            ?? throw new DeploymentException('Cannot compile blue-green routing without a durable claim.');
        $port = $this->application->blueGreenDeploymentBackendPort()
            ?? throw new DeploymentException('The blue-green backend port became ambiguous during promotion.');
        $activeContainer = $mode === BlueGreenRoutingMode::LegacyRecoveryBridge
            ? $this->previousContainerExpectation
            : ($activeColor === $claim->pendingColor
                ? $this->candidateContainerExpectation
                : $this->previousContainerExpectation);
        if ($activeContainer?->dockerId === null) {
            throw new DeploymentException('Blue-green routing cannot compile without the exact active Docker identity.');
        }
        $activeDeploymentUuid = $activeContainer->deploymentUuid
            ?? 'legacy-'.substr($activeContainer->dockerId, 0, 32);
        $mutationSequence = $this->destinationState === null
            || $this->destinationState->operationId !== $claim->deploymentUuid
                ? 1
                : $this->destinationState->mutationSequence + 1;
        $failoverHealthCheck = $mode !== BlueGreenRoutingMode::ProbeOnly && $fallbackContainerName !== null
            ? $this->httpFailoverHealthCheckContract()
            : [
                'type' => 'http',
                'path' => '/',
                'interval' => 5,
                'timeout' => 5,
                'scheme' => 'http',
                'hostname' => 'localhost',
                'method' => 'GET',
                'status' => 200,
                'port' => null,
            ];

        return new BlueGreenRoutingTarget(
            destinationId: $this->destination->id,
            activeColor: $activeColor,
            blueContainerName: $this->containerName(BlueGreenDeploymentColor::BLUE),
            greenContainerName: $this->containerName(BlueGreenDeploymentColor::GREEN),
            port: $port,
            routingRevision: $claim->expectedRoutingRevision,
            mode: $mode,
            probeHeaderName: $probeHeader,
            probeToken: $probeToken,
            probeColor: $probeColor,
            releaseProofToken: $releaseProofToken,
            publicProofToken: $publicProofToken,
            legacyContainerName: $legacyContainerName,
            fallbackContainerName: $fallbackContainerName,
            healthCheckType: $failoverHealthCheck['type'],
            healthCheckPath: $failoverHealthCheck['path'],
            healthCheckIntervalSeconds: $failoverHealthCheck['interval'],
            healthCheckTimeoutSeconds: $failoverHealthCheck['timeout'],
            healthCheckScheme: $failoverHealthCheck['scheme'],
            healthCheckHostname: $failoverHealthCheck['hostname'],
            healthCheckMethod: $failoverHealthCheck['method'],
            healthCheckStatus: $failoverHealthCheck['status'],
            healthCheckPort: $failoverHealthCheck['port'],
            destinationFenceEpoch: ($this->destinationState?->destinationFenceEpoch ?? 0) + 1,
            operationId: $claim->deploymentUuid,
            mutationSequence: $mutationSequence,
            activeDeploymentUuid: $activeDeploymentUuid,
            activeContainerId: $activeContainer->dockerId,
            destinationTopologyDigest: $claim->topologyDigest,
        );
    }

    /**
     * @return array{type: string, path: string, interval: int, timeout: int, scheme: string, hostname: string, method: string, status: int, port: ?int}
     */
    private function httpFailoverHealthCheckContract(): array
    {
        $type = $this->application->health_check_type;
        $path = $this->application->health_check_path;
        $scheme = $this->application->health_check_scheme;
        $hostname = $this->application->health_check_host;
        $method = $this->application->health_check_method;
        $interval = (int) $this->application->health_check_interval;
        $timeout = (int) $this->application->health_check_timeout;
        $status = (int) $this->application->health_check_return_code;
        $configuredPort = $this->application->health_check_port;

        if ($type !== 'http') {
            throw new DeploymentException('Blue-green failover requires an HTTP application health-check contract; command health checks cannot be represented by Traefik.');
        }
        if (! is_string($path) || preg_match('#^/[A-Za-z0-9/_.~%:;,@+\-]*$#D', $path) !== 1) {
            throw new DeploymentException('Blue-green failover requires an exact valid application health-check path.');
        }
        if ($interval < 1 || $timeout < 1) {
            throw new DeploymentException('Blue-green failover requires positive configured health-check interval and timeout values.');
        }
        if (! is_string($scheme) || ! in_array($scheme, ['http', 'https'], true)) {
            throw new DeploymentException('Blue-green failover requires an HTTP or HTTPS application health-check scheme.');
        }
        if (! is_string($hostname) || preg_match('/^[A-Za-z0-9._-]+$/D', $hostname) !== 1) {
            throw new DeploymentException('Blue-green failover requires an exact valid application health-check host.');
        }
        if (! is_string($method) || ! in_array($method, ['GET', 'HEAD', 'POST', 'OPTIONS'], true)) {
            throw new DeploymentException('Blue-green failover requires a supported application health-check method.');
        }
        if ($status < 100 || $status > 599) {
            throw new DeploymentException('Blue-green failover requires a valid application health-check status code.');
        }
        if ($configuredPort === null || $configuredPort === '') {
            $port = null;
        } elseif (is_int($configuredPort)
            || (is_string($configuredPort) && ctype_digit($configuredPort))) {
            $port = (int) $configuredPort;
            if ($port < 1 || $port > 65535) {
                throw new DeploymentException('Blue-green failover requires an application health-check port between 1 and 65535.');
            }
        } else {
            throw new DeploymentException('Blue-green failover requires an integer application health-check port when one is configured.');
        }

        return compact('type', 'path', 'interval', 'timeout', 'scheme', 'hostname', 'method', 'status', 'port');
    }

    private function writeAndVerifyRouting(
        BlueGreenRoutingTarget $target,
        bool $recordRoutingMutation = true,
        BlueGreenDeploymentPhase $expectedPhase = BlueGreenDeploymentPhase::PREPARING,
    ): BlueGreenProxyConfiguration {
        $claim = $this->claim
            ?? throw new DeploymentException('Blue-green routing cannot mutate without a durable claim.');
        $configuration = CompileBlueGreenProxyConfiguration::run(
            $this->application,
            $this->destination,
            $target,
        );
        if ($claim->rollbackManagedFilename !== $configuration->managedFilename) {
            throw new DeploymentException('Blue-green routing compilation changed its durable rollback ownership.');
        }
        $expectedState = $this->destinationState;
        $routingMutationKey = new BlueGreenProxyRollbackKey(
            operationId: $claim->deploymentUuid,
            expectedState: $expectedState,
            replacementState: $configuration->state,
        );

        $this->rollbackKey ??= $routingMutationKey;
        $this->latestRoutingMutationKey = $routingMutationKey;
        RecordBlueGreenRollbackKey::run($claim, $this->rollbackKey);
        $this->assertOperationOwned($expectedPhase);
        try {
            $this->assertServerBootIdentity();
            WriteBlueGreenProxyConfiguration::run(
                $this->server,
                $configuration,
                $routingMutationKey,
                $claim->serverBootId,
            );
            $this->proxyChanged = true;
        } catch (Throwable $exception) {
            if (! $this->remoteDestinationStateMatches($configuration->state)) {
                throw $exception;
            }
            $this->proxyChanged = true;
        }
        RecordBlueGreenDestinationState::run($claim, $expectedState, $configuration->state);
        $this->destinationState = $configuration->state;
        if ($recordRoutingMutation) {
            $this->assertOperationOwned($expectedPhase);
            RecordBlueGreenRoutingMutation::run($claim, $configuration->state);
        }
        $this->waitForRoutes($configuration, $target, $expectedPhase);
        $this->assertOperationOwned($expectedPhase);
        $this->assertExactCandidateStillHealthy();
        $this->assertCandidateReleaseProof();

        return $configuration;
    }

    private function captureAndProveLegacyRouting(): BlueGreenLegacyRoutingSnapshot
    {
        $expectation = $this->previousContainerExpectation
            ?? throw new DeploymentException('First blue-green adoption has no immutable legacy container identity.');
        $this->assertOperationOwned(BlueGreenDeploymentPhase::PREPARING);
        $snapshot = CaptureBlueGreenLegacyRouting::run(
            $this->server,
            $this->application,
            $this->destination,
            $expectation,
        );
        $this->assertOperationOwned(BlueGreenDeploymentPhase::PREPARING);
        WaitForBlueGreenLegacyDockerRouting::run(
            $this->server,
            $snapshot,
            BlueGreenLegacyProviderState::Active,
            1,
            $this->checkForCancellation,
        );
        $claim = $this->claim
            ?? throw new DeploymentException('First blue-green adoption lost its durable operation claim before snapshot persistence.');
        $this->assertOperationOwned(BlueGreenDeploymentPhase::PREPARING);
        RecordBlueGreenLegacyRoutingSnapshot::run($claim, $snapshot);

        return $snapshot;
    }

    private function assertExactCandidateStillHealthy(): void
    {
        $expectation = $this->candidateContainerExpectation
            ?? throw new DeploymentException('The candidate has no persisted Docker identity during routing promotion.');
        $inspection = InspectBlueGreenContainer::run($this->server, $expectation);
        if (! $inspection->exists
            || $inspection->dockerId !== $expectation->dockerId
            || $inspection->status !== 'running'
            || $inspection->health !== 'healthy') {
            throw new DeploymentException('The exact blue-green candidate lost Docker identity or health during routing promotion.');
        }
    }

    private function assertCandidateReleaseProof(): void
    {
        $claim = $this->claim
            ?? throw new DeploymentException('The candidate release proof has no durable operation claim.');
        $expectation = $this->candidateContainerExpectation
            ?? throw new DeploymentException('The candidate release proof has no exact Docker identity.');

        VerifyBlueGreenCandidateReleaseProof::run(
            $this->server,
            $expectation,
            BlueGreenRoutingTarget::durableReleaseProofToken($claim->deploymentUuid),
        );
    }

    private function assertPreviousReleaseProofDiffersFromCandidate(
        BlueGreenContainerExpectation $previousContainer,
        string $candidateReleaseProof,
    ): void {
        (new VerifyBlueGreenCandidateReleaseProof)->assertDistinctFrom(
            $this->server,
            $previousContainer,
            $candidateReleaseProof,
        );
    }

    private function waitForRoutes(
        BlueGreenProxyConfiguration $configuration,
        BlueGreenRoutingTarget $target,
        BlueGreenDeploymentPhase $expectedPhase,
    ): void {
        $routePlan = new PlanBlueGreenPublicRecovery;
        $publicRoutes = $target->mode === BlueGreenRoutingMode::ProbeOnly
            ? []
            : $routePlan->routesForYaml($configuration->yaml, requireEntryPoints: true);
        if ($publicRoutes !== [] && $target->publicAcknowledgement() === null) {
            throw new DeploymentException('Every applied blue-green public route requires an exact opaque acknowledgement.');
        }
        $probeRoutes = $target->probeAcknowledgement() === null
            ? []
            : $routePlan->routesForYaml($configuration->yaml, probe: true, requireEntryPoints: true);
        if ($publicRoutes === [] && $probeRoutes === []) {
            throw new DeploymentException('Blue-green routing has neither a public route nor a candidate probe route to verify.');
        }
        $verifier = new VerifyBlueGreenPublicRecovery;
        $attempts = $publicRoutes === []
            ? max(10, (int) $this->application->health_check_retries)
            : 1;
        $lastFailure = 'Traefik did not expose a route verification result.';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            ($this->checkForCancellation)();
            try {
                $this->assertManagedFileChecksum($configuration, $expectedPhase);
                foreach ($probeRoutes as $route) {
                    $verifier->verifyRoute(
                        server: $this->server,
                        application: $this->application,
                        route: $route,
                        expectedAcknowledgement: $target->probeAcknowledgement(),
                        expectedReleaseProof: $target->releaseProofToken,
                        probeHeader: $target->probeHeaderName,
                        probeToken: $target->probeToken,
                        nonceParameter: VerifyBlueGreenPublicRecovery::DEPLOYMENT_NONCE_PARAMETER,
                        beforeRequest: function () use ($expectedPhase): void {
                            $this->assertOperationOwned($expectedPhase);
                        },
                    );
                }
                foreach ($publicRoutes as $route) {
                    $verifier->verifyRoute(
                        server: $this->server,
                        application: $this->application,
                        route: $route,
                        expectedAcknowledgement: $target->publicAcknowledgement(),
                        expectedReleaseProof: $target->releaseProofToken,
                        nonceParameter: VerifyBlueGreenPublicRecovery::DEPLOYMENT_NONCE_PARAMETER,
                        beforeRequest: function () use ($expectedPhase): void {
                            $this->assertOperationOwned($expectedPhase);
                        },
                    );
                }

                return;
            } catch (Throwable $exception) {
                $lastFailure = $exception->getMessage();
                if ($publicRoutes !== []) {
                    $this->deployment->addLogEntry(
                        'Blue-green public handoff observed an error after switch; the zero-error guarantee is not met: '.$lastFailure,
                        'stderr',
                    );
                    throw new DeploymentException('Blue-green public verification failed after switch without retry: '.$lastFailure, previous: $exception);
                }
            }
            if ($attempt < $attempts) {
                Sleep::for(1)->seconds();
            }
        }

        throw new DeploymentException("Traefik did not acknowledge every canonical blue-green router: {$lastFailure}");
    }

    private function monitorPublicHandoff(
        BlueGreenProxyConfiguration $configuration,
        BlueGreenRoutingTarget $target,
        BlueGreenDeploymentPhase $expectedPhase,
    ): void {
        $acknowledgement = $target->publicAcknowledgement();
        if ($acknowledgement === null) {
            throw new DeploymentException('A blue-green handoff grace window requires a public route acknowledgement.');
        }
        $routes = (new PlanBlueGreenPublicRecovery)->routesForYaml(
            $configuration->yaml,
            requireEntryPoints: true,
        );
        $graceSeconds = $this->publicHandoffGraceSeconds();
        $this->deployment->addLogEntry(
            "Blue-green handoff is monitoring candidate-main and previous-fallback health for {$graceSeconds} seconds.",
        );
        $verifier = new VerifyBlueGreenPublicRecovery;

        for ($second = 1; $second <= $graceSeconds; $second++) {
            ($this->checkForCancellation)();
            try {
                $this->assertManagedFileChecksum($configuration, $expectedPhase);
                $this->assertExactCandidateStillHealthy();
                $this->assertCandidateReleaseProof();
                $this->assertExactPreviousContainerHealthy();
                foreach ($routes as $route) {
                    $verifier->verifyRoute(
                        server: $this->server,
                        application: $this->application,
                        route: $route,
                        expectedAcknowledgement: $acknowledgement,
                        expectedReleaseProof: $target->releaseProofToken,
                        nonceParameter: VerifyBlueGreenPublicRecovery::DEPLOYMENT_NONCE_PARAMETER,
                        beforeRequest: function () use ($expectedPhase): void {
                            $this->assertOperationOwned($expectedPhase);
                        },
                    );
                }
            } catch (Throwable $exception) {
                $this->deployment->addLogEntry(
                    'Blue-green handoff grace window observed a public or backend error; the zero-error guarantee is not met: '
                    .$exception->getMessage(),
                    'stderr',
                );
                throw new DeploymentException(
                    'Blue-green handoff grace window failed without retry: '.$exception->getMessage(),
                    previous: $exception,
                );
            }
            if ($second < $graceSeconds) {
                Sleep::for(1)->seconds();
            }
        }
    }

    private function assertManagedFileChecksum(
        BlueGreenProxyConfiguration $configuration,
        BlueGreenDeploymentPhase $expectedPhase,
    ): void {
        $destinationState = $this->destinationState
            ?? throw new DeploymentException('Managed route verification has no exact destination state.');
        if ($destinationState->serialize() !== $configuration->state->serialize()) {
            throw new DeploymentException('Managed route verification does not match the last destination mutation.');
        }
        $this->assertOperationOwned($expectedPhase);
        $output = trim((string) instant_remote_process([
            (new WriteBlueGreenProxyConfiguration)->attestStateCommandFor(
                $this->server->proxyPath(),
                $configuration->managedFilename,
                $destinationState,
            ),
        ], $this->server));
        if ($output !== 'coolify-blue-green-destination-state-attested') {
            throw new DeploymentException('The active blue-green Traefik state did not return its exact attestation.');
        }
    }

    private function publicHandoffGraceSeconds(): int
    {
        return self::boundedPublicHandoffGraceSeconds(
            (int) $this->application->health_check_interval,
            (int) $this->application->health_check_timeout,
        );
    }

    private static function boundedPublicHandoffGraceSeconds(
        int $healthCheckIntervalSeconds,
        int $healthCheckTimeoutSeconds,
    ): int {
        if ($healthCheckIntervalSeconds < 1 || $healthCheckTimeoutSeconds < 1) {
            throw new DeploymentException('Blue-green public handoff requires positive configured health-check timing.');
        }

        return min(30, max(3, ($healthCheckIntervalSeconds * 2) + $healthCheckTimeoutSeconds));
    }

    public function rollback(Throwable $cause): Throwable
    {
        if (! $this->enabled
            || $this->claim === null
            || $this->rollbackCompleted
            || $this->promotionCommitted
            || $this->interventionRequired) {
            return $cause;
        }
        $claim = $this->claim;
        if ($this->finalized) {
            if ($this->isRetryableDrainTimeout($cause)) {
                return $cause;
            }
            try {
                $this->assertOperationOwned(BlueGreenDeploymentPhase::DRAINING);
                TransitionsBlueGreenDeployment::markInterventionRequired($claim);
                $this->interventionRequired = true;
            } catch (Throwable $interventionError) {
                if ($this->causedByOperationFenceLoss($interventionError)) {
                    return new DeploymentException(
                        "{$cause->getMessage()} Blue-green intervention stopped because its lifecycle ownership expired or changed.",
                        0,
                        $cause,
                    );
                }

                return new DeploymentException(
                    "{$cause->getMessage()} Blue-green routing was finalized, and intervention state could not be recorded: {$interventionError->getMessage()}",
                    0,
                    $cause,
                );
            }

            return $cause;
        }

        try {
            $this->assertLifecycleLockOwned();
            $this->deployment->refresh();
            if ($this->deployment->status !== ApplicationDeploymentStatus::CANCELLED_BY_USER->value) {
                $this->assertOperationOwned(
                    BlueGreenDeploymentPhase::PREPARING,
                    BlueGreenDeploymentPhase::SWITCHING,
                );
            }
            TransitionsBlueGreenDeployment::beginRollback($claim);
            $this->reconcilePendingDestinationState();
            $this->reconcileAppliedRoutingState();
            if ($this->proxyChanged) {
                $rollbackKey = $this->rollbackKey
                    ?? throw new DeploymentException('Blue-green routing changed without a durable rollback key.');
                $this->waitForExactPreviousContainerHealth();
                $this->assertOperationOwned(BlueGreenDeploymentPhase::ROLLING_BACK);
                $this->assertServerBootIdentity();
                $artifact = BlueGreenProxyRollbackArtifactReader::run($this->server, $rollbackKey);
                $previousRoutes = [];
                $previousAcknowledgement = null;
                if ($claim->previousActiveColor !== null) {
                    if (! $artifact->existed) {
                        throw new DeploymentException('The fixed-color rollback artifact has no previous managed route.');
                    }
                    $routePlan = new PlanBlueGreenPublicRecovery;
                    $previousRoutes = $routePlan->routesForYaml($artifact->bytes);
                    $previousAcknowledgement = $routePlan->publicAcknowledgementForYaml($artifact->bytes);
                }
                $this->assertOperationOwned(BlueGreenDeploymentPhase::ROLLING_BACK);
                $this->assertServerBootIdentity();
                BlueGreenProxyRollbackArtifactRestorer::run($this->server, $rollbackKey, $claim->serverBootId);
                $rollbackState = $rollbackKey->rollbackState();
                RecordBlueGreenDestinationState::run(
                    $claim,
                    $this->destinationState,
                    $rollbackState,
                );
                $this->destinationState = $rollbackState;
                if ($previousRoutes !== [] && $previousAcknowledgement !== null) {
                    $this->assertOperationOwned(BlueGreenDeploymentPhase::ROLLING_BACK);
                    VerifyBlueGreenPublicRecovery::run(
                        $this->server,
                        $this->application,
                        $previousRoutes,
                        $previousAcknowledgement,
                    );
                } elseif ($claim->legacyContainerName !== null) {
                    $snapshot = $this->legacyRoutingSnapshot
                        ?? throw new DeploymentException('Legacy rollback lost its exact Docker-provider routing snapshot.');
                    WaitForBlueGreenLegacyDockerRouting::run(
                        $this->server,
                        $snapshot,
                        BlueGreenLegacyProviderState::Active,
                        min(300, max(10, (int) $this->application->health_check_retries)),
                        $this->checkForCancellation,
                    );
                }
            }
            $this->removeCandidateContainer();
            $this->assertOperationOwned(BlueGreenDeploymentPhase::ROLLING_BACK);
            TransitionsBlueGreenDeployment::finishRollback($claim);
            $this->rollbackCompleted = true;
            if ($this->proxyChanged) {
                $rollbackKey = $this->rollbackKey
                    ?? throw new DeploymentException('Blue-green rollback artifact ownership disappeared during compensation.');
                try {
                    $this->assertLifecycleLockOwned();
                    $this->assertServerBootIdentity();
                    BlueGreenProxyRollbackArtifactCommitter::run($this->server, $rollbackKey);
                } catch (Throwable $artifactCleanupError) {
                    report($artifactCleanupError);
                    $this->deployment->addLogEntry(
                        'Blue-green rollback completed durably; stale rollback-artifact cleanup will require maintenance.',
                        'stderr',
                    );
                }
            }
            $this->deployment->addLogEntry('Blue-green rollback restored and verified the previous route before removing the candidate.', 'stderr');

            return $cause;
        } catch (Throwable $rollbackError) {
            if ($this->causedByOperationFenceLoss($rollbackError)) {
                return new DeploymentException(
                    "{$cause->getMessage()} Blue-green rollback stopped because its lifecycle ownership expired or changed.",
                    0,
                    $cause,
                );
            }
            try {
                $this->assertOperationOwned(
                    BlueGreenDeploymentPhase::PREPARING,
                    BlueGreenDeploymentPhase::SWITCHING,
                    BlueGreenDeploymentPhase::DRAINING,
                    BlueGreenDeploymentPhase::ROLLING_BACK,
                    BlueGreenDeploymentPhase::IDLE,
                );
                TransitionsBlueGreenDeployment::markInterventionRequired($claim);
                $this->interventionRequired = true;
            } catch (Throwable $interventionError) {
                $rollbackError = new DeploymentException(
                    "{$rollbackError->getMessage()} Intervention state also failed: {$interventionError->getMessage()}",
                    0,
                    $rollbackError,
                );
            }

            return new DeploymentException(
                "{$cause->getMessage()} Blue-green rollback failed and requires intervention: {$rollbackError->getMessage()}",
                0,
                $cause,
            );
        }
    }

    private function removeCandidateContainer(): void
    {
        $expectation = $this->candidateContainerExpectation
            ?? throw new DeploymentException('Cannot remove a blue-green candidate without durable label and Docker identity provenance.');
        if ($expectation->dockerId === null) {
            $this->assertOperationOwned(BlueGreenDeploymentPhase::ROLLING_BACK);
            $inspection = InspectBlueGreenContainer::run($this->server, $expectation);
            if (! $inspection->exists || $inspection->dockerId === null) {
                return;
            }
            $claim = $this->claim
                ?? throw new DeploymentException('Cannot persist recovered candidate identity without its durable claim.');
            $this->assertOperationOwned(BlueGreenDeploymentPhase::ROLLING_BACK);
            RecordBlueGreenCandidateIdentity::run($claim, $inspection);
            $expectation = $expectation->withDockerId($inspection->dockerId);
            $this->candidateContainerExpectation = $expectation;
        }
        $this->assertOperationOwned(BlueGreenDeploymentPhase::ROLLING_BACK);
        $inspection = InspectBlueGreenContainer::run($this->server, $expectation);
        $this->assertOperationOwned(BlueGreenDeploymentPhase::ROLLING_BACK);
        $claim = $this->claim
            ?? throw new DeploymentException('Cannot remove a blue-green candidate without its durable claim.');
        try {
            $this->destinationState = RemoveExactBlueGreenCandidate::run(
                $this->server,
                $this->application,
                $claim,
                $this->destinationState,
                $expectation,
                $inspection,
            );
        } catch (BlueGreenDestinationStateRecordingException $exception) {
            $this->pendingDestinationState = $exception->replacementState;
            $this->reconcilePendingDestinationState();
        }
    }

    private function assertOperationOwned(BlueGreenDeploymentPhase ...$expectedPhases): BlueGreenDeploymentPhase
    {
        $claim = $this->claim
            ?? throw new DeploymentException('The blue-green lifecycle has no durable operation to fence.');
        $fence = $this->operationFence
            ?? throw new DeploymentException('The blue-green lifecycle has no owned cache lock to fence remote work.');

        $phase = $fence->assertDeploymentOwnership(
            $claim,
            $expectedPhases,
            $this->destinationState,
            verifyDestinationState: true,
        );
        $this->assertServerBootIdentity();

        return $phase;
    }

    private function assertLifecycleLockOwned(): void
    {
        $fence = $this->operationFence
            ?? throw new DeploymentException('The blue-green lifecycle has no owned cache lock to fence remote work.');
        $fence->assertLockOwnership();
    }

    private function assertServerBootIdentity(): void
    {
        $claim = $this->claim
            ?? throw new DeploymentException('The blue-green lifecycle has no durable server boot identity.');
        ReadBlueGreenServerBootIdentity::run($this->server, $claim->serverBootId);
    }

    /**
     * @param  non-empty-list<string>  $commands
     * @param  non-empty-list<string>  $completionCommands
     */
    private function executeDestinationMutation(array $commands, array $completionCommands): BlueGreenProxyState
    {
        $claim = $this->claim
            ?? throw new DeploymentException('A destination mutation has no durable operation claim.');
        try {
            return (new ExecuteBlueGreenDestinationMutation)->executeForClaim(
                $this->application,
                $this->server,
                $claim,
                $this->destinationState,
                $commands,
                $completionCommands,
            );
        } catch (BlueGreenDestinationStateRecordingException $exception) {
            $this->pendingDestinationState = $exception->replacementState;
            throw $exception;
        }
    }

    private function reconcilePendingDestinationState(): void
    {
        $replacementState = $this->pendingDestinationState;
        if ($replacementState === null) {
            return;
        }
        $claim = $this->claim
            ?? throw new DeploymentException('Pending destination reconciliation has no durable operation claim.');
        if (! $this->remoteDestinationStateMatches($replacementState)) {
            throw new DeploymentException('The remote container mutation cannot be reconciled to its exact replacement state.');
        }
        try {
            RecordBlueGreenDestinationState::run($claim, $this->destinationState, $replacementState);
        } catch (Throwable $exception) {
            $state = ApplicationBlueGreenDeployment::query()->find($claim->stateId);
            if ($state === null
                || $state->destination_fence_epoch !== $replacementState->destinationFenceEpoch
                || $state->destination_fence_operation_id !== $replacementState->operationId
                || $state->destination_fence_mutation_sequence !== $replacementState->mutationSequence
                || $state->managed_file_sha256 !== $replacementState->managedSha256
                || $state->destination_topology_digest !== $replacementState->destinationTopologyDigest
                || $state->application_routing_config_digest !== $replacementState->applicationRoutingConfigDigest) {
                throw $exception;
            }
        }
        $this->destinationState = $replacementState;
        $this->pendingDestinationState = null;
    }

    private function reconcileAppliedRoutingState(): void
    {
        if (! $this->proxyChanged) {
            return;
        }
        $claim = $this->claim
            ?? throw new DeploymentException('Applied routing reconciliation has no durable operation claim.');
        $routingMutationKey = $this->latestRoutingMutationKey
            ?? throw new DeploymentException('Applied routing reconciliation has no latest routing mutation ownership.');
        $replacementState = $routingMutationKey->replacementState;
        if ($this->destinationState?->serialize() === $replacementState->serialize()) {
            return;
        }
        if (! $this->remoteDestinationStateMatches($replacementState)) {
            throw new DeploymentException('The applied remote route cannot be reconciled to its exact replacement state.');
        }
        try {
            RecordBlueGreenDestinationState::run($claim, $this->destinationState, $replacementState);
        } catch (Throwable $exception) {
            $state = ApplicationBlueGreenDeployment::query()->find($claim->stateId);
            if ($state === null
                || $state->destination_fence_epoch !== $replacementState->destinationFenceEpoch
                || $state->destination_fence_operation_id !== $replacementState->operationId
                || $state->destination_fence_mutation_sequence !== $replacementState->mutationSequence
                || $state->managed_file_sha256 !== $replacementState->managedSha256
                || $state->destination_topology_digest !== $replacementState->destinationTopologyDigest
                || $state->application_routing_config_digest !== $replacementState->applicationRoutingConfigDigest) {
                throw $exception;
            }
        }
        $this->destinationState = $replacementState;
    }

    private function remoteDestinationStateMatches(BlueGreenProxyState $expectedState): bool
    {
        try {
            $output = trim((string) instant_remote_process([
                (new WriteBlueGreenProxyConfiguration)->attestStateCommandFor(
                    $this->server->proxyPath(),
                    $expectedState->managedFilename,
                    $expectedState,
                ),
            ], $this->server));

            return $output === 'coolify-blue-green-destination-state-attested';
        } catch (Throwable) {
            return false;
        }
    }

    private function causedByOperationFenceLoss(Throwable $exception): bool
    {
        do {
            if ($exception instanceof BlueGreenOperationFenceLostException) {
                return true;
            }
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return false;
    }
}
