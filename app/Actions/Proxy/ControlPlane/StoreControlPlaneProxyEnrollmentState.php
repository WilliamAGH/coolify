<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Actions\Proxy\SaveProxyConfiguration;
use App\Models\Server;
use Closure;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class StoreControlPlaneProxyEnrollmentState
{
    use AsAction;

    public const STATE_KEY = 'control_plane_proxy_enrollment';

    public const LF_REPAIR_PROVENANCE_KEY = 'control_plane_proxy_enrollment_dynamic_predecessor_lf_repair';

    private const LF_REPAIR_PROVENANCE_VERSION = 1;

    public static function operationLockName(int|string $serverId): string
    {
        return 'coolify:control-plane-proxy-enrollment:'.$serverId;
    }

    public function serializeOperation(Server $server, Closure $operation): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            return $operation($server);
        }
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Control-plane enrollment serialization requires PostgreSQL.');
        }

        $lockName = self::operationLockName($server->getKey());
        $connection->selectOne('select pg_advisory_lock(hashtextextended(?, 0))', [$lockName], false);

        try {
            $freshServer = Server::query()->useWritePdo()->find($server->getKey())
                ?? throw new RuntimeException('The control-plane enrollment server no longer exists.');

            return $operation($freshServer);
        } finally {
            $connection->selectOne('select pg_advisory_unlock(hashtextextended(?, 0))', [$lockName], false);
        }
    }

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

                throw new RuntimeException('Another durable control-plane enrollment state already owns this server.');
            }

            $this->assertNoGenerationPromotion($lockedServer);
            $lockedServer->proxy->set(self::LF_REPAIR_PROVENANCE_KEY, null);
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
            if ($nextPhase === ControlPlaneProxyEnrollmentPhase::RollingBack) {
                $this->assertNoGenerationPromotion($lockedServer);
            }

            $next = $current->withPhase($nextPhase, $timestamp);
            $lockedServer->proxy->set(self::LF_REPAIR_PROVENANCE_KEY, null);
            $this->writeTo($lockedServer, $next);

            return $next;
        }, 3);
    }

    public function repairDynamicPredecessorTerminalLfIfUnchanged(
        Server $server,
        ControlPlaneProxyEnrollmentState $expectedState,
        string $operationId,
        string $token,
    ): ControlPlaneProxyEnrollmentState {
        return DB::transaction(function () use (
            $server,
            $expectedState,
            $operationId,
            $token,
        ): ControlPlaneProxyEnrollmentState {
            $lockedServer = $this->lockServer($server);
            $current = $this->readFrom($lockedServer)
                ?? throw new RuntimeException('The durable control-plane enrollment state is missing.');
            if ($expectedState->serverId !== (int) $lockedServer->getKey()
                || ! $expectedState->isOwnedBy($operationId, $token)
                || ! $current->isOwnedBy($operationId, $token)) {
                throw new RuntimeException('The durable control-plane enrollment state is owned by another operation.');
            }

            $this->assertNoGenerationPromotion($lockedServer);
            if ($expectedState->phase === ControlPlaneProxyEnrollmentPhase::Activating
                && is_string($expectedState->dynamicPredecessorBytes)
                && $expectedState->dynamicPredecessorBytes !== ''
                && str_ends_with($expectedState->dynamicPredecessorBytes, "\n")
                && ! str_ends_with($expectedState->dynamicPredecessorBytes, "\n\n")) {
                if ($current->toArray() !== $expectedState->toArray()) {
                    throw new RuntimeException('The durable control-plane enrollment changed before its transport-LF repair completed.');
                }
                if (! $this->hasExactLfRepairProvenance($lockedServer, $current)) {
                    throw new RuntimeException('The durable control-plane enrollment transport-LF repair provenance is missing or stale.');
                }

                return $current;
            }

            $repairedState = $expectedState->withRepairedDynamicPredecessorTerminalLf();
            if ($current->toArray() === $repairedState->toArray()) {
                if (! $this->hasExactLfRepairProvenance($lockedServer, $repairedState)) {
                    throw new RuntimeException('The durable control-plane enrollment transport-LF repair provenance is missing or stale.');
                }

                return $current;
            }
            if ($current->toArray() !== $expectedState->toArray()) {
                throw new RuntimeException('The durable control-plane enrollment changed before its transport-LF repair completed.');
            }
            if ($lockedServer->proxy->get(self::LF_REPAIR_PROVENANCE_KEY) !== null) {
                throw new RuntimeException('The durable control-plane enrollment transport-LF repair provenance changed concurrently.');
            }

            $lockedServer->proxy->set(
                self::LF_REPAIR_PROVENANCE_KEY,
                $this->lfRepairProvenanceFor($repairedState),
            );
            $this->writeTo($lockedServer, $repairedState);

            return $repairedState;
        }, 3);
    }

    public function hasDynamicPredecessorTerminalLfRepairProvenance(
        Server $server,
        ControlPlaneProxyEnrollmentState $expectedState,
        string $operationId,
        string $token,
    ): bool {
        return DB::transaction(function () use ($server, $expectedState, $operationId, $token): bool {
            $lockedServer = $this->lockServer($server);
            if ($expectedState->serverId !== (int) $lockedServer->getKey()
                || ! $expectedState->isOwnedBy($operationId, $token)) {
                return false;
            }

            $current = $this->readFrom($lockedServer);
            if ($current === null
                || ! $current->isOwnedBy($operationId, $token)
                || $current->toArray() !== $expectedState->toArray()) {
                return false;
            }

            return $this->hasExactLfRepairProvenance($lockedServer, $current);
        }, 3);
    }

    public function read(Server $server): ?ControlPlaneProxyEnrollmentState
    {
        $fresh = Server::query()->useWritePdo()->find($server->getKey());
        if ($fresh === null) {
            throw new RuntimeException('The control-plane enrollment server no longer exists.');
        }

        return $this->readFrom($fresh);
    }

    public function assertRollbackAvailable(Server $server): void
    {
        $fresh = Server::query()->useWritePdo()->find($server->getKey())
            ?? throw new RuntimeException('The control-plane enrollment server no longer exists.');
        $this->assertNoGenerationPromotion($fresh);
    }

    public function clearRolledBackIfUnchanged(
        Server $server,
        ControlPlaneProxyEnrollmentState $expectedState,
    ): void {
        DB::transaction(function () use ($server, $expectedState): void {
            $lockedServer = $this->lockServer($server);
            $current = $this->readFrom($lockedServer)
                ?? throw new RuntimeException('The durable control-plane enrollment state is missing.');
            if ($current->phase !== ControlPlaneProxyEnrollmentPhase::RolledBack
                || $current->toArray() !== $expectedState->toArray()) {
                throw new RuntimeException('The rolled-back control-plane enrollment changed before reconciliation completed.');
            }
            $this->assertNoGenerationPromotion($lockedServer);
            $lockedServer->proxy->set(self::STATE_KEY, null);
            $lockedServer->proxy->set(self::LF_REPAIR_PROVENANCE_KEY, null);
            $lockedServer->save();
        }, 3);
    }

    public function abortUnchanged(
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
            if ($current->serverId !== (int) $lockedServer->getKey()
                || ! in_array($current->phase, [
                    ControlPlaneProxyEnrollmentPhase::Prepared,
                    ControlPlaneProxyEnrollmentPhase::Activating,
                ], true)
                || ! hash_equals($current->operationId, $operationId)
                || ! hash_equals($current->canonicalHost, $canonicalHost)
                || ! hash_equals($current->expectedRevision, $expectedRevision)) {
                throw new RuntimeException('The unchanged control-plane enrollment abort fence does not match the durable state.');
            }

            $rolledBack = $current
                ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, $timestamp)
                ->withPhase(ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement, $timestamp)
                ->withPhase(ControlPlaneProxyEnrollmentPhase::RolledBack, $timestamp);
            (new SaveProxyConfiguration)->persistDatabaseState($lockedServer, $current->staticPredecessorBytes);
            $lockedServer->proxy->set(self::LF_REPAIR_PROVENANCE_KEY, null);
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

    /** @return array<string, int|string> */
    private function lfRepairProvenanceFor(ControlPlaneProxyEnrollmentState $state): array
    {
        if ($state->phase !== ControlPlaneProxyEnrollmentPhase::Activating
            || $state->dynamicPredecessorBytes === null
            || $state->dynamicPredecessorBytes === ''
            || ! str_ends_with($state->dynamicPredecessorBytes, "\n")
            || str_ends_with($state->dynamicPredecessorBytes, "\n\n")) {
            throw new RuntimeException('The durable control-plane enrollment transport-LF repair provenance requires one exact activating predecessor.');
        }

        return [
            'version' => self::LF_REPAIR_PROVENANCE_VERSION,
            'phase' => ControlPlaneProxyEnrollmentPhase::Activating->value,
            'server_id' => $state->serverId,
            'operation_id' => $state->operationId,
            'token_sha256' => $state->tokenSha256,
            'dynamic_predecessor_sha256' => hash('sha256', $state->dynamicPredecessorBytes),
            'state_sha256' => $this->stateSha256($state),
        ];
    }

    private function hasExactLfRepairProvenance(
        Server $server,
        ControlPlaneProxyEnrollmentState $state,
    ): bool {
        try {
            $expected = $this->lfRepairProvenanceFor($state);
        } catch (RuntimeException) {
            return false;
        }

        $stored = $server->proxy->get(self::LF_REPAIR_PROVENANCE_KEY);
        if (! is_array($stored)) {
            return false;
        }
        $storedKeys = array_keys($stored);
        $expectedKeys = array_keys($expected);
        sort($storedKeys);
        sort($expectedKeys);
        if ($storedKeys !== $expectedKeys
            || $stored['version'] !== $expected['version']
            || $stored['server_id'] !== $expected['server_id']) {
            return false;
        }

        foreach ([
            'phase',
            'operation_id',
            'token_sha256',
            'dynamic_predecessor_sha256',
            'state_sha256',
        ] as $key) {
            if (! is_string($stored[$key]) || ! hash_equals($expected[$key], $stored[$key])) {
                return false;
            }
        }

        return true;
    }

    private function stateSha256(ControlPlaneProxyEnrollmentState $state): string
    {
        return hash(
            'sha256',
            json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    private function assertNoGenerationPromotion(Server $server): void
    {
        if ($server->proxy->get(StoreControlPlaneGenerationPromotionState::STATE_KEY) !== null) {
            throw new RuntimeException('Control-plane enrollment cannot change ownership while generation promotion state exists.');
        }
    }
}
