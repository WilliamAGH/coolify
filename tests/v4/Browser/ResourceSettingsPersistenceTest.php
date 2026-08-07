<?php

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\DiscordNotificationSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Pest\Browser\Api\PendingAwaitablePage;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedBrowserInstanceSettings();

    $this->user = User::factory()->create([
        'id' => 0,
        'name' => 'Root User',
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);

    DiscordNotificationSettings::where('team_id', 0)->update([
        'discord_enabled' => true,
        'discord_webhook_url' => 'https://discord.com/test',
    ]);

    $key = PrivateKey::create([
        'uuid' => 'ssh-test',
        'team_id' => 0,
        'name' => 'Test Key',
        'description' => 'Test SSH key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
    ]);

    $this->server = Server::create([
        'id' => 0,
        'uuid' => 'localhost',
        'name' => 'localhost',
        'description' => 'Test docker container in development',
        'ip' => 'coolify-testing-host',
        'team_id' => 0,
        'private_key_id' => $key->id,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => ProxyStatus::EXITED->value,
        ],
    ]);

    $this->project = Project::create([
        'uuid' => 'project-resource-persistence',
        'name' => 'Resource Persistence',
        'description' => 'Browser persistence tests',
        'team_id' => 0,
    ]);

    $this->environment = $this->project->environments()->first();

    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => 'docker-destination-1', 'name' => 'docker-destination-1']
        );
    });

    $this->application = Application::factory()->create([
        'uuid' => 'app-resource-persistence',
        'name' => 'App Before Browser Save',
        'git_repository' => 'https://github.com/coollabsio/coolify.git',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->database = StandalonePostgresql::create([
        'uuid' => 'db-resource-persistence',
        'name' => 'Database Before Browser Save',
        'description' => 'Initial database description',
        'postgres_user' => 'postgres',
        'postgres_password' => 'postgres-password',
        'postgres_db' => 'postgres',
        'image' => 'postgres:15-alpine',
        'status' => 'exited',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

it('saves application name and enables static site with nginx config', function () {
    loginAndSkipBoarding($this->user->email);

    $updatedName = 'App Saved '.(string) new Cuid2;
    $applicationRoute = "/project/{$this->project->uuid}/environment/{$this->environment->uuid}/application/{$this->application->uuid}";

    $page = visit($applicationRoute);
    $page->screenshot();

    $page->assertSee('General')
        ->assertDontSee('Custom Nginx Configuration')
        ->fill('name', $updatedName)
        ->fill('customDockerRunOptions', '--read-only');

    submitLivewireForm(
        $page,
        ['name' => $updatedName, 'customDockerRunOptions' => '--read-only'],
        'Application settings updated!'
    );
    $page->assertValue('name', $updatedName);

    $this->application->refresh();
    expect($this->application->name)->toBe($updatedName)
        ->and($this->application->custom_docker_run_options)->toBe('--read-only');

    $page->click('[id^="isStatic"]')
        ->screenshot();

    $page->assertSee('Custom Nginx Configuration')
        ->assertSee('Is it a SPA (Single Page Application)?')
        ->assertValue('name', $updatedName);

    $this->application->refresh();
    expect($this->application->settings->is_static)->toBeTrue();

    $reloadedPage = visit($applicationRoute);
    $reloadedPage->screenshot();

    $reloadedPage->assertValue('name', $updatedName)
        ->assertSee('Custom Nginx Configuration')
        ->assertSee('Is it a SPA (Single Page Application)?');
});

it('saves database name and enables ssl with mode selector', function () {
    loginAndSkipBoarding($this->user->email);

    $updatedDatabaseName = 'Database Saved '.(string) new Cuid2;
    $databaseRoute = "/project/{$this->project->uuid}/environment/{$this->environment->uuid}/database/{$this->database->uuid}";

    $page = visit($databaseRoute);
    $page->screenshot();

    $page->assertSee('General')
        ->assertDontSee('SSL Mode')
        ->fill('name', $updatedDatabaseName)
        ->fill('description', 'Updated by browser test');

    submitLivewireForm(
        $page,
        ['name' => $updatedDatabaseName, 'description' => 'Updated by browser test'],
        'Database updated.'
    );
    $this->database->refresh();
    expect($this->database->name)->toBe($updatedDatabaseName)
        ->and($this->database->description)->toBe('Updated by browser test');

    $page->click('[id^="enableSsl"]');

    $page->assertSee('SSL Mode')
        ->assertValue('name', $updatedDatabaseName);
    $page->screenshot();

    $this->database->refresh();
    expect($this->database->enable_ssl)->toBeTruthy();

    $reloadedPage = visit($databaseRoute);
    $reloadedPage->screenshot();

    $reloadedPage->assertValue('name', $updatedDatabaseName)
        ->assertSee('SSL Mode');
});

/**
 * @param  array<string, string>  $expectedUpdates
 */
function submitLivewireForm(
    PendingAwaitablePage $page,
    array $expectedUpdates,
    string $expectedSuccessMessage
): void {
    $componentId = $page->script(<<<'JAVASCRIPT'
        () => {
            const form = document.querySelector('input[name="name"]')?.closest('form[wire\\:submit="submit"]');
            if (!(form instanceof HTMLFormElement)) {
                throw new Error('Unable to find the canonical Livewire settings form.');
            }
            const componentId = form.closest('[wire\\:id]')?.getAttribute('wire:id');
            if (!componentId) {
                throw new Error('Unable to find the canonical Livewire settings component.');
            }

            const pendingSubmit = new Promise((resolve, reject) => {
                let settled = false;
                let stopObservingCommits = () => {};
                const finish = (callback) => {
                    if (settled) {
                        return;
                    }

                    settled = true;
                    window.clearTimeout(timeout);
                    stopObservingCommits();
                    callback();
                };
                const timeout = window.setTimeout(() => {
                    finish(() => reject(new Error(`Timed out waiting for Livewire submit: ${componentId}`)));
                }, 10_000);
                stopObservingCommits = window.Livewire.hook('commit', ({ component, commit, succeed, fail }) => {
                    if (
                        component.id !== componentId
                        || !commit.calls.some((call) => call.method === 'submit')
                    ) {
                        return;
                    }

                    fail(() => {
                        finish(() => reject(new Error(`Livewire submit failed: ${componentId}`)));
                    });
                    succeed(({ effects }) => {
                        finish(() => window.requestAnimationFrame(() => resolve({
                            componentId,
                            updates: commit.updates,
                            dispatches: effects.dispatches ?? [],
                        })));
                    });
                });
            });

            pendingSubmit.catch(() => {});
            window.__pestResourceSettingsSubmit = pendingSubmit;

            return componentId;
        }
        JAVASCRIPT);

    expect($componentId)->toBeString()->not->toBeEmpty();
    $page->click('form[wire\\:submit="submit"] > div:first-child > button[type="submit"]');

    $result = $page->script(<<<'JAVASCRIPT'
        async () => {
            try {
                return await window.__pestResourceSettingsSubmit;
            } finally {
                delete window.__pestResourceSettingsSubmit;
            }
        }
        JAVASCRIPT);

    expect($result['updates'])->toBeArray();
    foreach ($expectedUpdates as $property => $expectedValue) {
        expect($result['updates'])->toHaveKey($property, $expectedValue);
    }

    assertLivewireSuccess($result, $componentId, $expectedSuccessMessage);
}

/** @param  array{componentId: string, dispatches: array<int, array<string, mixed>>}  $result */
function assertLivewireSuccess(array $result, string $componentId, string $expectedSuccessMessage): void
{
    expect($result['componentId'])->toBe($componentId);
    $dispatches = collect($result['dispatches']);
    $errorDispatches = $dispatches->where('name', 'error')->values()->all();
    $successDispatch = $dispatches->first(fn (array $dispatch): bool => data_get($dispatch, 'name') === 'success'
        && data_get($dispatch, 'params.0') === $expectedSuccessMessage);

    expect($errorDispatches)->toBeEmpty(
        'Unexpected Livewire error dispatches: '.json_encode($errorDispatches, JSON_THROW_ON_ERROR)
    )->and($successDispatch)->not->toBeNull();
}
