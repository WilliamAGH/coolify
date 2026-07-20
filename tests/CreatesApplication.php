<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tests\Support\ExternalTestServicesGuard;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        ExternalTestServicesGuard::assertSafe(
            $app->make('config'),
            filter_var(env('COOLIFY_EXTERNAL_TEST_SERVICES'), FILTER_VALIDATE_BOOLEAN),
        );

        return $app;
    }
}
