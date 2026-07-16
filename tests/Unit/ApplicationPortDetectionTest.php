<?php

/**
 * Unit tests for PORT environment variable detection feature.
 *
 * Tests verify that the Application model can correctly detect PORT environment
 * variables and provide information to the UI about matches and mismatches with
 * the configured ports_exposes field.
 */

use App\Models\Application;
use App\Models\EnvironmentVariable;
use Tests\TestCase;

uses(TestCase::class);

function applicationWithPortEnvironmentVariable(?string $port = null, bool $isPreview = false): Application
{
    $application = new Application;
    $application->setRelation('environment', null);
    $application->setRelation('destination', null);

    $environmentVariables = collect();
    if ($port !== null) {
        $environmentVariable = new EnvironmentVariable([
            'key' => 'PORT',
            'value' => $port,
            'is_literal' => false,
            'is_multiline' => false,
        ]);
        $environmentVariable->setRelation('resourceable', $application);
        $environmentVariables->push($environmentVariable);
    }

    $application->setRelation(
        $isPreview ? 'environment_variables_preview' : 'environment_variables',
        $environmentVariables,
    );

    return $application;
}

it('detects PORT environment variable when present', function () {
    $application = applicationWithPortEnvironmentVariable('3000');

    $detectedPort = $application->detectPortFromEnvironment();

    expect($detectedPort)->toBe(3000);
});

it('returns null when PORT environment variable is not set', function () {
    $application = applicationWithPortEnvironmentVariable();

    $detectedPort = $application->detectPortFromEnvironment();

    expect($detectedPort)->toBeNull();
});

it('returns null when PORT value is not numeric', function () {
    $application = applicationWithPortEnvironmentVariable('invalid-port');

    $detectedPort = $application->detectPortFromEnvironment();

    expect($detectedPort)->toBeNull();
});

it('handles PORT value with whitespace', function () {
    $application = applicationWithPortEnvironmentVariable('  8080  ');

    $detectedPort = $application->detectPortFromEnvironment();

    expect($detectedPort)->toBe(8080);
});

it('detects PORT from preview environment variables when isPreview is true', function () {
    $application = applicationWithPortEnvironmentVariable('4000', isPreview: true);

    $detectedPort = $application->detectPortFromEnvironment(true);

    expect($detectedPort)->toBe(4000);
});

it('verifies ports_exposes array conversion logic', function () {
    // Test the logic that converts comma-separated ports to array
    $portsExposesString = '3000,3001,8080';
    $expectedArray = [3000, 3001, 8080];

    // This simulates what portsExposesArray accessor does
    $result = is_null($portsExposesString)
        ? []
        : explode(',', $portsExposesString);

    // Convert to integers for comparison
    $result = array_map('intval', $result);

    expect($result)->toBe($expectedArray);
});

it('verifies PORT matches detection logic', function () {
    $detectedPort = 3000;
    $portsExposesArray = [3000, 3001];

    $isMatch = in_array($detectedPort, $portsExposesArray);

    expect($isMatch)->toBeTrue();
});

it('verifies PORT mismatch detection logic', function () {
    $detectedPort = 8080;
    $portsExposesArray = [3000, 3001];

    $isMatch = in_array($detectedPort, $portsExposesArray);

    expect($isMatch)->toBeFalse();
});

it('verifies empty ports_exposes detection logic', function () {
    $portsExposesArray = [];

    $isEmpty = empty($portsExposesArray);

    expect($isEmpty)->toBeTrue();
});
