<?php

namespace App\Services;

use App\Actions\Application\BlueGreen\AssertBlueGreenLegacyManagedPathAvailable;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenLegacyProviderState;
use App\Actions\Application\BlueGreen\BlueGreenLegacyRoutingSnapshot;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\BlueGreenOperationFenceLostException;
use App\Actions\Application\BlueGreen\CaptureBlueGreenLegacyRouting;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\CompleteBlueGreenDeploymentOperation;
use App\Actions\Application\BlueGreen\EnsureBlueGreenPreviousContainerRunning;
use App\Actions\Application\BlueGreen\FindBlueGreenDeactivationFence;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\PlanBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\RebindBlueGreenLegacyRoutingSnapshot;
use App\Actions\Application\BlueGreen\RecordBlueGreenCandidateIdentity;
use App\Actions\Application\BlueGreen\RecordBlueGreenLegacyRoutingSnapshot;
use App\Actions\Application\BlueGreen\RecordBlueGreenRoutingMutation;
use App\Actions\Application\BlueGreen\RemoveBlueGreenInactiveContainer;
use App\Actions\Application\BlueGreen\RemoveExactBlueGreenCandidate;
use App\Actions\Application\BlueGreen\TransitionsBlueGreenDeployment;
use App\Actions\Application\BlueGreen\VerifyBlueGreenLegacyProviderRecovery;
use App\Actions\Application\BlueGreen\VerifyBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\WaitForBlueGreenLegacyDockerRouting;
use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactCommitter;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactReader;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactRestorer;
use App\Actions\Proxy\BlueGreenProxyRollbackKey;
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
use Carbon\Carbon;
use Closure;
use Illuminate\Cache\Lock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class BlueGreenDeploymentLifecycle
{
    private bool $enabled = false;

    private ?BlueGreenDeploymentClaim $claim = null;

    private ?BlueGreenDeploymentColor $previousActiveColor = null;

    private ?string $legacyContainerName = null;

    private ?BlueGreenProxyRollbackKey $rollbackKey = null;

    private ?BlueGreenProxyConfiguration $lastConfiguration = null;

    private ?BlueGreenContainerExpectation $previousContainerExpectation = null;

    private ?BlueGreenContainerExpectation $candidateContainerExpectation = null;

    private ?BlueGreenLegacyRoutingSnapshot $legacyRoutingSnapshot = null;

    private ?Lock $lifecycleLock = null;

    private ?BlueGreenOperationFence $operationFence = null;

    private bool $proxyChanged = false;

    private bool $finalized = false;

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
        $this->acquireLifecycleLock();
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
        if ($durableState !== null
            && ($durableState->phase !== BlueGreenDeploymentPhase::IDLE
                || $durableState->operation_deployment_uuid !== null)) {
            throw new DeploymentException('An unfinished blue-green lifecycle must be reconciled before another deployment can mutate this destination.');
        }
        $this->assertEligibility();

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
        $this->assertEligibility();

        $this->claim = ClaimBlueGreenDeployment::run(
            application: $this->application,
            standaloneDocker: $this->destination,
            deployment: $this->deployment,
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
        $this->rollbackKey = new BlueGreenProxyRollbackKey(
            managedFilename: $this->claim->rollbackManagedFilename
                ?? throw new DeploymentException('The blue-green claim has no durable rollback ownership.'),
            operationId: $this->claim->deploymentUuid,
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
     * @param  Closure(): void  $startCandidate
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
            $startCandidate();
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
        $this->assertOperationOwned(BlueGreenDeploymentPhase::IDLE);
        CompleteBlueGreenDeploymentOperation::run($claim);
        $this->promotionCommitted = true;
        $this->deployment->refresh();
    }

    public function release(): void
    {
        $this->operationFence?->releaseIfOwned();
        $this->operationFence = null;
        $this->lifecycleLock = null;
    }

    private function acquireLifecycleLock(): void
    {
        $leaseSeconds = BlueGreenDeploymentLock::leaseSeconds(
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
        $this->deployment->update([
            'status' => ApplicationDeploymentStatus::FAILED->value,
            'finished_at' => Carbon::now()->toImmutable(),
        ]);

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
        RemoveBlueGreenInactiveContainer::run(
            $this->server,
            $this->application,
            $this->destination,
            $claim,
        );
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
                $this->application->update(['status' => 'running:healthy']);

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
        $this->assertOperationOwned(BlueGreenDeploymentPhase::PREPARING);
        $this->assertExactPreviousContainerHealthy();

        if ($claim->previousActiveColor !== null) {
            $this->writeAndVerifyCandidateRouting(
                activeColor: $claim->previousActiveColor,
                candidateColor: $candidateColor,
                expectedPhase: BlueGreenDeploymentPhase::PREPARING,
            );
            $this->assertOperationOwned(BlueGreenDeploymentPhase::PREPARING);
            TransitionsBlueGreenDeployment::markSwitching($claim);
            $this->writeAndVerifyCandidateRouting(
                activeColor: $candidateColor,
                candidateColor: $candidateColor,
                expectedPhase: BlueGreenDeploymentPhase::SWITCHING,
            );
        } elseif ($claim->legacyContainerName !== null) {
            $this->legacyRoutingSnapshot = $this->captureAndProveLegacyRouting();
            $this->writeAndVerifyCandidateRouting(
                activeColor: $candidateColor,
                candidateColor: $candidateColor,
                mode: BlueGreenRoutingMode::LegacyAdoption,
                expectedPhase: BlueGreenDeploymentPhase::PREPARING,
            );
            $this->assertOperationOwned(BlueGreenDeploymentPhase::PREPARING);
            TransitionsBlueGreenDeployment::markSwitching($claim);
            $this->stopLegacyContainer($this->legacyRoutingSnapshot);
            $this->assertOperationOwned(BlueGreenDeploymentPhase::SWITCHING);
            WaitForBlueGreenLegacyDockerRouting::run(
                $this->server,
                $this->legacyRoutingSnapshot,
                BlueGreenLegacyProviderState::Evicted,
                min(300, max(10, (int) $this->application->health_check_retries)),
                $this->checkForCancellation,
            );
        } else {
            $this->writeAndVerifyCandidateRouting(
                activeColor: $candidateColor,
                candidateColor: $candidateColor,
                expectedPhase: BlueGreenDeploymentPhase::PREPARING,
            );
            $this->assertOperationOwned(BlueGreenDeploymentPhase::PREPARING);
            TransitionsBlueGreenDeployment::markSwitching($claim);
        }

        $this->writeAndVerifyRouting($this->routingTarget(
            activeColor: $candidateColor,
            mode: BlueGreenRoutingMode::Steady,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($claim->deploymentUuid),
        ), expectedPhase: BlueGreenDeploymentPhase::SWITCHING);
        $this->assertOperationOwned(BlueGreenDeploymentPhase::SWITCHING);
        TransitionsBlueGreenDeployment::finalize($claim);
        $this->finalized = true;

        try {
            $rollbackKey = $this->rollbackKey
                ?? throw new DeploymentException('Blue-green routing finalized without a durable rollback key.');
            $this->assertOperationOwned(BlueGreenDeploymentPhase::IDLE);
            BlueGreenProxyRollbackArtifactCommitter::run($this->server, $rollbackKey);
        } catch (Throwable $exception) {
            if ($this->causedByOperationFenceLoss($exception)) {
                throw $exception;
            }
            $this->assertOperationOwned(BlueGreenDeploymentPhase::IDLE);
            TransitionsBlueGreenDeployment::markInterventionRequired($claim);
            $this->interventionRequired = true;
            throw new DeploymentException('Blue-green routing is live, but durable finalization cleanup failed and requires intervention: '.$exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    private function writeAndVerifyCandidateRouting(
        BlueGreenDeploymentColor $activeColor,
        BlueGreenDeploymentColor $candidateColor,
        BlueGreenRoutingMode $mode = BlueGreenRoutingMode::Steady,
        BlueGreenDeploymentPhase $expectedPhase = BlueGreenDeploymentPhase::PREPARING,
    ): void {
        $probeToken = 'probe:'.bin2hex(random_bytes(32));
        $publicProofToken = 'public:'.bin2hex(random_bytes(32));

        $this->writeAndVerifyRouting($this->routingTarget(
            activeColor: $activeColor,
            probeHeader: 'X-Coolify-Blue-Green-Probe',
            probeToken: $probeToken,
            probeColor: $candidateColor,
            publicProofToken: $publicProofToken,
            mode: $mode,
        ), expectedPhase: $expectedPhase);
    }

    private function routingTarget(
        BlueGreenDeploymentColor $activeColor,
        ?string $probeHeader = null,
        ?string $probeToken = null,
        ?BlueGreenDeploymentColor $probeColor = null,
        ?string $publicProofToken = null,
        BlueGreenRoutingMode $mode = BlueGreenRoutingMode::Steady,
        ?string $legacyContainerName = null,
    ): BlueGreenRoutingTarget {
        $claim = $this->claim
            ?? throw new DeploymentException('Cannot compile blue-green routing without a durable claim.');
        $port = $this->application->blueGreenDeploymentBackendPort()
            ?? throw new DeploymentException('The blue-green backend port became ambiguous during promotion.');

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
            publicProofToken: $publicProofToken,
            legacyContainerName: $legacyContainerName,
        );
    }

    private function writeAndVerifyRouting(
        BlueGreenRoutingTarget $target,
        bool $recordRoutingMutation = true,
        BlueGreenDeploymentPhase $expectedPhase = BlueGreenDeploymentPhase::PREPARING,
    ): void {
        $claim = $this->claim
            ?? throw new DeploymentException('Blue-green routing cannot mutate without a durable claim.');
        $configuration = CompileBlueGreenProxyConfiguration::run(
            $this->application,
            $this->destination,
            $target,
        );
        $rollbackKey = $this->rollbackKey
            ?? throw new DeploymentException('Blue-green routing cannot mutate before durable rollback ownership is recorded.');
        if ($rollbackKey->managedFilename !== $configuration->managedFilename
            || $rollbackKey->operationId !== $this->deployment->deployment_uuid
            || $rollbackKey->routingRevision !== $target->routingRevision) {
            throw new DeploymentException('Blue-green routing compilation changed its durable rollback ownership.');
        }

        $this->lastConfiguration = $configuration;
        $this->proxyChanged = true;
        $this->assertOperationOwned($expectedPhase);
        $artifact = WriteBlueGreenProxyConfiguration::run(
            $this->server,
            $configuration,
            $rollbackKey,
        );
        if ($claim->previousActiveColor === null
            && $claim->legacyContainerName !== null
            && $artifact->existed) {
            throw new DeploymentException('First blue-green adoption detected a concurrent pre-existing managed route and left the candidate fence authoritative.');
        }
        if ($recordRoutingMutation) {
            $this->assertOperationOwned($expectedPhase);
            RecordBlueGreenRoutingMutation::run($claim);
        }
        $this->waitForRoutes($configuration, $target, $expectedPhase);
        $this->assertOperationOwned($expectedPhase);
        $this->assertExactCandidateStillHealthy();
    }

    private function stopLegacyContainer(BlueGreenLegacyRoutingSnapshot $snapshot): void
    {
        $expectation = $this->previousContainerExpectation
            ?? throw new DeploymentException('The legacy stop has no immutable container expectation.');
        if ($expectation->dockerId !== $snapshot->dockerId || $expectation->name !== $snapshot->containerName) {
            throw new DeploymentException('The legacy stop snapshot does not match durable container provenance.');
        }
        $inspection = InspectBlueGreenContainer::run($this->server, $expectation);
        if (! $inspection->exists
            || $inspection->dockerId !== $snapshot->dockerId
            || $inspection->status !== 'running'
            || $inspection->health !== 'healthy') {
            throw new DeploymentException('The exact legacy Docker identity lost health before provider-fenced stop.');
        }
        $dockerId = escapeshellarg($snapshot->dockerId);
        $stopTimeout = $this->application->settings->deploymentStopGracePeriodSeconds();
        $this->assertOperationOwned(BlueGreenDeploymentPhase::SWITCHING);
        instant_remote_process([
            "docker stop --time={$stopTimeout} {$dockerId}",
        ], $this->server);
    }

    private function captureAndProveLegacyRouting(): BlueGreenLegacyRoutingSnapshot
    {
        $expectation = $this->previousContainerExpectation
            ?? throw new DeploymentException('First blue-green adoption has no immutable legacy container identity.');
        $rollbackKey = $this->rollbackKey
            ?? throw new DeploymentException('First blue-green adoption has no durable managed routing ownership.');
        $this->assertOperationOwned(BlueGreenDeploymentPhase::PREPARING);
        $snapshot = CaptureBlueGreenLegacyRouting::run(
            $this->server,
            $this->application,
            $this->destination,
            $expectation,
        );
        $this->assertOperationOwned(BlueGreenDeploymentPhase::PREPARING);
        AssertBlueGreenLegacyManagedPathAvailable::run($this->server, $rollbackKey);
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

    private function waitForRoutes(
        BlueGreenProxyConfiguration $configuration,
        BlueGreenRoutingTarget $target,
        BlueGreenDeploymentPhase $expectedPhase,
    ): void {
        if ($target->publicAcknowledgement() === null) {
            throw new DeploymentException('Every applied blue-green public route requires an exact opaque acknowledgement.');
        }
        $publicRoutes = $this->routeChecks($configuration, probe: false);
        $probeRoutes = $target->probeAcknowledgement() === null
            ? []
            : $this->routeChecks($configuration, probe: true);
        $attempts = max(10, (int) $this->application->health_check_retries);
        $lastFailure = 'Traefik did not expose a route verification result.';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            ($this->checkForCancellation)();
            try {
                $this->assertManagedFileChecksum($configuration, $expectedPhase);
                foreach ($probeRoutes as $route) {
                    $this->assertHttpRoute(
                        route: $route,
                        probeHeader: $target->probeHeaderName,
                        probeToken: $target->probeToken,
                        expectedAcknowledgement: $target->probeAcknowledgement(),
                        expectedPhase: $expectedPhase,
                        requireOperationFence: true,
                    );
                }
                foreach ($publicRoutes as $route) {
                    $this->assertHttpRoute(
                        route: $route,
                        expectedAcknowledgement: $target->publicAcknowledgement(),
                        expectedPhase: $expectedPhase,
                        requireOperationFence: true,
                    );
                }

                return;
            } catch (Throwable $exception) {
                $lastFailure = $exception->getMessage();
            }
            if ($attempt < $attempts) {
                Sleep::for(1)->seconds();
            }
        }

        throw new DeploymentException("Traefik did not acknowledge every canonical blue-green router: {$lastFailure}");
    }

    private function assertManagedFileChecksum(
        BlueGreenProxyConfiguration $configuration,
        BlueGreenDeploymentPhase $expectedPhase,
    ): void {
        $managedPath = (new WriteBlueGreenProxyConfiguration)->managedPath(
            $this->server->proxyPath(),
            $configuration->managedFilename,
        );
        $this->assertOperationOwned($expectedPhase);
        $output = trim((string) instant_remote_process([
            'sha256sum '.escapeshellarg($managedPath),
        ], $this->server));
        $actualChecksum = str($output)->before(' ')->value();
        if (! hash_equals($configuration->sha256, $actualChecksum)) {
            throw new DeploymentException('The active blue-green Traefik file does not match the atomically written configuration.');
        }
    }

    /**
     * @return list<array{router: string, url: string}>
     */
    private function routeChecks(BlueGreenProxyConfiguration $configuration, bool $probe): array
    {
        $parsed = Yaml::parse(
            $configuration->yaml,
            Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
        );
        $routers = data_get($parsed, 'http.routers');
        if (! is_array($routers)) {
            throw new DeploymentException('The blue-green Traefik configuration has no typed router inventory.');
        }

        $checks = [];
        foreach ($routers as $routerName => $router) {
            if (! is_string($routerName) || ! is_array($router) || str_ends_with($routerName, '-probe') !== $probe) {
                continue;
            }
            $rule = data_get($router, 'rule');
            $entryPoints = data_get($router, 'entryPoints');
            if (! is_string($rule) || ! is_array($entryPoints) || $entryPoints === []) {
                throw new DeploymentException("Blue-green router {$routerName} has no verifiable rule or entry point.");
            }
            if (preg_match('/Host\(`([^`]+)`\)/', $rule, $hostMatch) !== 1
                || preg_match('/PathPrefix\(`([^`]+)`\)/', $rule, $pathMatch) !== 1) {
                throw new DeploymentException("Blue-green router {$routerName} does not use the canonical Host and PathPrefix rule.");
            }
            foreach ($entryPoints as $entryPoint) {
                $scheme = match ($entryPoint) {
                    'http' => 'http',
                    'https' => 'https',
                    default => throw new DeploymentException("Blue-green router {$routerName} uses an unsupported entry point."),
                };
                $checks[] = [
                    'router' => $routerName,
                    'url' => "{$scheme}://{$hostMatch[1]}{$pathMatch[1]}",
                ];
            }
        }
        if ($checks === []) {
            throw new DeploymentException($probe
                ? 'The blue-green Traefik configuration has no probe routers to acknowledge.'
                : 'The blue-green Traefik configuration has no public routers to prove.');
        }

        return $checks;
    }

    /** @param array{router: string, url: string} $route */
    private function assertHttpRoute(
        array $route,
        ?string $probeHeader = null,
        ?string $probeToken = null,
        ?string $expectedAcknowledgement = null,
        BlueGreenDeploymentPhase $expectedPhase = BlueGreenDeploymentPhase::PREPARING,
        bool $requireOperationFence = false,
    ): void {
        $request = $this->routeRequest($route, $probeHeader, $probeToken);
        if ($requireOperationFence) {
            $this->assertOperationOwned($expectedPhase);
        }
        $headers = (string) instant_remote_process(
            [$request['command']],
            $this->server,
            input: $request['input'],
        );

        $this->assertHttpResponse($route, $headers, $expectedAcknowledgement);
    }

    /**
     * @param  array{router: string, url: string}  $route
     * @return array{command: string, input: string}
     */
    private function routeRequest(
        array $route,
        ?string $probeHeader = null,
        ?string $probeToken = null,
    ): array {
        $url = Url::fromString($route['url']);
        $publicPort = match ($url->getScheme()) {
            'http' => 80,
            'https' => 443,
            default => throw new DeploymentException("Blue-green router {$route['router']} uses an unsupported public URL scheme."),
        };
        $host = $url->getHost();
        if ($host === '') {
            throw new DeploymentException("Blue-green router {$route['router']} has no public URL host.");
        }
        $nonceUrl = $route['url'].'?__coolify_blue_green_probe='.bin2hex(random_bytes(16));
        $config = [
            'silent',
            'show-error',
            'http1.1',
            'noproxy = '.$this->curlConfigValue('*'),
            'connect-timeout = 5',
            'max-time = 15',
            'output = '.$this->curlConfigValue('/dev/null'),
            'dump-header = '.$this->curlConfigValue('-'),
            'header = '.$this->curlConfigValue('Cache-Control: no-cache, no-store, max-age=0'),
            'header = '.$this->curlConfigValue('Pragma: no-cache'),
            'resolve = '.$this->curlConfigValue("{$host}:{$publicPort}:127.0.0.1"),
        ];
        if ($this->application->is_http_basic_auth_enabled) {
            $config[] = 'user = '.$this->curlConfigValue("{$this->application->http_basic_auth_username}:{$this->application->http_basic_auth_password}");
        }
        if ($probeHeader !== null && $probeToken !== null) {
            $config[] = 'header = '.$this->curlConfigValue("{$probeHeader}: {$probeToken}");
        }
        $config[] = 'url = '.$this->curlConfigValue($nonceUrl);

        return [
            'command' => 'curl --config -',
            'input' => implode("\n", $config)."\n",
        ];
    }

    private function curlConfigValue(string $value): string
    {
        return '"'.str_replace(
            ['\\', '"', "\r", "\n"],
            ['\\\\', '\\"', '\\r', '\\n'],
            $value,
        ).'"';
    }

    /** @param array{router: string, url: string} $route */
    private function assertHttpResponse(
        array $route,
        string $headers,
        ?string $expectedAcknowledgement,
    ): void {
        preg_match_all('/^HTTP\/(?:1\.[01]|2|3)\s+(\d{3})\b/mi', $headers, $statusMatches);
        $statuses = $statusMatches[1];
        $status = (int) (end($statuses) ?: 0);
        if ($status === 0 || $status === 404 || $status >= 500) {
            throw new DeploymentException("Blue-green router {$route['router']} returned gateway/server status {$status}.");
        }

        preg_match_all('/^'.preg_quote(BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER, '/').':\s*(.*?)\s*$/mi', $headers, $acknowledgementMatches);
        $acknowledgements = array_values(array_unique(array_filter(
            $acknowledgementMatches[1],
            fn (string $acknowledgement): bool => trim($acknowledgement) !== '',
        )));
        if ($expectedAcknowledgement === null) {
            if ($acknowledgements !== []) {
                throw new DeploymentException("Public blue-green router {$route['router']} leaked the reserved probe acknowledgement.");
            }

            return;
        }
        if ($acknowledgements !== [$expectedAcknowledgement]) {
            throw new DeploymentException("Blue-green router {$route['router']} did not return its exact opaque acknowledgement.");
        }
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
            try {
                $this->assertOperationOwned(BlueGreenDeploymentPhase::IDLE);
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
            $this->assertOperationOwned(
                BlueGreenDeploymentPhase::PREPARING,
                BlueGreenDeploymentPhase::SWITCHING,
            );
            TransitionsBlueGreenDeployment::beginRollback($claim);
            if ($this->proxyChanged && $claim->legacyContainerName !== null) {
                $rollbackKey = $this->rollbackKey
                    ?? throw new DeploymentException('Legacy routing changed without durable rollback ownership.');
                $this->assertOperationOwned(BlueGreenDeploymentPhase::ROLLING_BACK);
                $artifact = BlueGreenProxyRollbackArtifactReader::run($this->server, $rollbackKey);
                if ($artifact->existed) {
                    throw new DeploymentException('First-adoption rollback refuses to restore a pre-existing unmanaged routing file.');
                }
                $expectation = $this->previousContainerExpectation
                    ?? throw new DeploymentException('The legacy rollback target has no durable Docker identity.');
                $snapshot = $this->legacyRoutingSnapshot
                    ?? throw new DeploymentException('Legacy routing changed without its durable pre-stop routing snapshot.');
                $this->writeAndVerifyRouting(
                    $this->routingTarget(
                        activeColor: $claim->pendingColor,
                        publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($claim->deploymentUuid),
                        mode: BlueGreenRoutingMode::LegacyAdoption,
                    ),
                    recordRoutingMutation: false,
                    expectedPhase: BlueGreenDeploymentPhase::ROLLING_BACK,
                );
                $this->assertOperationOwned(BlueGreenDeploymentPhase::ROLLING_BACK);
                $inspection = InspectBlueGreenContainer::run($this->server, $expectation);
                $this->assertOperationOwned(BlueGreenDeploymentPhase::ROLLING_BACK);
                EnsureBlueGreenPreviousContainerRunning::run(
                    $this->server,
                    $this->application,
                    $expectation,
                    $inspection,
                );
                $this->waitForExactPreviousContainerHealth();
                $this->assertOperationOwned(BlueGreenDeploymentPhase::ROLLING_BACK);
                $snapshot = RebindBlueGreenLegacyRoutingSnapshot::run(
                    $this->server,
                    $this->application,
                    $this->destination,
                    $expectation,
                    $snapshot,
                );
                $this->legacyRoutingSnapshot = $snapshot;
                $bridgeTarget = $this->routingTarget(
                    activeColor: $claim->pendingColor,
                    publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($claim->deploymentUuid),
                    mode: BlueGreenRoutingMode::LegacyRecoveryBridge,
                    legacyContainerName: $snapshot->containerName,
                );
                $this->writeAndVerifyRouting(
                    $bridgeTarget,
                    recordRoutingMutation: false,
                    expectedPhase: BlueGreenDeploymentPhase::ROLLING_BACK,
                );
                $bridgeConfiguration = $this->lastConfiguration
                    ?? throw new DeploymentException('The legacy recovery bridge did not produce a route inventory.');
                $bridgeRoutes = (new PlanBlueGreenPublicRecovery)->routesForYaml($bridgeConfiguration->yaml);
                $this->assertOperationOwned(BlueGreenDeploymentPhase::ROLLING_BACK);
                WaitForBlueGreenLegacyDockerRouting::run(
                    $this->server,
                    $snapshot,
                    BlueGreenLegacyProviderState::Active,
                    min(300, max(10, (int) $this->application->health_check_retries)),
                    $this->checkForCancellation,
                );
                $this->assertOperationOwned(BlueGreenDeploymentPhase::ROLLING_BACK);
                BlueGreenProxyRollbackArtifactRestorer::run($this->server, $rollbackKey);
                $this->assertOperationOwned(BlueGreenDeploymentPhase::ROLLING_BACK);
                VerifyBlueGreenLegacyProviderRecovery::run(
                    $this->server,
                    $this->application,
                    $snapshot,
                    $bridgeRoutes,
                );
            } elseif ($this->proxyChanged) {
                $rollbackKey = $this->rollbackKey
                    ?? throw new DeploymentException('Blue-green routing changed without a durable rollback key.');
                $this->waitForExactPreviousContainerHealth();
                $this->assertOperationOwned(BlueGreenDeploymentPhase::ROLLING_BACK);
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
                BlueGreenProxyRollbackArtifactRestorer::run($this->server, $rollbackKey);
                if ($previousRoutes !== [] && $previousAcknowledgement !== null) {
                    $this->assertOperationOwned(BlueGreenDeploymentPhase::ROLLING_BACK);
                    VerifyBlueGreenPublicRecovery::run(
                        $this->server,
                        $this->application,
                        $previousRoutes,
                        $previousAcknowledgement,
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
        RemoveExactBlueGreenCandidate::run(
            $this->server,
            $this->application,
            $expectation,
            $inspection,
        );
    }

    private function assertOperationOwned(BlueGreenDeploymentPhase ...$expectedPhases): BlueGreenDeploymentPhase
    {
        $claim = $this->claim
            ?? throw new DeploymentException('The blue-green lifecycle has no durable operation to fence.');
        $fence = $this->operationFence
            ?? throw new DeploymentException('The blue-green lifecycle has no owned cache lock to fence remote work.');

        return $fence->assertDeploymentOwnership($claim, $expectedPhases);
    }

    private function assertLifecycleLockOwned(): void
    {
        $fence = $this->operationFence
            ?? throw new DeploymentException('The blue-green lifecycle has no owned cache lock to fence remote work.');
        $fence->assertLockOwnership();
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
