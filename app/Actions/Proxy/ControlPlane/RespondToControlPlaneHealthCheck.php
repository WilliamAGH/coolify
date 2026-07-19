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
        $healthProofTokenSha256 = config('constants.control_plane_health.health_proof_token_sha256');
        $dynamicSha256 = config('constants.control_plane_health.dynamic_sha256');
        $member = config('constants.control_plane_health.member');
        $revision = config('constants.control_plane_health.revision');
        $suppliedAcknowledgement = $request->header(ControlPlaneDynamicConfiguration::CONFIGURATION_ACKNOWLEDGEMENT_HEADER);
        $suppliedHealthProof = $request->header(ControlPlaneDynamicConfiguration::HEALTH_PROOF_HEADER);

        if ($suppliedAcknowledgement === null && $suppliedHealthProof === null) {
            $response = response('OK');
            if ($this->hasValidBackendIdentity($member, $revision, $dynamicSha256)) {
                $response->withHeaders($this->backendIdentityHeaders($member, $revision, $dynamicSha256));
            }

            return $response;
        }

        if (! $this->hasValidIdentity($configurationAcknowledgement, $healthProofTokenSha256, $member, $revision, $dynamicSha256)) {
            return response('', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($suppliedAcknowledgement !== null) {
            return response('', Response::HTTP_UNAUTHORIZED);
        }
        if ($suppliedHealthProof !== null && ! hash_equals($healthProofTokenSha256, hash('sha256', $suppliedHealthProof))) {
            return response('', Response::HTTP_UNAUTHORIZED);
        }

        return response('', Response::HTTP_NO_CONTENT)
            ->withHeaders($this->backendIdentityHeaders($member, $revision, $dynamicSha256));
    }

    private function hasValidIdentity(
        mixed $configurationAcknowledgement,
        mixed $healthProofTokenSha256,
        mixed $member,
        mixed $revision,
        mixed $dynamicSha256,
    ): bool {
        return is_string($configurationAcknowledgement)
            && preg_match('/\A[A-Za-z0-9._~+\/=:-]{16,512}\z/D', $configurationAcknowledgement) === 1
            && is_string($healthProofTokenSha256)
            && preg_match('/\A[a-f0-9]{64}\z/D', $healthProofTokenSha256) === 1
            && $this->hasValidBackendIdentity($member, $revision, $dynamicSha256);
    }

    private function hasValidBackendIdentity(mixed $member, mixed $revision, mixed $dynamicSha256): bool
    {
        return is_string($member)
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $member) === 1
            && is_string($revision)
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $revision) === 1
            && is_string($dynamicSha256)
            && preg_match('/\A[a-f0-9]{64}\z/D', $dynamicSha256) === 1;
    }

    /** @return array<string, string> */
    private function backendIdentityHeaders(string $member, string $revision, string $dynamicSha256): array
    {
        return [
            ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER => $member,
            ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER => $revision,
            ControlPlaneProxyRouteProof::DYNAMIC_SHA256_HEADER => $dynamicSha256,
        ];
    }
}
