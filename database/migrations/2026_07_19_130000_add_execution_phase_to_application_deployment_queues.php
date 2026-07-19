<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'execution_phase',
            'prepared_activation_payload',
        ];
        $present = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn('application_deployment_queues', $column),
        ));
        if ($present === []) {
            Schema::table('application_deployment_queues', function (Blueprint $table): void {
                $table->string('execution_phase', 16)->default('prepare');
                $table->text('prepared_activation_payload')->nullable();
            });
        } elseif ($present !== $columns) {
            throw new RuntimeException('Application deployment execution-phase columns are partial; refusing a non-convergent replay.');
        }

        $this->assertExactSchema();
    }

    public function assertExactSchema(): void
    {
        $columns = collect(Schema::getColumns('application_deployment_queues'))->keyBy('name');
        $phase = $columns->get('execution_phase');
        $payload = $columns->get('prepared_activation_payload');
        $phaseDefault = strtolower(trim((string) ($phase['default'] ?? ''), "()'\""));

        if (! is_array($phase)
            || ($phase['nullable'] ?? null) !== false
            || ! in_array(strtolower((string) ($phase['type_name'] ?? '')), ['varchar', 'string'], true)
            || $phaseDefault !== 'prepare') {
            throw new RuntimeException('Application deployment execution phase does not match the authorized schema.');
        }
        if (! is_array($payload)
            || ($payload['nullable'] ?? null) !== true
            || ! in_array(strtolower((string) ($payload['type_name'] ?? '')), ['text', 'clob'], true)
            || ($payload['default'] ?? null) !== null) {
            throw new RuntimeException('Application deployment prepared activation payload does not match the authorized schema.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Application deployment execution-phase migration is forward-only: 2026_07_19_130000_add_execution_phase_to_application_deployment_queues.');
    }
};
