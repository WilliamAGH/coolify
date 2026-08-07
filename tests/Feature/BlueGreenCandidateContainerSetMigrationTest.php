<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function finalBlueGreenLedgerMigration(): Migration
{
    return require database_path('migrations/2026_08_06_000000_add_destination_routing_topology_digest.php');
}

function historicalCandidateContainerSetMigration(): Migration
{
    return require database_path('migrations/2026_08_04_044304_add_blue_green_candidate_container_set.php');
}

/** @return list<string> */
function candidateContainerSetPrerequisiteMigrations(): array
{
    return [
        '2026_07_12_000000_add_blue_green_deployment_setting_to_application_settings',
        '2026_07_12_000001_create_application_blue_green_deployments_table',
        '2026_07_12_000002_add_blue_green_provenance_to_application_deployment_queues',
        '2026_07_12_000010_add_blue_green_deactivation_provenance',
        '2026_07_12_000011_create_application_blue_green_deactivations_table',
        '2026_07_12_000012_add_blue_green_deactivation_proxy_snapshot',
        '2026_07_19_025448_add_destination_fencing_to_blue_green_operations',
        '2026_07_19_025449_add_blue_green_supersession_generation',
        '2026_07_19_030000_add_blue_green_drain_provenance',
        '2026_07_19_064536_add_blue_green_recovery_predecessor_state',
        '2026_07_19_120000_add_blue_green_inactive_retention_setting',
        '2026_07_19_120001_add_blue_green_inactive_retirement_provenance',
        '2026_07_20_000000_add_blue_green_backend_port_inventories_to_deployment_queues',
        '2026_07_20_000100_add_blue_green_intervention_reasons',
        '2026_07_20_000200_add_blue_green_multi_destination_topology',
        '2026_07_20_211522_create_application_blue_green_replicas_table',
    ];
}

/** @return list<string> */
function candidateContainerSetReleaseKeyColumns(): array
{
    $index = collect(Schema::getIndexes('application_blue_green_replicas'))
        ->firstWhere('name', 'application_blue_green_replica_release_unique');

    expect($index)->toBeArray();

    return array_values($index['columns']);
}

function addCandidateContainerSetColumn(string $type = 'text'): void
{
    Schema::table('application_blue_green_deployments', function (Blueprint $table) use ($type): void {
        match ($type) {
            'text' => $table->text('operation_candidate_container_set')->nullable(),
            'integer' => $table->integer('operation_candidate_container_set')->nullable(),
        };
    });
}

function addRoutingTopologyDigestColumn(string $type = 'string'): void
{
    Schema::table('application_blue_green_deployments', function (Blueprint $table) use ($type): void {
        match ($type) {
            'string' => $table->string('destination_routing_topology_digest', 64)->nullable(),
            'integer' => $table->integer('destination_routing_topology_digest')->nullable(),
        };
    });
}

/** @param list<string> $columns */
function replaceCandidateContainerSetReleaseKey(array $columns): void
{
    Schema::table('application_blue_green_replicas', function (Blueprint $table): void {
        $table->dropUnique('application_blue_green_replica_release_unique');
    });
    Schema::table('application_blue_green_replicas', function (Blueprint $table) use ($columns): void {
        $table->unique($columns, 'application_blue_green_replica_release_unique');
    });
}

/** @param array<string, mixed> $state */
function insertCandidateContainerSetDeployment(array $state = []): void
{
    DB::table('servers')->insertOrIgnore(['id' => 1]);
    DB::table('standalone_dockers')->insertOrIgnore(['id' => 1, 'server_id' => 1]);
    DB::table('applications')->insertOrIgnore([
        'id' => 1,
        'destination_id' => 1,
        'destination_type' => 'App\\Models\\StandaloneDocker',
    ]);

    DB::table('application_blue_green_deployments')->insert([
        'application_id' => 1,
        'standalone_docker_id' => 1,
        'phase' => 'idle',
        ...$state,
    ]);
}

