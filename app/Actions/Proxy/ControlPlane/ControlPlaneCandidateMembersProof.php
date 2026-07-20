<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;

final readonly class ControlPlaneCandidateMembersProof
{
    public const LOCAL_HEALTH_URL = 'http://127.0.0.1:8080/api/health';

    public const TRANSCRIPT_BEGIN = '__COOLIFY_CANDIDATE_MEMBER_PROOF_BEGIN__';

    public const TRANSCRIPT_STATUS = '__COOLIFY_CANDIDATE_MEMBER_PROOF_STATUS__';

    public const TRANSCRIPT_END = '__COOLIFY_CANDIDATE_MEMBER_PROOF_END__';

    /** @var list<string> */
    public array $candidateNames;

    /** @var array<string, array{container_id: string, image_id: string}> */
    public array $candidateRuntime;

    /**
     * @param  list<string>  $candidateNames
     */
    public function __construct(
        array $candidateNames,
        public string $expectedMember,
        public string $expectedRevision,
        public string $dynamicSha256,
        public string $healthCheckProof,
        array $candidateRuntime = [],
    ) {
        $this->candidateNames = $this->normalizeCandidateNames($candidateNames);
        $this->candidateRuntime = $this->normalizeCandidateRuntime($candidateRuntime);
        if ($this->candidateRuntime !== [] && array_keys($this->candidateRuntime) !== $this->candidateNames) {
            throw new InvalidArgumentException('The exact control-plane candidate runtime must match the candidate member set.');
        }
        $this->assertIdentifier($expectedMember, 'expected member');
        $this->assertIdentifier($expectedRevision, 'expected revision');
        if (preg_match('/^[a-f0-9]{64}$/D', $dynamicSha256) !== 1) {
            throw new InvalidArgumentException('The control-plane dynamic checksum must be a SHA-256 value.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $healthCheckProof) !== 1) {
            throw new InvalidArgumentException('The derived control-plane health-check proof must be a SHA-256 value.');
        }
    }

    /** @return array<string, string> */
    public function expectedResponseHeaders(): array
    {
        return [
            ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER => $this->expectedMember,
            ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER => $this->expectedRevision,
            ControlPlaneProxyRouteProof::DYNAMIC_SHA256_HEADER => $this->dynamicSha256,
        ];
    }

    public function shellCommand(): string
    {
        $commands = ['set -eu'];
        foreach ($this->candidateNames as $candidateName) {
            foreach ([1, 2] as $attempt) {
                $commands[] = $this->attemptCommand($candidateName, $attempt);
            }
        }

        return implode("\n", $commands);
    }

    private function attemptCommand(string $candidateName, int $attempt): string
    {
        $runtimeIdentity = $this->candidateRuntime[$candidateName] ?? null;
        $containerSelector = $runtimeIdentity['container_id'] ?? $candidateName;
        $arguments = [
            'docker',
            'exec',
            $containerSelector,
            'curl',
            '--fail',
            '--silent',
            '--show-error',
            '--connect-timeout',
            '5',
            '--max-time',
            '15',
            '--request',
            'GET',
            '--header',
            ControlPlaneDynamicConfiguration::HEALTH_PROOF_HEADER.': '.$this->healthCheckProof,
            '--dump-header',
            '-',
            '--output',
            '/dev/null',
            '--write-out',
            "\n".self::TRANSCRIPT_STATUS." %{http_code}\n",
            '--',
            self::LOCAL_HEALTH_URL,
        ];

        $commands = [
            "printf '%s\\n' ".escapeshellarg(self::TRANSCRIPT_BEGIN." {$candidateName} {$attempt}"),
        ];
        if ($runtimeIdentity !== null) {
            $expected = "{$runtimeIdentity['container_id']}|/{$candidateName}|{$runtimeIdentity['image_id']}|true";
            $commands[] = 'candidate_inspection=$(docker inspect --type container --format '.escapeshellarg('{{.Id}}|{{.Name}}|{{.Image}}|{{.State.Running}}').' '.escapeshellarg($runtimeIdentity['container_id']).' 2>/dev/null)';
            $commands[] = '[ "$candidate_inspection" = '.escapeshellarg($expected).' ]';
        }
        $commands[] = implode(' ', array_map(static fn (string $argument): string => escapeshellarg($argument), $arguments));
        $commands[] = "printf '%s\\n' ".escapeshellarg(self::TRANSCRIPT_END);

        return implode("\n", $commands);
    }

    /**
     * @param  list<string>  $candidateNames
     * @return list<string>
     */
    private function normalizeCandidateNames(array $candidateNames): array
    {
        if (! array_is_list($candidateNames) || $candidateNames === []) {
            throw new InvalidArgumentException('The control-plane candidate member set must be a non-empty list.');
        }
        foreach ($candidateNames as $candidateName) {
            if (! is_string($candidateName)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $candidateName) !== 1) {
                throw new InvalidArgumentException('A control-plane candidate member must be a Docker-safe DNS name.');
            }
        }
        if (count(array_unique($candidateNames, SORT_STRING)) !== count($candidateNames)) {
            throw new InvalidArgumentException('The control-plane candidate member set must not contain duplicates.');
        }
        sort($candidateNames, SORT_STRING);

        return array_values($candidateNames);
    }

    /**
     * @param  array<string, array{container_id: string, image_id: string}>  $candidateRuntime
     * @return array<string, array{container_id: string, image_id: string}>
     */
    private function normalizeCandidateRuntime(array $candidateRuntime): array
    {
        if ($candidateRuntime === []) {
            return [];
        }
        if (array_is_list($candidateRuntime)) {
            throw new InvalidArgumentException('The exact control-plane candidate runtime must be a member map.');
        }
        foreach ($candidateRuntime as $candidateName => $identity) {
            if (! is_string($candidateName)
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $candidateName) !== 1
                || ! is_array($identity)
                || array_keys($identity) !== ['container_id', 'image_id']
                || ! is_string($identity['container_id'])
                || preg_match('/\A[a-f0-9]{64}\z/D', $identity['container_id']) !== 1
                || ! is_string($identity['image_id'])
                || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $identity['image_id']) !== 1) {
                throw new InvalidArgumentException('An exact control-plane candidate runtime identity is invalid.');
            }
        }
        $sorted = $candidateRuntime;
        ksort($sorted, SORT_STRING);
        if ($candidateRuntime !== $sorted) {
            throw new InvalidArgumentException('The exact control-plane candidate runtime member names must be sorted.');
        }

        return $candidateRuntime;
    }

    private function assertIdentifier(string $value, string $role): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) !== 1) {
            throw new InvalidArgumentException("The control-plane {$role} is invalid.");
        }
    }
}
