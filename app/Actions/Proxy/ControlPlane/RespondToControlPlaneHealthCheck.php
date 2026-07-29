<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Actions\Proxy\BlueGreenRoutingTarget;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpFoundation\Response;

final class RespondToControlPlaneHealthCheck
{
    use AsAction;

    public function __construct(
        private readonly string $candidateMarkerPath = ControlPlaneCandidateHealthMarker::CONTAINER_MARKER_PATH,
    ) {}

    public function handle(Request $request): Response
    {
        $configurationAcknowledgement = config('constants.control_plane_health.configuration_acknowledgement');
        $healthProofTokenSha256 = config('constants.control_plane_health.health_proof_token_sha256');
        $dynamicSha256 = config('constants.control_plane_health.dynamic_sha256');
        $member = config('constants.control_plane_health.member');
        $revision = config('constants.control_plane_health.revision');
        $deploymentReleaseProof = config('constants.control_plane_health.deployment_release_proof');
        $suppliedAcknowledgement = $request->header(ControlPlaneDynamicConfiguration::CONFIGURATION_ACKNOWLEDGEMENT_HEADER);
        $suppliedHealthProof = $request->header(ControlPlaneDynamicConfiguration::HEALTH_PROOF_HEADER);

        $configuredIdentity = $this->configuredIdentity(
            $configurationAcknowledgement,
            $healthProofTokenSha256,
            $member,
            $revision,
            $dynamicSha256,
        );
        $candidateIdentity = $this->candidateMarkerIdentity();

        if ($suppliedAcknowledgement === null && $suppliedHealthProof === null) {
            $response = response('OK');
            if ($this->validDeploymentReleaseProof($deploymentReleaseProof)) {
                $response->header(BlueGreenRoutingTarget::RELEASE_PROOF_HEADER, $deploymentReleaseProof);
            }
            if ($configuredIdentity !== null) {
                $response->withHeaders($this->backendIdentityHeaders(
                    $configuredIdentity['member'],
                    $configuredIdentity['revision'],
                    $configuredIdentity['dynamicSha256'],
                ));
            } elseif ($candidateIdentity !== null) {
                $response->withHeaders($this->backendIdentityHeaders(
                    $candidateIdentity['member'],
                    $candidateIdentity['revision'],
                    $candidateIdentity['dynamicSha256'],
                ));
            }

            return $response;
        }

        $identity = $candidateIdentity ?? $configuredIdentity;
        if ($identity === null) {
            return response('', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($suppliedAcknowledgement !== null) {
            return response('', Response::HTTP_UNAUTHORIZED);
        }
        if ($suppliedHealthProof !== null && ! hash_equals($identity['healthProofSha256'], hash('sha256', $suppliedHealthProof))) {
            return response('', Response::HTTP_UNAUTHORIZED);
        }

        return response('', Response::HTTP_NO_CONTENT)
            ->withHeaders($this->backendIdentityHeaders(
                $identity['member'],
                $identity['revision'],
                $identity['dynamicSha256'],
            ));
    }

    private function validDeploymentReleaseProof(mixed $releaseProof): bool
    {
        return is_string($releaseProof)
            && preg_match('/\Arelease:[a-f0-9]{64}\z/D', $releaseProof) === 1;
    }

    /** @return null|array{healthProofSha256: string, member: string, revision: string, dynamicSha256: string} */
    private function configuredIdentity(
        mixed $configurationAcknowledgement,
        mixed $healthProofTokenSha256,
        mixed $member,
        mixed $revision,
        mixed $dynamicSha256,
    ): ?array {
        if (! is_string($configurationAcknowledgement)
            || preg_match('/\A[A-Za-z0-9._~+\/=:-]{16,512}\z/D', $configurationAcknowledgement) !== 1) {
            return null;
        }
        if (! is_string($healthProofTokenSha256)
            || preg_match('/\A[a-f0-9]{64}\z/D', $healthProofTokenSha256) !== 1
            || ! $this->hasValidBackendIdentity($member, $revision, $dynamicSha256)) {
            return null;
        }

        return [
            'healthProofSha256' => $healthProofTokenSha256,
            'member' => $member,
            'revision' => $revision,
            'dynamicSha256' => $dynamicSha256,
        ];
    }

    /** @return null|array{healthProofSha256: string, member: string, revision: string, dynamicSha256: string} */
    private function candidateMarkerIdentity(): ?array
    {
        try {
            $marker = ControlPlaneCandidateHealthMarker::readFromPath($this->candidateMarkerPath);
        } catch (InvalidArgumentException) {
            return null;
        }

        return [
            'healthProofSha256' => $marker->healthProofSha256,
            'member' => $marker->expectedMember,
            'revision' => $marker->expectedRevision,
            'dynamicSha256' => $marker->dynamicSha256,
        ];
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
