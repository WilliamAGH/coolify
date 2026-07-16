<?php

namespace App\Support;

use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use App\Helpers\SshMultiplexingHelper;
use App\Jobs\CoolifyTask;
use App\Jobs\ProxyMutationTask;
use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Contracts\Activity;

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
            taskClass: ProxyMutationTask::class,
        );
    }

    /** @param class-string<CoolifyTask> $taskClass */
    private static function dispatch(
        Collection|array $command,
        Server $server,
        ?string $type,
        ?string $type_uuid,
        ?Model $model,
        bool $ignore_errors,
        mixed $callEventOnFinish,
        mixed $callEventData,
        string $taskClass,
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
            $taskClass,
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

            dispatch(new $taskClass(
                activity: $activity,
                ignore_errors: $ignore_errors,
                call_event_on_finish: $callEventOnFinish,
                call_event_data: $callEventData,
            ));

            $activity->refresh();

            return $activity;
        });
    }
}
