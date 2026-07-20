<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;

final readonly class ControlPlaneDynamicConfiguration
{
    public const MANAGED_FILENAME = 'coolify.yaml';

    public const HTTPS_ROUTER = 'coolify-https';

    public const HTTP_ROUTER = 'coolify-http';

    public const APP_PORT_ROUTER = 'coolify-app-port';

    public const SERVICE = 'coolify-control-plane';

    public const IDENTITY_MIDDLEWARE = 'coolify-control-plane-identity';

    public const HTTPS_REDIRECT_MIDDLEWARE = 'redirect-to-https';

    public const COLOR_HEADER = 'X-Coolify-Control-Plane-Color';

    public const GENERATION_HEADER = 'X-Coolify-Control-Plane-Generation';

    public const CONFIGURATION_ACKNOWLEDGEMENT_HEADER = 'X-Coolify-Control-Plane-Config-Ack';

    public const HEALTH_PROOF_HEADER = 'X-Coolify-Control-Plane-Health-Proof';

    public const AUTHENTICATION_PROXY_PROOF_HEADER = 'X-Coolify-Control-Plane-Authentication-Proof';

    public const AUTHENTICATION_PROXY_PROOF_DERIVATION_CONTEXT = 'coolify-control-plane-authentication-proxy-v1';

    public const HEALTH_PROOF_DERIVATION_CONTEXT = 'coolify-control-plane-health-check-v1';

    public static function deriveAuthenticationProxyProof(string $healthCheckProof): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $healthCheckProof) !== 1) {
            throw new InvalidArgumentException('The control-plane health-check proof must be a SHA-256 value.');
        }

        return hash_hmac('sha256', self::AUTHENTICATION_PROXY_PROOF_DERIVATION_CONTEXT, $healthCheckProof);
    }

    public function __construct(
        public string $managedFilename,
        public string $yaml,
        public string $sha256,
    ) {
        if ($managedFilename !== self::MANAGED_FILENAME) {
            throw new InvalidArgumentException('Control-plane dynamic configuration must use the single managed filename.');
        }
        if (! hash_equals($sha256, hash('sha256', $yaml))) {
            throw new InvalidArgumentException('Control-plane dynamic configuration bytes and checksum do not agree.');
        }
    }
}
