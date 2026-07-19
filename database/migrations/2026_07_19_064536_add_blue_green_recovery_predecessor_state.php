<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'operation_previous_proxy_state',
            'operation_previous_proxy_state_sha256',
            'operation_rollback_proxy_state',
            'operation_rollback_proxy_state_sha256',
        ];
        $present = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn('application_blue_green_deployments', $column),
        ));
        if ($present === []) {
            Schema::table('application_blue_green_deployments', function (Blueprint $table): void {
                $table->text('operation_previous_proxy_state')->nullable();
                $table->string('operation_previous_proxy_state_sha256', 64)->nullable();
                $table->text('operation_rollback_proxy_state')->nullable();
                $table->string('operation_rollback_proxy_state_sha256', 64)->nullable();
            });
        } elseif ($present !== $columns) {
            throw new RuntimeException('Blue-green recovery predecessor columns are partial; refusing a non-convergent replay.');
        }

        $this->assertExactSchema();
    }

    public function assertExactSchema(): void
    {
        $columns = [
            'operation_previous_proxy_state',
            'operation_previous_proxy_state_sha256',
            'operation_rollback_proxy_state',
            'operation_rollback_proxy_state_sha256',
        ];
        $actual = collect(Schema::getColumns('application_blue_green_deployments'))->keyBy('name');
        foreach ($columns as $column) {
            $definition = $actual->get($column);
            if (! is_array($definition)
                || ($definition['nullable'] ?? null) !== true
                || ($definition['default'] ?? null) !== null) {
                throw new RuntimeException("Blue-green recovery predecessor column is not nullable and default-free: {$column}.");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Control-plane expand migration is forward-only: 2026_07_19_064536_add_blue_green_recovery_predecessor_state.');
    }
};
