<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets one color own several containers.
 *
 * `operation_candidate_container_name` / `_container_id` stay authoritative for
 * a destination that owns a single container, so every existing row keeps its
 * meaning and the operation fence keeps comparing what it always compared. The
 * new column carries the whole set only when there is more than one member.
 *
 * The replica release key widens to include the routed service. With a single
 * routed service the old key already implied the new one, so no existing row can
 * conflict; with several, two services' replicas may legitimately share an index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('application_blue_green_deployments', 'operation_candidate_container_set')) {
            Schema::table('application_blue_green_deployments', function (Blueprint $table): void {
                $table->text('operation_candidate_container_set')->nullable();
            });
        }

        if (Schema::hasTable('application_blue_green_replicas')) {
            Schema::table('application_blue_green_replicas', function (Blueprint $table): void {
                $table->dropUnique('application_blue_green_replica_release_unique');
                $table->unique(
                    ['deployment_uuid', 'compose_service', 'replica_index'],
                    'application_blue_green_replica_release_unique',
                );
            });
        }

        $this->assertExactSchema();
    }

    public function down(): void
    {
        throw new RuntimeException('Control-plane expand migration is forward-only: 2026_08_04_044304_add_blue_green_candidate_container_set.');
    }

    public function assertExactSchema(): void
    {
        if (! Schema::hasColumn('application_blue_green_deployments', 'operation_candidate_container_set')) {
            throw new RuntimeException('The blue-green candidate container set column is missing.');
        }
        if (! Schema::hasColumns('application_blue_green_deployments', [
            'operation_candidate_container_name',
            'operation_candidate_container_id',
        ])) {
            throw new RuntimeException('The scalar blue-green candidate container identity must remain authoritative.');
        }
    }
};
