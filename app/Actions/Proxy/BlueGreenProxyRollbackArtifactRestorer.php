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
        ?BlueGreenProxyState $currentState = null,
        ?BlueGreenProxyState $restoredState = null,
    ): void {
        if (($currentState === null) !== ($restoredState === null)) {
            throw new InvalidArgumentException('Blue/green rollback restoration requires both current and restored destination states.');
        }
        if ($currentState !== null && $restoredState !== null) {
            $this->restoreFromCurrentState(
                $server,
                $rollbackKey,
                $currentState,
                $restoredState,
                $expectedBootId,
            );

            return;
        }
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new InvalidArgumentException('Blue/green proxy rollback requires a Traefik server.');
        }
        instant_privileged_remote_script(
            $this->commandFor($server->proxyPath(), $rollbackKey, $expectedBootId),
            $server,
        );
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

    public function restoreFromCurrentState(
        Server $server,
        BlueGreenProxyRollbackKey $rollbackKey,
        BlueGreenProxyState $currentState,
        BlueGreenProxyState $restoredState,
        string $expectedBootId,
    ): void {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new InvalidArgumentException('Blue/green proxy rollback requires a Traefik server.');
        }
        instant_privileged_remote_script(
            (new WriteBlueGreenProxyConfiguration)->rollbackArtifactRestoreFromStateCommandFor(
                $server->proxyPath(),
                $rollbackKey,
                $currentState,
                $restoredState,
                $expectedBootId,
            ),
            $server,
        );
    }
}
