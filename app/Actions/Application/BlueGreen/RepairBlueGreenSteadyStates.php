<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\StandaloneDocker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class RepairBlueGreenSteadyStates
{
    use AsAction;

    private const CURSOR_CACHE_KEY = 'blue-green:steady-repair-cursor';

    public string $commandSignature = 'blue-green:repair-steady
        {--application= : Limit repair to one application ID}
        {--destination= : Limit repair to one standalone Docker destination ID}
        {--limit=1 : Repair at most this many IDLE destinations}';

    public string $commandDescription = 'Verify and repair canonical application blue-green steady routes.';

    public function recoverCleanIdleContainerMutationJournal(
        Application $application,
        StandaloneDocker $destination,
    ): ?BlueGreenProxyState {
        $state = ApplicationBlueGreenDeployment::query()
            ->where('application_id', $application->getKey())
            ->where('standalone_docker_id', $destination->getKey())
            ->first();
        if ($state === null) {
            throw new BlueGreenDeploymentTransitionException('Clean IDLE journal recovery has no durable state for this application destination.');
        }

        $currentDestination = StandaloneDocker::query()
            ->with('server')
            ->find($destination->getKey());
        if ($currentDestination?->server === null
            || (int) $currentDestination->server_id !== (int) $destination->server_id) {
            throw new BlueGreenDeploymentTransitionException('Clean IDLE journal recovery has no exact current destination server.');
        }

        return RecoverCleanIdleBlueGreenContainerMutationJournal::run(
            $currentDestination->server,
            $application,
            $currentDestination,
            $state,
        );
    }

    /** @return list<BlueGreenSteadyStateRepairResult> */
    public function handle(int $limit = 1, ?int $applicationId = null, ?int $standaloneDockerId = null): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The steady-state repair limit must be positive.');
        }

        $query = ApplicationBlueGreenDeployment::query()
            ->where('phase', BlueGreenDeploymentPhase::IDLE->value)
            ->whereNotNull('active_color')
            ->whereNull('pending_color')
            ->whereNull('pending_deployment_uuid')
            ->whereNull('operation_deployment_uuid')
            ->whereNull('deactivation_operation_id')
            ->when($applicationId !== null, fn ($query) => $query->where('application_id', $applicationId))
            ->when($standaloneDockerId !== null, fn ($query) => $query->where('standalone_docker_id', $standaloneDockerId));
        if ($applicationId !== null || $standaloneDockerId !== null) {
            $states = $query->orderBy('id')->limit($limit)->get();
        } else {
            $afterId = max(0, (int) Cache::get(self::CURSOR_CACHE_KEY, 0));
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
        }

        return $states
            ->map(fn (ApplicationBlueGreenDeployment $state): BlueGreenSteadyStateRepairResult => $this->repairConvergingContainerMutationJournal($state))
            ->all();
    }

    public function asCommand(Command $command): int
    {
        $limit = filter_var($command->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1) {
            throw new \InvalidArgumentException('The --limit option must be a positive integer.');
        }
        $results = $this->handle(
            $limit,
            $this->scopeOption($command, 'application'),
            $this->scopeOption($command, 'destination'),
        );
        foreach ($results as $result) {
            $command->line("state={$result->stateId} outcome={$result->outcome} {$result->message}");
        }
        if ($results === []) {
            $command->info('No IDLE blue-green destinations matched the repair scope.');
        }

        return Command::SUCCESS;
    }

    private function repairConvergingContainerMutationJournal(
        ApplicationBlueGreenDeployment $state,
    ): BlueGreenSteadyStateRepairResult {
        $result = RepairBlueGreenSteadyState::run($state);
        if ($result->outcome !== BlueGreenSteadyStateRepairResult::PENDING_CONTAINER_JOURNAL) {
            return $result;
        }

        $application = Application::query()->find($state->application_id);
        $destination = StandaloneDocker::query()->find($state->standalone_docker_id);
        if ($application === null || $destination === null) {
            return new BlueGreenSteadyStateRepairResult(
                (int) $state->id,
                BlueGreenSteadyStateRepairResult::DEFERRED,
                'The journal-fenced IDLE route has no current application destination owner.',
            );
        }

        try {
            $this->recoverCleanIdleContainerMutationJournal($application, $destination);
        } catch (Throwable $exception) {
            return new BlueGreenSteadyStateRepairResult(
                (int) $state->id,
                BlueGreenSteadyStateRepairResult::DEFERRED,
                $exception->getMessage(),
            );
        }

        return RepairBlueGreenSteadyState::run($state);
    }

    private function scopeOption(Command $command, string $name): ?int
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
}
