<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeactivationPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class DeactivateBlueGreenApplication
{
    use AsAction;

    public function deletePermanently(Application $application): bool
    {
        $application = Application::withTrashed()->find($application->id);
        if ($application === null) {
            return false;
        }

        $this->handle($application);

        return DB::transaction(function () use ($application): bool {
            $tombstonedApplication = Application::withTrashed()
                ->whereKey($application->id)
                ->lockForUpdate()
                ->first();
            if ($tombstonedApplication === null) {
                return false;
            }
            if (! $tombstonedApplication->trashed()) {
                throw new BlueGreenDeactivationException('Permanent blue-green deletion requires the exact soft-deleted application owner.');
            }

            ApplicationSetting::query()
                ->where('application_id', $tombstonedApplication->id)
                ->lockForUpdate()
                ->get();
            ApplicationBlueGreenDeployment::query()
                ->where('application_id', $tombstonedApplication->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            ApplicationBlueGreenDeactivation::query()
                ->where('application_id', $tombstonedApplication->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            ApplicationDeploymentQueue::query()
                ->where('application_id', $tombstonedApplication->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $tombstonedApplication->assertBlueGreenDeletionAuthorized();

            return (bool) $tombstonedApplication->forceDelete();
        }, attempts: 5);
    }

    /** @return Collection<int, BlueGreenDeactivationPreparation> */
    public function beginDeletion(Application $application): Collection
    {
        $destinationIds = ResolveBlueGreenApplicationDestinationIds::run($application);
        $destinationFences = $this->acquireDestinationFences($application->id, $destinationIds);
        $releasedEveryFence = false;

        try {
            try {
                $preparations = DB::transaction(function () use ($application, $destinationFences): Collection {
                    $tombstonedApplication = Application::withTrashed()
                        ->whereKey($application->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    if (! $tombstonedApplication->trashed()
                        && $tombstonedApplication->delete() !== true) {
                        throw new BlueGreenDeactivationException('The exact blue-green application could not be soft-deleted.');
                    }

                    $this->refreshDestinationFences($destinationFences);
                    $destinationIds = ResolveBlueGreenApplicationDestinationIds::run($tombstonedApplication);
                    $this->assertEveryDestinationIsLocked($destinationIds, $destinationFences);

                    $preparations = collect();
                    foreach ($destinationIds as $standaloneDockerId) {
                        $this->refreshDestinationFences($destinationFences);
                        $preparations->push(PrepareBlueGreenDeactivation::run(
                            $tombstonedApplication,
                            $standaloneDockerId,
                        ));
                    }

                    $this->refreshDestinationFences($destinationFences);
                    $this->assertEveryDestinationIsLocked(
                        ResolveBlueGreenApplicationDestinationIds::run($tombstonedApplication),
                        $destinationFences,
                    );

                    return $preparations;
                }, attempts: 5);
            } catch (BlueGreenOperationFenceLostException $exception) {
                throw new BlueGreenDeactivationInProgressException(
                    'Blue-green application deletion lost one of its destination lifecycle locks before preparation committed.',
                    (int) $exception->getCode(),
                    $exception,
                );
            }
        } finally {
            $releasedEveryFence = $this->releaseDestinationFences($destinationFences);
        }

        if (! $releasedEveryFence) {
            throw new BlueGreenDeactivationInProgressException('Blue-green application deletion prepared its durable owners, but one or more destination lifecycle locks could not be released safely.');
        }

        $tombstonedApplication = Application::withTrashed()->findOrFail($application->id);
        $deletedAtColumn = $application->getDeletedAtColumn();
        $application->setAttribute($deletedAtColumn, $tombstonedApplication->getAttribute($deletedAtColumn));
        $application->syncOriginalAttribute($deletedAtColumn);

        return $preparations;
    }

    /** @return Collection<int, BlueGreenDeactivationPreparation> */
    public function handle(Application $application): Collection
    {
        $preparations = $this->beginDeletion($application);
        $tombstonedApplication = Application::withTrashed()->findOrFail($application->id);

        return $this->deactivatePreparedDestinations($tombstonedApplication, $preparations);
    }

    /** @return Collection<int, BlueGreenDeactivationPreparation> */
    public function stop(
        Application $application,
        ?int $standaloneDockerId = null,
        BlueGreenDeactivationPhase $requestedPhase = BlueGreenDeactivationPhase::STOPPING,
        ?int $expectedAdditionalServerId = null,
    ): Collection {
        if (! in_array($requestedPhase, [BlueGreenDeactivationPhase::STOPPING, BlueGreenDeactivationPhase::REMOVING], true)) {
            throw new \InvalidArgumentException('A manual blue-green stop requires a stopping or removing phase.');
        }
        if ($expectedAdditionalServerId !== null
            && ($requestedPhase !== BlueGreenDeactivationPhase::REMOVING || $standaloneDockerId === null)) {
            throw new \InvalidArgumentException('A blue-green destination-removal reservation requires one exact removing destination and server.');
        }
        $stoppingEveryDestination = $standaloneDockerId === null;
        $destinationIds = $application->blueGreenConfiguredStandaloneDockerDestinationIds();
        if ($standaloneDockerId !== null) {
            if (! $destinationIds->contains($standaloneDockerId)) {
                throw new BlueGreenDeactivationException('The requested blue-green stop destination is not configured for this application.');
            }
            $destinationIds = collect([$standaloneDockerId]);
        }

        $destinationFences = $this->acquireDestinationFences($application->id, $destinationIds);
        $releasedEveryFence = false;
        $preparations = null;
        $result = null;

        try {
            $preparations = DB::transaction(function () use ($application, $destinationIds, $destinationFences, $stoppingEveryDestination, $requestedPhase, $standaloneDockerId, $expectedAdditionalServerId): Collection {
                if ($expectedAdditionalServerId !== null && $standaloneDockerId !== null) {
                    BlueGreenTopologyLock::acquire($application->getConnection());
                }
                $liveApplication = Application::withTrashed()
                    ->whereKey($application->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($liveApplication->trashed()) {
                    throw new BlueGreenDeactivationException('A deleted blue-green application cannot be manually stopped.');
                }

                if ($expectedAdditionalServerId !== null && $standaloneDockerId !== null) {
                    $this->assertExpectedAdditionalDestination(
                        $liveApplication,
                        $standaloneDockerId,
                        $expectedAdditionalServerId,
                    );
                }

                $this->refreshDestinationFences($destinationFences);
                $currentDestinationIds = $stoppingEveryDestination
                    ? $liveApplication->blueGreenConfiguredStandaloneDockerDestinationIds()
                    : $destinationIds;
                $this->assertEveryDestinationIsLocked($currentDestinationIds, $destinationFences);

                $preparations = $currentDestinationIds->map(function (int $destinationId) use ($liveApplication, $destinationFences, $requestedPhase): BlueGreenDeactivationPreparation {
                    $this->refreshDestinationFences($destinationFences);

                    return PrepareBlueGreenDeactivation::run(
                        $liveApplication,
                        $destinationId,
                        requestedPhase: $requestedPhase,
                    );
                });

                if ($stoppingEveryDestination) {
                    $this->refreshDestinationFences($destinationFences);
                    $this->assertEveryDestinationIsLocked(
                        $liveApplication->blueGreenConfiguredStandaloneDockerDestinationIds(),
                        $destinationFences,
                    );
                }

                return $preparations;
            }, attempts: 5);

            $beforeRemoteMutation = $expectedAdditionalServerId === null || $standaloneDockerId === null
                ? null
                : function () use ($application, $standaloneDockerId, $expectedAdditionalServerId): void {
                    $this->assertExpectedAdditionalDestinationBeforeRemoteMutation(
                        $application,
                        $standaloneDockerId,
                        $expectedAdditionalServerId,
                    );
                };
            $result = $this->deactivatePreparedDestinations(
                Application::withTrashed()->findOrFail($application->id),
                $preparations,
                $destinationFences,
                $beforeRemoteMutation,
            );
        } finally {
            $releasedEveryFence = $this->releaseDestinationFences($destinationFences);
        }

        if (! $releasedEveryFence) {
            throw new BlueGreenDeactivationInProgressException('Blue-green application stop could not release one or more destination lifecycle locks safely.');
        }

        return $result ?? throw new \LogicException('Blue-green application stop finished without deactivation preparations.');
    }

    /** @return Collection<int, BlueGreenDeactivationPreparation> */
    public function removeDestination(
        Application $application,
        int $standaloneDockerId,
        int $serverId,
    ): Collection {
        return $this->stop(
            application: $application,
            standaloneDockerId: $standaloneDockerId,
            requestedPhase: BlueGreenDeactivationPhase::REMOVING,
            expectedAdditionalServerId: $serverId,
        );
    }

    /**
     * @param  Collection<int, BlueGreenDeactivationPreparation>  $preparations
     * @param  array<int, BlueGreenOperationFence>  $destinationFences
     * @return Collection<int, BlueGreenDeactivationPreparation>
     */
    private function deactivatePreparedDestinations(
        Application $application,
        Collection $preparations,
        array $destinationFences = [],
        ?Closure $beforeRemoteMutation = null,
    ): Collection {
        foreach ($preparations as $preparation) {
            $deactivation = $preparation->deactivation;
            $operationId = $deactivation->operation_id;
            $supersessionGeneration = (int) $deactivation->supersession_generation;
            if (! is_string($operationId) || $supersessionGeneration < 1) {
                throw new BlueGreenDeactivationException('A prepared blue-green destination has no exact durable deactivation owner.');
            }

            if (! DeactivateBlueGreenApplicationDestination::run(
                $application,
                (int) $deactivation->standalone_docker_id,
                (int) $deactivation->id,
                $operationId,
                $supersessionGeneration,
                $deactivation->phase,
                $destinationFences[(int) $deactivation->standalone_docker_id] ?? null,
                $beforeRemoteMutation,
            )) {
                throw new BlueGreenDeactivationException('Blue-green destination deactivation did not prove completion.');
            }
            $deactivation->refresh();
        }

        return $preparations;
    }

    private function assertExpectedAdditionalDestination(
        Application $application,
        int $standaloneDockerId,
        int $serverId,
    ): void {
        if ($application->blueGreenPrimaryStandaloneDockerDestinationId() === $standaloneDockerId) {
            throw new BlueGreenDeactivationException('The requested destination became the application primary and cannot be remotely deactivated for removal.');
        }

        if (! $application->getConnection()->table('additional_destinations')
            ->where('application_id', $application->id)
            ->where('standalone_docker_id', $standaloneDockerId)
            ->where('server_id', $serverId)
            ->lockForUpdate()
            ->exists()) {
            throw new BlueGreenDeactivationException('The requested destination is no longer an attached additional destination and cannot be remotely deactivated for removal.');
        }
    }

    private function assertExpectedAdditionalDestinationBeforeRemoteMutation(
        Application $application,
        int $standaloneDockerId,
        int $serverId,
    ): void {
        $connection = $application->getConnection();
        $connection->transaction(function () use ($application, $standaloneDockerId, $serverId, $connection): void {
            BlueGreenTopologyLock::acquire($connection);
            $liveApplication = Application::withTrashed()
                ->whereKey($application->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($liveApplication->trashed()) {
                throw new BlueGreenDeactivationException('A deleted blue-green application destination cannot be remotely deactivated for removal.');
            }

            $this->assertExpectedAdditionalDestination($liveApplication, $standaloneDockerId, $serverId);
        }, attempts: 5);
    }

    /**
     * @param  Collection<int, int>  $destinationIds
     * @return array<int, BlueGreenOperationFence>
     */
    private function acquireDestinationFences(int $applicationId, Collection $destinationIds): array
    {
        $leaseSeconds = BlueGreenDeploymentLock::deactivationLeaseSeconds();
        $destinationFences = [];

        try {
            foreach ($destinationIds as $standaloneDockerId) {
                $lifecycleLock = Cache::lock(
                    BlueGreenDeploymentLock::key($applicationId, $standaloneDockerId),
                    $leaseSeconds,
                );
                if (! $lifecycleLock->get()) {
                    throw new BlueGreenDeactivationInProgressException('Another blue-green lifecycle operation owns one of the application destinations required for deletion.');
                }
                $destinationFences[$standaloneDockerId] = new BlueGreenOperationFence(
                    $lifecycleLock,
                    $leaseSeconds,
                );
            }
        } catch (Throwable $exception) {
            $this->releaseDestinationFences($destinationFences);
            if ($exception instanceof BlueGreenDeactivationInProgressException) {
                throw $exception;
            }

            throw new BlueGreenDeactivationInProgressException(
                'Blue-green application deletion could not acquire its complete destination lifecycle lock set.',
                (int) $exception->getCode(),
                $exception,
            );
        }

        return $destinationFences;
    }

    /** @param array<int, BlueGreenOperationFence> $destinationFences */
    private function refreshDestinationFences(array $destinationFences): void
    {
        foreach ($destinationFences as $destinationFence) {
            $destinationFence->assertLockOwnership();
        }
    }

    /**
     * @param  Collection<int, int>  $destinationIds
     * @param  array<int, BlueGreenOperationFence>  $destinationFences
     */
    private function assertEveryDestinationIsLocked(Collection $destinationIds, array $destinationFences): void
    {
        $lockedDestinationIds = collect(array_keys($destinationFences));
        if ($destinationIds->diff($lockedDestinationIds)->isNotEmpty()) {
            throw new BlueGreenDeactivationInProgressException('Blue-green application topology gained a destination outside the deletion lock set. Retry deletion against the new exact topology.');
        }
    }

    /** @param array<int, BlueGreenOperationFence> $destinationFences */
    private function releaseDestinationFences(array $destinationFences): bool
    {
        $releasedEveryFence = true;
        foreach (array_reverse($destinationFences, preserve_keys: true) as $destinationFence) {
            try {
                if (! $destinationFence->releaseIfOwned()) {
                    $releasedEveryFence = false;
                }
            } catch (Throwable $exception) {
                $releasedEveryFence = false;
                report($exception);
            }
        }

        return $releasedEveryFence;
    }
}
