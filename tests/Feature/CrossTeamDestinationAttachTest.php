<?php

use App\Actions\Docker\GetContainersStatus;
use App\Livewire\Project\Shared\Destination;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    // Attacker: Team A
    $this->userA = User::factory()->create();
    $this->teamA = Team::factory()->create();
    $this->userA->teams()->attach($this->teamA, ['role' => 'owner']);

    $this->serverA = Server::factory()->create(['team_id' => $this->teamA->id]);
    $this->projectA = Project::factory()->create(['team_id' => $this->teamA->id]);
    $this->environmentA = Environment::factory()->create(['project_id' => $this->projectA->id]);
    $this->destinationA = StandaloneDocker::factory()->create([
        'server_id' => $this->serverA->id,
        'name' => 'dest-a-'.fake()->unique()->word(),
        'network' => 'coolify-a-'.fake()->unique()->word(),
    ]);

    $this->applicationA = Application::factory()->create([
        'environment_id' => $this->environmentA->id,
        'destination_id' => $this->destinationA->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    // A second usable destination on Team A's own server, used for positive-path tests.
    $this->serverA2 = Server::factory()->create(['team_id' => $this->teamA->id]);
    $this->destinationA2 = StandaloneDocker::factory()->create([
        'server_id' => $this->serverA2->id,
        'name' => 'dest-a2-'.fake()->unique()->word(),
        'network' => 'coolify-a2-'.fake()->unique()->word(),
    ]);

    // Victim: Team B
    $this->userB = User::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->userB->teams()->attach($this->teamB, ['role' => 'owner']);

    $this->serverB = Server::factory()->create(['team_id' => $this->teamB->id]);
    $this->destinationB = StandaloneDocker::factory()->create([
        'server_id' => $this->serverB->id,
        'name' => 'dest-b-'.fake()->unique()->word(),
        'network' => 'coolify-b-'.fake()->unique()->word(),
    ]);

    // Act as attacker (Team A)
    $this->actingAs($this->userA);
    session(['currentTeam' => $this->teamA]);
});

afterEach(function () {
    GetContainersStatus::clearFake();
});

describe('Destination::addServer GHSA-j395-3pqh-9r5g', function () {
    test('cannot attach another team\'s server + network to own application', function () {
        try {
            Livewire::test(Destination::class, ['resource' => $this->applicationA])
                ->call('addServer', $this->destinationB->id, $this->serverB->id);
        } catch (Throwable $e) {
            // handleError on ModelNotFoundException calls abort(404); pivot assertion is source of truth.
        }

        expect($this->applicationA->fresh()->additional_networks)->toHaveCount(0);
        expect($this->applicationA->fresh()->additional_servers)->toHaveCount(0);
    });

    test('cannot attach own network paired with another team\'s server', function () {
        try {
            Livewire::test(Destination::class, ['resource' => $this->applicationA])
                ->call('addServer', $this->destinationA2->id, $this->serverB->id);
        } catch (Throwable $e) {
        }

        expect($this->applicationA->fresh()->additional_networks)->toHaveCount(0);
    });

    test('cannot attach another team\'s network paired with own server', function () {
        try {
            Livewire::test(Destination::class, ['resource' => $this->applicationA])
                ->call('addServer', $this->destinationB->id, $this->serverA2->id);
        } catch (Throwable $e) {
        }

        expect($this->applicationA->fresh()->additional_networks)->toHaveCount(0);
    });

    test('cannot attach own network paired with wrong own server', function () {
        try {
            Livewire::test(Destination::class, ['resource' => $this->applicationA])
                ->call('addServer', $this->destinationA2->id, $this->serverA->id);
        } catch (Throwable $e) {
        }

        expect($this->applicationA->fresh()->additional_networks)->toHaveCount(0);
    });

    test('can attach own team\'s server + network to own application', function () {
        Livewire::test(Destination::class, ['resource' => $this->applicationA])
            ->call('addServer', $this->destinationA2->id, $this->serverA2->id);

        $additional = $this->applicationA->fresh()->additional_networks;
        expect($additional)->toHaveCount(1);
        expect($additional->first()->id)->toBe($this->destinationA2->id);
        expect($additional->first()->pivot->server_id)->toBe($this->serverA2->id);
    });
});

