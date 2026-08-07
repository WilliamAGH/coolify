<?php

namespace App\Console\Commands;

use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\RehydrateBlueGreenDestinationRoutingTopologyDigest;
use App\Models\ApplicationBlueGreenDeployment;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

class BlueGreenRehydrateRoutingTopology extends Command
{
    private const int ITERATION_CHUNK_SIZE = 100;

    protected $signature = 'blue-green:rehydrate-routing-topology
        {--state-id= : Rehydrate one durable blue-green state id}
        {--application-id= : Rehydrate missing states for one application id}
        {--all : Rehydrate every eligible missing state}';

    protected $description = 'Live-attest and establish missing DB-only blue-green routing topology digests';

    public function handle(): int
    {
        $stateIdOption = $this->option('state-id');
        $applicationIdOption = $this->option('application-id');
        $selectors = array_filter([
            $stateIdOption !== null,
            $applicationIdOption !== null,
            (bool) $this->option('all'),
        ]);
        if (count($selectors) !== 1) {
            $this->error('Select exactly one of --state-id, --application-id, or --all.');

            return self::INVALID;
        }
        $stateId = $stateIdOption === null ? null : $this->positiveIntegerOption($stateIdOption);
        $applicationId = $applicationIdOption === null ? null : $this->positiveIntegerOption($applicationIdOption);
        if (($stateIdOption !== null && $stateId === null)
            || ($applicationIdOption !== null && $applicationId === null)) {
            $this->error('--state-id and --application-id must be canonical positive integers.');

            return self::INVALID;
        }

        $query = ApplicationBlueGreenDeployment::query()
            ->whereNull('destination_routing_topology_digest');
        if ($stateId !== null) {
            $query->whereKey($stateId);
        }
        if ($applicationId !== null) {
            $query->where('application_id', $applicationId);
        }

        $failed = false;
        $count = 0;
        $skipped = 0;
        foreach ($query
            ->with([
                'application' => static function (BelongsTo $relation): void {
                    $relation->withTrashed()->with('settings');
                },
                'standaloneDocker.server',
            ])
            ->lazyById(self::ITERATION_CHUNK_SIZE) as $state) {
            $count++;
            $application = $state->application;
            $destination = $state->standaloneDocker;
            if ($application !== null
                && ! $application->trashed()
                && $destination?->server !== null
                && ClaimBlueGreenDeployment::stateDefersRoutingTopologyDigestToClaim(
                    $application,
                    $destination,
                    $state,
                )) {
                $this->info("Deferred clean absent blue-green state {$state->id}; its next exact claim establishes the digest before activation.");

                continue;
            }
            /**
             * A trashed or orphaned owner cannot be remotely attested, so rehydration
             * always throws for it. Reporting that as a failure makes the command
             * permanently red with no action that could ever clear it; surface it as a
             * skip so genuine rehydration failures stay distinguishable.
             */
            if ($application === null || $application->trashed() || $destination?->server === null) {
                $skipped++;
                $this->warn("Skipped blue-green state {$state->id}; it has no live application and server owner to attest against.");

                continue;
            }
            try {
                RehydrateBlueGreenDestinationRoutingTopologyDigest::run($state);
                $this->info("Rehydrated blue-green state {$state->id}.");
            } catch (Throwable $exception) {
                $failed = true;
                $this->error("State {$state->id}: {$exception->getMessage()}");
            }
        }
        if ($count === 0) {
            $this->warn('No missing blue-green routing topology digests matched the selector.');
        }
        if ($skipped > 0) {
            $this->warn("Skipped {$skipped} blue-green state(s) without a live application and server owner.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function positiveIntegerOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($integer) ? $integer : null;
    }
}
