<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriter;
use App\Actions\Proxy\ControlPlane\RollBackControlPlaneProxyEnrollment;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function controlPlaneRollbackState(string $token, ?string $dynamicPredecessorBytes): ControlPlaneProxyEnrollmentState
{
    return new ControlPlaneProxyEnrollmentState(
        phase: ControlPlaneProxyEnrollmentPhase::Active,
        operationId: 'control-plane-rollback',
        tokenSha256: hash('sha256', $token),
        serverId: 0,
        appPort: 8000,
        exposure: ControlPlaneProxyExposure::Public,
        managedFilename: ControlPlaneDynamicConfiguration::MANAGED_FILENAME,
        dynamicRevision: 1,
        canonicalHost: 'dashboard.example.test',
        publicScheme: 'https',
        expectedMember: 'blue',
        expectedRevision: 'revision-42',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        activeBackendDnsNames: ['coolify-web-a'],
        staticPredecessorBytes: "services:\n  traefik:\n    ports: ['80:80']\n",
        staticReplacementBytes: "services:\n  traefik:\n    ports: ['80:80', '8000:8000']\n",
        sourceOverrideBytes: "services:\n  coolify:\n    ports: !reset []\n",
        dynamicPredecessorBytes: $dynamicPredecessorBytes,
        dynamicReplacementBytes: "http:\n  routers:\n    coolify: {}\n",
        createdAt: '2026-07-19T12:00:00Z',
        updatedAt: '2026-07-19T12:00:00Z',
    );
}

function runControlPlaneRollbackCommand(string $command): Process
{
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(10);
    $process->run();

    return $process;
}

function controlPlaneRollbackRoot(): string
{
    return sys_get_temp_dir().'/coolify-control-plane-rollback-'.bin2hex(random_bytes(8));
}

it('plans an owned rollback that restores the exact dynamic predecessor and carries the static handoff inputs', function (): void {
    $filesystem = new Filesystem;
    $root = controlPlaneRollbackRoot();
    $token = 'control-plane-rollback-token';
    $legacyDynamicBytes = "http:\n  routers:\n    legacy: {}\n";

    try {
        $filesystem->mkdir($root.'/dynamic', 0700);
        file_put_contents($root.'/dynamic/'.ControlPlaneDynamicConfiguration::MANAGED_FILENAME, $legacyDynamicBytes);

        $plan = RollBackControlPlaneProxyEnrollment::plan(
            state: controlPlaneRollbackState($token, $legacyDynamicBytes),
            operationId: 'control-plane-rollback',
            token: $token,
            timestamp: '2026-07-19T12:01:00Z',
            dynamicDirectory: $root.'/dynamic',
            stateDirectory: $root.'/state',
        );
        $writer = new ManagedTraefikDocumentWriter;

        expect($plan->rollingBackState->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RollingBack)
            ->and($plan->rolledBackState->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RolledBack)
            ->and($plan->staticPredecessorBytes)->toBe("services:\n  traefik:\n    ports: ['80:80']\n")
            ->and($plan->sourceOverrideBytes)->toBe("services:\n  coolify:\n    ports: !reset []\n")
            ->and($plan->dynamicMutation)->not->toBeNull();

        expect(runControlPlaneRollbackCommand($writer->writeCommandFor($plan->dynamicMutation))->isSuccessful())->toBeTrue()
            ->and(runControlPlaneRollbackCommand($plan->dynamicRollbackCommand($writer) ?? 'false')->isSuccessful())->toBeTrue()
            ->and(runControlPlaneRollbackCommand($plan->dynamicRollbackCommand($writer) ?? 'false')->isSuccessful())->toBeTrue()
            ->and(file_get_contents($root.'/dynamic/'.ControlPlaneDynamicConfiguration::MANAGED_FILENAME))->toBe($legacyDynamicBytes);
    } finally {
        $filesystem->remove($root);
    }
});

it('fails closed for a foreign owner or a missing dynamic rollback artifact', function (): void {
    $filesystem = new Filesystem;
    $root = controlPlaneRollbackRoot();
    $token = 'control-plane-rollback-token';

    try {
        $plan = RollBackControlPlaneProxyEnrollment::plan(
            state: controlPlaneRollbackState($token, null),
            operationId: 'control-plane-rollback',
            token: $token,
            timestamp: '2026-07-19T12:01:00Z',
            dynamicDirectory: $root.'/dynamic',
            stateDirectory: $root.'/state',
        );

        expect(runControlPlaneRollbackCommand($plan->dynamicRollbackCommand(new ManagedTraefikDocumentWriter) ?? 'false')->isSuccessful())
            ->toBeFalse()
            ->and(fn (): RollBackControlPlaneProxyEnrollment => RollBackControlPlaneProxyEnrollment::plan(
                state: controlPlaneRollbackState($token, null),
                operationId: 'another-operation',
                token: 'another-token',
                timestamp: '2026-07-19T12:01:00Z',
                dynamicDirectory: $root.'/dynamic',
                stateDirectory: $root.'/state',
            ))->toThrow(InvalidArgumentException::class, 'owned by another operation');
    } finally {
        $filesystem->remove($root);
    }
});

it('does not schedule another dynamic mutation for an already rolled-back owner replay', function (): void {
    $token = 'control-plane-rollback-token';
    $state = controlPlaneRollbackState($token, null)
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-19T12:01:00Z')
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RolledBack, '2026-07-19T12:02:00Z');

    $plan = RollBackControlPlaneProxyEnrollment::plan(
        state: $state,
        operationId: 'control-plane-rollback',
        token: $token,
        timestamp: '2026-07-19T12:03:00Z',
        dynamicDirectory: '/tmp/dynamic',
        stateDirectory: '/tmp/state',
    );

    expect($plan->rollingBackState)->toBe($state)
        ->and($plan->rolledBackState)->toBe($state)
        ->and($plan->dynamicRollbackCommand(new ManagedTraefikDocumentWriter))->toBeNull();
});
