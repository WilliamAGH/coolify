<?php

use App\Livewire\Project\Shared\EnvironmentVariable\Add;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

it('has availableSharedVariables computed property', function () {
    $component = new Add;

    // Check that the method exists
    expect(method_exists($component, 'availableSharedVariables'))->toBeTrue();
});

it('component has required properties for environment variable autocomplete', function () {
    $component = new Add;

    expect($component)->toHaveProperty('key')
        ->and($component)->toHaveProperty('value')
        ->and($component)->toHaveProperty('is_multiline')
        ->and($component)->toHaveProperty('is_literal')
        ->and($component)->toHaveProperty('is_runtime')
        ->and($component)->toHaveProperty('is_buildtime')
        ->and($component)->toHaveProperty('parameters');
});

it('returns empty arrays when currentTeam returns null', function () {
    // Mock Auth facade to return null for user
    Auth::shouldReceive('user')
        ->andReturn(null);

    $component = new Add;
    $component->parameters = [];

    $result = $component->availableSharedVariables();

    expect($result)->toBe([
        'team' => [],
        'project' => [],
        'environment' => [],
        'server' => [],
    ]);
});

it('does not expose shared variables when authorization is denied', function () {
    $team = new stdClass;
    $user = new class($team)
    {
        public function __construct(private object $team) {}

        public function currentTeam(): object
        {
            return $this->team;
        }
    };

    Auth::shouldReceive('user')->andReturn($user);

    $component = new class extends Add
    {
        public function authorize($ability, $arguments = [])
        {
            throw new AuthorizationException;
        }
    };
    $component->parameters = [];

    expect($component->availableSharedVariables())->toBe([
        'team' => [],
        'project' => [],
        'environment' => [],
        'server' => [],
    ]);
});
