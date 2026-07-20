<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Models\Server;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

final class AttestControlPlaneTraefikRuntime
{
    use AsAction;

    private const MAXIMUM_EVENT_BYTES = 8192;

    public string $commandSignature = 'control-plane:attest-traefik-runtime {event_path : Absolute event path, or - for standard input}';

    public string $commandDescription = 'Run one bounded, read-only Traefik route attestation for a drift event.';

    public function __construct(
        private readonly StoreControlPlaneProxyEnrollmentState $enrollmentStore,
        private readonly StoreControlPlaneGenerationPromotionState $promotionStore,
        private readonly VerifyControlPlaneProxyRoutes $routeVerifier,
    ) {}

    /**
     * @param  array{
     *     version: int,
     *     event_id: string,
     *     reason: string,
     *     observed_at: string,
     *     expires_at: string,
     *     server_id: int,
     *     operation_id: string,
     *     dynamic_revision: int,
     *     dynamic_sha256: string
     * }  $event
     * @param  (Closure(string, Server): ?string)|null  $remoteExecutor
     */
    public function handle(array $event, ?Closure $remoteExecutor = null): ControlPlaneProxyRouteProof
    {
        $this->validateEvent($event);
        $server = Server::query()->find($event['server_id'])
            ?? throw new RuntimeException('The control-plane attestation server no longer exists.');
        if (! $server->isLocalhost()) {
            throw new RuntimeException('Control-plane runtime attestation requires the local Coolify server.');
        }

        $enrollment = $this->enrollmentStore->read($server)
            ?? throw new RuntimeException('The permanent control-plane enrollment state is missing.');
        if ($enrollment->phase !== ControlPlaneProxyEnrollmentPhase::Enrolled) {
            throw new RuntimeException('The permanent control-plane enrollment is not enrolled.');
        }

        $promotion = $this->promotionStore->read($server);
        $routeIdentity = $this->routeIdentity($event, $enrollment, $promotion);
        $proof = new ControlPlaneProxyRouteProof(
            canonicalHost: $enrollment->canonicalHost,
            publicScheme: $enrollment->publicScheme,
            appPort: $enrollment->appPort,
            expectedColor: $routeIdentity['member'],
            expectedGeneration: $routeIdentity['release_revision'],
            expectedBackendMember: $routeIdentity['member'],
            expectedBackendRevision: $routeIdentity['release_revision'],
            dynamicReplacementSha256: $routeIdentity['dynamic_sha256'],
            configurationAcknowledgement: $routeIdentity['configuration_acknowledgement'],
        );

        $execute = $remoteExecutor ?? static fn (string $command, Server $target): ?string => instant_remote_process(
            [$command],
            $target,
            timeout: 60,
            disableMultiplexing: true,
            retry: false,
        );
        $transcript = $execute($proof->shellCommand(), $server);
        if (! is_string($transcript)) {
            throw new RuntimeException('The control-plane runtime route attestation returned no transcript.');
        }
        $this->routeVerifier->handle($proof, $transcript);

        return $proof;
    }

