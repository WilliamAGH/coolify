<?php

use App\Console\Commands\Migration as MigrationCommand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function blueGreenMigration(string $filename): Migration
{
    return require database_path("migrations/{$filename}.php");
}

/** @return list<string> */
function blueGreenMigrationNames(): array
{
    return MigrationCommand::controlPlaneMigrationNames();
}

function blueGreenMigrationMismatchMessage(string $sqliteMessage, string $postgresMessage): string
{
    return Schema::getConnection()->getDriverName() === 'pgsql'
        ? $postgresMessage
        : $sqliteMessage;
}

function removeBlueGreenExpandSchema(): void
{
    Schema::dropIfExists('application_blue_green_deactivations');
    Schema::dropIfExists('application_blue_green_deployments');

    $setting = 'is_blue_green_deployment_enabled';
    if (Schema::hasColumn('application_settings', $setting)) {
        Schema::table('application_settings', fn (Blueprint $table) => $table->dropColumn($setting));
    }

    $queueColumns = [
        'blue_green_color',
        'blue_green_phase',
        'blue_green_routing_revision',
        'blue_green_previous_container_id',
        'blue_green_candidate_container_id',
        'blue_green_rollback_managed_filename',
        'blue_green_routing_mutated_at',
        'blue_green_destination_fence_epoch',
        'blue_green_server_boot_id',
        'blue_green_topology_digest',
        'blue_green_routing_config_digest',
        'blue_green_backend_port_inventory',
        'blue_green_drain_backend_port_inventory',
        'blue_green_supersession_generation',
    ];
    $presentQueueColumns = array_values(array_filter(
        $queueColumns,
        fn (string $column) => Schema::hasColumn('application_deployment_queues', $column),
    ));
    if ($presentQueueColumns !== []) {
        Schema::table('application_deployment_queues', fn (Blueprint $table) => $table->dropColumn($presentQueueColumns));
    }
}

function createDeploymentTableWithShortContainerId(): void
{
    if (Schema::getConnection()->getDriverName() === 'pgsql') {
        blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up();
        DB::statement('alter table application_blue_green_deployments alter column operation_previous_container_id type varchar(63)');

        return;
    }

    DB::statement(<<<'SQL'
        create table application_blue_green_deployments (
            id integer primary key autoincrement not null,
            application_id integer not null,
            standalone_docker_id integer not null,
            active_color varchar null,
            pending_color varchar null,
            blue_deployment_uuid varchar null,
            green_deployment_uuid varchar null,
            pending_deployment_uuid varchar null,
            legacy_container_name varchar null,
            operation_deployment_uuid varchar null,
            operation_previous_active_color varchar null,
            operation_previous_deployment_uuid varchar null,
            operation_previous_routing_revision integer null,
            operation_previous_container_name varchar null,
            operation_previous_container_id varchar(63) null,
            operation_candidate_container_name varchar null,
            operation_candidate_container_id varchar null,
            operation_rollback_managed_filename varchar null,
            operation_routing_mutated_at datetime null,
            operation_legacy_routing_snapshot_version integer null,
            operation_legacy_routing_snapshot text null,
            operation_legacy_routing_snapshot_sha256 varchar null,
            phase varchar not null default 'idle',
            routing_revision integer not null default 0,
            created_at datetime null,
            updated_at datetime null
        )
        SQL);
}

function createDeactivationTableWithShortOperationId(): void
{
    if (Schema::getConnection()->getDriverName() === 'pgsql') {
        blueGreenMigration('2026_07_12_000011_create_application_blue_green_deactivations_table')->up();
        DB::statement('alter table application_blue_green_deactivations alter column operation_id type varchar(63)');

        return;
    }

    DB::statement(<<<'SQL'
        create table application_blue_green_deactivations (
            id integer primary key autoincrement not null,
            application_id integer not null,
            standalone_docker_id integer not null,
            operation_id varchar(63) not null,
            started_at datetime not null,
            queue_cutoff_id integer not null default 0,
            phase varchar not null default 'deactivating',
            completed_at datetime null,
            created_at datetime null,
            updated_at datetime null
        )
        SQL);
}

