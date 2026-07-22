<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyRouteProof;
use App\Actions\Proxy\ControlPlane\ControlPlaneRestoredRoutesProof;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneProxyRoutes;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneRestoredRoutes;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function controlPlaneRouteProof(int $maximumAttempts = 5): ControlPlaneProxyRouteProof
{
    return new ControlPlaneProxyRouteProof(
        canonicalHost: 'dashboard.example.test',
        publicScheme: 'https',
        appPort: 8000,
        expectedColor: 'blue',
        expectedGeneration: 'generation-42',
        expectedBackendMember: 'web-a',
        expectedBackendRevision: 'revision-42',
        dynamicReplacementSha256: hash('sha256', 'coolify.yaml replacement'),
        configurationAcknowledgement: 'ack:'.str_repeat('c', 64),
        maximumAttempts: $maximumAttempts,
        pollIntervalSeconds: 0,
        connectTimeoutSeconds: 1,
        requestTimeoutSeconds: 1,
    );
}

/** @param array<string, string> $headers */
function controlPlaneRouteProofRecord(
    ControlPlaneProxyRouteProof $proof,
    string $route,
    int $attempt,
    array $headers = [],
    int $status = 200,
    int $curlExit = 0,
    bool $includeHeaders = true,
): string {
    $statusCode = str_pad((string) $status, 3, '0', STR_PAD_LEFT);
    $lines = ["__COOLIFY_ROUTE_PROOF_BEGIN__ {$route} {$attempt}"];
    if ($includeHeaders) {
        $responseHeaders = [
            ControlPlaneDynamicConfiguration::COLOR_HEADER => 'blue',
            ControlPlaneDynamicConfiguration::GENERATION_HEADER => 'generation-42',
            ControlPlaneDynamicConfiguration::CONFIGURATION_ACKNOWLEDGEMENT_HEADER => $proof->configurationAcknowledgement(),
            ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER => 'web-a',
            ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER => 'revision-42',
            ControlPlaneProxyRouteProof::DYNAMIC_SHA256_HEADER => $proof->dynamicReplacementSha256,
            ...$headers,
        ];
        $lines[] = "HTTP/2 {$statusCode}";
        foreach ($responseHeaders as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $lines[] = '';
    }
    $lines[] = "__COOLIFY_ROUTE_PROOF_STATUS__ {$statusCode}";
    $lines[] = "__COOLIFY_ROUTE_PROOF_CURL_EXIT__ {$curlExit}";
    $lines[] = '__COOLIFY_ROUTE_PROOF_END__';

    return implode("\n", $lines);
}

/** @param list<string> $records */
function controlPlaneRouteProofTranscript(array $records, string $terminal): string
{
    return implode("\n", [...$records, $terminal]);
}

function successfulControlPlaneRouteProofTranscript(ControlPlaneProxyRouteProof $proof): string
{
    $records = [];
    foreach ([1, 2] as $attempt) {
        foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
            $records[] = controlPlaneRouteProofRecord($proof, $route, $attempt);
        }
    }

    return controlPlaneRouteProofTranscript($records, '__COOLIFY_ROUTE_PROOF_CONVERGED__ 2');
}

/** @param array<string, string> $headers */
function executeRenderedControlPlaneRouteProof(string $command, array $headers): string
{
    $filesystem = new Filesystem;
    $directory = sys_get_temp_dir().'/coolify-route-proof-'.bin2hex(random_bytes(8));
    $filesystem->makeDirectory($directory, 0700);
    $curl = $directory.'/curl';
    file_put_contents($curl, <<<'SH'
#!/bin/sh
set -eu
write_out=
while [ "$#" -gt 0 ]; do
    if [ "$1" = --write-out ]; then
        shift
        write_out=$1
    fi
    shift
done
printf 'HTTP/1.1 200 OK\r\n'
printf 'X-Coolify-Control-Plane-Color: %s\r\n' "$FAKE_COLOR"
printf 'X-Coolify-Control-Plane-Generation: %s\r\n' "$FAKE_GENERATION"
printf 'X-Coolify-Control-Plane-Config-Ack: %s\r\n' "$FAKE_ACK"
printf 'X-Coolify-Control-Plane-Backend-Member: %s\r\n' "$FAKE_MEMBER"
printf 'X-Coolify-Control-Plane-Backend-Revision: %s\r\n' "$FAKE_REVISION"
printf 'X-Coolify-Control-Plane-Dynamic-Sha256: %s\r\n\r\n' "$FAKE_DYNAMIC_SHA"
formatted=$(printf %s "$write_out" | sed 's/%{http_code}/200/g')
printf '%b' "$formatted"
SH);
    chmod($curl, 0700);

    try {
        $process = Process::fromShellCommandline($command, null, [
            'PATH' => $directory.':'.getenv('PATH'),
            'FAKE_COLOR' => $headers[ControlPlaneDynamicConfiguration::COLOR_HEADER] ?? 'unused',
            'FAKE_GENERATION' => $headers[ControlPlaneDynamicConfiguration::GENERATION_HEADER] ?? 'unused',
            'FAKE_ACK' => $headers[ControlPlaneDynamicConfiguration::CONFIGURATION_ACKNOWLEDGEMENT_HEADER] ?? 'unused',
            'FAKE_MEMBER' => $headers[ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER],
            'FAKE_REVISION' => $headers[ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER],
            'FAKE_DYNAMIC_SHA' => $headers[ControlPlaneProxyRouteProof::DYNAMIC_SHA256_HEADER],
        ]);
        $process->mustRun();

        return $process->getOutput();
    } finally {
        $filesystem->deleteDirectory($directory);
    }
}

