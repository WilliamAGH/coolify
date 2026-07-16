<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class DeactivateBlueGreenApplication
{
    use AsAction;

    public function deletePermanently(Application $application): bool
    {
        $application = Application::withTrashed()->find($application->id);
        if ($application === null) {
            return false;
        }

        $this->beginDeletion($application);
        $tombstonedApplication = Application::withTrashed()->findOrFail($application->id);
        $this->handle($tombstonedApplication);
        $tombstonedApplication->assertBlueGreenDeletionAuthorized();

        return (bool) $tombstonedApplication->forceDelete();
    }

    public function beginDeletion(Application $application): void
    {
        DB::transaction(function () use ($application): void {
            $tombstonedApplication = Application::withTrashed()
                ->whereKey($application->id)
                ->lockForUpdate()
                ->firstOrFail();
            $setting = ApplicationSetting::query()
                ->where('application_id', $tombstonedApplication->id)
                ->lockForUpdate()
                ->first();
            if ($setting === null) {
                throw new BlueGreenDeactivationException('The blue-green application has no durable settings row.');
            }
            $tombstonedApplication->setRelation('settings', $setting);
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
            if (! $tombstonedApplication->trashed()) {
                $tombstonedApplication->delete();
            }

            $startedAt = now();
            if ($tombstonedApplication->deleted_at !== null
                && $startedAt->lt($tombstonedApplication->deleted_at->copy()->addSecond())) {
                $startedAt = $tombstonedApplication->deleted_at->copy()->addSecond();
            }
            $queueCutoffId = (int) ApplicationDeploymentQueue::query()->max('id');
            foreach (ResolveBlueGreenApplicationDestinationIds::run($tombstonedApplication) as $standaloneDockerId) {
                $deactivation = ApplicationBlueGreenDeactivation::query()
                    ->where('application_id', $tombstonedApplication->id)
                    ->where('standalone_docker_id', $standaloneDockerId)
                    ->lockForUpdate()
                    ->first();
                if ($deactivation?->phase === BlueGreenDeactivationPhase::DEACTIVATING) {
                    continue;
                }

                $attributes = [
                    'operation_id' => bin2hex(random_bytes(32)),
                    'started_at' => $startedAt,
                    'queue_cutoff_id' => $queueCutoffId,
                    'proxy_snapshot' => null,
                    'phase' => BlueGreenDeactivationPhase::DEACTIVATING->value,
                    'completed_at' => null,
                ];
                if ($deactivation === null) {
                    ApplicationBlueGreenDeactivation::create([
                        'application_id' => $tombstonedApplication->id,
                        'standalone_docker_id' => $standaloneDockerId,
                        ...$attributes,
                    ]);
                } else {
                    $deactivation->update($attributes);
                }
            }

            ApplicationDeploymentQueue::query()
                ->where('application_id', $tombstonedApplication->id)
                ->whereIn('status', [
                    ApplicationDeploymentStatus::QUEUED->value,
                    ApplicationDeploymentStatus::IN_PROGRESS->value,
                ])
                ->update([
                    'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
                    'finished_at' => now(),
                ]);

            $application->setAttribute($application->getDeletedAtColumn(), $tombstonedApplication->deleted_at);
            $application->syncOriginalAttribute($application->getDeletedAtColumn());
        }, attempts: 5);
    }

    /** @return Collection<int, ApplicationBlueGreenDeployment> */
    public function handle(Application $application): Collection
    {
        $states = ApplicationBlueGreenDeployment::query()
            ->where('application_id', $application->id)
            ->with('standaloneDocker.server')
            ->orderBy('standalone_docker_id')
            ->get();

        foreach (ResolveBlueGreenApplicationDestinationIds::run($application) as $standaloneDockerId) {
            DeactivateBlueGreenApplicationDestination::run(
                $application,
                $standaloneDockerId,
            );
        }

        return $states;
    }
}
