<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneCandidateMembersProof;
use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyRouteProof;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneCandidateMembers;

function controlPlaneCandidateMembersProof(array $candidateNames = ['coolify-web-b', 'coolify-web-a']): ControlPlaneCandidateMembersProof
{
    return new ControlPlaneCandidateMembersProof(
        candidateNames: $candidateNames,
        expectedMember: 'blue',
        expectedRevision: 'revision-42',
        dynamicSha256: hash('sha256', 'coolify.yaml replacement'),
        healthCheckProof: hash_hmac('sha256', 'coolify-control-plane-health-check-v1', 'raw-enrollment-token'),
    );
}

function controlPlaneCandidateMembersProofRecord(
    ControlPlaneCandidateMembersProof $proof,
    string $candidateName,
    int $attempt,
    array $headers = [],
    int $status = 204,
): string {
    $responseHeaders = [
        ...$proof->expectedResponseHeaders(),
        ...$headers,
    ];
    $headerLines = "HTTP/2 {$status}\r\n";
    foreach ($responseHeaders as $name => $value) {
        $headerLines .= "{$name}: {$value}\r\n";
    }

    return implode("\n", [
        ControlPlaneCandidateMembersProof::TRANSCRIPT_BEGIN." {$candidateName} {$attempt}",
        rtrim($headerLines),
        '',
        ControlPlaneCandidateMembersProof::TRANSCRIPT_STATUS." {$status}",
        ControlPlaneCandidateMembersProof::TRANSCRIPT_END,
    ]);
}

function successfulControlPlaneCandidateMembersTranscript(ControlPlaneCandidateMembersProof $proof): string
{
    $records = [];
    foreach ($proof->candidateNames as $candidateName) {
        foreach ([1, 2] as $attempt) {
            $records[] = controlPlaneCandidateMembersProofRecord($proof, $candidateName, $attempt);
        }
    }

    return implode("\n", $records);
}

it('creates a deterministic local two-attempt proof for every candidate', function (): void {
    $proof = controlPlaneCandidateMembersProof();
    $command = $proof->shellCommand();

    expect($proof->candidateNames)->toBe(['coolify-web-a', 'coolify-web-b'])
        ->and($command)->toBe(controlPlaneCandidateMembersProof(['coolify-web-a', 'coolify-web-b'])->shellCommand())
        ->and($command)->toContain("'docker' 'exec'")
        ->and($command)->toContain(ControlPlaneCandidateMembersProof::LOCAL_HEALTH_URL)
        ->and($command)->toContain(ControlPlaneDynamicConfiguration::HEALTH_PROOF_HEADER)
        ->and($command)->toContain($proof->healthCheckProof)
        ->and($command)->not->toContain('raw-enrollment-token')
        ->and(VerifyControlPlaneCandidateMembers::run($proof, successfulControlPlaneCandidateMembersTranscript($proof)))
        ->toBe($proof);
});

it('requires exact 204 backend identity responses from every candidate', function (): void {
    $proof = controlPlaneCandidateMembersProof();
    $success = successfulControlPlaneCandidateMembersTranscript($proof);
    $staleDynamicSha = str_replace($proof->dynamicSha256, str_repeat('0', 64), $success);
    $missingRevision = str_replace(
        ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER.": {$proof->expectedRevision}\r\n",
        '',
        $success,
    );
    $nonNoContent = implode("\n", [
        controlPlaneCandidateMembersProofRecord($proof, 'coolify-web-a', 1, status: 200),
        controlPlaneCandidateMembersProofRecord($proof, 'coolify-web-a', 2),
        controlPlaneCandidateMembersProofRecord($proof, 'coolify-web-b', 1),
        controlPlaneCandidateMembersProofRecord($proof, 'coolify-web-b', 2),
    ]);

    expect(fn (): ControlPlaneCandidateMembersProof => VerifyControlPlaneCandidateMembers::run($proof, $staleDynamicSha))
        ->toThrow(InvalidArgumentException::class, ControlPlaneProxyRouteProof::DYNAMIC_SHA256_HEADER);
    expect(fn (): ControlPlaneCandidateMembersProof => VerifyControlPlaneCandidateMembers::run($proof, $missingRevision))
        ->toThrow(InvalidArgumentException::class, ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER);
    expect(fn (): ControlPlaneCandidateMembersProof => VerifyControlPlaneCandidateMembers::run($proof, $nonNoContent))
        ->toThrow(InvalidArgumentException::class, 'expected 204');
});

