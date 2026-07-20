<?php

use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Livewire\Server\Proxy\DynamicConfigurationNavbar;
use App\Livewire\Server\Proxy\NewDynamicConfiguration;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $this->server->save();

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function managedBlueGreenDynamicFilename(): string
{
    return 'coolify-blue-green-a5adf99dc3d81b09.yaml';
}

test('recognizes only the canonical generated blue-green proxy filename', function (string $filename, bool $isManaged) {
    expect(BlueGreenProxyConfiguration::isManagedFilename($filename))->toBe($isManaged);
})->with([
    'generated filename' => [managedBlueGreenDynamicFilename(), true],
    'wrong extension' => ['coolify-blue-green-a5adf99dc3d81b09.yml', false],
    'wrong scope length' => ['coolify-blue-green-a5adf99dc3d81b0.yaml', false],
    'ordinary dynamic configuration' => ['custom-router.yaml', false],
]);

test('refuses create and edit calls that normalize into a managed blue-green filename', function (bool $newFile, string $fileName) {
    Process::fake();

    Livewire::test(NewDynamicConfiguration::class, [
        'server_id' => $this->server->id,
        'fileName' => $fileName,
        'value' => "http:\n  routers: {}",
        'newFile' => $newFile,
    ])
        ->call('addDynamicConfiguration')
        ->assertSet('fileName', managedBlueGreenDynamicFilename())
        ->assertDispatched('error');

    Process::assertNothingRan();
})->with([
    'create without extension' => [true, 'coolify-blue-green-a5adf99dc3d81b09'],
    'edit encoded filename' => [false, 'coolify-blue-green-a5adf99dc3d81b09|yaml'],
]);

test('refuses forged deletion of a managed blue-green configuration', function () {
    Process::fake();
    $filename = managedBlueGreenDynamicFilename();

    Livewire::test(DynamicConfigurationNavbar::class, [
        'server_id' => $this->server->id,
        'server' => $this->server,
        'fileName' => $filename,
        'value' => "http:\n  routers: {}",
    ])
        ->call('delete', str_replace('.', '|', $filename))
        ->assertDispatched('error');

    Process::assertNothingRan();
});
