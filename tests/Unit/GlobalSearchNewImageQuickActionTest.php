<?php

use App\Livewire\GlobalSearch;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $user = Mockery::mock();
    $user->shouldReceive('can')->with('createAnyResource')->andReturnTrue();
    $user->shouldReceive('isAdmin')->andReturnFalse();
    $user->shouldReceive('isOwner')->andReturnFalse();

    Auth::shouldReceive('user')->andReturn($user);
});

it('matches exact quick actions against the quickcommand metadata', function () {
    $blade = file_get_contents(resource_path('views/livewire/global-search.blade.php'));

    expect($blade)->toContain('item.quickcommand && item.quickcommand.toLowerCase().includes(trimmed)');
});

it('starts Docker image resource selection and clears the search query', function () {
    $component = new class extends GlobalSearch
    {
        public bool $serversLoaded = false;

        public function loadServers()
        {
            $this->serversLoaded = true;
        }
    };
    $component->searchQuery = 'new image';
    $component->creatableItems = [[
        'name' => 'Docker Image',
        'type' => 'docker-image',
        'resourceType' => 'application',
    ]];

    $component->navigateToResource('docker-image');

    expect($component->selectedResourceType)->toBe('docker-image')
        ->and($component->isSelectingResource)->toBeTrue()
        ->and($component->searchQuery)->toBe('')
        ->and($component->serversLoaded)->toBeTrue();
});

it('redirects a completed Docker image selection to resource creation', function () {
    $component = new class extends GlobalSearch
    {
        public ?array $recordedRedirect = null;

        public function redirectRoute($name, $parameters = [], $absolute = true, $navigate = false)
        {
            $this->recordedRedirect = [
                'name' => $name,
                'parameters' => $parameters,
            ];

            return null;
        }
    };
    $component->selectedResourceType = 'docker-image';
    $component->selectedServerId = 7;
    $component->selectedDestinationUuid = 'destination-uuid';
    $component->selectedProjectUuid = 'project-uuid';

    $component->selectEnvironment('environment-uuid');

    expect($component->recordedRedirect)->toBe([
        'name' => 'project.resource.create',
        'parameters' => [
            'project_uuid' => 'project-uuid',
            'environment_uuid' => 'environment-uuid',
            'type' => 'docker-image',
            'destination' => 'destination-uuid',
            'server_id' => 7,
        ],
    ]);
});

it('publishes Docker Image with the new image quickcommand', function () {
    $component = new class extends GlobalSearch
    {
        public function getServicesProperty()
        {
            return [];
        }
    };

    (new ReflectionMethod(GlobalSearch::class, 'loadCreatableItems'))->invoke($component);

    $dockerImage = collect($component->creatableItems)->firstWhere('type', 'docker-image');

    expect($dockerImage)->toMatchArray([
        'name' => 'Docker Image',
        'quickcommand' => '(type: new image)',
        'type' => 'docker-image',
        'resourceType' => 'application',
    ]);
});
