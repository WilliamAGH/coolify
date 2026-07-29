<?php

use App\Actions\Application\BlueGreen\ActiveApplicationContainerState;
use App\Actions\Application\BlueGreen\ResolveActiveApplicationContainerState;
use App\Actions\Application\BlueGreen\ResolveOrdinaryApplicationDeploymentUuids;
use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

function ordinaryApplicationContainer(
    int $applicationId,
    string $containerId,
    string $deploymentUuid,
    string $image,
    string $health = 'healthy',
    ?string $imageId = null,
): array {
    return [
        'Id' => $containerId,
        'Image' => $imageId ?? 'sha256:'.hash('sha256', $image),
        'Config' => [
            'Image' => $image,
            'Labels' => [
                'coolify.applicationId' => (string) $applicationId,
                'coolify.pullRequestId' => '0',
                'coolify.deploymentId' => $deploymentUuid,
            ],
        ],
        'State' => ['Status' => 'running', 'Health' => ['Status' => $health]],
    ];
}

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $this->token = $user->createToken('active-container-api-test', ['read'])->plainTextToken;
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'registry.example/app',
        'docker_registry_image_tag' => '815c045',
        'status' => 'running:healthy',
    ]);
});

it('returns the active container runtime image ID and configured image reference', function () {
    $resolver = Mockery::mock(new ResolveActiveApplicationContainerState)->makePartial();
    $resolver->shouldReceive('handle')
        ->once()
        ->andReturn(new ActiveApplicationContainerState(
            image: 'sha256:'.hash('sha256', 'registry.example/app:ccb9a3b'),
            imageReference: 'registry.example/app:ccb9a3b',
            status: 'running:unhealthy',
            destination: [[
                'destination_id' => (int) $this->application->destination_id,
                'deployment_uuid' => 'deployment-ccb9a3b',
                'color' => 'green',
                'routing_revision' => 7,
                'container_ids' => [str_repeat('a', 64)],
            ]],
        ));
    $this->app->instance(ResolveActiveApplicationContainerState::class, $resolver);

    $this->withToken($this->token)
        ->getJson("/api/v1/applications/{$this->application->uuid}/active-container")
        ->assertSuccessful()
        ->assertJsonPath('image', 'sha256:'.hash('sha256', 'registry.example/app:ccb9a3b'))
        ->assertJsonPath('image_reference', 'registry.example/app:ccb9a3b')
        ->assertJsonPath('source', 'dockerimage')
        ->assertJsonPath('status', 'running:unhealthy')
        ->assertJsonPath('provenance.kind', 'live-container-inspection')
        ->assertJsonPath('provenance.destination.0.deployment_uuid', 'deployment-ccb9a3b');
});

it('resolves an ordinary image application directly from its live container without blue-green state', function () {
    $containerId = str_repeat('b', 64);
    $image = 'registry.example/app:ordinary-live';
    $state = (new ResolveActiveApplicationContainerState)->resolveOrdinaryFromContainers(
        (int) $this->application->id,
        collect([(int) $this->application->destination_id => 'deployment-ordinary']),
        collect([
            (int) $this->application->destination_id => collect([ordinaryApplicationContainer(
                (int) $this->application->id,
                $containerId,
                'deployment-ordinary',
                $image,
            )]),
        ]),
    );

    expect($this->application->blueGreenDeployments()->exists())->toBeFalse()
        ->and($state?->image)->toBe('sha256:'.hash('sha256', $image))
        ->and($state?->imageReference)->toBe($image)
        ->and($state?->status)->toBe('running:healthy')
        ->and($state?->destination[0]['deployment_uuid'])->toBe('deployment-ordinary')
        ->and($state?->destination[0]['container_ids'])->toBe([$containerId]);
});

it('ignores a stale ordinary container with a different image', function () {
    $destinationId = (int) $this->application->destination_id;
    $state = (new ResolveActiveApplicationContainerState)->resolveOrdinaryFromContainers(
        (int) $this->application->id,
        collect([$destinationId => 'deployment-current']),
        collect([$destinationId => collect([
            ordinaryApplicationContainer(
                (int) $this->application->id,
                str_repeat('c', 64),
                'deployment-stale',
                'registry.example/app:stale',
            ),
            ordinaryApplicationContainer(
                (int) $this->application->id,
                str_repeat('d', 64),
                'deployment-current',
                'registry.example/app:current',
            ),
        ])]),
    );

    expect($state?->image)->toBe('sha256:'.hash('sha256', 'registry.example/app:current'))
        ->and($state?->destination[0]['container_ids'])->toBe([str_repeat('d', 64)]);
});