it('polls both routes without bearer credentials and accepts two exact consecutive rounds', function (): void {
    $proof = controlPlaneRouteProof(maximumAttempts: 4);
    $command = $proof->shellCommand();

    expect(VerifyControlPlaneProxyRoutes::run($proof, successfulControlPlaneRouteProofTranscript($proof)))
        ->toBe($proof)
        ->and($command)->toContain('while [ "$round" -le 4 ]; do')
        ->and($command)->toContain('sleep 0')
        ->and($command)->toContain('__COOLIFY_ROUTE_PROOF_CURL_EXIT__')
        ->and($command)->toContain('http://127.0.0.1:8000/api/health')
        ->and($command)->not->toContain('Authorization')
        ->and($command)->not->toContain('Bearer')
        ->and($command)->not->toContain($proof->configurationAcknowledgement());
});

it('executes indented active and restored route proofs with unindented curl status records', function (): void {
    $active = controlPlaneRouteProof(maximumAttempts: 2);
    $activeTranscript = executeRenderedControlPlaneRouteProof($active->shellCommand(), $active->expectedResponseHeaders());

    $restored = new ControlPlaneRestoredRoutesProof(
        canonicalHost: 'dashboard.example.test',
        publicScheme: 'https',
        appPort: 8000,
        expectedBackendMember: 'web-a',
        expectedBackendRevision: 'revision-42',
        expectedDynamicPredecessorSha256: $active->dynamicReplacementSha256,
        maximumAttempts: 2,
        pollIntervalSeconds: 0,
        connectTimeoutSeconds: 1,
        requestTimeoutSeconds: 1,
    );
    $restoredTranscript = executeRenderedControlPlaneRouteProof($restored->shellCommand(), $restored->expectedResponseHeaders());

    expect(VerifyControlPlaneProxyRoutes::run($active, $activeTranscript))->toBe($active)
        ->and(VerifyControlPlaneRestoredRoutes::run($restored, $restoredTranscript))->toBe($restored);
});

it('tolerates a delayed file-provider update before dual-route convergence', function (): void {
    $proof = controlPlaneRouteProof(maximumAttempts: 4);
    $records = [];
    foreach ([1, 2] as $attempt) {
        foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
            $records[] = controlPlaneRouteProofRecord($proof, $route, $attempt, status: 503, curlExit: 22);
        }
    }
    foreach ([3, 4] as $attempt) {
        foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
            $records[] = controlPlaneRouteProofRecord($proof, $route, $attempt);
        }
    }

    expect(VerifyControlPlaneProxyRoutes::run(
        $proof,
        controlPlaneRouteProofTranscript($records, '__COOLIFY_ROUTE_PROOF_CONVERGED__ 4'),
    ))->toBe($proof);
});

it('fails closed when route success oscillates instead of converging', function (): void {
    $proof = controlPlaneRouteProof(maximumAttempts: 4);
    $records = [];
    foreach ([1, 2, 3, 4] as $attempt) {
        foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
            $records[] = controlPlaneRouteProofRecord(
                $proof,
                $route,
                $attempt,
                status: $attempt % 2 === 0 ? 503 : 200,
                curlExit: $attempt % 2 === 0 ? 22 : 0,
            );
        }
    }

    expect(fn (): ControlPlaneProxyRouteProof => VerifyControlPlaneProxyRoutes::run(
        $proof,
        controlPlaneRouteProofTranscript($records, '__COOLIFY_ROUTE_PROOF_TIMEOUT__ 4'),
    ))->toThrow(InvalidArgumentException::class, 'timed out before both routes had two consecutive exact successes');
});