beforeEach(function (): void {
    Schema::dropAllTables();

    Schema::create('applications', fn (Blueprint $table) => $table->id());
    Schema::create('standalone_dockers', fn (Blueprint $table) => $table->id());
    Schema::create('application_settings', fn (Blueprint $table) => $table->id());
    Schema::create('application_deployment_queues', function (Blueprint $table) {
        $table->id();
        $table->string('status');
    });
});

afterEach(function (): void {
    if (Schema::getConnection()->getDriverName() === 'pgsql') {
        Artisan::call('migrate:install', ['--no-interaction' => true]);
        Artisan::call('migrate:fresh', ['--force' => true]);
    }
});

it('converges when each authorized schema commit exists without its migration ledger row', function () {
    removeBlueGreenExpandSchema();

    $migrations = array_map(blueGreenMigration(...), blueGreenMigrationNames());

    foreach ($migrations as $migration) {
        $migration->up();
        $migration->up();
    }

    expect(Schema::hasColumn('application_settings', 'is_blue_green_deployment_enabled'))->toBeTrue()
        ->and(Schema::hasColumn('application_deployment_queues', 'blue_green_routing_mutated_at'))->toBeTrue()
        ->and(Schema::hasColumn('application_blue_green_deployments', 'deactivation_started_at'))->toBeTrue()
        ->and(Schema::hasColumn('application_blue_green_deactivations', 'proxy_snapshot'))->toBeTrue()
        ->and(Schema::hasColumns('application_blue_green_deployments', ['intervention_phase', 'intervention_reason']))->toBeTrue()
        ->and(Schema::hasColumns('application_blue_green_deactivations', ['intervention_phase', 'intervention_reason']))->toBeTrue()
        ->and(Schema::hasColumn('application_blue_green_deployments', 'supersession_generation'))->toBeTrue()
        ->and(Schema::hasColumn('application_blue_green_deactivations', 'supersession_generation'))->toBeTrue()
        ->and(Schema::hasColumn('application_deployment_queues', 'blue_green_supersession_generation'))->toBeTrue()
        ->and(Schema::hasColumns('application_deployment_queues', [
            'blue_green_backend_port_inventory',
            'blue_green_drain_backend_port_inventory',
        ]))->toBeTrue();
});

