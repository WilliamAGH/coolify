<?php

use App\Livewire\Project\Service\EditDomain;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $this->destination = $this->server->destinations()->firstOrFail();

    $this->project = Project::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
    ]);

    $this->service = Service::factory()->create([
        'name' => 'service-with-required-port',
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx:alpine
    environment:
      SERVICE_FQDN_APP_8000: ''
YAML,
    ]);

    $this->serviceApplication = ServiceApplication::factory()->create([
        'service_id' => $this->service->id,
        'name' => 'app',
        'fqdn' => 'http://example.com:8000',
    ]);
});

it('loads the EditDomain component with required port', function () {
    Livewire::test(EditDomain::class, ['applicationId' => $this->serviceApplication->id])
        ->assertSet('requiredPort', 8000)
        ->assertSet('fqdn', 'http://example.com:8000')
        ->assertOk();
});

it('shows warning modal when trying to remove required port', function () {
    Livewire::test(EditDomain::class, ['applicationId' => $this->serviceApplication->id])
        ->set('fqdn', 'http://example.com') // Remove port
        ->call('submit')
        ->assertSet('showPortWarningModal', true)
        ->assertSet('requiredPort', 8000);
});

it('allows port removal when user confirms', function () {
    Livewire::test(EditDomain::class, ['applicationId' => $this->serviceApplication->id])
        ->set('fqdn', 'http://example.com') // Remove port
        ->call('submit')
        ->assertSet('showPortWarningModal', true)
        ->call('confirmRemovePort')
        ->assertSet('showPortWarningModal', false);

    // Verify the FQDN was updated in database
    $this->serviceApplication->refresh();
    expect($this->serviceApplication->fqdn)->toBe('http://example.com');
});

it('cancels port removal when user cancels', function () {
    $originalFqdn = $this->serviceApplication->fqdn;

    Livewire::test(EditDomain::class, ['applicationId' => $this->serviceApplication->id])
        ->set('fqdn', 'http://example.com') // Remove port
        ->call('submit')
        ->assertSet('showPortWarningModal', true)
        ->call('cancelRemovePort')
        ->assertSet('showPortWarningModal', false)
        ->assertSet('fqdn', $originalFqdn); // Should revert to original
});

it('allows saving when port is changed to different port', function () {
    Livewire::test(EditDomain::class, ['applicationId' => $this->serviceApplication->id])
        ->set('fqdn', 'http://example.com:3000') // Change to different port
        ->call('submit')
        ->assertSet('showPortWarningModal', false); // Should not show warning

    // Verify the FQDN was updated
    $this->serviceApplication->refresh();
    expect($this->serviceApplication->fqdn)->toBe('http://example.com:3000');
});

it('allows saving when all domains have ports (multiple domains)', function () {
    Livewire::test(EditDomain::class, ['applicationId' => $this->serviceApplication->id])
        ->set('fqdn', 'http://example.com:8000,https://app.example.com:8080')
        ->call('submit')
        ->assertSet('showPortWarningModal', false); // Should not show warning
});

it('shows warning when at least one domain is missing port (multiple domains)', function () {
    Livewire::test(EditDomain::class, ['applicationId' => $this->serviceApplication->id])
        ->set('fqdn', 'http://example.com:8000,https://app.example.com') // Second domain missing port
        ->call('submit')
        ->assertSet('showPortWarningModal', true);
});

it('does not show warning for services without a required port', function () {
    $serviceWithoutPort = Service::factory()->create([
        'name' => 'service-without-required-port',
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => <<<'YAML'
services:
  cloudflared:
    image: cloudflare/cloudflared:latest
YAML,
    ]);

    $appWithoutPort = ServiceApplication::factory()->create([
        'service_id' => $serviceWithoutPort->id,
        'name' => 'cloudflared',
        'fqdn' => 'http://example.com',
    ]);

    Livewire::test(EditDomain::class, ['applicationId' => $appWithoutPort->id])
        ->set('fqdn', 'http://example.com')
        ->call('submit')
        ->assertSet('showPortWarningModal', false);
});