/** @return array<string, int|string> */
function dirtyCandidateContainerSetOperationAttributes(): array
{
    return [
        'operation_deployment_uuid' => 'deployment-uuid',
        'operation_previous_active_color' => 'blue',
        'operation_previous_deployment_uuid' => 'previous-deployment-uuid',
        'operation_previous_routing_revision' => 1,
        'operation_previous_container_name' => 'previous-container',
        'operation_previous_container_id' => 'previous-container-id',
        'operation_candidate_container_name' => 'candidate-container',
        'operation_candidate_container_id' => 'candidate-container-id',
        'operation_rollback_managed_filename' => 'rollback.yaml',
        'operation_routing_mutated_at' => '2026-08-07 00:00:00',
        'operation_legacy_routing_snapshot_version' => 1,
        'operation_legacy_routing_snapshot' => '{}',
        'operation_legacy_routing_snapshot_sha256' => str_repeat('a', 64),
        'operation_drain_started_at' => '2026-08-07 00:00:00',
        'operation_drain_deadline_at' => '2026-08-07 00:01:00',
        'operation_drain_last_observed_connections' => 1,
        'operation_drain_observed_at' => '2026-08-07 00:00:30',
        'operation_destination_fence_epoch' => 1,
        'operation_previous_destination_fence_epoch' => 1,
        'operation_server_boot_id' => '11111111-1111-1111-1111-111111111111',
        'operation_topology_digest' => str_repeat('b', 64),
        'operation_routing_config_digest' => str_repeat('c', 64),
        'operation_previous_managed_file_sha256' => str_repeat('d', 64),
        'operation_previous_proxy_state' => '{}',
        'operation_previous_proxy_state_sha256' => str_repeat('e', 64),
        'operation_rollback_proxy_state' => '{}',
        'operation_rollback_proxy_state_sha256' => str_repeat('f', 64),
    ];
}

beforeEach(function (): void {
    Schema::dropAllTables();

    Schema::create('applications', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('destination_id')->nullable();
        $table->string('destination_type')->nullable();
    });
    Schema::create('servers', fn (Blueprint $table) => $table->id());
    Schema::create('standalone_dockers', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('server_id');
    });
    Schema::create('additional_destinations', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('application_id');
        $table->unsignedBigInteger('server_id');
        $table->unsignedBigInteger('standalone_docker_id');
    });
    Schema::create('application_settings', fn (Blueprint $table) => $table->id());
    Schema::create('application_deployment_queues', function (Blueprint $table): void {
        $table->id();
        $table->string('application_id');
        $table->integer('pull_request_id')->default(0);
        $table->string('status');
    });

    foreach (candidateContainerSetPrerequisiteMigrations() as $migrationName) {
        (require database_path("migrations/{$migrationName}.php"))->up();
    }
});

afterEach(function (): void {
    if (Schema::getConnection()->getDriverName() === 'pgsql') {
        Artisan::call('migrate:install', ['--no-interaction' => true]);
        Artisan::call('migrate:fresh', ['--force' => true]);
    }
});

it('refuses to take ownership from the released candidate-container-set migration', function (): void {
    $migration = finalBlueGreenLedgerMigration();

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'candidate container set');

    expect(Schema::hasColumn('application_blue_green_deployments', 'operation_candidate_container_set'))->toBeFalse()
        ->and(Schema::hasColumn('application_blue_green_deployments', 'destination_routing_topology_digest'))->toBeFalse()
        ->and(candidateContainerSetReleaseKeyColumns())
        ->toBe(['deployment_uuid', 'replica_index']);
});

it('accepts the exact historical candidate expansion and adds only the final digest', function (): void {
    historicalCandidateContainerSetMigration()->up();

    finalBlueGreenLedgerMigration()->up();

    expect(Schema::hasColumn('application_blue_green_deployments', 'destination_routing_topology_digest'))->toBeTrue()
        ->and(candidateContainerSetReleaseKeyColumns())
        ->toBe(['deployment_uuid', 'compose_service', 'replica_index']);
});

