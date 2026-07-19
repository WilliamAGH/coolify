<?php

namespace App\Actions\Application\BlueGreen;

use App\Jobs\RetireBlueGreenInactiveContainerJob;
use App\Models\ApplicationBlueGreenDeployment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResumeBlueGreenInactiveRetirements
{
    use AsAction;

    public string $commandSignature = 'blue-green:retire-inactive {--limit=10 : Dispatch at most this many due retirements}';

    public string $commandDescription = 'Redispatch durable due inactive blue-green container retirements.';

    public const DISPATCH_RESERVATION_SECONDS = 120;

    public function handle(int $limit = 10): int
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The inactive retirement dispatch limit must be positive.');
        }
        $states = DB::transaction(function () use ($limit) {
            $now = now();
            $states = ApplicationBlueGreenDeployment::query()
                ->whereNotNull('inactive_retirement_owner_deployment_uuid')
                ->whereNotNull('inactive_retirement_not_before_at')
                ->whereNotNull('inactive_retirement_lease_seconds')
                ->where('inactive_retirement_not_before_at', '<=', $now)
                ->whereNull('inactive_retirement_stopped_at')
                ->whereNull('inactive_retirement_intervention_required_at')
                ->where(function ($query) use ($now): void {
                    $query->whereNull('inactive_retirement_dispatch_reserved_until_at')
                        ->orWhere('inactive_retirement_dispatch_reserved_until_at', '<=', $now);
                })
                ->orderBy('inactive_retirement_not_before_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->limit($limit)
                ->get();
            foreach ($states as $state) {
                $state->update([
                    'inactive_retirement_dispatch_reserved_until_at' => $now->copy()->addSeconds(self::DISPATCH_RESERVATION_SECONDS),
                ]);
            }

            return $states;
        }, attempts: 5);
        foreach ($states as $state) {
            RetireBlueGreenInactiveContainerJob::dispatch(
                $state->id,
                $state->inactive_retirement_owner_deployment_uuid,
                $state->inactive_retirement_supersession_generation,
                BlueGreenDeploymentLock::inactiveRetirementJobTimeoutSeconds(
                    $state->inactive_retirement_lease_seconds,
                ),
            );
        }

        return $states->count();
    }

    public function asCommand(Command $command): int
    {
        $count = $this->handle((int) $command->option('limit'));
        $command->info("Dispatched {$count} due inactive blue-green retirement(s).");

        return Command::SUCCESS;
    }
}
