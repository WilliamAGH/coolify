<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeactivationPhase;
use App\Models\ApplicationBlueGreenDeactivation;
use Illuminate\Console\Command;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResumeBlueGreenDeactivations
{
    use AsAction;

    public string $commandSignature = 'blue-green:resume-deactivations
        {--application= : Limit resumption to one application ID}
        {--destination= : Limit resumption to one standalone Docker destination ID}
        {--stale-after=300 : Resume operations started at least this many seconds ago}';

    public string $commandDescription = 'Resume stale fail-closed blue-green deactivation operations.';

    /** @return list<BlueGreenDeactivationResumeResult> */
    public function handle(
        ?int $applicationId = null,
        ?int $standaloneDockerId = null,
        int $staleAfterSeconds = 300,
    ): array {
        if ($staleAfterSeconds < 1) {
            throw new \InvalidArgumentException('The deactivation stale window must be positive.');
        }

        return ApplicationBlueGreenDeactivation::query()
            ->where('phase', BlueGreenDeactivationPhase::DEACTIVATING->value)
            ->where(function ($query) use ($staleAfterSeconds): void {
                $query->whereNull('started_at')
                    ->orWhere('started_at', '<=', now()->subSeconds($staleAfterSeconds));
            })
            ->when($applicationId !== null, fn ($query) => $query->where('application_id', $applicationId))
            ->when($standaloneDockerId !== null, fn ($query) => $query->where('standalone_docker_id', $standaloneDockerId))
            ->with('application')
            ->orderBy('id')
            ->get()
            ->map($this->resume(...))
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
            $command->info('No stale blue-green deactivations were found.');
        }

        return collect($results)->contains(
            fn (BlueGreenDeactivationResumeResult $result): bool => $result->outcome === BlueGreenDeactivationResumeResult::INTERVENTION_REQUIRED,
        ) ? Command::FAILURE : Command::SUCCESS;
    }

    private function resume(ApplicationBlueGreenDeactivation $deactivation): BlueGreenDeactivationResumeResult
    {
        if ($deactivation->application === null) {
            return new BlueGreenDeactivationResumeResult(
                $deactivation->id,
                BlueGreenDeactivationResumeResult::SKIPPED,
                'The application no longer exists.',
            );
        }

        try {
            DeactivateBlueGreenApplicationDestination::run(
                $deactivation->application,
                $deactivation->standalone_docker_id,
            );

            return new BlueGreenDeactivationResumeResult(
                $deactivation->id,
                BlueGreenDeactivationResumeResult::RESUMED,
                'The exact durable deactivation operation was resumed.',
            );
        } catch (BlueGreenDeactivationInProgressException) {
            return new BlueGreenDeactivationResumeResult(
                $deactivation->id,
                BlueGreenDeactivationResumeResult::DEFERRED,
                'Another lifecycle owner still holds the shared lock.',
            );
        } catch (BlueGreenDeactivationTransportException $exception) {
            return new BlueGreenDeactivationResumeResult(
                $deactivation->id,
                BlueGreenDeactivationResumeResult::DEFERRED,
                $exception->getMessage(),
            );
        } catch (BlueGreenDeactivationException $exception) {
            return new BlueGreenDeactivationResumeResult(
                $deactivation->id,
                BlueGreenDeactivationResumeResult::INTERVENTION_REQUIRED,
                $exception->getMessage(),
            );
        }
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