it('replays every earlier migration against the complete later schema', function () {
    removeBlueGreenExpandSchema();
    $migrationNames = blueGreenMigrationNames();

    foreach ($migrationNames as $migrationName) {
        blueGreenMigration($migrationName)->up();
    }
    foreach ($migrationNames as $migrationName) {
        blueGreenMigration($migrationName)->up();
    }

    expect(Schema::hasColumns('application_blue_green_deployments', [
        'deactivation_operation_id',
        'deactivation_started_at',
        'operation_drain_started_at',
        'operation_drain_deadline_at',
        'operation_drain_last_observed_connections',
        'operation_drain_observed_at',
        'supersession_generation',
        'intervention_phase',
        'intervention_reason',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('application_blue_green_deactivations', [
            'supersession_generation',
            'intervention_phase',
            'intervention_reason',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('application_deployment_queues', 'blue_green_supersession_generation'))->toBeTrue()
        ->and(Schema::hasColumns('application_deployment_queues', [
            'blue_green_backend_port_inventory',
            'blue_green_drain_backend_port_inventory',
        ]))->toBeTrue();
});

it('attests the complete schema through each migration public contract', function () {
    removeBlueGreenExpandSchema();
    $migrations = array_map(blueGreenMigration(...), blueGreenMigrationNames());

    foreach ($migrations as $migration) {
        $migration->up();
    }
    foreach ($migrations as $migration) {
        $migration->assertExactSchema();
    }

    expect(Schema::hasTable('application_blue_green_deactivations'))->toBeTrue()
        ->and(Schema::hasColumn('application_blue_green_deactivations', 'proxy_snapshot'))->toBeTrue()
        ->and(Schema::hasColumns('application_blue_green_deployments', ['intervention_phase', 'intervention_reason']))->toBeTrue()
        ->and(Schema::hasColumns('application_blue_green_deactivations', ['intervention_phase', 'intervention_reason']))->toBeTrue()
        ->and(Schema::hasColumn('application_blue_green_deployments', 'supersession_generation'))->toBeTrue()
        ->and(Schema::hasColumn('application_blue_green_deactivations', 'supersession_generation'))->toBeTrue()
        ->and(Schema::hasColumn('application_deployment_queues', 'blue_green_supersession_generation'))->toBeTrue()
        ->and(Schema::hasColumns('application_deployment_queues', [
            'blue_green_backend_port_inventory',
            'blue_green_drain_backend_port_inventory',
        ]))->toBeTrue();
});

it('keeps all expand schema intact because every authorized migration is forward-only', function () {
    removeBlueGreenExpandSchema();
    $migrationNames = blueGreenMigrationNames();
    foreach ($migrationNames as $migrationName) {
        blueGreenMigration($migrationName)->up();
    }
    $deploymentColumns = Schema::getColumnListing('application_blue_green_deployments');
    $deactivationColumns = Schema::getColumnListing('application_blue_green_deactivations');
    $queueColumns = Schema::getColumnListing('application_deployment_queues');

    foreach ($migrationNames as $migrationName) {
        $prefix = str_contains($migrationName, 'inactive_retention') || str_contains($migrationName, 'inactive_retirement')
            ? 'Application blue-green'
            : 'Control-plane';
        expect(fn () => blueGreenMigration($migrationName)->down())
            ->toThrow(RuntimeException::class, "{$prefix} expand migration is forward-only: {$migrationName}.");
    }

    expect(Schema::getColumnListing('application_blue_green_deployments'))->toBe($deploymentColumns)
        ->and(Schema::getColumnListing('application_blue_green_deactivations'))->toBe($deactivationColumns)
        ->and(Schema::getColumnListing('application_deployment_queues'))->toBe($queueColumns)
        ->and(Schema::hasColumn('application_settings', 'is_blue_green_deployment_enabled'))->toBeTrue();
});

it('fails closed on a partial queue provenance schema', function () {
    removeBlueGreenExpandSchema();
    Schema::table('application_deployment_queues', function (Blueprint $table) {
        $table->string('blue_green_color')->nullable();
    });

    expect(fn () => blueGreenMigration('2026_07_12_000002_add_blue_green_provenance_to_application_deployment_queues')->up())
        ->toThrow(RuntimeException::class, 'partial');
});

it('fails closed on a partial backend port inventory schema', function () {
    removeBlueGreenExpandSchema();
    Schema::table('application_deployment_queues', function (Blueprint $table): void {
        $table->text('blue_green_backend_port_inventory')->nullable();
    });

    expect(fn () => blueGreenMigration('2026_07_20_000000_add_blue_green_backend_port_inventories_to_deployment_queues')->up())
        ->toThrow(RuntimeException::class, 'partial');
});

it('rejects backend port inventory columns with the wrong exact type', function () {
    removeBlueGreenExpandSchema();
    Schema::table('application_deployment_queues', function (Blueprint $table): void {
        $table->string('blue_green_backend_port_inventory')->nullable();
        $table->string('blue_green_drain_backend_port_inventory')->nullable();
    });

    expect(fn () => blueGreenMigration('2026_07_20_000000_add_blue_green_backend_port_inventories_to_deployment_queues')->up())
        ->toThrow(RuntimeException::class, 'not match the authorized');
});

it('rejects partial later-owned intervention reason groups during foundation replay', function (): void {
    removeBlueGreenExpandSchema();
    $deploymentMigration = blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table');
    $deactivationMigration = blueGreenMigration('2026_07_12_000011_create_application_blue_green_deactivations_table');
    $deploymentMigration->up();
    $deactivationMigration->up();

    Schema::table('application_blue_green_deployments', function (Blueprint $table): void {
        $table->string('intervention_phase', 32)->nullable();
    });
    Schema::table('application_blue_green_deactivations', function (Blueprint $table): void {
        $table->string('intervention_reason', 2048)->nullable();
    });

    expect(fn () => $deploymentMigration->up())
        ->toThrow(RuntimeException::class, blueGreenMigrationMismatchMessage(
            'Existing blue-green deployment table has an unexpected column set.',
            'Existing blue-green deployment PostgreSQL catalog does not match the authorized schema.',
        ));
    expect(fn () => $deactivationMigration->up())
        ->toThrow(RuntimeException::class, blueGreenMigrationMismatchMessage(
            'Existing blue-green deactivation table has an unexpected column set.',
            'Existing blue-green deactivation PostgreSQL catalog does not match the authorized schema.',
        ));
});

it('rejects an inactive retention setting with the wrong exact type', function () {
    Schema::table('application_settings', function (Blueprint $table): void {
        $table->string('blue_green_inactive_retention_seconds')->default('0');
    });

    expect(fn () => blueGreenMigration('2026_07_19_120000_add_blue_green_inactive_retention_setting')->up())
        ->toThrow(RuntimeException::class, 'does not match the authorized');
});

it('fails closed on malformed postgres inactive retention defaults', function () {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL default expressions are not represented by SQLite.');
    }

    $migration = blueGreenMigration('2026_07_19_120000_add_blue_green_inactive_retention_setting');
    $migration->up();
    DB::statement('alter table application_settings alter column blue_green_inactive_retention_seconds set default 1');

    expect(fn () => $migration->assertExactSchema())
        ->toThrow(RuntimeException::class, 'does not match the authorized PostgreSQL catalog');
});

it('fails closed on malformed postgres inactive retirement attempt defaults', function () {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL default expressions are not represented by SQLite.');
    }

    blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up();
    $migration = blueGreenMigration('2026_07_19_120001_add_blue_green_inactive_retirement_provenance');
    $migration->up();
    DB::statement('alter table application_blue_green_deployments alter column inactive_retirement_attempts set default 1');

    expect(fn () => $migration->assertExactSchema())
        ->toThrow(RuntimeException::class, 'does not match the authorized PostgreSQL catalog');
});

it('rejects malformed inactive retirement column and index shapes', function () {
    blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up();
    $migration = blueGreenMigration('2026_07_19_120001_add_blue_green_inactive_retirement_provenance');
    $migration->up();

    Schema::table('application_blue_green_deployments', function (Blueprint $table): void {
        $table->string('inactive_retirement_attempts')->default('0')->change();
    });
    expect(fn () => $migration->assertExactSchema())
        ->toThrow(RuntimeException::class, 'does not match');

    Schema::drop('application_blue_green_deployments');
    blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up();
    $migration->up();
    Schema::table('application_blue_green_deployments', function (Blueprint $table): void {
        $table->dropIndex('app_blue_green_inactive_retirement_due_index');
        $table->index(
            ['inactive_retirement_not_before_at'],
            'app_blue_green_inactive_retirement_due_index',
        );
    });

    expect(fn () => $migration->assertExactSchema())
        ->toThrow(RuntimeException::class, 'does not match');
});

it('fails closed on a partial deactivation provenance schema', function () {
    removeBlueGreenExpandSchema();
    blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up();
    Schema::table('application_blue_green_deployments', function (Blueprint $table) {
        $table->string('deactivation_operation_id', 64)->nullable();
    });

    expect(fn () => blueGreenMigration('2026_07_12_000010_add_blue_green_deactivation_provenance')->up())
        ->toThrow(RuntimeException::class, 'partial');
});

it('rejects a partial later-owned shape when replaying the deployment table migration', function () {
    removeBlueGreenExpandSchema();
    blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up();
    Schema::table('application_blue_green_deployments', function (Blueprint $table) {
        $table->string('deactivation_operation_id', 64)->nullable();
    });

    expect(fn () => blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up())
        ->toThrow(RuntimeException::class, blueGreenMigrationMismatchMessage(
            'unexpected column set',
            'Existing blue-green deployment PostgreSQL catalog does not match the authorized schema.',
        ));
});

it('fails closed on a mismatched existing deployment table', function () {
    removeBlueGreenExpandSchema();
    Schema::create('application_blue_green_deployments', function (Blueprint $table) {
        $table->id();
    });

    expect(fn () => blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up())
        ->toThrow(RuntimeException::class, blueGreenMigrationMismatchMessage(
            'unexpected column set',
            'Existing blue-green deployment PostgreSQL catalog does not match the authorized schema.',
        ));
});

it('fails closed on a mismatched existing deactivation table', function () {
    removeBlueGreenExpandSchema();
    Schema::create('application_blue_green_deactivations', function (Blueprint $table) {
        $table->id();
    });

    expect(fn () => blueGreenMigration('2026_07_12_000011_create_application_blue_green_deactivations_table')->up())
        ->toThrow(RuntimeException::class, blueGreenMigrationMismatchMessage(
            'unexpected column set',
            'Existing blue-green deactivation PostgreSQL catalog does not match the authorized schema.',
        ));
});

it('fails closed on a deployment container ID with the wrong declared length', function () {
    removeBlueGreenExpandSchema();
    createDeploymentTableWithShortContainerId();

    expect(fn () => blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up())
        ->toThrow(RuntimeException::class, blueGreenMigrationMismatchMessage(
            'operation_previous_container_id',
            'Existing blue-green deployment PostgreSQL catalog does not match the authorized schema.',
        ));
});

it('fails closed on a queue container ID with the wrong declared length', function () {
    removeBlueGreenExpandSchema();
    Schema::table('application_deployment_queues', function (Blueprint $table) {
        $table->string('blue_green_color')->nullable();
        $table->string('blue_green_phase')->nullable();
        $table->unsignedBigInteger('blue_green_routing_revision')->nullable();
        $table->string('blue_green_candidate_container_id', 64)->nullable();
        $table->string('blue_green_rollback_managed_filename')->nullable();
        $table->timestamp('blue_green_routing_mutated_at')->nullable();
    });
    DB::statement('alter table application_deployment_queues add column blue_green_previous_container_id varchar(63) null');

    expect(fn () => blueGreenMigration('2026_07_12_000002_add_blue_green_provenance_to_application_deployment_queues')->up())
        ->toThrow(RuntimeException::class, blueGreenMigrationMismatchMessage(
            'blue_green_previous_container_id',
            'Existing queue provenance columns do not match the authorized PostgreSQL catalog.',
        ));
});

it('fails closed on a deployment deactivation ID with the wrong declared length', function () {
    removeBlueGreenExpandSchema();
    blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up();
    DB::statement('alter table application_blue_green_deployments add column deactivation_operation_id varchar(63) null');
    Schema::table('application_blue_green_deployments', function (Blueprint $table) {
        $table->timestamp('deactivation_started_at')->nullable();
    });

    expect(fn () => blueGreenMigration('2026_07_12_000010_add_blue_green_deactivation_provenance')->up())
        ->toThrow(RuntimeException::class, blueGreenMigrationMismatchMessage(
            'deactivation_operation_id',
            'Existing deactivation provenance columns do not match the authorized PostgreSQL catalog.',
        ));
});

it('fails closed on a deactivation operation ID with the wrong declared length', function () {
    removeBlueGreenExpandSchema();
    createDeactivationTableWithShortOperationId();

    expect(fn () => blueGreenMigration('2026_07_12_000011_create_application_blue_green_deactivations_table')->up())
        ->toThrow(RuntimeException::class, blueGreenMigrationMismatchMessage(
            'operation_id',
            'Existing blue-green deactivation PostgreSQL catalog does not match the authorized schema.',
        ));
});

it('fails closed when the deployment ID sequence loses ownership', function () {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL catalog ownership is not represented by SQLite.');
    }

    removeBlueGreenExpandSchema();
    blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up();
    DB::statement('alter sequence application_blue_green_deployments_id_seq owned by none');

    expect(fn () => blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up())
        ->toThrow(RuntimeException::class, 'ID sequence ownership');

    DB::statement('alter sequence application_blue_green_deployments_id_seq owned by application_blue_green_deployments.id');
});

it('fails closed when the deactivation ID sequence loses ownership', function () {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL catalog ownership is not represented by SQLite.');
    }

    removeBlueGreenExpandSchema();
    blueGreenMigration('2026_07_12_000011_create_application_blue_green_deactivations_table')->up();
    DB::statement('alter sequence application_blue_green_deactivations_id_seq owned by none');

    expect(fn () => blueGreenMigration('2026_07_12_000011_create_application_blue_green_deactivations_table')->up())
        ->toThrow(RuntimeException::class, 'ID sequence ownership');

    DB::statement('alter sequence application_blue_green_deactivations_id_seq owned by application_blue_green_deactivations.id');
});