it('requires exactly two unique records for each expected candidate', function (): void {
    $proof = controlPlaneCandidateMembersProof();
    $partial = implode("\n", [
        controlPlaneCandidateMembersProofRecord($proof, 'coolify-web-a', 1),
        controlPlaneCandidateMembersProofRecord($proof, 'coolify-web-a', 2),
        controlPlaneCandidateMembersProofRecord($proof, 'coolify-web-b', 1),
    ]);
    $duplicate = successfulControlPlaneCandidateMembersTranscript($proof)."\n"
        .controlPlaneCandidateMembersProofRecord($proof, 'coolify-web-a', 1);
    $unexpected = successfulControlPlaneCandidateMembersTranscript($proof)."\n"
        .controlPlaneCandidateMembersProofRecord($proof, 'coolify-web-c', 1)."\n"
        .controlPlaneCandidateMembersProofRecord($proof, 'coolify-web-c', 2);

    expect(fn (): ControlPlaneCandidateMembersProof => VerifyControlPlaneCandidateMembers::run($proof, $partial))
        ->toThrow(InvalidArgumentException::class, 'partial');
    expect(fn (): ControlPlaneCandidateMembersProof => VerifyControlPlaneCandidateMembers::run($proof, $duplicate))
        ->toThrow(InvalidArgumentException::class, 'repeats a candidate attempt');
    expect(fn (): ControlPlaneCandidateMembersProof => VerifyControlPlaneCandidateMembers::run($proof, $unexpected))
        ->toThrow(InvalidArgumentException::class, 'unexpected candidate attempt');
});

it('rejects malformed transcripts and unsafe proof input before verification', function (): void {
    $proof = controlPlaneCandidateMembersProof();

    expect(fn (): ControlPlaneCandidateMembersProof => VerifyControlPlaneCandidateMembers::run(
        $proof,
        ControlPlaneCandidateMembersProof::TRANSCRIPT_BEGIN." coolify-web-a 1\nHTTP/2 204\n",
    ))->toThrow(InvalidArgumentException::class, 'no valid curl status');

    expect(fn (): ControlPlaneCandidateMembersProof => controlPlaneCandidateMembersProof([]))
        ->toThrow(InvalidArgumentException::class, 'non-empty list');
    expect(fn (): ControlPlaneCandidateMembersProof => controlPlaneCandidateMembersProof(['coolify-web-a', 'coolify-web-a']))
        ->toThrow(InvalidArgumentException::class, 'must not contain duplicates');
    expect(fn (): ControlPlaneCandidateMembersProof => controlPlaneCandidateMembersProof(['coolify-web-a; curl attacker.test']))
        ->toThrow(InvalidArgumentException::class, 'Docker-safe DNS name');
    expect(fn (): ControlPlaneCandidateMembersProof => controlPlaneCandidateMembersProof([42]))
        ->toThrow(InvalidArgumentException::class, 'Docker-safe DNS name');
    expect(fn (): ControlPlaneCandidateMembersProof => new ControlPlaneCandidateMembersProof(
        candidateNames: ['coolify-web-a'],
        expectedMember: "blue\nX-Injected: value",
        expectedRevision: 'revision-42',
        dynamicSha256: hash('sha256', 'coolify.yaml replacement'),
        healthCheckProof: hash_hmac('sha256', 'coolify-control-plane-health-check-v1', 'raw-enrollment-token'),
    ))->toThrow(InvalidArgumentException::class, 'expected member');
});
