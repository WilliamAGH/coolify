<?php

namespace App\Listeners;

use App\Contracts\ProxyMutation;
use App\Enums\ProxyTypes;
use App\Events\ProxyStatusChanged;
use App\Events\ProxyStatusChangedUI;
use App\Jobs\CheckTraefikVersionForServerJob;
use App\Models\Server;
use App\Support\ProxyMutationQueue;
use App\Support\UsesProxyMutationQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;

class ProxyStatusChangedNotification implements ProxyMutation, ShouldQueueAfterCommit
{
    use UsesProxyMutationQueue;

    public function __construct() {}

    public function viaQueue(ProxyStatusChanged $event): string
    {
        return ProxyMutationQueue::nameForDispatch();
    }

    public function viaConnection(ProxyStatusChanged $event): string
    {
        return ProxyMutationQueue::connectionNameForDispatch();
    }

    public function handle(ProxyStatusChanged $event)
    {
        ProxyMutationQueue::ensureExecutionAllowed();

        $serverId = $event->data;
        if (is_null($serverId)) {
            return;
        }
        $server = Server::where('id', $serverId)->first();
        if (is_null($server)) {
            return;
        }
        $proxyContainerName = 'coolify-proxy';
        $status = getContainerStatus($server, $proxyContainerName);
        $server->proxy->set('status', $status);
        $server->save();

        $versionCheckDispatched = false;

        if ($status === 'running') {
            $server->setupDefaultRedirect();
            $server->setupDynamicProxyConfiguration();
            $server->proxy->force_stop = false;
            $server->save();

            // Check Traefik version after proxy is running
            if ($server->proxyType() === ProxyTypes::TRAEFIK->value) {
                $traefikVersions = get_traefik_versions();
                if ($traefikVersions !== null) {
                    // Version check job will dispatch ProxyStatusChangedUI when complete
                    CheckTraefikVersionForServerJob::dispatch($server, $traefikVersions);
                    $versionCheckDispatched = true;
                } else {
                    Log::warning('Traefik version check skipped after proxy status change: versions.json data unavailable', [
                        'server_id' => $server->id,
                        'server_name' => $server->name,
                    ]);
                }
            }
        }

        // Only dispatch UI refresh if version check wasn't dispatched
        // (version check job handles its own UI refresh with updated version data)
        if (! $versionCheckDispatched) {
            ProxyStatusChangedUI::dispatch($server->team_id);
        }

        if ($status === 'created') {
            instant_remote_process([
                'docker rm -f coolify-proxy',
            ], $server);
        }
    }
}
