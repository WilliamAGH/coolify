<?php

namespace App\Livewire\Server\Proxy;

use App\Models\Server;
use App\Support\ProxyDynamicConfigurationFilenamePolicy;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DynamicConfigurationNavbar extends Component
{
    use AuthorizesRequests;

    public $server_id;

    public Server $server;

    public $fileName = '';

    public $value = '';

    public $newFile = false;

    public function delete(string $fileName)
    {
        $this->authorize('update', $this->server);

        $file = str_replace('|', '.', $fileName);

        if (ProxyDynamicConfigurationFilenamePolicy::isReadOnly($file)) {
            $this->dispatch('error', 'Coolify-managed dynamic configurations are read-only.');

            return;
        }

        validateFilenameSafe($file, 'proxy configuration filename');

        $proxy_path = $this->server->proxyPath();
        $proxy_type = $this->server->proxyType();

        $fullPath = "{$proxy_path}/dynamic/{$file}";
        $escapedPath = escapeshellarg($fullPath);
        instant_remote_process(["rm -f {$escapedPath}"], $this->server);
        if ($proxy_type === 'CADDY') {
            $this->server->reloadCaddy();
        }
        $this->dispatch('success', 'File deleted.');
        $this->dispatch('loadDynamicConfigurations');
        $this->dispatch('refresh');
    }

    public function render()
    {
        return view('livewire.server.proxy.dynamic-configuration-navbar');
    }
}
