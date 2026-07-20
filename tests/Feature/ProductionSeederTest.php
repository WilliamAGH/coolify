<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\SslCertificate;
use App\Models\Team;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('creates the root team before seeding the localhost server and predefined shared variables', function () {
    config([
        'broadcasting.default' => 'log',
        'constants.coolify.is_windows_docker_desktop' => true,
        'constants.coolify.windows_testing_host_private_key_path' => base_path('docker/testing-host/development-private_key'),
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

    expect($rootTeam)->not->toBeNull()
        ->and($localhostServer)->not->toBeNull()
        ->and($localhostServer->team_id)->toBe(0);

    $testingHostPrivateKey = PrivateKey::find(0);
    $authorizedKey = trim((string) file_get_contents(base_path('docker/testing-host/development-authorized_keys')));
    $expectedPublicKey = implode(' ', array_slice(explode(' ', $authorizedKey), 0, 2));
    $actualPublicKey = implode(' ', array_slice(explode(' ', $testingHostPrivateKey?->public_key ?? ''), 0, 2));

    expect($testingHostPrivateKey)->not->toBeNull()
        ->and($actualPublicKey)->toBe($expectedPublicKey);

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

it('fails closed when the Windows testing-host private-key fixture is unavailable', function () {
    config([
        'broadcasting.default' => 'log',
        'constants.coolify.is_windows_docker_desktop' => true,
        'constants.coolify.windows_testing_host_private_key_path' => sys_get_temp_dir().'/coolify-missing-testing-host-key-'.uniqid(),
    ]);
    Queue::fake();

    expect(fn () => $this->seed(ProductionSeeder::class))
        ->toThrow(RuntimeException::class, 'Windows Docker Desktop requires a readable testing-host private key fixture.');
});

it('fails closed when the Windows testing-host private-key fixture is invalid', function () {
    $fixture = tmpfile();
    expect($fixture)->not->toBeFalse();
    fwrite($fixture, 'not a private key');
    $fixtureMetadata = stream_get_meta_data($fixture);

    config([
        'broadcasting.default' => 'log',
        'constants.coolify.is_windows_docker_desktop' => true,
        'constants.coolify.windows_testing_host_private_key_path' => $fixtureMetadata['uri'],
    ]);
    Queue::fake();

    try {
        expect(fn () => $this->seed(ProductionSeeder::class))
            ->toThrow(RuntimeException::class, 'Windows Docker Desktop testing-host private key fixture is invalid.');
    } finally {
        fclose($fixture);
    }
});

it('fails closed when the Windows testing-host private-key fixture contains only a public key', function () {
    config([
        'broadcasting.default' => 'log',
        'constants.coolify.is_windows_docker_desktop' => true,
        'constants.coolify.windows_testing_host_private_key_path' => base_path('docker/testing-host/development-authorized_keys'),
    ]);
    Queue::fake();

    expect(fn () => $this->seed(ProductionSeeder::class))
        ->toThrow(RuntimeException::class, 'Windows Docker Desktop testing-host private key fixture is invalid.');
});

it('fails closed when the Windows testing-host private-key fixture is a symlink', function () {
    $target = tempnam(sys_get_temp_dir(), 'coolify-testing-host-key-target-');
    $link = $target.'-link';
    expect($target)->not->toBeFalse()
        ->and(file_put_contents($target, file_get_contents(base_path('docker/testing-host/development-private_key'))))->toBeInt()
        ->and(symlink($target, $link))->toBeTrue();

    config([
        'broadcasting.default' => 'log',
        'constants.coolify.is_windows_docker_desktop' => true,
        'constants.coolify.windows_testing_host_private_key_path' => $link,
    ]);
    Queue::fake();

    try {
        expect(fn () => $this->seed(ProductionSeeder::class))
            ->toThrow(RuntimeException::class, 'Windows Docker Desktop requires a readable testing-host private key fixture.');
    } finally {
        unlink($link);
        unlink($target);
    }
});