it('derives ordinary status only from the exact current deployment', function () {
    $destinationId = (int) $this->application->destination_id;
    $state = (new ResolveActiveApplicationContainerState)->resolveOrdinaryFromContainers(
        (int) $this->application->id,
        collect([$destinationId => 'deployment-current']),
        collect([$destinationId => collect([
            ordinaryApplicationContainer(
                (int) $this->application->id,
                str_repeat('e', 64),
                'deployment-stale',
                'registry.example/app:same',
                'unhealthy',
            ),
            ordinaryApplicationContainer(
                (int) $this->application->id,
                str_repeat('f', 64),
                'deployment-current',
                'registry.example/app:same',
            ),
        ])]),
    );

    expect($state?->image)->toBe('sha256:'.hash('sha256', 'registry.example/app:same'))
        ->and($state?->status)->toBe('running:healthy')
        ->and($state?->destination[0]['container_ids'])->toBe([str_repeat('f', 64)]);
});

it('fails closed when only a stale ordinary deployment is observable', function () {
    $destinationId = (int) $this->application->destination_id;

    expect((new ResolveActiveApplicationContainerState)->resolveOrdinaryFromContainers(
        (int) $this->application->id,
        collect([$destinationId => 'deployment-current']),
        collect([$destinationId => collect([ordinaryApplicationContainer(
            (int) $this->application->id,
            str_repeat('1', 64),
            'deployment-stale',
            'registry.example/app:stale',
        )])]),
    ))->toBeNull();
});

it('fails closed when an ordinary deployment has more than one matching container', function () {
    $destinationId = (int) $this->application->destination_id;
    $matchingContainers = collect([
        ordinaryApplicationContainer(
            (int) $this->application->id,
            str_repeat('3', 64),
            'deployment-duplicate',
            'registry.example/app:duplicate',
        ),
        ordinaryApplicationContainer(
            (int) $this->application->id,
            str_repeat('4', 64),
            'deployment-duplicate',
            'registry.example/app:duplicate',
        ),
    ]);

    expect((new ResolveActiveApplicationContainerState)->resolveOrdinaryFromContainers(
        (int) $this->application->id,
        collect([$destinationId => 'deployment-duplicate']),
        collect([$destinationId => $matchingContainers]),
    ))->toBeNull();
});

it('fails closed when configured and observed destination inventories differ', function () {
    $action = new ResolveActiveApplicationContainerState;

    expect($action->destinationIdsMatch(collect([11, 12]), collect([11])))->toBeFalse()
        ->and($action->destinationIdsMatch(collect([11, 12]), collect([11, 13])))->toBeFalse()
        ->and($action->destinationIdsMatch(collect([12, 11]), collect([11, 12])))->toBeTrue();
});

it('uses the latest completed ordinary deployment as the current identity fence', function () {
    $destinationId = (int) $this->application->destination_id;
    foreach (['deployment-old', 'deployment-current'] as $deploymentUuid) {
        ApplicationDeploymentQueue::query()->create([
            'application_id' => $this->application->id,
            'deployment_uuid' => $deploymentUuid,
            'destination_id' => $destinationId,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
        ]);
    }

    $resolved = ResolveOrdinaryApplicationDeploymentUuids::run(
        $this->application,
        collect([$destinationId]),
    );

    expect($resolved?->all())->toBe([$destinationId => 'deployment-current']);
});

it('fails closed while an ordinary deployment can change the current container', function () {
    $destinationId = (int) $this->application->destination_id;
    foreach ([
        'deployment-current' => ApplicationDeploymentStatus::FINISHED,
        'deployment-mutating' => ApplicationDeploymentStatus::IN_PROGRESS,
    ] as $deploymentUuid => $status) {
        ApplicationDeploymentQueue::query()->create([
            'application_id' => $this->application->id,
            'deployment_uuid' => $deploymentUuid,
            'destination_id' => $destinationId,
            'status' => $status->value,
        ]);
    }

    expect(ResolveOrdinaryApplicationDeploymentUuids::run(
        $this->application,
        collect([$destinationId]),
    ))->toBeNull();
});

