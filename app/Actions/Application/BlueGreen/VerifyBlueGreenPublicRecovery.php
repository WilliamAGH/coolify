<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Models\Application;
use App\Models\Server;
use Illuminate\Support\Sleep;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Spatie\Url\Url;
use Throwable;

class VerifyBlueGreenPublicRecovery
{
    use AsAction;

    /** @param list<array{router: string, url: string}> $routes */
    public function handle(
        Server $server,
        Application $application,
        array $routes,
        string $expectedAcknowledgement,
    ): void {
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedAcknowledgement) !== 1) {
            throw new \InvalidArgumentException('Public recovery requires one exact opaque acknowledgement.');
        }
        $attempts = max(10, (int) $application->health_check_retries);
        $lastFailure = 'No direct-origin response was observed.';
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                foreach ($routes as $route) {
                    $request = $this->requestFor($application, $route);
                    $headers = (string) instant_remote_process(
                        [$request['command']],
                        $server,
                        input: $request['input'],
                    );
                    $this->assertResponse($route, $headers, $expectedAcknowledgement);
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
     * @param  array{router: string, url: string}  $route
     * @return array{command: string, input: string}
     */
    public function requestFor(Application $application, array $route): array
    {
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
        $nonceUrl = $route['url'].'?__coolify_blue_green_recovery='.bin2hex(random_bytes(16));
        $config = [
            'silent',
            'show-error',
            'http1.1',
            'noproxy = '.$this->curlConfigValue('*'),
            'connect-timeout = 5',
            'max-time = 15',
            'output = '.$this->curlConfigValue('/dev/null'),
            'dump-header = '.$this->curlConfigValue('-'),
            'header = '.$this->curlConfigValue('Cache-Control: no-cache, no-store, max-age=0'),
            'header = '.$this->curlConfigValue('Pragma: no-cache'),
            'resolve = '.$this->curlConfigValue("{$host}:{$port}:127.0.0.1"),
        ];
        if ($application->is_http_basic_auth_enabled) {
            $config[] = 'user = '.$this->curlConfigValue("{$application->http_basic_auth_username}:{$application->http_basic_auth_password}");
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

    /** @param array{router: string, url: string} $route */
    public function assertResponse(array $route, string $headers, string $expectedAcknowledgement): void
    {
        preg_match_all('/^HTTP\/(?:1\.[01]|2|3)\s+(\d{3})\b/mi', $headers, $statusMatches);
        $statuses = $statusMatches[1];
        $status = (int) (end($statuses) ?: 0);
        if ($status === 0 || $status === 404 || $status >= 500) {
            throw new RuntimeException("The restored router {$route['router']} returned gateway/server status {$status}.");
        }
        preg_match_all('/^'.preg_quote(BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER, '/').':\s*(.*?)\s*$/mi', $headers, $acknowledgementMatches);
        $acknowledgements = array_values(array_unique(array_filter(
            $acknowledgementMatches[1],
            static fn (string $acknowledgement): bool => trim($acknowledgement) !== '',
        )));
        if ($acknowledgements !== [$expectedAcknowledgement]) {
            throw new RuntimeException("The restored router {$route['router']} did not return its exact opaque acknowledgement.");
        }
    }
}
