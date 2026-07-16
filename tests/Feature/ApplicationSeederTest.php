<?php

use App\Models\Application;
use App\Models\PrivateKey;
use App\Models\Server;
use Database\Seeders\ApplicationSeeder;
use Database\Seeders\GithubAppSeeder;
use Database\Seeders\PrivateKeySeeder;
use Database\Seeders\ProjectSeeder;
use Database\Seeders\ServerSeeder;
use Database\Seeders\StandaloneDockerSeeder;
use Database\Seeders\TeamSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('seeds the default applications without railpack examples', function () {
    $this->seed([
        UserSeeder::class,
        TeamSeeder::class,
        PrivateKeySeeder::class,
        ServerSeeder::class,
        ProjectSeeder::class,
        StandaloneDockerSeeder::class,
        GithubAppSeeder::class,
        ApplicationSeeder::class,
    ]);

    $nixpacksExample = Application::where('uuid', 'nodejs')->first();

    expect($nixpacksExample)
        ->not->toBeNull()
        ->and($nixpacksExample->name)->toBe('NodeJS Fastify Example')
        ->and($nixpacksExample->build_pack)->toBe('nixpacks')
        ->and($nixpacksExample->base_directory)->toBe('/nodejs')
        ->and($nixpacksExample->ports_exposes)->toBe('3000');

    expect(Application::query()->where('build_pack', 'railpack')->exists())->toBeFalse();
    expect(Application::query()->whereIn('uuid', ['railpack-nodejs', 'railpack-static'])->exists())->toBeFalse();
});

it('propagates the canonical testing-host key ID to seeded servers and deploy-key examples', function () {
    Storage::fake('ssh-keys');
    Storage::fake('testing-host-key');

    $this->seed([
        UserSeeder::class,
        TeamSeeder::class,
    ]);

    PrivateKey::forceCreate([
        'id' => 73,
        'uuid' => 'ssh',
        'team_id' => 0,
        'name' => 'Testing Host Key',
        'description' => 'This is a test docker container',
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
    ]);

    $this->seed([
        PrivateKeySeeder::class,
        ServerSeeder::class,
        ProjectSeeder::class,
        StandaloneDockerSeeder::class,
        GithubAppSeeder::class,
        ApplicationSeeder::class,
    ]);

    expect(Server::query()->find(0)?->private_key_id)->toBe(73)
        ->and(Application::query()
            ->whereIn('uuid', ['github-deploy-key', 'gitlab-deploy-key'])
            ->pluck('private_key_id')
            ->all())
        ->toBe([73, 73]);
});
