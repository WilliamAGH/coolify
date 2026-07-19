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