it('fails closed when deployment ID ownership moves to a decoy sequence', function () {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL catalog ownership is not represented by SQLite.');
    }

    removeBlueGreenExpandSchema();
    blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up();
    DB::statement('create sequence application_blue_green_deployments_id_decoy_seq');
    DB::statement('alter sequence application_blue_green_deployments_id_seq owned by none');
    DB::statement('alter sequence application_blue_green_deployments_id_decoy_seq owned by application_blue_green_deployments.id');

    expect(fn () => blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up())
        ->toThrow(RuntimeException::class, 'ID sequence ownership/default binding');

    DB::statement('alter sequence application_blue_green_deployments_id_decoy_seq owned by none');
    DB::statement('drop sequence application_blue_green_deployments_id_decoy_seq');
    DB::statement('alter sequence application_blue_green_deployments_id_seq owned by application_blue_green_deployments.id');
});

it('fails closed when deactivation ID ownership moves to a decoy sequence', function () {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL catalog ownership is not represented by SQLite.');
    }

    removeBlueGreenExpandSchema();
    blueGreenMigration('2026_07_12_000011_create_application_blue_green_deactivations_table')->up();
    DB::statement('create sequence application_blue_green_deactivations_id_decoy_seq');
    DB::statement('alter sequence application_blue_green_deactivations_id_seq owned by none');
    DB::statement('alter sequence application_blue_green_deactivations_id_decoy_seq owned by application_blue_green_deactivations.id');

    expect(fn () => blueGreenMigration('2026_07_12_000011_create_application_blue_green_deactivations_table')->up())
        ->toThrow(RuntimeException::class, 'ID sequence ownership/default binding');

    DB::statement('alter sequence application_blue_green_deactivations_id_decoy_seq owned by none');
    DB::statement('drop sequence application_blue_green_deactivations_id_decoy_seq');
    DB::statement('alter sequence application_blue_green_deactivations_id_seq owned by application_blue_green_deactivations.id');
});

