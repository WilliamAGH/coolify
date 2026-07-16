<?php

namespace Database\Seeders;

use App\Models\PrivateKey;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PrivateKeySeeder extends Seeder
{
    private const TESTING_HOST_KEY_UUID = 'ssh';

    private const TESTING_HOST_KEY_NAME = 'Testing Host Key';

    private const TESTING_HOST_KEY_DESCRIPTION = 'This is a test docker container';

    public function run(): void
    {
        self::testingHostKey();
    }

    public static function testingHostKey(): PrivateKey
    {
        $testingHostKey = PrivateKey::query()
            ->where('uuid', self::TESTING_HOST_KEY_UUID)
            ->first();

        if ($testingHostKey !== null) {
            self::synchronizeRuntimeTestingHostKey($testingHostKey);

            return $testingHostKey;
        }

        return PrivateKey::forceCreate([
            'uuid' => self::TESTING_HOST_KEY_UUID,
            'team_id' => 0,
            'name' => self::TESTING_HOST_KEY_NAME,
            'description' => self::TESTING_HOST_KEY_DESCRIPTION,
            'private_key' => self::testingHostPrivateKey(),
        ]);
    }

    public static function testingHostPrivateKey(): string
    {
        $disk = Storage::disk('testing-host-key');

        if ($disk->exists('testing-host')) {
            $privateKey = $disk->get('testing-host');

            if (is_string($privateKey) && PrivateKey::validatePrivateKey($privateKey)) {
                return $privateKey;
            }
        }

        if (app()->runningUnitTests()) {
            $privateKey = PrivateKey::generateNewKeyPair('ed25519')['private_key'];

            if (! $disk->put('testing-host', $privateKey)) {
                throw new RuntimeException('The runtime testing-host key could not be created for the test.');
            }

            return $privateKey;
        }

        throw new RuntimeException('The runtime testing-host key is missing or invalid. Start the testing-host-keygen service before seeding.');
    }

    private static function synchronizeRuntimeTestingHostKey(PrivateKey $testingHostKey): void
    {
        if (! self::hasTestingHostIdentity($testingHostKey)) {
            throw new RuntimeException('Private key uuid=ssh is not the canonical testing-host key and will not be replaced.');
        }

        $runtimePrivateKey = self::testingHostPrivateKey();

        if (! hash_equals($testingHostKey->private_key, $runtimePrivateKey)) {
            $testingHostKey->updatePrivateKey(['private_key' => $runtimePrivateKey]);
        }
    }

    private static function hasTestingHostIdentity(PrivateKey $testingHostKey): bool
    {
        return $testingHostKey->uuid === self::TESTING_HOST_KEY_UUID
            && (int) $testingHostKey->team_id === 0
            && $testingHostKey->name === self::TESTING_HOST_KEY_NAME
            && $testingHostKey->description === self::TESTING_HOST_KEY_DESCRIPTION
            && ! (bool) $testingHostKey->is_git_related;
    }
}
