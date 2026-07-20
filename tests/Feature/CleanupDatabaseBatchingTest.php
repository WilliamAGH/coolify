<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('deletes old activity and deployment rows in bounded batches while preserving the newest ten', function () {
    $oldestTimestamp = now()->subDays(90);
    $activityRows = [];
    $deploymentRows = [];

    foreach (range(1, 1015) as $sequence) {
        $timestamp = $oldestTimestamp->clone()->addSeconds($sequence);
        $activityRows[] = [
            'description' => "activity-{$sequence}",
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
        $deploymentRows[] = [
            'application_id' => '1',
            'deployment_uuid' => "cleanup-deployment-{$sequence}",
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    foreach (array_chunk($activityRows, 500) as $rows) {
        DB::table('activity_log')->insert($rows);
    }
    foreach (array_chunk($deploymentRows, 500) as $rows) {
        DB::table('application_deployment_queues')->insert($rows);
    }

    $this->artisan('cleanup:database', ['--yes' => true, '--keep-days' => 60])
        ->assertSuccessful();

    expect(DB::table('activity_log')->count())->toBe(10)
        ->and(DB::table('activity_log')->orderBy('id')->pluck('description')->all())
        ->toBe(array_map(static fn (int $sequence): string => "activity-{$sequence}", range(1006, 1015)))
        ->and(DB::table('application_deployment_queues')->count())->toBe(10)
        ->and(DB::table('application_deployment_queues')->orderBy('id')->pluck('deployment_uuid')->all())
        ->toBe(array_map(static fn (int $sequence): string => "cleanup-deployment-{$sequence}", range(1006, 1015)));
});

it('uses the highest ids to break cleanup cutoff timestamp ties', function () {
    $oldTimestamp = now()->subDays(90);

    foreach (range(1, 12) as $sequence) {
        DB::table('activity_log')->insert([
            'description' => "tied-activity-{$sequence}",
            'created_at' => $oldTimestamp,
            'updated_at' => $oldTimestamp,
        ]);
        DB::table('application_deployment_queues')->insert([
            'application_id' => '1',
            'deployment_uuid' => "tied-deployment-{$sequence}",
            'created_at' => $oldTimestamp,
            'updated_at' => $oldTimestamp,
        ]);
    }

    $expectedActivityIds = DB::table('activity_log')->orderByDesc('id')->limit(10)->pluck('id')->sort()->values()->all();
    $expectedDeploymentIds = DB::table('application_deployment_queues')->orderByDesc('id')->limit(10)->pluck('id')->sort()->values()->all();

    $this->artisan('cleanup:database', ['--yes' => true, '--keep-days' => 60])
        ->assertSuccessful();

    expect(DB::table('activity_log')->orderBy('id')->pluck('id')->all())->toBe($expectedActivityIds)
        ->and(DB::table('application_deployment_queues')->orderBy('id')->pluck('id')->all())->toBe($expectedDeploymentIds);
});
