<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Support\ProxyMutationExecutionPipe;
use App\Support\ProxyMutationQueue;
use App\Support\ProxyMutationRedisConnector;
use App\Support\ProxyMutationRedisQueue;
use Illuminate\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;
use Laravel\Telescope\TelescopeServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (App::isLocal()) {
            $this->app->register(TelescopeServiceProvider::class);
        }
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
        ProxyMutationQueue::redisConnectionName();

        $queueManager = $this->app->make(QueueManager::class);
        foreach (config('queue.connections', []) as $connectionName => $connection) {
            if (! is_array($connection)
                || ($connection['driver'] ?? null) !== 'redis'
                || ! $queueManager->connected($connectionName)) {
                continue;
            }

            if (! $queueManager->connection($connectionName) instanceof ProxyMutationRedisQueue) {
                throw new \LogicException("The Redis queue connection [{$connectionName}] resolved before the proxy-mutation gate.");
            }
        }

        $queueManager->addConnector('redis', fn (): ProxyMutationRedisConnector => new ProxyMutationRedisConnector(
            $this->app['redis'],
        ));

        if (! $queueManager->connection(ProxyMutationQueue::CONNECTION) instanceof ProxyMutationRedisQueue) {
            throw new \LogicException('The canonical proxy-mutation queue connection must resolve through the mutation gate.');
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
