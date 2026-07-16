<?php

use App\Actions\Application\BlueGreen\BlueGreenDeploymentQueueActivity;
use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-07-13 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('uses the safety window and Horizon lifecycle status to classify queue activity', function (
    int $ageSeconds,
    string $horizonStatus,
    string $deploymentStatus,
    bool $active,
) {
    $deployment = new ApplicationDeploymentQueue([
        'status' => $deploymentStatus,
    ]);
    $deployment->updated_at = now()->subSeconds($ageSeconds);

    expect(BlueGreenDeploymentQueueActivity::run($deployment, 300, $horizonStatus))->toBe($active);
})->with([
    'recent pending' => [300, 'pending', ApplicationDeploymentStatus::QUEUED->value, true],
    'recent reserved' => [300, 'reserved', ApplicationDeploymentStatus::IN_PROGRESS->value, true],
    'recent failed' => [300, 'failed', ApplicationDeploymentStatus::QUEUED->value, true],
    'recent completed' => [300, 'completed', ApplicationDeploymentStatus::IN_PROGRESS->value, true],
    'recent unknown' => [300, 'unknown', ApplicationDeploymentStatus::QUEUED->value, true],
    'stale pending' => [301, 'pending', ApplicationDeploymentStatus::QUEUED->value, true],
    'stale reserved' => [301, 'reserved', ApplicationDeploymentStatus::IN_PROGRESS->value, true],
    'stale running' => [301, 'running', ApplicationDeploymentStatus::IN_PROGRESS->value, true],
    'stale failed queued row' => [301, 'failed', ApplicationDeploymentStatus::QUEUED->value, false],
    'stale completed in-progress row' => [301, 'completed', ApplicationDeploymentStatus::IN_PROGRESS->value, false],
    'stale unknown' => [301, 'unknown', ApplicationDeploymentStatus::IN_PROGRESS->value, false],
]);
