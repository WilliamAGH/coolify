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

function managedBlueGreenProxyFilename(): string
{
    return 'coolify-blue-green-a5adf99dc3d81b09.yaml';
}

/**
 * @return array<string, string>
 */
function readOnlyDynamicConfigurationFilenames(): array
{
    return [
        'generated blue green configuration' => managedBlueGreenProxyFilename(),
        'traefik default configuration' => 'coolify.yaml',
        'traefik alternate configuration' => 'coolify.yml',
        'caddy dynamic configuration' => 'coolify.caddy',
        'caddy main configuration' => 'Caddyfile',
        'traefik maintenance configuration' => 'default_redirect_503.yaml',
        'caddy maintenance configuration' => 'default_redirect_503.caddy',
    ];
}

/**
 * @return array<string, array{bool, string, string}>
 */
function readOnlyDynamicConfigurationActionCases(): array
{
    $cases = [];

    foreach (readOnlyDynamicConfigurationFilenames() as $description => $filename) {
        $cases["create {$description}"] = [true, $filename, $filename];
        $cases["edit {$description}"] = [false, $filename, $filename];
    }

    $managedFilename = managedBlueGreenProxyFilename();
    $cases['create encoded generated blue green configuration'] = [true, str_replace('.', '|', $managedFilename), $managedFilename];
    $cases['edit encoded generated blue green configuration'] = [false, str_replace('.', '|', $managedFilename), $managedFilename];

    return $cases;
}

/**
 * @return array<string, array{string}>
 */
function readOnlyDynamicConfigurationDeleteCases(): array
{
    $cases = [];

    foreach (readOnlyDynamicConfigurationFilenames() as $description => $filename) {
        $cases[$description] = [$filename];
    }

    $managedFilename = managedBlueGreenProxyFilename();
    $cases['encoded generated blue green configuration'] = [str_replace('.', '|', $managedFilename)];

    return $cases;
}

test('recognizes only generated blue green proxy filenames', function (string $filename, bool $isManaged) {
    expect(BlueGreenProxyConfiguration::isManagedFilename($filename))->toBe($isManaged);
})->with([
    'generated filename' => [managedBlueGreenProxyFilename(), true],
    'wrong extension' => ['coolify-blue-green-a5adf99dc3d81b09.yml', false],
    'wrong scope length' => ['coolify-blue-green-a5adf99dc3d81b0.yaml', false],
    'ordinary dynamic configuration' => ['custom-router.yaml', false],
]);

test('refuses read-only filenames in forged create and edit Livewire actions', function (bool $newFile, string $fileName, string $expectedFileName) {
    Process::fake();

    Livewire::test(NewDynamicConfiguration::class, [
        'server_id' => $this->server->id,
        'fileName' => $fileName,
        'value' => "http:\n  routers: {}",
        'newFile' => $newFile,
    ])
        ->call('addDynamicConfiguration')
        ->assertSet('fileName', $expectedFileName)
        ->assertDispatched('error');

    Process::assertNothingRan();
})->with(readOnlyDynamicConfigurationActionCases());

test('refuses filenames that normalize into a read-only filename', function (bool $newFile, string $proxyType, string $fileName, string $expectedFileName) {
    Process::fake();

    $this->server->proxy->set('type', $proxyType);
    $this->server->save();

    Livewire::test(NewDynamicConfiguration::class, [
        'server_id' => $this->server->id,
        'fileName' => $fileName,
        'value' => "http:\n  routers: {}",
        'newFile' => $newFile,
    ])
        ->call('addDynamicConfiguration')
        ->assertSet('fileName', $expectedFileName)
        ->assertDispatched('error');

    Process::assertNothingRan();
})->with([
    'create generated blue green configuration' => [true, ProxyTypes::TRAEFIK->value, 'coolify-blue-green-a5adf99dc3d81b09', managedBlueGreenProxyFilename()],
    'edit generated blue green configuration' => [false, ProxyTypes::TRAEFIK->value, 'coolify-blue-green-a5adf99dc3d81b09', managedBlueGreenProxyFilename()],
    'create traefik default configuration' => [true, ProxyTypes::TRAEFIK->value, 'coolify', 'coolify.yaml'],
    'edit traefik maintenance configuration' => [false, ProxyTypes::TRAEFIK->value, 'default_redirect_503', 'default_redirect_503.yaml'],
    'create caddy dynamic configuration' => [true, ProxyTypes::CADDY->value, 'coolify', 'coolify.caddy'],
    'edit caddy maintenance configuration' => [false, ProxyTypes::CADDY->value, 'default_redirect_503', 'default_redirect_503.caddy'],
]);

test('refuses forged Livewire delete calls for read-only configurations', function (string $fileName) {
    Process::fake();

    Livewire::test(DynamicConfigurationNavbar::class, [
        'server_id' => $this->server->id,
        'server' => $this->server,
        'fileName' => $fileName,
        'value' => "http:\n  routers: {}",
    ])
        ->call('delete', $fileName)
        ->assertDispatched('error');

    Process::assertNothingRan();
})->with(readOnlyDynamicConfigurationDeleteCases());
