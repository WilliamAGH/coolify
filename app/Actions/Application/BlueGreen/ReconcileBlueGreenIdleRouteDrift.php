<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class ReconcileBlueGreenIdleRouteDrift
{
    use AsAction;

    private const CURSOR_CACHE_KEY = 'blue-green:idle-route-reconciliation:last-state-id';

    public string $commandSignature = 'blue-green:reconcile-idle-routes
        {--limit=1 : Reconcile no more than this many exact idle routes per run}';

    public string $commandDescription = 'Reconcile bounded exact idle blue-green route drift.';

    /**
     * @return array{
     *     repaired: int,
     *     unchanged: int,
     *     busy: int,
     *     failed: int,
     *     outcomes: list<array{state_id: int, outcome: string, message: string}>
     * }
     */
    public function handle(int $limit = 1): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The idle route reconciliation limit must be positive.');
        }

        /** @var array{repaired: int, unchanged: int, busy: int, failed: int, outcomes: list<array{state_id: int, outcome: string, message: string}>} $result */
        $result = [
            'repaired' => 0,
            'unchanged' => 0,
            'busy' => 0,
            'failed' => 0,
            'outcomes' => [],
        ];
        $cursor = $this->cursor();
        $selected = $this->reconcileStatesAfter($cursor, $limit, $result);

        if ($selected < $limit) {
            $this->reconcileStatesBeforeOrAt($cursor, $limit - $selected, $result);
        }

        return $result;
    }

    public function asCommand(Command $command): int
    {
        $result = $this->handle($this->requiredPositiveIntegerOption($command, 'limit'));

        foreach ($result['outcomes'] as $outcome) {
            $command->line("state={$outcome['state_id']} outcome=".str_replace('_', '-', $outcome['outcome'])." {$outcome['message']}");
        }
        if ($result['outcomes'] === []) {
            $command->info('No exact idle blue-green routes were found.');
        }

        $command->line("repaired={$result['repaired']} unchanged={$result['unchanged']} busy={$result['busy']} failed={$result['failed']}");

        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param array{
     *     repaired: int,
     *     unchanged: int,
     *     busy: int,
     *     failed: int,
     *     outcomes: list<array{state_id: int, outcome: string, message: string}>
     * } $result
     */
    private function reconcileStatesAfter(int $cursor, int $limit, array &$result): int
    {
        return $this->reconcileStates(
            $this->exactIdleRouteStates()->where('id', '>', $cursor),
            $limit,
            $result,
        );
    }

    /**
     * @param array{
     *     repaired: int,
     *     unchanged: int,
     *     busy: int,
     *     failed: int,
     *     outcomes: list<array{state_id: int, outcome: string, message: string}>
     * } $result
     */
    private function reconcileStatesBeforeOrAt(int $cursor, int $limit, array &$result): int
    {
        return $this->reconcileStates(
            $this->exactIdleRouteStates()->where('id', '<=', $cursor),
            $limit,
            $result,
        );
    }

    /**
     * @param array{
     *     repaired: int,
     *     unchanged: int,
     *     busy: int,
     *     failed: int,
     *     outcomes: list<array{state_id: int, outcome: string, message: string}>
     * } $result
     */
    private function reconcileStates(Builder $query, int $limit, array &$result): int
    {
        $selected = 0;

        $query
            ->orderBy('id')
            ->limit($limit)
            ->chunkById(1, function (Collection $states) use (&$result, &$selected): void {
                foreach ($states as $state) {
                    $selected++;
                    $this->reconcile($state, $result);
                }
            });

        return $selected;
    }

    private function exactIdleRouteStates(): Builder
    {
        return ApplicationBlueGreenDeployment::query()
            ->select(['id', 'application_id', 'standalone_docker_id'])
            ->where('phase', BlueGreenDeploymentPhase::IDLE->value)
            ->with([
                'application' => static fn (BelongsTo $query): BelongsTo => $query->select('id'),
                'standaloneDocker' => static fn (BelongsTo $query): BelongsTo => $query->select('id', 'server_id'),
            ]);
    }

    /**
     * @param array{
     *     repaired: int,
     *     unchanged: int,
     *     busy: int,
     *     failed: int,
     *     outcomes: list<array{state_id: int, outcome: string, message: string}>
     * } $result
     */
    private function reconcile(ApplicationBlueGreenDeployment $state, array &$result): void
    {
        try {
            $this->persistCursor((int) $state->id);
            $application = $state->application;
            $destination = $state->standaloneDocker;
            if ($application === null || $destination === null) {
                throw new BlueGreenDeploymentTransitionException('The selected idle blue-green route no longer has a live application and destination.');
            }

            $reconciliationOutcome = ReconcileBlueGreenIdleRoutes::run($application, $destination);
            [$outcome, $message] = match ($reconciliationOutcome) {
                BlueGreenIdleRouteReconciliationOutcome::Repaired => [
                    'repaired',
                    'The missing or drifted idle blue-green route was regenerated.',
                ],
                BlueGreenIdleRouteReconciliationOutcome::Unchanged => [
                    'unchanged',
                    'The exact idle blue-green route was already current.',
                ],
                BlueGreenIdleRouteReconciliationOutcome::Busy => [
                    'busy',
                    'A concurrent lifecycle operation owns the destination.',
                ],
            };
        } catch (Throwable $exception) {
            report($exception);
            $outcome = 'failed';
            $message = $exception->getMessage();
        }

        $result[$outcome]++;
        $result['outcomes'][] = [
            'state_id' => (int) $state->id,
            'outcome' => $outcome,
            'message' => $message,
        ];
    }

    private function cursor(): int
    {
        try {
            $cursor = Cache::get(self::CURSOR_CACHE_KEY, 0);
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
        if (is_int($cursor) && $cursor >= 0) {
            return $cursor;
        }

        report(new \UnexpectedValueException('The idle route reconciliation cursor was malformed and has been reset.'));

        return 0;
    }

    private function persistCursor(int $stateId): void
    {
        if (! Cache::forever(self::CURSOR_CACHE_KEY, $stateId)) {
            throw new \RuntimeException('The idle route reconciliation cursor could not be persisted before the destination attempt.');
        }
    }

    private function requiredPositiveIntegerOption(Command $command, string $name): int
    {
        $value = $command->option($name);
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new \InvalidArgumentException("The --{$name} option must be a positive integer.");
        }

        return (int) $value;
    }
}
