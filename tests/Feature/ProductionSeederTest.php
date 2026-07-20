<?php

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Proxy\StartProxy;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\SslCertificate;
use App\Models\Team;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('creates the root team before seeding the localhost server and predefined shared variables', function () {
    $testingHostPrivateKeyPath = tempnam(sys_get_temp_dir(), 'coolify-testing-host-key-');
    file_put_contents($testingHostPrivateKeyPath, generateSSHKey('ed25519')['private']);
    $this->beforeApplicationDestroyed(fn () => @unlink($testingHostPrivateKeyPath));

    config([
        'broadcasting.default' => 'log',
        'constants.coolify.is_windows_docker_desktop' => true,
        'constants.coolify.testing_host_private_key_path' => $testingHostPrivateKeyPath,
    ]);
    Queue::fake();
    StartProxy::shouldRun()->andReturn('OK');

    Server::creating(function (Server $server) {
        if ((int) $server->getKey() === 0) {
            expect(Team::find(0))->not->toBeNull();
        }
    });

    Server::created(function (Server $server) {
        SslCertificate::create([
            'server_id' => $server->id,
            'common_name' => 'Coolify CA Certificate',
            'ssl_certificate' => 'certificate',
            'ssl_private_key' => 'private-key',
            'valid_until' => now()->addYear(),
            'is_ca_certificate' => true,
        ]);
    });

    $this->seed(ProductionSeeder::class);

    $rootTeam = Team::find(0);
    $localhostServer = Server::find(0);

    expect($rootTeam)->not->toBeNull()
        ->and($localhostServer)->not->toBeNull()
        ->and($localhostServer->team_id)->toBe(0);

    expect(SharedEnvironmentVariable::query()
        ->where('type', 'server')
        ->where('server_id', 0)
        ->where('team_id', 0)
        ->pluck('key')
        ->all()
    )->toContain('COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME');

    instanceSettings()->update(['is_registration_enabled' => true]);

    $rootUser = app(CreateNewUser::class)->create([
        'name' => 'Root User',
        'email' => 'root@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    expect(Team::whereKey(0)->count())->toBe(1)
        ->and($rootUser->teams()->where('team_id', 0)->exists())->toBeTrue();
});

it('fails closed when the Windows testing-host runtime key is unavailable', function () {
    config([
        'constants.coolify.is_windows_docker_desktop' => true,
        'constants.coolify.testing_host_private_key_path' => '/missing/testing-host-private-key',
    ]);

    $this->seed(ProductionSeeder::class);
})->throws(RuntimeException::class, 'The runtime testing-host private key is unavailable.');

it('fails closed when the Windows testing-host runtime key is invalid', function () {
    $testingHostPrivateKeyPath = tempnam(sys_get_temp_dir(), 'coolify-testing-host-key-');
    file_put_contents($testingHostPrivateKeyPath, 'not-an-ssh-private-key');
    $this->beforeApplicationDestroyed(fn () => @unlink($testingHostPrivateKeyPath));

    config([
        'constants.coolify.is_windows_docker_desktop' => true,
        'constants.coolify.testing_host_private_key_path' => $testingHostPrivateKeyPath,
    ]);

    $this->seed(ProductionSeeder::class);
})->throws(RuntimeException::class, 'The runtime testing-host private key is invalid.');

it('fails closed when the Windows testing-host runtime key contains only a public key', function () {
    $testingHostPrivateKeyPath = tempnam(sys_get_temp_dir(), 'coolify-testing-host-key-');
    file_put_contents($testingHostPrivateKeyPath, generateSSHKey('ed25519')['public']);
    $this->beforeApplicationDestroyed(fn () => @unlink($testingHostPrivateKeyPath));

    config([
        'constants.coolify.is_windows_docker_desktop' => true,
        'constants.coolify.testing_host_private_key_path' => $testingHostPrivateKeyPath,
    ]);

    $this->seed(ProductionSeeder::class);
})->throws(RuntimeException::class, 'The runtime testing-host private key is invalid.');

it('fails closed when the Windows testing-host runtime key is a symlink', function () {
    $targetPath = tempnam(sys_get_temp_dir(), 'coolify-testing-host-key-target-');
    $symlinkPath = $targetPath.'-link';
    file_put_contents($targetPath, generateSSHKey('ed25519')['private']);
    symlink($targetPath, $symlinkPath);
    $this->beforeApplicationDestroyed(function () use ($targetPath, $symlinkPath): void {
        @unlink($symlinkPath);
        @unlink($targetPath);
    });

    config([
        'constants.coolify.is_windows_docker_desktop' => true,
        'constants.coolify.testing_host_private_key_path' => $symlinkPath,
    ]);

    $this->seed(ProductionSeeder::class);
})->throws(RuntimeException::class, 'The runtime testing-host private key is unavailable.');

it('routes the default production seed graph through ProductionSeeder', function () {
    $testingHostPrivateKeyPath = tempnam(sys_get_temp_dir(), 'coolify-testing-host-key-');
    file_put_contents($testingHostPrivateKeyPath, generateSSHKey('ed25519')['private']);
    $this->beforeApplicationDestroyed(fn () => @unlink($testingHostPrivateKeyPath));

    config([
        'app.env' => 'production',
        'broadcasting.default' => 'log',
        'constants.coolify.is_windows_docker_desktop' => true,
        'constants.coolify.testing_host_private_key_path' => $testingHostPrivateKeyPath,
    ]);
    Queue::fake();

    $this->seed(DatabaseSeeder::class);

    expect(Server::query()->whereKey(0)->value('uuid'))->toBe('coolify-testing-host')
        ->and(Team::whereKey(0)->exists())->toBeTrue();
});

it('replaces a stale Windows testing-host key with the generated runtime identity', function () {
    $runtimeKeyPair = generateSSHKey('ed25519');
    $testingHostPrivateKeyPath = tempnam(sys_get_temp_dir(), 'coolify-testing-host-key-');
    file_put_contents($testingHostPrivateKeyPath, $runtimeKeyPair['private']);
    $this->beforeApplicationDestroyed(fn () => @unlink($testingHostPrivateKeyPath));

    config([
        'broadcasting.default' => 'log',
        'constants.coolify.is_windows_docker_desktop' => true,
        'constants.coolify.testing_host_private_key_path' => $testingHostPrivateKeyPath,
    ]);
    Queue::fake();

    $this->seed(ProductionSeeder::class);
    $stalePrivateKey = PrivateKey::findOrFail(0);
    $stalePrivateKey->private_key = generateSSHKey('ed25519')['private'];
    $stalePrivateKey->save();
    $this->seed(ProductionSeeder::class);

    $storedPrivateKey = PrivateKey::findOrFail(0)->private_key;
    $storedPublicKey = PrivateKey::extractPublicKeyFromPrivate($storedPrivateKey);
    $expectedPublicKey = collect(explode(' ', trim($runtimeKeyPair['public'])))->take(2)->implode(' ');

    expect($storedPrivateKey)->toBe($runtimeKeyPair['private'])
        ->and(collect(explode(' ', trim((string) $storedPublicKey)))->take(2)->implode(' '))->toBe($expectedPublicKey);
});
