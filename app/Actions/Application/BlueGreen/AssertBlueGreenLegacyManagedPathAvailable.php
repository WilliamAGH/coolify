<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class AssertBlueGreenLegacyManagedPathAvailable
{
    use AsAction;

    public function handle(Server $server, BlueGreenProxyRollbackKey $rollbackKey): void
    {
        $path = (new WriteBlueGreenProxyConfiguration)->managedPath(
            $server->proxyPath(),
            $rollbackKey->managedFilename,
        );
        $result = trim((string) instant_remote_process([
            $this->commandFor($path),
        ], $server));
        if ($result !== 'available') {
            throw new RuntimeException('First blue-green adoption found a pre-existing or symlinked managed routing path.');
        }
    }

    public function commandFor(string $path): string
    {
        if ($path === '' || ! str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('The managed routing path must be absolute.');
        }
        $path = escapeshellarg($path);

        return "if [ ! -e {$path} ] && [ ! -L {$path} ]; then printf available; else printf occupied; fi";
    }
}
