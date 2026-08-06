<?php

/**
 * A compose file may bind a path that exists but is neither a regular file nor
 * a directory — masking a docker socket with /dev/null is the common case.
 * `test -f` and `test -d` both answer NOK for those, which the volume sync read
 * as "does not exist" and answered with `mkdir -p`, failing with "File exists"
 * on every run and failing ServerFilesFromServerJob with it.
 */

use App\Models\LocalFileVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function deviceBindFixture(string $fsPath): ServiceApplication
{
    $team = Team::factory()->create();
    $privateKey = PrivateKey::query()->create([
        'name' => 'device bind test key',
        'private_key' => generateSSHKey('ed25519')['private'],
        'team_id' => $team->id,
    ]);
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $service = Service::query()->create([
        'name' => 'alloy-like',
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  alloy:\n    image: grafana/alloy\n",
        'docker_compose' => "services:\n  alloy:\n    image: grafana/alloy\n",
    ]);
    $application = ServiceApplication::query()->create([
        'name' => 'alloy',
        'service_id' => $service->id,
        'image' => 'grafana/alloy',
    ]);
    LocalFileVolume::query()->create([
        'fs_path' => $fsPath,
        'mount_path' => '/var/run/docker.sock',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
        'is_directory' => false,
    ]);

    return $application;
}

/**
 * Answers the probes the volume sync issues, so the assertion is about which
 * commands the sync chooses to send rather than about SSH.
 */
function fakeHostWhereDevNullExists(): void
{
    Process::fake(function ($process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        if (str_contains($command, 'test -f')) {
            return Process::result(output: 'NOK');
        }
        if (str_contains($command, 'test -d')) {
            return Process::result(output: 'NOK');
        }
        if (str_contains($command, 'test -e')) {
            return Process::result(output: 'OK');
        }

        return Process::result(output: '');
    });
}

it('never tries to create a bind source that already exists as a device', function (): void {
    fakeHostWhereDevNullExists();
    $application = deviceBindFixture('/dev/null');

    $application->getFilesFromServer(isInit: true);

    Process::assertNotRan(fn ($process): bool => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
        'mkdir -p /dev/null',
    ));
});

it('leaves the device volume unflagged as a directory', function (): void {
    fakeHostWhereDevNullExists();
    $application = deviceBindFixture('/dev/null');

    $application->getFilesFromServer(isInit: true);

    // Recording it as a directory would make Coolify try to manage a device node
    // as a folder on every later sync.
    expect(LocalFileVolume::query()->firstOrFail()->is_directory)->toBeFalse();
});
