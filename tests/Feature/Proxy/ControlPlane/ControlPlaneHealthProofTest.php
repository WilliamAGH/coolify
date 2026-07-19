<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyRouteProof;

beforeEach(function (): void {
    config([
        'constants.control_plane_health.configuration_acknowledgement' => 'ack:'.str_repeat('a', 64),
        'constants.control_plane_health.proof_token_sha256' => hash('sha256', 'route-proof-token'),
        'constants.control_plane_health.health_proof_token_sha256' => hash('sha256', 'health-proof-token'),
        'constants.control_plane_health.member' => 'blue',
        'constants.control_plane_health.revision' => 'revision-42',
    ]);
});

it('preserves the public health response when no control-plane proof is requested', function (): void {
    $this->get('/api/health')
        ->assertOk()
        ->assertSeeText('OK')
        ->assertHeaderMissing(ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER)
        ->assertHeaderMissing(ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER);
});

it('preserves health and proof behavior through the versioned API alias', function (): void {
    $this->get('/api/v1/health')
        ->assertOk()
        ->assertSeeText('OK');

    $this->withHeader(ControlPlaneProxyRouteProof::PROOF_HEADER, 'route-proof-token')
        ->get('/api/v1/health')
        ->assertNoContent()
        ->assertHeader(ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER, 'blue')
        ->assertHeader(ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER, 'revision-42');
});

it('returns an authenticated side-effect-free backend identity for Traefik health checks', function (): void {
    $this->withHeader(
        ControlPlaneDynamicConfiguration::HEALTH_PROOF_HEADER,
        'health-proof-token',
    )->get('/api/health')
        ->assertNoContent()
        ->assertHeader(ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER, 'blue')
        ->assertHeader(ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER, 'revision-42');
});

it('returns an authenticated side-effect-free backend identity for route proof', function (): void {
    $this->withHeader(ControlPlaneProxyRouteProof::PROOF_HEADER, 'route-proof-token')
        ->get('/api/health')
        ->assertNoContent()
        ->assertHeader(ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER, 'blue')
        ->assertHeader(ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER, 'revision-42')
        ->assertHeaderMissing(ControlPlaneProxyRouteProof::PROOF_HEADER);
});

it('fails closed for invalid proof credentials without leaking the token', function (): void {
    $response = $this->withHeaders([
        ControlPlaneDynamicConfiguration::CONFIGURATION_ACKNOWLEDGEMENT_HEADER => 'ack:'.str_repeat('a', 64),
        ControlPlaneProxyRouteProof::PROOF_HEADER => 'wrong-route-proof-token',
    ])->get('/api/health');

    $response->assertUnauthorized()
        ->assertDontSee('wrong-route-proof-token')
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

    $this->withHeader(ControlPlaneProxyRouteProof::PROOF_HEADER, 'route-proof-token')
        ->get('/api/health')
        ->assertServiceUnavailable()
        ->assertHeaderMissing(ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER)
        ->assertHeaderMissing(ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER);
});

it('fails closed before emitting unsafe configured identity headers', function (): void {
    config(['constants.control_plane_health.member' => "blue\nX-Injected: value"]);

    $this->withHeader(ControlPlaneProxyRouteProof::PROOF_HEADER, 'route-proof-token')
        ->get('/api/health')
        ->assertServiceUnavailable()
        ->assertHeaderMissing(ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER);
});
