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
        $transcript = $this->parseTranscript($curlTranscript);
        $records = $transcript['records'];
        $terminal = $transcript['terminal'];
        if ($terminal['type'] === 'timeout' && $terminal['attempt'] !== $proof->maximumAttempts) {
            throw new InvalidArgumentException('Control-plane route proof output has an invalid timeout boundary.');
        }
        if ($terminal['type'] === 'converged'
            && ($terminal['attempt'] < 2 || $terminal['attempt'] > $proof->maximumAttempts)) {
            throw new InvalidArgumentException('Control-plane route proof output has an invalid convergence boundary.');
        }

        $expectedRecordCount = $terminal['attempt'] * 2;
        if (count($records) !== $expectedRecordCount) {
            throw new InvalidArgumentException('Control-plane route proof output is partial, duplicated, or contains an unexpected route attempt.');
        }
        $recordIndex = 0;
        foreach (range(1, $terminal['attempt']) as $attempt) {
            foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
                $record = $records[$recordIndex++] ?? null;
                if ($record === null || $record['route'] !== $route || $record['attempt'] !== $attempt) {
                    throw new InvalidArgumentException('Control-plane route proof output is partial, duplicated, or contains an unexpected route attempt.');
                }
            }
        }

        $expectedHeaders = $proof->expectedResponseHeaders();
        $consecutiveExactRounds = 0;
        $firstConvergedAttempt = null;
        $recordIndex = 0;
        foreach (range(1, $terminal['attempt']) as $attempt) {
            $isExactRound = true;
            $identityHeadersByRoute = [];
            foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
                $record = $records[$recordIndex++];
                if ($record['status'] !== 200) {
                    $isExactRound = false;

                    continue;
                }
                $identityHeaders = [];
                foreach ($expectedHeaders as $header => $expectedValue) {
                    $actualValue = $record['headers'][strtolower($header)] ?? null;
                    if (! is_string($actualValue) || ! hash_equals($expectedValue, $actualValue)) {
                        throw new InvalidArgumentException("Control-plane route proof has a missing or stale {$header} response header.");
                    }
                    $identityHeaders[$header] = $actualValue;
                }
                if ($record['curlExit'] !== 0) {
                    $isExactRound = false;

                    continue;
                }
                $identityHeadersByRoute[$route] = $identityHeaders;
            }
            if ($isExactRound) {
                if (($identityHeadersByRoute[ControlPlaneProxyRouteProof::PUBLIC_ROUTE] ?? null)
                    !== ($identityHeadersByRoute[ControlPlaneProxyRouteProof::APP_PORT_ROUTE] ?? null)) {
                    throw new InvalidArgumentException('Control-plane public and APP_PORT routes do not expose the same applied proof.');
                }
                $consecutiveExactRounds++;
                if ($consecutiveExactRounds === 2 && $firstConvergedAttempt === null) {
                    $firstConvergedAttempt = $attempt;
                }
            } else {
                $consecutiveExactRounds = 0;
            }
        }

        if ($terminal['type'] === 'timeout') {
            if ($firstConvergedAttempt !== null) {
                throw new InvalidArgumentException('Control-plane route proof timed out after it had already claimed convergence.');
            }

            throw new InvalidArgumentException('Control-plane route proof timed out before both routes had two consecutive exact successes.');
        }
        if ($firstConvergedAttempt !== $terminal['attempt']) {
            throw new InvalidArgumentException('Control-plane route proof claimed convergence without two consecutive exact route successes.');
        }

        return $proof;
    }

    /**
     * @return array{
     *     records: list<array{route: string, attempt: int, status: int, curlExit: int, headers: array<string, string>}>,
     *     terminal: array{type: 'converged'|'timeout', attempt: int}
     * }
     */
    private function parseTranscript(string $curlTranscript): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $curlTranscript);
        if (! is_array($lines)) {
            throw new InvalidArgumentException('Control-plane route proof output could not be parsed.');
        }
        $index = 0;
        $records = [];
        $terminal = null;
        while ($index < count($lines)) {
            if ($terminal !== null) {
                if ($lines[$index] !== '') {
                    throw new InvalidArgumentException('Control-plane route proof output has content after its terminal boundary.');
                }
                $index++;

                continue;
            }
            if ($lines[$index] === '') {
                throw new InvalidArgumentException('Control-plane route proof output has an invalid record boundary.');
            }
            if (preg_match('/^__COOLIFY_ROUTE_PROOF_(CONVERGED|TIMEOUT)__ ([0-9]+)$/D', $lines[$index], $terminalMatch) === 1) {
                $attempt = (int) $terminalMatch[2];
                if ($attempt < 1) {
                    throw new InvalidArgumentException('Control-plane route proof output has an invalid terminal boundary.');
                }
                $terminal = [
                    'type' => $terminalMatch[1] === 'CONVERGED' ? 'converged' : 'timeout',
                    'attempt' => $attempt,
                ];
                $index++;

                continue;
            }
            if (preg_match('/^__COOLIFY_ROUTE_PROOF_BEGIN__ (public|app-port) ([1-9][0-9]*)$/D', $lines[$index], $begin) !== 1) {
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
            if ($index >= count($lines)
                || preg_match('/^__COOLIFY_ROUTE_PROOF_CURL_EXIT__ ([0-9]{1,3})$/D', $lines[$index], $curlExit) !== 1
                || (int) $curlExit[1] > 255) {
                throw new InvalidArgumentException('Control-plane route proof output has no valid curl exit status.');
            }
            $index++;
            if (($lines[$index] ?? null) !== '__COOLIFY_ROUTE_PROOF_END__') {
                throw new InvalidArgumentException('Control-plane route proof output has no record terminator.');
            }
            $index++;

            $records[] = [
                'route' => $route,
                'attempt' => $attempt,
                'status' => (int) $status[1],
                'curlExit' => (int) $curlExit[1],
                'headers' => $this->parseHeaders($headerLines, (int) $status[1], (int) $curlExit[1]),
            ];
        }
        if ($terminal === null) {
            throw new InvalidArgumentException('Control-plane route proof output has no terminal boundary.');
        }

        return ['records' => $records, 'terminal' => $terminal];
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, string>
     */
    private function parseHeaders(array $lines, int $curlStatus, int $curlExit): array
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
            if ($curlStatus === 0 && $curlExit !== 0) {
                return [];
            }

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
