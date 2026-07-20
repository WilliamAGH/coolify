<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(
        fn (): InstanceSettings => InstanceSettings::query()->updateOrCreate(['id' => 0]),
    );
    RateLimiter::clear('login');

    $this->user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
    ]);
});

test('login is rate limited after 5 failed attempts from same IP', function () {
    $email = 'test@example.com';

    // First 5 attempts should be accepted (302 redirect back with error, not 429)
    for ($i = 1; $i <= 5; $i++) {
        $response = $this->post('/login', [
            'email' => $email,
            'password' => 'wrong-password',
        ]);

        expect($response->status())->toBe(302, "Attempt {$i} should redirect (302), got {$response->status()}");
    }

    // 6th attempt from same IP should be throttled
    $response = $this->post('/login', [
        'email' => $email,
        'password' => 'wrong-password',
    ]);

    expect($response->status())->toBe(429, 'Expected 429 Too Many Requests after exceeding rate limit');
});

test('rate limit is scoped per email and IP combination', function () {
    // Exhaust rate limit for first email
    for ($i = 1; $i <= 5; $i++) {
        $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);
    }

    // Different email from same IP should still work (different composite key)
    $response = $this->post('/login', [
        'email' => 'other@example.com',
        'password' => 'wrong-password',
    ]);

    expect($response->status())->toBe(302, 'Different email should not be rate limited');
});

test('successful login is still possible within rate limit', function () {
    $response = $this->post('/login', [
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $response->assertRedirect();
    expect($response->status())->not->toBe(429);
});

test('direct clients cannot split a login bucket with spoofed forwarding headers', function () {
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_X_FORWARDED_FOR' => "203.0.113.{$attempt}",
        ])->post('/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ])->assertRedirect();
    }

    $this->withServerVariables([
        'REMOTE_ADDR' => '198.51.100.10',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
    ])->post('/login', [
        'email' => 'test@example.com',
        'password' => 'wrong-password',
    ])->assertTooManyRequests();
});

test('distinct direct client addresses retain independent login buckets', function () {
    foreach (range(1, 6) as $host) {
        $this->withServerVariables([
            'REMOTE_ADDR' => "198.51.100.{$host}",
        ])->post('/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ])->assertRedirect();
    }
});
