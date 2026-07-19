<?php

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Enums\ActivityTypes;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class);

it('uses a positive activity-specific command timeout', function () {
    $activity = Mockery::mock(Activity::class);
    $activity->shouldReceive('getExtraProperty')
        ->with('type')
        ->andReturn(ActivityTypes::INLINE->value);
    $activity->shouldReceive('getExtraProperty')
        ->with('command_timeout')
        ->andReturn(600);
    $runner = new RunRemoteProcess($activity);
    $commandTimeout = new ReflectionMethod($runner, 'commandTimeout');

    expect($commandTimeout->invoke($runner))->toBe(600);
});

it('falls back to the configured timeout for missing or invalid activity values', function (mixed $activityTimeout) {
    config()->set('constants.ssh.command_timeout', 3600);
    $activity = Mockery::mock(Activity::class);
    $activity->shouldReceive('getExtraProperty')
        ->with('type')
        ->andReturn(ActivityTypes::INLINE->value);
    $activity->shouldReceive('getExtraProperty')
        ->with('command_timeout')
        ->andReturn($activityTimeout);
    $runner = new RunRemoteProcess($activity);
    $commandTimeout = new ReflectionMethod($runner, 'commandTimeout');

    expect($commandTimeout->invoke($runner))->toBe(3600);
})->with([null, 0, -1, '600']);
