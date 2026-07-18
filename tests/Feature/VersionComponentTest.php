<?php

use Illuminate\Support\Facades\Blade;

test('fork versions link to the owning fork release without a v prefix', function () {
    config(['constants.coolify.version' => '4.13.0-fork.1']);

    $html = Blade::render('<x-version />');

    expect($html)
        ->toContain('href="https://github.com/WilliamAGH/coolify/releases/tag/4.13.0-fork.1"')
        ->not->toContain('github.com/coollabsio/coolify/releases/tag/v4.13.0-fork.1')
        ->toContain('v4.13.0-fork.1');
});

test('upstream versions keep their upstream v-prefixed release links', function (string $version) {
    config(['constants.coolify.version' => $version]);

    $html = Blade::render('<x-version />');

    expect($html)
        ->toContain('href="https://github.com/coollabsio/coolify/releases/tag/v'.$version.'"')
        ->not->toContain('github.com/WilliamAGH/coolify/releases/tag/'.$version)
        ->toContain('v'.$version);
})->with([
    'stable version' => '4.1.2',
    'fork-like upstream prerelease' => '4.13.0-beta-fork.1',
]);
