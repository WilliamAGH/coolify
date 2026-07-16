<?php

namespace App\Providers;

use App\Contracts\CustomJobRepositoryInterface;
use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\User;
use App\Repositories\CustomJobRepository;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Queue;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(JobRepository::class, CustomJobRepository::class);
        $this->app->singleton(CustomJobRepositoryInterface::class, CustomJobRepository::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        parent::boot();
        Queue::createPayloadUsing(function (string $connection, ?string $queue, array $payload): array {
            $job = data_get($payload, 'data.command');
            if (! $job instanceof ApplicationDeploymentJob) {
                return [];
            }
            if (! is_string($job->dispatch_attempt_uuid) || ! Str::isUuid($job->dispatch_attempt_uuid)) {
                throw new DeploymentException('Application deployment jobs require a durable dispatch attempt identity.');
            }

            return ['uuid' => $job->dispatch_attempt_uuid];
        });

        Event::listen(function (JobFailed $event) {
            if (! isCloud()) {
                return;
            }

            $exception = $event->exception;
            if (! ($exception instanceof DeploymentException) && ! ($exception instanceof TimeoutExceededException)) {
                return;
            }

            try {
                $uuid = $event->job->uuid();
                if ($uuid) {
                    app(JobRepository::class)->deleteFailed($uuid);
                }
            } catch (\Throwable $e) {
                // Best-effort scrub; never mask the original failure.
            }
        });
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user) {
            $root_user = User::find(0);

            return in_array($user->email, [
                $root_user->email,
            ]);
        });
    }
}
