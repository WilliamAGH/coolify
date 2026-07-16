<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ControlPlaneMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PreflightController extends Controller
{
    private const DIRECT_PROBE_HEADER = 'X-Control-Plane-Probe';

    private const ROUTE_HEALTH_HEADER = 'X-Control-Plane-Route-Health';

    private const APPLIED_ACK_HEADER = 'X-Control-Plane-Applied-Config';

    private const POOL_ACK_HEADER = 'X-Control-Plane-Pool-Ack';

    private const MEMBER_HEADER = 'X-Control-Plane-Member';

    private const ROUTE_STATE_HEADER = 'X-Control-Plane-Route-State';

    private const ROUTE_DRAIN_EPOCH_HEADER = 'X-Control-Plane-Route-Drain-Epoch';

    private const SAFE_TOKEN_PATTERN = '/\A[A-Za-z0-9._:-]{16,128}\z/';

    public function version(): JsonResponse
    {
        $this->ensurePassive();

        return response()->json([
            'mode' => ControlPlaneMode::Passive->value,
            'version' => config('constants.coolify.version'),
        ]);
    }

    public function database(): JsonResponse
    {
        $this->ensurePassive();

        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();
            $readOnly = $driver === 'pgsql'
                && $connection->scalar(<<<'SQL'
                    select case when current_setting('transaction_read_only') = 'on'
                        and not role.rolsuper
                        and not role.rolcreatedb
                        and not role.rolcreaterole
                        and not role.rolreplication
                        and not role.rolbypassrls
                        and not has_database_privilege(current_user, current_database(), 'CREATE')
                        and not has_database_privilege(current_user, current_database(), 'TEMP')
                        and not exists (
                            select 1
                            from pg_auth_members membership
                            where membership.member = role.oid
                        )
                        and not exists (
                            select 1
                            from pg_namespace namespace
                            where left(namespace.nspname, 3) <> 'pg_'
                                and namespace.nspname <> 'information_schema'
                                and has_schema_privilege(current_user, namespace.oid, 'CREATE')
                        )
                        and not exists (
                            select 1
                            from pg_class relation
                            join pg_namespace namespace on namespace.oid = relation.relnamespace
                            where left(namespace.nspname, 3) <> 'pg_'
                                and namespace.nspname <> 'information_schema'
                                and relation.relkind in ('r', 'p', 'v', 'm', 'f')
                                and (
                                    has_table_privilege(current_user, relation.oid, 'INSERT')
                                    or has_table_privilege(current_user, relation.oid, 'UPDATE')
                                    or has_table_privilege(current_user, relation.oid, 'DELETE')
                                    or has_table_privilege(current_user, relation.oid, 'TRUNCATE')
                                    or has_table_privilege(current_user, relation.oid, 'REFERENCES')
                                    or has_table_privilege(current_user, relation.oid, 'TRIGGER')
                                    or has_any_column_privilege(current_user, relation.oid, 'INSERT')
                                    or has_any_column_privilege(current_user, relation.oid, 'UPDATE')
                                    or has_any_column_privilege(current_user, relation.oid, 'REFERENCES')
                                )
                        )
                        and not exists (
                            select 1
                            from pg_class sequence
                            join pg_namespace namespace on namespace.oid = sequence.relnamespace
                            where left(namespace.nspname, 3) <> 'pg_'
                                and namespace.nspname <> 'information_schema'
                                and sequence.relkind = 'S'
                                and (
                                    has_sequence_privilege(current_user, sequence.oid, 'USAGE')
                                    or has_sequence_privilege(current_user, sequence.oid, 'UPDATE')
                                )
                        )
                        and not exists (
                            select 1
                            from pg_proc routine
                            join pg_namespace namespace on namespace.oid = routine.pronamespace
                            where left(namespace.nspname, 3) <> 'pg_'
                                and namespace.nspname <> 'information_schema'
                                and routine.prosecdef
                                and has_function_privilege(current_user, routine.oid, 'EXECUTE')
                        ) then 'read-only' else 'writable' end
                    from pg_roles role
                    where role.rolname = current_user
                    SQL, [], false) === 'read-only';
        } catch (Throwable) {
            return response()->json([
                'status' => 'unavailable',
                'read_only' => false,
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json([
            'status' => $readOnly ? 'ok' : 'unavailable',
            'read_only' => $readOnly,
        ], $readOnly ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }

    public function directProbe(Request $request): IlluminateResponse
    {
        abort_unless(ControlPlaneMode::isActiveWebOnly(), Response::HTTP_NOT_FOUND);

        $expectedToken = $this->readControlPlaneSecret('direct_probe_token_path');
        $providedToken = $request->header(self::DIRECT_PROBE_HEADER);
        abort_unless(
            is_string($providedToken) && hash_equals($expectedToken, $providedToken),
            Response::HTTP_NOT_FOUND,
        );
        $this->ensureCandidateDependenciesReady();

        return response('', Response::HTTP_NO_CONTENT)->header(
            self::APPLIED_ACK_HEADER,
            $this->readControlPlaneSecret('applied_ack_path'),
        );
    }

    public function routeHealth(Request $request): IlluminateResponse
    {
        abort_unless(
            ControlPlaneMode::isActiveWebOnly() && $request->getQueryString() === null,
            Response::HTTP_NOT_FOUND,
        );

        $expectedToken = $this->readControlPlaneSecret('route_health_token_path');
        $providedToken = $request->header(self::ROUTE_HEALTH_HEADER);
        abort_unless(
            is_string($providedToken) && hash_equals($expectedToken, $providedToken),
            Response::HTTP_NOT_FOUND,
        );

        $poolIdentity = $this->poolIdentity();

        try {
            $webOwnershipProven = ControlPlaneMode::webOwnershipProven();
            $routeDrainEpoch = ControlPlaneMode::activeRouteDrainEpoch();
        } catch (\InvalidArgumentException) {
            abort(Response::HTTP_SERVICE_UNAVAILABLE);
        }

        abort_unless($webOwnershipProven, Response::HTTP_SERVICE_UNAVAILABLE);
        $appliedAcknowledgement = $this->readControlPlaneSecret('applied_ack_path');

        if ($routeDrainEpoch !== null) {
            return $this->routeIdentityResponse(
                Response::HTTP_SERVICE_UNAVAILABLE,
                $appliedAcknowledgement,
                $poolIdentity,
                $routeDrainEpoch,
            );
        }

        $this->ensureCandidateDependenciesReady();

        return $this->routeIdentityResponse(
            Response::HTTP_NO_CONTENT,
            $appliedAcknowledgement,
            $poolIdentity,
        );
    }

    /**
     * @param  array{acknowledgement: string, memberId: string}|null  $poolIdentity
     */
    private function routeIdentityResponse(
        int $status,
        string $appliedAcknowledgement,
        ?array $poolIdentity,
        ?string $routeDrainEpoch = null,
    ): IlluminateResponse {
        $response = response('', $status)->header(
            self::APPLIED_ACK_HEADER,
            $appliedAcknowledgement,
        );

        if ($poolIdentity === null) {
            return $response;
        }

        $response
            ->header(self::POOL_ACK_HEADER, $poolIdentity['acknowledgement'])
            ->header(self::MEMBER_HEADER, $poolIdentity['memberId']);

        if ($routeDrainEpoch !== null) {
            $response
                ->header(self::ROUTE_STATE_HEADER, 'draining')
                ->header(self::ROUTE_DRAIN_EPOCH_HEADER, $routeDrainEpoch);
        }

        return $response;
    }

    private function ensurePassive(): void
    {
        abort_unless(ControlPlaneMode::configured() === ControlPlaneMode::Passive, Response::HTTP_NOT_FOUND);
    }

    private function ensureCandidateDependenciesReady(): void
    {
        try {
            $databaseReady = DB::connection()->scalar('select 1', [], false);
            $redisReady = Redis::connection('default')->command('ping');
        } catch (Throwable) {
            abort(Response::HTTP_SERVICE_UNAVAILABLE);
        }

        abort_unless(
            in_array($databaseReady, [1, '1'], true)
                && in_array($redisReady, [true, 'PONG'], true),
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    private function readControlPlaneSecret(string $configurationKey): string
    {
        $path = config("control-plane.{$configurationKey}");
        abort_unless(
            is_string($path) && is_file($path) && ! is_link($path) && is_readable($path),
            Response::HTTP_SERVICE_UNAVAILABLE,
        );

        $bytes = file_get_contents($path);
        $secret = is_string($bytes) ? rtrim($bytes, "\r\n") : '';
        abort_unless(
            preg_match(self::SAFE_TOKEN_PATTERN, $secret) === 1,
            Response::HTTP_SERVICE_UNAVAILABLE,
        );

        return $secret;
    }

    /**
     * @return array{acknowledgement: string, memberId: string}|null
     */
    private function poolIdentity(): ?array
    {
        $poolAcknowledgementPath = config('control-plane.pool_ack_path');
        $memberId = config('control-plane.member_id');

        if ($poolAcknowledgementPath === null && $memberId === null) {
            return null;
        }

        abort_unless(
            is_string($poolAcknowledgementPath)
                && is_string($memberId)
                && preg_match(self::SAFE_TOKEN_PATTERN, $memberId) === 1,
            Response::HTTP_SERVICE_UNAVAILABLE,
        );

        return [
            'acknowledgement' => $this->readControlPlaneSecret('pool_ack_path'),
            'memberId' => $memberId,
        ];
    }
}
