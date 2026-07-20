<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use Illuminate\Console\Command;
use Lorisleiva\Actions\Concerns\AsAction;

final class ReconcileBlueGreenDeployments
{
    use AsAction;

    public string $commandSignature = 'blue-green:reconcile
        {--application= : Limit reconciliation to one application ID}
        {--destination= : Limit reconciliation to one standalone Docker destination ID}
        {--stale-after=300 : Refuse queue owners updated within this many seconds}
        {--limit=1 : Reconcile at most this many states in deterministic ID order}';

    public string $commandDescription = 'Fail-closed reconciliation for interrupted application blue-green deployments.';

    /** @return list<BlueGreenReconciliationResult> */
    public function handle(
        ?int $applicationId = null,
        ?int $standaloneDockerId = null,
        int $staleAfterSeconds = 300,
        int $limit = 1,
    ): array {
        if ($staleAfterSeconds < 1) {
            throw new \InvalidArgumentException('The reconciliation stale window must be positive.');
        }
        if ($limit < 1) {
            throw new \InvalidArgumentException('The reconciliation limit must be positive.');
        }

        return ApplicationBlueGreenDeployment::query()
            ->whereIn('phase', [
                BlueGreenDeploymentPhase::PREPARING->value,
                BlueGreenDeploymentPhase::SWITCHING->value,
                BlueGreenDeploymentPhase::ROLLING_BACK->value,
                BlueGreenDeploymentPhase::DRAINING->value,
            ])
            ->when($applicationId !== null, fn ($query) => $query->where('application_id', $applicationId))
            ->when($standaloneDockerId !== null, fn ($query) => $query->where('standalone_docker_id', $standaloneDockerId))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(
                fn (ApplicationBlueGreenDeployment $state): BlueGreenReconciliationResult => ReconcileBlueGreenDeployment::run(
                    $state,
                    $staleAfterSeconds,
                ),
            )
            ->all();
    }

    public function asCommand(Command $command): int
    {
        $results = $this->handle(
            applicationId: $this->positiveIntegerOption($command, 'application', required: false),
            standaloneDockerId: $this->positiveIntegerOption($command, 'destination', required: false),
            staleAfterSeconds: $this->positiveIntegerOption($command, 'stale-after'),
            limit: $this->positiveIntegerOption($command, 'limit'),
        );

        foreach ($results as $result) {
            $command->line("state={$result->stateId} outcome={$result->outcome} {$result->message}");
        }
        if ($results === []) {
            $command->info('No interrupted blue-green deployment operations were found.');
        }

        return collect($results)->contains(
            fn (BlueGreenReconciliationResult $result): bool => $result->outcome === BlueGreenReconciliationResult::INTERVENTION_REQUIRED,
        ) ? Command::FAILURE : Command::SUCCESS;
    }

    private function positiveIntegerOption(
        Command $command,
        string $name,
        bool $required = true,
    ): ?int {
        $value = $command->option($name);
        if ($value === null || $value === '') {
            if (! $required) {
                return null;
            }

            throw new \InvalidArgumentException("The --{$name} option must be a positive integer.");
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new \InvalidArgumentException("The --{$name} option must be a positive integer.");
        }

        return (int) $value;
    }
}
