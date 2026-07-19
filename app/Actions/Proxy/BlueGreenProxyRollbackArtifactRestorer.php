<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class BlueGreenProxyRollbackArtifactRestorer
{
    use AsAction;

    public function handle(
        Server $server,
        BlueGreenProxyRollbackKey $rollbackKey,
        string $expectedBootId,
    ): void {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new InvalidArgumentException('Blue/green proxy rollback requires a Traefik server.');
        }
        instant_remote_process([
            $this->commandFor($server->proxyPath(), $rollbackKey, $expectedBootId),
        ], $server);
    }

    public function commandFor(
        string $proxyPath,
        BlueGreenProxyRollbackKey $rollbackKey,
        string $expectedBootId,
    ): string {
        return (new WriteBlueGreenProxyConfiguration)->rollbackArtifactRestoreCommandFor(
            $proxyPath,
            $rollbackKey,
            $expectedBootId,
        );
    }
}
