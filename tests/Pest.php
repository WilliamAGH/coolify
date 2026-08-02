<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Once;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case + Global Hooks
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. The global beforeEach hooks MUST be registered on their own class-less
| uses() binding: a bare beforeEach() at the top level of Pest.php is inert under Pest 4.3,
| and hooks chained onto the uses(TestCase::class) binding never execute either — both let
| once() and Server::findCached identity-map state leak across test files in one process.
|
*/
uses(TestCase::class)->in('Feature', 'v4/Feature', 'v4/Browser');

uses()
    ->beforeEach(function () {
        // Flush the Once memoization cache to ensure tests get fresh data
        Once::flush();

        // Flush the Server identity map cache to ensure tests get fresh data
        Server::flushIdentityMap();
    })
    ->in('Feature', 'v4/Feature', 'v4/Browser');

/*
 * Unit tests run on plain PHPUnit\Framework\TestCase. Bind only the Mockery
 * teardown trait so Mockery::close() runs (and verifies expectations) after
 * every test instead of letting mock state leak across the whole process.
 *
 * The beforeEach hook resets leaked global container/facade state before every
 * plain unit test: an earlier test (including a TestCase-bound file whose torn
 * down application object lingers in the Facade root) can leave a partial
 * container behind, and facades resolved against it fail with
 * BindingResolutionException. TestCase-bound tests are skipped — Laravel's own
 * setUp/tearDown manages their application lifecycle.
 */
uses(MockeryPHPUnitIntegration::class)
    ->beforeEach(function (): void {
        if ($this instanceof TestCase) {
            return;
        }

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    })
    ->in('Unit');

function loginAndSkipBoarding(?string $email = null, string $password = 'password'): mixed
{
    $email ??= 'test@example.com';

    return visit('/login')
        ->fill('email', $email)
        ->fill('password', $password)
        ->click('Login')
        ->click('Skip Setup');
}

function seedBrowserInstanceSettings(): InstanceSettings
{
    return InstanceSettings::unguarded(fn (): InstanceSettings => InstanceSettings::query()->create([
        'id' => 0,
        'is_sponsorship_popup_enabled' => false,
    ]));
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

// expect()->extend('toBeOne', function () {
//     return $this->toBe(1);
// });

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

// function something()
// {
//     // ..
// }

/**
 * Seeds the instance-wide settings singleton.
 *
 * `InstanceSettings::get()` resolves it with `findOrFail(0)`, so any code path
 * reaching instance settings — proxy type, generated labels, most Livewire
 * pages — dies with ModelNotFoundException until row 0 exists. A real instance
 * always has it; a `RefreshDatabase` test only has it once seeded.
 */
function seedInstanceSettings(): InstanceSettings
{
    return InstanceSettings::unguarded(
        fn (): InstanceSettings => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]),
    );
}

/**
 * Opts an application out of blue-green so a test can exercise writable storage.
 *
 * This fork enables blue-green by default for new application settings, and
 * blue-green legitimately refuses writable storage: two colors would share one
 * writable volume. Tests whose subject is storage rather than blue-green state
 * that here, so the refusal stays a real invariant everywhere else.
 */
function withoutBlueGreenForStorage(Application $application): Application
{
    $settings = $application->settings()->first();
    if ($settings !== null && $settings->is_blue_green_deployment_enabled) {
        $settings->is_blue_green_deployment_enabled = false;
        $settings->save();
    }

    return $application->refresh();
}