it('makes an exact target replay a catalog no-op on SQLite', function (): void {
    if (Schema::getConnection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite schema SQL provides the deterministic no-op witness.');
    }
    historicalCandidateContainerSetMigration()->up();
    $migration = finalBlueGreenLedgerMigration();
    $migration->up();
    $before = DB::select(<<<'SQL'
        select type, name, tbl_name, sql
        from sqlite_schema
        where tbl_name in ('application_blue_green_deployments', 'application_blue_green_replicas')
        order by type, name
        SQL);

    $migration->up();

    expect(DB::select(<<<'SQL'
        select type, name, tbl_name, sql
        from sqlite_schema
        where tbl_name in ('application_blue_green_deployments', 'application_blue_green_replicas')
        order by type, name
        SQL))->toEqual($before);
});

it('rejects a malformed candidate column before any transition DDL', function (): void {
    addCandidateContainerSetColumn('integer');

    expect(fn () => finalBlueGreenLedgerMigration()->up())
        ->toThrow(RuntimeException::class, 'candidate container set');

    expect(Schema::hasColumn('application_blue_green_deployments', 'destination_routing_topology_digest'))->toBeFalse()
        ->and(candidateContainerSetReleaseKeyColumns())->toBe(['deployment_uuid', 'replica_index']);
});

it('rejects a malformed digest column before candidate or release-key DDL', function (): void {
    addRoutingTopologyDigestColumn('integer');

    expect(fn () => finalBlueGreenLedgerMigration()->up())
        ->toThrow(RuntimeException::class, 'routing topology digest');

    expect(Schema::hasColumn('application_blue_green_deployments', 'operation_candidate_container_set'))->toBeFalse()
        ->and(candidateContainerSetReleaseKeyColumns())->toBe(['deployment_uuid', 'replica_index']);
});

it('rejects a malformed release key before candidate or digest DDL', function (): void {
    addCandidateContainerSetColumn();
    replaceCandidateContainerSetReleaseKey(['compose_service', 'deployment_uuid', 'replica_index']);

    expect(fn () => finalBlueGreenLedgerMigration()->up())
        ->toThrow(RuntimeException::class, 'replica release key');

    expect(Schema::hasColumn('application_blue_green_deployments', 'operation_candidate_container_set'))->toBeTrue()
        ->and(Schema::hasColumn('application_blue_green_deployments', 'destination_routing_topology_digest'))->toBeFalse()
        ->and(candidateContainerSetReleaseKeyColumns())
        ->toBe(['compose_service', 'deployment_uuid', 'replica_index']);
});

it('does not take ownership of unrelated replica-ledger columns', function (): void {
    historicalCandidateContainerSetMigration()->up();
    Schema::table('application_blue_green_replicas', function (Blueprint $table): void {
        $table->string('decoy')->nullable();
    });

    finalBlueGreenLedgerMigration()->up();

    expect(Schema::hasColumn('application_blue_green_replicas', 'decoy'))->toBeTrue()
        ->and(Schema::hasColumn('application_blue_green_deployments', 'destination_routing_topology_digest'))->toBeTrue();
});

it('rejects an unsafe SQLite release index before any transition DDL', function (): void {
    if (Schema::getConnection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite index catalog semantics.');
    }
    historicalCandidateContainerSetMigration()->up();
    DB::statement('drop index application_blue_green_replica_release_unique');
    DB::statement(<<<'SQL'
        create unique index application_blue_green_replica_release_unique
        on application_blue_green_replicas (
            deployment_uuid collate nocase desc,
            compose_service,
            replica_index
        )
        where replica_index > 0
        SQL);

    expect(fn () => finalBlueGreenLedgerMigration()->up())
        ->toThrow(RuntimeException::class, 'replica release key');

    expect(Schema::hasColumn('application_blue_green_deployments', 'operation_candidate_container_set'))->toBeTrue()
        ->and(Schema::hasColumn('application_blue_green_deployments', 'destination_routing_topology_digest'))->toBeFalse();
});

