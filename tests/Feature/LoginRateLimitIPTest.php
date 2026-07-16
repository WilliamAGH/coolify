<?php

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('does not combine failed login attempts from distinct client IPs', function () {
    $email = 'grumpinout+admin@wearehackerone.com';

    // Each IP is allowed its own unsuccessful login attempt.
    for ($i = 1; $i <= 14; $i++) {
        $spoofedIp = "198.51.100.{$i}";

        $response = $this->withServerVariables(['REMOTE_ADDR' => $spoofedIp])
            ->post('/login', [
                'email' => $email,
                'password' => "WrongPass{$i}!",
            ]);

        $response->assertRedirect();
    }
});
