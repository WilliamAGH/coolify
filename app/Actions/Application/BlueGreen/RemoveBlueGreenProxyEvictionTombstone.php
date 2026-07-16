<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class RemoveBlueGreenProxyEvictionTombstone
{
    use AsAction;

    public function handle(Server $server, BlueGreenProxyDeactivationSnapshot $snapshot): void
    {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new InvalidArgumentException('Blue-green proxy tombstone removal requires a Traefik server.');
        }
        ExecuteBlueGreenDeactivationRemoteCommand::run(
            $server,
            $this->commandFor($server->proxyPath(), $snapshot),
        );
    }

    public function commandFor(string $proxyPath, BlueGreenProxyDeactivationSnapshot $snapshot): string
    {
        $writer = new WriteBlueGreenProxyConfiguration;
        $managedPath = $writer->managedPath(
            $proxyPath,
            $snapshot->managedFilename,
        );
        $safeManagedPath = escapeshellarg($managedPath);

        return implode("\n", [
            'set -eu',
            'umask 077',
            'mkdir -p -- '.escapeshellarg(dirname($managedPath)),
            ...$writer->exclusiveManagedFileLockCommands($proxyPath, $snapshot->managedFilename),
            'test ! -d '.$safeManagedPath,
            'if [ ! -e '.$safeManagedPath.' ] && [ ! -L '.$safeManagedPath.' ]; then exit 0; fi',
            'test -f '.$safeManagedPath,
            'test ! -L '.$safeManagedPath,
            'current_checksum=$(sha256sum '.$safeManagedPath.')',
            'test "${current_checksum%% *}" = '.escapeshellarg($snapshot->tombstoneSha256),
            'rm -f -- '.$safeManagedPath,
            'sync -f '.escapeshellarg(dirname($managedPath)),
        ]);
    }
}
