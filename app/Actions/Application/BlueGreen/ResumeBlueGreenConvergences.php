<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Jobs\ConvergeBlueGreenDeploymentJob;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Durable rediscovery owner for automatic blue-green convergence: scans
 * INTERVENTION_REQUIRED destinations and blocked stale queued successors with
 * a wrapping cursor so deferred rows never starve later destinations, then
 * dispatches the exact convergence job per destination. Bounded by a scan cap
 * and a dispatch cap per run.
 */
final class ResumeBlueGreenConvergences
{
    use AsAction;

    public string $commandSignature = 'blue-green:converge
        {--scan=100 : Scan at most this many rows per run}
        {--limit=10 : Dispatch at most this many convergences per run}
        {--stale-after=60 : Only consider queued successors older than this many seconds}
        {--intervention-cooldown=600 : Only re-attempt intervention states untouched for this many seconds}';

    public string $commandDescription = 'Redispatch automatic convergence for intervention-required destinations and blocked queued blue-green successors.';

    public const CURSOR_CACHE_KEY = 'blue-green:converge:cursor';

    public function handle(
        int $scanLimit = 100,
        int $dispatchLimit = 10,
        int $staleAfterSeconds = 60,
        int $interventionCooldownSeconds = 600,
    ): int {
        if ($scanLimit < 1 || $dispatchLimit < 1 || $staleAfterSeconds < 1 || $interventionCooldownSeconds < 1) {
            throw new \InvalidArgumentException('The convergence scan, dispatch, staleness, and cooldown bounds must be positive.');
        }

        // Every recovery attempt touches the state row, so updated_at is the
        // last-attempted signal: a destination whose recovery keeps failing is
        // re-attempted once per cooldown window instead of every scheduler run.
        $cursor = (int) Cache::get(self::CURSOR_CACHE_KEY, 0);
        $attemptedBefore = now()->subSeconds($interventionCooldownSeconds);
        $states = ApplicationBlueGreenDeployment::query()
            ->where('phase', BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value)
            ->where('updated_at', '<=', $attemptedBefore)
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit($scanLimit)
            ->get();
        if ($states->count() < $scanLimit && $cursor > 0) {
            $states = $states->concat(
                ApplicationBlueGreenDeployment::query()
                    ->where('phase', BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value)
                    ->where('updated_at', '<=', $attemptedBefore)
                    ->where('id', '<=', $cursor)
                    ->orderBy('id')
                    ->limit($scanLimit - $states->count())
                    ->get(),
            );
        }
        Cache::put(self::CURSOR_CACHE_KEY, (int) ($states->last()?->getKey() ?? 0), now()->addDay());

        $dispatched = 0;
        $visitedDestinations = [];
        foreach ($states as $state) {
            if ($dispatched >= $dispatchLimit) {
                return $dispatched;
            }
            $trigger = $this->exactTriggerRow($state);
            if ($trigger === null) {
                continue;
            }
            $destinationKey = $state->application_id.':'.$state->standalone_docker_id;
            if (isset($visitedDestinations[$destinationKey])) {
                continue;
            }
            $visitedDestinations[$destinationKey] = true;
            ConvergeBlueGreenDeploymentJob::dispatch(
                (int) $trigger->getKey(),
                (int) $state->application_id,
                (int) $state->standalone_docker_id,
            );
            $dispatched++;
        }

        $blockedSuccessors = ApplicationDeploymentQueue::query()
            ->where('status', ApplicationDeploymentStatus::QUEUED->value)
            ->where('pull_request_id', 0)
            ->whereNotNull('destination_id')
            ->where('updated_at', '<=', now()->subSeconds($staleAfterSeconds))
            ->orderByDesc('id')
            ->limit($scanLimit)
            ->get();
        foreach ($blockedSuccessors as $successor) {
            if ($dispatched >= $dispatchLimit) {
                return $dispatched;
            }
            $destinationKey = $successor->application_id.':'.$successor->destination_id;
            if (isset($visitedDestinations[$destinationKey])) {
                continue;
            }
            $state = ApplicationBlueGreenDeployment::query()
                ->where('application_id', $successor->application_id)
                ->where('standalone_docker_id', $successor->destination_id)
                ->orderBy('id')
                ->first();
            if ($state === null || ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state)) {
                continue;
            }
            $visitedDestinations[$destinationKey] = true;
            ConvergeBlueGreenDeploymentJob::dispatch(
                (int) $successor->getKey(),
                (int) $successor->application_id,
                (int) $successor->destination_id,
            );
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Resolves the exact queue row that names this intervention: the newest
     * queued successor for the destination, else the exact operation, pending,
     * or active-color owner row. Never latest-by-application.
     */
    private function exactTriggerRow(ApplicationBlueGreenDeployment $state): ?ApplicationDeploymentQueue
    {
        $queuedSuccessor = ApplicationDeploymentQueue::query()
            ->where('application_id', $state->application_id)
            ->where('destination_id', $state->standalone_docker_id)
            ->where('pull_request_id', 0)
            ->where('status', ApplicationDeploymentStatus::QUEUED->value)
            ->orderByDesc('id')
            ->first();
        if ($queuedSuccessor !== null) {
            return $queuedSuccessor;
        }

        $exactOwnerUuid = $state->operation_deployment_uuid
            ?? $state->pending_deployment_uuid
            ?? match ($state->active_color?->value) {
                'blue' => $state->blue_deployment_uuid,
                'green' => $state->green_deployment_uuid,
                default => null,
            };
        if (! is_string($exactOwnerUuid) || $exactOwnerUuid === '') {
            return null;
        }

        return ApplicationDeploymentQueue::query()
            ->where('application_id', $state->application_id)
            ->where('deployment_uuid', $exactOwnerUuid)
            ->where('pull_request_id', 0)
            ->first();
    }

    public function asCommand(Command $command): int
    {
        $count = $this->handle(
            scanLimit: (int) $command->option('scan'),
            dispatchLimit: (int) $command->option('limit'),
            staleAfterSeconds: (int) $command->option('stale-after'),
            interventionCooldownSeconds: (int) $command->option('intervention-cooldown'),
        );
        $command->info("Dispatched {$count} blue-green convergence job(s).");

        return Command::SUCCESS;
    }
}