it('excludes only the exact current restart when resolving the ordinary predecessor', function () {
    $destinationId = (int) $this->application->destination_id;
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deployment-current',
        'destination_id' => $destinationId,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    $restart = ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deployment-restart',
        'destination_id' => $destinationId,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'restart_only' => true,
    ]);

    expect(ResolveOrdinaryApplicationDeploymentUuids::run(
        $this->application,
        collect([$destinationId]),
    ))->toBeNull()
        ->and(ResolveOrdinaryApplicationDeploymentUuids::run(
            $this->application,
            collect([$destinationId]),
            $restart->id,
        )?->all())->toBe([$destinationId => 'deployment-current'])
        ->and((new ResolveOrdinaryApplicationDeploymentUuids)->currentRestartDeploymentUuids(
            $this->application,
            collect([$destinationId]),
            $restart->id,
        )?->all())->toBe([$destinationId => 'deployment-restart']);

    $restart->update(['restart_only' => false]);

    expect(ResolveOrdinaryApplicationDeploymentUuids::run(
        $this->application,
        collect([$destinationId]),
        $restart->id,
    ))->toBeNull()
        ->and((new ResolveOrdinaryApplicationDeploymentUuids)->currentRestartDeploymentUuids(
            $this->application,
            collect([$destinationId]),
            $restart->id,
        ))->toBeNull();
});

it('fails current restart recovery when a competing deployment appears during container observation', function () {
    $destinationId = (int) $this->application->destination_id;
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deployment-current',
        'destination_id' => $destinationId,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    $restart = ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deployment-restart',
        'destination_id' => $destinationId,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'restart_only' => true,
    ]);
    $competingDeploymentCreated = false;
    $observedContainer = ordinaryApplicationContainer(
        (int) $this->application->id,
        str_repeat('2', 64),
        $restart->deployment_uuid,
        "{$this->application->uuid}:restart-current",
    );
    $resolver = new class($destinationId, (int) $this->application->id, $observedContainer, function () use (&$competingDeploymentCreated): void {
        $competingDeploymentCreated = true;
    },
    ) extends ResolveActiveApplicationContainerState {
        public function __construct(
            private readonly int $destinationId,
            private readonly int $applicationId,
            private readonly array $observedContainer,
            private readonly Closure $onContainerObservation,
        ) {}

        protected function containersByDestination(
            Collection $destinationIds,
            Collection $destinations,
        ): ?Collection {
            ($this->onContainerObservation)();
            ApplicationDeploymentQueue::query()->create([
                'application_id' => $this->applicationId,
                'deployment_uuid' => 'deployment-competing',
                'destination_id' => $this->destinationId,
                'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
            ]);

            return collect([
                $this->destinationId => collect([$this->observedContainer]),
            ]);
        }
    };

    expect($resolver->handleCurrentOrdinaryRestart(
        $this->application,
        $restart->id,
    ))->toBeNull()
        ->and($competingDeploymentCreated)->toBeTrue();
});

it('fails closed when no exact active container image is observable', function () {
    $resolver = Mockery::mock(new ResolveActiveApplicationContainerState)->makePartial();
    $resolver->shouldReceive('handle')
        ->once()
        ->andReturnNull();
    $this->app->instance(ResolveActiveApplicationContainerState::class, $resolver);

    $this->withToken($this->token)
        ->getJson("/api/v1/applications/{$this->application->uuid}/active-container")
        ->assertConflict()
        ->assertJsonPath('message', 'Active application container state is not observable.');
});

it('fails closed for a compose application when runtime observation fails', function () {
    $this->application->update(['build_pack' => 'dockercompose']);
    $resolver = Mockery::mock(new ResolveActiveApplicationContainerState)->makePartial();
    $resolver->shouldReceive('handle')
        ->once()
        ->andReturnNull();
    $this->app->instance(ResolveActiveApplicationContainerState::class, $resolver);

    $this->withToken($this->token)
        ->getJson("/api/v1/applications/{$this->application->uuid}/active-container")
        ->assertConflict()
        ->assertJsonPath('message', 'Active application container state is not observable.');
});
