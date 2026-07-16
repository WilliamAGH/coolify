<?php

use App\Http\Middleware\TrustProxies;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class);

$originalControlPlaneEnvironment = [
    'CONTROL_PLANE_MODE' => getenv('CONTROL_PLANE_MODE'),
    'CONTROL_PLANE_STARTUP_MODE' => getenv('CONTROL_PLANE_STARTUP_MODE'),
];
$originalTrustedProxyAddresses = null;

beforeEach(function () use (&$originalTrustedProxyAddresses) {
    $originalTrustedProxyAddresses = config('control-plane.trusted_proxy_addresses');
});

afterEach(function () use ($originalControlPlaneEnvironment, &$originalTrustedProxyAddresses) {
    foreach ($originalControlPlaneEnvironment as $name => $value) {
        putenv($value === false ? $name : "{$name}={$value}");
    }

    config()->set('control-plane.trusted_proxy_addresses', $originalTrustedProxyAddresses);
});

it('keeps full-mode login limits on remote address despite spoofed forwarding headers', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=full');

    $request = Request::create('/login', 'POST', [
        'email' => 'ÜSER@EXAMPLE.COM',
    ], server: [
        'REMOTE_ADDR' => '198.51.100.10',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.20, 203.0.113.21',
    ]);

    $limit = (new TrustProxies)->handle(
        $request,
        fn (Request $trustedRequest) => RateLimiter::limiter('login')($trustedRequest),
    );

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($request->ip())->toBe('203.0.113.21')
        ->and($limit->key)->toBe('user@example.com|198.51.100.10');
});

it('keeps full-mode forgot-password limits on remote address despite spoofed forwarding headers', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=full');

    $request = Request::create('/forgot-password', 'POST', server: [
        'REMOTE_ADDR' => '2001:db8::10',
        'HTTP_X_FORWARDED_FOR' => '2001:db8::20',
    ]);

    $limit = (new TrustProxies)->handle(
        $request,
        fn (Request $trustedRequest) => RateLimiter::limiter('forgot-password')($trustedRequest),
    );

    expect($request->ip())->toBe('2001:db8::20')
        ->and($limit->key)->toBe('2001:db8::10');
});

it('does not trust the control-plane proxy forwarding headers outside active web-only mode', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=full');

    $request = Request::create('/login', 'POST', [
        'email' => 'USER@EXAMPLE.COM',
    ], server: [
        'REMOTE_ADDR' => '172.18.0.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.20',
    ]);

    $limit = (new TrustProxies)->handle(
        $request,
        fn (Request $trustedRequest) => RateLimiter::limiter('login')($trustedRequest),
    );

    expect($request->ip())->toBe('203.0.113.20')
        ->and($limit->key)->toBe('user@example.com|172.18.0.1');
});

it('ignores spoofed forwarding headers from a direct web-only client', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
    config()->set('control-plane.trusted_proxy_addresses', '172.30.44.1');

    $request = Request::create('/login', 'POST', [
        'email' => 'USER@EXAMPLE.COM',
    ], server: [
        'REMOTE_ADDR' => '198.51.100.10',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.20',
    ]);

    $loginLimit = (new TrustProxies)->handle(
        $request,
        fn (Request $trustedRequest) => RateLimiter::limiter('login')($trustedRequest),
    );
    $forgotPasswordLimit = (new TrustProxies)->handle(
        $request,
        fn (Request $trustedRequest) => RateLimiter::limiter('forgot-password')($trustedRequest),
    );

    expect($request->ip())->toBe('203.0.113.20')
        ->and($loginLimit->key)->toBe('user@example.com|198.51.100.10')
        ->and($forgotPasswordLimit->key)->toBe('198.51.100.10');
});

it('honors the forwarded client from an explicit loopback proxy', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=full');

    $request = Request::create('/login', 'POST', [
        'email' => 'USER@EXAMPLE.COM',
    ], server: [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.20',
    ]);

    $limit = (new TrustProxies)->handle(
        $request,
        fn (Request $trustedRequest) => RateLimiter::limiter('login')($trustedRequest),
    );

    expect($limit->key)->toBe('user@example.com|203.0.113.20');
});

