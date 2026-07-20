<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneCandidateHealthMarker;
use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneAuthenticationProxyProof;
use App\Http\Middleware\TrustProxies;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class);

function authenticationRateLimit(string $limiter, Request $request): Limit|array
{
    return (new TrustProxies)->handle(
        $request,
        fn (Request $trustedRequest): Limit|array => RateLimiter::limiter($limiter)($trustedRequest),
    );
}

function authenticationRequest(string $remoteAddress, string $forwardedAddress, ?string $proof = null): Request
{
    $server = [
        'REMOTE_ADDR' => $remoteAddress,
        'HTTP_X_FORWARDED_FOR' => $forwardedAddress,
    ];
    if ($proof !== null) {
        $server['HTTP_'.str_replace('-', '_', strtoupper(ControlPlaneDynamicConfiguration::AUTHENTICATION_PROXY_PROOF_HEADER))] = $proof;
    }

    return Request::create('/login', 'POST', ['email' => 'ÜSER@EXAMPLE.COM'], server: $server);
}

it('keeps direct authentication buckets on the canonical remote address', function (): void {
    config(['constants.control_plane_health.authentication_proxy_proof_sha256' => hash('sha256', str_repeat('e', 64))]);
    $request = authenticationRequest('2001:0db8:0:0:0:0:0:10', '203.0.113.20', 'forged-proof');

    $loginLimit = authenticationRateLimit('login', $request);
    $forgotPasswordLimits = authenticationRateLimit('forgot-password', $request);

    expect($request->ip())->toBe('203.0.113.20')
        ->and($loginLimit)->toBeInstanceOf(Limit::class)
        ->and($loginLimit->key)->toBe('user@example.com|2001:db8::10')
        ->and($forgotPasswordLimits)->toBeArray()
        ->and($forgotPasswordLimits[0]->key)->toBe('forgot-password:ip:'.sha1('2001:db8::10'));
});

it('uses the forwarded client only with the exact control-plane proof', function (): void {
    $proof = ControlPlaneDynamicConfiguration::deriveAuthenticationProxyProof(str_repeat('a', 64));
    config(['constants.control_plane_health.authentication_proxy_proof_sha256' => hash('sha256', $proof)]);

    $valid = authenticationRateLimit('login', authenticationRequest('172.30.44.1', '2001:0db8:0:0:0:0:0:20', $proof));
    $missing = authenticationRateLimit('login', authenticationRequest('172.30.44.1', '203.0.113.21'));
    $forged = authenticationRateLimit('login', authenticationRequest('172.30.44.1', '203.0.113.23', str_repeat('f', 64)));
    $malformedForwarded = authenticationRateLimit('login', authenticationRequest('172.30.44.1', 'not-an-ip', $proof));
    $nearHeaderRequest = authenticationRequest('172.30.44.1', '203.0.113.22');
    $nearHeaderRequest->headers->set(ControlPlaneDynamicConfiguration::AUTHENTICATION_PROXY_PROOF_HEADER.'-Near', $proof);
    $nearHeader = authenticationRateLimit('login', $nearHeaderRequest);

    expect($valid->key)->toBe('user@example.com|2001:db8::20')
        ->and($missing->key)->toBe('user@example.com|172.30.44.1')
        ->and($forged->key)->toBe('user@example.com|172.30.44.1')
        ->and($malformedForwarded->key)->toBe('user@example.com|172.30.44.1')
        ->and($nearHeader->key)->toBe('user@example.com|172.30.44.1');
});

it('keeps explicit loopback proxy forwarding without a control-plane proof', function (string $loopback): void {
    $limit = authenticationRateLimit('login', authenticationRequest($loopback, '203.0.113.30'));

    expect($limit->key)->toBe('user@example.com|203.0.113.30');
})->with(['IPv4' => '127.0.0.1', 'IPv6' => '::1']);

it('fails closed without a canonical server-supplied remote address', function (?string $remoteAddress): void {
    $request = authenticationRequest('198.51.100.10', '203.0.113.40');
    if ($remoteAddress === null) {
        $request->server->remove('REMOTE_ADDR');
    } else {
        $request->server->set('REMOTE_ADDR', $remoteAddress);
    }

    expect(fn (): Limit|array => RateLimiter::limiter('login')($request))
        ->toThrow(LogicException::class, 'canonical server-supplied REMOTE_ADDR');
})->with(['missing' => null, 'blank' => '', 'malformed' => 'not-an-ip']);

it('prefers the exact candidate marker proof during enrollment', function (): void {
    $candidateHealthProof = str_repeat('b', 64);
    $candidateProof = ControlPlaneDynamicConfiguration::deriveAuthenticationProxyProof($candidateHealthProof);
    $configuredProof = ControlPlaneDynamicConfiguration::deriveAuthenticationProxyProof(str_repeat('c', 64));
    config(['constants.control_plane_health.authentication_proxy_proof_sha256' => hash('sha256', $configuredProof)]);
    $marker = ControlPlaneCandidateHealthMarker::fromDerivedHealthProof(
        operationId: 'candidate-operation',
        expectedMember: 'green',
        expectedRevision: 'revision-43',
        dynamicSha256: str_repeat('d', 64),
        derivedHealthProof: $candidateHealthProof,
    );
    $markerPath = tempnam(sys_get_temp_dir(), 'coolify-auth-proof-');
    expect($markerPath)->toBeString();
    file_put_contents($markerPath, $marker->toJson());

    try {
        $verifier = new VerifyControlPlaneAuthenticationProxyProof($markerPath);
        $candidateRequest = authenticationRequest('172.30.44.1', '203.0.113.50', $candidateProof);
        $configuredRequest = authenticationRequest('172.30.44.1', '203.0.113.50', $configuredProof);

        expect($verifier->handle($candidateRequest))->toBeTrue()
            ->and($verifier->handle($configuredRequest))->toBeFalse();
    } finally {
        if (is_string($markerPath) && is_file($markerPath)) {
            unlink($markerPath);
        }
    }
});
