<?php

namespace App\Actions\Proxy\ControlPlane;

use Illuminate\Http\Request;
use InvalidArgumentException;

final readonly class VerifyControlPlaneAuthenticationProxyProof
{
    public function __construct(
        private string $candidateMarkerPath = ControlPlaneCandidateHealthMarker::CONTAINER_MARKER_PATH,
    ) {}

    public function handle(Request $request): bool
    {
        $suppliedProof = $request->header(ControlPlaneDynamicConfiguration::AUTHENTICATION_PROXY_PROOF_HEADER);
        if (! is_string($suppliedProof) || preg_match('/\A[a-f0-9]{64}\z/D', $suppliedProof) !== 1) {
            return false;
        }

        $expectedProofSha256 = $this->candidateProofSha256() ?? $this->configuredProofSha256();

        return $expectedProofSha256 !== null
            && hash_equals($expectedProofSha256, hash('sha256', $suppliedProof));
    }

    private function candidateProofSha256(): ?string
    {
        try {
            return ControlPlaneCandidateHealthMarker::readFromPath($this->candidateMarkerPath)->healthProofSha256;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function configuredProofSha256(): ?string
    {
        $proofSha256 = config('constants.control_plane_health.health_proof_token_sha256');

        return is_string($proofSha256) && preg_match('/\A[a-f0-9]{64}\z/D', $proofSha256) === 1
            ? $proofSha256
            : null;
    }
}
