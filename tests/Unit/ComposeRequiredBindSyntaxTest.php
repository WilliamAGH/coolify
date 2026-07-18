<?php

use Symfony\Component\Finder\Finder;

it('uses long syntax for required-path bind mounts', function () {
    $composeFiles = Finder::create()
        ->files()
        ->in(dirname(__DIR__, 2))
        ->exclude(['node_modules', 'vendor'])
        ->name(['*compose*.yml', '*compose*.yaml']);
    $violations = [];

    foreach ($composeFiles as $composeFile) {
        $lines = preg_split('/\R/', $composeFile->getContents()) ?: [];

        foreach ($lines as $lineNumber => $line) {
            if (preg_match('/^\s*-\s*["\']?\$\{[A-Z0-9_]+:\?[^}]+\}:/', $line) === 1) {
                $violations[] = sprintf(
                    '%s:%d uses ambiguous short bind syntax',
                    $composeFile->getRelativePathname(),
                    $lineNumber + 1,
                );
            }
        }
    }

    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
});
