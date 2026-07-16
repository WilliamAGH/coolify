<?php

namespace App\Jobs;

use App\Actions\Server\UpdateCoolify;
use App\Contracts\ProxyMutation;
use App\Models\Server;
use App\Support\ProxyMutationQueue;
use App\Support\UsesProxyMutationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateCoolifyJob implements ProxyMutation, ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use UsesProxyMutationQueue;

    public $timeout = 600;

    public function __construct()
    {
        ProxyMutationQueue::assign($this);
    }

    public function handle(): void
    {
        ProxyMutationQueue::ensureExecutionAllowed();

        try {
            CheckForUpdatesJob::dispatchSync();
            $settings = instanceSettings();
            if (! $settings->new_version_available) {
                Log::info('No new version available. Skipping update.');

                return;
            }

            $server = Server::findOrFail(0);
            if (! $server) {
                Log::error('Server not found. Cannot proceed with update.');

                return;
            }

            Log::info('Starting Coolify update process...');
            UpdateCoolify::run(false); // false means it's not a manual update

            $settings->update(['new_version_available' => false]);
            Log::info('Coolify update completed successfully.');
        } catch (\Throwable $e) {
            Log::error('UpdateCoolifyJob failed: '.$e->getMessage());
            // Consider implementing a notification to administrators
        }
    }
}
