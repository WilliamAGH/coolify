<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneCandidateHealthMarker;
use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;

function controlPlaneCandidateHealthMarker(string $operationId = 'operation-42'): ControlPlaneCandidateHealthMarker
{
    return ControlPlaneCandidateHealthMarker::fromDerivedHealthProof(
        operationId: $operationId,
        expectedMember: 'blue',
        expectedRevision: 'revision-42',
        dynamicSha256: hash('sha256', 'coolify.yaml replacement'),
        derivedHealthProof: hash_hmac('sha256', 'coolify-control-plane-health-check-v1', 'raw-enrollment-token'),
    );
}

function temporaryControlPlaneCandidateHealthMarkerPath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'coolify-health-marker-');
    if ($path === false || ! unlink($path)) {
        throw new RuntimeException('Could not allocate a temporary candidate health-marker path.');
    }

    return $path;
}

it('serializes only nonsecret candidate health identity material canonically', function (): void {
    $rawEnrollmentToken = 'raw-enrollment-token';
    $derivedHealthProof = hash_hmac('sha256', 'coolify-control-plane-health-check-v1', $rawEnrollmentToken);
    $marker = controlPlaneCandidateHealthMarker();
    $json = $marker->toJson();
    $authenticationProxyProof = ControlPlaneDynamicConfiguration::deriveAuthenticationProxyProof($derivedHealthProof);

    expect($marker->healthProofSha256)->toBe(hash('sha256', $derivedHealthProof))
        ->and($marker->authenticationProxyProofSha256)->toBe(hash('sha256', $authenticationProxyProof))
        ->and($json)->toBe(json_encode([
            'operation_id' => 'operation-42',
            'expected_member' => 'blue',
            'expected_revision' => 'revision-42',
            'dynamic_sha256' => hash('sha256', 'coolify.yaml replacement'),
            'health_proof_sha256' => hash('sha256', $derivedHealthProof),
            'authentication_proxy_proof_sha256' => hash('sha256', $authenticationProxyProof),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->and($json)->not->toContain($rawEnrollmentToken)
        ->and($json)->not->toContain($derivedHealthProof)
        ->and($json)->not->toContain($authenticationProxyProof)
        ->and(ControlPlaneCandidateHealthMarker::fromJson($json))
        ->toEqual($marker);
});

it('rejects malformed, duplicate, and unexpected candidate health-marker JSON', function (): void {
    $json = controlPlaneCandidateHealthMarker()->toJson();
    $duplicateOperationId = str_replace(
        '"operation_id":"operation-42",',
        '"operation_id":"operation-42","operation_id":"operation-42",',
        $json,
    );
    $unexpectedKey = str_replace('}', ',"unexpected":"value"}', $json);

    expect(fn (): ControlPlaneCandidateHealthMarker => ControlPlaneCandidateHealthMarker::fromJson('{not-json'))
        ->toThrow(InvalidArgumentException::class, 'malformed');
    expect(fn (): ControlPlaneCandidateHealthMarker => ControlPlaneCandidateHealthMarker::fromJson($duplicateOperationId))
        ->toThrow(InvalidArgumentException::class, 'not canonical');
    expect(fn (): ControlPlaneCandidateHealthMarker => ControlPlaneCandidateHealthMarker::fromJson($unexpectedKey))
        ->toThrow(InvalidArgumentException::class, 'unexpected shape');
});

it('rejects stale, symlinked, and nonregular candidate health-marker targets', function (): void {
    $path = temporaryControlPlaneCandidateHealthMarkerPath();
    $target = temporaryControlPlaneCandidateHealthMarkerPath();
    $expected = controlPlaneCandidateHealthMarker();

    try {
        file_put_contents($path, controlPlaneCandidateHealthMarker('operation-previous')->toJson());
        expect(fn (): ControlPlaneCandidateHealthMarker => ControlPlaneCandidateHealthMarker::readExpected($expected, $path))
            ->toThrow(InvalidArgumentException::class, 'stale');

        unlink($path);
        mkdir($path);
        expect(fn (): ControlPlaneCandidateHealthMarker => ControlPlaneCandidateHealthMarker::readFromPath($path))
            ->toThrow(InvalidArgumentException::class, 'regular file');

        rmdir($path);
        file_put_contents($target, $expected->toJson());
        symlink($target, $path);
        expect(fn (): ControlPlaneCandidateHealthMarker => ControlPlaneCandidateHealthMarker::readFromPath($path))
            ->toThrow(InvalidArgumentException::class, 'regular file');
    } finally {
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            rmdir($path);
        }
        if (is_file($target)) {
            unlink($target);
        }
    }
});