it('uses an attested non-default proxy gateway for control-plane login buckets', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
    config()->set('control-plane.trusted_proxy_addresses', '172.30.44.1');

    $firstRequest = Request::create('/login', 'POST', [
        'email' => 'USER@EXAMPLE.COM',
    ], server: [
        'REMOTE_ADDR' => '172.30.44.1',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.30',
        'HTTP_X_REAL_IP' => '203.0.113.30',
        'HTTP_FORWARDED' => 'for=203.0.113.31',
    ]);
    $secondRequest = Request::create('/login', 'POST', [
        'email' => 'user@example.com',
    ], server: [
        'REMOTE_ADDR' => '172.30.44.1',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.31',
        'HTTP_X_REAL_IP' => '203.0.113.32',
        'HTTP_FORWARDED' => 'for=203.0.113.33',
    ]);

    $firstLimit = (new TrustProxies)->handle(
        $firstRequest,
        fn (Request $trustedRequest) => RateLimiter::limiter('login')($trustedRequest),
    );
    $secondLimit = (new TrustProxies)->handle(
        $secondRequest,
        fn (Request $trustedRequest) => RateLimiter::limiter('login')($trustedRequest),
    );

    expect($firstLimit->key)->toBe('user@example.com|198.51.100.30')
        ->and($secondLimit->key)->toBe('user@example.com|198.51.100.31')
        ->and($firstLimit->key)->not->toBe($secondLimit->key);
});

it('uses canonical forwarded IPv6 clients for web-only auth buckets', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
    config()->set('control-plane.trusted_proxy_addresses', '172.30.44.1');

    $request = Request::create('/login', 'POST', [
        'email' => 'USER@EXAMPLE.COM',
    ], server: [
        'REMOTE_ADDR' => '172.30.44.1',
        'HTTP_X_FORWARDED_FOR' => '2001:db8::30',
    ]);

    $loginLimit = (new TrustProxies)->handle(
        $request,
        fn (Request $trustedRequest) => RateLimiter::limiter('login')($trustedRequest),
    );
    $forgotPasswordLimit = (new TrustProxies)->handle(
        $request,
        fn (Request $trustedRequest) => RateLimiter::limiter('forgot-password')($trustedRequest),
    );

    expect($loginLimit->key)->toBe('user@example.com|2001:db8::30')
        ->and($forgotPasswordLimit->key)->toBe('2001:db8::30');
});

it('fails closed for malformed control-plane proxy addresses', function () {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
    config()->set('control-plane.trusted_proxy_addresses', '172.30.44.1, 172.30.44.2');

    $request = Request::create('/login', 'POST', [
        'email' => 'USER@EXAMPLE.COM',
    ], server: [
        'REMOTE_ADDR' => '172.30.44.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.20',
    ]);

    $limit = (new TrustProxies)->handle(
        $request,
        fn (Request $trustedRequest) => RateLimiter::limiter('login')($trustedRequest),
    );

    expect($limit->key)->toBe('user@example.com|172.30.44.1');
});

it('uses remote address for direct web-only requests without forwarding headers', function (string $remoteAddress) {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');

    $request = Request::create('/forgot-password', 'POST', server: [
        'REMOTE_ADDR' => $remoteAddress,
    ]);

    $limit = (new TrustProxies)->handle(
        $request,
        fn (Request $trustedRequest) => RateLimiter::limiter('forgot-password')($trustedRequest),
    );

    expect($request->ip())->toBe($remoteAddress)
        ->and($limit->key)->toBe($remoteAddress);
})->with([
    'IPv4' => '198.51.100.40',
    'IPv6' => '2001:db8::40',
]);

it('fails closed when the web server does not provide a remote address', function (?string $remoteAddress) {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');

    $request = Request::create('/login', 'POST', [
        'email' => 'USER@EXAMPLE.COM',
    ], server: [
        'HTTP_X_FORWARDED_FOR' => '203.0.113.20',
    ]);

    if ($remoteAddress === null) {
        $request->server->remove('REMOTE_ADDR');
    } else {
        $request->server->set('REMOTE_ADDR', $remoteAddress);
    }

    expect(fn () => RateLimiter::limiter('login')($request))
        ->toThrow(LogicException::class, 'server-supplied REMOTE_ADDR');
})->with([
    'missing' => null,
    'blank' => '',
]);
