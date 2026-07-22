<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneProxyRouteProof;
use App\Actions\Proxy\ControlPlane\ControlPlaneRestoredRoutesProof;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneRestoredRoutes;

function controlPlaneRestoredRoutesProof(int $maximumAttempts = 5): ControlPlaneRestoredRoutesProof
{
    return new ControlPlaneRestoredRoutesProof(
        canonicalHost: 'dashboard.example.test',
        publicScheme: 'https',
        appPort: 8000,
        expectedBackendMember: 'coolify-web-a',
        expectedBackendRevision: 'revision-42',
        expectedDynamicPredecessorSha256: hash('sha256', 'coolify.yaml predecessor'),
        maximumAttempts: $maximumAttempts,
        pollIntervalSeconds: 0,
        connectTimeoutSeconds: 1,
        requestTimeoutSeconds: 1,
    );
}

/** @param array<string, string> $headers */
function controlPlaneRestoredRoutesProofRecord(
    ControlPlaneRestoredRoutesProof $proof,
    string $route,
    int $attempt,
    array $headers = [],
    int $status = 200,
    int $curlExit = 0,
    bool $includeHeaders = true,
): string {
    $statusCode = str_pad((string) $status, 3, '0', STR_PAD_LEFT);
    $lines = [ControlPlaneRestoredRoutesProof::TRANSCRIPT_BEGIN." {$route} {$attempt}"];
    if ($includeHeaders) {
        $responseHeaders = [...$proof->expectedResponseHeaders(), ...$headers];
        $lines[] = "HTTP/2 {$statusCode}";
        foreach ($responseHeaders as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $lines[] = '';
    }
    $lines[] = ControlPlaneRestoredRoutesProof::TRANSCRIPT_STATUS." {$statusCode}";
    $lines[] = ControlPlaneRestoredRoutesProof::TRANSCRIPT_CURL_EXIT." {$curlExit}";
    $lines[] = ControlPlaneRestoredRoutesProof::TRANSCRIPT_END;

    return implode("\n", $lines);
}

/** @param list<string> $records */
function controlPlaneRestoredRoutesProofTranscript(array $records, string $terminal): string
{
    return implode("\n", [...$records, $terminal]);
}

function successfulControlPlaneRestoredRoutesProofTranscript(ControlPlaneRestoredRoutesProof $proof): string
{
    $records = [];
    foreach ([1, 2] as $attempt) {
        foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
            $records[] = controlPlaneRestoredRoutesProofRecord($proof, $route, $attempt);
        }
    }

    return controlPlaneRestoredRoutesProofTranscript($records, ControlPlaneRestoredRoutesProof::TRANSCRIPT_CONVERGED.' 2');
}

it('renders bounded tokenless probes and accepts two exact restored rounds', function (): void {
    $proof = controlPlaneRestoredRoutesProof(maximumAttempts: 4);
    $command = $proof->shellCommand();

    expect(VerifyControlPlaneRestoredRoutes::run($proof, successfulControlPlaneRestoredRoutesProofTranscript($proof)."\n"))
        ->toBe($proof)
        ->and($command)->toContain('while [ "$round" -le 4 ]; do')
        ->and($command)->toContain('sleep 0')
        ->and($command)->toContain('https://dashboard.example.test/api/health')
        ->and($command)->toContain('http://127.0.0.1:8000/api/health')
        ->and($command)->toContain('Host: dashboard.example.test')
        ->and($command)->toContain(ControlPlaneRestoredRoutesProof::TRANSCRIPT_CURL_EXIT)
        ->and($command)->not->toContain('Authorization')
        ->and($command)->not->toContain('Bearer')
        ->and($command)->not->toContain('Health-Proof')
        ->and($command)->not->toContain($proof->expectedDynamicPredecessorSha256);
});

