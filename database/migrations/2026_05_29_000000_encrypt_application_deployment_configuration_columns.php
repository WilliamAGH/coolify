<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The configuration snapshot/diff now store an encrypted blob (not valid
     * JSON), so the columns must hold arbitrary text instead of json.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE application_deployment_queues ALTER COLUMN configuration_snapshot TYPE text USING configuration_snapshot::text');
            DB::statement('ALTER TABLE application_deployment_queues ALTER COLUMN configuration_diff TYPE text USING configuration_diff::text');

            return;
        }

        Schema::table('application_deployment_queues', function (Blueprint $table): void {
            $table->text('configuration_snapshot')->nullable()->change();
            $table->text('configuration_diff')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE application_deployment_queues ALTER COLUMN configuration_snapshot TYPE json USING configuration_snapshot::json');
            DB::statement('ALTER TABLE application_deployment_queues ALTER COLUMN configuration_diff TYPE json USING configuration_diff::json');

            return;
        }

        Schema::table('application_deployment_queues', function (Blueprint $table): void {
            $table->json('configuration_snapshot')->nullable()->change();
            $table->json('configuration_diff')->nullable()->change();
        });
    }
};
