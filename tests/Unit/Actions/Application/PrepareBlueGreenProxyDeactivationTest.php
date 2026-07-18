<?php

use App\Actions\Application\BlueGreen\BlueGreenDeactivationException;
use App\Actions\Application\BlueGreen\PrepareBlueGreenProxyDeactivation;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\BlueGreenDeploymentColor;
use App\Models\Application;
use App\Models\ApplicationSetting;

function proxyDeactivationApplication(): Application
{
    $application = new Application;
    $application->uuid = 'app-test';
    $application->fqdn = 'http://example.test:8080';
    $application->ports_exposes = '8080';
    $settings = new ApplicationSetting;
    $settings->setRawAttributes(['is_static' => false]);
    $application->setRelation('settings', $settings);

    return $application;
}

/** @param array<string, mixed> $services */
function proxyDeactivationBackendPort(
    array $services,
    Application $application,
    BlueGreenDeploymentColor $activeColor = BlueGreenDeploymentColor::BLUE,
): int {
    return (new ReflectionMethod(PrepareBlueGreenProxyDeactivation::class, 'backendPort'))
        ->invoke(new PrepareBlueGreenProxyDeactivation, $services, $application, 5, $activeColor);
}

it('accepts only the exact weighted active member and derives the application backend port', function () {
    $application = proxyDeactivationApplication();
    $activeService = BlueGreenRoutingTarget::activeServiceName($application->uuid, 5);

    expect(proxyDeactivationBackendPort([
        $activeService => [
            'weighted' => [
                'services' => [[
                    'name' => BlueGreenRoutingTarget::memberServiceReference(
                        $application->uuid,
                        5,
                        BlueGreenDeploymentColor::BLUE,
                    ),
                    'weight' => 1,
                ]],
            ],
        ],
    ], $application))->toBe(8080);
});

it('rejects a weighted service that points at a different member', function () {
    $application = proxyDeactivationApplication();
    $activeService = BlueGreenRoutingTarget::activeServiceName($application->uuid, 5);

    expect(fn () => proxyDeactivationBackendPort([
        $activeService => [
            'weighted' => [
                'services' => [[
                    'name' => BlueGreenRoutingTarget::memberServiceReference(
                        $application->uuid,
                        5,
                        BlueGreenDeploymentColor::GREEN,
                    ),
                    'weight' => 1,
                ]],
            ],
        ],
    ], $application))->toThrow(
        BlueGreenDeactivationException::class,
        'exact active weighted member',
    );
});

it('preserves legacy backend inventories only when their port matches the application', function () {
    $application = proxyDeactivationApplication();
    $legacyServices = [
        'legacy-active' => [
            'loadBalancer' => [
                'servers' => [['url' => 'http://app-test-blue:8080']],
            ],
        ],
    ];

    expect(proxyDeactivationBackendPort($legacyServices, $application))->toBe(8080);

    $legacyServices['legacy-active']['loadBalancer']['servers'][0]['url'] =
        'http://app-test-blue:3000';

    expect(fn () => proxyDeactivationBackendPort($legacyServices, $application))->toThrow(
        BlueGreenDeactivationException::class,
        'does not match the application backend port',
    );
});

it('accepts a legacy backend inventory on the application backend port', function () {
    $application = proxyDeactivationApplication();

    expect(proxyDeactivationBackendPort([
        'legacy-service' => [
            'loadBalancer' => [
                'servers' => [['url' => 'http://app-test-blue:8080']],
            ],
        ],
    ], $application))->toBe(8080);
});

it('rejects a legacy backend inventory on a different port', function () {
    $application = proxyDeactivationApplication();

    expect(fn () => proxyDeactivationBackendPort([
        'legacy-service' => [
            'loadBalancer' => [
                'servers' => [['url' => 'http://app-test-blue:8081']],
            ],
        ],
    ], $application))->toThrow(
        BlueGreenDeactivationException::class,
        'does not match the application backend port',
    );
});
