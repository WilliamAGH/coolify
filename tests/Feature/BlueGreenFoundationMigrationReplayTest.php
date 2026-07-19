<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->blueGreenFoundationReplayConnection = 'blue_green_foundation_replay';
    config()->set("database.connections.{$this->blueGreenFoundationReplayConnection}", [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge($this->blueGreenFoundationReplayConnection);
    $this->blueGreenFoundationOriginalConnection = DB::getDefaultConnection();
    DB::setDefaultConnection($this->blueGreenFoundationReplayConnection);
});

afterEach(function () {
    DB::purge($this->blueGreenFoundationReplayConnection);
    DB::setDefaultConnection($this->blueGreenFoundationOriginalConnection);
});

it('replays the blue-green setting migration only against its authorized schema', function () {
    Schema::create('application_settings', function (Blueprint $table) {
        $table->id();
    });

    $migration = require database_path('migrations/2026_07_12_000000_add_blue_green_deployment_setting_to_application_settings.php');
    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('application_settings', 'is_blue_green_deployment_enabled'))->toBeTrue();

    Schema::drop('application_settings');
    Schema::create('application_settings', function (Blueprint $table) {
        $table->id();
        $table->boolean('is_blue_green_deployment_enabled')->nullable();
    });

    $migration = require database_path('migrations/2026_07_12_000000_add_blue_green_deployment_setting_to_application_settings.php');

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'does not match the authorized schema');
});

it('replays the foundation state table without later deactivation state and rejects a partial table', function () {
    Schema::create('applications', function (Blueprint $table) {
        $table->id();
    });
    Schema::create('standalone_dockers', function (Blueprint $table) {
        $table->id();
    });

    $migration = require database_path('migrations/2026_07_12_000001_create_application_blue_green_deployments_table.php');
    $migration->up();
    $migration->up();

    expect(Schema::hasColumns('application_blue_green_deployments', [
        'application_id',
        'standalone_docker_id',
        'phase',
        'routing_revision',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('application_blue_green_deployments', 'deactivation_operation_id'))->toBeFalse()
        ->and(Schema::hasColumn('application_blue_green_deployments', 'deactivation_started_at'))->toBeFalse();

    Schema::drop('application_blue_green_deployments');
    Schema::create('application_blue_green_deployments', function (Blueprint $table) {
        $table->id();
    });

    $migration = require database_path('migrations/2026_07_12_000001_create_application_blue_green_deployments_table.php');

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'unexpected column set');
});

it('replays queue provenance and refuses its partial foundation schema', function () {
    Schema::create('application_deployment_queues', function (Blueprint $table) {
        $table->id();
    });

    $migration = require database_path('migrations/2026_07_12_000002_add_blue_green_provenance_to_application_deployment_queues.php');
    $migration->up();
    $migration->up();

    expect(Schema::hasColumns('application_deployment_queues', [
        'blue_green_color',
        'blue_green_phase',
        'blue_green_routing_revision',
        'blue_green_previous_container_id',
        'blue_green_candidate_container_id',
        'blue_green_rollback_managed_filename',
        'blue_green_routing_mutated_at',
    ]))->toBeTrue();

    Schema::drop('application_deployment_queues');
    Schema::create('application_deployment_queues', function (Blueprint $table) {
        $table->id();
        $table->string('blue_green_color')->nullable();
    });

    $migration = require database_path('migrations/2026_07_12_000002_add_blue_green_provenance_to_application_deployment_queues.php');

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'partial; refusing a non-convergent replay');
});

it('replays destination fencing only when both owned column groups are complete', function () {
    Schema::create('application_blue_green_deployments', function (Blueprint $table) {
        $table->id();
    });
    Schema::create('application_deployment_queues', function (Blueprint $table) {
        $table->id();
    });

    $migration = require database_path('migrations/2026_07_19_025448_add_destination_fencing_to_blue_green_operations.php');
    $migration->up();
    $migration->up();

    expect(Schema::hasColumns('application_blue_green_deployments', [
        'destination_fence_epoch',
        'destination_fence_operation_id',
        'destination_fence_mutation_sequence',
        'managed_file_sha256',
        'destination_topology_digest',
        'application_routing_config_digest',
        'operation_destination_fence_epoch',
        'operation_previous_destination_fence_epoch',
        'operation_server_boot_id',
        'operation_topology_digest',
        'operation_routing_config_digest',
        'operation_previous_managed_file_sha256',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('application_deployment_queues', [
            'blue_green_destination_fence_epoch',
            'blue_green_server_boot_id',
            'blue_green_topology_digest',
            'blue_green_routing_config_digest',
        ]))->toBeTrue()
        ->and(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'forward-only');

    Schema::drop('application_blue_green_deployments');
    Schema::create('application_blue_green_deployments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('destination_fence_epoch')->default(0);
    });

    $migration = require database_path('migrations/2026_07_19_025448_add_destination_fencing_to_blue_green_operations.php');

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'state columns are partial');
});

it('rejects complete destination-fencing state columns with a malformed shape', function () {
    Schema::create('application_blue_green_deployments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('destination_fence_epoch')->nullable();
        $table->string('destination_fence_operation_id')->nullable();
        $table->unsignedBigInteger('destination_fence_mutation_sequence')->default(0);
        $table->string('managed_file_sha256', 64)->nullable();
        $table->string('destination_topology_digest', 64)->nullable();
        $table->string('application_routing_config_digest', 64)->nullable();
        $table->unsignedBigInteger('operation_destination_fence_epoch')->nullable();
        $table->unsignedBigInteger('operation_previous_destination_fence_epoch')->nullable();
        $table->string('operation_server_boot_id', 36)->nullable();
        $table->string('operation_topology_digest', 64)->nullable();
        $table->string('operation_routing_config_digest', 64)->nullable();
        $table->string('operation_previous_managed_file_sha256', 64)->nullable();
    });
    Schema::create('application_deployment_queues', function (Blueprint $table) {
        $table->id();
    });

    $migration = require database_path('migrations/2026_07_19_025448_add_destination_fencing_to_blue_green_operations.php');

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'authorized SQLite schema');
});

