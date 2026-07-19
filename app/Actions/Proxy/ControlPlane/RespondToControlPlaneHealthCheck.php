<?php

namespace App\Actions\Proxy\ControlPlane;

use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpFoundation\Response;

final class RespondToControlPlaneHealthCheck
{
    use AsAction;

    public function handle(Request $request): Response
    {
        $configurationAcknowledgement = config('constants.control_plane_health.configuration_acknowledgement');
        $proofTokenSha256 = config('constants.control_plane_health.proof_token_sha256');
        $healthProofTokenSha256 = config('constants.control_plane_health.health_proof_token_sha256');
        $member = config('constants.control_plane_health.member');
        $revision = config('constants.control_plane_health.revision');
        $suppliedAcknowledgement = $request->header(ControlPlaneDynamicConfiguration::CONFIGURATION_ACKNOWLEDGEMENT_HEADER);
        $suppliedHealthProof = $request->header(ControlPlaneDynamicConfiguration::HEALTH_PROOF_HEADER);
        $suppliedProofToken = $request->header(ControlPlaneProxyRouteProof::PROOF_HEADER);

        if ($suppliedAcknowledgement === null && $suppliedHealthProof === null && $suppliedProofToken === null) {
            return response('OK');
        }

        if (! $this->hasValidIdentity($configurationAcknowledgement, $proofTokenSha256, $healthProofTokenSha256, $member, $revision)) {
            return response('', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($suppliedAcknowledgement !== null) {
            return response('', Response::HTTP_UNAUTHORIZED);
        }
        if ($suppliedHealthProof !== null && ! hash_equals($healthProofTokenSha256, hash('sha256', $suppliedHealthProof))) {
            return response('', Response::HTTP_UNAUTHORIZED);
        }
        if ($suppliedProofToken !== null && ! hash_equals($proofTokenSha256, hash('sha256', $suppliedProofToken))) {
            return response('', Response::HTTP_UNAUTHORIZED);
        }

        return response('', Response::HTTP_NO_CONTENT)->withHeaders([
            ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER => $member,
            ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER => $revision,
        ]);
    }

    private function hasValidIdentity(
        mixed $configurationAcknowledgement,
        mixed $proofTokenSha256,
        mixed $healthProofTokenSha256,
        mixed $member,
        mixed $revision,
    ): bool {
        return is_string($configurationAcknowledgement)
            && preg_match('/\A[A-Za-z0-9._~+\/=:-]{16,512}\z/D', $configurationAcknowledgement) === 1
            && is_string($proofTokenSha256)
            && preg_match('/\A[a-f0-9]{64}\z/D', $proofTokenSha256) === 1
            && is_string($healthProofTokenSha256)
            && preg_match('/\A[a-f0-9]{64}\z/D', $healthProofTokenSha256) === 1
            && is_string($member)
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $member) === 1
            && is_string($revision)
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $revision) === 1;
    }
}