    public function asCommand(Command $command): int
    {
        try {
            $eventPath = $command->argument('event_path');
            if (! is_string($eventPath) || $eventPath === '') {
                throw new InvalidArgumentException('The control-plane attestation event path is invalid.');
            }
            $this->handle($this->decodeEvent($this->readEvent($eventPath)));
            $command->info('Control-plane Traefik runtime attestation passed.');

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $command->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function decodeEvent(string $bytes): array
    {
        try {
            $event = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The control-plane attestation event is not valid JSON.', previous: $exception);
        }
        if (! is_array($event)) {
            throw new InvalidArgumentException('The control-plane attestation event must be a JSON object.');
        }

        return $event;
    }

    private function readEvent(string $path): string
    {
        if ($path === '-') {
            $bytes = stream_get_contents(STDIN, self::MAXIMUM_EVENT_BYTES + 1);
        } else {
            if (! str_starts_with($path, '/') || is_link($path) || ! is_file($path)) {
                throw new InvalidArgumentException('The control-plane attestation event must be an absolute regular non-symlink file.');
            }
            $size = filesize($path);
            if (! is_int($size) || $size < 1 || $size > self::MAXIMUM_EVENT_BYTES) {
                throw new InvalidArgumentException('The control-plane attestation event exceeds its bounded size.');
            }
            $bytes = file_get_contents($path);
        }
        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAXIMUM_EVENT_BYTES) {
            throw new InvalidArgumentException('The control-plane attestation event exceeds its bounded size.');
        }

        return $bytes;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function validateEvent(array $event): void
    {
        $expectedKeys = [
            'version', 'event_id', 'reason', 'observed_at', 'expires_at', 'server_id',
            'operation_id', 'dynamic_revision', 'dynamic_sha256',
        ];
        if (array_keys($event) !== $expectedKeys || $event['version'] !== 1) {
            throw new InvalidArgumentException('The control-plane attestation event schema is invalid.');
        }
        if (! is_string($event['reason']) || ! in_array($event['reason'], ['managed-state-invalid', 'provider-health-drift'], true)) {
            throw new InvalidArgumentException('The control-plane attestation reason is invalid.');
        }
        if (! is_int($event['server_id']) || $event['server_id'] < 0
            || ! is_int($event['dynamic_revision']) || $event['dynamic_revision'] < 1) {
            throw new InvalidArgumentException('The control-plane attestation server or dynamic revision is invalid.');
        }
        if (! is_string($event['operation_id'])
            || preg_match('/\A[a-z0-9][a-z0-9._-]{0,127}\z/D', $event['operation_id']) !== 1
            || ! is_string($event['dynamic_sha256'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $event['dynamic_sha256']) !== 1) {
            throw new InvalidArgumentException('The control-plane attestation managed-document identity is invalid.');
        }
        $expectedEventId = hash('sha256', implode("\0", [
            $event['reason'],
            $event['operation_id'],
            (string) $event['dynamic_revision'],
            $event['dynamic_sha256'],
        ])."\n");
        if (! is_string($event['event_id']) || ! hash_equals($expectedEventId, $event['event_id'])) {
            throw new InvalidArgumentException('The control-plane attestation event ID is invalid.');
        }

        $observedAt = $this->timestamp($event['observed_at'], 'observed');
        $expiresAt = $this->timestamp($event['expires_at'], 'expiry');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($expiresAt <= $observedAt || $expiresAt->getTimestamp() - $observedAt->getTimestamp() > 60
            || $now < $observedAt || $now > $expiresAt) {
            throw new InvalidArgumentException('The control-plane attestation event is stale or has an invalid validity window.');
        }
    }

    private function timestamp(mixed $value, string $label): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The control-plane attestation {$label} timestamp is invalid.");
        }
        $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if ($timestamp === false || $timestamp->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new InvalidArgumentException("The control-plane attestation {$label} timestamp is invalid.");
        }

        return $timestamp;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{dynamic_revision: int, dynamic_sha256: string, member: string, release_revision: string, configuration_acknowledgement: string}
     */
    private function routeIdentity(
        array $event,
        ControlPlaneProxyEnrollmentState $enrollment,
        ?ControlPlaneGenerationPromotionState $promotion,
    ): array {
        $enrolled = [
            'operation_id' => $enrollment->operationId,
            'dynamic_revision' => $enrollment->dynamicRevision,
            'dynamic_sha256' => hash('sha256', $enrollment->dynamicReplacementBytes),
            'member' => $enrollment->expectedMember,
            'release_revision' => $enrollment->expectedRevision,
            'configuration_acknowledgement' => $enrollment->configurationAcknowledgement,
        ];
        if ($promotion === null) {
            return $this->assertMatchingIdentity($event, $enrolled);
        }

        $predecessor = $promotion->predecessor;
        $successor = ['operation_id' => $promotion->operationId, ...$promotion->successor];
        $eligible = array_map(
            static fn (string $role): array => $role === 'predecessor' ? $predecessor : $successor,
            $this->eligibleIdentityRoles($promotion->phase),
        );
        foreach ($eligible as $identity) {
            if ($this->matchesIdentity($event, $identity)) {
                return $this->assertMatchingIdentity($event, $identity);
            }
        }

        throw new RuntimeException('The control-plane attestation event does not match a currently eligible route identity.');
    }

    /** @return list<'predecessor'|'successor'> */
    private function eligibleIdentityRoles(ControlPlaneGenerationPromotionPhase $phase): array
    {
        return match ($phase) {
            ControlPlaneGenerationPromotionPhase::Prepared,
            ControlPlaneGenerationPromotionPhase::CandidateProving,
            ControlPlaneGenerationPromotionPhase::CandidateProven,
            ControlPlaneGenerationPromotionPhase::Freezing,
            ControlPlaneGenerationPromotionPhase::Frozen,
            ControlPlaneGenerationPromotionPhase::Quiescing,
            ControlPlaneGenerationPromotionPhase::Quiesced,
            ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement,
            ControlPlaneGenerationPromotionPhase::RollbackUnfreezing,
            ControlPlaneGenerationPromotionPhase::RolledBack => ['predecessor'],
            ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement,
            ControlPlaneGenerationPromotionPhase::Draining,
            ControlPlaneGenerationPromotionPhase::Retiring,
            ControlPlaneGenerationPromotionPhase::WriterPromoting,
            ControlPlaneGenerationPromotionPhase::FenceReleasing,
            ControlPlaneGenerationPromotionPhase::Unfreezing,
            ControlPlaneGenerationPromotionPhase::Completed => ['successor'],
            ControlPlaneGenerationPromotionPhase::Switching,
            ControlPlaneGenerationPromotionPhase::RollingBack,
            ControlPlaneGenerationPromotionPhase::InterventionRequired => ['predecessor', 'successor'],
        };
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $identity
     */
    private function matchesIdentity(array $event, array $identity): bool
    {
        return $event['operation_id'] === $identity['operation_id']
            && $event['dynamic_revision'] === $identity['dynamic_revision']
            && hash_equals($event['dynamic_sha256'], $identity['dynamic_sha256']);
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $identity
     * @return array{dynamic_revision: int, dynamic_sha256: string, member: string, release_revision: string, configuration_acknowledgement: string}
     */
    private function assertMatchingIdentity(array $event, array $identity): array
    {
        if (! $this->matchesIdentity($event, $identity)) {
            throw new RuntimeException('The control-plane attestation event does not match the current managed route identity.');
        }

        return [
            'dynamic_revision' => $identity['dynamic_revision'],
            'dynamic_sha256' => $identity['dynamic_sha256'],
            'member' => $identity['member'],
            'release_revision' => $identity['release_revision'],
            'configuration_acknowledgement' => $identity['configuration_acknowledgement'],
        ];
    }
}