it('does not take ownership of unrelated SQLite secondary indexes', function (): void {
    if (Schema::getConnection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite index catalog semantics.');
    }
    historicalCandidateContainerSetMigration()->up();
    DB::statement('drop index application_blue_green_replica_state_color_index');
    DB::statement(<<<'SQL'
        create index application_blue_green_replica_state_color_index
        on application_blue_green_replicas (color, application_blue_green_deployment_id)
        SQL);

    finalBlueGreenLedgerMigration()->up();

    expect(Schema::hasColumn('application_blue_green_deployments', 'destination_routing_topology_digest'))->toBeTrue();
});

it('adds the nullable digest without requiring lifecycle quiescence', function (string $phase): void {
    historicalCandidateContainerSetMigration()->up();
    insertCandidateContainerSetDeployment(['phase' => $phase]);
    $migration = finalBlueGreenLedgerMigration();

    $migration->up();

    expect(Schema::hasColumn('application_blue_green_deployments', 'destination_routing_topology_digest'))->toBeTrue()
        ->and(DB::table('application_blue_green_deployments')->value('phase'))->toBe($phase);
})->with([
    'idle' => ['idle'],
    'stopped' => ['stopped'],
    'preparing' => ['preparing'],
    'switching' => ['switching'],
    'draining' => ['draining'],
    'rolling back' => ['rolling_back'],
    'deactivating' => ['deactivating'],
    'intervention required' => ['intervention_required'],
]);

it('preserves every live operation owner while adding the nullable digest', function (string $attribute, int|string $value): void {
    historicalCandidateContainerSetMigration()->up();
    $state = [$attribute => $value];
    if ($attribute === 'operation_deployment_uuid') {
        $state['supersession_generation'] = 1;
    }
    insertCandidateContainerSetDeployment($state);

    finalBlueGreenLedgerMigration()->up();

    expect(Schema::hasColumn('application_blue_green_deployments', 'destination_routing_topology_digest'))->toBeTrue()
        ->and(DB::table('application_blue_green_deployments')->value($attribute))->toBe($value);
})->with(fn (): array => collect(dirtyCandidateContainerSetOperationAttributes())
    ->mapWithKeys(fn (int|string $value, string $attribute): array => [$attribute => [$attribute, $value]])
    ->all());

it('preserves pending and deactivation owners while adding the nullable digest', function (string $attribute, string $value): void {
    historicalCandidateContainerSetMigration()->up();
    $state = [$attribute => $value];
    if ($attribute === 'deactivation_operation_id') {
        $state['supersession_generation'] = 1;
    }
    insertCandidateContainerSetDeployment($state);

    finalBlueGreenLedgerMigration()->up();

    expect(Schema::hasColumn('application_blue_green_deployments', 'destination_routing_topology_digest'))->toBeTrue()
        ->and(DB::table('application_blue_green_deployments')->value($attribute))->not->toBeNull();
})->with([
    'pending color' => ['pending_color', 'green'],
    'pending deployment' => ['pending_deployment_uuid', 'pending-deployment-uuid'],
    'deactivation operation' => ['deactivation_operation_id', 'deactivation-operation-id'],
    'deactivation timestamp' => ['deactivation_started_at', '2026-08-07 00:00:00'],
]);

it('preserves a live candidate set while adding the digest', function (): void {
    historicalCandidateContainerSetMigration()->up();
    insertCandidateContainerSetDeployment(['operation_candidate_container_set' => '{"web":"candidate"}']);

    finalBlueGreenLedgerMigration()->up();

    expect(Schema::hasColumn('application_blue_green_deployments', 'destination_routing_topology_digest'))->toBeTrue()
        ->and(DB::table('application_blue_green_deployments')->value('operation_candidate_container_set'))
        ->toBe('{"web":"candidate"}')
        ->and(candidateContainerSetReleaseKeyColumns())
        ->toBe(['deployment_uuid', 'compose_service', 'replica_index']);
});

it('does not take ownership of PostgreSQL replica-ledger persistence', function (): void {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL persistence catalog semantics.');
    }
    historicalCandidateContainerSetMigration()->up();
    DB::statement('alter table application_blue_green_replicas set unlogged');

    finalBlueGreenLedgerMigration()->up();

    expect(Schema::hasColumn('application_blue_green_deployments', 'destination_routing_topology_digest'))->toBeTrue();
});