it('rejects complete destination-fencing queue columns with a malformed shape', function () {
    Schema::create('application_blue_green_deployments', function (Blueprint $table) {
        $table->id();
    });
    Schema::create('application_deployment_queues', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('blue_green_destination_fence_epoch')->nullable();
        $table->integer('blue_green_server_boot_id')->nullable();
        $table->string('blue_green_topology_digest', 64)->nullable();
        $table->string('blue_green_routing_config_digest', 64)->nullable();
    });

    $migration = require database_path('migrations/2026_07_19_025448_add_destination_fencing_to_blue_green_operations.php');

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'authorized SQLite schema');
});

it('replays drain provenance only against a complete authorized schema', function () {
    Schema::create('application_blue_green_deployments', function (Blueprint $table) {
        $table->id();
    });

    $migration = require database_path('migrations/2026_07_19_030000_add_blue_green_drain_provenance.php');
    $migration->up();
    $migration->up();

    expect(Schema::hasColumns('application_blue_green_deployments', [
        'operation_drain_started_at',
        'operation_drain_deadline_at',
        'operation_drain_last_observed_connections',
        'operation_drain_observed_at',
    ]))->toBeTrue()
        ->and(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Control-plane expand migration is forward-only: 2026_07_19_030000_add_blue_green_drain_provenance.');

    Schema::drop('application_blue_green_deployments');
    Schema::create('application_blue_green_deployments', function (Blueprint $table) {
        $table->id();
        $table->timestamp('operation_drain_started_at')->nullable();
    });

    $migration = require database_path('migrations/2026_07_19_030000_add_blue_green_drain_provenance.php');

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'partial; refusing a non-convergent replay');
});

it('rejects complete drain provenance columns with a malformed SQLite shape', function () {
    Schema::create('application_blue_green_deployments', function (Blueprint $table) {
        $table->id();
        $table->timestamp('operation_drain_started_at')->nullable();
        $table->timestamp('operation_drain_deadline_at')->nullable();
        $table->string('operation_drain_last_observed_connections')->nullable();
        $table->timestamp('operation_drain_observed_at')->nullable();
    });

    $migration = require database_path('migrations/2026_07_19_030000_add_blue_green_drain_provenance.php');

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'authorized SQLite schema');
});

it('replays supersession generation only when all owner columns are complete', function () {
    Schema::create('application_blue_green_deployments', function (Blueprint $table) {
        $table->id();
    });
    Schema::create('application_blue_green_deactivations', function (Blueprint $table) {
        $table->id();
    });
    Schema::create('application_deployment_queues', function (Blueprint $table) {
        $table->id();
    });
    DB::table('application_blue_green_deactivations')->insert(['id' => 1]);

    $migration = require database_path('migrations/2026_07_19_025449_add_blue_green_supersession_generation.php');
    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('application_blue_green_deployments', 'supersession_generation'))->toBeTrue()
        ->and(Schema::hasColumn('application_blue_green_deactivations', 'supersession_generation'))->toBeTrue()
        ->and(Schema::hasColumn('application_deployment_queues', 'blue_green_supersession_generation'))->toBeTrue()
        ->and(DB::table('application_blue_green_deactivations')->where('id', 1)->value('supersession_generation'))->toBe(1)
        ->and(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'forward-only');

    Schema::drop('application_blue_green_deployments');
    Schema::create('application_blue_green_deployments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('supersession_generation')->default(0);
    });
    Schema::drop('application_blue_green_deactivations');
    Schema::create('application_blue_green_deactivations', function (Blueprint $table) {
        $table->id();
    });
    Schema::drop('application_deployment_queues');
    Schema::create('application_deployment_queues', function (Blueprint $table) {
        $table->id();
    });

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'columns are partial');
});

it('rejects malformed supersession-generation column shapes', function () {
    Schema::create('application_blue_green_deployments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('supersession_generation')->nullable();
    });
    Schema::create('application_blue_green_deactivations', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('supersession_generation')->default(0);
    });
    Schema::create('application_deployment_queues', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('blue_green_supersession_generation')->nullable();
    });

    $migration = require database_path('migrations/2026_07_19_025449_add_blue_green_supersession_generation.php');

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'authorized SQLite schema');
});

it('requires unversioned active owners to drain before generation expansion', function () {
    Schema::create('application_blue_green_deployments', function (Blueprint $table) {
        $table->id();
        $table->string('operation_deployment_uuid')->nullable();
    });
    Schema::create('application_blue_green_deactivations', function (Blueprint $table) {
        $table->id();
        $table->string('phase');
    });
    Schema::create('application_deployment_queues', function (Blueprint $table) {
        $table->id();
        $table->string('blue_green_phase')->nullable();
        $table->string('status');
    });
    DB::table('application_blue_green_deployments')->insert([
        'operation_deployment_uuid' => 'unversioned-live-owner',
    ]);

    $migration = require database_path('migrations/2026_07_19_025449_add_blue_green_supersession_generation.php');

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'must drain before supersession-generation expansion');
});
