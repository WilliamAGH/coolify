<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class VerifyBlueGreenManagedConfiguration
{
    use AsAction;

    public function handle(Server $server, BlueGreenProxyConfiguration $configuration): void
    {
        $managedPath = (new WriteBlueGreenProxyConfiguration)->managedPath(
            $server->proxyPath(),
            $configuration->managedFilename,
        );
        $output = trim((string) instant_remote_process([
            $this->commandFor($managedPath),
        ], $server));
        if (! hash_equals($configuration->sha256, $output)) {
            throw new RuntimeException('The finalized managed routing file does not match the canonical promoted configuration.');
        }
    }

    public function commandFor(string $managedPath): string
    {
        if ($managedPath === '' || ! str_starts_with($managedPath, '/')) {
            throw new \InvalidArgumentException('The managed routing path must be absolute.');
        }
        $path = escapeshellarg($managedPath);

        return implode("\n", [
            'set -eu',
            'test -f '.$path,
            'test ! -L '.$path,
            'managed_checksum=$(sha256sum '.$path.')',
            'printf %s "${managed_checksum%% *}"',
        ]);
    }
}
