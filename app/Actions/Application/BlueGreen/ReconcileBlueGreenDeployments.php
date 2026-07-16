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
        {--stale-after=300 : Refuse queue owners updated within this many seconds}';

    public string $commandDescription = 'Fail-closed reconciliation for interrupted application blue-green deployments.';

    /** @return list<BlueGreenReconciliationResult> */
    public function handle(
        ?int $applicationId = null,
        ?int $standaloneDockerId = null,
        int $staleAfterSeconds = 300,
    ): array {
        if ($staleAfterSeconds < 1) {
            throw new \InvalidArgumentException('The reconciliation stale window must be positive.');
        }

        $states = ApplicationBlueGreenDeployment::query()
            ->where(function ($query): void {
                $query->whereNotNull('operation_deployment_uuid')
                    ->orWhere('phase', BlueGreenDeploymentPhase::PREPARING->value);
            })
            ->when($applicationId !== null, fn ($query) => $query->where('application_id', $applicationId))
            ->when($standaloneDockerId !== null, fn ($query) => $query->where('standalone_docker_id', $standaloneDockerId))
            ->orderBy('id')
            ->get();

        return $states
            ->map(fn (ApplicationBlueGreenDeployment $state): BlueGreenReconciliationResult => ReconcileBlueGreenDeployment::run($state, $staleAfterSeconds))
            ->all();
    }

    public function asCommand(Command $command): int
    {
        $applicationId = $this->integerOption($command, 'application');
        $destinationId = $this->integerOption($command, 'destination');
        $staleAfterSeconds = $this->requiredPositiveIntegerOption($command, 'stale-after');
        $results = $this->handle($applicationId, $destinationId, $staleAfterSeconds);

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

    private function integerOption(Command $command, string $name): ?int
    {
        $value = $command->option($name);
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new \InvalidArgumentException("The --{$name} option must be a positive integer.");
        }

        return (int) $value;
    }

    private function requiredPositiveIntegerOption(Command $command, string $name): int
    {
        return $this->integerOption($command, $name)
            ?? throw new \InvalidArgumentException("The --{$name} option must be a positive integer.");
    }
}
