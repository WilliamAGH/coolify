<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class VerifyControlPlaneProxyRoutes
{
    use AsAction;

    public function handle(
        ControlPlaneProxyRouteProof $proof,
        string $curlTranscript,
    ): ControlPlaneProxyRouteProof {
        $records = $this->parseTranscript($curlTranscript);
        $expectedRecords = [];
        foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
            foreach ([1, 2] as $attempt) {
                $expectedRecords["{$route}:{$attempt}"] = true;
            }
        }
        ksort($expectedRecords, SORT_STRING);
        if (array_keys($records) !== array_keys($expectedRecords)) {
            throw new InvalidArgumentException('Control-plane route proof output is partial, duplicated, or contains an unexpected route attempt.');
        }

        $expectedHeaders = $proof->expectedResponseHeaders();
        $firstIdentityHeaders = null;
        foreach ($records as $record) {
            if ($record['status'] !== 204) {
                throw new InvalidArgumentException('Control-plane route proof did not receive the expected successful health response.');
            }
            $identityHeaders = [];
            foreach ($expectedHeaders as $header => $expectedValue) {
                $actualValue = $record['headers'][strtolower($header)] ?? null;
                if (! is_string($actualValue) || ! hash_equals($expectedValue, $actualValue)) {
                    throw new InvalidArgumentException("Control-plane route proof has a missing or stale {$header} response header.");
                }
                $identityHeaders[$header] = $actualValue;
            }
            if ($firstIdentityHeaders === null) {
                $firstIdentityHeaders = $identityHeaders;
            } elseif ($identityHeaders !== $firstIdentityHeaders) {
                throw new InvalidArgumentException('Control-plane public and APP_PORT routes do not expose the same applied proof.');
            }
        }

        return $proof;
    }

    /**
     * @return array<string, array{route: string, attempt: int, status: int, headers: array<string, string>}>
     */
    private function parseTranscript(string $curlTranscript): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $curlTranscript);
        if (! is_array($lines)) {
            throw new InvalidArgumentException('Control-plane route proof output could not be parsed.');
        }
        $index = 0;
        $records = [];
        while ($index < count($lines)) {
            if ($lines[$index] === '') {
                $index++;

                continue;
            }
            if (preg_match('/^__COOLIFY_ROUTE_PROOF_BEGIN__ (public|app-port) ([12])$/D', $lines[$index], $begin) !== 1) {
                throw new InvalidArgumentException('Control-plane route proof output has an invalid record boundary.');
            }
            $route = $begin[1];
            $attempt = (int) $begin[2];
            $index++;
            $headerLines = [];
            while ($index < count($lines) && ! str_starts_with($lines[$index], '__COOLIFY_ROUTE_PROOF_STATUS__ ')) {
                if (str_starts_with($lines[$index], '__COOLIFY_ROUTE_PROOF_')) {
                    throw new InvalidArgumentException('Control-plane route proof output has a malformed curl record.');
                }
                $headerLines[] = $lines[$index];
                $index++;
            }
            if ($index >= count($lines)
                || preg_match('/^__COOLIFY_ROUTE_PROOF_STATUS__ ([0-9]{3})$/D', $lines[$index], $status) !== 1) {
                throw new InvalidArgumentException('Control-plane route proof output has no valid curl status.');
            }
            $index++;
            if (($lines[$index] ?? null) !== '__COOLIFY_ROUTE_PROOF_END__') {
                throw new InvalidArgumentException('Control-plane route proof output has no record terminator.');
            }
            $index++;

            $record = [
                'route' => $route,
                'attempt' => $attempt,
                'status' => (int) $status[1],
                'headers' => $this->parseHeaders($headerLines, (int) $status[1]),
            ];
            $key = "{$route}:{$attempt}";
            if (isset($records[$key])) {
                throw new InvalidArgumentException('Control-plane route proof output repeats a route attempt.');
            }
            $records[$key] = $record;
        }
        ksort($records, SORT_STRING);

        return $records;
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, string>
     */
    private function parseHeaders(array $lines, int $curlStatus): array
    {
        $headerBlocks = [];
        $current = null;
        foreach ($lines as $line) {
            if (preg_match('/^HTTP\/[0-9.]+ ([0-9]{3})(?: .*)?$/D', $line, $status) === 1) {
                if ($current !== null) {
                    throw new InvalidArgumentException('Control-plane route proof response headers are not separated correctly.');
                }
                $current = ['status' => (int) $status[1], 'headers' => []];

                continue;
            }
            if ($line === '') {
                if ($current !== null) {
                    $headerBlocks[] = $current;
                    $current = null;
                }

                continue;
            }
            if ($current === null || preg_match('/^([^:\s]+):[ \t]*(.*)$/D', $line, $header) !== 1) {
                throw new InvalidArgumentException('Control-plane route proof response headers are malformed.');
            }
            $name = strtolower($header[1]);
            if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $header[1]) !== 1
                || isset($current['headers'][$name])) {
                throw new InvalidArgumentException('Control-plane route proof response headers are ambiguous.');
            }
            $value = trim($header[2]);
            if (preg_match('/^[\x20-\x7E]*$/D', $value) !== 1) {
                throw new InvalidArgumentException('Control-plane route proof response header values are malformed.');
            }
            $current['headers'][$name] = $value;
        }
        if ($current !== null) {
            throw new InvalidArgumentException('Control-plane route proof response headers have no terminating blank line.');
        }
        if ($headerBlocks === []) {
            throw new InvalidArgumentException('Control-plane route proof has no HTTP response headers.');
        }
        $final = $headerBlocks[array_key_last($headerBlocks)];
        if ($final['status'] !== $curlStatus) {
            throw new InvalidArgumentException('Control-plane route proof curl status and response status do not agree.');
        }
        ksort($final['headers'], SORT_STRING);

        return $final['headers'];
    }
}
