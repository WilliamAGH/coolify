<?php

use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Once;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/
uses(TestCase::class)->in('Feature', 'v4/Feature', 'v4/Browser');

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

/*
|--------------------------------------------------------------------------
| Test Hooks
|--------------------------------------------------------------------------
|
| Global hooks that run before/after each test.
|
*/
beforeEach(function () {
    // Flush the Once memoization cache to ensure tests get fresh data
    Once::flush();

    // Flush the Server identity map cache to ensure tests get fresh data
    Server::flushIdentityMap();
});

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
