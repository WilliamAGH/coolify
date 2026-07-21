<?php

use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\ControlPlane\ControlPlaneCandidateHealthMarker;
use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyRouteProof;
use App\Actions\Proxy\ControlPlane\RespondToControlPlaneHealthCheck;
use Illuminate\Http\Request;

beforeEach(function (): void {
    config([
        'constants.control_plane_health.configuration_acknowledgement' => 'ack:'.str_repeat('a', 64),
        'constants.control_plane_health.deployment_release_proof' => null,
        'constants.control_plane_health.health_proof_token_sha256' => hash('sha256', 'health-proof-token'),
        'constants.control_plane_health.dynamic_sha256' => str_repeat('d', 64),
        'constants.control_plane_health.member' => 'blue',
        'constants.control_plane_health.revision' => 'revision-42',
    ]);
});

it('preserves the public health body and exposes only non-secret backend identity', function (): void {
    $this->get('/api/health')
        ->assertOk()
        ->assertSeeText('OK')
        ->assertHeader(ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER, 'blue')
        ->assertHeader(ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER, 'revision-42')
        ->assertHeader(ControlPlaneProxyRouteProof::DYNAMIC_SHA256_HEADER, str_repeat('d', 64));
});

it('publishes an exact deployment-bound release proof on ordinary health when configured', function (): void {
    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken('deployment-health-proof');
    config(['constants.control_plane_health.deployment_release_proof' => $releaseProof]);

    $this->get('/api/health')
        ->assertOk()
        ->assertSeeText('OK')
        ->assertHeader(BlueGreenRoutingTarget::RELEASE_PROOF_HEADER, $releaseProof);
});

it('omits missing or malformed deployment release proofs from ordinary health', function (?string $releaseProof): void {
    config(['constants.control_plane_health.deployment_release_proof' => $releaseProof]);

    $this->get('/api/health')
        ->assertOk()
        ->assertHeaderMissing(BlueGreenRoutingTarget::RELEASE_PROOF_HEADER);
})->with([
    'missing' => [null],
    'wrong prefix' => ['proof:'.str_repeat('a', 64)],
    'uppercase digest' => ['release:'.str_repeat('A', 64)],
    'newline injection' => ['release:'.str_repeat('a', 64)."\nX-Injected: value"],
]);

it('preserves health and proof behavior through the versioned API alias', function (): void {
    $this->get('/api/v1/health')
        ->assertOk()
        ->assertSeeText('OK');

    $this->get('/api/v1/health')
        ->assertOk()
        ->assertHeader(ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER, 'blue')
        ->assertHeader(ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER, 'revision-42')
        ->assertHeader(ControlPlaneProxyRouteProof::DYNAMIC_SHA256_HEADER, str_repeat('d', 64));
});

it('returns an authenticated side-effect-free backend identity for Traefik health checks', function (): void {
    $this->withHeader(
        ControlPlaneDynamicConfiguration::HEALTH_PROOF_HEADER,
        'health-proof-token',
    )->get('/api/health')
        ->assertNoContent()
        ->assertHeader(ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER, 'blue')
        ->assertHeader(ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER, 'revision-42')
        ->assertHeader(ControlPlaneProxyRouteProof::DYNAMIC_SHA256_HEADER, str_repeat('d', 64));
});

it('fails closed for invalid health credentials without leaking the token', function (): void {
    $response = $this->withHeader(
        ControlPlaneDynamicConfiguration::HEALTH_PROOF_HEADER,
        'wrong-health-proof-token',
    )->get('/api/health');

    $response->assertUnauthorized()
        ->assertDontSee('wrong-health-proof-token')
        ->assertHeaderMissing(ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER)
        ->assertHeaderMissing(ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER);
});

it('does not treat the public configuration acknowledgement as an authentication credential', function (): void {
    $this->withHeader(
        ControlPlaneDynamicConfiguration::CONFIGURATION_ACKNOWLEDGEMENT_HEADER,
        'ack:'.str_repeat('a', 64),
    )->get('/api/health')
        ->assertUnauthorized()
        ->assertHeaderMissing(ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER);
});

it('fails closed when proof identity configuration is incomplete', function (): void {
    config(['constants.control_plane_health.revision' => null]);

    $this->withHeader(ControlPlaneDynamicConfiguration::HEALTH_PROOF_HEADER, 'health-proof-token')
        ->get('/api/health')
        ->assertServiceUnavailable()
        ->assertHeaderMissing(ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER)
        ->assertHeaderMissing(ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER);
});

it('fails closed before emitting unsafe configured identity headers', function (): void {
    config(['constants.control_plane_health.member' => "blue\nX-Injected: value"]);

    $this->withHeader(ControlPlaneDynamicConfiguration::HEALTH_PROOF_HEADER, 'health-proof-token')
        ->get('/api/health')
        ->assertServiceUnavailable()
        ->assertHeaderMissing(ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER);
});

it('omits backend identity from ordinary health when enrollment identity is incomplete', function (): void {
    config(['constants.control_plane_health.dynamic_sha256' => null]);

    $this->get('/api/health')
        ->assertOk()
        ->assertSeeText('OK')
        ->assertHeaderMissing(ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER)
        ->assertHeaderMissing(ControlPlaneProxyRouteProof::DYNAMIC_SHA256_HEADER);
});

it('uses the exact container-local candidate marker before static handoff', function (): void {
    config([
        'constants.control_plane_health.configuration_acknowledgement' => null,
        'constants.control_plane_health.health_proof_token_sha256' => null,
        'constants.control_plane_health.dynamic_sha256' => null,
        'constants.control_plane_health.member' => null,
        'constants.control_plane_health.revision' => null,
    ]);
    $derivedHealthProof = hash_hmac(
        'sha256',
        ControlPlaneDynamicConfiguration::HEALTH_PROOF_DERIVATION_CONTEXT,
        'candidate-enrollment-token',
    );
    $marker = ControlPlaneCandidateHealthMarker::fromDerivedHealthProof(
        operationId: 'candidate-operation',
        expectedMember: 'green',
        expectedRevision: 'revision-43',
        dynamicSha256: str_repeat('e', 64),
        derivedHealthProof: $derivedHealthProof,
    );
    $markerPath = tempnam(sys_get_temp_dir(), 'coolify-candidate-health-');
    expect($markerPath)->toBeString();
    file_put_contents($markerPath, $marker->toJson());

    try {
        $request = Request::create('/api/health');
        $request->headers->set(ControlPlaneDynamicConfiguration::HEALTH_PROOF_HEADER, $derivedHealthProof);
        $response = (new RespondToControlPlaneHealthCheck($markerPath))->handle($request);

        expect($response->getStatusCode())->toBe(204)
            ->and($response->headers->get(ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER))->toBe('green')
            ->and($response->headers->get(ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER))->toBe('revision-43')
            ->and($response->headers->get(ControlPlaneProxyRouteProof::DYNAMIC_SHA256_HEADER))->toBe(str_repeat('e', 64));

        $invalidRequest = Request::create('/api/health');
        $invalidRequest->headers->set(ControlPlaneDynamicConfiguration::HEALTH_PROOF_HEADER, str_repeat('0', 64));
        expect((new RespondToControlPlaneHealthCheck($markerPath))->handle($invalidRequest)->getStatusCode())->toBe(401);
    } finally {
        if (is_string($markerPath) && is_file($markerPath)) {
            unlink($markerPath);
        }
    }
});
