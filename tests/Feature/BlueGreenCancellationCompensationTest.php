<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\CompleteBlueGreenDeploymentOperation;
use App\Actions\Application\BlueGreen\ReconstructBlueGreenDeploymentRecovery;
use App\Actions\Application\BlueGreen\RecordBlueGreenCandidateIdentity;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenDeactivationScenario;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

it('compensates a claimed cancelled blue-green operation exactly once', function (ApplicationDeploymentStatus $cancellationStatus): void {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $application->update([
        'health_check_enabled' => true,
        'ports_mappings' => null,
    ]);
    $application->settings()->update([
        'is_blue_green_deployment_enabled' => true,
        'is_container_label_readonly_enabled' => true,
    ]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'cancelled-lifecycle-compensation',
        'pull_request_id' => 0,
        'commit' => 'cancelled-lifecycle-compensation-commit',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'only_this_server' => true,
    ]);
    $bootId = BlueGreenDeactivationScenario::BOOT_ID;
    Process::fake(['*' => Process::sequence([
        $bootId,
        'coolify-blue-green-destination-state-attested',
        '',
        $bootId,
    ])]);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment,
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    $lifecycle->initialize();
    $claim = $lifecycle->claim();
    $candidateId = str_repeat('b', 64);
    RecordBlueGreenCandidateIdentity::run(
        $claim,
        new BlueGreenContainerInspection(
            exists: true,
            dockerId: $candidateId,
            status: 'running',
            health: 'healthy',
        ),
    );
    $candidateInspection = json_encode([
        'Id' => $candidateId,
        'Name' => '/'.$application->uuid.'-blue',
        'State' => [
            'Status' => 'running',
            'Health' => ['Status' => 'healthy'],
        ],
        'Config' => [
            'Labels' => [
                'coolify.applicationId' => (string) $application->id,
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => $deployment->deployment_uuid,
                'coolify.blueGreen.color' => $claim->pendingColor->value,
                'coolify.blueGreen.routingRevision' => (string) $claim->expectedRoutingRevision,
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $compensationProcessCalls = 0;
    $phaseDuringCandidateCompensation = null;
    Process::fake(function (PendingProcess $process) use (
        $bootId,
        $candidateInspection,
        $claim,
        &$compensationProcessCalls,
        &$phaseDuringCandidateCompensation,
    ) {
        $compensationProcessCalls++;
        if (str_contains($process->command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: $bootId, exitCode: 0);
        }
        if (str_contains($process->command, 'coolify-blue-green-container:missing')) {
            $phaseDuringCandidateCompensation ??= ApplicationBlueGreenDeployment::query()
                ->findOrFail($claim->stateId)
                ->phase;

            return Process::result(output: $candidateInspection, exitCode: 0);
        }

        return Process::result(output: '', exitCode: 0);
    });
    $cancelledAt = now()->subMinute()->startOfSecond();
    $deployment->update([
        'status' => $cancellationStatus->value,
        'finished_at' => $cancelledAt,
    ]);
    $cause = new RuntimeException('The claimed deployment was cancelled.');

    try {
        expect($lifecycle->rollback($cause))->toBe($cause);

        $state = ApplicationBlueGreenDeployment::query()->findOrFail($claim->stateId);
        $queue = $deployment->fresh();
        expect($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
            ->and($state->pending_color)->toBeNull()
            ->and($state->pending_deployment_uuid)->toBeNull()
            ->and($phaseDuringCandidateCompensation)->toBe(BlueGreenDeploymentPhase::ROLLING_BACK)
            ->and($state->destination_fence_operation_id)->toBe($claim->deploymentUuid)
            ->and($state->destination_fence_mutation_sequence)->toBe(1)
            ->and($queue->status)->toBe($cancellationStatus->value)
            ->and($queue->finished_at->equalTo($cancelledAt))->toBeTrue();
        foreach (array_keys(ApplicationBlueGreenDeployment::clearedOperationAttributes()) as $attribute) {
            expect($state->{$attribute})->toBeNull();
        }

        $firstCompensationProcessCalls = $compensationProcessCalls;
        expect($lifecycle->rollback($cause))->toBe($cause);
        expect($compensationProcessCalls)->toBe($firstCompensationProcessCalls);
    } finally {
        $lifecycle->release();
    }
})->with([
    'user cancellation' => ApplicationDeploymentStatus::CANCELLED_BY_USER,
    'fleet cancellation' => ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET,
]);

it('completes finalized idle cleanup after cancellation without finishing the queue', function (ApplicationDeploymentStatus $cancellationStatus): void {
    $scenario = BlueGreenRecoveryScenario::create();
    $cancelledAt = now()->subMinute()->startOfSecond();
    $scenario->deployment->update([
        'status' => $cancellationStatus->value,
        'finished_at' => $cancelledAt,
    ]);

    $state = CompleteBlueGreenDeploymentOperation::run(
        ReconstructBlueGreenDeploymentRecovery::run($scenario->state),
    );

    expect($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->operation_deployment_uuid)->toBeNull()
        ->and($scenario->deployment->fresh()->status)->toBe($cancellationStatus->value)
        ->and($scenario->deployment->fresh()->finished_at->equalTo($cancelledAt))->toBeTrue();
})->with([
    'user cancellation' => ApplicationDeploymentStatus::CANCELLED_BY_USER,
    'fleet cancellation' => ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET,
]);
