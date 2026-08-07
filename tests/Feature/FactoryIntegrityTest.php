<?php

use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Process::fake();
    seedInstanceSettings();
});

it('creates a server with a resolvable private key owned by its team', function (): void {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $privateKey = $server->privateKey()->firstOrFail();

    expect($server->team_id)->toBe($team->id)
        ->and($privateKey->id)->toBe($server->private_key_id)
        ->and($privateKey->team_id)->toBe($team->id)
        ->and(PrivateKey::validatePrivateKey($privateKey->private_key))->toBeTrue();
});

it('builds distinct valid Ed25519 private key material in memory', function (): void {
    $team = Team::factory()->create();
    [$first, $second] = PrivateKey::factory()
        ->count(2)
        ->make(['team_id' => $team->id])
        ->all();

    expect($first->team_id)->toBe($team->id)
        ->and($second->team_id)->toBe($team->id)
        ->and($first->private_key)->not->toBe($second->private_key)
        ->and(PrivateKey::validatePrivateKey($first->private_key))->toBeTrue()
        ->and(PrivateKey::validatePrivateKey($second->private_key))->toBeTrue()
        ->and(PrivateKey::extractPublicKeyFromPrivate($first->private_key))->toStartWith('ssh-ed25519 ')
        ->and(PrivateKey::extractPublicKeyFromPrivate($second->private_key))->toStartWith('ssh-ed25519 ');
});

it('persists distinct factory private key material and fingerprints', function (): void {
    $team = Team::factory()->create();
    [$first, $second] = PrivateKey::factory()
        ->count(2)
        ->create(['team_id' => $team->id])
        ->all();

    $storedFirst = PrivateKey::query()->findOrFail($first->getKey());
    $storedSecond = PrivateKey::query()->findOrFail($second->getKey());

    $this->assertModelExists($storedFirst);
    $this->assertModelExists($storedSecond);

    expect($storedFirst->team_id)->toBe($team->id)
        ->and($storedSecond->team_id)->toBe($team->id)
        ->and($storedFirst->private_key)->not->toBe($storedSecond->private_key)
        ->and($storedFirst->fingerprint)->toBe(PrivateKey::generateFingerprint($storedFirst->private_key))
        ->and($storedSecond->fingerprint)->toBe(PrivateKey::generateFingerprint($storedSecond->private_key))
        ->and($storedFirst->fingerprint)->not->toBe($storedSecond->fingerprint);
});

it('preserves an explicitly shared same-team private key', function (): void {
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);

    $servers = Server::factory()->count(2)->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);

    expect($servers->pluck('private_key_id')->all())->toBe([$privateKey->id, $privateKey->id])
        ->and(PrivateKey::query()->where('team_id', $team->id)->count())->toBe(1);
});

it('isolates private key filesystem writes for factory-made servers', function (): void {
    $server = Server::factory()->create();
    $privateKey = $server->privateKey()->firstOrFail();

    $privateKey->update(['private_key' => generateSSHKey('ed25519')['private']]);

    $keyLocation = $privateKey->getKeyLocation();

    expect($keyLocation)->toStartWith(storage_path('framework/testing/disks/ssh-keys'))
        ->and(Storage::disk('ssh-keys')->exists("ssh_key@{$privateKey->uuid}"))->toBeTrue()
        ->and(file_exists($keyLocation.'.lock'))->toBeTrue();
});

it('configures fake SSH key storage for browser tests', function (): void {
    $pestConfiguration = file_get_contents(base_path('tests/Pest.php'));

    expect($pestConfiguration)->toContain(<<<'PHP'
uses()
    ->beforeEach(function (): void {
        // Model events still persist SSH keys, but only inside this test's disk.
        Storage::fake('ssh-keys');
    })
    ->in('Feature', 'v4/Feature', 'v4/Browser');
PHP);
});
