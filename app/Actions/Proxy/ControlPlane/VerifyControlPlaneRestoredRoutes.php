<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class VerifyControlPlaneRestoredRoutes
{
    use AsAction;

    public function handle(
        ControlPlaneRestoredRoutesProof $proof,
        string $curlTranscript,
    ): ControlPlaneRestoredRoutesProof {
        $transcript = $this->parseTranscript($curlTranscript);
        $records = $transcript['records'];
        $terminal = $transcript['terminal'];
        if ($terminal['type'] === 'timeout' && $terminal['attempt'] !== $proof->maximumAttempts) {
            throw new InvalidArgumentException('Restored control-plane route proof output has an invalid timeout boundary.');
        }
        if ($terminal['type'] === 'converged'
            && ($terminal['attempt'] < 2 || $terminal['attempt'] > $proof->maximumAttempts)) {
            throw new InvalidArgumentException('Restored control-plane route proof output has an invalid convergence boundary.');
        }

        $expectedRecordCount = $terminal['attempt'] * 2;
        if (count($records) !== $expectedRecordCount) {
            throw new InvalidArgumentException('Restored control-plane route proof output is partial, duplicated, or contains an unexpected route attempt.');
        }
        $recordIndex = 0;
        foreach (range(1, $terminal['attempt']) as $attempt) {
            foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
                $record = $records[$recordIndex++] ?? null;
                if ($record === null || $record['route'] !== $route || $record['attempt'] !== $attempt) {
                    throw new InvalidArgumentException('Restored control-plane route proof output is partial, duplicated, or contains an unexpected route attempt.');
                }
            }
        }

        $expectedHeaders = $proof->expectedResponseHeaders();
        $managedIdentityHeaders = array_fill_keys(array_map('strtolower', [
            ...array_keys($expectedHeaders),
            ControlPlaneDynamicConfiguration::COLOR_HEADER,
            ControlPlaneDynamicConfiguration::GENERATION_HEADER,
            ControlPlaneDynamicConfiguration::CONFIGURATION_ACKNOWLEDGEMENT_HEADER,
        ]), true);
        $consecutiveExactRounds = 0;
        $firstConvergedAttempt = null;
        $recordIndex = 0;
        foreach (range(1, $terminal['attempt']) as $attempt) {
            $isExactRound = true;
            $identityHeadersByRoute = [];
            foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
                $record = $records[$recordIndex++];
                if ($route === ControlPlaneProxyRouteProof::PUBLIC_ROUTE && ! $proof->publicRouteExpected) {
                    $unexpectedIdentityHeader = array_key_first(array_intersect_key(
                        $record['headers'],
                        $managedIdentityHeaders,
                    ));
                    if ($unexpectedIdentityHeader !== null) {
                        throw new InvalidArgumentException('Restored control-plane public route unexpectedly exposes a managed backend identity.');
                    }
                    if ($record['curlExit'] !== 22 || ! in_array($record['status'], [404, 503], true)) {
                        $isExactRound = false;
                    }

                    continue;
                }
                if ($record['status'] !== 200) {
                    $isExactRound = false;

                    continue;
                }
                $identityHeaders = [];
                foreach ($expectedHeaders as $header => $expectedValue) {
                    $actualValue = $record['headers'][strtolower($header)] ?? null;
                    if (! is_string($actualValue) || ! hash_equals($expectedValue, $actualValue)) {
                        throw new InvalidArgumentException("Restored control-plane route proof has a missing or stale {$header} response header.");
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
                if ($proof->publicRouteExpected
                    && ($identityHeadersByRoute[ControlPlaneProxyRouteProof::PUBLIC_ROUTE] ?? null)
                    !== ($identityHeadersByRoute[ControlPlaneProxyRouteProof::APP_PORT_ROUTE] ?? null)) {
                    throw new InvalidArgumentException('Restored control-plane public and APP_PORT routes do not expose the same restored marker.');
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
                throw new InvalidArgumentException('Restored control-plane route proof timed out after it had already claimed convergence.');
            }

            throw new InvalidArgumentException('Restored control-plane route proof timed out before both routes had two consecutive exact successes.');
        }
        if ($firstConvergedAttempt !== $terminal['attempt']) {
            throw new InvalidArgumentException('Restored control-plane route proof claimed convergence without two consecutive exact route successes.');
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
            throw new InvalidArgumentException('Restored control-plane route proof output could not be parsed.');
        }
        $index = 0;
        $records = [];
        $terminal = null;
        while ($index < count($lines)) {
            if ($terminal !== null) {
                if ($index === count($lines) - 1 && $lines[$index] === '') {
                    $index++;

                    continue;
                }

                throw new InvalidArgumentException('Restored control-plane route proof output has content after its terminal boundary.');
            }
            if ($lines[$index] === '') {
                throw new InvalidArgumentException('Restored control-plane route proof output has an invalid record boundary.');
            }
            if (preg_match('/^'.preg_quote(ControlPlaneRestoredRoutesProof::TRANSCRIPT_CONVERGED, '/').' ([0-9]+)$/D', $lines[$index], $converged) === 1) {
                $attempt = (int) $converged[1];
                $terminal = ['type' => 'converged', 'attempt' => $attempt];
            } elseif (preg_match('/^'.preg_quote(ControlPlaneRestoredRoutesProof::TRANSCRIPT_TIMEOUT, '/').' ([0-9]+)$/D', $lines[$index], $timeout) === 1) {
                $attempt = (int) $timeout[1];
                $terminal = ['type' => 'timeout', 'attempt' => $attempt];
            }
            if ($terminal !== null) {
                if ($terminal['attempt'] < 1) {
                    throw new InvalidArgumentException('Restored control-plane route proof output has an invalid terminal boundary.');
                }
                $index++;

                continue;
            }
            if (preg_match('/^'.preg_quote(ControlPlaneRestoredRoutesProof::TRANSCRIPT_BEGIN, '/').' (public|app-port) ([1-9][0-9]*)$/D', $lines[$index], $begin) !== 1) {
                throw new InvalidArgumentException('Restored control-plane route proof output has an invalid record boundary.');
            }
            $route = $begin[1];
            $attempt = (int) $begin[2];
            $index++;
            $headerLines = [];
            while ($index < count($lines) && ! str_starts_with($lines[$index], ControlPlaneRestoredRoutesProof::TRANSCRIPT_STATUS.' ')) {
                if (str_starts_with($lines[$index], '__COOLIFY_RESTORED_ROUTE_PROOF_')) {
                    throw new InvalidArgumentException('Restored control-plane route proof output has a malformed curl record.');
                }
                $headerLines[] = $lines[$index];
                $index++;
            }
            if ($index >= count($lines)
                || preg_match('/^'.preg_quote(ControlPlaneRestoredRoutesProof::TRANSCRIPT_STATUS, '/').' ([0-9]{3})$/D', $lines[$index], $status) !== 1) {
                throw new InvalidArgumentException('Restored control-plane route proof output has no valid curl status.');
            }
            $index++;
            if ($index >= count($lines)
                || preg_match('/^'.preg_quote(ControlPlaneRestoredRoutesProof::TRANSCRIPT_CURL_EXIT, '/').' ([0-9]{1,3})$/D', $lines[$index], $curlExit) !== 1
                || (int) $curlExit[1] > 255) {
                throw new InvalidArgumentException('Restored control-plane route proof output has no valid curl exit status.');
            }
            $index++;
            if (($lines[$index] ?? null) !== ControlPlaneRestoredRoutesProof::TRANSCRIPT_END) {
                throw new InvalidArgumentException('Restored control-plane route proof output has no record terminator.');
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
            throw new InvalidArgumentException('Restored control-plane route proof output has no terminal boundary.');
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
                    throw new InvalidArgumentException('Restored control-plane route proof response headers are not separated correctly.');
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
                throw new InvalidArgumentException('Restored control-plane route proof response headers are malformed.');
            }
            $name = strtolower($header[1]);
            if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $header[1]) !== 1
                || isset($current['headers'][$name])) {
                throw new InvalidArgumentException('Restored control-plane route proof response headers are ambiguous.');
            }
            $value = trim($header[2]);
            if (preg_match('/^[\x20-\x7E]*$/D', $value) !== 1) {
                throw new InvalidArgumentException('Restored control-plane route proof response header values are malformed.');
            }
            $current['headers'][$name] = $value;
        }
        if ($current !== null) {
            throw new InvalidArgumentException('Restored control-plane route proof response headers have no terminating blank line.');
        }
        if ($headerBlocks === []) {
            if ($curlStatus === 0 && $curlExit !== 0) {
                return [];
            }

            throw new InvalidArgumentException('Restored control-plane route proof has no HTTP response headers.');
        }
        $final = $headerBlocks[array_key_last($headerBlocks)];
        if ($final['status'] !== $curlStatus) {
            throw new InvalidArgumentException('Restored control-plane route proof curl status and response status do not agree.');
        }
        ksort($final['headers'], SORT_STRING);

        return $final['headers'];
    }
}
