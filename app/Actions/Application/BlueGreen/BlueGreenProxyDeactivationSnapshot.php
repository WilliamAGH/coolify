<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyConfiguration;
use InvalidArgumentException;

final readonly class BlueGreenProxyDeactivationSnapshot
{
    public const DEACTIVATION_WINDOW_SECONDS = 900;

    public const FINALIZATION_RESERVE_SECONDS = 60;

    public const ROUTE_CONVERGENCE_ATTEMPTS = 60;

    public const DRAIN_ATTEMPT_SECONDS = 240;

    private const VERSION = 3;

    private const LEGACY_VERSION = 2;

    /** @var non-empty-list<int> */
    public array $backendPorts;

    /**
     * @param  list<array{router: string, url: string}>  $routes
     */
    public function __construct(
        public string $managedFilename,
        public string $sourceYaml,
        public string $sourceSha256,
        public string $tombstoneYaml,
        public string $tombstoneSha256,
        public string $tombstoneAcknowledgement,
        public array $routes,
        public int $backendPort,
        public int $destinationClockObservedAtUnixSeconds,
        public int $drainDeadlineUnixSeconds,
        public int $deactivationDeadlineUnixSeconds,
        ?array $backendPorts = null,
    ) {
        BlueGreenProxyConfiguration::assertManagedFilename($managedFilename);
        if (! hash_equals(hash('sha256', $sourceYaml), $sourceSha256)
            || ! hash_equals(hash('sha256', $tombstoneYaml), $tombstoneSha256)) {
            throw new InvalidArgumentException('Blue-green proxy deactivation snapshot bytes do not match their checksums.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $tombstoneAcknowledgement) !== 1) {
            throw new InvalidArgumentException('Blue-green proxy deactivation requires one opaque tombstone acknowledgement.');
        }
        $this->backendPorts = $this->normalizeBackendPorts($backendPorts ?? [$backendPort]);
        if (! in_array($backendPort, $this->backendPorts, true)) {
            throw new InvalidArgumentException('Blue-green proxy deactivation requires its primary backend port in the complete backend port list.');
        }
        if ($routes === [] || $backendPort < 1 || $backendPort > 65535
            || $destinationClockObservedAtUnixSeconds < 1
            || $drainDeadlineUnixSeconds !== $destinationClockObservedAtUnixSeconds
                + self::DEACTIVATION_WINDOW_SECONDS - self::FINALIZATION_RESERVE_SECONDS
            || $deactivationDeadlineUnixSeconds !== $destinationClockObservedAtUnixSeconds
                + self::DEACTIVATION_WINDOW_SECONDS) {
            throw new InvalidArgumentException('Blue-green proxy deactivation requires routes, backend ports, and destination-clock-bound deadlines.');
        }
        $routeIdentities = [];
        foreach ($routes as $route) {
            if (! isset($route['router'], $route['url'])
                || ! is_string($route['router'])
                || ! is_string($route['url'])
                || trim($route['router']) === ''
                || ! in_array(parse_url($route['url'], PHP_URL_SCHEME), ['http', 'https'], true)) {
                throw new InvalidArgumentException('Blue-green proxy deactivation contains a malformed route.');
            }
            $identity = $route['router']."\0".$route['url'];
            if (isset($routeIdentities[$identity])) {
                throw new InvalidArgumentException('Blue-green proxy deactivation contains a duplicate route.');
            }
            $routeIdentities[$identity] = true;
        }
    }

    /** @return array<string, mixed> */
    public function encode(): array
    {
        return [
            'version' => self::VERSION,
            'managedFilename' => $this->managedFilename,
            'sourceYaml' => $this->sourceYaml,
            'sourceSha256' => $this->sourceSha256,
            'tombstoneYaml' => $this->tombstoneYaml,
            'tombstoneSha256' => $this->tombstoneSha256,
            'tombstoneAcknowledgement' => $this->tombstoneAcknowledgement,
            'routes' => $this->routes,
            'backendPort' => $this->backendPort,
            'backendPorts' => $this->backendPorts,
            'destinationClockObservedAtUnixSeconds' => $this->destinationClockObservedAtUnixSeconds,
            'drainDeadlineUnixSeconds' => $this->drainDeadlineUnixSeconds,
            'deactivationDeadlineUnixSeconds' => $this->deactivationDeadlineUnixSeconds,
        ];
    }

    /** @param array<mixed> $encoded */
    public static function decode(array $encoded): self
    {
        $version = $encoded['version'] ?? null;
        if (($version !== self::VERSION && $version !== self::LEGACY_VERSION)
            || ! is_string($encoded['managedFilename'] ?? null)
            || ! is_string($encoded['sourceYaml'] ?? null)
            || ! is_string($encoded['sourceSha256'] ?? null)
            || ! is_string($encoded['tombstoneYaml'] ?? null)
            || ! is_string($encoded['tombstoneSha256'] ?? null)
            || ! is_string($encoded['tombstoneAcknowledgement'] ?? null)
            || ! is_array($encoded['routes'] ?? null)
            || ! is_int($encoded['backendPort'] ?? null)
            || ! is_int($encoded['destinationClockObservedAtUnixSeconds'] ?? null)
            || ! is_int($encoded['drainDeadlineUnixSeconds'] ?? null)
            || ! is_int($encoded['deactivationDeadlineUnixSeconds'] ?? null)) {
            throw new InvalidArgumentException('Durable blue-green proxy deactivation snapshot is malformed.');
        }
        if ($version === self::VERSION && ! is_array($encoded['backendPorts'] ?? null)) {
            throw new InvalidArgumentException('Durable blue-green proxy deactivation snapshot has no complete backend port list.');
        }

        return new self(
            managedFilename: $encoded['managedFilename'],
            sourceYaml: $encoded['sourceYaml'],
            sourceSha256: $encoded['sourceSha256'],
            tombstoneYaml: $encoded['tombstoneYaml'],
            tombstoneSha256: $encoded['tombstoneSha256'],
            tombstoneAcknowledgement: $encoded['tombstoneAcknowledgement'],
            routes: array_values($encoded['routes']),
            backendPort: $encoded['backendPort'],
            destinationClockObservedAtUnixSeconds: $encoded['destinationClockObservedAtUnixSeconds'],
            drainDeadlineUnixSeconds: $encoded['drainDeadlineUnixSeconds'],
            deactivationDeadlineUnixSeconds: $encoded['deactivationDeadlineUnixSeconds'],
            backendPorts: $version === self::VERSION ? $encoded['backendPorts'] : [$encoded['backendPort']],
        );
    }

    /** @return non-empty-list<int> */
    private function normalizeBackendPorts(array $backendPorts): array
    {
        if (! array_is_list($backendPorts) || $backendPorts === []) {
            throw new InvalidArgumentException('Blue-green proxy deactivation requires a non-empty backend port list.');
        }

        $normalized = [];
        foreach ($backendPorts as $backendPort) {
            if (! is_int($backendPort) || $backendPort < 1 || $backendPort > 65535) {
                throw new InvalidArgumentException('Blue-green proxy deactivation backend ports must be valid integers.');
            }
            if (in_array($backendPort, $normalized, true)) {
                throw new InvalidArgumentException('Blue-green proxy deactivation backend ports must be unique.');
            }
            $normalized[] = $backendPort;
        }
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }
}
