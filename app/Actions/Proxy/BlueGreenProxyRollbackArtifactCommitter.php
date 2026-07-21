<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class BlueGreenProxyRollbackArtifactCommitter
{
    use AsAction;

    public function handle(Server $server, BlueGreenProxyRollbackKey $rollbackKey): void
    {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new InvalidArgumentException('Blue/green proxy rollback requires a Traefik server.');
        }
        instant_privileged_remote_script(
            $this->commandFor($server->proxyPath(), $rollbackKey),
            $server,
        );
    }

    public function commandFor(string $proxyPath, BlueGreenProxyRollbackKey $rollbackKey): string
    {
        return (new WriteBlueGreenProxyConfiguration)->rollbackArtifactCommitCommandFor($proxyPath, $rollbackKey);
    }
}
