<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\CleanupHelperContainersJob;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

uses(LazilyRefreshDatabase::class);

function createHelperDeployment(
    Server $server,
    string $deploymentUuid,
    ApplicationDeploymentStatus $status = ApplicationDeploymentStatus::FINISHED,
    ?Server $buildServer = null,
): void {
    ApplicationDeploymentQueue::query()->create([
        'application_id' => "application-{$deploymentUuid}",
        'application_name' => "Application {$deploymentUuid}",
        'server_id' => $server->id,
        'server_name' => $server->name,
        'build_server_id' => $buildServer?->id,
        'deployment_uuid' => $deploymentUuid,
        'commit' => 'helper-cleanup-test',
        'status' => $status->value,
    ]);
}

it('scopes cleanup uniqueness to each server', function () {
    $firstServer = Server::factory()->create();
    $secondServer = Server::factory()->create();
    $job = new CleanupHelperContainersJob($firstServer);

    expect($job->uniqueId())
        ->toBe($firstServer->uuid)
        ->not->toBe((new CleanupHelperContainersJob($secondServer))->uniqueId())
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 30]);
});

it('surfaces Docker transport failures for the queue retry lifecycle', function () {
    config(['constants.ssh.mux_enabled' => false]);

    $server = Server::factory()->create();
    Process::fake(fn () => throw new RuntimeException('docker transport unavailable'));

    expect(fn () => (new CleanupHelperContainersJob($server))->handle())
        ->toThrow(RuntimeException::class, 'docker transport unavailable');
});

it('removes only terminal deployment helpers owned by the same server', function () {
    config(['constants.ssh.mux_enabled' => false]);

    $server = Server::factory()->create();
    $otherServer = Server::factory()->create();
    $activeDeploymentUuid = 'active-deployment-on-this-server';
    $terminalDeploymentUuid = 'terminal-deployment-on-this-server';
    $digestDeploymentUuid = 'digest-deployment-on-this-server';
    $bareDeploymentUuid = 'bare-deployment-on-this-server';
    $otherServerDeploymentUuid = 'terminal-deployment-on-another-server';
    $buildServerTerminalUuid = 'terminal-deployment-built-on-this-server';
    $buildServerActiveUuid = 'active-deployment-built-on-this-server';
    $prunedDeploymentUuid = 'a1b2c3d4e5f6g7h8i9j0k1l2';
    createHelperDeployment($server, $activeDeploymentUuid, ApplicationDeploymentStatus::IN_PROGRESS);
    createHelperDeployment($server, $terminalDeploymentUuid);
    createHelperDeployment($server, $digestDeploymentUuid);
    createHelperDeployment($server, $bareDeploymentUuid);
    createHelperDeployment($otherServer, $otherServerDeploymentUuid);
    createHelperDeployment($otherServer, $buildServerTerminalUuid, buildServer: $server);
    createHelperDeployment(
        $otherServer,
        $buildServerActiveUuid,
        ApplicationDeploymentStatus::IN_PROGRESS,
        $server,
    );

    $commands = [];
    Process::fake(function (PendingProcess $process) use (
        &$commands,
        $activeDeploymentUuid,
        $terminalDeploymentUuid,
        $digestDeploymentUuid,
        $bareDeploymentUuid,
        $otherServerDeploymentUuid,
        $buildServerTerminalUuid,
        $buildServerActiveUuid,
        $prunedDeploymentUuid,
    ) {
        $command = (string) $process->command;
        $commands[] = $command;

        if (str_contains($command, 'docker container ps')) {
            return Process::result(output: json_encode([
                [
                    'ID' => 'active-helper',
                    'Image' => 'coollabsio/coolify-helper:1.0.14',
                    'Names' => $activeDeploymentUuid,
                ],
                [
                    'ID' => 'terminal-helper',
                    'Image' => 'coollabsio/coolify-helper:1.0.14',
                    'Names' => $terminalDeploymentUuid,
                ],
                [
                    'ID' => 'digest-helper',
                    'Image' => 'docker.io/coollabsio/coolify-helper@sha256:abc123',
                    'Names' => $digestDeploymentUuid,
                ],
                [
                    'ID' => 'bare-helper',
                    'Image' => 'coollabsio/coolify-helper',
                    'Names' => $bareDeploymentUuid,
                ],
                [
                    'ID' => 'other-server-helper',
                    'Image' => 'coollabsio/coolify-helper:1.0.14',
                    'Names' => $otherServerDeploymentUuid,
                ],
                [
                    'ID' => 'build-server-terminal-helper',
                    'Image' => 'coollabsio/coolify-helper:1.0.14',
                    'Names' => $buildServerTerminalUuid,
                ],
                [
                    'ID' => 'build-server-active-helper',
                    'Image' => 'coollabsio/coolify-helper:1.0.14',
                    'Names' => $buildServerActiveUuid,
                ],
                [
                    'ID' => 'pruned-deployment-helper',
                    'Image' => 'coollabsio/coolify-helper:1.0.14',
                    'Names' => $prunedDeploymentUuid,
                ],
                [
                    'ID' => 'backup-helper',
                    'Image' => 'coollabsio/coolify-helper:1.0.14',
                    'Names' => 'backup-of-postgresql-database',
                ],
                [
                    'ID' => 'restore-helper',
                    'Image' => 'coollabsio/coolify-helper:1.0.14',
                    'Names' => 's3-restore-postgresql-database',
                ],
                [
                    'ID' => 'foreign-registry-lookalike',
                    'Image' => 'attacker.example/tenant/coollabsio/coolify-helper:1.0.14',
                    'Names' => $terminalDeploymentUuid,
                ],
                [
                    'ID' => 'repository-lookalike',
                    'Image' => 'coollabsio/coolify-helper-malicious:1.0.14',
                    'Names' => $terminalDeploymentUuid,
                ],
            ], JSON_THROW_ON_ERROR));
        }

        return Process::result();
    });

    (new CleanupHelperContainersJob($server))->handle();

    $listingCommand = collect($commands)->first(
        fn (string $command): bool => str_contains($command, 'docker container ps'),
    );
    $removalCommands = collect($commands)
        ->filter(fn (string $command): bool => str_contains($command, 'docker container rm -f'))
        ->values();

    expect($listingCommand)
        ->toContain("docker container ps --format '{{json .}}' | jq -s '.'")
        ->and($removalCommands->contains(
            fn (string $command): bool => str_contains($command, 'docker container rm -f terminal-helper'),
        ))->toBeTrue()
        ->and($removalCommands->contains(
            fn (string $command): bool => str_contains($command, 'docker container rm -f digest-helper'),
        ))->toBeTrue()
        ->and($removalCommands->contains(
            fn (string $command): bool => str_contains($command, 'docker container rm -f bare-helper'),
        ))->toBeTrue()
        ->and($removalCommands->contains(
            fn (string $command): bool => str_contains($command, 'docker container rm -f active-helper'),
        ))->toBeFalse()
        ->and($removalCommands->contains(
            fn (string $command): bool => str_contains($command, 'docker container rm -f other-server-helper'),
        ))->toBeFalse()
        ->and($removalCommands->contains(
            fn (string $command): bool => str_contains($command, 'docker container rm -f build-server-terminal-helper'),
        ))->toBeTrue()
        ->and($removalCommands->contains(
            fn (string $command): bool => str_contains($command, 'docker container rm -f build-server-active-helper'),
        ))->toBeFalse()
        ->and($removalCommands->contains(
            fn (string $command): bool => str_contains($command, 'docker container rm -f pruned-deployment-helper'),
        ))->toBeTrue()
        ->and($removalCommands->contains(
            fn (string $command): bool => str_contains($command, 'docker container rm -f backup-helper'),
        ))->toBeFalse()
        ->and($removalCommands->contains(
            fn (string $command): bool => str_contains($command, 'docker container rm -f restore-helper'),
        ))->toBeFalse()
        ->and($removalCommands->contains(
            fn (string $command): bool => str_contains($command, 'docker container rm -f foreign-registry-lookalike'),
        ))->toBeFalse()
        ->and($removalCommands->contains(
            fn (string $command): bool => str_contains($command, 'docker container rm -f repository-lookalike'),
        ))->toBeFalse();
});

