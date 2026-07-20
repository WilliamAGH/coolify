<?php

use App\Actions\Fortify\CreateNewUser;
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
    $runtimeKeyPair = generateSSHKey('ed25519');
    $testingHostPrivateKeyPath = tempnam(sys_get_temp_dir(), 'coolify-testing-host-key-');
    expect($testingHostPrivateKeyPath)->not->toBeFalse()
        ->and(file_put_contents($testingHostPrivateKeyPath, $runtimeKeyPair['private']))->not->toBeFalse();
    $this->beforeApplicationDestroyed(fn () => @unlink($testingHostPrivateKeyPath));

    config([
        'broadcasting.default' => 'log',
        'constants.coolify.is_windows_docker_desktop' => true,
        'constants.coolify.testing_host_private_key_path' => $testingHostPrivateKeyPath,
    ]);
    Queue::fake();

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
    $testingHostPrivateKey = PrivateKey::find(0);

    expect($rootTeam)->not->toBeNull()
        ->and($localhostServer)->not->toBeNull()
        ->and($localhostServer->team_id)->toBe(0)
        ->and($testingHostPrivateKey)->not->toBeNull()
        ->and($testingHostPrivateKey->private_key)->toBe($runtimeKeyPair['private']);

    $expectedPublicKey = collect(explode(' ', trim($runtimeKeyPair['public'])))->take(2)->implode(' ');
    $actualPublicKey = collect(explode(' ', trim((string) PrivateKey::extractPublicKeyFromPrivate($testingHostPrivateKey->private_key))))->take(2)->implode(' ');

    expect($actualPublicKey)->toBe($expectedPublicKey);

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
        'broadcasting.default' => 'log',
        'constants.coolify.is_windows_docker_desktop' => true,
        'constants.coolify.testing_host_private_key_path' => sys_get_temp_dir().'/coolify-missing-testing-host-key-'.uniqid(),
    ]);
    Queue::fake();

    expect(fn () => $this->seed(ProductionSeeder::class))
        ->toThrow(RuntimeException::class, 'The runtime testing-host private key is unavailable.');
});

it('fails closed when the Windows testing-host runtime key is unreadable', function () {
    $testingHostPrivateKeyPath = tempnam(sys_get_temp_dir(), 'coolify-testing-host-key-');
    expect($testingHostPrivateKeyPath)->not->toBeFalse()
        ->and(file_put_contents($testingHostPrivateKeyPath, generateSSHKey('ed25519')['private']))->not->toBeFalse()
        ->and(chmod($testingHostPrivateKeyPath, 0000))->toBeTrue();
    clearstatcache(true, $testingHostPrivateKeyPath);

    config([
        'broadcasting.default' => 'log',
        'constants.coolify.is_windows_docker_desktop' => true,
        'constants.coolify.testing_host_private_key_path' => $testingHostPrivateKeyPath,
    ]);
    Queue::fake();

    try {
        expect(fn () => $this->seed(ProductionSeeder::class))
            ->toThrow(RuntimeException::class, 'The runtime testing-host private key is unavailable.');
    } finally {
        chmod($testingHostPrivateKeyPath, 0600);
        unlink($testingHostPrivateKeyPath);
    }
});

it('fails closed when the Windows testing-host runtime key is invalid', function () {
    $testingHostPrivateKeyPath = tempnam(sys_get_temp_dir(), 'coolify-testing-host-key-');
    expect($testingHostPrivateKeyPath)->not->toBeFalse()
        ->and(file_put_contents($testingHostPrivateKeyPath, 'not-an-ssh-private-key'))->not->toBeFalse();
    $this->beforeApplicationDestroyed(fn () => @unlink($testingHostPrivateKeyPath));

    config([
        'broadcasting.default' => 'log',
        'constants.coolify.is_windows_docker_desktop' => true,
        'constants.coolify.testing_host_private_key_path' => $testingHostPrivateKeyPath,
    ]);
    Queue::fake();

    expect(fn () => $this->seed(ProductionSeeder::class))
        ->toThrow(RuntimeException::class, 'The runtime testing-host private key is invalid.');
});

it('fails closed when the Windows testing-host runtime key contains only a public key', function () {
    $testingHostPrivateKeyPath = tempnam(sys_get_temp_dir(), 'coolify-testing-host-key-');
    expect($testingHostPrivateKeyPath)->not->toBeFalse()
        ->and(file_put_contents($testingHostPrivateKeyPath, generateSSHKey('ed25519')['public']))->not->toBeFalse();
    $this->beforeApplicationDestroyed(fn () => @unlink($testingHostPrivateKeyPath));

    config([
        'broadcasting.default' => 'log',
        'constants.coolify.is_windows_docker_desktop' => true,
        'constants.coolify.testing_host_private_key_path' => $testingHostPrivateKeyPath,
    ]);
    Queue::fake();

    expect(fn () => $this->seed(ProductionSeeder::class))
        ->toThrow(RuntimeException::class, 'The runtime testing-host private key is invalid.');
});

it('fails closed when the Windows testing-host runtime key is a symlink', function () {
    $targetPath = tempnam(sys_get_temp_dir(), 'coolify-testing-host-key-target-');
    expect($targetPath)->not->toBeFalse()
        ->and(file_put_contents($targetPath, generateSSHKey('ed25519')['private']))->not->toBeFalse();
    $symlinkPath = $targetPath.'-link';
    expect(symlink($targetPath, $symlinkPath))->toBeTrue();

    config([
        'broadcasting.default' => 'log',
        'constants.coolify.is_windows_docker_desktop' => true,
        'constants.coolify.testing_host_private_key_path' => $symlinkPath,
    ]);
    Queue::fake();

    try {
        expect(fn () => $this->seed(ProductionSeeder::class))
            ->toThrow(RuntimeException::class, 'The runtime testing-host private key is unavailable.');
    } finally {
        unlink($symlinkPath);
        unlink($targetPath);
    }
});

it('routes the default production seed graph through ProductionSeeder', function () {
    $testingHostPrivateKeyPath = tempnam(sys_get_temp_dir(), 'coolify-testing-host-key-');
    expect($testingHostPrivateKeyPath)->not->toBeFalse()
        ->and(file_put_contents($testingHostPrivateKeyPath, generateSSHKey('ed25519')['private']))->not->toBeFalse();
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
    expect($testingHostPrivateKeyPath)->not->toBeFalse()
        ->and(file_put_contents($testingHostPrivateKeyPath, $runtimeKeyPair['private']))->not->toBeFalse();
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

    expect(PrivateKey::findOrFail(0)->private_key)->toBe($runtimeKeyPair['private']);
});
