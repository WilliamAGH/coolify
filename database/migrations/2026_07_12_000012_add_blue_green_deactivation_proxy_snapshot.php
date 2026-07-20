<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('application_blue_green_deactivations', 'proxy_snapshot')) {
            $this->assertExactSchema();

            return;
        }

        Schema::table('application_blue_green_deactivations', function (Blueprint $table) {
            $table->text('proxy_snapshot')->nullable()->after('queue_cutoff_id');
        });
    }

    public function assertExactSchema(): void
    {
        $column = collect(Schema::getColumns('application_blue_green_deactivations'))
            ->firstWhere('name', 'proxy_snapshot');
        if (! is_array($column)
            || ! $column['nullable']
            || ! in_array($column['type_name'], ['text', 'clob'], true)) {
            throw new RuntimeException('Existing blue-green deactivation proxy snapshot column does not match.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Control-plane expand migration is forward-only: 2026_07_12_000012_add_blue_green_deactivation_proxy_snapshot.');
    }
};
