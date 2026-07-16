<?php

declare(strict_types=1);

use App\Support\ControlPlaneMigrationInventory;
use Composer\Autoload\ClassLoader;
use Illuminate\Database\Capsule\Manager as DatabaseCapsule;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;

const APPLICATION_ROOT = '/var/www/html';
const MIGRATION_DIRECTORY = APPLICATION_ROOT.'/database/migrations';

function loadFramework(): void
{
    $vendorDirectory = APPLICATION_ROOT.'/vendor';
    require $vendorDirectory.'/composer/ClassLoader.php';

    $loader = new ClassLoader($vendorDirectory);
    $prefixes = require $vendorDirectory.'/composer/autoload_psr4.php';
    foreach ($prefixes as $prefix => $directories) {
        $loader->setPsr4($prefix, $directories);
    }
    $loader->register();
    require $vendorDirectory.'/symfony/polyfill-php85/bootstrap.php';

    foreach ([
        'Collections/functions.php',
        'Collections/helpers.php',
        'Events/functions.php',
        'Filesystem/functions.php',
        'Foundation/helpers.php',
        'Log/functions.php',
        'Reflection/helpers.php',
        'Support/functions.php',
        'Support/helpers.php',
    ] as $helper) {
        require_once $vendorDirectory.'/laravel/framework/src/Illuminate/'.$helper;
    }
}

/** @return list<string> */
function migrationNames(): array
{
    require_once APPLICATION_ROOT.'/app/Support/ControlPlaneMigrationInventory.php';

    return ControlPlaneMigrationInventory::names(
        MIGRATION_DIRECTORY,
        MIGRATION_DIRECTORY.'/control-plane-migration-inventory.fingerprint',
        true,
    );
}

function requiredEnvironment(string $name): string
{
    $environmentValue = getenv($name);
    if (! is_string($environmentValue) || $environmentValue === '') {
        throw new RuntimeException("Required database environment is missing: {$name}.");
    }

    return $environmentValue;
}

function databaseManager(): DatabaseCapsule
{
    $port = requiredEnvironment('PGPORT');
    $password = getenv('PGPASSWORD');
    if (preg_match('/\A[1-9][0-9]*\z/', $port) !== 1) {
        throw new RuntimeException('PostgreSQL port is malformed.');
    }

    $database = new DatabaseCapsule;
    $database->addConnection([
        'driver' => 'pgsql',
        'host' => requiredEnvironment('PGHOST'),
        'port' => (int) $port,
        'database' => requiredEnvironment('PGDATABASE'),
        'username' => requiredEnvironment('PGUSER'),
        'password' => is_string($password) ? $password : '',
        'charset' => 'utf8',
        'prefix' => '',
        'schema' => 'public',
        'application_name' => 'coolify-control-plane-migration-lab',
    ]);
    $database->setAsGlobal();

    $manager = $database->getDatabaseManager();
    $container = $database->getContainer();
    $container->instance('db', $manager);
    $container->bind(
        'db.schema',
        static fn () => $manager->connection('default')->getSchemaBuilder(),
    );
    Facade::setFacadeApplication($container);

    return $database;
}

/** @param list<string> $migrationNames */
function runMigrations(DatabaseCapsule $database, array $migrationNames): void
{
    $manager = $database->getDatabaseManager();
    $repository = new DatabaseMigrationRepository($manager, 'migrations');
    $migrator = new Migrator($repository, $manager, new Filesystem);
    $migrator->setConnection('default');

    $migrationPaths = array_map(
        static fn (string $migration): string => MIGRATION_DIRECTORY."/{$migration}.php",
        $migrationNames,
    );
    $recordedNames = array_fill_keys($repository->getRan(), true);
    $pendingPaths = array_values(array_filter(
        $migrationPaths,
        static fn (string $path): bool => ! isset($recordedNames[pathinfo($path, PATHINFO_FILENAME)]),
    ));

    $partialCountValue = getenv('CONTROL_PLANE_LAB_PARTIAL_MIGRATION_COUNT') ?: '0';
    if (preg_match('/\A[0-9]+\z/', $partialCountValue) !== 1) {
        throw new RuntimeException('Partial migration count is malformed.');
    }
    $partialCount = (int) $partialCountValue;
    if ($partialCount > 0) {
        if ($partialCount >= count($pendingPaths)) {
            throw new RuntimeException('Partial migration count must leave at least one migration pending.');
        }
        $migrator->run(array_slice($pendingPaths, 0, $partialCount));
        exit(70);
    }

    $migrator->run($migrationPaths);

    $badSchema = getenv('CONTROL_PLANE_LAB_BAD_SCHEMA') ?: '0';
    if (! in_array($badSchema, ['0', '1'], true)) {
        throw new RuntimeException('Bad-schema injection must be zero or one.');
    }
    if ($badSchema === '1') {
        $manager->connection('default')->statement(
            'DROP INDEX app_blue_green_deactivation_phase_index',
        );
    }

    foreach ($migrationPaths as $migrationPath) {
        $migration = require $migrationPath;
        if (! $migration instanceof Migration) {
            throw new RuntimeException("Canonical migration did not return a migration: {$migrationPath}.");
        }
        $migration->up();
    }
}

if ($argc === 2 && $argv[1] === '--validate-migration-inventory') {
    try {
        fwrite(STDOUT, implode(PHP_EOL, migrationNames()).PHP_EOL);
        exit(0);
    } catch (Throwable $throwable) {
        fwrite(STDERR, $throwable."\n");
        exit(1);
    }
}

try {
    loadFramework();
    runMigrations(databaseManager(), migrationNames());
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable."\n");
    exit(1);
}
