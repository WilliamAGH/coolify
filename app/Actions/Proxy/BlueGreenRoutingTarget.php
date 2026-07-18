<?php

namespace App\Actions\Proxy;

use App\Enums\BlueGreenDeploymentColor;
use InvalidArgumentException;

final readonly class BlueGreenRoutingTarget
{
    public const PROBE_ACKNOWLEDGEMENT_HEADER = 'X-Coolify-Probe-Ack';

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

    public static function memberServiceName(
        string $applicationUuid,
        int $destinationId,
        BlueGreenDeploymentColor $color,
    ): string {
        return self::routingNamePrefix($applicationUuid, $destinationId).$color->value;
    }

    public static function memberServiceReference(
        string $applicationUuid,
        int $destinationId,
        BlueGreenDeploymentColor $color,
    ): string {
        return self::memberServiceName($applicationUuid, $destinationId, $color).'@docker';
    }

    public static function memberDiscoveryRouterName(
        string $applicationUuid,
        int $destinationId,
        BlueGreenDeploymentColor $color,
    ): string {
        return self::memberServiceName($applicationUuid, $destinationId, $color).'-discovery';
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
        public ?string $publicProofToken = null,
        public ?string $legacyContainerName = null,
    ) {
        if ($destinationId < 0) {
            throw new InvalidArgumentException('The destination ID must be a nonnegative integer.');
        }
        if ($routingRevision < 0) {
            throw new InvalidArgumentException('The routing revision must be a nonnegative integer.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The blue/green backend port must be between 1 and 65535.');
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
        if ($publicProofToken !== null) {
            $this->assertOpaqueToken($publicProofToken, 'public proof');
        }
        if ($probeToken !== null && $publicProofToken !== null && hash_equals($probeToken, $publicProofToken)) {
            throw new InvalidArgumentException('Probe and public proof tokens must be distinct.');
        }
        if ($mode === BlueGreenRoutingMode::LegacyRecoveryBridge) {
            if ($legacyContainerName === null) {
                throw new InvalidArgumentException('A legacy recovery bridge requires the exact legacy container name.');
            }
            $this->assertContainerName($legacyContainerName);
        } elseif ($legacyContainerName !== null) {
            throw new InvalidArgumentException('Only a legacy recovery bridge may override the public backend container.');
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

    public function probeAcknowledgement(): ?string
    {
        if ($this->probeToken === null || $this->probeColor === null) {
            return null;
        }

        return $this->acknowledgement('probe', $this->probeToken, $this->probeColor);
    }

    public function publicAcknowledgement(): ?string
    {
        if ($this->publicProofToken === null) {
            return null;
        }

        return $this->acknowledgement('public', $this->publicProofToken, $this->activeColor);
    }

    public static function durablePublicProofToken(string $operationId): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('The durable public proof operation ID is invalid.');
        }

        return 'public:'.hash('sha256', "coolify-blue-green-public-proof-v1\0{$operationId}");
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
            (string) $this->port,
        ];
        if ($this->legacyContainerName !== null) {
            $identity[] = $this->legacyContainerName;
        }

        return hash_hmac('sha256', implode("\0", $identity), $token);
    }
}