it('proves only the exact APP_PORT route when the restored dynamic predecessor is absent', function (): void {
    $proof = new ControlPlaneRestoredRoutesProof(
        canonicalHost: 'dashboard.example.test',
        publicScheme: 'https',
        appPort: 8000,
        expectedBackendMember: 'coolify-web-a',
        expectedBackendRevision: 'rollback-42',
        expectedDynamicPredecessorSha256: hash('sha256', ''),
        expectedDynamicPredecessorAbsent: true,
        pollIntervalSeconds: 0,
        connectTimeoutSeconds: 1,
        requestTimeoutSeconds: 1,
    );
    $existingEmptyPredecessor = new ControlPlaneRestoredRoutesProof(
        canonicalHost: 'dashboard.example.test',
        publicScheme: 'https',
        appPort: 8000,
        expectedBackendMember: 'coolify-web-a',
        expectedBackendRevision: 'rollback-42',
        expectedDynamicPredecessorSha256: hash('sha256', ''),
        pollIntervalSeconds: 0,
        connectTimeoutSeconds: 1,
        requestTimeoutSeconds: 1,
    );
    $records = [];
    foreach ([1, 2] as $attempt) {
        $records[] = controlPlaneRestoredRoutesProofRecord(
            $proof,
            ControlPlaneProxyRouteProof::APP_PORT_ROUTE,
            $attempt,
        );
    }
    $transcript = controlPlaneRestoredRoutesProofTranscript(
        $records,
        ControlPlaneRestoredRoutesProof::TRANSCRIPT_CONVERGED.' 2',
    );
    $unexpectedPublicRecord = controlPlaneRestoredRoutesProofTranscript([
        controlPlaneRestoredRoutesProofRecord($proof, ControlPlaneProxyRouteProof::PUBLIC_ROUTE, 1),
        ...$records,
    ], ControlPlaneRestoredRoutesProof::TRANSCRIPT_CONVERGED.' 2');
    $command = $proof->shellCommand();

    expect(VerifyControlPlaneRestoredRoutes::run($proof, $transcript))->toBe($proof)
        ->and($proof->routes())->toBe([ControlPlaneProxyRouteProof::APP_PORT_ROUTE])
        ->and($command)->toContain('http://127.0.0.1:8000/api/health')
        ->and($command)->toContain('Host: dashboard.example.test')
        ->and($command)->not->toContain('https://dashboard.example.test/api/health')
        ->and($existingEmptyPredecessor->routes())->toBe([
            ControlPlaneProxyRouteProof::PUBLIC_ROUTE,
            ControlPlaneProxyRouteProof::APP_PORT_ROUTE,
        ])
        ->and($existingEmptyPredecessor->shellCommand())->toContain('https://dashboard.example.test/api/health');
    expect(fn (): ControlPlaneRestoredRoutesProof => VerifyControlPlaneRestoredRoutes::run($proof, $unexpectedPublicRecord))
        ->toThrow(InvalidArgumentException::class, 'partial, duplicated, or contains an unexpected route attempt');
});

it('allows a delayed restored provider update before dual-route convergence', function (): void {
    $proof = controlPlaneRestoredRoutesProof(maximumAttempts: 3);
    $records = [];
    foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
        $records[] = controlPlaneRestoredRoutesProofRecord($proof, $route, 1, status: 503, curlExit: 22);
    }
    foreach ([2, 3] as $attempt) {
        foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
            $records[] = controlPlaneRestoredRoutesProofRecord($proof, $route, $attempt);
        }
    }

    expect(VerifyControlPlaneRestoredRoutes::run(
        $proof,
        controlPlaneRestoredRoutesProofTranscript($records, ControlPlaneRestoredRoutesProof::TRANSCRIPT_CONVERGED.' 3'),
    ))->toBe($proof);
});

it('rejects stale and mixed restored markers', function (): void {
    $proof = controlPlaneRestoredRoutesProof();
    $stale = str_replace(
        $proof->expectedDynamicPredecessorSha256,
        hash('sha256', 'different predecessor'),
        successfulControlPlaneRestoredRoutesProofTranscript($proof),
    );
    expect(fn (): ControlPlaneRestoredRoutesProof => VerifyControlPlaneRestoredRoutes::run($proof, $stale))
        ->toThrow(InvalidArgumentException::class, ControlPlaneProxyRouteProof::DYNAMIC_SHA256_HEADER);

    $mixed = preg_replace(
        '/'.preg_quote(ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER.': coolify-web-a', '/').'/',
        ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER.': coolify-web-b',
        successfulControlPlaneRestoredRoutesProofTranscript($proof),
        1,
    );
    expect(fn (): ControlPlaneRestoredRoutesProof => VerifyControlPlaneRestoredRoutes::run($proof, (string) $mixed))
        ->toThrow(InvalidArgumentException::class, ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER);
});

