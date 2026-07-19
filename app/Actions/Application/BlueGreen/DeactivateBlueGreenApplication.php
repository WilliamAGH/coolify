<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
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

        foreach ($preparations as $preparation) {
            $deactivation = $preparation->deactivation;
            $operationId = $deactivation->operation_id;
            $supersessionGeneration = (int) $deactivation->supersession_generation;
            if (! is_string($operationId) || $supersessionGeneration < 1) {
                throw new BlueGreenDeactivationException('A prepared blue-green destination has no exact durable deactivation owner.');
            }

            if (! DeactivateBlueGreenApplicationDestination::run(
                $tombstonedApplication,
                (int) $deactivation->standalone_docker_id,
                (int) $deactivation->id,
                $operationId,
                $supersessionGeneration,
            )) {
                throw new BlueGreenDeactivationException('Blue-green destination deactivation did not prove completion.');
            }
            $deactivation->refresh();
        }

        return $preparations;
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