it('fails closed on inexact postgres deployment defaults and timestamp precision', function () {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL catalog expressions are not represented by SQLite.');
    }

    removeBlueGreenExpandSchema();
    blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up();
    DB::statement("alter table application_blue_green_deployments alter column phase set default 'not-idle'");
    DB::statement('alter table application_blue_green_deployments alter column operation_routing_mutated_at type timestamp(3) without time zone');

    expect(fn () => blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up())
        ->toThrow(RuntimeException::class, 'PostgreSQL catalog does not match the authorized schema');
});

it('fails closed on a semantically equivalent postgres deployment default with a different expression', function () {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL catalog expressions are not represented by SQLite.');
    }

    removeBlueGreenExpandSchema();
    blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up();
    DB::statement('alter table application_blue_green_deployments alter column routing_revision set default (0::numeric)::bigint');

    expect(fn () => blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up())
        ->toThrow(RuntimeException::class, 'PostgreSQL catalog does not match the authorized schema');
});

it('fails closed on malformed postgres destination-fencing state columns', function () {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL destination-fencing catalogs are not represented by SQLite.');
    }

    removeBlueGreenExpandSchema();
    foreach (blueGreenMigrationNames() as $migrationName) {
        blueGreenMigration($migrationName)->up();
    }
    DB::statement('alter table application_blue_green_deployments alter column operation_server_boot_id type varchar(35)');

    expect(fn () => blueGreenMigration('2026_07_19_025448_add_destination_fencing_to_blue_green_operations')->up())
        ->toThrow(RuntimeException::class, 'state columns do not match the authorized PostgreSQL catalog');
});

