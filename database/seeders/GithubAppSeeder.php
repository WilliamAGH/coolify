<?php

namespace Database\Seeders;

use App\Models\GithubApp;
use App\Models\PrivateKey;
use Illuminate\Database\Seeder;

class GithubAppSeeder extends Seeder
{
    private const LEGACY_DEVELOPMENT_GITHUB_APP_UUID = 'github-app';

    private const LEGACY_DEVELOPMENT_GITHUB_APP_NAME = 'coolify-laravel-dev-public';

    private const LEGACY_DEVELOPMENT_GITHUB_APP_ORGANIZATION = 'coollabsio';

    private const LEGACY_DEVELOPMENT_GITHUB_APP_ID = 292941;

    private const LEGACY_DEVELOPMENT_GITHUB_APP_INSTALLATION_ID = 37267016;

    private const LEGACY_DEVELOPMENT_GITHUB_APP_CLIENT_ID = 'Iv1.220e564d2b0abd8c';

    private const LEGACY_DEVELOPMENT_GITHUB_PRIVATE_KEY_ID = 2;

    private const LEGACY_DEVELOPMENT_GITHUB_PRIVATE_KEY_UUID = 'github-key';

    private const LEGACY_DEVELOPMENT_GITHUB_PRIVATE_KEY_NAME = 'development-github-app';

    private const LEGACY_DEVELOPMENT_GITHUB_PRIVATE_KEY_DESCRIPTION = 'This is the key for using the development GitHub app';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->removeLegacyDevelopmentGithubApp();

        GithubApp::query()->firstOrCreate(
            ['id' => 0],
            [
                'id' => 0,
                'uuid' => 'github-public',
                'name' => 'Public GitHub',
                'api_url' => 'https://api.github.com',
                'html_url' => 'https://github.com',
                'is_public' => true,
                'team_id' => 0,
            ],
        );
    }

    private function removeLegacyDevelopmentGithubApp(): void
    {
        $legacyGithubApp = GithubApp::query()
            ->where('uuid', self::LEGACY_DEVELOPMENT_GITHUB_APP_UUID)
            ->first();

        if ($legacyGithubApp === null || ! $this->hasLegacyDevelopmentGithubAppIdentity($legacyGithubApp)) {
            return;
        }

        $legacyPrivateKey = $legacyGithubApp->privateKey;

        if ($legacyGithubApp->applications()->exists()) {
            $legacyGithubApp->update([
                'private_key_id' => null,
                'app_id' => null,
                'installation_id' => null,
                'client_id' => null,
                'client_secret' => null,
                'webhook_secret' => null,
            ]);
            $this->deleteLegacyDevelopmentGithubPrivateKeyIfUnused($legacyPrivateKey);

            return;
        }

        if ($legacyPrivateKey !== null && ! $this->hasLegacyDevelopmentGithubPrivateKeyIdentity($legacyPrivateKey)) {
            $legacyGithubApp->update(['private_key_id' => null]);
        }

        $legacyGithubApp->delete();
    }

    private function hasLegacyDevelopmentGithubAppIdentity(GithubApp $githubApp): bool
    {
        return $githubApp->uuid === self::LEGACY_DEVELOPMENT_GITHUB_APP_UUID
            && (int) $githubApp->team_id === 0
            && $githubApp->name === self::LEGACY_DEVELOPMENT_GITHUB_APP_NAME
            && $githubApp->organization === self::LEGACY_DEVELOPMENT_GITHUB_APP_ORGANIZATION
            && $githubApp->api_url === 'https://api.github.com'
            && $githubApp->html_url === 'https://github.com'
            && ! $githubApp->is_public
            && ! $githubApp->is_system_wide
            && $githubApp->custom_user === 'git'
            && (int) $githubApp->custom_port === 22
            && (int) $githubApp->app_id === self::LEGACY_DEVELOPMENT_GITHUB_APP_ID
            && (int) $githubApp->installation_id === self::LEGACY_DEVELOPMENT_GITHUB_APP_INSTALLATION_ID
            && $githubApp->client_id === self::LEGACY_DEVELOPMENT_GITHUB_APP_CLIENT_ID
            && (int) $githubApp->private_key_id === self::LEGACY_DEVELOPMENT_GITHUB_PRIVATE_KEY_ID;
    }

    private function hasLegacyDevelopmentGithubPrivateKeyIdentity(PrivateKey $privateKey): bool
    {
        return $privateKey->id === self::LEGACY_DEVELOPMENT_GITHUB_PRIVATE_KEY_ID
            && $privateKey->uuid === self::LEGACY_DEVELOPMENT_GITHUB_PRIVATE_KEY_UUID
            && (int) $privateKey->team_id === 0
            && $privateKey->name === self::LEGACY_DEVELOPMENT_GITHUB_PRIVATE_KEY_NAME
            && $privateKey->description === self::LEGACY_DEVELOPMENT_GITHUB_PRIVATE_KEY_DESCRIPTION
            && (bool) $privateKey->is_git_related;
    }

    private function deleteLegacyDevelopmentGithubPrivateKeyIfUnused(?PrivateKey $legacyPrivateKey): void
    {
        if ($legacyPrivateKey !== null && $this->hasLegacyDevelopmentGithubPrivateKeyIdentity($legacyPrivateKey)) {
            $legacyPrivateKey->safeDelete();
        }
    }
}
