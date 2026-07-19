<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\Application;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class RebindBlueGreenLegacyRoutingSnapshot
{
    use AsAction;

    public function handle(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        BlueGreenContainerExpectation $expectation,
        BlueGreenLegacyRoutingSnapshot $persistedSnapshot,
    ): BlueGreenLegacyRoutingSnapshot {
        $currentSnapshot = CaptureBlueGreenLegacyRouting::run(
            $server,
            $application,
            $destination,
            $expectation,
        );
        $codec = new BlueGreenLegacyRoutingSnapshotCodec;
        if (! hash_equals(
            $codec->routingIdentitySha256($persistedSnapshot),
            $codec->routingIdentitySha256($currentSnapshot),
        )) {
            throw new RuntimeException('The restarted legacy container routing identity differs from its durable pre-stop snapshot.');
        }

        return $currentSnapshot;
    }
}