it('fails closed on malformed postgres destination-fencing queue columns', function () {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL destination-fencing catalogs are not represented by SQLite.');
    }

    removeBlueGreenExpandSchema();
    foreach (blueGreenMigrationNames() as $migrationName) {
        blueGreenMigration($migrationName)->up();
    }
    DB::statement('alter table application_deployment_queues alter column blue_green_topology_digest type varchar(63)');

    expect(fn () => blueGreenMigration('2026_07_19_025448_add_destination_fencing_to_blue_green_operations')->up())
        ->toThrow(RuntimeException::class, 'queue columns do not match the authorized PostgreSQL catalog');
});

it('fails closed on malformed postgres drain provenance columns', function () {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL drain provenance catalogs are not represented by SQLite.');
    }

    removeBlueGreenExpandSchema();
    foreach (blueGreenMigrationNames() as $migrationName) {
        blueGreenMigration($migrationName)->up();
    }
    DB::statement('alter table application_blue_green_deployments alter column operation_drain_last_observed_connections type smallint');

    expect(fn () => blueGreenMigration('2026_07_19_030000_add_blue_green_drain_provenance')->up())
        ->toThrow(RuntimeException::class, 'drain provenance columns do not match the authorized PostgreSQL catalog');
});

it('fails closed on malformed postgres supersession-generation columns', function () {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL supersession-generation catalogs are not represented by SQLite.');
    }

    removeBlueGreenExpandSchema();
    foreach (blueGreenMigrationNames() as $migrationName) {
        blueGreenMigration($migrationName)->up();
    }
    DB::statement('alter table application_blue_green_deployments alter column supersession_generation drop not null');

    expect(fn () => blueGreenMigration('2026_07_19_025449_add_blue_green_supersession_generation')->up())
        ->toThrow(RuntimeException::class, 'do not match the authorized PostgreSQL catalog');
});

