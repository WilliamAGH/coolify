<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Tests\Support\ExternalTestServicesGuard;
use Tests\Support\TestingSQLiteConnection;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        Connection::resolverFor(
            'sqlite',
            static fn ($connection, $database, $prefix, $config): TestingSQLiteConnection => new TestingSQLiteConnection(
                $connection,
                $database,
                $prefix,
                $config,
            ),
        );

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        ExternalTestServicesGuard::assertSafe(
            $app->make('config'),
            filter_var(env('COOLIFY_EXTERNAL_TEST_SERVICES'), FILTER_VALIDATE_BOOLEAN),
        );

        return $app;
    }
}