describe('Destination::promote GHSA-j395-3pqh-9r5g', function () {
    test('cannot promote another team\'s network as the application\'s main destination', function () {
        $originalDestinationId = $this->applicationA->destination_id;

        try {
            Livewire::test(Destination::class, ['resource' => $this->applicationA])
                ->call('promote', $this->destinationB->id, $this->serverB->id);
        } catch (Throwable $e) {
        }

        expect($this->applicationA->fresh()->destination_id)->toBe($originalDestinationId);
    });

    test('cannot promote own network paired with wrong own server', function () {
        $originalDestinationId = $this->applicationA->destination_id;

        try {
            Livewire::test(Destination::class, ['resource' => $this->applicationA])
                ->call('promote', $this->destinationA2->id, $this->serverA->id);
        } catch (Throwable $e) {
        }

        expect($this->applicationA->fresh()->destination_id)->toBe($originalDestinationId);
    });

    test('can promote own team network and preserve previous main as additional network', function () {
        $this->applicationA->additional_networks()->attach($this->destinationA2->id, ['server_id' => $this->serverA2->id]);

        Livewire::test(Destination::class, ['resource' => $this->applicationA])
            ->call('promote', $this->destinationA2->id, $this->serverA2->id)
            ->assertNotDispatched('error');

        $application = $this->applicationA->fresh();
        $additional = $application->additional_networks;

        expect($application->destination_id)->toBe($this->destinationA2->id);
        expect($additional)->toHaveCount(1);
        expect($additional->first()->id)->toBe($this->destinationA->id);
        expect($additional->first()->pivot->server_id)->toBe($this->serverA->id);
    });

    test('reloads a stale application topology before preserving the current main destination', function () {
        $serverA3 = Server::factory()->create(['team_id' => $this->teamA->id]);
        $destinationA3 = StandaloneDocker::factory()->create([
            'server_id' => $serverA3->id,
            'name' => 'dest-a3-'.fake()->unique()->word(),
            'network' => 'coolify-a3-'.fake()->unique()->word(),
        ]);
        $this->applicationA->additional_networks()->attach($this->destinationA2->id, ['server_id' => $this->serverA2->id]);
        $this->applicationA->additional_networks()->attach($destinationA3->id, ['server_id' => $serverA3->id]);
        $staleComponent = Livewire::test(Destination::class, ['resource' => $this->applicationA->fresh()]);

        Livewire::test(Destination::class, ['resource' => $this->applicationA->fresh()])
            ->call('promote', $this->destinationA2->id, $this->serverA2->id)
            ->assertNotDispatched('error');

        $staleComponent->instance()->promote($destinationA3->id, $serverA3->id);

        $application = $this->applicationA->fresh();
        $additionalDestinationIds = $application->additional_networks
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        expect($application->destination_id)->toBe($destinationA3->id)
            ->and($additionalDestinationIds)->toBe(collect([
                $this->destinationA->id,
                $this->destinationA2->id,
            ])->sort()->values()->all());
    });

    test('does not promote a destination removed after a stale component loaded it', function () {
        $this->applicationA->additional_networks()->attach($this->destinationA2->id, ['server_id' => $this->serverA2->id]);
        $staleComponent = Livewire::test(Destination::class, ['resource' => $this->applicationA->fresh()]);

        Livewire::test(Destination::class, ['resource' => $this->applicationA->fresh()])
            ->call('removeServer', $this->destinationA2->id, $this->serverA2->id, 'password');

        $staleComponent->instance()->promote($this->destinationA2->id, $this->serverA2->id);

        $application = $this->applicationA->fresh();

        expect($application->destination_id)->toBe($this->destinationA->id)
            ->and($application->additional_networks)->toHaveCount(0);
    });

    test('refresh failures after promote do not roll back promoted destination', function () {
        $this->applicationA->additional_networks()->attach($this->destinationA2->id, ['server_id' => $this->serverA2->id]);

        GetContainersStatus::shouldRun()
            ->once()
            ->andThrow(new RuntimeException('refresh failed'));

        try {
            Livewire::test(Destination::class, ['resource' => $this->applicationA])
                ->call('promote', $this->destinationA2->id, $this->serverA2->id);
        } catch (Throwable $e) {
            // The refresh failure is intentionally outside the transaction; persistence is the assertion.
        }

        $application = $this->applicationA->fresh();
        $additional = $application->additional_networks;

        expect($application->destination_id)->toBe($this->destinationA2->id);
        expect($additional)->toHaveCount(1);
        expect($additional->first()->id)->toBe($this->destinationA->id);
        expect($additional->first()->pivot->server_id)->toBe($this->serverA->id);
    });

    test('only detaches the promoted network for the selected pivot server', function () {
        $this->applicationA->additional_networks()->attach($this->destinationA2->id, ['server_id' => $this->serverA2->id]);
        $this->applicationA->additional_networks()->attach($this->destinationA2->id, ['server_id' => $this->serverA->id]);

        Livewire::test(Destination::class, ['resource' => $this->applicationA])
            ->call('promote', $this->destinationA2->id, $this->serverA2->id);

        expect(DB::table('additional_destinations')
            ->where('application_id', $this->applicationA->id)
            ->where('standalone_docker_id', $this->destinationA2->id)
            ->where('server_id', $this->serverA->id)
            ->exists())->toBeTrue();

        expect(DB::table('additional_destinations')
            ->where('application_id', $this->applicationA->id)
            ->where('standalone_docker_id', $this->destinationA2->id)
            ->where('server_id', $this->serverA2->id)
            ->exists())->toBeFalse();
    });
});

