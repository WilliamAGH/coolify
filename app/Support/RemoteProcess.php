<?php

namespace App\Support;

use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use App\Helpers\SshMultiplexingHelper;
use App\Jobs\ProxyMutationTask;
use App\Models\Server;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Spatie\Activitylog\Contracts\Activity;
use Throwable;

final class RemoteProcess
{
    public static function mutation(
        Collection|array $command,
        Server $server,
        ?string $type = null,
        ?string $type_uuid = null,
        ?Model $model = null,
        bool $ignore_errors = false,
        mixed $callEventOnFinish = null,
        mixed $callEventData = null,
    ): Activity {
        ControlPlaneMode::ensureActive('Remote execution');
        $executeInline = ControlPlaneMode::acceptedMutationDrainExecutionActive();
        if ($executeInline) {
            return self::withAcceptedMutationDrainRemoteConfiguration(
                fn (): Activity => self::dispatch(
                    command: $command,
                    server: $server,
                    type: $type,
                    type_uuid: $type_uuid,
                    model: $model,
                    ignore_errors: $ignore_errors,
                    callEventOnFinish: $callEventOnFinish,
                    callEventData: $callEventData,
                    executeInline: true,
                ),
            );
        }

        ProxyMutationQueue::ensureDispatchAllowed();

        return self::dispatch(
            command: $command,
            server: $server,
            type: $type,
            type_uuid: $type_uuid,
            model: $model,
            ignore_errors: $ignore_errors,
            callEventOnFinish: $callEventOnFinish,
            callEventData: $callEventData,
            executeInline: $executeInline,
        );
    }

    private static function dispatch(
        Collection|array $command,
        Server $server,
        ?string $type,
        ?string $type_uuid,
        ?Model $model,
        bool $ignore_errors,
        mixed $callEventOnFinish,
        mixed $callEventData,
        bool $executeInline,
    ): Activity {
        ControlPlaneMode::ensureActive('Remote execution');

        return ControlPlaneMode::withMutationOperationLease(function () use (
            $command,
            $server,
            $type,
            $type_uuid,
            $model,
            $ignore_errors,
            $callEventOnFinish,
            $callEventData,
            $executeInline,
        ): Activity {
            $type = $type ?? ActivityTypes::INLINE->value;
            $command = $command instanceof Collection ? $command->toArray() : $command;

            if ($server->isNonRoot()) {
                $command = parseCommandsByLineForSudo(collect($command), $server);
            }

            $commandString = implode("\n", $command);

            if (Auth::check()) {
                $teamId = Auth::user()->teams->pluck('id');
                if (! $teamId->contains($server->team_id) && ! $teamId->contains(0)) {
                    throw new \Exception('User is not part of the team that owns this server');
                }
            }

            SshMultiplexingHelper::ensureMultiplexedConnection($server);

            $properties = [
                'server_uuid' => $server->uuid,
                'command' => $commandString,
                'type' => $type,
                'type_uuid' => $type_uuid,
                'status' => ProcessStatus::QUEUED->value,
                'team_id' => $server->team_id,
            ];

            $activityLog = activity()
                ->withProperties($properties)
                ->event($type);

            if ($model) {
                $activityLog->performedOn($model);
            }

            $activity = $activityLog->log('[]');

            $task = new ProxyMutationTask(
                activity: $activity,
                ignore_errors: $ignore_errors,
                call_event_on_finish: $callEventOnFinish,
                call_event_data: $callEventData,
                executeInline: $executeInline,
            );
            if ($executeInline) {
                self::executeInline($task);
            } else {
                dispatch($task);
            }

            $activity->refresh();

            return $activity;
        });
    }

    private static function acceptedDrainCommandTimeout(): int
    {
        $commandTimeout = self::positiveTimeout('constants.ssh.accepted_drain_command_timeout');
        $finalizationMargin = self::positiveTimeout('constants.ssh.accepted_drain_finalization_margin');
        $minimumOuterTimeout = self::positiveTimeout('constants.ssh.accepted_drain_minimum_outer_timeout');
        $implicitQueueTimeout = self::positiveTimeout('horizon.defaults.proxy-mutations.timeout');
        $provenOuterTimeout = min($minimumOuterTimeout, $implicitQueueTimeout);

        if ($commandTimeout + $finalizationMargin >= $provenOuterTimeout) {
            throw new LogicException(
                'Accepted proxy-mutation drain cannot prove a safe remote timeout envelope.'
            );
        }

        return $commandTimeout;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public static function withAcceptedMutationDrainRemoteConfiguration(Closure $operation): mixed
    {
        if (! ControlPlaneMode::acceptedMutationDrainExecutionActive()) {
            return $operation();
        }

        return self::withAcceptedDrainRemoteConfiguration(
            self::acceptedDrainCommandTimeout(),
            $operation,
        );
    }

    private static function positiveTimeout(string $configurationKey): int
    {
        $configuredTimeout = config($configurationKey);
        if (is_string($configuredTimeout) && ctype_digit($configuredTimeout)) {
            $configuredTimeout = (int) $configuredTimeout;
        }

        if (! is_int($configuredTimeout) || $configuredTimeout < 1) {
            throw new LogicException(
                "Accepted proxy-mutation drain requires a positive integer {$configurationKey}."
            );
        }

        return $configuredTimeout;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private static function withAcceptedDrainRemoteConfiguration(int $commandTimeout, Closure $operation): mixed
    {
        $originalCommandTimeout = config('constants.ssh.command_timeout');
        $originalMultiplexingEnabled = config('constants.ssh.mux_enabled');
        config()->set('constants.ssh.command_timeout', $commandTimeout);
        config()->set('constants.ssh.mux_enabled', false);

        try {
            return $operation();
        } finally {
            config()->set('constants.ssh.command_timeout', $originalCommandTimeout);
            config()->set('constants.ssh.mux_enabled', $originalMultiplexingEnabled);
        }
    }

    private static function executeInline(ProxyMutationTask $task): void
    {
        try {
            $task->handle();
            $task->activity->refresh();

            if (! in_array($task->activity->getExtraProperty('status'), [
                ProcessStatus::FINISHED->value,
                ProcessStatus::ERROR->value,
            ], true)) {
                throw new LogicException(
                    'Accepted proxy-mutation drain remote activity did not reach a terminal status.'
                );
            }
        } catch (Throwable $exception) {
            $task->failed($exception);

            throw $exception;
        }
    }
}
