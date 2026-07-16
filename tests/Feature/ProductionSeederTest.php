<?php

use App\Actions\Fortify\CreateNewUser;
use App\Exceptions\ControlPlaneMutationLockedException;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\SslCertificate;
use App\Models\Team;
use Database\Seeders\PrivateKeySeeder;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ControlPlaneStateFixture;

uses(RefreshDatabase::class);

$productionSeederControlPlaneEnvironment = [
    'CONTROL_PLANE_MODE' => getenv('CONTROL_PLANE_MODE'),
    'CONTROL_PLANE_STARTUP_MODE' => getenv('CONTROL_PLANE_STARTUP_MODE'),
    'CONTROL_PLANE_MUTATION_FREEZE_EPOCH' => getenv('CONTROL_PLANE_MUTATION_FREEZE_EPOCH'),
    'CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH' => getenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH'),
];

afterEach(function () use ($productionSeederControlPlaneEnvironment) {
    foreach ($productionSeederControlPlaneEnvironment as $name => $value) {
        putenv($value === false ? $name : "{$name}={$value}");
    }
});

it('creates the root team before seeding the localhost server and predefined shared variables', function () {
    config([
        'broadcasting.default' => 'log',
        'constants.coolify.is_windows_docker_desktop' => true,
    ]);
    Storage::fake('ssh-keys');
    Storage::fake('testing-host-key');
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
    $testingHostKey = PrivateKey::query()->find(0);

    expect($rootTeam)->not->toBeNull()
        ->and($localhostServer)->not->toBeNull()
        ->and($localhostServer->team_id)->toBe(0)
        ->and($testingHostKey)->not->toBeNull()
        ->and(PrivateKey::validatePrivateKey($testingHostKey->private_key))->toBeTrue();

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

it('preserves the baseline Windows testing-host key instead of synchronizing the runtime key', function () {
    config([
        'broadcasting.default' => 'log',
        'constants.coolify.is_windows_docker_desktop' => true,
    ]);
    Storage::fake('ssh-keys');
    Storage::fake('testing-host-key');
    Queue::fake();

    (new Team)->forceFill([
        'id' => 0,
        'name' => 'Root Team',
        'description' => 'The root team',
        'personal_team' => true,
    ])->save();

    $previousPrivateKey = PrivateKey::generateNewKeyPair('ed25519')['private_key'];
    $runtimePrivateKey = PrivateKey::generateNewKeyPair('ed25519')['private_key'];
    Storage::disk('testing-host-key')->put('testing-host', $runtimePrivateKey);

    PrivateKey::forceCreate([
        'id' => 0,
        'team_id' => 0,
        'name' => 'Testing-host',
        'description' => 'This is a a docker container with SSH access',
        'private_key' => $previousPrivateKey,
    ]);

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

    expect(PrivateKey::query()->find(0)?->private_key)->not->toBe($runtimePrivateKey);

    $this->seed(ProductionSeeder::class);

    expect(PrivateKey::query()->whereKey(0)->count())->toBe(1)
        ->and(PrivateKey::query()->find(0)?->private_key)->not->toBe($runtimePrivateKey);
});

it('retains the historical Windows key update behavior', function () {
    config([
        'broadcasting.default' => 'log',
        'constants.coolify.is_windows_docker_desktop' => true,
    ]);
    Storage::fake('ssh-keys');
    Storage::fake('testing-host-key');
    Queue::fake();

    (new Team)->forceFill([
        'id' => 0,
        'name' => 'Root Team',
        'description' => 'The root team',
        'personal_team' => true,
    ])->save();

    PrivateKey::forceCreate([
        'id' => 0,
        'team_id' => 0,
        'name' => 'User-managed key',
        'description' => 'Must never be replaced by a development seeder',
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
    ]);

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

    expect(PrivateKey::query()->find(0))
        ->name->toBe('Testing-host')
        ->description->toBe('This is a a docker container with SSH access');
});

it('refuses direct production seeding while a durable mutation freeze is active', function () {
    if (($unsupportedReason = ControlPlaneStateFixture::unsupportedReason()) !== null) {
        $this->markTestSkipped($unsupportedReason);
    }

    $fixture = ControlPlaneStateFixture::create('coolify-production-seeder-mutation-freeze');
    $freezeEpoch = 'operation-0123456789.fwd.mutation-freeze';

    try {
        putenv('CONTROL_PLANE_MODE=active');
        putenv('CONTROL_PLANE_STARTUP_MODE=full');
        putenv("CONTROL_PLANE_MUTATION_FREEZE_EPOCH={$freezeEpoch}");
        putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH='.$fixture->path('mutation-freeze-epoch'));
        $fixture->writeMarker('mutation-freeze-epoch', $freezeEpoch);
        $fixture->writeMutationLease();

        expect(fn () => $this->seed(ProductionSeeder::class))
            ->toThrow(ControlPlaneMutationLockedException::class)
            ->and(Team::find(0))->toBeNull();
    } finally {
        $fixture->cleanup();
    }
});

it('allocates a distinct identity sequence value for keys created after seeding the testing-host key', function () {
    Storage::fake('testing-host-key');

    Team::forceCreate([
        'id' => 0,
        'name' => 'Root Team',
        'personal_team' => true,
    ]);

    $testingHostKey = PrivateKeySeeder::testingHostKey();

    $postSeedKey = PrivateKey::create([
        'team_id' => 0,
        'name' => 'post-seed key',
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
    ]);

    expect($testingHostKey->uuid)->toBe('ssh')
        ->and($postSeedKey->getKey())->not->toBe($testingHostKey->getKey())
        ->and(PrivateKey::query()->whereKey($postSeedKey->getKey())->exists())->toBeTrue()
        ->and(PrivateKeySeeder::testingHostKey()->getKey())->toBe($testingHostKey->getKey());
});
