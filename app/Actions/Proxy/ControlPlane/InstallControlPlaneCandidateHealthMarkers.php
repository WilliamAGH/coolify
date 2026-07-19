<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Actions\Proxy\DurableRemoteArtifact;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class InstallControlPlaneCandidateHealthMarkers
{
    use AsAction;

    /**
     * @param  list<string>  $candidateNames
     */
    public function handle(ControlPlaneCandidateHealthMarker $marker, array $candidateNames): string
    {
        $candidateNames = $this->normalizeCandidateNames($candidateNames);
        $commands = ['set -eu'];
        foreach ($candidateNames as $candidateName) {
            $commands[] = $this->installCommand($marker, $candidateName);
        }

        return implode("\n", $commands);
    }

    /**
     * @param  array<string, array{container_id: string, image_id: string}>  $candidateRuntime
     */
    public function handleExact(ControlPlaneCandidateHealthMarker $marker, array $candidateRuntime): string
    {
        $candidateRuntime = $this->normalizeCandidateRuntime($candidateRuntime);
        $commands = ['set -eu'];
        foreach ($candidateRuntime as $candidateName => $identity) {
            $commands[] = $this->attestCommand($candidateName, $identity['container_id'], $identity['image_id']);
            $commands[] = $this->installCommand($marker, $identity['container_id']);
        }

        return implode("\n", $commands);
    }

    private function installCommand(ControlPlaneCandidateHealthMarker $marker, string $candidateName): string
    {
        $arguments = [
            'docker',
            'exec',
            $candidateName,
            'sh',
            '-ceu',
            $this->containerScript(),
            'coolify-candidate-health-marker',
            ControlPlaneCandidateHealthMarker::CONTAINER_MARKER_PATH,
            $marker->toJson(),
        ];

        return implode(' ', array_map(static fn (string $argument): string => escapeshellarg($argument), $arguments));
    }

    private function attestCommand(string $candidateName, string $containerId, string $imageId): string
    {
        $expected = "{$containerId}|/{$candidateName}|{$imageId}|true";

        return implode("\n", [
            'candidate_inspection=$(docker inspect --type container --format '.escapeshellarg('{{.Id}}|{{.Name}}|{{.Image}}|{{.State.Running}}').' '.escapeshellarg($containerId).' 2>/dev/null)',
            '[ "$candidate_inspection" = '.escapeshellarg($expected).' ]',
        ]);
    }

    private function containerScript(): string
    {
        return implode("\n", [
            'marker_path=$1',
            'marker_json=$2',
            'marker_directory=${marker_path%/*}',
            ...DurableRemoteArtifact::shellFunctions(),
            'test -n "$marker_directory" || exit 64',
            'test -d "$marker_directory" || exit 65',
            'test ! -L "$marker_directory" || exit 65',
            'if test -L "$marker_path"; then exit 65; fi',
            'if test -e "$marker_path"; then test -f "$marker_path" || exit 65; fi',
            'umask 077',
            'temporary_path=$(mktemp "$marker_directory/.coolify-control-plane-health-marker.XXXXXX")',
            'cleanup() {',
            '  if test -n "$temporary_path"; then',
            '    rm -f "$temporary_path"',
            '  fi',
            '}',
            "trap 'cleanup' 0 HUP INT TERM",
            'printf "%s" "$marker_json" > "$temporary_path"',
            'chmod 0644 "$temporary_path"',
            'test -f "$temporary_path" || exit 65',
            'test ! -L "$temporary_path" || exit 65',
            'durable_remote_replace "$temporary_path" "$marker_path" "$marker_directory" || exit 65',
            'temporary_path=',
            'test -f "$marker_path" || exit 65',
            'test ! -L "$marker_path" || exit 65',
            'test -r "$marker_path" || exit 65',
        ]);
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
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $candidateName) !== 1) {
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
        if ($candidateRuntime === [] || array_is_list($candidateRuntime)) {
            throw new InvalidArgumentException('The exact control-plane candidate runtime must be a non-empty member map.');
        }

        $candidateNames = array_keys($candidateRuntime);
        $normalizedNames = $this->normalizeCandidateNames($candidateNames);
        if ($candidateNames !== $normalizedNames) {
            throw new InvalidArgumentException('The exact control-plane candidate runtime member names must be sorted.');
        }

        foreach ($candidateRuntime as $identity) {
            if (! is_array($identity)
                || array_keys($identity) !== ['container_id', 'image_id']
                || ! is_string($identity['container_id'])
                || preg_match('/\A[a-f0-9]{64}\z/D', $identity['container_id']) !== 1
                || ! is_string($identity['image_id'])
                || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $identity['image_id']) !== 1) {
                throw new InvalidArgumentException('An exact control-plane candidate runtime identity is invalid.');
            }
        }

        return $candidateRuntime;
    }
}
