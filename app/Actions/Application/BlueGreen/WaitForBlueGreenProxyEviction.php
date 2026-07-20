<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\Application;
use App\Models\Server;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class WaitForBlueGreenProxyEviction
{
    use AsAction;

    public function handle(
        Server $server,
        Application $application,
        BlueGreenProxyDeactivationSnapshot $snapshot,
        BlueGreenProxyEvictionState $expectedState,
        string $expectedServerBootId,
        int $attempts = BlueGreenProxyDeactivationSnapshot::ROUTE_CONVERGENCE_ATTEMPTS,
    ): void {
        try {
            $this->assertBootIdentity($server, $expectedServerBootId);
            $this->waitForExpectedRoutes($server, $application, $snapshot, $expectedState, $attempts);
            $this->assertBootIdentity($server, $expectedServerBootId);
        } catch (InvalidArgumentException $exception) {
            throw new BlueGreenDeactivationException(
                'The durable route-convergence snapshot is malformed and requires intervention: '.$exception->getMessage(),
                (int) $exception->getCode(),
                $exception,
            );
        }
    }

    private function waitForExpectedRoutes(
        Server $server,
        Application $application,
        BlueGreenProxyDeactivationSnapshot $snapshot,
        BlueGreenProxyEvictionState $expectedState,
        int $attempts,
    ): void {
        if ($attempts < 1 || $attempts > 300) {
            throw new InvalidArgumentException('Traefik eviction attempts must be between 1 and 300.');
        }

        $attemptDeadlineUnixSeconds = $this->destinationUnixSeconds($server)
            + BlueGreenProxyDeactivationSnapshot::DRAIN_ATTEMPT_SECONDS;
        $requests = new VerifyBlueGreenPublicRecovery;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $allRoutesExpected = true;
            foreach ($snapshot->routes as $route) {
                $remainingSeconds = $this->remainingDeadlineSeconds(
                    $server,
                    $attemptDeadlineUnixSeconds,
                    $snapshot->deactivationDeadlineUnixSeconds,
                );
                $response = $this->requestResponse(
                    $server,
                    $application,
                    $requests,
                    $route,
                    min(15, $remainingSeconds),
                );
                $this->remainingDeadlineSeconds(
                    $server,
                    $attemptDeadlineUnixSeconds,
                    $snapshot->deactivationDeadlineUnixSeconds,
                );
                if ($response['status'] === 0) {
                    throw new BlueGreenDeactivationInProgressException("Managed router {$route['router']} did not return an HTTP status during eviction.");
                }
                if ($response['status'] >= 500) {
                    throw new BlueGreenDeactivationException("Managed router {$route['router']} returned an unsafe gateway/server status during eviction.");
                }
                if (! $this->matchesExpectedState($response, $snapshot, $expectedState)) {
                    $allRoutesExpected = false;
                }
            }
            if ($allRoutesExpected) {
                return;
            }
            if ($attempt < $attempts) {
                Sleep::for(1)->seconds();
            }
        }

        throw new BlueGreenDeactivationInProgressException(
            $expectedState === BlueGreenProxyEvictionState::Tombstone
                ? 'Traefik did not acknowledge every exact blue-green tombstone route before the deadline.'
                : 'Traefik did not prove every exact blue-green route absent before the deadline.',
        );
    }

    private function assertBootIdentity(Server $server, string $expectedServerBootId): void
    {
        ExecuteBlueGreenDeactivationRemoteCommand::run(
            $server,
            (new ReadBlueGreenServerBootIdentity)->assertionCommandFor($expectedServerBootId).' || exit 75',
        );
    }

    private function destinationUnixSeconds(Server $server): int
    {
        $destinationUnixSeconds = ExecuteBlueGreenDeactivationRemoteCommand::run($server, 'date +%s');
        if (preg_match('/^[1-9][0-9]*$/D', $destinationUnixSeconds) !== 1) {
            throw new BlueGreenDeactivationTransportException('The destination did not return a valid clock value for bounded route convergence.');
        }

        return (int) $destinationUnixSeconds;
    }

    private function remainingDeadlineSeconds(
        Server $server,
        int $attemptDeadlineUnixSeconds,
        int $deactivationDeadlineUnixSeconds,
    ): int {
        $destinationUnixSeconds = $this->destinationUnixSeconds($server);
        if ($destinationUnixSeconds >= $deactivationDeadlineUnixSeconds) {
            throw new BlueGreenDeactivationException('The durable blue-green deactivation deadline expired before Traefik converged.');
        }
        if ($destinationUnixSeconds >= $attemptDeadlineUnixSeconds) {
            throw new BlueGreenDeactivationInProgressException('This bounded route-convergence attempt ended before the durable overall deadline; resume the same operation.');
        }

        return min($attemptDeadlineUnixSeconds, $deactivationDeadlineUnixSeconds) - $destinationUnixSeconds;
    }

    /** @param array{router: string, url: string} $route */
    private function requestResponse(
        Server $server,
        Application $application,
        VerifyBlueGreenPublicRecovery $requests,
        array $route,
        int $maxTimeSeconds,
    ): array {
        $request = $requests->requestFor(
            $application,
            $route,
            nonceParameter: '__coolify_blue_green_eviction',
            maxTimeSeconds: $maxTimeSeconds,
        );
        try {
            $headers = (string) instant_remote_process(
                [$request['command']],
                $server,
                timeout: min(
                    BlueGreenDeploymentLock::deactivationRemoteTimeoutSeconds(),
                    $maxTimeSeconds + 5,
                ),
                input: $request['input'],
                retry: false,
            );
        } catch (Throwable $exception) {
            throw new BlueGreenDeactivationInProgressException(
                "Managed router {$route['router']} reset or timed out during eviction.",
                (int) $exception->getCode(),
                $exception,
            );
        }

        return $requests->responseFor($headers);
    }

    /** @param array{status: int, acknowledgements: list<string>} $response */
    private function matchesExpectedState(
        array $response,
        BlueGreenProxyDeactivationSnapshot $snapshot,
        BlueGreenProxyEvictionState $expectedState,
    ): bool {
        return match ($expectedState) {
            BlueGreenProxyEvictionState::Tombstone => $response['status'] === 418
                && $response['acknowledgements'] === [$snapshot->tombstoneAcknowledgement],
            BlueGreenProxyEvictionState::Absent => $response['status'] === 404
                && $response['acknowledgements'] === [],
        };
    }
}
