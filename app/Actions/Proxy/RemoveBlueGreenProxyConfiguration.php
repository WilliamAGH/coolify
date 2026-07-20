<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class RemoveBlueGreenProxyConfiguration
{
    use AsAction;

    public function handle(
        Server $server,
        BlueGreenProxyRollbackKey $rollbackKey,
        string $expectedBootId,
    ): BlueGreenProxyRollbackArtifact {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new InvalidArgumentException('Blue/green proxy configuration removal requires a Traefik server.');
        }
        $writer = new WriteBlueGreenProxyConfiguration;
        $output = instant_remote_process([
            $writer->removeCommandFor($server->proxyPath(), $rollbackKey, $expectedBootId),
        ], $server);

        return BlueGreenProxyRollbackArtifact::fromRemoteOutput($rollbackKey, $output ?? '');
    }

    public function commandFor(
        string $proxyPath,
        BlueGreenProxyRollbackKey $rollbackKey,
        string $expectedBootId,
    ): string {
        return (new WriteBlueGreenProxyConfiguration)->removeCommandFor($proxyPath, $rollbackKey, $expectedBootId);
    }
}
