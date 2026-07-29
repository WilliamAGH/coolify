<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;

final readonly class ControlPlaneProxyEnrollmentState
{
    public const VERSION = 3;

    public function __construct(
        public ControlPlaneProxyEnrollmentPhase $phase,
        public string $operationId,
        public string $tokenSha256,
        public int $serverId,
        public int $appPort,
        public ControlPlaneProxyExposure $exposure,
        public string $managedFilename,
        public int $dynamicRevision,
        public string $canonicalHost,
        public string $publicScheme,
        public string $expectedMember,
        public string $expectedRevision,
        public string $configurationAcknowledgement,
        public array $activeBackendDnsNames,
        public string $staticPredecessorBytes,
        public string $staticReplacementBytes,
        public string $sourceOverrideBytes,
        public ?string $dynamicPredecessorBytes,
        public string $dynamicReplacementBytes,
        public string $createdAt,
        public string $updatedAt,
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,127}\z/D', $operationId) !== 1) {
            throw new InvalidArgumentException('The control-plane enrollment operation ID is invalid.');
        }
        $this->assertSha256($tokenSha256, 'token hash');
        if ($serverId < 0 || $appPort < 1 || $appPort > 65535 || $dynamicRevision < 1) {
            throw new InvalidArgumentException('The control-plane enrollment server, port, or revision is invalid.');
        }
        if ($managedFilename !== ControlPlaneDynamicConfiguration::MANAGED_FILENAME) {
            throw new InvalidArgumentException('The control-plane enrollment must own the canonical dynamic filename.');
        }
        $this->assertHost($canonicalHost);
        if (! in_array($publicScheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('The public control-plane route scheme must be http or https.');
        }
        foreach (['member' => $expectedMember, 'revision' => $expectedRevision] as $role => $value) {
            $this->assertIdentifier($value, $role);
        }
        if (preg_match('/\A[A-Za-z0-9._~+\/=:-]{16,512}\z/D', $configurationAcknowledgement) !== 1) {
            throw new InvalidArgumentException('The control-plane configuration acknowledgement must be opaque and single-line.');
        }
        $this->assertBackendDnsNames($activeBackendDnsNames);
        foreach ([$staticPredecessorBytes, $staticReplacementBytes, $sourceOverrideBytes, $dynamicReplacementBytes] as $bytes) {
            if ($bytes === '') {
                throw new InvalidArgumentException('Control-plane enrollment artifacts must not be empty.');
            }
        }
        if ($createdAt === '' || $updatedAt === '') {
            throw new InvalidArgumentException('Control-plane enrollment timestamps must not be empty.');
        }
    }

    public static function reserve(
        string $operationId,
        string $token,
        int $serverId,
        int $appPort,
        ControlPlaneProxyExposure $exposure,
        int $dynamicRevision,
        string $canonicalHost,
        string $publicScheme,
        string $expectedMember,
        string $expectedRevision,
        string $configurationAcknowledgement,
        array $activeBackendDnsNames,
        ControlPlaneStaticProxyConfiguration $staticConfiguration,
        ControlPlaneDynamicConfiguration $dynamicConfiguration,
        ?string $dynamicPredecessorBytes,
        string $timestamp,
    ): self {
        if ($token === '') {
            throw new InvalidArgumentException('The control-plane enrollment token must not be empty.');
        }
        if ($staticConfiguration->appPort !== $appPort || $staticConfiguration->exposure !== $exposure) {
            throw new InvalidArgumentException('The static enrollment configuration does not match its durable owner.');
        }

        $activeBackendDnsNames = self::normalizeBackendDnsNames($activeBackendDnsNames);

        return new self(
            phase: ControlPlaneProxyEnrollmentPhase::Preparing,
            operationId: $operationId,
            tokenSha256: hash('sha256', $token),
            serverId: $serverId,
            appPort: $appPort,
            exposure: $exposure,
            managedFilename: $dynamicConfiguration->managedFilename,
            dynamicRevision: $dynamicRevision,
            canonicalHost: $canonicalHost,
            publicScheme: $publicScheme,
            expectedMember: $expectedMember,
            expectedRevision: $expectedRevision,
            configurationAcknowledgement: $configurationAcknowledgement,
            activeBackendDnsNames: $activeBackendDnsNames,
            staticPredecessorBytes: $staticConfiguration->predecessorProxyYaml,
            staticReplacementBytes: $staticConfiguration->replacementProxyYaml,
            sourceOverrideBytes: $staticConfiguration->sourceOverrideYaml,
            dynamicPredecessorBytes: $dynamicPredecessorBytes,
            dynamicReplacementBytes: $dynamicConfiguration->yaml,
            createdAt: $timestamp,
            updatedAt: $timestamp,
        );
    }

    public function withPhase(ControlPlaneProxyEnrollmentPhase $phase, string $timestamp): self
    {
        $this->phase->assertCanTransitionTo($phase);
        if ($phase === $this->phase) {
            return $this;
        }

        return new self(
            phase: $phase,
            operationId: $this->operationId,
            tokenSha256: $this->tokenSha256,
            serverId: $this->serverId,
            appPort: $this->appPort,
            exposure: $this->exposure,
            managedFilename: $this->managedFilename,
            dynamicRevision: $this->dynamicRevision,
            canonicalHost: $this->canonicalHost,
            publicScheme: $this->publicScheme,
            expectedMember: $this->expectedMember,
            expectedRevision: $this->expectedRevision,
            configurationAcknowledgement: $this->configurationAcknowledgement,
            activeBackendDnsNames: $this->activeBackendDnsNames,
            staticPredecessorBytes: $this->staticPredecessorBytes,
            staticReplacementBytes: $this->staticReplacementBytes,
            sourceOverrideBytes: $this->sourceOverrideBytes,
            dynamicPredecessorBytes: $this->dynamicPredecessorBytes,
            dynamicReplacementBytes: $this->dynamicReplacementBytes,
            createdAt: $this->createdAt,
            updatedAt: $timestamp,
        );
    }

    public function withRepairedDynamicPredecessorTerminalLf(): self
    {
        if ($this->phase !== ControlPlaneProxyEnrollmentPhase::Activating) {
            throw new InvalidArgumentException('The dynamic predecessor transport-LF repair is limited to an activating enrollment.');
        }
        if ($this->dynamicPredecessorBytes === null
            || $this->dynamicPredecessorBytes === ''
            || str_ends_with($this->dynamicPredecessorBytes, "\n")) {
            throw new InvalidArgumentException('The dynamic predecessor transport-LF repair requires one non-empty predecessor missing its terminal LF.');
        }

        return new self(
            phase: $this->phase,
            operationId: $this->operationId,
            tokenSha256: $this->tokenSha256,
            serverId: $this->serverId,
            appPort: $this->appPort,
            exposure: $this->exposure,
            managedFilename: $this->managedFilename,
            dynamicRevision: $this->dynamicRevision,
            canonicalHost: $this->canonicalHost,
            publicScheme: $this->publicScheme,
            expectedMember: $this->expectedMember,
            expectedRevision: $this->expectedRevision,
            configurationAcknowledgement: $this->configurationAcknowledgement,
            activeBackendDnsNames: $this->activeBackendDnsNames,
            staticPredecessorBytes: $this->staticPredecessorBytes,
            staticReplacementBytes: $this->staticReplacementBytes,
            sourceOverrideBytes: $this->sourceOverrideBytes,
            dynamicPredecessorBytes: $this->dynamicPredecessorBytes."\n",
            dynamicReplacementBytes: $this->dynamicReplacementBytes,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
        );
    }

    public function isOwnedBy(string $operationId, string $token): bool
    {
        return hash_equals($this->operationId, $operationId)
            && hash_equals($this->tokenSha256, hash('sha256', $token));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'phase' => $this->phase->value,
            'operation_id' => $this->operationId,
            'token_sha256' => $this->tokenSha256,
            'server_id' => $this->serverId,
            'app_port' => $this->appPort,
            'exposure' => $this->exposure->value,
            'managed_filename' => $this->managedFilename,
            'dynamic_revision' => $this->dynamicRevision,
            'canonical_host' => $this->canonicalHost,
            'public_scheme' => $this->publicScheme,
            'expected_member' => $this->expectedMember,
            'expected_revision' => $this->expectedRevision,
            'configuration_acknowledgement' => $this->configurationAcknowledgement,
            'active_backend_dns_names' => $this->activeBackendDnsNames,
            'static_predecessor' => $this->artifact($this->staticPredecessorBytes),
            'static_replacement' => $this->artifact($this->staticReplacementBytes),
            'source_override' => $this->artifact($this->sourceOverrideBytes),
            'dynamic_predecessor' => $this->dynamicPredecessorBytes === null
                ? null
                : $this->artifact($this->dynamicPredecessorBytes),
            'dynamic_replacement' => $this->artifact($this->dynamicReplacementBytes),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /** @param array<string, mixed> $state */
    public static function fromArray(array $state): self
    {
        self::assertExactKeys($state, [
            'version', 'phase', 'operation_id', 'token_sha256', 'server_id', 'app_port', 'exposure',
            'managed_filename', 'dynamic_revision', 'canonical_host', 'public_scheme', 'expected_member',
            'expected_revision', 'configuration_acknowledgement', 'active_backend_dns_names', 'static_predecessor', 'static_replacement',
            'source_override', 'dynamic_predecessor', 'dynamic_replacement', 'created_at', 'updated_at',
        ]);
        if ($state['version'] !== self::VERSION) {
            throw new InvalidArgumentException('The control-plane enrollment state version is unsupported.');
        }

        return new self(
            phase: ControlPlaneProxyEnrollmentPhase::from(self::requiredString($state, 'phase')),
            operationId: self::requiredString($state, 'operation_id'),
            tokenSha256: self::requiredString($state, 'token_sha256'),
            serverId: self::requiredInteger($state, 'server_id'),
            appPort: self::requiredInteger($state, 'app_port'),
            exposure: ControlPlaneProxyExposure::from(self::requiredString($state, 'exposure')),
            managedFilename: self::requiredString($state, 'managed_filename'),
            dynamicRevision: self::requiredInteger($state, 'dynamic_revision'),
            canonicalHost: self::requiredString($state, 'canonical_host'),
            publicScheme: self::requiredString($state, 'public_scheme'),
            expectedMember: self::requiredString($state, 'expected_member'),
            expectedRevision: self::requiredString($state, 'expected_revision'),
            configurationAcknowledgement: self::requiredString($state, 'configuration_acknowledgement'),
            activeBackendDnsNames: self::requiredStringList($state, 'active_backend_dns_names'),
            staticPredecessorBytes: self::decodeArtifact($state['static_predecessor'], 'static predecessor'),
            staticReplacementBytes: self::decodeArtifact($state['static_replacement'], 'static replacement'),
            sourceOverrideBytes: self::decodeArtifact($state['source_override'], 'source override'),
            dynamicPredecessorBytes: $state['dynamic_predecessor'] === null
                ? null
                : self::decodeArtifact($state['dynamic_predecessor'], 'dynamic predecessor'),
            dynamicReplacementBytes: self::decodeArtifact($state['dynamic_replacement'], 'dynamic replacement'),
            createdAt: self::requiredString($state, 'created_at'),
            updatedAt: self::requiredString($state, 'updated_at'),
        );
    }

    /** @return array{base64: string, sha256: string} */
    private function artifact(string $bytes): array
    {
        return ['base64' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)];
    }

    private static function decodeArtifact(mixed $artifact, string $label): string
    {
        if (! is_array($artifact)) {
            throw new InvalidArgumentException("The control-plane {$label} artifact is invalid.");
        }
        self::assertExactKeys($artifact, ['base64', 'sha256']);
        if (! is_string($artifact['base64']) || ! is_string($artifact['sha256'])) {
            throw new InvalidArgumentException("The control-plane {$label} artifact is invalid.");
        }
        $bytes = base64_decode($artifact['base64'], true);
        if ($bytes === false || ! hash_equals($artifact['sha256'], hash('sha256', $bytes))) {
            throw new InvalidArgumentException("The control-plane {$label} artifact checksum is invalid.");
        }

        return $bytes;
    }

    /** @param array<string, mixed> $value */
    private static function requiredString(array $value, string $key): string
    {
        if (! is_string($value[$key]) || $value[$key] === '') {
            throw new InvalidArgumentException("The control-plane enrollment {$key} is invalid.");
        }

        return $value[$key];
    }

    /** @param array<string, mixed> $value */
    private static function requiredInteger(array $value, string $key): int
    {
        if (! is_int($value[$key])) {
            throw new InvalidArgumentException("The control-plane enrollment {$key} is invalid.");
        }

        return $value[$key];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<string>
     */
    private static function requiredStringList(array $value, string $key): array
    {
        if (! is_array($value[$key]) || ! array_is_list($value[$key])) {
            throw new InvalidArgumentException("The control-plane enrollment {$key} is invalid.");
        }
        foreach ($value[$key] as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException("The control-plane enrollment {$key} is invalid.");
            }
        }

        return $value[$key];
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expected
     */
    private static function assertExactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('The control-plane enrollment state has an unexpected shape.');
        }
    }

    private function assertSha256(string $value, string $label): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The control-plane enrollment {$label} is invalid.");
        }
    }

    private function assertHost(string $host): void
    {
        $isIpv4Address = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isDnsName = preg_match('/\A(?=.{1,253}\z)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\z/D', $host) === 1;
        if (! $isIpv4Address && ! $isDnsName) {
            throw new InvalidArgumentException('The canonical control-plane host must be a safe DNS name or IPv4 address.');
        }
    }

    private function assertIdentifier(string $value, string $role): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The control-plane {$role} is invalid.");
        }
    }

    /** @param list<string> $backendDnsNames */
    private function assertBackendDnsNames(array $backendDnsNames): void
    {
        if ($backendDnsNames === [] || ! array_is_list($backendDnsNames)) {
            throw new InvalidArgumentException('The control-plane active backend set must be a non-empty list.');
        }
        foreach ($backendDnsNames as $backendDnsName) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $backendDnsName) !== 1) {
                throw new InvalidArgumentException('The control-plane active backend set contains an unsafe DNS name.');
            }
        }
        $normalized = self::normalizeBackendDnsNames($backendDnsNames);
        if ($normalized !== $backendDnsNames) {
            throw new InvalidArgumentException('The control-plane active backend set must be unique and sorted.');
        }
    }

    /**
     * @param  list<string>  $backendDnsNames
     * @return list<string>
     */
    private static function normalizeBackendDnsNames(array $backendDnsNames): array
    {
        $backendDnsNames = array_values(array_unique($backendDnsNames, SORT_STRING));
        sort($backendDnsNames, SORT_STRING);

        return $backendDnsNames;
    }
}
