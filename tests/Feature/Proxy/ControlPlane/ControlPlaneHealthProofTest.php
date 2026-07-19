<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyRouteProof;

beforeEach(function (): void {
    config([
        'constants.control_plane_health.configuration_acknowledgement' => 'ack:'.str_repeat('a', 64),
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
