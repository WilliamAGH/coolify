<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class ControlPlaneGenerationDrainProof
{
    use AsAction;

    public const TRANSCRIPT_BEGIN = '__COOLIFY_CONTROL_PLANE_GENERATION_DRAIN_PROOF_BEGIN__';

    public const TRANSCRIPT_RECORD = '__COOLIFY_CONTROL_PLANE_GENERATION_DRAIN_PROOF_RECORD__';

    public const TRANSCRIPT_END = '__COOLIFY_CONTROL_PLANE_GENERATION_DRAIN_PROOF_END__';

    public function __construct(private string $procRoot = '/proc')
    {
        if (! str_starts_with($this->procRoot, '/') || str_contains($this->procRoot, "\0")) {
            throw new InvalidArgumentException('The control-plane generation drain proof proc root is invalid.');
        }
    }

    public function handle(
        ControlPlaneGenerationPromotionState $state,
        int $backendPort,
        string $freezeOperationId,
    ): string {
        return $this->commandFor(
            state: $state,
            backendPort: $backendPort,
            freezeOperationId: $freezeOperationId,
        );
    }

    public function commandFor(
        ControlPlaneGenerationPromotionState $state,
        int $backendPort,
        string $freezeOperationId,
    ): string {
        $this->assertArguments($state, $backendPort, $freezeOperationId);

        $backendPortHex = strtoupper(str_pad(dechex($backendPort), 4, '0', STR_PAD_LEFT));
        $commands = [
            'set -eu',
            'fail() { exit 1; }',
            'proc_root='.escapeshellarg($this->procRoot),
            'backend_port_hex='.escapeshellarg($backendPortHex),
            "printf '%s\\n' ".escapeshellarg(self::TRANSCRIPT_BEGIN),
        ];
        foreach ($state->runtime->predecessorRuntime as $candidateName => $identity) {
            $commands[] = $this->candidateCommand(
                candidateName: $candidateName,
                containerId: $identity['container_id'],
                imageId: $identity['image_id'],
            );
        }
        $commands[] = "printf '%s\\n' ".escapeshellarg(self::TRANSCRIPT_END);

        return implode("\n", $commands);
    }

    private function candidateCommand(string $candidateName, string $containerId, string $imageId): string
    {
        return implode("\n", [
            'candidate_name='.escapeshellarg($candidateName),
            'expected_docker_id='.escapeshellarg($containerId),
            'expected_container_name='.escapeshellarg('/'.$candidateName),
            'expected_image_id='.escapeshellarg($imageId),
            <<<'SH'
candidate_inspection=$(docker inspect --type container --format '{{.Id}}|{{.Name}}|{{.Image}}|{{.State.Running}}|{{.State.Pid}}' "$expected_docker_id" 2>/dev/null) || fail
candidate_actual_id=${candidate_inspection%%|*}
candidate_remainder=${candidate_inspection#*|}
[ "$candidate_remainder" != "$candidate_inspection" ] || fail
candidate_actual_name=${candidate_remainder%%|*}
candidate_remainder=${candidate_remainder#*|}
[ "$candidate_remainder" != "$candidate_actual_name" ] || fail
candidate_actual_image=${candidate_remainder%%|*}
candidate_remainder=${candidate_remainder#*|}
[ "$candidate_remainder" != "$candidate_actual_image" ] || fail
candidate_running=${candidate_remainder%%|*}
candidate_pid=${candidate_remainder#*|}
[ "$candidate_pid" != "$candidate_running" ] || fail
case "$candidate_pid" in ''|*[!0-9]*|*'|'*) fail ;; esac
[ "$candidate_actual_id" = "$expected_docker_id" ] || fail
[ "$candidate_actual_name" = "$expected_container_name" ] || fail
[ "$candidate_actual_image" = "$expected_image_id" ] || fail
[ "$candidate_running" = true ] || fail
[ "$candidate_pid" -gt 0 ] || fail
candidate_tcp_path="$proc_root/$candidate_pid/net/tcp"
candidate_tcp6_path="$proc_root/$candidate_pid/net/tcp6"
[ -r "$candidate_tcp_path" ] && [ -r "$candidate_tcp6_path" ] || fail
candidate_connection_count=$(awk -v target_port="$backend_port_hex" '
FNR == 1 {
    headers += 1
    if (NF < 4 || $1 != "sl") {
        invalid = 1
        exit 1
    }
    next
}
{
    endpoint_fields = split($2, endpoint, ":")
    if (NF < 4 || endpoint_fields != 2 || (length(endpoint[1]) != 8 && length(endpoint[1]) != 32) || length(endpoint[2]) != 4 || endpoint[1] !~ /^[0-9A-Fa-f]+$/ || endpoint[2] !~ /^[0-9A-Fa-f]+$/ || length($4) != 2 || $4 !~ /^[0-9A-Fa-f]+$/) {
        invalid = 1
        exit 1
    }
    if (toupper(endpoint[2]) == target_port && toupper($4) == "01") {
        active += 1
    }
}
END {
    if (invalid || headers != 2) {
        exit 1
    }
    print active + 0
}
' "$candidate_tcp_path" "$candidate_tcp6_path" 2>/dev/null) || fail
case "$candidate_connection_count" in ''|*[!0-9]*) fail ;; esac
candidate_observed_at=$(date -u '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null) || fail
case "$candidate_observed_at" in
    [0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z) ;;
    *) fail ;;
esac
SH,
            "printf '%s %s %s %s %s %s %s %s\\n' ".escapeshellarg(self::TRANSCRIPT_RECORD).' "$candidate_name" "$candidate_actual_id" "$candidate_actual_name" "$candidate_actual_image" "$candidate_pid" "$candidate_connection_count" "$candidate_observed_at"',
        ]);
    }

    private function assertArguments(
        ControlPlaneGenerationPromotionState $state,
        int $backendPort,
        string $freezeOperationId,
    ): void {
        if ($backendPort < 1 || $backendPort > 65535) {
            throw new InvalidArgumentException('The control-plane generation drain proof backend port is invalid.');
        }
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,127}\z/D', $freezeOperationId) !== 1
            || ! hash_equals($state->operationId, $freezeOperationId)
            || $state->mutationFreeze === null
            || ! hash_equals($state->mutationFreeze['operation_id'], $freezeOperationId)) {
            throw new InvalidArgumentException('The control-plane generation drain proof requires the exact mutation freeze owner operation ID.');
        }
        if ($state->dualRoute === null || $state->draining === null) {
            throw new InvalidArgumentException('The control-plane generation drain proof requires durable route acknowledgement and drain deadline evidence.');
        }
        if ($state->runtime->predecessorRuntime === []) {
            throw new InvalidArgumentException('The control-plane generation drain proof requires predecessor runtime members.');
        }
    }
}
