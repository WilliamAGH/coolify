<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\LocalFileVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $privateKey = PrivateKey::query()->create([
        'name' => 'Filesystem volume test key',
        'private_key' => generateSSHKey('ed25519')['private'],
        'team_id' => $this->team->id,
    ]);
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $this->destination = $this->server->standaloneDockers()->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    // File volumes are writable storage, which an opted-in blue-green
    // application refuses to record; this helper is the plain-deployment path.
    $this->application->settings->fill([
        'is_blue_green_deployment_enabled' => false,
    ])->save();
});

/**
 * Fakes the three probes the helper runs per file volume. A special file --
 * device node, socket, or fifo -- answers NOK to both `test -f` and `test -d`
 * but OK to `test -e`, which is exactly the shape that used to fall through to
 * the mkdir branches.
 *
 * @param  list<string>  $payloads
 */
function fakeFilesystemVolumeProbes(array &$payloads, bool $exists): void
{
    Process::fake(function (PendingProcess $process) use (&$payloads, $exists) {
        $payload = (string) $process->command."\n".(string) $process->input;
        $payloads[] = $payload;
        if (str_contains($payload, 'test -f ')) {
            return Process::result(output: 'NOK');
        }
        if (str_contains($payload, 'test -d ')) {
            return Process::result(output: 'NOK');
        }
        if (str_contains($payload, 'test -e ')) {
            return Process::result(output: $exists ? 'OK' : 'NOK');
        }

        return Process::result(output: '');
    });
}

it('leaves an existing special file alone instead of running mkdir over it', function (): void {
    Queue::fake();
    $payloads = [];
    fakeFilesystemVolumeProbes($payloads, exists: true);
    // The common case: a compose file masking the docker socket with /dev/null.
    $volume = LocalFileVolume::create([
        'fs_path' => '/dev/null',
        'mount_path' => '/var/run/docker.sock',
        'content' => null,
        'is_directory' => false,
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);
    // Only the helper's own probes are under test; the save-side writes above
    // are not.
    $payloads = [];

    getFilesystemVolumesFromServer($this->application, isInit: true);

    // Coolify did not create the device node and cannot recreate it, so the
    // run must not try: `mkdir -p /dev/null` fails with "File exists" on every
    // single invocation, which is what kept these deployments noisy.
    $mkdirCalls = array_values(array_filter(
        $payloads,
        fn (string $payload): bool => str_contains($payload, 'mkdir -p /dev/null'),
    ));
    expect($mkdirCalls)->toBe([]);

    // The durable row is left exactly as the host has it.
    $stored = LocalFileVolume::query()->findOrFail($volume->id);
    expect($stored->is_directory)->toBeFalse()
        ->and($stored->content)->toBeNull();
});

it('still creates a directory for a path that genuinely does not exist', function (): void {
    Queue::fake();
    $payloads = [];
    fakeFilesystemVolumeProbes($payloads, exists: false);
    LocalFileVolume::create([
        'fs_path' => '/data/coolify-absent-dir',
        'mount_path' => '/data',
        'content' => null,
        'is_directory' => false,
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);
    $payloads = [];

    getFilesystemVolumesFromServer($this->application, isInit: true);

    // The `test -e` guard must not swallow the ordinary create-it path: an
    // absent path answers NOK to all three probes and still gets its directory.
    $mkdirCalls = array_values(array_filter(
        $payloads,
        fn (string $payload): bool => str_contains($payload, 'mkdir -p /data/coolify-absent-dir'),
    ));
    expect($mkdirCalls)->not->toBe([]);
});
