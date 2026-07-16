<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactReader;
use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class ReadBlueGreenRecoveryArtifact
{
    use AsAction;

    public function handle(Server $server, BlueGreenProxyRollbackKey $rollbackKey): ?BlueGreenProxyRollbackArtifact
    {
        return BlueGreenProxyRollbackArtifactReader::run($server, $rollbackKey);
    }
}
