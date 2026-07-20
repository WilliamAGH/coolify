<?php

use App\Models\StandaloneDocker;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ADDITIONAL_DESTINATION_SERVER_UNIQUE = 'additional_destinations_application_server_unique';

    private const ADDITIONAL_DESTINATION_UNIQUE = 'additional_destinations_application_destination_unique';

    private const FLEET_INDEX = 'app_deployment_queues_blue_green_fleet_index';

    private const RESERVATION_PRIMARY = 'application_destination_reservations_primary';

    private const RESERVATION_SERVER_UNIQUE = 'application_destination_reservations_server_unique';

    /** @var list<string> */
    private const TRIGGERS = [
        'coolify_additional_destination_topology_insert',
        'coolify_additional_destination_topology_update',
        'coolify_additional_destination_topology_delete',
        'coolify_application_primary_destination_topology_insert',
        'coolify_application_primary_destination_topology_update',
        'coolify_application_primary_destination_topology_delete',
        'coolify_standalone_docker_destination_topology_update',
    ];

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("Unsupported database driver for blue-green multi-destination topology: {$driver}.");
        }

        if ($driver === 'pgsql' && DB::transactionLevel() === 0) {
            DB::transaction($this->runExpansion(...), attempts: 5);

            return;
        }

        $this->runExpansion();
    }

    public function down(): void
    {
        throw new RuntimeException('Control-plane expand migration is forward-only: 2026_07_20_000200_add_blue_green_multi_destination_topology.');
    }

    public function assertExactSchema(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("Unsupported database driver for blue-green multi-destination topology attestation: {$driver}.");
        }

        $fleetColumn = collect(Schema::getColumns('application_deployment_queues'))
            ->firstWhere('name', 'blue_green_fleet_deployment_uuid');
        if (! is_array($fleetColumn)
            || ($fleetColumn['nullable'] ?? null) !== true
            || ! in_array(strtolower((string) ($fleetColumn['type_name'] ?? '')), ['varchar', 'varchar2', 'character varying'], true)
            || ($fleetColumn['default'] ?? null) !== null) {
            throw new RuntimeException('Blue-green fleet ownership column does not match the authorized schema.');
        }
        $fleetStatusColumn = collect(Schema::getColumns('application_deployment_queues'))
            ->firstWhere('name', 'blue_green_fleet_status');
        if (! is_array($fleetStatusColumn)
            || ($fleetStatusColumn['nullable'] ?? null) !== true
            || ! in_array(strtolower((string) ($fleetStatusColumn['type_name'] ?? '')), ['varchar', 'varchar2', 'character varying'], true)
            || ($fleetStatusColumn['default'] ?? null) !== null) {
            throw new RuntimeException('Blue-green fleet status column does not match the authorized schema.');
        }

        if (! Schema::hasColumns('application_destination_reservations', [
            'application_id',
            'server_id',
            'standalone_docker_id',
            'is_primary',
        ])) {
            throw new RuntimeException('Application destination reservations do not match the authorized schema.');
        }
        $this->assertIndex(
            'application_destination_reservations',
            self::RESERVATION_PRIMARY,
            ['application_id', 'standalone_docker_id'],
            true,
            true,
        );
        $this->assertIndex(
            'application_destination_reservations',
            self::RESERVATION_SERVER_UNIQUE,
            ['application_id', 'server_id'],
            true,
        );

        $this->assertIndex(
            'additional_destinations',
            self::ADDITIONAL_DESTINATION_SERVER_UNIQUE,
            ['application_id', 'server_id'],
            true,
        );
        $this->assertIndex(
            'additional_destinations',
            self::ADDITIONAL_DESTINATION_UNIQUE,
            ['application_id', 'standalone_docker_id'],
            true,
        );
        $this->assertIndex(
            'application_deployment_queues',
            self::FLEET_INDEX,
            ['application_id', 'pull_request_id', 'blue_green_fleet_deployment_uuid', 'status'],
            false,
        );
        $this->assertTopologyTriggers($driver);
    }

    private function runExpansion(): void
    {
        $this->assertPrerequisites();
        $this->ensureDestinationReservationTable();
        $this->lockTopologyTablesForExpansion();
        $this->assertExistingTopologyIsValid();
        $this->addFleetOwnerColumn();
        $this->addTopologyIndexes();
        $this->rebuildDestinationReservations();
        $this->replaceTopologyTriggers();
        $this->assertExactSchema();
    }

    private function assertPrerequisites(): void
    {
        $tables = [
            'applications' => ['destination_id', 'destination_type'],
            'standalone_dockers' => ['server_id'],
            'additional_destinations' => ['application_id', 'server_id', 'standalone_docker_id'],
            'application_deployment_queues' => ['application_id', 'pull_request_id', 'status'],
        ];
        foreach ($tables as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                throw new RuntimeException("Blue-green multi-destination topology requires the authorized {$table} schema.");
            }
        }
    }

    private function lockTopologyTablesForExpansion(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            lock table
                applications,
                standalone_dockers,
                additional_destinations,
                application_deployment_queues,
                application_destination_reservations
            in access exclusive mode
            SQL);
    }

    private function assertExistingTopologyIsValid(): void
    {
        $hasInvalidDestinationServer = DB::table('additional_destinations as additional_destination')
            ->leftJoin('standalone_dockers as standalone_docker', 'standalone_docker.id', '=', 'additional_destination.standalone_docker_id')
            ->whereNull('standalone_docker.id')
            ->orWhereColumn('additional_destination.server_id', '!=', 'standalone_docker.server_id')
            ->exists();
        $hasDuplicateServer = DB::table('additional_destinations')
            ->selectRaw('application_id, server_id')
            ->groupBy('application_id', 'server_id')
            ->havingRaw('count(*) > 1')
            ->exists();
        $hasDuplicateDestination = DB::table('additional_destinations')
            ->selectRaw('application_id, standalone_docker_id')
            ->groupBy('application_id', 'standalone_docker_id')
            ->havingRaw('count(*) > 1')
            ->exists();
        $hasPrimaryServerCollision = DB::table('applications as application')
            ->join('standalone_dockers as primary_destination', 'primary_destination.id', '=', 'application.destination_id')
            ->join('additional_destinations as additional_destination', 'additional_destination.application_id', '=', 'application.id')
            ->where('application.destination_type', $this->standaloneDockerMorphClass())
            ->whereColumn('additional_destination.server_id', 'primary_destination.server_id')
            ->exists();
        if ($hasInvalidDestinationServer || $hasDuplicateServer || $hasDuplicateDestination || $hasPrimaryServerCollision) {
            throw new RuntimeException('Blue-green multi-destination topology cannot be enabled until existing application destinations satisfy one destination per server.');
        }
    }

    private function addFleetOwnerColumn(): void
    {
        $missingColumns = collect([
            'blue_green_fleet_deployment_uuid',
            'blue_green_fleet_status',
        ])->reject(fn (string $column): bool => Schema::hasColumn('application_deployment_queues', $column));
        if ($missingColumns->isNotEmpty()) {
            Schema::table('application_deployment_queues', function (Blueprint $table) use ($missingColumns): void {
                if ($missingColumns->contains('blue_green_fleet_deployment_uuid')) {
                    $table->string('blue_green_fleet_deployment_uuid')->nullable();
                }
                if ($missingColumns->contains('blue_green_fleet_status')) {
                    $table->string('blue_green_fleet_status')->nullable();
                }
            });
        }
    }

    private function ensureDestinationReservationTable(): void
    {
        if (! Schema::hasTable('application_destination_reservations')) {
            Schema::create('application_destination_reservations', function (Blueprint $table): void {
                $table->unsignedBigInteger('application_id');
                $table->unsignedBigInteger('server_id');
                $table->unsignedBigInteger('standalone_docker_id');
                $table->boolean('is_primary');
                $table->primary(
                    ['application_id', 'standalone_docker_id'],
                    self::RESERVATION_PRIMARY,
                );
                $table->unique(
                    ['application_id', 'server_id'],
                    self::RESERVATION_SERVER_UNIQUE,
                );
                $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
                $table->foreign('server_id')->references('id')->on('servers');
                $table->foreign('standalone_docker_id')->references('id')->on('standalone_dockers')->cascadeOnDelete();
            });
        }

    }

    private function rebuildDestinationReservations(): void
    {
        DB::table('application_destination_reservations')->delete();
        $morphClass = $this->standaloneDockerMorphClass();
        DB::statement(<<<SQL
            insert into application_destination_reservations
                (application_id, server_id, standalone_docker_id, is_primary)
            select application.id, destination.server_id, destination.id, true
            from applications as application
            join standalone_dockers as destination on destination.id = application.destination_id
            where application.destination_type = '{$morphClass}'
            SQL);
        DB::statement(<<<'SQL'
            insert into application_destination_reservations
                (application_id, server_id, standalone_docker_id, is_primary)
            select application_id, server_id, standalone_docker_id, false
            from additional_destinations
            SQL);
    }

    private function addTopologyIndexes(): void
    {
        if (! $this->hasIndex('additional_destinations', self::ADDITIONAL_DESTINATION_SERVER_UNIQUE)) {
            Schema::table('additional_destinations', function (Blueprint $table): void {
                $table->unique(['application_id', 'server_id'], self::ADDITIONAL_DESTINATION_SERVER_UNIQUE);
            });
        }
        if (! $this->hasIndex('additional_destinations', self::ADDITIONAL_DESTINATION_UNIQUE)) {
            Schema::table('additional_destinations', function (Blueprint $table): void {
                $table->unique(['application_id', 'standalone_docker_id'], self::ADDITIONAL_DESTINATION_UNIQUE);
            });
        }
        if (! $this->hasIndex('application_deployment_queues', self::FLEET_INDEX)) {
            Schema::table('application_deployment_queues', function (Blueprint $table): void {
                $table->index(
                    ['application_id', 'pull_request_id', 'blue_green_fleet_deployment_uuid', 'status'],
                    self::FLEET_INDEX,
                );
            });
        }
    }

    private function replaceTopologyTriggers(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $this->replacePostgresReservationTriggers();

            return;
        }

        $this->replaceSqliteReservationTriggers();
    }

    private function replacePostgresReservationTriggers(): void
    {
        $morphClass = $this->standaloneDockerMorphClass();
        foreach (self::TRIGGERS as $trigger) {
            $table = match ($trigger) {
                'coolify_additional_destination_topology_insert',
                'coolify_additional_destination_topology_update',
                'coolify_additional_destination_topology_delete' => 'additional_destinations',
                'coolify_application_primary_destination_topology_insert',
                'coolify_application_primary_destination_topology_update',
                'coolify_application_primary_destination_topology_delete' => 'applications',
                default => 'standalone_dockers',
            };
            DB::statement("drop trigger if exists {$trigger} on {$table}");
        }
        DB::statement('drop function if exists coolify_enforce_additional_destination_topology()');
        DB::statement('drop function if exists coolify_enforce_application_primary_destination_topology()');
        DB::statement('drop function if exists coolify_enforce_standalone_docker_destination_topology()');

        DB::unprepared(<<<'SQL'
            create or replace function coolify_reserve_additional_destination_topology()
            returns trigger
            language plpgsql
            as $$
            declare exact_server_id bigint;
            begin
                if tg_op = 'DELETE' then
                    delete from application_destination_reservations
                    where application_id = old.application_id
                      and standalone_docker_id = old.standalone_docker_id
                      and is_primary = false;
                    return old;
                end if;

                perform 1 from applications where id = new.application_id for update;
                select server_id into exact_server_id
                from standalone_dockers
                where id = new.standalone_docker_id
                for key share;
                if exact_server_id is null or exact_server_id <> new.server_id then
                    raise exception 'An additional destination must belong to its exact server.';
                end if;
                if tg_op = 'UPDATE' then
                    delete from application_destination_reservations
                    where application_id = old.application_id
                      and standalone_docker_id = old.standalone_docker_id
                      and is_primary = false;
                end if;
                if exists (
                    select 1 from application_destination_reservations
                    where application_id = new.application_id
                      and server_id = new.server_id
                ) then
                    raise exception 'An application can use only one destination per server.';
                end if;
                insert into application_destination_reservations
                    (application_id, server_id, standalone_docker_id, is_primary)
                values (new.application_id, new.server_id, new.standalone_docker_id, false);
                return new;
            end;
            $$;
            SQL);
        DB::unprepared(<<<SQL
            create or replace function coolify_reserve_application_primary_destination_topology()
            returns trigger
            language plpgsql
            as $$
            declare exact_server_id bigint;
            begin
                if tg_op = 'DELETE' then
                    delete from application_destination_reservations
                    where application_id = old.id and is_primary = true;
                    return old;
                end if;
                if tg_op = 'UPDATE' then
                    delete from application_destination_reservations
                    where application_id = old.id and is_primary = true;
                end if;
                if new.destination_type = '{$morphClass}' and new.destination_id is not null then
                    select server_id into exact_server_id
                    from standalone_dockers
                    where id = new.destination_id
                    for key share;
                    if exact_server_id is null then
                        raise exception 'An application primary destination must exist.';
                    end if;
                    if exists (
                        select 1 from application_destination_reservations
                        where application_id = new.id
                          and server_id = exact_server_id
                    ) then
                        raise exception 'An application can use only one destination per server.';
                    end if;
                    insert into application_destination_reservations
                        (application_id, server_id, standalone_docker_id, is_primary)
                    values (new.id, exact_server_id, new.destination_id, true);
                end if;
                return new;
            end;
            $$;
            SQL);
        DB::unprepared(<<<'SQL'
            create or replace function coolify_fence_reserved_destination_server()
            returns trigger
            language plpgsql
            as $$
            begin
                if old.server_id <> new.server_id
                   and exists (
                       select 1 from application_destination_reservations
                       where standalone_docker_id = new.id
                   ) then
                    raise exception 'A reserved application destination cannot move to another server.';
                end if;
                return new;
            end;
            $$;
            SQL);

        DB::unprepared('create trigger coolify_additional_destination_topology_insert after insert on additional_destinations for each row execute function coolify_reserve_additional_destination_topology()');
        DB::unprepared('create trigger coolify_additional_destination_topology_update after update of application_id, server_id, standalone_docker_id on additional_destinations for each row execute function coolify_reserve_additional_destination_topology()');
        DB::unprepared('create trigger coolify_additional_destination_topology_delete after delete on additional_destinations for each row execute function coolify_reserve_additional_destination_topology()');
        DB::unprepared('create trigger coolify_application_primary_destination_topology_insert after insert on applications for each row execute function coolify_reserve_application_primary_destination_topology()');
        DB::unprepared('create trigger coolify_application_primary_destination_topology_update after update of destination_id, destination_type on applications for each row execute function coolify_reserve_application_primary_destination_topology()');
        DB::unprepared('create trigger coolify_application_primary_destination_topology_delete after delete on applications for each row execute function coolify_reserve_application_primary_destination_topology()');
        DB::unprepared('create trigger coolify_standalone_docker_destination_topology_update before update of server_id on standalone_dockers for each row execute function coolify_fence_reserved_destination_server()');
    }

    private function replaceSqliteReservationTriggers(): void
    {
        $morphClass = $this->standaloneDockerMorphClass();
        foreach (self::TRIGGERS as $trigger) {
            DB::statement("drop trigger if exists {$trigger}");
        }

        $exactDestinationGuard = <<<'SQL'
            select case when not exists (
                select 1 from standalone_dockers
                where id = new.standalone_docker_id and server_id = new.server_id
            ) then raise(abort, 'An additional destination must belong to its exact server.') end;
            SQL;
        $serverReservationGuard = <<<'SQL'
            select case when exists (
                select 1 from application_destination_reservations
                where application_id = new.application_id and server_id = new.server_id
            ) then raise(abort, 'An application can use only one destination per server.') end;
            SQL;
        DB::unprepared("create trigger coolify_additional_destination_topology_insert after insert on additional_destinations for each row begin {$exactDestinationGuard} {$serverReservationGuard} insert into application_destination_reservations (application_id, server_id, standalone_docker_id, is_primary) values (new.application_id, new.server_id, new.standalone_docker_id, false); end");
        DB::unprepared("create trigger coolify_additional_destination_topology_update after update of application_id, server_id, standalone_docker_id on additional_destinations for each row begin {$exactDestinationGuard} delete from application_destination_reservations where application_id = old.application_id and standalone_docker_id = old.standalone_docker_id and is_primary = false; {$serverReservationGuard} insert into application_destination_reservations (application_id, server_id, standalone_docker_id, is_primary) values (new.application_id, new.server_id, new.standalone_docker_id, false); end");
        DB::unprepared('create trigger coolify_additional_destination_topology_delete after delete on additional_destinations for each row begin delete from application_destination_reservations where application_id = old.application_id and standalone_docker_id = old.standalone_docker_id and is_primary = false; end');
        DB::unprepared("create trigger coolify_application_primary_destination_topology_insert after insert on applications for each row when new.destination_type = '{$morphClass}' and new.destination_id is not null begin select case when exists (select 1 from application_destination_reservations as reservation join standalone_dockers as destination on destination.id = new.destination_id where reservation.application_id = new.id and reservation.server_id = destination.server_id) then raise(abort, 'An application can use only one destination per server.') end; insert into application_destination_reservations (application_id, server_id, standalone_docker_id, is_primary) select new.id, server_id, id, true from standalone_dockers where id = new.destination_id; select case when changes() <> 1 then raise(abort, 'An application primary destination must exist.') end; end");
        DB::unprepared("create trigger coolify_application_primary_destination_topology_update after update of destination_id, destination_type on applications for each row begin delete from application_destination_reservations where application_id = old.id and is_primary = true; select case when new.destination_type = '{$morphClass}' and new.destination_id is not null and exists (select 1 from application_destination_reservations as reservation join standalone_dockers as destination on destination.id = new.destination_id where reservation.application_id = new.id and reservation.server_id = destination.server_id) then raise(abort, 'An application can use only one destination per server.') end; insert into application_destination_reservations (application_id, server_id, standalone_docker_id, is_primary) select new.id, server_id, id, true from standalone_dockers where id = new.destination_id and new.destination_type = '{$morphClass}'; select case when new.destination_type = '{$morphClass}' and new.destination_id is not null and changes() <> 1 then raise(abort, 'An application primary destination must exist.') end; end");
        DB::unprepared('create trigger coolify_application_primary_destination_topology_delete after delete on applications for each row begin delete from application_destination_reservations where application_id = old.id; end');
        DB::unprepared("create trigger coolify_standalone_docker_destination_topology_update before update of server_id on standalone_dockers for each row when old.server_id <> new.server_id and exists (select 1 from application_destination_reservations where standalone_docker_id = new.id) begin select raise(abort, 'A reserved application destination cannot move to another server.'); end");
    }

    private function standaloneDockerMorphClass(): string
    {
        return str_replace("'", "''", (new StandaloneDocker)->getMorphClass());
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            static fn (array $index): bool => ($index['name'] ?? null) === $name,
        );
    }

    /** @param list<string> $columns */
    private function assertIndex(string $table, string $name, array $columns, bool $unique, bool $primary = false): void
    {
        $indexes = collect(Schema::getIndexes($table));
        $index = $primary
            ? $indexes->first(static fn (array $candidate): bool => ($candidate['primary'] ?? null) === true)
            : $indexes->firstWhere('name', $name);
        if (! is_array($index)
            || ($index['columns'] ?? null) !== $columns
            || ($index['unique'] ?? null) !== $unique
            || ($index['primary'] ?? null) !== $primary) {
            throw new RuntimeException("Blue-green multi-destination topology index does not match the authorized schema: {$name}.");
        }
    }

    private function assertTopologyTriggers(string $driver): void
    {
        if ($driver === 'pgsql') {
            $triggerNames = collect(DB::select(<<<'SQL'
                select trigger_record.tgname as name
                from pg_trigger as trigger_record
                join pg_class as relation on relation.oid = trigger_record.tgrelid
                join pg_namespace as namespace on namespace.oid = relation.relnamespace
                where namespace.nspname = current_schema()
                  and not trigger_record.tgisinternal
                  and trigger_record.tgname in (
                      'coolify_additional_destination_topology_insert',
                      'coolify_additional_destination_topology_update',
                      'coolify_additional_destination_topology_delete',
                      'coolify_application_primary_destination_topology_insert',
                      'coolify_application_primary_destination_topology_update',
                      'coolify_application_primary_destination_topology_delete',
                      'coolify_standalone_docker_destination_topology_update'
                  )
                order by trigger_record.tgname
                SQL))->pluck('name')->all();
        } else {
            $triggerNames = collect(DB::select(<<<'SQL'
                select name
                from sqlite_master
                where type = 'trigger'
                  and name in (
                      'coolify_additional_destination_topology_insert',
                      'coolify_additional_destination_topology_update',
                      'coolify_additional_destination_topology_delete',
                      'coolify_application_primary_destination_topology_insert',
                      'coolify_application_primary_destination_topology_update',
                      'coolify_application_primary_destination_topology_delete',
                      'coolify_standalone_docker_destination_topology_update'
                  )
                order by name
                SQL))->pluck('name')->all();
        }

        $expected = self::TRIGGERS;
        sort($expected, SORT_STRING);
        if ($triggerNames !== $expected) {
            throw new RuntimeException('Blue-green multi-destination topology triggers do not match the authorized schema.');
        }
    }
};