describe('Destination::removeServer', function () {
    test('repeats the main destination fence after reloading a stale application topology', function () {
        $this->applicationA->additional_networks()->attach($this->destinationA2->id, ['server_id' => $this->serverA2->id]);
        $staleComponent = Livewire::test(Destination::class, ['resource' => $this->applicationA->fresh()]);

        Livewire::test(Destination::class, ['resource' => $this->applicationA->fresh()])
            ->call('promote', $this->destinationA2->id, $this->serverA2->id)
            ->assertNotDispatched('error');

        $result = $staleComponent->instance()->removeServer(
            $this->destinationA2->id,
            $this->serverA2->id,
            'password',
        );

        $application = $this->applicationA->fresh();

        expect($result)->toBeNull()
            ->and($application->destination_id)->toBe($this->destinationA2->id)
            ->and($application->additional_networks)->toHaveCount(1)
            ->and($application->additional_networks->first()->id)->toBe($this->destinationA->id);
    });

    test('only detaches the removed network for the selected pivot server', function () {
        $this->applicationA->additional_networks()->attach($this->destinationA2->id, ['server_id' => $this->serverA2->id]);
        $this->applicationA->additional_networks()->attach($this->destinationA2->id, ['server_id' => $this->serverA->id]);

        Livewire::test(Destination::class, ['resource' => $this->applicationA])
            ->call('removeServer', $this->destinationA2->id, $this->serverA2->id, 'password');

        expect(DB::table('additional_destinations')
            ->where('application_id', $this->applicationA->id)
            ->where('standalone_docker_id', $this->destinationA2->id)
            ->where('server_id', $this->serverA->id)
            ->exists())->toBeTrue();

        expect(DB::table('additional_destinations')
            ->where('application_id', $this->applicationA->id)
            ->where('standalone_docker_id', $this->destinationA2->id)
            ->where('server_id', $this->serverA2->id)
            ->exists())->toBeFalse();
    });
});
