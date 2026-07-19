<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Models\Application;
use App\Models\Server;
use Closure;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Spatie\Url\Url;
use Throwable;

class VerifyBlueGreenPublicRecovery
{
    use AsAction;

    public const DEPLOYMENT_NONCE_PARAMETER = '__coolify_blue_green_probe';

    public const RECOVERY_NONCE_PARAMETER = '__coolify_blue_green_recovery';

    /** @param list<array{router: string, url: string}> $routes */
    public function handle(
        Server $server,
        Application $application,
        array $routes,
        string $expectedAcknowledgement,
    ): void {
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedAcknowledgement) !== 1) {
            throw new InvalidArgumentException('Public recovery requires one exact opaque acknowledgement.');
        }
        $attempts = max(10, (int) $application->health_check_retries);
        $lastFailure = 'No direct-origin response was observed.';
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                foreach ($routes as $route) {
                    $this->verifyRoute($server, $application, $route, $expectedAcknowledgement);
                }

                return;
            } catch (Throwable $exception) {
                $lastFailure = $exception->getMessage();
            }
            if ($attempt < $attempts) {
                Sleep::for(1)->seconds();
            }
        }

        throw new RuntimeException("The restored direct-origin route did not recover: {$lastFailure}");
    }

    /**
     * Single-shot direct-origin proof shared by recovery (its own retry loop in
     * handle()) and deployment verification (the lifecycle's fenced retry loop).
     * A null $expectedAcknowledgement asserts no acknowledgement leaks at all;
     * $beforeRequest runs after the request is built and before it is sent.
     *
     * @param  array{router: string, url: string}  $route
     */
    public function verifyRoute(
        Server $server,
        Application $application,
        array $route,
        ?string $expectedAcknowledgement,
        ?string $expectedReleaseProof = null,
        ?string $probeHeader = null,
        ?string $probeToken = null,
        string $nonceParameter = self::RECOVERY_NONCE_PARAMETER,
        ?Closure $beforeRequest = null,
    ): void {
        $request = $this->requestFor($application, $route, $probeHeader, $probeToken, $nonceParameter);
        if ($beforeRequest !== null) {
            $beforeRequest();
        }
        $headers = (string) instant_remote_process(
            [$request['command']],
            $server,
            input: $request['input'],
        );
        $this->assertResponse($route, $headers, $expectedAcknowledgement, $expectedReleaseProof);
    }

    /**
     * @param  array{router: string, url: string}  $route
     * @return array{command: string, input: string}
     */
    public function requestFor(
        Application $application,
        array $route,
        ?string $probeHeader = null,
        ?string $probeToken = null,
        string $nonceParameter = self::RECOVERY_NONCE_PARAMETER,
        int $maxTimeSeconds = 15,
    ): array {
        if (($probeHeader === null) !== ($probeToken === null)) {
            throw new InvalidArgumentException('The probe header and token must either both be set or both be omitted.');
        }
        if ($maxTimeSeconds < 1 || $maxTimeSeconds > 15) {
            throw new InvalidArgumentException('The direct-origin request timeout must be between 1 and 15 seconds.');
        }
        $url = Url::fromString($route['url']);
        $port = match ($url->getScheme()) {
            'http' => 80,
            'https' => 443,
            default => throw new RuntimeException("The restored router {$route['router']} uses an unsupported URL scheme."),
        };
        $host = $url->getHost();
        if ($host === '') {
            throw new RuntimeException("The restored router {$route['router']} has no URL host.");
        }
        $nonceUrl = $route['url'].'?'.$nonceParameter.'='.bin2hex(random_bytes(16));
        $config = [
            'silent',
            'show-error',
            'http1.1',
            'noproxy = '.$this->curlConfigValue('*'),
            'connect-timeout = 5',
            'max-time = '.$maxTimeSeconds,
            'output = '.$this->curlConfigValue('/dev/null'),
            'dump-header = '.$this->curlConfigValue('-'),
            'header = '.$this->curlConfigValue('Cache-Control: no-cache, no-store, max-age=0'),
            'header = '.$this->curlConfigValue('Pragma: no-cache'),
            'header = '.$this->curlConfigValue('Connection: close'),
            'resolve = '.$this->curlConfigValue("{$host}:{$port}:127.0.0.1"),
        ];
        if ($application->is_http_basic_auth_enabled) {
            $config[] = 'user = '.$this->curlConfigValue("{$application->http_basic_auth_username}:{$application->http_basic_auth_password}");
        }
        if ($probeHeader !== null && $probeToken !== null) {
            $config[] = 'header = '.$this->curlConfigValue("{$probeHeader}: {$probeToken}");
        }
        $config[] = 'url = '.$this->curlConfigValue($nonceUrl);

        return [
            'command' => 'curl --config -',
            'input' => implode("\n", $config)."\n",
        ];
    }

    private function curlConfigValue(string $value): string
    {
        return '"'.str_replace(
            ['\\', '"', "\r", "\n"],
            ['\\\\', '\\"', '\\r', '\\n'],
            $value,
        ).'"';
    }

    /** @return array{status: int, acknowledgements: list<string>} */
    public function responseFor(string $headers): array
    {
        preg_match_all('/^HTTP\/(?:1\.[01]|2|3)\s+(\d{3})\b/mi', $headers, $statusMatches);
        $statuses = $statusMatches[1];
        $status = (int) (end($statuses) ?: 0);
        preg_match_all('/^'.preg_quote(BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER, '/').':\s*(.*?)\s*$/mi', $headers, $acknowledgementMatches);
        $acknowledgements = array_values(array_unique(array_filter(
            $acknowledgementMatches[1],
            static fn (string $acknowledgement): bool => trim($acknowledgement) !== '',
        )));

        return compact('status', 'acknowledgements');
    }

    /** @param array{router: string, url: string} $route */
    public function assertResponse(
        array $route,
        string $headers,
        ?string $expectedAcknowledgement,
        ?string $expectedReleaseProof = null,
    ): void {
        ['status' => $status, 'acknowledgements' => $acknowledgements] = $this->responseFor($headers);
        if ($status < 200 || $status >= 400) {
            throw new RuntimeException("The restored router {$route['router']} returned an ineligible public status {$status}.");
        }
        if ($expectedAcknowledgement === null) {
            if ($acknowledgements !== []) {
                throw new RuntimeException("The restored router {$route['router']} leaked the reserved probe acknowledgement.");
            }

            return;
        }
        if ($acknowledgements !== [$expectedAcknowledgement]) {
            throw new RuntimeException("The restored router {$route['router']} did not return its exact opaque acknowledgement.");
        }
        if ($expectedReleaseProof === null) {
            return;
        }
        preg_match_all('/^'.preg_quote(BlueGreenRoutingTarget::RELEASE_PROOF_HEADER, '/').':\s*(.*?)\s*$/mi', $headers, $releaseProofMatches);
        $releaseProofs = array_values(array_unique(array_filter(
            $releaseProofMatches[1],
            static fn (string $releaseProof): bool => trim($releaseProof) !== '',
        )));
        if ($releaseProofs !== [$expectedReleaseProof]) {
            throw new RuntimeException("The restored router {$route['router']} did not return the exact application release proof.");
        }
    }
}
