<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;

final class RepairBlueGreenSteadyStates
{
    use AsAction;

    private const CURSOR_CACHE_KEY = 'blue-green:steady-repair-cursor';

    public string $commandSignature = 'blue-green:repair-steady {--limit=1 : Repair at most this many IDLE destinations}';

    public string $commandDescription = 'Verify and repair canonical application blue-green steady routes.';

    /** @return list<BlueGreenSteadyStateRepairResult> */
    public function handle(int $limit = 1): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The steady-state repair limit must be positive.');
        }

        $afterId = max(0, (int) Cache::get(self::CURSOR_CACHE_KEY, 0));
        $query = ApplicationBlueGreenDeployment::query()
            ->where('phase', BlueGreenDeploymentPhase::IDLE->value)
            ->whereNotNull('active_color')
            ->whereNull('pending_color')
            ->whereNull('pending_deployment_uuid')
            ->whereNull('operation_deployment_uuid')
            ->whereNull('deactivation_operation_id');
        $states = (clone $query)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();
        if ($states->isEmpty() && $afterId > 0) {
            $states = $query->orderBy('id')->limit($limit)->get();
        }
        if ($states->isNotEmpty()) {
            Cache::put(self::CURSOR_CACHE_KEY, (int) $states->last()->id, now()->addDay());
        }

        return $states
            ->map(fn (ApplicationBlueGreenDeployment $state): BlueGreenSteadyStateRepairResult => RepairBlueGreenSteadyState::run($state))
            ->all();
    }

    public function asCommand(Command $command): int
    {
        $limit = filter_var($command->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1) {
            throw new \InvalidArgumentException('The --limit option must be a positive integer.');
        }
        foreach ($this->handle($limit) as $result) {
            $command->line("state={$result->stateId} outcome={$result->outcome} {$result->message}");
        }

        return Command::SUCCESS;
    }
}
