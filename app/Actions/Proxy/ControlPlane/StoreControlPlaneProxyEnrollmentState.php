<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class StoreControlPlaneProxyEnrollmentState
{
    use AsAction;

    public const STATE_KEY = 'control_plane_proxy_enrollment';

    public function reserve(Server $server, ControlPlaneProxyEnrollmentState $state, string $token): ControlPlaneProxyEnrollmentState
    {
        return DB::transaction(function () use ($server, $state, $token): ControlPlaneProxyEnrollmentState {
            $lockedServer = $this->lockServer($server);
            if ($state->serverId !== (int) $lockedServer->getKey() || ! $state->isOwnedBy($state->operationId, $token)) {
                throw new RuntimeException('The control-plane enrollment reservation does not match its server or token.');
            }

            $current = $this->readFrom($lockedServer);
            if ($current !== null) {
                if ($current->toArray() === $state->toArray() && $current->isOwnedBy($state->operationId, $token)) {
                    return $current;
                }
                if ($current->phase === ControlPlaneProxyEnrollmentPhase::RolledBack) {
                    $this->writeTo($lockedServer, $state);

                    return $state;
                }

                throw new RuntimeException('Another durable control-plane enrollment state already owns this server.');
            }

            $this->writeTo($lockedServer, $state);

            return $state;
        }, 3);
    }

    public function transition(
        Server $server,
        string $operationId,
        string $token,
        ControlPlaneProxyEnrollmentPhase $expectedPhase,
        ControlPlaneProxyEnrollmentPhase $nextPhase,
        string $timestamp,
    ): ControlPlaneProxyEnrollmentState {
        return DB::transaction(function () use ($server, $operationId, $token, $expectedPhase, $nextPhase, $timestamp): ControlPlaneProxyEnrollmentState {
            $lockedServer = $this->lockServer($server);
            $current = $this->readFrom($lockedServer)
                ?? throw new RuntimeException('The durable control-plane enrollment state is missing.');
            if (! $current->isOwnedBy($operationId, $token)) {
                throw new RuntimeException('The durable control-plane enrollment state is owned by another operation.');
            }
            if ($current->phase === $nextPhase) {
                return $current;
            }
            if ($current->phase !== $expectedPhase) {
                throw new RuntimeException('The durable control-plane enrollment phase changed concurrently.');
            }

            $next = $current->withPhase($nextPhase, $timestamp);
            $this->writeTo($lockedServer, $next);

            return $next;
        }, 3);
    }

    public function read(Server $server): ?ControlPlaneProxyEnrollmentState
    {
        $fresh = Server::query()->find($server->getKey());
        if ($fresh === null) {
            throw new RuntimeException('The control-plane enrollment server no longer exists.');
        }

        return $this->readFrom($fresh);
    }

    public function abortPrepared(
        Server $server,
        string $operationId,
        string $canonicalHost,
        string $expectedRevision,
        string $timestamp,
    ): ControlPlaneProxyEnrollmentState {
        return DB::transaction(function () use ($server, $operationId, $canonicalHost, $expectedRevision, $timestamp): ControlPlaneProxyEnrollmentState {
            $lockedServer = $this->lockServer($server);
            $current = $this->readFrom($lockedServer)
                ?? throw new RuntimeException('The durable control-plane enrollment state is missing.');
            if ($current->phase !== ControlPlaneProxyEnrollmentPhase::Prepared
                || ! hash_equals($current->operationId, $operationId)
                || ! hash_equals($current->canonicalHost, $canonicalHost)
                || ! hash_equals($current->expectedRevision, $expectedRevision)) {
                throw new RuntimeException('The prepared control-plane enrollment abort fence does not match the durable state.');
            }

            $rolledBack = $current
                ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, $timestamp)
                ->withPhase(ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement, $timestamp)
                ->withPhase(ControlPlaneProxyEnrollmentPhase::RolledBack, $timestamp);
            $this->writeTo($lockedServer, $rolledBack);

            return $rolledBack;
        }, 3);
    }

    private function lockServer(Server $server): Server
    {
        return Server::query()->whereKey($server->getKey())->lockForUpdate()->first()
            ?? throw new RuntimeException('The control-plane enrollment server no longer exists.');
    }

    private function readFrom(Server $server): ?ControlPlaneProxyEnrollmentState
    {
        $stored = $server->proxy->get(self::STATE_KEY);
        if ($stored === null) {
            return null;
        }
        if (! is_array($stored)) {
            throw new RuntimeException('The durable control-plane enrollment state is malformed.');
        }

        return ControlPlaneProxyEnrollmentState::fromArray($stored);
    }

    private function writeTo(Server $server, ControlPlaneProxyEnrollmentState $state): void
    {
        $server->proxy->set(self::STATE_KEY, $state->toArray());
        $server->save();
    }
}
