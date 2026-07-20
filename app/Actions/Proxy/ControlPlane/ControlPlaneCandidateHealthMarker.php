<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;
use JsonException;

final readonly class ControlPlaneCandidateHealthMarker
{
    public const CONTAINER_MARKER_PATH = '/var/www/html/storage/framework/cache/.coolify-control-plane-health-marker.json';

    private const JSON_KEYS = [
        'operation_id',
        'expected_member',
        'expected_revision',
        'dynamic_sha256',
        'health_proof_sha256',
        'authentication_proxy_proof_sha256',
    ];

    private const FILE_TYPE_MASK = 0170000;

    private const REGULAR_FILE_TYPE = 0100000;

    private function __construct(
        public string $operationId,
        public string $expectedMember,
        public string $expectedRevision,
        public string $dynamicSha256,
        public string $healthProofSha256,
        public string $authenticationProxyProofSha256,
    ) {
        self::assertOperationId($operationId);
        self::assertIdentifier($expectedMember, 'expected member');
        self::assertIdentifier($expectedRevision, 'expected revision');
        self::assertSha256($dynamicSha256, 'dynamic checksum');
        self::assertSha256($healthProofSha256, 'health-proof checksum');
        self::assertSha256($authenticationProxyProofSha256, 'authentication-proxy proof checksum');
    }

    public static function fromDerivedHealthProof(
        string $operationId,
        string $expectedMember,
        string $expectedRevision,
        string $dynamicSha256,
        string $derivedHealthProof,
    ): self {
        self::assertSha256($derivedHealthProof, 'derived health proof');

        return new self(
            operationId: $operationId,
            expectedMember: $expectedMember,
            expectedRevision: $expectedRevision,
            dynamicSha256: $dynamicSha256,
            healthProofSha256: hash('sha256', $derivedHealthProof),
            authenticationProxyProofSha256: hash(
                'sha256',
                ControlPlaneDynamicConfiguration::deriveAuthenticationProxyProof($derivedHealthProof),
            ),
        );
    }

    public static function fromJson(string $json): self
    {
        try {
            $value = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The control-plane candidate health marker JSON is malformed.', previous: $exception);
        }

        if (! is_array($value) || array_is_list($value) || array_keys($value) !== self::JSON_KEYS) {
            throw new InvalidArgumentException('The control-plane candidate health marker JSON has an unexpected shape.');
        }
        foreach (self::JSON_KEYS as $key) {
            if (! is_string($value[$key])) {
                throw new InvalidArgumentException('The control-plane candidate health marker JSON fields must be strings.');
            }
        }

        $marker = new self(
            operationId: $value['operation_id'],
            expectedMember: $value['expected_member'],
            expectedRevision: $value['expected_revision'],
            dynamicSha256: $value['dynamic_sha256'],
            healthProofSha256: $value['health_proof_sha256'],
            authenticationProxyProofSha256: $value['authentication_proxy_proof_sha256'],
        );

        if (! hash_equals($marker->toJson(), $json)) {
            throw new InvalidArgumentException('The control-plane candidate health marker JSON is not canonical.');
        }

        return $marker;
    }

    public static function readExpected(self $expected, string $path = self::CONTAINER_MARKER_PATH): self
    {
        $marker = self::readFromPath($path);
        $marker->assertMatches($expected);

        return $marker;
    }

    public static function readFromPath(string $path): self
    {
        self::assertAbsolutePath($path);
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false) {
            throw new InvalidArgumentException('The control-plane candidate health marker is missing.');
        }
        self::assertRegularTarget($before);

        $json = @file_get_contents($path);
        if ($json === false) {
            throw new InvalidArgumentException('The control-plane candidate health marker could not be read.');
        }

        clearstatcache(true, $path);
        $after = @lstat($path);
        if ($after === false) {
            throw new InvalidArgumentException('The control-plane candidate health marker disappeared during reading.');
        }
        self::assertRegularTarget($after);
        if (! self::isSameFile($before, $after)) {
            throw new InvalidArgumentException('The control-plane candidate health marker changed during reading.');
        }

        return self::fromJson($json);
    }

    public function assertMatches(self $expected): void
    {
        if (! hash_equals($expected->operationId, $this->operationId)
            || ! hash_equals($expected->expectedMember, $this->expectedMember)
            || ! hash_equals($expected->expectedRevision, $this->expectedRevision)
            || ! hash_equals($expected->dynamicSha256, $this->dynamicSha256)
            || ! hash_equals($expected->healthProofSha256, $this->healthProofSha256)
            || ! hash_equals($expected->authenticationProxyProofSha256, $this->authenticationProxyProofSha256)) {
            throw new InvalidArgumentException('The control-plane candidate health marker is stale.');
        }
    }

    public function toJson(): string
    {
        return json_encode([
            'operation_id' => $this->operationId,
            'expected_member' => $this->expectedMember,
            'expected_revision' => $this->expectedRevision,
            'dynamic_sha256' => $this->dynamicSha256,
            'health_proof_sha256' => $this->healthProofSha256,
            'authentication_proxy_proof_sha256' => $this->authenticationProxyProofSha256,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, int> $stat */
    private static function assertRegularTarget(array $stat): void
    {
        if (($stat['mode'] & self::FILE_TYPE_MASK) !== self::REGULAR_FILE_TYPE) {
            throw new InvalidArgumentException('The control-plane candidate health marker target must be a regular file and not a symlink.');
        }
    }

    private static function assertAbsolutePath(string $path): void
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new InvalidArgumentException('The control-plane candidate health marker path must be absolute.');
        }
    }

    private static function assertIdentifier(string $value, string $role): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The control-plane {$role} is invalid.");
        }
    }

    private static function assertOperationId(string $operationId): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,127}\z/D', $operationId) !== 1) {
            throw new InvalidArgumentException('The control-plane enrollment operation ID is invalid.');
        }
    }

    private static function assertSha256(string $value, string $label): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The control-plane {$label} must be a SHA-256 value.");
        }
    }

    /** @param array<string, int> $before
     * @param  array<string, int>  $after
     */
    private static function isSameFile(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $attribute) {
            if (($before[$attribute] ?? null) !== ($after[$attribute] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
