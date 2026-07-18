<?php

namespace App\Actions\Server;

use App\Models\Server;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use UnexpectedValueException;

class UpdateCoolify
{
    use AsAction;

    private const string SEMANTIC_VERSION_PATTERN = '/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-(?:(?:0|[1-9]\d*)|(?:\d*[A-Za-z-][0-9A-Za-z-]*))(?:\.(?:(?:0|[1-9]\d*)|(?:\d*[A-Za-z-][0-9A-Za-z-]*)))*)?\z/D';

    private const string FORK_VERSION_PATTERN = '/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)-fork(?:\.[1-9]\d*)?\z/D';

    private const string HTTPS_URL_PATTERN = '/\Ahttps:\/\/[A-Za-z0-9.-]+(?::[0-9]{1,5})?(?:\/[A-Za-z0-9._~!$&\'()*+,=:@%\/-]*)?(?:\?[A-Za-z0-9._~!$&\'()*+,=:@%\/?-]*)?\z/D';

    private const string UPGRADE_SCRIPT_PATH = '/data/coolify/source/upgrade.sh';

    public ?Server $server = null;

    public ?string $latestVersion = null;

    public ?string $latestHelperImageVersion = null;

    public ?string $currentVersion = null;

    public function handle(bool $manual_update = false): void
    {
        if (isDev()) {
            Sleep::for(10)->seconds();

            return;
        }
        $settings = instanceSettings();
        $this->server = Server::find(0);
        if (! $this->server) {
            return;
        }

        $this->currentVersion = $this->validatedSemanticVersion(
            config('constants.coolify.version'),
            'configured Coolify version',
        );
        if (self::isGuardedForkRelease($this->currentVersion)) {
            Log::warning('Upstream updater disabled for fork release', [
                'current_version' => $this->currentVersion,
                'manual_update' => $manual_update,
            ]);
            $settings->new_version_available = false;
            $settings->save();

            if ($manual_update) {
                throw new RuntimeException(
                    'Fork releases must be updated through the guarded fork deployment workflow.'
                );
            }

            return;
        }

        $latestVersions = $this->latestVersions();
        $this->latestVersion = $latestVersions['applicationVersion'];
        $this->latestHelperImageVersion = $latestVersions['helperVersion'];

        if (version_compare($this->latestVersion, $this->currentVersion, '<')) {
            Log::error('Downgrade prevented', [
                'target_version' => $this->latestVersion,
                'current_version' => $this->currentVersion,
                'manual_update' => $manual_update,
            ]);
            throw new RuntimeException(
                "Cannot downgrade from {$this->currentVersion} to {$this->latestVersion}. ".
                'If you need to downgrade, please do so manually via Docker commands.'
            );
        }

        if (! $manual_update) {
            if (! $settings->is_auto_update_enabled || $this->latestVersion === $this->currentVersion) {
                return;
            }
        }

        $this->update();
        $settings->new_version_available = false;
        $settings->save();
    }

    public static function isGuardedForkRelease(mixed $version): bool
    {
        return is_string($version) && preg_match(self::FORK_VERSION_PATTERN, $version) === 1;
    }

    /**
     * @return array{applicationVersion: string, helperVersion: string}
     */
    private function latestVersions(): array
    {
        $versionsUrl = $this->validatedHttpsUrl(
            config('constants.coolify.versions_url'),
            'Coolify versions URL',
        );

        try {
            $response = Http::retry(3, 1000)->timeout(10)
                ->get($versionsUrl);
        } catch (\Throwable $e) {
            return $this->cachedLatestVersions($e->getMessage());
        }

        if (! $response->successful()) {
            return $this->cachedLatestVersions();
        }

        return $this->validatedVersionPayload($response->json(), 'CDN response');
    }

    /**
     * @return array{applicationVersion: string, helperVersion: string}
     */
    private function cachedLatestVersions(?string $error = null): array
    {
        $versions = $this->validatedVersionPayload(get_versions_data(), 'cached version metadata');
        if (version_compare($versions['applicationVersion'], $this->currentVersion, '<')) {
            Log::error('Failed to fetch fresh version from CDN and cache is corrupted/outdated', [
                'error' => $error,
                'cached_version' => $versions['applicationVersion'],
                'current_version' => $this->currentVersion,
            ]);
            throw new RuntimeException(
                'Cannot determine latest version: CDN unavailable and cache version '.
                "({$versions['applicationVersion']}) is older than running version ({$this->currentVersion})"
            );
        }

        Log::warning('Failed to fetch fresh version from CDN, using validated cache', [
            'error' => $error,
            'version' => $versions['applicationVersion'],
        ]);

        return $versions;
    }

    /**
     * @return array{applicationVersion: string, helperVersion: string}
     */
    private function validatedVersionPayload(mixed $versions, string $source): array
    {
        if (! is_array($versions)) {
            throw new UnexpectedValueException("{$source} must be a JSON object.");
        }

        return [
            'applicationVersion' => $this->validatedSemanticVersion(
                data_get($versions, 'coolify.v4.version'),
                "{$source} Coolify version",
            ),
            'helperVersion' => $this->validatedSemanticVersion(
                data_get($versions, 'coolify.helper.version'),
                "{$source} helper version",
            ),
        ];
    }

    private function validatedSemanticVersion(mixed $version, string $description): string
    {
        if (! is_string($version) || preg_match(self::SEMANTIC_VERSION_PATTERN, $version) !== 1) {
            throw new UnexpectedValueException("{$description} must be a semantic version.");
        }

        return $version;
    }

    private function validatedHttpsUrl(mixed $url, string $description): string
    {
        if (! is_string($url)
            || preg_match(self::HTTPS_URL_PATTERN, $url) !== 1
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new UnexpectedValueException("{$description} must be a valid HTTPS URL.");
        }

        $urlParts = parse_url($url);
        if (! is_array($urlParts)
            || isset($urlParts['user'], $urlParts['pass'], $urlParts['fragment'])
            || ! isset($urlParts['host'])
            || (isset($urlParts['port']) && ($urlParts['port'] < 1 || $urlParts['port'] > 65535))) {
            throw new UnexpectedValueException("{$description} must be a valid HTTPS URL.");
        }

        return $url;
    }

    private function update(): void
    {
        $latestVersion = $this->validatedSemanticVersion($this->latestVersion, 'latest Coolify version');
        $latestHelperImageVersion = $this->validatedSemanticVersion(
            $this->latestHelperImageVersion,
            'latest helper image version',
        );
        $upgradeScriptUrl = $this->validatedHttpsUrl(
            config('constants.coolify.upgrade_script_url'),
            'Coolify upgrade script URL',
        );

        remote_process($this->upgradeCommands(
            $upgradeScriptUrl,
            $latestVersion,
            $latestHelperImageVersion,
        ), $this->server);
    }

    /** @return array<int, string> */
    private function upgradeCommands(string $upgradeScriptUrl, string $latestVersion, string $latestHelperImageVersion): array
    {
        $upgradeScriptPath = escapeshellarg(self::UPGRADE_SCRIPT_PATH);

        return [
            'curl -fsSL -- '.escapeshellarg($upgradeScriptUrl).' -o '.$upgradeScriptPath,
            'bash '.$upgradeScriptPath.' '.escapeshellarg($latestVersion).' '.escapeshellarg($latestHelperImageVersion),
        ];
    }
}