it('limits image ownership to the configured registry repository', function () {
    config([
        'constants.ssh.mux_enabled' => false,
        'constants.coolify.helper_image' => 'registry.example.com/custom/helper',
    ]);

    $server = Server::factory()->create();
    createHelperDeployment($server, 'configured-helper');
    createHelperDeployment($server, 'docker-hub-helper');
    createHelperDeployment($server, 'configured-lookalike');
    $commands = [];
    Process::fake(function (PendingProcess $process) use (&$commands) {
        $command = (string) $process->command;
        $commands[] = $command;

        if (str_contains($command, 'docker container ps')) {
            return Process::result(output: json_encode([
                [
                    'ID' => 'configured-helper-id',
                    'Image' => 'registry.example.com/custom/helper:1.0.14',
                    'Names' => 'configured-helper',
                ],
                [
                    'ID' => 'docker-hub-helper-id',
                    'Image' => 'coollabsio/coolify-helper:1.0.14',
                    'Names' => 'docker-hub-helper',
                ],
                [
                    'ID' => 'configured-lookalike-id',
                    'Image' => 'registry.example.com/custom/helper-malicious:1.0.14',
                    'Names' => 'configured-lookalike',
                ],
            ], JSON_THROW_ON_ERROR));
        }

        return Process::result();
    });

    (new CleanupHelperContainersJob($server))->handle();

    $removalCommands = collect($commands)
        ->filter(fn (string $command): bool => str_contains($command, 'docker container rm -f'))
        ->values();

    expect($removalCommands->contains(
        fn (string $command): bool => str_contains($command, 'docker container rm -f configured-helper-id'),
    ))->toBeTrue()
        ->and($removalCommands->contains(
            fn (string $command): bool => str_contains($command, 'docker container rm -f docker-hub-helper-id'),
        ))->toBeFalse()
        ->and($removalCommands->contains(
            fn (string $command): bool => str_contains($command, 'docker container rm -f configured-lookalike-id'),
        ))->toBeFalse();
});
