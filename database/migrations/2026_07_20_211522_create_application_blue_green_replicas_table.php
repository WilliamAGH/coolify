<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('application_settings', 'blue_green_replica_count')) {
            Schema::table('application_settings', function (Blueprint $table): void {
                $table->unsignedSmallInteger('blue_green_replica_count')
                    ->default(DEFAULT_BLUE_GREEN_REPLICA_COUNT);
            });
        }

        if (! Schema::hasTable('application_blue_green_replicas')) {
            Schema::create('application_blue_green_replicas', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('application_blue_green_deployment_id')
                    ->constrained('application_blue_green_deployments')
                    ->cascadeOnDelete();
                $table->foreignId('application_id')->constrained()->cascadeOnDelete();
                $table->foreignId('standalone_docker_id')->constrained()->cascadeOnDelete();
                $table->string('color', 5);
                $table->unsignedSmallInteger('replica_index');
                $table->string('deployment_uuid');
                $table->unsignedBigInteger('routing_revision');
                $table->string('compose_project');
                $table->string('compose_service');
                $table->string('container_name')->nullable();
                $table->string('container_id', 64)->nullable();
                $table->string('health_status', 16)->default('pending');
                $table->timestamp('last_observed_at')->nullable();
                $table->timestamps();
                $table->unique(
                    ['deployment_uuid', 'replica_index'],
                    'application_blue_green_replica_release_unique',
                );
                $table->index(
                    ['application_blue_green_deployment_id', 'color'],
                    'application_blue_green_replica_state_color_index',
                );
                $table->index(
                    ['application_id', 'standalone_docker_id', 'color', 'replica_index'],
                    'application_blue_green_replica_slot_history_index',
                );
            });
        }

        $this->installChecks();
        $this->assertExactSchema();
    }

    public function down(): void
    {
        throw new RuntimeException('Control-plane expand migration is forward-only: 2026_07_20_211522_create_application_blue_green_replicas_table.');
    }

    private function installChecks(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('alter table application_settings drop constraint if exists application_settings_blue_green_replica_count_check');
            DB::statement('alter table application_settings add constraint application_settings_blue_green_replica_count_check check (blue_green_replica_count between 1 and 32)');
            DB::statement('alter table application_blue_green_replicas drop constraint if exists application_blue_green_replicas_color_check');
            DB::statement("alter table application_blue_green_replicas add constraint application_blue_green_replicas_color_check check (color in ('blue', 'green'))");
            DB::statement('alter table application_blue_green_replicas drop constraint if exists application_blue_green_replicas_replica_index_check');
            DB::statement('alter table application_blue_green_replicas add constraint application_blue_green_replicas_replica_index_check check (replica_index between 1 and 32)');
            DB::statement('alter table application_blue_green_replicas drop constraint if exists application_blue_green_replicas_health_status_check');
            DB::statement("alter table application_blue_green_replicas add constraint application_blue_green_replicas_health_status_check check (health_status in ('pending', 'healthy', 'unhealthy', 'stopped'))");

            return;
        }
        if ($driver !== 'sqlite') {
            throw new RuntimeException('Unsupported database driver for blue-green replica schema.');
        }
    }

    public function assertExactSchema(): void
    {
        if (! Schema::hasColumns('application_blue_green_replicas', [
            'application_blue_green_deployment_id',
            'application_id',
            'standalone_docker_id',
            'color',
            'replica_index',
            'deployment_uuid',
            'routing_revision',
            'compose_project',
            'compose_service',
            'container_name',
            'container_id',
            'health_status',
            'last_observed_at',
        ]) || ! Schema::hasColumn('application_settings', 'blue_green_replica_count')) {
            throw new RuntimeException('Blue-green replica schema is incomplete.');
        }
    }
};
