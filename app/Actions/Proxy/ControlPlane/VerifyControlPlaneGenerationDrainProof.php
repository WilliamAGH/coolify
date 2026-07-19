<?php

namespace App\Actions\Proxy\ControlPlane;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class VerifyControlPlaneGenerationDrainProof
{
    use AsAction;

    /**
     * @return array{observed_at: string, pending: int, reserved: int, delayed: int, tcp_connection_count: int, predecessor_runtime_sha256: string}
     */
    public function handle(
        ControlPlaneGenerationPromotionState $state,
        string $transcript,
        string $freezeOperationId,
        int $pending = 0,
        int $reserved = 0,
        int $delayed = 0,
    ): array {
        $window = $this->assertContext(
            state: $state,
            freezeOperationId: $freezeOperationId,
            pending: $pending,
            reserved: $reserved,
            delayed: $delayed,
        );
        $records = $this->parseTranscript($transcript);
        $expectedRuntime = $state->runtime->predecessorRuntime;
        if (array_keys($records) !== array_keys($expectedRuntime)) {
            throw new InvalidArgumentException('Control-plane generation drain proof output is partial, contains an extra member, or repeats a member.');
        }

        $latestObservedAt = null;
        $latestObservedAtValue = null;
        foreach ($expectedRuntime as $candidateName => $identity) {
            $record = $records[$candidateName];
            if (! hash_equals($identity['container_id'], $record['container_id'])
                || ! hash_equals('/'.$candidateName, $record['container_name'])
                || ! hash_equals($identity['image_id'], $record['image_id'])) {
                throw new InvalidArgumentException('Control-plane generation drain proof has a stale predecessor runtime identity.');
            }
            if ($record['tcp_connection_count'] !== '0') {
                throw new InvalidArgumentException('Control-plane generation drain proof observed active backend TCP connections.');
            }

            $observedAt = $this->parseTimestamp($record['observed_at']);
            if ($observedAt < $window['route_acknowledged_at'] || $observedAt >= $window['deadline_at']) {
                throw new InvalidArgumentException('Control-plane generation drain proof timestamp is outside the acknowledged immutable drain window.');
            }
            if ($latestObservedAt === null || $observedAt > $latestObservedAt) {
                $latestObservedAt = $observedAt;
                $latestObservedAtValue = $record['observed_at'];
            }
        }
        if ($latestObservedAtValue === null) {
            throw new InvalidArgumentException('Control-plane generation drain proof has no predecessor runtime records.');
        }

        return [
            'observed_at' => $latestObservedAtValue,
            'pending' => $pending,
            'reserved' => $reserved,
            'delayed' => $delayed,
            'tcp_connection_count' => 0,
            'predecessor_runtime_sha256' => $state->predecessorRuntimeSha256(),
        ];
    }

    /**
     * @return array{route_acknowledged_at: DateTimeImmutable, deadline_at: DateTimeImmutable}
     */
    private function assertContext(
        ControlPlaneGenerationPromotionState $state,
        string $freezeOperationId,
        int $pending,
        int $reserved,
        int $delayed,
    ): array {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,127}\z/D', $freezeOperationId) !== 1
            || ! hash_equals($state->operationId, $freezeOperationId)
            || $state->mutationFreeze === null
            || ! hash_equals($state->mutationFreeze['operation_id'], $freezeOperationId)) {
            throw new InvalidArgumentException('The control-plane generation drain proof requires the exact mutation freeze owner operation ID.');
        }
        if ($pending !== 0 || $reserved !== 0 || $delayed !== 0) {
            throw new InvalidArgumentException('The control-plane generation drain proof requires an empty canonical mutation queue.');
        }
        if ($state->dualRoute === null || $state->draining === null) {
            throw new InvalidArgumentException('The control-plane generation drain proof requires durable route acknowledgement and drain deadline evidence.');
        }

        return [
            'route_acknowledged_at' => new DateTimeImmutable($state->dualRoute['observed_at']),
            'deadline_at' => new DateTimeImmutable($state->draining['deadline_at']),
        ];
    }

    /**
     * @return array<string, array{container_id: string, container_name: string, image_id: string, pid: string, tcp_connection_count: string, observed_at: string}>
     */
    private function parseTranscript(string $transcript): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $transcript);
        if (! is_array($lines)) {
            throw new InvalidArgumentException('Control-plane generation drain proof output could not be parsed.');
        }
        if (($lines[array_key_last($lines)] ?? null) === '') {
            array_pop($lines);
        }
        if (count($lines) < 3
            || $lines[0] !== ControlPlaneGenerationDrainProof::TRANSCRIPT_BEGIN
            || $lines[count($lines) - 1] !== ControlPlaneGenerationDrainProof::TRANSCRIPT_END) {
            throw new InvalidArgumentException('Control-plane generation drain proof output has invalid sentinel boundaries.');
        }

        $recordPattern = '/\A'.preg_quote(ControlPlaneGenerationDrainProof::TRANSCRIPT_RECORD, '/').' ([A-Za-z0-9][A-Za-z0-9_.-]{0,127}) ([a-f0-9]{64}) (\\/[A-Za-z0-9][A-Za-z0-9_.-]{0,127}) (sha256:[a-f0-9]{64}) ([1-9][0-9]*) (0|[1-9][0-9]*) (\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z)\\z/D';
        $records = [];
        foreach (array_slice($lines, 1, -1) as $line) {
            if (preg_match($recordPattern, $line, $record) !== 1) {
                throw new InvalidArgumentException('Control-plane generation drain proof output has a malformed sentinel record.');
            }
            $candidateName = $record[1];
            if (isset($records[$candidateName])) {
                throw new InvalidArgumentException('Control-plane generation drain proof output repeats a predecessor runtime member.');
            }
            $records[$candidateName] = [
                'container_id' => $record[2],
                'container_name' => $record[3],
                'image_id' => $record[4],
                'pid' => $record[5],
                'tcp_connection_count' => $record[6],
                'observed_at' => $record[7],
            ];
        }
        ksort($records, SORT_STRING);

        return $records;
    }

    private function parseTimestamp(string $value): DateTimeImmutable
    {
        $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))) {
            throw new InvalidArgumentException('Control-plane generation drain proof timestamp is malformed.');
        }

        return $timestamp;
    }
}
