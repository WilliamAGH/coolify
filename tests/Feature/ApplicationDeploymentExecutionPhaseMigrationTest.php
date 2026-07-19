<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function applicationDeploymentExecutionPhaseMigration(): object
{
    return require database_path('migrations/2026_07_19_130000_add_execution_phase_to_application_deployment_queues.php');
}

it('replays and attests the complete deployment execution phase schema', function () {
    $migration = applicationDeploymentExecutionPhaseMigration();

    $migration->up();
    $migration->up();
    $migration->assertExactSchema();

    $columns = collect(Schema::getColumns('application_deployment_queues'))->keyBy('name');

    expect($columns)->toHaveKeys(['execution_phase', 'prepared_activation_payload'])
        ->and(fn () => $migration->down())
        ->toThrow(
            RuntimeException::class,
            'Application deployment execution-phase migration is forward-only: 2026_07_19_130000_add_execution_phase_to_application_deployment_queues.',
        );
});

it('refuses a partial execution phase schema replay', function () {
    Schema::table('application_deployment_queues', function (Blueprint $table): void {
        $table->dropColumn('prepared_activation_payload');
    });

    expect(fn () => applicationDeploymentExecutionPhaseMigration()->up())
        ->toThrow(
            RuntimeException::class,
            'Application deployment execution-phase columns are partial; refusing a non-convergent replay.',
        );
});
