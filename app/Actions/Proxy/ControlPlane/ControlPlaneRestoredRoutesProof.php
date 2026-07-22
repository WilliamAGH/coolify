<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;

final readonly class ControlPlaneRestoredRoutesProof
{
    public const TRANSCRIPT_BEGIN = '__COOLIFY_RESTORED_ROUTE_PROOF_BEGIN__';

    public const TRANSCRIPT_STATUS = '__COOLIFY_RESTORED_ROUTE_PROOF_STATUS__';

    public const TRANSCRIPT_CURL_EXIT = '__COOLIFY_RESTORED_ROUTE_PROOF_CURL_EXIT__';

    public const TRANSCRIPT_END = '__COOLIFY_RESTORED_ROUTE_PROOF_END__';

    public const TRANSCRIPT_CONVERGED = '__COOLIFY_RESTORED_ROUTE_PROOF_CONVERGED__';

    public const TRANSCRIPT_TIMEOUT = '__COOLIFY_RESTORED_ROUTE_PROOF_TIMEOUT__';

    public function __construct(
        public string $canonicalHost,
        public string $publicScheme,
        public int $appPort,
        public string $expectedBackendMember,
        public string $expectedBackendRevision,
        public string $expectedDynamicPredecessorSha256,
        public int $maximumAttempts = 5,
        public int $pollIntervalSeconds = 3,
        public int $connectTimeoutSeconds = 2,
        public int $requestTimeoutSeconds = 3,
        public bool $publicRouteExpected = true,
    ) {
        $this->assertHost($canonicalHost);
        if (! in_array($publicScheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('The public restored control-plane route scheme must be http or https.');
        }
        if ($appPort < 1 || $appPort > 65535) {
            throw new InvalidArgumentException('The restored control-plane APP_PORT must be between 1 and 65535.');
        }
        $this->assertIdentifier($expectedBackendMember, 'expected backend member');
        $this->assertIdentifier($expectedBackendRevision, 'expected backend revision');
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedDynamicPredecessorSha256) !== 1) {
            throw new InvalidArgumentException('The expected restored dynamic predecessor checksum must be a SHA-256 value.');
        }
        if ($maximumAttempts < 2 || $maximumAttempts > 10) {
            throw new InvalidArgumentException('The restored control-plane route proof must allow between two and ten polling attempts.');
        }
        if ($pollIntervalSeconds < 0 || $pollIntervalSeconds > 60) {
            throw new InvalidArgumentException('The restored control-plane route proof polling interval must be between zero and sixty seconds.');
        }
        if ($connectTimeoutSeconds < 1 || $connectTimeoutSeconds > $requestTimeoutSeconds) {
            throw new InvalidArgumentException('The restored control-plane route proof connection timeout must not exceed its request timeout.');
        }
        if ($requestTimeoutSeconds < 1 || $requestTimeoutSeconds > 15) {
            throw new InvalidArgumentException('The restored control-plane route proof request timeout must be between one and fifteen seconds.');
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

    /** @return array<string, string> */
    public function expectedResponseHeaders(): array
    {
        return [
            ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER => $this->expectedBackendMember,
            ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER => $this->expectedBackendRevision,
            ControlPlaneProxyRouteProof::DYNAMIC_SHA256_HEADER => $this->expectedDynamicPredecessorSha256,
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
        foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
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
            "    printf '%s %s\\n' '".self::TRANSCRIPT_CONVERGED."' \"\$round\"",
            '    exit 0',
            '  fi',
            "  if [ \"\$round\" -lt {$this->maximumAttempts} ]; then",
            "    sleep {$this->pollIntervalSeconds}",
            '  fi',
            '  round=$((round + 1))',
            'done',
            "printf '%s %s\\n' '".self::TRANSCRIPT_TIMEOUT."' {$this->maximumAttempts}",
        ];

        return implode("\n", $commands);
    }

    private function attemptCommand(string $route): string
    {
        $url = match ($route) {
            ControlPlaneProxyRouteProof::PUBLIC_ROUTE => $this->canonicalPublicUrl(),
            ControlPlaneProxyRouteProof::APP_PORT_ROUTE => $this->directAppPortUrl(),
            default => throw new InvalidArgumentException('The restored control-plane route proof route is invalid.'),
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
        if ($route === ControlPlaneProxyRouteProof::APP_PORT_ROUTE) {
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
            '\n'.self::TRANSCRIPT_STATUS.' %{http_code}\n',
            '--',
            $url,
        ];

        $successfulCases = $route === ControlPlaneProxyRouteProof::PUBLIC_ROUTE && ! $this->publicRouteExpected
            ? ["  {$route}:22:404|{$route}:22:503)"]
            : ["  {$route}:0:200)"];

        return implode("\n", [
            "printf '%s %s\\n' ".escapeshellarg(self::TRANSCRIPT_BEGIN." {$route}").' "$round"',
            'curl_exit=0',
            'if output=$('.implode(' ', array_map(static fn (string $argument): string => escapeshellarg($argument), $arguments)).' 2>/dev/null); then',
            '  :',
            'else',
            '  curl_exit=$?',
            'fi',
            "printf '%s\\n' \"\$output\"",
            "printf '%s %s\\n' '".self::TRANSCRIPT_CURL_EXIT."' \"\$curl_exit\"",
            "printf '%s\\n' '".self::TRANSCRIPT_END."'",
            "status=\$(printf '%s\\n' \"\$output\" | sed -n 's/^".self::TRANSCRIPT_STATUS." \\([0-9][0-9][0-9]\\)$/\\1/p')",
            'case "'.$route.':$curl_exit:$status" in',
            ...$successfulCases,
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
            throw new InvalidArgumentException('The canonical restored control-plane host must be a safe DNS name or IPv4 address.');
        }
    }

    private function assertIdentifier(string $value, string $role): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) !== 1) {
            throw new InvalidArgumentException("The restored control-plane {$role} is invalid.");
        }
    }
}
