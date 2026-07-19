<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class StoreControlPlaneGenerationPromotionState
{
    use AsAction;

    public const STATE_KEY = 'control_plane_generation_promotion';

    public function reserve(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
        string $token,
    ): ControlPlaneGenerationPromotionState {
        return DB::transaction(function () use ($server, $state, $token): ControlPlaneGenerationPromotionState {
            $lockedServer = $this->lockServer($server);
            if ($state->serverId !== (int) $lockedServer->getKey() || ! $state->isOwnedBy($state->operationId, $token)) {
                throw new RuntimeException('The control-plane generation promotion reservation does not match its server or token.');
            }

            $enrollment = $this->enrolledState($lockedServer);
            $current = $this->readFrom($lockedServer);
            if ($current === null) {
                if (! $state->matchesEnrolledPredecessor($enrollment)) {
                    throw new RuntimeException('The control-plane generation promotion predecessor is stale.');
                }
                $this->writeTo($lockedServer, $state);

                return $state;
            }
            if ($current->isOwnedBy($state->operationId, $token)) {
                if (! $current->sameReservationAs($state)) {
                    throw new RuntimeException('The control-plane generation promotion reservation does not match its existing owner.');
                }

                return $current;
            }
            if ($current->phase === ControlPlaneGenerationPromotionPhase::Completed) {
                if (! $state->matchesCompletedSuccessor($current)) {
                    throw new RuntimeException('The control-plane generation promotion predecessor is stale.');
                }
                $this->writeTo($lockedServer, $state);

                return $state;
            }
            if ($current->phase === ControlPlaneGenerationPromotionPhase::RolledBack) {
                if (! $state->matchesRolledBackPredecessor($current)) {
                    throw new RuntimeException('The control-plane generation promotion predecessor is stale.');
                }
                $this->writeTo($lockedServer, $state);

                return $state;
            }

            throw new RuntimeException('Another durable control-plane generation promotion already owns this server.');
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    public function transition(
        Server $server,
        string $operationId,
        string $token,
        ControlPlaneGenerationPromotionPhase $expectedPhase,
        ControlPlaneGenerationPromotionPhase $nextPhase,
        string $timestamp,
        array $updates = [],
    ): ControlPlaneGenerationPromotionState {
        return DB::transaction(function () use ($server, $operationId, $token, $expectedPhase, $nextPhase, $timestamp, $updates): ControlPlaneGenerationPromotionState {
            $lockedServer = $this->lockServer($server);
            $current = $this->readFrom($lockedServer)
                ?? throw new RuntimeException('The durable control-plane generation promotion state is missing.');
            if (! $current->isOwnedBy($operationId, $token)) {
                throw new RuntimeException('The durable control-plane generation promotion state is owned by another operation.');
            }
            if ($current->phase === $nextPhase) {
                if ($current->matchesUpdates($updates)) {
                    return $current;
                }

                throw new RuntimeException('The durable control-plane generation promotion evidence changed after it was recorded.');
            }
            if ($current->phase !== $expectedPhase) {
                throw new RuntimeException('The durable control-plane generation promotion phase changed concurrently.');
            }

            $next = $current->withPhase($nextPhase, $timestamp, $updates);
            $this->writeTo($lockedServer, $next);

            return $next;
        }, 3);
    }

    public function read(Server $server): ?ControlPlaneGenerationPromotionState
    {
        $fresh = Server::query()->find($server->getKey());
        if ($fresh === null) {
            throw new RuntimeException('The control-plane generation promotion server no longer exists.');
        }

        return $this->readFrom($fresh);
    }

    private function lockServer(Server $server): Server
    {
        return Server::query()->whereKey($server->getKey())->lockForUpdate()->first()
            ?? throw new RuntimeException('The control-plane generation promotion server no longer exists.');
    }

    private function enrolledState(Server $server): ControlPlaneProxyEnrollmentState
    {
        $stored = $server->proxy->get(StoreControlPlaneProxyEnrollmentState::STATE_KEY);
        if (! is_array($stored)) {
            throw new RuntimeException('The permanent control-plane enrollment state is missing or malformed.');
        }
        $enrollment = ControlPlaneProxyEnrollmentState::fromArray($stored);
        if ($enrollment->phase !== ControlPlaneProxyEnrollmentPhase::Enrolled) {
            throw new RuntimeException('The permanent control-plane enrollment is not enrolled.');
        }

        return $enrollment;
    }

    private function readFrom(Server $server): ?ControlPlaneGenerationPromotionState
    {
        $stored = $server->proxy->get(self::STATE_KEY);
        if ($stored === null) {
            return null;
        }
        if (! is_array($stored)) {
            throw new RuntimeException('The durable control-plane generation promotion state is malformed.');
        }

        return ControlPlaneGenerationPromotionState::fromArray($stored);
    }

    private function writeTo(Server $server, ControlPlaneGenerationPromotionState $state): void
    {
        $server->proxy->set(self::STATE_KEY, $state->toArray());
        $server->save();
    }
}
