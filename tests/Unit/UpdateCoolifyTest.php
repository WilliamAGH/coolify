<?php

use App\Actions\Server\UpdateCoolify;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config([
        'constants.coolify.version' => '4.13.0-fork',
        'constants.coolify.versions_url' => 'https://cdn.coollabs.io/coolify/versions.json',
        'constants.coolify.upgrade_script_url' => 'https://cdn.coollabs.io/coolify/upgrade.sh',
    ]);

    $this->settings = (new InstanceSettings)->forceFill([
        'id' => 0,
        'instance_timezone' => 'UTC',
        'is_auto_update_enabled' => true,
    ]);
    $this->settings->saveQuietly();

    $this->server = (new Server)->forceFill([
        'id' => 0,
        'uuid' => 'coolify-server',
        'name' => 'Coolify',
        'ip' => '127.0.0.1',
        'team_id' => 0,
        'private_key_id' => 0,
        'proxy' => ['type' => 'NONE'],
    ]);
    $this->server->saveQuietly();

    Cache::flush();
});

it('has UpdateCoolify action class', function () {
    expect(class_exists(UpdateCoolify::class))->toBeTrue();
});

it('validates cache against running version before fallback', function () {
    Http::fake(['*' => Http::response(null, 500)]);
    Cache::put('coolify:versions:all', ['coolify' => [
        'v4' => ['version' => '4.0.5'],
        'helper' => ['version' => '1.0.14'],
    ]]);
    config(['constants.coolify.version' => '4.0.10']);

    $action = new UpdateCoolify;

    expect(fn () => $action->handle(manual_update: false))->toThrow(
        Exception::class,
        'Cannot determine latest version: CDN unavailable and cache version (4.0.5) is older than running version (4.0.10)',
    );
});

it('uses validated cache when CDN fails and automatic updates are disabled', function () {
    $this->settings->is_auto_update_enabled = false;
    $this->settings->saveQuietly();

    Http::fake(['*' => Http::response(null, 500)]);
    Cache::put('coolify:versions:all', ['coolify' => [
        'v4' => ['version' => '4.0.10'],
        'helper' => ['version' => '1.0.14'],
    ]]);
    config(['constants.coolify.version' => '4.0.5']);
    Log::spy();

    $action = new UpdateCoolify;
    $action->handle(manual_update: false);

    expect($action->latestVersion)->toBe('4.0.10');
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'using validated cache'))
        ->once();
});

it('does not automatically replace a guarded fork release while updates are disabled', function (string $availableVersion, bool $isNewer) {
    $this->settings->forceFill([
        'is_auto_update_enabled' => false,
        'new_version_available' => true,
    ])->saveQuietly();

    config(['constants.coolify.version' => '4.13.0-fork']);
    Http::fake([
        'https://cdn.coollabs.io/coolify/versions.json' => Http::response([
            'coolify' => [
                'v4' => ['version' => $availableVersion],
                'helper' => ['version' => '1.0.14'],
            ],
        ]),
    ]);

    $action = new UpdateCoolify;
    $action->handle(manual_update: false);

    expect(version_compare($availableVersion, '4.13.0-fork', '>'))->toBe($isNewer)
        ->and($action->latestVersion)->toBe($availableVersion)
        ->and($this->settings->refresh()->new_version_available)->toBeTrue();
})->with([
    'higher upstream stable release' => ['4.13.0', true],
    'equal fork release' => ['4.13.0-fork', false],
]);

it('prevents downgrade even with manual update', function () {
    Http::fake([
        '*' => Http::response([
            'coolify' => [
                'v4' => ['version' => '4.12.9'],
                'helper' => ['version' => '1.0.14'],
            ],
        ]),
    ]);
    config(['constants.coolify.version' => '4.13.0-fork']);
    Log::spy();

    $action = new UpdateCoolify;

    expect(fn () => $action->handle(manual_update: true))->toThrow(
        Exception::class,
        'Cannot downgrade from 4.13.0-fork to 4.12.9. If you need to downgrade, please do so manually via Docker commands.',
    );
    Log::shouldHaveReceived('error')
        ->with('Downgrade prevented', Mockery::type('array'))
        ->once();
});

it('rejects malformed remote semantic versions before an update can run', function () {
    Http::fake([
        '*' => Http::response([
            'coolify' => [
                'v4' => ['version' => '4.2.0; touch /tmp/pwned'],
                'helper' => ['version' => '1.0.15'],
            ],
        ]),
    ]);

    expect(fn () => (new UpdateCoolify)->handle())
        ->toThrow(UnexpectedValueException::class, 'CDN response Coolify version must be a semantic version.');
});

it('rejects malformed remote helper versions before an update can run', function () {
    Http::fake([
        '*' => Http::response([
            'coolify' => [
                'v4' => ['version' => '4.2.0'],
                'helper' => ['version' => '1.0.15; touch /tmp/pwned'],
            ],
        ]),
    ]);

    expect(fn () => (new UpdateCoolify)->handle())
        ->toThrow(UnexpectedValueException::class, 'CDN response helper version must be a semantic version.');
});

it('rejects unsafe configured update URLs before making a remote request', function () {
    config([
        'constants.coolify.versions_url' => 'https://cdn.coollabs.io/coolify/versions.json; touch /tmp/pwned',
    ]);

    expect(fn () => (new UpdateCoolify)->handle())
        ->toThrow(UnexpectedValueException::class, 'Coolify versions URL must be a valid HTTPS URL.');
});

it('rejects unsafe configured upgrade script URLs before remote processing', function () {
    Http::fake([
        '*' => Http::response([
            'coolify' => [
                'v4' => ['version' => '4.14.0'],
                'helper' => ['version' => '1.0.15'],
            ],
        ]),
    ]);
    config([
        'constants.coolify.upgrade_script_url' => 'https://cdn.coollabs.io/coolify/upgrade.sh; touch /tmp/pwned',
    ]);

    expect(fn () => (new UpdateCoolify)->handle(manual_update: true))
        ->toThrow(UnexpectedValueException::class, 'Coolify upgrade script URL must be a valid HTTPS URL.');
});

it('quotes all dynamic guarded update command arguments', function () {
    $upgradeScriptUrl = 'https://127.0.0.1:65535/coolify/upgrade.sh?channel=fork&safe=1';
    $upgradeCommands = (new ReflectionMethod(UpdateCoolify::class, 'upgradeCommands'))->invoke(
        new UpdateCoolify,
        $upgradeScriptUrl,
        '4.13.0-fork',
        '1.0.15',
    );

    expect($upgradeCommands)->toBe([
        "curl -fsSL -- '{$upgradeScriptUrl}' -o '/data/coolify/source/upgrade.sh'",
        "bash '/data/coolify/source/upgrade.sh' '4.13.0-fork' '1.0.15'",
    ]);
});
