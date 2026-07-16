<?php

use App\Models\Server;
use Illuminate\Support\Once;
use Tests\TestCase;

(function (): void {
    if (PHP_OS_FAMILY !== 'Darwin') {
        return;
    }

    $homebrewPrefixes = [
        getenv('HOMEBREW_PREFIX') ?: null,
        '/opt/homebrew',
        '/usr/local',
    ];

    foreach (array_unique($homebrewPrefixes) as $homebrewPrefix) {
        if ($homebrewPrefix === null) {
            continue;
        }

        $gnuCoreutilsBin = $homebrewPrefix.'/opt/coreutils/libexec/gnubin';

        if (! is_executable($gnuCoreutilsBin.'/stat')) {
            continue;
        }

        $path = $gnuCoreutilsBin.PATH_SEPARATOR.(getenv('PATH') ?: '');
        putenv("PATH={$path}");
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;

        break;
    }
})();

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
uses(TestCase::class)
    ->beforeEach(function (): void {
        // Flush the Once memoization cache to ensure tests get fresh data
        Once::flush();

        // Flush the Server identity map cache to ensure tests get fresh data
        Server::flushIdentityMap();
    })
    ->in('Feature', 'v4/Feature', 'v4/Browser');

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