it('fails closed on an extra postgres deployment index', function () {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL index catalogs are not represented by SQLite.');
    }

    removeBlueGreenExpandSchema();
    blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up();
    DB::statement('create index application_blue_green_deployments_phase_decoy on application_blue_green_deployments (phase)');

    expect(fn () => blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up())
        ->toThrow(RuntimeException::class, 'PostgreSQL catalog does not match the authorized schema');
});

it('fails closed on a same-name postgres index with unsafe opclass and ordering', function () {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL index catalogs are not represented by SQLite.');
    }

    removeBlueGreenExpandSchema();
    blueGreenMigration('2026_07_12_000011_create_application_blue_green_deactivations_table')->up();
    DB::statement('drop index app_blue_green_deactivation_phase_index');
    DB::statement(<<<'SQL'
        create index app_blue_green_deactivation_phase_index
        on application_blue_green_deactivations
        (phase varchar_pattern_ops desc nulls first, started_at)
        SQL);

    expect(fn () => blueGreenMigration('2026_07_12_000011_create_application_blue_green_deactivations_table')->up())
        ->toThrow(RuntimeException::class, 'PostgreSQL catalog does not match the authorized schema');
});

it('fails closed on unsafe postgres deployment sequence parameters', function () {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL sequence catalogs are not represented by SQLite.');
    }

    removeBlueGreenExpandSchema();
    blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up();
    DB::statement('alter sequence application_blue_green_deployments_id_seq increment by 2 cache 2 cycle');

    expect(fn () => blueGreenMigration('2026_07_12_000001_create_application_blue_green_deployments_table')->up())
        ->toThrow(RuntimeException::class, 'ID sequence ownership/default binding');
});

it('fails closed on inexact postgres deactivation defaults', function () {
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL catalog expressions are not represented by SQLite.');
    }

    removeBlueGreenExpandSchema();
    blueGreenMigration('2026_07_12_000011_create_application_blue_green_deactivations_table')->up();
    DB::statement("alter table application_blue_green_deactivations alter column phase set default 'not-deactivating'");
    DB::statement('alter table application_blue_green_deactivations alter column queue_cutoff_id set default 0 + 1');

    expect(fn () => blueGreenMigration('2026_07_12_000011_create_application_blue_green_deactivations_table')->up())
        ->toThrow(RuntimeException::class, 'PostgreSQL catalog does not match the authorized schema');
});
