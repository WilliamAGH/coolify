<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;

final readonly class ControlPlaneProxyRouteProof
{
    public const PUBLIC_ROUTE = 'public';

    public const APP_PORT_ROUTE = 'app-port';

    public const BACKEND_MEMBER_HEADER = 'X-Coolify-Control-Plane-Backend-Member';

    public const BACKEND_REVISION_HEADER = 'X-Coolify-Control-Plane-Backend-Revision';

    public const DYNAMIC_SHA256_HEADER = 'X-Coolify-Control-Plane-Dynamic-Sha256';

    public function __construct(
        public string $canonicalHost,
        public string $publicScheme,
        public int $appPort,
        public string $expectedColor,
        public string $expectedGeneration,
        public string $expectedBackendMember,
        public string $expectedBackendRevision,
        public string $dynamicReplacementSha256,
        public string $configurationAcknowledgement,
        public int $maximumAttempts = 5,
        public int $pollIntervalSeconds = 3,
        public int $connectTimeoutSeconds = 2,
        public int $requestTimeoutSeconds = 3,
    ) {
        $this->assertHost($canonicalHost);
        if (! in_array($publicScheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('The public control-plane route scheme must be http or https.');
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
        if ($maximumAttempts < 2 || $maximumAttempts > 10) {
            throw new InvalidArgumentException('The control-plane route proof must allow between two and ten polling attempts.');
        }
        if ($pollIntervalSeconds < 0 || $pollIntervalSeconds > 60) {
            throw new InvalidArgumentException('The control-plane route proof polling interval must be between zero and sixty seconds.');
        }
        if ($connectTimeoutSeconds < 1 || $connectTimeoutSeconds > $requestTimeoutSeconds) {
            throw new InvalidArgumentException('The control-plane route proof connection timeout must not exceed its request timeout.');
        }
        if ($requestTimeoutSeconds < 1 || $requestTimeoutSeconds > 15) {
            throw new InvalidArgumentException('The control-plane route proof request timeout must be between one and fifteen seconds.');
        }
    }

    public function canonicalPublicUrl(): string
    {
        return "{$this->publicScheme}://{$this->canonicalHost}/api/health";
    }

    public function directAppPortUrl(): string
    {
        return "http://127.0.0.1:{$this->appPort}/api/health";
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
            self::DYNAMIC_SHA256_HEADER => $this->dynamicReplacementSha256,
        ];
    }

    public function shellCommand(): string
    {
        $commands = [
            'set -eu',
            'consecutive_successful_rounds=0',
            'round=1',
            "while [ \"\$round\" -le {$this->maximumAttempts} ]; do",
            '  round_successful=1',
        ];
        foreach ([self::PUBLIC_ROUTE, self::APP_PORT_ROUTE] as $route) {
            $commands[] = '  '.str_replace("\n", "\n  ", $this->attemptCommand($route));
        }
        $commands = [
            ...$commands,
            '  if [ "$round_successful" -eq 1 ]; then',
            '    consecutive_successful_rounds=$((consecutive_successful_rounds + 1))',
            '  else',
            '    consecutive_successful_rounds=0',
            '  fi',
            '  if [ "$consecutive_successful_rounds" -ge 2 ]; then',
            "    printf '%s %s\\n' '__COOLIFY_ROUTE_PROOF_CONVERGED__' \"\$round\"",
            '    exit 0',
            '  fi',
            "  if [ \"\$round\" -lt {$this->maximumAttempts} ]; then",
            "    sleep {$this->pollIntervalSeconds}",
            '  fi',
            '  round=$((round + 1))',
            'done',
            "printf '%s %s\\n' '__COOLIFY_ROUTE_PROOF_TIMEOUT__' {$this->maximumAttempts}",
        ];

        return implode("\n", $commands);
    }

    private function attemptCommand(string $route): string
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
            '--connect-timeout',
            (string) $this->connectTimeoutSeconds,
            '--max-time',
            (string) $this->requestTimeoutSeconds,
            '--request',
            'GET',
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
            '\n__COOLIFY_ROUTE_PROOF_STATUS__ %{http_code}\n',
            '--',
            $url,
        ];

        return implode("\n", [
            "printf '%s %s\\n' ".escapeshellarg("__COOLIFY_ROUTE_PROOF_BEGIN__ {$route}").' "$round"',
            'curl_exit=0',
            'if output=$('.implode(' ', array_map(static fn (string $argument): string => escapeshellarg($argument), $arguments)).' 2>/dev/null); then',
            '  :',
            'else',
            '  curl_exit=$?',
            'fi',
            "printf '%s\\n' \"\$output\"",
            "printf '%s %s\\n' '__COOLIFY_ROUTE_PROOF_CURL_EXIT__' \"\$curl_exit\"",
            "printf '%s\\n' '__COOLIFY_ROUTE_PROOF_END__'",
            "status=\$(printf '%s\\n' \"\$output\" | sed -n 's/^__COOLIFY_ROUTE_PROOF_STATUS__ \\([0-9][0-9][0-9]\\)$/\\1/p')",
            'case "'.$route.':$curl_exit:$status" in',
            "  {$route}:0:200)",
            '    :',
            '    ;;',
            "  {$route}:*)",
            '    round_successful=0',
            '    ;;',
            'esac',
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
