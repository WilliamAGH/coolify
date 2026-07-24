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
     * The release proof header is optional — only applications that echo
     * COOLIFY_DEPLOYMENT_RELEASE_PROOF emit it — but strict when present.
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

    /**
     * Verifies every route with the bounded provider apply-lag budget:
     * healthy responses carrying a stale or absent acknowledgement are
     * retried while Traefik's file provider converges on a freshly written
     * managed file; every other failure is terminal on first observation.
     *
     * @param  list<array{router: string, url: string}>  $routes
     */
    public function verifyRoutesAbsorbingProviderLag(
        Server $server,
        Application $application,
        array $routes,
        ?string $expectedAcknowledgement,
        ?string $expectedReleaseProof = null,
        string $nonceParameter = self::RECOVERY_NONCE_PARAMETER,
        ?Closure $beforeRequest = null,
    ): void {
        retry(
            max(10, (int) $application->health_check_retries),
            function () use ($server, $application, $routes, $expectedAcknowledgement, $expectedReleaseProof, $nonceParameter, $beforeRequest): void {
                foreach ($routes as $route) {
                    $this->verifyRoute(
                        $server,
                        $application,
                        $route,
                        $expectedAcknowledgement,
                        $expectedReleaseProof,
                        nonceParameter: $nonceParameter,
                        beforeRequest: $beforeRequest,
                    );
                }
            },
            1000,
            fn (Throwable $exception): bool => self::isConvergingRouteObservation($exception),
        );
    }

    /**
     * A managed host with no blue-green route answers with the server's
     * catchall: 404 when the default redirect is disabled, 503 from the
     * default empty-service catchall, or 302 when a redirect URL is set
     * (Server::setupDefaultRedirect). Managed routes attach the probe
     * acknowledgement through a response middleware even on backend error
     * statuses, so a catchall status without any acknowledgement can only
     * mean the route is not present in Traefik.
     *
     * @param  list<string>  $acknowledgements
     */
    public static function indicatesRouteAbsence(int $status, array $acknowledgements): bool
    {
        return $acknowledgements === [] && in_array($status, [404, 503, 302], true);
    }

    /**
     * Traefik's file provider applies a freshly written managed file only
     * after its throttle window (~2s), during which the previous file keeps
     * serving. A healthy response carrying a stale or absent acknowledgement
     * is therefore a converging observation worth retrying; any error status
     * stays terminal so the post-switch zero-error guarantee holds.
     */
    public static function isConvergingRouteObservation(Throwable $exception): bool
    {
        return $exception instanceof BlueGreenPublicRouteAcknowledgementMismatch
            && $exception->status >= 200
            && $exception->status < 400;
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

    /**
     * Two-proof fenced-route acceptance. A routed response is accepted only
     * when it satisfies at least one Coolify-controlled proof and never
     * contradicts either:
     *
     *  1. Managed-route acknowledgement (primary, always required when
     *     $expectedAcknowledgement is non-null): the exact opaque
     *     acknowledgement that Traefik's response middleware attaches to the
     *     fenced managed route. This is written by Coolify — not the
     *     application — so it proves the fenced route points at this exact
     *     new deployment's backend regardless of what the app emits.
     *  2. Application release proof (secondary, strict only when present): the
     *     RELEASE_PROOF_HEADER an application may echo. Only the control-plane
     *     app (RespondToControlPlaneHealthCheck) emits it; ordinary
     *     applications (Next.js, Spring, ...) cannot. It is therefore treated
     *     as optional — verified exactly when the app emits it (defense in
     *     depth) and skipped when absent — but a wrong value is always fatal.
     *
     * The acknowledgement is the fail-closed boundary: a route that proves
     * neither the acknowledgement nor a matching release proof is rejected.
     * A null $expectedAcknowledgement inverts proof (1) to assert that no
     * managed acknowledgement leaks on a restored plain direct-origin route.
     *
     * @param  array{router: string, url: string}  $route
     */
    public function assertResponse(
        array $route,
        string $headers,
        ?string $expectedAcknowledgement,
        ?string $expectedReleaseProof = null,
    ): void {
        ['status' => $status, 'acknowledgements' => $acknowledgements] = $this->responseFor($headers);
        if ($status < 200 || $status >= 400) {
            throw new BlueGreenPublicRouteAcknowledgementMismatch(
                status: $status,
                acknowledgements: $acknowledgements,
                message: "The restored router {$route['router']} returned an ineligible public status {$status}.",
            );
        }
        if ($expectedAcknowledgement === null) {
            if ($acknowledgements !== []) {
                throw new BlueGreenPublicRouteAcknowledgementMismatch(
                    status: $status,
                    acknowledgements: $acknowledgements,
                    message: "The restored router {$route['router']} leaked the reserved probe acknowledgement.",
                );
            }

            return;
        }
        if ($acknowledgements !== [$expectedAcknowledgement]) {
            throw new BlueGreenPublicRouteAcknowledgementMismatch(
                status: $status,
                acknowledgements: $acknowledgements,
                message: "The restored router {$route['router']} did not return its exact opaque acknowledgement.",
            );
        }
        if ($expectedReleaseProof === null) {
            return;
        }
        // The acknowledgement above already proved the fenced route. The
        // release proof is the optional secondary proof: an ordinary app emits
        // no RELEASE_PROOF_HEADER, so an absent header is accepted, but a
        // present-and-wrong value proves a stale or foreign backend and is
        // always fatal (never a converging observation).
        preg_match_all('/^'.preg_quote(BlueGreenRoutingTarget::RELEASE_PROOF_HEADER, '/').':\s*(.*?)\s*$/mi', $headers, $releaseProofMatches);
        $releaseProofs = array_values(array_unique(array_filter(
            $releaseProofMatches[1],
            static fn (string $releaseProof): bool => trim($releaseProof) !== '',
        )));
        if ($releaseProofs !== [] && $releaseProofs !== [$expectedReleaseProof]) {
            throw new RuntimeException("The restored router {$route['router']} did not return the exact application release proof.");
        }
    }
}
