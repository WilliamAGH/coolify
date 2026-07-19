<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyRouteProof;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneProxyRoutes;

function controlPlaneRouteProof(): ControlPlaneProxyRouteProof
{
    return new ControlPlaneProxyRouteProof(
        canonicalHost: 'dashboard.example.test',
        publicScheme: 'https',
        directIpv4: '203.0.113.10',
        appPort: 8000,
        expectedColor: 'blue',
        expectedGeneration: 'generation-42',
        expectedBackendMember: 'web-a',
        expectedBackendRevision: 'revision-42',
        dynamicReplacementSha256: hash('sha256', 'coolify.yaml replacement'),
        configurationAcknowledgement: 'ack:'.str_repeat('c', 64),
        proofToken: 'proof:'.str_repeat('a', 64),
    );
}

function controlPlaneRouteProofRecord(
    ControlPlaneProxyRouteProof $proof,
    string $route,
    int $attempt,
    array $headers = [],
    int $status = 204,
): string {
    $responseHeaders = [
        ControlPlaneDynamicConfiguration::COLOR_HEADER => 'blue',
        ControlPlaneDynamicConfiguration::GENERATION_HEADER => 'generation-42',
        ControlPlaneDynamicConfiguration::CONFIGURATION_ACKNOWLEDGEMENT_HEADER => $proof->configurationAcknowledgement(),
        ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER => 'web-a',
        ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER => 'revision-42',
        ...$headers,
    ];
    $headerLines = "HTTP/2 {$status}\r\n";
    foreach ($responseHeaders as $name => $value) {
        $headerLines .= "{$name}: {$value}\r\n";
    }

    return implode("\n", [
        "__COOLIFY_ROUTE_PROOF_BEGIN__ {$route} {$attempt}",
        rtrim($headerLines),
        '',
        "__COOLIFY_ROUTE_PROOF_STATUS__ {$status}",
        '__COOLIFY_ROUTE_PROOF_END__',
    ]);
}

function successfulControlPlaneRouteProofTranscript(ControlPlaneProxyRouteProof $proof): string
{
    $records = [];
    foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
        foreach ([1, 2] as $attempt) {
            $records[] = controlPlaneRouteProofRecord($proof, $route, $attempt);
        }
    }

    return implode("\n", $records);
}

it('accepts two consecutive exact identity proofs through HTTPS and APP_PORT', function (): void {
    $proof = controlPlaneRouteProof();

    expect(VerifyControlPlaneProxyRoutes::run($proof, successfulControlPlaneRouteProofTranscript($proof)))
        ->toBe($proof);
});

it('fails closed when one route exposes a different backend identity', function (): void {
    $proof = controlPlaneRouteProof();
    $transcript = successfulControlPlaneRouteProofTranscript($proof);
    $transcript = preg_replace(
        '/'.preg_quote(ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER.': web-a', '/').'/',
        ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER.': web-b',
        $transcript,
        1,
    );

    expect(fn (): ControlPlaneProxyRouteProof => VerifyControlPlaneProxyRoutes::run($proof, $transcript))
        ->toThrow(InvalidArgumentException::class, ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER);
});

it('fails closed for a stale acknowledgement, a missing identity header, and a partial failed route', function (): void {
    $proof = controlPlaneRouteProof();

    $staleAcknowledgement = str_replace(
        $proof->configurationAcknowledgement(),
        str_repeat('b', 64),
        successfulControlPlaneRouteProofTranscript($proof),
    );
    expect(fn (): ControlPlaneProxyRouteProof => VerifyControlPlaneProxyRoutes::run($proof, $staleAcknowledgement))
        ->toThrow(InvalidArgumentException::class, ControlPlaneDynamicConfiguration::CONFIGURATION_ACKNOWLEDGEMENT_HEADER);

    $missingGeneration = str_replace(
        ControlPlaneDynamicConfiguration::GENERATION_HEADER.": generation-42\r\n",
        '',
        successfulControlPlaneRouteProofTranscript($proof),
    );
    expect(fn (): ControlPlaneProxyRouteProof => VerifyControlPlaneProxyRoutes::run($proof, $missingGeneration))
        ->toThrow(InvalidArgumentException::class, ControlPlaneDynamicConfiguration::GENERATION_HEADER);

    $failedAppPort = implode("\n", [
        controlPlaneRouteProofRecord($proof, ControlPlaneProxyRouteProof::PUBLIC_ROUTE, 1),
        controlPlaneRouteProofRecord($proof, ControlPlaneProxyRouteProof::PUBLIC_ROUTE, 2),
        controlPlaneRouteProofRecord($proof, ControlPlaneProxyRouteProof::APP_PORT_ROUTE, 1),
        controlPlaneRouteProofRecord($proof, ControlPlaneProxyRouteProof::APP_PORT_ROUTE, 2, status: 503),
    ]);
    expect(fn (): ControlPlaneProxyRouteProof => VerifyControlPlaneProxyRoutes::run($proof, $failedAppPort))
        ->toThrow(InvalidArgumentException::class, 'expected successful health response');
});

it('rejects malformed curl output before accepting a route proof', function (): void {
    $proof = controlPlaneRouteProof();

    expect(fn (): ControlPlaneProxyRouteProof => VerifyControlPlaneProxyRoutes::run(
        $proof,
        "__COOLIFY_ROUTE_PROOF_BEGIN__ public 1\nHTTP/2 204\n",
    ))->toThrow(InvalidArgumentException::class, 'no valid curl status');
});

it('rejects unsafe direct route host and proof inputs before command rendering', function (): void {
    expect(fn (): ControlPlaneProxyRouteProof => new ControlPlaneProxyRouteProof(
        canonicalHost: 'dashboard.example.test; curl attacker.test',
        publicScheme: 'https',
        directIpv4: '203.0.113.10',
        appPort: 8000,
        expectedColor: 'blue',
        expectedGeneration: 'generation-42',
        expectedBackendMember: 'web-a',
        expectedBackendRevision: 'revision-42',
        dynamicReplacementSha256: hash('sha256', 'coolify.yaml replacement'),
        configurationAcknowledgement: 'ack:'.str_repeat('c', 64),
        proofToken: 'proof:'.str_repeat('a', 64),
    ))->toThrow(InvalidArgumentException::class, 'canonical control-plane host');
});
