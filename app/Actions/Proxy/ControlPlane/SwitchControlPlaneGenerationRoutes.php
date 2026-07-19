<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Models\Server;
use Closure;
use DateInterval;
use DateTimeImmutable;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class SwitchControlPlaneGenerationRoutes
{
    use AsAction;

    private const DRAIN_WINDOW_SECONDS = 600;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /** @var Closure(DateTimeImmutable): DateTimeImmutable */
    private readonly Closure $drainDeadline;

    public function __construct(
        private readonly StoreControlPlaneGenerationPromotionState $promotionStore,
        private readonly StoreControlPlaneProxyEnrollmentState $enrollmentStore,
        private readonly ManagedTraefikDocumentWriter $dynamicWriter,
        private readonly VerifyControlPlaneProxyRoutes $routeVerifier,
        ?Closure $clock = null,
        ?Closure $drainDeadline = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => now()->toImmutable();
        $this->drainDeadline = $drainDeadline ?? static fn (DateTimeImmutable $acknowledgedAt): DateTimeImmutable => $acknowledgedAt->add(
            new DateInterval('PT'.self::DRAIN_WINDOW_SECONDS.'S'),
        );
    }

    /** @param null|Closure(string): ?string $remoteExecutor */
    public function handle(
        Server $server,
        string $operationId,
        string $token,
        string $successorYaml,
        ?Closure $remoteExecutor = null,
    ): ControlPlaneGenerationPromotionState {
        $state = $this->ownedState($server, $operationId, $token);
        $this->assertSuccessorYaml($state, $successorYaml);
        if ($state->phase === ControlPlaneGenerationPromotionPhase::Draining) {
            return $state;
        }
        if (! in_array($state->phase, [
            ControlPlaneGenerationPromotionPhase::Quiesced,
            ControlPlaneGenerationPromotionPhase::Switching,
            ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement,
        ], true)) {
            throw new RuntimeException("Control-plane generation routes cannot switch from {$state->phase->value}.");
        }

        $enrollment = $this->enrolledRouteOwner($server, $state);
        $execute = $remoteExecutor ?? static fn (string $command): ?string => instant_remote_process(
            [$command],
            $server,
            timeout: 120,
            disableMultiplexing: true,
            retry: false,
        );

        if ($state->phase === ControlPlaneGenerationPromotionPhase::Quiesced) {
            $state = $this->promotionStore->transition(
                $server,
                $operationId,
                $token,
                ControlPlaneGenerationPromotionPhase::Quiesced,
                ControlPlaneGenerationPromotionPhase::Switching,
                $this->timestamp($this->now()),
            );
        }
        if ($state->phase === ControlPlaneGenerationPromotionPhase::Switching) {
            $this->assertExactOutput(
                $execute($this->dynamicWriter->writeCommandFor($this->dynamicMutation($server, $state, $successorYaml))),
                ManagedTraefikDocumentWriter::APPLIED_OUTPUT,
                'dynamic Traefik document',
            );
            $dynamicWrittenAt = $this->now();
            $state = $this->promotionStore->transition(
                $server,
                $operationId,
                $token,
                ControlPlaneGenerationPromotionPhase::Switching,
                ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement,
                $this->timestamp($dynamicWrittenAt),
                ['dynamic_written' => $this->successorObservation($state, $dynamicWrittenAt)],
            );
        }
        if ($state->phase === ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement) {
            $proof = $this->routeProof($enrollment, $state);
            $transcript = $execute($proof->shellCommand());
            if (! is_string($transcript)) {
                throw new RuntimeException('The control-plane successor route proof returned no transcript.');
            }
            $this->routeVerifier->handle($proof, $transcript);

            $acknowledgedAt = $this->now();
            $deadline = $this->boundedDrainDeadline($acknowledgedAt);
            $state = $this->promotionStore->transition(
                $server,
                $operationId,
                $token,
                ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement,
                ControlPlaneGenerationPromotionPhase::Draining,
                $this->timestamp($acknowledgedAt),
                [
                    'dual_route' => $this->successorObservation($state, $acknowledgedAt),
                    'draining' => [
                        'deadline_at' => $this->timestamp($deadline),
                        'stable_zero_observations' => [],
                    ],
                ],
            );
        }

        return $state;
    }

    private function ownedState(
        Server $server,
        string $operationId,
        string $token,
    ): ControlPlaneGenerationPromotionState {
        $state = $this->promotionStore->read($server)
            ?? throw new RuntimeException('The durable control-plane generation promotion state is missing.');
        if ($state->serverId !== (int) $server->getKey() || ! $state->isOwnedBy($operationId, $token)) {
            throw new RuntimeException('The durable control-plane generation promotion state is owned by another operation.');
        }

        return $state;
    }

    private function enrolledRouteOwner(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
    ): ControlPlaneProxyEnrollmentState {
        $enrollment = $this->enrollmentStore->read($server)
            ?? throw new RuntimeException('The permanent control-plane enrollment state is missing.');
        if ($enrollment->phase !== ControlPlaneProxyEnrollmentPhase::Enrolled
            || $state->serverId !== $enrollment->serverId
            || $state->managedFilename !== $enrollment->managedFilename
            || $state->predecessor['dynamic_revision'] < $enrollment->dynamicRevision
            || ($state->predecessor['dynamic_revision'] === $enrollment->dynamicRevision
                && ! $state->matchesEnrolledPredecessor($enrollment))) {
            throw new RuntimeException('The permanent control-plane enrollment predecessor is stale.');
        }

        return $enrollment;
    }

    private function assertSuccessorYaml(ControlPlaneGenerationPromotionState $state, string $successorYaml): void
    {
        if (! hash_equals($state->successor['dynamic_sha256'], hash('sha256', $successorYaml))) {
            throw new RuntimeException('The successor control-plane YAML checksum does not match its durable promotion state.');
        }
    }

    private function dynamicMutation(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
        string $successorYaml,
    ): ManagedTraefikDocumentMutation {
        $proxyPath = rtrim((string) $server->proxyPath(), '/');

        return new ManagedTraefikDocumentMutation(
            dynamicDirectory: $proxyPath.'/dynamic',
            stateDirectory: $proxyPath.'/.control-plane-managed-traefik',
            filename: $state->managedFilename,
            operationId: $state->operationId,
            revision: $state->successor['dynamic_revision'],
            expectedSha256: $state->predecessor['dynamic_sha256'],
            expectedOperationId: $state->predecessor['operation_id'],
            expectedRevision: $state->predecessor['dynamic_revision'],
            replacementBytes: $successorYaml,
        );
    }

    private function routeProof(
        ControlPlaneProxyEnrollmentState $enrollment,
        ControlPlaneGenerationPromotionState $state,
    ): ControlPlaneProxyRouteProof {
        return new ControlPlaneProxyRouteProof(
            canonicalHost: $enrollment->canonicalHost,
            publicScheme: $enrollment->publicScheme,
            appPort: $enrollment->appPort,
            expectedColor: $state->successor['member'],
            expectedGeneration: $state->successor['release_revision'],
            expectedBackendMember: $state->successor['member'],
            expectedBackendRevision: $state->successor['release_revision'],
            dynamicReplacementSha256: $state->successor['dynamic_sha256'],
            configurationAcknowledgement: $state->successor['configuration_acknowledgement'],
        );
    }

    /** @return array<string, int|string> */
    private function successorObservation(
        ControlPlaneGenerationPromotionState $state,
        DateTimeImmutable $observedAt,
    ): array {
        return [
            'operation_id' => $state->operationId,
            'dynamic_revision' => $state->successor['dynamic_revision'],
            'dynamic_sha256' => $state->successor['dynamic_sha256'],
            'configuration_acknowledgement' => $state->successor['configuration_acknowledgement'],
            'member' => $state->successor['member'],
            'release_revision' => $state->successor['release_revision'],
            'observed_at' => $this->timestamp($observedAt),
        ];
    }

    private function now(): DateTimeImmutable
    {
        $now = ($this->clock)();
        if (! $now instanceof DateTimeImmutable) {
            throw new RuntimeException('The control-plane generation route-switch clock is invalid.');
        }

        return $now;
    }

    private function boundedDrainDeadline(DateTimeImmutable $acknowledgedAt): DateTimeImmutable
    {
        $deadline = ($this->drainDeadline)($acknowledgedAt);
        if (! $deadline instanceof DateTimeImmutable) {
            throw new RuntimeException('The control-plane generation drain deadline is invalid.');
        }
        $maximumDeadline = $acknowledgedAt->add(new DateInterval('PT'.self::DRAIN_WINDOW_SECONDS.'S'));
        if ($deadline <= $acknowledgedAt || $deadline > $maximumDeadline) {
            throw new RuntimeException('The control-plane generation drain deadline is outside its bounded acknowledgement window.');
        }

        return $deadline;
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->format(DATE_ATOM);
    }

    private function assertExactOutput(?string $output, string $expected, string $operation): void
    {
        if (! is_string($output) || ! hash_equals($expected, trim($output))) {
            throw new RuntimeException("The control-plane {$operation} did not return its exact completion proof.");
        }
    }
}