it('fails closed when polling reaches its timeout without an HTTP response', function (): void {
    $proof = controlPlaneRouteProof(maximumAttempts: 3);
    $records = [];
    foreach ([1, 2, 3] as $attempt) {
        foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
            $records[] = controlPlaneRouteProofRecord($proof, $route, $attempt, status: 0, curlExit: 7, includeHeaders: false);
        }
    }

    expect(fn (): ControlPlaneProxyRouteProof => VerifyControlPlaneProxyRoutes::run(
        $proof,
        controlPlaneRouteProofTranscript($records, '__COOLIFY_ROUTE_PROOF_TIMEOUT__ 3'),
    ))->toThrow(InvalidArgumentException::class, 'timed out before both routes had two consecutive exact successes');
});

it('fails closed when a route exposes stale or mixed identity headers', function (): void {
    $proof = controlPlaneRouteProof();
    $staleAcknowledgement = str_replace(
        $proof->configurationAcknowledgement(),
        str_repeat('b', 64),
        successfulControlPlaneRouteProofTranscript($proof),
    );
    expect(fn (): ControlPlaneProxyRouteProof => VerifyControlPlaneProxyRoutes::run($proof, $staleAcknowledgement))
        ->toThrow(InvalidArgumentException::class, ControlPlaneDynamicConfiguration::CONFIGURATION_ACKNOWLEDGEMENT_HEADER);

    $mixedBackend = preg_replace(
        '/'.preg_quote(ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER.': web-a', '/').'/',
        ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER.': web-b',
        successfulControlPlaneRouteProofTranscript($proof),
        1,
    );
    expect(fn (): ControlPlaneProxyRouteProof => VerifyControlPlaneProxyRoutes::run($proof, (string) $mixedBackend))
        ->toThrow(InvalidArgumentException::class, ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER);
});

it('rejects malformed, omitted, and forged polling transcripts', function (): void {
    $proof = controlPlaneRouteProof(maximumAttempts: 3);
    $malformed = str_replace(
        "__COOLIFY_ROUTE_PROOF_CURL_EXIT__ 0\n",
        '',
        successfulControlPlaneRouteProofTranscript($proof),
    );
    expect(fn (): ControlPlaneProxyRouteProof => VerifyControlPlaneProxyRoutes::run($proof, $malformed))
        ->toThrow(InvalidArgumentException::class, 'no valid curl exit status');

    $omittedFailure = controlPlaneRouteProofTranscript([
        controlPlaneRouteProofRecord($proof, ControlPlaneProxyRouteProof::PUBLIC_ROUTE, 1, status: 503, curlExit: 22),
        controlPlaneRouteProofRecord($proof, ControlPlaneProxyRouteProof::PUBLIC_ROUTE, 2),
        controlPlaneRouteProofRecord($proof, ControlPlaneProxyRouteProof::APP_PORT_ROUTE, 2),
    ], '__COOLIFY_ROUTE_PROOF_CONVERGED__ 2');
    expect(fn (): ControlPlaneProxyRouteProof => VerifyControlPlaneProxyRoutes::run($proof, $omittedFailure))
        ->toThrow(InvalidArgumentException::class, 'partial, duplicated, or contains an unexpected route attempt');

    $forgedConvergence = controlPlaneRouteProofTranscript([
        controlPlaneRouteProofRecord($proof, ControlPlaneProxyRouteProof::PUBLIC_ROUTE, 1, status: 503, curlExit: 22),
        controlPlaneRouteProofRecord($proof, ControlPlaneProxyRouteProof::APP_PORT_ROUTE, 1, status: 503, curlExit: 22),
        controlPlaneRouteProofRecord($proof, ControlPlaneProxyRouteProof::PUBLIC_ROUTE, 2),
        controlPlaneRouteProofRecord($proof, ControlPlaneProxyRouteProof::APP_PORT_ROUTE, 2),
    ], '__COOLIFY_ROUTE_PROOF_CONVERGED__ 2');
    expect(fn (): ControlPlaneProxyRouteProof => VerifyControlPlaneProxyRoutes::run($proof, $forgedConvergence))
        ->toThrow(InvalidArgumentException::class, 'claimed convergence without two consecutive exact route successes');
});

it('rejects unsafe public route inputs before command rendering', function (): void {
    expect(fn (): ControlPlaneProxyRouteProof => new ControlPlaneProxyRouteProof(
        canonicalHost: 'dashboard.example.test; curl attacker.test',
        publicScheme: 'https',
        appPort: 8000,
        expectedColor: 'blue',
        expectedGeneration: 'generation-42',
        expectedBackendMember: 'web-a',
        expectedBackendRevision: 'revision-42',
        dynamicReplacementSha256: hash('sha256', 'coolify.yaml replacement'),
        configurationAcknowledgement: 'ack:'.str_repeat('c', 64),
    ))->toThrow(InvalidArgumentException::class, 'canonical control-plane host');
});