it('rejects malformed PostgreSQL prerequisite columns before any transition DDL', function (
    string $sql,
    string $message,
): void {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL prerequisite catalog semantics.');
    }
    historicalCandidateContainerSetMigration()->up();
    DB::statement($sql);

    expect(fn () => finalBlueGreenLedgerMigration()->up())
        ->toThrow(RuntimeException::class, $message);

    expect(Schema::hasColumn('application_blue_green_deployments', 'destination_routing_topology_digest'))->toBeFalse();
})->with([
    'missing scalar candidate id' => [
        'alter table application_blue_green_deployments drop column operation_candidate_container_id',
        'candidate container set',
    ],
    'nullable deployment uuid' => ['alter table application_blue_green_replicas alter column deployment_uuid drop not null', 'replica release key'],
    'nullable compose service' => ['alter table application_blue_green_replicas alter column compose_service drop not null', 'replica release key'],
    'nullable replica index' => ['alter table application_blue_green_replicas alter column replica_index drop not null', 'replica release key'],
]);

it('rejects malformed PostgreSQL digest and release-key catalogs', function (
    array $statements,
    string $message,
    bool $digestExists,
): void {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL digest and constraint catalog semantics.');
    }
    historicalCandidateContainerSetMigration()->up();
    foreach ($statements as $statement) {
        DB::statement($statement);
    }

    expect(fn () => finalBlueGreenLedgerMigration()->up())->toThrow(RuntimeException::class, $message)
        ->and(Schema::hasColumn('application_blue_green_deployments', 'destination_routing_topology_digest'))
        ->toBe($digestExists);
})->with([
    'non-default digest collation' => [[
        'alter table application_blue_green_deployments add column destination_routing_topology_digest varchar(64) collate "C" null',
    ], 'routing topology digest', true],
    'deferrable release constraint' => [[
        'alter table application_blue_green_replicas drop constraint application_blue_green_replica_release_unique',
        'alter table application_blue_green_replicas add constraint application_blue_green_replica_release_unique unique (deployment_uuid, compose_service, replica_index) deferrable initially deferred',
    ], 'replica release key', false],
    'nulls-not-distinct release constraint' => [[
        'alter table application_blue_green_replicas drop constraint application_blue_green_replica_release_unique',
        'alter table application_blue_green_replicas add constraint application_blue_green_replica_release_unique unique nulls not distinct (deployment_uuid, compose_service, replica_index)',
    ], 'replica release key', false],
    'raw partial descending collated release index' => [[
        'alter table application_blue_green_replicas drop constraint application_blue_green_replica_release_unique',
        'create unique index application_blue_green_replica_release_unique on application_blue_green_replicas (deployment_uuid collate "C" desc, compose_service, replica_index) where replica_index > 0',
    ], 'replica release key', false],
]);

it('rolls back the digest and post-DDL prerequisite drift on PostgreSQL', function (): void {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL transactional DDL semantics.');
    }
    historicalCandidateContainerSetMigration()->up();
    $injected = false;
    DB::listen(function (QueryExecuted $query) use (&$injected): void {
        $sql = strtolower($query->sql);
        if ($injected || ! str_contains($sql, 'add column') || ! str_contains($sql, 'destination_routing_topology_digest')) {
            return;
        }
        $injected = true;
        DB::statement('alter table application_blue_green_deployments alter column operation_candidate_container_id type varchar(63)');
    });

    expect(fn () => finalBlueGreenLedgerMigration()->up())
        ->toThrow(RuntimeException::class, 'candidate container set');
    $candidateId = collect(Schema::getColumns('application_blue_green_deployments'))
        ->firstWhere('name', 'operation_candidate_container_id');

    expect($injected)->toBeTrue()
        ->and(Schema::hasColumn('application_blue_green_deployments', 'destination_routing_topology_digest'))->toBeFalse()
        ->and($candidateId['type'])->toBe('character varying(64)');
});
