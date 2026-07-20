<?php

namespace App\Actions\Proxy;

use App\Enums\BlueGreenDeploymentColor;
use InvalidArgumentException;

final readonly class BlueGreenRoutingTarget
{
    public const PROBE_ACKNOWLEDGEMENT_HEADER = 'X-Coolify-Probe-Ack';

    public const RELEASE_PROOF_HEADER = 'X-Coolify-Deployment';

    /** @var non-empty-list<int> */
    public array $ports;

    public static function managedFilename(string $applicationUuid, int $destinationId): string
    {
        return 'coolify-blue-green-'.self::routingScope($applicationUuid, $destinationId).'.yaml';
    }

    public static function routingNamePrefix(string $applicationUuid, int $destinationId): string
    {
        return 'coolify-bg-'.self::routingScope($applicationUuid, $destinationId).'-';
    }

    public static function activeServiceName(string $applicationUuid, int $destinationId): string
    {
        return self::routingNamePrefix($applicationUuid, $destinationId).'active';
    }

    public static function activeServiceNameForPort(
        string $applicationUuid,
        int $destinationId,
        int $port,
        bool $multiplePorts,
    ): string {
        self::assertBackendPort($port);

        return self::activeServiceName($applicationUuid, $destinationId).($multiplePorts ? "-{$port}" : '');
    }

    public static function memberServiceName(
        string $applicationUuid,
        int $destinationId,
        BlueGreenDeploymentColor $color,
    ): string {
        return self::routingNamePrefix($applicationUuid, $destinationId).$color->value;
    }

    public static function memberServiceNameForPort(
        string $applicationUuid,
        int $destinationId,
        BlueGreenDeploymentColor $color,
        int $port,
        bool $multiplePorts,
    ): string {
        self::assertBackendPort($port);

        return self::memberServiceName($applicationUuid, $destinationId, $color).($multiplePorts ? "-{$port}" : '');
    }

    public static function memberServiceReference(
        string $applicationUuid,
        int $destinationId,
        BlueGreenDeploymentColor $color,
    ): string {
        return self::memberServiceName($applicationUuid, $destinationId, $color).'@docker';
    }

    public static function memberServiceReferenceForPort(
        string $applicationUuid,
        int $destinationId,
        BlueGreenDeploymentColor $color,
        int $port,
        bool $multiplePorts,
    ): string {
        return self::memberServiceNameForPort(
            $applicationUuid,
            $destinationId,
            $color,
            $port,
            $multiplePorts,
        ).'@docker';
    }

    public static function memberDiscoveryRouterName(
        string $applicationUuid,
        int $destinationId,
        BlueGreenDeploymentColor $color,
    ): string {
        return self::memberServiceName($applicationUuid, $destinationId, $color).'-discovery';
    }

    public static function memberDiscoveryRouterNameForPort(
        string $applicationUuid,
        int $destinationId,
        BlueGreenDeploymentColor $color,
        int $port,
        bool $multiplePorts,
    ): string {
        return self::memberServiceNameForPort(
            $applicationUuid,
            $destinationId,
            $color,
            $port,
            $multiplePorts,
        ).'-discovery';
    }

    public function __construct(
        public int $destinationId,
        public BlueGreenDeploymentColor $activeColor,
        public string $blueContainerName,
        public string $greenContainerName,
        public int $port,
        public int $routingRevision,
        public BlueGreenRoutingMode $mode = BlueGreenRoutingMode::Steady,
        public ?string $probeHeaderName = null,
        public ?string $probeToken = null,
        public ?BlueGreenDeploymentColor $probeColor = null,
        public ?string $releaseProofToken = null,
        public ?string $publicProofToken = null,
        public ?string $legacyContainerName = null,
        public ?string $fallbackContainerName = null,
        public string $healthCheckType = 'http',
        public string $healthCheckPath = '/',
        public int $healthCheckIntervalSeconds = 5,
        public int $healthCheckTimeoutSeconds = 5,
        public string $healthCheckScheme = 'http',
        public string $healthCheckHostname = 'localhost',
        public string $healthCheckMethod = 'GET',
        public int $healthCheckStatus = 200,
        public ?int $healthCheckPort = null,
        public ?int $destinationFenceEpoch = null,
        public ?string $operationId = null,
        public ?int $mutationSequence = null,
        public ?string $activeDeploymentUuid = null,
        public ?string $activeContainerId = null,
        public ?string $destinationTopologyDigest = null,
        ?array $ports = null,
    ) {
        if ($destinationId < 0) {
            throw new InvalidArgumentException('The destination ID must be a nonnegative integer.');
        }
        if ($routingRevision < 0) {
            throw new InvalidArgumentException('The routing revision must be a nonnegative integer.');
        }
        self::assertBackendPort($port);
        $this->ports = $this->normalizePorts($ports ?? [$port]);
        if (! in_array($port, $this->ports, true)) {
            throw new InvalidArgumentException('The blue/green primary backend port must be included in the complete backend port list.');
        }
        if ($blueContainerName === $greenContainerName) {
            throw new InvalidArgumentException('Blue and green container DNS names must differ.');
        }
        $this->assertContainerName($blueContainerName);
        $this->assertContainerName($greenContainerName);

        $probeSettingCount = count(array_filter(
            [$probeHeaderName, $probeToken, $probeColor],
            static fn (mixed $probeSetting): bool => $probeSetting !== null,
        ));
        if ($probeSettingCount !== 0 && $probeSettingCount !== 3) {
            throw new InvalidArgumentException('The probe header name, token, and color must either all be set or all be omitted.');
        }
        if ($probeHeaderName !== null && preg_match('/^X-Coolify-[A-Za-z0-9-]{1,96}$/Di', $probeHeaderName) !== 1) {
            throw new InvalidArgumentException('The probe header must use a safe X-Coolify-* field name.');
        }
        if ($probeToken !== null) {
            $this->assertOpaqueToken($probeToken, 'probe');
        }
        if ($releaseProofToken !== null) {
            $this->assertOpaqueToken($releaseProofToken, 'release proof');
        }
        if ($publicProofToken !== null) {
            $this->assertOpaqueToken($publicProofToken, 'public proof');
        }
        if ($probeToken !== null && $publicProofToken !== null && hash_equals($probeToken, $publicProofToken)) {
            throw new InvalidArgumentException('Probe and public proof tokens must be distinct.');
        }
        if ($releaseProofToken !== null
            && $mode === BlueGreenRoutingMode::ProbeOnly
            && $probeToken === null) {
            throw new InvalidArgumentException('A probe-only release-proof token requires a candidate probe route.');
        }
        if ($fallbackContainerName !== null) {
            $this->assertContainerName($fallbackContainerName);
        }
        if ($mode === BlueGreenRoutingMode::Failover && $fallbackContainerName === null) {
            throw new InvalidArgumentException('A blue-green failover route requires the exact previous container name.');
        }
        if ($mode !== BlueGreenRoutingMode::ProbeOnly && $fallbackContainerName !== null) {
            $this->assertHttpFailoverHealthCheck();
        }
        if ($mode === BlueGreenRoutingMode::ProbeOnly && $publicProofToken !== null) {
            throw new InvalidArgumentException('A probe-only route cannot publish a public proof token.');
        }
        if ($mode === BlueGreenRoutingMode::LegacyRecoveryBridge) {
            if ($legacyContainerName === null) {
                throw new InvalidArgumentException('A legacy recovery bridge requires the exact legacy container name.');
            }
            $this->assertContainerName($legacyContainerName);
        } elseif ($legacyContainerName !== null) {
            throw new InvalidArgumentException('Only a legacy recovery bridge may override the public backend container.');
        }

        $fenceIdentity = [
            $destinationFenceEpoch,
            $operationId,
            $mutationSequence,
            $activeDeploymentUuid,
            $activeContainerId,
            $destinationTopologyDigest,
        ];
        $fenceIdentityCount = count(array_filter($fenceIdentity, static fn (mixed $value): bool => $value !== null));
        if ($fenceIdentityCount !== 0 && $fenceIdentityCount !== count($fenceIdentity)) {
            throw new InvalidArgumentException('The complete destination fence identity must be supplied together.');
        }
        if ($destinationFenceEpoch !== null && $destinationFenceEpoch < 1) {
            throw new InvalidArgumentException('The destination fence epoch must be positive.');
        }
        if ($operationId !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('The destination mutation operation ID is invalid.');
        }
        if ($mutationSequence !== null && $mutationSequence < 1) {
            throw new InvalidArgumentException('The destination mutation sequence must be positive.');
        }
        if ($destinationTopologyDigest !== null && preg_match('/^[a-f0-9]{64}$/D', $destinationTopologyDigest) !== 1) {
            throw new InvalidArgumentException('The destination topology digest must be a SHA-256 value.');
        }
    }

    public function containerName(BlueGreenDeploymentColor $color): string
    {
        return $color === BlueGreenDeploymentColor::BLUE
            ? $this->blueContainerName
            : $this->greenContainerName;
    }

    public function inactiveColor(): BlueGreenDeploymentColor
    {
        return match ($this->activeColor) {
            BlueGreenDeploymentColor::BLUE => BlueGreenDeploymentColor::GREEN,
            BlueGreenDeploymentColor::GREEN => BlueGreenDeploymentColor::BLUE,
        };
    }

    public function activeServiceNameForBackendPort(string $applicationUuid, int $port): string
    {
        $this->assertKnownPort($port);

        return self::activeServiceNameForPort(
            $applicationUuid,
            $this->destinationId,
            $port,
            count($this->ports) > 1,
        );
    }

    public function memberServiceReferenceForBackendPort(
        string $applicationUuid,
        BlueGreenDeploymentColor $color,
        int $port,
    ): string {
        $this->assertKnownPort($port);

        return self::memberServiceReferenceForPort(
            $applicationUuid,
            $this->destinationId,
            $color,
            $port,
            count($this->ports) > 1,
        );
    }

    public function probeAcknowledgement(): ?string
    {
        if ($this->probeToken === null || $this->probeColor === null) {
            return null;
        }

        return $this->acknowledgement('probe', $this->probeToken, $this->probeColor);
    }

    public function publicAcknowledgement(): ?string
    {
        if ($this->mode === BlueGreenRoutingMode::ProbeOnly || $this->publicProofToken === null) {
            return null;
        }

        return $this->acknowledgement('public', $this->publicProofToken, $this->activeColor);
    }

    /**
     * @return array{path: string, interval: string, timeout: string, scheme: string, hostname: string, method: string, status: int, port?: int}
     */
    public function failoverHealthCheck(): array
    {
        $healthCheck = [
            'path' => $this->healthCheckPath,
            'interval' => $this->healthCheckIntervalSeconds.'s',
            'timeout' => $this->healthCheckTimeoutSeconds.'s',
            'scheme' => $this->healthCheckScheme,
            'hostname' => $this->healthCheckHostname,
            'method' => $this->healthCheckMethod,
            'status' => $this->healthCheckStatus,
        ];
        if ($this->healthCheckPort !== null) {
            $healthCheck['port'] = $this->healthCheckPort;
        }

        return $healthCheck;
    }

    public static function durablePublicProofToken(string $operationId): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('The durable public proof operation ID is invalid.');
        }

        return 'public:'.hash('sha256', "coolify-blue-green-public-proof-v1\0{$operationId}");
    }

    public static function durableReleaseProofToken(string $operationId): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('The durable release proof operation ID is invalid.');
        }

        return 'release:'.hash('sha256', "coolify-blue-green-release-proof-v1\0{$operationId}");
    }

    public static function durableProbeToken(string $operationId): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('The durable probe operation ID is invalid.');
        }

        return 'probe:'.hash('sha256', "coolify-blue-green-probe-v1\0{$operationId}");
    }

    public function fencedState(
        string $applicationUuid,
        string $managedFilename,
        string $managedSha256,
        string $applicationRoutingConfigDigest,
    ): BlueGreenProxyState {
        if ($this->destinationFenceEpoch === null
            || $this->operationId === null
            || $this->mutationSequence === null
            || $this->activeDeploymentUuid === null
            || $this->activeContainerId === null
            || $this->destinationTopologyDigest === null) {
            throw new InvalidArgumentException('Compiling a managed blue/green route requires its durable destination fence identity.');
        }

        return new BlueGreenProxyState(
            managedFilename: $managedFilename,
            applicationUuid: $applicationUuid,
            destinationId: $this->destinationId,
            operationId: $this->operationId,
            mutationSequence: $this->mutationSequence,
            destinationFenceEpoch: $this->destinationFenceEpoch,
            routingRevision: $this->routingRevision,
            managedSha256: $managedSha256,
            activeColor: $this->activeColor,
            activeDeploymentUuid: $this->activeDeploymentUuid,
            activeContainerName: $this->mode === BlueGreenRoutingMode::LegacyRecoveryBridge
                ? $this->legacyContainerName
                : $this->containerName($this->activeColor),
            activeContainerId: $this->activeContainerId,
            applicationRoutingConfigDigest: $applicationRoutingConfigDigest,
            destinationTopologyDigest: $this->destinationTopologyDigest,
        );
    }

    private function assertContainerName(string $containerName): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $containerName) !== 1) {
            throw new InvalidArgumentException('Blue/green container DNS names must use Docker-safe characters.');
        }
    }

    private function assertOpaqueToken(string $token, string $role): void
    {
        if (preg_match('/^[A-Za-z0-9._~+\/=:-]{16,512}$/D', $token) !== 1) {
            throw new InvalidArgumentException("The {$role} token must be an opaque single-line token of at least 16 characters.");
        }
    }

    private function assertHttpFailoverHealthCheck(): void
    {
        if ($this->healthCheckType !== 'http') {
            throw new InvalidArgumentException('Blue-green failover supports only the application HTTP health-check contract.');
        }
        if (preg_match('#^/[A-Za-z0-9/_.~%:;,@+\-]*$#D', $this->healthCheckPath) !== 1) {
            throw new InvalidArgumentException('The failover health-check path is invalid.');
        }
        if ($this->healthCheckIntervalSeconds < 1 || $this->healthCheckTimeoutSeconds < 1) {
            throw new InvalidArgumentException('The failover health-check interval and timeout must be positive.');
        }
        if (! in_array($this->healthCheckScheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('The failover health-check scheme is invalid.');
        }
        if (preg_match('/^[A-Za-z0-9._-]+$/D', $this->healthCheckHostname) !== 1) {
            throw new InvalidArgumentException('The failover health-check hostname is invalid.');
        }
        if (! in_array($this->healthCheckMethod, ['GET', 'HEAD', 'POST', 'OPTIONS'], true)) {
            throw new InvalidArgumentException('The failover health-check method is invalid.');
        }
        if ($this->healthCheckStatus < 100 || $this->healthCheckStatus > 599) {
            throw new InvalidArgumentException('The failover health-check status must be a valid HTTP status code.');
        }
        if ($this->healthCheckPort !== null && ($this->healthCheckPort < 1 || $this->healthCheckPort > 65535)) {
            throw new InvalidArgumentException('The failover health-check port must be between 1 and 65535.');
        }
    }

    private static function routingScope(string $applicationUuid, int $destinationId): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $applicationUuid) !== 1) {
            throw new InvalidArgumentException('A Docker-safe application UUID is required to compile blue/green routing.');
        }
        if ($destinationId < 0) {
            throw new InvalidArgumentException('The destination ID must be a nonnegative integer.');
        }

        return substr(hash('sha256', $applicationUuid."\0".$destinationId), 0, 16);
    }

    private function acknowledgement(
        string $role,
        string $token,
        BlueGreenDeploymentColor $routedColor,
    ): string {
        $identity = [
            'coolify-blue-green-applied-proof-v1',
            $role,
            (string) $this->destinationId,
            (string) $this->routingRevision,
            $this->mode->name,
            $this->activeColor->value,
            $routedColor->value,
            $this->blueContainerName,
            $this->greenContainerName,
            implode(',', $this->ports),
        ];
        if ($this->legacyContainerName !== null) {
            $identity[] = $this->legacyContainerName;
        }
        if ($this->fallbackContainerName !== null) {
            $identity[] = $this->fallbackContainerName;
        }

        return hash_hmac('sha256', implode("\0", $identity), $token);
    }

    /** @return non-empty-list<int> */
    private function normalizePorts(array $ports): array
    {
        if (! array_is_list($ports) || $ports === []) {
            throw new InvalidArgumentException('Blue-green backend ports must be a non-empty list.');
        }

        $normalized = [];
        foreach ($ports as $port) {
            if (! is_int($port)) {
                throw new InvalidArgumentException('Blue-green backend ports must be integers.');
            }
            self::assertBackendPort($port);
            if (in_array($port, $normalized, true)) {
                throw new InvalidArgumentException('Blue-green backend ports must be unique.');
            }
            $normalized[] = $port;
        }
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function assertKnownPort(int $port): void
    {
        if (! in_array($port, $this->ports, true)) {
            throw new InvalidArgumentException('The requested blue-green backend port is not part of this routing target.');
        }
    }

    private static function assertBackendPort(int $port): void
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The blue/green backend port must be between 1 and 65535.');
        }
    }
}
