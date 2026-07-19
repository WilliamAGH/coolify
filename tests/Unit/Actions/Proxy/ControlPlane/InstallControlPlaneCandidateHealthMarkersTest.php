<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneCandidateHealthMarker;
use App\Actions\Proxy\ControlPlane\InstallControlPlaneCandidateHealthMarkers;

function markerForCandidateHealthInstallation(string $derivedHealthProof): ControlPlaneCandidateHealthMarker
{
    return ControlPlaneCandidateHealthMarker::fromDerivedHealthProof(
        operationId: 'operation-42',
        expectedMember: 'blue',
        expectedRevision: 'revision-42',
        dynamicSha256: hash('sha256', 'coolify.yaml replacement'),
        derivedHealthProof: $derivedHealthProof,
    );
}

it('renders deterministic, escaped, atomic candidate-marker installation commands', function (): void {
    $rawEnrollmentToken = 'raw-enrollment-token';
    $derivedHealthProof = hash_hmac('sha256', 'coolify-control-plane-health-check-v1', $rawEnrollmentToken);
    $marker = markerForCandidateHealthInstallation($derivedHealthProof);
    $command = InstallControlPlaneCandidateHealthMarkers::run($marker, ['coolify-web-b', 'coolify-web-a']);

    expect($command)->toBe(InstallControlPlaneCandidateHealthMarkers::run($marker, ['coolify-web-a', 'coolify-web-b']))
        ->and($command)->toContain("'docker' 'exec' 'coolify-web-a' 'sh' '-ceu'")
        ->and($command)->toContain("'docker' 'exec' 'coolify-web-b' 'sh' '-ceu'")
        ->and($command)->toContain(ControlPlaneCandidateHealthMarker::CONTAINER_MARKER_PATH)
        ->and($command)->toContain(escapeshellarg($marker->toJson()))
        ->and($command)->toContain('chmod 0644')
        ->and($command)->toContain('mv -f')
        ->and($command)->toContain($marker->healthProofSha256)
        ->and($command)->not->toContain($rawEnrollmentToken)
        ->and($command)->not->toContain($derivedHealthProof)
        ->and(strpos($command, "'coolify-web-a'"))
        ->toBeLessThan(strpos($command, "'coolify-web-b'"));
});

it('rejects empty, duplicate, and unsafe candidate sets before rendering', function (): void {
    $marker = markerForCandidateHealthInstallation(hash_hmac('sha256', 'coolify-control-plane-health-check-v1', 'raw-enrollment-token'));

    expect(fn (): string => InstallControlPlaneCandidateHealthMarkers::run($marker, []))
        ->toThrow(InvalidArgumentException::class, 'non-empty list');
    expect(fn (): string => InstallControlPlaneCandidateHealthMarkers::run($marker, ['coolify-web-a', 'coolify-web-a']))
        ->toThrow(InvalidArgumentException::class, 'must not contain duplicates');
    expect(fn (): string => InstallControlPlaneCandidateHealthMarkers::run($marker, ['coolify-web-a; curl attacker.test']))
        ->toThrow(InvalidArgumentException::class, 'Docker-safe DNS name');
});
