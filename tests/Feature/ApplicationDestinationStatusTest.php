<?php

use App\Actions\Shared\ComplexStatusCheck;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
});

it('updates the primary destination status for the id-0 instance-owned server and destination', function (): void {
    $team = Team::factory()->create();
    $privateKey = PrivateKey::query()->create([
        'name' => 'Instance-owned status key',
        'private_key' => generateSSHKey('ed25519')['private'],
        'team_id' => $team->id,
    ]);
    // The instance-owned localhost convention pins both the server and its
    // destination at primary key 0.
    $server = Server::unguarded(fn () => Server::query()->create([
        'id' => 0,
        'name' => 'localhost',
        'ip' => 'host.docker.internal',
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]));
    ServerSetting::unguarded(fn () => ServerSetting::query()->firstOrCreate(['server_id' => 0]));
    $destination = $server->standaloneDockers()->firstOrFail();
    StandaloneDocker::query()->whereKey($destination->id)->update(['id' => 0]);
    $destination = StandaloneDocker::query()->findOrFail(0);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'status' => 'exited',
    ]);

    $updated = (new ComplexStatusCheck)->updateApplicationDestinationStatus(
        $application,
        0,
        0,
        'running:healthy',
    );

    expect($updated)->toBeTrue()
        ->and($application->fresh()->status)->toBe('running:healthy');
});

it('still refuses a negative destination or blank status', function (): void {
    $application = new Application;

    expect(fn () => (new ComplexStatusCheck)->updateApplicationDestinationStatus($application, -1, 1, 'running'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new ComplexStatusCheck)->updateApplicationDestinationStatus($application, 1, -1, 'running'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new ComplexStatusCheck)->updateApplicationDestinationStatus($application, 1, 1, ' '))
        ->toThrow(InvalidArgumentException::class);
});
