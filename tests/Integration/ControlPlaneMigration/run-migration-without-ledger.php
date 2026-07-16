<?php

declare(strict_types=1);

use App\Support\ControlPlaneMigrationInventory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

$repositoryRoot = is_dir('/workspace') ? '/workspace' : dirname(__DIR__, 3);
$migrationName = getenv('CONTROL_PLANE_MIGRATION_NAME');
require_once "{$repositoryRoot}/app/Support/ControlPlaneMigrationInventory.php";

try {
    $authorizedMigrationNames = ControlPlaneMigrationInventory::names(
        "{$repositoryRoot}/database/migrations",
        "{$repositoryRoot}/database/migrations/control-plane-migration-inventory.fingerprint",
    );
} catch (RuntimeException) {
    fwrite(STDERR, "CONTROL_PLANE_MIGRATION_NAME is invalid.\n");
    exit(64);
}

if (! is_string($migrationName) || ! in_array($migrationName, $authorizedMigrationNames, true)) {
    fwrite(STDERR, "CONTROL_PLANE_MIGRATION_NAME is invalid.\n");
    exit(64);
}

$migrationPath = "{$repositoryRoot}/database/migrations/{$migrationName}.php";
$realMigrationPath = realpath($migrationPath);
$authorizedDirectory = realpath("{$repositoryRoot}/database/migrations");
if ($realMigrationPath === false || $authorizedDirectory === false || dirname($realMigrationPath) !== $authorizedDirectory) {
    fwrite(STDERR, "Authorized migration file was not found.\n");
    exit(66);
}

require "{$repositoryRoot}/vendor/autoload.php";
$application = require "{$repositoryRoot}/bootstrap/app.php";
$application->make(Kernel::class)->bootstrap();

$migration = require $realMigrationPath;
if (! $migration instanceof Migration) {
    fwrite(STDERR, "Migration file did not return a Laravel migration.\n");
    exit(65);
}

DB::transaction(static function () use ($migration): void {
    $migration->up();
});

fwrite(STDOUT, "SCHEMA_COMMITTED_WITHOUT_LEDGER {$migrationName}\n");
