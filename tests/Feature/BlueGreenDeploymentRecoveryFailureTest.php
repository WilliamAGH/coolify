<?php

use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\ReconstructBlueGreenDeploymentRecovery;
use App\Enums\ApplicationDeploymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

it('fails closed when a newer supersession generation leaves the queue stale', function () {
    $scenario = BlueGreenRecoveryScenario::create();
    $scenario->state->update(['supersession_generation' => 2]);

    expect(fn () => ReconstructBlueGreenDeploymentRecovery::run($scenario->state))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'generation');
});

it('fails closed when the queue generation no longer matches the durable state', function () {
    $scenario = BlueGreenRecoveryScenario::create();
    $scenario->deployment->update(['blue_green_supersession_generation' => 2]);

    expect(fn () => ReconstructBlueGreenDeploymentRecovery::run($scenario->state))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'generation');
});

it('fails closed for non-compensable terminal queue ownership', function (ApplicationDeploymentStatus $status) {
    $scenario = BlueGreenRecoveryScenario::create();
    $scenario->deployment->update(['status' => $status->value]);

    expect(fn () => ReconstructBlueGreenDeploymentRecovery::run($scenario->state))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'live generation');
})->with([
    'finished queue' => ApplicationDeploymentStatus::FINISHED,
    'failed queue' => ApplicationDeploymentStatus::FAILED,
]);