it('rejects partial, duplicate, malformed, extra, timeout, and forged convergence transcripts', function (): void {
    $proof = controlPlaneRestoredRoutesProof(maximumAttempts: 3);
    $malformed = str_replace(
        ControlPlaneRestoredRoutesProof::TRANSCRIPT_CURL_EXIT." 0\n",
        '',
        successfulControlPlaneRestoredRoutesProofTranscript($proof),
    );
    expect(fn (): ControlPlaneRestoredRoutesProof => VerifyControlPlaneRestoredRoutes::run($proof, $malformed))
        ->toThrow(InvalidArgumentException::class, 'no valid curl exit status');

    $partial = controlPlaneRestoredRoutesProofTranscript([
        controlPlaneRestoredRoutesProofRecord($proof, ControlPlaneProxyRouteProof::PUBLIC_ROUTE, 1),
        controlPlaneRestoredRoutesProofRecord($proof, ControlPlaneProxyRouteProof::PUBLIC_ROUTE, 2),
        controlPlaneRestoredRoutesProofRecord($proof, ControlPlaneProxyRouteProof::APP_PORT_ROUTE, 2),
    ], ControlPlaneRestoredRoutesProof::TRANSCRIPT_CONVERGED.' 2');
    expect(fn (): ControlPlaneRestoredRoutesProof => VerifyControlPlaneRestoredRoutes::run($proof, $partial))
        ->toThrow(InvalidArgumentException::class, 'partial, duplicated, or contains an unexpected route attempt');

    $duplicate = controlPlaneRestoredRoutesProofTranscript([
        controlPlaneRestoredRoutesProofRecord($proof, ControlPlaneProxyRouteProof::PUBLIC_ROUTE, 1),
        controlPlaneRestoredRoutesProofRecord($proof, ControlPlaneProxyRouteProof::PUBLIC_ROUTE, 1),
        controlPlaneRestoredRoutesProofRecord($proof, ControlPlaneProxyRouteProof::APP_PORT_ROUTE, 1),
        controlPlaneRestoredRoutesProofRecord($proof, ControlPlaneProxyRouteProof::APP_PORT_ROUTE, 2),
    ], ControlPlaneRestoredRoutesProof::TRANSCRIPT_CONVERGED.' 2');
    expect(fn (): ControlPlaneRestoredRoutesProof => VerifyControlPlaneRestoredRoutes::run($proof, $duplicate))
        ->toThrow(InvalidArgumentException::class, 'partial, duplicated, or contains an unexpected route attempt');

    $extra = successfulControlPlaneRestoredRoutesProofTranscript($proof)."\nunexpected";
    expect(fn (): ControlPlaneRestoredRoutesProof => VerifyControlPlaneRestoredRoutes::run($proof, $extra))
        ->toThrow(InvalidArgumentException::class, 'content after its terminal boundary');

    $timedOutRecords = [];
    foreach ([1, 2, 3] as $attempt) {
        foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
            $timedOutRecords[] = controlPlaneRestoredRoutesProofRecord($proof, $route, $attempt, status: 503, curlExit: 22);
        }
    }
    expect(fn (): ControlPlaneRestoredRoutesProof => VerifyControlPlaneRestoredRoutes::run(
        $proof,
        controlPlaneRestoredRoutesProofTranscript($timedOutRecords, ControlPlaneRestoredRoutesProof::TRANSCRIPT_TIMEOUT.' 3'),
    ))->toThrow(InvalidArgumentException::class, 'timed out before the required route set had two consecutive exact successes');

    $forged = controlPlaneRestoredRoutesProofTranscript([
        controlPlaneRestoredRoutesProofRecord($proof, ControlPlaneProxyRouteProof::PUBLIC_ROUTE, 1, status: 503, curlExit: 22),
        controlPlaneRestoredRoutesProofRecord($proof, ControlPlaneProxyRouteProof::APP_PORT_ROUTE, 1, status: 503, curlExit: 22),
        controlPlaneRestoredRoutesProofRecord($proof, ControlPlaneProxyRouteProof::PUBLIC_ROUTE, 2),
        controlPlaneRestoredRoutesProofRecord($proof, ControlPlaneProxyRouteProof::APP_PORT_ROUTE, 2),
    ], ControlPlaneRestoredRoutesProof::TRANSCRIPT_CONVERGED.' 2');
    expect(fn (): ControlPlaneRestoredRoutesProof => VerifyControlPlaneRestoredRoutes::run($proof, $forged))
        ->toThrow(InvalidArgumentException::class, 'claimed convergence without two consecutive exact required-route successes');
});

it('rejects invalid restored route proof settings', function (): void {
    expect(fn (): ControlPlaneRestoredRoutesProof => new ControlPlaneRestoredRoutesProof(
        canonicalHost: 'dashboard.example.test',
        publicScheme: 'https',
        appPort: 8000,
        expectedBackendMember: 'coolify-web-a',
        expectedBackendRevision: 'revision-42',
        expectedDynamicPredecessorSha256: hash('sha256', 'coolify.yaml predecessor'),
        maximumAttempts: 1,
    ))->toThrow(InvalidArgumentException::class, 'between two and ten polling attempts');

    expect(fn (): ControlPlaneRestoredRoutesProof => new ControlPlaneRestoredRoutesProof(
        canonicalHost: 'dashboard.example.test',
        publicScheme: 'https',
        appPort: 8000,
        expectedBackendMember: 'coolify-web-a',
        expectedBackendRevision: 'revision-42',
        expectedDynamicPredecessorSha256: hash('sha256', 'present predecessor'),
        expectedDynamicPredecessorAbsent: true,
    ))->toThrow(InvalidArgumentException::class, 'must use the empty SHA-256 value');
});
