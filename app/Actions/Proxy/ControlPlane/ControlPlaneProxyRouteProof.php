<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;

final readonly class ControlPlaneProxyRouteProof
{
    public const PUBLIC_ROUTE = 'public';

    public const APP_PORT_ROUTE = 'app-port';

    public const PROOF_HEADER = 'X-Coolify-Control-Plane-Route-Proof';

    public const BACKEND_MEMBER_HEADER = 'X-Coolify-Control-Plane-Backend-Member';

    public const BACKEND_REVISION_HEADER = 'X-Coolify-Control-Plane-Backend-Revision';

    public function __construct(
        public string $canonicalHost,
        public string $publicScheme,
        public string $directIpv4,
        public int $appPort,
        public string $expectedColor,
        public string $expectedGeneration,
        public string $expectedBackendMember,
        public string $expectedBackendRevision,
        public string $dynamicReplacementSha256,
        public string $configurationAcknowledgement,
        public string $proofToken,
    ) {
        $this->assertHost($canonicalHost);
        if (! in_array($publicScheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('The public control-plane route scheme must be http or https.');
        }
        if (filter_var($directIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('The direct control-plane route must use a safe IPv4 address.');
        }
        if ($appPort < 1 || $appPort > 65535) {
            throw new InvalidArgumentException('The control-plane APP_PORT must be between 1 and 65535.');
        }
        foreach ([
            'expected color' => $expectedColor,
            'expected generation' => $expectedGeneration,
            'expected backend member' => $expectedBackendMember,
            'expected backend revision' => $expectedBackendRevision,
        ] as $role => $value) {
            $this->assertIdentifier($value, $role);
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $dynamicReplacementSha256) !== 1) {
            throw new InvalidArgumentException('The dynamic replacement digest must be a SHA-256 value.');
        }
        if (preg_match('/^[A-Za-z0-9._~+\/=:-]{16,512}$/D', $configurationAcknowledgement) !== 1) {
            throw new InvalidArgumentException('The control-plane configuration acknowledgement must be opaque and single-line.');
        }
        if (preg_match('/^[A-Za-z0-9._~+\/=:-]{16,512}$/D', $proofToken) !== 1) {
            throw new InvalidArgumentException('The control-plane route proof token must be opaque and single-line.');
        }
    }

    public function canonicalPublicUrl(): string
    {
        return "{$this->publicScheme}://{$this->canonicalHost}/api/health";
    }

    public function directAppPortUrl(): string
    {
        return "http://{$this->directIpv4}:{$this->appPort}/api/health";
    }

    public function configurationAcknowledgement(): string
    {
        return $this->configurationAcknowledgement;
    }

    /** @return array<string, string> */
    public function expectedResponseHeaders(): array
    {
        return [
            ControlPlaneDynamicConfiguration::COLOR_HEADER => $this->expectedColor,
            ControlPlaneDynamicConfiguration::GENERATION_HEADER => $this->expectedGeneration,
            ControlPlaneDynamicConfiguration::CONFIGURATION_ACKNOWLEDGEMENT_HEADER => $this->configurationAcknowledgement(),
            self::BACKEND_MEMBER_HEADER => $this->expectedBackendMember,
            self::BACKEND_REVISION_HEADER => $this->expectedBackendRevision,
        ];
    }

    public function shellCommand(): string
    {
        $commands = ['set -eu'];
        foreach ([self::PUBLIC_ROUTE, self::APP_PORT_ROUTE] as $route) {
            foreach ([1, 2] as $attempt) {
                $commands[] = $this->attemptCommand($route, $attempt);
            }
        }

        return implode("\n", $commands);
    }

    private function attemptCommand(string $route, int $attempt): string
    {
        $url = match ($route) {
            self::PUBLIC_ROUTE => $this->canonicalPublicUrl(),
            self::APP_PORT_ROUTE => $this->directAppPortUrl(),
            default => throw new InvalidArgumentException('The control-plane route proof route is invalid.'),
        };
        $arguments = [
            'curl',
            '--fail',
            '--silent',
            '--show-error',
            '--connect-timeout',
            '5',
            '--max-time',
            '15',
            '--request',
            'GET',
            '--header',
            self::PROOF_HEADER.': '.$this->proofToken,
        ];
        if ($route === self::APP_PORT_ROUTE) {
            $arguments[] = '--header';
            $arguments[] = 'Host: '.$this->canonicalHost;
        }
        $arguments = [
            ...$arguments,
            '--dump-header',
            '-',
            '--output',
            '/dev/null',
            '--write-out',
            "\n__COOLIFY_ROUTE_PROOF_STATUS__ %{http_code}\n",
            '--',
            $url,
        ];

        return implode("\n", [
            "printf '%s\\n' ".escapeshellarg("__COOLIFY_ROUTE_PROOF_BEGIN__ {$route} {$attempt}"),
            implode(' ', array_map(static fn (string $argument): string => escapeshellarg($argument), $arguments)),
            "printf '%s\\n' ".escapeshellarg('__COOLIFY_ROUTE_PROOF_END__'),
        ]);
    }

    private function assertHost(string $host): void
    {
        $isIpv4Address = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isDnsName = preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/D', $host) === 1;
        if (! $isIpv4Address && ! $isDnsName) {
            throw new InvalidArgumentException('The canonical control-plane host must be a safe DNS name or IPv4 address.');
        }
    }

    private function assertIdentifier(string $value, string $role): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) !== 1) {
            throw new InvalidArgumentException("The {$role} is invalid.");
        }
    }
}
