<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\Server;
use Closure;
use Illuminate\Support\Sleep;
use JsonException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

class WaitForBlueGreenLegacyDockerRouting
{
    use AsAction;

    public function handle(
        Server $server,
        BlueGreenLegacyRoutingSnapshot $snapshot,
        BlueGreenLegacyProviderState $expectedState,
        int $attempts,
        ?Closure $cancellationCheck = null,
    ): void {
        if ($attempts < 1 || $attempts > 300) {
            throw new \InvalidArgumentException('Traefik Docker-provider proof attempts must be between 1 and 300.');
        }
        $lastFailure = 'Traefik rawdata did not expose a typed provider result.';
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $cancellationCheck?->__invoke();
            try {
                $rawData = (string) instant_remote_process([$this->commandFor()], $server);
                $this->assertRawData($rawData, $snapshot, $expectedState);

                return;
            } catch (Throwable $exception) {
                $lastFailure = $exception->getMessage();
            }
            if ($attempt < $attempts) {
                Sleep::for(1)->seconds();
            }
        }

        $state = $expectedState === BlueGreenLegacyProviderState::Active ? 'active and healthy' : 'fully evicted';
        throw new RuntimeException("Traefik did not prove the exact legacy Docker route {$state}: {$lastFailure}");
    }

    public function commandFor(): string
    {
        return implode(' ', [
            'curl',
            '--fail-with-body',
            '--silent',
            '--show-error',
            '--connect-timeout 5',
            '--max-time 15',
            '--url',
            escapeshellarg('http://127.0.0.1:8080/api/rawdata'),
        ]);
    }

    public function assertRawData(
        string $rawData,
        BlueGreenLegacyRoutingSnapshot $snapshot,
        BlueGreenLegacyProviderState $expectedState,
    ): void {
        try {
            $decoded = json_decode($rawData, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Traefik returned malformed rawdata JSON.', 0, $exception);
        }
        if (! is_array($decoded)
            || ! is_array($decoded['routers'] ?? null)
            || ! is_array($decoded['services'] ?? null)) {
            throw new RuntimeException('Traefik rawdata does not contain typed router and service inventories.');
        }

        if ($expectedState === BlueGreenLegacyProviderState::Evicted) {
            $this->assertEvicted($decoded['routers'], $decoded['services'], $snapshot);

            return;
        }
        $this->assertActive($decoded['routers'], $decoded['services'], $snapshot);
    }

    /**
     * @param  array<array-key, mixed>  $routers
     * @param  array<array-key, mixed>  $services
     */
    private function assertEvicted(
        array $routers,
        array $services,
        BlueGreenLegacyRoutingSnapshot $snapshot,
    ): void {
        foreach ($snapshot->routers as $router) {
            if (array_key_exists($router->providerName(), $routers)) {
                throw new RuntimeException("Legacy Docker router {$router->providerName()} is still present in Traefik rawdata.");
            }
        }
        foreach ($snapshot->services as $service) {
            if (array_key_exists($service->providerName(), $services)) {
                throw new RuntimeException("Legacy Docker service {$service->providerName()} is still present in Traefik rawdata.");
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $routers
     * @param  array<array-key, mixed>  $services
     */
    private function assertActive(
        array $routers,
        array $services,
        BlueGreenLegacyRoutingSnapshot $snapshot,
    ): void {
        foreach ($snapshot->routers as $expected) {
            $actual = $routers[$expected->providerName()] ?? null;
            if (! is_array($actual)
                || ($actual['status'] ?? null) !== 'enabled'
                || ($actual['rule'] ?? null) !== $expected->rule
                || ($actual['service'] ?? null) !== $expected->serviceName
                || ($actual['priority'] ?? null) !== $expected->priority) {
                throw new RuntimeException("Legacy Docker router {$expected->providerName()} is not the exact enabled canonical route.");
            }
            $this->assertExactStringList(
                $actual['entryPoints'] ?? null,
                $expected->entryPoints,
                "router {$expected->providerName()} entry points",
            );
            $this->assertExactStringList(
                $actual['using'] ?? null,
                $expected->entryPoints,
                "router {$expected->providerName()} provider usage",
            );
            $this->assertExactStringList(
                $actual['middlewares'] ?? [],
                $expected->providerMiddlewareNames(),
                "router {$expected->providerName()} middleware",
            );
            $tls = $actual['tls'] ?? null;
            if ($expected->tls) {
                if (! is_array($tls)) {
                    throw new RuntimeException("Legacy Docker router {$expected->providerName()} is missing TLS state.");
                }
                $actualResolver = $tls['certResolver'] ?? null;
                if ($actualResolver !== $expected->certificateResolver) {
                    throw new RuntimeException("Legacy Docker router {$expected->providerName()} has different TLS resolver state.");
                }
            } elseif ($tls !== null) {
                throw new RuntimeException("Legacy Docker router {$expected->providerName()} unexpectedly has TLS state.");
            }
        }

        foreach ($snapshot->services as $expected) {
            $actual = $services[$expected->providerName()] ?? null;
            if (! is_array($actual) || ($actual['status'] ?? null) !== 'enabled') {
                throw new RuntimeException("Legacy Docker service {$expected->providerName()} is not enabled.");
            }
            $this->assertExactStringList(
                $actual['usedBy'] ?? null,
                $expected->providerRouterNames(),
                "service {$expected->providerName()} router ownership",
            );
            $servers = data_get($actual, 'loadBalancer.servers');
            $serverStatus = $actual['serverStatus'] ?? null;
            if (! is_array($servers) || $servers === [] || ! is_array($serverStatus) || $serverStatus === []) {
                throw new RuntimeException("Legacy Docker service {$expected->providerName()} has no typed backend health inventory.");
            }
            $serverUrls = [];
            foreach ($servers as $server) {
                $url = is_array($server) ? ($server['url'] ?? null) : null;
                if (! is_string($url)) {
                    throw new RuntimeException("Legacy Docker service {$expected->providerName()} has a malformed backend URL.");
                }
                $this->assertBackendUrl($url, $snapshot, $expected);
                $serverUrls[] = $url;
            }
            sort($serverUrls);
            $statusUrls = array_keys($serverStatus);
            sort($statusUrls);
            if ($serverUrls !== $statusUrls) {
                throw new RuntimeException("Legacy Docker service {$expected->providerName()} backend and health inventories disagree.");
            }
            foreach ($serverStatus as $url => $status) {
                if (! is_string($url) || $status !== 'UP') {
                    throw new RuntimeException("Legacy Docker service {$expected->providerName()} has a non-UP backend.");
                }
            }
        }
    }

    /** @param list<string> $expected */
    private function assertExactStringList(mixed $actual, array $expected, string $role): void
    {
        if (! is_array($actual) || array_filter($actual, fn (mixed $value): bool => ! is_string($value)) !== []) {
            throw new RuntimeException("Traefik rawdata contains a malformed {$role} list.");
        }
        $actual = array_values($actual);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException("Traefik rawdata contains a different {$role} list.");
        }
    }

    private function assertBackendUrl(
        string $url,
        BlueGreenLegacyRoutingSnapshot $snapshot,
        BlueGreenLegacyService $service,
    ): void {
        $parts = parse_url($url);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'http'
            || ! is_string($parts['host'] ?? null)
            || ($parts['port'] ?? null) !== $service->port
            || ! in_array($parts['host'], $snapshot->containerAddresses, true)) {
            throw new RuntimeException("Legacy Docker service {$service->providerName()} does not target the exact container address and port.");
        }
    }
}
