<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\Application;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class VerifyBlueGreenLegacyProviderRecovery
{
    use AsAction;

    /** @param list<array{router: string, url: string}> $routes */
    public function handle(
        Server $server,
        Application $application,
        BlueGreenLegacyRoutingSnapshot $snapshot,
        array $routes,
    ): void {
        WaitForBlueGreenLegacyDockerRouting::run(
            $server,
            $snapshot,
            BlueGreenLegacyProviderState::Active,
            1,
        );
        $requests = new VerifyBlueGreenPublicRecovery;
        foreach ($routes as $route) {
            $request = $requests->requestFor($application, $route);
            $headers = (string) instant_remote_process(
                [$request['command']],
                $server,
                input: $request['input'],
            );
            $this->assertProviderResponse($route, $headers);
        }
        WaitForBlueGreenLegacyDockerRouting::run(
            $server,
            $snapshot,
            BlueGreenLegacyProviderState::Active,
            1,
        );
    }

    /** @param array{router: string, url: string} $route */
    public function assertProviderResponse(array $route, string $headers): void
    {
        preg_match_all('/^HTTP\/(?:1\.[01]|2|3)\s+(\d{3})\b/mi', $headers, $statusMatches);
        $statuses = $statusMatches[1];
        $status = (int) (end($statuses) ?: 0);
        if ($status === 0 || $status === 404 || $status >= 500) {
            throw new RuntimeException("The exact legacy Docker-provider router {$route['router']} returned unsafe status {$status}.");
        }
    }
}
