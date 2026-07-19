<?php

namespace App\Support;

use RuntimeException;

final class ControlPlaneMigrationInventory
{
    private const FILENAME_PATTERN = '/\A(?:2026_07_12_[0-9]{6}_[a-z0-9_]+|2026_07_19_(?:025448_add_destination_fencing_to_blue_green_operations|025449_add_blue_green_supersession_generation|030000_add_blue_green_drain_provenance|064536_add_blue_green_recovery_predecessor_state|120000_add_blue_green_inactive_retention_setting|120001_add_blue_green_inactive_retirement_provenance))\.php\z/';

    private const GLOBS = [
        '2026_07_12_*.php',
        '2026_07_19_025448_add_destination_fencing_to_blue_green_operations.php',
        '2026_07_19_025449_add_blue_green_supersession_generation.php',
        '2026_07_19_030000_add_blue_green_drain_provenance.php',
        '2026_07_19_064536_add_blue_green_recovery_predecessor_state.php',
        '2026_07_19_120000_add_blue_green_inactive_retention_setting.php',
        '2026_07_19_120001_add_blue_green_inactive_retirement_provenance.php',
    ];

    private const FINGERPRINT_PATH = 'database/migrations/control-plane-migration-inventory.fingerprint';

    /** @return list<string> */
    public static function names(
        ?string $migrationDirectory = null,
        ?string $fingerprintPath = null,
        bool $requireExclusiveMigrationDirectory = false,
    ): array {
        $migrationDirectory ??= database_path('migrations');
        $fingerprintPath ??= base_path(self::FINGERPRINT_PATH);
        if (! is_dir($migrationDirectory) || is_link($migrationDirectory) || ! is_readable($migrationDirectory)) {
            throw new RuntimeException('Control-plane migration directory is missing, linked, or unreadable.');
        }

        $migrationPaths = [];
        foreach (self::GLOBS as $pattern) {
            $matches = glob($migrationDirectory.DIRECTORY_SEPARATOR.$pattern, GLOB_NOSORT);
            if (! is_array($matches)) {
                throw new RuntimeException('Control-plane migration inventory is unavailable.');
            }
            $migrationPaths = [...$migrationPaths, ...$matches];
        }
        if ($migrationPaths === []) {
            throw new RuntimeException('Control-plane migration inventory is unavailable.');
        }
        sort($migrationPaths, SORT_STRING);

        $migrationNames = [];
        foreach ($migrationPaths as $migrationPath) {
            $filename = basename($migrationPath);
            if (! is_file($migrationPath)
                || is_link($migrationPath)
                || ! is_readable($migrationPath)
                || preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
                throw new RuntimeException('Control-plane migration inventory contains an unsafe or unexpected file.');
            }

            $migrationName = pathinfo($filename, PATHINFO_FILENAME);
            if (isset($migrationNames[$migrationName])) {
                throw new RuntimeException('Control-plane migration inventory contains duplicate filenames.');
            }

            $migrationNames[$migrationName] = true;
        }

        $migrationNames = array_keys($migrationNames);
        self::assertReviewedInventory($migrationNames, $fingerprintPath);
        if ($requireExclusiveMigrationDirectory) {
            self::assertExclusiveDirectory($migrationDirectory, $migrationNames);
        }

        return $migrationNames;
    }

    /**
     * @param  list<string>  $migrationNames
     */
    private static function assertReviewedInventory(array $migrationNames, string $fingerprintPath): void
    {
        if (! is_file($fingerprintPath) || is_link($fingerprintPath) || ! is_readable($fingerprintPath)) {
            throw new RuntimeException('Control-plane migration inventory fingerprint is missing, linked, or unreadable.');
        }

        $fingerprint = file_get_contents($fingerprintPath);
        if (! is_string($fingerprint)
            || preg_match('/\Acount=([1-9][0-9]*)\nsha256=([0-9a-f]{64})\n\z/', $fingerprint, $matches) !== 1) {
            throw new RuntimeException('Control-plane migration inventory fingerprint is malformed.');
        }

        $relativeMigrationPaths = array_map(
            fn (string $migrationName): string => "database/migrations/{$migrationName}.php",
            $migrationNames,
        );
        $actualFingerprint = hash('sha256', implode("\n", $relativeMigrationPaths)."\n");
        if (count($migrationNames) !== (int) $matches[1]
            || ! hash_equals($matches[2], $actualFingerprint)) {
            throw new RuntimeException('Control-plane migration inventory does not match the reviewed fingerprint.');
        }
    }

    /**
     * @param  list<string>  $authorizedMigrationNames
     */
    private static function assertExclusiveDirectory(
        string $migrationDirectory,
        array $authorizedMigrationNames,
    ): void {
        $migrationPaths = glob($migrationDirectory.DIRECTORY_SEPARATOR.'*.php', GLOB_NOSORT);
        if (! is_array($migrationPaths)) {
            throw new RuntimeException('Control-plane migration files are unavailable.');
        }
        sort($migrationPaths, SORT_STRING);

        $migrationNames = [];
        foreach ($migrationPaths as $migrationPath) {
            $migrationName = pathinfo($migrationPath, PATHINFO_FILENAME);
            if (! is_file($migrationPath)
                || is_link($migrationPath)
                || ! is_readable($migrationPath)
                || isset($migrationNames[$migrationName])) {
                throw new RuntimeException('Control-plane migration directory contains unsafe or duplicate files.');
            }

            $migrationNames[$migrationName] = true;
        }

        if (array_keys($migrationNames) !== $authorizedMigrationNames) {
            throw new RuntimeException('Control-plane migration directory contains unexpected migration files.');
        }
    }
}
