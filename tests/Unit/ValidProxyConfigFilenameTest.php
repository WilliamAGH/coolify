<?php

use App\Rules\ValidProxyConfigFilename;
use App\Support\ProxyDynamicConfigurationFilenamePolicy;

test('allows valid proxy config filenames', function () {
    $validFilenames = [
        'my-config',
        'service_name.yaml',
        'router-1.yml',
        'traefik-config',
        'my.service.yaml',
        'config_v2.caddy',
        'API-Gateway.yaml',
        'load-balancer_prod.yml',
    ];

    $rule = new ValidProxyConfigFilename;
    $failures = [];

    foreach ($validFilenames as $filename) {
        $rule->validate('fileName', $filename, function ($message) use (&$failures, $filename) {
            $failures[] = "{$filename}: {$message}";
        });
    }

    expect($failures)->toBeEmpty();
});

test('blocks path traversal with forward slash', function () {
    $rule = new ValidProxyConfigFilename;
    $failed = false;

    $rule->validate('fileName', '../etc/passwd', function () use (&$failed) {
        $failed = true;
    });

    expect($failed)->toBeTrue();
});

test('blocks path traversal with backslash', function () {
    $rule = new ValidProxyConfigFilename;
    $failed = false;

    $rule->validate('fileName', '..\\windows\\system32', function () use (&$failed) {
        $failed = true;
    });

    expect($failed)->toBeTrue();
});

test('blocks hidden files starting with dot', function () {
    $rule = new ValidProxyConfigFilename;
    $failed = false;

    $rule->validate('fileName', '.hidden.yaml', function () use (&$failed) {
        $failed = true;
    });

    expect($failed)->toBeTrue();
});

test('blocks reserved filename coolify.yaml', function () {
    $rule = new ValidProxyConfigFilename;
    $failed = false;

    $rule->validate('fileName', 'coolify.yaml', function () use (&$failed) {
        $failed = true;
    });

    expect($failed)->toBeTrue();
});

test('blocks reserved filename coolify.yml', function () {
    $rule = new ValidProxyConfigFilename;
    $failed = false;

    $rule->validate('fileName', 'coolify.yml', function () use (&$failed) {
        $failed = true;
    });

    expect($failed)->toBeTrue();
});

test('blocks reserved filename Caddyfile', function () {
    $rule = new ValidProxyConfigFilename;
    $failed = false;

    $rule->validate('fileName', 'Caddyfile', function () use (&$failed) {
        $failed = true;
    });

    expect($failed)->toBeTrue();
});

test('blocks Coolify-managed blue green proxy filenames', function () {
    $rule = new ValidProxyConfigFilename;
    $failed = false;

    $rule->validate('fileName', 'coolify-blue-green-a5adf99dc3d81b09.yaml', function () use (&$failed) {
        $failed = true;
    });

    expect($failed)->toBeTrue();
});

test('applies one read-only filename policy to validation', function (string $filename) {
    $rule = new ValidProxyConfigFilename;
    $failed = false;

    $rule->validate('fileName', $filename, function () use (&$failed) {
        $failed = true;
    });

    expect(ProxyDynamicConfigurationFilenamePolicy::isReadOnly($filename))->toBeTrue()
        ->and($failed)->toBeTrue();
})->with([
    'traefik default configuration' => 'coolify.yaml',
    'traefik alternate configuration' => 'coolify.yml',
    'caddy dynamic configuration' => 'coolify.caddy',
    'caddy main configuration' => 'Caddyfile',
    'traefik maintenance configuration' => 'default_redirect_503.yaml',
    'caddy maintenance configuration' => 'default_redirect_503.caddy',
    'blue green generated configuration' => 'coolify-blue-green-a5adf99dc3d81b09.yaml',
]);

test('does not reserve ordinary dynamic configuration filenames', function () {
    expect(ProxyDynamicConfigurationFilenamePolicy::isReadOnly('custom-router.yaml'))->toBeFalse()
        ->and(ProxyDynamicConfigurationFilenamePolicy::isReadOnly('Caddyfile.backup'))->toBeFalse();
});

test('blocks filenames with invalid characters', function () {
    $invalidFilenames = [
        'file;rm.yaml',
        'file|test.yaml',
        'config$var.yaml',
        'test`cmd`.yaml',
        'name with spaces.yaml',
        'file<redirect.yaml',
        'file>output.yaml',
        'config&background.yaml',
        "file\nnewline.yaml",
    ];

    $rule = new ValidProxyConfigFilename;

    foreach ($invalidFilenames as $filename) {
        $failed = false;
        $rule->validate('fileName', $filename, function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeTrue("Expected '{$filename}' to be rejected");
    }
});

test('blocks filenames exceeding 255 characters', function () {
    $rule = new ValidProxyConfigFilename;
    $failed = false;

    $longFilename = str_repeat('a', 256);
    $rule->validate('fileName', $longFilename, function () use (&$failed) {
        $failed = true;
    });

    expect($failed)->toBeTrue();
});

test('allows filenames at exactly 255 characters', function () {
    $rule = new ValidProxyConfigFilename;
    $failed = false;

    $exactFilename = str_repeat('a', 255);
    $rule->validate('fileName', $exactFilename, function () use (&$failed) {
        $failed = true;
    });

    expect($failed)->toBeFalse();
});

test('allows empty values without failing', function () {
    $rule = new ValidProxyConfigFilename;
    $failed = false;

    $rule->validate('fileName', '', function () use (&$failed) {
        $failed = true;
    });

    expect($failed)->toBeFalse();
});

test('blocks nested path traversal', function () {
    $rule = new ValidProxyConfigFilename;
    $failed = false;

    $rule->validate('fileName', 'foo/bar/../../etc/passwd', function () use (&$failed) {
        $failed = true;
    });

    expect($failed)->toBeTrue();
});

test('allows similar but not reserved filenames', function () {
    $validFilenames = [
        'coolify-custom.yaml',
        'my-coolify.yaml',
        'coolify2.yaml',
        'Caddyfile.backup',
        'my-Caddyfile',
    ];

    $rule = new ValidProxyConfigFilename;
    $failures = [];

    foreach ($validFilenames as $filename) {
        $rule->validate('fileName', $filename, function ($message) use (&$failures, $filename) {
            $failures[] = "{$filename}: {$message}";
        });
    }

    expect($failures)->toBeEmpty();
});
