<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Support\ControlPlaneMode;
use App\Support\ProxyMutationExecutionPipe;
use App\Support\ProxyMutationQueue;
use App\Support\ProxyMutationRedisConnector;
use App\Support\ProxyMutationRedisQueue;
use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;
use Laravel\Telescope\TelescopeServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->configureControlPlaneMode();

        if (App::isLocal() && ControlPlaneMode::configured() === ControlPlaneMode::Active) {
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    private function configureControlPlaneMode(): void
    {
        if (ControlPlaneMode::configured() === ControlPlaneMode::Active) {
            return;
        }

        $this->app->make(Repository::class)->set([
            'app.debug' => false,
            'app.maintenance.driver' => 'file',
            'app.maintenance.store' => null,
            'queue.default' => 'null',
            'queue.connections.null' => ['driver' => 'null'],
            'queue.failed.driver' => 'null',
            'cache.default' => 'array',
            'session.driver' => 'array',
            'session.lottery' => [0, 100],
            'broadcasting.default' => 'null',
            'mail.default' => 'array',
            'logging.default' => 'stderr',
            'nightwatch.enabled' => false,
            'sentry.dsn' => null,
            'sentry.enable_tracing' => false,
            'sentry.traces_sample_rate' => 0.0,
            'sentry.profiles_sample_rate' => null,
            'sentry.send_default_pii' => false,
            'sentry.enable_logs' => false,
            'sentry.tracing.default_integrations' => false,
            'telescope.enabled' => false,
            'debugbar.enabled' => false,
            'debugbar.storage.enabled' => false,
            'ray.enable' => false,
            'horizon.defaults' => [],
            'horizon.environments' => [],
            'constants.migration.is_migration_enabled' => false,
            'constants.seeder.is_seeder_enabled' => false,
            'constants.horizon.is_horizon_enabled' => false,
            'constants.horizon.is_scheduler_enabled' => false,
            'constants.nightwatch.is_nightwatch_enabled' => false,
            'constants.sentry.sentry_dsn' => null,
        ]);
    }

    public function boot(): void
    {
        $this->configureProxyMutationQueue();
        $this->configureProxyMutationExecutionPipe();
        $this->configureCommands();
        $this->configureModels();
        $this->configurePasswords();
        $this->configureSanctumModel();
        $this->configureGitHubHttp();

    }

    private function configureProxyMutationQueue(): void
    {
        if (ControlPlaneMode::configured() === ControlPlaneMode::Passive) {
            return;
        }

        ProxyMutationQueue::redisConnectionName();

        $queueManager = $this->app->make(QueueManager::class);

        foreach (config('queue.connections', []) as $connectionName => $connection) {
            if (! is_array($connection)
                || ($connection['driver'] ?? null) !== 'redis'
                || ! $queueManager->connected($connectionName)) {
                continue;
            }

            if (! $queueManager->connection($connectionName) instanceof ProxyMutationRedisQueue) {
                throw new LogicException(
                    "The Redis queue connection [{$connectionName}] resolved before the control-plane mutation gate."
                );
            }
        }

        $queueManager->addConnector('redis', fn (): ProxyMutationRedisConnector => new ProxyMutationRedisConnector(
            $this->app['redis'],
        ));

        $canonicalQueue = $queueManager->connection(ProxyMutationQueue::CONNECTION);
        if (! $canonicalQueue instanceof ProxyMutationRedisQueue) {
            throw new LogicException('The canonical proxy-mutation queue connection must resolve through the mutation gate.');
        }

        ProxyMutationQueue::registerPayloadTargetGate();
    }

    private function configureProxyMutationExecutionPipe(): void
    {
        $this->app->make(Dispatcher::class)->pipeThrough([
            ProxyMutationExecutionPipe::class,
        ]);
    }

    private function configureCommands(): void
    {
        if (App::isProduction()) {
            DB::prohibitDestructiveCommands();
        }
    }

    private function configureModels(): void
    {
        // Disabled because it's causing issues with the application
        // Model::shouldBeStrict();
    }

    private function configurePasswords(): void
    {
        Password::defaults(function () {
            return App::isProduction()
                ? Password::min(8)
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols()
                    ->uncompromised()
                : Password::min(8)->letters();
        });
    }

    private function configureSanctumModel(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }

    private function configureGitHubHttp(): void
    {
        Http::macro('GitHub', function (string $api_url, ?string $github_access_token = null) {
            if ($github_access_token) {
                return Http::withHeaders([
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'Accept' => 'application/vnd.github.v3+json',
                    'Authorization' => "Bearer $github_access_token",
                ])->baseUrl($api_url);
            } else {
                return Http::withHeaders([
                    'Accept' => 'application/vnd.github.v3+json',
                ])->baseUrl($api_url);
            }
        });
    }
}
